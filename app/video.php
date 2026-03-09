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

function ve_video_normalize_library_directory(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }

    @chmod($path, 0775);

    if (DIRECTORY_SEPARATOR === '\\') {
        return;
    }

    $parent = dirname($path);

    if ($parent === '' || $parent === $path || !is_dir($parent)) {
        return;
    }

    $owner = @fileowner($parent);
    $group = @filegroup($parent);

    if (is_int($owner) && $owner >= 0) {
        @chown($path, $owner);
    }

    if (is_int($group) && $group >= 0) {
        @chgrp($path, $group);
    }

    @chmod($path, 0775);
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
        'download_wait_free' => max(0, (int) (getenv('VE_VIDEO_DOWNLOAD_WAIT_FREE') ?: 15)),
        'download_wait_premium' => max(0, (int) (getenv('VE_VIDEO_DOWNLOAD_WAIT_PREMIUM') ?: 0)),
        'download_request_ttl' => max(60, (int) (getenv('VE_VIDEO_DOWNLOAD_REQUEST_TTL') ?: 600)),
        'download_ready_ttl' => max(15, (int) (getenv('VE_VIDEO_DOWNLOAD_READY_TTL') ?: 45)),
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
    $directory = ve_video_storage_path('library', $publicId);
    ve_ensure_directory($directory);
    ve_video_normalize_library_directory($directory);

    return $directory;
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

function ve_video_download_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'download.mp4';
}

function ve_video_download_lock_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'download.lock';
}

function ve_video_download_playlist_path(array $video): string
{
    return ve_video_library_directory((string) $video['public_id']) . DIRECTORY_SEPARATOR . 'download-build.m3u8';
}

function ve_video_download_session_hash(): string
{
    $sessionId = session_id();

    if ($sessionId === '') {
        return hash('sha256', 'no-session');
    }

    return hash('sha256', $sessionId);
}

function ve_video_download_wait_seconds_for_viewer(?array $user = null): int
{
    $user = is_array($user) ? $user : ve_current_user();

    return is_array($user) && ve_user_is_premium($user)
        ? (int) ve_video_config()['download_wait_premium']
        : (int) ve_video_config()['download_wait_free'];
}

function ve_video_download_filename(array $video): string
{
    $sourcePath = ve_video_source_path($video);
    $extension = is_file($sourcePath)
        ? strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION))
        : 'mp4';

    if ($extension === '' || preg_match('/^[a-z0-9]{2,5}$/', $extension) !== 1) {
        $extension = 'mp4';
    }

    $baseName = trim((string) pathinfo((string) ($video['original_filename'] ?? ''), PATHINFO_FILENAME));

    if ($baseName === '') {
        $baseName = trim((string) ($video['title'] ?? ''));
    }

    if ($baseName === '') {
        $baseName = 'video-' . (string) ($video['public_id'] ?? '');
    }

    return ve_remote_sanitize_filename($baseName . '.' . $extension, 'video.' . $extension);
}

function ve_video_download_post_url(array $video): string
{
    return ve_absolute_url('/download/' . rawurlencode((string) ($video['public_id'] ?? '')));
}

function ve_video_download_label(array $video): string
{
    return is_file(ve_video_source_path($video)) ? 'Original' : 'MP4';
}

function ve_video_download_size_bytes(array $video): int
{
    $sourcePath = ve_video_source_path($video);

    if (is_file($sourcePath)) {
        return (int) (filesize($sourcePath) ?: 0);
    }

    $downloadPath = ve_video_download_path($video);

    if (is_file($downloadPath)) {
        return (int) (filesize($downloadPath) ?: 0);
    }

    $processedBytes = (int) ($video['processed_size_bytes'] ?? 0);

    if ($processedBytes > 0) {
        return $processedBytes;
    }

    return (int) ($video['original_size_bytes'] ?? 0);
}

function ve_video_download_available(array $video): bool
{
    if ((string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        return false;
    }

    if (is_file(ve_video_source_path($video)) || is_file(ve_video_download_path($video))) {
        return true;
    }

    return ve_video_processing_available()
        && is_file(ve_video_playlist_path($video))
        && is_file(ve_video_key_path($video));
}

function ve_video_cleanup_download_grants(): void
{
    ve_db()->prepare(
        'DELETE FROM video_download_grants
         WHERE revoked_at IS NOT NULL
            OR used_at IS NOT NULL
            OR expires_at < :now'
    )->execute([':now' => ve_now()]);
}

function ve_video_download_request_payload(array $video): array
{
    $user = ve_current_user();
    $waitSeconds = ve_video_download_wait_seconds_for_viewer($user);
    $now = ve_now();
    $availableAt = gmdate('Y-m-d H:i:s', ve_timestamp() + $waitSeconds);
    $expiresAt = gmdate('Y-m-d H:i:s', ve_timestamp() + max(
        (int) ve_video_config()['download_request_ttl'],
        $waitSeconds + (int) ve_video_config()['download_ready_ttl']
    ));

    return [
        'session_id_hash' => ve_video_download_session_hash(),
        'ip_hash' => ve_video_playback_signature(ve_client_ip()),
        'user_agent_hash' => ve_video_playback_signature(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)),
        'viewer_user_id' => is_array($user) ? (int) ($user['id'] ?? 0) : null,
        'wait_seconds' => $waitSeconds,
        'available_at' => $availableAt,
        'expires_at' => $expiresAt,
        'created_at' => $now,
    ];
}

function ve_video_find_active_download_grant(array $video): ?array
{
    $request = ve_video_download_request_payload($video);
    $stmt = ve_db()->prepare(
        'SELECT * FROM video_download_grants
         WHERE video_id = :video_id
           AND session_id_hash = :session_id_hash
           AND ip_hash = :ip_hash
           AND user_agent_hash = :user_agent_hash
           AND revoked_at IS NULL
           AND used_at IS NULL
           AND expires_at >= :now
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':video_id' => (int) ($video['id'] ?? 0),
        ':session_id_hash' => (string) $request['session_id_hash'],
        ':ip_hash' => (string) $request['ip_hash'],
        ':user_agent_hash' => (string) $request['user_agent_hash'],
        ':now' => ve_now(),
    ]);
    $grant = $stmt->fetch();

    return is_array($grant) ? $grant : null;
}

function ve_video_issue_download_request(array $video): array
{
    ve_video_cleanup_download_grants();

    $existing = ve_video_find_active_download_grant($video);

    if (is_array($existing)) {
        ve_db()->prepare(
            'UPDATE video_download_grants
             SET revoked_at = :revoked_at
             WHERE id = :id'
        )->execute([
            ':revoked_at' => ve_now(),
            ':id' => (int) ($existing['id'] ?? 0),
        ]);
    }

    $requestToken = ve_random_token(24);
    $payload = ve_video_download_request_payload($video);
    $stmt = ve_db()->prepare(
        'INSERT INTO video_download_grants (
            video_id, viewer_user_id, session_id_hash, request_token_hash, download_token_hash,
            ip_hash, user_agent_hash, wait_seconds, available_at, expires_at, created_at, issued_at, used_at, revoked_at
        ) VALUES (
            :video_id, :viewer_user_id, :session_id_hash, :request_token_hash, NULL,
            :ip_hash, :user_agent_hash, :wait_seconds, :available_at, :expires_at, :created_at, NULL, NULL, NULL
        )'
    );
    $stmt->execute([
        ':video_id' => (int) ($video['id'] ?? 0),
        ':viewer_user_id' => $payload['viewer_user_id'],
        ':session_id_hash' => (string) $payload['session_id_hash'],
        ':request_token_hash' => ve_video_playback_signature($requestToken),
        ':ip_hash' => (string) $payload['ip_hash'],
        ':user_agent_hash' => (string) $payload['user_agent_hash'],
        ':wait_seconds' => (int) $payload['wait_seconds'],
        ':available_at' => (string) $payload['available_at'],
        ':expires_at' => (string) $payload['expires_at'],
        ':created_at' => (string) $payload['created_at'],
    ]);

    return [
        'request_token' => $requestToken,
        'wait_seconds' => (int) $payload['wait_seconds'],
        'remaining_seconds' => (int) $payload['wait_seconds'],
        'ready' => (int) $payload['wait_seconds'] === 0,
    ];
}

function ve_video_validate_download_request(array $video, string $requestToken): ?array
{
    if ($requestToken === '') {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_download_grants
         WHERE video_id = :video_id
           AND request_token_hash = :request_token_hash
           AND revoked_at IS NULL
           AND used_at IS NULL
           AND expires_at >= :now
         LIMIT 1'
    );
    $stmt->execute([
        ':video_id' => (int) ($video['id'] ?? 0),
        ':request_token_hash' => ve_video_playback_signature($requestToken),
        ':now' => ve_now(),
    ]);
    $grant = $stmt->fetch();

    if (!is_array($grant)) {
        return null;
    }

    $currentSessionHash = ve_video_download_session_hash();
    $currentIpHash = ve_video_playback_signature(ve_client_ip());
    $currentUserAgentHash = ve_video_playback_signature(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500));
    $viewerUserId = (int) (($grant['viewer_user_id'] ?? null) ?: 0);
    $currentUser = ve_current_user();
    $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : 0;

    if (!hash_equals((string) ($grant['session_id_hash'] ?? ''), $currentSessionHash)) {
        return null;
    }

    if (!hash_equals((string) ($grant['ip_hash'] ?? ''), $currentIpHash)) {
        return null;
    }

    if (!hash_equals((string) ($grant['user_agent_hash'] ?? ''), $currentUserAgentHash)) {
        return null;
    }

    if ($viewerUserId > 0 && $viewerUserId !== $currentUserId) {
        return null;
    }

    return $grant;
}

function ve_video_resolve_download_request(array $video, string $requestToken): array
{
    $grant = ve_video_validate_download_request($video, $requestToken);

    if (!is_array($grant)) {
        return [
            'status' => 'fail',
            'message' => 'Download session is invalid or expired.',
        ];
    }

    $availableAt = strtotime((string) ($grant['available_at'] ?? '')) ?: ve_timestamp();
    $remainingSeconds = max(0, $availableAt - ve_timestamp());

    if ($remainingSeconds > 0) {
        return [
            'status' => 'ok',
            'ready' => false,
            'remaining_seconds' => $remainingSeconds,
            'message' => 'Please wait before requesting the protected download.',
        ];
    }

    $downloadToken = ve_random_token(24);
    $expiresAt = gmdate('Y-m-d H:i:s', ve_timestamp() + (int) ve_video_config()['download_ready_ttl']);
    $stmt = ve_db()->prepare(
        'UPDATE video_download_grants
         SET download_token_hash = :download_token_hash,
             issued_at = :issued_at,
             expires_at = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':download_token_hash' => ve_video_playback_signature($downloadToken),
        ':issued_at' => ve_now(),
        ':expires_at' => $expiresAt,
        ':id' => (int) ($grant['id'] ?? 0),
    ]);

    return [
        'status' => 'ok',
        'ready' => true,
        'download_action' => ve_video_download_post_url($video),
        'download_token' => $downloadToken,
        'download_label' => ve_video_download_label($video),
        'size_label' => ve_video_format_bytes(ve_video_download_size_bytes($video)),
        'expires_at' => $expiresAt,
    ];
}

function ve_video_validate_download_grant(array $video, string $downloadToken): ?array
{
    if ($downloadToken === '') {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_download_grants
         WHERE video_id = :video_id
           AND download_token_hash = :download_token_hash
           AND revoked_at IS NULL
           AND used_at IS NULL
           AND expires_at >= :now
         LIMIT 1'
    );
    $stmt->execute([
        ':video_id' => (int) ($video['id'] ?? 0),
        ':download_token_hash' => ve_video_playback_signature($downloadToken),
        ':now' => ve_now(),
    ]);
    $grant = $stmt->fetch();

    if (!is_array($grant)) {
        return null;
    }

    if (!hash_equals((string) ($grant['session_id_hash'] ?? ''), ve_video_download_session_hash())) {
        return null;
    }

    if (!hash_equals((string) ($grant['ip_hash'] ?? ''), ve_video_playback_signature(ve_client_ip()))) {
        return null;
    }

    if (!hash_equals((string) ($grant['user_agent_hash'] ?? ''), ve_video_playback_signature(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)))) {
        return null;
    }

    $viewerUserId = (int) (($grant['viewer_user_id'] ?? null) ?: 0);
    $currentUser = ve_current_user();
    $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : 0;

    if ($viewerUserId > 0 && $viewerUserId !== $currentUserId) {
        return null;
    }

    return $grant;
}

function ve_video_prepare_download_path(array $video): ?string
{
    if ((string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        return null;
    }

    $sourcePath = ve_video_source_path($video);

    if (is_file($sourcePath)) {
        return $sourcePath;
    }

    $downloadPath = ve_video_download_path($video);
    clearstatcache(true, $downloadPath);

    if (is_file($downloadPath) && (int) (filesize($downloadPath) ?: 0) > 0) {
        return $downloadPath;
    }

    if (!ve_video_processing_available()) {
        return null;
    }

    $playlistPath = ve_video_playlist_path($video);
    $keyPath = ve_video_key_path($video);

    if (!is_file($playlistPath) || !is_file($keyPath)) {
        return null;
    }

    $lockHandle = fopen(ve_video_download_lock_path($video), 'c+');

    if ($lockHandle === false) {
        return null;
    }

    $buildPlaylistPath = ve_video_download_playlist_path($video);
    $segmentAliasPaths = [];
    $directory = ve_video_library_directory((string) ($video['public_id'] ?? ''));

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            return null;
        }

        clearstatcache(true, $downloadPath);

        if (is_file($downloadPath) && (int) (filesize($downloadPath) ?: 0) > 0) {
            return $downloadPath;
        }

        $playlistBody = file_get_contents($playlistPath);

        if (!is_string($playlistBody) || $playlistBody === '') {
            return null;
        }

        $playlistLines = preg_split('/\r\n|\r|\n/', $playlistBody) ?: [];
        $segmentIndex = 0;

        foreach ($playlistLines as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                $playlistLines[$index] = '';
                continue;
            }

            if (str_starts_with($line, '#EXT-X-KEY:')) {
                $playlistLines[$index] = preg_replace('/URI="[^"]*"/', 'URI="' . basename($keyPath) . '"', $line) ?? $line;
                continue;
            }

            if ($line[0] === '#') {
                $playlistLines[$index] = $line;
                continue;
            }

            $sourceSegmentPath = $directory . DIRECTORY_SEPARATOR . basename($line);

            if (!is_file($sourceSegmentPath)) {
                return null;
            }

            $aliasName = 'download-segment-' . str_pad((string) $segmentIndex, 5, '0', STR_PAD_LEFT) . '.ts';
            $aliasPath = $directory . DIRECTORY_SEPARATOR . $aliasName;
            @unlink($aliasPath);

            if (!@link($sourceSegmentPath, $aliasPath) && !@copy($sourceSegmentPath, $aliasPath)) {
                return null;
            }

            $segmentAliasPaths[] = $aliasPath;
            $playlistLines[$index] = $aliasName;
            $segmentIndex++;
        }

        if (@file_put_contents($buildPlaylistPath, implode("\n", $playlistLines)) === false) {
            return null;
        }

        $temporaryPath = $directory . DIRECTORY_SEPARATOR . 'download.part.mp4';
        @unlink($temporaryPath);

        [$exitCode] = ve_video_run_command([
            (string) ve_video_config()['ffmpeg'],
            '-y',
            '-hide_banner',
            '-loglevel',
            'error',
            '-allowed_extensions',
            'ALL',
            '-protocol_whitelist',
            'file,crypto,data',
            '-i',
            $buildPlaylistPath,
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            $temporaryPath,
        ]);

        @unlink($buildPlaylistPath);

        if ($exitCode !== 0 || !is_file($temporaryPath) || (int) (filesize($temporaryPath) ?: 0) === 0) {
            @unlink($temporaryPath);
            return null;
        }

        @unlink($downloadPath);

        if (!@rename($temporaryPath, $downloadPath)) {
            @copy($temporaryPath, $downloadPath);
            @unlink($temporaryPath);
        }

        clearstatcache(true, $downloadPath);

        return is_file($downloadPath) && (int) (filesize($downloadPath) ?: 0) > 0
            ? $downloadPath
            : null;
    } finally {
        @unlink($buildPlaylistPath);
        foreach ($segmentAliasPaths as $aliasPath) {
            @unlink($aliasPath);
        }
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
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

function ve_video_legacy_normalize_id_list(array $values): array
{
    $ids = [];

    foreach ($values as $value) {
        $id = (int) $value;

        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function ve_video_folder_generate_public_code(): string
{
    do {
        $code = substr(preg_replace('/[^A-Za-z0-9]/', '', ve_random_token(10)) ?? '', 0, 10);
        $exists = false;

        if ($code !== '') {
            $stmt = ve_db()->prepare('SELECT id FROM video_folders WHERE public_code = :code LIMIT 1');
            $stmt->execute([':code' => $code]);
            $exists = $stmt->fetch() !== false;
        }
    } while ($code === '' || $exists);

    return $code;
}

function ve_video_folder_get_for_user(int $userId, int $folderId): ?array
{
    if ($folderId <= 0) {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_folders
         WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $folderId,
        ':user_id' => $userId,
    ]);
    $folder = $stmt->fetch();

    return is_array($folder) ? $folder : null;
}

function ve_video_normalize_folder_id(int $userId, int $folderId): int
{
    $folder = ve_video_folder_get_for_user($userId, $folderId);

    return is_array($folder) ? (int) $folder['id'] : 0;
}

function ve_video_folder_name_exists(int $userId, int $parentId, string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM video_folders
            WHERE user_id = :user_id
              AND parent_id = :parent_id
              AND deleted_at IS NULL
              AND lower(name) = lower(:name)';
    $params = [
        ':user_id' => $userId,
        ':parent_id' => $parentId,
        ':name' => $name,
    ];

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> :ignore_id';
        $params[':ignore_id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    $stmt = ve_db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch() !== false;
}

function ve_video_folder_list_children(int $userId, int $parentId): array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM video_folders
         WHERE user_id = :user_id
           AND parent_id = :parent_id
           AND deleted_at IS NULL
         ORDER BY name COLLATE NOCASE ASC, id ASC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':parent_id' => $parentId,
    ]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ve_video_folder_create(int $userId, int $parentId, string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

    if ($name === '') {
        throw new RuntimeException('Folder name is required.');
    }

    if (mb_strlen($name) > 120) {
        throw new RuntimeException('Folder names must be 120 characters or fewer.');
    }

    $parentId = ve_video_normalize_folder_id($userId, $parentId);

    if (ve_video_folder_name_exists($userId, $parentId, $name)) {
        throw new RuntimeException('A folder with that name already exists here.');
    }

    $now = ve_now();
    $stmt = ve_db()->prepare(
        'INSERT INTO video_folders (user_id, parent_id, public_code, name, created_at, updated_at, deleted_at)
         VALUES (:user_id, :parent_id, :public_code, :name, :created_at, :updated_at, NULL)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':parent_id' => $parentId,
        ':public_code' => ve_video_folder_generate_public_code(),
        ':name' => $name,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $folder = ve_video_folder_get_for_user($userId, (int) ve_db()->lastInsertId());

    if (!is_array($folder)) {
        throw new RuntimeException('The folder could not be created.');
    }

    return $folder;
}

function ve_video_folder_collect_descendant_ids(int $userId, int $folderId): array
{
    $descendants = [];
    $queue = [$folderId];

    while ($queue !== []) {
        $currentId = array_shift($queue);

        foreach (ve_video_folder_list_children($userId, $currentId) as $folder) {
            $childId = (int) ($folder['id'] ?? 0);

            if ($childId <= 0 || isset($descendants[$childId])) {
                continue;
            }

            $descendants[$childId] = $childId;
            $queue[] = $childId;
        }
    }

    return array_values($descendants);
}

function ve_video_folder_tree_for_user(int $userId, array $excludedFolderIds = []): array
{
    $excluded = [];

    foreach (ve_video_legacy_normalize_id_list($excludedFolderIds) as $folderId) {
        $excluded[$folderId] = true;

        foreach (ve_video_folder_collect_descendant_ids($userId, $folderId) as $descendantId) {
            $excluded[$descendantId] = true;
        }
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_folders
         WHERE user_id = :user_id AND deleted_at IS NULL
         ORDER BY name COLLATE NOCASE ASC, id ASC'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();
    $byParent = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $folderId = (int) ($row['id'] ?? 0);

        if ($folderId <= 0 || isset($excluded[$folderId])) {
            continue;
        }

        $parentId = (int) ($row['parent_id'] ?? 0);
        $byParent[$parentId][] = $row;
    }

    $buildTree = static function (int $parentId) use (&$buildTree, $byParent): array {
        $items = [];

        foreach ($byParent[$parentId] ?? [] as $row) {
            $folderId = (int) ($row['id'] ?? 0);
            $items[] = [
                'id' => $folderId,
                'name' => (string) ($row['name'] ?? ''),
                'sub_folders' => $buildTree($folderId),
            ];
        }

        return $items;
    };

    return $buildTree(0);
}

function ve_video_legacy_sort_column(string $sortField): string
{
    return match ($sortField) {
        'file_size' => 'CASE WHEN processed_size_bytes > 0 THEN processed_size_bytes ELSE original_size_bytes END',
        'file_title' => 'title COLLATE NOCASE',
        'file_views_full' => 'id',
        default => 'created_at',
    };
}

function ve_video_legacy_sort_direction(string $sortOrder): string
{
    return strtolower($sortOrder) === 'up' ? 'ASC' : 'DESC';
}

function ve_video_legacy_per_page(): int
{
    $allowed = [25, 50, 100, 500, 1000];
    $requested = (int) ($_COOKIE['per_page'] ?? 25);

    return in_array($requested, $allowed, true) ? $requested : 25;
}

function ve_video_legacy_total_for_folder(int $userId, int $folderId, string $search = ''): int
{
    $params = [
        ':user_id' => $userId,
        ':folder_id' => $folderId,
    ];
    $sql = 'SELECT COUNT(*) FROM videos
            WHERE user_id = :user_id
              AND folder_id = :folder_id
              AND deleted_at IS NULL';

    $search = trim($search);

    if ($search !== '') {
        $sql .= ' AND (title LIKE :search OR original_filename LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = ve_db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function ve_video_legacy_list_for_folder(
    int $userId,
    int $folderId,
    string $search,
    string $sortField,
    string $sortOrder,
    int $limit,
    int $offset
): array {
    $params = [
        ':user_id' => $userId,
        ':folder_id' => $folderId,
        ':limit' => max(1, $limit),
        ':offset' => max(0, $offset),
    ];
    $sql = 'SELECT * FROM videos
            WHERE user_id = :user_id
              AND folder_id = :folder_id
              AND deleted_at IS NULL';
    $search = trim($search);

    if ($search !== '') {
        $sql .= ' AND (title LIKE :search OR original_filename LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY ' . ve_video_legacy_sort_column($sortField) . ' ' . ve_video_legacy_sort_direction($sortOrder) . ', id DESC';
    $sql .= ' LIMIT :limit OFFSET :offset';

    $stmt = ve_db()->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ve_video_legacy_date_label(string $timestamp): string
{
    $timestamp = trim($timestamp);

    if ($timestamp === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    } catch (Exception) {
        return $timestamp;
    }

    return $date->format('M d, Y');
}

function ve_video_public_thumbnail_url(array $video, string $mode): string
{
    return ve_absolute_url('/thumbs/' . rawurlencode((string) ($video['public_id'] ?? '')) . '/' . ($mode === 'splash' ? 'splash.jpg' : 'single.jpg'));
}

function ve_video_folder_to_legacy_payload(array $folder): array
{
    return [
        'fld_id' => (int) ($folder['id'] ?? 0),
        'fld_code' => (string) ($folder['public_code'] ?? ''),
        'fld_name' => (string) ($folder['name'] ?? ''),
    ];
}

function ve_video_to_legacy_payload(array $video): array
{
    $status = (string) ($video['status'] ?? VE_VIDEO_STATUS_QUEUED);
    $publicId = (string) ($video['public_id'] ?? '');
    $sizeBytes = (int) (($video['processed_size_bytes'] ?? 0) > 0 ? $video['processed_size_bytes'] : $video['original_size_bytes']);

    return [
        'id' => (int) ($video['id'] ?? 0),
        'fid' => $publicId,
        'fn' => (string) ($video['title'] ?? 'Untitled video'),
        'ft' => (string) ($video['title'] ?? 'Untitled video'),
        'dl' => ve_absolute_url('/d/' . rawurlencode($publicId)),
        'dl2' => '',
        'ec' => ve_absolute_url('/e/' . rawurlencode($publicId)),
        'img' => $publicId,
        'single_img_url' => ve_video_public_thumbnail_url($video, 'single'),
        'splash_img_url' => ve_video_public_thumbnail_url($video, 'splash'),
        'new' => false,
        'ins' => false,
        'cc' => false,
        'enc' => $status === VE_VIDEO_STATUS_READY,
        'ise' => in_array($status, [VE_VIDEO_STATUS_QUEUED, VE_VIDEO_STATUS_PROCESSING], true),
        'vid_container' => strtoupper((string) ($video['source_extension'] ?? 'mp4')),
        'siz' => ve_video_format_bytes($sizeBytes),
        'cre' => ve_video_legacy_date_label((string) ($video['created_at'] ?? '')),
        'viw' => '0',
        'pub' => (int) ($video['is_public'] ?? 1),
        'status' => $status,
        'status_message' => (string) ($video['status_message'] ?? ''),
    ];
}

function ve_video_export_payload(array $video): array
{
    return [
        'dl' => ve_absolute_url('/d/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'ec' => ve_absolute_url('/e/' . rawurlencode((string) ($video['public_id'] ?? ''))),
        'file_title' => (string) ($video['title'] ?? 'Untitled video'),
        'img' => (string) ($video['public_id'] ?? ''),
        'single_img_url' => ve_video_public_thumbnail_url($video, 'single'),
        'splash_img_url' => ve_video_public_thumbnail_url($video, 'splash'),
    ];
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

    if ($power < count($units) - 1 && $value >= 1000) {
        $power++;
        $value = $bytes / (1024 ** $power);
    }

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

function ve_video_insert_queued_record(int $userId, array $validated, string $title, int $folderId = 0): ?array
{
    $title = trim($title);

    if ($title === '') {
        $title = ve_video_title_from_filename((string) ($validated['filename'] ?? 'video.' . ($validated['extension'] ?? 'mp4')));
    }

    $title = mb_substr($title, 0, 180);
    $publicId = ve_video_generate_public_id();
    $now = ve_now();
    $folderId = ve_video_normalize_folder_id($userId, $folderId);

    $stmt = ve_db()->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public, status, status_message,
            duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio,
            processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :folder_id, :public_id, :title, :original_filename, :source_extension, 1, :status, :status_message,
            NULL, NULL, NULL, "", "",
            :original_size_bytes, 0, NULL,
            "", :created_at, :updated_at, :queued_at, NULL, NULL, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':folder_id' => $folderId,
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
    bool $isUploadedFile = false,
    int $folderId = 0
): array {
    $validated = ve_video_detect_local_file($incomingPath, $originalFilename);
    $videoTitle = is_string($title) ? $title : '';
    $video = ve_video_insert_queued_record($userId, $validated, $videoTitle, $folderId);

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
        'poster_url' => ve_url('/api/videos/' . rawurlencode((string) ($video['public_id'] ?? '')) . '/poster.jpg'),
        'single_image_url' => ve_url('/thumbs/' . rawurlencode((string) ($video['public_id'] ?? '')) . '/single.jpg'),
        'splash_image_url' => ve_url('/thumbs/' . rawurlencode((string) ($video['public_id'] ?? '')) . '/splash.jpg'),
        'folder_id' => (int) ($video['folder_id'] ?? 0),
        'is_public' => (int) ($video['is_public'] ?? 1),
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

function ve_video_legacy_current_user(): array
{
    $user = ve_current_user();

    if (!is_array($user)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Please sign in again.',
        ], 401);
    }

    return $user;
}

function ve_video_fetch_user_videos_by_ids(int $userId, array $videoIds): array
{
    $videoIds = ve_video_legacy_normalize_id_list($videoIds);

    if ($videoIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($videoIds), '?'));
    $params = array_merge([$userId], $videoIds);
    $stmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE user_id = ? AND deleted_at IS NULL AND id IN (' . $placeholders . ')'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $byId = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    $ordered = [];

    foreach ($videoIds as $videoId) {
        if (isset($byId[$videoId])) {
            $ordered[] = $byId[$videoId];
        }
    }

    return $ordered;
}

function ve_video_delete_video_rows(array $videos): int
{
    $videoIds = [];

    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }

        $videoIds[] = (int) ($video['id'] ?? 0);
        ve_video_delete_directory(ve_video_library_directory((string) ($video['public_id'] ?? '')));
    }

    $videoIds = ve_video_legacy_normalize_id_list($videoIds);

    if ($videoIds === []) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($videoIds), '?'));
    $stmt = ve_db()->prepare('DELETE FROM videos WHERE id IN (' . $placeholders . ')');
    $stmt->execute($videoIds);

    return count($videoIds);
}

function ve_video_delete_user_folders(int $userId, array $folderIds): int
{
    $folderIds = ve_video_legacy_normalize_id_list($folderIds);

    if ($folderIds === []) {
        return 0;
    }

    $selected = [];

    foreach ($folderIds as $folderId) {
        if (ve_video_folder_get_for_user($userId, $folderId) !== null) {
            $selected[$folderId] = $folderId;
        }
    }

    if ($selected === []) {
        return 0;
    }

    $allFolderIds = $selected;

    foreach (array_values($selected) as $folderId) {
        foreach (ve_video_folder_collect_descendant_ids($userId, $folderId) as $descendantId) {
            $allFolderIds[$descendantId] = $descendantId;
        }
    }

    $allFolderIds = array_values($allFolderIds);
    $placeholders = implode(', ', array_fill(0, count($allFolderIds), '?'));
    $videoParams = array_merge([$userId], $allFolderIds);
    $videoStmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE user_id = ? AND deleted_at IS NULL AND folder_id IN (' . $placeholders . ')'
    );
    $videoStmt->execute($videoParams);
    $videos = $videoStmt->fetchAll();

    if (is_array($videos) && $videos !== []) {
        ve_video_delete_video_rows($videos);
    }

    $folderParams = array_merge([$userId], $allFolderIds);
    $folderStmt = ve_db()->prepare(
        'DELETE FROM video_folders
         WHERE user_id = ? AND id IN (' . $placeholders . ')'
    );
    $folderStmt->execute($folderParams);

    return count($selected);
}

function ve_video_move_user_folders(int $userId, array $folderIds, int $targetFolderId): int
{
    $folderIds = ve_video_legacy_normalize_id_list($folderIds);
    $targetFolderId = ve_video_normalize_folder_id($userId, $targetFolderId);

    if ($folderIds === []) {
        return 0;
    }

    $stmt = ve_db()->prepare(
        'UPDATE video_folders
         SET parent_id = :parent_id, updated_at = :updated_at
         WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
    );
    $updated = 0;

    foreach ($folderIds as $folderId) {
        $folder = ve_video_folder_get_for_user($userId, $folderId);

        if (!is_array($folder)) {
            continue;
        }

        $blockedIds = [$folderId => true];

        foreach (ve_video_folder_collect_descendant_ids($userId, $folderId) as $descendantId) {
            $blockedIds[$descendantId] = true;
        }

        if (isset($blockedIds[$targetFolderId])) {
            continue;
        }

        $stmt->execute([
            ':parent_id' => $targetFolderId,
            ':updated_at' => ve_now(),
            ':id' => $folderId,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }

    return $updated;
}

function ve_video_set_user_visibility(int $userId, array $videoIds, bool $isPublic): int
{
    $videoIds = ve_video_legacy_normalize_id_list($videoIds);

    if ($videoIds === []) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($videoIds), '?'));
    $params = array_merge([(int) $isPublic, ve_now(), $userId], $videoIds);
    $stmt = ve_db()->prepare(
        'UPDATE videos
         SET is_public = ?, updated_at = ?
         WHERE user_id = ? AND deleted_at IS NULL AND id IN (' . $placeholders . ')'
    );
    $stmt->execute($params);

    return $stmt->rowCount();
}

function ve_handle_legacy_videos_json(): void
{
    $user = ve_video_legacy_current_user();
    $userId = (int) ($user['id'] ?? 0);

    if (ve_is_method('GET') && array_key_exists('content_type', $_GET)) {
        ve_json([
            'status' => 'ok',
            'message' => 'Content type preference updated for this session.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('file_export', $_POST)) {
        $videoIds = $_POST['file_id'] ?? [];
        $videos = ve_video_fetch_user_videos_by_ids($userId, is_array($videoIds) ? $videoIds : [$videoIds]);
        ve_json(array_map('ve_video_export_payload', $videos));
    }

    if (ve_is_method('POST') && array_key_exists('fld_select', $_POST)) {
        $excludedFolderIds = $_POST['not_in'] ?? [];
        ve_json(ve_video_folder_tree_for_user($userId, is_array($excludedFolderIds) ? $excludedFolderIds : [$excludedFolderIds]));
    }

    if (ve_is_method('POST') && array_key_exists('create_new_folder', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());

        try {
            $folder = ve_video_folder_create(
                $userId,
                (int) ($_POST['fld_id'] ?? 0),
                (string) ($_POST['create_new_folder'] ?? '')
            );
        } catch (RuntimeException $exception) {
            ve_json([
                'status' => 'fail',
                'message' => $exception->getMessage(),
            ]);
        }

        ve_json([ve_video_folder_to_legacy_payload($folder)]);
    }

    if (ve_is_method('POST') && array_key_exists('del_selected_fld', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $folderIds = $_POST['fld_id1'] ?? [];
        $deletedCount = ve_video_delete_user_folders($userId, is_array($folderIds) ? $folderIds : [$folderIds]);

        ve_json([
            'status' => $deletedCount > 0 ? 'ok' : 'fail',
            'message' => $deletedCount > 0 ? 'Folder deleted successfully.' : 'Folder not found.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('del_selected', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $videoIds = $_POST['file_id'] ?? [];
        $videos = ve_video_fetch_user_videos_by_ids($userId, is_array($videoIds) ? $videoIds : [$videoIds]);
        $deletedCount = ve_video_delete_video_rows($videos);

        ve_json([
            'status' => $deletedCount > 0 ? 'ok' : 'fail',
            'message' => $deletedCount > 0 ? 'Videos deleted successfully.' : 'No matching videos were found.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('del_code', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $video = ve_video_get_for_user($userId, trim((string) ($_POST['del_code'] ?? '')));
        $deletedCount = is_array($video) ? ve_video_delete_video_rows([$video]) : 0;

        ve_json([
            'status' => $deletedCount > 0 ? 'ok' : 'fail',
            'message' => $deletedCount > 0 ? 'Video deleted successfully.' : 'Video not found.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('rename', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $name = trim((string) ($_POST['rename'] ?? ''));

        if ($name === '') {
            ve_json([
                'status' => 'fail',
                'message' => 'A name is required.',
            ]);
        }

        if (isset($_POST['fld_id'])) {
            $folder = ve_video_folder_get_for_user($userId, (int) $_POST['fld_id']);

            if (!is_array($folder)) {
                ve_json([
                    'status' => 'fail',
                    'message' => 'Folder not found.',
                ]);
            }

            if (ve_video_folder_name_exists($userId, (int) ($folder['parent_id'] ?? 0), $name, (int) $folder['id'])) {
                ve_json([
                    'status' => 'fail',
                    'message' => 'A folder with that name already exists here.',
                ]);
            }

            ve_db()->prepare(
                'UPDATE video_folders
                 SET name = :name, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id'
            )->execute([
                ':name' => mb_substr($name, 0, 120),
                ':updated_at' => ve_now(),
                ':id' => (int) $folder['id'],
                ':user_id' => $userId,
            ]);

            ve_json([[
                'fn' => mb_substr($name, 0, 120),
            ]]);
        }

        $videoIds = isset($_POST['file_id']) ? [(int) $_POST['file_id']] : [];
        $videos = ve_video_fetch_user_videos_by_ids($userId, $videoIds);

        if ($videos === []) {
            ve_json([
                'status' => 'fail',
                'message' => 'Video not found.',
            ]);
        }

        $video = $videos[0];
        $title = mb_substr($name, 0, 180);
        ve_db()->prepare(
            'UPDATE videos
             SET title = :title, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id'
        )->execute([
            ':title' => $title,
            ':updated_at' => ve_now(),
            ':id' => (int) $video['id'],
            ':user_id' => $userId,
        ]);

        ve_json([[
            'ft' => $title,
        ]]);
    }

    if (ve_is_method('POST') && array_key_exists('file_move', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $videoIds = $_POST['file_id'] ?? [];
        $targetFolderId = ve_video_normalize_folder_id($userId, (int) ($_POST['to_folder'] ?? 0));
        $normalizedVideoIds = ve_video_legacy_normalize_id_list(is_array($videoIds) ? $videoIds : [$videoIds]);

        if ($normalizedVideoIds === []) {
            ve_json([
                'status' => 'fail',
                'message' => 'No videos were selected.',
            ]);
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedVideoIds), '?'));
        $params = array_merge([$targetFolderId, ve_now(), $userId], $normalizedVideoIds);
        $stmt = ve_db()->prepare(
            'UPDATE videos
             SET folder_id = ?, updated_at = ?
             WHERE user_id = ? AND deleted_at IS NULL AND id IN (' . $placeholders . ')'
        );
        $stmt->execute($params);

        ve_json([
            'status' => 'ok',
            'message' => 'Videos moved successfully.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('folder_move', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $folderIds = $_POST['fld_id1'] ?? [];
        $movedCount = ve_video_move_user_folders(
            $userId,
            is_array($folderIds) ? $folderIds : [$folderIds],
            (int) ($_POST['to_folder_fld'] ?? 0)
        );

        ve_json([
            'status' => $movedCount > 0 ? 'ok' : 'fail',
            'message' => $movedCount > 0 ? 'Folders moved successfully.' : 'No folders could be moved.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('set_public', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $videoIds = $_POST['file_id'] ?? [];
        $updatedCount = ve_video_set_user_visibility($userId, is_array($videoIds) ? $videoIds : [$videoIds], true);

        ve_json([
            'status' => $updatedCount > 0 ? 'ok' : 'fail',
            'message' => $updatedCount > 0 ? 'Videos are now public.' : 'No videos were updated.',
        ]);
    }

    if (ve_is_method('POST') && array_key_exists('set_private', $_POST)) {
        ve_require_csrf(ve_request_csrf_token());
        $videoIds = $_POST['file_id'] ?? [];
        $updatedCount = ve_video_set_user_visibility($userId, is_array($videoIds) ? $videoIds : [$videoIds], false);

        ve_json([
            'status' => $updatedCount > 0 ? 'ok' : 'fail',
            'message' => $updatedCount > 0 ? 'Videos are now private.' : 'No videos were updated.',
        ]);
    }

    $folderId = ve_video_normalize_folder_id($userId, (int) ($_REQUEST['fld_id'] ?? 0));
    $currentFolder = $folderId > 0 ? ve_video_folder_get_for_user($userId, $folderId) : null;
    $page = max(1, (int) ($_REQUEST['page'] ?? 1));
    $perPage = ve_video_legacy_per_page();
    $search = trim((string) ($_REQUEST['key'] ?? ''));
    $totalVideos = ve_video_legacy_total_for_folder($userId, $folderId, $search);
    $totalPages = max(1, (int) ceil($totalVideos / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $videos = ve_video_legacy_list_for_folder(
        $userId,
        $folderId,
        $search,
        (string) ($_REQUEST['sort_field'] ?? 'file_created'),
        (string) ($_REQUEST['sort_order'] ?? 'down'),
        $perPage,
        $offset
    );
    $folders = array_map('ve_video_folder_to_legacy_payload', ve_video_folder_list_children($userId, $folderId));
    $processingAvailable = ve_video_processing_available();

    ve_json([
        'status' => 'ok',
        'draw' => (int) ($_REQUEST['draw'] ?? 1),
        'per_page' => $perPage,
        'total_videos' => $totalVideos,
        'current_page' => $page,
        'folder_id' => $folderId,
        'fld_parent_id' => is_array($currentFolder) ? (int) ($currentFolder['parent_id'] ?? 0) : 0,
        'folders' => $folders,
        'videos' => array_map('ve_video_to_legacy_payload', $videos),
        'list' => array_map('ve_video_to_legacy_payload', $videos),
        'token' => ve_csrf_token(),
        'upload' => [
            'utype' => 'reg',
            'sess_id' => session_id(),
        ],
        'maintenance_upload' => $processingAvailable ? 0 : 1,
        'maintenance_upload_msg' => $processingAvailable
            ? ''
            : 'Video uploads are disabled until FFmpeg is configured on this server.',
    ]);
}

function ve_handle_legacy_upload_get_server(): void
{
    ve_video_legacy_current_user();

    ve_json([
        'success' => true,
        'server' => [
            'srv_url' => rtrim(ve_origin() . ve_base_path(), '/'),
            'disk_id' => 'local',
        ],
    ]);
}

function ve_handle_legacy_pass_file(): void
{
    ve_video_legacy_current_user();

    ve_json([
        'status' => 'fail',
        'prem' => true,
    ]);
}

function ve_handle_legacy_upload_endpoint(): void
{
    $user = ve_video_legacy_current_user();

    if (!ve_video_processing_available()) {
        ve_json([
            'status' => 'fail',
            'message' => 'FFmpeg is not configured on this server.',
        ], 503);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        ve_json([
            'status' => 'fail',
            'message' => 'Choose a video file to upload.',
        ], 422);
    }

    $file = $_FILES['file'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        ve_json([
            'status' => 'fail',
            'message' => ve_video_upload_error_message($error),
        ], 422);
    }

    try {
        $video = ve_video_queue_local_source(
            (int) $user['id'],
            (string) ($file['tmp_name'] ?? ''),
            (string) ($file['name'] ?? 'video.mp4'),
            trim((string) ($_POST['file_title'] ?? '')),
            true,
            (int) ($_POST['fld_id'] ?? 0)
        );
    } catch (RuntimeException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 500);
    }

    ve_json([
        'status' => 'ok',
        'code' => (string) ($video['public_id'] ?? ''),
    ]);
}

function ve_handle_legacy_upload_results(): void
{
    $user = ve_video_legacy_current_user();
    $publicId = trim((string) ($_POST['fn'] ?? ''));
    $video = ve_video_get_for_user((int) $user['id'], $publicId);

    if (!is_array($video)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Uploaded video not found.',
        ], 404);
    }

    ve_json([
        'status' => 'ok',
        'links' => [ve_video_to_legacy_payload($video)],
    ]);
}

function ve_handle_legacy_add_srt(): void
{
    ve_video_legacy_current_user();

    if (isset($_FILES['srt']) || isset($_POST['del_srt'])) {
        ve_json([
            'status' => 'fail',
            'message' => 'Subtitle management is not enabled for secure videos yet.',
            'srt_list' => [],
        ]);
    }

    ve_json([
        'status' => 'ok',
        'srt_list' => [],
    ]);
}

function ve_handle_legacy_change_thumbnail(): void
{
    $user = ve_video_legacy_current_user();
    $videoIds = isset($_GET['file_id']) ? [(int) $_GET['file_id']] : [];
    $videos = ve_video_fetch_user_videos_by_ids((int) $user['id'], $videoIds);
    $video = $videos[0] ?? null;

    if (!is_array($video)) {
        ve_html('<p class="text-muted mb-0">Video not found.</p>');
    }

    $singleUrl = ve_h(ve_video_public_thumbnail_url($video, 'single'));
    $splashUrl = ve_h(ve_video_public_thumbnail_url($video, 'splash'));
    $settingsUrl = ve_h(ve_url('/dashboard/settings'));
    $posterPreview = ve_h(ve_url('/api/videos/' . rawurlencode((string) ($video['public_id'] ?? '')) . '/poster.jpg'));
    $status = (string) ($video['status'] ?? '');
    $body = $status === VE_VIDEO_STATUS_READY
        ? <<<HTML
<div class="text-center mb-3">
    <img src="{$posterPreview}" alt="Poster preview" style="max-width:100%;border-radius:8px;max-height:240px">
</div>
<p class="text-muted">Poster and splash images are generated automatically from the processed video and the player settings.</p>
<div class="form-group">
    <label>Single image URL</label>
    <textarea class="form-control" rows="2" onclick="this.focus();this.select()">{$singleUrl}</textarea>
</div>
<div class="form-group mb-0">
    <label>Splash image URL</label>
    <textarea class="form-control" rows="2" onclick="this.focus();this.select()">{$splashUrl}</textarea>
</div>
<a class="btn btn-primary mt-3" href="{$settingsUrl}">Open Player Settings</a>
HTML
        : '<p class="text-muted mb-0">The poster becomes available after processing finishes.</p>';

    ve_html($body);
}

function ve_handle_legacy_folder_sharing(): void
{
    $user = ve_video_legacy_current_user();
    $folder = ve_video_folder_get_for_user((int) $user['id'], (int) ($_GET['folder_id'] ?? 0));

    if (!is_array($folder)) {
        ve_html('<p class="text-muted mb-0">Folder not found.</p>');
    }

    $folderName = ve_h((string) ($folder['name'] ?? 'Folder'));
    ve_html('<p class="mb-2"><strong>' . $folderName . '</strong></p><p class="text-muted mb-0">Folder sharing is not enabled in this build. Share videos through their watch or embed links instead.</p>');
}

function ve_handle_legacy_marker(): void
{
    $user = ve_video_legacy_current_user();
    $videos = ve_video_fetch_user_videos_by_ids((int) $user['id'], isset($_GET['file_id']) ? [(int) $_GET['file_id']] : []);
    $video = $videos[0] ?? null;

    if (!is_array($video)) {
        ve_html('<p class="text-muted mb-0">Video not found.</p>');
    }

    $previewImage = ve_h(ve_video_public_thumbnail_url($video, 'single'));
    $status = (string) ($video['status'] ?? '');
    $content = $status === VE_VIDEO_STATUS_READY
        ? '<div class="text-center mb-3"><img src="' . $previewImage . '" alt="Preview" style="max-width:100%;border-radius:8px;max-height:220px"></div><p class="text-muted mb-0">A 4x4 hover-preview sprite and WebVTT track are generated automatically during processing for the secure player.</p>'
        : '<p class="text-muted mb-0">Preview markers are generated automatically once processing finishes.</p>';

    ve_html($content);
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
    $script = ve_root_path('app', 'workers', 'video_queue.php');
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
        ve_video_cleanup_inactive_zero_view_videos();
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

function ve_video_inactive_zero_view_retention_days(): int
{
    return max(1, (int) (getenv('VE_VIDEO_ZERO_VIEW_RETENTION_DAYS') ?: 30));
}

function ve_video_cleanup_inactive_zero_view_videos(int $limit = 100): int
{
    $limit = max(1, $limit);
    $retentionDays = ve_video_inactive_zero_view_retention_days();
    $cutoff = gmdate('Y-m-d H:i:s', ve_timestamp() - ($retentionDays * 86400));
    $sql = <<<SQL
SELECT videos.*
FROM videos
LEFT JOIN (
    SELECT video_id, COALESCE(SUM(views), 0) AS total_views
    FROM video_stats_daily
    GROUP BY video_id
) AS stats ON stats.video_id = videos.id
WHERE videos.deleted_at IS NULL
  AND videos.status = :status
  AND COALESCE(videos.ready_at, videos.created_at) <= :cutoff
  AND COALESCE(stats.total_views, 0) = 0
ORDER BY COALESCE(videos.ready_at, videos.created_at) ASC
LIMIT {$limit}
SQL;

    $stmt = ve_db()->prepare($sql);
    $stmt->execute([
        ':status' => VE_VIDEO_STATUS_READY,
        ':cutoff' => $cutoff,
    ]);

    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($videos) || $videos === []) {
        return 0;
    }

    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }

        $userId = (int) ($video['user_id'] ?? 0);
        $title = trim((string) ($video['title'] ?? 'Untitled video'));

        if ($userId > 0) {
            ve_add_notification(
                $userId,
                'Inactive video deleted',
                '"' . $title . '" was deleted automatically after ' . $retentionDays . ' days without any views.'
            );
        }
    }

    return ve_video_delete_video_rows($videos);
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

function ve_video_session_uses_premium_bandwidth(array $video): bool
{
    $userId = (int) ($video['user_id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }

    $settings = ve_video_owner_settings($video);

    if (!ve_premium_bandwidth_settings_configured($settings)) {
        return false;
    }

    $bandwidth = ve_premium_bandwidth_totals($userId);

    return (int) ($bandwidth['available_bytes'] ?? 0) > 0;
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

function ve_video_resolve_public_image_asset(array $video, string $mode = 'single'): ?array
{
    $settings = ve_video_owner_settings($video);

    if ($mode === 'splash') {
        $splashPath = ve_storage_relative_path_to_absolute((string) ($settings['splash_image_path'] ?? ''));

        if ($splashPath !== '' && is_file($splashPath)) {
            return [
                'path' => $splashPath,
                'mime' => ve_detect_file_mime_type($splashPath),
            ];
        }
    }

    $posterPath = ve_video_poster_path($video);

    if (is_file($posterPath)) {
        return [
            'path' => $posterPath,
            'mime' => 'image/jpeg',
        ];
    }

    return $mode === 'splash' ? ve_video_resolve_poster_asset($video) : null;
}

function ve_video_is_request_visible(array $video): bool
{
    if ((int) ($video['is_public'] ?? 1) === 1) {
        return true;
    }

    $user = ve_current_user();

    return is_array($user) && (int) ($user['id'] ?? 0) === (int) ($video['user_id'] ?? 0);
}

function ve_video_is_owner_viewer(array $video): bool
{
    $user = ve_current_user();

    return is_array($user) && (int) ($user['id'] ?? 0) === (int) ($video['user_id'] ?? 0);
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

function ve_video_playback_request_token_hash(string $value): string
{
    return ve_video_playback_signature('request-token:' . $value);
}

function ve_video_pulse_interval_seconds(int $minimumWatchSeconds): int
{
    $minimumWatchSeconds = max(5, $minimumWatchSeconds);

    return max(5, min(10, (int) floor($minimumWatchSeconds / 3)));
}

function ve_video_required_pulse_count(int $minimumWatchSeconds): int
{
    $interval = ve_video_pulse_interval_seconds($minimumWatchSeconds);
    $required = (int) floor(max(1, $minimumWatchSeconds - 1) / max(1, $interval));

    return max(1, min(6, $required));
}

function ve_video_playback_request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '/';
    }

    return $path;
}

function ve_video_build_playback_request_proof(
    string $clientProofKey,
    string $kind,
    string $method,
    string $path,
    int $sequence,
    string $clientToken,
    string $serverToken,
    string $playbackToken,
    int $watchedSeconds
): string {
    $canonical = implode("\n", [
        strtolower($kind),
        strtoupper($method),
        $path,
        (string) max(0, $sequence),
        $clientToken,
        $serverToken,
        $playbackToken,
        (string) max(0, $watchedSeconds),
    ]);

    return bin2hex(hash_hmac('sha256', $canonical, $clientProofKey, true));
}

/**
 * @return array{playback_token:string,client_token:string,server_token:string,sequence:int}
 */
function ve_video_issue_playback_request_state(int $sequence = 0): array
{
    return [
        'playback_token' => ve_random_token(24),
        'client_token' => ve_random_token(24),
        'server_token' => ve_random_token(24),
        'sequence' => max(0, $sequence),
    ];
}

/**
 * @return array{playback_token:string,client_token:string,server_token:string,sequence:int}
 */
function ve_video_rotate_playback_request_state(array $session): array
{
    $sessionId = (int) ($session['id'] ?? 0);

    if ($sessionId <= 0) {
        throw new RuntimeException('Playback session is incomplete.');
    }

    $nextState = ve_video_issue_playback_request_state(((int) ($session['pulse_sequence'] ?? 0)) + 1);
    $stmt = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET previous_playback_token_hash = :previous_playback_token_hash,
             previous_pulse_client_token_hash = :previous_pulse_client_token_hash,
             previous_pulse_server_token_hash = :previous_pulse_server_token_hash,
             playback_token_hash = :playback_token_hash,
             pulse_client_token_hash = :pulse_client_token_hash,
             pulse_server_token_hash = :pulse_server_token_hash,
             pulse_sequence = :pulse_sequence,
             last_seen_at = :last_seen_at,
             expires_at = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':previous_playback_token_hash' => (string) ($session['playback_token_hash'] ?? ''),
        ':previous_pulse_client_token_hash' => (string) ($session['pulse_client_token_hash'] ?? ''),
        ':previous_pulse_server_token_hash' => (string) ($session['pulse_server_token_hash'] ?? ''),
        ':playback_token_hash' => ve_video_playback_request_token_hash($nextState['playback_token']),
        ':pulse_client_token_hash' => ve_video_playback_request_token_hash($nextState['client_token']),
        ':pulse_server_token_hash' => ve_video_playback_request_token_hash($nextState['server_token']),
        ':pulse_sequence' => $nextState['sequence'],
        ':last_seen_at' => ve_now(),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + (int) ve_video_config()['session_ttl']),
        ':id' => $sessionId,
    ]);

    return $nextState;
}

/**
 * @return array{playback_token:string,client_token:string,server_token:string,sequence:int,pulse_interval_seconds:int,required_pulse_count:int}
 */
function ve_video_rotation_payload(array $video, array $session): array
{
    $policy = ve_video_payable_view_policy();
    $nextState = ve_video_rotate_playback_request_state($session);

    return [
        'playback_token' => $nextState['playback_token'],
        'client_token' => $nextState['client_token'],
        'server_token' => $nextState['server_token'],
        'sequence' => $nextState['sequence'],
        'pulse_interval_seconds' => ve_video_pulse_interval_seconds((int) ($policy['minimum_watch_seconds'] ?? 30)),
        'required_pulse_count' => ve_video_required_pulse_count((int) ($policy['minimum_watch_seconds'] ?? 30)),
    ];
}

/**
 * @return array{playback_token:string,client_token:string,server_token:string,sequence:int}
 */
function ve_video_validate_playback_request_state(array $session, string $kind, int $watchedSeconds, string $playbackToken): array
{
    $clientToken = trim((string) ($_SERVER['HTTP_X_PLAYBACK_CLIENT_TOKEN'] ?? ''));
    $serverToken = trim((string) ($_SERVER['HTTP_X_PLAYBACK_SERVER_TOKEN'] ?? ''));
    $proof = trim((string) ($_SERVER['HTTP_X_PLAYBACK_PROOF'] ?? ''));
    $sequenceHeader = trim((string) ($_SERVER['HTTP_X_PLAYBACK_SEQUENCE'] ?? ''));

    if ($clientToken === '' || $serverToken === '' || $proof === '' || $sequenceHeader === '' || !preg_match('/^\d+$/', $sequenceHeader)) {
        throw new RuntimeException('Playback proof headers are missing.');
    }

    $sequence = (int) $sequenceHeader;
    $playbackTokenHash = ve_video_playback_request_token_hash($playbackToken);
    $clientTokenHash = ve_video_playback_request_token_hash($clientToken);
    $serverTokenHash = ve_video_playback_request_token_hash($serverToken);
    $currentSequence = max(0, (int) ($session['pulse_sequence'] ?? 0));
    $currentHeaderMatches = $sequence === $currentSequence
        && hash_equals((string) ($session['pulse_client_token_hash'] ?? ''), $clientTokenHash)
        && hash_equals((string) ($session['pulse_server_token_hash'] ?? ''), $serverTokenHash);
    $previousHeaderMatches = $sequence === max(0, $currentSequence - 1)
        && hash_equals((string) ($session['previous_pulse_client_token_hash'] ?? ''), $clientTokenHash)
        && hash_equals((string) ($session['previous_pulse_server_token_hash'] ?? ''), $serverTokenHash);
    $currentMatches = $currentHeaderMatches
        && hash_equals((string) ($session['playback_token_hash'] ?? ''), $playbackTokenHash);
    $previousMatches = $previousHeaderMatches
        && hash_equals((string) ($session['previous_playback_token_hash'] ?? ''), $playbackTokenHash);
    $legacyPlaybackTokenMatches = $playbackToken !== ''
        && hash_equals((string) ($session['session_token_hash'] ?? ''), ve_video_playback_signature($playbackToken))
        && ($currentHeaderMatches || $previousHeaderMatches);

    if (!$currentMatches && !$previousMatches && !$legacyPlaybackTokenMatches) {
        throw new RuntimeException('Playback request tokens are invalid or stale.');
    }

    $expectedProof = ve_video_build_playback_request_proof(
        (string) ($session['client_proof_key'] ?? ''),
        $kind,
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        ve_video_playback_request_path(),
        $sequence,
        $clientToken,
        $serverToken,
        $playbackToken,
        $watchedSeconds
    );

    if (!hash_equals($expectedProof, strtolower($proof))) {
        throw new RuntimeException('Playback request proof is invalid.');
    }

    return [
        'playback_token' => $playbackToken,
        'client_token' => $clientToken,
        'server_token' => $serverToken,
        'sequence' => $sequence,
    ];
}

/**
 * @return array{status:string,message:string,watched_seconds:int,accepted_watched_seconds:int,required_seconds:int,remaining_seconds:int,pulse_count:int,required_pulse_count:int,pulse_interval_seconds:int,ready_for_qualification:bool}
 */
function ve_video_record_playback_pulse(array $video, array $session, int $watchedSeconds): array
{
    $policy = ve_video_payable_view_policy();
    $minimumWatchSeconds = max(1, (int) ($policy['minimum_watch_seconds'] ?? 30));
    $pulseIntervalSeconds = ve_video_pulse_interval_seconds($minimumWatchSeconds);
    $requiredPulseCount = ve_video_required_pulse_count($minimumWatchSeconds);
    $sessionId = (int) ($session['id'] ?? 0);
    $watchedSeconds = max(0, $watchedSeconds);

    if ($sessionId <= 0) {
        return [
            'status' => 'fail',
            'message' => 'The playback pulse session is incomplete.',
            'watched_seconds' => $watchedSeconds,
            'accepted_watched_seconds' => 0,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => $minimumWatchSeconds,
            'pulse_count' => 0,
            'required_pulse_count' => $requiredPulseCount,
            'pulse_interval_seconds' => $pulseIntervalSeconds,
            'ready_for_qualification' => false,
        ];
    }

    $playbackStartedAt = trim((string) ($session['playback_started_at'] ?? ''));
    $playbackStartedTimestamp = $playbackStartedAt !== '' ? strtotime($playbackStartedAt) : false;

    if ($playbackStartedTimestamp === false || $playbackStartedTimestamp <= 0) {
        return [
            'status' => 'pending',
            'message' => 'Playback has not started yet.',
            'watched_seconds' => $watchedSeconds,
            'accepted_watched_seconds' => max(0, (int) ($session['last_pulse_watched_seconds'] ?? 0)),
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => $minimumWatchSeconds,
            'pulse_count' => max(0, (int) ($session['pulse_count'] ?? 0)),
            'required_pulse_count' => $requiredPulseCount,
            'pulse_interval_seconds' => $pulseIntervalSeconds,
            'ready_for_qualification' => false,
        ];
    }

    $elapsedPlaybackSeconds = max(0, ve_timestamp() - $playbackStartedTimestamp);
    $currentBandwidthBytes = max(0, (int) ($session['bandwidth_bytes_served'] ?? 0));
    $lastPulseBandwidthBytes = max(0, (int) ($session['last_pulse_bandwidth_bytes'] ?? 0));
    $lastPulseWatchedSeconds = max(0, (int) ($session['last_pulse_watched_seconds'] ?? 0));
    $acceptedWatchedSeconds = min(max($watchedSeconds, $lastPulseWatchedSeconds), $elapsedPlaybackSeconds);

    if ($acceptedWatchedSeconds <= $lastPulseWatchedSeconds && (int) ($session['pulse_count'] ?? 0) > 0) {
        return [
            'status' => 'pending',
            'message' => 'Playback has not progressed far enough for another validation pulse yet.',
            'watched_seconds' => $watchedSeconds,
            'accepted_watched_seconds' => $lastPulseWatchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - $lastPulseWatchedSeconds),
            'pulse_count' => max(0, (int) ($session['pulse_count'] ?? 0)),
            'required_pulse_count' => $requiredPulseCount,
            'pulse_interval_seconds' => $pulseIntervalSeconds,
            'ready_for_qualification' => false,
        ];
    }

    if ($currentBandwidthBytes <= $lastPulseBandwidthBytes && $acceptedWatchedSeconds <= $lastPulseWatchedSeconds) {
        return [
            'status' => 'pending',
            'message' => 'Secure playback has not advanced since the last validation pulse.',
            'watched_seconds' => $watchedSeconds,
            'accepted_watched_seconds' => $lastPulseWatchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - $lastPulseWatchedSeconds),
            'pulse_count' => max(0, (int) ($session['pulse_count'] ?? 0)),
            'required_pulse_count' => $requiredPulseCount,
            'pulse_interval_seconds' => $pulseIntervalSeconds,
            'ready_for_qualification' => false,
        ];
    }

    $pulseCount = max(0, (int) ($session['pulse_count'] ?? 0)) + 1;
    $update = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET pulse_count = :pulse_count,
             last_pulse_at = :last_pulse_at,
             last_pulse_watched_seconds = :last_pulse_watched_seconds,
             last_pulse_bandwidth_bytes = :last_pulse_bandwidth_bytes
         WHERE id = :id'
    );
    $update->execute([
        ':pulse_count' => $pulseCount,
        ':last_pulse_at' => ve_now(),
        ':last_pulse_watched_seconds' => $acceptedWatchedSeconds,
        ':last_pulse_bandwidth_bytes' => $currentBandwidthBytes,
        ':id' => $sessionId,
    ]);

    $readyForQualification = $acceptedWatchedSeconds >= $minimumWatchSeconds
        && $elapsedPlaybackSeconds >= $minimumWatchSeconds
        && $currentBandwidthBytes > 0
        && $pulseCount >= $requiredPulseCount;

    return [
        'status' => $readyForQualification ? 'ok' : 'pending',
        'message' => $readyForQualification
            ? 'Playback pulse accepted. The session can now attempt qualification.'
            : 'Playback pulse accepted. Keep watching to unlock the qualified view.',
        'watched_seconds' => $watchedSeconds,
        'accepted_watched_seconds' => $acceptedWatchedSeconds,
        'required_seconds' => $minimumWatchSeconds,
        'remaining_seconds' => max(0, $minimumWatchSeconds - $acceptedWatchedSeconds),
        'pulse_count' => $pulseCount,
        'required_pulse_count' => $requiredPulseCount,
        'pulse_interval_seconds' => $pulseIntervalSeconds,
        'ready_for_qualification' => $readyForQualification,
    ];
}

function ve_video_issue_playback_session(array $video): array
{
    $token = ve_random_token(24);
    $tokenHash = ve_video_playback_signature($token);
    $requestState = ve_video_issue_playback_request_state();
    $clientProofKey = ve_random_token(32);
    $ip = ve_client_ip();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $expiresAt = gmdate('Y-m-d H:i:s', ve_timestamp() + (int) ve_video_config()['session_ttl']);
    $user = ve_current_user();
    $usesPremiumBandwidth = ve_video_session_uses_premium_bandwidth($video) ? 1 : 0;

    ve_db()->prepare('DELETE FROM video_playback_sessions WHERE revoked_at IS NOT NULL OR expires_at < :now')
        ->execute([':now' => ve_now()]);

    $stmt = ve_db()->prepare(
        'INSERT INTO video_playback_sessions (
            video_id, session_token_hash, owner_user_id, ip_hash, user_agent_hash,
            client_proof_key, playback_token_hash, previous_playback_token_hash,
            pulse_client_token_hash, pulse_server_token_hash,
            previous_pulse_client_token_hash, previous_pulse_server_token_hash, pulse_sequence,
            pulse_count, last_pulse_at, last_pulse_watched_seconds, last_pulse_bandwidth_bytes,
            expires_at, created_at, last_seen_at, uses_premium_bandwidth, revoked_at
        ) VALUES (
            :video_id, :session_token_hash, :owner_user_id, :ip_hash, :user_agent_hash,
            :client_proof_key, :playback_token_hash, :previous_playback_token_hash,
            :pulse_client_token_hash, :pulse_server_token_hash,
            :previous_pulse_client_token_hash, :previous_pulse_server_token_hash, :pulse_sequence,
            0, NULL, 0, 0,
            :expires_at, :created_at, :last_seen_at, :uses_premium_bandwidth, NULL
        )'
    );
    $stmt->execute([
        ':video_id' => (int) $video['id'],
        ':session_token_hash' => $tokenHash,
        ':owner_user_id' => is_array($user) ? (int) $user['id'] : null,
        ':ip_hash' => ve_video_playback_signature($ip),
        ':user_agent_hash' => ve_video_playback_signature($userAgent),
        ':client_proof_key' => $clientProofKey,
        ':playback_token_hash' => ve_video_playback_request_token_hash($requestState['playback_token']),
        ':previous_playback_token_hash' => '',
        ':pulse_client_token_hash' => ve_video_playback_request_token_hash($requestState['client_token']),
        ':pulse_server_token_hash' => ve_video_playback_request_token_hash($requestState['server_token']),
        ':previous_pulse_client_token_hash' => '',
        ':previous_pulse_server_token_hash' => '',
        ':pulse_sequence' => $requestState['sequence'],
        ':expires_at' => $expiresAt,
        ':created_at' => ve_now(),
        ':last_seen_at' => ve_now(),
        ':uses_premium_bandwidth' => $usesPremiumBandwidth,
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
        'playback_token' => $requestState['playback_token'],
        'client_proof_key' => $clientProofKey,
        'pulse_client_token' => $requestState['client_token'],
        'pulse_server_token' => $requestState['server_token'],
        'pulse_sequence' => $requestState['sequence'],
        'uses_premium_bandwidth' => $usesPremiumBandwidth === 1,
    ];
}

function ve_video_find_playback_session(array $video, ?string $token): ?array
{
    $token = is_string($token) ? trim($token) : '';

    if ($token === '') {
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

    return $session;
}

function ve_video_validate_playback_session(array $video, ?string $token): ?array
{
    $token = is_string($token) ? trim($token) : '';
    $explicitTokenProvided = $token !== '';
    $headerToken = trim((string) ($_SERVER['HTTP_X_PLAYBACK_SESSION'] ?? ''));
    $cookieToken = trim((string) ($_COOKIE[ve_video_playback_cookie_name((string) $video['public_id'])] ?? ''));

    if ($token === '') {
        $token = $headerToken !== '' ? $headerToken : $cookieToken;
    }

    $session = ve_video_find_playback_session($video, $token);

    if (!is_array($session)) {
        return null;
    }

    $fetchDest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));

    $headerValid = is_string($headerToken) && $headerToken !== '' && hash_equals($token, $headerToken);
    $cookieValid = is_string($cookieToken) && $cookieToken !== '' && hash_equals($token, $cookieToken) && $fetchDest !== 'document';

    if (!$headerValid && !$cookieValid && !$explicitTokenProvided) {
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

function ve_video_fetch_playback_session_by_id(int $sessionId): ?array
{
    if ($sessionId <= 0) {
        return null;
    }

    $stmt = ve_db()->prepare('SELECT * FROM video_playback_sessions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();

    return is_array($session) ? $session : null;
}

/**
 * @return array{minimum_watch_seconds:int,max_payable_views_per_viewer_per_day:int}
 */
function ve_video_payable_view_policy(): array
{
    return [
        'minimum_watch_seconds' => ve_get_app_setting_int('video_payable_min_watch_seconds', 30, 5, 3600),
        'max_payable_views_per_viewer_per_day' => ve_get_app_setting_int('video_payable_max_views_per_viewer_per_day', 1, 0, 1000),
    ];
}

function ve_video_session_viewer_user_id(array $session): int
{
    // `owner_user_id` is the historical column name for the authenticated viewer tied to the playback session.
    return max(0, (int) ($session['owner_user_id'] ?? 0));
}

function ve_video_fetch_qualified_view_by_session_id(int $sessionId): ?array
{
    if ($sessionId <= 0) {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT *
         FROM video_view_qualifications
         WHERE playback_session_id = :playback_session_id
         LIMIT 1'
    );
    $stmt->execute([':playback_session_id' => $sessionId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return array{status:string,message:string,counted:bool,payable:bool,already_recorded:bool,watched_seconds:int,required_seconds:int,remaining_seconds:int,max_payable_views_per_day:int,payable_rank:int}
 */
function ve_video_record_qualified_view(array $video, array $session, int $watchedSeconds): array
{
    $policy = ve_video_payable_view_policy();
    $minimumWatchSeconds = max(1, (int) ($policy['minimum_watch_seconds'] ?? 30));
    $maxPayableViewsPerDay = max(0, (int) ($policy['max_payable_views_per_viewer_per_day'] ?? 1));
    $requiredPulseCount = ve_video_required_pulse_count($minimumWatchSeconds);
    $watchedSeconds = max(0, $watchedSeconds);
    $sessionId = (int) ($session['id'] ?? 0);
    $videoId = (int) ($video['id'] ?? 0);
    $ownerUserId = (int) ($video['user_id'] ?? 0);

    if ($sessionId <= 0 || $videoId <= 0 || $ownerUserId <= 0) {
        return [
            'status' => 'fail',
            'message' => 'The playback session is incomplete.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => $minimumWatchSeconds,
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    $existing = ve_video_fetch_qualified_view_by_session_id($sessionId);

    if (is_array($existing)) {
        return [
            'status' => 'ok',
            'message' => ((int) ($existing['is_payable'] ?? 0)) === 1
                ? 'This payable view was already recorded.'
                : 'This playback session was already processed.',
            'counted' => ((int) ($existing['is_payable'] ?? 0)) === 1,
            'payable' => ((int) ($existing['is_payable'] ?? 0)) === 1,
            'already_recorded' => true,
            'watched_seconds' => (int) ($existing['watched_seconds'] ?? 0),
            'required_seconds' => (int) ($existing['minimum_watch_seconds'] ?? $minimumWatchSeconds),
            'remaining_seconds' => 0,
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => (int) ($existing['payable_rank'] ?? 0),
        ];
    }

    $pulseCount = max(0, (int) ($session['pulse_count'] ?? 0));
    $lastPulseWatchedSeconds = max(0, (int) ($session['last_pulse_watched_seconds'] ?? 0));

    if ($pulseCount < $requiredPulseCount) {
        return [
            'status' => 'pending',
            'message' => 'Secure playback verification has not completed enough validation steps yet.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - max($watchedSeconds, $lastPulseWatchedSeconds)),
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    if ($lastPulseWatchedSeconds < $minimumWatchSeconds) {
        return [
            'status' => 'pending',
            'message' => 'Secure playback verification has not reached the payable watch threshold yet.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - $lastPulseWatchedSeconds),
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    if ($watchedSeconds < $minimumWatchSeconds) {
        return [
            'status' => 'pending',
            'message' => 'Playback has not reached the payable watch threshold yet.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - $watchedSeconds),
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    $playbackStartedAt = trim((string) ($session['playback_started_at'] ?? ''));
    $playbackStartedTimestamp = $playbackStartedAt !== '' ? strtotime($playbackStartedAt) : false;

    if ($playbackStartedTimestamp === false || $playbackStartedTimestamp <= 0) {
        return [
            'status' => 'pending',
            'message' => 'Playback has not started yet.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => $minimumWatchSeconds,
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    $elapsedPlaybackSeconds = max(0, ve_timestamp() - $playbackStartedTimestamp);

    if ($elapsedPlaybackSeconds < $minimumWatchSeconds) {
        return [
            'status' => 'pending',
            'message' => 'Playback must remain active a little longer before the view can be counted.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => max(0, $minimumWatchSeconds - $elapsedPlaybackSeconds),
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    if ((int) ($session['bandwidth_bytes_served'] ?? 0) <= 0) {
        return [
            'status' => 'pending',
            'message' => 'Playback data has not been streamed long enough to qualify this view yet.',
            'counted' => false,
            'payable' => false,
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => $minimumWatchSeconds,
            'remaining_seconds' => 1,
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => 0,
        ];
    }

    $viewerUserId = ve_video_session_viewer_user_id($session);
    $viewerIpAddress = ve_client_ip();
    $viewerIpHash = ve_video_playback_signature($viewerIpAddress);
    $viewerUserAgentHash = ve_video_playback_signature(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500));
    $viewerIdentityType = $viewerUserId > 0 ? 'user' : 'ip';
    $viewerIdentitySource = $viewerUserId > 0 ? 'user:' . $viewerUserId : 'ip:' . $viewerIpHash;
    $viewerIdentityHash = ve_video_playback_signature($viewerIdentitySource);
    $statDate = gmdate('Y-m-d');
    $qualifiedAt = ve_now();
    $isOwnerView = $viewerUserId > 0 && $viewerUserId === $ownerUserId;
    $pdo = ve_db();

    try {
        $pdo->beginTransaction();

        $existing = ve_video_fetch_qualified_view_by_session_id($sessionId);

        if (is_array($existing)) {
            $pdo->commit();

            return [
                'status' => 'ok',
                'message' => ((int) ($existing['is_payable'] ?? 0)) === 1
                    ? 'This payable view was already recorded.'
                    : 'This playback session was already processed.',
                'counted' => ((int) ($existing['is_payable'] ?? 0)) === 1,
                'payable' => ((int) ($existing['is_payable'] ?? 0)) === 1,
                'already_recorded' => true,
                'watched_seconds' => (int) ($existing['watched_seconds'] ?? 0),
                'required_seconds' => (int) ($existing['minimum_watch_seconds'] ?? $minimumWatchSeconds),
                'remaining_seconds' => 0,
                'max_payable_views_per_day' => $maxPayableViewsPerDay,
                'payable_rank' => (int) ($existing['payable_rank'] ?? 0),
            ];
        }

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM video_view_qualifications
             WHERE owner_user_id = :owner_user_id
               AND viewer_identity_hash = :viewer_identity_hash
               AND stat_date = :stat_date
               AND is_payable = 1'
        );
        $countStmt->execute([
            ':owner_user_id' => $ownerUserId,
            ':viewer_identity_hash' => $viewerIdentityHash,
            ':stat_date' => $statDate,
        ]);
        $existingPayableViews = (int) $countStmt->fetchColumn();
        $payableRank = $isOwnerView ? 0 : ($existingPayableViews + 1);
        $isPayable = !$isOwnerView && $payableRank <= $maxPayableViewsPerDay;

        $insert = $pdo->prepare(
            'INSERT INTO video_view_qualifications (
                playback_session_id, video_id, owner_user_id, viewer_user_id, viewer_ip_address,
                viewer_ip_hash, viewer_user_agent_hash, viewer_identity_type, viewer_identity_hash,
                watched_seconds, minimum_watch_seconds, stat_date, is_payable, payable_rank,
                qualified_at, created_at
             ) VALUES (
                :playback_session_id, :video_id, :owner_user_id, :viewer_user_id, :viewer_ip_address,
                :viewer_ip_hash, :viewer_user_agent_hash, :viewer_identity_type, :viewer_identity_hash,
                :watched_seconds, :minimum_watch_seconds, :stat_date, :is_payable, :payable_rank,
                :qualified_at, :created_at
             )'
        );
        $insert->execute([
            ':playback_session_id' => $sessionId,
            ':video_id' => $videoId,
            ':owner_user_id' => $ownerUserId,
            ':viewer_user_id' => $viewerUserId > 0 ? $viewerUserId : null,
            ':viewer_ip_address' => $viewerIpAddress,
            ':viewer_ip_hash' => $viewerIpHash,
            ':viewer_user_agent_hash' => $viewerUserAgentHash,
            ':viewer_identity_type' => $viewerIdentityType,
            ':viewer_identity_hash' => $viewerIdentityHash,
            ':watched_seconds' => $watchedSeconds,
            ':minimum_watch_seconds' => $minimumWatchSeconds,
            ':stat_date' => $statDate,
            ':is_payable' => $isPayable ? 1 : 0,
            ':payable_rank' => $isPayable ? $payableRank : 0,
            ':qualified_at' => $qualifiedAt,
            ':created_at' => $qualifiedAt,
        ]);

        if ($isPayable) {
            ve_dashboard_record_video_view($videoId, $ownerUserId, $statDate, null, 'qualified-view-' . $sessionId);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $existing = ve_video_fetch_qualified_view_by_session_id($sessionId);

        if (!is_array($existing)) {
            throw $exception;
        }

        return [
            'status' => 'ok',
            'message' => ((int) ($existing['is_payable'] ?? 0)) === 1
                ? 'This payable view was already recorded.'
                : 'This playback session was already processed.',
            'counted' => ((int) ($existing['is_payable'] ?? 0)) === 1,
            'payable' => ((int) ($existing['is_payable'] ?? 0)) === 1,
            'already_recorded' => true,
            'watched_seconds' => (int) ($existing['watched_seconds'] ?? 0),
            'required_seconds' => (int) ($existing['minimum_watch_seconds'] ?? $minimumWatchSeconds),
            'remaining_seconds' => 0,
            'max_payable_views_per_day' => $maxPayableViewsPerDay,
            'payable_rank' => (int) ($existing['payable_rank'] ?? 0),
        ];
    }

    return [
        'status' => 'ok',
        'message' => $isPayable
            ? 'The payable view was recorded successfully.'
            : ($isOwnerView
                ? 'Owner playback sessions are not payable.'
                : 'The daily payable view limit for this viewer was already reached.'),
        'counted' => $isPayable,
        'payable' => $isPayable,
        'already_recorded' => false,
        'watched_seconds' => $watchedSeconds,
        'required_seconds' => $minimumWatchSeconds,
        'remaining_seconds' => 0,
        'max_payable_views_per_day' => $maxPayableViewsPerDay,
        'payable_rank' => $isPayable ? $payableRank : 0,
    ];
}

function ve_video_playback_qualify_api(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = trim((string) ($_POST['playback_token'] ?? ''));
    $sessionToken = trim((string) ($_POST['session_token'] ?? ''));
    if ($sessionToken === '') {
        $sessionToken = trim((string) ($_SERVER['HTTP_X_PLAYBACK_SESSION'] ?? ''));
    }

    if ($token === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'The playback session token is missing.',
        ], 422);
    }

    $session = ve_video_find_playback_session($video, $sessionToken !== '' ? $sessionToken : $token);

    if (!is_array($session)) {
        ve_json([
            'status' => 'fail',
            'message' => 'The playback session is invalid or has expired.',
        ], 403);
    }

    $watchedSeconds = max(0, (int) round((float) ($_POST['watched_seconds'] ?? 0)));
    try {
        ve_video_validate_playback_request_state($session, 'qualify', $watchedSeconds, $token);
    } catch (RuntimeException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 403);
    }

    $session = ve_video_fetch_playback_session_by_id((int) ($session['id'] ?? 0)) ?? $session;
    $result = ve_video_record_qualified_view($video, $session, $watchedSeconds);
    $result['rotation'] = ve_video_rotation_payload($video, $session);
    $statusCode = match ($result['status'] ?? 'ok') {
        'pending' => 202,
        'fail' => 422,
        default => 200,
    };

    ve_json($result, $statusCode);
}

function ve_video_playback_pulse_api(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $token = trim((string) ($_POST['playback_token'] ?? ''));
    $sessionToken = trim((string) ($_POST['session_token'] ?? ''));
    if ($sessionToken === '') {
        $sessionToken = trim((string) ($_SERVER['HTTP_X_PLAYBACK_SESSION'] ?? ''));
    }

    if ($token === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'The playback session token is missing.',
        ], 422);
    }

    $session = ve_video_find_playback_session($video, $sessionToken !== '' ? $sessionToken : $token);

    if (!is_array($session)) {
        ve_json([
            'status' => 'fail',
            'message' => 'The playback session is invalid or has expired.',
        ], 403);
    }

    $watchedSeconds = max(0, (int) round((float) ($_POST['watched_seconds'] ?? 0)));

    try {
        ve_video_validate_playback_request_state($session, 'pulse', $watchedSeconds, $token);
    } catch (RuntimeException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 403);
    }

    $session = ve_video_fetch_playback_session_by_id((int) ($session['id'] ?? 0)) ?? $session;
    $result = ve_video_record_playback_pulse($video, $session, $watchedSeconds);
    $result['rotation'] = ve_video_rotation_payload($video, $session);
    $statusCode = match ($result['status'] ?? 'ok') {
        'pending' => 202,
        'fail' => 422,
        default => 200,
    };

    ve_json($result, $statusCode);
}

function ve_video_mark_playback_started(array $video, array $session): void
{
    $sessionId = (int) ($session['id'] ?? 0);

    if ($sessionId <= 0) {
        return;
    }

    $stmt = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET playback_started_at = :playback_started_at
         WHERE id = :id
           AND revoked_at IS NULL
           AND playback_started_at IS NULL'
    );
    $stmt->execute([
        ':playback_started_at' => ve_now(),
        ':id' => $sessionId,
    ]);
}

function ve_video_record_segment_delivery(array $video, array $session, int $bytes): void
{
    $sessionId = (int) ($session['id'] ?? 0);
    $videoId = (int) ($video['id'] ?? 0);
    $userId = (int) ($video['user_id'] ?? 0);
    $bytes = max(0, $bytes);
    $premiumBandwidthBytes = ((int) ($session['uses_premium_bandwidth'] ?? 0)) === 1 ? $bytes : 0;

    if ($sessionId <= 0 || $videoId <= 0 || $userId <= 0 || $bytes === 0) {
        return;
    }

    ve_video_mark_playback_started($video, $session);

    $stmt = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET bandwidth_bytes_served = bandwidth_bytes_served + :bandwidth_bytes_served,
             premium_bandwidth_bytes_served = premium_bandwidth_bytes_served + :premium_bandwidth_bytes_served
         WHERE id = :id
           AND revoked_at IS NULL'
    );
    $stmt->execute([
        ':bandwidth_bytes_served' => $bytes,
        ':premium_bandwidth_bytes_served' => $premiumBandwidthBytes,
        ':id' => $sessionId,
    ]);

    ve_dashboard_record_video_bandwidth($videoId, $userId, $bytes, null, $premiumBandwidthBytes);
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

    $session = ve_video_validate_playback_session($video, $token);

    if ($session === null) {
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

    $session = ve_video_validate_playback_session($video, $token);

    if ($session === null) {
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

    $session = ve_video_validate_playback_session($video, $token);

    if ($session === null) {
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

    ve_video_record_segment_delivery($video, $session, (int) (filesize($path) ?: 0));

    header('Content-Type: video/mp2t');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_emit_private_file(string $path, string $mime, int $maxAge = 300): void
{
    if (!is_file($path)) {
        ve_not_found();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=' . $maxAge);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_emit_download_file(string $path, string $downloadName): void
{
    if (!is_file($path)) {
        ve_not_found();
    }

    $safeName = ve_remote_sanitize_filename($downloadName, 'video.mp4');
    header('Content-Type: ' . ve_detect_file_mime_type($path));
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes($safeName, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_video_render_download_unavailable(string $title, string $message, int $status = 503): void
{
    $safeTitle = ve_h($title);
    $safeMessage = ve_h($message);

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #111;
            color: #fff;
            font-family: Arial, sans-serif;
        }
        .ve-download-card {
            max-width: 720px;
            width: 100%;
            padding: 28px;
            background: #1c1c1c;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            text-align: center;
        }
        .ve-download-card h1 {
            margin: 0 0 12px;
            font-size: 1.3rem;
        }
        .ve-download-card p {
            margin: 0;
            color: rgba(255,255,255,0.78);
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="ve-download-card">
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
    </div>
</body>
</html>
HTML, $status);
}

function ve_video_download_request_api(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    ve_require_csrf(ve_request_csrf_token());

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Video not found.',
        ], 404);
    }

    if (!ve_video_download_available($video)) {
        ve_json([
            'status' => 'fail',
            'message' => 'This video is not available for protected download yet.',
        ], 409);
    }

    $request = ve_video_issue_download_request($video);

    if (($request['ready'] ?? false) === true) {
        $resolved = ve_video_resolve_download_request($video, (string) ($request['request_token'] ?? ''));
        ve_json($resolved, ($resolved['status'] ?? 'fail') === 'ok' ? 200 : 409);
    }

    ve_json([
        'status' => 'ok',
        'ready' => false,
        'request_token' => (string) ($request['request_token'] ?? ''),
        'wait_seconds' => (int) ($request['wait_seconds'] ?? 0),
        'remaining_seconds' => (int) ($request['remaining_seconds'] ?? 0),
        'is_premium' => ve_video_download_wait_seconds_for_viewer() === 0,
    ]);
}

function ve_video_download_resolve_api(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    ve_require_csrf(ve_request_csrf_token());

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Video not found.',
        ], 404);
    }

    $requestToken = trim((string) ($_POST['request_token'] ?? ''));
    $resolved = ve_video_resolve_download_request($video, $requestToken);
    ve_json($resolved, ($resolved['status'] ?? 'fail') === 'ok' ? 200 : 409);
}

function ve_video_download_consume_file(string $publicId, string $downloadToken, string $requestedName = ''): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video)) {
        ve_not_found();
    }

    $title = trim((string) ($video['title'] ?? 'Untitled video'));

    if ((string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_video_render_download_unavailable($title, ve_video_public_status_message($video), 409);
    }

    $grant = ve_video_validate_download_grant($video, $downloadToken);

    if (!is_array($grant)) {
        ve_video_render_download_unavailable(
            $title,
            'This protected download link is invalid or has expired. Return to the watch page and request a new one.',
            403
        );
    }

    $path = ve_video_prepare_download_path($video);

    if (!is_string($path) || $path === '') {
        ve_video_render_download_unavailable(
            $title,
            'The downloadable file could not be prepared right now. Please try again in a moment.',
            503
        );
    }

    $downloadName = ve_video_download_filename($video);

    if ($requestedName !== '') {
        $requestedName = trim(rawurldecode($requestedName));

        if ($requestedName !== '' && strcasecmp($requestedName, $downloadName) === 0) {
            $downloadName = $requestedName;
        }
    }

    ve_db()->prepare(
        'UPDATE video_download_grants
         SET used_at = :used_at
         WHERE id = :id'
    )->execute([
        ':used_at' => ve_now(),
        ':id' => (int) ($grant['id'] ?? 0),
    ]);

    ve_video_emit_download_file($path, $downloadName);
}

function ve_video_download_file(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    ve_require_csrf(ve_request_csrf_token());
    $downloadToken = trim((string) ($_POST['download_token'] ?? ''));
    $requestedName = trim((string) ($_POST['filename'] ?? ''));
    ve_video_download_consume_file($publicId, $downloadToken, $requestedName);
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

    ve_video_emit_private_file((string) $asset['path'], (string) ($asset['mime'] ?? 'image/jpeg'));
}

function ve_video_owner_stream_poster(string $publicId): void
{
    $user = ve_require_auth();
    $video = ve_video_get_for_user((int) $user['id'], $publicId);

    if (!is_array($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $asset = ve_video_resolve_poster_asset($video);

    if (!is_array($asset) || !is_file((string) ($asset['path'] ?? ''))) {
        ve_not_found();
    }

    ve_video_emit_private_file((string) $asset['path'], (string) ($asset['mime'] ?? 'image/jpeg'));
}

function ve_video_stream_public_thumbnail(string $publicId, string $mode): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (
        !is_array($video)
        || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY
        || !ve_video_is_request_visible($video)
    ) {
        ve_not_found();
    }

    $asset = ve_video_resolve_public_image_asset($video, $mode);

    if (!is_array($asset) || !is_file((string) ($asset['path'] ?? ''))) {
        ve_not_found();
    }

    ve_video_emit_private_file((string) $asset['path'], (string) ($asset['mime'] ?? 'image/jpeg'), 86400);
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

    ve_video_emit_private_file($path, 'image/jpeg');
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

function ve_video_secure_player_script(array $session, string $publicId, int $minimumWatchSeconds, ?string $previewVttUrl = null): string
{
    $manifestUrl = json_encode(ve_absolute_url((string) $session['manifest_url']), JSON_UNESCAPED_SLASHES);
    $sessionToken = json_encode((string) $session['token'], JSON_UNESCAPED_SLASHES);
    $playbackToken = json_encode((string) ($session['playback_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $homeUrl = json_encode(ve_absolute_url('/'), JSON_UNESCAPED_SLASHES);
    $previewUrl = json_encode($previewVttUrl !== null ? ve_absolute_url($previewVttUrl) : '', JSON_UNESCAPED_SLASHES);
    $pulseViewUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/pulse'), JSON_UNESCAPED_SLASHES);
    $qualifyViewUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/qualify'), JSON_UNESCAPED_SLASHES);
    $minimumWatchSeconds = max(5, $minimumWatchSeconds);
    $pulseIntervalSeconds = ve_video_pulse_interval_seconds($minimumWatchSeconds);
    $requiredPulseCount = ve_video_required_pulse_count($minimumWatchSeconds);
    $segmentSeconds = max(1, (int) ve_video_config()['segment_seconds']);
    $pulseClientToken = json_encode((string) ($session['pulse_client_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $pulseServerToken = json_encode((string) ($session['pulse_server_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $pulseSequence = max(0, (int) ($session['pulse_sequence'] ?? 0));
    $clientProofKey = json_encode((string) ($session['client_proof_key'] ?? ''), JSON_UNESCAPED_SLASHES);

    return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
<script src="https://cdn.jsdelivr.net/npm/plyr@3/dist/plyr.polyfilled.min.js"></script>
<script>
    (function () {
        var manifestUrl = {$manifestUrl};
        var sessionToken = {$sessionToken};
        var playbackToken = {$playbackToken};
        var previewUrl = {$previewUrl};
        var fallbackUrl = {$homeUrl};
        var pulseViewUrl = {$pulseViewUrl};
        var qualifyViewUrl = {$qualifyViewUrl};
        var minimumWatchSeconds = {$minimumWatchSeconds};
        var pulseIntervalSeconds = {$pulseIntervalSeconds};
        var requiredPulseCount = {$requiredPulseCount};
        var segmentSeconds = {$segmentSeconds};
        var playbackClientToken = {$pulseClientToken};
        var playbackServerToken = {$pulseServerToken};
        var playbackSequence = {$pulseSequence};
        var clientProofKey = {$clientProofKey};
        var video = document.getElementById('ve-secure-player');
        var stage = document.querySelector('.ve-stage');
        var state = document.getElementById('ve-player-state');
        var overlay = document.getElementById('ve-player-overlay');
        var overlayButton = document.getElementById('ve-player-overlay-button');
        var player = null;
        var watchedSeconds = 0;
        var watchAuditTimer = null;
        var lastWatchSampleAt = 0;
        var lastWatchCurrentTime = 0;
        var pulseInFlight = false;
        var pulseAcceptedCount = 0;
        var pulseReadyForQualification = false;
        var nextPulseTargetSeconds = Math.min(minimumWatchSeconds, pulseIntervalSeconds);
        var proofKeyPromise = null;
        var qualificationSent = false;
        var qualificationInFlight = false;
        var qualificationRetryTimer = null;
        var nativePlay = video && typeof video.play === 'function' ? video.play.bind(video) : null;
        var hls = null;
        var hlsMediaAttached = false;
        var hlsSourceLoaded = false;
        var hlsLoaderActive = false;
        var pendingSourceLoad = false;
        var playbackStartRequested = false;
        var awaitingInitialPlayback = false;
        var playbackBootstrapped = false;
        var streamActivated = false;
        var sourceFailure = false;
        var bufferLowWatermark = Math.max(2, segmentSeconds * 0.75);
        var bufferHighWatermark = Math.max(bufferLowWatermark + 0.5, (segmentSeconds * 2) - 0.35);

        function setState(message, isError) {
            if (!state) {
                return;
            }

            state.textContent = message || '';
            state.className = 've-player-state'
                + (message ? ' is-visible' : '')
                + (isError ? ' is-error' : '');
        }

        function setOverlayMode(mode) {
            if (!overlay) {
                return;
            }

            overlay.classList.remove('is-hidden', 'is-loading');

            if (mode === 'hidden') {
                overlay.classList.add('is-hidden');
                return;
            }

            if (mode === 'loading') {
                overlay.classList.add('is-loading');
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

        function bufferedAheadSeconds() {
            if (!video || !video.buffered) {
                return 0;
            }

            var currentTime = Number(video.currentTime || 0);

            for (var index = 0; index < video.buffered.length; index += 1) {
                var start = video.buffered.start(index);
                var end = video.buffered.end(index);

                if (currentTime + 0.05 >= start && currentTime <= end + 0.05) {
                    return Math.max(0, end - currentTime);
                }
            }

            return 0;
        }

        function stopHlsLoading() {
            if (!hls || !hlsLoaderActive) {
                return;
            }

            hlsLoaderActive = false;

            try {
                hls.stopLoad();
            } catch (error) {}
        }

        function startHlsLoading() {
            if (!hls || !hlsSourceLoaded || hlsLoaderActive) {
                return;
            }

            hlsLoaderActive = true;

            try {
                hls.startLoad(Number.isFinite(Number(video && video.currentTime)) ? Number(video.currentTime) : -1);
            } catch (error) {
                hlsLoaderActive = false;
            }
        }

        function syncManagedLoading() {
            if (!hls || !hlsSourceLoaded || sourceFailure) {
                return;
            }

            if (!video || video.ended) {
                stopHlsLoading();
                return;
            }

            if (video.paused && !awaitingInitialPlayback) {
                stopHlsLoading();
                return;
            }

            if (bufferedAheadSeconds() >= bufferHighWatermark) {
                stopHlsLoading();
                return;
            }

            startHlsLoading();
        }

        function failSecurePlayback(message) {
            sourceFailure = true;
            awaitingInitialPlayback = false;
            stopHlsLoading();
            setOverlayMode('default');
            setState(message, true);
        }

        function ensureHlsPipeline() {
            if (!window.Hls || !window.Hls.isSupported()) {
                return false;
            }

            if (hls) {
                return true;
            }

            hls = new window.Hls({
                autoStartLoad: false,
                startFragPrefetch: false,
                maxBufferLength: bufferHighWatermark,
                maxMaxBufferLength: bufferHighWatermark,
                backBufferLength: bufferLowWatermark,
                maxBufferSize: 16 * 1024 * 1024,
                xhrSetup: function (xhr) {
                    xhr.withCredentials = true;
                    xhr.setRequestHeader('X-Playback-Session', sessionToken);
                }
            });

            hls.on(window.Hls.Events.MEDIA_ATTACHED, function () {
                hlsMediaAttached = true;

                if (pendingSourceLoad && !hlsSourceLoaded) {
                    pendingSourceLoad = false;
                    hlsSourceLoaded = true;
                    hls.loadSource(manifestUrl);
                }
            });

            hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
                hlsLoaderActive = false;
                startHlsLoading();

                if (playbackStartRequested && video && video.paused && typeof nativePlay === 'function') {
                    nativePlay().catch(function () {});
                }

                if (video && !video.paused) {
                    syncManagedLoading();
                }
            });

            hls.on(window.Hls.Events.FRAG_BUFFERED, function () {
                syncManagedLoading();
            });

            hls.on(window.Hls.Events.ERROR, function (event, data) {
                if (!data || !data.fatal) {
                    return;
                }

                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('VE secure player fatal HLS error', data.type || '', data.details || '');
                }

                failSecurePlayback('Playback session expired or could not be loaded. Reload the page to continue.');
            });

            hls.attachMedia(video);
            return true;
        }

        function ensureSourceLoaded() {
            if (!video || sourceFailure) {
                return false;
            }

            if (streamActivated) {
                return true;
            }

            streamActivated = true;
            playbackBootstrapped = true;
            bootUi();
            setOverlayMode('loading');
            setState('Loading encrypted video stream...');

            if (ensureHlsPipeline()) {
                if (hlsMediaAttached) {
                    if (!hlsSourceLoaded) {
                        hlsSourceLoaded = true;
                        hls.loadSource(manifestUrl);
                    }
                } else {
                    pendingSourceLoad = true;
                }

                return true;
            }

            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                if (!video.currentSrc) {
                    video.src = manifestUrl;
                    video.load();
                }

                return true;
            }

            failSecurePlayback('This browser cannot play the secure stream. Open the watch page in a modern browser.');
            return false;
        }

        function beginPlayback() {
            if (!video || typeof nativePlay !== 'function') {
                return Promise.resolve(false);
            }

            playbackStartRequested = true;
            awaitingInitialPlayback = true;

            if (!ensureSourceLoaded()) {
                awaitingInitialPlayback = false;
                return Promise.resolve(false);
            }

            if (hls) {
                syncManagedLoading();
            }

            if (video.readyState < 2) {
                return Promise.resolve(true);
            }

            var playPromise = nativePlay();

            if (playPromise && typeof playPromise.catch === 'function') {
                return playPromise.catch(function () {
                    return false;
                });
            }

            return Promise.resolve(true);
        }

        function nowMs() {
            if (window.performance && typeof window.performance.now === 'function') {
                return window.performance.now();
            }

            return Date.now();
        }

        function resetWatchSample() {
            lastWatchSampleAt = nowMs();
            lastWatchCurrentTime = Number(video && video.currentTime ? video.currentTime : 0);
        }

        function isPlaybackTrackable() {
            return Boolean(
                video
                && !document.hidden
                && !video.paused
                && !video.ended
                && !video.seeking
                && video.readyState >= 2
            );
        }

        function clearQualificationRetry() {
            if (qualificationRetryTimer !== null) {
                window.clearTimeout(qualificationRetryTimer);
                qualificationRetryTimer = null;
            }
        }

        function scheduleQualificationRetry(seconds) {
            if (qualificationSent) {
                return;
            }

            clearQualificationRetry();
            qualificationRetryTimer = window.setTimeout(function () {
                qualificationRetryTimer = null;
                maybeQualifyView();
            }, Math.max(1, seconds) * 1000);
        }

        function bytesToHex(bytes) {
            return Array.prototype.map.call(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }

        function textEncode(value) {
            var input = String(value || '');

            if (typeof window.TextEncoder === 'function') {
                return new TextEncoder().encode(input);
            }

            var escaped = unescape(encodeURIComponent(input));
            var bytes = new Uint8Array(escaped.length);

            for (var index = 0; index < escaped.length; index += 1) {
                bytes[index] = escaped.charCodeAt(index);
            }

            return bytes;
        }

        function sha256Bytes(inputBytes) {
            var bytes = Array.prototype.slice.call(inputBytes || []);
            var bitLength = bytes.length * 8;
            var words = [];
            var hash = [
                0x6a09e667,
                0xbb67ae85,
                0x3c6ef372,
                0xa54ff53a,
                0x510e527f,
                0x9b05688c,
                0x1f83d9ab,
                0x5be0cd19
            ];
            var constants = [
                0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5,
                0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
                0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
                0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
                0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc,
                0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
                0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
                0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
                0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
                0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
                0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3,
                0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
                0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5,
                0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
                0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
                0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
            ];

            function rightRotate(value, amount) {
                return (value >>> amount) | (value << (32 - amount));
            }

            bytes.push(0x80);

            while ((bytes.length % 64) !== 56) {
                bytes.push(0);
            }

            bytes.push(0);
            bytes.push(0);
            bytes.push(0);
            bytes.push(0);
            bytes.push((bitLength >>> 24) & 0xff);
            bytes.push((bitLength >>> 16) & 0xff);
            bytes.push((bitLength >>> 8) & 0xff);
            bytes.push(bitLength & 0xff);

            for (var chunkStart = 0; chunkStart < bytes.length; chunkStart += 64) {
                for (var wordIndex = 0; wordIndex < 16; wordIndex += 1) {
                    var offset = chunkStart + (wordIndex * 4);
                    words[wordIndex] = (
                        (bytes[offset] << 24)
                        | (bytes[offset + 1] << 16)
                        | (bytes[offset + 2] << 8)
                        | bytes[offset + 3]
                    ) >>> 0;
                }

                for (wordIndex = 16; wordIndex < 64; wordIndex += 1) {
                    var w15 = words[wordIndex - 15];
                    var w2 = words[wordIndex - 2];
                    var sigma0 = rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3);
                    var sigma1 = rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10);
                    words[wordIndex] = (words[wordIndex - 16] + sigma0 + words[wordIndex - 7] + sigma1) >>> 0;
                }

                var a = hash[0];
                var b = hash[1];
                var c = hash[2];
                var d = hash[3];
                var e = hash[4];
                var f = hash[5];
                var g = hash[6];
                var h = hash[7];

                for (wordIndex = 0; wordIndex < 64; wordIndex += 1) {
                    var sum1 = rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25);
                    var choice = (e & f) ^ (~e & g);
                    var temp1 = (h + sum1 + choice + constants[wordIndex] + words[wordIndex]) >>> 0;
                    var sum0 = rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22);
                    var majority = (a & b) ^ (a & c) ^ (b & c);
                    var temp2 = (sum0 + majority) >>> 0;

                    h = g;
                    g = f;
                    f = e;
                    e = (d + temp1) >>> 0;
                    d = c;
                    c = b;
                    b = a;
                    a = (temp1 + temp2) >>> 0;
                }

                hash[0] = (hash[0] + a) >>> 0;
                hash[1] = (hash[1] + b) >>> 0;
                hash[2] = (hash[2] + c) >>> 0;
                hash[3] = (hash[3] + d) >>> 0;
                hash[4] = (hash[4] + e) >>> 0;
                hash[5] = (hash[5] + f) >>> 0;
                hash[6] = (hash[6] + g) >>> 0;
                hash[7] = (hash[7] + h) >>> 0;
            }

            var output = new Uint8Array(32);

            for (var hashIndex = 0; hashIndex < hash.length; hashIndex += 1) {
                output[(hashIndex * 4)] = (hash[hashIndex] >>> 24) & 0xff;
                output[(hashIndex * 4) + 1] = (hash[hashIndex] >>> 16) & 0xff;
                output[(hashIndex * 4) + 2] = (hash[hashIndex] >>> 8) & 0xff;
                output[(hashIndex * 4) + 3] = hash[hashIndex] & 0xff;
            }

            return output;
        }

        function hmacSha256HexFallback(keyText, messageText) {
            var blockSize = 64;
            var keyBytes = Array.prototype.slice.call(textEncode(keyText));
            var messageBytes = Array.prototype.slice.call(textEncode(messageText));
            var innerPad = new Uint8Array(blockSize);
            var outerPad = new Uint8Array(blockSize);

            if (keyBytes.length > blockSize) {
                keyBytes = Array.prototype.slice.call(sha256Bytes(keyBytes));
            }

            while (keyBytes.length < blockSize) {
                keyBytes.push(0);
            }

            for (var index = 0; index < blockSize; index += 1) {
                innerPad[index] = keyBytes[index] ^ 0x36;
                outerPad[index] = keyBytes[index] ^ 0x5c;
            }

            var innerHash = sha256Bytes(Array.prototype.slice.call(innerPad).concat(messageBytes));

            return bytesToHex(sha256Bytes(Array.prototype.slice.call(outerPad).concat(Array.prototype.slice.call(innerHash))));
        }

        function canSignPlaybackRequests() {
            return Boolean(
                clientProofKey
                && playbackClientToken
                && playbackServerToken
                && (typeof window.TextEncoder === 'function' || typeof window.encodeURIComponent === 'function')
            );
        }

        function importProofKey() {
            if (!canSignPlaybackRequests()) {
                return Promise.reject(new Error('Playback proof signing is not available.'));
            }

            if (!window.crypto || !window.crypto.subtle) {
                return Promise.resolve(null);
            }

            if (proofKeyPromise === null) {
                proofKeyPromise = window.crypto.subtle.importKey(
                    'raw',
                    textEncode(clientProofKey),
                    { name: 'HMAC', hash: 'SHA-256' },
                    false,
                    ['sign']
                );
            }

            return proofKeyPromise;
        }

        function buildPlaybackProof(kind, watchedSeconds, requestUrl) {
            var pathname = '/';

            try {
                pathname = new URL(requestUrl, window.location.href).pathname || '/';
            } catch (error) {
                pathname = '/';
            }

            var canonical = [
                String(kind || '').toLowerCase(),
                'POST',
                pathname,
                String(Math.max(0, playbackSequence)),
                playbackClientToken,
                playbackServerToken,
                playbackToken,
                String(Math.max(0, Math.floor(watchedSeconds)))
            ].join('\\n');

            return importProofKey().then(function (cryptoKey) {
                if (cryptoKey === null) {
                    return hmacSha256HexFallback(clientProofKey, canonical);
                }

                return window.crypto.subtle.sign('HMAC', cryptoKey, textEncode(canonical)).then(function (signature) {
                    return bytesToHex(new Uint8Array(signature));
                });
            });
        }

        function buildPlaybackBody() {
            var params = new URLSearchParams();
            params.set('session_token', sessionToken);
            params.set('playback_token', playbackToken);
            params.set('watched_seconds', String(Math.max(0, Math.floor(watchedSeconds))));
            return params.toString();
        }

        function applyRotationPayload(rotation) {
            if (!rotation || typeof rotation !== 'object') {
                return;
            }

            if (typeof rotation.playback_token === 'string' && rotation.playback_token !== '') {
                playbackToken = rotation.playback_token;
            }

            if (typeof rotation.client_token === 'string' && rotation.client_token !== '') {
                playbackClientToken = rotation.client_token;
            }

            if (typeof rotation.server_token === 'string' && rotation.server_token !== '') {
                playbackServerToken = rotation.server_token;
            }

            if (Number.isFinite(Number(rotation.sequence))) {
                playbackSequence = Number(rotation.sequence);
            }

            if (Number.isFinite(Number(rotation.pulse_interval_seconds)) && Number(rotation.pulse_interval_seconds) > 0) {
                pulseIntervalSeconds = Number(rotation.pulse_interval_seconds);
            }

            if (Number.isFinite(Number(rotation.required_pulse_count)) && Number(rotation.required_pulse_count) > 0) {
                requiredPulseCount = Number(rotation.required_pulse_count);
            }
        }

        function postPlaybackRequest(url, kind) {
            return buildPlaybackProof(kind, watchedSeconds, url).then(function (proof) {
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Playback-Session': sessionToken,
                        'X-Playback-Client-Token': playbackClientToken,
                        'X-Playback-Server-Token': playbackServerToken,
                        'X-Playback-Sequence': String(Math.max(0, playbackSequence)),
                        'X-Playback-Proof': proof
                    },
                    body: buildPlaybackBody()
                });
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (payload) {
                    return {
                        statusCode: response.status,
                        payload: payload
                    };
                });
            });
        }

        function submitPlaybackPulse(force) {
            if (
                qualificationSent
                || pulseInFlight
                || !pulseViewUrl
                || !canSignPlaybackRequests()
                || watchedSeconds <= 0
                || (!force && watchedSeconds + 0.05 < nextPulseTargetSeconds)
            ) {
                return Promise.resolve(false);
            }

            pulseInFlight = true;

            return postPlaybackRequest(pulseViewUrl, 'pulse').then(function (result) {
                pulseInFlight = false;

                if (!result || !result.payload || typeof result.payload.status !== 'string') {
                    return false;
                }

                applyRotationPayload(result.payload.rotation || null);

                if (Number.isFinite(Number(result.payload.pulse_count))) {
                    pulseAcceptedCount = Number(result.payload.pulse_count);
                }

                pulseReadyForQualification = Boolean(result.payload.ready_for_qualification);

                if (Number.isFinite(Number(result.payload.accepted_watched_seconds))) {
                    var acceptedSeconds = Number(result.payload.accepted_watched_seconds);
                    nextPulseTargetSeconds = Math.min(
                        minimumWatchSeconds,
                        Math.max(
                            pulseIntervalSeconds,
                            acceptedSeconds + pulseIntervalSeconds
                        )
                    );
                }

                if (pulseReadyForQualification) {
                    maybeQualifyView();
                }

                return result.payload.status === 'ok';
            }).catch(function () {
                pulseInFlight = false;
                return false;
            });
        }

        function submitQualifiedView() {
            if (
                qualificationSent
                || qualificationInFlight
                || qualificationRetryTimer !== null
                || watchedSeconds < minimumWatchSeconds
                || !pulseReadyForQualification
                || !qualifyViewUrl
                || !canSignPlaybackRequests()
            ) {
                return;
            }

            qualificationInFlight = true;

            postPlaybackRequest(qualifyViewUrl, 'qualify').then(function (result) {
                qualificationInFlight = false;

                if (!result || !result.payload || typeof result.payload.status !== 'string') {
                    return;
                }

                applyRotationPayload(result.payload.rotation || null);
                pulseReadyForQualification = Boolean(result.payload.ready_for_qualification || false);

                if (result.payload.status === 'ok') {
                    qualificationSent = true;
                    clearQualificationRetry();
                    return;
                }

                if (result.payload.status === 'pending') {
                    pulseReadyForQualification = Boolean(result.payload.ready_for_qualification || false);
                    scheduleQualificationRetry(Number(result.payload.remaining_seconds || 1));
                }
            }).catch(function () {
                qualificationInFlight = false;
                scheduleQualificationRetry(3);
            });
        }

        function maybeQualifyView() {
            if (
                qualificationSent
                || qualificationInFlight
                || qualificationRetryTimer !== null
                || watchedSeconds < minimumWatchSeconds
            ) {
                return;
            }

            if (!pulseReadyForQualification) {
                submitPlaybackPulse(true);
                return;
            }

            submitQualifiedView();
        }

        function auditWatchProgress() {
            if (!video) {
                return;
            }

            var sampleAt = nowMs();
            var currentTime = Number(video.currentTime || 0);

            if (!lastWatchSampleAt) {
                lastWatchSampleAt = sampleAt;
                lastWatchCurrentTime = currentTime;
                return;
            }

            var elapsedSeconds = Math.max(0, (sampleAt - lastWatchSampleAt) / 1000);
            var mediaAdvanced = Math.max(0, currentTime - lastWatchCurrentTime);

            if (isPlaybackTrackable() && mediaAdvanced > 0.05) {
                watchedSeconds += Math.min(elapsedSeconds, mediaAdvanced);
                if (watchedSeconds + 0.05 >= nextPulseTargetSeconds) {
                    submitPlaybackPulse(false);
                }
                maybeQualifyView();
            }

            lastWatchSampleAt = sampleAt;
            lastWatchCurrentTime = currentTime;
        }

        function startWatchAudit() {
            if (!video) {
                return;
            }

            if (watchAuditTimer !== null) {
                return;
            }

            resetWatchSample();
            watchAuditTimer = window.setInterval(auditWatchProgress, 1000);
        }

        function stopWatchAudit() {
            if (!video) {
                return;
            }

            if (watchAuditTimer !== null) {
                auditWatchProgress();
                window.clearInterval(watchAuditTimer);
                watchAuditTimer = null;
            }

            resetWatchSample();
        }

        function bindWatchTracking() {
            if (!video) {
                return;
            }

            ['play', 'playing'].forEach(function (eventName) {
                video.addEventListener(eventName, startWatchAudit);
            });

            ['pause', 'ended', 'waiting', 'stalled', 'seeking'].forEach(function (eventName) {
                video.addEventListener(eventName, stopWatchAudit);
            });

            video.addEventListener('seeked', function () {
                resetWatchSample();

                if (isPlaybackTrackable()) {
                    startWatchAudit();
                }
            });

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    stopWatchAudit();
                    return;
                }

                if (isPlaybackTrackable()) {
                    startWatchAudit();
                }
            });

            window.addEventListener('pagehide', function () {
                stopWatchAudit();
                if (
                    !streamActivated
                    || watchedSeconds <= 0
                    || !canSignPlaybackRequests()
                    || (
                        watchedSeconds + 0.05 < nextPulseTargetSeconds
                        && watchedSeconds + 0.05 < minimumWatchSeconds
                    )
                ) {
                    return;
                }

                submitPlaybackPulse(true).then(function () {
                    maybeQualifyView();
                });
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

        bindWatchTracking();
        setState('');
        setOverlayMode('default');
        video.preload = 'none';
        video.play = function () {
            return beginPlayback();
        };

        if (overlayButton) {
            overlayButton.addEventListener('click', function (event) {
                event.preventDefault();
                beginPlayback();
            });
        }

        video.addEventListener('play', function () {
            if (!streamActivated) {
                beginPlayback();
            }

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('playing', function () {
            awaitingInitialPlayback = false;
            setOverlayMode('hidden');
            setState('');

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('progress', function () {
            if (!streamActivated) {
                return;
            }

            if (!video.paused && video.readyState >= 2) {
                setState('');
            }

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('timeupdate', function () {
            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('waiting', function () {
            if (!streamActivated) {
                return;
            }

            setState('Buffering secure stream...');

            if (hls) {
                startHlsLoading();
            }
        });

        video.addEventListener('seeked', function () {
            if (hls) {
                syncManagedLoading();
            }
        });

        ['loadeddata', 'loadedmetadata', 'canplay'].forEach(function (eventName) {
            video.addEventListener(eventName, function () {
                if (!streamActivated) {
                    return;
                }

                if (!video.paused) {
                    setState('');
                }

                if (playbackStartRequested && video.paused && typeof nativePlay === 'function') {
                    nativePlay().catch(function () {});
                }
            });
        });

        ['pause', 'ended'].forEach(function (eventName) {
            video.addEventListener(eventName, function () {
                awaitingInitialPlayback = false;
                if (hls) {
                    stopHlsLoading();
                }
            });
        });

        video.addEventListener('error', function () {
            if (!sourceFailure) {
                failSecurePlayback('Secure playback could not be started in this browser.');
            }
        });

        if (!(window.Hls && window.Hls.isSupported()) && !video.canPlayType('application/vnd.apple.mpegurl')) {
            setState('This browser cannot play the secure stream. Open the watch page in a modern browser.', true);
            window.setTimeout(function () {
                if (!video.currentSrc) {
                    window.location.href = fallbackUrl;
                }
            }, 1200);
        }
    }());
</script>
HTML;
}

function ve_video_duration_label(?float $seconds): string
{
    $duration = (float) ($seconds ?? 0);

    if ($duration <= 0) {
        return '';
    }

    return gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', (int) round($duration));
}

function ve_video_public_status_message(array $video): string
{
    return match ((string) ($video['status'] ?? VE_VIDEO_STATUS_QUEUED)) {
        VE_VIDEO_STATUS_FAILED => (string) (($video['processing_error'] ?? '') !== '' ? $video['processing_error'] : 'This video could not be processed.'),
        VE_VIDEO_STATUS_PROCESSING => 'This video is still being compressed and secured for streaming.',
        VE_VIDEO_STATUS_READY => 'This video is ready for playback.',
        default => 'This video is queued for processing.',
    };
}

function ve_video_player_image_mode_label(string $mode): string
{
    return match ($mode) {
        'splash' => 'Splash image',
        'single' => 'Generated poster',
        default => 'Default artwork',
    };
}

function ve_video_player_image_mode_description(string $mode): string
{
    return match ($mode) {
        'splash' => 'A protected splash image from Account Settings is shown before playback.',
        'single' => 'A generated poster frame is used as the player preview image.',
        default => 'The player uses its default preview behaviour until a poster is available.',
    };
}

function ve_render_secure_watch_page(string $publicId): void
{
    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video)) {
        ve_not_found();
    }

    $ownerSettings = ve_video_owner_settings($video);
    $isOwnerView = ve_video_is_owner_viewer($video);
    $title = trim((string) ($video['title'] ?? 'Untitled video'));
    $status = (string) ($video['status'] ?? VE_VIDEO_STATUS_QUEUED);
    $durationLabel = ve_video_duration_label(isset($video['duration_seconds']) ? (float) $video['duration_seconds'] : null);
    $lengthLabel = $durationLabel !== '' ? $durationLabel : ucfirst($status);
    $processedBytes = (int) ($video['processed_size_bytes'] ?? 0);
    $originalBytes = (int) ($video['original_size_bytes'] ?? 0);
    $downloadAvailable = ve_video_download_available($video);
    $downloadBytes = $downloadAvailable ? ve_video_download_size_bytes($video) : 0;
    $displayBytes = $downloadBytes > 0 ? $downloadBytes : ($processedBytes > 0 ? $processedBytes : $originalBytes);
    $sizeLabel = $displayBytes > 0 ? ve_video_format_bytes($displayBytes) : 'Protected stream';
    $uploadDateLabel = ve_video_legacy_date_label((string) ($video['created_at'] ?? ''));
    $statusCopy = ve_video_public_status_message($video);
    $pageTitle = ve_h($title . ' - DoodStream');
    $safeTitle = ve_h($title);
    $safeLength = ve_h($lengthLabel);
    $safeSize = ve_h($sizeLabel);
    $safeUploadDate = ve_h($uploadDateLabel !== '' ? $uploadDateLabel : 'Pending');
    $watchPageUrl = ve_h(ve_absolute_url('/d/' . rawurlencode($publicId)));
    $localEmbedUrl = ve_h(ve_absolute_url('/e/' . rawurlencode($publicId)));
    $embedWidth = max(240, (int) ($ownerSettings['embed_width'] ?? 600));
    $embedHeight = max(240, (int) ($ownerSettings['embed_height'] ?? 480));
    $iframeCode = ve_h('<iframe width="' . $embedWidth . '" height="' . $embedHeight . '" src="' . ve_absolute_url('/e/' . rawurlencode($publicId)) . '" scrolling="no" frameborder="0" allowfullscreen="true"></iframe>');
    $jqueryUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js');
    $bootstrapCssUrl = ve_h(ve_url('/assets/css/bootstrap.min.css'));
    $styleCssUrl = ve_h(ve_url('/assets/css/style.min.css'));
    $bootstrapJsUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.2.1/js/bootstrap.min.js');
    $posterMeta = ve_h(
        is_array(ve_video_resolve_public_image_asset($video, 'single'))
            ? ve_video_public_thumbnail_url($video, 'single')
            : ve_absolute_url('/assets/img/logo-s.png')
    );
    $downloadWaitSeconds = $downloadAvailable ? ve_video_download_wait_seconds_for_viewer() : 0;
    $downloadLabelText = ve_video_download_label($video);
    $downloadButtonIdleText = $downloadWaitSeconds === 0 ? 'Instant Download' : 'Download Now';
    $downloadStatusText = $downloadWaitSeconds === 0
        ? 'Premium account detected. Instant protected download is available.'
        : 'Free download unlocks after ' . $downloadWaitSeconds . ' seconds.';
    $downloadActionUrl = $downloadAvailable
        ? ve_h(ve_absolute_url('/download/' . rawurlencode($publicId)))
        : '';
    $downloadRequestUrl = $downloadAvailable
        ? ve_h(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/download/request'))
        : '';
    $downloadResolveUrl = $downloadAvailable
        ? ve_h(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/download/resolve'))
        : '';
    $downloadMetaText = ve_h($downloadLabelText . ' | ' . $sizeLabel);
    $safeDownloadButtonIdleText = ve_h($downloadButtonIdleText);
    $safeDownloadStatusText = ve_h($downloadStatusText);
    $downloadCsrfToken = ve_h(ve_csrf_token());
    $downloadActionUrlJs = json_encode($downloadAvailable ? ve_absolute_url('/download/' . rawurlencode($publicId)) : '', JSON_UNESCAPED_SLASHES);
    $downloadRequestUrlJs = json_encode($downloadAvailable ? ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/download/request') : '', JSON_UNESCAPED_SLASHES);
    $downloadResolveUrlJs = json_encode($downloadAvailable ? ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/download/resolve') : '', JSON_UNESCAPED_SLASHES);
    $downloadIdleLabelJs = json_encode($downloadButtonIdleText, JSON_UNESCAPED_SLASHES);
    $downloadLabelJs = json_encode($downloadLabelText, JSON_UNESCAPED_SLASHES);
    $downloadStatusTextJs = json_encode($downloadStatusText, JSON_UNESCAPED_SLASHES);
    $downloadCsrfTokenJs = json_encode(ve_csrf_token(), JSON_UNESCAPED_SLASHES);
    $safeStatusCopy = ve_h($statusCopy);
    $ownFileBanner = '';
    $exportPanel = '';

    if ($isOwnerView) {
        $ownFileBanner = <<<HTML
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12 pt-2">
                <p class="own-file text-center"><i class="fad fa-smile"></i><b>WoW!</b> as it is your own file we will not show any ads or adblock warnings, you can enjoy your file ad-free.</p>
            </div>
        </div>
    </div>
HTML;

        $exportPanel = <<<HTML
    <div class="container my-3">
        <div class="video-content text-center">
            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="pills-dr-tab" data-toggle="pill" href="#pills-dr" role="tab" aria-controls="pills-dr" aria-selected="true">Download link</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pills-el-tab" data-toggle="pill" href="#pills-el" role="tab" aria-controls="pills-el" aria-selected="false">Embed link</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pills-elc-tab" data-toggle="pill" href="#pills-elc" role="tab" aria-controls="pills-elc" aria-selected="false">Embed code</a>
                </li>
            </ul>
            <div class="tab-content" id="pills-tabContent">
                <div class="v-owner">only visible to the file owner</div>
                <div class="tab-pane fade show active buttonInside" id="pills-dr" role="tabpanel" aria-labelledby="pills-dr-tab">
                    <textarea id="code_txt" class="form-control export-txt">{$watchPageUrl}</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt">copy</button>
                </div>
                <div class="tab-pane fade buttonInside" id="pills-el" role="tabpanel" aria-labelledby="pills-el-tab">
                    <textarea id="code_txt_e" class="form-control export-txt">{$localEmbedUrl}</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt_e">copy</button>
                </div>
                <div class="tab-pane fade buttonInside" id="pills-elc" role="tabpanel" aria-labelledby="pills-elc-tab">
                    <textarea id="code_txt_ec" class="form-control export-txt">{$iframeCode}</textarea>
                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="code_txt_ec">copy</button>
                </div>
            </div>
        </div>
    </div>
HTML;
    }

    $downloadPanel = $downloadAvailable
        ? <<<HTML
    <div class="container">
        <div class="video-content text-center">
            <div id="ve-download-status" class="countdown">{$safeDownloadStatusText}</div>
            <div class="download-action-shell">
                <a href="#download_now" id="ve-download-button" class="btn btn-primary download_vd" data-ready="0">{$safeDownloadButtonIdleText} <i class="fad fa-arrow-right ml-2"></i></a>
            </div>
            <div id="ve-download-meta" class="download-meta" hidden>
                <label class="label-playlist d-block">Protected download</label>
                <div class="download-meta-copy">{$downloadMetaText}</div>
            </div>
        </div>
    </div>
HTML
        : <<<HTML
    <div class="container">
        <div class="video-content text-center">
            <div class="countdown">{$safeStatusCopy}</div>
        </div>
    </div>
HTML;

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{$pageTitle}</title>
    <meta name="og:title" content="{$safeTitle}">
    <meta name="og:sitename" content="DoodStream.com">
    <meta name="og:image" content="{$posterMeta}">
    <meta name="twitter:image" content="{$posterMeta}">
    <meta name="robots" content="nofollow, noindex">
    <script src="{$jqueryUrl}"></script>
    <link rel="stylesheet" href="{$bootstrapCssUrl}">
    <link rel="stylesheet" href="{$styleCssUrl}">
    <style>
        [style*="--aspect-ratio"] > :first-child { width: 100%; }
        [style*="--aspect-ratio"] > img { height: auto; }
        @supports (--custom: property) {
            [style*="--aspect-ratio"] { position: relative; }
            [style*="--aspect-ratio"]::before {
                content: "";
                display: block;
                padding-bottom: calc(100% / (var(--aspect-ratio)));
            }
            [style*="--aspect-ratio"] > :first-child {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
            }
        }
        .player-wrap iframe {
            width: 100%;
            height: 100%;
            min-height: 260px;
            border: 0;
        }
        .own-file {
            color: #12a701;
            border: 2px dashed #15bf00;
            padding: 10px 0 10px 40px;
            font-size: 15px;
            background: transparent;
        }
        .own-file .fad {
            font-size: 25px;
            position: absolute;
            margin-top: -1px;
            margin-left: -30px;
        }
        .title-wrap { background: #1c1c1c; }
        .nav-pills .nav-item { margin-right: 15px; }
        .nav-pills .nav-item .nav-link.active { background: #f90; color: #fff; }
        .nav-pills .nav-item .nav-link {
            font-weight: 600;
            color: #fff;
            background: #434645;
            border-radius: 1px;
            transition: color .3s ease, background .3s ease;
        }
        .v-owner {
            position: absolute;
            right: 8px;
            top: 5px;
            font-size: 12px;
            color: #6d6d6d;
        }
        .buttonInside {
            position: relative;
            margin-bottom: 10px;
        }
        .copy-in {
            position: absolute;
            right: 5px;
            top: 5px;
            border: none;
            outline: 0;
            text-align: center;
            font-weight: 700;
            padding: 2px 10px;
        }
        .copy-in:hover { cursor: pointer; }
        .export-txt {
            height: 42px !important;
            resize: none;
            padding-right: 68px;
        }
        .download-action-shell {
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 12px;
        }
        .download_vd {
            min-width: 260px;
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1.2;
        }
        .download_vd i {
            line-height: 1;
        }
        .download_vd.disabled,
        .download_vd.loading {
            pointer-events: none;
        }
        .download-meta {
            margin-top: 14px;
        }
        .download-meta-copy {
            color: #c7c7c7;
            font-weight: 600;
        }
        .spinner-inline {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .75s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 767.98px) {
            .copy-in {
                position: static;
                width: 100%;
                margin-top: 8px;
            }
            .export-txt {
                padding-right: 12px;
                height: 60px !important;
            }
        }
    </style>
</head>
<body>
    {$ownFileBanner}
    <div class="player-wrap container">
        <div style="--aspect-ratio: 16/9;" id="os_player">
            <iframe src="{$localEmbedUrl}" scrolling="no" frameborder="0" allowfullscreen="true"></iframe>
        </div>
    </div>

    <div class="container">
        <div class="title-wrap">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="info">
                    <h4>{$safeTitle}</h4>
                    <div class="d-flex flex-wrap align-items-center text-muted font-weight-bold">
                        <div class="length"><i class="fad fa-clock mr-1"></i>{$safeLength}</div>
                        <span class="mx-2"></span>
                        <div class="size"><i class="fad fa-save mr-1"></i>{$safeSize}</div>
                        <span class="mx-2"></span>
                        <div class="uploadate"><i class="fad fa-calendar-alt mr-1"></i>{$safeUploadDate}</div>
                    </div>
                </div>
                <a href="#lights" class="btn btn-white player_lights off">
                    <i class="fad fa-lightbulb-on"></i>
                </a>
            </div>
        </div>
    </div>

    {$exportPanel}
    {$downloadPanel}

    <script src="{$bootstrapJsUrl}"></script>
    <script>
        (function () {
            function copyText(targetId, button) {
                var field = document.getElementById(targetId);
                if (!field) {
                    return;
                }

                field.focus();
                field.select();
                field.setSelectionRange(0, field.value.length);

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value);
                } else {
                    document.execCommand('copy');
                }

                var original = button.textContent;
                button.textContent = 'copied';
                window.setTimeout(function () {
                    button.textContent = original;
                }, 1200);
            }

            document.querySelectorAll('[data-copy-target]').forEach(function (button) {
                button.addEventListener('click', function () {
                    copyText(button.getAttribute('data-copy-target'), button);
                });
            });

            var downloadRequestUrl = {$downloadRequestUrlJs};
            var downloadResolveUrl = {$downloadResolveUrlJs};
            var downloadActionUrl = {$downloadActionUrlJs};
            var downloadIdleLabel = {$downloadIdleLabelJs};
            var downloadLabel = {$downloadLabelJs};
            var downloadStatusText = {$downloadStatusTextJs};
            var downloadCsrfToken = {$downloadCsrfTokenJs};
            var downloadWaitSeconds = {$downloadWaitSeconds};
            var downloadButton = document.getElementById('ve-download-button');
            var downloadStatus = document.getElementById('ve-download-status');
            var downloadMeta = document.getElementById('ve-download-meta');
            var countdownTimer = null;
            var activeRequestToken = '';
            var activeDownloadToken = '';
            var requestInFlight = false;

            function updateDownloadStatus(message) {
                if (downloadStatus) {
                    downloadStatus.textContent = message;
                }
            }

            function setDownloadButtonMarkup(label, iconClass) {
                if (!downloadButton) {
                    return;
                }

                downloadButton.innerHTML = label + ' <i class="' + iconClass + ' ml-2"></i>';
            }

            function setDownloadIdleState() {
                requestInFlight = false;

                if (!downloadButton) {
                    return;
                }

                downloadButton.setAttribute('data-ready', '0');
                downloadButton.setAttribute('href', '#download_now');
                downloadButton.classList.remove('loading', 'disabled', 'download-ready');
                setDownloadButtonMarkup(downloadIdleLabel, 'fad fa-arrow-right');
                activeDownloadToken = '';
            }

            function setDownloadBusyState() {
                if (!downloadButton) {
                    return;
                }

                downloadButton.classList.add('loading', 'disabled');
                downloadButton.classList.remove('download-ready');
                downloadButton.setAttribute('data-ready', '0');
                downloadButton.setAttribute('href', '#download_now');
                downloadButton.innerHTML = '<span class="spinner-inline" aria-hidden="true"></span>';
            }

            function submitProtectedDownload() {
                if (!downloadActionUrl || !activeDownloadToken) {
                    setDownloadIdleState();
                    updateDownloadStatus('The protected download session expired. Request a new one.');
                    return;
                }

                var form = document.createElement('form');
                var tokenInput = document.createElement('input');
                var csrfInput = document.createElement('input');
                form.method = 'POST';
                form.action = downloadActionUrl;
                form.style.display = 'none';

                tokenInput.type = 'hidden';
                tokenInput.name = 'download_token';
                tokenInput.value = activeDownloadToken;
                form.appendChild(tokenInput);

                csrfInput.type = 'hidden';
                csrfInput.name = 'token';
                csrfInput.value = downloadCsrfToken;
                form.appendChild(csrfInput);

                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);

                setDownloadIdleState();
                updateDownloadStatus('Protected download started. Request a new one if you need another copy.');
            }

            function setDownloadReadyState(downloadToken, sizeLabel) {
                requestInFlight = false;

                if (!downloadButton) {
                    return;
                }

                activeDownloadToken = downloadToken;
                downloadButton.classList.remove('loading', 'disabled');
                downloadButton.classList.add('download-ready');
                downloadButton.setAttribute('data-ready', '1');
                downloadButton.setAttribute('href', '#download_now');
                setDownloadButtonMarkup('Download ' + downloadLabel, 'fad fa-cloud-download');
                updateDownloadStatus('Protected download is ready. This one-time link expires quickly after first use.');

                if (downloadMeta) {
                    downloadMeta.hidden = false;
                    downloadMeta.querySelector('.download-meta-copy').textContent = downloadLabel + ' | ' + sizeLabel;
                }
            }

            function postDownloadForm(url, payload) {
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: new URLSearchParams(payload).toString(),
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (data) {
                        return {
                            ok: response.ok,
                            status: response.status,
                            payload: data,
                        };
                    });
                });
            }

            function startDownloadCountdown(seconds) {
                var remaining = Math.max(0, seconds);

                if (countdownTimer !== null) {
                    window.clearInterval(countdownTimer);
                }

                updateDownloadStatus('Please wait ' + remaining + ' seconds before the protected download link is issued.');

                countdownTimer = window.setInterval(function () {
                    remaining -= 1;

                    if (remaining > 0) {
                        updateDownloadStatus('Please wait ' + remaining + ' seconds before the protected download link is issued.');
                        return;
                    }

                    window.clearInterval(countdownTimer);
                    countdownTimer = null;
                    resolveProtectedDownload();
                }, 1000);
            }

            function resolveProtectedDownload() {
                if (!downloadResolveUrl || !activeRequestToken) {
                    setDownloadIdleState();
                    updateDownloadStatus('The protected download session is missing. Request a new one.');
                    return;
                }

                setDownloadBusyState();
                updateDownloadStatus('Finalizing protected download link...');

                postDownloadForm(downloadResolveUrl, {
                    token: downloadCsrfToken,
                    request_token: activeRequestToken,
                }).then(function (result) {
                    if (!result.ok || !result.payload || result.payload.status !== 'ok') {
                        setDownloadIdleState();
                        updateDownloadStatus((result.payload && result.payload.message) || 'The protected download could not be issued.');
                        return;
                    }

                    if (result.payload.ready !== true || !result.payload.download_token) {
                        var retrySeconds = Number(result.payload.remaining_seconds || 0);
                        setDownloadBusyState();
                        startDownloadCountdown(retrySeconds > 0 ? retrySeconds : 1);
                        return;
                    }

                    setDownloadReadyState(result.payload.download_token, result.payload.size_label || '{$safeSize}');
                }).catch(function () {
                    setDownloadIdleState();
                    updateDownloadStatus('The protected download could not be issued.');
                });
            }

            if (downloadButton) {
                downloadButton.addEventListener('click', function (event) {
                    if (downloadButton.getAttribute('data-ready') === '1' && activeDownloadToken) {
                        event.preventDefault();
                        submitProtectedDownload();
                        return;
                    }

                    event.preventDefault();

                    if (requestInFlight) {
                        return;
                    }

                    requestInFlight = true;
                    activeRequestToken = '';
                    setDownloadBusyState();
                    updateDownloadStatus(downloadWaitSeconds === 0
                        ? 'Issuing protected premium download link...'
                        : 'Starting protected download timer...');

                    postDownloadForm(downloadRequestUrl, {
                        token: downloadCsrfToken,
                    }).then(function (result) {
                        if (!result.ok || !result.payload || result.payload.status !== 'ok') {
                            setDownloadIdleState();
                            updateDownloadStatus((result.payload && result.payload.message) || 'The protected download could not be started.');
                            return;
                        }

                        if (result.payload.ready === true && result.payload.download_token) {
                            setDownloadReadyState(result.payload.download_token, result.payload.size_label || '{$safeSize}');

                            if (downloadWaitSeconds === 0) {
                                submitProtectedDownload();
                            }

                            return;
                        }

                        activeRequestToken = result.payload.request_token || '';

                        if (!activeRequestToken) {
                            setDownloadIdleState();
                            updateDownloadStatus('The protected download session could not be started.');
                            return;
                        }

                        startDownloadCountdown(Number(result.payload.remaining_seconds || result.payload.wait_seconds || downloadWaitSeconds || 1));
                    }).catch(function () {
                        setDownloadIdleState();
                        updateDownloadStatus('The protected download could not be started.');
                    });
                });
            }

            $(document).on('click', '.player_lights', function (event) {
                event.preventDefault();
                var button = $(this);

                if (button.hasClass('off')) {
                    button.removeClass('off').addClass('on');
                    button.html('<i class="fad fa-lightbulb"></i>');
                    $('body').append('<div class="modal-backdrop fade" id="player-page-fade"></div>');
                    $('#player-page-fade').fadeTo('slow', 0.8);
                    return;
                }

                button.removeClass('on').addClass('off');
                button.html('<i class="fad fa-lightbulb-on"></i>');
                $('#player-page-fade').fadeTo('slow', 0, function () {
                    $('#player-page-fade').remove();
                });
            });
        }());
    </script>
</body>
</html>
HTML);
}

function ve_render_secure_video_page(string $publicId, bool $embed = false): void
{
    if (!$embed) {
        ve_render_secure_watch_page($publicId);
    }

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video)) {
        ve_not_found();
    }

    $title = ve_h((string) ($video['title'] ?? 'Untitled video'));

    if ((string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        $message = ve_h(ve_video_public_status_message($video));
        $refresh = (string) ($video['status'] ?? '') === VE_VIDEO_STATUS_FAILED ? '' : '<meta http-equiv="refresh" content="12">';

        ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    {$refresh}
    <style>
        html, body { margin:0; background:#000; color:#fff; font-family:Arial,sans-serif; }
        .ve-status-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
        }
        .ve-status-card {
            max-width: 720px;
            width: 100%;
            padding: 24px;
            background: rgba(17,17,17,0.94);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
        }
        .ve-status-card h1 { margin: 0 0 12px; font-size: 1.15rem; }
        .ve-status-card p { margin: 0; color: rgba(255,255,255,0.78); line-height: 1.6; }
    </style>
</head>
<body>
    <div class="ve-status-shell">
        <div class="ve-status-card">
            <h1>{$title}</h1>
            <p>{$message}</p>
        </div>
    </div>
</body>
</html>
HTML);
    }

    $ownerSettings = ve_video_owner_settings($video);
    $session = ve_video_issue_playback_session($video);
    $viewPolicy = ve_video_payable_view_policy();
    $previewVttUrl = is_file(ve_video_preview_vtt_path($video))
        ? '/stream/' . rawurlencode($publicId) . '/preview.vtt?token=' . rawurlencode((string) $session['token'])
        : null;
    $script = ve_video_secure_player_script(
        $session,
        $publicId,
        (int) ($viewPolicy['minimum_watch_seconds'] ?? 30),
        $previewVttUrl
    );
    $playerColour = strtolower(trim((string) ($ownerSettings['player_colour'] ?? 'ff9900')));

    if (!preg_match('/^[a-f0-9]{6}$/', $playerColour)) {
        $playerColour = 'ff9900';
    }

    $posterAsset = ve_video_resolve_poster_asset($video);
    $posterOverlay = '';

    if (is_array($posterAsset)) {
        $posterUrl = ve_h(ve_url('/stream/' . rawurlencode($publicId) . '/poster.jpg?token=' . rawurlencode((string) $session['token'])));
        $posterOverlay = '<img class="ve-player-poster" src="' . $posterUrl . '" alt="" draggable="false">';
    }

    $logoPath = trim((string) ($ownerSettings['logo_path'] ?? ''));
    $logoBadge = '';

    if ($logoPath !== '') {
        $logoBadgeUrl = ve_h(ve_url('/' . ltrim($logoPath, '/')));
        $logoBadge = '<img class="ve-logo-badge" src="' . $logoBadgeUrl . '" alt="Player logo">';
    }

    $titleMarkup = '';

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
            --ve-accent: #{$playerColour};
            --plyr-color-main: #{$playerColour};
        }
        html, body {
            margin: 0;
            background: #000;
            color: #fff;
            font-family: Arial, sans-serif;
        }
        .ve-embed-shell {
            background: #000;
        }
        .ve-stage {
            position: relative;
            width: 100%;
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
        .ve-player-overlay {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background:
                radial-gradient(circle at center, rgba(255,255,255,0.08), rgba(0,0,0,0.78) 62%),
                linear-gradient(180deg, rgba(0,0,0,0.18), rgba(0,0,0,0.68));
            transition: opacity .18s ease;
        }
        .ve-player-overlay.is-hidden {
            opacity: 0;
            pointer-events: none;
        }
        .ve-player-overlay.is-loading {
            pointer-events: none;
        }
        .ve-player-poster {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .ve-player-overlay-button {
            position: relative;
            z-index: 1;
            width: 94px;
            height: 94px;
            border: 0;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 153, 0, 0.96);
            color: #111;
            box-shadow: 0 18px 40px rgba(0,0,0,0.42);
            transition: transform .16s ease, opacity .16s ease, background .16s ease;
            cursor: pointer;
        }
        .ve-player-overlay-button:hover {
            transform: scale(1.04);
            background: rgba(255, 172, 38, 0.98);
        }
        .ve-player-overlay.is-loading .ve-player-overlay-button {
            opacity: 0.82;
        }
        .ve-player-overlay-button::before {
            content: "";
            display: block;
            width: 0;
            height: 0;
            margin-left: 6px;
            border-top: 17px solid transparent;
            border-bottom: 17px solid transparent;
            border-left: 27px solid currentColor;
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
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity .2s ease, transform .2s ease;
        }
        .ve-player-state.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .ve-player-state.is-error {
            border-color: rgba(255, 85, 85, 0.35);
            color: #ff9b9b;
        }
    </style>
</head>
<body>
    <div class="ve-embed-shell">
        <div class="ve-stage">
            {$logoBadge}
            <div id="ve-player-overlay" class="ve-player-overlay">
                {$posterOverlay}
                <button type="button" id="ve-player-overlay-button" class="ve-player-overlay-button" aria-label="Start secure playback"></button>
            </div>
            <video
                id="ve-secure-player"
                controls
                playsinline
                preload="none"
                controlsList="nodownload noplaybackrate"
                disablepictureinpicture
                crossorigin="use-credentials"
            ></video>
            <div id="ve-player-state" class="ve-player-state"></div>
        </div>
        {$titleMarkup}
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

function ve_video_dashboard_assets(): string
{
    $css = ve_url('/assets/css/video_dashboard.css');
    $js = ve_url('/assets/js/video_dashboard.js');

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
    $user = ve_current_user();

    if (!is_array($user)) {
        return '';
    }

    $settings = ve_get_user_settings((int) $user['id']);
    $playerMode = trim((string) ($settings['player_image_mode'] ?? ''));
    $playerModeLabel = ve_h(ve_video_player_image_mode_label($playerMode));
    $playerModeDescription = ve_h(ve_video_player_image_mode_description($playerMode));
    $settingsUrl = ve_h(ve_url('/dashboard/settings'));
    $uploadLimit = ve_video_upload_limit_bytes();
    $uploadLimitLabel = ve_h($uploadLimit > 0 ? ve_video_format_bytes($uploadLimit) : 'Server default');
    $processingReady = ve_video_processing_available();
    $processingCopy = ve_h($processingReady
        ? 'FFmpeg is available. Uploads are compressed, packaged as HLS and preview images are generated automatically.'
        : 'FFmpeg is not available yet. Uploading is disabled until video processing is configured on this server.');
    $embedWidth = max(240, (int) ($settings['embed_width'] ?? 600));
    $embedHeight = max(240, (int) ($settings['embed_height'] ?? 480));
    $noPoster = ve_h(ve_url('/assets/img/no-poster.png'));
    $acceptedTypes = ve_h('video/*,.mp4,.m4v,.mov,.mkv,.webm,.avi,.wmv,.flv,.mpeg,.mpg,.ts,.m2ts,.mts,.3gp');

    return <<<HTML
<section class="container-fluid manage ve-videos-page">
    <div class="container container-mp">
        <div
            id="ve-dashboard-videos"
            class="ve-dashboard-videos"
            data-settings-url="{$settingsUrl}"
            data-player-mode-label="{$playerModeLabel}"
            data-player-mode-description="{$playerModeDescription}"
            data-upload-limit-label="{$uploadLimitLabel}"
            data-processing-ready="{$processingReady}"
            data-processing-copy="{$processingCopy}"
            data-embed-width="{$embedWidth}"
            data-embed-height="{$embedHeight}"
            data-no-poster="{$noPoster}"
        >
            <input type="file" id="ve-video-upload-input" class="d-none" data-upload-input accept="{$acceptedTypes}" multiple>
            <div class="file_manager">
                <div class="title_wrap d-flex align-items-center justify-content-between flex-wrap">
                    <div>
                        <h2 class="title mb-1">My Videos</h2>
                        <span>Upload, compress and manage secure streams inside the existing dashboard workflow.</span>
                    </div>
                    <div class="btn-group mt-3 mt-lg-0">
                        <button type="button" class="btn btn-primary" data-action="select-files"><i class="fad fa-cloud-upload mr-2"></i>Upload Videos</button>
                        <button type="button" class="btn btn-white" data-action="refresh"><i class="fad fa-sync mr-2"></i>Refresh</button>
                        <a class="btn btn-white" href="{$settingsUrl}"><i class="fad fa-images mr-2"></i>Player Settings</a>
                    </div>
                </div>

                <div data-feedback></div>

                <div class="row mb-2">
                    <div class="col-sm-6 col-xl-3 mb-4">
                        <div class="the_box usage text-center ve-stat-box">
                            <i class="fad fa-play-circle"></i>
                            <span>Ready videos</span>
                            <div class="used"><strong data-stat-ready>0</strong></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3 mb-4">
                        <div class="the_box usage text-center ve-stat-box">
                            <i class="fad fa-cogs"></i>
                            <span>Queue / processing</span>
                            <div class="used"><strong data-stat-active>0</strong></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3 mb-4">
                        <div class="the_box usage text-center ve-stat-box">
                            <i class="fad fa-hdd"></i>
                            <span>Current storage</span>
                            <div class="used"><strong data-stat-storage>0 B</strong></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3 mb-4">
                        <div class="the_box usage text-center ve-stat-box">
                            <i class="fad fa-images"></i>
                            <span>Poster mode</span>
                            <div class="used"><strong data-stat-poster>{$playerModeLabel}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-4 mb-4">
                        <div class="ve-dashboard-box h-100">
                            <h4>Upload new videos</h4>
                            <p class="text-muted mb-3">All uploads are stored privately, compressed once and streamed back through short-lived HLS sessions instead of a public MP4 URL.</p>
                            <button type="button" class="ve-drop-zone" data-action="select-files">
                                <strong data-selected-title>Select one or more video files</strong>
                                <span data-selected-files>MP4, MKV, MOV, AVI, WEBM and similar containers are accepted.</span>
                            </button>
                            <div class="form-group mb-2">
                                <label class="small text-muted">Custom title for a single upload</label>
                                <input type="text" class="form-control" data-title-input placeholder="Optional title">
                            </div>
                            <div class="small text-muted mb-3">Upload limit: <span data-upload-limit-copy>{$uploadLimitLabel}</span></div>
                            <div class="small text-muted mb-3">{$processingCopy}</div>
                            <div class="d-flex flex-wrap">
                                <button type="button" class="btn btn-primary mr-2 mb-2" data-action="upload-selected">Start Upload</button>
                                <button type="button" class="btn btn-white mb-2" data-action="clear-selected">Clear</button>
                            </div>
                            <div class="small text-muted mb-0">Splash image and poster behaviour are managed from <a href="{$settingsUrl}">Player Settings</a>.</div>
                        </div>
                    </div>
                    <div class="col-xl-8 mb-4">
                        <div class="files">
                            <ul class="file_list" data-video-list>
                                <li class="header d-flex align-items-center">
                                    <div class="name">Name</div>
                                    <div class="size">Storage</div>
                                    <div class="date">Updated</div>
                                    <div class="views">Actions</div>
                                </li>
                                <li class="d-flex align-items-center ve-list-placeholder">
                                    <div class="name"><h4>Loading videos...</h4></div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="ve-video-links-modal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" data-modal-title>Video links</h5>
                            <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row align-items-center mb-4">
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <div class="ve-export-preview">
                                        <img src="{$noPoster}" alt="Poster preview" data-modal-poster>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="small text-muted mb-2" data-modal-meta>Token-protected HLS stream with generated poster and preview assets.</div>
                                    <a class="btn btn-white btn-sm" href="{$settingsUrl}">Change player settings</a>
                                </div>
                            </div>
                            <div class="tab-content">
                                <div class="tab-pane fade show active buttonInside" id="ve-links-watch" role="tabpanel" aria-labelledby="ve-links-watch-tab">
                                    <textarea id="ve-link-watch" class="form-control export-txt" rows="1"></textarea>
                                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="ve-link-watch">copy</button>
                                </div>
                                <div class="tab-pane fade buttonInside" id="ve-links-embed" role="tabpanel" aria-labelledby="ve-links-embed-tab">
                                    <textarea id="ve-link-embed" class="form-control export-txt" rows="1"></textarea>
                                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="ve-link-embed">copy</button>
                                </div>
                                <div class="tab-pane fade buttonInside" id="ve-links-iframe" role="tabpanel" aria-labelledby="ve-links-iframe-tab">
                                    <textarea id="ve-link-iframe" class="form-control export-txt" rows="2"></textarea>
                                    <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="ve-link-iframe">copy</button>
                                </div>
                            </div>
                            <ul class="nav nav-pills mb-0 mt-4" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="ve-links-watch-tab" data-toggle="tab" href="#ve-links-watch" role="tab" aria-controls="ve-links-watch">Watch link</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="ve-links-embed-tab" data-toggle="tab" href="#ve-links-embed" role="tab" aria-controls="ve-links-embed">Embed link</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="ve-links-iframe-tab" data-toggle="tab" href="#ve-links-iframe" role="tab" aria-controls="ve-links-iframe">Embed code</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="uploading-files position-fixed is-hidden" data-upload-panel>
                <div class="header d-flex align-items-center justify-content-between">
                    <strong data-upload-header>Uploads</strong>
                    <button type="button" data-action="toggle-upload-panel"><i class="fad fa-minus"></i></button>
                </div>
                <div class="uploading-list" data-upload-list></div>
            </div>
        </div>
    </div>
</section>
HTML;
}

function ve_render_home_page(): void
{
    $html = (string) file_get_contents(ve_root_path('index.html'));
    $html = ve_runtime_html_transform($html, 'index.html');

    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_videos_dashboard_page(): void
{
    $user = ve_current_user();
    $settings = is_array($user) ? ve_get_user_settings((int) $user['id']) : [];
    $embedWidth = max(240, (int) ($settings['embed_width'] ?? 600));
    $embedHeight = max(240, (int) ($settings['embed_height'] ?? 480));

    $html = (string) file_get_contents(ve_root_path('dashboard', 'index.html'));
    $html = str_replace('<title>Dashboard - DoodStream</title>', '<title>My Videos - DoodStream</title>', $html);
    $html = str_replace('href="/videos"', 'href="/dashboard/videos"', $html);

    $headAssets = <<<'HTML'
<style type="text/css">.vue-simple-context-menu{top:0;left:0;margin:0;padding:0;display:none;list-style:none;position:absolute;z-index:1000000;background-color:#ecf0f1;border-bottom-width:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Fira Sans","Droid Sans","Helvetica Neue",sans-serif;box-shadow:0 3px 6px 0 rgba(51,51,51,.2);border-radius:4px}.vue-simple-context-menu--active{display:block}.vue-simple-context-menu__item{display:flex;color:#333;cursor:pointer;padding:5px 15px;align-items:center}.vue-simple-context-menu__item:hover{background-color:#007aff;color:#fff}.vue-simple-context-menu li:first-of-type{margin-top:4px}.vue-simple-context-menu li:last-of-type{margin-bottom:4px}.text-orange{color:#f90;}@media (max-width: 768px){ .container-fluid{ padding-right:0px; padding-left:0px; } .file_manager { border-radius:0px !important; padding: 5px !important; } .file_manager .title_wrap { padding: 0px !important; margin-bottom:0px !important; }}.my-premium .bandwidth-plans .payments .selection, .my-premium .premium-plans .payments .selection { width: 100% !important;}.my-premium .bandwidth-plans .payments .selection .custom-control.p-plan:nth-of-type(3n), .my-premium .premium-plans .payments .selection .custom-control.p-plan:nth-of-type(3n) { margin-right: 20px !important;}@media (min-width: 1200px){ .my-premium .bandwidth-plans .payments .selection .custom-control:not(.p-plan):nth-of-type(5n+1), .my-premium .premium-plans .payments .selection .custom-control:not(.p-plan):nth-of-type(5n+1) { clear: both; margin-left: 20px !important; }}.my-premium .bandwidth-plans .payments .btn, .my-premium .premium-plans .payments .btn { margin-left: 20px;}.remote-list small{ display: block; color: #ea8c00;}.remote-list .fa-external-link{ font-size: 10px; margin: 2px;}.remote-list .badge{ padding: 7px;}@media (min-width: 1350px){ .container-mp { max-width: 1350px !important; }} </style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tootik/1.0.2/css/tootik.min.css" />
<link rel="stylesheet" href="/assets/css/video_page.min.css">
HTML;
    $html = str_replace('</head>', $headAssets . '</head>', $html);

    $contentMarker = '<div class="container-fluid pt-3 pt-sm-5 mt-sm-5">';
    $footerMarker = '<footer class="footer mt-4">';
    $contentPosition = strpos($html, $contentMarker);
    $footerPosition = strpos($html, $footerMarker);

    if ($contentPosition !== false && $footerPosition !== false && $footerPosition > $contentPosition) {
        $pageContent = <<<HTML
<div id="app" class="ve-video-dashboard-app">
    <video-manager :ask-content-type="0" embed-code-width="{$embedWidth}" embed-code-height="{$embedHeight}"></video-manager>
</div>
HTML;
        $html = substr($html, 0, $contentPosition + strlen($contentMarker))
            . $pageContent
            . substr($html, $footerPosition);
    }

    $selectionJs = 'https://cdnjs.cloudflare.com/ajax/libs/selection-js/1.7.1/selection.min.js';
    $vueJs = 'https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.min.js';
    $videoPageJs = ve_h(ve_url('/assets/js/video_page__q_f247575e8408.js'));
    $bootstrapJs = ve_h(ve_url('/assets/js/video_dashboard_bootstrap.js'));
    $legacyJs = ve_h(ve_url('/assets/js/video_dashboard_legacy.js'));
    $extraScripts = '<script src="' . ve_h($vueJs) . '"></script>'
        . '<script src="' . ve_h($selectionJs) . '"></script>'
        . '<script src="' . $videoPageJs . '"></script>'
        . '<script src="' . $bootstrapJs . '"></script>'
        . '<script src="' . $legacyJs . '"></script>';

    $doodLoadTag = '<script src="/assets/js/dood_load.js" type="module" defer></script>';

    if (str_contains($html, $doodLoadTag)) {
        $html = str_replace($doodLoadTag, $extraScripts . $doodLoadTag, $html);
    } else {
        $html = str_replace('</body>', $extraScripts . '</body>', $html);
    }

    $html = ve_runtime_html_transform($html, 'dashboard/videos.html');
    ve_html(ve_rewrite_html_paths($html));
}
