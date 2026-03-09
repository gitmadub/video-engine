<?php

declare(strict_types=1);

error_reporting(E_ALL);

function dashboard_browser_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dashboard_browser_wait_for_server(string $baseUrl, int $attempts = 50): void
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

    throw new RuntimeException('Built-in browser smoke server did not start in time.');
}

function dashboard_browser_find_listening_pid(int $port): ?int
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

function dashboard_browser_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title, int $sizeBytes): int
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
            :original_size_bytes, :processed_size_bytes, 0.75, "",
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

function dashboard_browser_add_stats(int $videoId, int $userId, string $date, int $views, int $bandwidthBytes): void
{
    for ($i = 0; $i < $views; $i++) {
        ve_dashboard_record_video_view($videoId, $userId, $date);
    }

    ve_dashboard_record_video_bandwidth($videoId, $userId, $bandwidthBytes, $date);
}

function dashboard_browser_seed_user_earnings(PDO $pdo, int $userId, string $statDate, int $earnedMicroUsd): void
{
    $now = ve_now();
    $stmt = $pdo->prepare(
        'INSERT INTO user_stats_daily (
            user_id, stat_date, views, earned_micro_usd, referral_earned_micro_usd, bandwidth_bytes, created_at, updated_at
        ) VALUES (
            :user_id, :stat_date, 0, :earned_micro_usd, 0, 0, :created_at, :updated_at
        )
        ON CONFLICT(user_id, stat_date) DO UPDATE SET
            earned_micro_usd = user_stats_daily.earned_micro_usd + excluded.earned_micro_usd,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':stat_date' => $statDate,
        ':earned_micro_usd' => $earnedMicroUsd,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$node = 'node';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dashboard-browser.sqlite';
$port = 18082;
$baseUrl = 'http://127.0.0.1:' . $port;

@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'dashboard-browser-app-key',
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
$user = ve_create_user('dashboard_browser', 'dashboard-browser@example.com', 'DashPass123');
$userId = (int) ($user['id'] ?? 0);
dashboard_browser_assert($userId > 0, 'Browser smoke user should be created.');

$alphaId = dashboard_browser_insert_ready_video($pdo, $userId, 'alphabrowser1', 'Alpha Browser Clip', 150 * 1024 * 1024);
$betaId = dashboard_browser_insert_ready_video($pdo, $userId, 'betabrowser02', 'Beta Browser Clip', 90 * 1024 * 1024);

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$todayDate = $today->format('Y-m-d');
$yesterdayDate = $today->sub(new DateInterval('P1D'))->format('Y-m-d');
$sevenDaysAgoDate = $today->sub(new DateInterval('P7D'))->format('Y-m-d');
$sixDaysAgoDate = $today->sub(new DateInterval('P6D'))->format('Y-m-d');

dashboard_browser_add_stats($alphaId, $userId, $sevenDaysAgoDate, 2, 300 * 1024 * 1024);
dashboard_browser_add_stats($betaId, $userId, $sixDaysAgoDate, 1, 150 * 1024 * 1024);
dashboard_browser_add_stats($alphaId, $userId, $yesterdayDate, 3, 400 * 1024 * 1024);
dashboard_browser_add_stats($alphaId, $userId, $todayDate, 1, 25 * 1024 * 1024);
dashboard_browser_add_stats($betaId, $userId, $todayDate, 1, 50 * 1024 * 1024);
dashboard_browser_seed_user_earnings($pdo, $userId, $todayDate, 50000000);

$playbackInsert = $pdo->prepare(
    'INSERT INTO video_playback_sessions (
        video_id, session_token_hash, owner_user_id, ip_hash, user_agent_hash, expires_at,
        created_at, last_seen_at, playback_started_at, bandwidth_bytes_served, revoked_at
    ) VALUES (
        :video_id, :session_token_hash, NULL, :ip_hash, :user_agent_hash, :expires_at,
        :created_at, :last_seen_at, NULL, 0, NULL
    )'
);
$playbackInsert->execute([
    ':video_id' => $alphaId,
    ':session_token_hash' => hash_hmac('sha256', 'dashboard-browser-session', ve_app_secret()),
    ':ip_hash' => hash_hmac('sha256', '127.0.0.1', ve_app_secret()),
    ':user_agent_hash' => hash_hmac('sha256', 'dashboard-browser-agent', ve_app_secret()),
    ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ':created_at' => ve_now(),
    ':last_seen_at' => ve_now(),
]);

$sessionId = (int) $pdo->lastInsertId();
$video = ve_video_get_by_id($alphaId);
dashboard_browser_assert(is_array($video), 'Expected browser smoke video to exist.');

$_SERVER['HTTP_USER_AGENT'] = 'dashboard-browser-agent';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

ve_video_mark_playback_started($video, ['id' => $sessionId]);
ve_video_record_segment_delivery($video, ['id' => $sessionId], 5 * 1024 * 1024);

$serverPid = null;

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($command, 'r'));
    dashboard_browser_wait_for_server($baseUrl);
    $serverPid = dashboard_browser_find_listening_pid($port);
    echo "browser server ready\n";

    $browserEnv = [
        'DASHBOARD_BROWSER_BASE_URL' => $baseUrl,
        'DASHBOARD_BROWSER_USER' => 'dashboard_browser',
        'DASHBOARD_BROWSER_PASSWORD' => 'DashPass123',
        'DASHBOARD_BROWSER_FROM' => $sevenDaysAgoDate,
        'DASHBOARD_BROWSER_TO' => $todayDate,
        'DASHBOARD_BROWSER_EXPECTED_VIEWS' => '9',
        'DASHBOARD_BROWSER_TOP_TITLE' => 'Alpha Browser Clip',
    ];
    $browserPrefix = '';

    foreach ($browserEnv as $key => $value) {
        $browserPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $browserCommand = 'cmd /c "' . $browserPrefix . 'cd /d "' . $root . '" && ' . $node . ' scripts\\test_dashboard_browser.js"';
    passthru($browserCommand, $exitCode);
    dashboard_browser_assert($exitCode === 0, 'Playwright dashboard browser smoke test failed.');
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
    }
}
