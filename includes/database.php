<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Database connection with retry logic.
 * Max 3 attempts with 200ms delay between retries.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $maxRetries = 3;
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (PDOException $e) {
            if ($attempt === $maxRetries) {
                throw $e;
            }
            usleep(200000); // 200ms delay before retry
        }
    }

    // Should not reach here, but satisfy static analysis
    throw new RuntimeException('Database connection failed after retries.');
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec(string $sql, array $params = []): bool
{
    $stmt = db()->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Optimized latest metric join SQL.
 *
 * Uses servers.latest_metric_id column (updated on push) instead of
 * a heavy ROW_NUMBER() OVER subquery that scans the entire metrics table.
 */
function latest_metric_join_sql(string $serverAlias = 's', string $metricAlias = 'm'): string
{
    static $hasColumn = null;

    $s = preg_replace('/[^a-zA-Z0-9_]/', '', $serverAlias) ?: 's';
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $metricAlias) ?: 'm';

    // One-time check: does the optimized column exist?
    if ($hasColumn === null) {
        try {
            $col = db_one(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'servers'
                   AND column_name = 'latest_metric_id'
                 LIMIT 1"
            );
            $hasColumn = ($col !== null);
        } catch (Throwable) {
            $hasColumn = false;
        }
    }

    if ($hasColumn) {
        return " LEFT JOIN metrics {$m} ON {$m}.id = {$s}.latest_metric_id";
    }

    // Fallback: ROW_NUMBER subquery (works without migration)
    return "
     LEFT JOIN (
         SELECT ranked.server_id, ranked.id
         FROM (
             SELECT id, server_id,
                    ROW_NUMBER() OVER (PARTITION BY server_id ORDER BY recorded_at DESC, id DESC) AS rn
             FROM metrics
         ) ranked
         WHERE ranked.rn = 1
     ) lm ON lm.server_id = {$s}.id
     LEFT JOIN metrics {$m} ON {$m}.id = lm.id";
}
