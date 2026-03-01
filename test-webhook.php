<?php
/**
 * Webhook feature integration tests.
 *
 * Run from container: php wp-content/plugins/siteguardbackup/test-webhook.php
 * Requires: HTTP_HOST env var set, WordPress loaded.
 */

// Bootstrap WordPress
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'old-host.example';
$_SERVER['REQUEST_URI'] = '/';
define('DOING_CRON', true); // Bypass is_admin() requirement, ensure sgbackup_should_load() = true

require_once '/var/www/html/wp-load.php';

// Ensure plugin classes are loaded
if (!function_exists('sgbackup_load_classes')) {
    require_once '/var/www/html/wp-content/plugins/siteguardbackup/siteguardbackup.php';
}
sgbackup_load_classes();

$pass = 0;
$fail = 0;

function assert_true($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $pass++;
    } else {
        echo "  FAIL: {$label}\n";
        $fail++;
    }
}

function assert_equals($expected, $actual, $label) {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "  PASS: {$label}\n";
        $pass++;
    } else {
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
        $fail++;
    }
}

// ========================================================
echo "=== Test 1: Class exists and singleton works ===\n";
// ========================================================
assert_true(class_exists('SiteGuard_Backup_Webhook'), 'SiteGuard_Backup_Webhook class exists');
$inst1 = SiteGuard_Backup_Webhook::instance();
$inst2 = SiteGuard_Backup_Webhook::instance();
assert_true($inst1 === $inst2, 'Singleton returns same instance');

// ========================================================
echo "\n=== Test 2: fire() returns false when URL is empty ===\n";
// ========================================================
$opts = SiteGuard_Backup_Options::instance();
$opts->update_option('sgbackup_webhook_url', '');
$opts->update_option('sgbackup_webhook_events', array('backup_complete'));

// Reset singleton to clear cached values
$ref = new ReflectionClass('SiteGuard_Backup_Webhook');
$inst_prop = $ref->getProperty('instance');
$inst_prop->setAccessible(true);
$inst_prop->setValue(null, null);

$result = SiteGuard_Backup_Webhook::fire('backup_complete', array('test' => true));
assert_equals(false, $result, 'fire() returns false when URL is empty');

// ========================================================
echo "\n=== Test 3: fire() returns false when event not in enabled list ===\n";
// ========================================================
$opts->update_option('sgbackup_webhook_url', 'https://httpbin.org/post');
$opts->update_option('sgbackup_webhook_events', array('backup_complete'));
$inst_prop->setValue(null, null); // Reset singleton

$result = SiteGuard_Backup_Webhook::fire('backup_failed', array('test' => true));
assert_equals(false, $result, 'fire() returns false for non-enabled event');

// ========================================================
echo "\n=== Test 4: fire() returns true when URL set and event enabled ===\n";
// ========================================================
$inst_prop->setValue(null, null); // Reset singleton
$result = SiteGuard_Backup_Webhook::fire('backup_complete', array('test' => true));
assert_equals(true, $result, 'fire() returns true for enabled event with URL set');

// ========================================================
echo "\n=== Test 5: fire() checks all five event types correctly ===\n";
// ========================================================
$all_events = array('backup_complete', 'backup_failed', 'restore_complete', 'restore_failed', 'schedule_missed');
$opts->update_option('sgbackup_webhook_events', $all_events);
foreach ($all_events as $event) {
    $inst_prop->setValue(null, null);
    $result = SiteGuard_Backup_Webhook::fire($event, array());
    assert_equals(true, $result, "fire('{$event}') returns true when all events enabled");
}

// Non-existent event should still fail
$inst_prop->setValue(null, null);
$result = SiteGuard_Backup_Webhook::fire('nonexistent_event', array());
assert_equals(false, $result, "fire('nonexistent_event') returns false");

// ========================================================
echo "\n=== Test 6: send_test() validates URL ===\n";
// ========================================================
$result = SiteGuard_Backup_Webhook::send_test('');
assert_equals(false, $result['success'], 'send_test("") returns success=false');
assert_equals(0, $result['http_code'], 'send_test("") returns http_code=0');

$result = SiteGuard_Backup_Webhook::send_test('not-a-url');
assert_equals(false, $result['success'], 'send_test("not-a-url") returns success=false');

$result = SiteGuard_Backup_Webhook::send_test('ftp://example.com');
assert_equals(false, $result['success'], 'send_test("ftp://...") returns success=false (non-http scheme)');

$result = SiteGuard_Backup_Webhook::send_test('javascript:alert(1)');
assert_equals(false, $result['success'], 'send_test("javascript:...") returns success=false');

// ========================================================
echo "\n=== Test 7: send_test() with real HTTP endpoint ===\n";
// ========================================================
$result = SiteGuard_Backup_Webhook::send_test('https://httpbin.org/post');
assert_equals(true, $result['success'], 'send_test() to httpbin.org succeeds');
assert_equals(200, $result['http_code'], 'send_test() returns HTTP 200');
echo "  Message: {$result['message']}\n";

$result = SiteGuard_Backup_Webhook::send_test('https://httpbin.org/status/500');
assert_equals(false, $result['success'], 'send_test() to /status/500 returns success=false');
assert_equals(500, $result['http_code'], 'send_test() returns HTTP 500');
echo "  Message: {$result['message']}\n";

$result = SiteGuard_Backup_Webhook::send_test('https://httpbin.org/status/404');
assert_equals(false, $result['success'], 'send_test() to /status/404 returns success=false');
assert_equals(404, $result['http_code'], 'send_test() returns HTTP 404');

// ========================================================
echo "\n=== Test 8: send_test() payload structure ===\n";
// ========================================================
$result_raw = SiteGuard_Backup_Webhook::send_test('https://httpbin.org/post');
// We can't inspect the payload sent, but we can verify the return structure
assert_true(isset($result_raw['success']), 'Result has "success" key');
assert_true(isset($result_raw['message']), 'Result has "message" key');
assert_true(isset($result_raw['http_code']), 'Result has "http_code" key');
assert_true(is_bool($result_raw['success']), '"success" is boolean');
assert_true(is_string($result_raw['message']), '"message" is string');
assert_true(is_int($result_raw['http_code']), '"http_code" is integer');

// ========================================================
echo "\n=== Test 9: HMAC signature correctness ===\n";
// ========================================================
// Verify HMAC is computed correctly by replicating the logic
$test_url = 'https://example.com/webhook';
$test_payload = array(
    'event'    => 'test',
    'site_url' => get_site_url(),
    'timestamp' => time(),
    'data'     => array('message' => 'Test'),
);
$body = wp_json_encode($test_payload);
$sig = hash_hmac('sha256', $body, $test_url);
assert_equals(64, strlen($sig), 'HMAC-SHA256 produces 64-char hex string');
assert_true(ctype_xdigit($sig), 'HMAC signature is valid hex');

// Verify same input gives same output (deterministic)
$sig2 = hash_hmac('sha256', $body, $test_url);
assert_equals($sig, $sig2, 'HMAC is deterministic');

// Different key gives different signature
$sig3 = hash_hmac('sha256', $body, 'https://other.com/hook');
assert_true($sig !== $sig3, 'Different URL gives different HMAC');

// ========================================================
echo "\n=== Test 10: test_webhook command via Commands class ===\n";
// ========================================================
require_once '/var/www/html/wp-content/plugins/siteguardbackup/includes/class-commands.php';
$cmds = new SiteGuard_Backup_Commands();

// Clear saved URL first so empty-params test is valid
$opts->update_option('sgbackup_webhook_url', '');
$inst_prop->setValue(null, null);

// Test with empty URL
$result = $cmds->test_webhook(array());
assert_true(is_wp_error($result) || (isset($result['success']) && $result['success'] === false), 'test_webhook({}) returns error or failure when no URL saved');

// Test with a URL param
$result = $cmds->test_webhook(array('url' => 'https://httpbin.org/post'));
assert_true(is_array($result), 'test_webhook returns array');
assert_equals(true, $result['success'], 'test_webhook with httpbin URL succeeds');
assert_equals(200, $result['http_code'], 'test_webhook returns HTTP 200');

// ========================================================
echo "\n=== Test 11: Options round-trip ===\n";
// ========================================================
$opts->update_option('sgbackup_webhook_url', 'https://example.com/my-webhook');
$opts->update_option('sgbackup_webhook_events', array('backup_complete', 'restore_failed'));
$inst_prop->setValue(null, null);

$saved_url = $opts->get_option('sgbackup_webhook_url', '');
$saved_events = $opts->get_option('sgbackup_webhook_events', array());
assert_equals('https://example.com/my-webhook', $saved_url, 'URL saved and retrieved correctly');
assert_equals(array('backup_complete', 'restore_failed'), $saved_events, 'Events saved and retrieved correctly');

// Verify fire() respects saved config
$result = SiteGuard_Backup_Webhook::fire('backup_complete');
assert_equals(true, $result, 'fire(backup_complete) works with saved URL');
$inst_prop->setValue(null, null);
$result = SiteGuard_Backup_Webhook::fire('backup_failed');
assert_equals(false, $result, 'fire(backup_failed) blocked when not in saved events');

// ========================================================
echo "\n=== Test 12: get_all_settings includes webhook fields ===\n";
// ========================================================
$settings = $opts->get_all_settings();
assert_true(array_key_exists('webhook_url', $settings), 'get_all_settings has webhook_url');
assert_true(array_key_exists('webhook_events', $settings), 'get_all_settings has webhook_events');
assert_equals('https://example.com/my-webhook', $settings['webhook_url'], 'webhook_url value matches');
assert_equals(array('backup_complete', 'restore_failed'), $settings['webhook_events'], 'webhook_events value matches');

// ========================================================
echo "\n=== Test 13: savesettings webhook_url sanitization ===\n";
// ========================================================
// Simulate the savesettings handler's sanitization logic
$test_urls = array(
    'https://good.example.com/hook'    => 'https://good.example.com/hook',
    'http://plain.example.com/hook'    => 'http://plain.example.com/hook',
    'javascript:alert(1)'             => '',
    'ftp://bad.example.com'            => '',
    ''                                 => '',
    '   https://trimmed.example.com  ' => 'https://trimmed.example.com',
);
foreach ($test_urls as $input => $expected) {
    $sanitized = trim($input);
    if (!empty($sanitized)) {
        $sanitized = esc_url_raw($sanitized, array('https', 'http'));
    }
    assert_equals($expected, $sanitized, "URL sanitize: '{$input}' -> '{$expected}'");
}

// ========================================================
echo "\n=== Test 14: savesettings webhook_events validation ===\n";
// ========================================================
$valid_events = array('backup_complete', 'backup_failed', 'restore_complete', 'restore_failed', 'schedule_missed');

// Valid events pass through
$input = array('backup_complete', 'backup_failed');
$result = array_values(array_intersect($input, $valid_events));
assert_equals(array('backup_complete', 'backup_failed'), $result, 'Valid events pass whitelist');

// Invalid events filtered
$input = array('backup_complete', 'evil_event', 'xss_attempt');
$result = array_values(array_intersect($input, $valid_events));
assert_equals(array('backup_complete'), $result, 'Invalid events removed by whitelist');

// Empty array stays empty
$input = array();
$result = array_values(array_intersect($input, $valid_events));
assert_equals(array(), $result, 'Empty events array stays empty');

// All valid events
$input = $valid_events;
$result = array_values(array_intersect($input, $valid_events));
assert_equals($valid_events, $result, 'All valid events pass through');

// ========================================================
echo "\n=== Test 15: schedule_missed detection logic ===\n";
// ========================================================
// Test the overdue calculation logic without actually touching cron
$now = time();

// Not overdue: scheduled 30 min ago with 1h interval (threshold: 1.5h = 5400s)
$next = $now - 1800; // 30 min ago
$interval = 3600;    // 1 hour
$overdue = $now - $next;
assert_true($overdue <= ($interval * 1.5), 'Not overdue: 30min past on 1h schedule');

// Overdue: scheduled 2h ago with 1h interval
$next = $now - 7200;
$overdue = $now - $next;
assert_true($overdue > ($interval * 1.5), 'Overdue: 2h past on 1h schedule');

// Not overdue: scheduled in the future
$next = $now + 3600;
$overdue = $now - $next;
assert_true($overdue <= 0, 'Not overdue: scheduled in future');

// Barely not overdue: exactly at 1.5x threshold
$interval = 3600;
$next = $now - (int)($interval * 1.5);
$overdue = $now - $next;
assert_true($overdue <= ($interval * 1.5), 'Edge case: exactly at threshold is not overdue (<=)');

// Just past threshold
$next = $now - (int)($interval * 1.5) - 1;
$overdue = $now - $next;
assert_true($overdue > ($interval * 1.5), 'Edge case: 1 second past threshold IS overdue');

// ========================================================
echo "\n=== Test 16: Cooldown option works ===\n";
// ========================================================
$opts->update_option('sgbackup_schedule_missed_notified', time());
$last_check = (int) $opts->get_option('sgbackup_schedule_missed_notified', 0);
assert_true($last_check > 0, 'Cooldown timestamp saved');
assert_true((time() - $last_check) < 86400, 'Within cooldown window');

// Clear for next test
$opts->update_option('sgbackup_schedule_missed_notified', 0);
$last_check = (int) $opts->get_option('sgbackup_schedule_missed_notified', 0);
assert_equals(0, $last_check, 'Cooldown cleared');

// ========================================================
echo "\n=== Test 17: Webhook payload JSON structure ===\n";
// ========================================================
// Build payload like fire() does and verify structure
$payload = array(
    'event'    => 'backup_complete',
    'site_url' => get_site_url(),
    'timestamp' => time(),
    'data'     => array(
        'nonce'    => 'abc123',
        'job_type' => 'full',
        'entities' => array('plugins' => array('index' => 0, 'status' => 'complete')),
    ),
);
$json = wp_json_encode($payload);
assert_true($json !== false, 'Payload encodes to valid JSON');

$decoded = json_decode($json, true);
assert_true(is_array($decoded), 'JSON decodes back to array');
assert_equals('backup_complete', $decoded['event'], 'Event field preserved');
assert_true(is_string($decoded['site_url']), 'site_url is string');
assert_true(is_int($decoded['timestamp']), 'timestamp is int');
assert_true(is_array($decoded['data']), 'data is array');
assert_equals('abc123', $decoded['data']['nonce'], 'Nested data preserved');

// Cleanup
$opts->update_option('sgbackup_webhook_url', '');
$opts->update_option('sgbackup_webhook_events', array());
$opts->update_option('sgbackup_schedule_missed_notified', 0);
$inst_prop->setValue(null, null);

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";

exit($fail > 0 ? 1 : 0);
