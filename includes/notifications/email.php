<?php
declare(strict_types=1);

require_once __DIR__ . '/../logger.php';

/**
 * Email notification channel for servmon.
 * Supports both PHP mail() and custom SMTP socket transport.
 */

function sanitize_email_header(string $value): string
{
    return preg_replace('/[\r\n\t]/', '', trim($value));
}

function sanitize_email_address(string $email): string
{
    $email = trim($email);
    $email = preg_replace('/[\r\n\t]/', '', $email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }
    return $email;
}

function sanitize_subject(string $subject): string
{
    $subject = trim($subject);
    $subject = preg_replace('/[\r\n]/', '', $subject);
    return $subject;
}

function notify_email(string $subject, string $message, array $settings): bool
{
    if (($settings['channel_email_enabled'] ?? '0') !== '1') {
        return false;
    }
    $to = sanitize_email_address((string) ($settings['smtp_to_email'] ?? ''));
    if ($to === '') {
        return false;
    }

    $fromEmail = sanitize_email_address((string) ($settings['smtp_from_email'] ?? ''));
    $fromName = sanitize_email_header((string) ($settings['smtp_from_name'] ?? 'servmon'));
    $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));
    $subject = sanitize_subject($subject);

    if ($smtpHost !== '') {
        return smtp_send_mail($to, $subject, $message, $fromEmail !== '' ? $fromEmail : 'noreply@localhost', $fromName, $settings);
    }

    $headers = "MIME-Version: 1.0\r\nContent-type: text/plain; charset=UTF-8\r\n";
    if ($fromEmail !== '') {
        $headers .= 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
    }

    $ok = @mail($to, $subject, $message, $headers);
    if (!$ok) {
        servmon_log_error('mail() failed for subject: ' . $subject, 'email', ['to' => $to]);
    }
    return $ok;
}

function smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }
    return $response;
}

function smtp_expect($socket, array $expectedCodes): bool
{
    $resp = smtp_read($socket);
    if ($resp === '') {
        return false;
    }
    $code = (int) substr($resp, 0, 3);
    return in_array($code, $expectedCodes, true);
}

function smtp_send_cmd($socket, string $cmd, array $expectedCodes): bool
{
    fwrite($socket, $cmd . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_send_mail(string $to, string $subject, string $message, string $fromEmail, string $fromName, array $settings): bool
{
    $to = sanitize_email_address($to);
    $fromEmail = sanitize_email_address($fromEmail);
    $fromName = sanitize_email_header($fromName);
    $subject = sanitize_subject($subject);
    
    if ($to === '' || $fromEmail === '') {
        return false;
    }
    $host = (string) ($settings['smtp_host'] ?? '');
    $port = (int) ($settings['smtp_port'] ?? 587);
    $secure = strtolower((string) ($settings['smtp_secure'] ?? 'tls'));
    $username = (string) ($settings['smtp_username'] ?? '');
    $password = (string) ($settings['smtp_password'] ?? '');

    $transportHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 3);
    if (!$socket) {
        servmon_log_error('SMTP connect failed: ' . $errstr, 'email', ['host' => $host, 'port' => $port, 'errno' => $errno]);
        return false;
    }

    stream_set_timeout($socket, 3);
    if (!smtp_expect($socket, [220])) {
        servmon_log_error('SMTP greeting failed', 'email', ['host' => $host]);
        fclose($socket);
        return false;
    }

    if (!smtp_send_cmd($socket, 'EHLO servmon', [250])) {
        servmon_log_error('SMTP EHLO failed', 'email', ['host' => $host]);
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        if (!smtp_send_cmd($socket, 'STARTTLS', [220])) {
            servmon_log_error('SMTP STARTTLS failed', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            servmon_log_error('SMTP TLS crypto upgrade failed', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }
        if (!smtp_send_cmd($socket, 'EHLO servmon', [250])) {
            servmon_log_error('SMTP EHLO after STARTTLS failed', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }
    }

    if ($username !== '') {
        if (!smtp_send_cmd($socket, 'AUTH LOGIN', [334])) {
            servmon_log_error('SMTP AUTH LOGIN failed', 'email', ['host' => $host, 'username' => $username]);
            fclose($socket);
            return false;
        }
        if (!smtp_send_cmd($socket, base64_encode($username), [334])) {
            servmon_log_error('SMTP AUTH username rejected', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }
        if (!smtp_send_cmd($socket, base64_encode($password), [235])) {
            servmon_log_error('SMTP AUTH password rejected', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }
    }

    if (!smtp_send_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
        servmon_log_error('SMTP MAIL FROM rejected', 'email', ['from' => $fromEmail]);
        fclose($socket);
        return false;
    }
    if (!smtp_send_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251])) {
        servmon_log_error('SMTP RCPT TO rejected', 'email', ['to' => $to]);
        fclose($socket);
        return false;
    }
    if (!smtp_send_cmd($socket, 'DATA', [354])) {
        servmon_log_error('SMTP DATA command rejected', 'email');
        fclose($socket);
        return false;
    }

    $data = "From: {$fromName} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $message . "\r\n.";
    if (!smtp_send_cmd($socket, $data, [250])) {
        servmon_log_error('SMTP DATA send failed', 'email', ['subject' => $subject]);
        fclose($socket);
        return false;
    }

    smtp_send_cmd($socket, 'QUIT', [221]);
    fclose($socket);

    servmon_log_info('Email sent successfully', 'email', ['to' => $to, 'subject' => $subject]);
    return true;
}
