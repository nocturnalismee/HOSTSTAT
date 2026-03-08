<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

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

function assert_false(bool $cond, string $message): void
{
    assert_true(!$cond, $message);
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

function assert_not_equals(mixed $expected, mixed $actual, string $message): void
{
    global $testResults, $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected !== $actual) {
        $passCount++;
        $testResults[] = "✓ {$message}";
    } else {
        $failCount++;
        $testResults[] = "✗ {$message} (should not be: " . var_export($expected, true) . ")";
    }
}

echo "=== ServMon Unit Tests ===\n\n";

echo "--- formatBytes() tests ---\n";
assert_equals('512 B', formatBytes(512), 'formatBytes bytes');
assert_equals('1.00 KB', formatBytes(1024), 'formatBytes KB');
assert_equals('1.00 MB', formatBytes(1048576), 'formatBytes MB');
assert_equals('1.00 GB', formatBytes(1073741824), 'formatBytes GB');
assert_equals('1.00 TB', formatBytes(1099511627776), 'formatBytes TB');
assert_equals('0 B', formatBytes(0), 'formatBytes zero');
assert_equals('0 B', formatBytes(null), 'formatBytes null');
assert_equals('0 B', formatBytes(-1), 'formatBytes negative');
assert_equals('500.00 MB', formatBytes(524288000), 'formatBytes 500MB');

echo "\n--- formatUptime() tests ---\n";
assert_equals('< 1 menit', formatUptime(0), 'formatUptime zero');
assert_equals('< 1 menit', formatUptime(45), 'formatUptime sub-minute');
assert_equals('< 1 menit', formatUptime(59), 'formatUptime under minute');
assert_equals('1 menit', formatUptime(60), 'formatUptime exactly 1 minute');
assert_equals('2 menit', formatUptime(120), 'formatUptime 2 minutes');
assert_equals('1 jam', formatUptime(3600), 'formatUptime exactly 1 hour');
assert_equals('1 jam, 1 menit', formatUptime(3660), 'formatUptime 1h 1m');
assert_equals('1 hari, 1 jam, 1 menit', formatUptime(90061), 'formatUptime 1d 1h 1m');
assert_equals('2 hari, 2 jam, 15 menit', formatUptime(180900), 'formatUptime 2d 3h 15m');
assert_equals('N/A', formatUptime(null), 'formatUptime null');

echo "\n--- calculateUsagePercent() tests ---\n";
assert_equals(0.0, calculateUsagePercent(0, 100), 'calculateUsagePercent zero used');
assert_equals(50.0, calculateUsagePercent(5, 10), 'calculateUsagePercent 50%');
assert_equals(100.0, calculateUsagePercent(100, 100), 'calculateUsagePercent 100%');
assert_equals(0.0, calculateUsagePercent(10, 0), 'calculateUsagePercent zero total');
assert_equals(0.0, calculateUsagePercent(10, -1), 'calculateUsagePercent negative total');
assert_equals(0.0, calculateUsagePercent(-5, 100), 'calculateUsagePercent negative used');
assert_equals(85.5, calculateUsagePercent(855, 1000), 'calculateUsagePercent decimal');

echo "\n--- formatNetworkBps() tests ---\n";
assert_equals('0 bps', formatNetworkBps(0), 'formatNetworkBps zero');
assert_equals('0 bps', formatNetworkBps(null), 'formatNetworkBps null');
assert_equals('0 bps', formatNetworkBps(-1), 'formatNetworkBps negative');
assert_equals('500 bps', formatNetworkBps(500), 'formatNetworkBps 500');
assert_equals('1.00 Kb', formatNetworkBps(1000), 'formatNetworkBps 1K');
assert_equals('1.50 Mb', formatNetworkBps(1500000), 'formatNetworkBps 1.5M');
assert_equals('1.00 Gb', formatNetworkBps(1000000000), 'formatNetworkBps 1G');

echo "\n--- parseHistoryRange() tests ---\n";
assert_equals('5 MINUTE', parseHistoryRange('5m'), 'parseHistoryRange 5m');
assert_equals('30 MINUTE', parseHistoryRange('30m'), 'parseHistoryRange 30m');
assert_equals('24 HOUR', parseHistoryRange('24h'), 'parseHistoryRange 24h');
assert_equals('7 DAY', parseHistoryRange('7d'), 'parseHistoryRange 7d');
assert_equals('30 DAY', parseHistoryRange('30d'), 'parseHistoryRange 30d');
assert_equals('24 HOUR', parseHistoryRange(''), 'parseHistoryRange empty');
assert_equals('24 HOUR', parseHistoryRange('invalid'), 'parseHistoryRange invalid');

echo "\n--- statusBadgeClass() tests ---\n";
assert_equals('text-bg-success', statusBadgeClass('online'), 'statusBadgeClass online');
assert_equals('text-bg-danger', statusBadgeClass('down'), 'statusBadgeClass down');
assert_equals('text-bg-warning', statusBadgeClass('pending'), 'statusBadgeClass pending');
assert_equals('text-bg-warning', statusBadgeClass('unknown'), 'statusBadgeClass unknown');

echo "\n--- serverStatusFromLastSeen() tests ---\n";
$_SERVER['REQUEST_TIME'] = time();
$now = date('Y-m-d H:i:s');
$twoMinutesAgo = date('Y-m-d H:i:s', strtotime('-2 minutes'));
$threeMinutesAgo = date('Y-m-d H:i:s', strtotime('-3 minutes'));

assert_equals('pending', serverStatusFromLastSeen(null), 'serverStatusFromLastSeen null');
assert_equals('pending', serverStatusFromLastSeen(''), 'serverStatusFromLastSeen empty');
assert_equals('pending', serverStatusFromLastSeen('   '), 'serverStatusFromLastSeen whitespace');
assert_equals('pending', serverStatusFromLastSeen($now, false), 'serverStatusFromLastSeen inactive');
assert_equals('online', serverStatusFromLastSeen($now, true, 2), 'serverStatusFromLastSeen online within threshold');
assert_equals('down', serverStatusFromLastSeen($threeMinutesAgo, true, 2), 'serverStatusFromLastSeen down after threshold');

echo "\n--- e() (escape) tests ---\n";
assert_equals('', e(null), 'e null');
assert_equals('', e(''), 'e empty');
assert_equals('hello', e('hello'), 'e plain text');
assert_equals('&lt;script&gt;', e('<script>'), 'e script tag');
assert_equals('&quot;test&quot;', e('"test"'), 'e quotes');
assert_equals('&#039;test&#039;', e("'test'"), 'e single quote');
assert_equals('a &amp; b', e('a & b'), 'e ampersand');

echo "\n--- is_post() tests ---\n";
$originalMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_METHOD'] = 'GET';
assert_false(is_post(), 'is_post GET');
$_SERVER['REQUEST_METHOD'] = 'POST';
assert_true(is_post(), 'is_post POST');
$_SERVER['REQUEST_METHOD'] = 'post';
assert_true(is_post(), 'is_post lowercase post');
$_SERVER['REQUEST_METHOD'] = $originalMethod;

echo "\n--- old() tests ---\n";
$originalPost = $_POST ?? [];
$_POST = [];
assert_equals('', old('test'), 'old not set');
assert_equals('default', old('test', 'default'), 'old not set with default');
$_POST['test'] = 'value';
assert_equals('value', old('test'), 'old set');
$_POST = $originalPost;

echo "\n--- redirect() tests ---\n";
$originalUrl = defined('APP_URL') ? APP_URL : '';
if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost');
}

echo "\n--- app_url() tests ---\n";
assert_equals('/test', app_url('test'), 'app_url with path');
assert_equals('/test/', app_url('/test/'), 'app_url with trailing slash');
assert_equals('/', app_url(''), 'app_url empty');

echo "\n--- asset_url() tests ---\n";
$result = asset_url('css/app.css');
assert_true(str_contains($result, 'css/app.css'), 'asset_url contains path');
assert_true(str_contains($result, '?v='), 'asset_url contains version');

echo "\n--- formatBytes edge cases ---\n";
assert_equals('1,023 B', formatBytes(1023), 'formatBytes 1023 bytes');
assert_equals('1.00 KB', formatBytes(1025), 'formatBytes 1025 bytes');
assert_equals('1,000.00 MB', formatBytes(1048575999), 'formatBytes large decimal');

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
