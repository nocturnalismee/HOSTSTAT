<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$ip = get_client_ip();
if (!api_rate_check('public_alerts_api', $ip, 60)) {
    api_rate_limit_exceeded();
}

$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;

$sql = 'SELECT a.id, a.server_id, a.alert_type, a.severity, a.title, a.message, a.created_at
        FROM alert_logs a
        LEFT JOIN servers s ON s.id = a.server_id
        WHERE a.id > :since_id
          AND (a.server_id IS NULL OR s.active = 1)
        ORDER BY a.id ASC
        LIMIT :limit';

$stmt = db()->prepare($sql);
$stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$redactMessage = setting_get('public_alerts_redact_message') === '1';
if ($redactMessage) {
    $rows = array_map(static function (array $row): array {
        $row['message'] = 'Alert detail is hidden in public mode.';
        return $row;
    }, $rows);
}

json_response($rows);
