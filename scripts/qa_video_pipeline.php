<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$keepArtifacts = in_array('--keep', $argv, true);
$pdo = ve_db();
$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();

if ($userId < 1) {
    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $user = ve_create_user('qa_' . $suffix, 'qa_' . $suffix . '@example.invalid', 'qa-password-123');
    $userId = (int) ($user['id'] ?? 0);
}

if ($userId < 1) {
    fwrite(STDERR, "Unable to provision a QA user.\n");
    exit(1);
}

$publicId = 'qa' . substr(preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(10)) ?: '', 0, 10);
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
    '10',
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
    fwrite(STDERR, "Failed to create QA source clip.\n" . $sampleOutput . "\n");
    exit(1);
}

$originalSize = filesize($sourcePath) ?: 0;
$now = ve_now();
$stmt = $pdo->prepare(
    'INSERT INTO videos (
        user_id, public_id, title, original_filename, source_extension, status, status_message,
        duration_seconds, width, height, video_codec, audio_codec,
        original_size_bytes, processed_size_bytes, compression_ratio,
        processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
    ) VALUES (
        :user_id, :public_id, :title, :original_filename, :source_extension, :status, :status_message,
        NULL, NULL, NULL, "", "",
        :original_size_bytes, 0, NULL,
        "", :created_at, :updated_at, :queued_at, NULL, NULL, NULL
    )'
);
$stmt->execute([
    ':user_id' => $userId,
    ':public_id' => $publicId,
    ':title' => 'QA pipeline clip',
    ':original_filename' => 'qa-source.mp4',
    ':source_extension' => 'mp4',
    ':status' => VE_VIDEO_STATUS_QUEUED,
    ':status_message' => 'Queued by QA smoke test.',
    ':original_size_bytes' => $originalSize,
    ':created_at' => $now,
    ':updated_at' => $now,
    ':queued_at' => $now,
]);

$videoId = (int) $pdo->lastInsertId();
ve_video_process_job($videoId);

$video = ve_video_get_by_id($videoId);
$result = [
    'video_id' => $videoId,
    'public_id' => $publicId,
    'status' => (string) ($video['status'] ?? ''),
    'duration_seconds' => isset($video['duration_seconds']) ? (float) $video['duration_seconds'] : null,
    'processed_size_bytes' => isset($video['processed_size_bytes']) ? (int) $video['processed_size_bytes'] : null,
    'playlist_exists' => is_file(ve_video_playlist_path(['public_id' => $publicId])),
    'key_exists' => is_file(ve_video_key_path(['public_id' => $publicId])),
    'poster_exists' => is_file(ve_video_poster_path(['public_id' => $publicId])),
    'preview_sprite_exists' => is_file(ve_video_preview_sprite_path(['public_id' => $publicId])),
    'preview_vtt_exists' => is_file(ve_video_preview_vtt_path(['public_id' => $publicId])),
    'processing_error' => (string) ($video['processing_error'] ?? ''),
];

$success = ($result['status'] ?? '') === VE_VIDEO_STATUS_READY
    && ($result['playlist_exists'] ?? false)
    && ($result['key_exists'] ?? false)
    && ($result['poster_exists'] ?? false)
    && ($result['preview_sprite_exists'] ?? false)
    && ($result['preview_vtt_exists'] ?? false);

if (!$keepArtifacts) {
    $pdo->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => $videoId]);
    $pdo->prepare('DELETE FROM video_playback_sessions WHERE video_id = :video_id')->execute([':video_id' => $videoId]);
    ve_video_delete_directory($directory);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($success ? 0 : 1);
