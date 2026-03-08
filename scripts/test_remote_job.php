<?php

declare(strict_types=1);

require __DIR__ . '/../app/frontend.php';

$userId = (int) ($argv[1] ?? 0);
$url = trim((string) ($argv[2] ?? ''));
$folderId = (int) ($argv[3] ?? 0);

if ($userId <= 0 || $url === '') {
    fwrite(STDERR, "Usage: php scripts/test_remote_job.php <user_id> <url> [folder_id]\n");
    exit(1);
}

try {
    $resolved = ve_remote_resolve_source(['source_url' => $url]);
    $jobId = ve_remote_create_job(
        $userId,
        $url,
        $folderId
    );
    $jobId = (int) ($jobId['id'] ?? 0);

    if ($jobId <= 0) {
        throw new RuntimeException('Remote test job could not be created.');
    }

    ve_remote_process_job($jobId);

    $job = ve_remote_get_by_id($jobId);
    $video = null;

    if (is_array($job) && (int) ($job['video_id'] ?? 0) > 0) {
        $statement = ve_db()->prepare('SELECT * FROM videos WHERE video_id = ? LIMIT 1');
        $statement->execute([(int) $job['video_id']]);
        $video = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    echo json_encode([
        'resolved' => $resolved,
        'job' => $job,
        'video' => $video,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'ERROR: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
