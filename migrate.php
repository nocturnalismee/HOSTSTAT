<?php
declare(strict_types=1);

/**
 * CLI migration runner for servmon.
 *
 * Usage: php migrate.php
 *
 * Reads database config from includes/local.php, discovers SQL migrations
 * in sql/migrations/, and applies any pending ones.
 */

$localPath = __DIR__ . '/includes/local.php';
if (!is_file($localPath)) {
    fwrite(STDERR, "Error: includes/local.php not found. Run install.php first.\n");
    exit(1);
}

$config = require $localPath;
if (!is_array($config)) {
    fwrite(STDERR, "Error: includes/local.php is invalid.\n");
    exit(1);
}

$dbHost = (string) ($config['DB_HOST'] ?? '127.0.0.1');
$dbPort = (string) ($config['DB_PORT'] ?? '3306');
$dbName = (string) ($config['DB_NAME'] ?? '');
$dbUser = (string) ($config['DB_USER'] ?? '');
$dbPass = (string) ($config['DB_PASS'] ?? '');

if ($dbName === '' || $dbUser === '') {
    fwrite(STDERR, "Error: DB_NAME and DB_USER must be set in local.php.\n");
    exit(1);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// --- Inline migration logic (from install.php) ---

function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $lines = preg_split('/\R/', $sql) ?: [];
    $buffer = '';
    $statements = [];
    $insideBlockComment = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($insideBlockComment) {
            if (str_contains($trimmed, '*/')) {
                $insideBlockComment = false;
            }
            continue;
        }

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        if (str_starts_with($trimmed, '/*')) {
            if (!str_contains($trimmed, '*/')) {
                $insideBlockComment = true;
            }
            continue;
        }

        if (preg_match('/^(CREATE DATABASE|USE)\s+/i', $trimmed) === 1) {
            continue;
        }

        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Execute one SQL statement and drain all possible result sets.
 *
 * This avoids MySQL "Cannot execute queries while other unbuffered queries
 * are active" when running PREPARE/EXECUTE/DEALLOCATE migration patterns.
 */
function executeMigrationStatement(PDO $pdo, string $statement): void
{
    $stmt = $pdo->prepare($statement);
    $stmt->execute();

    // Drain all rowsets if the statement returns any (e.g. EXECUTE stmt -> SELECT 1)
    do {
        try {
            $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            // Some statements do not produce rowsets; safe to ignore here.
        }
    } while ($stmt->nextRowset());

    $stmt->closeCursor();
}

// Ensure migrations table exists
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        checksum_sha256 CHAR(64) NOT NULL
    ) ENGINE=InnoDB'
);

// Get already applied migrations
$appliedMap = [];
$rows = $pdo->query('SELECT migration_name, checksum_sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $name = (string) ($row['migration_name'] ?? '');
    if ($name !== '') {
        $appliedMap[$name] = (string) ($row['checksum_sha256'] ?? '');
    }
}

// Discover migration files
$migrationDir = __DIR__ . '/sql/migrations';
if (!is_dir($migrationDir)) {
    echo "No migrations directory found.\n";
    exit(0);
}

$files = glob($migrationDir . '/*.sql');
if ($files === false || empty($files)) {
    echo "No migration files found.\n";
    exit(0);
}
natsort($files);
$files = array_values($files);

// Apply pending migrations
$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $migrationName = basename($file);
    $checksum = hash_file('sha256', $file);
    if ($checksum === false) {
        fwrite(STDERR, "Error: Failed to read checksum for {$migrationName}\n");
        exit(1);
    }

    if (isset($appliedMap[$migrationName])) {
        if ($appliedMap[$migrationName] !== $checksum) {
            fwrite(STDERR, "Error: Checksum changed for already-applied migration: {$migrationName}\n");
            exit(1);
        }
        $skipped++;
        continue;
    }

    echo "Applying: {$migrationName} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "\nError: Failed to read {$migrationName}\n");
        exit(1);
    }

    $statements = splitSqlStatements($sql);
    foreach ($statements as $statement) {
        try {
            executeMigrationStatement($pdo, $statement);
        } catch (Throwable $e) {
            $preview = substr(preg_replace('/\s+/', ' ', trim($statement)) ?? '', 0, 120);
            fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\nSQL: {$preview}\n");
            exit(1);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
         VALUES (:name, :checksum, NOW())'
    );
    $stmt->execute([':name' => $migrationName, ':checksum' => $checksum]);
    echo "OK\n";
    $applied++;
}

echo "\nMigration complete: {$applied} applied, {$skipped} already up-to-date, " . count($files) . " total.\n";
