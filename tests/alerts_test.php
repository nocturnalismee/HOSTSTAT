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

echo "=== Alert Helper Tests (No DB Required) ===\n\n";

echo "--- calculateUsagePercent() edge cases ---\n";
assert_equals(0.0, calculateUsagePercent(0, 0), 'calculateUsagePercent zero/zero');
assert_equals(0.0, calculateUsagePercent(-10, 100), 'calculateUsagePercent negative used');
assert_equals(0.0, calculateUsagePercent(10, -100), 'calculateUsagePercent negative total');
assert_equals(100.0, calculateUsagePercent(1000, 1000), 'calculateUsagePercent 100%');
assert_equals(0.1, calculateUsagePercent(1, 1000), 'calculateUsagePercent very small');
assert_equals(85.7, calculateUsagePercent(857, 1000), 'calculateUsagePercent rounding');

echo "\n--- formatBytes edge cases ---\n";
assert_equals('0 B', formatBytes(0), 'formatBytes zero');
assert_equals('0 B', formatBytes(null), 'formatBytes null');
assert_equals('0 B', formatBytes(-100), 'formatBytes negative');
assert_equals('1.00 KB', formatBytes(1024), 'formatBytes exactly 1KB');
assert_equals('1.00 MB', formatBytes(1048576), 'formatBytes exactly 1MB');
assert_equals('10.00 GB', formatBytes(10737418240), 'formatBytes 10GB');

echo "\n--- formatUptime edge cases ---\n";
assert_equals('N/A', formatUptime(null), 'formatUptime null');
assert_equals('< 1 menit', formatUptime(1), 'formatUptime 1 second');
assert_equals('1 hari', formatUptime(86400), 'formatUptime exactly 1 day');
assert_equals('1 hari, 1 jam', formatUptime(90000), 'formatUptime 1d 1h');
assert_equals('1 hari, 23 jam, 58 menit', formatUptime(172739), 'formatUptime almost 2 days');

echo "\n--- formatNetworkBps edge cases ---\n";
assert_equals('0 bps', formatNetworkBps(null), 'formatNetworkBps null');
assert_equals('0 bps', formatNetworkBps(0), 'formatNetworkBps zero');
assert_equals('0 bps', formatNetworkBps(-100), 'formatNetworkBps negative');
assert_equals('999 bps', formatNetworkBps(999), 'formatNetworkBps under 1K');
assert_equals('1.00 Kb', formatNetworkBps(1000), 'formatNetworkBps exactly 1K');
assert_equals('1.00 Mb', formatNetworkBps(1000000), 'formatNetworkBps exactly 1M');

echo "\n--- statusBadgeClass edge cases ---\n";
assert_equals('text-bg-success', statusBadgeClass('online'), 'statusBadgeClass online');
assert_equals('text-bg-warning', statusBadgeClass('ONLINE'), 'statusBadgeClass uppercase online (only lowercase)');
assert_equals('text-bg-danger', statusBadgeClass('down'), 'statusBadgeClass down');
assert_equals('text-bg-warning', statusBadgeClass('DOWN'), 'statusBadgeClass uppercase down (only lowercase)');
assert_equals('text-bg-warning', statusBadgeClass('pending'), 'statusBadgeClass pending');
assert_equals('text-bg-warning', statusBadgeClass('unknown'), 'statusBadgeClass unknown');
assert_equals('text-bg-warning', statusBadgeClass('anything'), 'statusBadgeClass anything else');

echo "\n--- serverStatusFromLastSeen edge cases ---\n";
$now = date('Y-m-d H:i:s');
$oneMinAgo = date('Y-m-d H:i:s', strtotime('-1 minute'));
$threeMinAgo = date('Y-m-d H:i:s', strtotime('-3 minutes'));
$invalidDate = 'invalid-date';

assert_equals('pending', serverStatusFromLastSeen(null), 'serverStatusFromLastSeen null');
assert_equals('pending', serverStatusFromLastSeen(''), 'serverStatusFromLastSeen empty');
assert_equals('pending', serverStatusFromLastSeen('not-a-date'), 'serverStatusFromLastSeen invalid date');
assert_equals('online', serverStatusFromLastSeen($now, true), 'serverStatusFromLastSeen now active');
assert_equals('online', serverStatusFromLastSeen($oneMinAgo, true, 2), 'serverStatusFromLastSeen 1 min ago within threshold');
assert_equals('down', serverStatusFromLastSeen($threeMinAgo, true, 2), 'serverStatusFromLastSeen 3 min ago over threshold');
assert_equals('pending', serverStatusFromLastSeen($now, false), 'serverStatusFromLastSeen now inactive');
assert_equals('online', serverStatusFromLastSeen($now, true, 1), 'serverStatusFromLastSeen 1 min threshold');

echo "\n--- parseHistoryRange edge cases ---\n";
assert_equals('24 HOUR', parseHistoryRange(''), 'parseHistoryRange empty');
assert_equals('24 HOUR', parseHistoryRange(null), 'parseHistoryRange null');
assert_equals('24 HOUR', parseHistoryRange('invalid'), 'parseHistoryRange invalid');
assert_equals('24 HOUR', parseHistoryRange('24h'), 'parseHistoryRange 24h');
assert_equals('5 MINUTE', parseHistoryRange('5m'), 'parseHistoryRange 5m');
assert_equals('30 MINUTE', parseHistoryRange('30m'), 'parseHistoryRange 30m');
assert_equals('7 DAY', parseHistoryRange('7d'), 'parseHistoryRange 7d');
assert_equals('30 DAY', parseHistoryRange('30d'), 'parseHistoryRange 30d');

echo "\n--- e() edge cases ---\n";
assert_equals('', e(null), 'e null');
assert_equals('', e(''), 'e empty string');
assert_equals('hello', e('hello'), 'e plain text');
assert_equals('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'), 'e script tag');
assert_equals('&lt;img src=x onerror=alert(1)&gt;', e('<img src=x onerror=alert(1)>'), 'e img onerror');
assert_equals('&quot;quoted&quot;', e('"quoted"'), 'e double quotes');
assert_equals('&#039;quoted&#039;', e("'quoted'"), 'e single quotes');
assert_equals('a &amp; b &lt; c &gt; d', e('a & b < c > d'), 'e special chars');
assert_equals('test &amp; Co', e('test & Co'), 'e ampersand in text');

echo "\n--- flash_set/flash_get_all tests ---\n";
$_SESSION['_flash'] = null;
flash_set('success', 'Test message');
$flashes = flash_get_all();
assert_equals(1, count($flashes), 'flash_get_all returns 1 message');
assert_equals('success', $flashes[0]['type'], 'flash type correct');
assert_equals('Test message', $flashes[0]['message'], 'flash message correct');
$flashes2 = flash_get_all();
assert_equals(0, count($flashes2), 'flash_get_all clears after get');

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
