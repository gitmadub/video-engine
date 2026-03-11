<?php

declare(strict_types=1);

error_reporting(E_ALL);

function remote_features_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'remote-features.sqlite';

@unlink($dbPath);

putenv('VE_DB_DSN=sqlite:' . str_replace('\\', '/', $dbPath));
$_ENV['VE_DB_DSN'] = 'sqlite:' . str_replace('\\', '/', $dbPath);
putenv('VE_APP_KEY=remote-features-app-key');
$_ENV['VE_APP_KEY'] = 'remote-features-app-key';

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$pdo = ve_db();
$actor = ve_create_user('remote_admin', 'remote-admin@example.com', 'RemotePass123!');
$user = ve_create_user('remote_user', 'remote-user@example.com', 'RemotePass123!');
$actorUserId = (int) ($actor['id'] ?? 0);
$userId = (int) ($user['id'] ?? 0);

remote_features_assert($actorUserId > 0, 'Expected admin actor user to be created.');
remote_features_assert($userId > 0, 'Expected remote upload user to be created.');

$allSettings = ve_admin_default_settings();
$allSettings['remote_default_quality'] = '720';
$allSettings['remote_max_queue_per_user'] = '33';
ve_admin_save_app_settings($allSettings, $actorUserId);

remote_features_assert(ve_remote_default_quality_height() === 720, 'Remote default quality should be loaded from app settings.');
remote_features_assert((int) (ve_remote_config()['max_queue_per_user'] ?? 0) === 33, 'Remote queue cap should honor the app setting.');

$parentFolder = ve_video_folder_create($userId, 0, 'Parent Folder');
$childFolder = ve_video_folder_create($userId, (int) ($parentFolder['id'] ?? 0), 'Child Folder');
$folderOptions = ve_video_folder_options_for_user($userId);
$folderLabels = array_map(static fn (array $folder): string => (string) ($folder['fld_name'] ?? ''), $folderOptions);

remote_features_assert(in_array('Parent Folder', $folderLabels, true), 'Folder options should include root folders.');
remote_features_assert(in_array('Parent Folder / Child Folder', $folderLabels, true), 'Folder options should include nested folders with their full path.');

$downloadWorkspace = ve_storage_path('private', 'remote_uploads', 'qa');
ve_ensure_directory($downloadWorkspace);
$rawDownloadPath = $downloadWorkspace . DIRECTORY_SEPARATOR . 'yt-dlp-download.mp4';
file_put_contents($rawDownloadPath, 'qa');
$finalizedDownload = ve_remote_finalize_downloaded_file([
    'filename' => 'My Example Title.mp4',
], $rawDownloadPath, 'yt-dlp-download.mp4');

remote_features_assert(is_file((string) ($finalizedDownload['path'] ?? '')), 'Finalized yt-dlp download should exist.');
remote_features_assert((string) ($finalizedDownload['filename'] ?? '') === 'My Example Title.mp4', 'Finalized yt-dlp download should use the grabbed title as the filename.');

function remote_features_insert_video(PDO $pdo, int $userId, int $folderId, string $publicId, string $title, string $status, string $statusMessage, string $processingError = ''): int
{
    $now = ve_now();
    $stmt = $pdo->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public, status, status_message,
            duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio,
            processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :folder_id, :public_id, :title, :original_filename, :source_extension, 1, :status, :status_message,
            NULL, NULL, NULL, "", "",
            1024, 0, NULL,
            :processing_error, :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':folder_id' => $folderId,
        ':public_id' => $publicId,
        ':title' => $title,
        ':original_filename' => strtolower(str_replace(' ', '-', $title)) . '.mp4',
        ':source_extension' => 'mp4',
        ':status' => $status,
        ':status_message' => $statusMessage,
        ':processing_error' => $processingError,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
        ':processing_started_at' => $status === VE_VIDEO_STATUS_PROCESSING || $status === VE_VIDEO_STATUS_READY || $status === VE_VIDEO_STATUS_FAILED ? $now : null,
        ':ready_at' => $status === VE_VIDEO_STATUS_READY ? $now : null,
    ]);

    return (int) $pdo->lastInsertId();
}

$processingVideoId = remote_features_insert_video(
    $pdo,
    $userId,
    (int) ($childFolder['id'] ?? 0),
    'rproc000001',
    'Processing Clip',
    VE_VIDEO_STATUS_PROCESSING,
    'Compressing video and packaging secure stream.'
);
$failedVideoId = remote_features_insert_video(
    $pdo,
    $userId,
    0,
    'rfail000001',
    'Failed Clip',
    VE_VIDEO_STATUS_FAILED,
    'Processing failed.',
    'Codec mismatch during QA.'
);

$processingJob = ve_remote_create_job($userId, 'https://example.com/processing.mp4', 0);
$failedJob = ve_remote_create_job($userId, 'https://example.com/failed.mp4', 0);

ve_remote_update_job((int) ($processingJob['id'] ?? 0), [
    'status' => VE_REMOTE_STATUS_COMPLETE,
    'status_message' => 'Remote file imported successfully. Video queued for processing.',
    'video_id' => $processingVideoId,
    'video_public_id' => 'rproc000001',
    'original_filename' => 'Processing Clip.mp4',
    'updated_at' => ve_now(),
]);
ve_remote_update_job((int) ($failedJob['id'] ?? 0), [
    'status' => VE_REMOTE_STATUS_COMPLETE,
    'status_message' => 'Remote file imported successfully. Video queued for processing.',
    'video_id' => $failedVideoId,
    'video_public_id' => 'rfail000001',
    'original_filename' => 'Failed Clip.mp4',
    'updated_at' => ve_now(),
]);

$remotePayload = ve_remote_list_payload($userId);
$rowsByName = [];

foreach ((array) ($remotePayload['list'] ?? []) as $row) {
    if (is_array($row)) {
        $rowsByName[(string) ($row['url'] ?? '')] = $row;
    }
}

remote_features_assert(count((array) ($remotePayload['folders_tree'] ?? [])) >= 2, 'Remote payload should expose folder options for the upload target select.');
remote_features_assert((string) (($rowsByName['Processing Clip.mp4']['status'] ?? '')) === 'WORKING2', 'Completed remote jobs should surface linked video processing as a working state.');
remote_features_assert(str_contains((string) (($rowsByName['Processing Clip.mp4']['st'] ?? '')), 'Compressing video and packaging secure stream.'), 'Remote payload should expose the linked video processing message.');
remote_features_assert((string) (($rowsByName['Failed Clip.mp4']['status'] ?? '')) === 'ERROR', 'Completed remote jobs should surface linked video processing failures as errors.');
remote_features_assert(str_contains((string) (($rowsByName['Failed Clip.mp4']['st'] ?? '')), 'Codec mismatch during QA.'), 'Remote payload should expose linked video processing errors.');

$fixturePath = ve_storage_path('private', 'remote_uploads', 'import-fixture.mp4');
@unlink($fixturePath);
[$fixtureExitCode, $fixtureOutput] = ve_video_run_command([
    (string) ve_video_config()['ffmpeg'],
    '-y',
    '-hide_banner',
    '-loglevel',
    'error',
    '-f',
    'lavfi',
    '-i',
    'testsrc2=size=640x360:rate=24',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=440:sample_rate=48000',
    '-t',
    '1',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-b:a',
    '96k',
    $fixturePath,
]);

remote_features_assert($fixtureExitCode === 0 && is_file($fixturePath), 'Expected ffmpeg to generate a remote import fixture. ' . $fixtureOutput);

$importedVideo = ve_remote_import_downloaded_video([
    'user_id' => $userId,
    'folder_id' => (int) ($childFolder['id'] ?? 0),
], [
    'path' => $fixturePath,
    'filename' => 'Imported Remote Clip.mp4',
]);

remote_features_assert((int) ($importedVideo['folder_id'] ?? 0) === (int) ($childFolder['id'] ?? 0), 'Imported remote videos should preserve the selected target folder.');
remote_features_assert((string) ($importedVideo['title'] ?? '') === 'Imported Remote Clip', 'Imported remote videos should derive the title from the finalized filename.');
remote_features_assert((string) ($importedVideo['original_filename'] ?? '') === 'Imported Remote Clip.mp4', 'Imported remote videos should keep the finalized original filename.');

echo "remote upload features qa ok\n";
