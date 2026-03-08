<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/settings.php';

require_login();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$filterType = trim((string) ($_GET['type'] ?? ''));
$filterSeverity = trim((string) ($_GET['severity'] ?? ''));
$filterServerId = (int) ($_GET['server_id'] ?? 0);

$where = [];
$params = [];

if ($filterType !== '') {
    $where[] = 'a.alert_type = :alert_type';
    $params[':alert_type'] = $filterType;
}
if ($filterSeverity !== '') {
    $where[] = 'a.severity = :severity';
    $params[':severity'] = $filterSeverity;
}
if ($filterServerId > 0) {
    $where[] = 'a.server_id = :server_id';
    $params[':server_id'] = $filterServerId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cacheTtl = cache_ttl('cache_ttl_alert_logs', 20);
$cacheKey = 'alert:logs:' . md5(json_encode([
    'page' => $page,
    'per' => $perPage,
    'type' => $filterType,
    'severity' => $filterSeverity,
    'server_id' => $filterServerId,
], JSON_UNESCAPED_SLASHES));
$cached = cache_get($cacheKey);

if (is_array($cached) && isset($cached['rows'], $cached['total'])) {
    $rows = is_array($cached['rows']) ? $cached['rows'] : [];
    $total = (int) ($cached['total'] ?? 0);
} else {
    $countRow = db_one(
        'SELECT COUNT(*) AS total
         FROM alert_logs a
         LEFT JOIN servers s ON s.id = a.server_id
         ' . $whereSql,
        $params
    );
    $total = (int) ($countRow['total'] ?? 0);

    $sql = 'SELECT
                a.id, a.server_id, a.alert_type, a.severity, a.title, a.message, a.context_json,
                a.sent_email, a.sent_telegram, a.created_at,
                s.name AS server_name
            FROM alert_logs a
            LEFT JOIN servers s ON s.id = a.server_id
            ' . $whereSql . '
            ORDER BY a.id DESC
            LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $rows = db_all($sql, $params);
    cache_set($cacheKey, ['rows' => $rows, 'total' => $total], $cacheTtl);
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');
$types = db_all('SELECT DISTINCT alert_type FROM alert_logs ORDER BY alert_type ASC');
$severities = ['info', 'warning', 'danger', 'success'];

$title = APP_NAME . ' - Alert Logs';
$activeNav = 'alerts';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Alert Logs</h1>
            <p class="page-subtitle">Review incident events, severity, and delivery channel status.</p>
        </div>
        <div class="toolbar-actions">
            <?php
            $q = http_build_query([
                'alert_type' => $filterType,
                'severity' => $filterSeverity,
                'server_id' => $filterServerId,
            ]);
            ?>
            <a href="<?= e(app_url('admin/export.php?type=alerts&format=csv&' . $q)) ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
            <a href="<?= e(app_url('admin/export.php?type=alerts&format=json&' . $q)) ?>" class="btn btn-outline-info btn-sm">Export JSON</a>
        </div>
    </section>

    <form method="get" class="card card-neon p-3" data-ui-section>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                    <option value="">All</option>
                    <?php foreach ($types as $type): ?>
                        <?php $t = (string) $type['alert_type']; ?>
                        <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Severity</label>
                <select class="form-select" name="severity">
                    <option value="">All</option>
                    <?php foreach ($severities as $sev): ?>
                        <option value="<?= e($sev) ?>" <?= $filterSeverity === $sev ? 'selected' : '' ?>><?= e($sev) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Server</label>
                <select class="form-select" name="server_id">
                    <option value="0">All</option>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= e((string) $server['id']) ?>" <?= $filterServerId === (int) $server['id'] ? 'selected' : '' ?>><?= e((string) $server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-info w-100" type="submit">Filter</button>
                <a class="btn btn-outline-light" href="<?= e(app_url('admin/alert-logs.php')) ?>">Reset</a>
            </div>
        </div>
    </form>

    <section class="card card-neon" data-ui-section>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Server</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Title</th>
                    <th>Channel</th>
                    <th class="text-end">Details</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="table-empty">No alert data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $sev = (string) $row['severity'];
                    $sevClass = match ($sev) {
                        'danger' => 'badge-severity badge-severity-danger',
                        'warning' => 'badge-severity badge-severity-warning',
                        'success' => 'badge-severity badge-severity-success',
                        default => 'badge-severity badge-severity-info',
                    };
                    $contextJson = (string) ($row['context_json'] ?? '');
                    ?>
                    <tr>
                        <td><?= e((string) $row['id']) ?></td>
                        <td><?= e((string) $row['created_at']) ?></td>
                        <td><?= e((string) ($row['server_name'] ?? 'global')) ?></td>
                        <td><code><?= e((string) $row['alert_type']) ?></code></td>
                        <td><span class="badge <?= e($sevClass) ?> text-uppercase"><?= e($sev) ?></span></td>
                        <td><?= e((string) $row['title']) ?></td>
                        <td>
                            <span class="badge badge-channel <?= (int) $row['sent_email'] === 1 ? 'badge-channel-on' : 'badge-channel-off' ?>">Email</span>
                            <span class="badge badge-channel <?= (int) $row['sent_telegram'] === 1 ? 'badge-channel-on' : 'badge-channel-off' ?>">Telegram</span>
                        </td>
                        <td class="text-end">
                            <button
                                class="btn btn-sm btn-outline-info"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#alertDetailModal"
                                data-alert-title="<?= e((string) $row['title']) ?>"
                                data-alert-message="<?= e((string) $row['message']) ?>"
                                data-alert-context="<?= e($contextJson) ?>"
                            >
                                View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <div class="text-secondary small">Total: <?= e((string) $total) ?> logs</div>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $base = app_url('admin/alert-logs.php?type=' . urlencode($filterType) . '&severity=' . urlencode($filterSeverity) . '&server_id=' . $filterServerId . '&page=');
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($base . max(1, $page - 1)) ?>">Prev</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link"><?= e((string) $page) ?>/<?= e((string) $totalPages) ?></span></li>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($base . min($totalPages, $page + 1)) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    </section>
</main>

<div class="modal fade" id="alertDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-surface border-soft">
            <div class="modal-header">
                <h5 class="modal-title" id="alertDetailTitle">Alert Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="alertDetailMessage"></p>
                <h6>Context JSON</h6>
                <pre class="bg-surface-2 rounded p-3 border-soft mb-0"><code id="alertDetailContext">{}</code></pre>
            </div>
        </div>
    </div>
</div>

<script>
  const modal = document.getElementById('alertDetailModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const title = btn.getAttribute('data-alert-title') || 'Alert Details';
      const message = btn.getAttribute('data-alert-message') || '';
      const contextRaw = btn.getAttribute('data-alert-context') || '{}';
      let contextPretty = contextRaw;
      try {
        contextPretty = JSON.stringify(JSON.parse(contextRaw), null, 2);
      } catch (e) {}
      document.getElementById('alertDetailTitle').textContent = title;
      document.getElementById('alertDetailMessage').textContent = message;
      document.getElementById('alertDetailContext').textContent = contextPretty;
    });
  }
</script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
