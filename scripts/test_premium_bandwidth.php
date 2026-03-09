<?php

declare(strict_types=1);

error_reporting(E_ALL);

function premium_bandwidth_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function premium_bandwidth_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title, int $sizeBytes): int
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
        ':original_filename' => strtolower(str_replace(' ', '-', $title)) . '.mp4',
        ':source_extension' => 'mp4',
        ':status' => VE_VIDEO_STATUS_READY,
        ':status_message' => 'Ready for playback.',
        ':original_size_bytes' => $sizeBytes,
        ':processed_size_bytes' => $sizeBytes,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
        ':processing_started_at' => $now,
        ':ready_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return array<string, mixed>
 */
function premium_bandwidth_latest_session(PDO $pdo): array
{
    $session = $pdo->query('SELECT * FROM video_playback_sessions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    premium_bandwidth_assert(is_array($session), 'Expected a playback session row to be created.');
    return $session;
}

function premium_bandwidth_insert_completed_order(PDO $pdo, int $userId, int $bandwidthBytes): void
{
    $now = ve_now();
    ve_insert_premium_order($pdo, [
        'order_code' => 'pbw_' . strtolower(ve_random_token(8)),
        'user_id' => $userId,
        'purchase_type' => 'bandwidth',
        'package_id' => 'test-bandwidth',
        'package_title' => 'Test bandwidth',
        'payment_method' => 'balance',
        'status' => 'completed',
        'amount_micro_usd' => 0,
        'bandwidth_bytes' => $bandwidthBytes,
        'metadata' => [
            'source' => 'test-suite',
        ],
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => $now,
    ]);
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'premium-bandwidth.sqlite';

@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'premium-bandwidth-test-key',
];

foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'premium-bandwidth-test-agent';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $root . '/app/frontend.php';

$pdo = ve_db();
$user = ve_create_user('premium_bandwidth_case', 'premium-bandwidth@example.com', 'Premium123');
$userId = (int) ($user['id'] ?? 0);
premium_bandwidth_assert($userId > 0, 'Premium bandwidth test user should be created.');

$videoId = premium_bandwidth_insert_ready_video($pdo, $userId, 'premiumbw01', 'Premium Bandwidth Clip', 48 * 1024 * 1024);
$video = ve_video_get_by_id($videoId);
premium_bandwidth_assert(is_array($video), 'Expected premium bandwidth test video to exist.');

$generalBytes = 12 * 1024 * 1024;
ve_dashboard_record_video_bandwidth($videoId, $userId, $generalBytes);

$summaryBefore = ve_premium_page_payload($userId);
premium_bandwidth_assert((int) ($summaryBefore['used_bw'] ?? -1) === 0, 'Regular playback bandwidth must not consume premium bandwidth.');

$premiumBytes = 24 * 1024 * 1024;
premium_bandwidth_insert_completed_order($pdo, $userId, $premiumBytes);

$pdo->prepare(
    'UPDATE user_settings
     SET vast_url = :vast_url,
         pop_type = :pop_type,
         pop_url = :pop_url,
         pop_cap = :pop_cap,
         updated_at = :updated_at
     WHERE user_id = :user_id'
)->execute([
    ':vast_url' => 'https://ads.example.com/vast.xml',
    ':pop_type' => '1',
    ':pop_url' => '',
    ':pop_cap' => 0,
    ':updated_at' => ve_now(),
    ':user_id' => $userId,
]);

ve_video_issue_playback_session($video);
$firstPremiumSession = premium_bandwidth_latest_session($pdo);
premium_bandwidth_assert((int) ($firstPremiumSession['uses_premium_bandwidth'] ?? 0) === 1, 'Playback sessions should be flagged for premium bandwidth when own adverts are enabled and credit is available.');

ve_video_record_segment_delivery($video, $firstPremiumSession, $premiumBytes);

$summaryAfterPremiumPlayback = ve_premium_page_payload($userId);
premium_bandwidth_assert((int) ($summaryAfterPremiumPlayback['used_bw'] ?? -1) === $premiumBytes, 'Eligible playback bytes should be recorded as premium bandwidth usage.');
premium_bandwidth_assert((int) ($summaryAfterPremiumPlayback['available_bw'] ?? -1) === 0, 'Premium bandwidth availability should reach zero after the configured usage is consumed.');
premium_bandwidth_assert(max(array_map('floatval', (array) ($summaryAfterPremiumPlayback['stats'] ?? []))) === 24.0, 'Premium bandwidth charts should graph premium-only usage in megabytes.');

$firstPremiumSession = premium_bandwidth_latest_session($pdo);
premium_bandwidth_assert((int) ($firstPremiumSession['premium_bandwidth_bytes_served'] ?? -1) === $premiumBytes, 'Playback sessions should persist premium bandwidth bytes separately from total bytes served.');

ve_video_issue_playback_session($video);
$exhaustedSession = premium_bandwidth_latest_session($pdo);
premium_bandwidth_assert((int) ($exhaustedSession['uses_premium_bandwidth'] ?? 1) === 0, 'New sessions must stop using premium bandwidth once no premium credit remains.');

ve_video_record_segment_delivery($video, $exhaustedSession, 5 * 1024 * 1024);
$summaryAfterExhaustedPlayback = ve_premium_page_payload($userId);
premium_bandwidth_assert((int) ($summaryAfterExhaustedPlayback['used_bw'] ?? -1) === $premiumBytes, 'Traffic served after premium bandwidth is exhausted must not increment premium usage.');

echo "premium bandwidth ok\n";
