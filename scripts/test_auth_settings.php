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
        $url = $this->baseUrl . $path;
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

        if (isset($options['query']) && is_array($options['query']) && $options['query'] !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            curl_setopt($curl, CURLOPT_URL, $url . $separator . http_build_query($options['query']));
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

function extract_hidden_token(string $html): string
{
    preg_match('/<input type="hidden" name="token" value="([^"]+)"/i', $html, $matches);
    assert_true(isset($matches[1]), 'Unable to find CSRF token in settings page.');
    return $matches[1];
}

/**
 * @return string[]
 */
function extract_hidden_tokens(string $html): array
{
    preg_match_all('/<input type="hidden" name="token" value="([^"]+)"/i', $html, $matches);
    return array_values(array_filter($matches[1] ?? [], static fn ($value): bool => is_string($value) && $value !== ''));
}

function extract_api_key(string $html): string
{
    preg_match('/<label>API key<\/label>.*?<input[^>]*value="([^"]+)"/is', $html, $matches);
    assert_true(isset($matches[1]), 'Unable to find API key in settings page.');
    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function extract_runtime_token(string $html): string
{
    preg_match('/window\.VE_CSRF_TOKEN=("[^"]+"|\'[^\']+\')/i', $html, $matches);
    assert_true(isset($matches[1]), 'Unable to find runtime CSRF token.');
    $decoded = json_decode($matches[1], true);
    assert_true(is_string($decoded) && $decoded !== '', 'Runtime CSRF token was invalid.');
    return $decoded;
}

function assert_settings_panel_structure(string $html): void
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    assert_true($loaded, 'Settings HTML should be parseable.');

    $xpath = new DOMXPath($dom);
    $settingsData = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " details ") and contains(concat(" ", normalize-space(@class), " "), " settings_data ")]')->item(0);
    assert_true($settingsData instanceof DOMElement, 'Settings page should contain the settings data container.');

    foreach (['details', 'password_settings', 'email_settings', 'player_settings', 'premium_settings', 'ftp_servers', 'custom_domain', 'api_access', 'delete_account'] as $panelId) {
        $panel = $xpath->query('//*[@id="' . $panelId . '"]')->item(0);
        assert_true($panel instanceof DOMElement, 'Missing settings panel #' . $panelId . '.');
        assert_true(
            $panel->parentNode instanceof DOMNode && $panel->parentNode->isSameNode($settingsData),
            'Settings panel #' . $panelId . ' should remain a direct child of the settings container.'
        );
    }
}

function assert_settings_forms_bound(string $html): void
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    assert_true($loaded, 'Settings HTML should be parseable.');

    $xpath = new DOMXPath($dom);
    $expectedForms = [
        'details' => '/account/settings',
        'password_settings' => '/account/password',
        'email_settings' => '/account/email',
        'player_settings' => '/account/player',
        'premium_settings' => '/account/advertising',
        'api_access' => '/account/api-settings',
        'delete_account' => '/account/delete',
    ];

    foreach ($expectedForms as $panelId => $action) {
        $panel = $xpath->query('//*[@id="' . $panelId . '"]')->item(0);
        assert_true($panel instanceof DOMElement, 'Missing settings panel #' . $panelId . '.');

        $form = $xpath->query('.//form', $panel)->item(0);
        assert_true($form instanceof DOMElement, 'Settings panel #' . $panelId . ' should render a form.');
        assert_true(
            str_contains(' ' . trim((string) $form->getAttribute('class')) . ' ', ' js-settings-form '),
            'Settings panel #' . $panelId . ' should use the managed settings form class.'
        );
        assert_true($form->getAttribute('action') === $action, 'Settings panel #' . $panelId . ' should post to ' . $action . '.');

        $feedback = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " js-form-feedback ")]', $form)->item(0);
        assert_true($feedback instanceof DOMElement, 'Settings panel #' . $panelId . ' should render an in-form feedback alert.');
    }

    assert_true($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " js-panel-feedback ")]')->length === 0, 'Settings page should not render panel-level feedback alerts.');
}

function assert_settings_ajax_script_valid(string $html): void
{
    assert_true(
        str_contains($html, "document.addEventListener('submit'"),
        'Settings page should render the AJAX submit interceptor.'
    );
    assert_true(
        str_contains($html, "split(String.fromCharCode(92)).pop().split('/').pop();"),
        'Settings page should render a valid custom-file label script.'
    );
    assert_true(
        !str_contains($html, "split('\\').pop();"),
        'Settings page must not render broken custom-file JavaScript.'
    );
    assert_true(
        str_contains($html, "applyPlayerSnapshot(response.player)"),
        'Settings page should restore the canonical player snapshot after AJAX saves.'
    );
    assert_true(
        str_contains($html, "id=\"apiKeyModal\""),
        'Settings page should render the API key regeneration modal.'
    );
    assert_true(
        str_contains($html, "#confirmRegenerateApiKey"),
        'Settings page should render the managed API key rotation flow.'
    );
}

function assert_json_content_type(array $response, string $message): void
{
    $contentType = strtolower((string) ($response['headers']['content-type'] ?? ''));
    assert_true(str_contains($contentType, 'application/json'), $message . ' Expected JSON content type, got: ' . $contentType);
}

function create_test_png(string $path): void
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO1ZzWQAAAAASUVORK5CYII=', true);
    assert_true(is_string($png), 'Unable to create PNG fixture.');
    file_put_contents($path, $png);
}

function create_test_video(string $path): void
{
    $bytes = random_bytes(1024);
    file_put_contents($path, $bytes);
    assert_true(is_file($path) && filesize($path) > 0, 'Unable to create MP4 fixture.');
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

function set_test_user_plan(string $dbPath, string $username, string $planCode, ?string $premiumUntil): void
{
    $pdo = new PDO('sqlite:' . str_replace('\\', '/', $dbPath));
    $stmt = $pdo->prepare('UPDATE users SET plan_code = :plan_code, premium_until = :premium_until, updated_at = :updated_at WHERE username = :username');
    $stmt->execute([
        ':plan_code' => $planCode,
        ':premium_until' => $premiumUntil,
        ':updated_at' => gmdate('Y-m-d H:i:s'),
        ':username' => $username,
    ]);
    assert_true($stmt->rowCount() === 1, 'Unable to update the test user plan state.');
}

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$dbPath = getenv('VE_TEST_DB_PATH') ?: ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-suite.sqlite');
$cookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-suite.cookie';
$apiCookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-suite-api.cookie';
$resetCookiePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-suite-reset.cookie';
$logoPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-logo.png';
$videoPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test-video.mp4';
$port = 18080;
$baseUrl = getenv('VE_TEST_BASE_URL') ?: ('http://127.0.0.1:' . $port);
$managedServer = getenv('VE_TEST_BASE_URL') === false || getenv('VE_TEST_BASE_URL') === '';

@unlink($dbPath);
@unlink($cookiePath);
@unlink($apiCookiePath);
@unlink($resetCookiePath);
@unlink($logoPath);
@unlink($videoPath);

$env = array_merge($_ENV, [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'test-suite-app-key',
    'VE_CUSTOM_DOMAIN_TARGET' => '127.0.0.1',
    'VE_DNS_STATIC_MAP' => 'active.example.test=127.0.0.1;wrong.example.test=203.0.113.10',
]);
$expectedDomainTarget = $managedServer
    ? (string) ($env['VE_CUSTOM_DOMAIN_TARGET'] ?? '')
    : (string) (getenv('VE_CUSTOM_DOMAIN_TARGET') ?: '');
$serverPid = null;
$serverProcess = null;

try {
    if ($managedServer) {
        if (DIRECTORY_SEPARATOR === '\\') {
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
        } else {
            $devNull = '/dev/null';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $devNull, 'a'],
                2 => ['file', $devNull, 'a'],
            ];
            $serverProcess = proc_open([$php, '-S', '127.0.0.1:' . $port, 'router.php'], $descriptors, $pipes, $root, $env);

            if (!is_resource($serverProcess)) {
                throw new RuntimeException('Unable to start the built-in PHP server.');
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            wait_for_server($baseUrl);
            $status = proc_get_status($serverProcess);
            $serverPid = is_array($status) && isset($status['pid']) ? (int) $status['pid'] : find_listening_pid($port);
        }
    }

    $client = new HttpClient($baseUrl, $cookiePath);
    $apiClient = new HttpClient($baseUrl, $apiCookiePath);
    $resetClient = new HttpClient($baseUrl, $resetCookiePath);
    $ajaxHeaders = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    echo "server ready\n";

    $homePage = $client->request('GET', '/');
    assert_true($homePage['status'] === 200, 'Home page should load.');
    $publicCsrf = extract_runtime_token($homePage['body']);

    $registerGet = $client->request('GET', '/register');
    assert_true($registerGet['status'] === 405, 'GET /register should be rejected.');

    $legacyRegistration = $client->request('GET', '/?op=registration_ajax&usr_login=legacy&usr_email=legacy%40example.com&usr_password=Legacy123&usr_password2=Legacy123');
    assert_true($legacyRegistration['status'] === 410, 'Legacy registration endpoint should be disabled.');

    $response = $client->request('GET', '/dashboard/settings');
    assert_true(in_array($response['status'], [301, 302], true), 'Unauthenticated settings access should redirect.');

    $registration = json_response($client->request('POST', '/register', [
        'form' => [
            'usr_login' => 'alice_case',
            'usr_email' => 'alice@example.com',
            'usr_password' => 'Start123',
            'usr_password2' => 'Start123',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($registration['status'] ?? null) === 'ok', 'Registration should succeed.');
    echo "registration ok\n";

    $loginFail = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'alice_case',
            'password' => 'Wrong123',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($loginFail['status'] ?? null) === 'fail', 'Login with a wrong password should fail.');

    $login = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'alice_case',
            'password' => 'Start123',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($login['status'] ?? null) === 'redirect', 'Login should return a redirect payload.');
    echo "login ok\n";

    $settingsPage = $client->request('GET', '/dashboard/settings');
    assert_true($settingsPage['status'] === 200, 'Authenticated settings page should load.');
    assert_true(str_contains($settingsPage['body'], 'alice@example.com'), 'Settings page should render the current email.');
    assert_true(str_contains($settingsPage['body'], 'ftp.doodstream.com'), 'Settings page should render FTP servers from the database.');
    if ($expectedDomainTarget !== '') {
        assert_true(str_contains($settingsPage['body'], $expectedDomainTarget), 'Settings page should render the configured custom-domain DNS target.');
    }
    assert_true(str_contains($settingsPage['body'], '/premium-plans'), 'Settings page should use the dashboard premium-plans route.');
    assert_true(!str_contains($settingsPage['body'], 'href="/premium"'), 'Settings page should not use the guest premium route.');
    assert_true(str_contains($settingsPage['body'], '/account/api-settings'), 'Settings page should expose the API policy form.');
    assert_true(str_contains($settingsPage['body'], 'data-api-status'), 'Settings page should render the API usage summary.');
    assert_true(str_contains($settingsPage['body'], 'id="apiKeyModal"'), 'Settings page should render the API key rotation modal.');
    assert_settings_panel_structure($settingsPage['body']);
    assert_settings_forms_bound($settingsPage['body']);
    assert_settings_ajax_script_valid($settingsPage['body']);
    $runtimeCsrf = extract_runtime_token($settingsPage['body']);
    $hiddenTokens = extract_hidden_tokens($settingsPage['body']);
    assert_true(count($hiddenTokens) >= 6, 'Every settings form should render a hidden CSRF token.');
    foreach ($hiddenTokens as $hiddenToken) {
        assert_true($hiddenToken === $runtimeCsrf, 'Settings forms should use the current session CSRF token.');
    }
    assert_true(
        preg_match('/name="op" value="(?:my_account|my_password|my_email|premium_settings|api_settings)">\s*[A-Fa-f0-9]{32}">/i', $settingsPage['body']) !== 1,
        'CSRF tokens must never leak as visible plaintext in the settings DOM.'
    );
    $premiumPlansPage = $client->request('GET', '/premium-plans');
    assert_true($premiumPlansPage['status'] === 200, 'Premium plans page should load.');
    assert_true(str_contains($premiumPlansPage['body'], 'Choose the <strong>right</strong> plan for your account'), 'Premium plans page should render the premium hero content.');
    assert_true(str_contains($premiumPlansPage['body'], 'Premium account includes'), 'Premium plans page should render the included feature list.');
    $csrf = extract_hidden_token($settingsPage['body']);
    $oldApiKey = extract_api_key($settingsPage['body']);

    $accountSaveResponse = $client->request('POST', '/account/settings', [
        'form' => [
            'token' => $csrf,
            'usr_pay_type' => 'Bitcoin',
            'usr_pay_email' => 'wallet-123',
            'dood_ads_mode' => '3',
            'usr_content_type' => '2',
            'embed_domain_allowed' => 'alpha.example,beta.example,alpha.example',
            'usr_embed_access_only' => '1',
            'usr_disable_download' => '1',
            'usr_disable_adb' => '1',
            'usr_srt_burn' => '1',
        ],
    ]);
    assert_json_content_type($accountSaveResponse, 'Saving account settings should return JSON.');
    $accountSave = json_response($accountSaveResponse);
    assert_true(($accountSave['status'] ?? null) === 'ok', 'Saving account settings should return success JSON.');
    echo "account settings ok\n";

    create_test_png($logoPath);
    $playerColourOnlyResponse = $client->request('POST', '/account/player', [
        'form' => [
            'token' => $csrf,
            'logo_mode' => 'image',
            'usr_player_colour' => '00aa11',
        ],
    ]);
    assert_json_content_type($playerColourOnlyResponse, 'Saving gated player colour should still return JSON.');
    $playerColourOnly = json_response($playerColourOnlyResponse);
    assert_true(($playerColourOnly['status'] ?? null) === 'fail', 'Free accounts should not be able to save only the premium player colour.');
    assert_true(str_contains(strtolower((string) ($playerColourOnly['message'] ?? '')), 'premium'), 'Premium-only colour rejection should explain the entitlement requirement.');
    assert_true(($playerColourOnly['player']['player_colour'] ?? null) === 'ff9900', 'Rejected player colour saves should return the canonical persisted colour.');

    $playerSaveResponse = $client->request('POST', '/account/player', [
        'form' => [
            'token' => $csrf,
            'logo_mode' => 'image',
            'usr_embed_title' => '1',
            'usr_sub_auto_start' => '1',
            'usr_player_image' => 'single',
            'usr_player_colour' => '00aa11',
            'embedcode_width' => '720',
            'embedcode_height' => '405',
            'logo_image' => new CURLFile($logoPath, 'image/png', 'logo.png'),
        ],
    ]);
    assert_json_content_type($playerSaveResponse, 'Saving player settings should return JSON.');
    $playerSave = json_response($playerSaveResponse);
    assert_true(($playerSave['status'] ?? null) === 'warning', 'Free accounts should receive a warning when gated player colour is bundled with allowed player changes.');
    assert_true(($playerSave['player']['player_colour'] ?? null) === 'ff9900', 'Partial player updates must keep the previous premium-gated colour.');
    assert_true(($playerSave['player']['embed_width'] ?? null) === 720, 'Allowed player settings should still persist during partial saves.');

    $settingsAfterPartialPlayerSave = $client->request('GET', '/dashboard/settings');
    assert_true(str_contains($settingsAfterPartialPlayerSave['body'], 'value="720"'), 'Partial player saves should keep the updated embed width.');
    assert_true(str_contains($settingsAfterPartialPlayerSave['body'], 'value="405"'), 'Partial player saves should keep the updated embed height.');
    assert_true(str_contains($settingsAfterPartialPlayerSave['body'], 'value="ff9900"'), 'Partial player saves should restore the persisted player colour in the rendered settings page.');

    set_test_user_plan($dbPath, 'alice_case', 'premium', gmdate('Y-m-d H:i:s', time() + 86400 * 30));
    $csrf = extract_hidden_token($settingsAfterPartialPlayerSave['body']);

    $premiumPlayerSaveResponse = $client->request('POST', '/account/player', [
        'form' => [
            'token' => $csrf,
            'logo_mode' => 'image',
            'usr_player_colour' => '00aa11',
        ],
    ]);
    assert_json_content_type($premiumPlayerSaveResponse, 'Premium player colour saves should return JSON.');
    $premiumPlayerSave = json_response($premiumPlayerSaveResponse);
    assert_true(($premiumPlayerSave['status'] ?? null) === 'ok', 'Premium accounts should be able to save the player colour.');
    assert_true(($premiumPlayerSave['player']['player_colour'] ?? null) === '00aa11', 'Premium player colour saves should persist the chosen colour.');
    echo "player settings ok\n";

    $adsSaveResponse = $client->request('POST', '/account/advertising', [
        'form' => [
            'token' => $csrf,
            'vast_url' => 'https://ads.example.com/vast.xml',
            'pop_type' => '2',
            'pop_url' => 'https://ads.example.com/popup.js',
            'pop_cap' => '45',
        ],
    ]);
    assert_json_content_type($adsSaveResponse, 'Saving advert settings should return JSON.');
    $adsSave = json_response($adsSaveResponse);
    assert_true(($adsSave['status'] ?? null) === 'ok', 'Saving advert settings should return success JSON.');
    echo "ad settings ok\n";

    $passwordSaveResponse = $client->request('POST', '/account/password', [
        'form' => [
            'token' => $csrf,
            'password_current' => 'Start123',
            'password_new' => 'NewPass456',
            'password_new2' => 'NewPass456',
        ],
    ]);
    assert_json_content_type($passwordSaveResponse, 'Changing password should return JSON.');
    $passwordSave = json_response($passwordSaveResponse);
    assert_true(($passwordSave['status'] ?? null) === 'ok', 'Changing password should return success JSON.');
    echo "password change ok\n";

    $emailSaveResponse = $client->request('POST', '/account/email', [
        'form' => [
            'token' => $csrf,
            'usr_email' => 'alice+updated@example.com',
            'usr_email2' => 'alice+updated@example.com',
        ],
    ]);
    assert_json_content_type($emailSaveResponse, 'Changing email should return JSON.');
    $emailSave = json_response($emailSaveResponse);
    assert_true(($emailSave['status'] ?? null) === 'ok', 'Changing email should return success JSON.');
    echo "email change ok\n";

    $settingsPage = $client->request('GET', '/dashboard/settings');
    assert_true(str_contains($settingsPage['body'], 'alice+updated@example.com'), 'Updated email should render on the settings page.');
    assert_true(str_contains($settingsPage['body'], 'wallet-123'), 'Updated payment ID should render on the settings page.');
    assert_true(str_contains($settingsPage['body'], 'value="Bitcoin" selected="selected"'), 'Updated payment method should remain selected.');
    assert_true(str_contains($settingsPage['body'], 'value="720"'), 'Updated embed width should render.');
    assert_true(str_contains($settingsPage['body'], 'value="405"'), 'Updated embed height should render.');
    assert_true(str_contains($settingsPage['body'], 'value="00aa11"'), 'Premium player colour should render after a successful premium save.');
    assert_true(str_contains($settingsPage['body'], 'https://ads.example.com/vast.xml'), 'Updated VAST URL should render.');
    assert_true(str_contains($settingsPage['body'], 'https://ads.example.com/popup.js'), 'Updated popup URL should render.');
    assert_settings_panel_structure($settingsPage['body']);
    assert_settings_forms_bound($settingsPage['body']);
    assert_settings_ajax_script_valid($settingsPage['body']);
    $csrf = extract_hidden_token($settingsPage['body']);

    $apiRotateResponse = $client->request('POST', '/account/api-key/regenerate', [
        'form' => [
            'token' => $csrf,
        ],
    ]);
    assert_json_content_type($apiRotateResponse, 'API key regeneration should return JSON.');
    $apiRotate = json_response($apiRotateResponse);
    assert_true(($apiRotate['status'] ?? null) === 'ok', 'API key regeneration should return success JSON.');
    assert_true(is_string($apiRotate['api_key'] ?? null) && $apiRotate['api_key'] !== '', 'API key regeneration should return a new API key.');

    $settingsAfterApiRotate = $client->request('GET', '/dashboard/settings');
    $newApiKey = extract_api_key($settingsAfterApiRotate['body']);
    assert_true($newApiKey !== $oldApiKey, 'API key should change after regeneration.');
    assert_true($newApiKey === $apiRotate['api_key'], 'Rendered API key should match the JSON response.');
    $csrf = extract_hidden_token($settingsAfterApiRotate['body']);
    echo "api rotate ok\n";

    $apiUsageInitial = json_response($client->request('GET', '/api/account/api-usage', [
        'headers' => $ajaxHeaders,
    ]));
    assert_true(($apiUsageInitial['status'] ?? null) === 'ok', 'API usage endpoint should return success JSON.');
    assert_true(($apiUsageInitial['api']['limits']['requests_per_hour'] ?? null) === 250, 'API usage should expose the default hourly limit.');

    create_test_video($videoPath);
    $apiHeaders = [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $newApiKey,
    ];

    $apiList = json_response($apiClient->request('GET', '/api/videos', [
        'headers' => $apiHeaders,
    ]));
    assert_true(($apiList['status'] ?? null) === 'ok', 'API key should authorize video list requests.');
    assert_true(($apiList['capabilities']['processing_available'] ?? null) === true, 'Video API should report processing availability in the test environment.');

    $apiUpload = json_response($apiClient->request('POST', '/api/videos/upload', [
        'headers' => $apiHeaders,
        'form' => [
            'title' => 'API Upload Test',
            'video' => new CURLFile($videoPath, 'video/mp4', 'api-test.mp4'),
        ],
    ]));
    assert_true(($apiUpload['status'] ?? null) === 'ok', 'API key should authorize video uploads.');
    $uploadedPublicId = (string) ($apiUpload['video']['public_id'] ?? '');
    assert_true($uploadedPublicId !== '', 'API upload should return a public video id.');

    $apiDelete = json_response($apiClient->request('DELETE', '/api/videos/' . rawurlencode($uploadedPublicId), [
        'headers' => $apiHeaders,
    ]));
    assert_true(($apiDelete['status'] ?? null) === 'ok', 'API key should authorize video deletion.');

    $apiUsageAfterTraffic = json_response($client->request('GET', '/api/account/api-usage', [
        'headers' => $ajaxHeaders,
    ]));
    assert_true(($apiUsageAfterTraffic['api']['usage']['requests_last_hour'] ?? 0) >= 3, 'API usage should track external API requests.');
    assert_true(($apiUsageAfterTraffic['api']['usage']['uploads_today'] ?? 0) >= 1, 'API usage should track API uploads.');
    assert_true(count($apiUsageAfterTraffic['api']['recent_activity'] ?? []) >= 3, 'API usage should expose recent activity.');

    $requestsLastHour = (int) ($apiUsageAfterTraffic['api']['usage']['requests_last_hour'] ?? 0);
    $apiPolicyResponse = $client->request('POST', '/account/api-settings', [
        'form' => [
            'token' => $csrf,
            'api_enabled' => '1',
            'api_requests_per_hour' => (string) ($requestsLastHour + 1),
            'api_requests_per_day' => (string) ($requestsLastHour + 3),
            'api_uploads_per_day' => '1',
        ],
    ]);
    assert_json_content_type($apiPolicyResponse, 'Saving API policy should return JSON.');
    $apiPolicy = json_response($apiPolicyResponse);
    assert_true(($apiPolicy['status'] ?? null) === 'ok', 'Saving API policy should return success JSON.');
    assert_true(($apiPolicy['api']['limits']['uploads_per_day'] ?? null) === 1, 'API policy response should reflect the saved upload limit.');

    $limitAllowed = $apiClient->request('GET', '/api/videos', [
        'headers' => $apiHeaders,
    ]);
    $limitDenied = $apiClient->request('GET', '/api/videos', [
        'headers' => $apiHeaders,
    ]);
    assert_true($limitAllowed['status'] === 200, 'One request should still fit under the updated hourly limit.');
    assert_true($limitDenied['status'] === 429, 'Hourly API limits should block excess requests.');

    $uploadOnlyPolicy = json_response($client->request('POST', '/account/api-settings', [
        'headers' => array_merge($ajaxHeaders, [
            'X-CSRF-Token' => $csrf,
        ]),
        'form' => [
            'token' => $csrf,
            'api_enabled' => '1',
            'api_requests_per_hour' => '50',
            'api_requests_per_day' => '200',
            'api_uploads_per_day' => '1',
        ],
    ]));
    assert_true(($uploadOnlyPolicy['status'] ?? null) === 'ok', 'API policy should allow resetting request limits independently of upload limits.');

    $uploadLimitDenied = $apiClient->request('POST', '/api/videos/upload', [
        'headers' => $apiHeaders,
        'form' => [
            'title' => 'Upload Limit Test',
            'video' => new CURLFile($videoPath, 'video/mp4', 'api-limit.mp4'),
        ],
    ]);
    assert_true($uploadLimitDenied['status'] === 429, 'Daily API upload limits should block additional uploads.');

    $disableApi = json_response($client->request('POST', '/account/api-settings', [
        'headers' => array_merge($ajaxHeaders, [
            'X-CSRF-Token' => $csrf,
        ]),
        'form' => [
            'token' => $csrf,
            'api_requests_per_hour' => '10',
            'api_requests_per_day' => '25',
            'api_uploads_per_day' => '5',
        ],
    ]));
    assert_true(($disableApi['status'] ?? null) === 'ok' && ($disableApi['api']['enabled'] ?? true) === false, 'API access should be disableable from settings.');
    $disabledApiCall = $apiClient->request('GET', '/api/videos', [
        'headers' => $apiHeaders,
    ]);
    assert_true($disabledApiCall['status'] === 403, 'Disabled API access should reject API key requests.');

    $reenableApi = json_response($client->request('POST', '/account/api-settings', [
        'headers' => array_merge($ajaxHeaders, [
            'X-CSRF-Token' => $csrf,
        ]),
        'form' => [
            'token' => $csrf,
            'api_enabled' => '1',
            'api_requests_per_hour' => '50',
            'api_requests_per_day' => '200',
            'api_uploads_per_day' => '5',
        ],
    ]));
    assert_true(($reenableApi['status'] ?? null) === 'ok' && ($reenableApi['api']['enabled'] ?? false) === true, 'API access should be re-enableable from settings.');

    $oldKeyDenied = $apiClient->request('GET', '/api/videos', [
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $oldApiKey,
        ],
    ]);
    $newKeyAllowed = $apiClient->request('GET', '/api/videos', [
        'headers' => $apiHeaders,
    ]);
    assert_true($oldKeyDenied['status'] === 401, 'Regenerating the API key should invalidate the previous key immediately.');
    assert_true($newKeyAllowed['status'] === 200, 'The regenerated API key should remain usable after policy updates.');
    echo "api usage ok\n";

    $wrongDomainAdd = $client->request('POST', '/api/domains', [
        'form' => [
            'token' => $csrf,
            'domain' => 'wrong.example.test',
        ],
    ]);
    assert_json_content_type($wrongDomainAdd, 'Custom domain validation failures should return JSON.');
    $wrongDomainPayload = json_response($wrongDomainAdd);
    assert_true(($wrongDomainPayload['status'] ?? null) === 'fail', 'Domains that point to the wrong IP should be rejected.');
    assert_true(str_contains(strtolower((string) ($wrongDomainPayload['message'] ?? '')), '203.0.113.10'), 'Wrong-IP domain errors should mention the currently resolved A record.');

    $domainAdd = json_response($client->request('POST', '/api/domains', [
        'form' => [
            'token' => $csrf,
            'domain' => 'active.example.test',
        ],
    ]));
    assert_true(($domainAdd['status'] ?? null) === 'ok', 'Custom domain add should succeed once DNS points to the required target.');
    assert_true(count($domainAdd['domains'] ?? []) === 1, 'Custom domain list should contain the added domain.');

    $domainDelete = json_response($client->request('DELETE', '/api/domains/active.example.test', [
        'headers' => [
            'X-CSRF-Token' => $csrf,
        ],
    ]));
    assert_true(($domainDelete['status'] ?? null) === 'ok', 'Custom domain delete should succeed.');
    assert_true(count($domainDelete['domains'] ?? []) === 0, 'Custom domain list should be empty after deletion.');
    echo "domains ok\n";

    $notifications = json_response($client->request('GET', '/api/notifications'));
    assert_true(count($notifications) >= 5, 'Notifications should be generated for account actions.');

    $legacyNotifications = $client->request('GET', '/?op=notifications');
    assert_true($legacyNotifications['status'] === 410, 'Legacy notifications endpoint should be disabled.');

    $notificationId = (int) $notifications[0]['id'];
    $markNotification = json_response($client->request('POST', '/api/notifications/' . (int) $notifications[0]['id'] . '/read', [
        'form' => [
            'token' => $csrf,
        ],
    ]));
    assert_true(($markNotification['status'] ?? null) === 'ok', 'Notification read endpoint should succeed.');

    $notificationsAfterRead = json_response($client->request('GET', '/api/notifications'));
    $markedNotification = null;

    foreach ($notificationsAfterRead as $notification) {
        if ((int) ($notification['id'] ?? 0) === $notificationId) {
            $markedNotification = $notification;
            break;
        }
    }

    assert_true(is_array($markedNotification) && (int) ($markedNotification['read'] ?? 0) === 1, 'Notification should remain marked as read.');

    $deleteNotification = json_response($client->request('DELETE', '/api/notifications/' . $notificationId, [
        'headers' => [
            'X-CSRF-Token' => $csrf,
        ],
    ]));
    assert_true(($deleteNotification['status'] ?? null) === 'ok', 'Notification delete endpoint should succeed.');

    $notificationsAfterDelete = json_response($client->request('GET', '/api/notifications'));
    foreach ($notificationsAfterDelete as $notification) {
        assert_true((int) ($notification['id'] ?? 0) !== $notificationId, 'Deleted notification should disappear from the list.');
    }

    $clearNotifications = json_response($client->request('DELETE', '/api/notifications', [
        'headers' => [
            'X-CSRF-Token' => $csrf,
        ],
    ]));
    assert_true(($clearNotifications['status'] ?? null) === 'ok', 'Clear-all notifications endpoint should succeed.');
    assert_true(count(json_response($client->request('GET', '/api/notifications'))) === 0, 'All notifications should be removable.');

    $client->request('POST', '/logout', [
        'form' => [
            'token' => $csrf,
        ],
    ]);

    $homePage = $client->request('GET', '/');
    $publicCsrf = extract_runtime_token($homePage['body']);

    $oldPasswordLogin = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'alice_case',
            'password' => 'Start123',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($oldPasswordLogin['status'] ?? null) === 'fail', 'Old password should stop working after a password change.');

    $newPasswordLogin = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'alice+updated@example.com',
            'password' => 'NewPass456',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($newPasswordLogin['status'] ?? null) === 'redirect', 'New password should log in successfully.');
    echo "password relogin ok\n";

    $resetRegistration = json_response($resetClient->request('POST', '/register', [
        'form' => [
            'usr_login' => 'reset_case',
            'usr_email' => 'reset@example.com',
            'usr_password' => 'Reset123',
            'usr_password2' => 'Reset123',
            'token' => extract_runtime_token($resetClient->request('GET', '/')['body']),
        ],
    ]));
    assert_true(($resetRegistration['status'] ?? null) === 'ok', 'Reset-case registration should succeed.');

    $resetPublicCsrf = extract_runtime_token($resetClient->request('GET', '/')['body']);
    $resetRequest = json_response($resetClient->request('POST', '/password/forgot', [
        'form' => [
            'usr_login' => 'reset_case',
            'token' => $resetPublicCsrf,
        ],
    ]));
    assert_true(($resetRequest['status'] ?? null) === 'ok', 'Forgot-password request should succeed.');
    preg_match('/reset-password\?token=([^"&]+)/', (string) ($resetRequest['message'] ?? ''), $resetMatches);
    assert_true(isset($resetMatches[1]), 'Forgot-password response should include a reset token.');
    $resetToken = html_entity_decode($resetMatches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $resetPage = $resetClient->request('GET', '/reset-password?token=' . urlencode($resetToken));
    assert_true($resetPage['status'] === 200, 'Reset password page should load.');
    assert_true(str_contains($resetPage['body'], 'name="sess_id" value="' . $resetToken . '"'), 'Reset page should include the token.');
    $resetPageCsrf = extract_runtime_token($resetPage['body']);

    $resetSave = json_response($resetClient->request('POST', '/password/reset', [
        'form' => [
            'sess_id' => $resetToken,
            'password' => 'Reset456',
            'password2' => 'Reset456',
            'token' => $resetPageCsrf,
        ],
    ]));
    assert_true(($resetSave['status'] ?? null) === 'ok', 'Password reset should complete successfully.');

    $resetPublicCsrf = extract_runtime_token($resetClient->request('GET', '/')['body']);
    $resetLogin = json_response($resetClient->request('POST', '/login', [
        'form' => [
            'login' => 'reset_case',
            'password' => 'Reset456',
            'token' => $resetPublicCsrf,
        ],
    ]));
    assert_true(($resetLogin['status'] ?? null) === 'redirect', 'Reset user should log in with the new password.');
    echo "reset flow ok\n";

    $aliceSettings = $client->request('GET', '/dashboard/settings');
    $csrf = extract_hidden_token($aliceSettings['body']);
    $deleteAccountResponse = $client->request('POST', '/account/delete', [
        'form' => [
            'token' => $csrf,
            'reason_code' => 'other',
            'reason' => 'End-to-end test cleanup',
            'password_confirmation' => 'NewPass456',
        ],
    ]);
    assert_json_content_type($deleteAccountResponse, 'Deleting the account should return JSON.');
    $deleteAccount = json_response($deleteAccountResponse);
    assert_true(($deleteAccount['status'] ?? null) === 'redirect', 'Delete-account endpoint should return a redirect payload.');
    echo "delete account ok\n";

    $publicCsrf = extract_runtime_token($client->request('GET', '/')['body']);
    $deletedLogin = json_response($client->request('POST', '/login', [
        'form' => [
            'login' => 'alice_case',
            'password' => 'NewPass456',
            'token' => $publicCsrf,
        ],
    ]));
    assert_true(($deletedLogin['status'] ?? null) === 'fail', 'Deleted account should no longer log in.');

    $pdo = new PDO('sqlite:' . str_replace('\\', '/', $dbPath));
    $row = $pdo->query("SELECT status, deleted_at FROM users WHERE username LIKE 'deleted_%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($row) && $row['status'] === 'deleted' && $row['deleted_at'] !== null, 'Deleted account should be scrubbed in the database.');

    $settingsRow = $pdo->query("SELECT logo_path FROM user_settings ORDER BY user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($settingsRow) && is_string($settingsRow['logo_path']) && $settingsRow['logo_path'] !== '', 'Logo path should persist after player settings save.');
    assert_true(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $settingsRow['logo_path'])), 'Uploaded logo file should exist on disk.');

    echo "All auth/settings tests passed.\n";
} finally {
    if ($managedServer) {
        if (is_resource($serverProcess)) {
            proc_terminate($serverProcess);
            proc_close($serverProcess);
        }

        $pid = $serverPid;

        if ((!is_int($pid) || $pid <= 0) && DIRECTORY_SEPARATOR === '\\') {
            $pid = find_listening_pid($port);
        }

        if (is_int($pid) && $pid > 0 && DIRECTORY_SEPARATOR === '\\') {
            exec('taskkill /F /PID ' . $pid . ' >NUL 2>NUL');
        }
    }

    @unlink($cookiePath);
    @unlink($apiCookiePath);
    @unlink($resetCookiePath);
    @unlink($logoPath);
    @unlink($videoPath);
}
