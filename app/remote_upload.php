<?php

declare(strict_types=1);

const VE_REMOTE_STATUS_PENDING = 'pending';
const VE_REMOTE_STATUS_RESOLVING = 'resolving';
const VE_REMOTE_STATUS_DOWNLOADING = 'downloading';
const VE_REMOTE_STATUS_IMPORTING = 'importing';
const VE_REMOTE_STATUS_COMPLETE = 'complete';
const VE_REMOTE_STATUS_ERROR = 'error';

function ve_remote_storage_path(string ...$parts): string
{
    return ve_storage_path('private', 'remote_uploads', ...$parts);
}

function ve_remote_processing_lock_path(): string
{
    return ve_remote_storage_path('processor.lock');
}

function ve_remote_job_directory(int $jobId): string
{
    return ve_remote_storage_path('jobs', (string) $jobId);
}

function ve_remote_config(): array
{
    static $config;

    if (is_array($config)) {
        return $config;
    }

    ve_ensure_directory(ve_remote_storage_path());
    ve_ensure_directory(ve_remote_storage_path('jobs'));

    $windowsYtDlpCandidates = [];
    $windowsPythonCandidates = [];

    if (ve_video_is_windows()) {
        foreach (['313', '312', '311', '310', '39'] as $version) {
            $windowsYtDlpCandidates[] = 'C:\\Users\\User\\AppData\\Roaming\\Python\\Python' . $version . '\\Scripts\\yt-dlp.exe';
            $windowsPythonCandidates[] = 'C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python' . $version . '\\python.exe';
        }

        $windowsPythonCandidates[] = 'C:\\Users\\User\\AppData\\Local\\Microsoft\\WindowsApps\\python.exe';
        $windowsPythonCandidates[] = 'C:\\Windows\\py.exe';
    }

    $ytDlpBinary = ve_video_find_binary(array_filter([
        getenv('VE_YT_DLP_PATH') ?: null,
        ve_root_path('tools', 'yt-dlp', ve_video_is_windows() ? 'yt-dlp.exe' : 'yt-dlp'),
        ...$windowsYtDlpCandidates,
        'yt-dlp',
    ]));

    $pythonBinary = ve_video_find_binary(array_filter([
        getenv('VE_PYTHON_BINARY') ?: null,
        ...$windowsPythonCandidates,
        'py',
        'python3',
        'python',
    ]));

    $config = [
        'root' => ve_remote_storage_path(),
        'jobs_root' => ve_remote_storage_path('jobs'),
        'php_binary' => ve_video_find_php_binary(),
        'python_binary' => $pythonBinary,
        'yt_dlp_binary' => $ytDlpBinary,
        'max_queue_per_user' => ve_get_app_setting_int(
            'remote_max_queue_per_user',
            max(1, (int) (getenv('VE_REMOTE_MAX_QUEUE_PER_USER') ?: 25)),
            1,
            500
        ),
        'worker_stale_after' => max(900, (int) (getenv('VE_REMOTE_STALE_AFTER') ?: 7200)),
        'connect_timeout' => max(5, (int) (getenv('VE_REMOTE_CONNECT_TIMEOUT') ?: 20)),
        'request_timeout' => max(15, (int) (getenv('VE_REMOTE_REQUEST_TIMEOUT') ?: 60)),
        'download_timeout' => max(60, (int) (getenv('VE_REMOTE_DOWNLOAD_TIMEOUT') ?: 21600)),
        'max_redirects' => max(1, (int) (getenv('VE_REMOTE_MAX_REDIRECTS') ?: 10)),
        'user_agent' => trim((string) (getenv('VE_REMOTE_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36 VideoEngineRemote/1.0')),
    ];

    return $config;
}

function ve_remote_folder_options_for_user(int $userId, array $excludedFolderIds = []): array
{
    $options = [];
    $appendOptions = static function (array $folders, array $pathParts = []) use (&$appendOptions, &$options): void {
        foreach ($folders as $folder) {
            if (!is_array($folder)) {
                continue;
            }

            $folderId = (int) ($folder['id'] ?? 0);
            $folderName = trim((string) ($folder['name'] ?? ''));

            if ($folderId <= 0 || $folderName === '') {
                continue;
            }

            $currentPath = array_merge($pathParts, [$folderName]);
            $options[] = [
                'fld_id' => $folderId,
                'fld_name' => implode(' / ', $currentPath),
            ];

            $appendOptions((array) ($folder['sub_folders'] ?? []), $currentPath);
        }
    };

    $appendOptions(ve_video_folder_tree_for_user($userId, $excludedFolderIds));

    return $options;
}

function ve_remote_quality_options(): array
{
    return [2160, 1440, 1080, 720, 480, 360, 240];
}

function ve_remote_default_quality_height(): int
{
    $default = ve_get_app_setting_int('remote_default_quality', 1080, 240, 2160);

    if (in_array($default, ve_remote_quality_options(), true)) {
        return $default;
    }

    return 1080;
}

function ve_remote_yt_dlp_format_limit_clause(): string
{
    return '[height<=' . ve_remote_default_quality_height() . ']';
}

function ve_remote_yt_dlp_best_format(bool $preferSeparateStreams = false): string
{
    $limit = ve_remote_yt_dlp_format_limit_clause();

    if ($preferSeparateStreams) {
        return 'bv*' . $limit . '+ba'
            . '/b' . $limit
            . '/best' . $limit
            . '/bv*+ba'
            . '/b'
            . '/best';
    }

    return 'best' . $limit
        . '/b' . $limit
        . '/best';
}

function ve_remote_yt_dlp_mp4_format(bool $preferSeparateStreams = false): string
{
    return ve_remote_yt_dlp_best_format($preferSeparateStreams);
}

function ve_remote_yt_dlp_merge_output_format(): string
{
    return 'mkv';
}

function ve_remote_youtube_extractor_args_value(): string
{
    $value = ve_get_app_setting(
        'remote_youtube_extractor_args',
        (string) (getenv('VE_REMOTE_YOUTUBE_EXTRACTOR_ARGS') ?: 'youtube:player_client=default,mweb')
    );

    return trim((string) $value);
}

function ve_remote_ytdlp_plugin_dirs(): array
{
    $raw = ve_get_app_setting(
        'remote_ytdlp_plugin_dirs',
        (string) (getenv('VE_REMOTE_YTDLP_PLUGIN_DIRS') ?: '')
    );
    $raw = trim((string) $raw);

    if ($raw === '') {
        return [];
    }

    $pattern = ve_video_is_windows() ? '/[;\r\n]+/' : '/[:\r\n]+/';
    $dirs = preg_split($pattern, $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $unique = [];

    foreach ($dirs as $dir) {
        $dir = trim((string) $dir);

        if ($dir === '') {
            continue;
        }

        $unique[$dir] = $dir;
    }

    return array_values($unique);
}

function ve_remote_yt_dlp_plugin_args(): array
{
    $args = [];

    foreach (ve_remote_ytdlp_plugin_dirs() as $dir) {
        $args[] = '--plugin-dirs';
        $args[] = $dir;
    }

    return $args;
}

function ve_remote_yt_dlp_strip_plugin_args(array $arguments): array
{
    $stripped = [];
    $count = count($arguments);

    for ($index = 0; $index < $count; $index++) {
        if (($arguments[$index] ?? null) === '--plugin-dirs') {
            $index++;
            continue;
        }

        $stripped[] = $arguments[$index];
    }

    return $stripped;
}

function ve_remote_yt_dlp_disable_plugins(array $arguments): array
{
    $disabled = ve_remote_yt_dlp_strip_plugin_args($arguments);

    if (in_array('--no-plugin-dirs', $disabled, true)) {
        return $disabled;
    }

    $insertAt = 1;
    $second = strtolower((string) ($disabled[1] ?? ''));
    $third = strtolower((string) ($disabled[2] ?? ''));

    if ($second === '-m' && ($third === 'yt_dlp' || $third === 'yt-dlp')) {
        $insertAt = 3;
    }

    array_splice($disabled, $insertAt, 0, ['--no-plugin-dirs']);

    return $disabled;
}

function ve_remote_yt_dlp_plugin_is_incompatible_output(string $output): bool
{
    $normalized = strtolower(trim($output));

    if ($normalized === '') {
        return false;
    }

    return str_contains($normalized, "unexpected keyword argument 'once'")
        || str_contains($normalized, 'unexpected keyword argument "once"')
        || (
            str_contains($normalized, 'got an unexpected keyword argument')
            && str_contains($normalized, 'once')
        );
}

function ve_remote_yt_dlp_clean_output(string $output): string
{
    $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
    $cleaned = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '' || strtolower($line) === 'null') {
            continue;
        }

        $normalized = strtolower($line);

        if (
            str_starts_with($normalized, 'deprecated feature:')
            && str_contains($normalized, 'support for python version')
        ) {
            continue;
        }

        $cleaned[] = $line;
    }

    return trim(implode(' ', $cleaned));
}

function ve_remote_yt_dlp_run(array $arguments): array
{
    [$exitCode, $output] = ve_video_run_command($arguments);

    if ($exitCode === 0 || !ve_remote_yt_dlp_plugin_is_incompatible_output($output)) {
        return [$exitCode, $output];
    }

    $fallbackArguments = ve_remote_yt_dlp_disable_plugins($arguments);

    if ($fallbackArguments === $arguments) {
        return [$exitCode, $output];
    }

    [$fallbackExitCode, $fallbackOutput] = ve_video_run_command($fallbackArguments);

    if ($fallbackOutput === '') {
        return [$fallbackExitCode, $output];
    }

    return [$fallbackExitCode, $fallbackOutput];
}

function ve_remote_youtube_extractor_args(): array
{
    $value = ve_remote_youtube_extractor_args_value();

    return $value !== '' ? ['--extractor-args', $value] : [];
}

function ve_remote_ytdlp_cookies_browser(): string
{
    $value = ve_get_app_setting(
        'remote_ytdlp_cookies_browser',
        (string) (getenv('VE_REMOTE_YTDLP_COOKIES_FROM_BROWSER') ?: '')
    );

    return trim((string) $value);
}

function ve_remote_ytdlp_cookies_file(): string
{
    $value = ve_get_app_setting(
        'remote_ytdlp_cookies_file',
        (string) (getenv('VE_REMOTE_YTDLP_COOKIES_PATH') ?: '')
    );

    return trim((string) $value);
}

function ve_remote_yt_dlp_cookie_args(): array
{
    $cookieFile = ve_remote_ytdlp_cookies_file();

    if ($cookieFile !== '') {
        if (!is_file($cookieFile)) {
            throw new RuntimeException('The configured remote yt-dlp cookies file could not be found: ' . $cookieFile);
        }

        return ['--cookies', $cookieFile];
    }

    $cookieBrowser = ve_remote_ytdlp_cookies_browser();

    if ($cookieBrowser !== '') {
        return ['--cookies-from-browser', $cookieBrowser];
    }

    return [];
}

function ve_remote_yt_dlp_failure_message(string $action, string $output): string
{
    $trimmedOutput = ve_remote_yt_dlp_clean_output($output);
    $normalizedOutput = strtolower($trimmedOutput);

    $needsCookies = $normalizedOutput !== ''
        && (
            str_contains($normalizedOutput, 'sign in to confirm you')
            || str_contains($normalizedOutput, '--cookies-from-browser')
            || str_contains($normalizedOutput, '--cookies for the authentication')
            || str_contains($normalizedOutput, 'use --cookies')
        );

    if ($needsCookies) {
        $configuredSource = ve_remote_ytdlp_cookies_file() !== '' || ve_remote_ytdlp_cookies_browser() !== '';
        $message = $configuredSource
            ? 'yt-dlp could not ' . $action . ' the remote URL. The configured yt-dlp cookies were rejected by YouTube.'
            : 'yt-dlp could not ' . $action . ' the remote URL. YouTube now requires authenticated yt-dlp cookies on this server.';

        $message .= ' Configure "Remote yt-dlp cookies browser" or "Remote yt-dlp cookies file" in App Settings.';

        return $trimmedOutput !== '' ? $message . ' ' . $trimmedOutput : $message;
    }

    return $trimmedOutput !== ''
        ? 'yt-dlp could not ' . $action . ' the remote URL. ' . $trimmedOutput
        : 'yt-dlp could not ' . $action . ' the remote URL.';
}

function ve_remote_yt_dlp_command(): array
{
    $binary = (string) (ve_remote_config()['yt_dlp_binary'] ?? '');

    if ($binary !== '') {
        return [$binary];
    }

    $pythonBinary = (string) (ve_remote_config()['python_binary'] ?? '');

    if ($pythonBinary !== '') {
        $basename = strtolower(pathinfo($pythonBinary, PATHINFO_BASENAME));

        if ($basename === 'py.exe' || $basename === 'py') {
            return [$pythonBinary, '-m', 'yt_dlp'];
        }

        return [$pythonBinary, '-m', 'yt_dlp'];
    }

    return [];
}

function ve_remote_python_command(): array
{
    $binary = (string) (ve_remote_config()['python_binary'] ?? '');

    if ($binary === '') {
        return [];
    }

    $basename = strtolower(pathinfo($binary, PATHINFO_BASENAME));

    if ($basename === 'py.exe' || $basename === 'py') {
        return [$binary, '-3'];
    }

    return [$binary];
}

function ve_remote_command_output_last_json(string $output): ?array
{
    $output = trim($output);

    if ($output === '') {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];

    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $line = trim((string) $lines[$index]);

        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $decoded = json_decode($output, true);

    return is_array($decoded) ? $decoded : null;
}

function ve_remote_header_lines_from_assoc(array $headers): array
{
    $lines = [];

    foreach ($headers as $name => $value) {
        if (!is_string($name) || trim($name) === '') {
            continue;
        }

        if (is_array($value)) {
            $value = implode(', ', array_map(static fn ($item): string => trim((string) $item), $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            continue;
        }

        $lines[] = trim($name) . ': ' . $value;
    }

    return $lines;
}

function ve_remote_yt_dlp_extract_info(string $url, array $options = []): array
{
    $command = ve_remote_yt_dlp_command();

    if ($command === []) {
        throw new RuntimeException('yt-dlp is not available on this server.');
    }

    $args = array_merge($command, [
        '--ignore-config',
        '--skip-download',
        '--dump-single-json',
        '--no-warnings',
        '--no-playlist',
        '--socket-timeout',
        '30',
        '--user-agent',
        (string) ve_remote_config()['user_agent'],
    ]);
    $args = array_merge($args, ve_remote_yt_dlp_plugin_args());
    $args = array_merge($args, ve_remote_yt_dlp_cookie_args());

    $format = trim((string) ($options['format'] ?? ''));

    if ($format !== '') {
        $args[] = '-f';
        $args[] = $format;
    }

    if (($options['referer'] ?? '') !== '') {
        $args[] = '--referer';
        $args[] = (string) $options['referer'];
    }

    foreach (($options['extra_args'] ?? []) as $extraArg) {
        if (is_string($extraArg) && $extraArg !== '') {
            $args[] = $extraArg;
        }
    }

    $args[] = $url;

    [$exitCode, $output] = ve_remote_yt_dlp_run($args);
    $info = ve_remote_command_output_last_json($output);

    if ($exitCode !== 0 || !is_array($info)) {
        throw new RuntimeException(ve_remote_yt_dlp_failure_message('inspect', $output));
    }

    return $info;
}

function ve_remote_mega_helper_script(): string
{
    return ve_root_path('scripts', 'mega_helper.py');
}

function ve_remote_mega_info(string $url): array
{
    $command = ve_remote_python_command();

    if ($command === []) {
        throw new RuntimeException('Python is required for MEGA remote downloads.');
    }

    $script = ve_remote_mega_helper_script();

    if (!is_file($script)) {
        throw new RuntimeException('The MEGA helper script is missing from this installation.');
    }

    [$exitCode, $output] = ve_video_run_command(array_merge($command, [
        $script,
        'info',
        $url,
    ]));

    $info = ve_remote_command_output_last_json($output);

    if ($exitCode !== 0 || !is_array($info)) {
        throw new RuntimeException('MEGA link inspection failed. ' . trim($output));
    }

    return $info;
}

function ve_remote_require_auth_json(): array
{
    $user = ve_current_user();

    if (!is_array($user)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Please sign in to manage remote uploads.',
        ], 401);
    }

    return $user;
}

function ve_remote_hosts(): array
{
    static $hosts;

    if (is_array($hosts)) {
        return $hosts;
    }

    $hosts = [];

    foreach ([
        '1fichier.php',
        'fembed.php',
        'google_drive.php',
        'dropbox.php',
        'mega.php',
        'mixdrop.php',
        'myvidplay.php',
        'netu.php',
        'okru.php',
        'streamsb.php',
        'streamtape.php',
        'uploaded.php',
        'uptobox.php',
        'uptostream.php',
        'upstream.php',
        'vidi64.php',
        'videobin.php',
        'vidoza.php',
        'vidlox.php',
        'vivo.php',
        'xvideos.php',
        'youporn.php',
        'youtube.php',
        'zippyshare.php',
        'direct.php',
    ] as $file) {
        $host = require __DIR__ . '/remote_upload/hosts/' . $file;

        if (is_array($host)) {
            $key = strtolower(trim((string) ($host['key'] ?? '')));

            if ($key !== '') {
                $host = array_merge(ve_remote_host_metadata($key), $host);
            }

            $hosts[] = $host;
        }
    }

    return $hosts;
}

function ve_remote_is_http_url(string $url): bool
{
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

function ve_remote_url_host(string $url): string
{
    return strtolower((string) parse_url($url, PHP_URL_HOST));
}

function ve_remote_url_matches_host(string $url, array $hosts): bool
{
    $host = ve_remote_url_host($url);

    if ($host === '') {
        return false;
    }

    foreach ($hosts as $candidate) {
        $candidate = strtolower(trim($candidate));

        if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.' . $candidate))) {
            return true;
        }
    }

    return false;
}

function ve_remote_find_host_resolver(array $hosts, string $key): ?array
{
    $key = strtolower(trim($key));

    if ($key === '') {
        return null;
    }

    foreach ($hosts as $host) {
        if (strtolower(trim((string) ($host['key'] ?? ''))) === $key) {
            return $host;
        }
    }

    return null;
}

function ve_remote_absolute_url(string $baseUrl, string $location): string
{
    $location = trim($location);

    if ($location === '') {
        return $baseUrl;
    }

    if (preg_match('#^(?:https?:)?//#i', $location) === 1) {
        if (str_starts_with($location, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $location;
        }

        return $location;
    }

    $parts = parse_url($baseUrl);

    if (!is_array($parts)) {
        return $location;
    }

    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if ($host === '') {
        return $location;
    }

    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $port . $location;
    }

    $path = (string) ($parts['path'] ?? '/');
    $directory = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

    return $scheme . '://' . $host . $port . $directory . $location;
}

function ve_remote_origin_url(string $url): string
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return '';
    }

    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = trim((string) ($parts['host'] ?? ''));

    if ($host === '') {
        return '';
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $scheme . '://' . $host . $port . '/';
}

function ve_remote_sanitize_filename(string $filename, string $fallback = 'video.mp4'): string
{
    $filename = trim(str_replace(["\r", "\n", "\t"], ' ', $filename));
    $filename = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $filename) ?? '';
    $filename = preg_replace('/\s+/', ' ', $filename) ?? '';
    $filename = trim($filename, " .");

    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = $fallback;
    }

    return mb_substr($filename, 0, 220);
}

function ve_remote_filename_from_url(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $filename = basename($path);
    $filename = rawurldecode($filename);

    return ve_remote_sanitize_filename($filename, 'video.mp4');
}

function ve_remote_filename_from_content_disposition(string $header): string
{
    $header = trim($header);

    if ($header === '') {
        return '';
    }

    if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $header, $matches) === 1) {
        return ve_remote_sanitize_filename(rawurldecode(trim((string) $matches[1], "\"'")), 'video.mp4');
    }

    if (preg_match('/filename="?([^";]+)"?/i', $header, $matches) === 1) {
        return ve_remote_sanitize_filename(trim((string) $matches[1]), 'video.mp4');
    }

    return '';
}

function ve_remote_resolve_download_filename(array $source, string $downloadPath, string $fallback = 'video.mp4'): string
{
    $requested = trim((string) ($source['filename'] ?? ''));
    $actualName = basename($downloadPath);
    $actualExtension = strtolower(pathinfo($actualName, PATHINFO_EXTENSION));
    $requestedBase = $requested !== ''
        ? trim((string) pathinfo($requested, PATHINFO_FILENAME))
        : trim((string) pathinfo($actualName, PATHINFO_FILENAME));

    if ($requestedBase === '') {
        $requestedBase = trim((string) pathinfo($fallback, PATHINFO_FILENAME));
    }

    if ($requestedBase === '') {
        $requestedBase = 'video';
    }

    if ($actualExtension === '') {
        $actualExtension = strtolower(pathinfo($requested !== '' ? $requested : $fallback, PATHINFO_EXTENSION));
    }

    if ($actualExtension === '') {
        $actualExtension = strtolower(pathinfo($fallback, PATHINFO_EXTENSION));
    }

    if ($actualExtension === '') {
        $actualExtension = 'mp4';
    }

    return ve_remote_sanitize_filename($requestedBase . '.' . $actualExtension, $fallback);
}

function ve_remote_finalize_downloaded_file(array $source, string $downloadPath, string $fallback = 'video.mp4'): array
{
    $directory = dirname($downloadPath);
    $finalFilename = ve_remote_resolve_download_filename($source, $downloadPath, $fallback);
    $finalPath = $directory . DIRECTORY_SEPARATOR . $finalFilename;

    if (strcasecmp($downloadPath, $finalPath) !== 0) {
        @unlink($finalPath);

        if (!@rename($downloadPath, $finalPath)) {
            if (@copy($downloadPath, $finalPath)) {
                @unlink($downloadPath);
            } else {
                $finalPath = $downloadPath;
                $finalFilename = basename($downloadPath);
            }
        }
    }

    return [
        'path' => $finalPath,
        'filename' => $finalFilename,
    ];
}

function ve_remote_extension_from_content_type(string $contentType): string
{
    $contentType = strtolower(trim(strtok($contentType, ';') ?: $contentType));

    return match ($contentType) {
        'video/3gpp' => '3gp',
        'video/mp2t' => 'ts',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpeg',
        'video/ogg', 'application/ogg' => 'ogv',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-flv' => 'flv',
        'video/x-matroska' => 'mkv',
        'video/x-ms-asf' => 'asf',
        'video/x-msvideo' => 'avi',
        'video/x-ms-wmv' => 'wmv',
        default => '',
    };
}

function ve_remote_ensure_filename_extension(string $filename, string $contentType): string
{
    $filename = ve_remote_sanitize_filename($filename, 'video.mp4');

    if (pathinfo($filename, PATHINFO_EXTENSION) !== '') {
        return $filename;
    }

    $extension = ve_remote_extension_from_content_type($contentType);

    if ($extension === '') {
        return $filename;
    }

    return $filename . '.' . $extension;
}

function ve_remote_http_response_header(array $response, string $name): string
{
    $headers = $response['headers'] ?? [];
    $values = $headers[strtolower($name)] ?? [];

    if (!is_array($values) || $values === []) {
        return '';
    }

    return (string) end($values);
}

function ve_remote_cookie_header_from_response(array $response): string
{
    $headers = $response['headers'] ?? [];
    $cookies = [];

    foreach (($headers['set-cookie'] ?? []) as $line) {
        $pair = trim((string) explode(';', (string) $line, 2)[0]);

        if ($pair !== '' && str_contains($pair, '=')) {
            $cookies[] = $pair;
        }
    }

    return implode('; ', array_unique($cookies));
}

function ve_remote_merge_cookie_header(string $current, string $incoming): string
{
    $pairs = [];

    foreach ([$current, $incoming] as $header) {
        foreach (explode(';', (string) $header) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $pairs[$name] = $name . '=' . trim($value);
        }
    }

    return implode('; ', array_values($pairs));
}

function ve_remote_http_request(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for remote uploads.');
    }

    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $currentHeaders = [];
    $finalHeaders = [];
    $method = strtoupper((string) ($options['method'] ?? 'GET'));
    $headers = [];

    foreach (($options['headers'] ?? []) as $header) {
        if (is_string($header) && trim($header) !== '') {
            $headers[] = trim($header);
        }
    }

    $verifySsl = (bool) ($options['verify_ssl'] ?? true);

    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, (bool) ($options['follow_location'] ?? true));
    curl_setopt($handle, CURLOPT_MAXREDIRS, (int) ve_remote_config()['max_redirects']);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) ve_remote_config()['connect_timeout']);
    curl_setopt($handle, CURLOPT_TIMEOUT, (int) (($options['timeout'] ?? ve_remote_config()['request_timeout'])));
    curl_setopt($handle, CURLOPT_ENCODING, '');
    curl_setopt($handle, CURLOPT_USERAGENT, (string) ($options['user_agent'] ?? ve_remote_config()['user_agent']));
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
    curl_setopt($handle, CURLOPT_COOKIEFILE, '');
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$currentHeaders, &$finalHeaders): int {
        $trimmed = trim($line);

        if ($trimmed === '') {
            if ($currentHeaders !== []) {
                $finalHeaders = $currentHeaders;
                $currentHeaders = [];
            }

            return strlen($line);
        }

        if (!str_contains($line, ':')) {
            return strlen($line);
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);

        if ($name !== '') {
            $currentHeaders[$name] ??= [];
            $currentHeaders[$name][] = $value;
        }

        return strlen($line);
    });

    if (($options['referer'] ?? '') !== '') {
        curl_setopt($handle, CURLOPT_REFERER, (string) $options['referer']);
    }

    if (($options['cookie'] ?? '') !== '') {
        curl_setopt($handle, CURLOPT_COOKIE, (string) $options['cookie']);
    }

    if ($method === 'HEAD') {
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'HEAD');
    } else {
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
    }

    if (($options['range'] ?? '') !== '') {
        curl_setopt($handle, CURLOPT_RANGE, (string) $options['range']);
    }

    $body = curl_exec($handle);

    if ($body === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    if ($currentHeaders !== []) {
        $finalHeaders = $currentHeaders;
    }

    $response = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'body' => (string) $body,
        'effective_url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
        'content_type' => strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE)),
        'headers' => $finalHeaders,
    ];

    curl_close($handle);

    return $response;
}

function ve_remote_base_encode(int $value, int $base): string
{
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $base = max(2, min(62, $base));

    if ($value === 0) {
        return '0';
    }

    $encoded = '';

    while ($value > 0) {
        $encoded = $alphabet[$value % $base] . $encoded;
        $value = intdiv($value, $base);
    }

    return $encoded;
}

function ve_remote_unpack_packer_payload(string $payload, int $base, int $count, array $symbols): string
{
    if ($payload === '' || $base < 2 || $base > 62 || $count < 1) {
        return $payload;
    }

    for ($index = $count - 1; $index >= 0; $index--) {
        $replacement = (string) ($symbols[$index] ?? '');

        if ($replacement === '') {
            continue;
        }

        $token = ve_remote_base_encode($index, $base);
        $pattern = '/\b' . preg_quote($token, '/') . '\b/u';
        $payload = preg_replace_callback(
            $pattern,
            static fn (): string => $replacement,
            $payload
        ) ?? $payload;
    }

    return $payload;
}

function ve_remote_unpack_packer_blocks(string $html): string
{
    if (!str_contains($html, 'eval(function(p,a,c,k,e,d)')) {
        return '';
    }

    if (preg_match_all(
        "/eval\\(function\\(p,a,c,k,e,d\\)\\{.*?\\}\\(\\s*'((?:\\\\.|[^'])*)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'((?:\\\\.|[^'])*)'\\.split\\('\\|'\\)/is",
        $html,
        $matches,
        PREG_SET_ORDER
    ) !== 1 && ($matches === [])) {
        return '';
    }

    $blocks = [];

    foreach ($matches as $match) {
        $payload = stripcslashes((string) ($match[1] ?? ''));
        $base = (int) ($match[2] ?? 0);
        $count = (int) ($match[3] ?? 0);
        $symbols = explode('|', stripcslashes((string) ($match[4] ?? '')));
        $decoded = ve_remote_unpack_packer_payload($payload, $base, $count, $symbols);

        if (trim($decoded) !== '') {
            $blocks[] = $decoded;
        }
    }

    return implode("\n", $blocks);
}

function ve_remote_html_redirect_url(string $html, string $baseUrl): string
{
    $patterns = [
        '/window\.location\.replace\(\s*["\']([^"\']+)["\']\s*\)/i',
        '/location\.replace\(\s*["\']([^"\']+)["\']\s*\)/i',
        '/window\.location\.href\s*=\s*["\']([^"\']+)["\']/i',
        '/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^"\']*url=([^"\']+)["\']/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match) !== 1 || !isset($match[1])) {
            continue;
        }

        $redirectUrl = ve_remote_decode_escaped_url((string) $match[1], $baseUrl);

        if ($redirectUrl !== '' && ve_remote_is_http_url($redirectUrl)) {
            return $redirectUrl;
        }
    }

    return '';
}

function ve_remote_detect_host_key_from_page(string $html): string
{
    $html = strtolower($html);

    if ($html !== ''
        && (str_contains($html, '/token911') || str_contains($html, 'token911'))
        && (str_contains($html, '/ip129jk') || str_contains($html, 'iframeid'))
    ) {
        return 'vidi64';
    }

    return '';
}

function ve_remote_decode_escaped_url(string $value, string $baseUrl = ''): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($value === '') {
        return '';
    }

    $decoded = json_decode('"' . addcslashes($value, "\"\n\r\t") . '"', true);

    if (is_string($decoded) && $decoded !== '') {
        $value = $decoded;
    }

    $value = str_replace(['\\/', '\\u0026'], ['/', '&'], $value);
    $value = preg_replace('/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $value) ?? $value;
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/^(?:data|blob|javascript|mailto):/i', $value) === 1) {
        return trim($value);
    }

    if ($baseUrl !== '' && !ve_remote_is_http_url($value)) {
        $value = ve_remote_absolute_url($baseUrl, $value);
    }

    return trim($value);
}

function ve_remote_media_sources_from_html(string $html, string $baseUrl): array
{
    $unpacked = ve_remote_unpack_packer_blocks($html);

    if ($unpacked !== '') {
        $html .= "\n" . $unpacked;
    }

    $patterns = [
        '/hlsManifestUrl["\']?\s*:\s*["\']([^"\']+)["\']/i',
        '/MDCore\.(?:wurl|vurl|furl)\s*=\s*["\']([^"\']+)["\']/i',
        '/\bvsr\s*=\s*["\']([^"\']+)["\']/i',
        '/sources?(?:Code)?\s*:\s*\[[^\]]*?\{[^}]*?(?:file|src)\s*:\s*["\']([^"\']+)["\']/is',
        '/\b(?:file|src)\s*:\s*["\']([^"\']+)["\']/i',
        '/["\']file["\']\s*:\s*["\']([^"\']+)["\']/i',
        '/["\']src["\']\s*:\s*["\']([^"\']+)["\']/i',
        '/<source[^>]+src=["\']([^"\']+)["\']/i',
        '/player\.src\(\s*["\']([^"\']+)["\']/i',
        '/video[^>]+src=["\']([^"\']+)["\']/i',
    ];

    $sources = [];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) !== 1 && ($matches === [])) {
            continue;
        }

        foreach ($matches as $match) {
            if (!is_array($match) || !isset($match[1])) {
                continue;
            }

            $url = ve_remote_decode_escaped_url((string) $match[1], $baseUrl);

            if ($url === '' || !ve_remote_is_http_url($url)) {
                continue;
            }

            $downloadMethod = str_contains(strtolower($url), '.m3u8') ? 'ffmpeg' : 'curl';
            $sources[$url] = [
                'url' => $url,
                'download_method' => $downloadMethod,
            ];
        }
    }

    return array_values($sources);
}

function ve_remote_pick_media_source(array $sources): ?array
{
    if ($sources === []) {
        return null;
    }

    usort($sources, static function (array $left, array $right): int {
        $leftUrl = strtolower((string) ($left['url'] ?? ''));
        $rightUrl = strtolower((string) ($right['url'] ?? ''));
        $leftScore = str_contains($leftUrl, '.mp4') ? 3 : (str_contains($leftUrl, '.m3u8') ? 2 : 1);
        $rightScore = str_contains($rightUrl, '.mp4') ? 3 : (str_contains($rightUrl, '.m3u8') ? 2 : 1);

        return $rightScore <=> $leftScore;
    });

    return $sources[0] ?? null;
}

function ve_remote_headers_to_ffmpeg_blob(array $source): string
{
    $headers = [];

    if (($source['referer'] ?? '') !== '') {
        $headers[] = 'Referer: ' . trim((string) $source['referer']);
    }

    if (($source['cookie'] ?? '') !== '') {
        $headers[] = 'Cookie: ' . trim((string) $source['cookie']);
    }

    foreach (($source['headers'] ?? []) as $header) {
        if (!is_string($header) || trim($header) === '') {
            continue;
        }

        $headers[] = trim($header);
    }

    if ($headers === []) {
        return '';
    }

    return implode("\r\n", $headers) . "\r\n";
}

function ve_remote_find_downloaded_file(string $directory): ?string
{
    if (!is_dir($directory)) {
        return null;
    }

    $candidates = [];
    $items = scandir($directory);

    if (!is_array($items)) {
        return null;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if (!is_file($path)) {
            continue;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['part', 'ytdl', 'tmp'], true)) {
            continue;
        }

        $candidates[$path] = filemtime($path) ?: 0;
    }

    if ($candidates === []) {
        return null;
    }

    arsort($candidates);

    return (string) array_key_first($candidates);
}

function ve_remote_download_via_ffmpeg(int $jobId, array $source): array
{
    $ffmpeg = (string) (ve_video_config()['ffmpeg'] ?? '');

    if ($ffmpeg === '') {
        throw new RuntimeException('FFmpeg is required for this remote stream source.');
    }

    $directory = ve_remote_job_directory($jobId);
    ve_ensure_directory($directory);

    $filename = trim((string) ($source['filename'] ?? 'remote-stream.mp4'));
    $filename = ve_remote_ensure_filename_extension($filename, 'video/mp4');
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    $headerBlob = ve_remote_headers_to_ffmpeg_blob($source);

    $args = [
        $ffmpeg,
        '-y',
        '-hide_banner',
        '-loglevel',
        'error',
        '-protocol_whitelist',
        'file,http,https,tcp,tls,crypto',
        '-user_agent',
        (string) ve_remote_config()['user_agent'],
    ];

    if ($headerBlob !== '') {
        $args[] = '-headers';
        $args[] = $headerBlob;
    }

    $args[] = '-i';
    $args[] = (string) ($source['download_url'] ?? '');
    $args[] = '-c';
    $args[] = 'copy';
    $args[] = '-bsf:a';
    $args[] = 'aac_adtstoasc';
    $args[] = $path;

    [$exitCode, $output] = ve_video_run_command($args);

    if ($exitCode !== 0 || !is_file($path)) {
        @unlink($path);
        throw new RuntimeException('FFmpeg could not download the remote stream. ' . trim($output));
    }

    $size = (int) (filesize($path) ?: 0);
    ve_remote_update_progress($jobId, $size, $size, 0, 100);

    return [
        'path' => $path,
        'filename' => basename($path),
        'size' => $size,
        'content_type' => 'video/mp4',
        'effective_url' => (string) ($source['download_url'] ?? ''),
        'headers' => [],
        'speed' => 0,
    ];
}

function ve_remote_download_via_ytdlp(int $jobId, array $source): array
{
    $command = ve_remote_yt_dlp_command();

    if ($command === []) {
        throw new RuntimeException('yt-dlp is required for this remote host but is not available on the server.');
    }

    $directory = ve_remote_job_directory($jobId);
    ve_ensure_directory($directory);

    $outputTemplate = $directory . DIRECTORY_SEPARATOR . 'yt-dlp-download.%(ext)s';
    $args = array_merge($command, [
        '--ignore-config',
        '--no-warnings',
        '--no-progress',
        '--no-playlist',
        '--socket-timeout',
        '30',
        '--user-agent',
        (string) ve_remote_config()['user_agent'],
        '-o',
        $outputTemplate,
    ]);
    $args = array_merge($args, ve_remote_yt_dlp_plugin_args());
    $args = array_merge($args, ve_remote_yt_dlp_cookie_args());

    $format = trim((string) ($source['yt_dlp_format'] ?? ''));

    if ($format !== '') {
        $args[] = '-f';
        $args[] = $format;
    }

    $mergeOutputFormat = trim((string) ($source['merge_output_format'] ?? 'mp4'));

    if ($mergeOutputFormat !== '') {
        $args[] = '--merge-output-format';
        $args[] = $mergeOutputFormat;
    }

    if (($source['referer'] ?? '') !== '') {
        $args[] = '--referer';
        $args[] = (string) $source['referer'];
    }

    foreach (($source['yt_dlp_extra_args'] ?? []) as $extraArg) {
        if (is_string($extraArg) && $extraArg !== '') {
            $args[] = $extraArg;
        }
    }

    $args[] = (string) ($source['download_url'] ?? '');

    [$exitCode, $output] = ve_remote_yt_dlp_run($args);
    $path = ve_remote_find_downloaded_file($directory);

    if ($exitCode !== 0 || !is_string($path) || $path === '' || !is_file($path)) {
        throw new RuntimeException(ve_remote_yt_dlp_failure_message('download', $output));
    }

    $finalized = ve_remote_finalize_downloaded_file($source, $path, 'yt-dlp-download.mp4');
    $path = (string) ($finalized['path'] ?? $path);
    $filename = (string) ($finalized['filename'] ?? basename($path));

    $size = (int) (filesize($path) ?: 0);
    ve_remote_update_progress($jobId, $size, $size, 0, 100);

    return [
        'path' => $path,
        'filename' => $filename,
        'size' => $size,
        'content_type' => '',
        'effective_url' => (string) ($source['download_url'] ?? ''),
        'headers' => [],
        'speed' => 0,
    ];
}

function ve_remote_download_via_mega_py(int $jobId, array $source): array
{
    $command = ve_remote_python_command();

    if ($command === []) {
        throw new RuntimeException('Python is required for MEGA remote downloads.');
    }

    $script = ve_remote_mega_helper_script();

    if (!is_file($script)) {
        throw new RuntimeException('The MEGA helper script is missing from this installation.');
    }

    $directory = ve_remote_job_directory($jobId);
    ve_ensure_directory($directory);

    $filename = trim((string) ($source['filename'] ?? 'remote-mega-download.bin'));

    if ($filename === '') {
        $filename = 'remote-mega-download.bin';
    }

    [$exitCode, $output] = ve_video_run_command(array_merge($command, [
        $script,
        'download',
        (string) ($source['download_url'] ?? ''),
        $directory,
        $filename,
    ]), [
        'timeout' => (int) ve_remote_config()['download_timeout'],
    ]);

    $result = ve_remote_command_output_last_json($output);

    if ($exitCode !== 0 || !is_array($result)) {
        throw new RuntimeException('MEGA download failed. ' . trim($output));
    }

    $path = (string) ($result['path'] ?? '');

    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('MEGA download finished without a local file.');
    }

    $size = (int) (filesize($path) ?: 0);
    ve_remote_update_progress($jobId, $size, $size, 0, 100);

    return [
        'path' => $path,
        'filename' => basename($path),
        'size' => $size,
        'content_type' => '',
        'effective_url' => (string) ($source['download_url'] ?? ''),
        'headers' => [],
        'speed' => 0,
    ];
}

function ve_remote_update_job(int $jobId, array $columns): void
{
    if ($columns === []) {
        return;
    }

    $assignments = [];
    $params = [':id' => $jobId];

    foreach ($columns as $column => $value) {
        $assignments[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }

    ve_db()->prepare('UPDATE remote_uploads SET ' . implode(', ', $assignments) . ' WHERE id = :id')
        ->execute($params);
}

function ve_remote_get_by_id(int $jobId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM remote_uploads WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    return is_array($job) ? $job : null;
}

function ve_remote_job_is_deleted(int $jobId): bool
{
    $job = ve_remote_get_by_id($jobId);

    if (!is_array($job)) {
        return true;
    }

    return trim((string) ($job['deleted_at'] ?? '')) !== '';
}

function ve_remote_update_progress(int $jobId, int $downloaded, int $total, int $speedBytesPerSecond, float $progressPercent): void
{
    ve_remote_update_job($jobId, [
        'bytes_downloaded' => max(0, $downloaded),
        'bytes_total' => max(0, $total),
        'speed_bytes_per_second' => max(0, $speedBytesPerSecond),
        'progress_percent' => max(0, min(100, $progressPercent)),
        'updated_at' => ve_now(),
    ]);
}

function ve_remote_mark_failed(int $jobId, string $message): void
{
    $message = trim($message);
    $message = mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 500);

    ve_remote_update_job($jobId, [
        'status' => VE_REMOTE_STATUS_ERROR,
        'status_message' => 'Remote upload failed.',
        'error_message' => $message,
        'speed_bytes_per_second' => 0,
        'updated_at' => ve_now(),
    ]);

    ve_remote_host_mark_job_failed($jobId, $message);
}

function ve_remote_cleanup_job_files(int $jobId): void
{
    $directory = ve_remote_job_directory($jobId);

    if (is_dir($directory)) {
        ve_video_delete_directory($directory);
    }
}

function ve_remote_active_count_for_user(int $userId): int
{
    $stmt = ve_db()->prepare(
        "SELECT COUNT(*)
         FROM remote_uploads
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         AND status IN ('" . VE_REMOTE_STATUS_PENDING . "', '" . VE_REMOTE_STATUS_RESOLVING . "', '" . VE_REMOTE_STATUS_DOWNLOADING . "', '" . VE_REMOTE_STATUS_IMPORTING . "')"
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ve_remote_list_for_user(int $userId): array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM remote_uploads
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ve_remote_create_job(
    int $userId,
    string $sourceUrl,
    int $folderId,
    string $status = VE_REMOTE_STATUS_PENDING,
    string $message = 'Queued for remote download.',
    string $errorMessage = ''
): array {
    $now = ve_now();
    $folderId = ve_video_normalize_folder_id($userId, $folderId);
    $stmt = ve_db()->prepare(
        'INSERT INTO remote_uploads (
            user_id, source_url, normalized_url, resolved_url, host_key, folder_id,
            status, status_message, error_message, original_filename, content_type,
            bytes_downloaded, bytes_total, speed_bytes_per_second, progress_percent, attempt_count,
            video_id, video_public_id, created_at, updated_at, started_at, completed_at, deleted_at
        ) VALUES (
            :user_id, :source_url, "", "", "", :folder_id,
            :status, :status_message, :error_message, "", "",
            0, 0, 0, 0, 0,
            NULL, "", :created_at, :updated_at, NULL, NULL, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':source_url' => $sourceUrl,
        ':folder_id' => $folderId,
        ':status' => $status,
        ':status_message' => $message,
        ':error_message' => $errorMessage,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return ve_remote_get_by_id((int) ve_db()->lastInsertId()) ?? [];
}

function ve_remote_parse_urls(string $urls): array
{
    $lines = preg_split('/\r\n|\r|\n/', $urls) ?: [];
    $parsed = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $parsed[$line] = $line;
    }

    return array_values($parsed);
}

function ve_remote_remaining_slots(int $userId): int
{
    return max(0, (int) ve_remote_config()['max_queue_per_user'] - ve_remote_active_count_for_user($userId));
}

function ve_remote_add_queue(int $userId, string $urls, int $folderId): array
{
    if (!ve_video_processing_available()) {
        return [
            'status' => 'fail',
            'message' => 'FFmpeg is not available on this server yet. Configure video processing before using remote upload.',
        ];
    }

    $entries = ve_remote_parse_urls($urls);

    if ($entries === []) {
        return [
            'status' => 'fail',
            'message' => 'Please add at least one remote URL.',
        ];
    }

    $remaining = ve_remote_remaining_slots($userId);
    $added = 0;
    $invalid = 0;
    $disabled = 0;
    $skipped = 0;

    foreach ($entries as $entry) {
        $sourceHost = ve_remote_url_host($entry);
        $matchedHostKey = ve_remote_is_http_url($entry) ? ve_remote_host_known_match_key($entry) : '';

        if (!ve_remote_is_http_url($entry)) {
            $job = ve_remote_create_job(
                $userId,
                $entry,
                $folderId,
                VE_REMOTE_STATUS_ERROR,
                'Invalid remote URL.',
                'Only fully qualified http:// or https:// URLs are supported.'
            );
            $logId = ve_remote_host_log_submission([
                'user_id' => $userId,
                'source_url' => $entry,
                'source_host' => $sourceHost,
                'matched_host_key' => $matchedHostKey,
                'submission_status' => 'invalid_url',
                'detail_message' => 'Only fully qualified http:// or https:// URLs are supported.',
            ]);

            if ($logId > 0 && isset($job['id'])) {
                ve_remote_host_attach_log_to_job($logId, (int) $job['id']);
            }

            $invalid++;
            continue;
        }

        if ($matchedHostKey !== '' && !ve_remote_host_is_enabled($matchedHostKey)) {
            $message = ve_remote_host_disabled_message($matchedHostKey);
            $job = ve_remote_create_job(
                $userId,
                $entry,
                $folderId,
                VE_REMOTE_STATUS_ERROR,
                'Remote host disabled.',
                $message
            );
            $jobId = (int) ($job['id'] ?? 0);

            if ($jobId > 0) {
                ve_remote_update_job($jobId, [
                    'host_key' => $matchedHostKey,
                    'updated_at' => ve_now(),
                ]);
            }

            $logId = ve_remote_host_log_submission([
                'user_id' => $userId,
                'remote_upload_id' => $jobId > 0 ? $jobId : null,
                'source_url' => $entry,
                'source_host' => $sourceHost,
                'matched_host_key' => $matchedHostKey,
                'host_key' => $matchedHostKey,
                'submission_status' => 'disabled_host',
                'detail_message' => $message,
            ]);

            if ($logId > 0 && $jobId > 0) {
                ve_remote_host_attach_log_to_job($logId, $jobId);
            }

            $disabled++;
            continue;
        }

        if ($remaining <= 0) {
            ve_remote_host_log_submission([
                'user_id' => $userId,
                'source_url' => $entry,
                'source_host' => $sourceHost,
                'matched_host_key' => $matchedHostKey,
                'submission_status' => 'skipped_capacity',
                'detail_message' => 'No remote-upload slots were left for this account.',
            ]);
            $skipped++;
            continue;
        }

        $job = ve_remote_create_job($userId, $entry, $folderId);
        $jobId = (int) ($job['id'] ?? 0);
        $logId = ve_remote_host_log_submission([
            'user_id' => $userId,
            'remote_upload_id' => $jobId > 0 ? $jobId : null,
            'source_url' => $entry,
            'source_host' => $sourceHost,
            'matched_host_key' => $matchedHostKey,
            'submission_status' => 'queued',
        ]);

        if ($jobId > 0 && $matchedHostKey !== '') {
            ve_remote_update_job($jobId, [
                'host_key' => $matchedHostKey,
                'updated_at' => ve_now(),
            ]);
        }

        if ($logId > 0 && $jobId > 0) {
            ve_remote_host_attach_log_to_job($logId, $jobId);
        }

        $added++;
        $remaining--;
    }

    if ($added > 0) {
        ve_remote_maybe_spawn_worker();
    }

    $parts = [];

    if ($added > 0) {
        $parts[] = $added . ' link' . ($added === 1 ? '' : 's') . ' added to the remote upload queue.';
    }

    if ($invalid > 0) {
        $parts[] = $invalid . ' invalid link' . ($invalid === 1 ? ' was' : 's were') . ' moved to broken links.';
    }

    if ($disabled > 0) {
        $parts[] = $disabled . ' disabled host link' . ($disabled === 1 ? ' was' : 's were') . ' moved to broken links.';
    }

    if ($skipped > 0) {
        $parts[] = $skipped . ' link' . ($skipped === 1 ? ' was' : 's were') . ' skipped because no upload slots were left.';
    }

    if ($parts === []) {
        return [
            'status' => 'fail',
            'message' => 'No links were queued.',
        ];
    }

    return [
        'status' => $added > 0 ? 'ok' : 'fail',
        'message' => implode(' ', $parts),
    ];
}

function ve_remote_delete_job(int $userId, int $jobId): bool
{
    $stmt = ve_db()->prepare(
        'UPDATE remote_uploads
         SET deleted_at = :deleted_at, updated_at = :updated_at
         WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
    );
    $stmt->execute([
        ':deleted_at' => ve_now(),
        ':updated_at' => ve_now(),
        ':id' => $jobId,
        ':user_id' => $userId,
    ]);

    if ($stmt->rowCount() > 0) {
        ve_remote_cleanup_job_files($jobId);
        return true;
    }

    return false;
}

function ve_remote_restart_job(int $userId, int $jobId): bool
{
    $stmt = ve_db()->prepare(
        'UPDATE remote_uploads
         SET status = :status,
             status_message = :status_message,
             error_message = "",
             normalized_url = "",
             resolved_url = "",
             host_key = "",
             content_type = "",
             bytes_downloaded = 0,
             bytes_total = 0,
             speed_bytes_per_second = 0,
             progress_percent = 0,
             original_filename = "",
             video_id = NULL,
             video_public_id = "",
             completed_at = NULL,
             updated_at = :updated_at
         WHERE id = :id
         AND user_id = :user_id
         AND deleted_at IS NULL
         AND status = :expected_status'
    );
    $stmt->execute([
        ':status' => VE_REMOTE_STATUS_PENDING,
        ':status_message' => 'Queued for remote download.',
        ':updated_at' => ve_now(),
        ':id' => $jobId,
        ':user_id' => $userId,
        ':expected_status' => VE_REMOTE_STATUS_ERROR,
    ]);

    if ($stmt->rowCount() > 0) {
        ve_remote_cleanup_job_files($jobId);
        ve_remote_host_reset_job_log($jobId);
        ve_remote_maybe_spawn_worker();
        return true;
    }

    return false;
}

function ve_remote_restart_error_jobs(int $userId): int
{
    $jobIdStmt = ve_db()->prepare(
        'SELECT id FROM remote_uploads
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         AND status = :expected_status'
    );
    $jobIdStmt->execute([
        ':user_id' => $userId,
        ':expected_status' => VE_REMOTE_STATUS_ERROR,
    ]);
    $rows = $jobIdStmt->fetchAll();

    $stmt = ve_db()->prepare(
        'UPDATE remote_uploads
         SET status = :status,
             status_message = :status_message,
             error_message = "",
             normalized_url = "",
             resolved_url = "",
             host_key = "",
             content_type = "",
             bytes_downloaded = 0,
             bytes_total = 0,
             speed_bytes_per_second = 0,
             progress_percent = 0,
             original_filename = "",
             video_id = NULL,
             video_public_id = "",
             completed_at = NULL,
             updated_at = :updated_at
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         AND status = :expected_status'
    );
    $stmt->execute([
        ':status' => VE_REMOTE_STATUS_PENDING,
        ':status_message' => 'Queued for remote download.',
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
        ':expected_status' => VE_REMOTE_STATUS_ERROR,
    ]);

    if ($stmt->rowCount() > 0) {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                ve_remote_host_reset_job_log((int) $row['id']);
            }
        }

        ve_remote_maybe_spawn_worker();
    }

    return (int) $stmt->rowCount();
}

function ve_remote_clear_jobs_by_status(int $userId, string $status): int
{
    $now = ve_now();
    $stmt = ve_db()->prepare(
        'SELECT id FROM remote_uploads
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         AND status = :status'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':status' => $status,
    ]);
    $rows = $stmt->fetchAll();

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    $update = ve_db()->prepare(
        'UPDATE remote_uploads
         SET deleted_at = :deleted_at, updated_at = :updated_at
         WHERE user_id = :user_id
         AND deleted_at IS NULL
         AND status = :status'
    );
    $update->execute([
        ':deleted_at' => $now,
        ':updated_at' => $now,
        ':user_id' => $userId,
        ':status' => $status,
    ]);

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'])) {
            ve_remote_cleanup_job_files((int) $row['id']);
        }
    }

    return (int) $update->rowCount();
}

function ve_remote_clear_all_jobs(int $userId): int
{
    $now = ve_now();
    $stmt = ve_db()->prepare(
        'SELECT id FROM remote_uploads
         WHERE user_id = :user_id
         AND deleted_at IS NULL'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    $update = ve_db()->prepare(
        'UPDATE remote_uploads
         SET deleted_at = :deleted_at, updated_at = :updated_at
         WHERE user_id = :user_id
         AND deleted_at IS NULL'
    );
    $update->execute([
        ':deleted_at' => $now,
        ':updated_at' => $now,
        ':user_id' => $userId,
    ]);

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'])) {
            ve_remote_cleanup_job_files((int) $row['id']);
        }
    }

    return (int) $update->rowCount();
}

function ve_remote_status_for_ui(string $status, ?array $linkedVideo = null): string
{
    if ((string) ($status ?? '') === VE_REMOTE_STATUS_COMPLETE && is_array($linkedVideo)) {
        return match ((string) ($linkedVideo['status'] ?? '')) {
            VE_VIDEO_STATUS_QUEUED => 'PENDING',
            VE_VIDEO_STATUS_PROCESSING => 'WORKING2',
            VE_VIDEO_STATUS_FAILED => 'ERROR',
            default => 'COMPLETE',
        };
    }

    return match ($status) {
        VE_REMOTE_STATUS_PENDING, VE_REMOTE_STATUS_RESOLVING => 'PENDING',
        VE_REMOTE_STATUS_DOWNLOADING => 'WORKING',
        VE_REMOTE_STATUS_IMPORTING => 'WORKING2',
        VE_REMOTE_STATUS_ERROR => 'ERROR',
        default => 'COMPLETE',
    };
}

function ve_remote_status_message_html(array $job, ?array $linkedVideo = null): string
{
    $jobStatus = (string) ($job['status'] ?? VE_REMOTE_STATUS_PENDING);

    if ($jobStatus === VE_REMOTE_STATUS_COMPLETE && is_array($linkedVideo)) {
        $videoStatus = (string) ($linkedVideo['status'] ?? '');
        $videoMessage = trim((string) ($linkedVideo['status_message'] ?? ''));
        $videoError = trim((string) ($linkedVideo['processing_error'] ?? ''));

        $message = match ($videoStatus) {
            VE_VIDEO_STATUS_QUEUED => $videoMessage !== '' ? $videoMessage : 'Imported. Video is queued for processing.',
            VE_VIDEO_STATUS_PROCESSING => $videoMessage !== '' ? $videoMessage : 'Imported. Video is processing.',
            VE_VIDEO_STATUS_FAILED => $videoError !== '' ? $videoError : ($videoMessage !== '' ? $videoMessage : 'Imported, but video processing failed.'),
            VE_VIDEO_STATUS_READY => 'Remote file imported successfully. Video is ready to stream.',
            default => '',
        };

        if ($message !== '') {
            return nl2br(ve_h($message));
        }
    }

    $message = trim((string) ($jobStatus === VE_REMOTE_STATUS_ERROR ? ($job['error_message'] ?? '') : ($job['status_message'] ?? '')));

    if ($message === '') {
        $message = trim((string) ($job['status_message'] ?? ''));
    }

    if ($message === '') {
        $message = 'Ready.';
    }

    return nl2br(ve_h($message));
}

function ve_remote_format_megabytes(int $bytes): string
{
    return number_format(max(0, $bytes) / 1048576, 2, '.', '');
}

function ve_remote_payload_from_row(array $job, ?array $linkedVideo = null): array
{
    $status = (string) ($job['status'] ?? VE_REMOTE_STATUS_PENDING);
    $progress = max(0, min(100, (int) round((float) ($job['progress_percent'] ?? 0))));
    $bytesDownloaded = (int) ($job['bytes_downloaded'] ?? 0);
    $bytesTotal = max((int) ($job['bytes_total'] ?? 0), $bytesDownloaded);
    $resolvedUrl = trim((string) ($job['resolved_url'] ?? ''));
    $normalizedUrl = trim((string) ($job['normalized_url'] ?? ''));
    $sourceUrl = trim((string) ($job['source_url'] ?? ''));
    $filename = trim((string) ($job['original_filename'] ?? ''));

    return [
        'id' => (int) ($job['id'] ?? 0),
        'url' => $filename !== '' ? $filename : $sourceUrl,
        'fcurl' => $resolvedUrl !== '' ? $resolvedUrl : ($normalizedUrl !== '' ? $normalizedUrl : $sourceUrl),
        'created' => (string) ($job['created_at'] ?? ''),
        'status' => ve_remote_status_for_ui($status, $linkedVideo),
        'pro' => $status === VE_REMOTE_STATUS_COMPLETE ? 100 : $progress,
        'szd' => ve_remote_format_megabytes($bytesDownloaded),
        'szf' => ve_remote_format_megabytes($bytesTotal),
        'speed' => number_format(max(0, (int) ($job['speed_bytes_per_second'] ?? 0)) / 1024, 0, '.', ''),
        'st' => ve_remote_status_message_html($job, $linkedVideo),
        'restart' => $status === VE_REMOTE_STATUS_ERROR,
    ];
}

function ve_remote_list_payload(int $userId): array
{
    $rows = ve_remote_list_for_user($userId);
    $list = [];
    $broken = [];
    $linkedVideoIds = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $videoId = (int) ($row['video_id'] ?? 0);

        if ($videoId > 0) {
            $linkedVideoIds[$videoId] = $videoId;
        }
    }

    $linkedVideos = [];

    foreach (array_values($linkedVideoIds) as $videoId) {
        $video = ve_video_get_by_id($videoId);

        if (is_array($video)) {
            $linkedVideos[$videoId] = $video;
        }
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $linkedVideo = $linkedVideos[(int) ($row['video_id'] ?? 0)] ?? null;
        $payload = ve_remote_payload_from_row($row, is_array($linkedVideo) ? $linkedVideo : null);
        $list[] = $payload;

        if ((string) ($row['status'] ?? '') === VE_REMOTE_STATUS_ERROR) {
            $broken[] = $payload;
        }
    }

    return [
        'list' => $list,
        'bli' => $broken,
        'folders_tree' => ve_remote_folder_options_for_user($userId),
        'slo' => ve_remote_remaining_slots($userId),
        'max' => (int) ve_remote_config()['max_queue_per_user'],
    ];
}

function ve_remote_has_pending_jobs(): bool
{
    $stmt = ve_db()->query(
        "SELECT COUNT(*)
         FROM remote_uploads
         WHERE deleted_at IS NULL
         AND status IN ('" . VE_REMOTE_STATUS_PENDING . "', '" . VE_REMOTE_STATUS_RESOLVING . "', '" . VE_REMOTE_STATUS_DOWNLOADING . "', '" . VE_REMOTE_STATUS_IMPORTING . "')"
    );

    return (int) $stmt->fetchColumn() > 0;
}

function ve_remote_worker_is_running(): bool
{
    $handle = fopen(ve_remote_processing_lock_path(), 'c+');

    if ($handle === false) {
        return false;
    }

    $hasLock = flock($handle, LOCK_EX | LOCK_NB);

    if ($hasLock) {
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    return !$hasLock;
}

function ve_remote_maybe_spawn_worker(): void
{
    if (!ve_remote_has_pending_jobs() || ve_remote_worker_is_running()) {
        return;
    }

    $phpBinary = (string) ve_remote_config()['php_binary'];
    $script = ve_root_path('app', 'workers', 'remote_upload_queue.php');
    $command = ve_video_shell_join([$phpBinary, $script]);

    if (ve_video_is_windows()) {
        @pclose(@popen('start /B "" ' . $command . ' >NUL 2>NUL', 'r'));
        return;
    }

    @exec($command . ' > /dev/null 2>&1 &');
}

function ve_remote_requeue_stale_jobs(): void
{
    $cutoff = gmdate('Y-m-d H:i:s', ve_timestamp() - (int) ve_remote_config()['worker_stale_after']);
    $stmt = ve_db()->prepare(
        "UPDATE remote_uploads
         SET status = :status,
             status_message = :status_message,
             error_message = '',
             bytes_downloaded = 0,
             bytes_total = 0,
             speed_bytes_per_second = 0,
             progress_percent = 0,
             updated_at = :updated_at
         WHERE deleted_at IS NULL
         AND status IN ('" . VE_REMOTE_STATUS_RESOLVING . "', '" . VE_REMOTE_STATUS_DOWNLOADING . "', '" . VE_REMOTE_STATUS_IMPORTING . "')
         AND updated_at < :cutoff"
    );
    $stmt->execute([
        ':status' => VE_REMOTE_STATUS_PENDING,
        ':status_message' => 'Queued again after an interrupted remote worker.',
        ':updated_at' => ve_now(),
        ':cutoff' => $cutoff,
    ]);
}

function ve_remote_claim_next_job(): ?array
{
    $pdo = ve_db();
    $pdo->beginTransaction();

    try {
        $job = $pdo->query(
            "SELECT * FROM remote_uploads
             WHERE deleted_at IS NULL
             AND status = '" . VE_REMOTE_STATUS_PENDING . "'
             ORDER BY created_at ASC, id ASC
             LIMIT 1"
        )->fetch();

        if (!is_array($job)) {
            $pdo->commit();
            return null;
        }

        $now = ve_now();
        $stmt = $pdo->prepare(
            'UPDATE remote_uploads
             SET status = :status,
                 status_message = :status_message,
                 error_message = "",
                 attempt_count = attempt_count + 1,
                 started_at = COALESCE(started_at, :started_at),
                 updated_at = :updated_at
             WHERE id = :id
             AND status = :expected_status
             AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':status' => VE_REMOTE_STATUS_RESOLVING,
            ':status_message' => 'Resolving remote source URL.',
            ':started_at' => $now,
            ':updated_at' => $now,
            ':id' => (int) $job['id'],
            ':expected_status' => VE_REMOTE_STATUS_PENDING,
        ]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();

        return ve_remote_get_by_id((int) $job['id']);
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

function ve_remote_assert_host_enabled(string $hostKey): void
{
    $hostKey = strtolower(trim($hostKey));

    if ($hostKey !== '' && !ve_remote_host_is_enabled($hostKey)) {
        throw new RuntimeException(ve_remote_host_disabled_message($hostKey));
    }
}

function ve_remote_finalize_resolved_source(array $host, array $resolved, string $fallbackUrl): array
{
    if (!isset($resolved['download_url'])) {
        throw new RuntimeException('The remote host resolver did not return a download URL.');
    }

    $hostKey = strtolower(trim((string) ($host['key'] ?? 'unknown')));
    ve_remote_assert_host_enabled($hostKey);

    $resolved['host_key'] = $hostKey !== '' ? $hostKey : 'unknown';
    $resolved['normalized_url'] = trim((string) ($resolved['normalized_url'] ?? $fallbackUrl));
    $resolved['download_url'] = trim((string) $resolved['download_url']);
    $resolved['filename'] = trim((string) ($resolved['filename'] ?? ''));
    $resolved['referer'] = trim((string) ($resolved['referer'] ?? ''));
    $resolved['headers'] = is_array($resolved['headers'] ?? null) ? $resolved['headers'] : [];

    if ($resolved['download_url'] === '') {
        throw new RuntimeException('The resolved download URL is empty.');
    }

    return $resolved;
}

function ve_remote_resolve_source(array $job): array
{
    $url = trim((string) ($job['source_url'] ?? ''));
    $hosts = ve_remote_hosts();
    $directHost = ve_remote_find_host_resolver($hosts, 'direct');

    foreach ($hosts as $host) {
        if (strtolower(trim((string) ($host['key'] ?? ''))) === 'direct') {
            continue;
        }

        $match = $host['match'] ?? null;
        $resolve = $host['resolve'] ?? null;

        if (!is_callable($match) || !is_callable($resolve)) {
            continue;
        }

        if ($match($url) !== true) {
            continue;
        }

        $resolved = $resolve($url, $job);

        if (!is_array($resolved)) {
            throw new RuntimeException('The remote host resolver did not return a download URL.');
        }

        return ve_remote_finalize_resolved_source($host, $resolved, $url);
    }

    try {
        $probe = ve_remote_http_request($url, [
            'follow_location' => true,
            'referer' => $url,
            'timeout' => 20,
        ]);
    } catch (Throwable $throwable) {
        $probe = null;
    }

    $effectiveUrl = trim((string) (($probe['effective_url'] ?? '') ?: ''));

    if ($effectiveUrl !== '' && $effectiveUrl !== $url) {
        foreach ($hosts as $host) {
            $match = $host['match'] ?? null;
            $resolve = $host['resolve'] ?? null;

            if (!is_callable($match) || !is_callable($resolve)) {
                continue;
            }

            if ($match($effectiveUrl) !== true) {
                continue;
            }

            $resolved = $resolve($effectiveUrl, $job);

            if (!is_array($resolved)) {
                throw new RuntimeException('The remote host resolver did not return a download URL.');
            }

            return ve_remote_finalize_resolved_source($host, $resolved, $effectiveUrl);
        }
    }

    $detectedHostKey = is_array($probe)
        ? ve_remote_detect_host_key_from_page((string) ($probe['body'] ?? ''))
        : '';

    if ($detectedHostKey !== '') {
        $host = ve_remote_find_host_resolver($hosts, $detectedHostKey);
        $resolve = is_array($host) ? ($host['resolve'] ?? null) : null;

        if (is_array($host) && is_callable($resolve)) {
            $detectedUrl = $effectiveUrl !== '' ? $effectiveUrl : $url;
            $resolved = $resolve($detectedUrl, $job);

            if (!is_array($resolved)) {
                throw new RuntimeException('The remote host resolver did not return a download URL.');
            }

            return ve_remote_finalize_resolved_source($host, $resolved, $detectedUrl);
        }
    }

    if (is_array($directHost)) {
        $match = $directHost['match'] ?? null;
        $resolve = $directHost['resolve'] ?? null;

        if (is_callable($match) && is_callable($resolve) && $match($url) === true) {
            $resolved = $resolve($url, $job);

            if (!is_array($resolved)) {
                throw new RuntimeException('The remote host resolver did not return a download URL.');
            }

            return ve_remote_finalize_resolved_source($directHost, $resolved, $url);
        }
    }

    throw new RuntimeException('This remote host is not supported yet.');
}

function ve_remote_download_to_file(int $jobId, array $source): array
{
    $downloadMethod = strtolower(trim((string) ($source['download_method'] ?? 'curl')));

    if ($downloadMethod === 'yt_dlp') {
        return ve_remote_download_via_ytdlp($jobId, $source);
    }

    if ($downloadMethod === 'mega_py') {
        return ve_remote_download_via_mega_py($jobId, $source);
    }

    if ($downloadMethod === 'ffmpeg') {
        return ve_remote_download_via_ffmpeg($jobId, $source);
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for remote uploads.');
    }

    $directory = ve_remote_job_directory($jobId);
    ve_ensure_directory($directory);

    $path = $directory . DIRECTORY_SEPARATOR . 'download.part';
    @unlink($path);

    $stream = fopen($path, 'wb');

    if ($stream === false) {
        throw new RuntimeException('The remote upload workspace could not be created.');
    }

    $handle = curl_init((string) $source['download_url']);

    if ($handle === false) {
        fclose($stream);
        throw new RuntimeException('Unable to initialize the remote download.');
    }

    $currentHeaders = [];
    $finalHeaders = [];
    $lastTick = microtime(true);
    $lastBytes = 0.0;
    $nextCancelCheck = 0.0;
    $headers = [];

    foreach (($source['headers'] ?? []) as $header) {
        if (is_string($header) && trim($header) !== '') {
            $headers[] = trim($header);
        }
    }

    $verifySsl = (bool) ($source['verify_ssl'] ?? true);

    curl_setopt($handle, CURLOPT_FILE, $stream);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($handle, CURLOPT_MAXREDIRS, (int) ve_remote_config()['max_redirects']);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) ve_remote_config()['connect_timeout']);
    curl_setopt($handle, CURLOPT_TIMEOUT, (int) ve_remote_config()['download_timeout']);
    curl_setopt($handle, CURLOPT_ENCODING, '');
    curl_setopt($handle, CURLOPT_USERAGENT, (string) ve_remote_config()['user_agent']);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
    curl_setopt($handle, CURLOPT_COOKIEFILE, '');
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$currentHeaders, &$finalHeaders): int {
        $trimmed = trim($line);

        if ($trimmed === '') {
            if ($currentHeaders !== []) {
                $finalHeaders = $currentHeaders;
                $currentHeaders = [];
            }

            return strlen($line);
        }

        if (!str_contains($line, ':')) {
            return strlen($line);
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);

        if ($name !== '') {
            $currentHeaders[$name] ??= [];
            $currentHeaders[$name][] = $value;
        }

        return strlen($line);
    });
    curl_setopt($handle, CURLOPT_NOPROGRESS, false);
    curl_setopt($handle, CURLOPT_PROGRESSFUNCTION, static function (
        $curl,
        float $downloadTotal,
        float $downloadNow,
        float $uploadTotal,
        float $uploadNow
    ) use ($jobId, &$lastTick, &$lastBytes, &$nextCancelCheck): int {
        $now = microtime(true);

        if (($now - $lastTick) >= 0.8 || ($downloadTotal > 0.0 && $downloadNow >= $downloadTotal)) {
            $elapsed = max($now - $lastTick, 0.001);
            $speed = (int) round(max(0.0, $downloadNow - $lastBytes) / $elapsed);
            $progress = $downloadTotal > 0.0 ? (($downloadNow / $downloadTotal) * 100.0) : 0.0;

            ve_remote_update_progress($jobId, (int) round($downloadNow), (int) round($downloadTotal), $speed, $progress);

            $lastTick = $now;
            $lastBytes = $downloadNow;
        }

        if ($now >= $nextCancelCheck) {
            $nextCancelCheck = $now + 1.0;

            if (ve_remote_job_is_deleted($jobId)) {
                return 1;
            }
        }

        return 0;
    });

    if (($source['referer'] ?? '') !== '') {
        curl_setopt($handle, CURLOPT_REFERER, (string) $source['referer']);
    }

    if (($source['cookie'] ?? '') !== '') {
        curl_setopt($handle, CURLOPT_COOKIE, (string) $source['cookie']);
    }

    $success = curl_exec($handle);
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $contentType = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
    $speed = (int) round((float) curl_getinfo($handle, CURLINFO_SPEED_DOWNLOAD));
    $bytesDownloaded = (int) round((float) curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD));

    if ($currentHeaders !== []) {
        $finalHeaders = $currentHeaders;
    }

    curl_close($handle);
    fclose($stream);

    if ($success === false) {
        @unlink($path);

        if ($errno === CURLE_ABORTED_BY_CALLBACK && ve_remote_job_is_deleted($jobId)) {
            throw new RuntimeException('Remote upload was cancelled.');
        }

        throw new RuntimeException('Download failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
    }

    if ($status >= 400) {
        @unlink($path);
        throw new RuntimeException('Remote host returned HTTP ' . $status . ' during download.');
    }

    $fileSize = (int) (filesize($path) ?: $bytesDownloaded);
    $response = ['headers' => $finalHeaders];
    $filename = trim((string) ($source['filename'] ?? ''));

    if ($filename === '') {
        $filename = ve_remote_filename_from_content_disposition(
            ve_remote_http_response_header($response, 'content-disposition')
        );
    }

    if ($filename === '') {
        $filename = ve_remote_filename_from_url($effectiveUrl !== '' ? $effectiveUrl : (string) $source['download_url']);
    }

    $filename = ve_remote_ensure_filename_extension($filename, $contentType);

    ve_remote_update_progress($jobId, $fileSize, $fileSize, $speed, 100);

    return [
        'path' => $path,
        'filename' => $filename,
        'size' => $fileSize,
        'content_type' => $contentType,
        'effective_url' => $effectiveUrl !== '' ? $effectiveUrl : (string) $source['download_url'],
        'headers' => $finalHeaders,
        'speed' => $speed,
    ];
}

function ve_remote_import_downloaded_video(array $job, array $download): array
{
    return ve_video_queue_local_source(
        (int) ($job['user_id'] ?? 0),
        (string) ($download['path'] ?? ''),
        (string) ($download['filename'] ?? 'video.mp4'),
        null,
        false,
        (int) ($job['folder_id'] ?? 0)
    );
}

function ve_remote_process_job(int $jobId): void
{
    $job = ve_remote_get_by_id($jobId);

    if (!is_array($job) || trim((string) ($job['deleted_at'] ?? '')) !== '') {
        return;
    }

    try {
        $resolved = ve_remote_resolve_source($job);

        ve_remote_update_job($jobId, [
            'status' => VE_REMOTE_STATUS_DOWNLOADING,
            'status_message' => 'Downloading remote video.',
            'host_key' => (string) ($resolved['host_key'] ?? ''),
            'normalized_url' => (string) ($resolved['normalized_url'] ?? (string) ($job['source_url'] ?? '')),
            'resolved_url' => (string) ($resolved['download_url'] ?? ''),
            'original_filename' => (string) ($resolved['filename'] ?? ''),
            'updated_at' => ve_now(),
        ]);

        $download = ve_remote_download_to_file($jobId, $resolved);

        if (ve_remote_job_is_deleted($jobId)) {
            ve_remote_cleanup_job_files($jobId);
            return;
        }

        ve_remote_update_job($jobId, [
            'status' => VE_REMOTE_STATUS_IMPORTING,
            'status_message' => 'Importing downloaded file into the video library.',
            'content_type' => (string) ($download['content_type'] ?? ''),
            'resolved_url' => (string) ($download['effective_url'] ?? (string) ($resolved['download_url'] ?? '')),
            'original_filename' => (string) ($download['filename'] ?? (string) ($resolved['filename'] ?? '')),
            'updated_at' => ve_now(),
        ]);

        $video = ve_remote_import_downloaded_video($job, $download);

        ve_remote_update_job($jobId, [
            'status' => VE_REMOTE_STATUS_COMPLETE,
            'status_message' => 'Remote file imported successfully. Video queued for processing.',
            'error_message' => '',
            'content_type' => (string) ($download['content_type'] ?? ''),
            'bytes_downloaded' => (int) ($download['size'] ?? 0),
            'bytes_total' => (int) ($download['size'] ?? 0),
            'speed_bytes_per_second' => 0,
            'progress_percent' => 100,
            'video_id' => (int) ($video['id'] ?? 0),
            'video_public_id' => (string) ($video['public_id'] ?? ''),
            'completed_at' => ve_now(),
            'updated_at' => ve_now(),
        ]);

        ve_remote_host_mark_job_completed($jobId);
        ve_remote_cleanup_job_files($jobId);
    } catch (Throwable $throwable) {
        ve_remote_cleanup_job_files($jobId);

        if (!ve_remote_job_is_deleted($jobId)) {
            ve_remote_mark_failed($jobId, trim($throwable->getMessage()) !== '' ? $throwable->getMessage() : 'Remote upload failed.');
        }
    }
}

function ve_remote_process_pending_jobs(int $maxJobs = 0): int
{
    $handle = fopen(ve_remote_processing_lock_path(), 'c+');

    if ($handle === false) {
        return 0;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return 0;
    }

    $processed = 0;

    try {
        ve_remote_requeue_stale_jobs();

        while ($maxJobs === 0 || $processed < $maxJobs) {
            $job = ve_remote_claim_next_job();

            if (!is_array($job)) {
                break;
            }

            ve_remote_process_job((int) $job['id']);
            $processed++;
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    return $processed;
}

function ve_handle_remote_upload_json(): void
{
    $user = ve_remote_require_auth_json();
    $userId = (int) $user['id'];

    if (isset($_GET['urls'])) {
        ve_json(ve_remote_add_queue(
            $userId,
            (string) ($_GET['urls'] ?? ''),
            max(0, (int) ($_GET['fld_id'] ?? 0))
        ));
    }

    if (isset($_GET['del_id'])) {
        $deleted = ve_remote_delete_job($userId, (int) $_GET['del_id']);

        ve_json([
            'status' => $deleted ? 'ok' : 'fail',
            'message' => $deleted ? 'Remote upload deleted.' : 'Remote upload not found.',
        ], $deleted ? 200 : 404);
    }

    if (isset($_GET['restart'])) {
        $restarted = ve_remote_restart_job($userId, (int) $_GET['restart']);

        ve_json([
            'status' => $restarted ? 'ok' : 'fail',
            'message' => $restarted ? 'Remote upload restarted.' : 'Only failed remote uploads can be restarted.',
        ], $restarted ? 200 : 422);
    }

    if (isset($_GET['restart_errors'])) {
        $count = ve_remote_restart_error_jobs($userId);

        ve_json([
            'status' => 'ok',
            'message' => $count > 0
                ? $count . ' failed remote upload' . ($count === 1 ? ' was' : 's were') . ' queued again.'
                : 'There were no failed remote uploads to restart.',
        ]);
    }

    if (isset($_GET['clear_errors'])) {
        $count = ve_remote_clear_jobs_by_status($userId, VE_REMOTE_STATUS_ERROR);

        ve_json([
            'status' => 'ok',
            'message' => $count > 0
                ? $count . ' broken link' . ($count === 1 ? ' was' : 's were') . ' cleared.'
                : 'There were no broken links to clear.',
        ]);
    }

    if (isset($_GET['clear_all'])) {
        $count = ve_remote_clear_all_jobs($userId);

        ve_json([
            'status' => 'ok',
            'message' => $count > 0
                ? $count . ' remote upload' . ($count === 1 ? ' was' : 's were') . ' cleared.'
                : 'There were no remote uploads to clear.',
        ]);
    }

    ve_remote_maybe_spawn_worker();
    ve_json(ve_remote_list_payload($userId));
}
