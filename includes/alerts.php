<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/notifications/email.php';
require_once __DIR__ . '/notifications/telegram.php';

function alert_in_cooldown(?int $serverId, string $alertType, int $cooldownMinutes): bool
{
    if ($cooldownMinutes <= 0) {
        return false;
    }

    $sql = 'SELECT created_at FROM alert_logs
            WHERE alert_type = :alert_type
            AND created_at >= DATE_SUB(NOW(), INTERVAL :cooldown MINUTE)';
    $params = [':alert_type' => $alertType, ':cooldown' => (int) $cooldownMinutes];

    if ($serverId !== null) {
        $sql .= ' AND server_id = :server_id';
        $params[':server_id'] = (int) $serverId;
    }

    $sql .= ' ORDER BY id DESC LIMIT 1';
    $lastAlert = db_one($sql, $params);

    if ($lastAlert === null) {
        return false;
    }

    $recoveryType = null;
    if ($alertType === 'server_down') {
        $recoveryType = 'server_recovery';
    } elseif (str_starts_with($alertType, 'service_down_')) {
        $recoveryType = 'service_recovery_' . substr($alertType, 13);
    } elseif (str_starts_with($alertType, 'ping_down_')) {
        $recoveryType = 'ping_recovery_' . substr($alertType, 10);
    }

    if ($recoveryType !== null) {
        $recSql = 'SELECT id FROM alert_logs
                   WHERE alert_type = :rec_type
                   AND created_at >= :last_alert';
        $recParams = [':rec_type' => $recoveryType, ':last_alert' => $lastAlert['created_at']];
        if ($serverId !== null) {
            $recSql .= ' AND server_id = :server_id';
            $recParams[':server_id'] = $serverId;
        }
        $recSql .= ' LIMIT 1';

        if (db_one($recSql, $recParams) !== null) {
            return false;
        }
    }

    return true;
}

function create_alert(
    ?int $serverId,
    string $alertType,
    string $severity,
    string $title,
    string $message,
    array $context = []
): void {
    $settings = settings_get_all();
    $cooldownMinutes = max(0, (int) ($settings['alert_cooldown_minutes'] ?? '30'));

    if (alert_in_cooldown($serverId, $alertType, $cooldownMinutes)) {
        return;
    }

    $emailSent = notify_email($title, $message, $settings);
    $telegramSent = notify_telegram("<b>{$title}</b>\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $settings);

    db_exec(
        'INSERT INTO alert_logs
        (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, created_at)
        VALUES
        (:server_id, :alert_type, :severity, :title, :message, :context_json, :sent_email, :sent_telegram, NOW())',
        [
            ':server_id' => $serverId,
            ':alert_type' => $alertType,
            ':severity' => $severity,
            ':title' => $title,
            ':message' => $message,
            ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':sent_email' => $emailSent ? 1 : 0,
            ':sent_telegram' => $telegramSent ? 1 : 0,
        ]
    );
    invalidate_alert_cache();
}

function evaluate_server_threshold_alerts(array $server, array $metric): void
{
    $serverId = (int) $server['id'];
    if (is_server_in_maintenance($serverId)) {
        return;
    }

    $settings = settings_get_all();

    $serverName = (string) $server['name'];
    $mailThreshold = max(0, (int) ($settings['threshold_mail_queue'] ?? '50'));
    $mailCritical = max($mailThreshold, (int) ($settings['threshold_mail_queue_critical'] ?? '100'));
    $cpuThreshold = (float) ($settings['threshold_cpu_load'] ?? '2.00');
    $cpuCritical = max($cpuThreshold, (float) ($settings['threshold_cpu_load_critical'] ?? '4.00'));
    $ramThreshold = max(0, (float) ($settings['threshold_ram_pct'] ?? '85'));
    $ramCritical = max($ramThreshold, (float) ($settings['threshold_ram_pct_critical'] ?? '95'));
    $diskThreshold = max(0, (float) ($settings['threshold_disk_pct'] ?? '90'));
    $diskCritical = max($diskThreshold, (float) ($settings['threshold_disk_pct_critical'] ?? '97'));

    $mailQueue = (int) ($metric['mail_queue_total'] ?? 0);
    $cpuLoad = (float) ($metric['cpu_load'] ?? 0.0);
    $ramPct = calculateUsagePercent((int) ($metric['ram_used'] ?? 0), (int) ($metric['ram_total'] ?? 0));
    $diskPct = calculateUsagePercent((int) ($metric['hdd_used'] ?? 0), (int) ($metric['hdd_total'] ?? 0));

    if ($mailQueue >= $mailCritical) {
        create_alert(
            $serverId,
            'mail_queue_critical',
            'danger',
            "[{$serverName}] Critical Mail Queue",
            "Mail queue {$mailQueue} exceeds critical threshold {$mailCritical}.",
            ['mail_queue_total' => $mailQueue, 'threshold' => $mailCritical]
        );
    } elseif ($mailQueue >= $mailThreshold) {
        create_alert(
            $serverId,
            'mail_queue_high',
            'warning',
            "[{$serverName}] High Mail Queue",
            "Mail queue {$mailQueue} exceeds threshold {$mailThreshold}.",
            ['mail_queue_total' => $mailQueue, 'threshold' => $mailThreshold]
        );
    }

    if ($cpuLoad >= $cpuCritical) {
        create_alert(
            $serverId,
            'cpu_critical',
            'danger',
            "[{$serverName}] Critical CPU Load",
            "CPU load {$cpuLoad} exceeds critical threshold {$cpuCritical}.",
            ['cpu_load' => $cpuLoad, 'threshold' => $cpuCritical]
        );
    } elseif ($cpuLoad >= $cpuThreshold) {
        create_alert(
            $serverId,
            'cpu_high',
            'warning',
            "[{$serverName}] High CPU Load",
            "CPU load {$cpuLoad} exceeds threshold {$cpuThreshold}.",
            ['cpu_load' => $cpuLoad, 'threshold' => $cpuThreshold]
        );
    }

    if ($ramPct >= $ramCritical) {
        create_alert(
            $serverId,
            'ram_critical',
            'danger',
            "[{$serverName}] Critical RAM Usage",
            "RAM usage {$ramPct}% exceeds critical threshold {$ramCritical}%.",
            ['ram_pct' => $ramPct, 'threshold' => $ramCritical]
        );
    } elseif ($ramPct >= $ramThreshold) {
        create_alert(
            $serverId,
            'ram_high',
            'warning',
            "[{$serverName}] High RAM Usage",
            "RAM usage {$ramPct}% exceeds threshold {$ramThreshold}%.",
            ['ram_pct' => $ramPct, 'threshold' => $ramThreshold]
        );
    }

    if ($diskPct >= $diskCritical) {
        create_alert(
            $serverId,
            'disk_critical',
            'danger',
            "[{$serverName}] Critical Disk Usage",
            "Disk usage {$diskPct}% exceeds critical threshold {$diskCritical}%.",
            ['disk_pct' => $diskPct, 'threshold' => $diskCritical]
        );
    } elseif ($diskPct >= $diskThreshold) {
        create_alert(
            $serverId,
            'disk_high',
            'warning',
            "[{$serverName}] High Disk Usage",
            "Disk usage {$diskPct}% exceeds threshold {$diskThreshold}%.",
            ['disk_pct' => $diskPct, 'threshold' => $diskThreshold]
        );
    }
}

function evaluate_service_transition_alerts(array $server, array $transitions): void
{
    $settings = settings_get_all();
    if (($settings['alert_service_status_enabled'] ?? '1') !== '1') {
        return;
    }

    $serverId = (int) ($server['id'] ?? 0);
    $serverName = (string) ($server['name'] ?? ('Server #' . $serverId));
    $flapSuppressMinutes = max(0, (int) ($settings['alert_service_flap_suppress_minutes'] ?? '5'));
    if ($serverId <= 0 || empty($transitions)) {
        return;
    }
    if (is_server_in_maintenance($serverId)) {
        return;
    }

    foreach ($transitions as $transition) {
        $group = (string) ($transition['service_group'] ?? '');
        $key = (string) ($transition['service_key'] ?? '');
        $unit = (string) ($transition['unit_name'] ?? '');
        $prev = (string) ($transition['prev_status'] ?? '');
        $next = (string) ($transition['new_status'] ?? '');
        $prevChangedAt = (string) ($transition['prev_changed_at'] ?? '');

        if ($key === '' || $group === '' || $unit === '') {
            continue;
        }
        if ($prev === 'unknown' || $next === 'unknown' || $prev === $next) {
            continue;
        }
        if ($flapSuppressMinutes > 0 && $prevChangedAt !== '') {
            $prevChangedTs = strtotime($prevChangedAt);
            if ($prevChangedTs !== false && (time() - $prevChangedTs) < ($flapSuppressMinutes * 60)) {
                continue;
            }
        }

        $serviceLabel = strtoupper($key);
        if ($prev === 'up' && $next === 'down') {
            create_alert(
                $serverId,
                'service_down_' . $key,
                'warning',
                "[{$serverName}] Service {$serviceLabel} Down",
                "Service {$serviceLabel} ({$unit}) changed from up to down.",
                [
                    'service_group' => $group,
                    'service_key' => $key,
                    'unit_name' => $unit,
                    'prev_status' => $prev,
                    'new_status' => $next,
                ]
            );
            continue;
        }

        if ($prev === 'down' && $next === 'up') {
            create_alert(
                $serverId,
                'service_recovery_' . $key,
                'success',
                "[{$serverName}] Service {$serviceLabel} Recovery",
                "Service {$serviceLabel} ({$unit}) recovered from down to up.",
                [
                    'service_group' => $group,
                    'service_key' => $key,
                    'unit_name' => $unit,
                    'prev_status' => $prev,
                    'new_status' => $next,
                ]
            );
        }
    }
}

function evaluate_down_recovery_alerts(): void
{
    $settings = settings_get_all();
    $downMinutes = max(1, (int) ($settings['alert_down_minutes'] ?? '5'));

    $servers = db_all(
        'SELECT s.id, s.name, s.active,
                m.recorded_at AS last_seen
         FROM servers s' . latest_metric_join_sql('s', 'm') . '
         WHERE s.active = 1'
    );

    foreach ($servers as $server) {
        $serverId = (int) $server['id'];
        if (is_server_in_maintenance($serverId)) {
            db_exec(
                'INSERT INTO server_states (server_id, is_down) VALUES (:id, 0)
                 ON DUPLICATE KEY UPDATE is_down = 0',
                [':id' => $serverId]
            );
            continue;
        }
        $state = db_one('SELECT is_down FROM server_states WHERE server_id = :id LIMIT 1', [':id' => $serverId]);
        $isDown = $state ? ((int) $state['is_down'] === 1) : false;

        $lastSeen = $server['last_seen'] ?? null;
        $statusNow = 'pending';
        if ($lastSeen !== null && trim((string) $lastSeen) !== '') {
            $seenTs = strtotime((string) $lastSeen);
            if ($seenTs !== false) {
                $minutes = (time() - $seenTs) / 60;
                $statusNow = $minutes > $downMinutes ? 'down' : 'online';
            }
        }
        $downNow = $statusNow === 'down';

        if ($downNow && !$isDown) {
            create_alert(
                $serverId,
                'server_down',
                'danger',
                '[' . $server['name'] . '] Server Down',
                'Server has not sent metrics for more than ' . $downMinutes . ' minutes.',
                ['last_seen' => $lastSeen]
            );
        }

        if ($statusNow === 'online' && $isDown) {
            create_alert(
                $serverId,
                'server_recovery',
                'success',
                '[' . $server['name'] . '] Server Recovery',
                'Server is back online and sending metrics.',
                ['last_seen' => $lastSeen]
            );
        }

        db_exec(
            'INSERT INTO server_states (server_id, is_down) VALUES (:id, :is_down)
             ON DUPLICATE KEY UPDATE is_down = VALUES(is_down)',
            [':id' => $serverId, ':is_down' => $downNow ? 1 : 0]
        );
    }
}

function evaluate_current_service_down_alerts(): void
{
    $settings = settings_get_all();
    if (($settings['alert_service_status_enabled'] ?? '1') !== '1') {
        return;
    }
    $statusOnlineMinutes = max(1, (int) ($settings['alert_down_minutes'] ?? '5'));

    $rows = db_all(
        'SELECT s.id AS server_id, s.name AS server_name,
                m.recorded_at AS last_seen,
                st.service_group, st.service_key, st.unit_name, st.last_status, st.updated_at
         FROM servers s' . latest_metric_join_sql('s', 'm') . '
         INNER JOIN server_service_states st ON st.server_id = s.id
         WHERE s.active = 1'
    );

    foreach ($rows as $row) {
        $serverOnline = serverStatusFromLastSeen($row['last_seen'] ?? null, true, $statusOnlineMinutes) === 'online';
        if (!$serverOnline) {
            continue;
        }

        $status = (string) ($row['last_status'] ?? 'unknown');
        if ($status !== 'down') {
            continue;
        }

        $serverId = (int) ($row['server_id'] ?? 0);
        if ($serverId <= 0) {
            continue;
        }
        if (is_server_in_maintenance($serverId)) {
            continue;
        }

        $serverName = (string) ($row['server_name'] ?? ('Server #' . $serverId));
        $serviceKey = (string) ($row['service_key'] ?? '');
        $serviceGroup = (string) ($row['service_group'] ?? '');
        $unitName = (string) ($row['unit_name'] ?? '');
        if ($serviceKey === '' || $serviceGroup === '' || $unitName === '') {
            continue;
        }

        $serviceLabel = strtoupper($serviceKey);
        create_alert(
            $serverId,
            'service_down_' . $serviceKey,
            'warning',
            "[{$serverName}] Service {$serviceLabel} Down",
            "Service {$serviceLabel} ({$unitName}) is currently down.",
            [
                'service_group' => $serviceGroup,
                'service_key' => $serviceKey,
                'unit_name' => $unitName,
                'current_status' => $status,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'source' => 'snapshot-check',
            ]
        );
    }
}

function evaluate_current_ping_down_alerts(): void
{
    $settings = settings_get_all();
    if (($settings['alert_ping_enabled'] ?? '1') !== '1') {
        return;
    }

    $rows = db_all(
        'SELECT pm.id, pm.name, pm.target, pm.check_method,
                ps.last_status, ps.last_error, ps.updated_at
         FROM ping_monitors pm
         INNER JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
         WHERE pm.active = 1'
    );

    foreach ($rows as $row) {
        if ((string)($row['last_status'] ?? 'unknown') !== 'down') {
            continue;
        }

        $monitorId = (int) ($row['id'] ?? 0);
        if ($monitorId <= 0) {
            continue;
        }

        $name = (string) ($row['name'] ?? ('Ping Monitor #' . $monitorId));
        $target = (string) ($row['target'] ?? '-');
        $method = strtolower((string) ($row['check_method'] ?? 'icmp'));
        $error = trim((string) ($row['last_error'] ?? ''));

        $message = "Target {$target} ({$method}) is currently down.";
        if ($error !== '') {
            $message .= ' Error: ' . $error;
        }

        create_alert(
            null,
            'ping_down_monitor_' . $monitorId,
            'danger',
            "[Ping] {$name} Down",
            $message,
            [
                'monitor_id' => $monitorId,
                'monitor_name' => $name,
                'target' => $target,
                'check_method' => $method,
                'current_status' => 'down',
                'error' => $error,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'source' => 'snapshot-check',
            ]
        );
    }
}

function evaluate_ping_monitor_transition_alert(array $monitor, array $transition, array $probe): void
{
    $settings = settings_get_all();
    if (($settings['alert_ping_enabled'] ?? '1') !== '1') {
        return;
    }

    $monitorId = (int) ($monitor['id'] ?? 0);
    if ($monitorId <= 0) {
        return;
    }

    $name = (string) ($monitor['name'] ?? ('Ping Monitor #' . $monitorId));
    $target = (string) ($monitor['target'] ?? '-');
    $method = strtolower((string) ($monitor['check_method'] ?? 'icmp'));
    $prev = (string) ($transition['previous_status'] ?? 'unknown');
    $next = (string) ($transition['current_status'] ?? 'unknown');
    if ($prev === $next) {
        return;
    }

    $latency = $probe['latency_ms'] ?? null;
    $error = trim((string) ($probe['error'] ?? ''));
    $context = [
        'monitor_id' => $monitorId,
        'monitor_name' => $name,
        'target' => $target,
        'check_method' => $method,
        'previous_status' => $prev,
        'current_status' => $next,
        'latency_ms' => $latency,
        'error' => $error,
    ];

    if ($prev === 'down' && $next === 'up') {
        $latencyText = is_numeric($latency) ? ('Latency: ' . number_format((float) $latency, 2) . ' ms.') : '';
        create_alert(
            null,
            'ping_recovery_monitor_' . $monitorId,
            'success',
            "[Ping] {$name} Recovery",
            "Target {$target} ({$method}) is reachable again. {$latencyText}",
            $context
        );
        return;
    }

    if ($next === 'down') {
        $message = "Target {$target} ({$method}) is unreachable.";
        if ($error !== '') {
            $message .= ' Error: ' . $error;
        }
        create_alert(
            null,
            'ping_down_monitor_' . $monitorId,
            'danger',
            "[Ping] {$name} Down",
            $message,
            $context
        );
    }
}

function run_alert_checks_if_due(int $minIntervalSeconds = 60): void
{
    static $alreadyRun = false;
    if ($alreadyRun) {
        return;
    }
    $alreadyRun = true;

    $minIntervalSeconds = max(10, $minIntervalSeconds);
    $stampFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'servmon-alert-check.stamp';
    $now = time();
    $lastRun = @file_exists($stampFile) ? (int) @filemtime($stampFile) : 0;
    if ($lastRun > 0 && ($now - $lastRun) < $minIntervalSeconds) {
        return;
    }

    // Use flock() to prevent race conditions between concurrent requests
    $lockFile = $stampFile . '.lock';
    $lockFp = @fopen($lockFile, 'c');
    if ($lockFp === false) {
        return;
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return; // Another process holds the lock
    }

    @touch($stampFile);

    try {
        evaluate_down_recovery_alerts();
        evaluate_current_service_down_alerts();
    } catch (Throwable $e) {
        servmon_log_error('Alert check failed: ' . $e->getMessage(), 'alert');
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}
