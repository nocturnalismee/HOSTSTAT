<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/worker.php';
require_once __DIR__ . '/../includes/retention.php';

require_role('admin');

function normalize_user_role(?string $role): string
{
    $role = strtolower(trim((string) $role));
    return in_array($role, ['admin', 'viewer'], true) ? $role : 'viewer';
}

function admin_user_count(): int
{
    $row = db_one('SELECT COUNT(*) AS total FROM users WHERE role = "admin"');
    return (int) ($row['total'] ?? 0);
}

function settings_section_map(): array
{
    return [
        'general' => 'General',
        'notifications' => 'Notifications',
        'security' => 'Security',
        'users' => 'Users',
        'ops' => 'Ops',
    ];
}

function normalize_settings_section(?string $section): string
{
    $section = strtolower(trim((string) $section));
    return array_key_exists($section, settings_section_map()) ? $section : 'general';
}

function infer_section_from_post(array $post): string
{
    if (isset($post['section'])) {
        return normalize_settings_section((string) $post['section']);
    }

    $action = (string) ($post['action'] ?? '');
    if (in_array($action, ['test_email', 'test_telegram'], true)) {
        return 'notifications';
    }
    if ($action === 'run_retention') {
        return 'security';
    }
    if (str_starts_with($action, 'user_')) {
        return 'users';
    }
    if (isset($post['smtp_host']) || isset($post['telegram_bot_token']) || isset($post['channel_email_enabled']) || isset($post['channel_telegram_enabled'])) {
        return 'notifications';
    }
    if (isset($post['session_idle_timeout_minutes']) || isset($post['session_absolute_timeout_minutes']) || isset($post['retention_days']) || isset($post['disk_retention_days'])) {
        return 'security';
    }

    return 'general';
}

function settings_url(string $section): string
{
    return '/admin/settings.php?section=' . rawurlencode(normalize_settings_section($section));
}

if (is_post()) {
    $section = infer_section_from_post($_POST);
    if (!csrf_validate($_POST['_csrf_token'] ?? null)) {
        flash_set('danger', 'Invalid CSRF token.');
        redirect(settings_url($section));
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $values = [];
        if ($section === 'general') {
            $values = [
                'branding_logo_url' => trim((string) ($_POST['branding_logo_url'] ?? setting_get('branding_logo_url'))),
                'branding_favicon_url' => trim((string) ($_POST['branding_favicon_url'] ?? setting_get('branding_favicon_url'))),
                'alert_down_minutes' => (string) max(1, (int) ($_POST['alert_down_minutes'] ?? setting_get('alert_down_minutes'))),
                'alert_cooldown_minutes' => (string) max(0, (int) ($_POST['alert_cooldown_minutes'] ?? setting_get('alert_cooldown_minutes'))),
                'alert_service_status_enabled' => isset($_POST['alert_service_status_enabled']) ? '1' : '0',
                'alert_ping_enabled' => isset($_POST['alert_ping_enabled']) ? '1' : '0',
                'threshold_mail_queue' => (string) max(0, (int) ($_POST['threshold_mail_queue'] ?? setting_get('threshold_mail_queue'))),
                'threshold_mail_queue_critical' => (string) max(0, (int) ($_POST['threshold_mail_queue_critical'] ?? setting_get('threshold_mail_queue_critical'))),
                'threshold_cpu_load' => (string) max(0, (float) ($_POST['threshold_cpu_load'] ?? setting_get('threshold_cpu_load'))),
                'threshold_cpu_load_critical' => (string) max(0, (float) ($_POST['threshold_cpu_load_critical'] ?? setting_get('threshold_cpu_load_critical'))),
                'threshold_ram_pct' => (string) max(0, min(100, (float) ($_POST['threshold_ram_pct'] ?? setting_get('threshold_ram_pct')))),
                'threshold_ram_pct_critical' => (string) max(0, min(100, (float) ($_POST['threshold_ram_pct_critical'] ?? setting_get('threshold_ram_pct_critical')))),
                'threshold_disk_pct' => (string) max(0, min(100, (float) ($_POST['threshold_disk_pct'] ?? setting_get('threshold_disk_pct')))),
                'threshold_disk_pct_critical' => (string) max(0, min(100, (float) ($_POST['threshold_disk_pct_critical'] ?? setting_get('threshold_disk_pct_critical')))),
                'alert_service_flap_suppress_minutes' => (string) max(0, (int) ($_POST['alert_service_flap_suppress_minutes'] ?? setting_get('alert_service_flap_suppress_minutes'))),
                'public_alerts_redact_message' => isset($_POST['public_alerts_redact_message']) ? '1' : '0',
                'cache_ttl_status_list' => (string) max(1, (int) ($_POST['cache_ttl_status_list'] ?? setting_get('cache_ttl_status_list'))),
                'cache_ttl_status_single' => (string) max(1, (int) ($_POST['cache_ttl_status_single'] ?? setting_get('cache_ttl_status_single'))),
                'cache_ttl_history_24h' => (string) max(1, (int) ($_POST['cache_ttl_history_24h'] ?? setting_get('cache_ttl_history_24h'))),
                'cache_ttl_history_7d' => (string) max(1, (int) ($_POST['cache_ttl_history_7d'] ?? setting_get('cache_ttl_history_7d'))),
                'cache_ttl_history_30d' => (string) max(1, (int) ($_POST['cache_ttl_history_30d'] ?? setting_get('cache_ttl_history_30d'))),
                'cache_ttl_alert_logs' => (string) max(1, (int) ($_POST['cache_ttl_alert_logs'] ?? setting_get('cache_ttl_alert_logs'))),
            ];
        } elseif ($section === 'notifications') {
            $values = [
                'channel_email_enabled' => isset($_POST['channel_email_enabled']) ? '1' : '0',
                'channel_telegram_enabled' => isset($_POST['channel_telegram_enabled']) ? '1' : '0',
                'smtp_host' => trim((string) ($_POST['smtp_host'] ?? setting_get('smtp_host'))),
                'smtp_port' => (string) max(1, (int) ($_POST['smtp_port'] ?? setting_get('smtp_port'))),
                'smtp_username' => trim((string) ($_POST['smtp_username'] ?? setting_get('smtp_username'))),
                'smtp_secure' => in_array((string) ($_POST['smtp_secure'] ?? setting_get('smtp_secure')), ['none', 'tls', 'ssl'], true) ? (string) ($_POST['smtp_secure'] ?? setting_get('smtp_secure')) : 'tls',
                'smtp_from_email' => trim((string) ($_POST['smtp_from_email'] ?? setting_get('smtp_from_email'))),
                'smtp_from_name' => trim((string) ($_POST['smtp_from_name'] ?? setting_get('smtp_from_name'))),
                'smtp_to_email' => trim((string) ($_POST['smtp_to_email'] ?? setting_get('smtp_to_email'))),
                'telegram_bot_token' => trim((string) ($_POST['telegram_bot_token'] ?? setting_get('telegram_bot_token'))),
                'telegram_chat_id' => trim((string) ($_POST['telegram_chat_id'] ?? setting_get('telegram_chat_id'))),
                'telegram_thread_id' => trim((string) ($_POST['telegram_thread_id'] ?? setting_get('telegram_thread_id'))),
            ];
            $newSmtpPassword = trim((string) ($_POST['smtp_password'] ?? ''));
            if ($newSmtpPassword !== '') {
                $values['smtp_password'] = $newSmtpPassword;
            }
        } elseif ($section === 'security') {
            $values = [
                'session_idle_timeout_minutes' => (string) max(5, (int) ($_POST['session_idle_timeout_minutes'] ?? setting_get('session_idle_timeout_minutes'))),
                'session_absolute_timeout_minutes' => (string) max(15, (int) ($_POST['session_absolute_timeout_minutes'] ?? setting_get('session_absolute_timeout_minutes'))),
                'retention_days' => (string) max(1, (int) ($_POST['retention_days'] ?? setting_get('retention_days'))),
                'disk_retention_days' => (string) max(1, (int) ($_POST['disk_retention_days'] ?? setting_get('disk_retention_days'))),
            ];
        }

        if (!empty($values)) {
            settings_save_many($values);
            invalidate_status_cache();
            audit_log('settings_save', 'Updated application settings', 'settings', null, [
                'section' => $section,
                'updated_keys' => array_keys($values),
            ]);
            flash_set('success', 'Settings saved successfully.');
        } else {
            flash_set('warning', 'No settings updated for this section.');
        }
        redirect(settings_url($section));
    }

    if ($action === 'test_email') {
        $settings = settings_get_all();
        $ok = notify_email('servmon Test Notification', 'This is a test email notification from servmon.', $settings);
        audit_log('settings_test_email', 'Ran test email notification', 'settings');
        flash_set($ok ? 'success' : 'warning', $ok ? 'Test email sent.' : 'Test email failed. Check configuration or server mail() support.');
        redirect(settings_url('notifications'));
    }

    if ($action === 'test_telegram') {
        $settings = settings_get_all();
        $settings['channel_telegram_enabled'] = '1';
        $settings['telegram_bot_token'] = trim((string) ($_POST['telegram_bot_token'] ?? $settings['telegram_bot_token'] ?? ''));
        $settings['telegram_chat_id'] = trim((string) ($_POST['telegram_chat_id'] ?? $settings['telegram_chat_id'] ?? ''));
        $settings['telegram_thread_id'] = trim((string) ($_POST['telegram_thread_id'] ?? $settings['telegram_thread_id'] ?? ''));
        $ok = notify_telegram("<b>servmon Test Notification</b>\nThis is a Telegram test notification.", $settings);
        audit_log('settings_test_telegram', 'Ran test telegram notification', 'settings');
        flash_set($ok ? 'success' : 'warning', $ok ? 'Test Telegram message sent.' : 'Test Telegram failed. Check bot token and chat ID.');
        redirect(settings_url('notifications'));
    }

    if ($action === 'run_retention') {
        $days = max(1, (int) ($_POST['retention_days'] ?? setting_get('retention_days')));
        $result = run_core_retention_cleanup($days);
        audit_log('settings_run_retention', 'Executed retention cleanup from settings', 'settings', null, ['days' => $days, 'result' => $result]);
        flash_set('success', 'Core retention completed. Deleted: ' . $result['metrics_deleted'] . ' metrics, ' . $result['alerts_deleted'] . ' alerts.');
        redirect(settings_url('security'));
    }

    if ($action === 'run_disk_retention') {
        $days = max(1, (int) ($_POST['disk_retention_days'] ?? setting_get('disk_retention_days')));
        $result = run_disk_retention_cleanup($days);
        audit_log('settings_run_disk_retention', 'Executed disk retention cleanup', 'settings', null, ['days' => $days]);
        flash_set('success', 'Disk retention completed. Deleted: ' . ($result['disk_metrics_deleted'] ?? 0) . ' metrics.');
        redirect(settings_url('security'));
    }

    if ($action === 'user_create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = normalize_user_role($_POST['role'] ?? 'viewer');

        if ($username === '' || preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username) !== 1) {
            flash_set('danger', 'Invalid username format.');
            redirect(settings_url('users'));
        }
        if (strlen($password) < 8) {
            flash_set('danger', 'Password must be at least 8 characters.');
            redirect(settings_url('users'));
        }
        if (db_one('SELECT id FROM users WHERE username = :username LIMIT 1', [':username' => $username]) !== null) {
            flash_set('danger', 'Username already exists.');
            redirect(settings_url('users'));
        }

        db_exec('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, NOW())', [
            ':username' => $username,
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ':role' => $role,
        ]);
        $newUserId = (int) db()->lastInsertId();
        audit_log('user_create', 'Created user account', 'user', $newUserId, ['username' => $username, 'role' => $role]);
        flash_set('success', 'User created successfully.');
        redirect(settings_url('users'));
    }

    if ($action === 'user_update') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = normalize_user_role($_POST['role'] ?? 'viewer');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $target = db_one('SELECT id, username, role FROM users WHERE id = :id LIMIT 1', [':id' => $userId]);
        $current = current_user();
        $currentUserId = (int) ($current['id'] ?? 0);

        if ($target === null || $userId <= 0) {
            flash_set('danger', 'User not found.');
            redirect(settings_url('users'));
        }
        if ($userId === $currentUserId && $role !== 'admin') {
            flash_set('danger', 'You cannot downgrade your own role.');
            redirect(settings_url('users'));
        }
        if ((string) $target['role'] === 'admin' && $role !== 'admin' && admin_user_count() <= 1) {
            flash_set('danger', 'Cannot remove role from the last admin user.');
            redirect(settings_url('users'));
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            flash_set('danger', 'New password must be at least 8 characters.');
            redirect(settings_url('users'));
        }

        db_exec('UPDATE users SET role = :role WHERE id = :id', [':role' => $role, ':id' => $userId]);
        if ($newPassword !== '') {
            db_exec('UPDATE users SET password_hash = :password_hash WHERE id = :id', [':password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $userId]);
        }

        audit_log('user_update', 'Updated user account', 'user', $userId, ['username' => $target['username'], 'role' => $role]);
        flash_set('success', 'User updated successfully.');
        redirect(settings_url('users'));
    }

    if ($action === 'user_delete') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = db_one('SELECT id, username, role FROM users WHERE id = :id LIMIT 1', [':id' => $userId]);
        $current = current_user();
        $currentUserId = (int) ($current['id'] ?? 0);

        if ($target === null || $userId <= 0) {
            flash_set('danger', 'User not found.');
            redirect(settings_url('users'));
        }
        if ($userId === $currentUserId) {
            flash_set('danger', 'You cannot delete your own account.');
            redirect(settings_url('users'));
        }
        if ((string) $target['role'] === 'admin' && admin_user_count() <= 1) {
            flash_set('danger', 'Cannot delete the last admin user.');
            redirect(settings_url('users'));
        }

        db_exec('DELETE FROM users WHERE id = :id', [':id' => $userId]);
        audit_log('user_delete', 'Deleted user account', 'user', $userId, ['username' => $target['username']]);
        flash_set('success', 'User deleted successfully.');
        redirect(settings_url('users'));
    }
}

$sections = settings_section_map();
$activeSection = normalize_settings_section($_GET['section'] ?? 'general');
$settings = settings_get_all();
$users = db_all('SELECT id, username, role, last_login, created_at FROM users ORDER BY created_at ASC');
$currentUser = current_user();
$adminCount = 0;
foreach ($users as $u) {
    if ((string) ($u['role'] ?? '') === 'admin') {
        $adminCount++;
    }
}

$projectRoot = realpath(__DIR__ . '/..');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}
$phpCron = '/usr/bin/php';
$workersRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$alertWorker = $workersRoot . '/workers/alert-check.php';
$pingWorker = $workersRoot . '/workers/ping-check.php';
$retentionWorker = $workersRoot . '/workers/cleanup.php';
$diskRetentionWorker = $workersRoot . '/workers/disk-cleanup.php';
$recommendedCron = [
    '* * * * * ' . $phpCron . ' ' . $alertWorker . ' >/dev/null 2>&1',
    '* * * * * ' . $phpCron . ' ' . $pingWorker . ' >/dev/null 2>&1',
    '30 2 * * * ' . $phpCron . ' ' . $diskRetentionWorker . ' >/dev/null 2>&1',
    '0 3 * * * ' . $phpCron . ' ' . $retentionWorker . ' >/dev/null 2>&1',
];
$workerStatuses = [
    'alert_check' => worker_health_status('alert_check', 180),
    'ping_check' => worker_health_status('ping_check', 300),
    'disk_retention_cleanup' => worker_health_status('disk_retention_cleanup', 129600),
    'retention_cleanup' => worker_health_status('retention_cleanup', 129600),
];

$title = APP_NAME . ' - Settings';
$activeNav = 'settings';

require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header">
        <div>
            <h1 class="page-title">Settings</h1>
            <p class="page-subtitle">Configure alerts, channels, retention, security, and user access.</p>
        </div>
    </section>

    <!-- Settings Navigation -->
    <nav class="settings-nav">
        <?php foreach ($sections as $key => $label): ?>
            <a class="settings-nav-item <?= $activeSection === $key ? 'active' : '' ?>" href="<?= e(app_url('admin/settings.php?section=' . $key)) ?>">
                <?php if ($key === 'general'): ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M12 1v6m0 6v10"></path></svg>
                <?php elseif ($key === 'notifications'): ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                <?php elseif ($key === 'security'): ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <?php elseif ($key === 'users'): ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <?php else: ?><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                <?php endif; ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (in_array($activeSection, ['general', 'notifications', 'security'], true)): ?>
    <form method="post" class="settings-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="section" value="<?= e($activeSection) ?>">
        
        <?php if ($activeSection === 'general'): ?>
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line></svg></div>
                <div><h3 class="settings-section-title">Branding</h3><p class="settings-section-desc">Customize logo and favicon</p></div>
            </div>
            <div class="settings-section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="settings-label">Brand Logo URL</label>
                        <input class="form-control" name="branding_logo_url" value="<?= e($settings['branding_logo_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="settings-label">Favicon URL</label>
                        <input class="form-control" name="branding_favicon_url" value="<?= e($settings['branding_favicon_url'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path></svg></div>
                <div><h3 class="settings-section-title">Alerts & Thresholds</h3><p class="settings-section-desc">Configure alert policies</p></div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid-4">
                    <div class="settings-field"><label class="settings-label">Down Duration (min)</label><input class="form-control" type="number" min="1" name="alert_down_minutes" value="<?= e($settings['alert_down_minutes']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Cooldown (min)</label><input class="form-control" type="number" min="0" name="alert_cooldown_minutes" value="<?= e($settings['alert_cooldown_minutes']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Flap Suppress (min)</label><input class="form-control" type="number" min="0" name="alert_service_flap_suppress_minutes" value="<?= e($settings['alert_service_flap_suppress_minutes']) ?>"></div>
                    <div class="settings-field">
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="alert_service_status_enabled" id="alert_service_status_enabled" <?= ($settings['alert_service_status_enabled'] ?? '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="alert_service_status_enabled">Service Alerts</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="alert_ping_enabled" id="alert_ping_enabled" <?= ($settings['alert_ping_enabled'] ?? '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="alert_ping_enabled">Ping Alerts</label></div>
                    </div>
                </div>
                <div class="settings-divider"></div>
                <h4 class="settings-subsection-title">Warning Thresholds</h4>
                <div class="settings-grid-4">
                    <div class="settings-field"><label class="settings-label">Mail Queue</label><input class="form-control" type="number" min="0" name="threshold_mail_queue" value="<?= e($settings['threshold_mail_queue']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">CPU Load</label><input class="form-control" type="number" step="0.01" min="0" name="threshold_cpu_load" value="<?= e($settings['threshold_cpu_load']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">RAM (%)</label><input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_ram_pct" value="<?= e($settings['threshold_ram_pct']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Disk (%)</label><input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_disk_pct" value="<?= e($settings['threshold_disk_pct']) ?>"></div>
                </div>
                <h4 class="settings-subsection-title">Critical Thresholds</h4>
                <div class="settings-grid-4">
                    <div class="settings-field"><label class="settings-label">Mail Queue</label><input class="form-control" type="number" min="0" name="threshold_mail_queue_critical" value="<?= e($settings['threshold_mail_queue_critical']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">CPU Load</label><input class="form-control" type="number" step="0.01" min="0" name="threshold_cpu_load_critical" value="<?= e($settings['threshold_cpu_load_critical']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">RAM (%)</label><input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_ram_pct_critical" value="<?= e($settings['threshold_ram_pct_critical']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Disk (%)</label><input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_disk_pct_critical" value="<?= e($settings['threshold_disk_pct_critical']) ?>"></div>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"></rect><rect x="2" y="14" width="20" height="8" rx="2"></rect></svg></div>
                <div><h3 class="settings-section-title">Cache TTL (seconds)</h3><p class="settings-section-desc">API cache expiration times</p></div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid-6">
                    <div class="settings-field"><label class="settings-label">Status List</label><input class="form-control" type="number" min="1" name="cache_ttl_status_list" value="<?= e($settings['cache_ttl_status_list']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Status Single</label><input class="form-control" type="number" min="1" name="cache_ttl_status_single" value="<?= e($settings['cache_ttl_status_single']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">History 24h</label><input class="form-control" type="number" min="1" name="cache_ttl_history_24h" value="<?= e($settings['cache_ttl_history_24h']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">History 7d</label><input class="form-control" type="number" min="1" name="cache_ttl_history_7d" value="<?= e($settings['cache_ttl_history_7d']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">History 30d</label><input class="form-control" type="number" min="1" name="cache_ttl_history_30d" value="<?= e($settings['cache_ttl_history_30d']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Alert Logs</label><input class="form-control" type="number" min="1" name="cache_ttl_alert_logs" value="<?= e($settings['cache_ttl_alert_logs']) ?>"></div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline></svg> Save Settings</button>
        </div>
        <?php endif; ?>

        <?php if ($activeSection === 'notifications'): ?>
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></div>
                <div><h3 class="settings-section-title">Email Channel</h3><p class="settings-section-desc">SMTP configuration</p></div>
            </div>
            <div class="settings-section-body">
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="channel_email_enabled" id="channel_email_enabled" <?= $settings['channel_email_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="channel_email_enabled">Enable Email Notifications</label></div>
                <div class="settings-grid-2">
                    <div class="settings-field"><label class="settings-label">SMTP Host</label><input class="form-control" name="smtp_host" value="<?= e($settings['smtp_host']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">SMTP Port</label><input class="form-control" type="number" name="smtp_port" value="<?= e($settings['smtp_port']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">SMTP Secure</label><select class="form-select" name="smtp_secure"><option value="none" <?= $settings['smtp_secure'] === 'none' ? 'selected' : '' ?>>None</option><option value="tls" <?= $settings['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS</option><option value="ssl" <?= $settings['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option></select></div>
                    <div class="settings-field"><label class="settings-label">SMTP Username</label><input class="form-control" name="smtp_username" value="<?= e($settings['smtp_username']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">SMTP Password</label><input class="form-control" type="password" name="smtp_password" placeholder="Leave blank"></div>
                    <div class="settings-field"><label class="settings-label">Sender Email</label><input class="form-control" name="smtp_from_email" value="<?= e($settings['smtp_from_email']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Sender Name</label><input class="form-control" name="smtp_from_name" value="<?= e($settings['smtp_from_name']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Recipient Email</label><input class="form-control" name="smtp_to_email" value="<?= e($settings['smtp_to_email']) ?>"></div>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></div>
                <div><h3 class="settings-section-title">Telegram Channel</h3><p class="settings-section-desc">Bot configuration</p></div>
            </div>
            <div class="settings-section-body">
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="channel_telegram_enabled" id="channel_telegram_enabled" <?= $settings['channel_telegram_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="channel_telegram_enabled">Enable Telegram Notifications</label></div>
                <div class="settings-grid-3">
                    <div class="settings-field"><label class="settings-label">Bot Token</label><input class="form-control" name="telegram_bot_token" value="<?= e($settings['telegram_bot_token']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Chat ID</label><input class="form-control" name="telegram_chat_id" value="<?= e($settings['telegram_chat_id']) ?>"></div>
                    <div class="settings-field"><label class="settings-label">Thread ID (optional)</label><input class="form-control" name="telegram_thread_id" value="<?= e($settings['telegram_thread_id']) ?>"></div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path></svg> Save Settings</button>
            <button class="btn btn-outline-success" type="submit" name="action" value="test_email">Test Email</button>
            <button class="btn btn-outline-primary" type="submit" name="action" value="test_telegram">Test Telegram</button>
        </div>
        <?php endif; ?>

        <?php if ($activeSection === 'security'): ?>
        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                <div><h3 class="settings-section-title">Session Security</h3><p class="settings-section-desc">Timeout configuration</p></div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid-2">
                    <div class="settings-field"><label class="settings-label">Idle Timeout (minutes)</label><input class="form-control" type="number" min="5" name="session_idle_timeout_minutes" value="<?= e($settings['session_idle_timeout_minutes']) ?>"><div class="settings-help">Applies when no activity</div></div>
                    <div class="settings-field"><label class="settings-label">Absolute Timeout (minutes)</label><input class="form-control" type="number" min="15" name="session_absolute_timeout_minutes" value="<?= e($settings['session_absolute_timeout_minutes']) ?>"><div class="settings-help">Applies since login</div></div>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <div class="settings-section-header">
                <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg></div>
                <div><h3 class="settings-section-title">Data Retention</h3><p class="settings-section-desc">Automatic cleanup policies</p></div>
            </div>
            <div class="settings-section-body">
                <div class="settings-grid-2">
                    <div class="settings-field"><label class="settings-label">Core Retention (days)</label><select class="form-select" name="retention_days"><?php foreach ([2,7,15,30,60,90] as $d): ?><option value="<?= $d ?>" <?= (int)$settings['retention_days']===$d?'selected':'' ?>><?= $d ?> days</option><?php endforeach; ?></select><div class="settings-help">metrics, alerts, login attempts</div></div>
                    <div class="settings-field"><label class="settings-label">Disk Retention (days)</label><select class="form-select" name="disk_retention_days"><?php foreach ([7,15,30,60,90,180,365] as $d): ?><option value="<?= $d ?>" <?= (int)($settings['disk_retention_days']??90)===$d?'selected':'' ?>><?= $d ?> days</option><?php endforeach; ?></select><div class="settings-help">disk health metrics</div></div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn btn-primary" type="submit"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path></svg> Save Settings</button>
            <button class="btn btn-outline-warning" type="submit" name="action" value="run_retention">Run Core Retention</button>
            <button class="btn btn-outline-warning" type="submit" name="action" value="run_disk_retention">Run Disk Retention</button>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($activeSection === 'users'): ?>
    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg></div>
            <div><h3 class="settings-section-title">User Management</h3><p class="settings-section-desc">Manage admin and viewer accounts</p></div>
        </div>
        <div class="settings-section-body">
            <form method="post" class="user-create-form mb-4">
                <?= csrf_input() ?><input type="hidden" name="action" value="user_create"><input type="hidden" name="section" value="users">
                <div class="user-create-header"><h4>Create New User</h4><p>Use <code>viewer</code> for read-only, <code>admin</code> for full access</p></div>
                <div class="user-create-fields">
                    <div class="settings-field"><label class="settings-label">Username</label><input class="form-control" name="username" required pattern="[a-zA-Z0-9_.-]{3,50}"></div>
                    <div class="settings-field"><label class="settings-label">Password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
                    <div class="settings-field"><label class="settings-label">Role</label><select class="form-select" name="role"><option value="viewer">viewer</option><option value="admin">admin</option></select></div>
                    <div class="settings-field"><label class="settings-label">&nbsp;</label><button class="btn btn-primary w-100" type="submit">Add User</button></div>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table servmon-table">
                    <thead><tr><th>Username</th><th>Role</th><th>Last Login</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $userRow): $isSelf = (int)($userRow['id']??0) === (int)($currentUser['id']??0); $isLastAdmin = (string)($userRow['role']??'')==='admin' && $adminCount<=1; ?>
                        <tr>
                            <td><?= e((string)$userRow['username']) ?><?php if($isSelf): ?><span class="user-badge">(you)</span><?php endif; ?></td>
                            <td><span class="badge text-bg-<?= (string)$userRow['role']==='admin'?'info':'secondary' ?>"><?= e((string)$userRow['role']) ?></span></td>
                            <td><?= e((string)($userRow['last_login']??'-')) ?></td>
                            <td><?= e((string)($userRow['created_at']??'-')) ?></td>
                            <td>
                                <div class="user-actions">
                                    <form method="post" class="user-action-form"><?= csrf_input() ?><input type="hidden" name="action" value="user_update"><input type="hidden" name="section" value="users"><input type="hidden" name="user_id" value="<?= e((string)$userRow['id']) ?>"><select class="form-select form-select-sm" name="role"><option value="viewer" <?= (string)$userRow['role']==='viewer'?'selected':'' ?>>viewer</option><option value="admin" <?= (string)$userRow['role']==='admin'?'selected':'' ?>>admin</option></select><input class="form-control form-control-sm" type="password" name="new_password" placeholder="New password"><button class="btn btn-sm btn-outline-info" type="submit">Save</button></form>
                                    <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="section" value="users"><input type="hidden" name="user_id" value="<?= e((string)$userRow['id']) ?>"><button class="btn btn-sm btn-outline-danger" type="submit" <?= ($isSelf||$isLastAdmin)?'disabled':'' ?>>Delete</button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($activeSection === 'ops'): ?>
    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg></div>
            <div><h3 class="settings-section-title">Worker Health</h3><p class="settings-section-desc">Background worker status</p></div>
        </div>
        <div class="settings-section-body">
            <table class="table servmon-table">
                <thead><tr><th>Worker</th><th>Health</th><th>Last Success</th><th>Last Error</th></tr></thead>
                <tbody>
                    <?php foreach ($workerStatuses as $wn => $ws): $h = (string)($ws['health']??'unknown'); ?>
                    <tr><td><code><?= e($wn) ?></code></td><td><span class="badge text-bg-<?= match($h){'ok'=>'success','error'=>'danger','late'=>'warning',default=>'secondary'} ?>"><?= e($h) ?></span></td><td><?= e((string)($ws['last_success_at']??'never')) ?></td><td><?= e((string)($ws['last_error']??'-')) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"></polyline></svg></div>
            <div><h3 class="settings-section-title">Cron Configuration</h3><p class="settings-section-desc">Recommended cron jobs</p></div>
        </div>
        <div class="settings-section-body">
            <pre class="code-block"><code><?= e(implode("\n", $recommendedCron)) ?></code></pre>
        </div>
    </div>
    <?php endif; ?>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php';
