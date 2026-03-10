<?php

declare(strict_types=1);

error_reporting(E_ALL);

function videos_browser_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function videos_browser_wait_for_server(string $baseUrl, int $attempts = 50): void
{
    for ($i = 0; $i < $attempts; $i++) {
        $curl = curl_init($baseUrl . '/');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status > 0) {
            return;
        }

        usleep(100000);
    }

    throw new RuntimeException('Videos browser server did not start in time.');
}

function videos_browser_find_listening_pid(int $port): ?int
{
    $output = shell_exec('netstat -ano -p tcp | findstr :' . $port);

    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    foreach (preg_split('/\R/', trim($output)) as $line) {
        if (!is_string($line) || trim($line) === '') {
            continue;
        }

        $columns = preg_split('/\s+/', trim($line));

        if (!is_array($columns) || count($columns) < 5) {
            continue;
        }

        $localAddress = (string) ($columns[1] ?? '');
        $foreignAddress = (string) ($columns[2] ?? '');
        $pid = (string) ($columns[4] ?? '');

        if (!preg_match('/:' . preg_quote((string) $port, '/') . '$/', $localAddress)) {
            continue;
        }

        if (!preg_match('/:0$/', $foreignAddress)) {
            continue;
        }

        if (ctype_digit($pid) && (int) $pid > 0) {
            return (int) $pid;
        }
    }

    return null;
}

function videos_browser_insert_ready_video(PDO $pdo, int $userId, int $folderId, string $publicId, string $title, int $sizeBytes): int
{
    $now = ve_now();
    $stmt = $pdo->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public,
            status, status_message, duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio, processing_error,
            created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :folder_id, :public_id, :title, :original_filename, :source_extension, 1,
            :status, :status_message, 120.0, 1280, 720, "h264", "aac",
            :original_size_bytes, :processed_size_bytes, 0.75, "",
            :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':folder_id' => $folderId,
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

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$node = 'node';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'videos-browser.sqlite';
$port = 18152;
$baseUrl = 'http://127.0.0.1:' . $port;

@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'videos-browser-app-key',
];

foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/app/frontend.php';

$pdo = ve_db();
$user = ve_create_user('videos_browser', 'videos-browser@example.com', 'DashPass123');
$userId = (int) ($user['id'] ?? 0);
videos_browser_assert($userId > 0, 'Videos browser test user should be created.');

$now = ve_now();
$insertFolder = $pdo->prepare(
    'INSERT INTO video_folders (user_id, parent_id, public_code, name, created_at, updated_at, deleted_at)
     VALUES (:user_id, 0, :public_code, :name, :created_at, :updated_at, NULL)'
);

$insertFolder->execute([
    ':user_id' => $userId,
    ':public_code' => ve_video_folder_generate_public_code(),
    ':name' => 'Shared Folder',
    ':created_at' => $now,
    ':updated_at' => $now,
]);
$sharedFolderId = (int) $pdo->lastInsertId();

$insertFolder->execute([
    ':user_id' => $userId,
    ':public_code' => ve_video_folder_generate_public_code(),
    ':name' => 'Moved Folder',
    ':created_at' => $now,
    ':updated_at' => $now,
]);
$targetFolderId = (int) $pdo->lastInsertId();

videos_browser_insert_ready_video($pdo, $userId, $sharedFolderId, 'sharedfolder1', 'Shared Folder Clip', 64 * 1024 * 1024);
videos_browser_insert_ready_video($pdo, $userId, 0, 'browsermove01', 'Browser Move A', 40 * 1024 * 1024);
videos_browser_insert_ready_video($pdo, $userId, 0, 'browsermove02', 'Browser Move B', 55 * 1024 * 1024);

$serverPid = null;

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($command, 'r'));
    videos_browser_wait_for_server($baseUrl);
    $serverPid = videos_browser_find_listening_pid($port);
    echo "videos browser server ready\n";

    $browserEnv = [
        'VIDEOS_BROWSER_BASE_URL' => $baseUrl,
        'VIDEOS_BROWSER_USER' => 'videos_browser',
        'VIDEOS_BROWSER_PASSWORD' => 'DashPass123',
        'VIDEOS_BROWSER_SHARED_FOLDER' => 'Shared Folder',
        'VIDEOS_BROWSER_TARGET_FOLDER' => 'Moved Folder',
    ];
    $browserPrefix = '';

    foreach ($browserEnv as $key => $value) {
        $browserPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $browserCommand = 'cmd /c "' . $browserPrefix . 'cd /d "' . $root . '" && ' . $node . ' scripts\\test_videos_dashboard_browser.js"';
    passthru($browserCommand, $exitCode);
    videos_browser_assert($exitCode === 0, 'Videos dashboard browser QA failed.');
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
    }
}
