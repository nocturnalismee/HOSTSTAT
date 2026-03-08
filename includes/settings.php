<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/cache.php';

/** @var array<string,string>|null */
$SERVMON_SETTINGS_CACHE = null;

function settings_defaults(): array
{
    return [
        'branding_logo_url' => '',
        'branding_favicon_url' => '',
        'alert_down_minutes' => '5',
        'alert_cooldown_minutes' => '30',
        'alert_service_status_enabled' => '1',
        'threshold_mail_queue' => '50',
        'threshold_mail_queue_critical' => '100',
        'threshold_cpu_load' => '2.00',
        'threshold_cpu_load_critical' => '4.00',
        'threshold_ram_pct' => '85',
        'threshold_ram_pct_critical' => '95',
        'threshold_disk_pct' => '90',
        'threshold_disk_pct_critical' => '97',
        'alert_service_flap_suppress_minutes' => '5',
        'alert_ping_enabled' => '1',
        'cache_ttl_status_list' => '15',
        'cache_ttl_status_single' => '15',
        'cache_ttl_history_24h' => '30',
        'cache_ttl_history_7d' => '120',
        'cache_ttl_history_30d' => '180',
        'cache_ttl_alert_logs' => '20',
        'cache_ttl_disk_health_list' => '15',
        'cache_ttl_disk_health_single' => '10',
        'cache_ttl_disk_health_history' => '20',
        'disk_rollup_days' => '2',
        'disk_push_max_body_bytes' => '1048576',
        'disk_push_max_items' => '64',
        'public_alerts_redact_message' => '0',
        'session_idle_timeout_minutes' => '60',
        'session_absolute_timeout_minutes' => '480',
        'channel_email_enabled' => '0',
        'channel_telegram_enabled' => '0',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_secure' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => 'servmon',
        'smtp_to_email' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'telegram_thread_id' => '',
        'retention_days' => '30',
        'disk_retention_days' => '90',
        'agent_push_signature_required' => '0',
    ];
}

function settings_is_sensitive(string $key): bool
{
    $sensitiveKeys = ['smtp_password', 'telegram_bot_token'];
    return in_array($key, $sensitiveKeys, true);
}

function settings_encrypt(string $value): string
{
    if ($value === '' || !defined('APP_KEY') || APP_KEY === '') {
        return $value;
    }
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', APP_KEY, 0, $iv);
    if ($encrypted === false) {
        return $value;
    }
    return 'ENC:' . base64_encode($iv . $encrypted);
}

function settings_decrypt(string $value): string
{
    if (!str_starts_with($value, 'ENC:') || !defined('APP_KEY') || APP_KEY === '') {
        return $value;
    }
    $data = base64_decode(substr($value, 4));
    if ($data === false) {
        return '';
    }
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', APP_KEY, 0, $iv);
    return $decrypted !== false ? $decrypted : '';
}

function settings_get_all(): array
{
    global $SERVMON_SETTINGS_CACHE;
    if (is_array($SERVMON_SETTINGS_CACHE)) {
        return $SERVMON_SETTINGS_CACHE;
    }

    $redisKey = 'settings:all';
    $cached = cache_get($redisKey);
    if (is_array($cached)) {
        // Redis cache does NOT contain sensitive values — re-fetch them from DB
        $sensitiveKeys = array_filter(array_keys(settings_defaults()), 'settings_is_sensitive');
        if (!empty($sensitiveKeys)) {
            $rows = db_all('SELECT setting_key, setting_value FROM app_settings');
            foreach ($rows as $row) {
                $k = (string) $row['setting_key'];
                if (in_array($k, $sensitiveKeys, true)) {
                    $cached[$k] = settings_decrypt((string) $row['setting_value']);
                }
            }
        }
        $SERVMON_SETTINGS_CACHE = $cached;
        return $SERVMON_SETTINGS_CACHE;
    }

    $defaults = settings_defaults();
    $rows = db_all('SELECT setting_key, setting_value FROM app_settings');
    $data = $defaults;
    foreach ($rows as $row) {
        $k = (string) $row['setting_key'];
        $v = (string) $row['setting_value'];
        if (settings_is_sensitive($k)) {
            $v = settings_decrypt($v);
        }
        $data[$k] = $v;
    }
    
    $SERVMON_SETTINGS_CACHE = $data;

    // Cache in Redis but strip sensitive values (they remain in PHP memory only)
    $safeForRedis = $data;
    foreach (array_keys($safeForRedis) as $k) {
        if (settings_is_sensitive($k)) {
            unset($safeForRedis[$k]);
        }
    }
    cache_set($redisKey, $safeForRedis, 300);

    return $SERVMON_SETTINGS_CACHE;
}

function setting_get(string $key): string
{
    $all = settings_get_all();
    return (string) ($all[$key] ?? '');
}

function settings_set(string $key, string $value): void
{
    if (settings_is_sensitive($key)) {
        $value = settings_encrypt($value);
    }
    db_exec(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [':key' => $key, ':value' => $value]
    );
    global $SERVMON_SETTINGS_CACHE;
    $SERVMON_SETTINGS_CACHE = null;
    cache_delete('settings:all');
}

function settings_save_many(array $values): void
{
    foreach ($values as $k => $v) {
        settings_set((string) $k, (string) $v);
    }
}
