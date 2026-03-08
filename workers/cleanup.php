<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/retention.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/worker.php';

$workerName = 'retention_cleanup';
worker_mark_run_start($workerName);

try {
    $days = max(1, (int) setting_get('retention_days'));
    $result = run_core_retention_cleanup($days);

    worker_mark_run_success($workerName);
    echo 'retention_cleanup completed: '
        . 'cutoff=' . ($result['cutoff_at'] ?? '-')
        . ', '
        . 'history_cutoff=' . ($result['history_cutoff_at'] ?? '-')
        . ', '
        . 'service_metrics=' . ($result['service_metrics_deleted'] ?? 0)
        . ', '
        . 'metrics=' . $result['metrics_deleted']
        . ', metrics_history=' . ($result['metrics_history_deleted'] ?? 0)
        . ', alerts=' . $result['alerts_deleted']
        . ', attempts=' . $result['attempts_deleted']
        . ', audits=' . ($result['audits_deleted'] ?? 0)
        . PHP_EOL;
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, "retention_cleanup failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
