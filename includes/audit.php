<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

function audit_log(
    string $actionType,
    string $actionDetail,
    ?string $targetType = null,
    ?int $targetId = null,
    array $context = []
): void {
    try {
        $userId = null;
        $username = null;
        if (isset($_SESSION['user_id']) && (is_int($_SESSION['user_id']) || ctype_digit((string) $_SESSION['user_id']))) {
            $userId = (int) $_SESSION['user_id'];
            $user = db_one('SELECT username FROM users WHERE id = :id LIMIT 1', [':id' => $userId]);
            $username = $user['username'] ?? null;
        }

        db_exec(
            'INSERT INTO admin_audit_logs
            (user_id, username, action_type, action_detail, target_type, target_id, context_json, ip_address, created_at)
             VALUES
            (:user_id, :username, :action_type, :action_detail, :target_type, :target_id, :context_json, :ip_address, NOW())',
            [
                ':user_id' => $userId,
                ':username' => $username,
                ':action_type' => substr($actionType, 0, 50),
                ':action_detail' => substr($actionDetail, 0, 255),
                ':target_type' => $targetType !== null ? substr($targetType, 0, 50) : null,
                ':target_id' => $targetId,
                ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip_address' => substr(get_client_ip(), 0, 45),
            ]
        );
    } catch (Throwable $e) {
        // Keep primary workflow running even when audit table is not available yet.
    }
}
