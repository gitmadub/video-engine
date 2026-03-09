<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class PublicApiHttpClient
{
    public function __construct(
        private string $baseUrl,
        private string $cookieFile
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $this->baseUrl . $path;
        $headers = [];
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);

                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
            curl_setopt($curl, CURLOPT_URL, $url);
        }

        if (isset($options['form'])) {
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
            'headers' => $headers,
            'body' => $body,
        ];
    }
}

function public_api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function public_api_json(array $response): array
{
    $payload = json_decode($response['body'], true);
    public_api_assert(is_array($payload), 'Expected JSON response, got: ' . $response['body']);
    return $payload;
}

function public_api_wait_for_server(string $baseUrl, int $attempts = 50): void
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

function public_api_find_listening_pid(int $port): ?int
{
    $output = shell_exec('netstat -ano -p tcp | findstr :' . $port);

    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    foreach (preg_split('/\R/', trim($output)) as $line) {
        $columns = preg_split('/\s+/', trim((string) $line));

        if (!is_array($columns) || count($columns) < 5) {
            continue;
        }

        $localAddress = (string) ($columns[1] ?? '');
        $foreignAddress = (string) ($columns[2] ?? '');
        $pid = (string) ($columns[4] ?? '');

        if (!preg_match('/:' . preg_quote((string) $port, '/') . '$/', $localAddress) || !preg_match('/:0$/', $foreignAddress)) {
            continue;
        }

        if (ctype_digit($pid)) {
            return (int) $pid;
        }
    }

    return null;
}

function public_api_create_test_video(string $path): void
{
    file_put_contents($path, random_bytes(2048));
    public_api_assert(is_file($path), 'Unable to create upload fixture.');
}

function public_api_insert_ready_video(PDO $pdo, int $userId, int $folderId, string $publicId, string $title, int $sizeBytes): int
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
            :status, :status_message, 123.0, 1280, 720, "h264", "aac",
            :original_size_bytes, :processed_size_bytes, 1.0, "",
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

function public_api_seed_library_files(array $video): void
{
    $directory = ve_video_library_directory((string) ($video['public_id'] ?? ''));
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'source.mp4', random_bytes(1024));
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'poster.jpg', random_bytes(512));
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'preview-sprite.jpg', random_bytes(512));
}

function public_api_add_stats(int $videoId, int $userId, string $date, int $views, int $bandwidthBytes): void
{
    for ($index = 0; $index < $views; $index++) {
        ve_dashboard_record_video_view($videoId, $userId, $date);
    }

    ve_dashboard_record_video_bandwidth($videoId, $userId, $bandwidthBytes, $date);
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public-api-test.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public-api-test.cookie';
$uploadFixture = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public-api-upload.mp4';
$port = 18082;
$baseUrl = 'http://127.0.0.1:' . $port;
$serverPid = null;

@unlink($dbPath);
@unlink($cookiePath);
@unlink($uploadFixture);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'public-api-test-app-key',
    'VE_FFMPEG_PATH' => str_replace('\\', '/', $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'ffmpeg-8.0.1-essentials_build' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe'),
    'VE_FFPROBE_PATH' => str_replace('\\', '/', $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'ffmpeg-8.0.1-essentials_build' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffprobe.exe'),
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
$user = ve_create_user('publicapi_case', 'publicapi@example.com', 'PublicApiPass123');
$userId = (int) ($user['id'] ?? 0);
public_api_assert($userId > 0, 'Unable to create the public API test user.');
$apiKey = ve_user_api_key($user);
public_api_assert($apiKey !== '', 'Unable to load the public API test key.');

$seedFolder = ve_video_folder_create($userId, 0, 'Seed Folder');
$seedFolderId = (int) ($seedFolder['id'] ?? 0);
$videoOneId = public_api_insert_ready_video($pdo, $userId, 0, 'pubapi000001', 'Public API Seed One', 1250000);
$videoTwoId = public_api_insert_ready_video($pdo, $userId, $seedFolderId, 'pubapi000002', 'Public API Seed Two', 2500000);
$videoOne = ve_video_get_by_id($videoOneId);
$videoTwo = ve_video_get_by_id($videoTwoId);
public_api_assert(is_array($videoOne) && is_array($videoTwo), 'Unable to reload seeded videos.');
public_api_seed_library_files($videoOne);
public_api_seed_library_files($videoTwo);

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$todayDate = $today->format('Y-m-d');
$yesterdayDate = $today->sub(new DateInterval('P1D'))->format('Y-m-d');
public_api_add_stats($videoOneId, $userId, $yesterdayDate, 4, 100 * 1024 * 1024);
public_api_add_stats($videoOneId, $userId, $todayDate, 3, 80 * 1024 * 1024);

$playbackInsert = $pdo->prepare(
    'INSERT INTO video_playback_sessions (
        video_id, session_token_hash, owner_user_id, ip_hash, user_agent_hash, expires_at,
        created_at, last_seen_at, playback_started_at, bandwidth_bytes_served, revoked_at
    ) VALUES (
        :video_id, :session_token_hash, NULL, :ip_hash, :user_agent_hash, :expires_at,
        :created_at, :last_seen_at, :playback_started_at, 0, NULL
    )'
);
$playbackInsert->execute([
    ':video_id' => $videoOneId,
    ':session_token_hash' => hash_hmac('sha256', 'public-api-session', ve_app_secret()),
    ':ip_hash' => hash_hmac('sha256', '127.0.0.1', ve_app_secret()),
    ':user_agent_hash' => hash_hmac('sha256', 'public-api-test-agent', ve_app_secret()),
    ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ':created_at' => ve_now(),
    ':last_seen_at' => ve_now(),
    ':playback_started_at' => ve_now(),
]);

$errorJob = ve_remote_create_job($userId, 'https://example.com/broken.mp4', 0, VE_REMOTE_STATUS_ERROR, 'Remote upload failed.', 'Broken link');
public_api_assert((int) ($errorJob['id'] ?? 0) > 0, 'Unable to seed remote upload error job.');
public_api_create_test_video($uploadFixture);

$envPrefix = '';

foreach ($env as $key => $value) {
    $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', (string) $value) . '" && ';
}

$command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
pclose(popen($command, 'r'));
public_api_wait_for_server($baseUrl);
$serverPid = public_api_find_listening_pid($port);

try {
    $client = new PublicApiHttpClient($baseUrl, $cookiePath);

    $accountInfo = public_api_json($client->request('GET', '/api/account/info', ['query' => ['key' => $apiKey]]));
    public_api_assert(($accountInfo['status'] ?? null) === 200, 'Account info should return 200.');
    public_api_assert(($accountInfo['result']['email'] ?? null) === 'publicapi@example.com', 'Account info should return the test email.');

    $stats = public_api_json($client->request('GET', '/api/account/stats', ['query' => ['key' => $apiKey, 'last' => 2]]));
    public_api_assert(($stats['status'] ?? null) === 200, 'Account stats should return 200.');
    public_api_assert(count((array) ($stats['result'] ?? [])) >= 2, 'Account stats should return report rows.');

    $dmca = public_api_json($client->request('GET', '/api/dmca/list', ['query' => ['key' => $apiKey]]));
    public_api_assert(($dmca['status'] ?? null) === 200, 'DMCA list should return 200.');
    public_api_assert(is_array($dmca['result'] ?? null), 'DMCA list should return an array.');

    $uploadServer = public_api_json($client->request('GET', '/api/upload/server', ['query' => ['key' => $apiKey]]));
    public_api_assert(($uploadServer['status'] ?? null) === 200 && is_string($uploadServer['result'] ?? null), 'Upload server discovery should return a URL.');

    $uploadResponse = public_api_json($client->request('POST', (string) $uploadServer['result'], [
        'form' => [
            'api_key' => $apiKey,
            'file' => new CURLFile($uploadFixture, 'application/octet-stream', 'fixture.mp4'),
        ],
    ]));
    public_api_assert(($uploadResponse['status'] ?? null) === 200, 'Upload server POST should return 200.');
    public_api_assert(is_array($uploadResponse['result'] ?? null) && count($uploadResponse['result']) === 1, 'Upload server POST should return one uploaded file result.');

    $folderCreate = public_api_json($client->request('GET', '/api/folder/create', ['query' => ['key' => $apiKey, 'name' => 'QA Folder']]));
    $qaFolderId = (int) (($folderCreate['result']['fld_id'] ?? 0));
    public_api_assert($qaFolderId > 0, 'Folder create should return a new folder id.');

    $folderRename = public_api_json($client->request('GET', '/api/folder/rename', ['query' => ['key' => $apiKey, 'fld_id' => $qaFolderId, 'name' => 'Renamed QA Folder']]));
    public_api_assert(($folderRename['result'] ?? null) === 'true', 'Folder rename should return true.');

    $fileList = public_api_json($client->request('GET', '/api/file/list', ['query' => ['key' => $apiKey, 'per_page' => 200]]));
    public_api_assert(($fileList['status'] ?? null) === 200, 'File list should return 200.');
    public_api_assert((int) (($fileList['result']['results_total'] ?? '0')) >= 2, 'File list should include the seeded files.');

    $fileCheck = public_api_json($client->request('GET', '/api/file/check', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001']]));
    public_api_assert((string) ($fileCheck['result'][0]['status'] ?? '') === 'Active', 'File check should report the ready file as Active.');

    $fileInfo = public_api_json($client->request('GET', '/api/file/info', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001']]));
    public_api_assert((string) ($fileInfo['result'][0]['filecode'] ?? '') === 'pubapi000001', 'File info should include the requested file.');

    $fileImage = public_api_json($client->request('GET', '/api/file/image', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001']]));
    public_api_assert((string) ($fileImage['result'][0]['filecode'] ?? '') === 'pubapi000001', 'File image should include the requested file.');

    $fileRename = public_api_json($client->request('GET', '/api/file/rename', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001', 'title' => 'Renamed Public API Seed']]));
    public_api_assert(($fileRename['result'] ?? null) === 'true', 'File rename should return true.');

    $fileMove = public_api_json($client->request('GET', '/api/file/move', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001', 'fld_id' => $qaFolderId]]));
    public_api_assert(($fileMove['result'] ?? null) === 'true', 'File move should return true.');

    $folderList = public_api_json($client->request('GET', '/api/folder/list', ['query' => ['key' => $apiKey, 'fld_id' => $qaFolderId]]));
    public_api_assert(count((array) ($folderList['result']['files'] ?? [])) >= 1, 'Folder list should include the moved file.');

    $search = public_api_json($client->request('GET', '/api/search/videos', ['query' => ['key' => $apiKey, 'search_term' => 'Renamed Public API Seed']]));
    public_api_assert((int) (($search['result']['results_total'] ?? '0')) >= 1, 'Search should find the renamed file.');

    $clone = public_api_json($client->request('GET', '/api/file/clone', ['query' => ['key' => $apiKey, 'file_code' => 'pubapi000001']]));
    public_api_assert(is_string($clone['result']['filecode'] ?? null) && $clone['result']['filecode'] !== '', 'Clone should return a new filecode.');

    $uploadUrl = public_api_json($client->request('GET', '/api/upload/url', ['query' => ['key' => $apiKey, 'url' => 'https://example.com/video.mp4']]));
    $remoteCode = (string) ($uploadUrl['result']['filecode'] ?? '');
    public_api_assert($remoteCode !== '', 'Remote upload add should return a transfer code.');

    $remoteList = public_api_json($client->request('GET', '/api/urlupload/list', ['query' => ['key' => $apiKey]]));
    public_api_assert(count((array) ($remoteList['result'] ?? [])) >= 2, 'Remote upload list should include seeded jobs.');

    $remoteStatus = public_api_json($client->request('GET', '/api/urlupload/status', ['query' => ['key' => $apiKey, 'file_code' => $remoteCode]]));
    public_api_assert((string) ($remoteStatus['result'][0]['file_code'] ?? '') === $remoteCode, 'Remote upload status should return the requested transfer.');

    $remoteSlots = public_api_json($client->request('GET', '/api/urlupload/slots', ['query' => ['key' => $apiKey]]));
    public_api_assert(($remoteSlots['status'] ?? null) === 200, 'Remote upload slots should return 200.');

    $restartErrors = public_api_json($client->request('GET', '/api/urlupload/actions', ['query' => ['key' => $apiKey, 'restart_errors' => 1]]));
    public_api_assert(($restartErrors['msg'] ?? null) === 'Errors restarted', 'Remote upload restart_errors should return the documented message.');

    $deleteTransfer = public_api_json($client->request('GET', '/api/urlupload/actions', ['query' => ['key' => $apiKey, 'delete_code' => $remoteCode]]));
    public_api_assert(($deleteTransfer['msg'] ?? null) === 'Transfer deleted', 'Remote upload delete_code should delete the queued transfer.');

    echo "PASS: public api docs surface\n";
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        exec('taskkill /F /PID ' . $serverPid . ' >NUL 2>NUL');
    }
}
