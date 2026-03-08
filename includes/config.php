<?php
declare(strict_types=1);

if (!defined('SERVMON_BOOTSTRAPPED')) {
    define('SERVMON_BOOTSTRAPPED', true);
}

/** @var array<string, string> $SERVMON_LOCAL_CONFIG */
$SERVMON_LOCAL_CONFIG = [];
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    $loaded = require $localConfigFile;
    if (is_array($loaded)) {
        $SERVMON_LOCAL_CONFIG = array_map(static fn ($v): string => (string) $v, $loaded);
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string) $value;
    }

    global $SERVMON_LOCAL_CONFIG;
    if (array_key_exists($key, $SERVMON_LOCAL_CONFIG) && $SERVMON_LOCAL_CONFIG[$key] !== '') {
        return $SERVMON_LOCAL_CONFIG[$key];
    }

    return $default;
}

define('APP_NAME', env('APP_NAME', 'servmon'));
define('APP_ENV', env('APP_ENV', 'development'));
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
define('APP_TZ', env('APP_TZ', 'Asia/Jakarta'));
define('APP_KEY', env('APP_KEY', ''));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'servmon'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('REDIS_ENABLED', env('REDIS_ENABLED', '0') === '1');
define('REDIS_HOST', env('REDIS_HOST', '127.0.0.1'));
define('REDIS_PORT', (int) env('REDIS_PORT', '6379'));
define('REDIS_PASSWORD', env('REDIS_PASSWORD', ''));
define('REDIS_DB', (int) env('REDIS_DB', '0'));
define('REDIS_PREFIX', env('REDIS_PREFIX', 'servmon:'));
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_MINUTES', 5);
define('STATUS_ONLINE_MINUTES', 2);
define('STATUS_DOWN_MINUTES', 5);

if (APP_ENV === 'production') {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-XSS-Protection: 1; mode=block');
    
    $cspPolicy = "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https:; " .
        "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "connect-src 'self'; " .
        "frame-ancestors 'self'; " .
        "form-action 'self'; " .
        "base-uri 'self';";
    header('Content-Security-Policy: ' . $cspPolicy);
    
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
}

date_default_timezone_set(APP_TZ);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('servmon_session');
    session_start();
}
