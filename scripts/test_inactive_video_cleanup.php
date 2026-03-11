<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

function inactive_cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function inactive_cleanup_insert_video(PDO $pdo, int $userId, string $publicId, string $title, string $readyAt): int
{
    $relativeDir = ve_video_default_storage_relative_dir($publicId, $readyAt);
    $directory = ve_video_storage_path('library', str_replace('/', DIRECTORY_SEPARATOR, $relativeDir));
    ve_ensure_directory($directory);
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'master.m3u8', "#EXTM3U\n");

    $stmt = $pdo->prepare(
        'INSERT INTO videos (
            user_id, public_id, title, original_filename, source_extension, is_public, status, status_message,
            duration_seconds, width, height, video_codec, audio_codec, original_size_bytes, processed_size_bytes,
            compression_ratio, processing_error, storage_relative_dir, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :public_id, :title, :original_filename, :source_extension, 1, :status, :status_message,
            120, 1280, 720, "h264", "aac", 1024, 512,
            0.5, "", :storage_relative_dir, :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':public_id' => $publicId,
        ':title' => $title,
        ':original_filename' => $publicId . '.mp4',
        ':source_extension' => 'mp4',
        ':status' => VE_VIDEO_STATUS_READY,
        ':status_message' => 'Ready for cleanup QA.',
        ':storage_relative_dir' => $relativeDir,
        ':created_at' => $readyAt,
        ':updated_at' => $readyAt,
        ':queued_at' => $readyAt,
        ':processing_started_at' => $readyAt,
        ':ready_at' => $readyAt,
    ]);

    return (int) $pdo->lastInsertId();
}

$pdo = ve_db();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$user = ve_create_user('cleanup_' . $suffix, 'cleanup_' . $suffix . '@example.invalid', 'qa-password-123');
$userId = (int) ($user['id'] ?? 0);

inactive_cleanup_assert($userId > 0, 'Unable to create cleanup QA user.');

$oldDate = gmdate('Y-m-d H:i:s', ve_timestamp() - (31 * 86400));
$freshDate = gmdate('Y-m-d H:i:s', ve_timestamp() - (7 * 86400));

$stalePublicId = 'cleanupstale' . $suffix;
$viewedPublicId = 'cleanupviewed' . $suffix;
$freshPublicId = 'cleanupfresh' . $suffix;

$staleVideoId = inactive_cleanup_insert_video($pdo, $userId, $stalePublicId, 'Stale zero-view video', $oldDate);
$viewedVideoId = inactive_cleanup_insert_video($pdo, $userId, $viewedPublicId, 'Viewed video', $oldDate);
$freshVideoId = inactive_cleanup_insert_video($pdo, $userId, $freshPublicId, 'Fresh zero-view video', $freshDate);

ve_dashboard_record_video_view($viewedVideoId, $userId, gmdate('Y-m-d'), 0, 'cleanup-viewed-' . $suffix);

$deletedCount = ve_video_cleanup_inactive_videos();

inactive_cleanup_assert($deletedCount === 1, 'Exactly one inactive video should be deleted.');
inactive_cleanup_assert(ve_video_get_by_id($staleVideoId) === null, 'Stale zero-view video should be removed from the database.');
inactive_cleanup_assert(!is_dir(ve_video_storage_path('library', str_replace('/', DIRECTORY_SEPARATOR, ve_video_default_storage_relative_dir($stalePublicId, $oldDate)))), 'Stale inactive video directory should be removed.');
inactive_cleanup_assert(is_array(ve_video_get_by_id($viewedVideoId)), 'Viewed video should be retained.');
inactive_cleanup_assert(is_array(ve_video_get_by_id($freshVideoId)), 'Fresh zero-view video should be retained.');

ve_video_delete_video_rows(array_filter([
    ve_video_get_by_id($viewedVideoId),
    ve_video_get_by_id($freshVideoId),
]));
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);

echo "inactive video cleanup qa ok\n";
