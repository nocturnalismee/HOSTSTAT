<?php
declare(strict_types=1);
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/../settings.php';
$activeNav = $activeNav ?? '';
$user = current_user();
$isAdminUser = has_role('admin');
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
?>
<aside class="servmon-sidebar">
    <div class="servmon-sidebar-brand">
        <a class="text-decoration-none fw-bold fs-5 servmon-brand-link" href="<?= e(app_url('admin/dashboard.php')) ?>">
            <?php if ($brandingLogoUrl !== ''): ?>
                <img class="servmon-brand-logo" src="<?= e($brandingLogoUrl) ?>" alt="<?= e(APP_NAME) ?> logo">
            <?php else: ?>
                <i class="ti ti-server text-cyan"></i>
            <?php endif; ?>
            <span class="sidebar-label"><?= e(APP_NAME) ?></span>
        </a>
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex sidebar-toggle-btn"
            data-sidebar-toggle-desktop
            title="Collapse sidebar"
            aria-label="Collapse sidebar"
        >
            <i class="ti ti-chevron-left" data-sidebar-toggle-icon></i>
        </button>
    </div>
    <nav class="nav flex-column p-3 gap-1">
        <a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('admin/dashboard.php')) ?>">
            <i class="ti ti-dashboard me-2"></i><span class="sidebar-label">Dashboard</span>
        </a>
        <a class="nav-link <?= $activeNav === 'servers' ? 'active' : '' ?>" href="<?= e(app_url('admin/servers.php')) ?>">
            <i class="ti ti-server me-2"></i><span class="sidebar-label">Servers</span>
        </a>
        <a class="nav-link <?= $activeNav === 'disk_health' ? 'active' : '' ?>" href="<?= e(app_url('admin/disk-health.php')) ?>">
            <i class="ti ti-device-desktop-analytics me-2"></i><span class="sidebar-label">Disk Health</span>
        </a>
        <a class="nav-link <?= $activeNav === 'ping' ? 'active' : '' ?>" href="<?= e(app_url('admin/ping-monitors.php')) ?>">
            <i class="ti ti-radar me-2"></i><span class="sidebar-label">Ping Monitor</span>
        </a>
        <a class="nav-link <?= $activeNav === 'alerts' ? 'active' : '' ?>" href="<?= e(app_url('admin/alert-logs.php')) ?>">
            <i class="ti ti-bell me-2"></i><span class="sidebar-label">Alert Logs</span>
        </a>
        <?php if ($isAdminUser): ?>
            <a class="nav-link <?= $activeNav === 'audit' ? 'active' : '' ?>" href="<?= e(app_url('admin/audit-logs.php')) ?>">
                <i class="ti ti-shield-check me-2"></i><span class="sidebar-label">Audit Logs</span>
            </a>
            <a class="nav-link <?= $activeNav === 'export' ? 'active' : '' ?>" href="<?= e(app_url('admin/export.php')) ?>">
                <i class="ti ti-download me-2"></i><span class="sidebar-label">Export</span>
            </a>
            <a class="nav-link <?= $activeNav === 'settings' ? 'active' : '' ?>" href="<?= e(app_url('admin/settings.php')) ?>">
                <i class="ti ti-settings me-2"></i><span class="sidebar-label">Settings</span>
            </a>
        <?php endif; ?>
        <a class="nav-link" href="<?= e(app_url('index.php')) ?>" target="_blank">
            <i class="ti ti-world me-2"></i><span class="sidebar-label">Public Status</span>
        </a>
    </nav>
    <div class="servmon-sidebar-footer">
        <div class="sidebar-user-meta small text-secondary">
            <i class="ti ti-user-circle"></i>
            <span class="sidebar-label"><?= e($user['username'] ?? '') ?></span>
        </div>
        <div class="sidebar-footer-actions">
            <button type="button" class="btn btn-soft btn-sm w-100 d-flex align-items-center justify-content-center gap-2" data-theme-toggle>
                <i class="ti ti-moon-2" data-theme-toggle-icon></i><span class="sidebar-label">Dark Mode</span>
            </button>
            <form method="post" action="<?= e(app_url('auth/logout.php')) ?>">
                <?= csrf_input() ?>
                <button class="btn btn-outline-light btn-sm w-100 d-flex align-items-center justify-content-center gap-2" type="submit">
                    <i class="ti ti-logout-2"></i><span class="sidebar-label">Logout</span>
                </button>
            </form>
        </div>
    </div>
</aside>
<button type="button" class="btn btn-info sidebar-mobile-toggle d-md-none" data-sidebar-toggle-mobile>
    <i class="ti ti-layout-sidebar"></i>
</button>
<div class="sidebar-overlay d-md-none" data-sidebar-overlay></div>
