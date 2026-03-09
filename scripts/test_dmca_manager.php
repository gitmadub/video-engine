<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class DmcaHttpClient
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
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $url = preg_match('#^https?://#i', $path) === 1 ? $path : $this->baseUrl . $path;
        $headers = [];
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);

                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headerLines = [];

            foreach ($options['headers'] as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            curl_setopt($curl, CURLOPT_HTTPHEADER, $headerLines);
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

function dmca_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function dmca_json(array $response): array
{
    $decoded = json_decode($response['body'], true);
    dmca_assert(is_array($decoded), 'Expected JSON response, got: ' . $response['body']);
    return $decoded;
}

function dmca_extract_runtime_token(string $html): string
{
    preg_match('/window\.VE_CSRF_TOKEN=("[^"]+"|\'[^\']+\')/i', $html, $matches);
    dmca_assert(isset($matches[1]), 'Unable to find runtime CSRF token.');
    $decoded = json_decode($matches[1], true);
    dmca_assert(is_string($decoded) && $decoded !== '', 'Runtime CSRF token was invalid.');
    return $decoded;
}

function dmca_wait_for_server(string $baseUrl, int $attempts = 50): void
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

    throw new RuntimeException('Built-in DMCA server did not start in time.');
}

function dmca_find_listening_pid(int $port): ?int
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

function dmca_insert_ready_video(PDO $pdo, int $userId, string $publicId, string $title): int
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
function dmca_update_notice_fields(int $noticeId, array $fields): void
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
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dmca-suite.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dmca-suite.cookie';
$port = 18085;
$baseUrl = 'http://127.0.0.1:' . $port;
$serverPid = null;

$existingPid = dmca_find_listening_pid($port);

if (is_int($existingPid) && $existingPid > 0) {
    @shell_exec('taskkill /PID ' . $existingPid . ' /T /F >NUL 2>NUL');
}

@unlink($dbPath);
@unlink($cookiePath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'dmca-suite-app-key',
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
$user = ve_create_user('dmca_case', 'dmca@example.com', 'DmcaPass123');
$userId = (int) ($user['id'] ?? 0);
dmca_assert($userId > 0, 'DMCA suite user should be created.');

$responseVideoId = dmca_insert_ready_video($pdo, $userId, 'dmcaresponse01', 'DMCA Response Fixture');
$deleteVideoId = dmca_insert_ready_video($pdo, $userId, 'dmcadelete01', 'DMCA Delete Fixture');
$overdueVideoId = dmca_insert_ready_video($pdo, $userId, 'dmcaoverdue01', 'DMCA Overdue Fixture');
$restoredVideoId = dmca_insert_ready_video($pdo, $userId, 'dmcarestored01', 'DMCA Restored Fixture');

$responseNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $responseVideoId,
    'case_code' => 'DMCA-QA-RESP',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Studio Rights',
    'complainant_company' => 'Studio Rights LLC',
    'complainant_email' => 'legal@studio-rights.test',
    'claimed_work' => 'Response Fixture Clip',
    'reported_url' => ve_absolute_url('/d/dmcaresponse01'),
    'work_reference_url' => 'https://rights.example.test/response-fixture',
    'evidence_urls' => ['https://rights.example.test/evidence/response'],
]);

$deleteNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $deleteVideoId,
    'case_code' => 'DMCA-QA-DELETE',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Delete Rights',
    'claimed_work' => 'Delete Fixture Clip',
    'reported_url' => ve_absolute_url('/d/dmcadelete01'),
]);

$overdueNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $overdueVideoId,
    'case_code' => 'DMCA-QA-OVERDUE',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Overdue Rights',
    'claimed_work' => 'Overdue Fixture Clip',
    'reported_url' => ve_absolute_url('/d/dmcaoverdue01'),
]);

dmca_update_notice_fields((int) ($overdueNotice['id'] ?? 0), [
    'received_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'updated_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'effective_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'content_disabled_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
    'auto_delete_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
]);

$restoredNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $restoredVideoId,
    'case_code' => 'DMCA-QA-RESTORED',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Archive Label',
    'claimed_work' => 'Archive Film',
    'reported_url' => ve_absolute_url('/d/dmcarestored01'),
]);
$restoredNotice = ve_dmca_update_notice_status(
    (int) ($restoredNotice['id'] ?? 0),
    VE_DMCA_NOTICE_STATUS_RESTORED,
    'status_change',
    'Content restored',
    'Restored after review.'
);
dmca_update_notice_fields((int) ($restoredNotice['id'] ?? 0), [
    'received_at' => gmdate('Y-m-d H:i:s', strtotime('-20 days')),
    'updated_at' => gmdate('Y-m-d H:i:s', strtotime('-18 days')),
    'effective_at' => gmdate('Y-m-d H:i:s', strtotime('-20 days')),
    'resolved_at' => gmdate('Y-m-d H:i:s', strtotime('-18 days')),
]);

dmca_assert((int) ((ve_video_get_by_id($responseVideoId) ?: [])['is_public'] ?? 1) === 0, 'Response fixture should be hidden after DMCA.');
dmca_assert((int) ((ve_video_get_by_id($deleteVideoId) ?: [])['is_public'] ?? 1) === 0, 'Delete fixture should be hidden after DMCA.');

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($command, 'r'));
    dmca_wait_for_server($baseUrl);
    $serverPid = dmca_find_listening_pid($port);

    $client = new DmcaHttpClient($baseUrl, $cookiePath);
    $home = $client->request('GET', '/');
    dmca_assert($home['status'] === 200, 'Home page should load.');
    $csrf = dmca_extract_runtime_token($home['body']);

    $login = dmca_json($client->request('POST', '/api/auth/login', [
        'form' => [
            'login' => 'dmca_case',
            'password' => 'DmcaPass123',
            'token' => $csrf,
        ],
    ]));
    dmca_assert(($login['status'] ?? null) === 'redirect', 'DMCA suite login should succeed.');

    $dmcaPage = $client->request('GET', '/dashboard/dmca-manager');
    dmca_assert($dmcaPage['status'] === 200, 'DMCA dashboard page should load after login.');
    dmca_assert(str_contains($dmcaPage['body'], 'settings_menu'), 'DMCA page should reuse the settings-page menu shell.');
    dmca_assert(str_contains($dmcaPage['body'], 'widget_area'), 'DMCA page should render dashboard widgets.');
    dmca_assert(str_contains($dmcaPage['body'], 'data-dmca-manager'), 'DMCA page should render the managed root.');
    dmca_assert(str_contains($dmcaPage['body'], '/assets/js/dashboard_dmca.js'), 'DMCA dashboard page should load the managed bundle.');
    $postLoginCsrf = dmca_extract_runtime_token($dmcaPage['body']);

    $snapshot = dmca_json($client->request('GET', '/api/dmca'));
    dmca_assert(($snapshot['status'] ?? null) === 'ok', 'DMCA snapshot should return status ok.');
    dmca_assert((int) (($snapshot['summary']['open_cases'] ?? 0)) === 2, 'Snapshot should count open uploader cases after auto-delete processing.');
    dmca_assert((int) (($snapshot['summary']['pending_delete'] ?? 0)) === 2, 'Snapshot should count active 24-hour cases.');
    dmca_assert((int) (($snapshot['summary']['responses_received'] ?? 0)) === 0, 'Snapshot should start with zero uploader responses.');
    dmca_assert((int) (($snapshot['summary']['deleted_videos'] ?? 0)) === 1, 'Snapshot should count the overdue auto-deleted case.');

    $resolvedOnly = dmca_json($client->request('GET', '/api/dmca?status=resolved'));
    dmca_assert(count((array) ($resolvedOnly['items'] ?? [])) === 2, 'Resolved filter should include restored and auto-deleted cases.');

    $detail = dmca_json($client->request('GET', '/api/dmca/DMCA-QA-RESP'));
    dmca_assert(($detail['notice']['status'] ?? null) === VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED, 'Response case should still wait for uploader action.');
    dmca_assert((bool) ($detail['notice']['can_submit_response'] ?? false) === true, 'Response case should allow optional uploader information.');
    dmca_assert((bool) ($detail['notice']['can_delete_video'] ?? false) === true, 'Response case should allow direct video deletion.');
    dmca_assert(count((array) ($detail['notice']['evidence_urls'] ?? [])) === 1, 'Response case should expose evidence URLs.');

    $responsePayload = dmca_json($client->request('POST', '/api/dmca/DMCA-QA-RESP/response', [
        'form' => [
            'token' => $postLoginCsrf,
            'contact_email' => '',
            'contact_phone' => '',
            'notes' => '',
        ],
    ]));
    dmca_assert(($responsePayload['status'] ?? null) === 'ok', 'Optional uploader response should succeed with blank fields.');
    dmca_assert(($responsePayload['notice']['status'] ?? null) === VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED, 'Uploader response should move the case into response_submitted.');
    dmca_assert((bool) ($responsePayload['notice']['can_submit_response'] ?? true) === false, 'Uploader response should disable resubmission.');

    $postResponse = dmca_json($client->request('GET', '/api/dmca/DMCA-QA-RESP'));
    dmca_assert(($postResponse['notice']['uploader_response']['notes'] ?? null) === '', 'Blank uploader notes should be stored as empty text.');
    dmca_assert(($postResponse['notice']['status_label'] ?? null) === 'Info sent', 'Uploader response should expose the uploader-facing status label.');

    $deletePayload = dmca_json($client->request('POST', '/api/dmca/DMCA-QA-DELETE/delete-video', [
        'form' => [
            'token' => $postLoginCsrf,
        ],
    ]));
    dmca_assert(($deletePayload['status'] ?? null) === 'ok', 'Direct delete should succeed.');
    dmca_assert(($deletePayload['notice']['status'] ?? null) === VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED, 'Direct delete should close the case as uploader deleted.');
    dmca_assert(ve_video_get_by_id($deleteVideoId) === null, 'Deleted DMCA video should be removed from the database.');

    $finalSnapshot = dmca_json($client->request('GET', '/api/dmca'));
    dmca_assert((int) (($finalSnapshot['summary']['open_cases'] ?? 0)) === 1, 'Final summary should keep only the response-submitted case open.');
    dmca_assert((int) (($finalSnapshot['summary']['pending_delete'] ?? 0)) === 0, 'Final summary should have no remaining 24-hour delete timers.');
    dmca_assert((int) (($finalSnapshot['summary']['responses_received'] ?? 0)) === 1, 'Final summary should count the uploader response.');
    dmca_assert((int) (($finalSnapshot['summary']['deleted_videos'] ?? 0)) === 2, 'Final summary should count uploader-deleted and auto-deleted videos.');

    $resolvedAfterActions = dmca_json($client->request('GET', '/api/dmca?status=resolved'));
    dmca_assert(count((array) ($resolvedAfterActions['items'] ?? [])) === 3, 'Resolved filter should include restored, auto-deleted, and uploader-deleted cases after actions.');

    echo "DMCA manager tests passed.\n";
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        exec('taskkill /F /PID ' . $serverPid . ' >NUL 2>NUL');
    }

    @unlink($cookiePath);
}
