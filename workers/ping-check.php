<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/ping.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/worker.php';

$workerName = 'ping_check';
worker_mark_run_start($workerName);

$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'servmon-ping-check.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp !== false) {
        fclose($lockFp);
    }
    worker_mark_run_success($workerName);
    echo "ping_check already running\n";
    exit(0);
}

try {
    $dueMonitors = ping_due_monitors(1000);
    $checked = 0;
    $changed = 0;

    foreach ($dueMonitors as $monitor) {
        $monitorId = (int) ($monitor['id'] ?? 0);
        if ($monitorId <= 0) {
            continue;
        }

        $probe = ping_probe_target(
            (string) ($monitor['target'] ?? ''),
            max(1, (int) ($monitor['timeout_seconds'] ?? 2)),
            (string) ($monitor['check_method'] ?? 'icmp')
        );
        $transition = ping_record_check(
            $monitorId,
            $probe,
            max(1, (int) ($monitor['failure_threshold'] ?? 2))
        );

        if (($transition['changed'] ?? false) === true) {
            $changed++;
            evaluate_ping_monitor_transition_alert($monitor, $transition, $probe);
        }

        $checked++;
    }

    if ($checked > 0) {
        invalidate_ping_cache();
    }

    worker_mark_run_success($workerName);
    echo 'ping_check completed. checked=' . $checked . ' changed=' . $changed . PHP_EOL;
} catch (Throwable $e) {
    worker_mark_run_failure($workerName, $e->getMessage());
    fwrite(STDERR, 'ping_check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
