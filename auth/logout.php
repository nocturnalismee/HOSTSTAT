<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/admin/dashboard.php');
    }
    audit_log('auth_logout', 'User logout via secure POST', 'auth');
    logout_user();
    redirect('/auth/login.php?logged_out=1');
}

if ($method === 'GET' && (string) ($_GET['compat'] ?? '') === '1') {
    // Deprecated compatibility path for old clients; prefer POST + CSRF logout.
    audit_log('auth_logout_legacy', 'User logout via deprecated GET compat path', 'auth');
    logout_user();
    flash_set('warning', 'Legacy logout link is deprecated. Please use secure logout button.');
    redirect('/auth/login.php?logged_out=1');
}

flash_set('warning', 'Use secure logout button.');
redirect('/admin/dashboard.php');
