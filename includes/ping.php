<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function ping_normalize_target(string $target): string
{
    return trim($target);
}

function ping_normalize_target_type(?string $targetType): string
{
    $value = strtolower(trim((string) $targetType));
    return in_array($value, ['ip', 'domain', 'url'], true) ? $value : 'domain';
}

function ping_normalize_check_method(?string $checkMethod): string
{
    $value = strtolower(trim((string) $checkMethod));
    return in_array($value, ['icmp', 'http'], true) ? $value : 'icmp';
}

function ping_validate_target(string $target, string $targetType, string $checkMethod = 'icmp'): bool
{
    $target = ping_normalize_target($target);
    $checkMethod = ping_normalize_check_method($checkMethod);
    if ($target === '') {
        return false;
    }

    if ($checkMethod === 'http') {
        if (filter_var($target, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($target);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    if ($targetType === 'ip') {
        return filter_var($target, FILTER_VALIDATE_IP) !== false;
    }

    if (strlen($target) > 253) {
        return false;
    }

    if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $target) !== 1) {
        return false;
    }

    return true;
}

function ping_display_status(?string $lastStatus, int $active): string
{
    if ($active !== 1) {
        return 'paused';
    }

    $normalized = strtolower(trim((string) $lastStatus));
    if ($normalized === 'up' || $normalized === 'down') {
        return $normalized;
    }
    return 'pending';
}

function ping_probe_command(string $target, int $timeoutSeconds): string
{
    $timeoutSeconds = max(1, min(10, $timeoutSeconds));
    $safeTarget = escapeshellarg($target);
    $family = PHP_OS_FAMILY;

    if ($family === 'Windows') {
        $timeoutMs = $timeoutSeconds * 1000;
        return "ping -n 1 -w {$timeoutMs} {$safeTarget}";
    }

    return "ping -n -c 1 -W {$timeoutSeconds} {$safeTarget}";
}

function ping_probe_target_icmp(string $target, int $timeoutSeconds): array
{
    if (!function_exists('exec')) {
        return [
            'status' => 'down',
            'latency_ms' => null,
            'error' => 'exec disabled on PHP runtime',
            'raw_output' => '',
        ];
    }

    $target = ping_normalize_target($target);
    $start = microtime(true);
    $output = [];
    $exitCode = 1;
    $cmd = ping_probe_command($target, $timeoutSeconds);
    exec($cmd . ' 2>&1', $output, $exitCode);
    $elapsedMs = round((microtime(true) - $start) * 1000, 2);
    $raw = trim(implode("\n", $output));

    $latency = null;
    if (preg_match('/time[=<]\s*([0-9]+(?:\.[0-9]+)?)\s*ms/i', $raw, $match) === 1) {
        $latency = (float) $match[1];
    } elseif ($exitCode === 0) {
        $latency = $elapsedMs;
    }

    $status = $exitCode === 0 ? 'up' : 'down';
    $error = null;
    if ($status !== 'up') {
        $error = $raw !== '' ? substr($raw, 0, 255) : 'ping failed';
    }

    return [
        'status' => $status,
        'latency_ms' => $latency,
        'error' => $error,
        'raw_output' => $raw,
    ];
}

function ping_probe_target_http(string $target, int $timeoutSeconds): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => 'down',
            'latency_ms' => null,
            'error' => 'curl extension is required for HTTP checks',
            'raw_output' => '',
        ];
    }

    $timeoutSeconds = max(1, min(30, $timeoutSeconds));
    $target = ping_normalize_target($target);
    $ch = curl_init($target);
    if ($ch === false) {
        return [
            'status' => 'down',
            'latency_ms' => null,
            'error' => 'failed to initialize curl',
            'raw_output' => '',
        ];
    }
    $baseOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => 'servmon-ping-http-check/1.0',
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $baseOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS')) {
        $baseOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    curl_setopt_array($ch, $baseOptions + [
        CURLOPT_NOBODY => true,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
    ]);
    curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $totalTime = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);

    if ($curlErrNo === 0 && $httpCode === 405) {
        curl_setopt_array($ch, $baseOptions + [
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data): int {
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $totalTime = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    }

    curl_close($ch);

    if ($curlErrNo !== 0) {
        return [
            'status' => 'down',
            'latency_ms' => null,
            'error' => substr($curlErr !== '' ? $curlErr : ('curl error #' . $curlErrNo), 0, 255),
            'raw_output' => '',
        ];
    }

    $latencyMs = round($totalTime * 1000, 2);
    $status = ($httpCode >= 200 && $httpCode < 400) ? 'up' : 'down';
    $error = $status === 'up' ? null : ('HTTP status ' . $httpCode);

    return [
        'status' => $status,
        'latency_ms' => $latencyMs,
        'error' => $error,
        'raw_output' => 'http_code=' . $httpCode,
    ];
}

function ping_probe_target(string $target, int $timeoutSeconds, string $checkMethod = 'icmp'): array
{
    $checkMethod = ping_normalize_check_method($checkMethod);
    if ($checkMethod === 'http') {
        return ping_probe_target_http($target, $timeoutSeconds);
    }
    return ping_probe_target_icmp($target, $timeoutSeconds);
}

function ping_due_monitors(int $limit = 500): array
{
    $limit = max(1, min(5000, $limit));
    $sql = "SELECT pm.id, pm.name, pm.target, pm.target_type, pm.check_method, pm.check_interval_seconds, pm.timeout_seconds, pm.failure_threshold, pm.active,
                   ps.last_status, ps.consecutive_failures, ps.last_checked_at
            FROM ping_monitors pm
            LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
            WHERE pm.active = 1
            AND (
                ps.last_checked_at IS NULL
                OR TIMESTAMPDIFF(SECOND, ps.last_checked_at, NOW()) >= pm.check_interval_seconds
            )
            ORDER BY COALESCE(ps.last_checked_at, '1970-01-01 00:00:00') ASC, pm.id ASC
            LIMIT :limit";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function ping_record_check(int $monitorId, array $probe, int $failureThreshold): array
{
    $failureThreshold = max(1, min(10, $failureThreshold));
    $previous = db_one(
        'SELECT last_status, consecutive_failures
         FROM ping_monitor_states
         WHERE monitor_id = :monitor_id
         LIMIT 1',
        [':monitor_id' => $monitorId]
    );
    $prevStatus = strtolower((string) ($previous['last_status'] ?? 'unknown'));
    $prevFailures = max(0, (int) ($previous['consecutive_failures'] ?? 0));

    $probeStatus = (string) ($probe['status'] ?? 'down');
    $latencyMs = isset($probe['latency_ms']) ? (float) $probe['latency_ms'] : null;
    $error = trim((string) ($probe['error'] ?? ''));
    $error = $error !== '' ? substr($error, 0, 255) : null;

    $consecutiveFailures = $probeStatus === 'up' ? 0 : ($prevFailures + 1);
    $currentStatus = $probeStatus === 'up'
        ? 'up'
        : ($consecutiveFailures >= $failureThreshold ? 'down' : 'unknown');

    db_exec(
        'INSERT INTO ping_checks (monitor_id, status, latency_ms, error_message, checked_at)
         VALUES (:monitor_id, :status, :latency_ms, :error_message, NOW())',
        [
            ':monitor_id' => $monitorId,
            ':status' => $probeStatus === 'up' ? 'up' : 'down',
            ':latency_ms' => $latencyMs,
            ':error_message' => $error,
        ]
    );

    db_exec(
        'INSERT INTO ping_monitor_states (
            monitor_id, last_status, consecutive_failures, last_latency_ms, last_error, last_checked_at, last_change_at, updated_at
         ) VALUES (
            :monitor_id, :last_status, :consecutive_failures, :last_latency_ms, :last_error, NOW(), NOW(), NOW()
         )
         ON DUPLICATE KEY UPDATE
            last_status = VALUES(last_status),
            consecutive_failures = VALUES(consecutive_failures),
            last_latency_ms = VALUES(last_latency_ms),
            last_error = VALUES(last_error),
            last_checked_at = NOW(),
            last_change_at = IF(last_status <> VALUES(last_status), NOW(), last_change_at),
            updated_at = NOW()',
        [
            ':monitor_id' => $monitorId,
            ':last_status' => $currentStatus,
            ':consecutive_failures' => $consecutiveFailures,
            ':last_latency_ms' => $latencyMs,
            ':last_error' => $error,
        ]
    );

    return [
        'previous_status' => $prevStatus,
        'current_status' => $currentStatus,
        'probe_status' => $probeStatus,
        'changed' => $prevStatus !== $currentStatus,
        'consecutive_failures' => $consecutiveFailures,
    ];
}
