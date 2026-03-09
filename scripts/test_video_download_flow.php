<?php

declare(strict_types=1);

error_reporting(E_ALL);

function video_download_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_download_wait_for_server(string $baseUrl, int $attempts = 50): void
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

    throw new RuntimeException('Video download QA server did not start in time.');
}

function video_download_find_listening_pid(int $port): ?int
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

$root = dirname(__DIR__);
$php = 'C:\\xampp\\php\\php.exe';
$node = 'node';
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'video-download-browser.sqlite';
$port = 18084;
$baseUrl = 'http://127.0.0.1:' . $port;

@unlink($dbPath);

$env = [
    'VE_DB_DSN' => 'sqlite:' . str_replace('\\', '/', $dbPath),
    'VE_APP_KEY' => 'video-download-browser-app-key',
    'VE_VIDEO_DOWNLOAD_WAIT_FREE' => '1',
    'VE_VIDEO_DOWNLOAD_WAIT_PREMIUM' => '0',
    'VE_VIDEO_PAYABLE_MIN_WATCH_SECONDS' => '5',
    'VE_VIDEO_PAYABLE_MAX_VIEWS_PER_VIEWER_PER_DAY' => '1',
];

$serverPid = null;

try {
    $envPrefix = '';

    foreach ($env as $key => $value) {
        $envPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $serverCommand = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && start "" /b "' . $php . '" -S 127.0.0.1:' . $port . ' router.php"';
    pclose(popen($serverCommand, 'r'));
    video_download_wait_for_server($baseUrl);
    $serverPid = video_download_find_listening_pid($port);

    $fixtureCommand = 'cmd /c "' . $envPrefix . 'cd /d "' . $root . '" && "' . $php . '" scripts\\prepare_video_download_fixture.php "' . $baseUrl . '""';
    $fixtureOutput = shell_exec($fixtureCommand);
    video_download_assert(is_string($fixtureOutput) && trim($fixtureOutput) !== '', 'Fixture preparation did not return JSON output.');

    $fixture = json_decode($fixtureOutput, true);
    video_download_assert(is_array($fixture), 'Fixture preparation returned invalid JSON: ' . $fixtureOutput);

    $browserEnv = [
        'VIDEO_DOWNLOAD_BROWSER_BASE_URL' => $baseUrl,
        'VIDEO_DOWNLOAD_BROWSER_WATCH_URL' => (string) ($fixture['watch_url'] ?? ''),
        'VIDEO_DOWNLOAD_BROWSER_EMBED_URL' => (string) ($fixture['embed_url'] ?? ''),
        'VIDEO_DOWNLOAD_BROWSER_USER' => (string) ($fixture['username'] ?? ''),
        'VIDEO_DOWNLOAD_BROWSER_PASSWORD' => (string) ($fixture['password'] ?? ''),
        'VIDEO_DOWNLOAD_BROWSER_PUBLIC_ID' => (string) ($fixture['public_id'] ?? ''),
        'VIDEO_DOWNLOAD_BROWSER_WAIT_FREE' => (string) ($fixture['download_wait_free'] ?? '1'),
        'VIDEO_DOWNLOAD_BROWSER_WAIT_PREMIUM' => (string) ($fixture['download_wait_premium'] ?? '0'),
        'VIDEO_DOWNLOAD_BROWSER_MIN_WATCH_SECONDS' => '5',
    ];

    foreach ($browserEnv as $key => $value) {
        video_download_assert($value !== '', 'Missing browser QA environment value for ' . $key . '.');
    }

    $browserPrefix = '';

    foreach ($browserEnv as $key => $value) {
        $browserPrefix .= 'set "' . $key . '=' . str_replace('"', '""', $value) . '" && ';
    }

    $browserCommand = 'cmd /c "' . $browserPrefix . 'cd /d "' . $root . '" && ' . $node . ' scripts\\test_video_download_flow.js"';
    passthru($browserCommand, $exitCode);
    video_download_assert($exitCode === 0, 'Playwright video download QA failed.');
} finally {
    if (is_int($serverPid) && $serverPid > 0) {
        shell_exec('taskkill /PID ' . $serverPid . ' /T /F >NUL 2>NUL');
    }
}

echo "video download flow qa ok\n";
