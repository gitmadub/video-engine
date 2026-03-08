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

    $config = [
        'root' => ve_remote_storage_path(),
        'jobs_root' => ve_remote_storage_path('jobs'),
        'php_binary' => ve_video_find_php_binary(),
        'max_queue_per_user' => max(1, (int) (getenv('VE_REMOTE_MAX_QUEUE_PER_USER') ?: 25)),
        'worker_stale_after' => max(900, (int) (getenv('VE_REMOTE_STALE_AFTER') ?: 7200)),
        'connect_timeout' => max(5, (int) (getenv('VE_REMOTE_CONNECT_TIMEOUT') ?: 20)),
        'request_timeout' => max(15, (int) (getenv('VE_REMOTE_REQUEST_TIMEOUT') ?: 60)),
        'download_timeout' => max(60, (int) (getenv('VE_REMOTE_DOWNLOAD_TIMEOUT') ?: 21600)),
        'max_redirects' => max(1, (int) (getenv('VE_REMOTE_MAX_REDIRECTS') ?: 10)),
        'user_agent' => trim((string) (getenv('VE_REMOTE_USER_AGENT') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36 VideoEngineRemote/1.0')),
    ];

    return $config;
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
        'google_drive.php',
        'dropbox.php',
        'okru.php',
        'streamtape.php',
        'direct.php',
    ] as $file) {
        $host = require __DIR__ . '/remote_upload/hosts/' . $file;

        if (is_array($host)) {
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

    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, (bool) ($options['follow_location'] ?? true));
    curl_setopt($handle, CURLOPT_MAXREDIRS, (int) ve_remote_config()['max_redirects']);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) ve_remote_config()['connect_timeout']);
    curl_setopt($handle, CURLOPT_TIMEOUT, (int) (($options['timeout'] ?? ve_remote_config()['request_timeout'])));
    curl_setopt($handle, CURLOPT_ENCODING, '');
    curl_setopt($handle, CURLOPT_USERAGENT, (string) ($options['user_agent'] ?? ve_remote_config()['user_agent']));
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
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
    $skipped = 0;

    foreach ($entries as $entry) {
        if (!ve_remote_is_http_url($entry)) {
            ve_remote_create_job(
                $userId,
                $entry,
                $folderId,
                VE_REMOTE_STATUS_ERROR,
                'Invalid remote URL.',
                'Only fully qualified http:// or https:// URLs are supported.'
            );
            $invalid++;
            continue;
        }

        if ($remaining <= 0) {
            $skipped++;
            continue;
        }

        ve_remote_create_job($userId, $entry, $folderId);
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
        ve_remote_maybe_spawn_worker();
        return true;
    }

    return false;
}

function ve_remote_restart_error_jobs(int $userId): int
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

function ve_remote_status_for_ui(string $status): string
{
    return match ($status) {
        VE_REMOTE_STATUS_PENDING, VE_REMOTE_STATUS_RESOLVING => 'PENDING',
        VE_REMOTE_STATUS_DOWNLOADING => 'WORKING',
        VE_REMOTE_STATUS_IMPORTING => 'WORKING2',
        VE_REMOTE_STATUS_ERROR => 'ERROR',
        default => 'COMPLETE',
    };
}

function ve_remote_status_message_html(array $job): string
{
    $message = trim((string) ($job['status'] === VE_REMOTE_STATUS_ERROR ? ($job['error_message'] ?? '') : ($job['status_message'] ?? '')));

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

function ve_remote_payload_from_row(array $job): array
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
        'status' => ve_remote_status_for_ui($status),
        'pro' => $status === VE_REMOTE_STATUS_COMPLETE ? 100 : $progress,
        'szd' => ve_remote_format_megabytes($bytesDownloaded),
        'szf' => ve_remote_format_megabytes($bytesTotal),
        'speed' => number_format(max(0, (int) ($job['speed_bytes_per_second'] ?? 0)) / 1024, 0, '.', ''),
        'st' => ve_remote_status_message_html($job),
        'restart' => $status === VE_REMOTE_STATUS_ERROR,
    ];
}

function ve_remote_list_payload(int $userId): array
{
    $rows = ve_remote_list_for_user($userId);
    $list = [];
    $broken = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $payload = ve_remote_payload_from_row($row);
        $list[] = $payload;

        if ((string) ($row['status'] ?? '') === VE_REMOTE_STATUS_ERROR) {
            $broken[] = $payload;
        }
    }

    return [
        'list' => $list,
        'bli' => $broken,
        'folders_tree' => [],
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
    $script = ve_root_path('scripts', 'process_remote_upload_queue.php');
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

function ve_remote_resolve_source(array $job): array
{
    $url = trim((string) ($job['source_url'] ?? ''));

    foreach (ve_remote_hosts() as $host) {
        $match = $host['match'] ?? null;
        $resolve = $host['resolve'] ?? null;

        if (!is_callable($match) || !is_callable($resolve)) {
            continue;
        }

        if ($match($url) !== true) {
            continue;
        }

        $resolved = $resolve($url, $job);

        if (!is_array($resolved) || !isset($resolved['download_url'])) {
            throw new RuntimeException('The remote host resolver did not return a download URL.');
        }

        $resolved['host_key'] = (string) ($host['key'] ?? 'unknown');
        $resolved['normalized_url'] = trim((string) ($resolved['normalized_url'] ?? $url));
        $resolved['download_url'] = trim((string) $resolved['download_url']);
        $resolved['filename'] = trim((string) ($resolved['filename'] ?? ''));
        $resolved['referer'] = trim((string) ($resolved['referer'] ?? ''));
        $resolved['headers'] = is_array($resolved['headers'] ?? null) ? $resolved['headers'] : [];

        if ($resolved['download_url'] === '') {
            throw new RuntimeException('The resolved download URL is empty.');
        }

        return $resolved;
    }

    throw new RuntimeException('This remote host is not supported yet.');
}

function ve_remote_download_to_file(int $jobId, array $source): array
{
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

    curl_setopt($handle, CURLOPT_FILE, $stream);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($handle, CURLOPT_MAXREDIRS, (int) ve_remote_config()['max_redirects']);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) ve_remote_config()['connect_timeout']);
    curl_setopt($handle, CURLOPT_TIMEOUT, (int) ve_remote_config()['download_timeout']);
    curl_setopt($handle, CURLOPT_ENCODING, '');
    curl_setopt($handle, CURLOPT_USERAGENT, (string) ve_remote_config()['user_agent']);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
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

        $video = ve_video_queue_local_source(
            (int) ($job['user_id'] ?? 0),
            (string) $download['path'],
            (string) ($download['filename'] ?? 'video.mp4')
        );

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
