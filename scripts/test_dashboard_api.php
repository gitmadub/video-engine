<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class DashboardApiHttpClient
{
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl, string $cookieFile)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = $cookieFile;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status:int,body:string}
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl . $path;

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
        ]);

        if (isset($options['form']) && is_array($options['form'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['form']);
        }

        $body = curl_exec($curl);

        if (!is_string($body)) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}

function dashboard_api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function dashboard_api_json(array $response): array
{
    $payload = json_decode($response['body'], true);
    dashboard_api_assert(is_array($payload), 'Expected JSON response, got: ' . $response['body']);
    return $payload;
}

function dashboard_api_extract_runtime_token(string $html): string
{
    preg_match('/window\.VE_CSRF_TOKEN=("[^"]+"|\'[^\']+\')/i', $html, $matches);
    dashboard_api_assert(isset($matches[1]), 'Unable to find runtime CSRF token.');
    $decoded = json_decode($matches[1], true);
    dashboard_api_assert(is_string($decoded) && $decoded !== '', 'Runtime CSRF token was invalid.');
    return $decoded;
}

function dashboard_api_wait_for_server(string $baseUrl, int $attempts = 50): void
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

    throw new RuntimeException('Built-in server did not start in time.');
}

function dashboard_api_find_listening_pid(int $port): ?int
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

function dashboard_api_pick_port(): int
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if (!is_resource($server)) {
        throw new RuntimeException('Unable to allocate dashboard API test port: ' . $errstr);
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);

    dashboard_api_assert(is_string($address) && preg_match('/:(\d+)$/', $address, $matches) === 1, 'Unable to resolve dashboard API test port.');

    return (int) $matches[1];
}

function dashboard_api_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title, int $sizeBytes): int
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

function dashboard_api_add_stats(int $videoId, int $userId, string $date, int $views, int $bandwidthBytes): void
{
    for ($i = 0; $i < $views; $i++) {
        ve_dashboard_record_video_view($videoId, $userId, $date);
    }

    ve_dashboard_record_video_bandwidth($videoId, $userId, $bandwidthBytes, $date);
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dashboard-test.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dashboard-test.cookie';
$port = dashboard_api_pick_port();
$baseUrl = 'http://127.0.0.1:' . $port;

@unlink($dbPath);
@unlink($cookiePath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'dashboard-test-app-key',
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
$user = ve_create_user('dashboard_case', 'dashboard@example.com', 'DashPass123');
$userId = (int) ($user['id'] ?? 0);
dashboard_api_assert($userId > 0, 'Dashboard test user should be created.');

$alphaSize = 150 * 1024 * 1024;
$betaSize = 90 * 1024 * 1024;
$alphaId = dashboard_api_insert_ready_video($pdo, $userId, 'alpha000001', 'Alpha Dashboard Clip', $alphaSize);
$betaId = dashboard_api_insert_ready_video($pdo, $userId, 'beta0000002', 'Beta Dashboard Clip', $betaSize);

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$todayDate = $today->format('Y-m-d');
$yesterdayDate = $today->sub(new DateInterval('P1D'))->format('Y-m-d');
$sevenDaysAgoDate = $today->sub(new DateInterval('P7D'))->format('Y-m-d');
$sixDaysAgoDate = $today->sub(new DateInterval('P6D'))->format('Y-m-d');

dashboard_api_add_stats($alphaId, $userId, $sevenDaysAgoDate, 2, 300 * 1024 * 1024);
dashboard_api_add_stats($betaId, $userId, $sixDaysAgoDate, 1, 150 * 1024 * 1024);
dashboard_api_add_stats($alphaId, $userId, $yesterdayDate, 3, 400 * 1024 * 1024);
dashboard_api_add_stats($alphaId, $userId, $todayDate, 1, 25 * 1024 * 1024);
dashboard_api_add_stats($betaId, $userId, $todayDate, 1, 50 * 1024 * 1024);

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
    ':session_token_hash' => hash_hmac('sha256', 'dashboard-session', ve_app_secret()),
    ':ip_hash' => hash_hmac('sha256', '127.0.0.1', ve_app_secret()),
    ':user_agent_hash' => hash_hmac('sha256', 'dashboard-test-agent', ve_app_secret()),
    ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ':created_at' => ve_now(),
    ':last_seen_at' => ve_now(),
]);
$hookSession = ['id' => (int) $pdo->lastInsertId()];
$hookVideo = ve_video_get_by_id($alphaId);
dashboard_api_assert(is_array($hookVideo), 'Expected dashboard hook video to exist.');

$_SERVER['HTTP_USER_AGENT'] = 'dashboard-test-agent';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ve_video_mark_playback_started($hookVideo, $hookSession);
ve_video_mark_playback_started($hookVideo, $hookSession);
ve_video_record_segment_delivery($hookVideo, $hookSession, 5 * 1024 * 1024);

$earnPerView = ve_dashboard_earnings_per_view_micro_usd();
$expectedTodayProfit = ve_dashboard_format_currency_micro_usd(2 * $earnPerView);
$expectedBalance = ve_dashboard_format_currency_micro_usd(8 * $earnPerView);

$serverPid = null;

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b cmd /c ""' . $php . '" -S 127.0.0.1:' . $port . ' router.php >NUL 2>&1"""';
    pclose(popen($command, 'r'));
    dashboard_api_wait_for_server($baseUrl);
    $serverPid = dashboard_api_find_listening_pid($port);
    dashboard_api_assert(is_int($serverPid) && $serverPid > 0, 'Dashboard API test server PID could not be resolved.');
    echo "server ready\n";

    $client = new DashboardApiHttpClient($baseUrl, $cookiePath);
    $home = $client->request('GET', '/');
    dashboard_api_assert($home['status'] === 200, 'Home page should load.');
    $csrf = dashboard_api_extract_runtime_token($home['body']);
    echo "home ok\n";

    $login = dashboard_api_json($client->request('POST', '/api/auth/login', [
        'form' => [
            'login' => 'dashboard_case',
            'password' => 'DashPass123',
            'token' => $csrf,
        ],
    ]));
    dashboard_api_assert(($login['status'] ?? null) === 'redirect', 'Login should succeed for the dashboard test user.');
    echo "login ok\n";

    $summary = dashboard_api_json($client->request('GET', '/api/dashboard/summary'));
    dashboard_api_assert((string) ($summary['status'] ?? '') === 'ok', 'Dashboard summary should return status ok.');
    dashboard_api_assert((string) ($summary['widgets']['today_earnings']['formatted'] ?? '') === $expectedTodayProfit, 'Dashboard summary should expose today earnings.');
    dashboard_api_assert((string) ($summary['widgets']['balance']['formatted'] ?? '') === $expectedBalance, 'Dashboard summary should expose total balance.');
    dashboard_api_assert((string) (($summary['top_files'][0]['public_id'] ?? '')) === 'alpha000001', 'Dashboard top files should rank Alpha first.');
    echo "summary ok\n";

    $legacy = dashboard_api_json($client->request('GET', '/data/dashboard-update.json'));
    dashboard_api_assert((string) ($legacy['today'] ?? '') === $expectedTodayProfit, 'Legacy dashboard route should proxy live today earnings.');
    dashboard_api_assert((string) ($legacy['balance'] ?? '') === $expectedBalance, 'Legacy dashboard route should proxy live balance.');
    echo "legacy ok\n";

    $report = dashboard_api_json($client->request('GET', '/api/dashboard/reports', [
        'query' => [
            'from' => $sevenDaysAgoDate,
            'to' => $todayDate,
        ],
    ]));
    dashboard_api_assert((int) (($report['totals']['views'] ?? 0)) === 8, 'Dashboard reports totals should sum seeded views.');
    dashboard_api_assert((string) ($report['totals']['profit'] ?? '') === $expectedBalance, 'Dashboard reports totals should sum seeded profit.');
    echo "reports api ok\n";

    $dashboardPage = $client->request('GET', '/dashboard');
    dashboard_api_assert($dashboardPage['status'] === 200, 'Dashboard page should load.');
    dashboard_api_assert(str_contains($dashboardPage['body'], 'data-dashboard-home'), 'Dashboard page should render the managed dashboard root.');
    dashboard_api_assert(str_contains($dashboardPage['body'], '/assets/js/dashboard_home.js'), 'Dashboard page should load the managed dashboard script.');
    dashboard_api_assert(!str_contains($dashboardPage['body'], '/data/dashboard-update.json'), 'Dashboard page should no longer hardcode the legacy JSON endpoint.');
    echo "dashboard page ok\n";

    $reportsPage = $client->request('GET', '/dashboard/reports');
    dashboard_api_assert($reportsPage['status'] === 200, 'Dashboard reports page should load.');
    dashboard_api_assert(str_contains($reportsPage['body'], 'data-dashboard-reports'), 'Reports page should render the managed reports root.');
    dashboard_api_assert(str_contains($reportsPage['body'], '/assets/js/dashboard_reports.js'), 'Reports page should load the managed reports script.');
    echo "reports page ok\n";
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
    }
}
