<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/notifications/email.php';

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

echo "=== Email Sanitization Tests ===\n\n";

echo "--- sanitize_email_header() tests ---\n";
assert_equals('', sanitize_email_header(''), 'sanitize_email_header empty');
assert_equals('test', sanitize_email_header('test'), 'sanitize_email_header plain');
assert_equals('testvalue', sanitize_email_header("test\tvalue"), 'sanitize_email_header removes tabs');
assert_equals('testvalue', sanitize_email_header("test\rvalue"), 'sanitize_email_header removes CR');
assert_equals('testvalue', sanitize_email_header("test\nvalue"), 'sanitize_email_header removes LF');
assert_equals('testvalue', sanitize_email_header("test\r\nvalue"), 'sanitize_email_header removes CRLF');
assert_equals('test', sanitize_email_header('  test  '), 'sanitize_email_header trims spaces');

echo "\n--- sanitize_email_address() tests ---\n";
assert_equals('', sanitize_email_address(''), 'sanitize_email_address empty');
assert_equals('', sanitize_email_address('invalid'), 'sanitize_email_address invalid');
assert_equals('', sanitize_email_address('test@'), 'sanitize_email_address incomplete');
assert_equals('', sanitize_email_address('@domain.com'), 'sanitize_email_address no user');
assert_equals('test@example.com', sanitize_email_address('test@example.com'), 'sanitize_email_address valid');
assert_equals('test@example.com', sanitize_email_address('  test@example.com  '), 'sanitize_email_address with spaces');
assert_equals('TEST@EXAMPLE.COM', sanitize_email_address('TEST@EXAMPLE.COM'), 'sanitize_email_address uppercase');
assert_equals('test.user@example.com', sanitize_email_address('test.user@example.com'), 'sanitize_email_address with dot');
assert_equals('test+tag@example.com', sanitize_email_address('test+tag@example.com'), 'sanitize_email_address with plus');
assert_equals('test@example.com', sanitize_email_address("test@example.com\r\n"), 'sanitize_email_address trims whitespace');

echo "\n--- sanitize_subject() tests ---\n";
assert_equals('', sanitize_subject(''), 'sanitize_subject empty');
assert_equals('test subject', sanitize_subject('test subject'), 'sanitize_subject plain');
assert_equals('testsubject', sanitize_subject("test\rsubject"), 'sanitize_subject removes CR');
assert_equals('testsubject', sanitize_subject("test\nsubject"), 'sanitize_subject removes LF');
assert_equals('testsubject', sanitize_subject("test\r\nsubject"), 'sanitize_subject removes CRLF');
assert_equals('test', sanitize_subject('  test  '), 'sanitize_subject trims spaces');
assert_equals('test - subject', sanitize_subject('test - subject'), 'sanitize_subject preserves dashes');

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
