<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $row = db_one(
        'SELECT id, username, role, last_login, created_at FROM users WHERE id = :id LIMIT 1',
        [':id' => (int) $userId]
    );
    return $row;
}

function require_login(): void
{
    $now = time();
    $idleTimeoutMinutes = max(5, (int) setting_get('session_idle_timeout_minutes'));
    $absoluteTimeoutMinutes = max(15, (int) setting_get('session_absolute_timeout_minutes'));

    $loginAt = (int) ($_SESSION['login_at'] ?? 0);
    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);
    
    $currentUserAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $sessionUserAgent = (string) ($_SESSION['user_agent'] ?? '');

    if ($loginAt > 0) {
        $idleExpired = $lastActivityAt > 0 && ($now - $lastActivityAt) > ($idleTimeoutMinutes * 60);
        $absoluteExpired = ($now - $loginAt) > ($absoluteTimeoutMinutes * 60);
        $agentMismatch = $sessionUserAgent !== '' && $currentUserAgent !== $sessionUserAgent;
        
        if ($idleExpired || $absoluteExpired || $agentMismatch) {
            logout_user();
            flash_set('warning', 'Session expired or invalidated. Please log in again.');
            redirect('/auth/login.php');
        }
    }

    if (current_user() === null) {
        flash_set('warning', 'Please log in to continue.');
        redirect('/auth/login.php');
    }

    $_SESSION['last_activity_at'] = $now;
}

function role_rank(string $role): int
{
    return match ($role) {
        'admin' => 20,
        'viewer' => 10,
        default => 0,
    };
}

function has_role(string $requiredRole): bool
{
    $user = current_user();
    if ($user === null) {
        return false;
    }

    $currentRole = (string) ($user['role'] ?? '');
    return role_rank($currentRole) >= role_rank($requiredRole);
}

function require_role(string $requiredRole): void
{
    require_login();
    if (has_role($requiredRole)) {
        return;
    }

    flash_set('danger', 'Insufficient permission to access this action.');
    redirect('/admin/dashboard.php');
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $now = time();
    $_SESSION['login_at'] = $now;
    $_SESSION['last_activity_at'] = $now;
    $_SESSION['user_agent'] = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function is_ip_rate_limited(string $ip): bool
{
    db_exec(
        'DELETE FROM login_attempts WHERE TIMESTAMPDIFF(MINUTE, attempted_at, NOW()) >= :window',
        [':window' => LOGIN_WINDOW_MINUTES]
    );

    $attempt = db_one(
        'SELECT COUNT(*) AS total FROM login_attempts
         WHERE ip_address = :ip
         AND TIMESTAMPDIFF(MINUTE, attempted_at, NOW()) < :window',
        [':ip' => $ip, ':window' => LOGIN_WINDOW_MINUTES]
    );

    return ((int) ($attempt['total'] ?? 0)) >= LOGIN_MAX_ATTEMPTS;
}

function register_login_attempt(string $ip, ?string $username): void
{
    db_exec(
        'INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (:ip, :username, NOW())',
        [':ip' => $ip, ':username' => $username]
    );
}

function clear_login_attempts(string $ip): void
{
    db_exec('DELETE FROM login_attempts WHERE ip_address = :ip', [':ip' => $ip]);
}
