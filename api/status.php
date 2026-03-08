<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/settings.php';

$ip = get_client_ip();
if (!api_rate_check('status_api', $ip, 60)) {
    api_rate_limit_exceeded();
}
$statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));

function disk_health_tables_exist(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $rows = db_all(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('disk_health_states')"
        );
        $map = [];
        foreach ($rows as $row) {
            $name = (string) ($row['table_name'] ?? '');
            if ($name !== '') {
                $map[$name] = true;
            }
        }
        $ready = isset($map['disk_health_states']);
    } catch (Throwable) {
        $ready = false;
    }

    return $ready;
}

function service_summary_map(array $serverIds): array
{
    if (empty($serverIds)) {
        return [];
    }

    $safeIds = array_values(array_filter(array_map('intval', $serverIds), static fn (int $id): bool => $id > 0));
    if (empty($safeIds)) {
        return [];
    }

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
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['server_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $map[$sid] = [
            'up' => (int) ($row['up_count'] ?? 0),
            'down' => (int) ($row['down_count'] ?? 0),
            'unknown' => (int) ($row['unknown_count'] ?? 0),
        ];
    }
    return $map;
}

$serverId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$history = isset($_GET['history']) ? (string) $_GET['history'] : null;
$diskSummary = isset($_GET['disk_summary']) && (string) $_GET['disk_summary'] === '1';

if ($diskSummary) {
    if (!disk_health_tables_exist()) {
        json_response([]);
    }

    $includeInactive = isset($_GET['include_inactive']) && (string) $_GET['include_inactive'] === '1';
    $cacheKey = $includeInactive ? 'status:disk_summary:all' : 'status:disk_summary:active';
    $cached = cache_get($cacheKey);
    if (is_array($cached)) {
        json_response($cached);
    }

    $rows = db_all(
        'SELECT
            s.id AS server_id,
            s.name AS server_name,
            COUNT(dhs.disk_key) AS disk_count,
            ROUND(AVG(dhs.health_score), 2) AS avg_health_score,
            ROUND(AVG(dhs.power_on_time), 2) AS avg_power_on_time,
            ROUND(AVG(dhs.total_written_bytes), 2) AS avg_tbw_bytes,
            DATE_FORMAT(MAX(dhs.updated_at), "%Y-%m-%d %H:%i:%s") AS last_update,
            pd.model AS primary_disk_model,
            pd.device_name AS primary_disk_device
         FROM servers s
         LEFT JOIN disk_health_states dhs
            ON dhs.server_id = s.id
         LEFT JOIN (
            SELECT ranked.server_id, ranked.model, ranked.device_name
            FROM (
                SELECT
                    server_id,
                    model,
                    device_name,
                    ROW_NUMBER() OVER (
                        PARTITION BY server_id
                        ORDER BY
                            CASE health_status
                                WHEN "critical" THEN 4
                                WHEN "warning" THEN 3
                                WHEN "ok" THEN 2
                                ELSE 1
                            END DESC,
                            updated_at DESC,
                            disk_key ASC
                    ) AS rn
                FROM disk_health_states
            ) ranked
            WHERE ranked.rn = 1
         ) pd
            ON pd.server_id = s.id
         ' . ($includeInactive ? '' : 'WHERE s.active = 1') . '
         GROUP BY
            s.id, s.name, pd.model, pd.device_name
         ORDER BY s.name ASC'
    );

    $result = [];
    foreach ($rows as $row) {
        $diskCount = max(0, (int) ($row['disk_count'] ?? 0));
        $extraDiskCount = max(0, $diskCount - 1);
        $model = trim((string) ($row['primary_disk_model'] ?? ''));
        $device = trim((string) ($row['primary_disk_device'] ?? ''));
        if ($model !== '' && $device !== '') {
            $primaryLabel = $model . ' (' . $device . ')';
        } elseif ($model !== '') {
            $primaryLabel = $model;
        } elseif ($device !== '') {
            $primaryLabel = $device;
        } else {
            $primaryLabel = '-';
        }

        $result[] = [
            'server_id' => (int) ($row['server_id'] ?? 0),
            'server_name' => (string) ($row['server_name'] ?? ''),
            'disk_count' => $diskCount,
            'avg_health_score' => isset($row['avg_health_score']) ? (float) $row['avg_health_score'] : null,
            'avg_power_on_time' => isset($row['avg_power_on_time']) ? (float) $row['avg_power_on_time'] : null,
            // Backward-compatible alias for older consumers.
            'avg_power_on_hours' => isset($row['avg_power_on_time']) ? (float) $row['avg_power_on_time'] : null,
            'avg_tbw_bytes' => isset($row['avg_tbw_bytes']) ? (float) $row['avg_tbw_bytes'] : null,
            'last_update' => (string) ($row['last_update'] ?? '-'),
            'primary_disk_label' => $primaryLabel,
            'extra_disk_count' => $extraDiskCount,
        ];
    }

    cache_set($cacheKey, $result, cache_ttl('cache_ttl_disk_health_list', 15));
    json_response($result);
}

if ($serverId !== null && $serverId > 0 && $history !== null) {
    $historyKey = in_array($history, ['5m', '30m', '24h', '7d', '30d'], true) ? $history : '24h';
    $points = isset($_GET['points']) ? (int) $_GET['points'] : 2000;
    $points = max(100, min(5000, $points));
    $cacheKey = 'status:history:' . $serverId . ':' . $historyKey . ':p' . $points;
    $historyTtl = match ($historyKey) {
        '5m' => cache_ttl('cache_ttl_history_5m', 5),
        '30m' => cache_ttl('cache_ttl_history_30m', 10),
        '7d' => cache_ttl('cache_ttl_history_7d', 60),
        '30d' => cache_ttl('cache_ttl_history_30d', 120),
        default => cache_ttl('cache_ttl_history_24h', 10),
    };
    $cached = cache_get($cacheKey);
    if (is_array($cached)) {
        json_response($cached);
    }

    $sql = match ($historyKey) {
        '5m' => 'SELECT * FROM (
                        SELECT
                            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
                            uptime, ram_total, ram_used, hdd_total, hdd_used,
                            ROUND(cpu_load, 4) AS cpu_load, network_in_bps, network_out_bps,
                            mail_mta, mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                        ORDER BY recorded_at DESC
                        LIMIT :points
                    ) AS latest_points
                    ORDER BY recorded_at ASC',
        '30m' => 'SELECT * FROM (
                        SELECT
                            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
                            uptime, ram_total, ram_used, hdd_total, hdd_used,
                            ROUND(cpu_load, 4) AS cpu_load, network_in_bps, network_out_bps,
                            mail_mta, mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                        ORDER BY recorded_at DESC
                        LIMIT :points
                    ) AS latest_points
                    ORDER BY recorded_at ASC',
        '7d' => 'SELECT * FROM (
                    SELECT * FROM (
                        SELECT
                            DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00") AS recorded_at,
                            MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                            MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                            ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                            MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00")
                        UNION ALL
                        SELECT
                            DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00") AS recorded_at,
                            MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                            MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                            ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                            MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                        FROM metrics_history
                        WHERE server_id = :server_id2 AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00")
                    ) combined_metrics
                    ORDER BY recorded_at DESC
                    LIMIT :points
                 ) AS latest_points
                 ORDER BY recorded_at ASC',
        '30d' => 'SELECT * FROM (
                    SELECT * FROM (
                        SELECT
                            DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 900) * 900), "%Y-%m-%d %H:%i:00") AS recorded_at,
                            MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                            MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                            ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                            MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 900) * 900), "%Y-%m-%d %H:%i:00")
                        UNION ALL
                        SELECT
                            DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 900) * 900), "%Y-%m-%d %H:%i:00") AS recorded_at,
                            MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                            MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                            ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                            MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                        FROM metrics_history
                        WHERE server_id = :server_id2 AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 900) * 900), "%Y-%m-%d %H:%i:00")
                    ) combined_metrics
                    ORDER BY recorded_at DESC
                    LIMIT :points
                  ) AS latest_points
                  ORDER BY recorded_at ASC',
        default => 'SELECT * FROM (
                        SELECT
                            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
                            uptime, ram_total, ram_used, hdd_total, hdd_used,
                            ROUND(cpu_load, 4) AS cpu_load, network_in_bps, network_out_bps,
                            mail_mta, mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        ORDER BY recorded_at DESC
                        LIMIT :points
                    ) AS latest_points
                    ORDER BY recorded_at ASC',
    };
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    if ($historyKey === '7d' || $historyKey === '30d') {
        $stmt->bindValue(':server_id2', $serverId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':points', $points, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    cache_set($cacheKey, $rows, $historyTtl);
    json_response($rows);
}

if ($serverId !== null && $serverId > 0) {
    $singleCacheKey = 'status:single:' . $serverId;
    $singleCached = cache_get($singleCacheKey);
    if (is_array($singleCached) && isset($singleCached['id'])) {
        json_response($singleCached);
    }

    $row = db_one(
        'SELECT s.id, s.name, s.location, s.type, s.host, s.active, s.maintenance_mode, s.maintenance_until,
                m.recorded_at AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
         FROM servers s' . latest_metric_join_sql('s', 'm') . '
         WHERE s.id = :id
         LIMIT 1',
        [':id' => $serverId]
    );

    if ($row === null) {
        json_response(['error' => 'Server not found'], 404);
    }

    $row['status'] = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
    $row['panel_profile'] = (string) ($row['panel_profile'] ?? 'generic');
    $row['maintenance_mode'] = (int) ($row['maintenance_mode'] ?? 0);
    $row['maintenance_until'] = $row['maintenance_until'] ?? null;
    $row['ram_used_pct'] = calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0));
    $row['hdd_used_pct'] = calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0));
    $services = db_all(
        'SELECT service_group, service_key, unit_name, last_status AS status, updated_at
         FROM server_service_states
         WHERE server_id = :id
         ORDER BY service_group ASC, service_key ASC',
        [':id' => $serverId]
    );
    $row['services'] = array_map(static function (array $service): array {
        return [
            'group' => (string) ($service['service_group'] ?? ''),
            'service_key' => (string) ($service['service_key'] ?? ''),
            'unit_name' => (string) ($service['unit_name'] ?? ''),
            'status' => (string) ($service['status'] ?? 'unknown'),
            'updated_at' => (string) ($service['updated_at'] ?? ''),
        ];
    }, $services);
    $summaryMap = service_summary_map([$serverId]);
    $row['services_summary'] = $summaryMap[$serverId] ?? ['up' => 0, 'down' => 0, 'unknown' => 0];
    cache_set($singleCacheKey, $row, cache_ttl('cache_ttl_status_single', 15));
    json_response($row);
}

$includeInactive = isset($_GET['include_inactive']) && (string) $_GET['include_inactive'] === '1';
$listCacheKey = $includeInactive ? 'status:list:all' : 'status:list:active';
$listCached = cache_get($listCacheKey);
if (is_array($listCached)) {
    json_response($listCached);
}

$rows = db_all(
    'SELECT s.id, s.name, s.location, s.type, s.active, s.maintenance_mode, s.maintenance_until,
            m.recorded_at AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
     FROM servers s' . latest_metric_join_sql('s', 'm') . '
     ' . ($includeInactive ? '' : 'WHERE s.active = 1') . '
     ORDER BY s.name ASC'
);
$summaryMap = service_summary_map(array_map(static fn (array $r): int => (int) $r['id'], $rows));

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

cache_set($listCacheKey, $result, cache_ttl('cache_ttl_status_list', 15));
json_response($result);
