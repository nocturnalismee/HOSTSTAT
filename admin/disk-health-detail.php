<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_login();

/**
 * @return list<array<string, mixed>>
 */
function fetch_disk_health_detail_rows(int $serverId): array
{
    $powerOnColumn = disk_health_power_on_column_detail();
    return db_all(
        'SELECT
            disk_key, device_name, model, serial,
            health_status, health_score, temperature_c, ' . $powerOnColumn . ' AS power_on_value,
            total_written_bytes, updated_at
         FROM disk_health_states
         WHERE server_id = :id
         ORDER BY
            CASE health_status
                WHEN "critical" THEN 4
                WHEN "warning" THEN 3
                WHEN "ok" THEN 2
                ELSE 1
            END DESC,
            IFNULL(CAST(SUBSTRING_INDEX(REGEXP_SUBSTR(device_name, "#0/[0-9]+"), "/", -1) AS UNSIGNED), 999999) ASC,
            updated_at DESC,
            disk_key ASC',
        [':id' => $serverId]
    );
}

function disk_health_power_on_column_detail(): string
{
    static $column = null;
    if (is_string($column) && $column !== '') {
        return $column;
    }

    try {
        $hasPowerOnTime = db_one(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'disk_health_states'
               AND column_name = 'power_on_time'
             LIMIT 1"
        );
        $column = $hasPowerOnTime !== null ? 'power_on_time' : 'power_on_hours';
    } catch (Throwable) {
        $column = 'power_on_time';
    }

    return $column;
}

function format_tbw_from_bytes(?float $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '-';
    }
    $tb = $bytes / 1000 / 1000 / 1000 / 1000;
    return number_format($tb, 2) . ' TB';
}

function format_power_on_time(?float $hours): string
{
    if ($hours === null || $hours <= 0) {
        return '-';
    }
    $totalHours = (int) round($hours);
    $days = intdiv($totalHours, 24);
    $remainHours = $totalHours % 24;
    if ($days > 0) {
        return $days . ' days, ' . $remainHours . ' hours';
    }
    return $totalHours . ' hours';
}

function format_device_parts(?string $deviceName, ?string $diskKey): array
{
    $raw = trim((string) ($deviceName ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($diskKey ?? '-'));
    }
    if ($raw === '') {
        $raw = '-';
    }
    if (preg_match('/^(.+?)\s*\((.+)\)$/', $raw, $m) === 1) {
        return [trim($m[1]), trim($m[2])];
    }
    return [$raw, ''];
}

function format_health_pct(?float $value): string
{
    if ($value === null) {
        return '-';
    }
    return number_format($value, 0) . ' %';
}

function disk_health_badge_class(string $healthStatus): string
{
    return match (strtolower(trim($healthStatus))) {
        'critical' => 'badge-severity badge-severity-danger',
        'warning' => 'badge-severity badge-severity-warning',
        'ok' => 'badge-severity badge-severity-success',
        default => 'badge-severity badge-severity-info',
    };
}

function disk_health_table_exists_detail(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $row = db_one(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'disk_health_states'
             LIMIT 1"
        );
        $exists = ($row !== null);
    } catch (Throwable) {
        $exists = false;
    }
    return $exists;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'Invalid server.');
    redirect('/admin/disk-health.php');
}

$server = db_one(
    'SELECT id, name, host, location, active
     FROM servers
     WHERE id = :id
     LIMIT 1',
    [':id' => $id]
);
if ($server === null) {
    flash_set('danger', 'Server not found.');
    redirect('/admin/disk-health.php');
}

$diskHealthRows = [];
$diskHealthLoadError = '';
if (disk_health_table_exists_detail()) {
    try {
        $diskHealthRows = fetch_disk_health_detail_rows($id);
    } catch (Throwable) {
        $diskHealthLoadError = 'Disk health data unavailable.';
    }
} else {
    $diskHealthLoadError = 'Disk health table not found.';
}

$title = APP_NAME . ' - Disk Health Detail';
$activeNav = 'disk_health';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><i class="ti ti-disc me-2 text-info" aria-hidden="true"></i>Disk Health Detail</h1>
            <ul class="page-subtitle server-meta-list mb-0" aria-label="Server metadata">
                <li class="server-meta-chip">
                    <span class="server-meta-key">Server</span>
                    <span class="server-meta-value"><?= e((string) ($server['name'] ?? '-')) ?></span>
                </li>
                <li class="server-meta-chip">
                    <span class="server-meta-key">Host</span>
                    <span class="server-meta-value"><?= e((string) ($server['host'] ?? '-')) ?></span>
                </li>
                <li class="server-meta-chip">
                    <span class="server-meta-key">Location</span>
                    <span class="server-meta-value"><?= e((string) ($server['location'] ?? '-')) ?></span>
                </li>
            </ul>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft btn-sm" href="<?= e(app_url('admin/disk-health.php')) ?>">
                <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back to Disk Health
            </a>
            <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('admin/server-detail.php?id=' . (int) $server['id'])) ?>">
                <i class="ti ti-server-2 me-1" aria-hidden="true"></i>Server Detail
            </a>
        </div>
    </section>

    <?php if ($diskHealthLoadError !== ''): ?>
        <div class="alert alert-warning mb-0"><?= e($diskHealthLoadError) ?></div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="ti ti-table me-2 text-info" aria-hidden="true"></i>Disk Health Detail</h2>
            <small class="text-secondary">
                <i class="ti ti-device-imac me-1" aria-hidden="true"></i><?= e((string) count($diskHealthRows)) ?> disk(s)
            </small>
        </div>
        <div class="table-responsive table-shell">
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Device</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>Health</th>
                    <th>Score</th>
                    <th>Temp</th>
                    <th>POT</th>
                    <th>TBW</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($diskHealthRows)): ?>
                    <tr><td colspan="9" class="table-empty">No disk health data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($diskHealthRows as $disk): ?>
                    <?php
                    $healthStatus = strtolower(trim((string) ($disk['health_status'] ?? 'unknown')));
                    $healthBadgeClass = disk_health_badge_class($healthStatus);
                    $healthScore = isset($disk['health_score']) ? (float) $disk['health_score'] : null;
                    $temperature = isset($disk['temperature_c']) ? (float) $disk['temperature_c'] : null;
                    $powerOnHours = isset($disk['power_on_value']) ? (float) $disk['power_on_value'] : null;
                    $tbwBytes = isset($disk['total_written_bytes']) ? (float) $disk['total_written_bytes'] : null;
                    [$devicePrimary, $deviceMeta] = format_device_parts(
                        isset($disk['device_name']) ? (string) $disk['device_name'] : null,
                        isset($disk['disk_key']) ? (string) $disk['disk_key'] : null
                    );
                    ?>
                    <tr>
                        <td>
                            <code><?= e($devicePrimary) ?></code>
                            <?php if ($deviceMeta !== ''): ?>
                                <div class="small text-secondary mt-1"><?= e($deviceMeta) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($disk['model'] ?? '-')) ?></td>
                        <td><?= e((string) ($disk['serial'] ?? '-')) ?></td>
                        <td><span class="badge <?= e($healthBadgeClass) ?> text-uppercase"><?= e($healthStatus) ?></span></td>
                        <td class="font-mono"><?= e(format_health_pct($healthScore)) ?></td>
                        <td class="font-mono"><?= e($temperature === null ? '-' : number_format($temperature, 1) . ' C') ?></td>
                        <td class="font-mono"><?= e(format_power_on_time($powerOnHours)) ?></td>
                        <td class="font-mono"><?= e(format_tbw_from_bytes($tbwBytes)) ?></td>
                        <td><?= e((string) ($disk['updated_at'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>window.SERVMON_DISK_AUTO_REFRESH_MS = 30000;</script>
<script src="<?= e(asset_url('assets/js/disk-refresh.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
