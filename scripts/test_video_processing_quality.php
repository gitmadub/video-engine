<?php

declare(strict_types=1);

error_reporting(E_ALL);

function processing_quality_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function processing_quality_insert_video(PDO $pdo, int $userId, string $publicId, string $filename, string $extension, int $sizeBytes): int
{
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
        ':title' => 'Processing quality fixture',
        ':original_filename' => $filename,
        ':source_extension' => $extension,
        ':status' => VE_VIDEO_STATUS_QUEUED,
        ':status_message' => 'Queued by processing quality QA.',
        ':original_size_bytes' => $sizeBytes,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function processing_quality_run_case(PDO $pdo, int $actorUserId, int $userId, int $maxHeight, string $extension): array
{
    $settings = ve_admin_default_settings();
    $settings['video_processing_free_max_height'] = (string) $maxHeight;
    ve_admin_save_app_settings($settings, $actorUserId);

    $publicId = 'qaq' . substr(preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(10)) ?: '', 0, 9);
    $directory = ve_video_library_directory($publicId);
    $sourcePath = $directory . DIRECTORY_SEPARATOR . 'source.' . $extension;

    [$fixtureExitCode, $fixtureOutput] = ve_video_run_command([
        (string) ve_video_config()['ffmpeg'],
        '-y',
        '-hide_banner',
        '-loglevel',
        'error',
        '-f',
        'lavfi',
        '-i',
        'testsrc2=size=1920x1080:rate=24',
        '-f',
        'lavfi',
        '-i',
        'sine=frequency=660:sample_rate=48000',
        '-t',
        '2',
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

    processing_quality_assert($fixtureExitCode === 0 && is_file($sourcePath), 'Expected ffmpeg to create the ' . $extension . ' source fixture. ' . $fixtureOutput);

    $videoId = processing_quality_insert_video(
        $pdo,
        $userId,
        $publicId,
        'quality-source.' . $extension,
        $extension,
        (int) (filesize($sourcePath) ?: 0)
    );

    ve_video_process_job($videoId);

    $video = ve_video_get_by_id($videoId);
    processing_quality_assert(is_array($video), 'Processed quality fixture video should exist.');
    processing_quality_assert((string) ($video['status'] ?? '') === VE_VIDEO_STATUS_READY, 'Processed quality fixture should reach ready status.');

    $downloadPath = ve_video_prepare_download_path($video);
    processing_quality_assert(is_string($downloadPath) && $downloadPath !== '' && is_file($downloadPath), 'Processed quality fixture should produce a downloadable MP4.');

    $downloadMetadata = ve_video_probe($downloadPath);
    processing_quality_assert(is_array($downloadMetadata), 'Processed quality fixture download should be probeable.');

    $expectedWidth = $maxHeight === 720 ? 1280 : 1920;
    processing_quality_assert((int) ($downloadMetadata['height'] ?? 0) === $maxHeight, 'Processed download height should match the configured max height for .' . $extension . ' sources.');
    processing_quality_assert((int) ($downloadMetadata['width'] ?? 0) === $expectedWidth, 'Processed download width should stay proportional for .' . $extension . ' sources.');

    $pdo->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => $videoId]);
    $pdo->prepare('DELETE FROM video_playback_sessions WHERE video_id = :video_id')->execute([':video_id' => $videoId]);
    ve_video_delete_directory($directory);

    return [
        'source_extension' => $extension,
        'configured_max_height' => $maxHeight,
        'download_width' => (int) ($downloadMetadata['width'] ?? 0),
        'download_height' => (int) ($downloadMetadata['height'] ?? 0),
    ];
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'processing-quality.sqlite';

@unlink($dbPath);

putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));
$_ENV['VE_DB_DSN'] = 'sqlite:' . str_replace('\\', '/', $dbPath);
putenv('VE_APP_KEY=processing-quality-app-key');
$_ENV['VE_APP_KEY'] = 'processing-quality-app-key';

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$pdo = ve_db();
$actor = ve_create_user('processing_quality_admin', 'processing-quality-admin@example.com', 'QualityPass123!');
$user = ve_create_user('processing_quality_user', 'processing-quality-user@example.com', 'QualityPass123!');
$actorUserId = (int) ($actor['id'] ?? 0);
$userId = (int) ($user['id'] ?? 0);

processing_quality_assert($actorUserId > 0, 'Expected processing-quality admin user to be created.');
processing_quality_assert($userId > 0, 'Expected processing-quality user to be created.');

$results = [
    processing_quality_run_case($pdo, $actorUserId, $userId, 1080, 'mp4'),
    processing_quality_run_case($pdo, $actorUserId, $userId, 1080, 'mkv'),
    processing_quality_run_case($pdo, $actorUserId, $userId, 720, 'mp4'),
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
