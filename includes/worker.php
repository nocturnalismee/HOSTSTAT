<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function worker_health_read(string $workerName): array
{
    $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $workerName) ?? 'unknown', 0, 50);
    $row = db_one('SELECT * FROM worker_health WHERE worker_name = :name LIMIT 1', [':name' => $safe]);
    if ($row === null) {
        return [];
    }
    return $row;
}

function worker_health_write(string $workerName, array $data): void
{
    $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $workerName) ?? 'unknown', 0, 50);
    db_exec(
        'INSERT INTO worker_health (worker_name, last_state, last_started_at, last_success_at, last_failure_at, last_error)
         VALUES (:name, :state, :started, :success, :failure, :err)
         ON DUPLICATE KEY UPDATE
             last_state = VALUES(last_state),
             last_started_at = COALESCE(VALUES(last_started_at), last_started_at),
             last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
             last_failure_at = COALESCE(VALUES(last_failure_at), last_failure_at),
             last_error = COALESCE(VALUES(last_error), last_error)',
        [
            ':name' => $safe,
            ':state' => $data['last_state'] ?? 'running',
            ':started' => $data['last_started_at'] ?? null,
            ':success' => $data['last_success_at'] ?? null,
            ':failure' => $data['last_failure_at'] ?? null,
            ':err' => $data['last_error'] ?? null,
        ]
    );
}

function worker_mark_run_start(string $workerName): void
{
    $now = date('Y-m-d H:i:s');
    worker_health_write($workerName, [
        'last_state' => 'running',
        'last_started_at' => $now,
    ]);
}

function worker_mark_run_success(string $workerName): void
{
    $now = date('Y-m-d H:i:s');
    worker_health_write($workerName, [
        'last_state' => 'ok',
        'last_success_at' => $now,
        'last_error' => '',
    ]);
}

function worker_mark_run_failure(string $workerName, string $errorMessage): void
{
    $now = date('Y-m-d H:i:s');
    worker_health_write($workerName, [
        'last_state' => 'error',
        'last_failure_at' => $now,
        'last_error' => mb_substr(trim($errorMessage), 0, 500),
    ]);
}

function worker_health_status(string $workerName, int $staleSeconds): array
{
    $staleSeconds = max(30, $staleSeconds);
    $data = worker_health_read($workerName);
    
    $lastSuccessAt = (string) ($data['last_success_at'] ?? '');
    $lastState = (string) ($data['last_state'] ?? 'never');
    
    $ageSeconds = null;
    if ($lastSuccessAt !== '') {
        $ts = strtotime($lastSuccessAt);
        if ($ts !== false) {
            $ageSeconds = max(0, time() - $ts);
        }
    }

    $health = 'unknown';
    if ($lastState === 'error') {
        $health = 'error';
    } elseif ($ageSeconds !== null && $ageSeconds <= $staleSeconds) {
        $health = 'ok';
    } elseif ($ageSeconds !== null) {
        $health = 'stale';
    }

    return [
        'worker' => $workerName,
        'health' => $health,
        'stale_threshold_seconds' => $staleSeconds,
        'age_seconds' => $ageSeconds,
        'last_state' => $lastState,
        'last_started_at' => $data['last_started_at'] ?? null,
        'last_success_at' => $data['last_success_at'] ?? null,
        'last_failure_at' => $data['last_failure_at'] ?? null,
        'last_error' => $data['last_error'] ?? '',
    ];
}
