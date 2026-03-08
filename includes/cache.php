<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';

function redis_client(): ?Redis
{
    static $client = null;
    static $initialized = false;

    if ($initialized) {
        return $client;
    }
    $initialized = true;

    if (!REDIS_ENABLED || !class_exists('Redis')) {
        return null;
    }

    try {
        $redis = new Redis();
        $ok = $redis->connect(REDIS_HOST, REDIS_PORT, 2.5);
        if (!$ok) {
            return null;
        }
        if (REDIS_PASSWORD !== '') {
            $redis->auth(REDIS_PASSWORD);
        }
        if (REDIS_DB > 0) {
            $redis->select(REDIS_DB);
        }
        $client = $redis;
        return $client;
    } catch (Throwable $e) {
        return null;
    }
}

function cache_key(string $suffix): string
{
    return REDIS_PREFIX . $suffix;
}

function cache_get(string $key): mixed
{
    $redis = redis_client();
    if (!$redis) {
        return null;
    }
    $value = $redis->get(cache_key($key));
    if ($value === false) {
        return null;
    }
    return json_decode((string) $value, true);
}

function cache_set(string $key, mixed $value, int $ttlSeconds): void
{
    $redis = redis_client();
    if (!$redis || $ttlSeconds <= 0) {
        return;
    }
    $redis->setex(cache_key($key), $ttlSeconds, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function cache_delete(string $key): void
{
    $redis = redis_client();
    if (!$redis) {
        return;
    }
    $redis->del(cache_key($key));
}

function cache_delete_pattern(string $pattern): void
{
    $redis = redis_client();
    if (!$redis) {
        return;
    }

    $it = null;
    $fullPattern = cache_key($pattern);
    while (($keys = $redis->scan($it, $fullPattern, 100)) !== false) {
        if (!empty($keys)) {
            $redis->del($keys);
        }
        if ($it === 0) {
            break;
        }
    }
}

function cache_ttl(string $settingKey, int $fallback): int
{
    $v = (int) setting_get($settingKey);
    if ($v <= 0) {
        return $fallback;
    }
    return $v;
}

function invalidate_status_cache(?int $serverId = null): void
{
    cache_delete('status:list');
    cache_delete('status:list:active');
    cache_delete('status:list:all');
    if ($serverId !== null) {
        cache_delete('status:single:' . $serverId);
        cache_delete_pattern('status:history:' . $serverId . ':*');
    } else {
        cache_delete_pattern('status:single:*');
        cache_delete_pattern('status:history:*');
    }
}

function invalidate_alert_cache(): void
{
    cache_delete_pattern('alert:*');
}

function invalidate_ping_cache(?int $monitorId = null): void
{
    cache_delete_pattern('ping:list:*');
    if ($monitorId !== null) {
        cache_delete('ping:single:' . $monitorId);
        cache_delete_pattern('ping:history:' . $monitorId . ':*');
    } else {
        cache_delete_pattern('ping:single:*');
        cache_delete_pattern('ping:history:*');
    }
}
