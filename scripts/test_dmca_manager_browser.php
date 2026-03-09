<?php

declare(strict_types=1);

error_reporting(E_ALL);

function dmca_browser_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dmca_browser_wait_for_server(string $baseUrl, int $attempts = 50): void
{
    for ($i = 0; $i < $attempts; $i++) {
        $curl = curl_init($baseUrl . '/');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 400,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status > 0) {
            return;
        }

        usleep(100000);
    }

    throw new RuntimeException('Built-in DMCA browser server did not start in time.');
}

function dmca_browser_find_listening_pid(int $port): ?int
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

function dmca_browser_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title): int
{
    $now = ve_now();
    $stmt = $pdo->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public,
            status, status_message, duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio, processing_error,
            created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, 0, :public_id, :title, :original_filename, :source_extension, 1,
            :status, :status_message, 120.0, 1280, 720, "h264", "aac",
            104857600, 104857600, 1.0, "",
            :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':public_id' => $publicId,
        ':title' => $title,
        ':original_filename' => strtolower(str_replace(' ', '-', $title)) . '.mp4',
        ':source_extension' => 'mp4',
        ':status' => VE_VIDEO_STATUS_READY,
        ':status_message' => 'Ready for playback.',
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
        ':processing_started_at' => $now,
        ':ready_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $fields
 */
function dmca_browser_update_notice_fields(int $noticeId, array $fields): void
{
    $assignments = [];
    $params = [':id' => $noticeId];

    foreach ($fields as $column => $value) {
        $assignments[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }

    ve_db()->prepare(
        'UPDATE dmca_notices
         SET ' . implode(', ', $assignments) . '
         WHERE id = :id'
    )->execute($params);
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$node = is_file('C:\\Program Files\\nodejs\\node.exe') ? 'C:\\Program Files\\nodejs\\node.exe' : 'node';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dmca-browser.sqlite';
$port = 18086;
$baseUrl = 'http://127.0.0.1:' . $port;
$serverPid = null;

$existingPid = dmca_browser_find_listening_pid($port);

if (is_int($existingPid) && $existingPid > 0) {
    @shell_exec('taskkill /PID ' . $existingPid . ' /T /F >NUL 2>NUL');
}

@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'dmca-browser-app-key',
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
$activeUser = ve_create_user('dmca_browser', 'dmca-browser@example.com', 'DmcaPass123');
$activeUserId = (int) ($activeUser['id'] ?? 0);
dmca_browser_assert($activeUserId > 0, 'Browser DMCA user should be created.');

$emptyUser = ve_create_user('dmca_browser_empty', 'dmca-browser-empty@example.com', 'DmcaPass123');
dmca_browser_assert((int) ($emptyUser['id'] ?? 0) > 0, 'Browser DMCA empty user should be created.');

$responseVideoId = dmca_browser_insert_ready_video($pdo, $activeUserId, 'dmcabrowserresp', 'Browser DMCA Response Fixture');
$deleteVideoId = dmca_browser_insert_ready_video($pdo, $activeUserId, 'dmcabrowserdelete', 'Browser DMCA Delete Fixture');
$overdueVideoId = dmca_browser_insert_ready_video($pdo, $activeUserId, 'dmcabrowseroverdue', 'Browser DMCA Overdue Fixture');

ve_dmca_create_notice([
    'user_id' => $activeUserId,
    'video_id' => $responseVideoId,
    'case_code' => 'DMCA-BROWSER-RESP',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Browser Rights',
    'complainant_email' => 'rights@example.test',
    'claimed_work' => 'Browser Response Fixture',
    'reported_url' => ve_absolute_url('/d/dmcabrowserresp'),
    'work_reference_url' => 'https://rights.example.test/browser-fixture',
    'evidence_urls' => ['https://rights.example.test/evidence/browser'],
]);

ve_dmca_create_notice([
    'user_id' => $activeUserId,
    'video_id' => $deleteVideoId,
    'case_code' => 'DMCA-BROWSER-DELETE',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Delete Rights',
    'claimed_work' => 'Browser Delete Fixture',
    'reported_url' => ve_absolute_url('/d/dmcabrowserdelete'),
]);

$overdueNotice = ve_dmca_create_notice([
    'user_id' => $activeUserId,
    'video_id' => $overdueVideoId,
    'case_code' => 'DMCA-BROWSER-OVERDUE',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Overdue Rights',
    'claimed_work' => 'Browser Overdue Fixture',
    'reported_url' => ve_absolute_url('/d/dmcabrowseroverdue'),
]);
dmca_browser_update_notice_fields((int) ($overdueNotice['id'] ?? 0), [
    'received_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'updated_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'effective_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'content_disabled_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'auto_delete_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
]);

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($command, 'r'));
    dmca_browser_wait_for_server($baseUrl);
    $serverPid = dmca_browser_find_listening_pid($port);

    $browserEnv = [
        'DMCA_BROWSER_BASE_URL' => $baseUrl,
        'DMCA_BROWSER_USER' => 'dmca_browser',
        'DMCA_BROWSER_PASSWORD' => 'DmcaPass123',
        'DMCA_BROWSER_EMPTY_USER' => 'dmca_browser_empty',
        'DMCA_BROWSER_EMPTY_PASSWORD' => 'DmcaPass123',
        'DMCA_BROWSER_RESPONSE_CASE_CODE' => 'DMCA-BROWSER-RESP',
        'DMCA_BROWSER_DELETE_CASE_CODE' => 'DMCA-BROWSER-DELETE',
    ];

    foreach ($browserEnv as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    $browserCommand = "powershell -NoProfile -Command \"Set-Location -LiteralPath ''"
        . str_replace("'", "''", $root)
        . "''; & ''"
        . str_replace("'", "''", $node)
        . "'' ''scripts\\test_dmca_manager_browser.js''\"";
    passthru($browserCommand, $exitCode);
    dmca_browser_assert($exitCode === 0, 'DMCA browser smoke test failed.');
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
    }
}
