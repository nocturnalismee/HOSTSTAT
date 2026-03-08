<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function is_server_in_maintenance(int $serverId): bool
{
    if ($serverId <= 0) {
        return false;
    }

    $row = db_one(
        'SELECT maintenance_mode, maintenance_until
         FROM servers
         WHERE id = :id
         LIMIT 1',
        [':id' => $serverId]
    );
    if ($row === null || (int) ($row['maintenance_mode'] ?? 0) !== 1) {
        return false;
    }

    $until = (string) ($row['maintenance_until'] ?? '');
    if ($until === '') {
        return true;
    }

    $untilTs = strtotime($until);
    if ($untilTs === false) {
        return true;
    }

    return time() <= $untilTs;
}

function maintenance_display_text(array $server): string
{
    $enabled = (int) ($server['maintenance_mode'] ?? 0) === 1;
    if (!$enabled) {
        return '-';
    }

    $until = (string) ($server['maintenance_until'] ?? '');
    if ($until === '') {
        return 'ON';
    }
    return 'Until ' . $until;
}
