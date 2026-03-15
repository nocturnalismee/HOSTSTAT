<?php
declare(strict_types=1);

/**
 * SSE endpoint for servmon real-time updates.
 *
 * Streams two event types:
 *   - "status"  : server list with metrics (same data as GET /api/status.php)
 *   - "alerts"  : new alert logs since the client's last-known alert ID
 *
 * Query params:
 *   - include_inactive=1  : include inactive servers (admin dashboard)
 *   - since_alert_id=N    : only send alerts with id > N
 *
 * The connection stays open for up to MAX_LIFETIME_SECONDS, pushing updates
 * every PUSH_INTERVAL_SECONDS. After MAX_LIFETIME_SECONDS the server sends
 * an "end" event so the client can cleanly reconnect.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/sse.php';

// ── Rate limiting ────────────────────────────────────────────────────
$ip = get_client_ip();
if (!api_rate_check('sse_connect', $ip, 20)) {
    api_rate_limit_exceeded();
}

// ── Configuration ────────────────────────────────────────────────────
$pushInterval      = 10;  // seconds between pushes
$keepaliveInterval = 25;  // seconds between keepalive comments
$maxLifetime       = 300; // 5 minutes max connection lifetime

$includeInactive   = isset($_GET['include_inactive']) && (string) $_GET['include_inactive'] === '1';
$sinceAlertId      = isset($_GET['since_alert_id']) ? max(0, (int) $_GET['since_alert_id']) : 0;

// ── Respect Last-Event-ID from auto-reconnect ────────────────────────
$lastEventId = trim((string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? ''));
if ($lastEventId !== '' && preg_match('/^alert:(\d+)$/', $lastEventId, $m)) {
    $sinceAlertId = max($sinceAlertId, (int) $m[1]);
}

// ── Start SSE stream ─────────────────────────────────────────────────
sse_headers();

$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
$startTime           = time();
$lastPushAt          = 0;
$lastKeepaliveAt     = 0;
$lastStatusHash      = '';

/**
 * Build the server status list (mirrors api/status.php logic).
 */
function sse_build_status_list(bool $includeInactive, int $statusOnlineMinutes): array
{
    $rows = db_all(
        'SELECT s.id, s.name, s.location, s.type, s.active, s.maintenance_mode, s.maintenance_until,
                m.recorded_at AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used,
                m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
         FROM servers s' . latest_metric_join_sql('s', 'm') . '
         ' . ($includeInactive ? '' : 'WHERE s.active = 1') . '
         ORDER BY s.name ASC'
    );

    // Service summary map
    $serverIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $summaryMap = [];
    if (!empty($serverIds)) {
        $safeIds = array_values(array_filter(array_map('intval', $serverIds), static fn(int $id): bool => $id > 0));
        if (!empty($safeIds)) {
            $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
            $stmt = db()->prepare(
                "SELECT server_id,
                        SUM(last_status = 'up') AS up_count,
                        SUM(last_status = 'down') AS down_count,
                        SUM(last_status = 'unknown') AS unknown_count
                 FROM server_service_states
                 WHERE server_id IN ({$placeholders})
                 GROUP BY server_id"
            );
            $stmt->execute($safeIds);
            foreach ($stmt->fetchAll() as $sRow) {
                $sid = (int) ($sRow['server_id'] ?? 0);
                if ($sid > 0) {
                    $summaryMap[$sid] = [
                        'up' => (int) ($sRow['up_count'] ?? 0),
                        'down' => (int) ($sRow['down_count'] ?? 0),
                        'unknown' => (int) ($sRow['unknown_count'] ?? 0),
                    ];
                }
            }
        }
    }

    $result = [];
    foreach ($rows as $row) {
        $sid = (int) $row['id'];
        $result[] = [
            'id' => $sid,
            'name' => $row['name'],
            'location' => $row['location'],
            'type' => $row['type'],
            'active' => (int) ($row['active'] ?? 0),
            'maintenance_mode' => (int) ($row['maintenance_mode'] ?? 0),
            'maintenance_until' => $row['maintenance_until'] ?? null,
            'status' => serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes),
            'last_seen' => $row['last_seen'],
            'uptime' => (int) ($row['uptime'] ?? 0),
            'ram_total' => (int) ($row['ram_total'] ?? 0),
            'ram_used' => (int) ($row['ram_used'] ?? 0),
            'hdd_total' => (int) ($row['hdd_total'] ?? 0),
            'hdd_used' => (int) ($row['hdd_used'] ?? 0),
            'ram_used_pct' => calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0)),
            'hdd_used_pct' => calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0)),
            'cpu_load' => (float) ($row['cpu_load'] ?? 0),
            'network_in_bps' => (int) ($row['network_in_bps'] ?? 0),
            'network_out_bps' => (int) ($row['network_out_bps'] ?? 0),
            'mail_mta' => $row['mail_mta'] ?? 'none',
            'mail_queue_total' => (int) ($row['mail_queue_total'] ?? 0),
            'panel_profile' => (string) ($row['panel_profile'] ?? 'generic'),
            'services_summary' => $summaryMap[$sid] ?? ['up' => 0, 'down' => 0, 'unknown' => 0],
        ];
    }

    return $result;
}

/**
 * Fetch new alerts since a given ID.
 */
function sse_fetch_new_alerts(int &$sinceId): array
{
    if ($sinceId <= 0) {
        // First connection: resolve the current max ID without sending old alerts
        $row = db_one('SELECT MAX(id) AS max_id FROM alert_logs');
        $sinceId = (int) ($row['max_id'] ?? 0);
        return [];
    }

    $rows = db_all(
        'SELECT id, server_id, alert_type, severity, title, message, created_at
         FROM alert_logs
         WHERE id > :since_id
         ORDER BY id ASC
         LIMIT 20',
        [':since_id' => $sinceId]
    );

    if (!empty($rows)) {
        $lastRow = end($rows);
        $sinceId = (int) ($lastRow['id'] ?? $sinceId);
    }

    return $rows;
}

// ── Main SSE loop ────────────────────────────────────────────────────
while (true) {
    $now = time();

    // Check max lifetime
    if (($now - $startTime) >= $maxLifetime) {
        sse_send('end', ['reason' => 'max_lifetime', 'reconnect' => true]);
        break;
    }

    // Check client still connected
    if (connection_aborted()) {
        break;
    }

    // Push data at interval
    if (($now - $lastPushAt) >= $pushInterval) {
        $lastPushAt = $now;

        try {
            // Status data
            $statusData = sse_build_status_list($includeInactive, $statusOnlineMinutes);
            $statusJson = json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $statusHash = md5((string) $statusJson);

            // Only send status if data changed (reduces bandwidth)
            if ($statusHash !== $lastStatusHash) {
                $lastStatusHash = $statusHash;
                sse_send('status', $statusJson);
            }

            // Alerts
            $alerts = sse_fetch_new_alerts($sinceAlertId);
            if (!empty($alerts)) {
                sse_send('alerts', $alerts, 'alert:' . $sinceAlertId);
            }
        } catch (Throwable $e) {
            error_log('sse.php loop error: ' . $e->getMessage());
            sse_send('error', ['message' => 'Internal error']);
            break;
        }

        // Check again after flush
        if (connection_aborted()) {
            break;
        }
    }

    // Keepalive at interval
    if (($now - $lastKeepaliveAt) >= $keepaliveInterval) {
        $lastKeepaliveAt = $now;
        sse_keepalive();

        if (connection_aborted()) {
            break;
        }
    }

    // Sleep 1 second between iterations to reduce CPU usage
    sleep(1);
}
