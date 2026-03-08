<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/settings.php';
require_login();

/**
 * @return list<array<string, mixed>>
 */
function fetch_disk_health_summary_rows(bool $includeInactive): array
{
    $whereClause = $includeInactive ? '' : 'WHERE s.active = 1';
    $powerOnColumn = disk_health_power_on_column_summary();
    return db_all(
        'SELECT
            s.id AS server_id,
            s.name AS server_name,
            COUNT(dhs.disk_key) AS disk_count,
            ROUND(AVG(dhs.health_score), 2) AS avg_health_score,
            ROUND(AVG(dhs.' . $powerOnColumn . '), 2) AS avg_power_on_value,
            ROUND(AVG(dhs.total_written_bytes), 2) AS avg_tbw_bytes,
            DATE_FORMAT(MAX(dhs.updated_at), "%Y-%m-%d %H:%i:%s") AS last_update,
            pd.model AS primary_disk_model,
            pd.device_name AS primary_disk_device
         FROM servers s
         LEFT JOIN disk_health_states dhs
            ON dhs.server_id = s.id
         LEFT JOIN (
            SELECT ranked.server_id, ranked.model, ranked.device_name
            FROM (
                SELECT
                    server_id,
                    model,
                    device_name,
                    ROW_NUMBER() OVER (
                        PARTITION BY server_id
                        ORDER BY
                            CASE health_status
                                WHEN "critical" THEN 4
                                WHEN "warning" THEN 3
                                WHEN "ok" THEN 2
                                ELSE 1
                            END DESC,
                            updated_at DESC,
                            disk_key ASC
                    ) AS rn
                FROM disk_health_states
            ) ranked
            WHERE ranked.rn = 1
         ) pd
            ON pd.server_id = s.id
         ' . $whereClause . '
         GROUP BY
            s.id, s.name, pd.model, pd.device_name
         ORDER BY s.name ASC'
    );
}

function disk_health_power_on_column_summary(): string
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

function format_health_pct(?float $value): string
{
    if ($value === null) {
        return '-';
    }
    return number_format($value, 0) . ' %';
}

function build_primary_disk_label(?string $model, ?string $device): string
{
    $modelText = trim((string) $model);
    if ($modelText !== '') {
        return $modelText;
    }

    $deviceText = trim((string) $device);
    if ($deviceText === '') {
        return '-';
    }

    $shortDevice = preg_replace('/\s*\(.*/', '', $deviceText);
    if (is_string($shortDevice) && trim($shortDevice) !== '') {
        return trim($shortDevice);
    }
    return $deviceText;
}

$includeInactive = isset($_GET['include_inactive']) && (string) $_GET['include_inactive'] === '1';
$summaryRows = [];
$loadError = '';

try {
    $summaryRows = fetch_disk_health_summary_rows($includeInactive);
} catch (Throwable $e) {
    $loadError = 'Disk health table is not ready or has no data yet.';
}

$title = APP_NAME . ' - Disk Health';
$activeNav = 'disk_health';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><i class="ti ti-disc me-2 text-info" aria-hidden="true"></i>Disk Health</h1>
            <p class="page-subtitle">Disk condition summary per server. Click a row to open detail.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft btn-sm" href="<?= e(asset_url('agents/agent-disk-health.sh')) ?>" target="_blank">
                <i class="ti ti-download me-1" aria-hidden="true"></i>Download Agent
            </a>
            <?php if ($includeInactive): ?>
                <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('admin/disk-health.php')) ?>">
                    <i class="ti ti-filter me-1" aria-hidden="true"></i>Active Only
                </a>
            <?php else: ?>
                <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('admin/disk-health.php?include_inactive=1')) ?>">
                    <i class="ti ti-filter-cog me-1" aria-hidden="true"></i>Include Inactive
                </a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-warning mb-0"><?= e($loadError) ?></div>
    <?php elseif (empty($summaryRows)): ?>
        <div class="alert alert-info mb-0">
            No disk health data yet. Install and run <code>agents/agent-disk-health.sh</code> on target servers
            with <code>SERVER_TOKEN</code>, <code>SERVER_ID</code>, and <code>MASTER_URL=/api/push-disk.php</code>.
        </div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="ti ti-table me-2 text-info" aria-hidden="true"></i>Disk Health Summary</h2>
            <small class="text-secondary">
                <i class="ti ti-server me-1" aria-hidden="true"></i><?= e((string) count($summaryRows)) ?> server(s)
            </small>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Server</th>
                    <th>Primary Disk</th>
                    <th>Disk Count</th>
                    <th>Avg Health</th>
                    <th>Avg POT</th>
                    <th>Avg TBW</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody data-disk-health-table>
                <?php if (empty($summaryRows)): ?>
                    <tr><td colspan="7" class="table-empty">No disk health data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($summaryRows as $row): ?>
                    <?php
                    $serverId = (int) ($row['server_id'] ?? 0);
                    $diskCount = max(0, (int) ($row['disk_count'] ?? 0));
                    $diskLabel = build_primary_disk_label(
                        isset($row['primary_disk_model']) ? (string) $row['primary_disk_model'] : null,
                        isset($row['primary_disk_device']) ? (string) $row['primary_disk_device'] : null
                    );
                    $avgHealth = isset($row['avg_health_score']) ? (float) $row['avg_health_score'] : null;
                    $avgPoh = isset($row['avg_power_on_value']) ? (float) $row['avg_power_on_value'] : null;
                    $avgTbw = isset($row['avg_tbw_bytes']) ? (float) $row['avg_tbw_bytes'] : null;
                    $detailUrl = $serverId > 0 ? app_url('admin/disk-health-detail.php?id=' . $serverId) : '';
                    ?>
                    <tr
                        <?php if ($detailUrl !== ''): ?>
                            class="dashboard-row-link"
                            data-detail-url="<?= e($detailUrl) ?>"
                            tabindex="0"
                            role="link"
                            aria-label="Open disk health details for <?= e((string) ($row['server_name'] ?? 'server')) ?>"
                        <?php endif; ?>
                    >
                        <td><?= e((string) ($row['server_name'] ?? '-')) ?></td>
                        <td><?= e($diskLabel) ?></td>
                        <td class="font-mono"><?= e((string) $diskCount) ?></td>
                        <td class="font-mono"><?= e(format_health_pct($avgHealth)) ?></td>
                        <td class="font-mono"><?= e(format_power_on_time($avgPoh)) ?></td>
                        <td class="font-mono"><?= e(format_tbw_from_bytes($avgTbw)) ?></td>
                        <td><?= e((string) ($row['last_update'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const table = document.querySelector("[data-disk-health-table]");
  if (!table) return;

  const goToRowDetail = function (row) {
    const detailUrl = row.getAttribute("data-detail-url");
    if (!detailUrl) return;
    window.location.assign(detailUrl);
  };

  table.addEventListener("click", function (event) {
    const interactive = event.target.closest("a,button,input,select,textarea,label");
    if (interactive) return;
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    goToRowDetail(row);
  });

  table.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" && event.key !== " ") return;
    const row = event.target.closest("tr[data-detail-url]");
    if (!row) return;
    event.preventDefault();
    goToRowDetail(row);
  });
});
</script>
<script>window.SERVMON_DISK_AUTO_REFRESH_MS = 30000;</script>
<script src="<?= e(asset_url('assets/js/disk-refresh.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
