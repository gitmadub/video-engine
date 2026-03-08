<?php

declare(strict_types=1);

error_reporting(E_ALL);

final class HttpClient
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
        $curl = curl_init($url);
        $headers = [];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);

                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            },
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);

        if (isset($options['headers']) && is_array($options['headers']) && $options['headers'] !== []) {
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

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function json_response(array $response): array
{
    $decoded = json_decode($response['body'], true);
    assert_true(is_array($decoded), 'Expected JSON response, got: ' . $response['body']);
    return $decoded;
}

function extract_runtime_token(string $html): string
{
    preg_match('/window\.VE_CSRF_TOKEN=("[^"]+"|\'[^\']+\')/i', $html, $matches);
    assert_true(isset($matches[1]), 'Unable to find runtime CSRF token.');
    $decoded = json_decode($matches[1], true);
    assert_true(is_string($decoded) && $decoded !== '', 'Runtime CSRF token was invalid.');
    return $decoded;
}

function wait_for_server(string $baseUrl, int $attempts = 50): void
{
    for ($i = 0; $i < $attempts; $i++) {
        $curl = curl_init($baseUrl . '/');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT_MS => 250,
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

function find_listening_pid(int $port): ?int
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

function seed_report_stats(string $dbPath, string $username): void
{
    $pdo = new PDO('sqlite:' . str_replace('\\', '/', $dbPath));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $userId = (int) $stmt->fetchColumn();
    assert_true($userId > 0, 'Unable to resolve the reports test user.');

    $rows = [
        ['2026-02-20', 120, 420000, 80000, 4 * 1024 * 1024 * 1024],
        ['2026-02-21', 75, 262500, 40000, 2 * 1024 * 1024 * 1024],
        ['2026-02-22', 5, 17500, 0, 512 * 1024 * 1024],
    ];
    $insert = $pdo->prepare(
        'INSERT INTO user_stats_daily (
            user_id, stat_date, views, earned_micro_usd, referral_earned_micro_usd, bandwidth_bytes, created_at, updated_at
         ) VALUES (
            :user_id, :stat_date, :views, :earned_micro_usd, :referral_earned_micro_usd, :bandwidth_bytes, :created_at, :updated_at
         )'
    );
    $now = gmdate('Y-m-d H:i:s');

    foreach ($rows as [$statDate, $views, $earnedMicroUsd, $referralMicroUsd, $bandwidthBytes]) {
        $insert->execute([
            ':user_id' => $userId,
            ':stat_date' => $statDate,
            ':views' => $views,
            ':earned_micro_usd' => $earnedMicroUsd,
            ':referral_earned_micro_usd' => $referralMicroUsd,
            ':bandwidth_bytes' => $bandwidthBytes,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function assert_reports_page_markup(string $html): void
{
    $textSample = preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';
    assert_true(str_contains($html, 'name="op" value="my_reports"'), 'Reports page should preserve the original reports form.');
    assert_true(str_contains($html, 'name="date1" id="date1" value="2026-02-20"'), 'Reports page should backfill the from date.');
    assert_true(str_contains($html, 'name="date2" id="date2" value="2026-02-22"'), 'Reports page should backfill the to date.');
    assert_true(str_contains($html, '<table id="datatable" class="table table-striped" data-page-length="31">'), 'Reports page should preserve the original reports table.');
    assert_true(str_contains($html, '$0.70000'), 'Reports page should render the view-profit total. Sample: ' . substr($textSample, 0, 1200));
    assert_true(str_contains($html, '$0.12000'), 'Reports page should render the referral-share total. Sample: ' . substr($textSample, 0, 1200));
    assert_true(str_contains($html, '$0.82000'), 'Reports page should render the combined total. Sample: ' . substr($textSample, 0, 1200));
    assert_true(str_contains($html, '6.5 GB'), 'Reports page should render traffic totals. Sample: ' . substr($textSample, 0, 1200));
    assert_true(str_contains($html, '2026-02-20'), 'Reports page should render seeded report rows.');
    assert_true(str_contains($html, '2026-02-22'), 'Reports page should render the full seeded range.');
    assert_true(str_contains($html, 'Morris.Line'), 'Reports page should initialize the chart from live data.');
    assert_true(!str_contains($html, 'data-dashboard-reports'), 'Reports page should not render the replacement reports shell.');
    assert_true(!str_contains($html, '/assets/js/dashboard_reports.js'), 'Reports page should not load the removed reports bundle.');
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reports-suite.sqlite';
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reports-suite.cookie';
$port = 18081;
$baseUrl = 'http://127.0.0.1:' . $port;
$serverPid = null;

@unlink($dbPath);
@unlink($cookiePath);

$env = array_merge($_ENV, [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'reports-suite-app-key',
]);

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        if (str_starts_with((string) $key, 'VE_')) {
            $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', (string) $value) . '" && ';
        }
    }

    $command = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($command, 'r'));
    wait_for_server($baseUrl);
    $serverPid = find_listening_pid($port);

    $client = new HttpClient($baseUrl, $cookiePath);
    $homePage = $client->request('GET', '/');
    assert_true($homePage['status'] === 200, 'Home page should load.');
    $csrf = extract_runtime_token($homePage['body']);

    $registration = json_response($client->request('POST', '/register', [
        'form' => [
            'usr_login' => 'reports_case',
            'usr_email' => 'reports@example.com',
            'usr_password' => 'Start123',
            'usr_password2' => 'Start123',
            'token' => $csrf,
        ],
    ]));
    assert_true(($registration['status'] ?? null) === 'ok', 'Reports test registration should succeed.');

    $login = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'reports@example.com',
            'password' => 'Start123',
            'token' => $csrf,
        ],
    ]));
    assert_true(($login['status'] ?? null) === 'redirect', 'Reports test login should succeed.');

    seed_report_stats($dbPath, 'reports_case');

    $apiResponse = json_response($client->request('GET', '/api/dashboard/reports?from=2026-02-20&to=2026-02-22', [
        'headers' => ['Accept' => 'application/json'],
    ]));
    assert_true(($apiResponse['status'] ?? null) === 'ok', 'Reports API should succeed.');
    assert_true((int) (($apiResponse['totals']['views'] ?? 0)) === 200, 'Reports API should total seeded views.');
    assert_true((int) (($apiResponse['totals']['profit_micro_usd'] ?? 0)) === 700000, 'Reports API should total seeded view profit.');
    assert_true((int) (($apiResponse['totals']['referral_share_micro_usd'] ?? 0)) === 120000, 'Reports API should total seeded referral revenue.');
    assert_true((int) (($apiResponse['totals']['total_micro_usd'] ?? 0)) === 820000, 'Reports API should expose the combined total.');
    assert_true(count((array) ($apiResponse['rows'] ?? [])) === 3, 'Reports API should return one row per seeded date.');

    $reportsPage = $client->request('GET', '/dashboard/reports?from=2026-02-20&to=2026-02-22');
    assert_true($reportsPage['status'] === 200, 'Reports page should load for the authenticated user.');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reports-page-debug.html', $reportsPage['body']);
    assert_reports_page_markup($reportsPage['body']);

    $legacyPageRedirect = $client->request('GET', '/reports');
    assert_true(in_array($legacyPageRedirect['status'], [301, 302], true), 'Legacy /reports route should redirect to the dashboard route.');
    assert_true(
        ($legacyPageRedirect['headers']['location'] ?? '') === '/dashboard/reports',
        'Legacy /reports route should resolve to the dashboard reports page.'
    );

    $legacyRedirect = $client->request('GET', '/?op=my_reports&date1=2026-02-20&date2=2026-02-22');
    assert_true(in_array($legacyRedirect['status'], [301, 302], true), 'Legacy my_reports requests should redirect.');
    assert_true(
        ($legacyRedirect['headers']['location'] ?? '') === '/dashboard/reports?from=2026-02-20&to=2026-02-22',
        'Legacy my_reports redirect should preserve the requested range.'
    );

    echo "Reports page tests passed.\n";
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        exec('taskkill /F /PID ' . $serverPid . ' >NUL 2>NUL');
    }

    @unlink($cookiePath);
}
