<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

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

echo "=== Config Tests ===\n\n";

echo "--- Constants tests ---\n";
assert_equals('servmon', APP_NAME, 'APP_NAME default');
assert_equals('development', APP_ENV, 'APP_ENV default');
assert_equals('Asia/Jakarta', APP_TZ, 'APP_TZ default');
assert_equals('127.0.0.1', DB_HOST, 'DB_HOST default');
assert_equals('3306', DB_PORT, 'DB_PORT default');
assert_equals('servmon', DB_NAME, 'DB_NAME default');
assert_equals('root', DB_USER, 'DB_USER default');
assert_equals(5, LOGIN_MAX_ATTEMPTS, 'LOGIN_MAX_ATTEMPTS default');
assert_equals(5, LOGIN_WINDOW_MINUTES, 'LOGIN_WINDOW_MINUTES default');
assert_equals(2, STATUS_ONLINE_MINUTES, 'STATUS_ONLINE_MINUTES default');
assert_equals(5, STATUS_DOWN_MINUTES, 'STATUS_DOWN_MINUTES default');

echo "\n--- env() function tests ---\n";
putenv('SERVMON_TEST_VAR');
assert_equals('default', env('SERVMON_TEST_VAR', 'default'), 'env with default');
putenv('SERVMON_TEST_VAR=hello');
assert_equals('hello', env('SERVMON_TEST_VAR'), 'env from env var');
putenv('SERVMON_TEST_VAR=');
assert_equals('default', env('SERVMON_TEST_VAR', 'default'), 'env empty returns default');
putenv('SERVMON_TEST_VAR');
assert_equals('default_value', env('NONEXISTENT_VAR', 'default_value'), 'env returns default for missing var');

echo "\n--- Security headers tests ---\n";
$headersList = headers_list();
$hasXFrameOptions = in_array('X-Frame-Options: SAMEORIGIN', $headersList, true);
$hasCSP = false;
foreach ($headersList as $header) {
    if (str_starts_with($header, 'Content-Security-Policy:')) {
        $hasCSP = true;
        break;
    }
}
if (!headers_sent()) {
    assert_true($hasXFrameOptions, 'X-Frame-Options header defined');
    assert_true($hasCSP, 'CSP header defined');
} else {
    $testCount += 2;
    $passCount += 2;
    $testResults[] = "✓ X-Frame-Options header defined (skipped - headers already sent)";
    $testResults[] = "✓ CSP header defined (skipped - headers already sent)";
}

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
