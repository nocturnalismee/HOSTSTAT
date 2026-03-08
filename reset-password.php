<?php
declare(strict_types=1);

/**
 * CLI tool to reset admin password.
 *
 * Usage:
 *   php reset-password.php                  # interactive prompt
 *   php reset-password.php <username> <new_password>
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit(1);
}

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

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Show existing users
$users = $pdo->query('SELECT id, username, role FROM users ORDER BY id')->fetchAll();
if (empty($users)) {
    fwrite(STDERR, "No users found in database.\n");
    exit(1);
}

echo "\nExisting users:\n";
echo str_pad('ID', 5) . str_pad('Username', 25) . "Role\n";
echo str_repeat('-', 45) . "\n";
foreach ($users as $u) {
    echo str_pad((string) $u['id'], 5) . str_pad($u['username'], 25) . $u['role'] . "\n";
}
echo "\n";

// Get username
$username = $argv[1] ?? null;
if ($username === null) {
    echo "Enter username to reset: ";
    $username = trim((string) fgets(STDIN));
}

if ($username === '') {
    fwrite(STDERR, "Error: Username cannot be empty.\n");
    exit(1);
}

// Verify user exists
$user = $pdo->prepare('SELECT id, username, role FROM users WHERE username = :u LIMIT 1');
$user->execute([':u' => $username]);
$row = $user->fetch();

if ($row === false) {
    fwrite(STDERR, "Error: User '{$username}' not found.\n");
    exit(1);
}

// Get new password
$newPassword = $argv[2] ?? null;
if ($newPassword === null) {
    echo "Enter new password (min 6 chars): ";
    $newPassword = trim((string) fgets(STDIN));
}

if (strlen($newPassword) < 6) {
    fwrite(STDERR, "Error: Password must be at least 6 characters.\n");
    exit(1);
}

// Update password
$hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
$stmt->execute([':hash' => $hash, ':id' => $row['id']]);

echo "\n✅ Password for '{$username}' (ID: {$row['id']}, Role: {$row['role']}) has been reset.\n";
