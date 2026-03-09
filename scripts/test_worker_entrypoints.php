<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/frontend.php';

ve_bootstrap();

function worker_entrypoint_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function worker_entrypoint_run(string $path): void
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $exitCode = 0;

    exec($command, $output, $exitCode);

    worker_entrypoint_assert($exitCode === 0, basename($path) . ' failed: ' . implode(PHP_EOL, $output));
}

$workerPaths = [
    ve_root_path('app', 'workers', 'video_queue.php'),
    ve_root_path('app', 'workers', 'remote_upload_queue.php'),
    ve_root_path('scripts', 'process_video_queue.php'),
    ve_root_path('scripts', 'process_remote_upload_queue.php'),
];

foreach ($workerPaths as $path) {
    worker_entrypoint_assert(is_file($path), 'Missing worker entrypoint: ' . $path);
    worker_entrypoint_run($path);
}

echo "worker entrypoints qa ok\n";
