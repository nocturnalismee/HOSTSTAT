<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;

$sql = 'SELECT id, server_id, alert_type, severity, title, message, sent_email, sent_telegram, created_at
        FROM alert_logs
        WHERE id > :since_id
        ORDER BY id ASC
        LIMIT :limit';

$stmt = db()->prepare($sql);
$stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

json_response($rows);
