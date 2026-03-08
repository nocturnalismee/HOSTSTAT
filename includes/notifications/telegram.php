<?php
declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * Telegram notification channel for servmon.
 */

function notify_telegram(string $message, array $settings): bool
{
    if (($settings['channel_telegram_enabled'] ?? '0') !== '1') {
        return false;
    }
    $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
    $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        return false;
    }
    if (!function_exists('curl_init')) {
        servmon_log_error('curl extension not available for Telegram', 'telegram');
        return false;
    }

    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $threadId = trim((string) ($settings['telegram_thread_id'] ?? ''));
    if ($threadId !== '') {
        $payload['message_thread_id'] = $threadId;
    }

    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($res === false || $code < 200 || $code >= 300) {
        servmon_log_error('Telegram send failed', 'telegram', [
            'http_code' => $code,
            'curl_error' => $curlError,
            'chat_id' => $chatId,
        ]);
        return false;
    }

    servmon_log_info('Telegram message sent', 'telegram', ['chat_id' => $chatId]);
    return true;
}
