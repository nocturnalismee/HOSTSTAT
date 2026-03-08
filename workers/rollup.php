<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/worker.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/retention.php';

$workerName = 'rollup_metrics';
worker_mark_run_start($workerName);

try {
    $pdo = db();
    
    // Aggregation threshold: raw data older than 2 days will be rolled up
    $configuredDays = trim((string) setting_get('rollup_days'));
    $days = (int) $configuredDays;
    if ($days <= 0) {
        $days = 2;
    }
    $cutoffStr = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    servmon_log_info("Starting metrics rollup: older than {$days} days, cutoff={$cutoffStr}", 'rollup');

    // 5 minutes resolution = 300 seconds (validated as constant)
    $interval = 300;
    if (!is_int($interval) || $interval <= 0) {
        throw new RuntimeException('Invalid rollup interval');
    }

    $sql = "
        INSERT INTO metrics_history (
            server_id, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used,
            cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile
        )
        SELECT 
            server_id,
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / :interval_group) * :interval_select) AS recorded_at,
            MAX(uptime),
            MAX(ram_total),
            ROUND(AVG(ram_used)),
            MAX(hdd_total),
            ROUND(AVG(hdd_used)),
            ROUND(AVG(cpu_load), 4),
            ROUND(AVG(network_in_bps)),
            ROUND(AVG(network_out_bps)),
            MAX(mail_mta),
            ROUND(AVG(mail_queue_total)),
            MAX(panel_profile)
        FROM metrics
        WHERE recorded_at < :cutoff
        GROUP BY server_id, FLOOR(UNIX_TIMESTAMP(recorded_at) / :interval_group_by)
        ON DUPLICATE KEY UPDATE
            uptime = GREATEST(metrics_history.uptime, VALUES(uptime)),
            ram_total = GREATEST(metrics_history.ram_total, VALUES(ram_total)),
            ram_used = VALUES(ram_used),
            hdd_total = GREATEST(metrics_history.hdd_total, VALUES(hdd_total)),
            hdd_used = VALUES(hdd_used),
            cpu_load = VALUES(cpu_load),
            network_in_bps = VALUES(network_in_bps),
            network_out_bps = VALUES(network_out_bps),
            mail_mta = VALUES(mail_mta),
            mail_queue_total = VALUES(mail_queue_total),
            panel_profile = VALUES(panel_profile)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':interval_group', $interval, PDO::PARAM_INT);
    $stmt->bindValue(':interval_select', $interval, PDO::PARAM_INT);
    $stmt->bindValue(':interval_group_by', $interval, PDO::PARAM_INT);
    $stmt->bindValue(':cutoff', $cutoffStr, PDO::PARAM_STR);
    $stmt->execute();
    $rowsInserted = $stmt->rowCount();

    servmon_log_info("Rollup inserted/updated {$rowsInserted} history rows.", 'rollup');

    // Delete the raw metrics that we just rolled up to keep `metrics` table lean
    $rowsDeleted = retention_batch_delete('metrics', 'recorded_at', $cutoffStr);

    servmon_log_info("Rollup deleted {$rowsDeleted} raw metrics.", 'rollup');
    worker_mark_run_success($workerName);
    
    echo "rollup_metrics completed: cutoff={$cutoffStr}, aggregated={$rowsInserted}, raw_deleted={$rowsDeleted}" . PHP_EOL;
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, "rollup_metrics failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
