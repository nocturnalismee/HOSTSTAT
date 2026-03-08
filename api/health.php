<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/worker.php';

$checks = [];
$status = 'ok';

try {
    $row = db_one('SELECT 1 AS ok');
    $checks['db'] = ['status' => (($row['ok'] ?? null) == 1 ? 'ok' : 'error')];
} catch (Throwable $e) {
    $checks['db'] = ['status' => 'error', 'error' => $e->getMessage()];
}
if (($checks['db']['status'] ?? 'error') !== 'ok') {
    $status = 'degraded';
}

if (!REDIS_ENABLED) {
    $checks['redis'] = ['status' => 'disabled'];
} else {
    $redis = redis_client();
    $checks['redis'] = $redis ? ['status' => 'ok'] : ['status' => 'error'];
    if (($checks['redis']['status'] ?? 'error') !== 'ok') {
        $status = 'degraded';
    }
}

$alertWorker = worker_health_status('alert_check', 180);
$pingWorker = worker_health_status('ping_check', 300);
$retentionWorker = worker_health_status('retention_cleanup', 129600);
$rollupWorker = worker_health_status('rollup_metrics', 7200);
$checks['workers'] = [
    'alert_check' => $alertWorker,
    'ping_check' => $pingWorker,
    'retention_cleanup' => $retentionWorker,
    'rollup_metrics' => $rollupWorker,
];

if (($alertWorker['health'] ?? 'unknown') !== 'ok') {
    $status = 'degraded';
}
if (($pingWorker['health'] ?? 'unknown') !== 'ok') {
    $status = 'degraded';
}
if (($retentionWorker['health'] ?? 'unknown') === 'error') {
    $status = 'degraded';
}
if (($rollupWorker['health'] ?? 'unknown') === 'error') {
    $status = 'degraded';
}

json_response([
    'status' => $status,
    'service' => 'servmon',
    'time' => date('Y-m-d H:i:s'),
    'checks' => $checks,
], $status === 'ok' ? 200 : 503);
