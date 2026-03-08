<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/worker.php';
require_once __DIR__ . '/../includes/logger.php';

function disk_rollup_tables_ready(): bool
{
    try {
        $rows = db_all(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('disk_health_metrics', 'disk_health_metrics_history')"
        );
    } catch (Throwable) {
        return false;
    }
    $map = [];
    foreach ($rows as $row) {
        $name = (string) ($row['table_name'] ?? '');
        if ($name !== '') {
            $map[$name] = true;
        }
    }
    return isset($map['disk_health_metrics'], $map['disk_health_metrics_history']);
}

$workerName = 'disk_history_rollup';
worker_mark_run_start($workerName);

$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'servmon-disk-rollup.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp !== false) {
        fclose($lockFp);
    }
    worker_mark_run_success($workerName);
    echo "disk_history_rollup already running\n";
    exit(0);
}

try {
    if (!disk_rollup_tables_ready()) {
        worker_mark_run_success($workerName);
        echo "disk_history_rollup skipped: tables not ready\n";
        exit(0);
    }

    $configuredDays = (int) trim((string) setting_get('disk_rollup_days'));
    $days = max(1, $configuredDays > 0 ? $configuredDays : 2);
    $cutoffStr = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $pdo = db();
    $sql = "
        INSERT INTO disk_health_metrics_history (
            server_id, disk_key, recorded_date,
            health_score_avg, temperature_avg, temperature_max,
            power_on_time_max, total_written_bytes_max, worst_status
        )
        SELECT
            server_id,
            disk_key,
            DATE(recorded_at) AS recorded_date,
            ROUND(AVG(health_score), 2) AS health_score_avg,
            ROUND(AVG(temperature_c), 2) AS temperature_avg,
            MAX(temperature_c) AS temperature_max,
            MAX(power_on_time) AS power_on_time_max,
            MAX(total_written_bytes) AS total_written_bytes_max,
            CASE MAX(
                CASE health_status
                    WHEN 'critical' THEN 4
                    WHEN 'warning' THEN 3
                    WHEN 'ok' THEN 2
                    ELSE 1
                END
            )
                WHEN 4 THEN 'critical'
                WHEN 3 THEN 'warning'
                WHEN 2 THEN 'ok'
                ELSE 'unknown'
            END AS worst_status
        FROM disk_health_metrics
        WHERE recorded_at < :cutoff
        GROUP BY server_id, disk_key, DATE(recorded_at)
        ON DUPLICATE KEY UPDATE
            health_score_avg = VALUES(health_score_avg),
            temperature_avg = VALUES(temperature_avg),
            temperature_max = VALUES(temperature_max),
            power_on_time_max = VALUES(power_on_time_max),
            total_written_bytes_max = VALUES(total_written_bytes_max),
            worst_status = VALUES(worst_status)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cutoff' => $cutoffStr]);
    $affected = $stmt->rowCount();

    servmon_log_info(
        'Disk history rollup completed',
        'disk_rollup',
        ['cutoff_at' => $cutoffStr, 'affected_rows' => $affected, 'days' => $days]
    );

    worker_mark_run_success($workerName);
    echo 'disk_history_rollup completed: cutoff=' . $cutoffStr . ', affected=' . $affected . PHP_EOL;
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, 'disk_history_rollup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
