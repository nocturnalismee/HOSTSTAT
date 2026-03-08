<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Structured logging framework for servmon.
 *
 * Writes to var/logs/servmon-YYYY-MM-DD.log with format:
 * [DATETIME] [LEVEL] [context] message
 *
 * Levels: DEBUG, INFO, WARN, ERROR
 */

define('SERVMON_LOG_DEBUG', 'DEBUG');
define('SERVMON_LOG_INFO', 'INFO');
define('SERVMON_LOG_WARN', 'WARN');
define('SERVMON_LOG_ERROR', 'ERROR');

function servmon_log_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function servmon_log(string $level, string $message, string $context = 'app', array $extra = []): void
{
    $validLevels = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
    $level = strtoupper($level);
    if (!in_array($level, $validLevels, true)) {
        $level = 'INFO';
    }

    if ($level === 'DEBUG' && APP_ENV === 'production') {
        return;
    }

    $date = date('Y-m-d');
    $datetime = date('Y-m-d H:i:s');
    $logFile = servmon_log_dir() . DIRECTORY_SEPARATOR . 'servmon-' . $date . '.log';

    $line = sprintf('[%s] [%s] [%s] %s', $datetime, $level, $context, $message);

    if (!empty($extra)) {
        $line .= ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function servmon_log_info(string $message, string $context = 'app', array $extra = []): void
{
    servmon_log(SERVMON_LOG_INFO, $message, $context, $extra);
}

function servmon_log_warn(string $message, string $context = 'app', array $extra = []): void
{
    servmon_log(SERVMON_LOG_WARN, $message, $context, $extra);
}

function servmon_log_error(string $message, string $context = 'app', array $extra = []): void
{
    servmon_log(SERVMON_LOG_ERROR, $message, $context, $extra);
}

function servmon_log_debug(string $message, string $context = 'app', array $extra = []): void
{
    servmon_log(SERVMON_LOG_DEBUG, $message, $context, $extra);
}
