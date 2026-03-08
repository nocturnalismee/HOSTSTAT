<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';

if (current_user() !== null) {
    redirect('/admin/dashboard.php');
}

if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    flash_set('success', 'You have logged out.');
}

if (is_post()) {
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect('/auth/login.php');
    }

    $ip = get_client_ip();
    if (is_ip_rate_limited($ip)) {
        flash_set('danger', 'Too many login attempts. Please try again in 5 minutes.');
        redirect('/auth/login.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $user = db_one('SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1', [':username' => $username]);

    if ($user !== null && password_verify($password, (string) $user['password_hash'])) {
        login_user($user);
        clear_login_attempts($ip);
        db_exec('UPDATE users SET last_login = NOW() WHERE id = :id', [':id' => (int) $user['id']]);
        audit_log('auth_login_success', 'User login success', 'user', (int) $user['id']);
        flash_set('success', 'Login successful.');
        redirect('/admin/dashboard.php');
    }

    register_login_attempt($ip, $username);
    audit_log('auth_login_failed', 'Login failed attempt', 'auth', null, ['username' => $username]);
    flash_set('danger', 'Invalid username or password.');
    redirect('/auth/login.php');
}

$title = APP_NAME . ' - Login Admin';
require_once __DIR__ . '/../includes/helpers.php';

$uiSettings = settings_get_all();
$brandingLogoRaw = trim((string) ($uiSettings['branding_logo_url'] ?? ''));
$brandingLogoUrl = '';
if ($brandingLogoRaw !== '') {
    if (preg_match('/^(https?:)?\/\//i', $brandingLogoRaw) === 1 || str_starts_with($brandingLogoRaw, 'data:')) {
        $brandingLogoUrl = $brandingLogoRaw;
    } else {
        $brandingLogoUrl = app_url(ltrim($brandingLogoRaw, '/'));
    }
}

require_once __DIR__ . '/../includes/layout/head.php';
?>
<main class="auth-shell">
    <div class="auth-backdrop" aria-hidden="true"></div>
    
    <div class="auth-panel">
        <div class="auth-panel-card">
            <div class="auth-brand-badge">
                <?php if ($brandingLogoUrl !== ''): ?>
                    <img src="<?= e($brandingLogoUrl) ?>" alt="<?= e(APP_NAME) ?> logo" class="servmon-brand-logo login-brand-logo">
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-cyan"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <?php endif; ?>
            </div>
            
            <h1 class="auth-title">Welcome back</h1>
            <p class="auth-subtitle">Enter your credentials to access the admin panel</p>

            <?php require __DIR__ . '/../includes/layout/flash.php'; ?>

            <form method="post" novalidate class="auth-form">
                <?= csrf_input() ?>
                
                <div class="auth-input-wrapper">
                    <span class="auth-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </span>
                    <input class="form-control" type="text" name="username" required value="<?= e(old('username')) ?>" placeholder="Username" autocomplete="username">
                </div>
                
                <div class="auth-input-wrapper">
                    <span class="auth-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </span>
                    <input class="form-control" type="password" name="password" required placeholder="Password" autocomplete="current-password">
                </div>
                
                <button class="btn auth-submit-btn w-100" type="submit">
                    <span>Sign in</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px;"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
            </form>

            <div class="auth-note">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                <span>Brute-force protection: 5 attempts / 5 minutes per IP</span>
            </div>
        </div>
        
        <p class="auth-footer">
            <?= e(APP_NAME) ?> Server Monitoring
        </p>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/layout/footer.php';
