<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class VideosDashboardHttpClient
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

        if (isset($options['files']) && is_array($options['files'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, array_merge($options['form'] ?? [], $options['files']));
        } elseif (isset($options['form']) && is_array($options['form'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($options['form']));
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

function videos_actions_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videos_actions_json(array $response): array
{
    $payload = json_decode($response['body'], true);
    videos_actions_assert(is_array($payload), 'Expected JSON response, got: ' . $response['body']);
    return $payload;
}

function videos_actions_extract_runtime_token(string $html): string
{
    preg_match('/window\.VE_CSRF_TOKEN=("[^"]+"|\'[^\']+\')/i', $html, $matches);
    videos_actions_assert(isset($matches[1]), 'Unable to find runtime CSRF token.');
    $decoded = json_decode($matches[1], true);
    videos_actions_assert(is_string($decoded) && $decoded !== '', 'Runtime CSRF token was invalid.');
    return $decoded;
}

function videos_actions_wait_for_server(string $baseUrl, int $attempts = 80): void
{
    for ($i = 0; $i < $attempts; $i++) {
        $curl = curl_init($baseUrl . '/');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1000,
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

function videos_actions_is_windows(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function videos_actions_find_listening_pid(int $port): ?int
{
    if (!videos_actions_is_windows()) {
        $output = shell_exec('lsof -ti tcp:' . (int) $port . ' -sTCP:LISTEN 2>/dev/null');

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $line = trim((string) $line);

            if (ctype_digit($line) && (int) $line > 0) {
                return (int) $line;
            }
        }

        return null;
    }

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

function videos_actions_start_server(string $php, string $root, int $port, array $env): ?int
{
    if (videos_actions_is_windows()) {
        $envPrefix = '';

        foreach ($env as $key => $value) {
            $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
        }

        $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b cmd /c ""' . $php . '" -S 127.0.0.1:' . $port . ' router.php >NUL 2>&1"""';
        pclose(popen($command, 'r'));

        return null;
    }

    $envPrefix = [];

    foreach ($env as $key => $value) {
        if (!preg_match('/^[A-Z0-9_]+$/i', (string) $key)) {
            continue;
        }

        $envPrefix[] = $key . '=' . escapeshellarg((string) $value);
    }

    $command = 'cd ' . escapeshellarg($root)
        . ' && ' . implode(' ', $envPrefix)
        . ' ' . escapeshellarg($php)
        . ' -S 127.0.0.1:' . $port
        . ' router.php >/dev/null 2>&1 & echo $!';
    $output = shell_exec($command);
    $pid = trim((string) $output);

    return ctype_digit($pid) && (int) $pid > 0 ? (int) $pid : null;
}

function videos_actions_stop_server(?int $serverPid): void
{
    if (!is_int($serverPid) || $serverPid <= 0) {
        return;
    }

    if (videos_actions_is_windows()) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
        return;
    }

    shell_exec('kill ' . $serverPid . ' >/dev/null 2>&1');
}

function videos_actions_pick_port(): int
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if (!is_resource($server)) {
        throw new RuntimeException('Unable to allocate videos actions test port: ' . $errstr);
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);

    videos_actions_assert(is_string($address) && preg_match('/:(\d+)$/', $address, $matches) === 1, 'Unable to resolve videos actions test port.');

    return (int) $matches[1];
}

function videos_actions_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title, int $sizeBytes): int
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

$root = dirname(__DIR__);
$php = is_string(PHP_BINARY) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$runId = bin2hex(random_bytes(4));
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'videos-actions-test-' . $runId . '.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'videos-actions-test-' . $runId . '.cookie';
$port = videos_actions_pick_port();
$baseUrl = 'http://127.0.0.1:' . $port;

@unlink($dbPath);
@unlink($cookiePath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'videos-actions-test-app-key',
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
$actionsUsername = 'videos_actions_' . $runId;
$actionsEmail = 'videos-actions-' . $runId . '@example.com';
$user = ve_create_user($actionsUsername, $actionsEmail, 'DashPass123');
$userId = (int) ($user['id'] ?? 0);
videos_actions_assert($userId > 0, 'Videos actions test user should be created.');

$now = ve_now();
$pdo->prepare(
    'INSERT INTO video_folders (user_id, parent_id, public_code, name, created_at, updated_at, deleted_at)
     VALUES (:user_id, 0, :public_code, :name, :created_at, :updated_at, NULL)'
)->execute([
    ':user_id' => $userId,
    ':public_code' => ve_video_folder_generate_public_code(),
    ':name' => 'Existing Folder',
    ':created_at' => $now,
    ':updated_at' => $now,
]);

$rootVideoId = videos_actions_insert_ready_video($pdo, $userId, 'rootvideo01', 'Root Video Fixture', 150 * 1024 * 1024);
$bulkVideoId = videos_actions_insert_ready_video($pdo, $userId, 'bulkvideo02', 'Bulk Video Fixture', 90 * 1024 * 1024);

$serverPid = null;

try {
    $serverPid = videos_actions_start_server($php, $root, $port, $env);
    videos_actions_wait_for_server($baseUrl);
    $serverPid = $serverPid ?? videos_actions_find_listening_pid($port);
    videos_actions_assert(is_int($serverPid) && $serverPid > 0, 'Videos actions test server PID could not be resolved.');
    echo "videos actions server ready\n";

    $client = new VideosDashboardHttpClient($baseUrl, $cookiePath);
    $home = $client->request('GET', '/');
    videos_actions_assert($home['status'] === 200, 'Home page should load.');
    $csrf = videos_actions_extract_runtime_token($home['body']);

    $login = videos_actions_json($client->request('POST', '/api/auth/login', [
        'form' => [
            'login' => $actionsUsername,
            'password' => 'DashPass123',
            'token' => $csrf,
        ],
    ]));
    videos_actions_assert(($login['status'] ?? null) === 'redirect', 'Login should succeed for the videos actions test user.');

    $legacyDashboardPage = $client->request('GET', '/dashboard/videos');
    videos_actions_assert($legacyDashboardPage['status'] === 302, 'Legacy dashboard videos route should redirect to /videos.');

    $dashboardPage = $client->request('GET', '/videos');
    videos_actions_assert($dashboardPage['status'] === 200, 'Videos page should load.');
    videos_actions_assert(str_contains($dashboardPage['body'], '<video-manager'), 'Videos page should render the legacy video manager component.');
    videos_actions_assert(str_contains($dashboardPage['body'], '/assets/js/video_dashboard_legacy.js'), 'Videos page should load the legacy patch script.');

    $initialActions = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => 0,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    videos_actions_assert(($initialActions['status'] ?? null) === 'ok', 'Initial videos actions request should succeed.');
    videos_actions_assert(count((array) ($initialActions['folders'] ?? [])) === 1, 'Initial videos actions request should expose the seeded folder.');
    videos_actions_assert(count((array) ($initialActions['videos'] ?? [])) === 2, 'Initial videos actions request should expose both seeded videos.');
    videos_actions_assert((string) ($initialActions['per_page'] ?? '') === '25', 'Initial videos actions response should expose the default results-per-page value.');
    videos_actions_assert((array) ($initialActions['folder_path'] ?? []) === [], 'Root folder path should be empty in the legacy payload.');
    videos_actions_assert((string) (($initialActions['folders'][0]['siz'] ?? '')) === '0 B', 'Folder payload should include a formatted size.');
    videos_actions_assert((string) (($initialActions['folders'][0]['cre'] ?? '')) !== '', 'Folder payload should include a created date.');
    videos_actions_assert(str_contains((string) (($initialActions['folders'][0]['share_url'] ?? '')), '/videos/shared/'), 'Folder payload should include a public share URL.');

    $pageToken = (string) ($initialActions['token'] ?? '');
    videos_actions_assert($pageToken !== '', 'Videos actions response should expose a CSRF token.');

    $createFolderA = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'fld_id' => 0,
            'create_new_folder' => 'QA Folder A',
        ],
    ]));
    videos_actions_assert(isset($createFolderA[0]['fld_id']), 'Legacy create folder should return a folder payload.');
    $folderAId = (int) $createFolderA[0]['fld_id'];

    $createFolderB = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'fld_id' => 0,
            'create_new_folder' => 'QA Folder B',
        ],
    ]));
    videos_actions_assert(isset($createFolderB[0]['fld_id']), 'Second legacy create folder should return a folder payload.');
    $folderBId = (int) $createFolderB[0]['fld_id'];

    $renameFolder = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'fld_id' => $folderAId,
            'rename' => 'QA Folder Alpha',
        ],
    ]));
    videos_actions_assert(($renameFolder[0]['fn'] ?? null) === 'QA Folder Alpha', 'Legacy folder rename should return the new folder name.');

    $renameVideo = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'file_id' => $rootVideoId,
            'rename' => 'Root Video Renamed',
        ],
    ]));
    videos_actions_assert(($renameVideo[0]['ft'] ?? null) === 'Root Video Renamed', 'Legacy video rename should return the new video title.');

    $exportLinks = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'file_export' => '1',
            'file_id' => [$rootVideoId, $bulkVideoId],
        ],
    ]));
    videos_actions_assert(count($exportLinks) === 2, 'Legacy export should return both selected videos.');
    videos_actions_assert(str_contains((string) ($exportLinks[0]['dl'] ?? ''), '/d/rootvideo01'), 'Legacy export should include the root video download URL.');

    $folderTree = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'fld_select' => '1',
            'parent_id' => 0,
            'not_in' => [],
        ],
    ]));
    videos_actions_assert(count($folderTree) >= 3, 'Legacy folder selection tree should include the seeded and created folders.');

    $moveVideo = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'file_move' => '1',
            'to_folder' => $folderAId,
            'file_id' => [$rootVideoId],
        ],
    ]));
    videos_actions_assert(($moveVideo['status'] ?? null) === 'ok', 'Legacy file move should succeed.');

    $moveFolder = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'folder_move' => '1',
            'to_folder_fld' => $folderAId,
            'fld_id1' => [$folderBId],
            'fld_id' => 0,
        ],
    ]));
    videos_actions_assert(($moveFolder['status'] ?? null) === 'ok', 'Legacy folder move should succeed.');

    $folderActions = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => $folderAId,
            'per_page' => 50,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    videos_actions_assert(count((array) ($folderActions['videos'] ?? [])) === 1, 'Target folder should contain the moved video.');
    videos_actions_assert((string) (($folderActions['videos'][0]['fid'] ?? '')) === 'rootvideo01', 'Moved video should appear in the target folder list.');
    videos_actions_assert((string) (($folderActions['current_folder']['siz'] ?? '')) === '150.00 MB', 'Current folder payload should include the recursive folder size.');
    videos_actions_assert((string) ($folderActions['per_page'] ?? '') === '50', 'Videos actions should honor the requested results-per-page value.');
    videos_actions_assert(count((array) ($folderActions['folder_path'] ?? [])) === 1, 'The current folder payload should include a single breadcrumb segment for the parent folder.');
    videos_actions_assert((string) (($folderActions['folder_path'][0]['name'] ?? '')) === 'QA Folder Alpha', 'Folder path should expose the current folder name.');
    videos_actions_assert((int) (($folderActions['current_folder']['pub'] ?? 0)) === 1, 'Folder payload should expose the public folder flag.');

    $nestedTree = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'fld_select' => '1',
            'parent_id' => 0,
            'not_in' => [],
        ],
    ]));
    $alphaTree = null;

    foreach ($nestedTree as $folder) {
        if (is_array($folder) && ($folder['name'] ?? null) === 'QA Folder Alpha') {
            $alphaTree = $folder;
            break;
        }
    }

    videos_actions_assert(is_array($alphaTree), 'Nested folder tree should include the renamed parent folder.');
    videos_actions_assert(count((array) ($alphaTree['sub_folders'] ?? [])) === 1, 'Nested folder tree should show the moved sub-folder under the parent folder.');

    $setContentType = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'content_type' => '2',
        ],
    ]));
    videos_actions_assert(($setContentType['status'] ?? null) === 'ok', 'Saving the uploader content type should succeed.');
    videos_actions_assert((string) ($setContentType['uploader_type'] ?? '') === '2', 'The saved uploader content type should be returned to the dashboard.');
    $savedSettings = ve_get_user_settings($userId);
    videos_actions_assert((string) ($savedSettings['uploader_type'] ?? '') === '2', 'Uploader content type should persist into the account settings record.');

    $setPrivate = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'set_private' => '1',
            'file_id' => [$rootVideoId],
        ],
    ]));
    videos_actions_assert(($setPrivate['status'] ?? null) === 'ok', 'Legacy set_private should succeed.');

    $privateActions = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => $folderAId,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    videos_actions_assert((int) (($privateActions['videos'][0]['pub'] ?? 1)) === 0, 'Legacy set_private should update the video visibility flag.');

    $setPublic = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'set_public' => '1',
            'file_id' => [$rootVideoId],
        ],
    ]));
    videos_actions_assert(($setPublic['status'] ?? null) === 'ok', 'Legacy set_public should succeed.');

    $publicActions = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => $folderAId,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    videos_actions_assert((int) (($publicActions['videos'][0]['pub'] ?? 0)) === 1, 'Legacy set_public should restore the video visibility flag.');

    $setFolderPrivate = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'set_private' => '1',
            'folder_id' => [$folderAId],
        ],
    ]));
    videos_actions_assert(($setFolderPrivate['status'] ?? null) === 'ok', 'Legacy folder set_private should succeed.');

    $rootAfterFolderPrivate = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => 0,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    $privateFolderPayload = null;

    foreach ((array) ($rootAfterFolderPrivate['folders'] ?? []) as $folderPayload) {
        if ((int) ($folderPayload['fld_id'] ?? 0) === $folderAId) {
            $privateFolderPayload = $folderPayload;
            break;
        }
    }

    videos_actions_assert(is_array($privateFolderPayload), 'Root listing should still include the folder after making it private.');
    videos_actions_assert((int) (($privateFolderPayload['pub'] ?? 1)) === 0, 'Legacy set_private should update the folder visibility flag.');

    $setFolderPublic = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'set_public' => '1',
            'folder_id' => [$folderAId],
        ],
    ]));
    videos_actions_assert(($setFolderPublic['status'] ?? null) === 'ok', 'Legacy folder set_public should succeed.');

    $rootAfterFolderPublic = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => 0,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    $publicFolderPayload = null;

    foreach ((array) ($rootAfterFolderPublic['folders'] ?? []) as $folderPayload) {
        if ((int) ($folderPayload['fld_id'] ?? 0) === $folderAId) {
            $publicFolderPayload = $folderPayload;
            break;
        }
    }

    videos_actions_assert(is_array($publicFolderPayload), 'Root listing should still include the folder after making it public again.');
    videos_actions_assert((int) (($publicFolderPayload['pub'] ?? 0)) === 1, 'Legacy set_public should restore the folder visibility flag.');

    $subtitleList = videos_actions_json($client->request('POST', '/videos/subtitles', [
        'form' => [
            'token' => $pageToken,
            'file_code' => 'rootvideo01',
        ],
    ]));
    videos_actions_assert(($subtitleList['status'] ?? null) === 'ok', 'Legacy subtitle list should succeed.');

    $subtitleUpload = videos_actions_json($client->request('POST', '/videos/subtitles', [
        'form' => [
            'token' => $pageToken,
            'file_code' => 'rootvideo01',
            'srt_lang' => 'English',
        ],
        'files' => [
            'srt' => curl_file_create($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.gitignore', 'text/plain', 'qa.srt'),
        ],
    ]));
    videos_actions_assert(($subtitleUpload['status'] ?? null) === 'fail', 'Legacy subtitle upload should fail gracefully in this build.');

    $uploadResults = videos_actions_json($client->request('POST', '/videos/result', [
        'form' => [
            'fn' => 'rootvideo01',
            'st' => 'OK',
            'is_xhr' => '1',
        ],
    ]));
    videos_actions_assert(($uploadResults['status'] ?? null) === 'ok', 'Legacy upload results handoff should succeed.');
    videos_actions_assert((string) (($uploadResults['links'][0]['fid'] ?? '')) === 'rootvideo01', 'Legacy upload results handoff should return the moved video payload.');

    $thumbnail = $client->request('GET', '/videos/thumbnail', [
        'query' => ['file_id' => $rootVideoId],
    ]);
    videos_actions_assert($thumbnail['status'] === 200 && str_contains($thumbnail['body'], 'Poster and splash images are generated automatically'), 'Thumbnail modal endpoint should render the expected helper copy.');

    $marker = $client->request('GET', '/videos/markers', [
        'query' => ['file_id' => $rootVideoId],
    ]);
    videos_actions_assert($marker['status'] === 200 && str_contains($marker['body'], 'hover-preview sprite'), 'Markers modal endpoint should render the expected helper copy.');

    $sharing = $client->request('GET', '/videos/share', [
        'query' => ['folder_id' => $folderAId],
    ]);
    videos_actions_assert($sharing['status'] === 200 && str_contains($sharing['body'], 'Share link'), 'Folder sharing modal endpoint should render the share helper UI.');
    videos_actions_assert(str_contains($sharing['body'], '/videos/shared/'), 'Folder sharing modal endpoint should expose the public folder URL.');
    videos_actions_assert(str_contains($sharing['body'], 'Show Title'), 'Folder sharing modal endpoint should expose the Show Title toggle.');
    videos_actions_assert(str_contains($sharing['body'], 'data-share-folder-toggle'), 'Folder sharing modal endpoint should expose the share title toggle hook.');

    $publicFolderPath = (string) parse_url((string) ($publicFolderPayload['share_url'] ?? ''), PHP_URL_PATH);
    videos_actions_assert($publicFolderPath !== '', 'Folder sharing payload should expose a valid share path.');
    $publicFolder = $client->request('GET', $publicFolderPath);
    videos_actions_assert($publicFolder['status'] === 200 && str_contains($publicFolder['body'], 'Root Video Renamed'), 'Public folder page should render the public video listing.');
    videos_actions_assert(str_contains($publicFolder['body'], 'video_page.min.css'), 'Public folder page should load the shared player/dashboard design assets.');
    videos_actions_assert(str_contains($publicFolder['body'], '<h2 class="title mb-1">Folders</h2>'), 'Public folder page should render the sub-folder section when sub-folders exist.');

    $emptyFolderSharePath = (string) parse_url((string) ($initialActions['folders'][0]['share_url'] ?? ''), PHP_URL_PATH);
    videos_actions_assert($emptyFolderSharePath !== '', 'Seeded folder should expose a public share path.');
    $emptyFolderPage = $client->request('GET', $emptyFolderSharePath);
    videos_actions_assert($emptyFolderPage['status'] === 200, 'Seeded shared folder page should load.');
    videos_actions_assert(!str_contains($emptyFolderPage['body'], '<h2 class="title mb-1">Folders</h2>'), 'Public folder page should hide the folder section when there are no sub-folders.');

    $bulkDelete = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'del_selected' => '1',
            'file_id' => [$bulkVideoId],
        ],
    ]));
    videos_actions_assert(($bulkDelete['status'] ?? null) === 'ok', 'Legacy bulk delete should succeed.');

    $deleteMovedVideo = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'fld_id' => $folderAId,
            'del_code' => 'rootvideo01',
        ],
    ]));
    videos_actions_assert(($deleteMovedVideo['status'] ?? null) === 'ok', 'Legacy direct delete should succeed.');

    $deleteFolder = videos_actions_json($client->request('POST', '/videos/actions', [
        'form' => [
            'token' => $pageToken,
            'fld_id' => 0,
            'del_selected_fld' => '1',
            'fld_id1' => [$folderAId],
        ],
    ]));
    videos_actions_assert(($deleteFolder['status'] ?? null) === 'ok', 'Legacy folder delete should succeed.');

    $finalRoot = videos_actions_json($client->request('GET', '/videos/actions', [
        'query' => [
            'page' => 1,
            'fld_id' => 0,
            'sort_field' => 'file_created',
            'sort_order' => 'down',
        ],
    ]));
    videos_actions_assert(count((array) ($finalRoot['videos'] ?? [])) === 0, 'All test videos should be removed by the end of the flow.');
    videos_actions_assert(count((array) ($finalRoot['folders'] ?? [])) === 1, 'Only the original seeded folder should remain after deleting the test folders.');

    echo "videos dashboard actions ok\n";
} finally {
    videos_actions_stop_server($serverPid);
}
