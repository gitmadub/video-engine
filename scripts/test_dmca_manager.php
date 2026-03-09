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

function dmca_force_notice_dates(int $noticeId, string $receivedAt, ?string $effectiveAt = null, ?string $resolvedAt = null, ?string $updatedAt = null): void
{
    ve_db()->prepare(
        'UPDATE dmca_notices
         SET received_at = :received_at,
             updated_at = :updated_at,
             effective_at = :effective_at,
             resolved_at = :resolved_at
         WHERE id = :id'
    )->execute([
        ':received_at' => $receivedAt,
        ':updated_at' => $updatedAt ?? $receivedAt,
        ':effective_at' => $effectiveAt,
        ':resolved_at' => $resolvedAt,
        ':id' => $noticeId,
    ]);
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dmca-suite.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dmca-suite.cookie';
$port = 18085;
$baseUrl = 'http://127.0.0.1:' . $port;
$serverPid = null;

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

$emptyUser = ve_create_user('dmca_empty', 'dmca-empty@example.com', 'DmcaPass123');
dmca_assert((int) ($emptyUser['id'] ?? 0) > 0, 'Empty-state DMCA user should be created.');

$videoId = dmca_insert_ready_video($pdo, $userId, 'dmcacase0001', 'DMCA Fixture Clip');

$disabledNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $videoId,
    'case_code' => 'DMCA-QA-OPEN',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Studio Rights',
    'complainant_company' => 'Studio Rights LLC',
    'complainant_email' => 'legal@studio-rights.test',
    'claimed_work' => 'Fixture Clip',
    'reported_url' => ve_absolute_url('/d/dmcacase0001'),
    'work_reference_url' => 'https://rights.example.test/fixture-clip',
    'evidence_urls' => ['https://evidence.example.test/open'],
]);
dmca_force_notice_dates((int) ($disabledNotice['id'] ?? 0), gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s', time() - 86400));

$restoredNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $videoId,
    'case_code' => 'DMCA-QA-RESTORED',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Archive Label',
    'claimed_work' => 'Archive Film',
    'reported_url' => ve_absolute_url('/d/dmcacase0001'),
]);
$restoredNotice = ve_dmca_update_notice_status((int) ($restoredNotice['id'] ?? 0), VE_DMCA_NOTICE_STATUS_RESTORED, 'status_change', 'Content restored', 'Restored after review.');
dmca_force_notice_dates(
    (int) ($restoredNotice['id'] ?? 0),
    gmdate('Y-m-d H:i:s', strtotime('-20 days')),
    gmdate('Y-m-d H:i:s', strtotime('-20 days')),
    gmdate('Y-m-d H:i:s', strtotime('-18 days')),
    gmdate('Y-m-d H:i:s', strtotime('-18 days'))
);

$oldNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'video_id' => $videoId,
    'case_code' => 'DMCA-QA-OLD',
    'status' => VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED,
    'complainant_name' => 'Legacy Reporter',
    'claimed_work' => 'Legacy Film',
    'reported_url' => ve_absolute_url('/d/dmcacase0001'),
]);
$oldNotice = ve_dmca_update_notice_status((int) ($oldNotice['id'] ?? 0), VE_DMCA_NOTICE_STATUS_WITHDRAWN, 'status_change', 'Notice withdrawn', 'Withdrawn by complainant.');
dmca_force_notice_dates(
    (int) ($oldNotice['id'] ?? 0),
    gmdate('Y-m-d H:i:s', strtotime('-240 days')),
    gmdate('Y-m-d H:i:s', strtotime('-240 days')),
    gmdate('Y-m-d H:i:s', strtotime('-230 days')),
    gmdate('Y-m-d H:i:s', strtotime('-230 days'))
);

$pendingNotice = ve_dmca_create_notice([
    'user_id' => $userId,
    'case_code' => 'DMCA-QA-PENDING',
    'status' => VE_DMCA_NOTICE_STATUS_PENDING_REVIEW,
    'complainant_name' => 'Pending Rights',
    'claimed_work' => 'Pending Title',
    'reported_url' => 'https://mirror.example.test/pending',
]);

$video = ve_video_get_by_id($videoId);
dmca_assert(is_array($video), 'Fixture video should exist.');
dmca_assert((int) ($video['is_public'] ?? 1) === 0, 'Video should be disabled after an active DMCA notice.');

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
    $postLoginCsrf = dmca_extract_runtime_token($dmcaPage['body']);

    $snapshot = dmca_json($client->request('GET', '/api/dmca'));
    dmca_assert(($snapshot['status'] ?? null) === 'ok', 'DMCA snapshot should return status ok.');
    dmca_assert((int) (($snapshot['summary']['open_cases'] ?? 0)) === 2, 'DMCA snapshot should count open cases.');
    dmca_assert((int) (($snapshot['summary']['content_disabled'] ?? 0)) === 1, 'DMCA snapshot should count disabled cases.');
    dmca_assert((int) (($snapshot['summary']['effective_strikes'] ?? 0)) === 2, 'DMCA snapshot should count only in-window effective strikes.');
    dmca_assert(count((array) ($snapshot['items'] ?? [])) >= 3, 'DMCA list should include seeded cases.');

    $resolvedOnly = dmca_json($client->request('GET', '/api/dmca?status=resolved'));
    dmca_assert(count((array) ($resolvedOnly['items'] ?? [])) === 2, 'Resolved filter should return restored and withdrawn cases.');

    $detail = dmca_json($client->request('GET', '/api/dmca/DMCA-QA-OPEN'));
    dmca_assert(($detail['notice']['status'] ?? null) === VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED, 'DMCA detail should expose the open disabled case.');
    dmca_assert((bool) ($detail['notice']['can_submit_counter_notice'] ?? false) === true, 'Open disabled case should allow counter notices.');

    $counterResponse = dmca_json($client->request('POST', '/api/dmca/DMCA-QA-OPEN/counter-notice', [
        'form' => [
            'token' => $postLoginCsrf,
            'full_name' => 'Uploader QA',
            'email' => 'uploader@example.com',
            'phone' => '+1 555 111 2222',
            'address_line' => '123 QA Street',
            'city' => 'Testville',
            'country' => 'US',
            'postal_code' => '90210',
            'removed_material_location' => 'https://127.0.0.1/d/dmcacase0001',
            'mistake_statement' => 'I have a good-faith belief that the material was removed as a result of mistake or misidentification.',
            'jurisdiction_statement' => 'I consent to the jurisdiction of the appropriate Federal District Court and accept service of process.',
            'signature_name' => 'Uploader QA',
        ],
    ]));
    dmca_assert(($counterResponse['status'] ?? null) === 'ok', 'Counter notice submission should succeed.');
    dmca_assert(($counterResponse['notice']['status'] ?? null) === VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED, 'Counter notice should move the case into the pending restoration state.');
    dmca_assert(trim((string) ($counterResponse['notice']['restoration_earliest_at'] ?? '')) !== '', 'Counter notice should expose the restoration window.');

    $postCounter = dmca_json($client->request('GET', '/api/dmca/DMCA-QA-OPEN'));
    dmca_assert(($postCounter['notice']['counter_notice']['status'] ?? null) === VE_DMCA_COUNTER_STATUS_SUBMITTED, 'Counter notice detail should be stored.');
    dmca_assert((bool) ($postCounter['notice']['can_submit_counter_notice'] ?? true) === false, 'Counter notice should disable resubmission.');

    dmca_assert(str_contains($dmcaPage['body'], 'data-dmca-manager'), 'DMCA dashboard page should render the managed root.');
    dmca_assert(str_contains($dmcaPage['body'], '/assets/js/dashboard_dmca.js'), 'DMCA dashboard page should load the managed DMCA bundle.');

    echo "DMCA manager tests passed.\n";
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        exec('taskkill /F /PID ' . $serverPid . ' >NUL 2>NUL');
    }

    @unlink($cookiePath);
}
