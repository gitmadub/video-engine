<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

$keepSource = in_array('--keep-source', $argv, true);
$selectedVolumeId = 0;

foreach ($argv as $index => $argument) {
    if ($argument === '--volume-id' && isset($argv[$index + 1])) {
        $selectedVolumeId = max(0, (int) $argv[$index + 1]);
    }
}

if (!function_exists('ve_admin_storage_box_pick_for_new_video')) {
    fwrite(STDERR, "Storage-box helpers are not available in this build.\n");
    exit(1);
}

$volume = $selectedVolumeId > 0
    ? ve_admin_storage_box_load($selectedVolumeId)
    : ve_admin_storage_box_pick_for_new_video();

if (!is_array($volume) || (int) ($volume['id'] ?? 0) <= 0) {
    fwrite(STDERR, "No ready storage box is available.\n");
    exit(1);
}

$targetRoot = ve_admin_storage_box_library_root($volume);

if (!is_dir($targetRoot)) {
    fwrite(STDERR, "Storage-box library root is not mounted locally: {$targetRoot}\n");
    exit(1);
}

function migrate_copy_tree(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create target directory: ' . $target);
    }

    $items = scandir($source);

    if (!is_array($items)) {
        throw new RuntimeException('Unable to scan source directory: ' . $source);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $targetPath = $target . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            migrate_copy_tree($sourcePath, $targetPath);
            continue;
        }

        if (!is_file($sourcePath)) {
            continue;
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Unable to copy file: ' . $sourcePath);
        }
    }
}

function migrate_resolve_existing_directory(array $video, string $targetRoot): string
{
    $publicId = trim((string) ($video['public_id'] ?? ''));
    $storedRelativeDir = trim((string) ($video['storage_relative_dir'] ?? ''));
    $legacyDirectory = ve_video_local_library_directory($publicId);
    $candidates = [];

    if ($storedRelativeDir !== '') {
        $candidates[] = rtrim($targetRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedRelativeDir);
    }

    $candidates[] = $legacyDirectory;
    $candidates[] = rtrim($targetRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ve_video_legacy_storage_relative_dir($publicId));

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_dir($candidate)) {
            return $candidate;
        }
    }

    return '';
}

$videos = ve_db()->query(
    'SELECT id, public_id, storage_volume_id, storage_relative_dir, created_at
     FROM videos
     WHERE deleted_at IS NULL
     ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$migrated = 0;
$updatedOnly = 0;
$skipped = 0;

foreach ($videos as $video) {
    if (!is_array($video)) {
        continue;
    }

    $videoId = (int) ($video['id'] ?? 0);
    $publicId = (string) ($video['public_id'] ?? '');

    if ($videoId <= 0 || $publicId === '') {
        $skipped++;
        continue;
    }

    $relativeDir = ve_video_default_storage_relative_dir($publicId, (string) ($video['created_at'] ?? ''));
    $targetDirectory = rtrim($targetRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    $sourceDirectory = migrate_resolve_existing_directory($video, $targetRoot);

    if ((int) ($video['storage_volume_id'] ?? 0) === (int) ($volume['id'] ?? 0)
        && trim((string) ($video['storage_relative_dir'] ?? '')) === $relativeDir
        && is_dir($targetDirectory)
        && ($sourceDirectory === '' || realpath($sourceDirectory) === realpath($targetDirectory))) {
        $skipped++;
        continue;
    }

    if ($sourceDirectory !== '' && realpath($sourceDirectory) !== realpath($targetDirectory)) {
        migrate_copy_tree($sourceDirectory, $targetDirectory);

        if (!$keepSource) {
            ve_video_delete_directory($sourceDirectory);
        }

        $migrated++;
    } elseif (is_dir($targetDirectory)) {
        $updatedOnly++;
    } elseif ($sourceDirectory === '') {
        $updatedOnly++;
    } else {
        $skipped++;
        continue;
    }

    ve_db()->prepare(
        'UPDATE videos
         SET storage_volume_id = :storage_volume_id,
             storage_relative_dir = :storage_relative_dir,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':storage_volume_id' => (int) ($volume['id'] ?? 0),
        ':storage_relative_dir' => $relativeDir,
        ':updated_at' => ve_now(),
        ':id' => $videoId,
    ]);
}

echo json_encode([
    'storage_volume_id' => (int) ($volume['id'] ?? 0),
    'target_root' => $targetRoot,
    'migrated' => $migrated,
    'updated_only' => $updatedOnly,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
