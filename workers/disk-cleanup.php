<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/retention.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/worker.php';

$workerName = 'disk_retention_cleanup';
worker_mark_run_start($workerName);

try {
    $days = max(1, (int) setting_get('disk_retention_days'));
    $result = run_disk_retention_cleanup($days);

    worker_mark_run_success($workerName);
    echo 'disk_retention_cleanup completed: '
        . 'cutoff=' . ($result['cutoff_at'] ?? '-')
        . ', '
        . 'history_cutoff=' . ($result['history_cutoff_at'] ?? '-')
        . ', '
        . 'disk_metrics=' . ($result['disk_metrics_deleted'] ?? 0)
        . ', disk_history=' . ($result['disk_history_deleted'] ?? 0)
        . PHP_EOL;
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, "disk_retention_cleanup failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

