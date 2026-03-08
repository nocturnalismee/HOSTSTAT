<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

function output_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        echo 'Cannot open output stream';
        exit;
    }
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

function output_json(string $filename, array $rows): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$type = (string) ($_GET['type'] ?? '');
$format = (string) ($_GET['format'] ?? 'csv');

if (in_array($type, ['alerts', 'metrics', 'services', 'audits'], true)) {
    $format = in_array($format, ['csv', 'json'], true) ? $format : 'csv';
    audit_log('export_download', 'Export requested', 'export', null, ['type' => $type, 'format' => $format]);

    if ($type === 'alerts') {
        $filterType = trim((string) ($_GET['alert_type'] ?? ''));
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

        $rows = db_all(
            'SELECT
                a.id, a.server_id, COALESCE(s.name, "global") AS server_name,
                a.alert_type, a.severity, a.title, a.message, a.context_json,
                a.sent_email, a.sent_telegram, a.created_at
             FROM alert_logs a
             LEFT JOIN servers s ON s.id = a.server_id
             ' . $whereSql . '
             ORDER BY a.id DESC',
            $params
        );

        $filename = 'servmon_alerts_' . date('Ymd_His') . '.' . $format;
        if ($format === 'json') {
            output_json($filename, $rows);
        }
        output_csv($filename, $rows);
    }

    if ($type === 'metrics') {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $history = (string) ($_GET['history'] ?? '24h');

        $historySql = match ($history) {
            '7d' => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            '30d' => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        };

        $where = [$historySql];
        $params = [];
        if ($serverId > 0) {
            $where[] = 'm.server_id = :server_id';
            $params[':server_id'] = $serverId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = db_all(
            'SELECT
                m.id, m.server_id, s.name AS server_name, m.recorded_at, m.uptime,
                m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load,
                m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
             FROM metrics m
             LEFT JOIN servers s ON s.id = m.server_id
             ' . $whereSql . '
             ORDER BY m.recorded_at DESC',
            $params
        );

        $filename = 'servmon_metrics_' . date('Ymd_His') . '.' . $format;
        if ($format === 'json') {
            output_json($filename, $rows);
        }
        output_csv($filename, $rows);
    }

    if ($type === 'services') {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $history = (string) ($_GET['history'] ?? '24h');

        $historySql = match ($history) {
            '7d' => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            '30d' => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        };

        $where = [$historySql];
        $params = [];
        if ($serverId > 0) {
            $where[] = 'sm.server_id = :server_id';
            $params[':server_id'] = $serverId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = db_all(
            'SELECT
                sm.id, sm.metric_id, sm.server_id, s.name AS server_name, sm.recorded_at,
                sm.service_group, sm.service_key, sm.unit_name, sm.status, sm.source
             FROM service_metrics sm
             LEFT JOIN servers s ON s.id = sm.server_id
             ' . $whereSql . '
             ORDER BY sm.recorded_at DESC',
            $params
        );

        $filename = 'servmon_services_' . date('Ymd_His') . '.' . $format;
        if ($format === 'json') {
            output_json($filename, $rows);
        }
        output_csv($filename, $rows);
    }

    if ($type === 'audits') {
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

        $rows = db_all(
            'SELECT
                a.id, a.user_id, COALESCE(a.username, "system") AS username,
                a.action_type, a.action_detail, a.target_type, a.target_id,
                a.context_json, a.ip_address, a.created_at
             FROM admin_audit_logs a
             ' . $whereSql . '
             ORDER BY a.id DESC',
            $params
        );

        $filename = 'servmon_audits_' . date('Ymd_His') . '.' . $format;
        if ($format === 'json') {
            output_json($filename, $rows);
        }
        output_csv($filename, $rows);
    }
}

$servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');
$users = db_all('SELECT id, username FROM users ORDER BY username ASC');
$title = APP_NAME . ' - Export Data';
$activeNav = 'export';
require_once __DIR__ . '/../includes/layout/head.php';
require_once __DIR__ . '/../includes/layout/nav.php';
require_once __DIR__ . '/../includes/layout/flash.php';
?>
<main class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Export Data</h1>
            <p class="page-subtitle">Download alerts, metrics, service checks, and audit logs in CSV or JSON format.</p>
        </div>
    </section>

    <div class="export-grid">
        <!-- Alert Logs Export -->
        <div class="export-card">
            <div class="export-card-header">
                <div class="export-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                </div>
                <div>
                    <h3 class="export-title">Alert Logs</h3>
                    <p class="export-desc">Export historical alert notifications</p>
                </div>
            </div>
            <form method="get" class="export-form">
                <input type="hidden" name="type" value="alerts">
                <div class="export-fields">
                    <div class="export-field">
                        <label class="export-label">Format</label>
                        <select class="form-select" name="format">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Severity</label>
                        <select class="form-select" name="severity">
                            <option value="">All</option>
                            <option value="info">info</option>
                            <option value="warning">warning</option>
                            <option value="danger">danger</option>
                            <option value="success">success</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Server</label>
                        <select class="form-select" name="server_id">
                            <option value="0">All</option>
                            <?php foreach ($servers as $server): ?>
                                <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn export-btn" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download
                </button>
            </form>
        </div>

        <!-- Metrics Export -->
        <div class="export-card">
            <div class="export-card-header">
                <div class="export-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                </div>
                <div>
                    <h3 class="export-title">Metrics</h3>
                    <p class="export-desc">Export server performance metrics</p>
                </div>
            </div>
            <form method="get" class="export-form">
                <input type="hidden" name="type" value="metrics">
                <div class="export-fields">
                    <div class="export-field">
                        <label class="export-label">Format</label>
                        <select class="form-select" name="format">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Range</label>
                        <select class="form-select" name="history">
                            <option value="24h">24 hours</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Server</label>
                        <select class="form-select" name="server_id">
                            <option value="0">All</option>
                            <?php foreach ($servers as $server): ?>
                                <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn export-btn" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download
                </button>
            </form>
        </div>

        <!-- Service Metrics Export -->
        <div class="export-card">
            <div class="export-card-header">
                <div class="export-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                </div>
                <div>
                    <h3 class="export-title">Service Metrics</h3>
                    <p class="export-desc">Export service status history</p>
                </div>
            </div>
            <form method="get" class="export-form">
                <input type="hidden" name="type" value="services">
                <div class="export-fields">
                    <div class="export-field">
                        <label class="export-label">Format</label>
                        <select class="form-select" name="format">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Range</label>
                        <select class="form-select" name="history">
                            <option value="24h">24 hours</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Server</label>
                        <select class="form-select" name="server_id">
                            <option value="0">All</option>
                            <?php foreach ($servers as $server): ?>
                                <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn export-btn" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download
                </button>
            </form>
        </div>

        <!-- Audit Logs Export -->
        <div class="export-card">
            <div class="export-card-header">
                <div class="export-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <div>
                    <h3 class="export-title">Audit Logs</h3>
                    <p class="export-desc">Export admin activity logs</p>
                </div>
            </div>
            <form method="get" class="export-form">
                <input type="hidden" name="type" value="audits">
                <div class="export-fields export-fields-audit">
                    <div class="export-field">
                        <label class="export-label">Format</label>
                        <select class="form-select" name="format">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">User</label>
                        <select class="form-select" name="user_id">
                            <option value="0">All</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= e((string) $u['id']) ?>"><?= e((string) $u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="export-field">
                        <label class="export-label">Action</label>
                        <input class="form-control" name="action_type" placeholder="optional">
                    </div>
                    <div class="export-field">
                        <label class="export-label">Date From</label>
                        <input class="form-control" type="date" name="date_from">
                    </div>
                    <div class="export-field">
                        <label class="export-label">Date To</label>
                        <input class="form-control" type="date" name="date_to">
                    </div>
                </div>
                <button class="btn export-btn" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download
                </button>
            </form>
        </div>
    </div>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/layout/footer.php';
