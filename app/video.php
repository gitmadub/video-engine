<?php

declare(strict_types=1);

const VE_VIDEO_STATUS_QUEUED = 'queued';
const VE_VIDEO_STATUS_PROCESSING = 'processing';
const VE_VIDEO_STATUS_READY = 'ready';
const VE_VIDEO_STATUS_FAILED = 'failed';
const VE_VIDEO_PREVIEW_COLUMNS = 4;
const VE_VIDEO_PREVIEW_ROWS = 4;
const VE_VIDEO_PREVIEW_TILE_WIDTH = 256;
const VE_VIDEO_PREVIEW_TILE_HEIGHT = 144;

const VE_VIDEO_ALLOWED_EXTENSIONS = [
    '3gp',
    'asf',
    'avi',
    'divx',
    'flv',
    'm2ts',
    'm2v',
    'm4v',
    'mkv',
    'mov',
    'mp4',
    'mpeg',
    'mpg',
    'mts',
    'mxf',
    'ogg',
    'ogv',
    'qt',
    'rm',
    'rmvb',
    'ts',
    'vob',
    'webm',
    'wmv',
    'xvid',
];

function ve_video_storage_path(string ...$parts): string
{
    return ve_storage_path('private', 'videos', ...$parts);
}

function ve_video_processing_lock_path(): string
{
    return ve_video_storage_path('processor.lock');
}

function ve_video_is_windows(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function ve_video_find_binary(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $candidate = trim($candidate);

        if (str_contains($candidate, '\\') || str_contains($candidate, '/') || str_contains($candidate, ':')) {
            if (is_file($candidate)) {
                return $candidate;
            }

            continue;
        }

        $command = ve_video_is_windows()
            ? 'where ' . escapeshellarg($candidate) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($candidate) . ' 2>/dev/null';

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode === 0 && isset($output[0]) && is_string($output[0]) && trim($output[0]) !== '') {
            return trim($output[0]);
        }
    }

    return null;
}

function ve_video_find_php_binary(): string
{
    static $binary;

    if (is_string($binary) && $binary !== '') {
        return $binary;
    }

    $currentBinary = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
    $candidates = array_filter([
        getenv('VE_PHP_BINARY') ?: null,
        $currentBinary !== '' ? $currentBinary : null,
        $currentBinary !== '' ? dirname($currentBinary) . DIRECTORY_SEPARATOR . (ve_video_is_windows() ? 'php.exe' : 'php') : null,
        ve_video_is_windows() ? 'C:\\xampp\\php\\php.exe' : null,
        'php',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ]);

    $binary = ve_video_find_binary($candidates) ?? ($currentBinary !== '' ? $currentBinary : 'php');

    return $binary;
}

function ve_video_config(): array
{
    static $config;

    if (is_array($config)) {
        return $config;
    }

    ve_ensure_directory(ve_video_storage_path());
    ve_ensure_directory(ve_video_storage_path('library'));
    ve_ensure_directory(ve_video_storage_path('tmp'));

    $localFfmpeg = ve_root_path('tools', 'ffmpeg', 'bin', ve_video_is_windows() ? 'ffmpeg.exe' : 'ffmpeg');
    $localFfprobe = ve_root_path('tools', 'ffmpeg', 'bin', ve_video_is_windows() ? 'ffprobe.exe' : 'ffprobe');

    $ffmpegCandidates = array_filter([
        getenv('VE_FFMPEG_PATH') ?: null,
        $localFfmpeg,
        'ffmpeg',
        'C:\\Program Files\\Wondershare\\UniConverter 14\\ffmpeg.exe',
        'C:\\Program Files\\FFmpeg\\bin\\ffmpeg.exe',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
    ]);

    $ffprobeCandidates = array_filter([
        getenv('VE_FFPROBE_PATH') ?: null,
        $localFfprobe,
        'ffprobe',
        'C:\\Program Files\\FFmpeg\\bin\\ffprobe.exe',
        'C:\\ffmpeg\\bin\\ffprobe.exe',
        '/usr/bin/ffprobe',
        '/usr/local/bin/ffprobe',
    ]);

    $config = [
        'root' => ve_video_storage_path(),
        'library' => ve_video_storage_path('library'),
        'tmp' => ve_video_storage_path('tmp'),
        'ffmpeg' => ve_video_find_binary($ffmpegCandidates),
        'ffprobe' => ve_video_find_binary($ffprobeCandidates),
        'php_binary' => ve_video_find_php_binary(),
        'encode_threads' => max(1, (int) (getenv('VE_VIDEO_THREADS') ?: 2)),
        'session_ttl' => max(300, (int) (getenv('VE_VIDEO_PLAY_TTL') ?: 900)),
        'worker_stale_after' => max(1800, (int) (getenv('VE_VIDEO_STALE_AFTER') ?: 7200)),
        'max_upload_bytes' => max(0, (int) (getenv('VE_VIDEO_MAX_UPLOAD_BYTES') ?: 0)),
        'segment_seconds' => max(4, (int) (getenv('VE_VIDEO_SEGMENT_SECONDS') ?: 6)),
        'encoder_preset' => trim((string) (getenv('VE_VIDEO_PRESET') ?: 'medium')),
        'target_max_width' => max(640, (int) (getenv('VE_VIDEO_MAX_WIDTH') ?: 1920)),
        'target_max_height' => max(360, (int) (getenv('VE_VIDEO_MAX_HEIGHT') ?: 1080)),
    ];

    return $config;
}

function ve_video_processing_available(): bool
{
    return is_string(ve_video_config()['ffmpeg']) && ve_video_config()['ffmpeg'] !== '';
}

function ve_video_parse_shorthand_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    if (!preg_match('/^(\d+(?:\.\d+)?)([KMGTP]?)/i', $value, $matches)) {
        return 0;
    }

    $number = (float) $matches[1];
    $suffix = strtoupper($matches[2] ?? '');
    $power = match ($suffix) {
        'P' => 5,
        'T' => 4,
        'G' => 3,
        'M' => 2,
        'K' => 1,
        default => 0,
    };

    return (int) round($number * (1024 ** $power));
}

function ve_video_upload_limit_bytes(): int
{
    $limits = [
        ve_video_parse_shorthand_bytes((string) ini_get('upload_max_filesize')),
        ve_video_parse_shorthand_bytes((string) ini_get('post_max_size')),
    ];

    $configured = (int) ve_video_config()['max_upload_bytes'];

    if ($configured > 0) {
        $limits[] = $configured;
    }

    $limits = array_values(array_filter($limits, static fn (int $limit): bool => $limit > 0));

    if ($limits === []) {
        return 0;
    }

    return min($limits);
}

function ve_video_library_directory(string $publicId): string
{
    return ve_video_storage_path('library', $publicId);
}

function ve_video_source_path(array $video): string
{
    $extension = strtolower((string) ($video['source_extension'] ?? 'mp4'));

    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'source.' . $extension;
}

function ve_video_playlist_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'stream.m3u8';
}

function ve_video_key_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'stream.key';
}

function ve_video_key_info_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'stream.keyinfo';
}

function ve_video_segment_pattern_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'part_%05d.bin';
}

function ve_video_poster_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'poster.jpg';
}

function ve_video_preview_sprite_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'preview-sprite.jpg';
}

function ve_video_preview_vtt_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'preview.vtt';
}

function ve_video_shell_join(array $parts): string
{
    return implode(' ', array_map(static function (string $part): string {
        $escaped = escapeshellarg($part);

        if (ve_video_is_windows()) {
            $escaped = str_replace('%', '%%', $escaped);
        }

        return $escaped;
    }, $parts));
}

function ve_video_run_command(array $arguments): array
{
    if (function_exists('proc_open')) {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($arguments, $descriptors, $pipes, ve_root_path(), null, ['bypass_shell' => true]);

        if (is_resource($process)) {
            $stdout = is_resource($pipes[1] ?? null) ? stream_get_contents($pipes[1]) : '';
            $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $exitCode = proc_close($process);

            return [$exitCode, trim($stdout . "\n" . $stderr)];
        }
    }

    $command = ve_video_shell_join($arguments) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return [$exitCode, trim(implode("\n", $output))];
}

function ve_video_title_from_filename(string $filename): string
{
    $title = pathinfo($filename, PATHINFO_FILENAME);
    $title = preg_replace('/[_\-.]+/', ' ', $title ?? '') ?? '';
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');

    if ($title === '') {
        return 'Untitled video';
    }

    return mb_substr($title, 0, 180);
}

function ve_video_generate_public_id(): string
{
    do {
        $id = substr(preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(12)) ?? '', 0, 12);
    } while ($id === '' || ve_video_get_by_public_id($id) !== null);

    return $id;
}

function ve_video_require_auth_json(): array
{
    $user = ve_current_user();

    if (!is_array($user)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Please sign in to upload and manage videos.',
        ], 401);
    }

    return $user;
}

function ve_video_require_api_access(string $requestKind): array
{
    $apiKey = ve_api_extract_key_from_request();

    if (is_string($apiKey) && $apiKey !== '') {
        $user = ve_find_user_by_api_key($apiKey);

        if (!is_array($user)) {
            ve_json([
                'status' => 'fail',
                'message' => 'The supplied API key is invalid.',
            ], 401);
        }

        $rateLimit = ve_api_rate_limit_state($user, $requestKind);
        ve_api_send_rate_limit_headers($rateLimit);

        if (($rateLimit['allowed'] ?? false) !== true) {
            ve_json([
                'status' => 'fail',
                'message' => (string) ($rateLimit['message'] ?? 'API access denied.'),
            ], (int) ($rateLimit['status'] ?? 403));
        }

        return [
            'mode' => 'api_key',
            'user' => $user,
            'request_kind' => $requestKind,
            'api_key_hash' => (string) ($user['api_key_hash'] ?? ve_api_key_hash($apiKey)),
            'rate_limit' => $rateLimit,
        ];
    }

    return [
        'mode' => 'session',
        'user' => ve_video_require_auth_json(),
        'request_kind' => $requestKind,
        'api_key_hash' => '',
        'rate_limit' => null,
    ];
}

function ve_video_require_csrf_json(): void
{
    $token = ve_request_csrf_token();

    if (!is_string($token) || $token === '' || !hash_equals(ve_csrf_token(), $token)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Your session token is invalid. Refresh the page and try again.',
        ], 419);
    }
}

function ve_video_api_respond(array $auth, array $payload, int $status = 200, int $bytesIn = 0): void
{
    if (($auth['mode'] ?? '') === 'api_key') {
        ve_api_record_request(
            (int) (($auth['user']['id'] ?? 0)),
            (string) ($auth['api_key_hash'] ?? ''),
            (string) ($auth['request_kind'] ?? 'request'),
            $status,
            $bytesIn
        );
    }

    ve_json($payload, $status);
}

function ve_video_get_by_id(int $videoId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM videos WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $videoId]);
    $video = $stmt->fetch();

    return is_array($video) ? $video : null;
}

function ve_video_get_by_public_id(string $publicId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM videos WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':public_id' => $publicId]);
    $video = $stmt->fetch();

    return is_array($video) ? $video : null;
}

function ve_video_get_for_user(int $userId, string $publicId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM videos WHERE user_id = :user_id AND public_id = :public_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([
        ':user_id' => $userId,
        ':public_id' => $publicId,
    ]);
    $video = $stmt->fetch();

    return is_array($video) ? $video : null;
}

function ve_video_list_for_user(int $userId): array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE user_id = :user_id AND deleted_at IS NULL
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ve_video_has_pending_jobs(): bool
{
    $stmt = ve_db()->query(
        "SELECT COUNT(*) FROM videos
         WHERE deleted_at IS NULL AND status IN ('" . VE_VIDEO_STATUS_QUEUED . "', '" . VE_VIDEO_STATUS_PROCESSING . "')"
    );

    return (int) $stmt->fetchColumn() > 0;
}

function ve_video_update_status(int $videoId, string $status, string $message, array $extra = []): void
{
    $columns = array_merge([
        'status' => $status,
        'status_message' => $message,
        'updated_at' => ve_now(),
    ], $extra);

    $assignments = [];
    $params = [':id' => $videoId];

    foreach ($columns as $column => $value) {
        $assignments[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }

    $stmt = ve_db()->prepare('UPDATE videos SET ' . implode(', ', $assignments) . ' WHERE id = :id');
    $stmt->execute($params);
}

function ve_video_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = (int) floor(log($bytes, 1024));
    $power = max(0, min($power, count($units) - 1));
    $value = $bytes / (1024 ** $power);

    return number_format($value, $power === 0 ? 0 : 2) . ' ' . $units[$power];
}

function ve_video_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded video exceeds the configured upload limit.',
        UPLOAD_ERR_PARTIAL => 'The video upload was interrupted before completion.',
        UPLOAD_ERR_NO_FILE => 'Choose a video file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded video to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'The video upload failed unexpectedly.',
    };
}

function ve_video_detect_local_file(string $path, string $filename = '', ?int $knownSize = null): array
{
    $filename = trim($filename);

    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('The source file is missing.');
    }

    $size = $knownSize ?? (int) (filesize($path) ?: 0);
    $extension = strtolower(pathinfo($filename !== '' ? $filename : basename($path), PATHINFO_EXTENSION));
    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($detected)) {
                $mime = strtolower($detected);
            }
        }
    }

    $isKnownExtension = in_array($extension, VE_VIDEO_ALLOWED_EXTENSIONS, true);
    $isLikelyVideoMime = str_starts_with($mime, 'video/')
        || in_array($mime, ['application/ogg', 'application/octet-stream'], true);

    if (!$isKnownExtension && !$isLikelyVideoMime) {
        throw new RuntimeException('Only video files can be uploaded.');
    }

    if ($size <= 0) {
        throw new RuntimeException('The selected file is empty.');
    }

    $limit = ve_video_upload_limit_bytes();

    if ($limit > 0 && $size > $limit) {
        throw new RuntimeException('This file exceeds the current upload limit of ' . ve_video_format_bytes($limit) . '.');
    }

    if ($extension === '') {
        $extension = 'mp4';
    }

    return [
        'filename' => $filename !== '' ? $filename : basename($path),
        'extension' => $extension,
        'size' => $size,
        'mime' => $mime,
    ];
}

function ve_video_validate_file(array $file): array
{
    try {
        return ve_video_detect_local_file(
            (string) ($file['tmp_name'] ?? ''),
            trim((string) ($file['name'] ?? '')),
            isset($file['size']) ? (int) $file['size'] : null
        );
    } catch (RuntimeException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 422);
    }
}

function ve_video_store_input_file(string $incomingPath, string $destinationPath, bool $isUploadedFile): bool
{
    if ($isUploadedFile) {
        return move_uploaded_file($incomingPath, $destinationPath);
    }

    if (@rename($incomingPath, $destinationPath)) {
        return true;
    }

    if (@copy($incomingPath, $destinationPath)) {
        @unlink($incomingPath);
        return true;
    }

    return false;
}

function ve_video_insert_queued_record(int $userId, array $validated, string $title): ?array
{
    $title = trim($title);

    if ($title === '') {
        $title = ve_video_title_from_filename((string) ($validated['filename'] ?? 'video.' . ($validated['extension'] ?? 'mp4')));
    }

    $title = mb_substr($title, 0, 180);
    $publicId = ve_video_generate_public_id();
    $now = ve_now();

    $stmt = ve_db()->prepare(
        'INSERT INTO videos (
            user_id, public_id, title, original_filename, source_extension, status, status_message,
            duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio,
            processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :public_id, :title, :original_filename, :source_extension, :status, :status_message,
            NULL, NULL, NULL, "", "",
            :original_size_bytes, 0, NULL,
            "", :created_at, :updated_at, :queued_at, NULL, NULL, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':public_id' => $publicId,
        ':title' => $title,
        ':original_filename' => (string) ($validated['filename'] ?? ''),
        ':source_extension' => (string) ($validated['extension'] ?? 'mp4'),
        ':status' => VE_VIDEO_STATUS_QUEUED,
        ':status_message' => 'Queued for compression and secure stream packaging.',
        ':original_size_bytes' => (int) ($validated['size'] ?? 0),
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
    ]);

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video)) {
        return null;
    }

    ve_add_notification($userId, 'Video queued', '"' . $title . '" is queued for compression.');
    ve_video_maybe_spawn_worker();

    return $video;
}

function ve_video_queue_local_source(
    int $userId,
    string $incomingPath,
    string $originalFilename,
    ?string $title = null,
    bool $isUploadedFile = false
): array {
    $validated = ve_video_detect_local_file($incomingPath, $originalFilename);
    $videoTitle = is_string($title) ? $title : '';
    $video = ve_video_insert_queued_record($userId, $validated, $videoTitle);

    if (!is_array($video)) {
        throw new RuntimeException('The video record could not be created.');
    }

    $directory = ve_video_library_directory((string) $video['public_id']);
    ve_ensure_directory($directory);

    $sourcePath = $directory . DIRECTORY_SEPARATOR . 'source.' . (string) $validated['extension'];

    if (!ve_video_store_input_file($incomingPath, $sourcePath, $isUploadedFile)) {
        ve_db()->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => (int) $video['id']]);
        throw new RuntimeException('The uploaded video could not be stored on the server.');
    }

    $storedVideo = ve_video_get_by_id((int) $video['id']);

    if (!is_array($storedVideo)) {
        throw new RuntimeException('The stored video could not be loaded again.');
    }

    return $storedVideo;
}

function ve_video_to_api_payload(array $video): array
{
    $status = (string) ($video['status'] ?? VE_VIDEO_STATUS_QUEUED);
    $originalBytes = (int) ($video['original_size_bytes'] ?? 0);
    $processedBytes = (int) ($video['processed_size_bytes'] ?? 0);
    $ratio = $originalBytes > 0 && $processedBytes > 0 ? round($processedBytes / $originalBytes, 4) : null;
    $savedPercent = $ratio !== null ? max(0, (1 - $ratio) * 100) : null;

    return [
        'public_id' => (string) ($video['public_id'] ?? ''),
        'title' => (string) ($video['title'] ?? 'Untitled video'),
        'original_filename' => (string) ($video['original_filename'] ?? ''),
        'status' => $status,
        'status_message' => (string) ($video['status_message'] ?? ''),
        'duration_seconds' => isset($video['duration_seconds']) ? (float) $video['duration_seconds'] : null,
        'width' => isset($video['width']) ? (int) $video['width'] : null,
        'height' => isset($video['height']) ? (int) $video['height'] : null,
        'video_codec' => (string) ($video['video_codec'] ?? ''),
        'audio_codec' => (string) ($video['audio_codec'] ?? ''),
        'original_size_bytes' => $originalBytes,
        'processed_size_bytes' => $processedBytes,
        'compression_ratio' => $ratio,
        'space_saved_percent' => $savedPercent !== null ? round($savedPercent, 2) : null,
        'created_at' => (string) ($video['created_at'] ?? ''),
        'updated_at' => (string) ($video['updated_at'] ?? ''),
        'ready_at' => (string) ($video['ready_at'] ?? ''),
        'watch_url' => ve_url('/d/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'embed_url' => ve_url('/e/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'delete_url' => ve_url('/api/videos/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'error' => $status === VE_VIDEO_STATUS_FAILED ? (string) ($video['processing_error'] ?? '') : '',
    ];
}

function ve_handle_video_list_api(): void
{
    $auth = ve_video_require_api_access('list');
    $user = $auth['user'];
    ve_video_maybe_spawn_worker();

    $videos = array_map('ve_video_to_api_payload', ve_video_list_for_user((int) $user['id']));
    $uploadLimit = ve_video_upload_limit_bytes();

    ve_video_api_respond($auth, [
        'status' => 'ok',
        'videos' => $videos,
        'capabilities' => [
            'processing_available' => ve_video_processing_available(),
            'ffmpeg' => ve_video_config()['ffmpeg'] !== null,
            'ffprobe' => ve_video_config()['ffprobe'] !== null,
            'segment_seconds' => (int) ve_video_config()['segment_seconds'],
            'max_upload_bytes' => $uploadLimit,
            'max_upload_human' => $uploadLimit > 0 ? ve_video_format_bytes($uploadLimit) : 'Server default',
        ],
    ]);
}

function ve_handle_video_upload_api(): void
{
    $auth = ve_video_require_api_access('upload');
    $user = $auth['user'];

    if (($auth['mode'] ?? '') === 'session') {
        ve_video_require_csrf_json();
    }

    if (!ve_video_processing_available()) {
        ve_video_api_respond($auth, [
            'status' => 'fail',
            'message' => 'FFmpeg is not available on this server yet. Configure VE_FFMPEG_PATH before accepting uploads.',
        ], 503);
    }

    if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
        ve_video_api_respond($auth, [
            'status' => 'fail',
            'message' => 'Choose a video file to upload.',
        ], 422);
    }

    $file = $_FILES['video'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        ve_video_api_respond($auth, [
            'status' => 'fail',
            'message' => ve_video_upload_error_message($error),
        ], 422);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $validated = ve_video_validate_file($file);

    try {
        $video = ve_video_queue_local_source(
            (int) $user['id'],
            (string) $file['tmp_name'],
            (string) ($validated['filename'] ?? (string) ($file['name'] ?? '')),
            $title,
            true
        );
    } catch (RuntimeException $exception) {
        ve_video_api_respond($auth, [
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 500);
    }

    ve_video_api_respond($auth, [
        'status' => 'ok',
        'message' => 'Upload received. Compression and secure HLS packaging started.',
        'video' => ve_video_to_api_payload($video),
    ], 201, (int) ($validated['size'] ?? 0));
}

function ve_handle_video_delete_api(string $publicId): void
{
    $auth = ve_video_require_api_access('delete');
    $user = $auth['user'];

    if (($auth['mode'] ?? '') === 'session') {
        ve_video_require_csrf_json();
    }

    $video = ve_video_get_for_user((int) $user['id'], $publicId);

    if (!is_array($video)) {
        ve_video_api_respond($auth, [
            'status' => 'fail',
            'message' => 'Video not found.',
        ], 404);
    }

    $directory = ve_video_library_directory((string) $video['public_id']);
    ve_video_delete_directory($directory);

    $stmt = ve_db()->prepare('DELETE FROM videos WHERE id = :id');
    $stmt->execute([':id' => (int) $video['id']]);

    ve_add_notification((int) $user['id'], 'Video deleted', '"' . (string) $video['title'] . '" was removed.');

    ve_video_api_respond($auth, [
        'status' => 'ok',
        'message' => 'Video deleted successfully.',
    ]);
}

function ve_video_delete_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);

    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            ve_video_delete_directory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

function ve_video_requeue_stale_jobs(): void
{
    $cutoff = gmdate('Y-m-d H:i:s', ve_timestamp() - (int) ve_video_config()['worker_stale_after']);
    $stmt = ve_db()->prepare(
        'UPDATE videos
         SET status = :queued, status_message = :message, updated_at = :updated_at
         WHERE status = :processing AND deleted_at IS NULL AND updated_at < :cutoff'
    );
    $stmt->execute([
        ':queued' => VE_VIDEO_STATUS_QUEUED,
        ':message' => 'Queued again after an interrupted processing worker.',
        ':updated_at' => ve_now(),
        ':processing' => VE_VIDEO_STATUS_PROCESSING,
        ':cutoff' => $cutoff,
    ]);
}

function ve_video_claim_next_job(): ?array
{
    $pdo = ve_db();
    $pdo->beginTransaction();

    try {
        $video = $pdo->query(
            "SELECT * FROM videos
             WHERE deleted_at IS NULL AND status = '" . VE_VIDEO_STATUS_QUEUED . "'
             ORDER BY created_at ASC, id ASC
             LIMIT 1"
        )->fetch();

        if (!is_array($video)) {
            $pdo->commit();
            return null;
        }

        $now = ve_now();
        $stmt = $pdo->prepare(
            'UPDATE videos
             SET status = :status,
                 status_message = :status_message,
                 processing_started_at = COALESCE(processing_started_at, :processing_started_at),
                 updated_at = :updated_at
             WHERE id = :id AND status = :expected_status'
        );
        $stmt->execute([
            ':status' => VE_VIDEO_STATUS_PROCESSING,
            ':status_message' => 'Analyzing source file.',
            ':processing_started_at' => $now,
            ':updated_at' => $now,
            ':id' => (int) $video['id'],
            ':expected_status' => VE_VIDEO_STATUS_QUEUED,
        ]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();

        return ve_video_get_by_id((int) $video['id']);
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

function ve_video_worker_is_running(): bool
{
    $handle = fopen(ve_video_processing_lock_path(), 'c+');

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

function ve_video_maybe_spawn_worker(): void
{
    if (!ve_video_processing_available() || !ve_video_has_pending_jobs() || ve_video_worker_is_running()) {
        return;
    }

    $phpBinary = (string) ve_video_config()['php_binary'];
    $script = ve_root_path('scripts', 'process_video_queue.php');
    $command = ve_video_shell_join([$phpBinary, $script]);

    if (ve_video_is_windows()) {
        @pclose(@popen('start /B "" ' . $command . ' >NUL 2>NUL', 'r'));
        return;
    }

    @exec($command . ' > /dev/null 2>&1 &');
}

function ve_video_process_pending_jobs(int $maxJobs = 0): int
{
    $handle = fopen(ve_video_processing_lock_path(), 'c+');

    if ($handle === false) {
        return 0;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return 0;
    }

    $processed = 0;

    try {
        ve_video_requeue_stale_jobs();

        while ($maxJobs === 0 || $processed < $maxJobs) {
            $video = ve_video_claim_next_job();

            if (!is_array($video)) {
                break;
            }

            ve_video_process_job((int) $video['id']);
            $processed++;
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    return $processed;
}

function ve_video_cleanup_output_files(array $video): void
{
    $directory = ve_video_library_directory((string) $video['public_id']);
    $items = scandir($directory);

    if (!is_array($items)) {
        return;
    }

    $sourcePath = ve_video_source_path($video);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if ($path === $sourcePath) {
            continue;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function ve_video_directory_size(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    $size = 0;
    $items = scandir($directory);

    if (!is_array($items)) {
        return 0;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if (is_file($path)) {
            $size += filesize($path) ?: 0;
        }
    }

    return $size;
}

function ve_video_mark_failed(int $videoId, string $message, string $details = ''): void
{
    $details = trim($details);

    if ($details !== '') {
        $message .= ' ' . mb_substr(preg_replace('/\s+/', ' ', $details) ?? '', 0, 500);
    }

    ve_video_update_status($videoId, VE_VIDEO_STATUS_FAILED, 'Processing failed.', [
        'processing_error' => $message,
    ]);

    $video = ve_video_get_by_id($videoId);

    if (is_array($video)) {
        ve_add_notification((int) $video['user_id'], 'Video processing failed', '"' . (string) $video['title'] . '" could not be processed.');
    }
}

function ve_video_choose_profile(array $metadata): array
{
    $height = (int) ($metadata['height'] ?? 0);
    $width = (int) ($metadata['width'] ?? 0);
    $maxWidth = (int) ve_video_config()['target_max_width'];
    $maxHeight = (int) ve_video_config()['target_max_height'];
    $targetWidth = max(2, $width > 0 ? min($maxWidth, $width) : $maxWidth);
    $targetHeight = max(2, $height > 0 ? min($maxHeight, $height) : $maxHeight);
    $targetWidth -= $targetWidth % 2;
    $targetHeight -= $targetHeight % 2;

    if ($targetWidth < 2) {
        $targetWidth = 2;
    }

    if ($targetHeight < 2) {
        $targetHeight = 2;
    }

    $vf = $width > 0 && $height > $width
        ? 'scale=-2:' . $targetHeight
        : 'scale=' . $targetWidth . ':-2';

    if ($height >= 1080 || $width >= 1920) {
        return [
            'vf' => $vf,
            'crf' => 23,
            'maxrate' => '4500k',
            'bufsize' => '9000k',
            'audio_bitrate' => '128k',
        ];
    }

    if ($height >= 720 || $width >= 1280) {
        return [
            'vf' => $vf,
            'crf' => 24,
            'maxrate' => '2500k',
            'bufsize' => '5000k',
            'audio_bitrate' => '128k',
        ];
    }

    if ($height >= 480 || $width >= 854) {
        return [
            'vf' => $vf,
            'crf' => 25,
            'maxrate' => '1200k',
            'bufsize' => '2400k',
            'audio_bitrate' => '96k',
        ];
    }

    return [
        'vf' => $vf,
        'crf' => 26,
        'maxrate' => '850k',
        'bufsize' => '1700k',
        'audio_bitrate' => '64k',
    ];
}

function ve_video_probe(string $sourcePath): ?array
{
    $ffprobe = ve_video_config()['ffprobe'];

    if (is_string($ffprobe) && $ffprobe !== '') {
        [$exitCode, $output] = ve_video_run_command([
            $ffprobe,
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $sourcePath,
        ]);

        if ($exitCode === 0 && $output !== '') {
            $payload = json_decode($output, true);

            if (is_array($payload)) {
                $videoStream = null;
                $audioStream = null;

                foreach (($payload['streams'] ?? []) as $stream) {
                    if (!is_array($stream)) {
                        continue;
                    }

                    if (($stream['codec_type'] ?? null) === 'video' && $videoStream === null) {
                        $videoStream = $stream;
                    }

                    if (($stream['codec_type'] ?? null) === 'audio' && $audioStream === null) {
                        $audioStream = $stream;
                    }
                }

                if (is_array($videoStream)) {
                    return [
                        'duration' => isset($payload['format']['duration']) ? (float) $payload['format']['duration'] : 0.0,
                        'width' => isset($videoStream['width']) ? (int) $videoStream['width'] : null,
                        'height' => isset($videoStream['height']) ? (int) $videoStream['height'] : null,
                        'video_codec' => (string) ($videoStream['codec_name'] ?? ''),
                        'audio_codec' => is_array($audioStream) ? (string) ($audioStream['codec_name'] ?? '') : '',
                    ];
                }
            }
        }
    }

    [$exitCode, $output] = ve_video_run_command([
        (string) ve_video_config()['ffmpeg'],
        '-i',
        $sourcePath,
    ]);

    if ($output === '') {
        return null;
    }

    preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/i', $output, $durationMatches);
    preg_match('/Video:\s*([^,\n]+).*?(\d{2,5})x(\d{2,5})/i', $output, $videoMatches);
    preg_match('/Audio:\s*([^,\n]+)/i', $output, $audioMatches);

    if ($videoMatches === []) {
        return null;
    }

    $duration = 0.0;

    if ($durationMatches !== []) {
        $duration = ((int) $durationMatches[1] * 3600)
            + ((int) $durationMatches[2] * 60)
            + (float) $durationMatches[3];
    }

    return [
        'duration' => $duration,
        'width' => isset($videoMatches[2]) ? (int) $videoMatches[2] : null,
        'height' => isset($videoMatches[3]) ? (int) $videoMatches[3] : null,
        'video_codec' => trim((string) ($videoMatches[1] ?? '')),
        'audio_codec' => trim((string) ($audioMatches[1] ?? '')),
    ];
}

function ve_video_preview_frame_count(): int
{
    return VE_VIDEO_PREVIEW_COLUMNS * VE_VIDEO_PREVIEW_ROWS;
}

function ve_video_format_webvtt_time(float $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    $wholeSeconds = (int) floor($seconds % 60);
    $milliseconds = (int) round(($seconds - floor($seconds)) * 1000);

    if ($milliseconds === 1000) {
        $milliseconds = 0;
        $wholeSeconds++;
    }

    if ($wholeSeconds >= 60) {
        $wholeSeconds -= 60;
        $minutes++;
    }

    if ($minutes >= 60) {
        $minutes -= 60;
        $hours++;
    }

    return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $wholeSeconds, $milliseconds);
}

function ve_video_write_preview_vtt(array $video, float $duration): bool
{
    $frameCount = ve_video_preview_frame_count();
    $segmentDuration = $duration > 0 ? max($duration / $frameCount, 0.5) : 1.0;
    $lines = ['WEBVTT', ''];

    for ($index = 0; $index < $frameCount; $index++) {
        $start = $index * $segmentDuration;
        $end = min(max($duration, $start + $segmentDuration), ($index + 1) * $segmentDuration);

        if ($end <= $start) {
            $end = $start + 0.5;
        }

        $x = ($index % VE_VIDEO_PREVIEW_COLUMNS) * VE_VIDEO_PREVIEW_TILE_WIDTH;
        $y = intdiv($index, VE_VIDEO_PREVIEW_COLUMNS) * VE_VIDEO_PREVIEW_TILE_HEIGHT;

        $lines[] = ve_video_format_webvtt_time($start) . ' --> ' . ve_video_format_webvtt_time($end);
        $lines[] = 'preview-sprite.jpg#xywh=' . $x . ',' . $y . ',' . VE_VIDEO_PREVIEW_TILE_WIDTH . ',' . VE_VIDEO_PREVIEW_TILE_HEIGHT;
        $lines[] = '';
    }

    return file_put_contents(ve_video_preview_vtt_path($video), implode("\n", $lines)) !== false;
}

function ve_video_generate_preview_assets(array $video, string $sourcePath, array $metadata): void
{
    $duration = max(0.0, (float) ($metadata['duration'] ?? 0.0));
    $posterOffset = $duration > 0 ? min(max($duration * 0.18, 1.0), max(1.0, $duration - 0.5)) : 1.0;
    $posterPath = ve_video_poster_path($video);
    $spritePath = ve_video_preview_sprite_path($video);
    $previewVttPath = ve_video_preview_vtt_path($video);
    $frameCount = ve_video_preview_frame_count();
    $fps = max($frameCount / max($duration, 1.0), 0.25);
    $fpsLabel = rtrim(rtrim(sprintf('%.6F', $fps), '0'), '.');

    [$posterExitCode] = ve_video_run_command([
        (string) ve_video_config()['ffmpeg'],
        '-y',
        '-hide_banner',
        '-loglevel',
        'error',
        '-ss',
        sprintf('%.3F', $posterOffset),
        '-i',
        $sourcePath,
        '-frames:v',
        '1',
        '-vf',
        'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:black',
        '-q:v',
        '4',
        $posterPath,
    ]);

    if ($posterExitCode !== 0 || !is_file($posterPath)) {
        @unlink($posterPath);
    }

    [$spriteExitCode] = ve_video_run_command([
        (string) ve_video_config()['ffmpeg'],
        '-y',
        '-hide_banner',
        '-loglevel',
        'error',
        '-i',
        $sourcePath,
        '-frames:v',
        '1',
        '-vf',
        'fps=' . $fpsLabel .
            ',scale=' . VE_VIDEO_PREVIEW_TILE_WIDTH . ':' . VE_VIDEO_PREVIEW_TILE_HEIGHT .
            ':force_original_aspect_ratio=decrease,pad=' . VE_VIDEO_PREVIEW_TILE_WIDTH . ':' . VE_VIDEO_PREVIEW_TILE_HEIGHT .
            ':(ow-iw)/2:(oh-ih)/2:black,tile=' . VE_VIDEO_PREVIEW_COLUMNS . 'x' . VE_VIDEO_PREVIEW_ROWS,
        '-q:v',
        '6',
        $spritePath,
    ]);

    if ($spriteExitCode !== 0 || !is_file($spritePath) || !ve_video_write_preview_vtt($video, $duration)) {
        @unlink($spritePath);
        @unlink($previewVttPath);
    }
}

function ve_video_owner_settings(array $video): array
{
    return ve_get_user_settings((int) ($video['user_id'] ?? 0));
}

function ve_video_resolve_poster_asset(array $video): ?array
{
    $settings = ve_video_owner_settings($video);
    $mode = trim((string) ($settings['player_image_mode'] ?? ''));

    if ($mode === 'splash') {
        $splashPath = ve_storage_relative_path_to_absolute((string) ($settings['splash_image_path'] ?? ''));

        if ($splashPath !== '' && is_file($splashPath)) {
            return [
                'path' => $splashPath,
                'mime' => ve_detect_file_mime_type($splashPath),
            ];
        }
    }

    if ($mode === 'single' || $mode === 'splash') {
        $posterPath = ve_video_poster_path($video);

        if (is_file($posterPath)) {
            return [
                'path' => $posterPath,
                'mime' => 'image/jpeg',
            ];
        }
    }

    return null;
}

function ve_video_process_job(int $videoId): void
{
    $video = ve_video_get_by_id($videoId);

    if (!is_array($video) || (string) ($video['deleted_at'] ?? '') !== '') {
        return;
    }

    $directory = ve_video_library_directory((string) $video['public_id']);
    $sourcePath = ve_video_source_path($video);

    if (!is_file($sourcePath)) {
        ve_video_mark_failed((int) $video['id'], 'Source file is missing.');
        return;
    }

    $metadata = ve_video_probe($sourcePath);

    if (!is_array($metadata)) {
        ve_video_mark_failed((int) $video['id'], 'Unable to inspect the uploaded video.');
        return;
    }

    $profile = ve_video_choose_profile($metadata);
    ve_video_update_status((int) $video['id'], VE_VIDEO_STATUS_PROCESSING, 'Compressing video and packaging secure stream.');

    ve_video_cleanup_output_files($video);

    $playlistPath = ve_video_playlist_path($video);
    $keyPath = ve_video_key_path($video);
    $keyInfoPath = ve_video_key_info_path($video);

    file_put_contents($keyPath, random_bytes(16));
    file_put_contents($keyInfoPath, "__KEY_URI__\n" . str_replace('\\', '/', $keyPath) . "\n");

    $args = [
        (string) ve_video_config()['ffmpeg'],
        '-y',
        '-hide_banner',
        '-loglevel',
        'error',
        '-i',
        $sourcePath,
        '-map',
        '0:v:0',
        '-map',
        '0:a:0?',
        '-sn',
        '-dn',
        '-threads',
        (string) ve_video_config()['encode_threads'],
        '-vf',
        $profile['vf'],
        '-c:v',
        'libx264',
        '-preset',
        (string) ve_video_config()['encoder_preset'],
        '-crf',
        (string) $profile['crf'],
        '-profile:v',
        'high',
        '-pix_fmt',
        'yuv420p',
        '-maxrate',
        $profile['maxrate'],
        '-bufsize',
        $profile['bufsize'],
        '-g',
        '48',
        '-keyint_min',
        '48',
        '-sc_threshold',
        '0',
        '-c:a',
        'aac',
        '-ac',
        '2',
        '-ar',
        '48000',
        '-b:a',
        $profile['audio_bitrate'],
        '-f',
        'hls',
        '-hls_time',
        (string) ve_video_config()['segment_seconds'],
        '-hls_list_size',
        '0',
        '-hls_playlist_type',
        'vod',
        '-hls_segment_filename',
        ve_video_segment_pattern_path($video),
        '-hls_key_info_file',
        $keyInfoPath,
        $playlistPath,
    ];

    [$exitCode, $output] = ve_video_run_command($args);
    @unlink($keyInfoPath);

    if ($exitCode !== 0 || !is_file($playlistPath)) {
        ve_video_mark_failed((int) $video['id'], 'FFmpeg failed while compressing the video.', $output);
        return;
    }

    ve_video_generate_preview_assets($video, $sourcePath, $metadata);

    @unlink($sourcePath);

    $processedSize = ve_video_directory_size($directory);
    $originalSize = (int) ($video['original_size_bytes'] ?? 0);
    $ratio = $originalSize > 0 && $processedSize > 0 ? round($processedSize / $originalSize, 4) : null;
    $now = ve_now();

    ve_video_update_status((int) $video['id'], VE_VIDEO_STATUS_READY, 'Ready to stream.', [
        'duration_seconds' => $metadata['duration'],
        'width' => $metadata['width'],
        'height' => $metadata['height'],
        'video_codec' => 'h264',
        'audio_codec' => $metadata['audio_codec'],
        'processed_size_bytes' => $processedSize,
        'compression_ratio' => $ratio,
        'processing_error' => '',
        'ready_at' => $now,
    ]);

    ve_add_notification((int) $video['user_id'], 'Video ready', '"' . (string) $video['title'] . '" is ready for secure playback.');
}

function ve_video_playback_signature(string $value): string
{
    return hash_hmac('sha256', $value, ve_app_secret());
}

function ve_video_playback_cookie_name(string $publicId): string
{
    return 've_play_' . preg_replace('/[^A-Za-z0-9_]/', '_', $publicId);
}

function ve_video_issue_playback_session(array $video): array
{
    $token = ve_random_token(24);
    $tokenHash = ve_video_playback_signature($token);
    $ip = ve_client_ip();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $expiresAt = gmdate('Y-m-d H:i:s', ve_timestamp() + (int) ve_video_config()['session_ttl']);
    $user = ve_current_user();

    ve_db()->prepare('DELETE FROM video_playback_sessions WHERE revoked_at IS NOT NULL OR expires_at < :now')
        ->execute([':now' => ve_now()]);

    $stmt = ve_db()->prepare(
        'INSERT INTO video_playback_sessions (
            video_id, session_token_hash, owner_user_id, ip_hash, user_agent_hash,
            expires_at, created_at, last_seen_at, revoked_at
        ) VALUES (
            :video_id, :session_token_hash, :owner_user_id, :ip_hash, :user_agent_hash,
            :expires_at, :created_at, :last_seen_at, NULL
        )'
    );
    $stmt->execute([
        ':video_id' => (int) $video['id'],
        ':session_token_hash' => $tokenHash,
        ':owner_user_id' => is_array($user) ? (int) $user['id'] : null,
        ':ip_hash' => ve_video_playback_signature($ip),
        ':user_agent_hash' => ve_video_playback_signature($userAgent),
        ':expires_at' => $expiresAt,
        ':created_at' => ve_now(),
        ':last_seen_at' => ve_now(),
    ]);

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $cookiePath = ve_url('/stream/' . rawurlencode((string) $video['public_id']));

    setcookie(ve_video_playback_cookie_name((string) $video['public_id']), $token, [
        'expires' => ve_timestamp() + (int) ve_video_config()['session_ttl'],
        'path' => $cookiePath,
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);

    return [
        'token' => $token,
        'manifest_url' => ve_url('/stream/' . rawurlencode((string) $video['public_id']) . '/manifest.m3u8?token=' . rawurlencode($token)),
    ];
}

function ve_video_validate_playback_session(array $video, ?string $token): ?array
{
    if (!is_string($token) || $token === '') {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_playback_sessions
         WHERE video_id = :video_id
         AND session_token_hash = :session_token_hash
         AND revoked_at IS NULL
         AND expires_at >= :now
         LIMIT 1'
    );
    $stmt->execute([
        ':video_id' => (int) $video['id'],
        ':session_token_hash' => ve_video_playback_signature($token),
        ':now' => ve_now(),
    ]);
    $session = $stmt->fetch();

    if (!is_array($session)) {
        return null;
    }

    $ip = ve_client_ip();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    if (!hash_equals((string) $session['ip_hash'], ve_video_playback_signature($ip))) {
        return null;
    }

    if (!hash_equals((string) $session['user_agent_hash'], ve_video_playback_signature($userAgent))) {
        return null;
    }

    $headerToken = $_SERVER['HTTP_X_PLAYBACK_SESSION'] ?? '';
    $cookieToken = $_COOKIE[ve_video_playback_cookie_name((string) $video['public_id'])] ?? '';
    $fetchDest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));

    $headerValid = is_string($headerToken) && $headerToken !== '' && hash_equals($token, $headerToken);
    $cookieValid = is_string($cookieToken) && $cookieToken !== '' && hash_equals($token, $cookieToken) && $fetchDest !== 'document';

    if (!$headerValid && !$cookieValid) {
        return null;
    }

    $update = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET last_seen_at = :last_seen_at, expires_at = :expires_at
         WHERE id = :id'
    );
    $update->execute([
        ':last_seen_at' => ve_now(),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + (int) ve_video_config()['session_ttl']),
        ':id' => (int) $session['id'],
    ]);

    return $session;
}

function ve_video_stream_access_denied(): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Playback session is invalid or expired.';
    exit;
}

function ve_video_stream_manifest(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $playlistPath = ve_video_playlist_path($video);

    if (!is_file($playlistPath)) {
        ve_not_found();
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($playlistPath)) ?: [];
    $keyUrl = ve_url('/stream/' . rawurlencode($publicId) . '/key?token=' . rawurlencode($token));

    foreach ($lines as &$line) {
        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '#EXT-X-KEY:')) {
            $line = preg_replace('/URI="[^"]*"/', 'URI="' . $keyUrl . '"', $line) ?? $line;
            continue;
        }

        if ($line[0] === '#') {
            continue;
        }

        $line = ve_url('/stream/' . rawurlencode($publicId) . '/segment/' . rawurlencode(basename($line)) . '?token=' . rawurlencode($token));
    }
    unset($line);

    header('Content-Type: application/vnd.apple.mpegurl; charset=UTF-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    echo implode("\n", $lines);
    exit;
}

function ve_video_stream_key(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $path = ve_video_key_path($video);

    if (!is_file($path)) {
        ve_not_found();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_stream_segment(string $publicId, string $filename): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $filename = basename($filename);

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $filename)) {
        ve_not_found();
    }

    $path = ve_video_library_directory($publicId) . DIRECTORY_SEPARATOR . $filename;

    if (!is_file($path)) {
        ve_not_found();
    }

    header('Content-Type: video/mp2t');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_stream_poster(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $asset = ve_video_resolve_poster_asset($video);

    if (!is_array($asset) || !is_file((string) ($asset['path'] ?? ''))) {
        ve_not_found();
    }

    header('Content-Type: ' . (string) ($asset['mime'] ?? 'image/jpeg'));
    header('Content-Length: ' . (string) filesize((string) $asset['path']));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile((string) $asset['path']);
    exit;
}

function ve_video_stream_preview_sprite(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $path = ve_video_preview_sprite_path($video);

    if (!is_file($path)) {
        ve_not_found();
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_stream_preview_vtt(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';

    if (ve_video_validate_playback_session($video, $token) === null) {
        ve_video_stream_access_denied();
    }

    $path = ve_video_preview_vtt_path($video);

    if (!is_file($path)) {
        ve_not_found();
    }

    $spriteUrl = ve_url('/stream/' . rawurlencode($publicId) . '/preview.jpg?token=' . rawurlencode($token));
    $payload = (string) file_get_contents($path);
    $payload = str_replace('preview-sprite.jpg', $spriteUrl, $payload);

    header('Content-Type: text/vtt; charset=UTF-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $payload;
    exit;
}

function ve_video_secure_player_script(array $session, ?string $previewVttUrl = null): string
{
    $manifestUrl = json_encode(ve_absolute_url((string) $session['manifest_url']), JSON_UNESCAPED_SLASHES);
    $token = json_encode((string) $session['token'], JSON_UNESCAPED_SLASHES);
    $homeUrl = json_encode(ve_absolute_url('/'), JSON_UNESCAPED_SLASHES);
    $previewUrl = json_encode($previewVttUrl !== null ? ve_absolute_url($previewVttUrl) : '', JSON_UNESCAPED_SLASHES);

    return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
<script src="https://cdn.jsdelivr.net/npm/plyr@3/dist/plyr.polyfilled.min.js"></script>
<script>
    (function () {
        var manifestUrl = {$manifestUrl};
        var token = {$token};
        var previewUrl = {$previewUrl};
        var fallbackUrl = {$homeUrl};
        var video = document.getElementById('ve-secure-player');
        var stage = document.querySelector('.ve-stage');
        var state = document.getElementById('ve-player-state');
        var player = null;

        function setState(message, isError) {
            if (!state) {
                return;
            }

            state.textContent = message || '';
            state.className = isError ? 've-player-state is-error' : 've-player-state';
        }

        function attemptAutoplay() {
            var playPromise = video.play();

            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {});
            }
        }

        function bootUi() {
            if (!video || !window.Plyr || player) {
                return;
            }

            player = new window.Plyr(video, {
                controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'pip', 'fullscreen'],
                settings: ['speed'],
                keyboard: { focused: true, global: false },
                disableContextMenu: true,
                previewThumbnails: previewUrl ? { enabled: true, src: previewUrl } : { enabled: false }
            });
        }

        function attachNative() {
            video.src = manifestUrl;
            video.addEventListener('loadedmetadata', function () {
                setState('');
                attemptAutoplay();
            }, { once: true });
            video.addEventListener('error', function () {
                setState('Secure playback could not be started in this browser.', true);
            });
        }

        if (!video) {
            return;
        }

        if (stage) {
            stage.addEventListener('contextmenu', function (event) {
                event.preventDefault();
            });
        }

        bootUi();
        setState('Securing playback session...');

        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls({
                xhrSetup: function (xhr) {
                    xhr.withCredentials = true;
                    xhr.setRequestHeader('X-Playback-Session', token);
                }
            });

            hls.on(window.Hls.Events.MEDIA_ATTACHED, function () {
                setState('Loading encrypted video stream...');
                hls.loadSource(manifestUrl);
            });

            hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
                setState('');
                attemptAutoplay();
            });

            hls.on(window.Hls.Events.ERROR, function (event, data) {
                if (data && data.fatal) {
                    setState('Playback session expired or could not be loaded. Reload the page to continue.', true);
                }
            });

            hls.attachMedia(video);
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            setState('Loading encrypted video stream...');
            attachNative();
            return;
        }

        setState('This browser cannot play the secure stream. Open the watch page in a modern browser.', true);
        window.setTimeout(function () {
            if (!video.currentSrc) {
                window.location.href = fallbackUrl;
            }
        }, 1200);
    }());
</script>
HTML;
}

function ve_render_secure_video_page(string $publicId, bool $embed = false): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video)) {
        ve_not_found();
    }

    $title = ve_h((string) $video['title']);

    if ((string) $video['status'] !== VE_VIDEO_STATUS_READY) {
        $message = match ((string) $video['status']) {
            VE_VIDEO_STATUS_FAILED => ve_h((string) (($video['processing_error'] ?? '') !== '' ? $video['processing_error'] : 'This video could not be processed.')),
            VE_VIDEO_STATUS_PROCESSING => 'This video is still being compressed and secured for streaming.',
            default => 'This video is queued for processing.',
        };
        $refresh = (string) $video['status'] === VE_VIDEO_STATUS_FAILED ? '' : '<meta http-equiv="refresh" content="12">';
        $bootstrapCss = ve_h(ve_url('/assets/css/bootstrap.min.css'));

        ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    {$refresh}
    <link rel="stylesheet" href="{$bootstrapCss}">
    <style>
        body { margin:0; background:#050505; color:#fff; font-family:Arial,sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .ve-status-card { max-width:720px; width:100%; background:#111; border:1px solid rgba(255,255,255,0.08); border-radius:20px; padding:32px; box-shadow:0 25px 60px rgba(0,0,0,0.45); }
        .ve-status-card h1 { font-size:1.6rem; margin:0 0 14px; }
        .ve-status-card p { color:#c8c8c8; margin:0; line-height:1.6; }
    </style>
</head>
<body>
    <div class="ve-status-card">
        <h1>{$title}</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML);
    }

    $ownerSettings = ve_video_owner_settings($video);
    $session = ve_video_issue_playback_session($video);
    $duration = (float) ($video['duration_seconds'] ?? 0);
    $durationLabel = $duration > 0 ? gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', (int) round($duration)) : 'Ready';
    $previewVttUrl = is_file(ve_video_preview_vtt_path($video))
        ? '/stream/' . rawurlencode($publicId) . '/preview.vtt?token=' . rawurlencode((string) $session['token'])
        : null;
    $script = ve_video_secure_player_script($session, $previewVttUrl);
    $watchUrl = ve_h(ve_absolute_url('/d/' . rawurlencode($publicId)));
    $embedUrl = ve_h(ve_absolute_url('/e/' . rawurlencode($publicId)));
    $showEmbedTitle = !$embed || (bool) ($ownerSettings['show_embed_title'] ?? false);
    $playerColour = strtolower(trim((string) ($ownerSettings['player_colour'] ?? 'ff9900')));

    if (!preg_match('/^[a-f0-9]{6}$/', $playerColour)) {
        $playerColour = 'ff9900';
    }

    $posterAsset = ve_video_resolve_poster_asset($video);
    $posterAttribute = '';

    if (is_array($posterAsset)) {
        $posterUrl = ve_h(ve_url('/stream/' . rawurlencode($publicId) . '/poster.jpg?token=' . rawurlencode((string) $session['token'])));
        $posterAttribute = ' poster="' . $posterUrl . '"';
    }

    $logoPath = trim((string) ($ownerSettings['logo_path'] ?? ''));
    $logoBadge = '';

    if ($logoPath !== '') {
        $logoBadgeUrl = ve_h(ve_url('/' . ltrim($logoPath, '/')));
        $logoBadge = '<img class="ve-logo-badge" src="' . $logoBadgeUrl . '" alt="Player logo">';
    }

    $bodyPadding = $embed ? '0' : '32px 20px 48px';
    $cardRadius = $embed ? '0' : '24px';
    $boxShadow = $embed ? 'none' : '0 30px 80px rgba(0,0,0,0.45)';
    $bodyPanelPadding = $embed ? ($showEmbedTitle ? '16px 18px 18px' : '0') : '24px';
    $titleSize = $embed ? '1rem' : '1.8rem';

    $playerMeta = '';
    $sharePanel = '';

    if (!$embed) {
        $safeDurationLabel = ve_h($durationLabel);
        $playerMeta = '<div class="ve-meta"><span>Secure HLS stream</span><span>' . $safeDurationLabel . '</span></div>';
        $sharePanel = <<<HTML
<div class="ve-links">
    <label>Watch page</label>
    <input type="text" readonly value="{$watchUrl}">
    <label>Embed page</label>
    <input type="text" readonly value="{$embedUrl}">
</div>
HTML;
    }

    $titleMarkup = $showEmbedTitle ? '<h1>' . $title . '</h1>' : '';

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/plyr@3/dist/plyr.css">
    <style>
        :root {
            --ve-bg: #040404;
            --ve-panel: #101010;
            --ve-border: rgba(255, 255, 255, 0.08);
            --ve-text: #f5f5f5;
            --ve-muted: #a8a8a8;
            --ve-accent: #{$playerColour};
            --plyr-color-main: #{$playerColour};
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background:
                radial-gradient(circle at top, rgba(255, 153, 0, 0.16), transparent 32%),
                linear-gradient(180deg, #090909 0%, #020202 100%);
            color: var(--ve-text);
            font-family: Arial, sans-serif;
            min-height: 100vh;
        }
        .ve-shell {
            max-width: 1120px;
            margin: 0 auto;
            padding: {$bodyPadding};
        }
        .ve-card {
            background: var(--ve-panel);
            border: 1px solid var(--ve-border);
            border-radius: {$cardRadius};
            overflow: hidden;
            box-shadow: {$boxShadow};
        }
        .ve-stage {
            position: relative;
            aspect-ratio: 16 / 9;
            background: #000;
        }
        .ve-stage .plyr,
        .ve-stage video {
            width: 100%;
            height: 100%;
            display: block;
            background: #000;
        }
        .ve-stage .plyr--full-ui input[type=range] {
            color: var(--ve-accent);
        }
        .ve-logo-badge {
            position: absolute;
            top: 18px;
            left: 18px;
            z-index: 5;
            max-width: min(22%, 150px);
            max-height: 72px;
            object-fit: contain;
            pointer-events: none;
            filter: drop-shadow(0 10px 22px rgba(0,0,0,0.45));
        }
        .ve-body {
            padding: {$bodyPanelPadding};
        }
        h1 {
            margin: 0 0 8px;
            font-size: {$titleSize};
            line-height: 1.2;
        }
        .ve-meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            color: var(--ve-muted);
            font-size: 0.95rem;
        }
        .ve-links {
            display: grid;
            gap: 10px;
        }
        .ve-links label {
            color: var(--ve-muted);
            font-size: 0.9rem;
            margin: 0;
        }
        .ve-links input {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.12);
            background: #070707;
            color: #ddd;
            padding: 12px 14px;
            border-radius: 12px;
        }
        .ve-player-state {
            position: absolute;
            left: 16px;
            right: 16px;
            bottom: 16px;
            padding: 12px 14px;
            background: rgba(10, 10, 10, 0.82);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            color: #f4f4f4;
            font-size: 0.92rem;
            backdrop-filter: blur(10px);
        }
        .ve-player-state.is-error {
            border-color: rgba(255, 85, 85, 0.35);
            color: #ff9b9b;
        }
    </style>
</head>
<body>
    <div class="ve-shell">
        <div class="ve-card">
            <div class="ve-stage">
                {$logoBadge}
                <video
                    id="ve-secure-player"
                    controls
                    playsinline
                    preload="metadata"
                    controlsList="nodownload noplaybackrate"
                    disablepictureinpicture
                    crossorigin="use-credentials"{$posterAttribute}
                ></video>
                <div id="ve-player-state" class="ve-player-state"></div>
            </div>
            <div class="ve-body">{$titleMarkup}{$playerMeta}{$sharePanel}</div>
        </div>
    </div>
    {$script}
</body>
</html>
HTML);
}

function ve_video_portal_assets(): string
{
    $css = ve_url('/assets/css/video_portal.css');
    $js = ve_url('/assets/js/video_portal.js');

    return '<link rel="stylesheet" href="' . ve_h($css) . '">' . "\n" .
        '<script src="' . ve_h($js) . '" defer></script>';
}

function ve_video_home_panel(?array $user): string
{
    $auth = $user === null ? '0' : '1';
    $dashboardUrl = ve_url('/dashboard/videos');

    return <<<HTML
<section class="ve-home-panel">
    <div id="ve-home-videos" class="ve-video-portal ve-home-portal" data-auth="{$auth}" data-scope="home" data-dashboard-url="{$dashboardUrl}">
        <div class="ve-portal-copy">
            <p class="ve-eyebrow">Efficient Video Hosting</p>
            <h1>Upload once, store less, stream securely.</h1>
            <p class="ve-copy">Videos are compressed into token-protected HLS streams so bandwidth and storage scale better without exposing a raw MP4 download URL.</p>
        </div>
        <div class="ve-portal-app">
            <div class="ve-portal-loader">Loading upload module...</div>
        </div>
    </div>
</section>
HTML;
}

function ve_video_dashboard_panel(): string
{
    return <<<HTML
<section class="ve-dashboard-panel">
    <div id="ve-dashboard-videos" class="ve-video-portal" data-auth="1" data-scope="dashboard">
        <div class="ve-portal-loader">Loading video dashboard...</div>
    </div>
</section>
HTML;
}

function ve_render_home_page(): void
{
    $user = ve_current_user();
    $html = (string) file_get_contents(ve_root_path('index.html'));
    $html = ve_runtime_html_transform($html, 'index.html');
    $html = str_replace('<script src="/assets/js/home_page.js" type="text/javascript"></script>', '', $html);
    $html = str_replace('<home-upload :upload="{ utype: \'anon\', sess_id: \'\' }"></home-upload>', ve_video_home_panel($user), $html);
    $html = str_replace('</head>', ve_video_portal_assets() . "\n</head>", $html);

    if (is_array($user)) {
        $logoutForm = '<form method="POST" action="' . ve_h(ve_url('/logout')) . '" class="form-inline ml-0 ml-sm-3">' .
            '<input type="hidden" name="token" value="' . ve_h(ve_csrf_token()) . '">' .
            '<button type="submit" class="btn btn-primary">Logout</button>' .
            '</form>';
        $dashboardLink = '<li class="nav-item"><a class="nav-link" href="' . ve_h(ve_url('/dashboard/videos')) . '">Dashboard</a></li>';
        $html = str_replace('<li class="nav-item"> <a class="nav-link" data-toggle="modal" data-target="#login" href="#login">Sign in</a> </li>', $dashboardLink, $html);
        $html = preg_replace('/<div class="form-inline ml-0 ml-sm-3">.*?<\/div>/s', $logoutForm, $html, 1) ?? $html;
    }

    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_videos_dashboard_page(): void
{
    $html = (string) file_get_contents(ve_root_path('dashboard', 'videos.html'));
    $html = ve_runtime_html_transform($html, 'dashboard/videos.html');
    $html = str_replace('<script src="/assets/js/video_page__q_f247575e8408.js"></script>', '', $html);
    $html = str_replace('<video-manager :ask-content-type="0" embed-code-width="600" embed-code-height="480"></video-manager>', ve_video_dashboard_panel(), $html);
    $html = str_replace('</head>', ve_video_portal_assets() . "\n</head>", $html);
    ve_html(ve_rewrite_html_paths($html));
}
