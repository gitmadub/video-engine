<?php

declare(strict_types=1);

function ve_public_api_extract_key_from_request(): ?string
{
    foreach ([
        $_REQUEST['key'] ?? null,
        $_REQUEST['api_key'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    $headerKey = ve_api_extract_key_from_request();

    if (is_string($headerKey) && $headerKey !== '') {
        return $headerKey;
    }

    $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

    if ($queryString !== '' && !str_contains($queryString, '=') && !str_contains($queryString, '&')) {
        return $queryString;
    }

    return null;
}

function ve_public_api_require_auth(string $requestKind = 'request'): array
{
    $apiKey = ve_public_api_extract_key_from_request();

    if (!is_string($apiKey) || $apiKey === '') {
        ve_public_api_respond_raw(null, [
            'msg' => 'Missing API key.',
            'server_time' => ve_now(),
            'status' => 401,
        ], 401);
    }

    $user = ve_find_user_by_api_key($apiKey);

    if (!is_array($user)) {
        ve_public_api_respond_raw(null, [
            'msg' => 'Invalid API key.',
            'server_time' => ve_now(),
            'status' => 401,
        ], 401);
    }

    $rateLimit = ve_api_rate_limit_state($user, $requestKind);
    ve_api_send_rate_limit_headers($rateLimit);

    if (($rateLimit['allowed'] ?? false) !== true) {
        ve_public_api_respond_raw([
            'user' => $user,
            'api_key_hash' => (string) ($user['api_key_hash'] ?? ve_api_key_hash($apiKey)),
            'request_kind' => $requestKind,
        ], [
            'msg' => (string) ($rateLimit['message'] ?? 'Too Many Requests'),
            'server_time' => ve_now(),
            'status' => (int) ($rateLimit['status'] ?? 429),
        ], (int) ($rateLimit['status'] ?? 429));
    }

    return [
        'user' => $user,
        'api_key_hash' => (string) ($user['api_key_hash'] ?? ve_api_key_hash($apiKey)),
        'request_kind' => $requestKind,
    ];
}

function ve_public_api_respond_raw(?array $auth, array $payload, int $status = 200, int $bytesIn = 0): void
{
    if (is_array($auth) && isset($auth['user']['id'], $auth['api_key_hash'], $auth['request_kind'])) {
        ve_api_record_request(
            (int) $auth['user']['id'],
            (string) $auth['api_key_hash'],
            (string) $auth['request_kind'],
            $status,
            $bytesIn
        );
    }

    ve_json($payload, $status);
}

function ve_public_api_success(array $auth, mixed $result = null, array $extra = [], string $msg = 'OK', int $status = 200, int $bytesIn = 0): void
{
    $payload = array_merge([
        'msg' => $msg,
        'server_time' => ve_now(),
        'status' => $status,
    ], $extra);

    if ($result !== null) {
        $payload['result'] = $result;
    }

    ve_public_api_respond_raw($auth, $payload, $status, $bytesIn);
}

function ve_public_api_error(array $auth, string $msg, int $status = 422, array $extra = []): void
{
    ve_public_api_respond_raw($auth, array_merge([
        'msg' => $msg,
        'server_time' => ve_now(),
        'status' => $status,
    ], $extra), $status);
}

function ve_public_api_storage_left_bytes(): int
{
    $path = ve_storage_path();
    $free = @disk_free_space($path);

    return is_float($free) || is_int($free) ? max(0, (int) $free) : 0;
}

function ve_public_api_video_status_label(array $video): string
{
    return match ((string) ($video['status'] ?? VE_VIDEO_STATUS_QUEUED)) {
        VE_VIDEO_STATUS_READY => 'Active',
        VE_VIDEO_STATUS_FAILED => 'Error',
        default => 'Working',
    };
}

function ve_public_api_video_views_map(int $userId, array $videoIds): array
{
    $videoIds = ve_video_legacy_normalize_id_list($videoIds);

    if ($videoIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($videoIds), '?'));
    $params = array_merge([$userId], $videoIds);
    $stmt = ve_db()->prepare(
        'SELECT videos.id AS video_id, COALESCE(SUM(video_stats_daily.views), 0) AS total_views
         FROM videos
         LEFT JOIN video_stats_daily ON video_stats_daily.video_id = videos.id
         WHERE videos.user_id = ? AND videos.deleted_at IS NULL AND videos.id IN (' . $placeholders . ')
         GROUP BY videos.id'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $map[(int) ($row['video_id'] ?? 0)] = (int) ($row['total_views'] ?? 0);
    }

    return $map;
}

function ve_public_api_video_last_view_map(int $userId, array $videoIds): array
{
    $videoIds = ve_video_legacy_normalize_id_list($videoIds);

    if ($videoIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($videoIds), '?'));
    $params = array_merge([$userId], $videoIds);
    $stmt = ve_db()->prepare(
        'SELECT videos.id AS video_id, MAX(video_playback_sessions.last_seen_at) AS last_view
         FROM videos
         LEFT JOIN video_playback_sessions ON video_playback_sessions.video_id = videos.id
         WHERE videos.user_id = ? AND videos.deleted_at IS NULL AND videos.id IN (' . $placeholders . ')
         GROUP BY videos.id'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $map[(int) ($row['video_id'] ?? 0)] = trim((string) ($row['last_view'] ?? ''));
    }

    return $map;
}

function ve_public_api_file_row(array $video, int $views = 0, string $lastView = ''): array
{
    $size = (int) (($video['processed_size_bytes'] ?? 0) > 0 ? $video['processed_size_bytes'] : $video['original_size_bytes']);
    $publicId = (string) ($video['public_id'] ?? '');
    $duration = (int) round((float) ($video['duration_seconds'] ?? 0));

    return [
        'download_url' => ve_absolute_url('/d/' . rawurlencode($publicId)),
        'single_img' => ve_video_public_thumbnail_url($video, 'single'),
        'file_code' => $publicId,
        'canplay' => (string) ((string) ($video['status'] ?? '') === VE_VIDEO_STATUS_READY ? '1' : '0'),
        'length' => (string) max(0, $duration),
        'views' => (string) max(0, $views),
        'uploaded' => (string) ($video['created_at'] ?? ''),
        'public' => (string) ((int) ($video['is_public'] ?? 1)),
        'fld_id' => (string) ((int) ($video['folder_id'] ?? 0)),
        'title' => (string) ($video['title'] ?? 'Untitled video'),
        'status' => 200,
        'filecode' => $publicId,
        'splash_img' => ve_video_public_thumbnail_url($video, 'splash'),
        'size' => (string) max(0, $size),
        'last_view' => $lastView,
        'protected_embed' => ve_absolute_url('/e/' . rawurlencode($publicId)),
        'protected_dl' => ve_absolute_url('/d/' . rawurlencode($publicId)),
    ];
}

function ve_public_api_file_image_row(array $video): array
{
    $publicId = (string) ($video['public_id'] ?? '');

    return [
        'status' => 200,
        'filecode' => $publicId,
        'title' => (string) ($video['title'] ?? 'Untitled video'),
        'single_img' => ve_video_public_thumbnail_url($video, 'single'),
        'thumb_img' => ve_absolute_url('/api/videos/' . rawurlencode($publicId) . '/poster.jpg'),
        'splash_img' => ve_video_public_thumbnail_url($video, 'splash'),
    ];
}

function ve_public_api_parse_file_codes(string $rawValue): array
{
    $codes = preg_split('/[\s,]+/', trim($rawValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $normalized = [];

    foreach ($codes as $code) {
        $code = trim((string) $code);

        if ($code !== '') {
            $normalized[$code] = $code;
        }
    }

    return array_values($normalized);
}

function ve_public_api_fetch_user_videos_by_codes(int $userId, array $codes): array
{
    $codes = array_values(array_filter($codes, static fn ($code): bool => is_string($code) && trim($code) !== ''));

    if ($codes === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($codes), '?'));
    $params = array_merge([$userId], $codes);
    $stmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE user_id = ? AND deleted_at IS NULL AND public_id IN (' . $placeholders . ')'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $byCode = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $byCode[(string) ($row['public_id'] ?? '')] = $row;
    }

    $ordered = [];

    foreach ($codes as $code) {
        if (isset($byCode[$code])) {
            $ordered[] = $byCode[$code];
        }
    }

    return $ordered;
}

function ve_public_api_folder_row(array $folder): array
{
    return [
        'name' => (string) ($folder['name'] ?? ''),
        'code' => (string) ($folder['public_code'] ?? ''),
        'fld_id' => (string) ((int) ($folder['id'] ?? 0)),
    ];
}

function ve_public_api_list_videos_for_user(int $userId, array $options = []): array
{
    $page = max(1, (int) ($options['page'] ?? 1));
    $perPage = max(1, min(200, (int) ($options['per_page'] ?? 50)));
    $hasFolderFilter = array_key_exists('folder_id', $options);
    $folderId = ve_video_normalize_folder_id($userId, (int) ($options['folder_id'] ?? 0));
    $search = trim((string) ($options['search'] ?? ''));
    $created = trim((string) ($options['created'] ?? ''));
    $params = [
        ':user_id' => $userId,
    ];
    $where = [
        'user_id = :user_id',
        'deleted_at IS NULL',
    ];

    if ($hasFolderFilter) {
        $params[':folder_id'] = $folderId;
        $where[] = 'folder_id = :folder_id';
    }

    if ($search !== '') {
        $where[] = '(title LIKE :search OR original_filename LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($created !== '') {
        if (preg_match('/^\d+$/', $created) === 1) {
            $params[':created_since'] = gmdate('Y-m-d H:i:s', ve_timestamp() - ((int) $created * 60));
            $where[] = 'created_at >= :created_since';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $created) === 1) {
            $params[':created_since'] = strlen($created) === 10 ? ($created . ' 00:00:00') : $created;
            $where[] = 'created_at >= :created_since';
        }
    }

    $countStmt = ve_db()->prepare(
        'SELECT COUNT(*) FROM videos WHERE ' . implode(' AND ', $where)
    );
    $countStmt->execute($params);
    $totalResults = (int) $countStmt->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = ve_db()->prepare(
        'SELECT * FROM videos
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $videos = $stmt->fetchAll();
    $videos = is_array($videos) ? $videos : [];
    $videoIds = array_map(static fn (array $video): int => (int) ($video['id'] ?? 0), $videos);
    $viewsMap = ve_public_api_video_views_map($userId, $videoIds);
    $lastViewMap = ve_public_api_video_last_view_map($userId, $videoIds);
    $files = [];

    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }

        $videoId = (int) ($video['id'] ?? 0);
        $files[] = ve_public_api_file_row(
            $video,
            (int) ($viewsMap[$videoId] ?? 0),
            (string) ($lastViewMap[$videoId] ?? '')
        );
    }

    return [
        'total_pages' => max(1, (int) ceil($totalResults / $perPage)),
        'files' => $files,
        'results_total' => (string) $totalResults,
        'results' => count($files),
    ];
}

function ve_public_api_copy_directory(string $source, string $destination): void
{
    if (!is_dir($source)) {
        return;
    }

    ve_ensure_directory($destination);
    $items = scandir($source);

    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            ve_public_api_copy_directory($sourcePath, $destinationPath);
            continue;
        }

        if (!@copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('The video library files could not be cloned.');
        }
    }
}

function ve_public_api_clone_video(int $userId, array $sourceVideo, int $folderId): array
{
    $folderId = ve_video_normalize_folder_id($userId, $folderId);
    $now = ve_now();
    $publicId = ve_video_generate_public_id();
    $stmt = ve_db()->prepare(
        'INSERT INTO videos (
            user_id, folder_id, public_id, title, original_filename, source_extension, is_public, status, status_message,
            duration_seconds, width, height, video_codec, audio_codec,
            original_size_bytes, processed_size_bytes, compression_ratio,
            processing_error, created_at, updated_at, queued_at, processing_started_at, ready_at, deleted_at
        ) VALUES (
            :user_id, :folder_id, :public_id, :title, :original_filename, :source_extension, :is_public, :status, :status_message,
            :duration_seconds, :width, :height, :video_codec, :audio_codec,
            :original_size_bytes, :processed_size_bytes, :compression_ratio,
            :processing_error, :created_at, :updated_at, :queued_at, :processing_started_at, :ready_at, NULL
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':folder_id' => $folderId,
        ':public_id' => $publicId,
        ':title' => (string) ($sourceVideo['title'] ?? 'Untitled video'),
        ':original_filename' => (string) ($sourceVideo['original_filename'] ?? ''),
        ':source_extension' => (string) ($sourceVideo['source_extension'] ?? 'mp4'),
        ':is_public' => (int) ($sourceVideo['is_public'] ?? 1),
        ':status' => (string) ($sourceVideo['status'] ?? VE_VIDEO_STATUS_READY),
        ':status_message' => (string) ($sourceVideo['status_message'] ?? ''),
        ':duration_seconds' => isset($sourceVideo['duration_seconds']) ? (float) $sourceVideo['duration_seconds'] : null,
        ':width' => isset($sourceVideo['width']) ? (int) $sourceVideo['width'] : null,
        ':height' => isset($sourceVideo['height']) ? (int) $sourceVideo['height'] : null,
        ':video_codec' => (string) ($sourceVideo['video_codec'] ?? ''),
        ':audio_codec' => (string) ($sourceVideo['audio_codec'] ?? ''),
        ':original_size_bytes' => (int) ($sourceVideo['original_size_bytes'] ?? 0),
        ':processed_size_bytes' => (int) ($sourceVideo['processed_size_bytes'] ?? 0),
        ':compression_ratio' => $sourceVideo['compression_ratio'] ?? null,
        ':processing_error' => (string) ($sourceVideo['processing_error'] ?? ''),
        ':created_at' => $now,
        ':updated_at' => $now,
        ':queued_at' => $now,
        ':processing_started_at' => $sourceVideo['processing_started_at'] ?? null,
        ':ready_at' => $sourceVideo['ready_at'] ?? null,
    ]);

    $cloned = ve_video_get_by_public_id($publicId);

    if (!is_array($cloned)) {
        throw new RuntimeException('The cloned video could not be created.');
    }

    try {
        ve_public_api_copy_directory(
            ve_video_library_directory((string) ($sourceVideo['public_id'] ?? '')),
            ve_video_library_directory($publicId)
        );
    } catch (Throwable $throwable) {
        ve_db()->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => (int) ($cloned['id'] ?? 0)]);
        ve_video_delete_directory(ve_video_library_directory($publicId));
        throw $throwable;
    }

    return $cloned;
}

function ve_public_api_remote_code_from_job_id(int $jobId): string
{
    return 'ru' . $jobId;
}

function ve_public_api_remote_job_id_from_code(string $code, int $userId): int
{
    $code = trim($code);

    if ($code === '') {
        return 0;
    }

    if (preg_match('/^ru(\d+)$/i', $code, $matches) === 1) {
        return (int) $matches[1];
    }

    if (preg_match('/^\d+$/', $code) === 1) {
        return (int) $code;
    }

    $stmt = ve_db()->prepare(
        'SELECT id FROM remote_uploads
         WHERE user_id = :user_id AND deleted_at IS NULL AND video_public_id = :video_public_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':video_public_id' => $code,
    ]);

    return (int) $stmt->fetchColumn();
}

function ve_public_api_remote_status_label(string $status): string
{
    return match ($status) {
        VE_REMOTE_STATUS_COMPLETE => 'finished',
        VE_REMOTE_STATUS_ERROR => 'error',
        VE_REMOTE_STATUS_DOWNLOADING, VE_REMOTE_STATUS_IMPORTING => 'working',
        default => 'pending',
    };
}

function ve_public_api_remote_row(array $job): array
{
    return [
        'bytes_total' => (string) max((int) ($job['bytes_total'] ?? 0), (int) ($job['bytes_downloaded'] ?? 0)),
        'created' => (string) ($job['created_at'] ?? ''),
        'remote_url' => (string) ($job['source_url'] ?? ''),
        'status' => ve_public_api_remote_status_label((string) ($job['status'] ?? VE_REMOTE_STATUS_PENDING)),
        'file_code' => ve_public_api_remote_code_from_job_id((int) ($job['id'] ?? 0)),
        'bytes_downloaded' => (string) max(0, (int) ($job['bytes_downloaded'] ?? 0)),
        'folder_id' => (string) ((int) ($job['folder_id'] ?? 0)),
    ];
}

function ve_public_api_handle_upload_server_get(): void
{
    $auth = ve_public_api_require_auth('upload');
    $url = ve_absolute_url('/api/upload/server/01?key=' . rawurlencode((string) ve_public_api_extract_key_from_request()));
    ve_public_api_success($auth, $url);
}

function ve_public_api_handle_upload_server_post(): void
{
    $auth = ve_public_api_require_auth('upload');
    $user = $auth['user'];

    if (!ve_video_processing_available()) {
        ve_public_api_error($auth, 'Upload service is not available on this server right now.', 503);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        ve_public_api_error($auth, 'No file uploaded.', 422);
    }

    $file = $_FILES['file'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        ve_public_api_error($auth, ve_video_upload_error_message($error), 422);
    }

    $validated = ve_video_validate_file($file);

    try {
        $video = ve_video_queue_local_source(
            (int) $user['id'],
            (string) $file['tmp_name'],
            (string) ($validated['filename'] ?? (string) ($file['name'] ?? '')),
            trim((string) ($_POST['title'] ?? '')),
            true,
            (int) ($_POST['fld_id'] ?? 0)
        );
    } catch (RuntimeException $exception) {
        ve_public_api_error($auth, $exception->getMessage(), 500);
    }

    $viewsMap = ve_public_api_video_views_map((int) $user['id'], [(int) ($video['id'] ?? 0)]);
    $lastViewMap = ve_public_api_video_last_view_map((int) $user['id'], [(int) ($video['id'] ?? 0)]);

    ve_public_api_success(
        $auth,
        [
            ve_public_api_file_row(
                $video,
                (int) ($viewsMap[(int) ($video['id'] ?? 0)] ?? 0),
                (string) ($lastViewMap[(int) ($video['id'] ?? 0)] ?? '')
            ),
        ],
        [],
        'OK',
        200,
        (int) ($validated['size'] ?? 0)
    );
}

function ve_dispatch_public_api_routes(string $path): bool
{
    if ($path === '/api/account/info') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $user = $auth['user'];
        $balanceMicroUsd = ve_dashboard_balance_micro_usd((int) $user['id']);
        $storageUsed = ve_dashboard_storage_bytes((int) $user['id']);
        ve_public_api_success($auth, [
            'email' => (string) ($user['email'] ?? ''),
            'balance' => number_format($balanceMicroUsd / 1000000, 5, '.', ''),
            'storage_used' => (string) $storageUsed,
            'storage_left' => ve_public_api_storage_left_bytes(),
            'premim_expire' => trim((string) ($user['premium_until'] ?? '')),
        ]);
    }

    if ($path === '/api/account/stats') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $user = $auth['user'];
        $from = null;
        $to = null;
        $last = max(1, min(120, (int) ($_GET['last'] ?? 7)));

        if (isset($_GET['from_date']) || isset($_GET['to_date'])) {
            $from = isset($_GET['from_date']) ? (string) $_GET['from_date'] : null;
            $to = isset($_GET['to_date']) ? (string) $_GET['to_date'] : null;
        }

        if ($from !== null || $to !== null) {
            $snapshot = ve_dashboard_reports_snapshot((int) $user['id'], $from, $to);
        } else {
            $range = ve_dashboard_normalize_date_range(null, null, $last);
            $snapshot = ve_dashboard_reports_snapshot((int) $user['id'], $range['from'], $range['to']);
        }

        $result = [];

        foreach (($snapshot['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'profit_views' => number_format(((int) ($row['profit_micro_usd'] ?? 0)) / 1000000, 5, '.', ''),
                'downloads' => '0',
                'views' => (string) ((int) ($row['views'] ?? 0)),
                'day' => (string) ($row['date'] ?? ''),
                'profit_total' => number_format(((int) ($row['total_micro_usd'] ?? 0)) / 1000000, 5, '.', ''),
            ];
        }

        ve_public_api_success($auth, $result);
    }

    if ($path === '/api/dmca/list') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        ve_public_api_success($auth, []);
    }

    if ($path === '/api/upload/server') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        ve_public_api_handle_upload_server_get();
    }

    if (preg_match('#^/api/upload/server/([A-Za-z0-9_-]+)$#', $path) === 1) {
        if (!ve_is_method('POST')) {
            ve_method_not_allowed(['POST']);
        }

        ve_public_api_handle_upload_server_post();
    }

    if ($path === '/api/file/clone') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('upload');
        $user = $auth['user'];
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));

        if ($fileCode === '') {
            ve_public_api_error($auth, 'file_code is required.');
        }

        $source = ve_video_get_by_public_id($fileCode);

        if (!is_array($source)) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        if ((int) ($source['user_id'] ?? 0) !== (int) $user['id'] && (int) ($source['is_public'] ?? 0) !== 1) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        try {
            $clone = ve_public_api_clone_video((int) $user['id'], $source, (int) ($_GET['fld_id'] ?? 0));
        } catch (RuntimeException $exception) {
            ve_public_api_error($auth, $exception->getMessage(), 500);
        }

        $publicId = (string) ($clone['public_id'] ?? '');
        ve_public_api_success($auth, [
            'embed_url' => ve_absolute_url('/e/' . rawurlencode($publicId)),
            'download_url' => ve_absolute_url('/d/' . rawurlencode($publicId)),
            'protected_download' => ve_absolute_url('/d/' . rawurlencode($publicId)),
            'protected_embed' => ve_absolute_url('/e/' . rawurlencode($publicId)),
            'filecode' => $publicId,
        ]);
    }

    if ($path === '/api/upload/url') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('upload');
        $user = $auth['user'];
        $url = trim((string) ($_GET['url'] ?? ''));

        if ($url === '') {
            ve_public_api_error($auth, 'url is required.');
        }

        if (!ve_video_processing_available()) {
            ve_public_api_error($auth, 'Remote upload is not available on this server right now.', 503);
        }

        if (!ve_remote_is_http_url($url)) {
            ve_public_api_error($auth, 'Only fully qualified http:// or https:// URLs are supported.');
        }

        if (ve_remote_remaining_slots((int) $user['id']) <= 0) {
            ve_public_api_error($auth, 'No remote upload slots are available right now.', 429);
        }

        $job = ve_remote_create_job((int) $user['id'], $url, (int) ($_GET['fld_id'] ?? 0));
        $jobId = (int) ($job['id'] ?? 0);

        if ($jobId <= 0) {
            ve_public_api_error($auth, 'Remote upload could not be queued.', 500);
        }

        ve_remote_maybe_spawn_worker();
        $totalSlots = (int) ve_remote_config()['max_queue_per_user'];
        $usedSlots = min($totalSlots, ve_remote_active_count_for_user((int) $user['id']));
        ve_public_api_success($auth, [
            'filecode' => ve_public_api_remote_code_from_job_id($jobId),
        ], [
            'new_title' => trim((string) ($_GET['new_title'] ?? '')),
            'total_slots' => (string) $totalSlots,
            'used_slots' => (string) $usedSlots,
        ]);
    }

    if ($path === '/api/urlupload/list') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $jobs = array_map('ve_public_api_remote_row', ve_remote_list_for_user((int) $auth['user']['id']));
        ve_public_api_success($auth, $jobs);
    }

    if ($path === '/api/urlupload/status') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));

        if ($fileCode === '') {
            ve_public_api_error($auth, 'file_code is required.');
        }

        $jobId = ve_public_api_remote_job_id_from_code($fileCode, (int) $auth['user']['id']);
        $job = $jobId > 0 ? ve_remote_get_by_id($jobId) : null;

        if (!is_array($job) || (int) ($job['user_id'] ?? 0) !== (int) $auth['user']['id'] || trim((string) ($job['deleted_at'] ?? '')) !== '') {
            ve_public_api_error($auth, 'Remote upload not found.', 404);
        }

        ve_public_api_success($auth, [ve_public_api_remote_row($job)]);
    }

    if ($path === '/api/urlupload/slots') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $userId = (int) $auth['user']['id'];
        $totalSlots = (int) ve_remote_config()['max_queue_per_user'];
        $usedSlots = min($totalSlots, ve_remote_active_count_for_user($userId));
        ve_public_api_success($auth, null, [
            'total_slots' => (string) $totalSlots,
            'used_slots' => (string) $usedSlots,
        ]);
    }

    if ($path === '/api/urlupload/actions') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $userId = (int) $auth['user']['id'];

        if (isset($_GET['restart_errors'])) {
            ve_remote_restart_error_jobs($userId);
            ve_public_api_success($auth, null, [], 'Errors restarted');
        }

        if (isset($_GET['clear_errors'])) {
            ve_remote_clear_jobs_by_status($userId, VE_REMOTE_STATUS_ERROR);
            ve_public_api_success($auth, null, [], 'Errors cleared');
        }

        if (isset($_GET['clear_all'])) {
            ve_remote_clear_all_jobs($userId);
            ve_public_api_success($auth, null, [], 'All transfers cleared');
        }

        if (isset($_GET['delete_code'])) {
            $jobId = ve_public_api_remote_job_id_from_code((string) $_GET['delete_code'], $userId);

            if ($jobId <= 0 || !ve_remote_delete_job($userId, $jobId)) {
                ve_public_api_error($auth, 'Transfer not found.', 404);
            }

            ve_public_api_success($auth, null, [], 'Transfer deleted');
        }

        ve_public_api_error($auth, 'No valid action was requested.');
    }

    if ($path === '/api/folder/create') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $name = trim((string) ($_GET['name'] ?? ''));

        if ($name === '') {
            ve_public_api_error($auth, 'name is required.');
        }

        try {
            $folder = ve_video_folder_create((int) $auth['user']['id'], (int) ($_GET['parent_id'] ?? 0), $name);
        } catch (RuntimeException $exception) {
            ve_public_api_error($auth, $exception->getMessage(), 422);
        }

        ve_public_api_success($auth, [
            'fld_id' => (string) ((int) ($folder['id'] ?? 0)),
        ]);
    }

    if ($path === '/api/folder/rename') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $folderId = (int) ($_GET['fld_id'] ?? 0);
        $name = trim((string) ($_GET['name'] ?? ''));
        $folder = ve_video_folder_get_for_user((int) $auth['user']['id'], $folderId);

        if (!is_array($folder)) {
            ve_public_api_error($auth, 'Folder not found.', 404);
        }

        if ($name === '') {
            ve_public_api_error($auth, 'name is required.');
        }

        if (ve_video_folder_name_exists((int) $auth['user']['id'], (int) ($folder['parent_id'] ?? 0), $name, (int) $folder['id'])) {
            ve_public_api_error($auth, 'A folder with that name already exists here.', 422);
        }

        ve_db()->prepare(
            'UPDATE video_folders
             SET name = :name, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        )->execute([
            ':name' => mb_substr($name, 0, 120),
            ':updated_at' => ve_now(),
            ':id' => (int) $folder['id'],
            ':user_id' => (int) $auth['user']['id'],
        ]);

        ve_public_api_success($auth, 'true');
    }

    if ($path === '/api/folder/list') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $userId = (int) $auth['user']['id'];
        $folderId = (int) ($_GET['fld_id'] ?? 0);
        $folderId = $folderId > 0 ? ve_video_normalize_folder_id($userId, $folderId) : 0;
        $folders = array_map('ve_public_api_folder_row', ve_video_folder_list_children($userId, $folderId));
        $files = [];

        if ((int) ($_GET['only_folders'] ?? 0) !== 1) {
            $result = ve_public_api_list_videos_for_user($userId, [
                'folder_id' => $folderId,
                'page' => 1,
                'per_page' => 200,
            ]);
            $files = (array) ($result['files'] ?? []);
        }

        ve_public_api_success($auth, [
            'folders' => $folders,
            'files' => $files,
        ]);
    }

    if ($path === '/api/file/list') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $options = [
            'page' => (int) ($_GET['page'] ?? 1),
            'per_page' => (int) ($_GET['per_page'] ?? 50),
            'created' => (string) ($_GET['created'] ?? ''),
        ];

        if (isset($_GET['fld_id'])) {
            $options['folder_id'] = (int) $_GET['fld_id'];
        }

        ve_public_api_success($auth, ve_public_api_list_videos_for_user((int) $auth['user']['id'], $options));
    }

    if ($path === '/api/file/check') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));

        if ($fileCode === '') {
            ve_public_api_error($auth, 'file_code is required.');
        }

        $videos = ve_public_api_fetch_user_videos_by_codes(
            (int) $auth['user']['id'],
            ve_public_api_parse_file_codes($fileCode)
        );

        if ($videos === []) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        $result = [];

        foreach ($videos as $video) {
            $result[] = [
                'status' => ve_public_api_video_status_label($video),
                'filecode' => (string) ($video['public_id'] ?? ''),
            ];
        }

        ve_public_api_success($auth, $result);
    }

    if ($path === '/api/file/info') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));

        if ($fileCode === '') {
            ve_public_api_error($auth, 'file_code is required.');
        }

        $videos = ve_public_api_fetch_user_videos_by_codes(
            (int) $auth['user']['id'],
            ve_public_api_parse_file_codes($fileCode)
        );

        if ($videos === []) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        $videoIds = array_map(static fn (array $video): int => (int) ($video['id'] ?? 0), $videos);
        $viewsMap = ve_public_api_video_views_map((int) $auth['user']['id'], $videoIds);
        $lastViewMap = ve_public_api_video_last_view_map((int) $auth['user']['id'], $videoIds);
        $result = [];

        foreach ($videos as $video) {
            $videoId = (int) ($video['id'] ?? 0);
            $result[] = ve_public_api_file_row(
                $video,
                (int) ($viewsMap[$videoId] ?? 0),
                (string) ($lastViewMap[$videoId] ?? '')
            );
        }

        ve_public_api_success($auth, $result);
    }

    if ($path === '/api/file/image') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));

        if ($fileCode === '') {
            ve_public_api_error($auth, 'file_code is required.');
        }

        $videos = ve_public_api_fetch_user_videos_by_codes(
            (int) $auth['user']['id'],
            ve_public_api_parse_file_codes($fileCode)
        );

        if ($videos === []) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        ve_public_api_success($auth, array_map('ve_public_api_file_image_row', $videos));
    }

    if ($path === '/api/file/rename') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));
        $title = trim((string) ($_GET['title'] ?? ''));
        $video = ve_video_get_for_user((int) $auth['user']['id'], $fileCode);

        if (!is_array($video)) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        if ($title === '') {
            ve_public_api_error($auth, 'title is required.');
        }

        ve_db()->prepare(
            'UPDATE videos
             SET title = :title, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        )->execute([
            ':title' => mb_substr($title, 0, 180),
            ':updated_at' => ve_now(),
            ':id' => (int) $video['id'],
            ':user_id' => (int) $auth['user']['id'],
        ]);

        ve_public_api_success($auth, 'true');
    }

    if ($path === '/api/file/move') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $fileCode = trim((string) ($_GET['file_code'] ?? ''));
        $video = ve_video_get_for_user((int) $auth['user']['id'], $fileCode);

        if (!is_array($video)) {
            ve_public_api_error($auth, 'File not found.', 404);
        }

        $folderId = ve_video_normalize_folder_id((int) $auth['user']['id'], (int) ($_GET['fld_id'] ?? 0));
        ve_db()->prepare(
            'UPDATE videos
             SET folder_id = :folder_id, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        )->execute([
            ':folder_id' => $folderId,
            ':updated_at' => ve_now(),
            ':id' => (int) $video['id'],
            ':user_id' => (int) $auth['user']['id'],
        ]);

        ve_public_api_success($auth, 'true');
    }

    if ($path === '/api/search/videos') {
        if (!ve_is_method('GET')) {
            ve_method_not_allowed(['GET']);
        }

        $auth = ve_public_api_require_auth('request');
        $searchTerm = trim((string) ($_GET['search_term'] ?? ''));

        if ($searchTerm === '') {
            ve_public_api_error($auth, 'search_term is required.');
        }

        $options = [
            'page' => (int) ($_GET['page'] ?? 1),
            'per_page' => (int) ($_GET['per_page'] ?? 50),
            'search' => $searchTerm,
        ];

        if (isset($_GET['fld_id'])) {
            $options['folder_id'] = (int) $_GET['fld_id'];
        }

        ve_public_api_success($auth, ve_public_api_list_videos_for_user((int) $auth['user']['id'], $options));
    }

    return false;
}
