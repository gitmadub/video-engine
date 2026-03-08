<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$downloadUrl = getenv('VE_FFMPEG_WINDOWS_URL') ?: 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip';
$downloadsDir = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'downloads';
$zipPath = $downloadsDir . DIRECTORY_SEPARATOR . 'ffmpeg-release-essentials.zip';
$extractDir = $downloadsDir . DIRECTORY_SEPARATOR . 'ffmpeg-install';
$targetDir = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin';

if (DIRECTORY_SEPARATOR !== '\\') {
    fwrite(STDERR, "This installer currently targets Windows builds only.\n");
    exit(1);
}

foreach ([$downloadsDir, $targetDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Failed to create directory: {$directory}\n");
        exit(1);
    }
}

if (!is_file($zipPath)) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 180,
            'follow_location' => 1,
            'user_agent' => 'video-engine-ffmpeg-installer',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $remote = @fopen($downloadUrl, 'rb', false, $context);
    $local = @fopen($zipPath, 'wb');

    if (!is_resource($remote) || !is_resource($local)) {
        fwrite(STDERR, "Failed to download FFmpeg from {$downloadUrl}\n");
        exit(1);
    }

    $written = stream_copy_to_stream($remote, $local);
    fclose($remote);
    fclose($local);

    if ($written === false || $written <= 0) {
        fwrite(STDERR, "Failed to write archive to {$zipPath}\n");
        exit(1);
    }
}

if (is_dir($extractDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();

        if ($item->isDir()) {
            rmdir($path);
            continue;
        }

        unlink($path);
    }

    rmdir($extractDir);
}

if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
    fwrite(STDERR, "Failed to create extraction directory: {$extractDir}\n");
    exit(1);
}

$zip = new ZipArchive();

if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Failed to open {$zipPath}\n");
    exit(1);
}

if (!$zip->extractTo($extractDir)) {
    $zip->close();
    fwrite(STDERR, "Failed to extract {$zipPath}\n");
    exit(1);
}

$zip->close();
$entries = array_values(array_filter(
    scandir($extractDir) ?: [],
    static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
));

if ($entries === []) {
    fwrite(STDERR, "The extracted archive is empty.\n");
    exit(1);
}

$packageRoot = $extractDir . DIRECTORY_SEPARATOR . $entries[0];

foreach (['ffmpeg.exe', 'ffprobe.exe'] as $binary) {
    $source = $packageRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binary;
    $target = $targetDir . DIRECTORY_SEPARATOR . $binary;

    if (!is_file($source)) {
        fwrite(STDERR, "Missing binary in package: {$source}\n");
        exit(1);
    }

    if (!copy($source, $target)) {
        fwrite(STDERR, "Failed to copy {$source} to {$target}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Installed FFmpeg into {$targetDir}\n");
