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

function ve_video_folder_scope_ids(int $userId, int $folderId): array
{
    $folderId = ve_video_normalize_folder_id($userId, $folderId);

    if ($folderId <= 0) {
        return [];
    }

    $ids = [$folderId];

    foreach (ve_video_folder_collect_descendant_ids($userId, $folderId) as $descendantId) {
        $ids[] = $descendantId;
    }

    return $ids;
}

function ve_video_folder_size_bytes(int $userId, int $folderId): int
{
    static $cache = [];

    $cacheKey = $userId . ':' . $folderId;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $folderIds = ve_video_folder_scope_ids($userId, $folderId);

    if ($folderIds === []) {
        $cache[$cacheKey] = 0;
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($folderIds), '?'));
    $params = array_merge([$userId], $folderIds);
    $stmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN processed_size_bytes > 0 THEN processed_size_bytes ELSE original_size_bytes END), 0)
         FROM videos
         WHERE user_id = ? AND deleted_at IS NULL AND folder_id IN (' . $placeholders . ')'
    );
    $stmt->execute($params);
    $cache[$cacheKey] = (int) $stmt->fetchColumn();

    return $cache[$cacheKey];
}

function ve_video_folder_public_url(string $publicCode): string
{
    $publicCode = trim($publicCode);

    if ($publicCode === '') {
        return '';
    }

    return ve_absolute_url('/videos/shared/' . rawurlencode($publicCode));
}

function ve_video_folder_share_url(array $folder): string
{
    return ve_video_folder_public_url((string) ($folder['public_code'] ?? ''));
}

function ve_video_folder_get_by_public_code(string $publicCode): ?array
{
    $publicCode = trim($publicCode);

    if ($publicCode === '') {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM video_folders
         WHERE public_code = :public_code AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':public_code' => $publicCode]);
    $folder = $stmt->fetch();

    return is_array($folder) ? $folder : null;
}

function ve_video_folder_list_public_videos(array $folder): array
{
    $folderId = (int) ($folder['id'] ?? 0);
    $userId = (int) ($folder['user_id'] ?? 0);

    if ($folderId <= 0 || $userId <= 0) {
        return [];
    }

    $stmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE user_id = :user_id
           AND folder_id = :folder_id
           AND deleted_at IS NULL
           AND is_public = 1
           AND status = :status
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':folder_id' => $folderId,
        ':status' => VE_VIDEO_STATUS_READY,
    ]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
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
    $folderId = (int) ($folder['id'] ?? 0);
    $userId = (int) ($folder['user_id'] ?? 0);
    $sizeBytes = ($folderId > 0 && $userId > 0) ? ve_video_folder_size_bytes($userId, $folderId) : 0;

    return [
        'fld_id' => $folderId,
        'fld_code' => (string) ($folder['public_code'] ?? ''),
        'fld_name' => (string) ($folder['name'] ?? ''),
        'siz' => ve_video_format_bytes($sizeBytes),
        'siz_bytes' => $sizeBytes,
        'cre' => ve_video_legacy_date_label((string) ($folder['created_at'] ?? '')),
        'share_url' => ve_video_folder_share_url($folder),
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
        'current_folder' => is_array($currentFolder) ? ve_video_folder_to_legacy_payload($currentFolder) : null,
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
    $shareUrl = ve_h(ve_video_folder_share_url($folder));
    $publicCount = count(ve_video_folder_list_public_videos($folder));
    $shareNotice = $publicCount > 0
        ? 'Shared visitors can open the public videos in this folder through this link.'
        : 'This shared folder page is live, but only public videos appear to visitors. Private videos stay hidden until you mark them public.';
    $toggleId = 'share-folder-title-' . (int) ($folder['id'] ?? 0);

    ve_html(<<<HTML
<div data-share-folder-root data-share-folder-link="{$shareUrl}" data-share-folder-title="{$folderName}">
    <p class="mb-2"><strong>{$folderName}</strong></p>
    <div class="custom-control custom-switch mb-3">
        <input type="checkbox" class="custom-control-input" id="{$toggleId}" value="1" data-share-folder-toggle>
        <label class="custom-control-label" for="{$toggleId}">Show Title</label>
    </div>
    <div class="form-group mb-3">
        <label>Share link</label>
        <textarea class="form-control" rows="3" data-share-folder-output onclick="this.focus();this.select()">{$shareUrl}</textarea>
    </div>
    <p class="text-muted mb-0">{$shareNotice}</p>
</div>
HTML);
}

function ve_render_public_folder_page(string $publicCode): void
{
    $folder = ve_video_folder_get_by_public_code($publicCode);

    if (!is_array($folder)) {
        ve_not_found();
    }

    $folderName = (string) ($folder['name'] ?? 'Shared folder');
    $folderId = (int) ($folder['id'] ?? 0);
    $userId = (int) ($folder['user_id'] ?? 0);
    $folders = ($folderId > 0 && $userId > 0) ? ve_video_folder_list_children($userId, $folderId) : [];
    $videos = ve_video_folder_list_public_videos($folder);
    $pageTitle = ve_h($folderName . ' - Shared Folder');
    $safeFolderName = ve_h($folderName);
    $folderSize = ve_h(ve_video_format_bytes(ve_video_folder_size_bytes($userId, $folderId)));
    $folderCreated = ve_h(ve_video_legacy_date_label((string) ($folder['created_at'] ?? '')));
    $shareUrl = ve_h(ve_video_folder_share_url($folder));
    $jqueryUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js');
    $bootstrapCssUrl = ve_h(ve_url('/assets/css/bootstrap.min.css'));
    $styleCssUrl = ve_h(ve_url('/assets/css/style.min.css'));
    $panelCssUrl = ve_h(ve_url('/assets/css/panel.min__q_e2207d238712.css'));
    $videoPageCssUrl = ve_h(ve_url('/assets/css/video_page.min.css'));
    $bootstrapJsUrl = ve_h('https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.2.1/js/bootstrap.min.js');
    $logoUrl = ve_h(ve_url('/assets/img/logo-s.png'));
    $folderRows = '';
    $videoRows = '';
    $hasSubfolders = false;

    foreach ($folders as $childFolder) {
        if (!is_array($childFolder)) {
            continue;
        }

        $hasSubfolders = true;
        $childName = ve_h((string) ($childFolder['name'] ?? 'Folder'));
        $childUrl = ve_h(ve_video_folder_share_url($childFolder));
        $childSize = ve_h(ve_video_format_bytes(ve_video_folder_size_bytes($userId, (int) ($childFolder['id'] ?? 0))));
        $childCreated = ve_h(ve_video_legacy_date_label((string) ($childFolder['created_at'] ?? '')));
        $folderRows .= <<<HTML
<li class="folder item d-flex align-items-center">
    <div class="name">
        <h4><a href="{$childUrl}">{$childName}</a></h4>
        <span class="text-muted">Shared sub-folder</span>
    </div>
    <div class="size d-none d-sm-block">{$childSize}</div>
    <div class="date d-none d-sm-block">{$childCreated}</div>
    <div class="views d-none d-sm-block">-</div>
</li>
HTML;
    }

    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }

        $videoTitle = ve_h((string) ($video['title'] ?? 'Untitled video'));
        $watchUrl = ve_h(ve_absolute_url('/d/' . rawurlencode((string) ($video['public_id'] ?? ''))));
        $embedUrl = ve_h(ve_absolute_url('/e/' . rawurlencode((string) ($video['public_id'] ?? ''))));
        $videoSize = ve_h(ve_video_format_bytes(ve_video_download_size_bytes($video)));
        $videoCreated = ve_h(ve_video_legacy_date_label((string) ($video['created_at'] ?? '')));
        $videoRows .= <<<HTML
<li class="video item d-flex align-items-center">
    <div class="name">
        <h4><a href="{$watchUrl}" target="_blank" rel="noopener">{$videoTitle}</a></h4>
        <div class="ve-public-actions">
            <a href="{$watchUrl}" target="_blank" rel="noopener">Watch</a>
            <a href="{$embedUrl}" target="_blank" rel="noopener">Embed</a>
        </div>
    </div>
    <div class="size d-none d-sm-block">{$videoSize}</div>
    <div class="date d-none d-sm-block">{$videoCreated}</div>
    <div class="views d-none d-sm-block">-</div>
</li>
HTML;
    }

    $foldersSection = '';

    if ($hasSubfolders) {
        $foldersSection = <<<HTML
        <div class="files mb-4">
            <div class="title_wrap d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h2 class="title mb-1">Folders</h2>
                    <span>Browse the shared sub-folders inside this folder.</span>
                </div>
            </div>
            <ul class="file_list">
                <li class="header d-flex align-items-center">
                    <div class="name">Name</div>
                    <div class="size">Size</div>
                    <div class="date">Created</div>
                    <div class="views">Views</div>
                </li>
                {$folderRows}
            </ul>
        </div>
HTML;
    }

    if ($videoRows === '') {
        $videoRows = <<<HTML
<li class="d-flex align-items-center ve-public-empty">
    <div class="name"><h4>No public videos are available in this folder yet.</h4></div>
</li>
HTML;
    }

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{$pageTitle}</title>
    <script src="{$jqueryUrl}"></script>
    <link rel="stylesheet" href="{$bootstrapCssUrl}">
    <link rel="stylesheet" href="{$styleCssUrl}">
    <link rel="stylesheet" href="{$panelCssUrl}">
    <link rel="stylesheet" href="{$videoPageCssUrl}">
    <style>
        body {
            background: #0d0d0d;
        }
        .player-wrap .ve-folder-stage {
            width: 100%;
            min-height: 280px;
            background:
                radial-gradient(circle at top right, rgba(255, 153, 0, 0.2), transparent 34%),
                linear-gradient(135deg, #111 0%, #1d1d1d 55%, #080808 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .ve-folder-stage-copy {
            width: min(720px, calc(100% - 48px));
            text-align: center;
            color: #fff;
        }
        .ve-folder-stage-copy img {
            width: 120px;
            margin-bottom: 18px;
        }
        .ve-folder-stage-copy h1 {
            margin: 0 0 14px;
            font-size: clamp(2rem, 3vw, 3.2rem);
            letter-spacing: -0.03em;
        }
        .ve-folder-stage-copy p {
            margin: 0;
            color: rgba(255, 255, 255, 0.72);
            font-size: 1rem;
        }
        .title-wrap .btn {
            min-width: 152px;
        }
        .file_manager.public-folder-manager .title_wrap {
            margin-bottom: 0;
        }
        .file_manager.public-folder-manager .files {
            background: #fff;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        .ve-public-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.875rem;
        }
        .ve-public-actions a {
            color: #ea8c00;
            font-weight: 700;
            text-decoration: none;
        }
        .ve-public-actions a:hover {
            text-decoration: underline;
        }
        .ve-public-empty {
            background: #fff;
        }
        .ve-public-empty .name {
            width: 100%;
        }
        @media (max-width: 767.98px) {
            .player-wrap .ve-folder-stage {
                min-height: 220px;
            }
            .title-wrap .btn {
                width: 100%;
                margin-top: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="player-wrap container">
        <div style="--aspect-ratio: 16/9;" class="ve-folder-stage">
            <div class="ve-folder-stage-copy">
                <img src="{$logoUrl}" alt="DoodStream">
                <h1>{$safeFolderName}</h1>
                <p>Shared from the secure video dashboard with the same watch links and embedded playback flow.</p>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="title-wrap">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="info">
                    <h4>{$safeFolderName}</h4>
                    <div class="d-flex flex-wrap align-items-center text-muted font-weight-bold">
                        <div class="size"><i class="fad fa-save mr-1"></i>{$folderSize}</div>
                        <span class="mx-2"></span>
                        <div class="uploadate"><i class="fad fa-calendar-alt mr-1"></i>{$folderCreated}</div>
                    </div>
                </div>
                <button class="btn btn-white" type="button" data-copy-target="ve-public-folder-share">
                    <i class="fad fa-link mr-2"></i>Copy Share Link
                </button>
            </div>
        </div>
    </div>

    <div class="container my-3">
        <div class="video-content text-center">
            <div class="buttonInside">
                <textarea id="ve-public-folder-share" class="form-control export-txt" rows="1">{$shareUrl}</textarea>
                <button class="copy-in btn btn-primary btn-sm" type="button" data-copy-target="ve-public-folder-share">copy</button>
            </div>
        </div>
    </div>

    <div class="container my-4">
        <div class="file_manager public-folder-manager">
            {$foldersSection}
            <div class="files">
                <div class="title_wrap d-flex align-items-center justify-content-between flex-wrap">
                    <div>
                        <h2 class="title mb-1">Videos</h2>
                        <span>Only public videos are visible inside this shared folder.</span>
                    </div>
                </div>
                <ul class="file_list">
                    <li class="header d-flex align-items-center">
                        <div class="name">Name</div>
                        <div class="size">Size</div>
                        <div class="date">Created</div>
                        <div class="views">Views</div>
                    </li>
                    {$videoRows}
                </ul>
            </div>
        </div>
    </div>

    <script src="{$bootstrapJsUrl}"></script>
    <script>
        (function () {
            function copyTarget(targetId) {
                var field = document.getElementById(targetId);

                if (!field) {
                    return;
                }

                field.focus();
                field.select();

                try {
                    document.execCommand('copy');
                } catch (error) {}
            }

            document.querySelectorAll('[data-copy-target]').forEach(function (button) {
                button.addEventListener('click', function () {
                    copyTarget(button.getAttribute('data-copy-target'));
                });
            });
        }());
    </script>
</body>
</html>
HTML);
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

function ve_video_playback_preview_vtt_url(array $video, string $sessionToken): ?string
{
    if (!is_file(ve_video_preview_vtt_path($video))) {
        return null;
    }

    return '/stream/' . rawurlencode((string) $video['public_id']) . '/preview.vtt?token=' . rawurlencode($sessionToken);
}

/**
 * @return array{
 *     session_token:string,
 *     manifest_url:string,
 *     key_url:string,
 *     preview_url:string,
 *     playback_token:string,
 *     client_proof_key:string,
 *     pulse_client_token:string,
 *     pulse_server_token:string,
 *     pulse_sequence:int,
 *     session_ttl_seconds:int,
 *     issued_at_ms:int
 * }
 */
function ve_video_playback_session_payload(array $video, array $session): array
{
    $sessionToken = (string) ($session['token'] ?? '');
    $previewVttUrl = $sessionToken !== ''
        ? ve_video_playback_preview_vtt_url($video, $sessionToken)
        : null;

    return [
        'session_token' => $sessionToken,
        'manifest_url' => ve_absolute_url((string) ($session['manifest_url'] ?? '')),
        'key_url' => ve_absolute_url('/stream/' . rawurlencode((string) $video['public_id']) . '/key?token=' . rawurlencode($sessionToken)),
        'preview_url' => $previewVttUrl !== null ? ve_absolute_url($previewVttUrl) : '',
        'playback_token' => (string) ($session['playback_token'] ?? ''),
        'client_proof_key' => (string) ($session['client_proof_key'] ?? ''),
        'pulse_client_token' => (string) ($session['pulse_client_token'] ?? ''),
        'pulse_server_token' => (string) ($session['pulse_server_token'] ?? ''),
        'pulse_sequence' => max(0, (int) ($session['pulse_sequence'] ?? 0)),
        'session_ttl_seconds' => (int) ve_video_config()['session_ttl'],
        'issued_at_ms' => ve_timestamp() * 1000,
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

function ve_video_full_play_threshold_seconds(array $video): int
{
    $durationSeconds = max(0.0, (float) ($video['duration_seconds'] ?? 0));

    if ($durationSeconds <= 0) {
        return 1;
    }

    return max(1, (int) ceil($durationSeconds - 0.75));
}

/**
 * @return array{status:string,message:string,already_recorded:bool,watched_seconds:int,required_seconds:int,remaining_seconds:int,full_play:bool}
 */
function ve_video_record_full_play(array $video, array $session, int $watchedSeconds): array
{
    $sessionId = (int) ($session['id'] ?? 0);

    if ($sessionId <= 0) {
        return [
            'status' => 'fail',
            'message' => 'The playback session is incomplete.',
            'already_recorded' => false,
            'watched_seconds' => $watchedSeconds,
            'required_seconds' => ve_video_full_play_threshold_seconds($video),
            'remaining_seconds' => ve_video_full_play_threshold_seconds($video),
            'full_play' => false,
        ];
    }

    $requiredSeconds = ve_video_full_play_threshold_seconds($video);
    $lastPulseWatchedSeconds = max(0, (int) ($session['last_pulse_watched_seconds'] ?? 0));
    $acceptedWatchedSeconds = max($lastPulseWatchedSeconds, $watchedSeconds);
    $reportedAt = trim((string) ($session['full_play_reported_at'] ?? ''));
    $reportedWatchedSeconds = max(0, (int) ($session['full_play_watched_seconds'] ?? 0));

    if ($reportedAt !== '') {
        return [
            'status' => 'ok',
            'message' => 'This playback session was already recorded as a full play.',
            'already_recorded' => true,
            'watched_seconds' => max($acceptedWatchedSeconds, $reportedWatchedSeconds),
            'required_seconds' => $requiredSeconds,
            'remaining_seconds' => 0,
            'full_play' => true,
        ];
    }

    if ($acceptedWatchedSeconds < $requiredSeconds) {
        return [
            'status' => 'pending',
            'message' => 'Playback has not reached the full-play threshold yet.',
            'already_recorded' => false,
            'watched_seconds' => $acceptedWatchedSeconds,
            'required_seconds' => $requiredSeconds,
            'remaining_seconds' => max(0, $requiredSeconds - $acceptedWatchedSeconds),
            'full_play' => false,
        ];
    }

    if ((int) ($session['bandwidth_bytes_served'] ?? 0) <= 0) {
        return [
            'status' => 'pending',
            'message' => 'Playback data has not been streamed long enough to record a full play yet.',
            'already_recorded' => false,
            'watched_seconds' => $acceptedWatchedSeconds,
            'required_seconds' => $requiredSeconds,
            'remaining_seconds' => 1,
            'full_play' => false,
        ];
    }

    $recordedAt = ve_now();
    $update = ve_db()->prepare(
        'UPDATE video_playback_sessions
         SET full_play_reported_at = :full_play_reported_at,
             full_play_watched_seconds = :full_play_watched_seconds
         WHERE id = :id
           AND full_play_reported_at IS NULL'
    );
    $update->execute([
        ':full_play_reported_at' => $recordedAt,
        ':full_play_watched_seconds' => $acceptedWatchedSeconds,
        ':id' => $sessionId,
    ]);

    if ($update->rowCount() < 1) {
        $freshSession = ve_video_fetch_playback_session_by_id($sessionId);

        return [
            'status' => 'ok',
            'message' => 'This playback session was already recorded as a full play.',
            'already_recorded' => true,
            'watched_seconds' => max($acceptedWatchedSeconds, (int) ($freshSession['full_play_watched_seconds'] ?? 0)),
            'required_seconds' => $requiredSeconds,
            'remaining_seconds' => 0,
            'full_play' => true,
        ];
    }

    return [
        'status' => 'ok',
        'message' => 'The full play was recorded successfully.',
        'already_recorded' => false,
        'watched_seconds' => $acceptedWatchedSeconds,
        'required_seconds' => $requiredSeconds,
        'remaining_seconds' => 0,
        'full_play' => true,
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

function ve_video_playback_full_api(string $publicId): void
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
        ve_video_validate_playback_request_state($session, 'full', $watchedSeconds, $token);
    } catch (RuntimeException $exception) {
        ve_json([
            'status' => 'fail',
            'message' => $exception->getMessage(),
        ], 403);
    }

    $session = ve_video_fetch_playback_session_by_id((int) ($session['id'] ?? 0)) ?? $session;
    $result = ve_video_record_full_play($video, $session, $watchedSeconds);
    $result['rotation'] = ve_video_rotation_payload($video, $session);
    $statusCode = match ($result['status'] ?? 'ok') {
        'pending' => 202,
        'fail' => 422,
        default => 200,
    };

    ve_json($result, $statusCode);
}

function ve_video_playback_session_api(string $publicId): void
{
    if (!ve_is_method('POST')) {
        ve_method_not_allowed(['POST']);
    }

    $video = ve_video_get_by_public_id($publicId);

    if (!is_array($video) || !ve_video_is_request_visible($video) || (string) ($video['status'] ?? '') !== VE_VIDEO_STATUS_READY) {
        ve_not_found();
    }

    $session = ve_video_issue_playback_session($video);

    ve_json([
        'status' => 'ok',
        'message' => 'A fresh playback session was issued.',
        'session' => ve_video_playback_session_payload($video, $session),
    ]);
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

    $isPrefetch = trim((string) ($_SERVER['HTTP_X_PLAYBACK_PREFETCH'] ?? '')) === '1';

    if (!$isPrefetch) {
        ve_video_record_segment_delivery($video, $session, (int) (filesize($path) ?: 0));
    }

    header('Content-Type: video/mp2t');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Playback-Prefetch: ' . ($isPrefetch ? '1' : '0'));
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

function ve_video_secure_player_script(
    array $session,
    string $publicId,
    int $minimumWatchSeconds,
    ?string $previewVttUrl = null,
    bool $autoSubtitleStart = false
): string
{
    $video = ve_video_get_by_public_id($publicId);
    $sessionPayload = is_array($video) ? ve_video_playback_session_payload($video, $session) : [
        'session_token' => (string) ($session['token'] ?? ''),
        'manifest_url' => ve_absolute_url((string) ($session['manifest_url'] ?? '')),
        'key_url' => ve_absolute_url('/stream/' . rawurlencode($publicId) . '/key?token=' . rawurlencode((string) ($session['token'] ?? ''))),
        'preview_url' => $previewVttUrl !== null ? ve_absolute_url($previewVttUrl) : '',
        'playback_token' => (string) ($session['playback_token'] ?? ''),
        'client_proof_key' => (string) ($session['client_proof_key'] ?? ''),
        'pulse_client_token' => (string) ($session['pulse_client_token'] ?? ''),
        'pulse_server_token' => (string) ($session['pulse_server_token'] ?? ''),
        'pulse_sequence' => max(0, (int) ($session['pulse_sequence'] ?? 0)),
        'session_ttl_seconds' => (int) ve_video_config()['session_ttl'],
        'issued_at_ms' => ve_timestamp() * 1000,
    ];
    if ($previewVttUrl !== null) {
        $sessionPayload['preview_url'] = ve_absolute_url($previewVttUrl);
    }
    $manifestUrl = json_encode((string) ($sessionPayload['manifest_url'] ?? ''), JSON_UNESCAPED_SLASHES);
    $keyUrl = json_encode((string) ($sessionPayload['key_url'] ?? ''), JSON_UNESCAPED_SLASHES);
    $hlsJsUrl = ve_h(ve_absolute_url('/assets/vendor/hls/hls.min.js'));
    $sessionToken = json_encode((string) ($sessionPayload['session_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $playbackToken = json_encode((string) ($sessionPayload['playback_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $homeUrl = json_encode(ve_absolute_url('/'), JSON_UNESCAPED_SLASHES);
    $previewUrl = json_encode((string) ($sessionPayload['preview_url'] ?? ''), JSON_UNESCAPED_SLASHES);
    $pulseViewUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/pulse'), JSON_UNESCAPED_SLASHES);
    $qualifyViewUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/qualify'), JSON_UNESCAPED_SLASHES);
    $fullPlayUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/full'), JSON_UNESCAPED_SLASHES);
    $sessionRefreshUrl = json_encode(ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/playback/session'), JSON_UNESCAPED_SLASHES);
    $minimumWatchSeconds = max(5, $minimumWatchSeconds);
    $pulseIntervalSeconds = ve_video_pulse_interval_seconds($minimumWatchSeconds);
    $requiredPulseCount = ve_video_required_pulse_count($minimumWatchSeconds);
    $segmentSeconds = max(1, (int) ve_video_config()['segment_seconds']);
    $pulseClientToken = json_encode((string) ($sessionPayload['pulse_client_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $pulseServerToken = json_encode((string) ($sessionPayload['pulse_server_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $pulseSequence = max(0, (int) ($sessionPayload['pulse_sequence'] ?? 0));
    $clientProofKey = json_encode((string) ($sessionPayload['client_proof_key'] ?? ''), JSON_UNESCAPED_SLASHES);
    $sessionTtlSeconds = max(60, (int) ($sessionPayload['session_ttl_seconds'] ?? (int) ve_video_config()['session_ttl']));
    $sessionIssuedAtMs = max(0, (int) ($sessionPayload['issued_at_ms'] ?? (ve_timestamp() * 1000)));
    $previewColumns = VE_VIDEO_PREVIEW_COLUMNS;
    $previewRows = VE_VIDEO_PREVIEW_ROWS;
    $autoSubtitleStart = $autoSubtitleStart ? 'true' : 'false';

    return <<<HTML
<script src="{$hlsJsUrl}"></script>
<script>
    (function () {
        var manifestUrl = {$manifestUrl};
        var keyUrl = {$keyUrl};
        var sessionToken = {$sessionToken};
        var playbackToken = {$playbackToken};
        var previewUrl = {$previewUrl};
        var autoSubtitleStart = {$autoSubtitleStart};
        var fallbackUrl = {$homeUrl};
        var pulseViewUrl = {$pulseViewUrl};
        var qualifyViewUrl = {$qualifyViewUrl};
        var fullPlayUrl = {$fullPlayUrl};
        var sessionRefreshUrl = {$sessionRefreshUrl};
        var minimumWatchSeconds = {$minimumWatchSeconds};
        var pulseIntervalSeconds = {$pulseIntervalSeconds};
        var requiredPulseCount = {$requiredPulseCount};
        var segmentSeconds = {$segmentSeconds};
        var sessionTtlSeconds = {$sessionTtlSeconds};
        var sessionIssuedAtMs = {$sessionIssuedAtMs};
        var playbackClientToken = {$pulseClientToken};
        var playbackServerToken = {$pulseServerToken};
        var playbackSequence = {$pulseSequence};
        var clientProofKey = {$clientProofKey};
        var previewColumns = {$previewColumns};
        var previewRows = {$previewRows};
        var video = document.getElementById('ve-secure-player');
        var stage = document.querySelector('.ve-stage');
        var state = document.getElementById('ve-player-state');
        var overlay = document.getElementById('ve-player-overlay');
        var overlayButton = document.getElementById('ve-player-overlay-button');
        var loadingSpinner = document.getElementById('ve-loading-spinner');
        var controlsBar = document.getElementById('ve-player-controls');
        var playToggleButton = document.getElementById('ve-play-toggle');
        var muteToggleButton = document.getElementById('ve-mute-toggle');
        var volumeRange = document.getElementById('ve-volume-range');
        var currentTimeDisplay = document.getElementById('ve-current-time-display');
        var durationDisplay = document.getElementById('ve-duration-display');
        var remainingTimeDisplay = document.getElementById('ve-remaining-time-display');
        var progressHolder = document.getElementById('ve-progress-holder');
        var loadProgress = document.getElementById('ve-load-progress');
        var playProgress = document.getElementById('ve-play-progress');
        var mouseDisplay = document.getElementById('ve-mouse-display');
        var timeTooltip = document.getElementById('ve-time-tooltip');
        var thumbnailHolder = document.getElementById('ve-thumbnail-holder');
        var thumbnailImage = document.getElementById('ve-thumbnail-image');
        var thumbnailText = document.getElementById('ve-thumbnail-text');
        var fullscreenToggleButton = document.getElementById('ve-fullscreen-toggle');
        var cinemaToggleButton = document.getElementById('ve-cinema-toggle');
        var watchTimeValue = document.getElementById('ve-watch-time-value');
        var positionValue = document.getElementById('ve-position-value');
        var skipBackButton = document.getElementById('ve-skip-back');
        var skipForwardButton = document.getElementById('ve-skip-forward');
        var subtitleButton = document.getElementById('ve-subtitle-button');
        var subtitlePanel = document.getElementById('ve-subtitle-panel');
        var subtitleList = document.getElementById('ve-subtitle-list');
        var subtitleStatus = document.getElementById('ve-subtitle-status');
        var subtitleUrlForm = document.getElementById('ve-subtitle-url-form');
        var subtitleUrlInput = document.getElementById('ve-subtitle-url-input');
        var subtitleFileInput = document.getElementById('ve-subtitle-file-input');
        var captionOverlay = document.getElementById('ve-caption-overlay');
        var homeLink = document.getElementById('ve-home-link');
        var speedButton = document.getElementById('ve-speed-button');
        var speedPanel = document.getElementById('ve-speed-panel');
        var speedPanelOpen = false;
        var cinemaModeActive = false;
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
        var fullPlaySent = false;
        var fullPlayInFlight = false;
        var fullPlayRetryTimer = null;
        var nativePlay = video && typeof video.play === 'function' ? video.play.bind(video) : null;
        var hls = null;
        var hlsMediaAttached = false;
        var hlsSourceLoaded = false;
        var hlsLoaderActive = false;
        var hlsFragRequestActive = false;
        var hlsBufferingPaused = false;
        var pendingSourceLoad = false;
        var playbackStartRequested = false;
        var awaitingInitialPlayback = false;
        var playbackBootstrapped = false;
        var streamActivated = false;
        var sourceFailure = false;
        var sessionRefreshPromise = null;
        var sessionRefreshTimer = null;
        var sessionRefreshCount = 0;
        var preloadManifestPromise = null;
        var preloadKeyPromise = null;
        var preloadStartupSegmentPromise = null;
        var preloadedManifestText = '';
        var preloadedKeyBytes = null;
        var preloadedStartupSegmentBytes = null;
        var preparedManifestPromise = null;
        var preparedManifestUrl = '';
        var preparedKeyBlobUrl = '';
        var preparedStartupSegmentBlobUrl = '';
        var hlsSourceLoadPromise = null;
        var preloadStartupSegmentUrl = '';
        var startupPartReady = false;
        var postLoadWarmupScheduled = false;
        var streamResourceVersion = 0;
        var pageLoaded = document.readyState === 'complete';
        var bufferLowWatermark = Math.max(1.5, segmentSeconds * 0.5);
        var bufferHighWatermark = Math.max(bufferLowWatermark + 0.75, segmentSeconds + 0.75);
        var sessionRefreshLeadMs = Math.max(30000, Math.min(120000, Math.floor(sessionTtlSeconds * 1000 * 0.2)));
        var preloadDebug = window.__VE_SECURE_PLAYER_DEBUG = window.__VE_SECURE_PLAYER_DEBUG || {};
        var subtitleEntries = [];
        var subtitleCounter = 0;
        var activeSubtitleId = null;
        var activeSubtitleTrack = null;
        var activeSubtitleCueHandler = null;
        var subtitlePanelOpen = false;
        var controlsBootstrapped = false;
        var controlsHideTimer = null;
        var previewMetadataPromise = null;
        var previewCues = [];

        preloadDebug.pageLoaded = pageLoaded;
        preloadDebug.manifestPreloaded = false;
        preloadDebug.startupSegmentPreloaded = false;
        preloadDebug.startupSegmentUrl = '';
        preloadDebug.keyPreloaded = false;
        preloadDebug.preparedManifestUrl = '';
        preloadDebug.preparedKeyUrl = '';
        preloadDebug.preparedStartupSegmentUrl = '';
        preloadDebug.sessionTtlSeconds = sessionTtlSeconds;
        preloadDebug.sessionIssuedAtMs = sessionIssuedAtMs;
        preloadDebug.sessionRefreshCount = 0;

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
                if (stage) {
                    stage.classList.add('vjs-has-started');
                }
                return;
            }

            if (mode === 'loading') {
                overlay.classList.add('is-loading');
            }

            if (stage) {
                stage.classList.remove('vjs-has-started');
            }
        }

        function setLoadingSpinner(active) {
            if (loadingSpinner) {
                loadingSpinner.hidden = !active;
            }

            if (stage) {
                stage.classList.toggle('vjs-waiting', Boolean(active));
            }
        }

        function setOverlayButtonLoading(active) {
            if (!overlayButton) {
                return;
            }

            overlayButton.classList.toggle('is-starting', Boolean(active));
            overlayButton.setAttribute('aria-busy', active ? 'true' : 'false');
        }

        function padTimePart(value) {
            var numericValue = Math.max(0, Math.floor(Number(value || 0)));
            return numericValue < 10 ? '0' + numericValue : String(numericValue);
        }

        function formatPlayerTime(seconds) {
            var totalSeconds = Math.max(0, Math.floor(Number(seconds || 0)));
            var hours = Math.floor(totalSeconds / 3600);
            var minutes = Math.floor((totalSeconds % 3600) / 60);
            var secondsPart = totalSeconds % 60;

            if (hours > 0) {
                return hours + ':' + padTimePart(minutes) + ':' + padTimePart(secondsPart);
            }

            return padTimePart(minutes) + ':' + padTimePart(secondsPart);
        }

        function formatControlTime(seconds) {
            var totalSeconds = Math.max(0, Math.floor(Number(seconds || 0)));
            var hours = Math.floor(totalSeconds / 3600);
            var minutes = Math.floor((totalSeconds % 3600) / 60);
            var secondsPart = totalSeconds % 60;

            if (hours > 0) {
                return hours + ':' + padTimePart(minutes) + ':' + padTimePart(secondsPart);
            }

            return String(minutes) + ':' + padTimePart(secondsPart);
        }

        function getBufferedEndSeconds() {
            if (!video || !video.buffered || video.buffered.length === 0) {
                return 0;
            }

            var currentTime = Number(video.currentTime || 0);
            var furthestEnd = 0;

            for (var index = 0; index < video.buffered.length; index += 1) {
                var start = Number(video.buffered.start(index) || 0);
                var end = Number(video.buffered.end(index) || 0);

                if (end > furthestEnd) {
                    furthestEnd = end;
                }

                if (currentTime + 0.1 >= start && currentTime <= end + 0.1) {
                    return end;
                }
            }

            return furthestEnd;
        }

        function updateBufferDisplay() {
            if (!loadProgress || !video) {
                return;
            }

            var duration = Number(video.duration || 0);
            var bufferedEnd = getBufferedEndSeconds();
            var bufferedPercent = duration > 0 && Number.isFinite(duration)
                ? Math.max(0, Math.min(100, (bufferedEnd / duration) * 100))
                : 0;

            loadProgress.style.width = bufferedPercent.toFixed(2) + '%';
        }

        function updatePlaybackProgress() {
            if (!playProgress || !progressHolder || !video) {
                return;
            }

            var duration = Number(video.duration || 0);
            var currentTime = Number(video.currentTime || 0);
            var percent = duration > 0 && Number.isFinite(duration)
                ? Math.max(0, Math.min(100, (currentTime / duration) * 100))
                : 0;
            var currentLabel = formatControlTime(currentTime);
            var durationLabel = duration > 0 && Number.isFinite(duration) ? formatControlTime(duration) : '--:--';

            playProgress.style.width = percent.toFixed(2) + '%';
            progressHolder.setAttribute('aria-valuenow', percent.toFixed(2));
            progressHolder.setAttribute('aria-valuetext', currentLabel + ' of ' + durationLabel);
        }

        function updatePlaybackUi() {
            if (!video) {
                return;
            }

            var paused = Boolean(video.paused);
            var ended = Boolean(video.ended);

            if (stage) {
                stage.classList.toggle('vjs-paused', paused);
                stage.classList.toggle('vjs-playing', !paused && !ended);
                stage.classList.toggle('vjs-ended', ended);
            }

            if (playToggleButton) {
                playToggleButton.classList.toggle('vjs-paused', paused);
                playToggleButton.classList.toggle('vjs-playing', !paused && !ended);
                playToggleButton.title = paused ? 'Play' : 'Pause';
                playToggleButton.setAttribute('aria-label', paused ? 'Play' : 'Pause');
            }
        }

        function updateVolumeUi() {
            var volumeLevel = video ? Math.max(0, Math.min(1, Number(video.volume || 0))) : 1;
            var muted = !video || Boolean(video.muted) || volumeLevel <= 0;
            var levelClass = muted ? 'vjs-vol-0' : (volumeLevel < 0.35 ? 'vjs-vol-1' : (volumeLevel < 0.7 ? 'vjs-vol-2' : 'vjs-vol-3'));

            if (muteToggleButton) {
                muteToggleButton.className = 'vjs-mute-control vjs-control vjs-button ' + levelClass;
                muteToggleButton.title = muted ? 'Unmute' : 'Mute';
                muteToggleButton.setAttribute('aria-label', muted ? 'Unmute' : 'Mute');
            }

            if (volumeRange) {
                var numericValue = muted ? 0 : Math.round(volumeLevel * 100);
                volumeRange.value = String(numericValue);
                volumeRange.setAttribute('aria-valuenow', String(numericValue));
                volumeRange.setAttribute('aria-valuetext', numericValue + '%');
            }
        }

        function updateFullscreenUi() {
            if (!fullscreenToggleButton) {
                return;
            }

            var fullscreenElement = document.fullscreenElement || document.webkitFullscreenElement || null;
            var active = Boolean(stage && fullscreenElement === stage);
            fullscreenToggleButton.classList.toggle('is-active', active);
            fullscreenToggleButton.title = active ? 'Exit Fullscreen' : 'Fullscreen';
            fullscreenToggleButton.setAttribute('aria-label', active ? 'Exit Fullscreen' : 'Fullscreen');
        }

        function postPlayerHostMessage(type, payload) {
            if (window.parent && window.parent !== window && typeof window.parent.postMessage === 'function') {
                window.parent.postMessage(Object.assign({ type: type }, payload || {}), '*');
            }
        }

        function updateCinemaUi() {
            if (!cinemaToggleButton) {
                return;
            }

            cinemaToggleButton.classList.toggle('is-active', cinemaModeActive);
            cinemaToggleButton.title = cinemaModeActive ? 'Exit Cinema Mode' : 'Cinema Mode';
            cinemaToggleButton.setAttribute('aria-label', cinemaModeActive ? 'Exit Cinema Mode' : 'Cinema Mode');
        }

        function setCinemaMode(active) {
            cinemaModeActive = Boolean(active);
            updateCinemaUi();
            postPlayerHostMessage('ve-player-cinema-toggle', { active: cinemaModeActive });
        }

        function closePlayerPanels() {
            if (speedPanelOpen) {
                closeSpeedPanel();
            }

            if (subtitlePanelOpen) {
                closeSubtitlePanel();
            }
        }

        function clearControlsHideTimer() {
            if (controlsHideTimer !== null) {
                window.clearTimeout(controlsHideTimer);
                controlsHideTimer = null;
            }
        }

        function setControlsActive(active) {
            if (!stage) {
                return;
            }

            stage.classList.toggle('vjs-user-active', Boolean(active));
            stage.classList.toggle('vjs-user-inactive', !active);
        }

        function showControlsTemporarily() {
            setControlsActive(true);
            clearControlsHideTimer();

            if (!video || video.paused || video.ended || !streamActivated) {
                return;
            }

            controlsHideTimer = window.setTimeout(function () {
                setControlsActive(false);
            }, 2200);
        }

        function parsePreviewTimecode(value) {
            var match = String(value || '').trim().match(/^(?:(\d+):)?(\d{2}):(\d{2})\.(\d{3})$/);

            if (!match) {
                return 0;
            }

            var hours = Number(match[1] || 0);
            var minutes = Number(match[2] || 0);
            var secondsPart = Number(match[3] || 0);
            var milliseconds = Number(match[4] || 0);

            return (hours * 3600) + (minutes * 60) + secondsPart + (milliseconds / 1000);
        }

        function parsePreviewMetadata(text) {
            var lines = String(text || '').split(/\\r\\n|\\r|\\n/);
            var cues = [];

            for (var index = 0; index < lines.length; index += 1) {
                var timingLine = String(lines[index] || '').trim();

                if (timingLine === '' || timingLine.indexOf('-->') === -1) {
                    continue;
                }

                var parts = timingLine.split('-->');
                var start = parsePreviewTimecode(parts[0] || '');
                var end = parsePreviewTimecode(parts[1] || '');
                var target = '';

                for (var nextIndex = index + 1; nextIndex < lines.length; nextIndex += 1) {
                    target = String(lines[nextIndex] || '').trim();

                    if (target !== '') {
                        break;
                    }
                }

                if (target === '') {
                    continue;
                }

                var hashParts = target.split('#xywh=');
                var imageUrl = '';

                try {
                    imageUrl = new URL(hashParts[0], previewUrl).toString();
                } catch (error) {
                    imageUrl = '';
                }

                var xywh = String(hashParts[1] || '').split(',').map(function (part) {
                    return Number(part || 0);
                });

                cues.push({
                    start: start,
                    end: end,
                    imageUrl: imageUrl,
                    x: xywh[0] || 0,
                    y: xywh[1] || 0,
                    width: xywh[2] || 0,
                    height: xywh[3] || 0,
                });
            }

            return cues;
        }

        function ensurePreviewMetadata() {
            if (!previewUrl) {
                return Promise.resolve([]);
            }

            if (previewMetadataPromise !== null) {
                return previewMetadataPromise;
            }

            previewMetadataPromise = fetch(previewUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Playback-Session': sessionToken,
                },
            }).then(function (response) {
                if (!response || !response.ok) {
                    return '';
                }

                return response.text();
            }).then(function (payload) {
                previewCues = parsePreviewMetadata(payload);
                return previewCues;
            }).catch(function () {
                previewCues = [];
                return previewCues;
            });

            return previewMetadataPromise;
        }

        function setProgressPreview(hoverSeconds, ratio) {
            if (!mouseDisplay || !timeTooltip || !progressHolder) {
                return;
            }

            var rect = progressHolder.getBoundingClientRect();
            var left = Math.max(0, Math.min(rect.width, rect.width * ratio));

            mouseDisplay.style.left = left + 'px';
            mouseDisplay.classList.add('is-visible');
            timeTooltip.textContent = formatControlTime(hoverSeconds);

            if (!thumbnailHolder || !thumbnailImage || !thumbnailText) {
                return;
            }

            ensurePreviewMetadata().then(function (cues) {
                var activeCue = null;

                for (var cueIndex = 0; cueIndex < cues.length; cueIndex += 1) {
                    if (hoverSeconds >= cues[cueIndex].start && hoverSeconds <= cues[cueIndex].end) {
                        activeCue = cues[cueIndex];
                        break;
                    }
                }

                if (!activeCue || !activeCue.imageUrl || !activeCue.width || !activeCue.height) {
                    thumbnailHolder.hidden = true;
                    if (timeTooltip) {
                        timeTooltip.style.display = '';
                    }
                    return;
                }

                var scale = Math.min(1, 200 / activeCue.width);
                var width = Math.round(activeCue.width * scale);
                var height = Math.round(activeCue.height * scale);

                thumbnailHolder.hidden = false;
                if (timeTooltip) {
                    timeTooltip.style.display = 'none';
                }
                thumbnailHolder.style.width = width + 'px';
                thumbnailHolder.style.height = height + 'px';
                thumbnailImage.style.backgroundImage = 'url("' + activeCue.imageUrl + '")';
                thumbnailImage.style.backgroundSize = (previewColumns * width) + 'px ' + (previewRows * height) + 'px';
                thumbnailImage.style.backgroundPosition = (-Math.round(activeCue.x * scale)) + 'px ' + (-Math.round(activeCue.y * scale)) + 'px';
                thumbnailText.textContent = formatPlayerTime(hoverSeconds);
            });
        }

        function clearProgressPreview() {
            if (mouseDisplay) {
                mouseDisplay.classList.remove('is-visible');
            }

            if (timeTooltip) {
                timeTooltip.style.display = '';
            }

            if (thumbnailHolder) {
                thumbnailHolder.hidden = true;
            }
        }

        function updateWatchTimeDisplay() {
            if (!watchTimeValue) {
                return;
            }

            watchTimeValue.textContent = formatPlayerTime(watchedSeconds);
        }

        function updatePositionDisplay() {
            if (!video) {
                return;
            }

            var duration = Number(video.duration || 0);
            var currentTime = Number(video.currentTime || 0);
            var durationLabel = duration > 0 && Number.isFinite(duration) ? formatPlayerTime(duration) : '--:--';
            var remainingSeconds = duration > 0 && Number.isFinite(duration) ? Math.max(0, duration - currentTime) : 0;

            if (positionValue) {
                positionValue.textContent = formatPlayerTime(currentTime) + ' / ' + durationLabel;
            }

            if (currentTimeDisplay) {
                currentTimeDisplay.textContent = formatControlTime(currentTime);
            }

            if (durationDisplay) {
                durationDisplay.textContent = duration > 0 && Number.isFinite(duration) ? formatControlTime(duration) : '--:--';
            }

            if (remainingTimeDisplay) {
                remainingTimeDisplay.textContent = duration > 0 && Number.isFinite(duration) ? formatControlTime(remainingSeconds) : '--:--';
            }

            updatePlaybackProgress();
            updateBufferDisplay();
        }

        function setSubtitleStatus(message, isError) {
            if (!subtitleStatus) {
                return;
            }

            subtitleStatus.textContent = message || '';
            subtitleStatus.hidden = !message;
            subtitleStatus.classList.toggle('is-error', Boolean(message && isError));
        }

        function clearCaptionOverlay() {
            if (!captionOverlay) {
                return;
            }

            captionOverlay.textContent = '';
            captionOverlay.classList.remove('is-visible');
        }

        function renderActiveSubtitleCue() {
            if (!captionOverlay || !activeSubtitleTrack) {
                clearCaptionOverlay();
                return;
            }

            var cues = activeSubtitleTrack.activeCues;
            var lines = [];

            if (cues && cues.length) {
                for (var cueIndex = 0; cueIndex < cues.length; cueIndex += 1) {
                    var cue = cues[cueIndex];
                    var cueText = cue && typeof cue.text === 'string' ? cue.text.trim() : '';

                    if (cueText) {
                        lines.push(cueText);
                    }
                }
            }

            if (lines.length === 0) {
                clearCaptionOverlay();
                return;
            }

            captionOverlay.textContent = lines.join('\\n');
            captionOverlay.classList.add('is-visible');
        }

        function unbindActiveSubtitleTrack() {
            if (activeSubtitleTrack && activeSubtitleCueHandler && typeof activeSubtitleTrack.removeEventListener === 'function') {
                activeSubtitleTrack.removeEventListener('cuechange', activeSubtitleCueHandler);
            }

            activeSubtitleTrack = null;
            activeSubtitleCueHandler = null;
            clearCaptionOverlay();
        }

        function bindActiveSubtitleTrack(track) {
            unbindActiveSubtitleTrack();

            if (!track) {
                return;
            }

            activeSubtitleTrack = track;
            activeSubtitleCueHandler = renderActiveSubtitleCue;

            if (typeof activeSubtitleTrack.addEventListener === 'function') {
                activeSubtitleTrack.addEventListener('cuechange', activeSubtitleCueHandler);
            }

            renderActiveSubtitleCue();
        }

        function normalizeSubtitleLanguage(value) {
            var fallback = 'en';
            var rawValue = String(value || '').trim().toLowerCase();
            var matched = rawValue.match(/[a-z]{2,3}(?:-[a-z]{2})?/i);
            return matched ? matched[0] : fallback;
        }

        function subtitleLabelFromSource(source, fallbackLabel) {
            var explicitLabel = String(fallbackLabel || '').trim();

            if (explicitLabel !== '') {
                return explicitLabel;
            }

            var rawSource = String(source || '').trim();

            if (rawSource === '') {
                return 'Subtitle';
            }

            try {
                var url = new URL(rawSource, window.location.href);
                var lastSegment = url.pathname.split('/').pop() || '';
                var cleaned = lastSegment.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
                return cleaned !== '' ? cleaned : 'Subtitle';
            } catch (error) {
                return 'Subtitle';
            }
        }

        function normalizeSubtitleContent(text) {
            var normalizedText = String(text || '').replace(/\\r\\n/g, '\\n').replace(/\\r/g, '\\n').trim();

            if (normalizedText === '') {
                throw new Error('Subtitle file is empty.');
            }

            if (/^WEBVTT/i.test(normalizedText)) {
                return normalizedText;
            }

            var lines = normalizedText.split('\\n');
            var output = [];

            for (var index = 0; index < lines.length; index += 1) {
                var line = lines[index];
                var trimmed = line.trim();
                var nextLine = index + 1 < lines.length ? lines[index + 1].trim() : '';

                if (/^\d+$/.test(trimmed) && nextLine.indexOf('-->') !== -1) {
                    continue;
                }

                if (trimmed.indexOf('-->') !== -1) {
                    output.push(line.replace(/,(\d{3})/g, '.$1'));
                    continue;
                }

                output.push(line);
            }

            return 'WEBVTT\\n\\n' + output.join('\\n');
        }

        function closeSpeedPanel() {
            if (speedPanel && speedButton) {
                speedPanelOpen = false;
                speedPanel.hidden = true;
                speedButton.setAttribute('aria-expanded', 'false');
            }
        }

        function openSubtitlePanel() {
            if (!subtitlePanel || !subtitleButton) {
                return;
            }

            closeSpeedPanel();
            toggleSubtitleUrlForm(false);
            subtitlePanel.hidden = false;
            subtitlePanel.classList.add('is-visible');
            subtitleButton.classList.add('is-active');
            subtitleButton.setAttribute('aria-expanded', 'true');
            subtitlePanelOpen = true;
        }

        function closeSubtitlePanel() {
            if (!subtitlePanel || !subtitleButton) {
                return;
            }

            subtitlePanel.classList.remove('is-visible');
            subtitlePanel.hidden = true;
            subtitleButton.classList.remove('is-active');
            subtitleButton.setAttribute('aria-expanded', 'false');
            subtitlePanelOpen = false;

            if (subtitleUrlForm) {
                subtitleUrlForm.hidden = true;
            }
        }

        function toggleSubtitleUrlForm(forceVisible) {
            if (!subtitleUrlForm) {
                return;
            }

            var shouldShow = typeof forceVisible === 'boolean' ? forceVisible : subtitleUrlForm.hidden;
            subtitleUrlForm.hidden = !shouldShow;

            if (shouldShow && subtitleUrlInput) {
                subtitleUrlInput.focus();
                subtitleUrlInput.select();
            }
        }

        function renderSubtitleMenu() {
            if (!subtitleList) {
                return;
            }

            subtitleList.innerHTML = '';

            function appendOption(label, isActive, handler, className) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 've-subtitle-option'
                    + (isActive ? ' is-active' : '')
                    + (className ? ' ' + className : '');
                button.textContent = label;
                button.addEventListener('click', handler);
                subtitleList.appendChild(button);
            }

            appendOption('Captions Off', activeSubtitleId === null, function () {
                toggleSubtitleUrlForm(false);
                selectSubtitle(null);
            });

            subtitleEntries.forEach(function (entry) {
                appendOption(entry.label, activeSubtitleId === entry.id, function () {
                    toggleSubtitleUrlForm(false);
                    selectSubtitle(entry.id);
                });
            });

            appendOption('Upload From PC', false, function () {
                toggleSubtitleUrlForm(false);
                if (subtitleFileInput) {
                    subtitleFileInput.click();
                }
            }, 'is-secondary');

            appendOption('Load From URL', false, function () {
                toggleSubtitleUrlForm(true);
            }, 'is-secondary');
        }

        function updateSubtitleButtonState() {
            if (!subtitleButton) {
                return;
            }

            subtitleButton.classList.toggle('has-subtitle', activeSubtitleId !== null);
        }

        function removeSubtitleEntry(entry) {
            if (!entry) {
                return;
            }

            if (entry.objectUrl && window.URL && typeof window.URL.revokeObjectURL === 'function') {
                window.URL.revokeObjectURL(entry.objectUrl);
            }

            if (entry.element && entry.element.parentNode) {
                entry.element.parentNode.removeChild(entry.element);
            }

            subtitleEntries = subtitleEntries.filter(function (candidate) {
                return candidate.id !== entry.id;
            });
        }

        function selectSubtitle(id) {
            activeSubtitleId = id === null ? null : id;

            subtitleEntries.forEach(function (entry) {
                if (entry.track) {
                    entry.track.mode = entry.id === activeSubtitleId ? 'hidden' : 'disabled';
                }
            });

            if (activeSubtitleId === null) {
                unbindActiveSubtitleTrack();
                updateSubtitleButtonState();
                renderSubtitleMenu();
                return;
            }

            var nextEntry = null;

            for (var index = 0; index < subtitleEntries.length; index += 1) {
                if (subtitleEntries[index].id === activeSubtitleId) {
                    nextEntry = subtitleEntries[index];
                    break;
                }
            }

            if (!nextEntry || !nextEntry.track) {
                activeSubtitleId = null;
                unbindActiveSubtitleTrack();
                updateSubtitleButtonState();
                renderSubtitleMenu();
                return;
            }

            nextEntry.track.mode = 'hidden';
            bindActiveSubtitleTrack(nextEntry.track);
            updateSubtitleButtonState();
            renderSubtitleMenu();
        }

        function registerSubtitleTrack(sourceUrl, label, language, activateByDefault, objectUrl) {
            if (!video || !sourceUrl) {
                return Promise.reject(new Error('Subtitle source is missing.'));
            }

            var trackElement = document.createElement('track');
            var entry = {
                id: 'subtitle-' + String(subtitleCounter += 1),
                label: subtitleLabelFromSource(sourceUrl, label),
                language: normalizeSubtitleLanguage(language || label || sourceUrl),
                element: trackElement,
                track: null,
                objectUrl: objectUrl || '',
            };

            trackElement.kind = 'subtitles';
            trackElement.label = entry.label;
            trackElement.srclang = entry.language;
            trackElement.src = sourceUrl;
            trackElement.default = false;

            return new Promise(function (resolve, reject) {
                var settled = false;

                function cleanup() {
                    trackElement.removeEventListener('load', handleLoad);
                    trackElement.removeEventListener('error', handleError);
                }

                function handleLoad() {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    cleanup();
                    entry.track = trackElement.track || null;

                    if (entry.track) {
                        entry.track.mode = 'disabled';
                    }

                    subtitleEntries.push(entry);
                    renderSubtitleMenu();

                    if (activateByDefault || (autoSubtitleStart && activeSubtitleId === null)) {
                        selectSubtitle(entry.id);
                    } else {
                        updateSubtitleButtonState();
                    }

                    setSubtitleStatus('Loaded ' + entry.label + '.', false);
                    resolve(entry);
                }

                function handleError() {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    cleanup();

                    if (trackElement.parentNode) {
                        trackElement.parentNode.removeChild(trackElement);
                    }

                    if (objectUrl && window.URL && typeof window.URL.revokeObjectURL === 'function') {
                        window.URL.revokeObjectURL(objectUrl);
                    }

                    reject(new Error('Subtitle track could not be loaded.'));
                }

                trackElement.addEventListener('load', handleLoad);
                trackElement.addEventListener('error', handleError);
                video.appendChild(trackElement);

                window.setTimeout(function () {
                    if (!settled && trackElement.track && trackElement.track.cues !== null) {
                        handleLoad();
                    }
                }, 350);
            });
        }

        function addSubtitleFromText(text, label, language, activateByDefault) {
            var subtitleBlob = new Blob([normalizeSubtitleContent(text)], { type: 'text/vtt' });
            var objectUrl = window.URL && typeof window.URL.createObjectURL === 'function'
                ? window.URL.createObjectURL(subtitleBlob)
                : '';

            if (!objectUrl) {
                return Promise.reject(new Error('This browser cannot create a local subtitle track.'));
            }

            return registerSubtitleTrack(objectUrl, label, language, activateByDefault, objectUrl);
        }

        function loadSubtitleFromUrl(url, label, language, activateByDefault) {
            var targetUrl = String(url || '').trim();

            if (targetUrl === '') {
                return Promise.reject(new Error('Subtitle URL is required.'));
            }

            setSubtitleStatus('Loading subtitles...', false);

            return fetch(targetUrl, {
                method: 'GET',
                credentials: 'omit',
            }).then(function (response) {
                if (!response || !response.ok) {
                    throw new Error('Subtitle URL could not be fetched.');
                }

                return response.text();
            }).then(function (text) {
                return addSubtitleFromText(text, label || subtitleLabelFromSource(targetUrl), language, activateByDefault);
            });
        }

        function loadSubtitleManifest(url) {
            var manifestUrl = String(url || '').trim();

            if (manifestUrl === '') {
                return Promise.resolve([]);
            }

            return fetch(manifestUrl, {
                method: 'GET',
                credentials: 'omit',
            }).then(function (response) {
                if (!response || !response.ok) {
                    throw new Error('Subtitle manifest could not be fetched.');
                }

                return response.json();
            }).then(function (payload) {
                var items = [];

                if (Array.isArray(payload)) {
                    items = payload;
                } else if (payload && Array.isArray(payload.tracks)) {
                    items = payload.tracks;
                }

                return items.reduce(function (sequence, item, itemIndex) {
                    return sequence.then(function () {
                        if (!item || typeof item !== 'object') {
                            return null;
                        }

                        var itemUrl = String(item.file || item.src || item.url || '').trim();

                        if (itemUrl === '') {
                            return null;
                        }

                        return loadSubtitleFromUrl(
                            itemUrl,
                            String(item.label || item.title || item.name || ('Subtitle ' + String(itemIndex + 1))),
                            String(item.lang || item.language || ''),
                            autoSubtitleStart && activeSubtitleId === null && itemIndex === 0
                        ).catch(function () {
                            return null;
                        });
                    });
                }, Promise.resolve()).then(function () {
                    return items;
                });
            });
        }

        function loadInitialSubtitles() {
            var params = new URLSearchParams(window.location.search || '');
            var specs = [];

            params.forEach(function (value, key) {
                var match = String(key).match(/^c(\d+)_file$/i);

                if (!match || !value) {
                    return;
                }

                var slot = match[1];
                specs.push({
                    index: Number(slot),
                    url: String(value),
                    label: String(params.get('c' + slot + '_label') || ('Subtitle ' + slot)),
                    language: String(params.get('c' + slot + '_lang') || ''),
                });
            });

            specs.sort(function (left, right) {
                return left.index - right.index;
            });

            var sequence = Promise.resolve();

            specs.forEach(function (spec, index) {
                sequence = sequence.then(function () {
                    return loadSubtitleFromUrl(
                        spec.url,
                        spec.label,
                        spec.language,
                        autoSubtitleStart && activeSubtitleId === null && index === 0
                    ).catch(function () {
                        return null;
                    });
                });
            });

            var manifestUrl = params.get('subtitle_json');

            if (manifestUrl) {
                sequence = sequence.then(function () {
                    return loadSubtitleManifest(manifestUrl).catch(function () {
                        setSubtitleStatus('Subtitle manifest could not be loaded.', true);
                        return null;
                    });
                });
            }

            return sequence.then(function () {
                renderSubtitleMenu();
                updateSubtitleButtonState();
            });
        }

        function bindSubtitleControls() {
            renderSubtitleMenu();
            updateSubtitleButtonState();

            if (subtitleButton) {
                subtitleButton.addEventListener('click', function () {
                    showControlsTemporarily();

                    if (subtitlePanelOpen) {
                        closeSubtitlePanel();
                        return;
                    }

                    openSubtitlePanel();
                });
            }

            if (subtitleUrlForm) {
                subtitleUrlForm.addEventListener('submit', function (event) {
                    event.preventDefault();

                    if (!subtitleUrlInput) {
                        return;
                    }

                    var remoteUrl = subtitleUrlInput.value.trim();

                    if (remoteUrl === '') {
                        setSubtitleStatus('Subtitle URL is required.', true);
                        return;
                    }

                    loadSubtitleFromUrl(remoteUrl, '', '', true).then(function () {
                        subtitleUrlInput.value = '';
                        subtitleUrlForm.hidden = true;
                    }).catch(function (error) {
                        setSubtitleStatus(error && error.message ? error.message : 'Subtitle URL could not be loaded.', true);
                    });
                });
            }

            if (subtitleFileInput) {
                subtitleFileInput.addEventListener('change', function () {
                    var file = subtitleFileInput.files && subtitleFileInput.files[0] ? subtitleFileInput.files[0] : null;

                    if (!file) {
                        return;
                    }

                    var reader = new FileReader();

                    reader.onload = function () {
                        addSubtitleFromText(
                            String(reader.result || ''),
                            file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' '),
                            '',
                            true
                        ).catch(function (error) {
                            setSubtitleStatus(error && error.message ? error.message : 'Subtitle file could not be read.', true);
                        });
                    };

                    reader.onerror = function () {
                        setSubtitleStatus('Subtitle file could not be read.', true);
                    };

                    reader.readAsText(file);
                    subtitleFileInput.value = '';
                });
            }

            document.addEventListener('pointerdown', function (event) {
                if (!subtitlePanelOpen || !subtitlePanel || !subtitleButton) {
                    return;
                }

                if (subtitlePanel.contains(event.target) || subtitleButton.contains(event.target)) {
                    return;
                }

                closeSubtitlePanel();
            }, true);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && cinemaModeActive) {
                    setCinemaMode(false);
                }

                if (event.key === 'Escape' && subtitlePanelOpen) {
                    closeSubtitlePanel();
                }
            });
        }

        function seekBy(deltaSeconds) {
            if (!video) {
                return;
            }

            if (!streamActivated) {
                ensureSourceLoaded();
            }

            var duration = Number(video.duration || 0);
            var nextTime = Number(video.currentTime || 0) + Number(deltaSeconds || 0);

            if (Number.isFinite(duration) && duration > 0) {
                nextTime = Math.min(duration, nextTime);
            }

            video.currentTime = Math.max(0, nextTime);
            updatePositionDisplay();

            if (hls) {
                syncManagedLoading();
            }
        }

        function bindSkipButtons() {
            if (skipBackButton) {
                skipBackButton.addEventListener('click', function () {
                    seekBy(-10);
                    showControlsTemporarily();
                });
            }

            if (skipForwardButton) {
                skipForwardButton.addEventListener('click', function () {
                    seekBy(10);
                    showControlsTemporarily();
                });
            }
        }

        function togglePlaybackFromSurface() {
            if (!video) {
                return;
            }

            if (video.paused || video.ended) {
                video.play();
                return;
            }

            video.pause();
        }

        function bootUi() {
            if (!video || controlsBootstrapped) {
                return;
            }

            controlsBootstrapped = true;
            setControlsActive(true);
            updatePlaybackUi();
            updateVolumeUi();
            updateFullscreenUi();
            updatePositionDisplay();

            if (playToggleButton) {
                playToggleButton.addEventListener('click', function () {
                    showControlsTemporarily();

                    if (video.paused || video.ended) {
                        video.play();
                        return;
                    }

                    video.pause();
                });
            }

            if (muteToggleButton) {
                muteToggleButton.addEventListener('click', function () {
                    if (!video) {
                        return;
                    }

                    video.muted = !video.muted;
                    updateVolumeUi();
                    showControlsTemporarily();
                });
            }

            if (volumeRange) {
                ['input', 'change'].forEach(function (eventName) {
                    volumeRange.addEventListener(eventName, function () {
                        if (!video) {
                            return;
                        }

                        var volumeValue = Math.max(0, Math.min(100, Number(volumeRange.value || 0)));
                        video.volume = volumeValue / 100;
                        video.muted = volumeValue <= 0;
                        updateVolumeUi();
                        showControlsTemporarily();
                    });
                });
            }

            if (progressHolder) {
                var handleProgressPointer = function (clientX) {
                    if (!video) {
                        return;
                    }

                    var duration = Number(video.duration || 0);

                    if (!(duration > 0) || !Number.isFinite(duration)) {
                        return;
                    }

                    var rect = progressHolder.getBoundingClientRect();

                    if (rect.width <= 0) {
                        return;
                    }

                    var ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    setProgressPreview(duration * ratio, ratio);
                };

                progressHolder.addEventListener('mousemove', function (event) {
                    handleProgressPointer(event.clientX);
                    showControlsTemporarily();
                });

                progressHolder.addEventListener('mouseleave', clearProgressPreview);

                progressHolder.addEventListener('click', function (event) {
                    if (!video) {
                        return;
                    }

                    if (!streamActivated) {
                        ensureSourceLoaded();
                    }

                    var duration = Number(video.duration || 0);

                    if (!(duration > 0) || !Number.isFinite(duration)) {
                        return;
                    }

                    var rect = progressHolder.getBoundingClientRect();
                    var ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
                    video.currentTime = duration * ratio;
                    updatePositionDisplay();
                    showControlsTemporarily();

                    if (hls) {
                        syncManagedLoading();
                    }
                });

                progressHolder.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowLeft') {
                        event.preventDefault();
                        seekBy(-5);
                        return;
                    }

                    if (event.key === 'ArrowRight') {
                        event.preventDefault();
                        seekBy(5);
                    }
                });
            }

            if (fullscreenToggleButton) {
                fullscreenToggleButton.addEventListener('click', function () {
                    var fullscreenElement = document.fullscreenElement || document.webkitFullscreenElement || null;

                    if (stage && fullscreenElement !== stage) {
                        if (typeof stage.requestFullscreen === 'function') {
                            stage.requestFullscreen().catch(function () {});
                        } else if (typeof stage.webkitRequestFullscreen === 'function') {
                            stage.webkitRequestFullscreen();
                        }
                    } else if (typeof document.exitFullscreen === 'function') {
                        document.exitFullscreen().catch(function () {});
                    } else if (typeof document.webkitExitFullscreen === 'function') {
                        document.webkitExitFullscreen();
                    }

                    showControlsTemporarily();
                });
            }

            if (cinemaToggleButton) {
                cinemaToggleButton.addEventListener('click', function () {
                    setCinemaMode(!cinemaModeActive);
                    showControlsTemporarily();
                });
            }

            if (homeLink && fallbackUrl) {
                homeLink.href = fallbackUrl;
            }

            if (speedButton && speedPanel) {
                speedButton.addEventListener('click', function () {
                    if (subtitlePanelOpen) {
                        closeSubtitlePanel();
                    }

                    speedPanelOpen = !speedPanelOpen;
                    speedPanel.hidden = !speedPanelOpen;
                    speedButton.setAttribute('aria-expanded', String(speedPanelOpen));
                    showControlsTemporarily();
                });

                speedPanel.querySelectorAll('.ve-speed-option').forEach(function (option) {
                    option.addEventListener('click', function () {
                        var rate = parseFloat(option.getAttribute('data-speed') || '1');

                        if (video) {
                            video.playbackRate = rate;
                        }

                        speedPanel.querySelectorAll('.ve-speed-option').forEach(function (opt) {
                            opt.classList.toggle('is-active', opt === option);
                        });

                        var label = rate === 1 ? '1x' : rate + 'x';
                        var placeholder = speedButton.querySelector('.vjs-icon-placeholder');

                        if (placeholder) {
                            placeholder.textContent = label;
                        }

                        speedPanelOpen = false;
                        speedPanel.hidden = true;
                        speedButton.setAttribute('aria-expanded', 'false');
                        showControlsTemporarily();
                    });
                });
            }

            ['mousemove', 'mouseenter', 'touchstart'].forEach(function (eventName) {
                stage.addEventListener(eventName, showControlsTemporarily, { passive: true });
            });

            stage.addEventListener('click', function (event) {
                if (!video || !stage || event.defaultPrevented) {
                    return;
                }

                var target = event.target;

                if (target && typeof target.closest === 'function') {
                    if (
                        target.closest('#ve-player-controls')
                        || target.closest('#ve-subtitle-panel')
                        || target.closest('#ve-subtitle-button')
                        || target.closest('#ve-player-overlay-button')
                        || target.closest('a, button, input, label, form')
                    ) {
                        return;
                    }
                }

                closePlayerPanels();

                showControlsTemporarily();
                togglePlaybackFromSurface();
            });

            document.addEventListener('pointerdown', function (event) {
                var target = event.target;

                if (!target || typeof target.closest !== 'function') {
                    return;
                }

                if (speedPanelOpen && !target.closest('#ve-speed-panel') && !target.closest('#ve-speed-button')) {
                    closeSpeedPanel();
                }

                if (subtitlePanelOpen && !target.closest('#ve-subtitle-panel') && !target.closest('#ve-subtitle-button')) {
                    closeSubtitlePanel();
                }
            }, true);

            window.addEventListener('message', function (event) {
                var data = event && event.data && typeof event.data === 'object' ? event.data : null;

                if (!data || typeof data.type !== 'string') {
                    return;
                }

                if (data.type === 've-close-player-panels') {
                    closePlayerPanels();
                    return;
                }

                if (data.type === 've-player-cinema-sync') {
                    cinemaModeActive = Boolean(data.active);
                    updateCinemaUi();
                }
            });

            window.addEventListener('blur', function () {
                closePlayerPanels();
            });

            stage.addEventListener('mouseleave', function () {
                clearControlsHideTimer();

                if (video && !video.paused && !video.ended) {
                    setControlsActive(false);
                }
            });

            video.addEventListener('volumechange', updateVolumeUi);
            document.addEventListener('fullscreenchange', updateFullscreenUi);
            document.addEventListener('webkitfullscreenchange', updateFullscreenUi);
            updateCinemaUi();
        }

        function warmPlaybackRuntime() {
            importProofKey().catch(function () {
                return null;
            });

            warmStreamMetadata();
            schedulePostLoadStreamWarmup();
            schedulePlaybackSessionRefresh();

            if (window.Hls && window.Hls.isSupported()) {
                ensureHlsPipeline();
            }
        }

        function updateSessionDebugState() {
            preloadDebug.sessionTtlSeconds = sessionTtlSeconds;
            preloadDebug.sessionIssuedAtMs = sessionIssuedAtMs;
            preloadDebug.sessionRefreshCount = sessionRefreshCount;
        }

        function clearPlaybackSessionRefresh() {
            if (sessionRefreshTimer !== null) {
                window.clearTimeout(sessionRefreshTimer);
                sessionRefreshTimer = null;
            }
        }

        function sessionNeedsRefresh(force) {
            if (force) {
                return true;
            }

            if (!sessionToken || !manifestUrl || !keyUrl) {
                return true;
            }

            var ttlMs = Math.max(60000, Number(sessionTtlSeconds || 0) * 1000);
            var expiresAtMs = Number(sessionIssuedAtMs || 0) + ttlMs;

            if (!Number.isFinite(expiresAtMs) || expiresAtMs <= 0) {
                return true;
            }

            return Date.now() + sessionRefreshLeadMs >= expiresAtMs;
        }

        function schedulePlaybackSessionRefresh() {
            clearPlaybackSessionRefresh();

            if (streamActivated || sourceFailure || !sessionRefreshUrl) {
                return;
            }

            var ttlMs = Math.max(60000, Number(sessionTtlSeconds || 0) * 1000);
            var expiresAtMs = Number(sessionIssuedAtMs || 0) + ttlMs;

            if (!Number.isFinite(expiresAtMs) || expiresAtMs <= 0) {
                sessionRefreshTimer = window.setTimeout(function () {
                    sessionRefreshTimer = null;
                    refreshPlaybackSession(true).catch(function () {
                        schedulePlaybackSessionRefresh();
                    });
                }, 10000);
                return;
            }

            var refreshDelay = Math.max(1000, expiresAtMs - Date.now() - sessionRefreshLeadMs);
            sessionRefreshTimer = window.setTimeout(function () {
                sessionRefreshTimer = null;
                refreshPlaybackSession(true).catch(function () {
                    sessionRefreshTimer = window.setTimeout(function () {
                        sessionRefreshTimer = null;
                        schedulePlaybackSessionRefresh();
                    }, 10000);
                });
            }, refreshDelay);
        }

        function preloadTextAsset(url) {
            if (!url) {
                return Promise.resolve(null);
            }

            return fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Playback-Session': sessionToken,
                },
            }).then(function (response) {
                if (!response || !response.ok) {
                    return null;
                }

                return response.text().catch(function () {
                    return null;
                });
            }).catch(function () {
                return null;
            });
        }

        function preloadBinaryAsset(url, extraHeaders) {
            if (!url) {
                return Promise.resolve(null);
            }

            var headers = {
                'X-Playback-Session': sessionToken,
            };

            if (extraHeaders && typeof extraHeaders === 'object') {
                Object.keys(extraHeaders).forEach(function (key) {
                    headers[key] = extraHeaders[key];
                });
            }

            return fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: headers,
            }).then(function (response) {
                if (!response || !response.ok) {
                    return null;
                }

                return response.arrayBuffer().catch(function () {
                    return null;
                });
            });
        }

        function revokePreparedObjectUrl(url) {
            if (!url || !window.URL || typeof window.URL.revokeObjectURL !== 'function') {
                return;
            }

            try {
                window.URL.revokeObjectURL(url);
            } catch (error) {}
        }

        function cleanupPreparedStreamSources() {
            revokePreparedObjectUrl(preparedManifestUrl);
            revokePreparedObjectUrl(preparedKeyBlobUrl);
            revokePreparedObjectUrl(preparedStartupSegmentBlobUrl);
            preparedManifestUrl = '';
            preparedKeyBlobUrl = '';
            preparedStartupSegmentBlobUrl = '';
            preloadDebug.preparedManifestUrl = '';
            preloadDebug.preparedKeyUrl = '';
            preloadDebug.preparedStartupSegmentUrl = '';
        }

        function resetPreparedStreamState() {
            cleanupPreparedStreamSources();
            preloadManifestPromise = null;
            preloadKeyPromise = null;
            preloadStartupSegmentPromise = null;
            preloadedManifestText = '';
            preloadedKeyBytes = null;
            preloadedStartupSegmentBytes = null;
            preparedManifestPromise = null;
            hlsSourceLoadPromise = null;
            preloadStartupSegmentUrl = '';
            startupPartReady = false;
            postLoadWarmupScheduled = false;
            previewMetadataPromise = null;
            previewCues = [];
            preloadDebug.manifestPreloaded = false;
            preloadDebug.startupSegmentPreloaded = false;
            preloadDebug.startupSegmentUrl = '';
            preloadDebug.keyPreloaded = false;

            if (!streamActivated) {
                hlsSourceLoaded = false;
                pendingSourceLoad = false;
            }
        }

        function applyPlaybackSessionPayload(payload) {
            if (!payload || typeof payload !== 'object') {
                return false;
            }

            var nextSessionToken = String(payload.session_token || '').trim();
            var nextManifestUrl = String(payload.manifest_url || '').trim();
            var nextKeyUrl = String(payload.key_url || '').trim();

            if (!nextSessionToken || !nextManifestUrl || !nextKeyUrl) {
                return false;
            }

            sessionToken = nextSessionToken;
            manifestUrl = nextManifestUrl;
            keyUrl = nextKeyUrl;
            previewUrl = String(payload.preview_url || '').trim();
            playbackToken = String(payload.playback_token || playbackToken || '').trim();
            playbackClientToken = String(payload.pulse_client_token || playbackClientToken || '').trim();
            playbackServerToken = String(payload.pulse_server_token || playbackServerToken || '').trim();
            clientProofKey = String(payload.client_proof_key || clientProofKey || '').trim();
            playbackSequence = Number.isFinite(Number(payload.pulse_sequence))
                ? Math.max(0, Number(payload.pulse_sequence))
                : playbackSequence;
            sessionTtlSeconds = Math.max(60, Number(payload.session_ttl_seconds || sessionTtlSeconds || 0));
            sessionIssuedAtMs = Number.isFinite(Number(payload.issued_at_ms))
                ? Math.max(0, Number(payload.issued_at_ms))
                : Date.now();
            proofKeyPromise = null;
            streamResourceVersion += 1;
            resetPreparedStreamState();
            updateSessionDebugState();
            schedulePlaybackSessionRefresh();

            if (pageLoaded && !streamActivated) {
                warmStreamMetadata();
                warmStartupSegmentWhenIdle();
            }

            return true;
        }

        function refreshPlaybackSession(force) {
            if (!sessionRefreshUrl) {
                return Promise.reject(new Error('Playback session refresh is unavailable.'));
            }

            if (!force && !sessionNeedsRefresh(false)) {
                return Promise.resolve(false);
            }

            if (sessionRefreshPromise !== null) {
                return sessionRefreshPromise;
            }

            sessionRefreshPromise = fetch(sessionRefreshUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (payload) {
                    if (!response || !response.ok || !payload || payload.status !== 'ok' || !payload.session) {
                        throw new Error('Playback session refresh failed.');
                    }

                    if (!applyPlaybackSessionPayload(payload.session)) {
                        throw new Error('Playback session refresh returned incomplete data.');
                    }

                    sessionRefreshCount += 1;
                    updateSessionDebugState();
                    return payload.session;
                });
            }).finally(function () {
                sessionRefreshPromise = null;
            });

            return sessionRefreshPromise;
        }

        preloadDebug.refreshSession = function (force) {
            return refreshPlaybackSession(Boolean(force));
        };
        preloadDebug.expireSessionForTest = function () {
            sessionIssuedAtMs = Date.now() - (Math.max(60, Number(sessionTtlSeconds || 0)) * 1000) - 1000;
            updateSessionDebugState();
        };
        updateSessionDebugState();

        function resolveStartupSegmentUrl(manifestText) {
            if (!manifestText) {
                return '';
            }

            var lines = String(manifestText).split(/\\r\\n|\\r|\\n/);

            for (var index = 0; index < lines.length; index += 1) {
                var line = String(lines[index] || '').trim();

                if (line === '' || line.charAt(0) === '#') {
                    continue;
                }

                try {
                    return new URL(line, manifestUrl).toString();
                } catch (error) {
                    return '';
                }
            }

            return '';
        }

        function preloadStartupSegment(url) {
            if (!url) {
                return Promise.resolve(null);
            }

            var resourceVersion = streamResourceVersion;

            return preloadBinaryAsset(url, {
                'X-Playback-Prefetch': '1',
            }).then(function (bytes) {
                if (!bytes || resourceVersion !== streamResourceVersion) {
                    return null;
                }

                preloadedStartupSegmentBytes = bytes;
                startupPartReady = true;
                preloadDebug.startupSegmentPreloaded = true;
                return bytes;
            }).catch(function () {
                return null;
            });
        }

        function warmStreamMetadata() {
            if (preloadManifestPromise === null) {
                var manifestResourceVersion = streamResourceVersion;
                preloadManifestPromise = preloadTextAsset(manifestUrl).then(function (manifestText) {
                    if (manifestResourceVersion !== streamResourceVersion) {
                        return null;
                    }

                    preloadedManifestText = typeof manifestText === 'string' ? manifestText : '';
                    preloadDebug.manifestPreloaded = Boolean(manifestText);
                    return manifestText;
                });
            }

            if (preloadKeyPromise === null) {
                var keyResourceVersion = streamResourceVersion;
                preloadKeyPromise = preloadBinaryAsset(keyUrl).then(function (bytes) {
                    if (keyResourceVersion !== streamResourceVersion) {
                        return null;
                    }

                    preloadedKeyBytes = bytes;
                    preloadDebug.keyPreloaded = Boolean(bytes && bytes.byteLength > 0);
                    return bytes;
                });
            }
        }

        function warmStartupSegmentWhenIdle() {
            if (
                postLoadWarmupScheduled
                || sourceFailure
                || streamActivated
                || playbackStartRequested
            ) {
                return;
            }

            postLoadWarmupScheduled = true;

            var runWarmup = function () {
                warmStreamMetadata();

                preloadManifestPromise.then(function (manifestText) {
                    if (
                        sourceFailure
                        || streamActivated
                        || playbackStartRequested
                        || preloadStartupSegmentPromise !== null
                    ) {
                        return false;
                    }

                    preloadStartupSegmentUrl = resolveStartupSegmentUrl(manifestText);
                    preloadDebug.startupSegmentUrl = preloadStartupSegmentUrl;

                    if (!preloadStartupSegmentUrl) {
                        return false;
                    }

                    preloadStartupSegmentPromise = preloadStartupSegment(preloadStartupSegmentUrl);
                    return preloadStartupSegmentPromise;
                }).catch(function () {
                    return null;
                });
            };

            window.setTimeout(runWarmup, 60);
        }

        function schedulePostLoadStreamWarmup() {
            if (pageLoaded || document.readyState === 'complete') {
                pageLoaded = true;
                preloadDebug.pageLoaded = true;
                warmStartupSegmentWhenIdle();
                return;
            }

            window.addEventListener('load', function () {
                pageLoaded = true;
                preloadDebug.pageLoaded = true;
                warmStartupSegmentWhenIdle();
            }, { once: true });
        }

        function buildPreparedManifestUrl() {
            if (preparedManifestPromise !== null) {
                return preparedManifestPromise;
            }

            var resourceVersion = streamResourceVersion;
            preparedManifestPromise = Promise.resolve().then(function () {
                warmStreamMetadata();
                return preloadManifestPromise;
            }).then(function (manifestText) {
                if (resourceVersion !== streamResourceVersion) {
                    return manifestUrl;
                }

                manifestText = typeof manifestText === 'string' ? manifestText : preloadedManifestText;

                if (!manifestText) {
                    return manifestUrl;
                }

                preloadedManifestText = manifestText;

                if (!preloadStartupSegmentUrl) {
                    preloadStartupSegmentUrl = resolveStartupSegmentUrl(manifestText);
                    preloadDebug.startupSegmentUrl = preloadStartupSegmentUrl;
                }

                if (preloadStartupSegmentUrl && preloadStartupSegmentPromise === null) {
                    preloadStartupSegmentPromise = preloadStartupSegment(preloadStartupSegmentUrl);
                }

                return Promise.all([
                    preloadKeyPromise ? preloadKeyPromise.catch(function () { return null; }) : Promise.resolve(null),
                    preloadStartupSegmentPromise ? preloadStartupSegmentPromise.catch(function () { return null; }) : Promise.resolve(null),
                ]).then(function (results) {
                    if (resourceVersion !== streamResourceVersion) {
                        return manifestUrl;
                    }

                    var keyBytes = results[0] || preloadedKeyBytes;
                    var startupBytes = results[1] || preloadedStartupSegmentBytes;
                    var lines = String(manifestText).split(/\\r\\n|\\r|\\n/);
                    var rewrittenLines = [];
                    var replacedStartupSegment = false;

                    cleanupPreparedStreamSources();

                    if (keyBytes && window.URL && typeof window.URL.createObjectURL === 'function') {
                        preparedKeyBlobUrl = window.URL.createObjectURL(new Blob([keyBytes], { type: 'application/octet-stream' }));
                    }

                    if (startupBytes && window.URL && typeof window.URL.createObjectURL === 'function') {
                        preparedStartupSegmentBlobUrl = window.URL.createObjectURL(new Blob([startupBytes], { type: 'video/mp2t' }));
                    }

                    if (!preparedKeyBlobUrl && !preparedStartupSegmentBlobUrl) {
                        return manifestUrl;
                    }

                    for (var index = 0; index < lines.length; index += 1) {
                        var originalLine = String(lines[index] || '');
                        var trimmedLine = originalLine.trim();

                        if (trimmedLine === '') {
                            rewrittenLines.push(originalLine);
                            continue;
                        }

                        if (preparedKeyBlobUrl && trimmedLine.indexOf('#EXT-X-KEY:') === 0) {
                            rewrittenLines.push(originalLine.replace(/URI="[^"]*"/, 'URI="' + preparedKeyBlobUrl + '"'));
                            continue;
                        }

                        if (trimmedLine.charAt(0) !== '#') {
                            var resolvedSegmentUrl = trimmedLine;

                            try {
                                resolvedSegmentUrl = new URL(trimmedLine, manifestUrl).toString();
                            } catch (error) {}

                            if (!replacedStartupSegment && preparedStartupSegmentBlobUrl) {
                                rewrittenLines.push(preparedStartupSegmentBlobUrl);
                                replacedStartupSegment = true;
                                continue;
                            }

                            rewrittenLines.push(resolvedSegmentUrl);
                            continue;
                        }

                        rewrittenLines.push(originalLine);
                    }

                    preparedManifestUrl = window.URL.createObjectURL(new Blob([rewrittenLines.join('\\n')], {
                        type: 'application/vnd.apple.mpegurl',
                    }));
                    preloadDebug.preparedManifestUrl = preparedManifestUrl;
                    preloadDebug.preparedKeyUrl = preparedKeyBlobUrl;
                    preloadDebug.preparedStartupSegmentUrl = preparedStartupSegmentBlobUrl;

                    return preparedManifestUrl;
                });
            }).catch(function () {
                return manifestUrl;
            });

            return preparedManifestPromise;
        }

        function loadPreparedStreamSource() {
            if (hlsSourceLoadPromise !== null) {
                return hlsSourceLoadPromise;
            }

            hlsSourceLoadPromise = buildPreparedManifestUrl().then(function (sourceUrl) {
                var finalSourceUrl = sourceUrl || manifestUrl;

                if (hls) {
                    hls.loadSource(finalSourceUrl);
                    return finalSourceUrl;
                }

                if (video && !video.currentSrc) {
                    video.src = finalSourceUrl;
                    video.load();
                }

                return finalSourceUrl;
            }).catch(function () {
                if (hls) {
                    hls.loadSource(manifestUrl);
                } else if (video && !video.currentSrc) {
                    video.src = manifestUrl;
                    video.load();
                }

                return manifestUrl;
            });

            return hlsSourceLoadPromise;
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

        function stopHlsLoading(forceAbort) {
            if (!hls || !hlsLoaderActive) {
                return;
            }

            hlsLoaderActive = false;

            try {
                hls.stopLoad();
            } catch (error) {}
        }

        function pauseHlsBuffering() {
            if (!hls || hlsBufferingPaused) {
                return;
            }

            if (typeof hls.pauseBuffering === 'function') {
                try {
                    hls.pauseBuffering();
                    hlsBufferingPaused = true;
                    return;
                } catch (error) {}
            }

            if (!hlsFragRequestActive) {
                stopHlsLoading(false);
            }
        }

        function resumeHlsBuffering() {
            if (!hls || !hlsBufferingPaused) {
                return;
            }

            if (typeof hls.resumeBuffering === 'function') {
                try {
                    hls.resumeBuffering();
                } catch (error) {}
            }

            hlsBufferingPaused = false;
        }

        function startHlsLoading() {
            if (!hls || !hlsSourceLoaded || hlsLoaderActive) {
                return;
            }

            resumeHlsBuffering();
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
                resumeHlsBuffering();
                stopHlsLoading(false);
                return;
            }

            if (video.paused && !awaitingInitialPlayback) {
                pauseHlsBuffering();
                return;
            }

            if (bufferedAheadSeconds() >= bufferHighWatermark) {
                pauseHlsBuffering();
                return;
            }

            resumeHlsBuffering();
            startHlsLoading();
        }

        function failSecurePlayback(message) {
            sourceFailure = true;
            awaitingInitialPlayback = false;
            setOverlayButtonLoading(false);
            setLoadingSpinner(false);
            stopHlsLoading(true);
            setOverlayMode('default');
            setState(message, true);
            updatePlaybackUi();
        }

        function ensureFreshPlaybackSession(force) {
            if (streamActivated) {
                return Promise.resolve(true);
            }

            return refreshPlaybackSession(Boolean(force)).then(function () {
                return true;
            });
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
                    loadPreparedStreamSource();
                }
            });

            hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
                hlsLoaderActive = false;
                hlsBufferingPaused = false;
                if (streamActivated || playbackStartRequested) {
                    startHlsLoading();
                }

                if (playbackStartRequested && video && video.paused && typeof nativePlay === 'function') {
                    nativePlay().catch(function () {});
                }

                if (video && !video.paused) {
                    syncManagedLoading();
                }
            });

            hls.on(window.Hls.Events.FRAG_LOADING, function () {
                hlsFragRequestActive = true;
            });

            hls.on(window.Hls.Events.FRAG_LOADED, function () {
                hlsFragRequestActive = false;
            });

            hls.on(window.Hls.Events.FRAG_BUFFERED, function () {
                hlsFragRequestActive = false;
                startupPartReady = true;
                setOverlayButtonLoading(false);

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
            clearPlaybackSessionRefresh();
            setOverlayMode('loading');
            setState('');

            if (ensureHlsPipeline()) {
                if (hlsMediaAttached) {
                    if (!hlsSourceLoaded) {
                        hlsSourceLoaded = true;
                        loadPreparedStreamSource();
                    }
                } else {
                    pendingSourceLoad = true;
                }

                return true;
            }

            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                if (!video.currentSrc) {
                    loadPreparedStreamSource();
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
            startupPartReady = false;
            setOverlayButtonLoading(true);
            setOverlayMode('loading');
            setState('');

            return ensureFreshPlaybackSession(false).catch(function () {
                failSecurePlayback('Playback session could not be refreshed automatically. Try again in a moment.');
                return false;
            }).then(function (ready) {
                if (!ready) {
                    return false;
                }

                if (!ensureSourceLoaded()) {
                    awaitingInitialPlayback = false;
                    setOverlayButtonLoading(false);
                    return false;
                }

                if (hls) {
                    syncManagedLoading();
                }

                if (video.readyState < 2) {
                    return true;
                }

                var playPromise = nativePlay();

                if (playPromise && typeof playPromise.catch === 'function') {
                    return playPromise.catch(function () {
                        return false;
                    });
                }

                return true;
            });
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

        function clearFullPlayRetry() {
            if (fullPlayRetryTimer !== null) {
                window.clearTimeout(fullPlayRetryTimer);
                fullPlayRetryTimer = null;
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

        function scheduleFullPlayRetry(seconds) {
            if (fullPlaySent) {
                return;
            }

            clearFullPlayRetry();
            fullPlayRetryTimer = window.setTimeout(function () {
                fullPlayRetryTimer = null;
                maybeReportFullPlay();
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

        function buildPlaybackProof(kind, requestWatchedSeconds, requestUrl) {
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
                String(Math.max(0, Math.floor(requestWatchedSeconds)))
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

        function buildPlaybackBody(requestWatchedSeconds) {
            var params = new URLSearchParams();
            params.set('session_token', sessionToken);
            params.set('playback_token', playbackToken);
            params.set('watched_seconds', String(Math.max(0, Math.floor(requestWatchedSeconds))));
            return params.toString();
        }

        function requestWatchedSecondsFor(kind) {
            var requestWatchedSeconds = watchedSeconds;

            if (kind === 'full' && video) {
                requestWatchedSeconds = Math.max(requestWatchedSeconds, Number(video.currentTime || 0));
            }

            return Math.max(0, requestWatchedSeconds);
        }

        function playbackEndThresholdSeconds() {
            if (!video) {
                return Number.POSITIVE_INFINITY;
            }

            var duration = Number(video.duration || 0);

            if (!Number.isFinite(duration) || duration <= 0) {
                return Number.POSITIVE_INFINITY;
            }

            return Math.max(1, Math.ceil(duration - 0.75));
        }

        function hasReachedPlaybackEnd() {
            if (!video) {
                return false;
            }

            if (video.ended) {
                return true;
            }

            var duration = Number(video.duration || 0);
            var currentTime = Number(video.currentTime || 0);

            if (!Number.isFinite(duration) || duration <= 0) {
                return false;
            }

            return currentTime >= Math.max(0, duration - 0.75);
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
            var requestWatchedSeconds = requestWatchedSecondsFor(kind);

            return buildPlaybackProof(kind, requestWatchedSeconds, url).then(function (proof) {
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
                    body: buildPlaybackBody(requestWatchedSeconds)
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

                maybeReportFullPlay();
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
                    maybeReportFullPlay();
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

        function submitFullPlay() {
            if (
                fullPlaySent
                || fullPlayInFlight
                || fullPlayRetryTimer !== null
                || pulseInFlight
                || qualificationInFlight
                || !fullPlayUrl
                || !canSignPlaybackRequests()
                || !hasReachedPlaybackEnd()
            ) {
                return;
            }

            fullPlayInFlight = true;

            postPlaybackRequest(fullPlayUrl, 'full').then(function (result) {
                fullPlayInFlight = false;

                if (!result) {
                    scheduleFullPlayRetry(2);
                    return;
                }

                if (!result.payload || typeof result.payload.status !== 'string') {
                    if (Number(result.statusCode || 0) === 200) {
                        fullPlaySent = true;
                        clearFullPlayRetry();
                        return;
                    }

                    scheduleFullPlayRetry(2);
                    return;
                }

                applyRotationPayload(result.payload.rotation || null);

                if (result.payload.status === 'ok') {
                    fullPlaySent = true;
                    clearFullPlayRetry();
                    return;
                }

                if (result.payload.status === 'pending') {
                    scheduleFullPlayRetry(Number(result.payload.remaining_seconds || 1));
                    return;
                }

                scheduleFullPlayRetry(2);
            }).catch(function () {
                fullPlayInFlight = false;
                scheduleFullPlayRetry(2);
            });
        }

        function maybeReportFullPlay() {
            if (fullPlaySent || !hasReachedPlaybackEnd()) {
                return;
            }

            if (!qualificationSent && watchedSeconds >= minimumWatchSeconds) {
                maybeQualifyView();

                if (qualificationInFlight || qualificationRetryTimer !== null) {
                    scheduleFullPlayRetry(1);
                    return;
                }
            }

            submitFullPlay();
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
                updateWatchTimeDisplay();
                if (watchedSeconds + 0.05 >= nextPulseTargetSeconds) {
                    submitPlaybackPulse(false);
                }
                maybeQualifyView();
                maybeReportFullPlay();
            }

            lastWatchSampleAt = sampleAt;
            lastWatchCurrentTime = currentTime;
            updatePositionDisplay();
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
            maybeReportFullPlay();
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
                updatePositionDisplay();

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
                clearPlaybackSessionRefresh();
                cleanupPreparedStreamSources();
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

        updateWatchTimeDisplay();
        updatePositionDisplay();
        bindSkipButtons();
        bindSubtitleControls();
        loadInitialSubtitles();
        bindWatchTracking();
        bootUi();
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                window.setTimeout(warmPlaybackRuntime, 0);
            });
        } else {
            window.setTimeout(warmPlaybackRuntime, 32);
        }
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

            updatePlaybackUi();
            showControlsTemporarily();

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('playing', function () {
            awaitingInitialPlayback = false;
            bootUi();
            setOverlayMode('hidden');
            setLoadingSpinner(false);
            setState('');
            updatePlaybackUi();
            showControlsTemporarily();

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

            updateBufferDisplay();

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('timeupdate', function () {
            updatePositionDisplay();
            updatePlaybackUi();

            if (hls) {
                syncManagedLoading();
            }
        });

        video.addEventListener('waiting', function () {
            if (!streamActivated) {
                return;
            }

            setState('');
            if (!overlay || overlay.classList.contains('is-hidden')) {
                setLoadingSpinner(true);
            }
            showControlsTemporarily();

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
                updatePositionDisplay();

                if (!streamActivated) {
                    return;
                }

                 if (!startupPartReady && video.readyState >= 2) {
                    startupPartReady = true;
                    setOverlayButtonLoading(false);
                }

                setLoadingSpinner(false);

                if (!video.paused) {
                    setState('');
                }

                updatePositionDisplay();
                updatePlaybackUi();

                if (playbackStartRequested && video.paused && typeof nativePlay === 'function') {
                    nativePlay().catch(function () {});
                }
            });
        });

        ['pause', 'ended'].forEach(function (eventName) {
            video.addEventListener(eventName, function () {
                awaitingInitialPlayback = false;
                updatePositionDisplay();
                updatePlaybackUi();
                setLoadingSpinner(false);
                clearControlsHideTimer();
                setControlsActive(true);

                if (eventName === 'ended') {
                    setOverlayButtonLoading(false);
                }

                if (hls && eventName === 'pause') {
                    syncManagedLoading();
                    return;
                }

                if (hls) {
                    stopHlsLoading(false);
                }
            });
        });

        video.addEventListener('ended', function () {
            maybeQualifyView();
            maybeReportFullPlay();
        });

        video.addEventListener('error', function () {
            setLoadingSpinner(false);
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
    $embedQueryString = http_build_query($_GET);
    $localEmbedUrl = ve_h(ve_absolute_url('/e/' . rawurlencode($publicId) . ($embedQueryString !== '' ? '?' . $embedQueryString : '')));
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
            margin-top: 0;
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
        .player-wrap {
            position: relative;
            z-index: 3;
            transition: box-shadow .2s ease, transform .2s ease;
        }
        body.ve-cinema-mode {
            background: #050505;
        }
        body.ve-cinema-mode::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.82);
            pointer-events: none;
            z-index: 1;
        }
        body.ve-cinema-mode .player-wrap {
            position: fixed;
            inset: 16px;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
            width: auto;
            max-width: none;
            margin: 0;
            padding: 0;
            box-shadow: none;
            transform: none;
        }
        body.ve-cinema-mode #os_player {
            width: min(calc(100vw - 32px), calc((100vh - 32px) * 16 / 9));
            max-width: none;
            margin: 0 auto;
        }
        body.ve-cinema-mode .container:not(.player-wrap) {
            position: relative;
            z-index: 0;
            opacity: 0.2;
            transition: opacity .2s ease;
        }
        @media (max-width: 767.98px) {
            body.ve-cinema-mode .player-wrap {
                inset: 8px;
            }
            body.ve-cinema-mode #os_player {
                width: min(calc(100vw - 16px), calc((100vh - 16px) * 16 / 9));
            }
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
            .player-wrap,
            .player-wrap.container,
            .container,
            .container.my-3,
            .container.mt-4 {
                max-width: 100%;
            }
            .player-wrap.container,
            .container.my-3,
            .container.mt-4,
            .container {
                padding-left: 12px;
                padding-right: 12px;
            }
            .title-wrap .d-flex {
                gap: 10px;
            }
            .title-wrap .info,
            .title-wrap .text-right {
                width: 100%;
            }
            .nav-pills#pills-tab {
                display: flex;
                flex-wrap: nowrap;
                align-items: stretch;
                margin-left: -4px;
                margin-right: -4px;
            }
            .nav-pills#pills-tab .nav-item {
                flex: 1 1 0;
                min-width: 0;
                margin-right: 0;
                padding-left: 4px;
                padding-right: 4px;
            }
            .nav-pills#pills-tab .nav-link {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                padding: 8px 6px;
                font-size: 12px;
                line-height: 1.2;
                text-align: center;
            }
            .v-owner {
                position: static;
                display: block;
                margin-bottom: 8px;
                text-align: right;
            }
            .buttonInside {
                margin-bottom: 8px;
            }
            .copy-in {
                position: absolute;
                right: 5px;
                top: 5px;
                width: auto;
                margin-top: 0;
            }
            .export-txt {
                height: 42px !important;
                min-height: 42px;
                padding-right: 68px;
                overflow: hidden;
                white-space: nowrap;
            }
            #code_txt_ec {
                height: 42px !important;
                min-height: 42px;
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
            var cinemaModeActive = false;

            function getPlayerFrameWindow() {
                var host = document.getElementById('os_player');
                var frame = host ? host.querySelector('iframe') : null;

                if (!frame || !frame.contentWindow) {
                    return null;
                }

                return frame.contentWindow;
            }

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

            function setDownloadCountdownState(remaining) {
                if (!downloadButton) {
                    return;
                }

                downloadButton.classList.add('loading', 'disabled');
                downloadButton.classList.remove('download-ready');
                downloadButton.setAttribute('data-ready', '0');
                downloadButton.setAttribute('href', '#download_now');
                downloadButton.textContent = 'Wait ' + remaining + 's';
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

                setDownloadCountdownState(remaining);
                updateDownloadStatus('Please wait ' + remaining + ' seconds before the protected download link is issued.');

                countdownTimer = window.setInterval(function () {
                    remaining -= 1;

                    if (remaining > 0) {
                        setDownloadCountdownState(remaining);
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

                    if (downloadWaitSeconds > 0) {
                        setDownloadCountdownState(downloadWaitSeconds);
                        updateDownloadStatus('Please wait ' + downloadWaitSeconds + ' seconds before the protected download link is issued.');
                    } else {
                        setDownloadBusyState();
                        updateDownloadStatus('Issuing protected premium download link...');
                    }

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

            document.addEventListener('pointerdown', function (event) {
                var host = document.getElementById('os_player');
                var frameWindow = getPlayerFrameWindow();

                if (!host || !frameWindow || host.contains(event.target)) {
                    return;
                }

                frameWindow.postMessage({ type: 've-close-player-panels' }, '*');
            }, true);

            window.addEventListener('message', function (event) {
                var data = event && event.data && typeof event.data === 'object' ? event.data : null;

                if (!data || typeof data.type !== 'string') {
                    return;
                }

                if (data.type === 've-player-cinema-toggle') {
                    cinemaModeActive = Boolean(data.active);
                    document.body.classList.toggle('ve-cinema-mode', cinemaModeActive);

                    var frameWindow = getPlayerFrameWindow();

                    if (frameWindow) {
                        frameWindow.postMessage({ type: 've-player-cinema-sync', active: cinemaModeActive }, '*');
                    }
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' || !cinemaModeActive) {
                    return;
                }

                cinemaModeActive = false;
                document.body.classList.remove('ve-cinema-mode');

                var frameWindow = getPlayerFrameWindow();

                if (frameWindow) {
                    frameWindow.postMessage({ type: 've-player-cinema-sync', active: false }, '*');
                }
            });

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
        $previewVttUrl,
        ((int) ($ownerSettings['auto_subtitle_start'] ?? 0)) === 1
    );
    $playerColour = strtolower(trim((string) ($ownerSettings['player_colour'] ?? 'e50914')));

    if (!preg_match('/^[a-f0-9]{6}$/', $playerColour)) {
        $playerColour = 'e50914';
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
    <style>
        :root {
            --ve-accent: #{$playerColour};
            --plyr-color-main: #{$playerColour};
        }
        html, body {
            margin: 0;
            background: #000;
            color: #fff;
            font-family: Arial, Helvetica, sans-serif;
        }
        .ve-embed-shell {
            background: #000;
        }
        .ve-stage {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #000;
            overflow: hidden;
            border: 0;
            border-radius: 0;
        }
        .ve-stage::before {
            content: none;
        }
        .ve-stage::after {
            content: none;
        }
        .ve-stage .plyr,
        .ve-stage video {
            width: 100%;
            height: 100%;
            display: block;
            background: #000;
        }
        .ve-stage .plyr {
            position: relative;
            z-index: 2;
        }
        .ve-stage .plyr--full-ui input[type=range] {
            color: var(--ve-accent);
        }
        .ve-stage .plyr__control--overlaid,
        .ve-stage .plyr__captions {
            display: none !important;
        }
        .ve-stage .plyr--video .plyr__controls {
            padding: 28px 14px 16px;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.34) 22%, rgba(0, 0, 0, 0.78) 66%, rgba(0, 0, 0, 0.96) 100%);
            border: 0;
            border-radius: 0;
            color: #fff;
            font-family: Arial, Helvetica, sans-serif;
        }
        .ve-stage .plyr--video .plyr__controls::before {
            content: none;
        }
        .ve-stage .plyr__controls .plyr__control,
        .ve-stage .plyr__controls .plyr__time,
        .ve-stage .plyr__controls .plyr__volume {
            min-height: 40px;
        }
        .ve-stage .plyr__controls .plyr__control {
            color: #fff;
            width: 42px;
            min-width: 42px;
            padding: 0;
            border-radius: 0;
            background: transparent;
            transition: opacity .16s ease;
        }
        .ve-stage .plyr__controls .plyr__control:hover,
        .ve-stage .plyr__controls .plyr__control:focus-visible,
        .ve-stage .plyr__controls .plyr__control[aria-expanded=true] {
            color: #fff;
            background: transparent;
            opacity: 0.82;
        }
        .ve-stage .plyr__controls .plyr__time {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            min-width: 74px;
            padding: 0 0 0 10px;
            background: transparent;
            border: 0;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0;
            text-shadow: 0 0 3px rgba(0, 0, 0, 0.85);
        }
        .ve-stage .plyr__progress {
            margin: 0 12px 0 8px;
        }
        .ve-stage .plyr__progress__container {
            margin: 0;
        }
        .ve-stage .plyr__progress__buffer {
            color: rgba(255, 255, 255, 0.22);
        }
        .ve-stage .plyr__progress input[type=range] {
            --range-track-height: 4px;
            --range-thumb-height: 12px;
            --range-thumb-active-shadow-width: 0;
        }
        .ve-stage .plyr--full-ui.plyr--video input[type=range]::-webkit-slider-runnable-track {
            background-color: rgba(255, 255, 255, 0.48);
        }
        .ve-stage .plyr--full-ui.plyr--video input[type=range]::-moz-range-track {
            background-color: rgba(255, 255, 255, 0.48);
        }
        .ve-stage .plyr--full-ui.plyr--video input[type=range]::-ms-track {
            background-color: rgba(255, 255, 255, 0.48);
        }
        .ve-stage .plyr--full-ui.plyr--video input[type=range]::-webkit-slider-thumb {
            background: #fff;
            box-shadow: none;
        }
        .ve-stage .plyr--full-ui.plyr--video input[type=range]::-moz-range-thumb {
            background: #fff;
            box-shadow: none;
        }
        .ve-stage .plyr__menu__container {
            background: rgba(16, 16, 16, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 0;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.36);
            color: #fff;
        }
        .ve-stage .plyr__menu__container::after {
            border-top-color: rgba(16, 16, 16, 0.96);
        }
        .ve-stage .plyr__menu__container .plyr__control {
            color: #f5f5f5;
            width: 100%;
        }
        .ve-stage .plyr__menu__container .plyr__control[role=menuitemradio][aria-checked=true]::before {
            background: var(--ve-accent);
        }
        .ve-stage .plyr__menu__container .plyr__control--back::before {
            background: rgba(255, 255, 255, 0.12);
            box-shadow: none;
        }
        .ve-player-overlay {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: transparent;
            transition: opacity .22s ease;
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
            object-fit: contain;
            object-position: center center;
            display: block;
        }
        .ve-player-overlay-button {
            position: absolute;
            top: 50%;
            left: 50%;
            z-index: 1;
            width: 120px;
            height: 120px;
            min-width: 120px;
            min-height: 120px;
            padding: 0;
            border: 0;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: #fff;
            box-shadow: none;
            transform: translate(-50%, -50%);
            transition: opacity .18s ease;
            cursor: pointer;
            font: inherit;
        }
        .ve-player-overlay-button:hover {
            background: transparent;
            opacity: 0.82;
        }
        .ve-player-overlay.is-loading .ve-player-overlay-button {
            opacity: 0.92;
        }
        .ve-player-overlay-button.is-starting {
            background: transparent;
        }
        .ve-player-overlay-button.is-starting::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 42px;
            height: 42px;
            margin-top: -21px;
            margin-left: -21px;
            border-radius: 999px;
            border: 3px solid rgba(255, 255, 255, 0.18);
            border-top-color: rgba(255, 255, 255, 0.92);
            animation: ve-player-button-spin .8s linear infinite;
        }
        .ve-player-overlay-glyph {
            position: relative;
            display: block;
            width: 120px;
            height: 120px;
            color: #fff;
        }
        .ve-player-overlay-glyph::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            margin-top: -30px;
            margin-left: -10px;
            border-top: 30px solid transparent;
            border-bottom: 30px solid transparent;
            border-left: 48px solid currentColor;
            filter: drop-shadow(0 0 10px rgba(0, 0, 0, 0.45));
        }
        .ve-player-overlay-label {
            display: none;
        }
        .ve-player-overlay-button.is-starting .ve-player-overlay-glyph {
            opacity: 0;
        }
        @keyframes ve-player-button-spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        .ve-logo-badge {
            display: none;
        }
        .ve-stage-hud {
            display: none;
        }
        .ve-stage-action {
            display: none;
        }
        .ve-subtitle-anchor {
            position: absolute;
            right: 16px;
            bottom: 64px;
            z-index: 6;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }
        .ve-subtitle-button {
            min-width: 54px;
            height: 46px;
            padding: 0 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.72);
            color: #fff;
            font: inherit;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, transform .16s ease, opacity .16s ease;
            opacity: 0;
            pointer-events: none;
        }
        .ve-subtitle-button.has-subtitle,
        .ve-subtitle-button:hover,
        .ve-subtitle-button:focus-visible,
        .ve-subtitle-button.is-active,
        .ve-subtitle-button.has-subtitle:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
            opacity: 1;
            pointer-events: auto;
        }
        .ve-subtitle-panel {
            width: min(240px, calc(100vw - 36px));
            padding: 12px;
            background: rgba(10, 12, 18, 0.94);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            backdrop-filter: blur(22px);
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.32);
        }
        .ve-subtitle-panel-header {
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.66);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .ve-subtitle-list {
            display: grid;
            gap: 6px;
        }
        .ve-subtitle-option {
            width: 100%;
            padding: 10px 12px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #f2f2f2;
            font: inherit;
            font-size: 0.95rem;
            text-align: left;
            cursor: pointer;
            transition: background .16s ease, color .16s ease;
        }
        .ve-subtitle-option:hover,
        .ve-subtitle-option:focus-visible {
            background: rgba(255, 255, 255, 0.05);
        }
        .ve-subtitle-option.is-active {
            background: rgba(255, 153, 0, 0.18);
            color: var(--ve-accent);
            font-weight: 800;
        }
        .ve-subtitle-option.is-secondary {
            color: rgba(255, 255, 255, 0.84);
        }
        .ve-subtitle-url-form {
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }
        .ve-subtitle-url-form input {
            width: 100%;
            min-height: 42px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #fff;
            font: inherit;
        }
        .ve-subtitle-url-form button {
            min-height: 42px;
            border: 0;
            border-radius: 10px;
            background: rgba(255, 153, 0, 0.96);
            color: #111;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }
        .ve-subtitle-status {
            margin-top: 10px;
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.82rem;
            line-height: 1.4;
        }
        .ve-subtitle-status.is-error {
            color: #ff9b9b;
        }
        .ve-caption-overlay {
            position: absolute;
            left: 50%;
            right: auto;
            bottom: 66px;
            z-index: 5;
            display: none;
            justify-content: center;
            pointer-events: none;
            text-align: center;
            white-space: pre-line;
            transform: translateX(-50%);
            max-width: min(92%, 760px);
            padding: 8px 14px;
            background: rgba(0, 0, 0, 0.78);
            border: 0;
            border-radius: 4px;
            color: #fff;
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.45;
        }
        .ve-caption-overlay.is-visible {
            display: flex;
        }
        .ve-player-state {
            position: absolute;
            left: 12px;
            right: auto;
            bottom: 60px;
            max-width: calc(100% - 24px);
            padding: 8px 10px;
            background: rgba(0, 0, 0, 0.82);
            border: 0;
            border-radius: 4px;
            color: #f4f4f4;
            font-size: 0.84rem;
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
        @media (max-width: 767px) {
            .ve-stage {
                border-radius: 0;
            }
            .ve-subtitle-anchor {
                right: 14px;
                bottom: 58px;
            }
            .ve-stage .plyr--video .plyr__controls {
                padding: 24px 10px 12px;
            }
            .ve-player-state {
                left: 10px;
                bottom: 54px;
            }
            .ve-caption-overlay {
                left: 50%;
                right: auto;
                bottom: 60px;
                font-size: 0.92rem;
            }
            .ve-player-overlay-button {
                width: 96px;
                height: 96px;
                min-width: 96px;
                min-height: 96px;
            }
            .ve-player-overlay-glyph {
                width: 96px;
                height: 96px;
            }
            .ve-player-overlay-glyph::before {
                margin-top: -24px;
                margin-left: -8px;
                border-top-width: 24px;
                border-bottom-width: 24px;
                border-left-width: 38px;
            }
        }
        /* ── Premium player chrome ── */
        .ve-stage.video-js {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #000;
            overflow: hidden;
            border: 0;
            border-radius: 0;
            color: #fff;
            font-family: Averta, "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            --ve-glow: rgba(229, 9, 20, 0.35);
            --ve-surface: rgba(20, 20, 20, 0.92);
            --ve-surface-hover: rgba(255, 255, 255, 0.1);
            --ve-text: #f4f4f4;
            --ve-muted: rgba(255, 255, 255, 0.55);
            --ve-border: rgba(255, 255, 255, 0.06);
            --ve-track: rgba(255, 255, 255, 0.18);
            --ve-load: rgba(255, 255, 255, 0.3);
            --ve-selected: rgba(255, 255, 255, 0.14);
            --ve-selected-text: #fff;
            --ve-title-font: Averta, "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            --ve-control-size: 56px;
        }
        .ve-stage.video-js,
        .ve-stage.video-js *:not(svg):not(path) {
            font-family: var(--ve-title-font) !important;
        }
        .ve-stage.video-js .vjs-tech {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
            background: #000;
        }
        .ve-stage.video-js .ve-player-overlay {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            transition: opacity .22s ease;
        }
        .ve-stage.video-js .ve-player-overlay.is-hidden {
            opacity: 0;
            pointer-events: none;
        }
        .ve-stage.video-js .ve-player-overlay.is-loading {
            pointer-events: none;
        }
        .ve-stage.video-js .ve-player-poster {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center center;
            display: block;
        }
        .ve-stage.video-js .vjs-text-track-display {
            position: absolute;
            inset: 0;
            z-index: 5;
            pointer-events: none;
        }
        .ve-stage.video-js .vjs-text-track-display > div {
            position: absolute;
            inset: 0;
            margin: 1.5%;
        }
        .ve-stage.video-js .vjs-control-text {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
            white-space: nowrap;
        }
        .ve-stage.video-js .vjs-loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            z-index: 8;
            width: 40px;
            height: 40px;
            margin: 0;
            transform: translate(-50%, -50%);
            border-radius: 999px;
            border: 3px solid rgba(255, 255, 255, 0.12);
            border-top-color: #fff;
            animation: ve-player-button-spin .7s linear infinite;
            pointer-events: none;
        }
        /* ── Big play button (center overlay) ── */
        .ve-stage.video-js .vjs-big-play-button,
        .ve-stage.video-js .ve-player-overlay-button {
            position: relative;
            top: auto;
            left: auto;
            width: 92px;
            height: 92px;
            margin: 0;
            transform: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            transition: transform .2s ease, background .2s ease, box-shadow .2s ease;
            cursor: pointer;
            color: #fff;
            padding: 0;
        }
        .ve-stage.video-js .vjs-big-play-button:hover,
        .ve-stage.video-js .vjs-big-play-button:focus-visible,
        .ve-stage.video-js .ve-player-overlay-button:hover,
        .ve-stage.video-js .ve-player-overlay-button:focus-visible {
            opacity: 1;
            transform: scale(1.06);
            background: var(--ve-accent);
            box-shadow: 0 0 0 5px rgba(0, 0, 0, 0.15), 0 12px 40px rgba(0, 0, 0, 0.45);
        }
        .ve-stage.video-js .ve-player-overlay-glyph,
        .ve-stage.video-js .vjs-big-play-button .vjs-icon-placeholder {
            position: relative;
            display: block;
            width: 92px;
            height: 92px;
        }
        .ve-stage.video-js .ve-player-overlay-glyph::before,
        .ve-stage.video-js .vjs-big-play-button .vjs-icon-placeholder::before {
            content: "";
            position: absolute;
            inset: 0;
            width: 40px;
            height: 40px;
            margin: auto;
            border: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M8 5v14l11-7z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            transform: none;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.25));
        }
        .ve-stage.video-js .ve-player-overlay-label {
            position: absolute;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0,0,0,0); border: 0; white-space: nowrap;
        }
        .ve-stage.video-js .ve-player-overlay-button.is-starting,
        .ve-stage.video-js .vjs-big-play-button.is-starting {
            transform: none;
        }
        .ve-stage.video-js .vjs-big-play-button.is-starting .vjs-icon-placeholder::before,
        .ve-stage.video-js .ve-player-overlay-button.is-starting .ve-player-overlay-glyph::before {
            content: "";
            width: 40px;
            height: 40px;
            margin: auto;
            border: 3px solid rgba(255, 255, 255, 0.16);
            border-top-color: #fff;
            border-radius: 999px;
            border-left-width: 3px;
            border-bottom-width: 3px;
            border-right-width: 3px;
            animation: ve-player-button-spin .7s linear infinite;
            filter: none;
            background: none;
        }
        /* ── Bottom gradient overlay ── */
        .ve-stage.video-js::before {
            content: "";
            position: absolute;
            left: 0; right: 0; bottom: 0;
            height: 50%;
            z-index: 1;
            background: linear-gradient(0deg, rgba(0,0,0,0.88) 0%, rgba(0,0,0,0.4) 45%, transparent 100%);
            pointer-events: none;
            transition: opacity .25s ease;
        }
        .ve-stage.video-js::after {
            content: none;
        }
        .ve-stage.video-js .vjs-tech,
        .ve-stage.video-js .ve-player-poster,
        .ve-stage.video-js .vjs-text-track-display {
            z-index: 0;
        }
        /* ── Control bar ── */
        .ve-stage.video-js .vjs-control-bar {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 6;
            display: flex;
            flex-direction: column;
            padding: 0 16px 14px;
            background: transparent;
            transition: opacity .25s ease, transform .25s ease;
        }
        .ve-stage.video-js.vjs-user-inactive .vjs-control-bar,
        .ve-stage.video-js:not(.vjs-has-started) .vjs-control-bar {
            opacity: 0;
            transform: translateY(6px);
            pointer-events: none;
        }
        /* ── Progress bar row ── */
        .ve-stage.video-js .vjs-progress-control {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            height: 28px;
            margin-bottom: 4px;
        }
        .ve-stage.video-js .vjs-progress-holder {
            position: relative;
            display: block;
            flex: 1 1 auto;
            height: 4px;
            padding: 10px 0;
            margin: -10px 0;
            border-radius: 0;
            background: transparent;
            cursor: pointer;
            overflow: visible;
            background-clip: content-box;
        }
        .ve-stage.video-js .vjs-progress-holder::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 4px;
            margin-top: -2px;
            border-radius: 999px;
            background: var(--ve-track);
            transition: height .15s ease, margin-top .15s ease;
        }
        .ve-stage.video-js .vjs-progress-holder:hover::after {
            height: 6px;
            margin-top: -3px;
        }
        .ve-stage.video-js .vjs-load-progress,
        .ve-stage.video-js .vjs-play-progress {
            position: absolute;
            top: 50%;
            left: 0;
            height: 4px;
            margin-top: -2px;
            border-radius: 999px;
            z-index: 1;
            transition: height .15s ease, margin-top .15s ease;
        }
        .ve-stage.video-js .vjs-progress-holder:hover .vjs-load-progress,
        .ve-stage.video-js .vjs-progress-holder:hover .vjs-play-progress {
            height: 6px;
            margin-top: -3px;
        }
        .ve-stage.video-js .vjs-load-progress {
            background: var(--ve-load);
        }
        .ve-stage.video-js .vjs-play-progress {
            background: var(--ve-accent);
        }
        .ve-stage.video-js .vjs-play-progress::before {
            content: "";
            position: absolute;
            top: 50%;
            right: -7px;
            width: 14px;
            height: 14px;
            border-radius: 999px;
            background: #fff;
            transform: translateY(-50%) scale(0);
            box-shadow: 0 0 0 3px var(--ve-glow);
            transition: transform .15s ease;
            z-index: 2;
        }
        .ve-stage.video-js .vjs-progress-holder:hover .vjs-play-progress::before {
            transform: translateY(-50%) scale(1);
        }
        .ve-stage.video-js .vjs-remaining-time {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            flex: 0 0 auto;
            height: 32px;
            min-width: 64px;
            padding-left: 18px;
            color: var(--ve-muted);
            font-size: 0.92rem;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.01em;
            white-space: nowrap;
            font-family: var(--ve-title-font);
        }
        /* ── Buttons row ── */
        .ve-stage.video-js .vjs-buttons-row {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
        }
        .ve-stage.video-js .vjs-control,
        .ve-stage.video-js .vjs-button {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 0;
            color: #fff;
            font: inherit;
            line-height: 1;
            cursor: pointer;
            transition: background .15s ease, transform .12s ease, opacity .15s ease;
            appearance: none;
            text-decoration: none;
        }
        .ve-stage.video-js .vjs-control:hover,
        .ve-stage.video-js .vjs-control:focus-visible,
        .ve-stage.video-js .vjs-button:hover,
        .ve-stage.video-js .vjs-button:focus-visible {
            opacity: 1;
            outline: none;
            background: transparent;
        }
        .ve-stage.video-js .vjs-icon-placeholder {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
        }
        .ve-stage.video-js .vjs-icon-placeholder::before,
        .ve-stage.video-js .vjs-icon-placeholder::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
        }
        .ve-stage.video-js .vjs-play-control,
        .ve-stage.video-js .vjs-fullscreen-control,
        .ve-stage.video-js .vjs-mute-control,
        .ve-stage.video-js .vjs-seek-button,
        .ve-stage.video-js .vjs-home-link,
        .ve-stage.video-js .vjs-cinema-toggle,
        .ve-stage.video-js .vjs-speed-toggle,
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button {
            width: 56px;
            height: 56px;
            padding: 0;
            border-radius: 50%;
            background: transparent;
            transition: background .15s ease, opacity .15s ease;
        }
        .ve-stage.video-js .vjs-play-control:hover,
        .ve-stage.video-js .vjs-play-control:focus-visible,
        .ve-stage.video-js .vjs-fullscreen-control:hover,
        .ve-stage.video-js .vjs-fullscreen-control:focus-visible,
        .ve-stage.video-js .vjs-mute-control:hover,
        .ve-stage.video-js .vjs-mute-control:focus-visible,
        .ve-stage.video-js .vjs-seek-button:hover,
        .ve-stage.video-js .vjs-seek-button:focus-visible,
        .ve-stage.video-js .vjs-home-link:hover,
        .ve-stage.video-js .vjs-home-link:focus-visible,
        .ve-stage.video-js .vjs-cinema-toggle:hover,
        .ve-stage.video-js .vjs-cinema-toggle:focus-visible,
        .ve-stage.video-js .vjs-speed-toggle:hover,
        .ve-stage.video-js .vjs-speed-toggle:focus-visible,
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button:hover,
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button:focus-visible {
            background: transparent;
            transform: translateY(-1px) scale(1.04);
            opacity: 0.92;
        }
        .ve-stage.video-js .vjs-play-control:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-play-control:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-fullscreen-control:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-fullscreen-control:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-mute-control:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-mute-control:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-seek-button:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-seek-button:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-home-link:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-home-link:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-cinema-toggle:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-cinema-toggle:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-speed-toggle:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-speed-toggle:focus-visible .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button:hover .vjs-icon-placeholder,
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button:focus-visible .vjs-icon-placeholder {
            transform: scale(1.08);
        }
        /* Play/Pause icons */
        .ve-stage.video-js .vjs-play-control.vjs-paused .vjs-icon-placeholder::before {
            width: 36px;
            height: 36px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M8 5v14l11-7z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-play-control.vjs-playing .vjs-icon-placeholder::before {
            width: 36px;
            height: 36px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M6 19h4V5H6v14zm8-14v14h4V5h-4z'/%3E%3C/svg%3E");
        }
        /* Skip buttons - chevron style */
        .ve-stage.video-js .vjs-seek-button {
            width: 82px;
            border-radius: 999px;
        }
        .ve-stage.video-js .vjs-seek-button .vjs-icon-placeholder {
            width: 62px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .ve-stage.video-js .vjs-seek-button .vjs-icon-placeholder::before {
            position: static;
            width: 35px;
            height: 35px;
            transform: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-seek-button.skip-forward .vjs-icon-placeholder::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z'/%3E%3C/svg%3E");
            order: 2;
        }
        .ve-stage.video-js .vjs-seek-button .vjs-icon-placeholder::after {
            content: "10";
            position: static;
            transform: none;
            background: none;
            color: #fff;
            font-size: 12px;
            line-height: 35px;
            font-weight: 800;
            font-family: var(--ve-title-font);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0;
            display: inline-flex;
            align-items: center;
            height: 35px;
        }
        .ve-stage.video-js .vjs-seek-button.skip-forward .vjs-icon-placeholder::after {
            order: 1;
        }
        /* Volume */
        .ve-stage.video-js .vjs-mute-control .vjs-icon-placeholder::before {
            width: 28px;
            height: 28px;
        }
        .ve-stage.video-js .vjs-mute-control.vjs-vol-1 .vjs-icon-placeholder::before,
        .ve-stage.video-js .vjs-mute-control.vjs-vol-2 .vjs-icon-placeholder::before,
        .ve-stage.video-js .vjs-mute-control.vjs-vol-3 .vjs-icon-placeholder::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-mute-control.vjs-vol-0 .vjs-icon-placeholder::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M16.5 12A4.5 4.5 0 0 0 14 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.796 8.796 0 0 0 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3 3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 0 0 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4 9.91 6.09 12 8.18V4z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-volume-panel {
            display: inline-flex;
            align-items: center;
            width: auto;
            gap: 10px;
        }
        .ve-stage.video-js .vjs-volume-control {
            display: inline-flex;
            align-items: center;
            width: 112px;
            height: 56px;
            padding: 16px 0;
            cursor: pointer;
        }
        .ve-stage.video-js .vjs-volume-bar {
            width: 100%;
            height: 24px;
            margin: 0;
            accent-color: var(--ve-accent);
            cursor: pointer;
            border-radius: 999px;
            background: transparent;
        }
        .ve-stage.video-js .vjs-control-bar:hover,
        .ve-stage.video-js .vjs-progress-control:hover,
        .ve-stage.video-js .vjs-volume-panel:hover,
        .ve-stage.video-js .vjs-volume-control:hover,
        .ve-stage.video-js .vjs-volume-bar:hover {
            transform: none;
            opacity: 1;
            background: transparent;
            filter: none;
        }
        .ve-stage.video-js .ve-speed-panel:hover,
        .ve-stage.video-js .ve-subtitle-panel:hover {
            transform: none;
            opacity: 1;
            background: rgba(18, 18, 18, 0.96);
            filter: none;
        }
        /* Home link icon */
        .ve-stage.video-js .vjs-home-link .vjs-icon-placeholder::before {
            width: 28px;
            height: 28px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-cinema-toggle .vjs-icon-placeholder::before {
            width: 32px;
            height: 24px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 24'%3E%3Crect x='3' y='4' width='26' height='12' rx='2.5' fill='white' fill-opacity='0.14'/%3E%3Cpath d='M7 19h18' stroke='white' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M16 16.5v3' stroke='white' stroke-width='2' stroke-linecap='round'/%3E%3Crect x='1.75' y='2.75' width='28.5' height='14.5' rx='3.25' fill='none' stroke='white' stroke-width='1.5'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-cinema-toggle.is-active .vjs-icon-placeholder::before {
            opacity: 1;
        }
        /* Captions button - subtitle icon */
        .ve-stage.video-js .vjs-subs-caps-button {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .ve-stage.video-js .vjs-subs-caps-button > .vjs-button .vjs-icon-placeholder::before {
            width: 28px;
            height: 28px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h16v12zM6 10h2v2H6zm0 4h8v2H6zm10 0h2v2h-2zm-6-4h8v2h-8z'/%3E%3C/svg%3E");
            color: transparent;
            font-size: 0;
        }
        /* Speed button */
        .ve-stage.video-js .vjs-speed-button {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .ve-stage.video-js .vjs-speed-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .ve-stage.video-js .vjs-speed-toggle .vjs-icon-placeholder {
            width: auto;
            height: auto;
            color: #fff;
            font-size: 1.02rem;
            font-weight: 700;
            letter-spacing: 0;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .vjs-speed-toggle .vjs-icon-placeholder::before {
            content: none;
        }
        /* Fullscreen icon - rounded */
        .ve-stage.video-js .vjs-fullscreen-control .vjs-icon-placeholder::before {
            width: 28px;
            height: 28px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M5 5h5V3H3v7h2V5zm9-2v2h5v5h2V3h-7zM5 14H3v7h7v-2H5v-5zm14 5h-5v2h7v-7h-2v5z'/%3E%3C/svg%3E");
        }
        .ve-stage.video-js .vjs-fullscreen-control.is-active .vjs-icon-placeholder::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z'/%3E%3C/svg%3E");
        }
        /* Spacer */
        .ve-stage.video-js .vjs-custom-control-spacer {
            flex: 1 1 auto;
            min-width: 4px;
        }
        /* ── Hover tooltip ── */
        .ve-stage.video-js .vjs-mouse-display {
            position: absolute;
            top: 0;
            display: none;
            pointer-events: none;
        }
        .ve-stage.video-js .vjs-mouse-display.is-visible {
            display: block;
        }
        .ve-stage.video-js .vjs-time-tooltip {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 4px 8px;
            border-radius: 4px;
            background: rgba(0, 0, 0, 0.92);
            color: #fff;
            font-size: 0.76rem;
            font-weight: 600;
            white-space: nowrap;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .vjs-thumbnail-holder {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            overflow: visible;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: #000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .ve-stage.video-js .vjs-thumbnail {
            width: 100%;
            height: 100%;
            background-repeat: no-repeat;
            background-color: #000;
        }
        .ve-stage.video-js .vjs-thumbnail-text {
            position: absolute;
            left: 50%;
            right: auto;
            top: calc(100% + 8px);
            bottom: auto;
            transform: translateX(-50%);
            min-width: 78px;
            padding: 0;
            color: #fff;
            font-size: 0.88rem;
            font-weight: 700;
            text-align: center;
            text-shadow: 0 0 3px rgba(0, 0, 0, 0.85);
            font-family: var(--ve-title-font);
            white-space: nowrap;
        }
        /* ── Speed panel ── */
        .ve-stage.video-js .ve-speed-panel {
            position: absolute;
            right: 0;
            bottom: calc(100% + 14px);
            z-index: 8;
            width: 160px;
            padding: 8px;
            background: rgba(18, 18, 18, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.5);
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-speed-panel[hidden] {
            display: none !important;
        }
        .ve-stage.video-js .ve-speed-panel::after {
            content: "";
            position: absolute;
            right: calc((var(--ve-control-size) - 14px) / 2);
            bottom: -7px;
            width: 14px;
            height: 14px;
            background: rgba(18, 18, 18, 0.96);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transform: rotate(45deg);
        }
        .ve-stage.video-js .ve-speed-panel-header {
            margin-bottom: 6px;
            padding: 4px 8px;
            color: var(--ve-muted);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-speed-list {
            display: grid;
            gap: 2px;
        }
        .ve-stage.video-js .ve-speed-option {
            width: 100%;
            min-height: 34px;
            padding: 7px 10px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--ve-text);
            font: inherit;
            font-size: 0.85rem;
            text-align: left;
            cursor: pointer;
            transition: background .12s ease, color .12s ease;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-speed-option:hover,
        .ve-stage.video-js .ve-speed-option:focus-visible {
            background: var(--ve-surface-hover);
            outline: none;
        }
        .ve-stage.video-js .ve-speed-option.is-active {
            color: var(--ve-selected-text);
            font-weight: 700;
            background: var(--ve-selected);
        }
        /* ── Subtitle panel ── */
        .ve-stage.video-js .ve-subtitle-panel {
            position: absolute;
            right: 0;
            bottom: calc(100% + 14px);
            z-index: 8;
            width: 240px;
            max-width: calc(100vw - 32px);
            padding: 10px;
            background: rgba(18, 18, 18, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.5);
            overflow: visible;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-panel[hidden] {
            display: none !important;
        }
        .ve-stage.video-js .ve-subtitle-panel::after {
            content: "";
            position: absolute;
            right: calc((var(--ve-control-size) - 14px) / 2);
            bottom: -7px;
            width: 14px;
            height: 14px;
            background: rgba(18, 18, 18, 0.96);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transform: rotate(45deg);
        }
        .ve-stage.video-js .ve-subtitle-panel-header {
            margin-bottom: 6px;
            padding: 4px 8px;
            color: var(--ve-muted);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-list {
            display: grid;
            gap: 2px;
        }
        .ve-stage.video-js .ve-subtitle-option {
            width: 100%;
            min-height: 34px;
            padding: 7px 10px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--ve-text);
            font: inherit;
            font-size: 0.85rem;
            text-align: left;
            cursor: pointer;
            transition: background .12s ease, color .12s ease;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-option:hover,
        .ve-stage.video-js .ve-subtitle-option:focus-visible {
            background: var(--ve-surface-hover);
            outline: none;
        }
        .ve-stage.video-js .ve-subtitle-option.is-active {
            color: var(--ve-selected-text);
            font-weight: 700;
            background: var(--ve-selected);
        }
        .ve-stage.video-js .ve-subtitle-url-form[hidden] {
            display: none !important;
        }
        .ve-stage.video-js .ve-subtitle-url-form {
            display: grid;
            gap: 6px;
            margin-top: 8px;
        }
        .ve-stage.video-js .ve-subtitle-url-form input {
            width: 100%;
            min-width: 0;
            min-height: 34px;
            padding: 0 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.04);
            color: #fff;
            font: inherit;
            font-size: 0.82rem;
            box-sizing: border-box;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-url-form button {
            min-height: 34px;
            border: 0;
            border-radius: 6px;
            background: var(--ve-accent);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-status {
            margin-top: 6px;
            padding: 0 4px;
            color: var(--ve-muted);
            font-size: 0.78rem;
            line-height: 1.4;
            font-family: var(--ve-title-font);
        }
        .ve-stage.video-js .ve-subtitle-status.is-error {
            color: #ff6b6b;
        }
        /* ── Caption overlay ── */
        .ve-stage.video-js .ve-caption-overlay {
            position: absolute;
            left: 50%;
            bottom: 80px;
            z-index: 5;
            display: none;
            max-width: min(92%, 760px);
            padding: 8px 14px;
            background: rgba(0, 0, 0, 0.82);
            border-radius: 4px;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.45;
            text-align: center;
            white-space: pre-line;
            transform: translateX(-50%);
            pointer-events: none;
        }
        .ve-stage.video-js .ve-caption-overlay.is-visible {
            display: block;
        }
        /* ── Player state toast ── */
        .ve-stage.video-js .ve-player-state {
            position: absolute;
            left: 16px;
            bottom: 80px;
            z-index: 7;
            max-width: calc(100% - 32px);
            padding: 8px 12px;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.86);
            color: var(--ve-text);
            font-size: 0.82rem;
            opacity: 0;
            transform: translateY(6px);
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease;
        }
        .ve-stage.video-js .ve-player-state.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .ve-stage.video-js .ve-player-state.is-error {
            color: #ff6b6b;
        }
        /* ── Mobile adjustments ── */
        @media (max-width: 767px) {
            .ve-stage.video-js .vjs-big-play-button,
            .ve-stage.video-js .ve-player-overlay-button {
                width: 72px;
                height: 72px;
            }
            .ve-stage.video-js .ve-player-overlay-glyph,
            .ve-stage.video-js .vjs-big-play-button .vjs-icon-placeholder {
                width: 72px;
                height: 72px;
            }
            .ve-stage.video-js .ve-player-overlay-glyph::before,
            .ve-stage.video-js .vjs-big-play-button .vjs-icon-placeholder::before {
                width: 32px;
                height: 32px;
            }
            .ve-stage.video-js .vjs-control-bar {
                padding: 0 10px 8px;
            }
            .ve-stage.video-js .vjs-buttons-row {
                gap: 1px;
            }
            .ve-stage.video-js .vjs-play-control,
            .ve-stage.video-js .vjs-fullscreen-control,
            .ve-stage.video-js .vjs-mute-control,
            .ve-stage.video-js .vjs-seek-button,
            .ve-stage.video-js .vjs-home-link,
            .ve-stage.video-js .vjs-cinema-toggle,
            .ve-stage.video-js .vjs-speed-toggle,
            .ve-stage.video-js .vjs-subs-caps-button > .vjs-button {
                width: 46px;
                height: 46px;
            }
            .ve-stage.video-js .vjs-seek-button {
                width: 70px;
            }
            .ve-stage.video-js .vjs-seek-button .vjs-icon-placeholder {
                width: 58px;
                height: 35px;
            }
            .ve-stage.video-js .vjs-volume-control {
                display: none;
            }
            .ve-stage.video-js .vjs-subs-caps-button,
            .ve-stage.video-js .vjs-cinema-toggle {
                display: none !important;
            }
            .ve-stage.video-js {
                --ve-control-size: 46px;
            }
            .ve-stage.video-js .vjs-remaining-time {
                font-size: 0.72rem;
                min-width: 44px;
            }
            .ve-stage.video-js .ve-speed-panel,
            .ve-stage.video-js .ve-subtitle-panel {
                width: min(220px, calc(100vw - 20px));
            }
            .ve-stage.video-js .ve-caption-overlay {
                bottom: 64px;
                font-size: 0.88rem;
            }
            .ve-stage.video-js .ve-player-state {
                left: 10px;
                bottom: 64px;
            }
        }
    </style>
</head>
<body>
    <div class="ve-embed-shell">
        <div id="video_player" class="ve-stage video-js vjs-big-play-centered vjs-controls-enabled vjs-touch-enabled vjs-workinghover vjs-v7 vjs-layout-large vjs-seek-buttons vjs-brand vjs-paused vjs-user-active" tabindex="-1" lang="en-us" translate="no" role="region" aria-label="Video Player">
            <div id="ve-player-overlay" class="ve-player-overlay">
                {$posterOverlay}
                <button type="button" id="ve-player-overlay-button" class="ve-player-overlay-button vjs-big-play-button" title="Play Video" aria-label="Play Video">
                    <span class="ve-player-overlay-glyph vjs-icon-placeholder" aria-hidden="true"></span>
                    <span class="ve-player-overlay-label">Play Video</span>
                </button>
            </div>
            <video
                id="ve-secure-player"
                class="vjs-tech"
                playsinline
                preload="none"
                controlsList="nodownload noplaybackrate"
                disablepictureinpicture
                crossorigin="use-credentials"
            ></video>
            <div class="vjs-text-track-display" translate="yes" aria-live="off" aria-atomic="true">
                <div></div>
            </div>
            <div id="ve-loading-spinner" class="vjs-loading-spinner" dir="ltr" hidden>
                <span class="vjs-control-text">Video Player is loading.</span>
            </div>
            <div id="ve-caption-overlay" class="ve-caption-overlay" aria-live="polite"></div>
            <div id="ve-player-controls" class="vjs-control-bar" dir="ltr">
                <div class="vjs-progress-control vjs-control">
                    <div id="ve-progress-holder" tabindex="0" class="vjs-progress-holder vjs-slider vjs-slider-horizontal" role="slider" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Progress Bar" aria-valuetext="0:00 of 0:00">
                        <div id="ve-load-progress" class="vjs-load-progress" style="width:0%;"></div>
                        <div id="ve-mouse-display" class="vjs-mouse-display">
                            <div id="ve-time-tooltip" class="vjs-time-tooltip" aria-hidden="true">0:00</div>
                            <div id="ve-thumbnail-holder" class="vjs-thumbnail-holder" hidden>
                                <div id="ve-thumbnail-image" class="vjs-thumbnail"></div>
                                <span id="ve-thumbnail-text" class="vjs-thumbnail-text">00:00:00</span>
                            </div>
                        </div>
                        <div id="ve-play-progress" class="vjs-play-progress vjs-slider-bar" aria-hidden="true" style="width:0%;"></div>
                    </div>
                    <div class="vjs-remaining-time vjs-time-control vjs-control">
                        <span class="vjs-control-text" role="presentation">Remaining Time&nbsp;</span>
                        <span id="ve-remaining-time-display" class="vjs-remaining-time-display" aria-live="off" role="presentation">0:00</span>
                    </div>
                </div>
                <div class="vjs-buttons-row">
                    <button type="button" id="ve-play-toggle" class="vjs-play-control vjs-control vjs-button vjs-paused" title="Play" aria-label="Play">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text" aria-live="polite">Play</span>
                    </button>
                    <button type="button" id="ve-skip-back" class="vjs-seek-button skip-back skip-10 vjs-control vjs-button" title="Seek back 10 seconds" aria-label="Seek back 10 seconds">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text" aria-live="polite">Seek back 10 seconds</span>
                    </button>
                    <button type="button" id="ve-skip-forward" class="vjs-seek-button skip-forward skip-10 vjs-control vjs-button" title="Seek forward 10 seconds" aria-label="Seek forward 10 seconds">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text" aria-live="polite">Seek forward 10 seconds</span>
                    </button>
                    <div class="vjs-volume-panel vjs-control vjs-volume-panel-horizontal">
                        <button type="button" id="ve-mute-toggle" class="vjs-mute-control vjs-control vjs-button vjs-vol-3" title="Mute" aria-label="Mute">
                            <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                            <span class="vjs-control-text" aria-live="polite">Mute</span>
                        </button>
                        <div class="vjs-volume-control vjs-control vjs-volume-horizontal">
                            <input type="range" id="ve-volume-range" class="vjs-volume-bar vjs-slider-bar vjs-slider vjs-slider-horizontal" min="0" max="100" step="1" value="100" aria-label="Volume Level" aria-live="polite" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100" aria-valuetext="100%">
                        </div>
                    </div>
                    <div class="vjs-custom-control-spacer vjs-spacer">&nbsp;</div>
                    <a id="ve-home-link" class="vjs-home-link vjs-control vjs-button" title="Visit Website" aria-label="Visit Website" target="_blank" rel="noopener">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text">Visit Website</span>
                    </a>
                    <div class="vjs-subs-caps-button vjs-menu-button vjs-menu-button-popup vjs-control vjs-button">
                        <button type="button" id="ve-subtitle-button" class="vjs-subs-caps-button vjs-menu-button vjs-menu-button-popup vjs-button" title="Captions" aria-label="Captions" aria-controls="ve-subtitle-panel" aria-expanded="false">
                            <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                            <span class="vjs-control-text" aria-live="polite">Captions</span>
                        </button>
                        <div id="ve-subtitle-panel" class="ve-subtitle-panel vjs-menu" hidden>
                            <div class="ve-subtitle-panel-header">Captions Settings</div>
                            <div id="ve-subtitle-list" class="ve-subtitle-list"></div>
                            <form id="ve-subtitle-url-form" class="ve-subtitle-url-form" hidden>
                                <input type="url" id="ve-subtitle-url-input" placeholder="https://example.com/subtitles.vtt" inputmode="url">
                                <button type="submit">Load</button>
                            </form>
                            <div id="ve-subtitle-status" class="ve-subtitle-status" hidden></div>
                        </div>
                    </div>
                    <div class="vjs-speed-button vjs-menu-button vjs-control vjs-button">
                        <button type="button" id="ve-speed-button" class="vjs-speed-toggle vjs-button" title="Playback Speed" aria-label="Playback Speed" aria-expanded="false">
                            <span class="vjs-icon-placeholder" aria-hidden="true">1x</span>
                            <span class="vjs-control-text">Playback Speed</span>
                        </button>
                        <div id="ve-speed-panel" class="ve-speed-panel vjs-menu" hidden>
                            <div class="ve-speed-panel-header">Playback Speed</div>
                            <div id="ve-speed-list" class="ve-speed-list">
                                <button type="button" class="ve-speed-option" data-speed="0.25">0.25x</button>
                                <button type="button" class="ve-speed-option" data-speed="0.5">0.5x</button>
                                <button type="button" class="ve-speed-option" data-speed="0.75">0.75x</button>
                                <button type="button" class="ve-speed-option is-active" data-speed="1">Normal</button>
                                <button type="button" class="ve-speed-option" data-speed="1.25">1.25x</button>
                                <button type="button" class="ve-speed-option" data-speed="1.5">1.5x</button>
                                <button type="button" class="ve-speed-option" data-speed="1.75">1.75x</button>
                                <button type="button" class="ve-speed-option" data-speed="2">2x</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="ve-cinema-toggle" class="vjs-cinema-toggle vjs-control vjs-button" title="Cinema Mode" aria-label="Cinema Mode">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text" aria-live="polite">Cinema Mode</span>
                    </button>
                    <button type="button" id="ve-fullscreen-toggle" class="vjs-fullscreen-control vjs-control vjs-button" title="Fullscreen" aria-label="Fullscreen">
                        <span class="vjs-icon-placeholder" aria-hidden="true"></span>
                        <span class="vjs-control-text" aria-live="polite">Fullscreen</span>
                    </button>
                </div>
            </div>
            <input type="file" id="ve-subtitle-file-input" accept=".vtt,.srt,text/vtt" hidden>
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
    $dashboardUrl = ve_url('/videos');

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
    $html = str_replace('href="/dashboard/videos"', 'href="/videos"', $html);

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
    $legacyJsVersion = (string) (@filemtime(ve_root_path('assets', 'js', 'video_dashboard_legacy.js')) ?: time());
    $legacyJs = ve_h(ve_url('/assets/js/video_dashboard_legacy.js?v=' . rawurlencode($legacyJsVersion)));
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
