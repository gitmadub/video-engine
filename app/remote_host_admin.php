<?php

declare(strict_types=1);

function ve_remote_host_catalog(): array
{
    static $catalog;

    if (is_array($catalog)) {
        return $catalog;
    }

    $catalog = [
        'youtube' => [
            'label' => 'YouTube',
            'priority' => 100,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Verified through the current yt-dlp client flow, with optional server-side cookies for anti-bot challenges.',
        ],
        'google_drive' => [
            'label' => 'Google Drive',
            'priority' => 95,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Verified with public files and the current confirm/resourcekey flow.',
        ],
        'dropbox' => [
            'label' => 'Dropbox',
            'priority' => 90,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Verified with public shared files using the raw download flow.',
        ],
        'mega' => [
            'label' => 'MEGA',
            'priority' => 85,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Verified through the bundled MEGA helper.',
        ],
        'vidi64' => [
            'label' => 'Vidi64 / WinVidPlay / Vidoy',
            'priority' => 80,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Verified with current clone domains and structure-based detection.',
        ],
        'myvidplay' => [
            'label' => 'FileHost.net / MyVidPlay / Vidoy',
            'priority' => 78,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Currently blocked by an interactive captcha or Turnstile step on the embed page.',
        ],
        'direct' => [
            'label' => 'Direct MP4 / M3U8 links',
            'priority' => 75,
            'verified' => true,
            'default_enabled' => true,
            'show_in_supported_hosts' => true,
            'note' => 'Generic direct media URLs.',
        ],
        'netu' => [
            'label' => 'Waaw / Netu / HQQ',
            'priority' => 70,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Resolver can find HLS, but the current server could not reach the upstream CDN reliably.',
        ],
        'mixdrop' => [
            'label' => 'Mixdrop',
            'priority' => 68,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'streamtape' => [
            'label' => 'Streamtape',
            'priority' => 66,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'okru' => [
            'label' => 'OK.ru',
            'priority' => 64,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Current public samples did not expose a stable direct video file.',
        ],
        'videobin' => [
            'label' => 'VideoBin',
            'priority' => 62,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'vidoza' => [
            'label' => 'Vidoza',
            'priority' => 60,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'vivo' => [
            'label' => 'Vivo',
            'priority' => 58,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'xvideos' => [
            'label' => 'Xvideos',
            'priority' => 56,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'youporn' => [
            'label' => 'YouPorn',
            'priority' => 54,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        'streamsb' => [
            'label' => 'StreamSB',
            'priority' => 52,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Current public examples land on parking or broken pages.',
        ],
        'upstream' => [
            'label' => 'Upstream',
            'priority' => 50,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Current requests are blocked by upstream anti-bot protection.',
        ],
        'vidlox' => [
            'label' => 'Vidlox',
            'priority' => 48,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Current public examples land on parking or broken pages.',
        ],
        'fembed' => [
            'label' => 'Fembed',
            'priority' => 46,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Current domains redirect to landers instead of a working player page.',
        ],
        'uptostream' => [
            'label' => 'Uptostream',
            'priority' => 44,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Not verified in the current QA pass.',
        ],
        '1fichier' => [
            'label' => '1fichier',
            'priority' => 42,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Known inactive upstream in the current implementation.',
        ],
        'uploaded' => [
            'label' => 'Uploaded',
            'priority' => 40,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Known inactive upstream in the current implementation.',
        ],
        'uptobox' => [
            'label' => 'Uptobox',
            'priority' => 38,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Known inactive upstream in the current implementation.',
        ],
        'zippyshare' => [
            'label' => 'Zippyshare',
            'priority' => 36,
            'verified' => false,
            'default_enabled' => false,
            'show_in_supported_hosts' => false,
            'note' => 'Known inactive upstream in the current implementation.',
        ],
    ];

    return $catalog;
}

function ve_remote_host_metadata(string $key): array
{
    $catalog = ve_remote_host_catalog();

    return is_array($catalog[$key] ?? null) ? $catalog[$key] : [
        'label' => ucwords(str_replace('_', ' ', $key)),
        'priority' => 0,
        'verified' => false,
        'default_enabled' => false,
        'show_in_supported_hosts' => false,
        'note' => '',
    ];
}

function ve_remote_host_label(string $key): string
{
    return (string) (ve_remote_host_metadata($key)['label'] ?? $key);
}

function ve_remote_host_default_enabled(string $key): bool
{
    return (bool) (ve_remote_host_metadata($key)['default_enabled'] ?? false);
}

function ve_remote_host_run_migrations(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS remote_host_policies (
            host_key TEXT PRIMARY KEY,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            updated_by_user_id INTEGER DEFAULT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS remote_upload_submission_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER DEFAULT NULL,
            remote_upload_id INTEGER DEFAULT NULL,
            source_url TEXT NOT NULL,
            source_host TEXT NOT NULL DEFAULT "",
            matched_host_key TEXT NOT NULL DEFAULT "",
            host_key TEXT NOT NULL DEFAULT "",
            resolved_host TEXT NOT NULL DEFAULT "",
            submission_status TEXT NOT NULL DEFAULT "queued",
            detail_message TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
            FOREIGN KEY (remote_upload_id) REFERENCES remote_uploads (id) ON DELETE SET NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_host_logs_created ON remote_upload_submission_logs(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_host_logs_source_host ON remote_upload_submission_logs(source_host, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_host_logs_matched_host ON remote_upload_submission_logs(matched_host_key, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_host_logs_host_key ON remote_upload_submission_logs(host_key, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_host_logs_remote_upload ON remote_upload_submission_logs(remote_upload_id)');
}

function ve_remote_host_manager_user_ids(): array
{
    static $ids;

    if (is_array($ids)) {
        return $ids;
    }

    $raw = trim((string) (getenv('VE_REMOTE_HOST_MANAGER_IDS') ?: ''));
    $parsed = [];

    if ($raw !== '') {
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
            $id = (int) $value;

            if ($id > 0) {
                $parsed[$id] = $id;
            }
        }
    }

    if ($parsed === []) {
        try {
            $row = ve_db()->query('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1')->fetch();

            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $parsed[(int) $row['id']] = (int) $row['id'];
            }
        } catch (Throwable $throwable) {
            $parsed = [];
        }
    }

    if ($parsed === []) {
        $parsed[1] = 1;
    }

    $ids = array_values($parsed);

    return $ids;
}

function ve_remote_host_can_manage(?array $user = null): bool
{
    if (!is_array($user)) {
        $user = ve_current_user();
    }

    if (!is_array($user)) {
        return false;
    }

    return in_array((int) ($user['id'] ?? 0), ve_remote_host_manager_user_ids(), true);
}

function ve_remote_host_require_manager(): array
{
    $user = ve_require_auth();

    if (!ve_remote_host_can_manage($user)) {
        ve_json([
            'status' => 'fail',
            'message' => 'You are not allowed to manage sitewide remote-upload hosts.',
        ], 403);
    }

    return $user;
}

function ve_remote_host_enabled_map(): array
{
    $cache = $GLOBALS['ve_remote_host_enabled_map_cache'] ?? null;

    if (is_array($cache)) {
        return $cache;
    }

    $map = [];

    foreach (ve_remote_host_catalog() as $key => $metadata) {
        $map[$key] = (bool) ($metadata['default_enabled'] ?? false);
    }

    $rows = ve_db()->query('SELECT host_key, is_enabled FROM remote_host_policies')->fetchAll();

    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = strtolower(trim((string) ($row['host_key'] ?? '')));

            if ($key === '') {
                continue;
            }

            $map[$key] = ((int) ($row['is_enabled'] ?? 0)) === 1;
        }
    }

    $GLOBALS['ve_remote_host_enabled_map_cache'] = $map;

    return $map;
}

function ve_remote_host_reset_enabled_cache(): void
{
    unset($GLOBALS['ve_remote_host_enabled_map_cache']);
}

function ve_remote_host_is_enabled(string $key): bool
{
    $map = ve_remote_host_enabled_map();

    return (bool) ($map[$key] ?? ve_remote_host_default_enabled($key));
}

function ve_remote_host_disabled_message(string $key): string
{
    return ve_remote_host_label($key) . ' is currently disabled in remote upload settings.';
}

function ve_remote_host_known_match_key(string $url): string
{
    foreach (ve_remote_hosts() as $host) {
        $key = strtolower(trim((string) ($host['key'] ?? '')));

        if ($key === '' || $key === 'direct') {
            continue;
        }

        $match = $host['match'] ?? null;

        if (is_callable($match) && $match($url) === true) {
            return $key;
        }
    }

    return '';
}

function ve_remote_host_log_submission(array $payload): int
{
    $now = ve_now();
    $stmt = ve_db()->prepare(
        'INSERT INTO remote_upload_submission_logs (
            user_id, remote_upload_id, source_url, source_host, matched_host_key, host_key,
            resolved_host, submission_status, detail_message, created_at, updated_at
        ) VALUES (
            :user_id, :remote_upload_id, :source_url, :source_host, :matched_host_key, :host_key,
            :resolved_host, :submission_status, :detail_message, :created_at, :updated_at
        )'
    );
    $stmt->execute([
        ':user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        ':remote_upload_id' => isset($payload['remote_upload_id']) ? (int) $payload['remote_upload_id'] : null,
        ':source_url' => trim((string) ($payload['source_url'] ?? '')),
        ':source_host' => trim((string) ($payload['source_host'] ?? '')),
        ':matched_host_key' => trim((string) ($payload['matched_host_key'] ?? '')),
        ':host_key' => trim((string) ($payload['host_key'] ?? '')),
        ':resolved_host' => trim((string) ($payload['resolved_host'] ?? '')),
        ':submission_status' => trim((string) ($payload['submission_status'] ?? 'queued')),
        ':detail_message' => mb_substr(trim((string) ($payload['detail_message'] ?? '')), 0, 500),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) ve_db()->lastInsertId();
}

function ve_remote_host_update_submission_log(int $logId, array $columns): void
{
    if ($logId <= 0 || $columns === []) {
        return;
    }

    $allowed = [
        'remote_upload_id',
        'matched_host_key',
        'host_key',
        'resolved_host',
        'submission_status',
        'detail_message',
    ];
    $assignments = [];
    $params = [
        ':id' => $logId,
        ':updated_at' => ve_now(),
    ];

    foreach ($columns as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }

        $placeholder = ':' . $column;
        $assignments[] = $column . ' = ' . $placeholder;

        if ($column === 'remote_upload_id') {
            $params[$placeholder] = $value === null ? null : (int) $value;
            continue;
        }

        $params[$placeholder] = mb_substr(trim((string) $value), 0, 500);
    }

    if ($assignments === []) {
        return;
    }

    $assignments[] = 'updated_at = :updated_at';
    $stmt = ve_db()->prepare(
        'UPDATE remote_upload_submission_logs
         SET ' . implode(', ', $assignments) . '
         WHERE id = :id'
    );
    $stmt->execute($params);
}

function ve_remote_host_attach_log_to_job(int $logId, int $jobId): void
{
    if ($logId <= 0 || $jobId <= 0) {
        return;
    }

    ve_remote_host_update_submission_log($logId, [
        'remote_upload_id' => $jobId,
    ]);
}

function ve_remote_host_find_log_by_job_id(int $jobId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM remote_upload_submission_logs
         WHERE remote_upload_id = :remote_upload_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':remote_upload_id' => $jobId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_remote_host_failure_status(string $message): string
{
    $message = strtolower(trim($message));

    if ($message === '') {
        return 'failed';
    }

    if (str_contains($message, 'disabled in remote upload settings')) {
        return 'disabled_host';
    }

    if (str_contains($message, 'not supported yet')) {
        return 'unsupported_host';
    }

    if (str_contains($message, 'only fully qualified http:// or https:// urls are supported')) {
        return 'invalid_url';
    }

    return 'failed';
}

function ve_remote_host_reset_job_log(int $jobId): void
{
    $log = ve_remote_host_find_log_by_job_id($jobId);

    if (!is_array($log)) {
        return;
    }

    ve_remote_host_update_submission_log((int) $log['id'], [
        'host_key' => '',
        'resolved_host' => '',
        'submission_status' => 'queued',
        'detail_message' => '',
    ]);
}

function ve_remote_host_mark_job_completed(int $jobId): void
{
    $log = ve_remote_host_find_log_by_job_id($jobId);
    $job = ve_remote_get_by_id($jobId);

    if (!is_array($log) || !is_array($job)) {
        return;
    }

    $resolvedUrl = trim((string) ($job['resolved_url'] ?? ''));
    $resolvedHost = $resolvedUrl !== '' ? ve_remote_url_host($resolvedUrl) : '';
    $hostKey = trim((string) ($job['host_key'] ?? ''));

    ve_remote_host_update_submission_log((int) $log['id'], [
        'host_key' => $hostKey !== '' ? $hostKey : (string) ($log['matched_host_key'] ?? ''),
        'resolved_host' => $resolvedHost,
        'submission_status' => 'completed',
        'detail_message' => '',
    ]);
}

function ve_remote_host_mark_job_failed(int $jobId, string $message): void
{
    $log = ve_remote_host_find_log_by_job_id($jobId);
    $job = ve_remote_get_by_id($jobId);

    if (!is_array($log)) {
        return;
    }

    $hostKey = is_array($job)
        ? trim((string) ($job['host_key'] ?? ''))
        : trim((string) ($log['matched_host_key'] ?? ''));
    $resolvedUrl = is_array($job) ? trim((string) ($job['resolved_url'] ?? '')) : '';
    $resolvedHost = $resolvedUrl !== '' ? ve_remote_url_host($resolvedUrl) : '';

    ve_remote_host_update_submission_log((int) $log['id'], [
        'host_key' => $hostKey !== '' ? $hostKey : (string) ($log['matched_host_key'] ?? ''),
        'resolved_host' => $resolvedHost,
        'submission_status' => ve_remote_host_failure_status($message),
        'detail_message' => $message,
    ]);
}

function ve_remote_host_provider_stats_map(): array
{
    $rows = ve_db()->query(
        'SELECT
            COALESCE(NULLIF(host_key, ""), NULLIF(matched_host_key, "")) AS provider_key,
            COUNT(*) AS submission_count,
            SUM(CASE WHEN submission_status = "completed" THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN submission_status = "failed" THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN submission_status = "disabled_host" THEN 1 ELSE 0 END) AS disabled_count,
            MAX(created_at) AS last_seen_at
         FROM remote_upload_submission_logs
         WHERE COALESCE(NULLIF(host_key, ""), NULLIF(matched_host_key, "")) <> ""
         GROUP BY COALESCE(NULLIF(host_key, ""), NULLIF(matched_host_key, ""))'
    )->fetchAll();

    $map = [];

    if (!is_array($rows)) {
        return $map;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = trim((string) ($row['provider_key'] ?? ''));

        if ($key === '') {
            continue;
        }

        $map[$key] = [
            'submission_count' => (int) ($row['submission_count'] ?? 0),
            'completed_count' => (int) ($row['completed_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'disabled_count' => (int) ($row['disabled_count'] ?? 0),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
        ];
    }

    return $map;
}

function ve_remote_host_unsupported_domains(): array
{
    $rows = ve_db()->query(
        'SELECT
            source_host,
            COUNT(*) AS submission_count,
            MAX(created_at) AS last_seen_at,
            MAX(detail_message) AS detail_message
         FROM remote_upload_submission_logs
         WHERE source_host <> ""
         AND COALESCE(NULLIF(host_key, ""), NULLIF(matched_host_key, ""), "") = ""
         AND submission_status IN ("unsupported_host", "failed")
         GROUP BY source_host
         ORDER BY submission_count DESC, last_seen_at DESC
         LIMIT 25'
    )->fetchAll();

    return is_array($rows) ? array_values(array_filter($rows, static fn ($row): bool => is_array($row))) : [];
}

function ve_remote_host_summary_totals(): array
{
    $row = ve_db()->query(
        'SELECT
            COUNT(*) AS total_submissions,
            SUM(CASE WHEN submission_status = "completed" THEN 1 ELSE 0 END) AS completed_submissions,
            SUM(CASE WHEN submission_status = "unsupported_host" THEN 1 ELSE 0 END) AS unsupported_submissions,
            SUM(CASE WHEN submission_status = "disabled_host" THEN 1 ELSE 0 END) AS disabled_submissions
         FROM remote_upload_submission_logs'
    )->fetch();

    if (!is_array($row)) {
        return [
            'total_submissions' => 0,
            'completed_submissions' => 0,
            'unsupported_submissions' => 0,
            'disabled_submissions' => 0,
        ];
    }

    return [
        'total_submissions' => (int) ($row['total_submissions'] ?? 0),
        'completed_submissions' => (int) ($row['completed_submissions'] ?? 0),
        'unsupported_submissions' => (int) ($row['unsupported_submissions'] ?? 0),
        'disabled_submissions' => (int) ($row['disabled_submissions'] ?? 0),
    ];
}

function ve_remote_host_supported_hosts_payload(): array
{
    $items = [];

    foreach (ve_remote_host_catalog() as $key => $metadata) {
        if (!(bool) ($metadata['show_in_supported_hosts'] ?? false) || !ve_remote_host_is_enabled($key)) {
            continue;
        }

        $items[] = [
            'key' => $key,
            'label' => (string) ($metadata['label'] ?? $key),
            'priority' => (int) ($metadata['priority'] ?? 0),
        ];
    }

    usort($items, static function (array $left, array $right): int {
        $priority = (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);

        if ($priority !== 0) {
            return $priority;
        }

        return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    return array_map(static function (array $item): array {
        unset($item['priority']);
        return $item;
    }, $items);
}

function ve_remote_host_provider_rows(): array
{
    $stats = ve_remote_host_provider_stats_map();
    $rows = [];

    foreach (ve_remote_host_catalog() as $key => $metadata) {
        $summary = is_array($stats[$key] ?? null) ? $stats[$key] : [];

        $rows[] = [
            'key' => $key,
            'label' => (string) ($metadata['label'] ?? $key),
            'verified' => (bool) ($metadata['verified'] ?? false),
            'enabled' => ve_remote_host_is_enabled($key),
            'show_in_supported_hosts' => (bool) ($metadata['show_in_supported_hosts'] ?? false),
            'note' => (string) ($metadata['note'] ?? ''),
            'submission_count' => (int) ($summary['submission_count'] ?? 0),
            'completed_count' => (int) ($summary['completed_count'] ?? 0),
            'failed_count' => (int) ($summary['failed_count'] ?? 0),
            'disabled_count' => (int) ($summary['disabled_count'] ?? 0),
            'last_seen_at' => (string) ($summary['last_seen_at'] ?? ''),
            'priority' => (int) ($metadata['priority'] ?? 0),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $priority = (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);

        if ($priority !== 0) {
            return $priority;
        }

        return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    return array_map(static function (array $row): array {
        unset($row['priority']);
        return $row;
    }, $rows);
}

function ve_remote_host_settings_menu_html(): string
{
    return <<<HTML
<li>
    <a href="#remote_upload_hosts" class="d-flex flex-wrap align-items-center">
        <i class="fad fa-cloud-download-alt"></i>
        <span>Remote Upload Hosts</span>
    </a>
</li>
HTML;
}

function ve_remote_host_provider_rows_html(array $rows): string
{
    if ($rows === []) {
        return '<tr><td colspan="10" class="text-center text-muted">No remote-upload host data recorded yet.</td></tr>';
    }

    $html = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $enabled = (bool) ($row['enabled'] ?? false);
        $verified = (bool) ($row['verified'] ?? false);
        $public = (bool) ($row['show_in_supported_hosts'] ?? false);
        $key = trim((string) ($row['key'] ?? ''));
        $lastSeen = ve_format_datetime_label((string) ($row['last_seen_at'] ?? ''), 'Never');

        $statusBits = [];
        $statusBits[] = $verified
            ? '<span class="badge badge-success">Verified</span>'
            : '<span class="badge badge-secondary">Unverified</span>';
        $statusBits[] = $public
            ? '<span class="badge badge-info">Public</span>'
            : '<span class="badge badge-dark">Hidden</span>';
        $statusBits[] = $enabled
            ? '<span class="badge badge-primary">Enabled</span>'
            : '<span class="badge badge-danger">Disabled</span>';

        $html[] = sprintf(
            '<tr>
                <td><strong>%s</strong><div class="small text-muted"><code>%s</code></div></td>
                <td>%s</td>
                <td class="text-center">%d</td>
                <td class="text-center">%d</td>
                <td class="text-center">%d</td>
                <td class="text-center">%d</td>
                <td>%s</td>
                <td>%s</td>
                <td class="text-center">
                    <div class="custom-control custom-switch d-inline-block text-left">
                        <input type="checkbox" class="custom-control-input" id="remote-host-%s" name="enabled_hosts[]" value="%s"%s>
                        <label class="custom-control-label" for="remote-host-%s"></label>
                    </div>
                </td>
            </tr>',
            ve_h((string) ($row['label'] ?? $key)),
            ve_h($key),
            implode(' ', $statusBits),
            (int) ($row['submission_count'] ?? 0),
            (int) ($row['completed_count'] ?? 0),
            (int) ($row['failed_count'] ?? 0),
            (int) ($row['disabled_count'] ?? 0),
            ve_h($lastSeen),
            ve_h((string) ($row['note'] ?? '')),
            ve_h($key),
            ve_h($key),
            $enabled ? ' checked="checked"' : '',
            ve_h($key)
        );
    }

    return implode("\n", $html);
}

function ve_remote_host_unsupported_rows_html(array $rows): string
{
    if ($rows === []) {
        return '<tr><td colspan="4" class="text-center text-muted">No unsupported domains have been submitted yet.</td></tr>';
    }

    $html = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $html[] = sprintf(
            '<tr><td><code>%s</code></td><td class="text-center">%d</td><td>%s</td><td>%s</td></tr>',
            ve_h((string) ($row['source_host'] ?? '')),
            (int) ($row['submission_count'] ?? 0),
            ve_h(ve_format_datetime_label((string) ($row['last_seen_at'] ?? ''), 'Never')),
            ve_h((string) ($row['detail_message'] ?? ''))
        );
    }

    return implode("\n", $html);
}

function ve_remote_host_dashboard_snapshot(?array $user = null): array
{
    if (!is_array($user)) {
        $user = ve_current_user();
    }

    $snapshot = [
        'can_manage' => ve_remote_host_can_manage($user),
        'supported_hosts' => ve_remote_host_supported_hosts_payload(),
        'supported_hosts_note' => 'Mirror sites and domains are also supported.',
        'totals' => [
            'total_submissions' => 0,
            'completed_submissions' => 0,
            'unsupported_submissions' => 0,
            'disabled_submissions' => 0,
        ],
        'provider_rows' => [],
        'unsupported_rows' => [],
        'provider_rows_html' => '',
        'unsupported_rows_html' => '',
        'panel_html' => '',
    ];

    if (!$snapshot['can_manage']) {
        return $snapshot;
    }

    $snapshot['totals'] = ve_remote_host_summary_totals();
    $snapshot['provider_rows'] = ve_remote_host_provider_rows();
    $snapshot['unsupported_rows'] = ve_remote_host_unsupported_domains();
    $snapshot['provider_rows_html'] = ve_remote_host_provider_rows_html($snapshot['provider_rows']);
    $snapshot['unsupported_rows_html'] = ve_remote_host_unsupported_rows_html($snapshot['unsupported_rows']);
    $snapshot['panel_html'] = ve_render_remote_host_settings_panel($snapshot);

    return $snapshot;
}

function ve_render_remote_host_settings_panel(array $snapshot): string
{
    if (!(bool) ($snapshot['can_manage'] ?? false)) {
        return '';
    }

    $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
    $providerRowsHtml = (string) ($snapshot['provider_rows_html'] ?? '');
    $unsupportedRowsHtml = (string) ($snapshot['unsupported_rows_html'] ?? '');

    $html = <<<HTML
<div class="data settings-panel" id="remote_upload_hosts">
    <div class="settings-panel-title">Remote upload hosts</div>
    <p class="settings-panel-subtitle">Control which remote-upload providers are live, inspect real usage, and identify unsupported domains users keep submitting.</p>

    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3">
            <div class="the_box text-center h-100">
                <div class="text-muted mb-2">Total submissions</div>
                <div class="h3 mb-0" data-remote-host-card="total_submissions">{$totals['total_submissions']}</div>
                <small class="text-muted">All entered URLs</small>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="the_box text-center h-100">
                <div class="text-muted mb-2">Completed</div>
                <div class="h3 mb-0" data-remote-host-card="completed_submissions">{$totals['completed_submissions']}</div>
                <small class="text-muted">Successful imports</small>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="the_box text-center h-100">
                <div class="text-muted mb-2">Unsupported</div>
                <div class="h3 mb-0" data-remote-host-card="unsupported_submissions">{$totals['unsupported_submissions']}</div>
                <small class="text-muted">Unknown hosts/domains</small>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="the_box text-center h-100">
                <div class="text-muted mb-2">Disabled hits</div>
                <div class="h3 mb-0" data-remote-host-card="disabled_submissions">{$totals['disabled_submissions']}</div>
                <small class="text-muted">Blocked by policy</small>
            </div>
        </div>
    </div>

    <div class="alert alert-info mb-4">
        End users only see enabled providers that are currently verified. Mirror sites and domains are also supported.
    </div>

    <form method="POST">
        <input type="hidden" name="op" value="remote_upload_hosts">

        <div class="settings-table-wrap">
            <table class="table is-fullwidth">
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Status</th>
                        <th class="text-center">Submitted</th>
                        <th class="text-center">Completed</th>
                        <th class="text-center">Failed</th>
                        <th class="text-center">Disabled</th>
                        <th>Last seen</th>
                        <th>Notes</th>
                        <th class="text-center">Enabled</th>
                    </tr>
                </thead>
                <tbody id="remote-host-provider-rows">
                    {$providerRowsHtml}
                </tbody>
            </table>
        </div>

        <div class="mt-3 text-right">
            <button type="button" class="btn btn-secondary mr-2" id="refreshRemoteUploadHosts">Refresh usage</button>
            <button type="submit" class="btn btn-primary">
                Save host policy <i class="fad fa-check ml-2"></i>
            </button>
        </div>
    </form>

    <div class="settings-table-wrap mt-4">
        <table class="table is-fullwidth">
            <thead>
                <tr>
                    <th>Unsupported domain</th>
                    <th class="text-center">Submitted</th>
                    <th>Last seen</th>
                    <th>Latest error</th>
                </tr>
            </thead>
            <tbody id="remote-host-unsupported-rows">
                {$unsupportedRowsHtml}
            </tbody>
        </table>
    </div>
</div>
HTML;

    if (function_exists('ve_settings_bind_form')) {
        $html = ve_settings_bind_form($html, 'remote_upload_hosts', '/account/remote-upload');
    }

    return $html;
}

function ve_remote_host_persist_settings(array $enabledHostKeys, int $userId): void
{
    $catalog = ve_remote_host_catalog();
    $enabledMap = [];

    foreach ($enabledHostKeys as $value) {
        $key = strtolower(trim((string) $value));

        if ($key !== '' && isset($catalog[$key])) {
            $enabledMap[$key] = true;
        }
    }

    $stmt = ve_db()->prepare(
        'INSERT INTO remote_host_policies (host_key, is_enabled, updated_by_user_id, updated_at)
         VALUES (:host_key, :is_enabled, :updated_by_user_id, :updated_at)
         ON CONFLICT(host_key) DO UPDATE SET
            is_enabled = excluded.is_enabled,
            updated_by_user_id = excluded.updated_by_user_id,
            updated_at = excluded.updated_at'
    );
    $updatedAt = ve_now();

    foreach ($catalog as $key => $_metadata) {
        $stmt->execute([
            ':host_key' => $key,
            ':is_enabled' => isset($enabledMap[$key]) ? 1 : 0,
            ':updated_by_user_id' => $userId,
            ':updated_at' => $updatedAt,
        ]);
    }

    ve_remote_host_reset_enabled_cache();
}

function ve_save_remote_host_settings(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());

    if (!ve_remote_host_can_manage(ve_current_user())) {
        ve_json([
            'status' => 'fail',
            'message' => 'You are not allowed to manage sitewide remote-upload hosts.',
        ], 403);
    }

    $enabledHostKeys = is_array($_POST['enabled_hosts'] ?? null) ? (array) $_POST['enabled_hosts'] : [];
    ve_remote_host_persist_settings($enabledHostKeys, $userId);

    ve_add_notification($userId, 'Remote upload hosts updated', 'Sitewide remote-upload host policies were updated.');

    ve_success_form_submission('Remote upload host policy saved successfully.', '/dashboard/settings#remote_upload_hosts', [
        'remote_upload' => ve_remote_host_dashboard_snapshot(ve_current_user(true)),
    ]);
}
