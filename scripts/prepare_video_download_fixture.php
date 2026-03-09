<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$baseUrl = rtrim((string) (getenv('VIDEO_DOWNLOAD_QA_BASE_URL') ?: ($argv[1] ?? 'http://127.0.0.1:8123')), '/');
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$username = 'qa_dl_' . $suffix;
$email = $username . '@example.invalid';
$password = 'Qa-download-123!';
$user = ve_create_user($username, $email, $password);
$userId = (int) ($user['id'] ?? 0);

if ($userId < 1) {
    fwrite(STDERR, "Unable to create QA download user.\n");
    exit(1);
}

$pdo = ve_db();
$pdo->prepare(
    'UPDATE users
     SET plan_code = :plan_code,
         premium_until = :premium_until,
         updated_at = :updated_at
     WHERE id = :id'
)->execute([
    ':plan_code' => 'premium',
    ':premium_until' => gmdate('Y-m-d H:i:s', time() + 86400),
    ':updated_at' => ve_now(),
    ':id' => $userId,
]);

$publicId = 'qadl' . substr(preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(12)) ?: '', 0, 8);
$directory = ve_video_library_directory($publicId);
ve_ensure_directory($directory);
$sourcePath = $directory . DIRECTORY_SEPARATOR . 'source.mp4';

[$sampleExitCode, $sampleOutput] = ve_video_run_command([
    (string) ve_video_config()['ffmpeg'],
    '-y',
    '-hide_banner',
    '-loglevel',
    'error',
    '-f',
    'lavfi',
    '-i',
    'testsrc2=size=1280x720:rate=24',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=880:sample_rate=48000',
    '-t',
    '12',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-b:a',
    '128k',
    $sourcePath,
]);

if ($sampleExitCode !== 0 || !is_file($sourcePath)) {
    fwrite(STDERR, "Failed to create QA download source clip.\n" . $sampleOutput . "\n");
    exit(1);
}

$originalSize = (int) (filesize($sourcePath) ?: 0);
$now = ve_now();
$stmt = $pdo->prepare(
    'INSERT INTO videos (
        user_id, public_id, title, original_filename, source_extension, is_public, status, status_message,
        duration_seconds, width, height, video_codec, audio_codec,
        original_size_bytes, processed_size_bytes, compression_ratio,
        processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
    ) VALUES (
        :user_id, :public_id, :title, :original_filename, :source_extension, 1, :status, :status_message,
        NULL, NULL, NULL, "", "",
        :original_size_bytes, 0, NULL,
        "", :created_at, :updated_at, :queued_at, NULL, NULL, NULL
    )'
);
$stmt->execute([
    ':user_id' => $userId,
    ':public_id' => $publicId,
    ':title' => 'QA download fixture',
    ':original_filename' => 'qa-download-source.mp4',
    ':source_extension' => 'mp4',
    ':status' => VE_VIDEO_STATUS_QUEUED,
    ':status_message' => 'Queued by QA download fixture.',
    ':original_size_bytes' => $originalSize,
    ':created_at' => $now,
    ':updated_at' => $now,
    ':queued_at' => $now,
]);

$videoId = (int) $pdo->lastInsertId();
ve_video_process_job($videoId);
$video = ve_video_get_by_id($videoId);

if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
    fwrite(STDERR, "QA download fixture video did not reach READY state.\n");
    exit(1);
}

$pdo->prepare('DELETE FROM video_download_grants WHERE video_id = :video_id')->execute([
    ':video_id' => $videoId,
]);
@unlink(ve_video_download_path($video));

echo json_encode([
    'user_id' => $userId,
    'video_id' => $videoId,
    'public_id' => $publicId,
    'username' => $username,
    'password' => $password,
    'watch_url' => $baseUrl . '/d/' . rawurlencode($publicId),
    'embed_url' => $baseUrl . '/e/' . rawurlencode($publicId),
    'download_wait_free' => (int) ve_video_config()['download_wait_free'],
    'download_wait_premium' => (int) ve_video_config()['download_wait_premium'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
