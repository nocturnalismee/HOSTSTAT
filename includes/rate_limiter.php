<?php
declare(strict_types=1);

/**
 * File-based API rate limiter for servmon.
 *
 * Uses sliding window counter pattern with temp directory storage.
 * No Redis dependency required.
 */

function api_rate_limit_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'servmon-rate-limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Check if a request should be rate limited.
 *
 * Uses flock() to wrap the entire read+check+write cycle for atomicity,
 * preventing TOCTOU race conditions under concurrent requests.
 *
 * @return bool true if request is ALLOWED, false if rate limited
 */
function api_rate_check(string $endpoint, string $ip, int $maxPerMinute = 60): bool
{
    $key = md5($endpoint . ':' . $ip);
    $file = api_rate_limit_dir() . DIRECTORY_SEPARATOR . $key . '.dat';
    $now = time();
    $windowStart = $now - 60;

    // Acquire exclusive lock for atomic read+check+write
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        // Unable to open file — allow request (fail-open)
        return true;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }

    $timestamps = [];
    $raw = stream_get_contents($fp);
    if ($raw !== false && $raw !== '') {
        $timestamps = array_filter(
            array_map('intval', explode("\n", trim($raw))),
            static fn(int $ts): bool => $ts > $windowStart
        );
    }

    if (count($timestamps) >= $maxPerMinute) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $timestamps[] = $now;

    // Rewrite the file with updated timestamps
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, implode("\n", $timestamps));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

/**
 * Send a 429 Too Many Requests response and exit.
 */
function api_rate_limit_exceeded(): void
{
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    exit;
}

/**
 * Cleanup stale rate limit files older than 5 minutes.
 * Called occasionally to prevent temp dir bloat.
 */
function api_rate_limit_cleanup(): void
{
    $dir = api_rate_limit_dir();
    $cutoff = time() - 300;
    $files = @glob($dir . '/*.dat');
    if ($files === false) {
        return;
    }
    foreach ($files as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
