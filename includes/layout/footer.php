<?php
declare(strict_types=1);
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/sidebar.js')) ?>"></script>
<?php
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$isAdminPage = str_contains($scriptName, '/admin/');
$isPublicIndex = basename($scriptName) === 'index.php' && !$isAdminPage;
if ($isAdminPage || $isPublicIndex):
    $alertEndpoint = $isAdminPage ? app_url('api/alerts.php') : app_url('api/public_alerts.php');
?>
<div id="servmon-alert-toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script>window.SERVMON_API_ALERTS = "<?= e($alertEndpoint) ?>";window.SERVMON_API_SSE = "<?= e(app_url('api/sse.php')) ?>";</script>
<script src="<?= e(asset_url('assets/js/alerts.js')) ?>"></script>
<?php endif; ?>
<script>
document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (alertEl) {
    var delay = parseInt(alertEl.getAttribute('data-auto-dismiss') || '0', 10);
    if (delay <= 0) {
        return;
    }

    window.setTimeout(function () {
        if (!alertEl.isConnected) {
            return;
        }

        var instance = bootstrap.Alert.getOrCreateInstance(alertEl);
        instance.close();
    }, delay);
});

document.querySelectorAll('[data-servmon-flash-toast="1"]').forEach(function (toastEl) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
        return;
    }

    var toast = bootstrap.Toast.getOrCreateInstance(toastEl);
    toast.show();
});
</script>
</body>
</html>
