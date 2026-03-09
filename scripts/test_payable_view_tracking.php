<?php

declare(strict_types=1);

error_reporting(E_ALL);

function payable_view_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payable_view_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title): int
{
    $now = ve_now();
    $stmt = $pdo->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public,
            status, status_message, duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio, processing_error,
            created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, 0, :public_id, :title, :original_filename, :source_extension, 1,
            :status, :status_message, 120.0, 1280, 720, "h264", "aac",
            :original_size_bytes, :processed_size_bytes, 0.75, "",
            :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':public_id' => $publicId,
        ':title' => $title,
        ':original_filename' => 'qa-payable-view.mp4',
        ':source_extension' => 'mp4',
        ':status' => VE_VIDEO_STATUS_READY,
        ':status_message' => 'Ready for payable-view QA.',
        ':original_size_bytes' => 1024 * 1004,
        ':processed_size_bytes' => 1024 * 1004,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
        ':processing_started_at' => $now,
        ':ready_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function payable_view_insert_session(PDO $pdo, int $videoId, ?int $viewerUserId, string $ipAddress, string $userAgent): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO video_playback_sessions (
            video_id, session_token_hash, owner_user_id, ip_hash, user_agent_hash,
            expires_at, created_at, last_seen_at, playback_started_at, bandwidth_bytes_served,
            uses_premium_bandwidth, premium_bandwidth_bytes_served, revoked_at
        ) VALUES (
            :video_id, :session_token_hash, :owner_user_id, :ip_hash, :user_agent_hash,
            :expires_at, :created_at, :last_seen_at, :playback_started_at, :bandwidth_bytes_served,
            0, 0, NULL
        )'
    );
    $stmt->execute([
        ':video_id' => $videoId,
        ':session_token_hash' => ve_video_playback_signature('qa-session-' . bin2hex(random_bytes(8))),
        ':owner_user_id' => $viewerUserId,
        ':ip_hash' => ve_video_playback_signature($ipAddress),
        ':user_agent_hash' => ve_video_playback_signature($userAgent),
        ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ':created_at' => ve_now(),
        ':last_seen_at' => ve_now(),
        ':playback_started_at' => gmdate('Y-m-d H:i:s', time() - 6),
        ':bandwidth_bytes_served' => 512 * 1024,
    ]);

    return (int) $pdo->lastInsertId();
}

function payable_view_load_session(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM video_playback_sessions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    payable_view_assert(is_array($row), 'Expected playback session to exist.');
    return $row;
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'qa-payable-view.sqlite';
@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'qa-payable-view-app-key',
    'VE_VIDEO_PAYABLE_MIN_WATCH_SECONDS' => '5',
    'VE_VIDEO_PAYABLE_MAX_VIEWS_PER_VIEWER_PER_DAY' => '1',
];

foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$pdo = ve_db();
$owner = ve_create_user('payable_owner', 'payable-owner@example.com', 'PayablePass123');
$ownerId = (int) ($owner['id'] ?? 0);
payable_view_assert($ownerId > 0, 'Owner user should be created.');

$videoId = payable_view_insert_ready_video($pdo, $ownerId, 'payableview1', 'Payable View Fixture');
$video = ve_video_get_by_id($videoId);
payable_view_assert(is_array($video), 'Payable-view fixture video should exist.');

payable_view_assert(str_contains(ve_video_format_bytes(1024 * 1004), 'MB'), 'Near-MB byte labels should promote to MB.');

ve_set_app_setting('video_payable_min_watch_seconds', '5');
ve_set_app_setting('video_payable_max_views_per_viewer_per_day', '1');

$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_USER_AGENT'] = 'qa-payable-view-agent';
$firstSessionId = payable_view_insert_session($pdo, $videoId, null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
$firstSession = payable_view_load_session($pdo, $firstSessionId);

$tooEarly = ve_video_record_qualified_view($video, $firstSession, 4);
payable_view_assert(($tooEarly['status'] ?? '') === 'pending', 'Views below the minimum watch time should stay pending.');
payable_view_assert((int) $pdo->query('SELECT COUNT(*) FROM video_view_qualifications')->fetchColumn() === 0, 'Pending views should not be persisted.');

$firstResult = ve_video_record_qualified_view($video, $firstSession, 5);
payable_view_assert(($firstResult['status'] ?? '') === 'ok', 'A qualified playback should be processed successfully.');
payable_view_assert(($firstResult['payable'] ?? false) === true, 'The first qualified playback should be payable.');
payable_view_assert(($firstResult['counted'] ?? false) === true, 'The first qualified playback should increment dashboard totals.');

$today = gmdate('Y-m-d');
$totalsAfterFirst = ve_dashboard_totals_for_date($ownerId, $today);
$earnPerView = ve_dashboard_earnings_per_view_micro_usd();
payable_view_assert((int) ($totalsAfterFirst['views'] ?? 0) === 1, 'The first payable playback should increment daily views.');
payable_view_assert((int) ($totalsAfterFirst['earned_micro_usd'] ?? 0) === $earnPerView, 'The first payable playback should increment daily earnings.');

$secondSessionId = payable_view_insert_session($pdo, $videoId, null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
$secondSession = payable_view_load_session($pdo, $secondSessionId);
$secondResult = ve_video_record_qualified_view($video, $secondSession, 5);
payable_view_assert(($secondResult['status'] ?? '') === 'ok', 'A repeated viewer should still get a processed response.');
payable_view_assert(($secondResult['payable'] ?? true) === false, 'The second qualified playback from the same anonymous viewer should not be payable.');

$totalsAfterSecond = ve_dashboard_totals_for_date($ownerId, $today);
payable_view_assert((int) ($totalsAfterSecond['views'] ?? 0) === 1, 'The daily payable cap should prevent an extra counted view.');

ve_set_app_setting('video_payable_max_views_per_viewer_per_day', '2');
$thirdSessionId = payable_view_insert_session($pdo, $videoId, null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
$thirdSession = payable_view_load_session($pdo, $thirdSessionId);
$thirdResult = ve_video_record_qualified_view($video, $thirdSession, 5);
payable_view_assert(($thirdResult['payable'] ?? false) === true, 'Raising the daily payable cap should allow another payable view.');
payable_view_assert((int) ($thirdResult['payable_rank'] ?? 0) === 2, 'The second payable view should report rank 2 for the day.');

$totalsAfterThird = ve_dashboard_totals_for_date($ownerId, $today);
payable_view_assert((int) ($totalsAfterThird['views'] ?? 0) === 2, 'The second payable view should increment daily views after the cap is raised.');

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
$_SERVER['HTTP_USER_AGENT'] = 'qa-owner-self-view';
$ownerSessionId = payable_view_insert_session($pdo, $videoId, $ownerId, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
$ownerSession = payable_view_load_session($pdo, $ownerSessionId);
$ownerResult = ve_video_record_qualified_view($video, $ownerSession, 5);
payable_view_assert(($ownerResult['payable'] ?? true) === false, 'Owner playback sessions should not be payable.');

$totalsAfterOwner = ve_dashboard_totals_for_date($ownerId, $today);
payable_view_assert((int) ($totalsAfterOwner['views'] ?? 0) === 2, 'Owner playback should not increment payable view totals.');

echo "payable view tracking qa ok\n";
