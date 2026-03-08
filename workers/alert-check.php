<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/worker.php';

$workerName = 'alert_check';
worker_mark_run_start($workerName);

$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'servmon-alert-check-main.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp !== false) {
        fclose($lockFp);
    }
    worker_mark_run_success($workerName);
    echo "alert_check already running\n";
    exit(0);
}

try {
    evaluate_down_recovery_alerts();
    evaluate_current_service_down_alerts();
    evaluate_current_ping_down_alerts();

    $rows = db_all(
        'SELECT s.id, s.name,
                m.mail_queue_total, m.cpu_load, m.ram_used, m.ram_total, m.hdd_used, m.hdd_total
         FROM servers s' . latest_metric_join_sql('s', 'm') . '
         WHERE s.active = 1'
    );

    foreach ($rows as $row) {
        if ($row['ram_total'] === null) {
            continue;
        }
        evaluate_server_threshold_alerts(
            ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            [
                'mail_queue_total' => (int) ($row['mail_queue_total'] ?? 0),
                'cpu_load' => (float) ($row['cpu_load'] ?? 0),
                'ram_used' => (int) ($row['ram_used'] ?? 0),
                'ram_total' => (int) ($row['ram_total'] ?? 0),
                'hdd_used' => (int) ($row['hdd_used'] ?? 0),
                'hdd_total' => (int) ($row['hdd_total'] ?? 0),
            ]
        );
    }

    worker_mark_run_success($workerName);
    echo "alert_check completed\n";
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, "alert_check failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
