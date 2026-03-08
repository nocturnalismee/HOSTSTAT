<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/ping.php';

$testResults = [];
$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_true(bool $cond, string $message): void
{
    global $testResults, $testCount, $passCount, $failCount;
    $testCount++;
    if ($cond) {
        $passCount++;
        $testResults[] = "✓ {$message}";
    } else {
        $failCount++;
        $testResults[] = "✗ {$message}";
    }
}

function assert_equals(mixed $expected, mixed $actual, string $message): void
{
    global $testResults, $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected === $actual) {
        $passCount++;
        $testResults[] = "✓ {$message}";
    } else {
        $failCount++;
        $testResults[] = "✗ {$message} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
    }
}

function assert_false(bool $cond, string $message): void
{
    global $testResults, $testCount, $passCount, $failCount;
    $testCount++;
    if (!$cond) {
        $passCount++;
        $testResults[] = "✓ {$message}";
    } else {
        $failCount++;
        $testResults[] = "✗ {$message} (expected false)";
    }
}

echo "=== Ping Function Tests ===\n\n";

echo "--- ping_normalize_target() tests ---\n";
assert_equals('example.com', ping_normalize_target('example.com'), 'ping_normalize_target plain');
assert_equals('example.com', ping_normalize_target('  example.com  '), 'ping_normalize_target with spaces');
assert_equals('example.com', ping_normalize_target("\texample.com\n"), 'ping_normalize_target with whitespace');

echo "\n--- ping_normalize_target_type() tests ---\n";
assert_equals('domain', ping_normalize_target_type(null), 'ping_normalize_target_type null');
assert_equals('domain', ping_normalize_target_type(''), 'ping_normalize_target_type empty');
assert_equals('domain', ping_normalize_target_type('invalid'), 'ping_normalize_target_type invalid');
assert_equals('ip', ping_normalize_target_type('ip'), 'ping_normalize_target_type ip');
assert_equals('domain', ping_normalize_target_type('domain'), 'ping_normalize_target_type domain');
assert_equals('url', ping_normalize_target_type('url'), 'ping_normalize_target_type url');
assert_equals('ip', ping_normalize_target_type('IP'), 'ping_normalize_target_type uppercase');

echo "\n--- ping_normalize_check_method() tests ---\n";
assert_equals('icmp', ping_normalize_check_method(null), 'ping_normalize_check_method null');
assert_equals('icmp', ping_normalize_check_method(''), 'ping_normalize_check_method empty');
assert_equals('icmp', ping_normalize_check_method('invalid'), 'ping_normalize_check_method invalid');
assert_equals('icmp', ping_normalize_check_method('icmp'), 'ping_normalize_check_method icmp');
assert_equals('http', ping_normalize_check_method('http'), 'ping_normalize_check_method http');
assert_equals('icmp', ping_normalize_check_method('ICMP'), 'ping_normalize_check_method uppercase');

echo "\n--- ping_validate_target() tests ---\n";
assert_true(ping_validate_target('example.com', 'domain', 'icmp'), 'ping_validate_target valid domain');
assert_true(ping_validate_target('192.168.1.1', 'ip', 'icmp'), 'ping_validate_target valid IP');
assert_true(ping_validate_target('http://example.com', 'domain', 'http'), 'ping_validate_target valid http URL');
assert_true(ping_validate_target('https://example.com', 'domain', 'http'), 'ping_validate_target valid https URL');
assert_false(ping_validate_target('', 'domain', 'icmp'), 'ping_validate_target empty domain returns false');
assert_true(ping_validate_target('http://invalid', 'domain', 'http'), 'ping_validate_target http://invalid is valid URL format');
assert_false(ping_validate_target('999.999.999.999', 'ip', 'icmp'), 'ping_validate_target invalid IP');
assert_false(ping_validate_target('a', 'domain', 'icmp'), 'ping_validate_target too short domain');

echo "\n--- ping_display_status() tests ---\n";
assert_equals('paused', ping_display_status('up', 0), 'ping_display_status inactive');
assert_equals('paused', ping_display_status('down', 0), 'ping_display_status inactive down');
assert_equals('up', ping_display_status('up', 1), 'ping_display_status up');
assert_equals('down', ping_display_status('down', 1), 'ping_display_status down');
assert_equals('pending', ping_display_status('unknown', 1), 'ping_display_status unknown');
assert_equals('pending', ping_display_status('', 1), 'ping_display_status empty');
assert_equals('pending', ping_display_status(null, 1), 'ping_display_status null');

echo "\n--- ping_probe_command() tests ---\n";
$cmd = ping_probe_command('example.com', 2);
assert_true(str_contains($cmd, 'ping'), 'ping_probe_command contains ping');
assert_true(str_contains($cmd, '-c 1') || str_contains($cmd, '-n 1'), 'ping_probe_command has count');
assert_true(str_contains($cmd, '-W 2') || str_contains($cmd, '-w 2000'), 'ping_probe_command has timeout');

echo "\n=== Test Summary ===\n";
echo "Total: {$testCount} tests\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";

foreach ($testResults as $result) {
    echo $result . "\n";
}

if ($failCount > 0) {
    echo "\n✗ TESTS FAILED\n";
    exit(1);
} else {
    echo "\n✓ ALL TESTS PASSED\n";
    exit(0);
}
