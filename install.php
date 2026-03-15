<?php
declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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

function isAlreadyInstalled(): bool
{
    return is_file(__DIR__ . '/includes/local.php');
}

function executeSqlFile(PDO $pdo, string $filePath): int
{
    if (!is_file($filePath)) {
        throw new RuntimeException('SQL file not found: ' . basename($filePath));
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Failed to read SQL file: ' . basename($filePath));
    }

    $statements = splitSqlStatements($sql);
    $executed = 0;
    foreach ($statements as $statement) {
        executeSqlStatement($pdo, $statement);
        $executed++;
    }

    return $executed;
}

function executeSqlStatement(PDO $pdo, string $statement): void
{
    $stmt = $pdo->prepare($statement);
    $stmt->execute();

    // Drain all possible rowsets to avoid "unbuffered queries are active"
    // on migration patterns that use PREPARE/EXECUTE/DEALLOCATE.
    do {
        try {
            $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            // Statement may not return a rowset.
        }
    } while ($stmt->nextRowset());

    $stmt->closeCursor();
}

function discoverMigrations(string $dir): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException('Migration folder not found: sql/migrations');
    }

    $files = glob($dir . '/*.sql');
    if ($files === false) {
        return [];
    }

    natsort($files);
    return array_values($files);
}

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checksum_sha256 CHAR(64) NOT NULL
        ) ENGINE=InnoDB'
    );
}

function getAppliedMigrations(PDO $pdo): array
{
    $rows = $pdo->query('SELECT migration_name, checksum_sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $name = (string) ($row['migration_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $result[$name] = (string) ($row['checksum_sha256'] ?? '');
    }
    return $result;
}

function safeStatementPreview(string $statement): string
{
    $singleLine = preg_replace('/\s+/', ' ', trim($statement)) ?? '';
    if ($singleLine === '') {
        return '';
    }
    return substr($singleLine, 0, 160);
}

function applyPendingMigrations(PDO $pdo, array $files): array
{
    ensureMigrationsTable($pdo);
    $appliedMap = getAppliedMigrations($pdo);
    $alreadyApplied = [];
    $appliedNow = [];

    foreach ($files as $file) {
        $migrationName = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Failed to read migration checksum: ' . $migrationName);
        }

        if (isset($appliedMap[$migrationName])) {
            if ($appliedMap[$migrationName] !== $checksum) {
                throw new RuntimeException('Migration checksum changed after being applied: ' . $migrationName);
            }
            $alreadyApplied[] = $migrationName;
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Failed to read migration: ' . $migrationName);
        }

        $statements = splitSqlStatements($sql);
        foreach ($statements as $statement) {
            try {
                executeSqlStatement($pdo, $statement);
            } catch (Throwable $e) {
                $preview = safeStatementPreview($statement);
                throw new RuntimeException(
                    'Migration failed [' . $migrationName . ']: ' . $e->getMessage() . ($preview !== '' ? ' | SQL: ' . $preview : '')
                );
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
             VALUES (:name, :checksum, NOW())'
        );
        $stmt->execute([
            ':name' => $migrationName,
            ':checksum' => $checksum,
        ]);
        $appliedNow[] = $migrationName;
    }

    return [
        'total_discovered' => count($files),
        'already_applied' => $alreadyApplied,
        'applied_now' => $appliedNow,
    ];
}

function markMigrationsAsApplied(PDO $pdo, array $files): array
{
    ensureMigrationsTable($pdo);
    $appliedMap = getAppliedMigrations($pdo);
    $alreadyApplied = [];
    $markedNow = [];

    foreach ($files as $file) {
        $migrationName = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Failed to read migration checksum: ' . $migrationName);
        }

        if (isset($appliedMap[$migrationName])) {
            if ($appliedMap[$migrationName] !== $checksum) {
                throw new RuntimeException('Migration checksum changed after being applied: ' . $migrationName);
            }
            $alreadyApplied[] = $migrationName;
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
             VALUES (:name, :checksum, NOW())'
        );
        $stmt->execute([
            ':name' => $migrationName,
            ':checksum' => $checksum,
        ]);
        $markedNow[] = $migrationName;
    }

    return [
        'total_discovered' => count($files),
        'already_applied' => $alreadyApplied,
        'marked_now' => $markedNow,
    ];
}

function defaultAppSettings(): array
{
    return [
        'branding_logo_url' => '',
        'branding_favicon_url' => '',
        'alert_down_minutes' => '5',
        'alert_cooldown_minutes' => '30',
        'alert_service_status_enabled' => '1',
        'alert_ping_enabled' => '1',
        'threshold_mail_queue' => '50',
        'threshold_mail_queue_critical' => '100',
        'threshold_cpu_load' => '2.00',
        'threshold_cpu_load_critical' => '4.00',
        'threshold_ram_pct' => '85',
        'threshold_ram_pct_critical' => '95',
        'threshold_disk_pct' => '90',
        'threshold_disk_pct_critical' => '97',
        'alert_service_flap_suppress_minutes' => '5',
        'cache_ttl_status_list' => '15',
        'cache_ttl_status_single' => '15',
        'cache_ttl_history_24h' => '30',
        'cache_ttl_history_7d' => '120',
        'cache_ttl_history_30d' => '180',
        'cache_ttl_alert_logs' => '20',
        'cache_ttl_disk_health_list' => '15',
        'cache_ttl_disk_health_single' => '10',
        'cache_ttl_disk_health_history' => '20',
        'disk_rollup_days' => '2',
        'disk_push_max_body_bytes' => '1048576',
        'disk_push_max_items' => '64',
        'public_alerts_redact_message' => '0',
        'channel_email_enabled' => '0',
        'channel_telegram_enabled' => '0',
        'session_idle_timeout_minutes' => '60',
        'session_absolute_timeout_minutes' => '480',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_secure' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => 'servmon',
        'smtp_to_email' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'telegram_thread_id' => '',
        'retention_days' => '30',
        'disk_retention_days' => '90',
        'agent_push_signature_required' => '0',
    ];
}

function verifyFreshInstallSchema(PDO $pdo): array
{
    $checks = [
        'worker_health table' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'worker_health'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
        'servers.latest_metric_id column' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'servers' AND column_name = 'latest_metric_id'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
        'idx_servers_latest_metric index' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'servers' AND index_name = 'idx_servers_latest_metric'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
    ];

    $missing = [];
    foreach ($checks as $label => $check) {
        if ($check($pdo) !== true) {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        throw new RuntimeException(
            'Schema parity check failed after import. Missing: '
            . implode(', ', $missing)
            . '. Ensure sql/schema.sql includes required migration structures before install.'
        );
    }

    return array_keys($checks);
}

function ensureBaseRecordsWithoutSeed(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO users (username, password_hash, role)
         VALUES ('admin', '', 'admin')
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );

    $insert = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach (defaultAppSettings() as $key => $value) {
        $insert->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }
}

function writeLocalConfig(array $config): void
{
    $localPath = __DIR__ . '/includes/local.php';
    $localContent = "<?php\nreturn " . var_export($config, true) . ";\n";
    $bytes = file_put_contents($localPath, $localContent);
    if ($bytes === false) {
        throw new RuntimeException('Failed to write includes/local.php (check permissions).');
    }
}

function loadLocalConfig(): array
{
    $path = __DIR__ . '/includes/local.php';
    if (!is_file($path)) {
        throw new RuntimeException('includes/local.php not found.');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid includes/local.php format.');
    }
    return array_map(static fn ($v): string => (string) $v, $config);
}

function makePdo(string $host, string $port, string $dbName, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }
    return new PDO($dsn, $user, $pass, $options);
}

$errors = [];
$success = false;
$mode = 'install';
$lastOperation = '';
$summary = [];
$stepStatus = [
    'db' => 'pending',
    'schema' => 'pending',
    'seed' => 'pending',
    'migrations' => 'pending',
    'config' => 'pending',
];

$defaultValues = [
    'app_name' => 'servmon',
    'app_env' => 'development',
    'app_url' => '',
    'app_tz' => 'Asia/Jakarta',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'servmon',
    'db_user' => 'root',
    'db_pass' => '',
    'redis_enabled' => '0',
    'redis_host' => '127.0.0.1',
    'redis_port' => '6379',
    'redis_password' => '',
    'redis_db' => '0',
    'redis_prefix' => 'servmon:',
    'trust_proxy_headers' => '0',
    'admin_password' => 'admin123',
    'import_seed' => '0',
];

$data = $defaultValues;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($defaultValues) as $key) {
        if ($key === 'import_seed') {
            $data[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }
        $data[$key] = trim((string) ($_POST[$key] ?? $defaultValues[$key]));
    }
}

$installed = isAlreadyInstalled();
if ($installed) {
    $mode = 'upgrade';
    $stepStatus['schema'] = 'skipped';
    $stepStatus['seed'] = 'skipped';
    $stepStatus['config'] = 'skipped';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if ($data['db_name'] === '' || $data['db_user'] === '' || $data['admin_password'] === '') {
        $errors[] = 'Database name, database user, and admin password are required.';
    }
    if (strlen($data['admin_password']) < 8) {
        $errors[] = 'Admin password minimal 8 karakter.';
    }

    if (empty($errors)) {
        try {
            $schemaPath = __DIR__ . '/sql/schema.sql';
            $seedPath = __DIR__ . '/sql/seed.sql';
            $migrationDir = __DIR__ . '/sql/migrations';
            if (!is_file($schemaPath)) {
                throw new RuntimeException('sql/schema.sql not found.');
            }
            if ($data['import_seed'] === '1' && !is_file($seedPath)) {
                throw new RuntimeException('sql/seed.sql not found.');
            }

            $dbNameSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $data['db_name']);
            if ($dbNameSafe === null || $dbNameSafe === '') {
                throw new RuntimeException('Invalid database name.');
            }

            $rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $data['db_host'], $data['db_port']);
            $rootOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $rootOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }
            $pdoRoot = new PDO($rootDsn, $data['db_user'], $data['db_pass'], $rootOptions);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $summary[] = ['step' => 'db', 'message' => 'Database connection successful and DB is ready.'];
            $stepStatus['db'] = 'success';

            $pdo = makePdo($data['db_host'], $data['db_port'], $dbNameSafe, $data['db_user'], $data['db_pass']);
            $schemaCount = executeSqlFile($pdo, $schemaPath);
            $summary[] = ['step' => 'schema', 'message' => 'Schema diimport: ' . $schemaCount . ' statement.'];
            $verifiedSchemaItems = verifyFreshInstallSchema($pdo);
            $summary[] = ['step' => 'schema', 'message' => 'Schema parity check passed: ' . implode(', ', $verifiedSchemaItems) . '.'];
            $stepStatus['schema'] = 'success';

            if ($data['import_seed'] === '1') {
                $seedCount = executeSqlFile($pdo, $seedPath);
                $summary[] = ['step' => 'seed', 'message' => 'Seed diimport: ' . $seedCount . ' statement.'];
                $stepStatus['seed'] = 'success';
            } else {
                ensureBaseRecordsWithoutSeed($pdo);
                $summary[] = ['step' => 'seed', 'message' => 'Seed sample dilewati. Admin + app_settings default dibuat.'];
                $stepStatus['seed'] = 'skipped';
            }

            $passwordHash = password_hash($data['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE username = :username');
            $stmt->execute([':hash' => $passwordHash, ':username' => 'admin']);

            $migrationFiles = discoverMigrations($migrationDir);
            $migrationResult = markMigrationsAsApplied($pdo, $migrationFiles);
            $summary[] = [
                'step' => 'migrations',
                'message' => sprintf(
                    'Fresh install migrations: total %d, already applied %d, marked as applied %d.',
                    $migrationResult['total_discovered'],
                    count($migrationResult['already_applied']),
                    count($migrationResult['marked_now'])
                ),
            ];
            if (!empty($migrationResult['marked_now'])) {
                foreach ($migrationResult['marked_now'] as $name) {
                    $summary[] = ['step' => 'migrations', 'message' => 'Marked migration as applied: ' . $name];
                }
            } else {
                $summary[] = ['step' => 'migrations', 'message' => 'No pending migrations.'];
            }
            $stepStatus['migrations'] = 'success';

            $localConfig = [
                'APP_NAME' => $data['app_name'],
                'APP_ENV' => $data['app_env'],
                'APP_URL' => rtrim($data['app_url'], '/'),
                'APP_TZ' => $data['app_tz'],
                'DB_HOST' => $data['db_host'],
                'DB_PORT' => $data['db_port'],
                'DB_NAME' => $dbNameSafe,
                'DB_USER' => $data['db_user'],
                'DB_PASS' => $data['db_pass'],
                'REDIS_ENABLED' => ($data['redis_enabled'] === '1') ? '1' : '0',
                'REDIS_HOST' => $data['redis_host'],
                'REDIS_PORT' => $data['redis_port'],
                'REDIS_PASSWORD' => $data['redis_password'],
                'REDIS_DB' => $data['redis_db'],
                'REDIS_PREFIX' => $data['redis_prefix'],
                'TRUST_PROXY_HEADERS' => ($data['trust_proxy_headers'] === '1') ? '1' : '0',
            ];
            writeLocalConfig($localConfig);
            $summary[] = ['step' => 'config', 'message' => 'Local configuration written to includes/local.php successfully.'];
            $stepStatus['config'] = 'success';

            $success = true;
            $installed = true;
            $lastOperation = 'install';
            $mode = 'upgrade';
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
            foreach ($stepStatus as $step => $status) {
                if ($status === 'pending') {
                    $stepStatus[$step] = 'error';
                    break;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed && ($_POST['action'] ?? '') === 'upgrade') {
    $errors[] = 'Web upgrade is disabled on installed instances. Please run upgrade via CLI: php migrate.php';
    $stepStatus['db'] = 'skipped';
    $stepStatus['migrations'] = 'skipped';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>APP INSTALLER</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
            --sv-bg: #141414;
            --sv-surface: #1b1b1b;
            --sv-surface-soft: #232323;
            --sv-border: #333333;
            --sv-text: #e9e9e9;
            --sv-muted: #9a9a9a;
            --sv-accent: #2dd4bf;
            --sv-success: #22c55e;
            --sv-warning: #f59e0b;
            --sv-danger: #ef4444;
            --sv-shadow: 0 6px 16px rgba(0, 0, 0, 0.28);
        }
        @media (prefers-color-scheme: light) {
            :root {
                color-scheme: light;
                --sv-bg: #f8fafc;
                --sv-surface: #ffffff;
                --sv-surface-soft: #f1f5f9;
                --sv-border: #cbd5e1;
                --sv-text: #0f172a;
                --sv-muted: #475569;
                --sv-accent: #0284c7;
                --sv-success: #15803d;
                --sv-warning: #b45309;
                --sv-danger: #b91c1c;
                --sv-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
            }
        }
        body {
            background: var(--sv-bg);
            color: var(--sv-text);
            min-height: 100vh;
        }
        .install-card {
            background: var(--sv-surface);
            border: 1px solid var(--sv-border);
            border-radius: 1rem;
            box-shadow: var(--sv-shadow);
        }
        .install-title {
            letter-spacing: 0.01em;
            margin-bottom: 0.25rem;
        }
        .muted {
            color: var(--sv-muted);
        }
        .form-control,
        .form-select {
            background: var(--sv-surface);
            color: var(--sv-text);
            border-color: var(--sv-border);
        }
        .form-control:focus,
        .form-select:focus {
            border-color: var(--sv-accent);
            box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--sv-accent) 22%, transparent);
        }
        .step-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.6rem;
            border-radius: 999px;
            font-size: 0.78rem;
            border: 1px solid var(--sv-border);
            background: var(--sv-surface-soft);
            color: var(--sv-muted);
            text-transform: uppercase;
        }
        .step-chip.is-success {
            border-color: color-mix(in srgb, var(--sv-success) 48%, transparent);
            color: var(--sv-success);
            background: color-mix(in srgb, var(--sv-success) 14%, transparent);
        }
        .step-chip.is-error {
            border-color: color-mix(in srgb, var(--sv-danger) 48%, transparent);
            color: var(--sv-danger);
            background: color-mix(in srgb, var(--sv-danger) 14%, transparent);
        }
        .step-chip.is-skipped {
            border-color: color-mix(in srgb, var(--sv-warning) 48%, transparent);
            color: var(--sv-warning);
            background: color-mix(in srgb, var(--sv-warning) 14%, transparent);
        }
        .log-list {
            background: var(--sv-surface-soft);
            border: 1px solid var(--sv-border);
            border-radius: 0.75rem;
            max-height: 380px;
            overflow: auto;
        }
        .log-row {
            padding: 0.55rem 0.75rem;
            border-bottom: 1px solid color-mix(in srgb, var(--sv-border) 65%, transparent);
            font-size: 0.9rem;
        }
        .log-row:last-child {
            border-bottom: 0;
        }
        .log-step {
            color: var(--sv-muted);
            font-size: 0.74rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .help-block {
            background: color-mix(in srgb, var(--sv-accent) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--sv-accent) 28%, transparent);
            border-radius: 0.75rem;
            padding: 0.75rem;
            color: var(--sv-text);
            font-size: 0.9rem;
        }
        .divider {
            border-top: 1px dashed color-mix(in srgb, var(--sv-border) 72%, transparent);
            margin: 1rem 0;
        }
        .btn-info {
            background: var(--sv-accent);
            border-color: var(--sv-accent);
            color: #ffffff;
            font-weight: 600;
        }
        .btn-info:hover {
            background: color-mix(in srgb, var(--sv-accent) 88%, #000000 12%);
            border-color: color-mix(in srgb, var(--sv-accent) 88%, #000000 12%);
            color: #ffffff;
        }
        .btn-outline-light {
            color: var(--sv-text);
            border-color: var(--sv-border);
        }
        .btn-outline-light:hover {
            color: var(--sv-text);
            border-color: var(--sv-border);
            background: var(--sv-surface-soft);
        }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="install-card shadow h-100">
                <div class="p-4 p-md-5">
                    <h1 class="h3 install-title">APP INSTALLER</h1>
                    <p class="muted">
                        <?= $mode === 'install'
                            ? 'Set up database, import schema, run migrations automatically, and write local configuration.'
                            : 'Application is already installed. Web upgrade is disabled; run pending migrations via CLI (`php migrate.php`).' ?>
                    </p>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= h($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <?= $lastOperation === 'install' ? 'Installation successful.' : 'Migration upgrade successful (CLI).' ?>
                            <div class="mt-2">If this is a production server, remove or rename `install.php` after setup.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'install'): ?>
                        <form method="post" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">APP_NAME</label>
                                    <input class="form-control" name="app_name" value="<?= h($data['app_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">APP_ENV</label>
                                    <select class="form-select" name="app_env">
                                        <option value="development"<?= $data['app_env'] === 'development' ? ' selected' : '' ?>>development</option>
                                        <option value="production"<?= $data['app_env'] === 'production' ? ' selected' : '' ?>>production</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">APP_URL (opsional)</label>
                                    <input class="form-control" name="app_url" placeholder="https://status.domain.com" value="<?= h($data['app_url']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">APP_TZ</label>
                                    <input class="form-control" name="app_tz" value="<?= h($data['app_tz']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">DB_HOST</label>
                                    <input class="form-control" name="db_host" value="<?= h($data['db_host']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">DB_PORT</label>
                                    <input class="form-control" name="db_port" value="<?= h($data['db_port']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">DB_NAME</label>
                                    <input class="form-control" name="db_name" value="<?= h($data['db_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB_USER</label>
                                    <input class="form-control" name="db_user" value="<?= h($data['db_user']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DB_PASS</label>
                                    <input class="form-control" type="password" name="db_pass" value="<?= h($data['db_pass']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Redis Cache</label>
                                    <select class="form-select" name="redis_enabled">
                                        <option value="0"<?= $data['redis_enabled'] === '0' ? ' selected' : '' ?>>Nonaktif</option>
                                        <option value="1"<?= $data['redis_enabled'] === '1' ? ' selected' : '' ?>>Aktif</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">REDIS_HOST</label>
                                    <input class="form-control" name="redis_host" value="<?= h($data['redis_host']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">REDIS_PORT</label>
                                    <input class="form-control" name="redis_port" value="<?= h($data['redis_port']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">REDIS_DB</label>
                                    <input class="form-control" name="redis_db" value="<?= h($data['redis_db']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">REDIS_PASSWORD</label>
                                    <input class="form-control" type="password" name="redis_password" value="<?= h($data['redis_password']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">REDIS_PREFIX</label>
                                    <input class="form-control" name="redis_prefix" value="<?= h($data['redis_prefix']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Trust Proxy Headers</label>
                                    <select class="form-select" name="trust_proxy_headers">
                                        <option value="0"<?= $data['trust_proxy_headers'] === '0' ? ' selected' : '' ?>>Off (default)</option>
                                        <option value="1"<?= $data['trust_proxy_headers'] === '1' ? ' selected' : '' ?>>On (Cloudflare / Reverse Proxy)</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Password Admin Awal (`admin`)</label>
                                    <input class="form-control" type="password" name="admin_password" value="<?= h($data['admin_password']) ?>" required>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="import_seed" name="import_seed" value="1"<?= $data['import_seed'] === '1' ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="import_seed">Import sample seed data (demo/testing)</label>
                                    </div>
                                </div>
                            </div>
                            <div class="divider"></div>
                            <button class="btn btn-info" type="submit">Install Now</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            Install mode is locked because `includes/local.php` already exists.
                            Web upgrade is disabled. Run pending migrations from CLI:
                            <code>php migrate.php</code>
                        </div>
                    <?php endif; ?>

                    <div class="help-block mt-4">
                        Tip: migrations are scanned automatically from `sql/migrations`, and only pending files are executed.
                        For uptime checks, run workers:
                        `workers/alert-check.php`, `workers/ping-check.php`, `workers/disk-rollup.php`, and `workers/cleanup.php` via cron.
                        After setup is complete, remove or rename `install.php` for security.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="install-card shadow h-100">
                <div class="p-4 p-md-4">
                    <h2 class="h5 mb-3">Step Status</h2>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <?php
                        $labels = [
                            'db' => 'DB Connect',
                            'schema' => 'Schema',
                            'seed' => 'Seed',
                            'migrations' => 'Migrations',
                            'config' => 'Config File',
                        ];
                        foreach ($labels as $key => $label):
                            $status = $stepStatus[$key] ?? 'pending';
                            $class = match ($status) {
                                'success' => 'is-success',
                                'error' => 'is-error',
                                'skipped' => 'is-skipped',
                                default => '',
                            };
                        ?>
                            <span class="step-chip <?= h($class) ?>">
                                <?= h($label) ?>: <?= h($status) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <h2 class="h6 muted mb-2">Execution Log</h2>
                    <div class="log-list">
                        <?php if (empty($summary)): ?>
                            <div class="log-row muted">No execution yet. Run install/upgrade to see step results.</div>
                        <?php else: ?>
                            <?php foreach ($summary as $row): ?>
                                <div class="log-row">
                                    <div class="log-step"><?= h((string) ($row['step'] ?? 'info')) ?></div>
                                    <div><?= h((string) ($row['message'] ?? '')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-light btn-sm" href="./index.php">Public Dashboard</a>
                        <a class="btn btn-outline-light btn-sm" href="./auth/login.php">Admin Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
