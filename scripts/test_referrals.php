<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

function fail_test(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail_test($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail_test($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$root = dirname(__DIR__);
$dbPath = $root . '/storage/referrals-test-' . bin2hex(random_bytes(4)) . '.sqlite';

@unlink($dbPath);
putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8123';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT'] = 'text/html';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

session_id('referraltest' . bin2hex(random_bytes(6)));

require $root . '/app/frontend.php';

ve_bootstrap();

$pdo = ve_db();
$userColumns = ve_table_columns($pdo, 'users');
$dailyColumns = ve_table_columns($pdo, 'user_stats_daily');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

assert_true(in_array('referral_code', $userColumns, true), 'users.referral_code column missing.');
assert_true(in_array('referred_by_user_id', $userColumns, true), 'users.referred_by_user_id column missing.');
assert_true(in_array('referral_earned_micro_usd', $dailyColumns, true), 'user_stats_daily.referral_earned_micro_usd column missing.');
assert_true(in_array('referral_earnings', $tables, true), 'referral_earnings table missing.');

$referrer = ve_create_user('referrerqa', 'referrerqa@example.com', 'Password123!');
$referralCode = ve_referral_ensure_user_code((int) $referrer['id']);

assert_true(ve_referral_is_valid_code($referralCode), 'Referrer did not receive a valid referral code.');

ve_referral_set_pending_code($referralCode);
$pendingReferrer = ve_referral_pending_referrer();

assert_true(is_array($pendingReferrer), 'Pending referrer was not resolved from the stored referral code.');
assert_same((int) $referrer['id'], (int) ($pendingReferrer['id'] ?? 0), 'Pending referrer did not match the referrer account.');

$referred = ve_create_user('referredqa', 'referredqa@example.com', 'Password123!');
$applied = ve_referral_apply_pending_to_user((int) $referred['id']);

assert_true(is_array($applied), 'Referral was not applied to the new account.');
assert_same((int) $referrer['id'], (int) ($applied['id'] ?? 0), 'Applied referrer id mismatch.');

$referredReloaded = ve_get_user_by_id((int) $referred['id']);

assert_true(is_array($referredReloaded), 'Unable to reload referred user.');
assert_same((int) $referrer['id'], (int) ($referredReloaded['referred_by_user_id'] ?? 0), 'Referred user was not linked to the referrer.');

$now = ve_now();
$videoInsert = $pdo->prepare(
    'INSERT INTO videos (
        user_id, folder_id, public_id, title, original_filename, source_extension, is_public, status,
        status_message, original_size_bytes, processed_size_bytes, created_at, updated_at, queued_at,
        processing_started_at, ready_at, deleted_at
     ) VALUES (
        :user_id, 0, :public_id, :title, :original_filename, :source_extension, 1, :status,
        "", 1024, 1024, :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
     )'
);
$videoInsert->execute([
    ':user_id' => (int) $referred['id'],
    ':public_id' => 'qa' . bin2hex(random_bytes(6)),
    ':title' => 'Referral QA Video',
    ':original_filename' => 'referral-qa.mp4',
    ':source_extension' => 'mp4',
    ':status' => VE_VIDEO_STATUS_READY,
    ':created_at' => $now,
    ':updated_at' => $now,
    ':queued_at' => $now,
    ':processing_started_at' => $now,
    ':ready_at' => $now,
]);
$videoId = (int) $pdo->lastInsertId();

ve_dashboard_record_video_view($videoId, (int) $referred['id'], '2026-03-09', 500000, 'qa-view-1');
$premiumCommission = ve_referral_record_premium_commission((int) $referred['id'], 10000000, 'qa-premium-1', '2026-03-09', ['plan' => 'monthly']);
$duplicatePremium = ve_referral_record_premium_commission((int) $referred['id'], 10000000, 'qa-premium-1', '2026-03-09', ['plan' => 'monthly']);

assert_true(is_array($premiumCommission), 'Premium referral commission was not recorded.');
assert_same(null, $duplicatePremium, 'Duplicate premium commission should have been ignored.');

$snapshot = ve_referral_snapshot((int) $referrer['id']);
$reports = ve_dashboard_reports_snapshot((int) $referrer['id'], '2026-03-09', '2026-03-09');
$balanceMicroUsd = ve_dashboard_balance_micro_usd((int) $referrer['id']);
$pageHtml = ve_referral_page_html(ve_get_user_by_id((int) $referrer['id']) ?: $referrer);

assert_same(1, (int) ($snapshot['counts']['referrals'] ?? 0), 'Referral count mismatch.');
assert_same(3050000, (int) ($snapshot['totals']['total_micro_usd'] ?? 0), 'Total referral earnings mismatch.');
assert_same(50000, (int) ($snapshot['totals']['video_view_micro_usd'] ?? 0), 'Video referral earnings mismatch.');
assert_same(3000000, (int) ($snapshot['totals']['premium_purchase_micro_usd'] ?? 0), 'Premium referral earnings mismatch.');
assert_same(3050000, $balanceMicroUsd, 'Dashboard balance did not include referral earnings.');
assert_same(3050000, (int) ($reports['totals']['total_micro_usd'] ?? 0), 'Reports total earnings mismatch.');
assert_same(3050000, (int) ($reports['totals']['referral_micro_usd'] ?? 0), 'Reports referral earnings mismatch.');
assert_same(0, (int) ($reports['totals']['profit_micro_usd'] ?? 0), 'Reports direct video profit should remain zero for the referrer.');
assert_same(0, (int) ($reports['totals']['views'] ?? 0), 'Reports direct view count should remain zero for the referrer.');

assert_true(str_contains($pageHtml, 'container mt-sm-2 my-premium'), 'Existing referral template shell was not preserved.');
assert_true(!str_contains($pageHtml, 'referral-shell'), 'Custom referral redesign markup is still present.');
assert_true(str_contains($pageHtml, (string) ($snapshot['referral_link'] ?? '')), 'Rendered referral page did not contain the live referral link.');
assert_true(str_contains($pageHtml, 'referredqa'), 'Rendered referral page did not contain the referred username.');
assert_true(str_contains($pageHtml, '$3.05000'), 'Rendered referral page did not show the combined referral earnings.');
assert_true(str_contains($pageHtml, '$0.05000'), 'Rendered referral page did not show the referral view commission.');
assert_true(str_contains($pageHtml, '$3.00000'), 'Rendered referral page did not show the referral premium commission.');
assert_true(str_contains($pageHtml, '/join/' . $referralCode . '.html'), 'Rendered banner code did not contain the referral join URL.');

@unlink($dbPath);

fwrite(STDOUT, "Referral integration test passed.\n");
