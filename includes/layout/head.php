<?php
declare(strict_types=1);
/** @var string $title */
require_once __DIR__ . '/../settings.php';
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$uiSettings = settings_get_all();
$brandingFaviconRaw = trim((string) ($uiSettings['branding_favicon_url'] ?? ''));
$brandingFaviconUrl = '';
if ($brandingFaviconRaw !== '') {
    if (preg_match('/^(https?:)?\/\//i', $brandingFaviconRaw) === 1 || str_starts_with($brandingFaviconRaw, 'data:')) {
        $brandingFaviconUrl = $brandingFaviconRaw;
    } else {
        $brandingFaviconUrl = app_url(ltrim($brandingFaviconRaw, '/'));
    }
}
$sidebarCollapsedCookie = (string) ($_COOKIE['servmon_sidebar_collapsed'] ?? '');
$bodyClasses = ['servmon-body'];
if ($sidebarCollapsedCookie === '1') {
    $bodyClasses[] = 'sidebar-collapsed';
}
?>
<!doctype html>
<html lang="id" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? APP_NAME) ?></title>
    <?php if ($brandingFaviconUrl !== ''): ?>
        <link rel="icon" href="<?= e($brandingFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= e($brandingFaviconUrl) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.34.1/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/app.css')) ?>" rel="stylesheet">
    <script>
      (function () {
        try {
          var stored = localStorage.getItem('servmon_theme');
          var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
          var theme = stored === 'dark' || stored === 'light' ? stored : (systemDark ? 'dark' : 'light');
          document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {}
      })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="<?= e(asset_url('assets/js/common.js')) ?>"></script>
</head>
<body class="<?= e(implode(' ', $bodyClasses)) ?>">
