<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cache.php';

require_role('admin');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$filterAction = trim((string) ($_GET['action_type'] ?? ''));
$filterUserId = (int) ($_GET['user_id'] ?? 0);
$filterDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$filterDateTo = trim((string) ($_GET['date_to'] ?? ''));

$where = [];
$params = [];
if ($filterAction !== '') {
    $where[] = 'a.action_type = :action_type';
    $params[':action_type'] = $filterAction;
}
if ($filterUserId > 0) {
    $where[] = 'a.user_id = :user_id';
    $params[':user_id'] = $filterUserId;
}
if ($filterDateFrom !== '') {
    $where[] = 'a.created_at >= :date_from';
    $params[':date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = 'a.created_at <= :date_to';
    $params[':date_to'] = $filterDateTo . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$cacheKey = 'audit:logs:' . md5(json_encode([
    'page' => $page,
    'per' => $perPage,
    'action_type' => $filterAction,
    'user_id' => $filterUserId,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
], JSON_UNESCAPED_SLASHES));
$cached = cache_get($cacheKey);

if (is_array($cached) && isset($cached['rows'], $cached['total'])) {
    $rows = is_array($cached['rows']) ? $cached['rows'] : [];
    $total = (int) ($cached['total'] ?? 0);
} else {
    $countRow = db_one(
        'SELECT COUNT(*) AS total FROM admin_audit_logs a ' . $whereSql,
        $params
    );
    $total = (int) ($countRow['total'] ?? 0);
    $rows = db_all(
        'SELECT
            a.id, a.user_id, COALESCE(a.username, "system") AS username,
            a.action_type, a.action_detail, a.target_type, a.target_id,
            a.context_json, a.ip_address, a.created_at
         FROM admin_audit_logs a
         ' . $whereSql . '
         ORDER BY a.id DESC
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    cache_set($cacheKey, ['rows' => $rows, 'total' => $total], cache_ttl('cache_ttl_alert_logs', 20));
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$actionTypes = db_all('SELECT DISTINCT action_type FROM admin_audit_logs ORDER BY action_type ASC');
$users = db_all('SELECT id, username FROM users ORDER BY username ASC');

$title = APP_NAME . ' - Audit Logs';
$activeNav = 'audit';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Admin Audit Logs</h1>
            <p class="page-subtitle">Track privileged actions, actor identity, target, and request context.</p>
        </div>
        <div class="toolbar-actions">
            <?php
            $q = http_build_query([
                'action_type' => $filterAction,
                'user_id' => $filterUserId,
                'date_from' => $filterDateFrom,
                'date_to' => $filterDateTo,
            ]);
            ?>
            <a href="<?= e(app_url('admin/export.php?type=audits&format=csv&' . $q)) ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
            <a href="<?= e(app_url('admin/export.php?type=audits&format=json&' . $q)) ?>" class="btn btn-outline-info btn-sm">Export JSON</a>
        </div>
    </section>

    <form method="get" class="card card-neon p-3" data-ui-section>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Action Type</label>
                <select class="form-select" name="action_type">
                    <option value="">All</option>
                    <?php foreach ($actionTypes as $row): ?>
                        <?php $action = (string) ($row['action_type'] ?? ''); ?>
                        <option value="<?= e($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>><?= e($action) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">User</label>
                <select class="form-select" name="user_id">
                    <option value="0">All</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= e((string) $u['id']) ?>" <?= $filterUserId === (int) $u['id'] ? 'selected' : '' ?>><?= e((string) $u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="date_from" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="date_to" value="<?= e($filterDateTo) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-info w-100" type="submit">Filter</button>
                <a class="btn btn-outline-light" href="<?= e(app_url('admin/audit-logs.php')) ?>">Reset</a>
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
                    <th>User</th>
                    <th>Action</th>
                    <th>Detail</th>
                    <th>Target</th>
                    <th>IP</th>
                    <th class="text-end">Context</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="table-empty">No audit data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $targetText = trim((string) ($row['target_type'] ?? '')) !== '' ? ((string) $row['target_type'] . '#' . (string) ($row['target_id'] ?? '-')) : '-'; ?>
                    <tr>
                        <td><?= e((string) $row['id']) ?></td>
                        <td><?= e((string) $row['created_at']) ?></td>
                        <td><?= e((string) ($row['username'] ?? 'system')) ?></td>
                        <td><code><?= e((string) $row['action_type']) ?></code></td>
                        <td><?= e((string) $row['action_detail']) ?></td>
                        <td><?= e($targetText) ?></td>
                        <td><?= e((string) ($row['ip_address'] ?? '-')) ?></td>
                        <td class="text-end">
                            <button
                                class="btn btn-sm btn-outline-info"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#auditContextModal"
                                data-audit-context="<?= e((string) ($row['context_json'] ?? '{}')) ?>"
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
                    $base = app_url('admin/audit-logs.php?action_type=' . urlencode($filterAction) . '&user_id=' . $filterUserId . '&date_from=' . urlencode($filterDateFrom) . '&date_to=' . urlencode($filterDateTo) . '&page=');
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

<div class="modal fade" id="auditContextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-surface border-soft">
            <div class="modal-header">
                <h5 class="modal-title">Audit Context</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-surface-2 rounded p-3 border-soft mb-0"><code id="auditContextContent">{}</code></pre>
            </div>
        </div>
    </div>
</div>

<script>
  const auditModal = document.getElementById('auditContextModal');
  if (auditModal) {
    auditModal.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const contextRaw = btn.getAttribute('data-audit-context') || '{}';
      let contextPretty = contextRaw;
      try {
        contextPretty = JSON.stringify(JSON.parse(contextRaw), null, 2);
      } catch (e) {}
      document.getElementById('auditContextContent').textContent = contextPretty;
    });
  }
</script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
