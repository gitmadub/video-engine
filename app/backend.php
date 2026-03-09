<?php

declare(strict_types=1);

require_once __DIR__ . '/remote_host_admin.php';

const VE_SESSION_USER_ID = 've_user_id';
const VE_SESSION_FLASH = 've_flash';
const VE_SESSION_CSRF = 've_csrf';

function ve_storage_path(string ...$parts): string
{
    return ve_root_path('storage', ...$parts);
}

function ve_player_storage_path(string ...$parts): string
{
    return ve_storage_path('private', 'player', ...$parts);
}

function ve_storage_relative_path_to_absolute(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    if ($relativePath === '') {
        return '';
    }

    $parts = array_values(array_filter(
        explode('/', $relativePath),
        static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..'
    ));

    if ($parts === []) {
        return '';
    }

    return ve_storage_path(...$parts);
}

function ve_ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function ve_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ve_timestamp(): int
{
    return time();
}

function ve_config(): array
{
    static $config;

    if (is_array($config)) {
        return $config;
    }

    $storagePath = ve_storage_path();
    ve_ensure_directory($storagePath);
    ve_ensure_directory(ve_storage_path('uploads'));
    ve_ensure_directory(ve_storage_path('uploads', 'logos'));
    ve_ensure_directory(ve_storage_path('private'));
    ve_ensure_directory(ve_player_storage_path());
    ve_ensure_directory(ve_player_storage_path('splashes'));

    $config = [
        'db_dsn' => getenv('VE_DB_DSN') ?: 'sqlite:' . str_replace('\\', '/', ve_storage_path('video-engine.sqlite')),
        'custom_domain_target' => trim((string) (getenv('VE_CUSTOM_DOMAIN_TARGET') ?: '208.73.202.233')),
        'app_key_path' => ve_storage_path('app.key'),
    ];

    return $config;
}

function ve_bootstrap(): void
{
    ve_start_session();
    ve_db();
    ve_touch_session_record();
}

function ve_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('ve_session');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => ve_base_path() !== '' ? ve_base_path() . '/' : '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function ve_request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function ve_client_ip(): string
{
    $candidates = [];

    foreach ([
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
    ] as $value) {
        if (is_string($value) && trim($value) !== '') {
            $candidates[] = trim($value);
        }
    }

    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($forwardedFor !== '') {
        foreach (explode(',', $forwardedFor) as $value) {
            $value = trim($value);

            if ($value !== '') {
                $candidates[] = $value;
            }
        }
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($remoteAddr !== '') {
        $candidates[] = $remoteAddr;
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    return '';
}

function ve_is_method(string $method): bool
{
    return ve_request_method() === strtoupper($method);
}

function ve_method_not_allowed(array $allowedMethods): void
{
    http_response_code(405);
    header('Allow: ' . implode(', ', $allowedMethods));

    if (str_starts_with((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || str_starts_with(ve_request_path(), '/api/')) {
        ve_json([
            'status' => 'fail',
            'message' => 'Method not allowed.',
        ], 405);
    }

    ve_html('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Method Not Allowed</title></head><body><h1>405</h1><p>Method not allowed.</p></body></html>', 405);
}

function ve_db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = ve_config();
    $pdo = new PDO($config['db_dsn']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    ve_initialize_database($pdo);

    return $pdo;
}

function ve_initialize_database(PDO $pdo): void
{
    $schema = (string) file_get_contents(ve_root_path('database', 'schema.sql'));

    foreach (ve_schema_statements($schema, false) as $statement) {
        $pdo->exec($statement);
    }

    ve_run_database_migrations($pdo);

    foreach (ve_schema_statements($schema, true) as $statement) {
        $pdo->exec($statement);
    }

    ve_seed_ftp_servers($pdo);
}

function ve_schema_statements(string $schema, bool $indexesOnly): array
{
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
    $filtered = [];

    foreach ($statements as $statement) {
        $statement = trim($statement);

        if ($statement === '') {
            continue;
        }

        $isIndexStatement = preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $statement) === 1;

        if ($indexesOnly !== $isIndexStatement) {
            continue;
        }

        $filtered[] = $statement;
    }

    return $filtered;
}

function ve_table_columns(PDO $pdo, string $table): array
{
    $quotedTable = str_replace("'", "''", $table);
    $columns = $pdo->query("PRAGMA table_info('{$quotedTable}')")->fetchAll();

    if (!is_array($columns)) {
        return [];
    }

    return array_map(
        static fn (array $column): string => (string) ($column['name'] ?? ''),
        array_filter($columns, static fn ($column): bool => is_array($column))
    );
}

function ve_table_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, ve_table_columns($pdo, $table), true);
}

function ve_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (ve_table_has_column($pdo, $table, $column)) {
        return;
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

/**
 * @return array<string, string>
 */
function ve_default_app_settings(): array
{
    return [
        'video_payable_min_watch_seconds' => (string) max(5, (int) (getenv('VE_VIDEO_PAYABLE_MIN_WATCH_SECONDS') ?: 30)),
        'video_payable_max_views_per_viewer_per_day' => (string) max(0, (int) (getenv('VE_VIDEO_PAYABLE_MAX_VIEWS_PER_VIEWER_PER_DAY') ?: 1)),
    ];
}

function ve_seed_default_app_settings(PDO $pdo): void
{
    $defaults = ve_default_app_settings();

    if ($defaults === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)'
    );
    $updatedAt = ve_now();

    foreach ($defaults as $settingKey => $settingValue) {
        $stmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => $settingValue,
            ':updated_at' => $updatedAt,
        ]);
    }
}

function ve_get_app_setting(string $settingKey, ?string $defaultValue = null): ?string
{
    try {
        $stmt = ve_db()->prepare(
            'SELECT setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1'
        );
        $stmt->execute([':setting_key' => $settingKey]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return $defaultValue;
    }

    if (!is_string($value)) {
        return $defaultValue;
    }

    return $value;
}

function ve_get_app_setting_int(string $settingKey, int $defaultValue, int $minValue = 0, int $maxValue = PHP_INT_MAX): int
{
    $rawValue = ve_get_app_setting($settingKey, (string) $defaultValue);

    if (!is_string($rawValue) || !preg_match('/^-?\d+$/', trim($rawValue))) {
        return max($minValue, min($maxValue, $defaultValue));
    }

    return max($minValue, min($maxValue, (int) $rawValue));
}

function ve_set_app_setting(string $settingKey, string $settingValue): void
{
    ve_db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at'
    )->execute([
        ':setting_key' => $settingKey,
        ':setting_value' => $settingValue,
        ':updated_at' => ve_now(),
    ]);
}

function ve_run_database_migrations(PDO $pdo): void
{
    ve_add_column_if_missing($pdo, 'users', 'plan_code', "TEXT NOT NULL DEFAULT 'free'");
    ve_add_column_if_missing($pdo, 'users', 'premium_until', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'referral_code', 'TEXT COLLATE NOCASE DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'referred_by_user_id', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'referred_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'api_key_hash', "TEXT NOT NULL DEFAULT ''");
    ve_add_column_if_missing($pdo, 'users', 'api_key_last_rotated_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'api_key_last_used_at', 'TEXT DEFAULT NULL');

    ve_add_column_if_missing($pdo, 'user_settings', 'api_enabled', 'INTEGER NOT NULL DEFAULT 1');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_requests_per_hour', 'INTEGER NOT NULL DEFAULT 250');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_requests_per_day', 'INTEGER NOT NULL DEFAULT 5000');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_uploads_per_day', 'INTEGER NOT NULL DEFAULT 25');
    ve_add_column_if_missing($pdo, 'user_settings', 'splash_image_path', "TEXT NOT NULL DEFAULT ''");

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_request_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            api_key_hash TEXT NOT NULL,
            auth_type TEXT NOT NULL DEFAULT "api_key",
            request_kind TEXT NOT NULL DEFAULT "request",
            endpoint TEXT NOT NULL,
            http_method TEXT NOT NULL,
            status_code INTEGER NOT NULL,
            bytes_in INTEGER NOT NULL DEFAULT 0,
            client_ip TEXT NOT NULL DEFAULT "",
            user_agent TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS account_balance_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            entry_type TEXT NOT NULL,
            source_type TEXT NOT NULL,
            source_key TEXT NOT NULL,
            amount_micro_usd INTEGER NOT NULL,
            description TEXT NOT NULL,
            metadata_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS dmca_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            case_code TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            video_id INTEGER DEFAULT NULL,
            source_type TEXT NOT NULL DEFAULT "email",
            status TEXT NOT NULL DEFAULT "pending_review",
            complainant_name TEXT NOT NULL,
            complainant_company TEXT NOT NULL DEFAULT "",
            complainant_email TEXT NOT NULL DEFAULT "",
            complainant_phone TEXT NOT NULL DEFAULT "",
            complainant_address TEXT NOT NULL DEFAULT "",
            complainant_country TEXT NOT NULL DEFAULT "",
            claimed_work TEXT NOT NULL,
            work_reference_url TEXT NOT NULL DEFAULT "",
            reported_url TEXT NOT NULL,
            evidence_urls_json TEXT NOT NULL DEFAULT "[]",
            notes TEXT NOT NULL DEFAULT "",
            signature_name TEXT NOT NULL DEFAULT "",
            effective_at TEXT DEFAULT NULL,
            content_disabled_at TEXT DEFAULT NULL,
            counter_notice_submitted_at TEXT DEFAULT NULL,
            restoration_earliest_at TEXT DEFAULT NULL,
            restoration_latest_at TEXT DEFAULT NULL,
            resolved_at TEXT DEFAULT NULL,
            video_is_public_before_action INTEGER DEFAULT NULL,
            video_status_message_before_action TEXT NOT NULL DEFAULT "",
            response_submitted_at TEXT DEFAULT NULL,
            uploader_response_json TEXT NOT NULL DEFAULT "",
            auto_delete_at TEXT DEFAULT NULL,
            video_deleted_at TEXT DEFAULT NULL,
            video_title_snapshot TEXT NOT NULL DEFAULT "",
            video_public_id_snapshot TEXT NOT NULL DEFAULT "",
            received_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS dmca_notice_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notice_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY (notice_id) REFERENCES dmca_notices (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS dmca_counter_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notice_id INTEGER NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "submitted",
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            address_line TEXT NOT NULL,
            city TEXT NOT NULL,
            country TEXT NOT NULL,
            postal_code TEXT NOT NULL DEFAULT "",
            removed_material_location TEXT NOT NULL,
            mistake_statement TEXT NOT NULL,
            jurisdiction_statement TEXT NOT NULL,
            signature_name TEXT NOT NULL,
            submitted_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (notice_id) REFERENCES dmca_notices (id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            purchase_type TEXT NOT NULL,
            package_id TEXT NOT NULL,
            package_title TEXT NOT NULL,
            payment_method TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            amount_micro_usd INTEGER NOT NULL,
            bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
            plan_interval_spec TEXT NOT NULL DEFAULT "",
            crypto_currency_code TEXT NOT NULL DEFAULT "",
            crypto_currency_name TEXT NOT NULL DEFAULT "",
            crypto_amount TEXT NOT NULL DEFAULT "",
            crypto_address TEXT NOT NULL DEFAULT "",
            payment_uri TEXT NOT NULL DEFAULT "",
            qr_url TEXT NOT NULL DEFAULT "",
            metadata_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS remote_uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            source_url TEXT NOT NULL,
            normalized_url TEXT NOT NULL DEFAULT "",
            resolved_url TEXT NOT NULL DEFAULT "",
            host_key TEXT NOT NULL DEFAULT "",
            folder_id INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "pending",
            status_message TEXT NOT NULL DEFAULT "",
            error_message TEXT NOT NULL DEFAULT "",
            original_filename TEXT NOT NULL DEFAULT "",
            content_type TEXT NOT NULL DEFAULT "",
            bytes_downloaded INTEGER NOT NULL DEFAULT 0,
            bytes_total INTEGER NOT NULL DEFAULT 0,
            speed_bytes_per_second INTEGER NOT NULL DEFAULT 0,
            progress_percent REAL NOT NULL DEFAULT 0,
            attempt_count INTEGER NOT NULL DEFAULT 0,
            video_id INTEGER DEFAULT NULL,
            video_public_id TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            started_at TEXT DEFAULT NULL,
            completed_at TEXT DEFAULT NULL,
            deleted_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_stats_daily (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            stat_date TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            earned_micro_usd INTEGER NOT NULL DEFAULT 0,
            referral_earned_micro_usd INTEGER NOT NULL DEFAULT 0,
            bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS video_stats_daily (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            video_id INTEGER NOT NULL,
            stat_date TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            earned_micro_usd INTEGER NOT NULL DEFAULT 0,
            bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS video_folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            parent_id INTEGER NOT NULL DEFAULT 0,
            public_code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            deleted_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS video_view_qualifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            playback_session_id INTEGER NOT NULL UNIQUE,
            video_id INTEGER NOT NULL,
            owner_user_id INTEGER NOT NULL,
            viewer_user_id INTEGER DEFAULT NULL,
            viewer_ip_address TEXT NOT NULL DEFAULT "",
            viewer_ip_hash TEXT NOT NULL,
            viewer_user_agent_hash TEXT NOT NULL,
            viewer_identity_type TEXT NOT NULL DEFAULT "ip",
            viewer_identity_hash TEXT NOT NULL,
            watched_seconds INTEGER NOT NULL DEFAULT 0,
            minimum_watch_seconds INTEGER NOT NULL DEFAULT 30,
            stat_date TEXT NOT NULL,
            is_payable INTEGER NOT NULL DEFAULT 0,
            payable_rank INTEGER NOT NULL DEFAULT 0,
            qualified_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (playback_session_id) REFERENCES video_playback_sessions (id) ON DELETE CASCADE,
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE,
            FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (viewer_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS video_download_grants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            video_id INTEGER NOT NULL,
            viewer_user_id INTEGER DEFAULT NULL,
            session_id_hash TEXT NOT NULL,
            request_token_hash TEXT NOT NULL UNIQUE,
            download_token_hash TEXT DEFAULT NULL UNIQUE,
            ip_hash TEXT NOT NULL,
            user_agent_hash TEXT NOT NULL,
            wait_seconds INTEGER NOT NULL DEFAULT 0,
            available_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            issued_at TEXT DEFAULT NULL,
            used_at TEXT DEFAULT NULL,
            revoked_at TEXT DEFAULT NULL,
            FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE,
            FOREIGN KEY (viewer_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );

    ve_add_column_if_missing($pdo, 'videos', 'folder_id', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'videos', 'is_public', 'INTEGER NOT NULL DEFAULT 1');
    ve_add_column_if_missing($pdo, 'videos', 'dmca_hold_count', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'videos', 'dmca_original_is_public', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'videos', 'dmca_original_status_message', 'TEXT NOT NULL DEFAULT ""');

    ve_add_column_if_missing($pdo, 'remote_uploads', 'normalized_url', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'resolved_url', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'host_key', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'folder_id', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'status', 'TEXT NOT NULL DEFAULT "pending"');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'status_message', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'error_message', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'original_filename', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'content_type', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'bytes_downloaded', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'bytes_total', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'speed_bytes_per_second', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'progress_percent', 'REAL NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'attempt_count', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'video_id', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'video_public_id', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'created_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'updated_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'started_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'completed_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'remote_uploads', 'deleted_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'complainant_company', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'complainant_email', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'complainant_phone', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'complainant_address', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'complainant_country', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'work_reference_url', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'evidence_urls_json', 'TEXT NOT NULL DEFAULT "[]"');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'notes', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'signature_name', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'effective_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'content_disabled_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'counter_notice_submitted_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'restoration_earliest_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'restoration_latest_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'resolved_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'video_is_public_before_action', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'video_status_message_before_action', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'response_submitted_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'uploader_response_json', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'auto_delete_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'video_deleted_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'video_title_snapshot', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'video_public_id_snapshot', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'dmca_notices', 'updated_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'user_stats_daily', 'referral_earned_micro_usd', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'user_stats_daily', 'premium_bandwidth_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_stats_daily', 'premium_bandwidth_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'client_proof_key', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'playback_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'previous_playback_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'pulse_client_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'pulse_server_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'previous_pulse_client_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'previous_pulse_server_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'pulse_sequence', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'pulse_count', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'last_pulse_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'last_pulse_watched_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'last_pulse_bandwidth_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'playback_started_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'bandwidth_bytes_served', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'uses_premium_bandwidth', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_playback_sessions', 'premium_bandwidth_bytes_served', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'viewer_user_id', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'session_id_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'request_token_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'download_token_hash', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'ip_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'user_agent_hash', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'wait_seconds', 'INTEGER NOT NULL DEFAULT 0');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'available_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'expires_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'created_at', 'TEXT NOT NULL DEFAULT ""');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'issued_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'used_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'video_download_grants', 'revoked_at', 'TEXT DEFAULT NULL');

    $users = $pdo->query('SELECT id, api_key_encrypted, created_at, updated_at, api_key_hash, api_key_last_rotated_at FROM users')->fetchAll();

    if (is_array($users)) {
        $update = $pdo->prepare(
            'UPDATE users
             SET api_key_encrypted = :api_key_encrypted,
                 api_key_hash = :api_key_hash,
                 api_key_last_rotated_at = :api_key_last_rotated_at
             WHERE id = :id'
        );

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $rawKey = ve_decrypt_string((string) ($user['api_key_encrypted'] ?? ''));

            if ($rawKey === '') {
                $rawKey = ve_generate_api_key();
            }

            $rotatedAt = (string) ($user['api_key_last_rotated_at'] ?? '');

            if ($rotatedAt === '') {
                $rotatedAt = (string) ($user['updated_at'] ?: $user['created_at'] ?: ve_now());
            }

            $update->execute([
                ':api_key_encrypted' => ve_encrypt_string($rawKey),
                ':api_key_hash' => ve_api_key_hash($rawKey),
                ':api_key_last_rotated_at' => $rotatedAt,
                ':id' => (int) $user['id'],
            ]);
        }
    }

    if (function_exists('ve_referrals_run_database_migrations')) {
        ve_referrals_run_database_migrations($pdo);
    }

    ve_remote_host_run_migrations($pdo);
    ve_seed_default_app_settings($pdo);

    if (function_exists('ve_admin_run_migrations')) {
        ve_admin_run_migrations($pdo);
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_api_key_hash ON users(api_key_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_app_settings_updated_at ON app_settings(updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dmca_notices_user_received ON dmca_notices(user_id, received_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dmca_notices_user_status_received ON dmca_notices(user_id, status, received_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dmca_notices_video_status ON dmca_notices(video_id, status, received_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dmca_notices_effective_at ON dmca_notices(user_id, effective_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dmca_notice_events_notice_created ON dmca_notice_events(notice_id, created_at ASC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_created ON api_request_logs(user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_kind_created ON api_request_logs(user_id, request_kind, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_account_balance_ledger_user_created ON account_balance_ledger(user_id, created_at DESC)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_account_balance_ledger_source_entry ON account_balance_ledger(source_type, source_key, entry_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_premium_orders_user_created ON premium_orders(user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_premium_orders_status_created ON premium_orders(status, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_view_qualifications_owner_date ON video_view_qualifications(owner_user_id, stat_date DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_view_qualifications_identity_date ON video_view_qualifications(owner_user_id, viewer_identity_hash, stat_date DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_view_qualifications_video_date ON video_view_qualifications(video_id, stat_date DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_download_grants_video_expiry ON video_download_grants(video_id, expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_download_grants_session_created ON video_download_grants(session_id_hash, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_folders_user_parent_name ON video_folders(user_id, parent_id, name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_folders_user_parent_created ON video_folders(user_id, parent_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_videos_user_folder_created ON videos(user_id, folder_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_uploads_user_created ON remote_uploads(user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remote_uploads_status_created ON remote_uploads(status, created_at ASC)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_user_stats_daily_user_date ON user_stats_daily(user_id, stat_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_stats_daily_date ON user_stats_daily(stat_date)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_video_stats_daily_video_date ON video_stats_daily(video_id, stat_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_stats_daily_date ON video_stats_daily(stat_date)');
}

function ve_seed_ftp_servers(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ftp_servers')->fetchColumn();

    if ($count > 0) {
        return;
    }

    $servers = [
        ['ftp.doodstream.com', 'Global', 'WW'],
        ['ftp-uk-eri-1.doodstream.com', 'United Kingdom (Erith)', 'UK'],
        ['ftp-fr-sbg-1.doodstream.com', 'France (Strasbourg)', 'FR'],
        ['ftp-fr-gra-1.doodstream.com', 'France (Gravelines)', 'FR'],
        ['ftp-fr-rbx-1.doodstream.com', 'France (Roubaix)', 'FR'],
        ['ftp-pol-waw-1.doodstream.com', 'Poland (Warsaw)', 'PL'],
    ];

    $stmt = $pdo->prepare('INSERT INTO ftp_servers (hostname, location_name, flag_code, status) VALUES (:hostname, :location_name, :flag_code, :status)');

    foreach ($servers as [$hostname, $locationName, $flagCode]) {
        $stmt->execute([
            ':hostname' => $hostname,
            ':location_name' => $locationName,
            ':flag_code' => $flagCode,
            ':status' => 'online',
        ]);
    }
}

function ve_list_ftp_servers(): array
{
    $stmt = ve_db()->query('SELECT hostname, location_name, flag_code, status FROM ftp_servers ORDER BY id ASC');
    $servers = $stmt->fetchAll();

    return is_array($servers) ? $servers : [];
}

function ve_render_ftp_servers_rows(): string
{
    $rows = [];

    foreach (ve_list_ftp_servers() as $server) {
        $isOnline = (string) ($server['status'] ?? '') === 'online';
        $flagCode = strtoupper((string) ($server['flag_code'] ?? 'WW'));
        $flagPath = ve_url('/assets/img/flags/' . rawurlencode($flagCode) . '.svg');

        $rows[] = sprintf(
            '<tr><td><i class="fad fa-circle" style="color:%s;"></i></td><td>%s</td><td><img src="%s" class="country">%s</td></tr>',
            $isOnline ? '#019001' : '#dc3545',
            ve_h((string) ($server['hostname'] ?? '')),
            ve_h($flagPath),
            ve_h((string) ($server['location_name'] ?? ''))
        );
    }

    return implode("\n", $rows);
}

function ve_app_secret(): string
{
    static $secret;

    if (is_string($secret)) {
        return $secret;
    }

    $envSecret = getenv('VE_APP_KEY');

    if (is_string($envSecret) && $envSecret !== '') {
        $secret = hash('sha256', $envSecret, true);
        return $secret;
    }

    $path = ve_config()['app_key_path'];

    if (!is_file($path)) {
        file_put_contents($path, bin2hex(random_bytes(32)));
    }

    $secret = hash('sha256', trim((string) file_get_contents($path)), true);

    return $secret;
}

function ve_encrypt_string(string $value): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', ve_app_secret(), OPENSSL_RAW_DATA, $iv);

    if ($cipher === false) {
        throw new RuntimeException('Unable to encrypt application secret.');
    }

    return base64_encode($iv . $cipher);
}

function ve_decrypt_string(string $value): string
{
    $payload = base64_decode($value, true);

    if ($payload === false || strlen($payload) < 17) {
        return '';
    }

    $iv = substr($payload, 0, 16);
    $cipher = substr($payload, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', ve_app_secret(), OPENSSL_RAW_DATA, $iv);

    return $plain === false ? '' : $plain;
}

function ve_flash(string $type, string $message): void
{
    $_SESSION[VE_SESSION_FLASH] = [
        'type' => $type,
        'message' => $message,
    ];
}

function ve_pull_flash(): ?array
{
    $flash = $_SESSION[VE_SESSION_FLASH] ?? null;
    unset($_SESSION[VE_SESSION_FLASH]);

    return is_array($flash) ? $flash : null;
}

function ve_csrf_token(): string
{
    $token = $_SESSION[VE_SESSION_CSRF] ?? null;

    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION[VE_SESSION_CSRF] = $token;
    }

    return $token;
}

function ve_require_csrf(?string $token): void
{
    if (!is_string($token) || $token === '' || !hash_equals(ve_csrf_token(), $token)) {
        if (ve_request_expects_json()) {
            ve_json([
                'status' => 'fail',
                'message' => 'Your session token is invalid. Refresh the page and try again.',
            ], 419);
        }

        ve_flash('danger', 'Your session token is invalid. Refresh the page and try again.');
        ve_back_redirect(ve_url('/dashboard/settings'));
    }
}

function ve_request_csrf_token(): ?string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $requestToken = $_POST['token'] ?? null;

    return is_string($requestToken) ? $requestToken : null;
}

function ve_request_expects_json(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $path = ve_request_path();

    return $requestedWith === 'xmlhttprequest'
        || str_contains($accept, 'application/json')
        || str_starts_with($path, '/account/')
        || str_starts_with($path, '/api/');
}

function ve_fail_form_submission(string $message, string $redirectPath, int $status = 422): void
{
    if (ve_request_expects_json()) {
        ve_json([
            'status' => 'fail',
            'message' => $message,
        ], $status);
    }

    ve_flash('danger', $message);
    ve_redirect($redirectPath);
}

/**
 * @param array<string, mixed> $payload
 */
function ve_success_form_submission(string $message, string $redirectPath, array $payload = []): void
{
    if (ve_request_expects_json()) {
        ve_json(array_merge([
            'status' => 'ok',
            'message' => $message,
        ], $payload));
    }

    ve_flash('success', $message);
    ve_redirect($redirectPath);
}

function ve_random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function ve_notification_date(string $timestamp): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));

    if (!$date instanceof DateTimeImmutable) {
        return $timestamp;
    }

    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y g:i A');
}

function ve_validate_username(string $username): ?string
{
    if (strlen($username) < 4) {
        return 'Username must be at least 4 characters.';
    }

    if (strlen($username) > 32) {
        return 'Username must be 32 characters or less.';
    }

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $username)) {
        return 'Username can only contain letters, numbers, dashes, and underscores.';
    }

    return null;
}

function ve_validate_password(string $password, string $confirmation): ?string
{
    if (strlen($password) < 4) {
        return 'Password must be at least 4 characters.';
    }

    if (strlen($password) > 64) {
        return 'Password must be 64 characters or less.';
    }

    if ($password !== $confirmation) {
        return 'Passwords do not match.';
    }

    return null;
}

function ve_validate_email(string $email): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }

    return null;
}

function ve_create_default_settings(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO user_settings (
            user_id, payment_method, payment_id, ads_mode, uploader_type, embed_domains,
            embed_access_only, disable_download, disable_adblock, extract_subtitles,
            show_embed_title, auto_subtitle_start, player_image_mode, player_colour,
            embed_width, embed_height, logo_path, splash_image_path, vast_url, pop_type, pop_url, pop_cap,
            api_enabled, api_requests_per_hour, api_requests_per_day, api_uploads_per_day, updated_at
        ) VALUES (
            :user_id, :payment_method, :payment_id, :ads_mode, :uploader_type, :embed_domains,
            :embed_access_only, :disable_download, :disable_adblock, :extract_subtitles,
            :show_embed_title, :auto_subtitle_start, :player_image_mode, :player_colour,
            :embed_width, :embed_height, :logo_path, :splash_image_path, :vast_url, :pop_type, :pop_url, :pop_cap,
            :api_enabled, :api_requests_per_hour, :api_requests_per_day, :api_uploads_per_day, :updated_at
        )'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':payment_method' => 'Webmoney',
        ':payment_id' => '',
        ':ads_mode' => '',
        ':uploader_type' => '0',
        ':embed_domains' => json_encode([], JSON_UNESCAPED_SLASHES),
        ':embed_access_only' => 0,
        ':disable_download' => 0,
        ':disable_adblock' => 0,
        ':extract_subtitles' => 0,
        ':show_embed_title' => 0,
        ':auto_subtitle_start' => 0,
        ':player_image_mode' => '',
        ':player_colour' => 'ff9900',
        ':embed_width' => 600,
        ':embed_height' => 480,
        ':logo_path' => '',
        ':splash_image_path' => '',
        ':vast_url' => '',
        ':pop_type' => '1',
        ':pop_url' => '',
        ':pop_cap' => 0,
        ':api_enabled' => 1,
        ':api_requests_per_hour' => 250,
        ':api_requests_per_day' => 5000,
        ':api_uploads_per_day' => 25,
        ':updated_at' => ve_now(),
    ]);
}

function ve_generate_api_key(): string
{
    return ve_random_token(18);
}

function ve_api_key_hash(string $apiKey): string
{
    return hash_hmac('sha256', $apiKey, base64_encode(ve_app_secret()));
}

function ve_generate_ftp_password(): string
{
    return strtolower(substr(ve_random_token(12), 0, 10));
}

function ve_get_user_by_id(int $userId): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function ve_get_user_settings(int $userId): array
{
    ve_create_default_settings(ve_db(), $userId);
    $stmt = ve_db()->prepare('SELECT * FROM user_settings WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    $settings = $stmt->fetch();

    if (!is_array($settings)) {
        return [];
    }

    $settings['embed_domains_array'] = json_decode((string) $settings['embed_domains'], true) ?: [];
    $settings['api_enabled'] = (int) ($settings['api_enabled'] ?? 1);
    $settings['api_requests_per_hour'] = (int) ($settings['api_requests_per_hour'] ?? 250);
    $settings['api_requests_per_day'] = (int) ($settings['api_requests_per_day'] ?? 5000);
    $settings['api_uploads_per_day'] = (int) ($settings['api_uploads_per_day'] ?? 25);

    return $settings;
}

function ve_user_plan_code(array $user): string
{
    $planCode = strtolower(trim((string) ($user['plan_code'] ?? 'free')));

    return $planCode !== '' ? $planCode : 'free';
}

function ve_user_is_premium(array $user): bool
{
    $planCode = ve_user_plan_code($user);
    $premiumUntil = trim((string) ($user['premium_until'] ?? ''));

    if (in_array($planCode, ['enterprise', 'lifetime'], true)) {
        return true;
    }

    if ($premiumUntil !== '') {
        $premiumUntilDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $premiumUntil, new DateTimeZone('UTC'));

        if ($premiumUntilDate instanceof DateTimeImmutable && $premiumUntilDate >= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            return true;
        }

        return false;
    }

    return $planCode === 'premium';
}

function ve_player_splash_preview_html(array $settings): string
{
    $path = ve_storage_relative_path_to_absolute((string) ($settings['splash_image_path'] ?? ''));

    if ($path === '' || !is_file($path)) {
        return '<div class="text-muted small">No splash image uploaded yet.</div>';
    }

    $updatedAt = (string) ($settings['updated_at'] ?? ve_timestamp());
    $splashPreviewUrl = ve_h(ve_url('/account/player/splash-preview?ts=' . rawurlencode($updatedAt)));

    return '<div class="small text-muted mb-2">Current protected splash image</div>'
        . '<img src="' . $splashPreviewUrl . '" alt="Splash preview" style="display:block;width:100%;max-width:100%;border-radius:12px;border:1px solid rgba(255,255,255,0.08);background:#0c0c0c;">';
}

function ve_player_settings_payload(int $userId, ?array $settings = null): array
{
    $settings = is_array($settings) ? $settings : ve_get_user_settings($userId);

    return [
        'show_embed_title' => ((int) ($settings['show_embed_title'] ?? 0)) === 1,
        'auto_subtitle_start' => ((int) ($settings['auto_subtitle_start'] ?? 0)) === 1,
        'player_image_mode' => (string) ($settings['player_image_mode'] ?? ''),
        'player_colour' => strtolower((string) ($settings['player_colour'] ?? 'ff9900')),
        'embed_width' => (int) ($settings['embed_width'] ?? 600),
        'embed_height' => (int) ($settings['embed_height'] ?? 480),
        'logo_path' => (string) ($settings['logo_path'] ?? ''),
        'splash_image_path' => (string) ($settings['splash_image_path'] ?? ''),
        'splash_preview_html' => ve_player_splash_preview_html($settings),
    ];
}

function ve_premium_bandwidth_settings_configured(array $settings): bool
{
    return trim((string) ($settings['vast_url'] ?? '')) !== ''
        || trim((string) ($settings['pop_url'] ?? '')) !== '';
}

function ve_find_user_by_login(string $login): ?array
{
    $stmt = ve_db()->prepare('SELECT * FROM users WHERE lower(username) = lower(:login) OR lower(email) = lower(:login) LIMIT 1');
    $stmt->execute([':login' => trim($login)]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function ve_find_user_by_api_key(string $apiKey): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM users
         WHERE api_key_hash = :api_key_hash
           AND status = :status
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':api_key_hash' => ve_api_key_hash($apiKey),
        ':status' => 'active',
    ]);
    $user = $stmt->fetch();

    if (!is_array($user)) {
        return null;
    }

    $user['settings'] = ve_get_user_settings((int) $user['id']);

    return $user;
}

function ve_create_user(string $username, string $email, string $password): array
{
    $pdo = ve_db();
    $now = ve_now();
    $apiKey = ve_generate_api_key();
    $ftpPassword = ve_generate_ftp_password();

    $stmt = $pdo->prepare(
        'INSERT INTO users (
            username, email, password_hash, status, api_key_encrypted, api_key_hash,
            api_key_last_rotated_at, api_key_last_used_at, ftp_username,
            ftp_password_encrypted, created_at, updated_at
        ) VALUES (
            :username, :email, :password_hash, :status, :api_key_encrypted, :api_key_hash,
            :api_key_last_rotated_at, :api_key_last_used_at, :ftp_username,
            :ftp_password_encrypted, :created_at, :updated_at
        )'
    );

    $stmt->execute([
        ':username' => $username,
        ':email' => strtolower($email),
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':status' => 'active',
        ':api_key_encrypted' => ve_encrypt_string($apiKey),
        ':api_key_hash' => ve_api_key_hash($apiKey),
        ':api_key_last_rotated_at' => $now,
        ':api_key_last_used_at' => null,
        ':ftp_username' => $username,
        ':ftp_password_encrypted' => ve_encrypt_string($ftpPassword),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $userId = (int) $pdo->lastInsertId();
    ve_create_default_settings($pdo, $userId);

    if (function_exists('ve_referral_ensure_user_code')) {
        ve_referral_ensure_user_code($userId);
    }

    ve_add_notification($userId, 'Welcome to Video Engine', 'Your account is ready. Configure your player and payout settings from the dashboard.');

    $user = ve_get_user_by_id($userId);

    if (!is_array($user)) {
        throw new RuntimeException('Unable to reload newly created user.');
    }

    return $user;
}

function ve_add_notification(int $userId, string $subject, string $message): void
{
    $stmt = ve_db()->prepare(
        'INSERT INTO notifications (user_id, subject, message, is_read, created_at, read_at)
         VALUES (:user_id, :subject, :message, 0, :created_at, NULL)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':subject' => $subject,
        ':message' => $message,
        ':created_at' => ve_now(),
    ]);
}

function ve_current_user(bool $refresh = false): ?array
{
    static $user;

    if ($refresh) {
        $user = null;
    }

    if (is_array($user)) {
        return $user;
    }

    $userId = $_SESSION[VE_SESSION_USER_ID] ?? null;

    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $loaded = ve_get_user_by_id((int) $userId);

    if (!is_array($loaded) || $loaded['status'] !== 'active' || $loaded['deleted_at'] !== null) {
        ve_logout_current_user(false);
        return null;
    }

    $loaded['settings'] = ve_get_user_settings((int) $loaded['id']);
    $user = $loaded;

    return $user;
}

function ve_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION[VE_SESSION_USER_ID] = (int) $user['id'];
    $_SESSION[VE_SESSION_CSRF] = bin2hex(random_bytes(16));

    $stmt = ve_db()->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':last_login_at' => ve_now(),
        ':updated_at' => ve_now(),
        ':id' => (int) $user['id'],
    ]);

    ve_store_session_record((int) $user['id']);
}

function ve_store_session_record(int $userId): void
{
    $stmt = ve_db()->prepare(
        'INSERT INTO user_sessions (user_id, session_id_hash, ip_address, user_agent, created_at, last_seen_at, expires_at, revoked_at)
         VALUES (:user_id, :session_id_hash, :ip_address, :user_agent, :created_at, :last_seen_at, :expires_at, NULL)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':session_id_hash' => hash('sha256', session_id()),
        ':ip_address' => ve_client_ip(),
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ':created_at' => ve_now(),
        ':last_seen_at' => ve_now(),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + 86400),
    ]);
}

function ve_touch_session_record(): void
{
    $user = ve_current_user();

    if (!is_array($user)) {
        return;
    }

    $stmt = ve_db()->prepare(
        'UPDATE user_sessions SET last_seen_at = :last_seen_at, expires_at = :expires_at
         WHERE session_id_hash = :session_id_hash AND revoked_at IS NULL'
    );
    $stmt->execute([
        ':last_seen_at' => ve_now(),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + 86400),
        ':session_id_hash' => hash('sha256', session_id()),
    ]);
}

function ve_logout_current_user(bool $destroySession = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        $stmt = ve_db()->prepare(
            'UPDATE user_sessions SET revoked_at = :revoked_at
             WHERE session_id_hash = :session_id_hash AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':revoked_at' => ve_now(),
            ':session_id_hash' => hash('sha256', session_id()),
        ]);
    }

    unset($_SESSION[VE_SESSION_USER_ID], $_SESSION[VE_SESSION_CSRF]);

    if ($destroySession && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function ve_require_auth(): array
{
    $user = ve_current_user();

    if (!is_array($user)) {
        ve_redirect('/');
    }

    return $user;
}

function ve_user_api_key(array $user): string
{
    return ve_decrypt_string((string) $user['api_key_encrypted']);
}

function ve_user_ftp_password(array $user): string
{
    return ve_decrypt_string((string) $user['ftp_password_encrypted']);
}

function ve_regenerate_api_key_for_user(int $userId): string
{
    $apiKey = ve_generate_api_key();
    $stmt = ve_db()->prepare(
        'UPDATE users
         SET api_key_encrypted = :api_key_encrypted,
             api_key_hash = :api_key_hash,
             api_key_last_rotated_at = :api_key_last_rotated_at,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':api_key_encrypted' => ve_encrypt_string($apiKey),
        ':api_key_hash' => ve_api_key_hash($apiKey),
        ':api_key_last_rotated_at' => ve_now(),
        ':updated_at' => ve_now(),
        ':id' => $userId,
    ]);

    ve_add_notification($userId, 'API key regenerated', 'Your API key was rotated from the settings page.');

    return $apiKey;
}

function ve_format_datetime_label(?string $timestamp, string $fallback = 'Never'): string
{
    if (!is_string($timestamp) || trim($timestamp) === '') {
        return $fallback;
    }

    return ve_notification_date($timestamp);
}

function ve_human_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    if ($unit < count($units) - 1 && $size >= 1000) {
        $size /= 1024;
        $unit++;
    }

    $precision = $unit === 0 ? 0 : 1;

    return number_format($size, $precision) . ' ' . $units[$unit];
}

function ve_dashboard_earnings_per_view_micro_usd(): int
{
    static $rate;

    if (is_int($rate)) {
        return $rate;
    }

    $perThousand = (int) (getenv('VE_DASHBOARD_RATE_PER_1000_VIEWS_MICRO_USD') ?: 3500000);
    $rate = max(0, (int) round($perThousand / 1000));

    return $rate;
}

function ve_dashboard_format_currency_micro_usd(int $amount): string
{
    return '$' . number_format($amount / 1000000, 5, '.', '');
}

function ve_dashboard_format_traffic_gb(int $bytes, int $precision = 4): string
{
    return number_format($bytes / (1024 ** 3), $precision, '.', '');
}

function ve_dashboard_format_storage_bytes(int $bytes): string
{
    return number_format($bytes / (1024 ** 3), 2, '.', '') . ' GB';
}

function ve_dashboard_total_micro_usd(array $row): int
{
    return max(0, (int) ($row['earned_micro_usd'] ?? 0))
        + max(0, (int) ($row['referral_earned_micro_usd'] ?? 0));
}

/**
 * @return array{from:string,to:string}
 */
function ve_dashboard_normalize_date_range(?string $from, ?string $to, int $defaultLookbackDays = 7): array
{
    $timezone = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $timezone);
    $defaultFrom = $today->sub(new DateInterval('P' . max(0, $defaultLookbackDays) . 'D'));
    $defaultTo = $today;

    $parse = static function (?string $value, DateTimeImmutable $fallback, DateTimeZone $timezone): DateTimeImmutable {
        if (!is_string($value) || trim($value) === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $fallback;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date instanceof DateTimeImmutable ? $date : $fallback;
    };

    $fromDate = $parse($from, $defaultFrom, $timezone);
    $toDate = $parse($to, $defaultTo, $timezone);

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $maxSpanDays = 120;

    if ($fromDate->diff($toDate)->days > $maxSpanDays) {
        $fromDate = $toDate->sub(new DateInterval('P' . $maxSpanDays . 'D'));
    }

    return [
        'from' => $fromDate->format('Y-m-d'),
        'to' => $toDate->format('Y-m-d'),
    ];
}

/**
 * @return string[]
 */
function ve_dashboard_date_series(string $fromDate, string $toDate): array
{
    $timezone = new DateTimeZone('UTC');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);

    if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
        return [];
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $dates = [];
    $cursor = $from;

    while ($cursor <= $to) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->add(new DateInterval('P1D'));
    }

    return $dates;
}

function ve_dashboard_increment_daily_stat_row(
    string $table,
    string $keyColumn,
    int $keyValue,
    string $statDate,
    int $viewDelta,
    int $earnedDelta,
    int $bandwidthDelta,
    int $premiumBandwidthDelta = 0
): void {
    if ($keyValue <= 0 || ($viewDelta === 0 && $earnedDelta === 0 && $bandwidthDelta === 0 && $premiumBandwidthDelta === 0)) {
        return;
    }

    $allowedTables = [
        'user_stats_daily' => 'user_id',
        'video_stats_daily' => 'video_id',
    ];

    if (($allowedTables[$table] ?? null) !== $keyColumn) {
        throw new InvalidArgumentException('Unsupported dashboard aggregate target.');
    }

    $pdo = ve_db();
    $now = ve_now();
    $update = $pdo->prepare(
        'UPDATE ' . $table . '
         SET views = views + :views,
             earned_micro_usd = earned_micro_usd + :earned_micro_usd,
             bandwidth_bytes = bandwidth_bytes + :bandwidth_bytes,
             premium_bandwidth_bytes = premium_bandwidth_bytes + :premium_bandwidth_bytes,
             updated_at = :updated_at
         WHERE ' . $keyColumn . ' = :key_value
           AND stat_date = :stat_date'
    );
    $params = [
        ':views' => $viewDelta,
        ':earned_micro_usd' => $earnedDelta,
        ':bandwidth_bytes' => $bandwidthDelta,
        ':premium_bandwidth_bytes' => $premiumBandwidthDelta,
        ':updated_at' => $now,
        ':key_value' => $keyValue,
        ':stat_date' => $statDate,
    ];
    $update->execute($params);

    if ($update->rowCount() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO ' . $table . ' (
            ' . $keyColumn . ', stat_date, views, earned_micro_usd, bandwidth_bytes, premium_bandwidth_bytes, created_at, updated_at
         ) VALUES (
            :key_value, :stat_date, :views, :earned_micro_usd, :bandwidth_bytes, :premium_bandwidth_bytes, :created_at, :updated_at
         )'
    );

    try {
        $insert->execute([
            ':key_value' => $keyValue,
            ':stat_date' => $statDate,
            ':views' => $viewDelta,
            ':earned_micro_usd' => $earnedDelta,
            ':bandwidth_bytes' => $bandwidthDelta,
            ':premium_bandwidth_bytes' => $premiumBandwidthDelta,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } catch (PDOException $exception) {
        $update->execute($params);
    }
}

function ve_dashboard_record_video_view(
    int $videoId,
    int $userId,
    ?string $statDate = null,
    ?int $earnedMicroUsd = null,
    ?string $referralSourceKey = null
): void {
    $statDate = is_string($statDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statDate) === 1 ? $statDate : gmdate('Y-m-d');
    $earnedMicroUsd = max(0, $earnedMicroUsd ?? ve_dashboard_earnings_per_view_micro_usd());

    ve_dashboard_increment_daily_stat_row('video_stats_daily', 'video_id', $videoId, $statDate, 1, $earnedMicroUsd, 0);
    ve_dashboard_increment_daily_stat_row('user_stats_daily', 'user_id', $userId, $statDate, 1, $earnedMicroUsd, 0);

    if (function_exists('ve_referral_record_video_view_commission')) {
        $sourceKey = trim((string) $referralSourceKey);

        if ($sourceKey === '') {
            $sourceKey = sprintf(
                'video-%d-user-%d-date-%s-%s',
                $videoId,
                $userId,
                $statDate,
                bin2hex(random_bytes(6))
            );
        }

        ve_referral_record_video_view_commission($sourceKey, $videoId, $userId, $earnedMicroUsd, $statDate);
    }
}

function ve_dashboard_record_video_bandwidth(int $videoId, int $userId, int $bytes, ?string $statDate = null, int $premiumBandwidthBytes = 0): void
{
    $bytes = max(0, $bytes);
    $premiumBandwidthBytes = max(0, min($bytes, $premiumBandwidthBytes));

    if ($bytes === 0 && $premiumBandwidthBytes === 0) {
        return;
    }

    $statDate = is_string($statDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statDate) === 1 ? $statDate : gmdate('Y-m-d');

    ve_dashboard_increment_daily_stat_row('video_stats_daily', 'video_id', $videoId, $statDate, 0, 0, $bytes, $premiumBandwidthBytes);
    ve_dashboard_increment_daily_stat_row('user_stats_daily', 'user_id', $userId, $statDate, 0, 0, $bytes, $premiumBandwidthBytes);
}

function ve_dashboard_record_referral_earning(int $userId, int $amountMicroUsd, ?string $statDate = null): void
{
    $userId = max(0, $userId);
    $amountMicroUsd = max(0, $amountMicroUsd);

    if ($userId <= 0 || $amountMicroUsd <= 0) {
        return;
    }

    $statDate = is_string($statDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statDate) === 1 ? $statDate : gmdate('Y-m-d');
    $pdo = ve_db();
    $now = ve_now();
    $update = $pdo->prepare(
        'UPDATE user_stats_daily
         SET referral_earned_micro_usd = referral_earned_micro_usd + :referral_earned_micro_usd,
             updated_at = :updated_at
         WHERE user_id = :user_id
           AND stat_date = :stat_date'
    );
    $params = [
        ':referral_earned_micro_usd' => $amountMicroUsd,
        ':updated_at' => $now,
        ':user_id' => $userId,
        ':stat_date' => $statDate,
    ];
    $update->execute($params);

    if ($update->rowCount() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO user_stats_daily (
            user_id, stat_date, views, earned_micro_usd, referral_earned_micro_usd, bandwidth_bytes, created_at, updated_at
         ) VALUES (
            :user_id, :stat_date, 0, 0, :referral_earned_micro_usd, 0, :created_at, :updated_at
         )'
    );

    try {
        $insert->execute([
            ':user_id' => $userId,
            ':stat_date' => $statDate,
            ':referral_earned_micro_usd' => $amountMicroUsd,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } catch (PDOException $exception) {
        $update->execute($params);
    }
}

/**
 * @return array<string, array{views:int,earned_micro_usd:int,referral_earned_micro_usd:int,bandwidth_bytes:int,premium_bandwidth_bytes:int}>
 */
function ve_dashboard_user_stats_map(int $userId, string $fromDate, string $toDate): array
{
    $stmt = ve_db()->prepare(
        'SELECT stat_date, views, earned_micro_usd, referral_earned_micro_usd, bandwidth_bytes, premium_bandwidth_bytes
         FROM user_stats_daily
         WHERE user_id = :user_id
           AND stat_date BETWEEN :from_date AND :to_date
         ORDER BY stat_date ASC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ]);

    $map = [];

    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $date = (string) ($row['stat_date'] ?? '');

        if ($date === '') {
            continue;
        }

        $map[$date] = [
            'views' => (int) ($row['views'] ?? 0),
            'earned_micro_usd' => (int) ($row['earned_micro_usd'] ?? 0),
            'referral_earned_micro_usd' => (int) ($row['referral_earned_micro_usd'] ?? 0),
            'bandwidth_bytes' => (int) ($row['bandwidth_bytes'] ?? 0),
            'premium_bandwidth_bytes' => (int) ($row['premium_bandwidth_bytes'] ?? 0),
        ];
    }

    return $map;
}

/**
 * @return array<int, array{time:string,views:int,profit:string,traffic:string,earned_micro_usd:int,referral_profit:string,referral_earned_micro_usd:int,total_profit:string,total_profit_micro_usd:int,bandwidth_bytes:int,premium_bandwidth_bytes:int}>
 */
function ve_dashboard_chart_series(int $userId, string $fromDate, string $toDate): array
{
    $stats = ve_dashboard_user_stats_map($userId, $fromDate, $toDate);
    $series = [];

    foreach (ve_dashboard_date_series($fromDate, $toDate) as $date) {
        $row = $stats[$date] ?? [
            'views' => 0,
            'earned_micro_usd' => 0,
            'referral_earned_micro_usd' => 0,
            'bandwidth_bytes' => 0,
            'premium_bandwidth_bytes' => 0,
        ];
        $earnedMicroUsd = (int) ($row['earned_micro_usd'] ?? 0);
        $referralMicroUsd = (int) ($row['referral_earned_micro_usd'] ?? 0);
        $totalMicroUsd = $earnedMicroUsd + $referralMicroUsd;

        $series[] = [
            'time' => $date,
            'views' => (int) $row['views'],
            'profit' => number_format($earnedMicroUsd / 1000000, 5, '.', ''),
            'traffic' => ve_dashboard_format_traffic_gb((int) $row['bandwidth_bytes']),
            'earned_micro_usd' => $earnedMicroUsd,
            'referral_profit' => number_format($referralMicroUsd / 1000000, 5, '.', ''),
            'referral_earned_micro_usd' => $referralMicroUsd,
            'total_profit' => number_format($totalMicroUsd / 1000000, 5, '.', ''),
            'total_profit_micro_usd' => $totalMicroUsd,
            'bandwidth_bytes' => (int) $row['bandwidth_bytes'],
            'premium_bandwidth_bytes' => (int) $row['premium_bandwidth_bytes'],
        ];
    }

    return $series;
}

/**
 * @return array{views:int,earned_micro_usd:int,referral_earned_micro_usd:int,bandwidth_bytes:int}
 */
function ve_dashboard_totals_for_date(int $userId, string $statDate): array
{
    $stmt = ve_db()->prepare(
        'SELECT views, earned_micro_usd, referral_earned_micro_usd, bandwidth_bytes
         FROM user_stats_daily
         WHERE user_id = :user_id AND stat_date = :stat_date
         LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':stat_date' => $statDate,
    ]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return [
            'views' => 0,
            'earned_micro_usd' => 0,
            'referral_earned_micro_usd' => 0,
            'bandwidth_bytes' => 0,
        ];
    }

    return [
        'views' => (int) ($row['views'] ?? 0),
        'earned_micro_usd' => (int) ($row['earned_micro_usd'] ?? 0),
        'referral_earned_micro_usd' => (int) ($row['referral_earned_micro_usd'] ?? 0),
        'bandwidth_bytes' => (int) ($row['bandwidth_bytes'] ?? 0),
    ];
}

function ve_dashboard_balance_micro_usd(int $userId): int
{
    $stmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(earned_micro_usd + referral_earned_micro_usd), 0)
         FROM user_stats_daily
         WHERE user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn() + ve_account_balance_ledger_adjustment_micro_usd($userId);
}

function ve_account_balance_ledger_adjustment_micro_usd(int $userId): int
{
    $stmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(amount_micro_usd), 0)
         FROM account_balance_ledger
         WHERE user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ve_premium_account_plan_catalog(): array
{
    static $plans;

    if (is_array($plans)) {
        return $plans;
    }

    $plans = [
        'monthly' => [
            'id' => 'monthly',
            'kind' => 'account',
            'title' => 'Monthly',
            'amount_micro_usd' => 7990000,
            'interval_spec' => 'P1M',
            'description' => 'Monthly premium account',
        ],
        'half_yearly' => [
            'id' => 'half_yearly',
            'kind' => 'account',
            'title' => 'Half Yearly',
            'amount_micro_usd' => 37990000,
            'interval_spec' => 'P6M',
            'description' => 'Half-year premium account',
        ],
        'yearly' => [
            'id' => 'yearly',
            'kind' => 'account',
            'title' => 'Yearly',
            'amount_micro_usd' => 77990000,
            'interval_spec' => 'P1Y',
            'description' => 'Yearly premium account',
        ],
    ];

    return $plans;
}

function ve_premium_bandwidth_package_catalog(): array
{
    static $packages;

    if (is_array($packages)) {
        return $packages;
    }

    $tb = 1024 ** 4;
    $packages = [
        '10tb' => [
            'id' => '10tb',
            'kind' => 'bandwidth',
            'title' => '10 TB',
            'amount_micro_usd' => 20000000,
            'bandwidth_bytes' => 10 * $tb,
            'description' => '10 TB premium bandwidth',
        ],
        '25tb' => [
            'id' => '25tb',
            'kind' => 'bandwidth',
            'title' => '25 TB',
            'amount_micro_usd' => 50000000,
            'bandwidth_bytes' => 25 * $tb,
            'description' => '25 TB premium bandwidth',
        ],
        '50tb' => [
            'id' => '50tb',
            'kind' => 'bandwidth',
            'title' => '50 TB',
            'amount_micro_usd' => 100000000,
            'bandwidth_bytes' => 50 * $tb,
            'description' => '50 TB premium bandwidth',
        ],
        '100tb' => [
            'id' => '100tb',
            'kind' => 'bandwidth',
            'title' => '100 TB',
            'amount_micro_usd' => 200000000,
            'bandwidth_bytes' => 100 * $tb,
            'description' => '100 TB premium bandwidth',
        ],
        '200tb' => [
            'id' => '200tb',
            'kind' => 'bandwidth',
            'title' => '200 TB',
            'amount_micro_usd' => 400000000,
            'bandwidth_bytes' => 200 * $tb,
            'description' => '200 TB premium bandwidth',
        ],
    ];

    return $packages;
}

function ve_premium_payment_catalog(): array
{
    static $payments;

    if (is_array($payments)) {
        return $payments;
    }

    $payments = [
        'balance' => [
            'code' => 'balance',
            'label' => 'Balance',
            'kind' => 'balance',
        ],
        'BTC' => [
            'code' => 'BTC',
            'label' => 'Bitcoin',
            'kind' => 'crypto',
            'currency_code' => 'BTC',
            'currency_name' => 'Bitcoin',
            'address' => 'bc1qdummypremiumcheckout0000000000000000000000',
            'usd_rate' => 66400.00,
            'precision' => 8,
            'uri_scheme' => 'bitcoin',
        ],
        'ETH' => [
            'code' => 'ETH',
            'label' => 'Ethereum',
            'kind' => 'crypto',
            'currency_code' => 'ETH',
            'currency_name' => 'Ethereum',
            'address' => '0xDEADBEEF00000000000000000000000000BEEF00',
            'usd_rate' => 3325.00,
            'precision' => 6,
            'uri_scheme' => 'ethereum',
        ],
        'BCH' => [
            'code' => 'BCH',
            'label' => 'Bitcoin Cash',
            'kind' => 'crypto',
            'currency_code' => 'BCH',
            'currency_name' => 'Bitcoin Cash',
            'address' => 'bitcoincash:qrdummypremiumcheckout0000000000000000000',
            'usd_rate' => 425.00,
            'precision' => 6,
            'uri_scheme' => 'bitcoincash',
        ],
        'LTC' => [
            'code' => 'LTC',
            'label' => 'Litecoin',
            'kind' => 'crypto',
            'currency_code' => 'LTC',
            'currency_name' => 'Litecoin',
            'address' => 'ltc1qdummypremiumcheckout000000000000000000000',
            'usd_rate' => 84.00,
            'precision' => 6,
            'uri_scheme' => 'litecoin',
        ],
        'USDTTRC20' => [
            'code' => 'USDTTRC20',
            'label' => 'USDT TRC20',
            'kind' => 'crypto',
            'currency_code' => 'USDTTRC20',
            'currency_name' => 'USDT TRC20',
            'address' => 'TDummyPremiumCheckoutWallet1111111111111111',
            'usd_rate' => 1.00,
            'precision' => 2,
            'uri_scheme' => 'tron',
        ],
    ];

    return $payments;
}

function ve_premium_normalize_purchase_type(string $purchaseType): ?string
{
    $normalized = strtolower(trim($purchaseType));

    return match ($normalized) {
        'account', 'plan', 'premium_account' => 'account',
        'bandwidth', 'premium_bw', 'premium_bandwidth' => 'bandwidth',
        default => null,
    };
}

function ve_premium_normalize_payment_code(string $paymentCode): string
{
    $paymentCode = trim($paymentCode);

    return strcasecmp($paymentCode, 'balance') === 0 ? 'balance' : strtoupper($paymentCode);
}

function ve_premium_project_expiry(array $user, string $intervalSpec): ?string
{
    try {
        $base = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $premiumUntil = trim((string) ($user['premium_until'] ?? ''));

        if ($premiumUntil !== '') {
            $premiumUntilDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $premiumUntil, new DateTimeZone('UTC'));

            if ($premiumUntilDate instanceof DateTimeImmutable && $premiumUntilDate > $base) {
                $base = $premiumUntilDate;
            }
        }

        return $base->add(new DateInterval($intervalSpec))->format('Y-m-d H:i:s');
    } catch (Throwable $throwable) {
        return null;
    }
}

function ve_premium_bandwidth_totals(int $userId): array
{
    $purchasedStmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(bandwidth_bytes), 0)
         FROM premium_orders
         WHERE user_id = :user_id
           AND purchase_type = :purchase_type
           AND status = :status'
    );
    $purchasedStmt->execute([
        ':user_id' => $userId,
        ':purchase_type' => 'bandwidth',
        ':status' => 'completed',
    ]);
    $purchasedBytes = (int) $purchasedStmt->fetchColumn();

    $usedStmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(premium_bandwidth_bytes), 0)
         FROM user_stats_daily
         WHERE user_id = :user_id'
    );
    $usedStmt->execute([':user_id' => $userId]);
    $usedBytes = (int) $usedStmt->fetchColumn();

    return [
        'purchased_bytes' => $purchasedBytes,
        'used_bytes' => $usedBytes,
        'available_bytes' => max(0, $purchasedBytes - $usedBytes),
    ];
}

function ve_premium_bandwidth_feature_state(int $userId, ?array $settings = null, ?array $bandwidth = null): array
{
    $settings = is_array($settings) ? $settings : ve_get_user_settings($userId);
    $bandwidth = is_array($bandwidth) ? $bandwidth : ve_premium_bandwidth_totals($userId);
    $configured = ve_premium_bandwidth_settings_configured($settings);
    $purchasedBytes = max(0, (int) ($bandwidth['purchased_bytes'] ?? 0));
    $availableBytes = max(0, (int) ($bandwidth['available_bytes'] ?? 0));
    $active = $configured && $availableBytes > 0;

    if (!$configured) {
        return [
            'configured' => false,
            'active' => false,
            'status_label' => $purchasedBytes > 0 ? 'Ready' : 'Inactive',
            'detail' => 'Only traffic served while own adverts are enabled counts against premium bandwidth.',
        ];
    }

    if ($active) {
        return [
            'configured' => true,
            'active' => true,
            'status_label' => 'Active',
            'detail' => 'Own adverts are enabled and premium-bandwidth traffic is being tracked separately.',
        ];
    }

    return [
        'configured' => true,
        'active' => false,
        'status_label' => $purchasedBytes > 0 ? 'Paused' : 'Inactive',
        'detail' => $purchasedBytes > 0
            ? 'Own adverts are configured, but premium bandwidth is exhausted until more credit is added.'
            : 'Own adverts are configured, but no premium bandwidth has been purchased yet.',
    ];
}

function ve_premium_chart_payload(int $userId, int $lookbackDays = 7): array
{
    $range = ve_dashboard_normalize_date_range(null, null, $lookbackDays);
    $series = ve_dashboard_chart_series($userId, $range['from'], $range['to']);
    $labels = [];
    $stats = [];

    foreach ($series as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $labels[] = (string) ($entry['time'] ?? '');
        $stats[] = round(((int) ($entry['premium_bandwidth_bytes'] ?? 0)) / (1024 * 1024), 2);
    }

    return [
        'labels' => $labels,
        'stats' => $stats,
    ];
}

function ve_premium_page_payload(int $userId): array
{
    $user = ve_get_user_by_id($userId);
    $settings = is_array($user) ? ve_get_user_settings((int) $user['id']) : [];
    $balanceMicroUsd = ve_dashboard_balance_micro_usd($userId);
    $bandwidth = ve_premium_bandwidth_totals($userId);
    $feature = ve_premium_bandwidth_feature_state($userId, $settings, $bandwidth);
    $chart = ve_premium_chart_payload($userId, 7);
    $premiumUntil = is_array($user) ? trim((string) ($user['premium_until'] ?? '')) : '';
    $usedBytes = (int) ($bandwidth['used_bytes'] ?? 0);
    $availableBytes = (int) ($bandwidth['available_bytes'] ?? 0);
    $purchasedBytes = (int) ($bandwidth['purchased_bytes'] ?? 0);

    return [
        'usr_money' => number_format($balanceMicroUsd / 1000000, 5, '.', ''),
        'balance_micro_usd' => $balanceMicroUsd,
        'balance_label' => ve_dashboard_format_currency_micro_usd($balanceMicroUsd),
        'rand' => strtolower(substr(ve_random_token(6), 0, 6)),
        'accept_paypal' => 0,
        'used_bw' => $usedBytes,
        'used_bw_label' => ve_human_bytes($usedBytes),
        'available_bw' => $availableBytes,
        'available_bw_label' => ve_human_bytes($availableBytes),
        'purchased_bw' => $purchasedBytes,
        'purchased_bw_label' => ve_human_bytes($purchasedBytes),
        'stats' => $chart['stats'],
        'labels' => $chart['labels'],
        'plan_label' => is_array($user) && ve_user_is_premium($user) ? 'Premium active' : 'Free account',
        'premium_until_raw' => $premiumUntil,
        'premium_until_label' => $premiumUntil !== '' ? ve_format_datetime_label($premiumUntil, 'Active') : 'No active renewal',
        'premium_bandwidth_configured' => (bool) ($feature['configured'] ?? false),
        'premium_bandwidth_active' => (bool) ($feature['active'] ?? false),
        'premium_bandwidth_status_label' => (string) ($feature['status_label'] ?? 'Inactive'),
        'premium_bandwidth_status_detail' => (string) ($feature['detail'] ?? ''),
    ];
}

function ve_premium_order_code(): string
{
    return 'po_' . strtolower(ve_random_token(9));
}

function ve_premium_checkout_context_from_request(int $userId, array $request): array
{
    $purchaseType = ve_premium_normalize_purchase_type((string) ($request['purchase_type'] ?? $request['premium'] ?? ''));

    if ($purchaseType === null) {
        ve_json([
            'status' => 'fail',
            'message' => 'Choose a valid premium product before continuing.',
        ], 422);
    }

    $packageId = strtolower(trim((string) ($request['package_id'] ?? $request['plan_id'] ?? $request['package'] ?? '')));

    if ($packageId === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Choose a valid plan or bandwidth package before continuing.',
        ], 422);
    }

    $catalog = $purchaseType === 'account' ? ve_premium_account_plan_catalog() : ve_premium_bandwidth_package_catalog();
    $product = $catalog[$packageId] ?? null;

    if (!is_array($product)) {
        ve_json([
            'status' => 'fail',
            'message' => 'The selected package is no longer available.',
        ], 422);
    }

    $paymentCode = ve_premium_normalize_payment_code((string) ($request['payment_method'] ?? $request['coin'] ?? $request['submethod'] ?? 'balance'));
    $payments = ve_premium_payment_catalog();
    $payment = $payments[$paymentCode] ?? null;

    if (!is_array($payment)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Choose a valid payment method before continuing.',
        ], 422);
    }

    return [
        'purchase_type' => $purchaseType,
        'product' => $product,
        'payment' => $payment,
        'quote' => ve_premium_checkout_quote($userId, $purchaseType, $product, $payment),
    ];
}

function ve_premium_checkout_quote(int $userId, string $purchaseType, array $product, array $payment): array
{
    $user = ve_get_user_by_id($userId);
    $balanceMicroUsd = ve_dashboard_balance_micro_usd($userId);
    $amountMicroUsd = (int) ($product['amount_micro_usd'] ?? 0);
    $remainingBalanceMicroUsd = $balanceMicroUsd - $amountMicroUsd;
    $shortfallMicroUsd = $remainingBalanceMicroUsd < 0 ? abs($remainingBalanceMicroUsd) : 0;
    $canPay = ($payment['kind'] ?? '') !== 'balance' || $remainingBalanceMicroUsd >= 0;
    $projectedPremiumUntil = $purchaseType === 'account'
        ? ve_premium_project_expiry(is_array($user) ? $user : [], (string) ($product['interval_spec'] ?? 'P1M'))
        : null;
    $benefitLabel = $purchaseType === 'account'
        ? ((string) ($product['title'] ?? 'Premium') . ' premium account')
        : ((string) ($product['title'] ?? 'Premium') . ' premium bandwidth');

    return [
        'purchase_type' => $purchaseType,
        'package_id' => (string) ($product['id'] ?? ''),
        'package_title' => (string) ($product['title'] ?? ''),
        'description' => (string) ($product['description'] ?? $benefitLabel),
        'payment_method' => (string) ($payment['code'] ?? ''),
        'payment_label' => (string) ($payment['label'] ?? ''),
        'amount_micro_usd' => $amountMicroUsd,
        'amount_label' => ve_dashboard_format_currency_micro_usd($amountMicroUsd),
        'balance_micro_usd' => $balanceMicroUsd,
        'balance_label' => ve_dashboard_format_currency_micro_usd($balanceMicroUsd),
        'remaining_balance_micro_usd' => $remainingBalanceMicroUsd,
        'remaining_balance_label' => ve_dashboard_format_currency_micro_usd($remainingBalanceMicroUsd),
        'shortfall_micro_usd' => $shortfallMicroUsd,
        'shortfall_label' => ve_dashboard_format_currency_micro_usd($shortfallMicroUsd),
        'can_pay' => $canPay,
        'insufficient_balance_message' => $canPay ? '' : 'Your current balance is not high enough for this purchase.',
        'bandwidth_bytes' => (int) ($product['bandwidth_bytes'] ?? 0),
        'bandwidth_label' => isset($product['bandwidth_bytes']) ? ve_human_bytes((int) $product['bandwidth_bytes']) : '',
        'projected_premium_until_raw' => $projectedPremiumUntil,
        'projected_premium_until_label' => $projectedPremiumUntil !== null ? ve_format_datetime_label($projectedPremiumUntil, 'Pending activation') : '',
        'benefit_label' => $benefitLabel,
    ];
}

function ve_insert_balance_ledger_entry(PDO $pdo, int $userId, string $entryType, string $sourceType, string $sourceKey, int $amountMicroUsd, string $description, array $metadata = []): void
{
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($metadataJson)) {
        $metadataJson = '{}';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO account_balance_ledger (
            user_id, entry_type, source_type, source_key, amount_micro_usd, description, metadata_json, created_at
        ) VALUES (
            :user_id, :entry_type, :source_type, :source_key, :amount_micro_usd, :description, :metadata_json, :created_at
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':entry_type' => $entryType,
        ':source_type' => $sourceType,
        ':source_key' => $sourceKey,
        ':amount_micro_usd' => $amountMicroUsd,
        ':description' => $description,
        ':metadata_json' => $metadataJson,
        ':created_at' => ve_now(),
    ]);
}

function ve_insert_premium_order(PDO $pdo, array $order): void
{
    $metadataJson = json_encode((array) ($order['metadata'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($metadataJson)) {
        $metadataJson = '{}';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO premium_orders (
            order_code, user_id, purchase_type, package_id, package_title, payment_method, status, amount_micro_usd,
            bandwidth_bytes, plan_interval_spec, crypto_currency_code, crypto_currency_name, crypto_amount, crypto_address,
            payment_uri, qr_url, metadata_json, created_at, updated_at, completed_at
        ) VALUES (
            :order_code, :user_id, :purchase_type, :package_id, :package_title, :payment_method, :status, :amount_micro_usd,
            :bandwidth_bytes, :plan_interval_spec, :crypto_currency_code, :crypto_currency_name, :crypto_amount, :crypto_address,
            :payment_uri, :qr_url, :metadata_json, :created_at, :updated_at, :completed_at
        )'
    );
    $stmt->execute([
        ':order_code' => (string) ($order['order_code'] ?? ''),
        ':user_id' => (int) ($order['user_id'] ?? 0),
        ':purchase_type' => (string) ($order['purchase_type'] ?? ''),
        ':package_id' => (string) ($order['package_id'] ?? ''),
        ':package_title' => (string) ($order['package_title'] ?? ''),
        ':payment_method' => (string) ($order['payment_method'] ?? ''),
        ':status' => (string) ($order['status'] ?? 'pending'),
        ':amount_micro_usd' => (int) ($order['amount_micro_usd'] ?? 0),
        ':bandwidth_bytes' => (int) ($order['bandwidth_bytes'] ?? 0),
        ':plan_interval_spec' => (string) ($order['plan_interval_spec'] ?? ''),
        ':crypto_currency_code' => (string) ($order['crypto_currency_code'] ?? ''),
        ':crypto_currency_name' => (string) ($order['crypto_currency_name'] ?? ''),
        ':crypto_amount' => (string) ($order['crypto_amount'] ?? ''),
        ':crypto_address' => (string) ($order['crypto_address'] ?? ''),
        ':payment_uri' => (string) ($order['payment_uri'] ?? ''),
        ':qr_url' => (string) ($order['qr_url'] ?? ''),
        ':metadata_json' => $metadataJson,
        ':created_at' => (string) ($order['created_at'] ?? ve_now()),
        ':updated_at' => (string) ($order['updated_at'] ?? ve_now()),
        ':completed_at' => $order['completed_at'] ?? null,
    ]);
}

function ve_premium_dummy_payment_uri(array $payment, string $address, string $amount, string $orderCode): string
{
    $scheme = (string) ($payment['uri_scheme'] ?? '');
    $query = 'amount=' . rawurlencode($amount) . '&invoice=' . rawurlencode($orderCode);

    if (($payment['code'] ?? '') === 'USDTTRC20') {
        $query .= '&token=USDTTRC20';
    }

    if ($scheme === '') {
        return $address . '?' . $query;
    }

    return $scheme . ':' . $address . '?' . $query;
}

function ve_premium_dummy_crypto_invoice(array $payment, array $quote, string $orderCode): array
{
    $usdAmount = ((int) ($quote['amount_micro_usd'] ?? 0)) / 1000000;
    $usdRate = max(0.000001, (float) ($payment['usd_rate'] ?? 1));
    $precision = max(2, min(8, (int) ($payment['precision'] ?? 8)));
    $cryptoAmount = number_format($usdAmount / $usdRate, $precision, '.', '');
    $address = (string) ($payment['address'] ?? '');
    $paymentUri = ve_premium_dummy_payment_uri($payment, $address, $cryptoAmount, $orderCode);

    return [
        'order_code' => $orderCode,
        'currency_code' => (string) ($payment['currency_code'] ?? ($payment['code'] ?? '')),
        'currency_name' => (string) ($payment['currency_name'] ?? ($payment['label'] ?? 'Crypto')),
        'amount' => $cryptoAmount,
        'address' => $address,
        'payment_uri' => $paymentUri,
        'qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($paymentUri),
    ];
}

function ve_apply_premium_purchase(PDO $pdo, int $userId, string $purchaseType, array $product): array
{
    if ($purchaseType === 'account') {
        $user = ve_get_user_by_id($userId);
        $premiumUntil = ve_premium_project_expiry(is_array($user) ? $user : [], (string) ($product['interval_spec'] ?? 'P1M'));

        if ($premiumUntil === null) {
            throw new RuntimeException('Unable to compute the premium renewal date.');
        }

        $stmt = $pdo->prepare(
            'UPDATE users
             SET plan_code = :plan_code,
                 premium_until = :premium_until,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':plan_code' => 'premium',
            ':premium_until' => $premiumUntil,
            ':updated_at' => ve_now(),
            ':id' => $userId,
        ]);

        return [
            'premium_until' => $premiumUntil,
            'message' => 'Premium account active until ' . ve_format_datetime_label($premiumUntil, 'Active') . '.',
        ];
    }

    return [
        'premium_until' => null,
        'message' => (string) ($product['title'] ?? 'Premium bandwidth') . ' was added to your account.',
    ];
}

function ve_dashboard_storage_bytes(int $userId): int
{
    $stmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(CASE
            WHEN processed_size_bytes > 0 THEN processed_size_bytes
            ELSE original_size_bytes
         END), 0)
         FROM videos
         WHERE user_id = :user_id
           AND deleted_at IS NULL'
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ve_dashboard_online_watchers(int $userId, int $windowSeconds = 600): int
{
    $stmt = ve_db()->prepare(
        'SELECT COUNT(*)
         FROM video_playback_sessions sessions
         INNER JOIN videos ON videos.id = sessions.video_id
         WHERE videos.user_id = :user_id
           AND videos.deleted_at IS NULL
           AND sessions.revoked_at IS NULL
           AND sessions.expires_at >= :now
           AND sessions.last_seen_at >= :active_since'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':now' => ve_now(),
        ':active_since' => gmdate('Y-m-d H:i:s', ve_timestamp() - max(60, $windowSeconds)),
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function ve_dashboard_top_files(int $userId, string $fromDate, string $toDate, int $limit = 5): array
{
    $stmt = ve_db()->prepare(
        'SELECT
            videos.public_id,
            videos.title,
            videos.status,
            COALESCE(SUM(video_stats_daily.views), 0) AS total_views,
            COALESCE(SUM(video_stats_daily.earned_micro_usd), 0) AS total_earned_micro_usd,
            COALESCE(SUM(video_stats_daily.bandwidth_bytes), 0) AS total_bandwidth_bytes
         FROM videos
         LEFT JOIN video_stats_daily
           ON video_stats_daily.video_id = videos.id
          AND video_stats_daily.stat_date BETWEEN :from_date AND :to_date
         WHERE videos.user_id = :user_id
           AND videos.deleted_at IS NULL
         GROUP BY videos.id
         HAVING total_views > 0 OR total_bandwidth_bytes > 0
         ORDER BY total_views DESC, total_bandwidth_bytes DESC, videos.created_at DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ]);

    $rows = $stmt->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        $publicId = (string) ($row['public_id'] ?? '');
        $bandwidthBytes = (int) ($row['total_bandwidth_bytes'] ?? 0);
        $earnedMicroUsd = (int) ($row['total_earned_micro_usd'] ?? 0);

        return [
            'public_id' => $publicId,
            'title' => (string) ($row['title'] ?? 'Untitled video'),
            'status' => (string) ($row['status'] ?? ''),
            'views' => (int) ($row['total_views'] ?? 0),
            'earned_micro_usd' => $earnedMicroUsd,
            'earned' => ve_dashboard_format_currency_micro_usd($earnedMicroUsd),
            'bandwidth_bytes' => $bandwidthBytes,
            'bandwidth' => ve_human_bytes($bandwidthBytes),
            'watch_url' => ve_url('/d/' . rawurlencode($publicId)),
        ];
    }, array_filter($rows, static fn ($row): bool => is_array($row)));
}

function ve_dashboard_summary(int $userId, int $lookbackDays = 7): array
{
    $range = ve_dashboard_normalize_date_range(null, null, $lookbackDays);
    $todayDate = $range['to'];
    $yesterdayDate = (new DateTimeImmutable($todayDate, new DateTimeZone('UTC')))
        ->sub(new DateInterval('P1D'))
        ->format('Y-m-d');
    $today = ve_dashboard_totals_for_date($userId, $todayDate);
    $yesterday = ve_dashboard_totals_for_date($userId, $yesterdayDate);
    $balanceMicroUsd = ve_dashboard_balance_micro_usd($userId);
    $storageBytes = ve_dashboard_storage_bytes($userId);
    $online = ve_dashboard_online_watchers($userId);

    return [
        'status' => 'ok',
        'generated_at' => ve_now(),
        'online' => $online,
        'today' => ve_dashboard_format_currency_micro_usd(ve_dashboard_total_micro_usd($today)),
        'balance' => ve_dashboard_format_currency_micro_usd($balanceMicroUsd),
        'widgets' => [
            'online' => [
                'value' => $online,
                'formatted' => (string) $online,
            ],
            'today_earnings' => [
                'micro_usd' => ve_dashboard_total_micro_usd($today),
                'views_micro_usd' => (int) ($today['earned_micro_usd'] ?? 0),
                'referral_micro_usd' => (int) ($today['referral_earned_micro_usd'] ?? 0),
                'formatted' => ve_dashboard_format_currency_micro_usd(ve_dashboard_total_micro_usd($today)),
            ],
            'yesterday_earnings' => [
                'micro_usd' => ve_dashboard_total_micro_usd($yesterday),
                'views_micro_usd' => (int) ($yesterday['earned_micro_usd'] ?? 0),
                'referral_micro_usd' => (int) ($yesterday['referral_earned_micro_usd'] ?? 0),
                'formatted' => ve_dashboard_format_currency_micro_usd(ve_dashboard_total_micro_usd($yesterday)),
            ],
            'balance' => [
                'micro_usd' => $balanceMicroUsd,
                'formatted' => ve_dashboard_format_currency_micro_usd($balanceMicroUsd),
            ],
            'storage_used' => [
                'bytes' => $storageBytes,
                'formatted' => ve_dashboard_format_storage_bytes($storageBytes),
            ],
        ],
        'chart' => ve_dashboard_chart_series($userId, $range['from'], $range['to']),
        'top_files' => ve_dashboard_top_files($userId, $range['from'], $range['to']),
        'range' => $range,
    ];
}

function ve_dashboard_reports_snapshot(int $userId, ?string $from = null, ?string $to = null): array
{
    $range = ve_dashboard_normalize_date_range($from, $to, 7);
    $series = ve_dashboard_chart_series($userId, $range['from'], $range['to']);
    $totalViews = 0;
    $totalEarnedMicroUsd = 0;
    $totalReferralMicroUsd = 0;
    $totalBandwidthBytes = 0;
    $rows = [];

    foreach ($series as $entry) {
        $views = (int) ($entry['views'] ?? 0);
        $earnedMicroUsd = (int) ($entry['earned_micro_usd'] ?? 0);
        $referralMicroUsd = (int) ($entry['referral_earned_micro_usd'] ?? 0);
        $totalMicroUsd = $earnedMicroUsd + $referralMicroUsd;
        $bandwidthBytes = (int) ($entry['bandwidth_bytes'] ?? 0);

        $totalViews += $views;
        $totalEarnedMicroUsd += $earnedMicroUsd;
        $totalReferralMicroUsd += $referralMicroUsd;
        $totalBandwidthBytes += $bandwidthBytes;
        $rows[] = [
            'date' => (string) ($entry['time'] ?? ''),
            'views' => $views,
            'profit' => ve_dashboard_format_currency_micro_usd($earnedMicroUsd),
            'profit_micro_usd' => $earnedMicroUsd,
            'referral_share' => ve_dashboard_format_currency_micro_usd($referralMicroUsd),
            'referral_share_micro_usd' => $referralMicroUsd,
            'referral_micro_usd' => $referralMicroUsd,
            'traffic' => ve_human_bytes($bandwidthBytes),
            'bandwidth_bytes' => $bandwidthBytes,
            'total' => ve_dashboard_format_currency_micro_usd($totalMicroUsd),
            'total_micro_usd' => $totalMicroUsd,
        ];
    }

    return [
        'status' => 'ok',
        'range' => $range,
        'chart' => $series,
        'rows' => $rows,
        'totals' => [
            'views' => $totalViews,
            'profit' => ve_dashboard_format_currency_micro_usd($totalEarnedMicroUsd),
            'profit_micro_usd' => $totalEarnedMicroUsd,
            'referral_share' => ve_dashboard_format_currency_micro_usd($totalReferralMicroUsd),
            'referral_share_micro_usd' => $totalReferralMicroUsd,
            'referral_micro_usd' => $totalReferralMicroUsd,
            'traffic' => ve_human_bytes($totalBandwidthBytes),
            'bandwidth_bytes' => $totalBandwidthBytes,
            'total' => ve_dashboard_format_currency_micro_usd($totalEarnedMicroUsd + $totalReferralMicroUsd),
            'total_micro_usd' => $totalEarnedMicroUsd + $totalReferralMicroUsd,
        ],
    ];
}

function ve_api_extract_key_from_request(): ?string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));

    if ($authorization !== '' && preg_match('/^(?:Bearer|ApiKey)\s+(.+)$/i', $authorization, $matches) === 1) {
        $candidate = trim((string) ($matches[1] ?? ''));

        if ($candidate !== '') {
            return $candidate;
        }
    }

    $headerKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));

    return $headerKey !== '' ? $headerKey : null;
}

function ve_api_limit_value(array $settings, string $key, int $default): int
{
    return max(0, (int) ($settings[$key] ?? $default));
}

function ve_api_window_start(string $window): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return match ($window) {
        'hour' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
        'month' => $now->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s'),
        default => $now->setTime(0, 0)->format('Y-m-d H:i:s'),
    };
}

function ve_api_count_requests_since(int $userId, string $since, ?string $requestKind = null): int
{
    $sql = 'SELECT COUNT(*) FROM api_request_logs WHERE user_id = :user_id AND created_at >= :since';
    $params = [
        ':user_id' => $userId,
        ':since' => $since,
    ];

    if ($requestKind !== null) {
        $sql .= ' AND request_kind = :request_kind';
        $params[':request_kind'] = $requestKind;
    }

    $stmt = ve_db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function ve_api_recent_activity(int $userId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $stmt = ve_db()->prepare(
        'SELECT request_kind, endpoint, http_method, status_code, bytes_in, created_at
         FROM api_request_logs
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        $statusCode = (int) ($row['status_code'] ?? 0);

        return [
            'request_kind' => (string) ($row['request_kind'] ?? 'request'),
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'http_method' => strtoupper((string) ($row['http_method'] ?? 'GET')),
            'status_code' => $statusCode,
            'status_label' => $statusCode >= 200 && $statusCode < 300 ? 'Success' : ($statusCode >= 400 ? 'Error' : 'Accepted'),
            'status_class' => $statusCode >= 200 && $statusCode < 300 ? 'text-success' : ($statusCode >= 400 ? 'text-danger' : 'text-warning'),
            'bytes_in' => (int) ($row['bytes_in'] ?? 0),
            'bytes_in_human' => ve_human_bytes((int) ($row['bytes_in'] ?? 0)),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_at_label' => ve_format_datetime_label((string) ($row['created_at'] ?? ''), 'Unknown'),
        ];
    }, array_filter($rows, static fn ($row): bool => is_array($row)));
}

function ve_api_usage_snapshot(int $userId): array
{
    $settings = ve_get_user_settings($userId);
    $user = ve_get_user_by_id($userId);
    $requestsLastHour = ve_api_count_requests_since($userId, ve_api_window_start('hour'));
    $requestsToday = ve_api_count_requests_since($userId, ve_api_window_start('day'));
    $requestsThisMonth = ve_api_count_requests_since($userId, ve_api_window_start('month'));
    $uploadsToday = ve_api_count_requests_since($userId, ve_api_window_start('day'), 'upload');
    $deletesToday = ve_api_count_requests_since($userId, ve_api_window_start('day'), 'delete');

    return [
        'enabled' => ((int) ($settings['api_enabled'] ?? 1)) === 1,
        'status_label' => ((int) ($settings['api_enabled'] ?? 1)) === 1 ? 'Active' : 'Disabled',
        'limits' => [
            'requests_per_hour' => ve_api_limit_value($settings, 'api_requests_per_hour', 250),
            'requests_per_day' => ve_api_limit_value($settings, 'api_requests_per_day', 5000),
            'uploads_per_day' => ve_api_limit_value($settings, 'api_uploads_per_day', 25),
        ],
        'usage' => [
            'requests_last_hour' => $requestsLastHour,
            'requests_today' => $requestsToday,
            'requests_this_month' => $requestsThisMonth,
            'uploads_today' => $uploadsToday,
            'deletes_today' => $deletesToday,
            'last_used_at' => ve_format_datetime_label((string) ($user['api_key_last_used_at'] ?? ''), 'Never used'),
            'last_used_at_raw' => (string) ($user['api_key_last_used_at'] ?? ''),
            'last_rotated_at' => ve_format_datetime_label((string) ($user['api_key_last_rotated_at'] ?? ''), 'Not available'),
            'last_rotated_at_raw' => (string) ($user['api_key_last_rotated_at'] ?? ''),
        ],
        'recent_activity' => ve_api_recent_activity($userId, 10),
    ];
}

function ve_api_record_request(int $userId, string $apiKeyHash, string $requestKind, int $statusCode, int $bytesIn = 0): void
{
    $now = ve_now();
    $stmt = ve_db()->prepare(
        'INSERT INTO api_request_logs (
            user_id, api_key_hash, auth_type, request_kind, endpoint, http_method,
            status_code, bytes_in, client_ip, user_agent, created_at
        ) VALUES (
            :user_id, :api_key_hash, :auth_type, :request_kind, :endpoint, :http_method,
            :status_code, :bytes_in, :client_ip, :user_agent, :created_at
        )'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':api_key_hash' => $apiKeyHash,
        ':auth_type' => 'api_key',
        ':request_kind' => $requestKind,
        ':endpoint' => ve_request_path(),
        ':http_method' => ve_request_method(),
        ':status_code' => $statusCode,
        ':bytes_in' => max(0, $bytesIn),
        ':client_ip' => ve_client_ip(),
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ':created_at' => $now,
    ]);

    $update = ve_db()->prepare('UPDATE users SET api_key_last_used_at = :api_key_last_used_at WHERE id = :id');
    $update->execute([
        ':api_key_last_used_at' => $now,
        ':id' => $userId,
    ]);
}

function ve_api_rate_limit_state(array $user, string $requestKind): array
{
    $settings = is_array($user['settings'] ?? null) ? $user['settings'] : ve_get_user_settings((int) $user['id']);
    $requestsLastHour = ve_api_count_requests_since((int) $user['id'], ve_api_window_start('hour'));
    $requestsToday = ve_api_count_requests_since((int) $user['id'], ve_api_window_start('day'));
    $uploadsToday = ve_api_count_requests_since((int) $user['id'], ve_api_window_start('day'), 'upload');
    $hourLimit = ve_api_limit_value($settings, 'api_requests_per_hour', 250);
    $dayLimit = ve_api_limit_value($settings, 'api_requests_per_day', 5000);
    $uploadLimit = ve_api_limit_value($settings, 'api_uploads_per_day', 25);
    $enabled = ((int) ($settings['api_enabled'] ?? 1)) === 1;

    $state = [
        'enabled' => $enabled,
        'allowed' => true,
        'status' => 200,
        'message' => '',
        'retry_after' => 0,
        'limits' => [
            'requests_per_hour' => $hourLimit,
            'requests_per_day' => $dayLimit,
            'uploads_per_day' => $uploadLimit,
        ],
        'counts' => [
            'requests_last_hour' => $requestsLastHour,
            'requests_today' => $requestsToday,
            'uploads_today' => $uploadsToday,
        ],
        'remaining' => [
            'requests_per_hour' => $hourLimit > 0 ? max(0, $hourLimit - $requestsLastHour - 1) : null,
            'requests_per_day' => $dayLimit > 0 ? max(0, $dayLimit - $requestsToday - 1) : null,
            'uploads_per_day' => $uploadLimit > 0 ? max(0, $uploadLimit - $uploadsToday - ($requestKind === 'upload' ? 1 : 0)) : null,
        ],
    ];

    if (!$enabled) {
        $state['allowed'] = false;
        $state['status'] = 403;
        $state['message'] = 'API access is disabled for this account.';

        return $state;
    }

    if ($hourLimit > 0 && $requestsLastHour >= $hourLimit) {
        $state['allowed'] = false;
        $state['status'] = 429;
        $state['message'] = 'The hourly API request limit has been reached.';
        $state['retry_after'] = 3600;

        return $state;
    }

    if ($dayLimit > 0 && $requestsToday >= $dayLimit) {
        $endOfDay = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(23, 59, 59);
        $state['allowed'] = false;
        $state['status'] = 429;
        $state['message'] = 'The daily API request limit has been reached.';
        $state['retry_after'] = max(60, $endOfDay->getTimestamp() - ve_timestamp());

        return $state;
    }

    if ($requestKind === 'upload' && $uploadLimit > 0 && $uploadsToday >= $uploadLimit) {
        $endOfDay = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(23, 59, 59);
        $state['allowed'] = false;
        $state['status'] = 429;
        $state['message'] = 'The daily API upload limit has been reached.';
        $state['retry_after'] = max(60, $endOfDay->getTimestamp() - ve_timestamp());
    }

    return $state;
}

function ve_api_send_rate_limit_headers(array $state): void
{
    $hourLimit = (int) ($state['limits']['requests_per_hour'] ?? 0);
    $dayLimit = (int) ($state['limits']['requests_per_day'] ?? 0);
    $uploadLimit = (int) ($state['limits']['uploads_per_day'] ?? 0);

    if ($hourLimit > 0) {
        header('X-RateLimit-Limit-Hour: ' . $hourLimit);
        header('X-RateLimit-Remaining-Hour: ' . max(0, (int) ($state['remaining']['requests_per_hour'] ?? 0)));
    }

    if ($dayLimit > 0) {
        header('X-RateLimit-Limit-Day: ' . $dayLimit);
        header('X-RateLimit-Remaining-Day: ' . max(0, (int) ($state['remaining']['requests_per_day'] ?? 0)));
    }

    if ($uploadLimit > 0) {
        header('X-UploadLimit-Day: ' . $uploadLimit);
        header('X-UploadRemaining-Day: ' . max(0, (int) ($state['remaining']['uploads_per_day'] ?? 0)));
    }

    if ((int) ($state['retry_after'] ?? 0) > 0) {
        header('Retry-After: ' . (int) $state['retry_after']);
    }
}

function ve_normalize_domain_list(string $rawDomains): array
{
    $domains = preg_split('/\s*,\s*/', trim($rawDomains), -1, PREG_SPLIT_NO_EMPTY);
    $normalized = [];

    foreach ($domains as $domain) {
        $domain = strtolower(trim($domain));

        if ($domain !== '' && ve_is_valid_domain($domain)) {
            $normalized[$domain] = $domain;
        }
    }

    return array_values($normalized);
}

function ve_allowed_payment_methods(): array
{
    return [
        'Webmoney',
        'Payeer',
        'Capitalist',
        'AdvCash',
        'PerfectMoney',
        'Bitcoin',
        'Ethereum',
        'Bitcoin Cash',
        'Litecoin',
        'USDT TRC20',
    ];
}

function ve_save_account_settings(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());

    $paymentMethod = trim((string) ($_POST['usr_pay_type'] ?? ''));
    $paymentId = trim((string) ($_POST['usr_pay_email'] ?? ''));
    $adsMode = trim((string) ($_POST['dood_ads_mode'] ?? ''));
    $uploaderType = trim((string) ($_POST['usr_content_type'] ?? '0'));
    $embedDomains = ve_normalize_domain_list((string) ($_POST['embed_domain_allowed'] ?? ''));

    if (!in_array($paymentMethod, ve_allowed_payment_methods(), true)) {
        ve_fail_form_submission('Choose a valid payout method.', '/dashboard/settings#details');
    }

    if (!in_array($adsMode, ['', '1', '2', '3', '4'], true)) {
        ve_fail_form_submission('Choose a valid ads mode.', '/dashboard/settings#details');
    }

    if (!in_array($uploaderType, ['0', '1', '2', '3'], true)) {
        ve_fail_form_submission('Choose a valid uploader type.', '/dashboard/settings#details');
    }

    $stmt = ve_db()->prepare(
        'UPDATE user_settings SET
            payment_method = :payment_method,
            payment_id = :payment_id,
            ads_mode = :ads_mode,
            uploader_type = :uploader_type,
            embed_domains = :embed_domains,
            embed_access_only = :embed_access_only,
            disable_download = :disable_download,
            disable_adblock = :disable_adblock,
            extract_subtitles = :extract_subtitles,
            updated_at = :updated_at
         WHERE user_id = :user_id'
    );

    $stmt->execute([
        ':payment_method' => $paymentMethod,
        ':payment_id' => $paymentId,
        ':ads_mode' => $adsMode,
        ':uploader_type' => $uploaderType,
        ':embed_domains' => json_encode($embedDomains, JSON_UNESCAPED_SLASHES),
        ':embed_access_only' => isset($_POST['usr_embed_access_only']) ? 1 : 0,
        ':disable_download' => isset($_POST['usr_disable_download']) ? 1 : 0,
        ':disable_adblock' => isset($_POST['usr_disable_adb']) ? 1 : 0,
        ':extract_subtitles' => isset($_POST['usr_srt_burn']) ? 1 : 0,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    ve_add_notification($userId, 'Account settings updated', 'Your payout and playback defaults were saved.');
    ve_success_form_submission('Account details saved successfully.', '/dashboard/settings#details', [
        'settings' => [
            'payment_method' => $paymentMethod,
            'payment_id' => $paymentId,
            'ads_mode' => $adsMode,
            'uploader_type' => $uploaderType,
            'embed_domains' => $embedDomains,
        ],
    ]);
}

function ve_save_password(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());
    $user = ve_require_auth();
    $currentPassword = (string) ($_POST['password_current'] ?? '');
    $newPassword = (string) ($_POST['password_new'] ?? '');
    $confirmPassword = (string) ($_POST['password_new2'] ?? '');

    $error = ve_validate_password($newPassword, $confirmPassword);

    if ($error !== null) {
        ve_fail_form_submission($error, '/dashboard/settings#password_settings');
    }

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        ve_fail_form_submission('Your current password is incorrect.', '/dashboard/settings#password_settings');
    }

    $stmt = ve_db()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':updated_at' => ve_now(),
        ':id' => $userId,
    ]);

    ve_add_notification($userId, 'Password changed', 'Your dashboard password was updated.');
    ve_success_form_submission('Password updated successfully.', '/dashboard/settings#password_settings');
}

function ve_save_email(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());
    $email = strtolower(trim((string) ($_POST['usr_email'] ?? '')));
    $email2 = strtolower(trim((string) ($_POST['usr_email2'] ?? '')));
    $error = ve_validate_email($email);

    if ($error !== null) {
        ve_fail_form_submission($error, '/dashboard/settings#email_settings');
    }

    if ($email !== $email2) {
        ve_fail_form_submission('The new email addresses do not match.', '/dashboard/settings#email_settings');
    }

    $stmt = ve_db()->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) AND id <> :id LIMIT 1');
    $stmt->execute([
        ':email' => $email,
        ':id' => $userId,
    ]);

    if ($stmt->fetchColumn() !== false) {
        ve_fail_form_submission('That email address is already in use.', '/dashboard/settings#email_settings');
    }

    $update = ve_db()->prepare('UPDATE users SET email = :email, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':email' => $email,
        ':updated_at' => ve_now(),
        ':id' => $userId,
    ]);

    ve_add_notification($userId, 'Email updated', 'Your account email address was changed.');
    ve_success_form_submission('Email address updated successfully.', '/dashboard/settings#email_settings', [
        'email' => $email,
    ]);
}

function ve_store_logo_upload(array $file, int $userId): string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK) {
        ve_fail_form_submission('Logo upload failed. Please try again.', '/dashboard/settings#player_settings');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_file($tmpName)) {
        ve_fail_form_submission('Uploaded logo file was not found.', '/dashboard/settings#player_settings');
    }

    $imageInfo = @getimagesize($tmpName);

    if (!is_array($imageInfo)) {
        ve_fail_form_submission('Upload a valid image file for the player logo.', '/dashboard/settings#player_settings');
    }

    if (($imageInfo[0] ?? 0) > 300 || ($imageInfo[1] ?? 0) > 300) {
        ve_fail_form_submission('Logo images must be no larger than 300x300 pixels.', '/dashboard/settings#player_settings');
    }

    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = (string) ($imageInfo['mime'] ?? '');

    if (!isset($extensionMap[$mime])) {
        ve_fail_form_submission('Only PNG, JPG, GIF, and WEBP logos are supported.', '/dashboard/settings#player_settings');
    }

    $targetName = 'user-' . $userId . '-' . ve_timestamp() . '.' . $extensionMap[$mime];
    $targetPath = ve_storage_path('uploads', 'logos', $targetName);

    if (!move_uploaded_file($tmpName, $targetPath)) {
        if (!rename($tmpName, $targetPath)) {
            ve_fail_form_submission('Unable to store the uploaded logo.', '/dashboard/settings#player_settings');
        }
    }

    return 'storage/uploads/logos/' . $targetName;
}

function ve_delete_storage_relative_file(string $relativePath): void
{
    $absolutePath = ve_storage_relative_path_to_absolute($relativePath);

    if ($absolutePath === '' || !is_file($absolutePath)) {
        return;
    }

    @unlink($absolutePath);
}

function ve_detect_file_mime_type(string $path): string
{
    if (!is_file($path)) {
        return 'application/octet-stream';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

function ve_store_player_splash_upload(array $file, int $userId, string $currentPath = ''): string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK) {
        ve_fail_form_submission('Splash image upload failed. Please try again.', '/dashboard/settings#player_settings');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_file($tmpName)) {
        ve_fail_form_submission('Uploaded splash image was not found.', '/dashboard/settings#player_settings');
    }

    $imageInfo = @getimagesize($tmpName);

    if (!is_array($imageInfo)) {
        ve_fail_form_submission('Upload a valid image file for the splash image.', '/dashboard/settings#player_settings');
    }

    if (($imageInfo[0] ?? 0) > 4096 || ($imageInfo[1] ?? 0) > 4096) {
        ve_fail_form_submission('Splash images must be no larger than 4096x4096 pixels.', '/dashboard/settings#player_settings');
    }

    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    $mime = (string) ($imageInfo['mime'] ?? '');

    if (!isset($extensionMap[$mime])) {
        ve_fail_form_submission('Only PNG, JPG, and WEBP splash images are supported.', '/dashboard/settings#player_settings');
    }

    $targetName = 'user-' . $userId . '-' . ve_timestamp() . '.' . $extensionMap[$mime];
    $targetPath = ve_player_storage_path('splashes', $targetName);
    ve_ensure_directory(dirname($targetPath));

    if (!move_uploaded_file($tmpName, $targetPath)) {
        if (!rename($tmpName, $targetPath)) {
            ve_fail_form_submission('Unable to store the uploaded splash image.', '/dashboard/settings#player_settings');
        }
    }

    if ($currentPath !== '' && str_starts_with($currentPath, 'private/player/splashes/')) {
        ve_delete_storage_relative_file($currentPath);
    }

    return 'private/player/splashes/' . $targetName;
}

function ve_save_player_settings(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());

    $user = ve_get_user_by_id($userId);

    if (!is_array($user)) {
        ve_fail_form_submission('Unable to load the current account.', '/dashboard/settings#player_settings', 404);
    }

    $settings = ve_get_user_settings($userId);
    $currentPlayerImageMode = (string) ($settings['player_image_mode'] ?? '');
    $currentPlayerColour = strtolower((string) ($settings['player_colour'] ?? 'ff9900'));
    $currentEmbedWidth = (int) ($settings['embed_width'] ?? 600);
    $currentEmbedHeight = (int) ($settings['embed_height'] ?? 480);
    $playerImageMode = array_key_exists('usr_player_image', $_POST)
        ? trim((string) $_POST['usr_player_image'])
        : $currentPlayerImageMode;
    $playerColour = array_key_exists('usr_player_colour', $_POST)
        ? ltrim(trim((string) $_POST['usr_player_colour']), '#')
        : $currentPlayerColour;
    $embedWidth = array_key_exists('embedcode_width', $_POST)
        ? max(200, min(4000, (int) $_POST['embedcode_width']))
        : $currentEmbedWidth;
    $embedHeight = array_key_exists('embedcode_height', $_POST)
        ? max(200, min(4000, (int) $_POST['embedcode_height']))
        : $currentEmbedHeight;
    $logoPath = (string) ($settings['logo_path'] ?? '');
    $splashImagePath = (string) ($settings['splash_image_path'] ?? '');

    if (!in_array($playerImageMode, ['', 'splash', 'single'], true)) {
        ve_fail_form_submission('Choose a valid player image mode.', '/dashboard/settings#player_settings');
    }

    if (!preg_match('/^[A-Fa-f0-9]{6}$/', $playerColour)) {
        ve_fail_form_submission('Choose a valid player colour.', '/dashboard/settings#player_settings');
    }

    if (isset($_FILES['logo_image']) && is_array($_FILES['logo_image']) && (int) ($_FILES['logo_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $logoPath = ve_store_logo_upload($_FILES['logo_image'], $userId);
    }

    if (isset($_FILES['splash_image']) && is_array($_FILES['splash_image']) && (int) ($_FILES['splash_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $splashImagePath = ve_store_player_splash_upload($_FILES['splash_image'], $userId, $splashImagePath);
    }

    $submittedShowEmbedTitle = array_key_exists('usr_embed_title', $_POST)
        ? (isset($_POST['usr_embed_title']) ? 1 : 0)
        : (int) ($settings['show_embed_title'] ?? 0);
    $submittedAutoSubtitleStart = array_key_exists('usr_sub_auto_start', $_POST)
        ? (isset($_POST['usr_sub_auto_start']) ? 1 : 0)
        : (int) ($settings['auto_subtitle_start'] ?? 0);
    $playerColourChanged = strtolower($playerColour) !== $currentPlayerColour;
    $playerColourAllowed = ve_user_is_premium($user);
    $otherChangesDetected = $submittedShowEmbedTitle !== (int) ($settings['show_embed_title'] ?? 0)
        || $submittedAutoSubtitleStart !== (int) ($settings['auto_subtitle_start'] ?? 0)
        || $playerImageMode !== (string) ($settings['player_image_mode'] ?? '')
        || $embedWidth !== (int) ($settings['embed_width'] ?? 600)
        || $embedHeight !== (int) ($settings['embed_height'] ?? 480)
        || $logoPath !== (string) ($settings['logo_path'] ?? '')
        || $splashImagePath !== (string) ($settings['splash_image_path'] ?? '');

    if (!$playerColourAllowed && $playerColourChanged && !$otherChangesDetected) {
        $message = 'Custom player colour requires an active premium subscription. Upgrade on Premium Plans to unlock this setting.';

        if (ve_request_expects_json()) {
            ve_json([
                'status' => 'fail',
                'message' => $message,
                'player' => ve_player_settings_payload($userId, $settings),
                'premium_required_fields' => ['usr_player_colour'],
            ], 403);
        }

        ve_flash('danger', $message);
        ve_redirect('/dashboard/settings#player_settings');
    }

    $savedPlayerColour = !$playerColourAllowed && $playerColourChanged ? $currentPlayerColour : strtolower($playerColour);

    $stmt = ve_db()->prepare(
        'UPDATE user_settings SET
            show_embed_title = :show_embed_title,
            auto_subtitle_start = :auto_subtitle_start,
            player_image_mode = :player_image_mode,
            player_colour = :player_colour,
            logo_path = :logo_path,
            splash_image_path = :splash_image_path,
            embed_width = :embed_width,
            embed_height = :embed_height,
            updated_at = :updated_at
         WHERE user_id = :user_id'
    );
    $stmt->execute([
        ':show_embed_title' => $submittedShowEmbedTitle,
        ':auto_subtitle_start' => $submittedAutoSubtitleStart,
        ':player_image_mode' => $playerImageMode,
        ':player_colour' => $savedPlayerColour,
        ':logo_path' => $logoPath,
        ':splash_image_path' => $splashImagePath,
        ':embed_width' => $embedWidth,
        ':embed_height' => $embedHeight,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    $savedSettings = ve_get_user_settings($userId);

    if (!$playerColourAllowed && $playerColourChanged && $otherChangesDetected) {
        $message = 'Other player settings were saved, but the custom player colour was skipped because it requires an active premium subscription.';
        ve_add_notification($userId, 'Player settings partially updated', 'Player settings were saved, but the custom player colour still requires a premium subscription.');

        if (ve_request_expects_json()) {
            ve_json([
                'status' => 'warning',
                'message' => $message,
                'player' => ve_player_settings_payload($userId, $savedSettings),
                'premium_required_fields' => ['usr_player_colour'],
            ]);
        }

        ve_flash('warning', $message);
        ve_redirect('/dashboard/settings#player_settings');
    }

    ve_add_notification($userId, 'Player settings updated', 'Your player appearance defaults were saved.');
    ve_success_form_submission('Player settings saved successfully.', '/dashboard/settings#player_settings', [
        'player' => ve_player_settings_payload($userId, $savedSettings),
    ]);
}

function ve_render_player_splash_preview(int $userId): void
{
    $settings = ve_get_user_settings($userId);
    $path = ve_storage_relative_path_to_absolute((string) ($settings['splash_image_path'] ?? ''));

    if ($path === '' || !is_file($path)) {
        ve_not_found();
    }

    header('Content-Type: ' . ve_detect_file_mime_type($path));
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function ve_save_ad_settings(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());

    $vastUrl = trim((string) ($_POST['vast_url'] ?? ''));
    $popType = trim((string) ($_POST['pop_type'] ?? '1'));
    $popUrl = trim((string) ($_POST['pop_url'] ?? ''));
    $popCap = max(0, min(86400, (int) ($_POST['pop_cap'] ?? 0)));

    if ($vastUrl !== '' && filter_var($vastUrl, FILTER_VALIDATE_URL) === false) {
        ve_fail_form_submission('Enter a valid VAST URL.', '/dashboard/settings#premium_settings');
    }

    if ($popUrl !== '' && filter_var($popUrl, FILTER_VALIDATE_URL) === false) {
        ve_fail_form_submission('Enter a valid popup URL.', '/dashboard/settings#premium_settings');
    }

    if (!in_array($popType, ['1', '2'], true)) {
        ve_fail_form_submission('Choose a valid popup type.', '/dashboard/settings#premium_settings');
    }

    $advertisingEnabled = ve_premium_bandwidth_settings_configured([
        'vast_url' => $vastUrl,
        'pop_url' => $popUrl,
    ]);

    if ($advertisingEnabled) {
        $bandwidth = ve_premium_bandwidth_totals($userId);

        if ((int) ($bandwidth['purchased_bytes'] ?? 0) <= 0) {
            ve_fail_form_submission(
                'Own adverts require premium bandwidth. Purchase premium bandwidth before enabling VAST or popup ads.',
                '/dashboard/settings#premium_settings',
                403
            );
        }
    }

    $stmt = ve_db()->prepare(
        'UPDATE user_settings SET
            vast_url = :vast_url,
            pop_type = :pop_type,
            pop_url = :pop_url,
            pop_cap = :pop_cap,
            updated_at = :updated_at
         WHERE user_id = :user_id'
    );
    $stmt->execute([
        ':vast_url' => $vastUrl,
        ':pop_type' => $popType,
        ':pop_url' => $popUrl,
        ':pop_cap' => $popCap,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    ve_add_notification($userId, 'Advert settings updated', 'Your VAST and popup ad settings were saved.');
    ve_success_form_submission('Own adverts settings saved successfully.', '/dashboard/settings#premium_settings', [
        'advertising' => [
            'vast_url' => $vastUrl,
            'pop_type' => $popType,
            'pop_url' => $popUrl,
            'pop_cap' => $popCap,
        ],
    ]);
}

function ve_parse_api_limit_input(string $rawValue, string $label, int $maxValue): int
{
    $rawValue = trim($rawValue);

    if ($rawValue === '') {
        return 0;
    }

    if (!preg_match('/^\d+$/', $rawValue)) {
        ve_fail_form_submission($label . ' must be a whole number.', '/dashboard/settings#api_access');
    }

    $value = (int) $rawValue;

    if ($value < 0 || $value > $maxValue) {
        ve_fail_form_submission($label . ' must be between 0 and ' . number_format($maxValue) . '.', '/dashboard/settings#api_access');
    }

    return $value;
}

function ve_save_api_settings(int $userId): void
{
    ve_require_csrf(ve_request_csrf_token());

    $apiEnabled = isset($_POST['api_enabled']) ? 1 : 0;
    $requestsPerHour = ve_parse_api_limit_input((string) ($_POST['api_requests_per_hour'] ?? '0'), 'Requests per hour', 100000);
    $requestsPerDay = ve_parse_api_limit_input((string) ($_POST['api_requests_per_day'] ?? '0'), 'Requests per day', 1000000);
    $uploadsPerDay = ve_parse_api_limit_input((string) ($_POST['api_uploads_per_day'] ?? '0'), 'Uploads per day', 10000);

    if ($requestsPerDay > 0 && $requestsPerHour > $requestsPerDay) {
        ve_fail_form_submission('Requests per hour cannot exceed requests per day.', '/dashboard/settings#api_access');
    }

    $stmt = ve_db()->prepare(
        'UPDATE user_settings
         SET api_enabled = :api_enabled,
             api_requests_per_hour = :api_requests_per_hour,
             api_requests_per_day = :api_requests_per_day,
             api_uploads_per_day = :api_uploads_per_day,
             updated_at = :updated_at
         WHERE user_id = :user_id'
    );
    $stmt->execute([
        ':api_enabled' => $apiEnabled,
        ':api_requests_per_hour' => $requestsPerHour,
        ':api_requests_per_day' => $requestsPerDay,
        ':api_uploads_per_day' => $uploadsPerDay,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    ve_add_notification(
        $userId,
        'API policy updated',
        $apiEnabled === 1
            ? 'API access policy and usage limits were updated.'
            : 'API access was disabled for your account.'
    );

    ve_success_form_submission('API access policy saved successfully.', '/dashboard/settings#api_access', [
        'api' => ve_api_usage_snapshot($userId),
    ]);
}

function ve_mark_notification_read(int $userId, int $notificationId): void
{
    $stmt = ve_db()->prepare('UPDATE notifications SET is_read = 1, read_at = :read_at WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        ':read_at' => ve_now(),
        ':id' => $notificationId,
        ':user_id' => $userId,
    ]);
}

function ve_delete_notification(int $userId, int $notificationId): bool
{
    $stmt = ve_db()->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        ':id' => $notificationId,
        ':user_id' => $userId,
    ]);

    return $stmt->rowCount() > 0;
}

function ve_clear_notifications(int $userId): int
{
    $stmt = ve_db()->prepare('DELETE FROM notifications WHERE user_id = :user_id');
    $stmt->execute([
        ':user_id' => $userId,
    ]);

    return (int) $stmt->rowCount();
}

function ve_fetch_notifications(int $userId): array
{
    $stmt = ve_db()->prepare('SELECT id, subject, message, is_read, created_at FROM notifications WHERE user_id = :user_id ORDER BY id DESC LIMIT 20');
    $stmt->execute([':user_id' => $userId]);

    $items = [];

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'subject' => (string) $row['subject'],
            'message' => (string) $row['message'],
            'read' => (int) $row['is_read'],
            'cr' => ve_notification_date((string) $row['created_at']),
        ];
    }

    return $items;
}

function ve_is_valid_domain(string $domain): bool
{
    return preg_match('/^(?!:\/\/)([A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/', $domain) === 1;
}

function ve_domain_override_a_records(string $domain): ?array
{
    $rawMap = trim((string) (getenv('VE_DNS_STATIC_MAP') ?: ''));

    if ($rawMap === '') {
        return null;
    }

    $decoded = json_decode($rawMap, true);

    if (!is_array($decoded)) {
        $decoded = [];

        foreach (preg_split('/\s*;\s*/', $rawMap, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (!is_string($entry) || !str_contains($entry, '=')) {
                continue;
            }

            [$mapDomain, $ipsRaw] = explode('=', $entry, 2);
            $mapDomain = strtolower(trim($mapDomain));

            if ($mapDomain === '') {
                continue;
            }

            $decoded[$mapDomain] = preg_split('/\s*,\s*/', trim($ipsRaw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    foreach ($decoded as $mapDomain => $ips) {
        if (!is_string($mapDomain) || strcasecmp($mapDomain, $domain) !== 0) {
            continue;
        }

        if (is_string($ips)) {
            $ips = [$ips];
        }

        if (!is_array($ips)) {
            return [];
        }

        return array_values(array_filter($ips, static fn ($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
    }

    return null;
}

function ve_check_domain_status(string $domain): array
{
    $target = ve_config()['custom_domain_target'];
    $status = 'lookup_failed';
    $error = 'DNS lookup could not be completed right now. Try again in a few minutes.';
    $userMessage = 'DNS lookup could not be completed right now. Try again in a few minutes.';
    $resolvedIps = ve_domain_override_a_records($domain);

    if ($resolvedIps === null) {
        $resolvedIps = [];

        if (function_exists('dns_get_record')) {
            try {
                $records = @dns_get_record($domain, DNS_A);

                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (is_array($record) && isset($record['ip']) && is_string($record['ip'])) {
                            $resolvedIps[] = $record['ip'];
                        }
                    }
                }
            } catch (Throwable $throwable) {
                $error = 'DNS lookup failed: ' . $throwable->getMessage();
            }
        }

        if ($resolvedIps === [] && function_exists('gethostbynamel')) {
            $fallbackIps = @gethostbynamel($domain);

            if (is_array($fallbackIps)) {
                $resolvedIps = $fallbackIps;
            }
        }
    }

    $resolvedIps = array_values(array_unique(array_filter($resolvedIps, static fn ($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)));

    if ($resolvedIps === []) {
        $status = $error !== '' ? 'lookup_failed' : 'pending_dns';
        $error = $error !== '' ? $error : 'No A record found yet.';
        $userMessage = $status === 'lookup_failed'
            ? 'DNS lookup could not be completed right now. Try again in a few minutes.'
            : 'No A record was found for ' . $domain . '. Point it to ' . $target . ' and try again after DNS propagation.';
    } elseif (in_array($target, $resolvedIps, true)) {
        $status = 'active';
        $error = '';
        $userMessage = 'Domain connected successfully.';
    } else {
        $status = 'pending_dns';
        $resolvedLabel = implode(', ', $resolvedIps);
        $error = 'A record points to ' . $resolvedLabel . ' instead of ' . $target . '.';
        $userMessage = 'This domain currently points to ' . $resolvedLabel . '. Update the A record to ' . $target . ' before adding it.';
    }

    return [
        'status' => $status,
        'dns_target' => $target,
        'dns_check_error' => $error,
        'user_message' => $userMessage,
        'dns_last_checked_at' => ve_now(),
        'resolved_ips' => $resolvedIps,
    ];
}

function ve_list_custom_domains(int $userId): array
{
    $stmt = ve_db()->prepare('SELECT * FROM custom_domains WHERE user_id = :user_id ORDER BY domain ASC');
    $stmt->execute([':user_id' => $userId]);
    $domains = [];

    foreach ($stmt->fetchAll() as $domain) {
        $domains[] = [
            'domain' => (string) $domain['domain'],
            'status' => (string) $domain['status'],
            'dns_target' => (string) $domain['dns_target'],
            'dns_last_checked_at' => (string) ($domain['dns_last_checked_at'] ?? ''),
            'message' => (string) ($domain['dns_check_error'] ?? ''),
        ];
    }

    return $domains;
}

function ve_handle_premium_checkout_quote(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $context = ve_premium_checkout_context_from_request((int) $user['id'], $_POST);

    ve_json([
        'status' => 'ok',
        'checkout' => $context['quote'],
        'summary' => ve_premium_page_payload((int) $user['id']),
    ]);
}

function ve_handle_premium_checkout_balance(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $context = ve_premium_checkout_context_from_request((int) $user['id'], $_POST);
    $payment = (array) ($context['payment'] ?? []);

    if (($payment['code'] ?? '') !== 'balance') {
        ve_json([
            'status' => 'fail',
            'message' => 'Balance checkout requires the balance payment method.',
        ], 422);
    }

    $quote = (array) ($context['quote'] ?? []);

    if (!((bool) ($quote['can_pay'] ?? false))) {
        ve_json([
            'status' => 'fail',
            'message' => (string) ($quote['insufficient_balance_message'] ?? 'Your current balance is not high enough for this purchase.'),
            'checkout' => $quote,
            'summary' => ve_premium_page_payload((int) $user['id']),
        ], 422);
    }

    $userId = (int) $user['id'];
    $product = (array) ($context['product'] ?? []);
    $purchaseType = (string) ($context['purchase_type'] ?? '');
    $amountMicroUsd = (int) ($product['amount_micro_usd'] ?? 0);
    $orderCode = ve_premium_order_code();
    $pdo = ve_db();

    try {
        $pdo->beginTransaction();

        if (ve_dashboard_balance_micro_usd($userId) < $amountMicroUsd) {
            throw new RuntimeException('insufficient_balance');
        }

        $now = ve_now();
        ve_insert_premium_order($pdo, [
            'order_code' => $orderCode,
            'user_id' => $userId,
            'purchase_type' => $purchaseType,
            'package_id' => (string) ($product['id'] ?? ''),
            'package_title' => (string) ($product['title'] ?? ''),
            'payment_method' => 'balance',
            'status' => 'completed',
            'amount_micro_usd' => $amountMicroUsd,
            'bandwidth_bytes' => (int) ($product['bandwidth_bytes'] ?? 0),
            'plan_interval_spec' => (string) ($product['interval_spec'] ?? ''),
            'metadata' => [
                'payment_label' => 'Balance',
                'description' => (string) ($product['description'] ?? ''),
            ],
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => $now,
        ]);
        ve_insert_balance_ledger_entry(
            $pdo,
            $userId,
            'debit',
            'premium_order',
            $orderCode,
            -$amountMicroUsd,
            'Premium checkout: ' . (string) ($product['description'] ?? ($product['title'] ?? 'Premium purchase')),
            [
                'purchase_type' => $purchaseType,
                'package_id' => (string) ($product['id'] ?? ''),
                'payment_method' => 'balance',
            ]
        );
        $purchaseResult = ve_apply_premium_purchase($pdo, $userId, $purchaseType, $product);
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($throwable->getMessage() === 'insufficient_balance') {
            ve_json([
                'status' => 'fail',
                'message' => 'Your current balance is not high enough for this purchase.',
                'checkout' => ve_premium_checkout_quote($userId, $purchaseType, $product, $payment),
                'summary' => ve_premium_page_payload($userId),
            ], 422);
        }

        ve_json([
            'status' => 'fail',
            'message' => 'Unable to complete the premium purchase right now.',
        ], 500);
    }

    ve_current_user(true);
    $message = (string) ($purchaseResult['message'] ?? 'Premium purchase completed successfully.');
    ve_add_notification(
        $userId,
        $purchaseType === 'account' ? 'Premium account purchased' : 'Premium bandwidth purchased',
        $message
    );

    ve_json([
        'status' => 'ok',
        'message' => $message,
        'order_code' => $orderCode,
        'summary' => ve_premium_page_payload($userId),
    ]);
}

function ve_handle_premium_checkout_crypto(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $context = ve_premium_checkout_context_from_request((int) $user['id'], $_POST);
    $payment = (array) ($context['payment'] ?? []);

    if (($payment['kind'] ?? '') !== 'crypto') {
        ve_json([
            'status' => 'fail',
            'message' => 'Crypto checkout requires a crypto payment method.',
        ], 422);
    }

    $userId = (int) $user['id'];
    $product = (array) ($context['product'] ?? []);
    $purchaseType = (string) ($context['purchase_type'] ?? '');
    $quote = (array) ($context['quote'] ?? []);
    $orderCode = ve_premium_order_code();
    $invoice = ve_premium_dummy_crypto_invoice($payment, $quote, $orderCode);
    $now = ve_now();

    try {
        ve_insert_premium_order(ve_db(), [
            'order_code' => $orderCode,
            'user_id' => $userId,
            'purchase_type' => $purchaseType,
            'package_id' => (string) ($product['id'] ?? ''),
            'package_title' => (string) ($product['title'] ?? ''),
            'payment_method' => (string) ($payment['code'] ?? ''),
            'status' => 'pending',
            'amount_micro_usd' => (int) ($product['amount_micro_usd'] ?? 0),
            'bandwidth_bytes' => (int) ($product['bandwidth_bytes'] ?? 0),
            'plan_interval_spec' => (string) ($product['interval_spec'] ?? ''),
            'crypto_currency_code' => (string) ($invoice['currency_code'] ?? ''),
            'crypto_currency_name' => (string) ($invoice['currency_name'] ?? ''),
            'crypto_amount' => (string) ($invoice['amount'] ?? ''),
            'crypto_address' => (string) ($invoice['address'] ?? ''),
            'payment_uri' => (string) ($invoice['payment_uri'] ?? ''),
            'qr_url' => (string) ($invoice['qr'] ?? ''),
            'metadata' => [
                'sandbox' => true,
                'payment_label' => (string) ($payment['label'] ?? ''),
                'description' => (string) ($product['description'] ?? ''),
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (Throwable $throwable) {
        ve_json([
            'status' => 'fail',
            'message' => 'Unable to create the crypto invoice right now.',
        ], 500);
    }

    ve_add_notification(
        $userId,
        'Crypto invoice created',
        'A sandbox ' . (string) ($payment['label'] ?? 'crypto') . ' invoice was generated for ' . (string) ($product['title'] ?? 'your premium checkout') . '.'
    );

    ve_json([
        'status' => 'ok',
        'message' => 'Sandbox crypto invoice created. Real on-chain confirmation will be added when the payment gateway is connected.',
        'checkout' => $quote,
        'invoice' => $invoice,
        'summary' => ve_premium_page_payload($userId),
    ]);
}

function ve_handle_custom_domain_list(): void
{
    $user = ve_require_auth();
    ve_json([
        'status' => 'ok',
        'domains' => ve_list_custom_domains((int) $user['id']),
        'dns_target' => ve_config()['custom_domain_target'],
    ]);
}

function ve_handle_custom_domain_add(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));

    if ($domain === '' || !ve_is_valid_domain($domain)) {
        ve_json([
            'status' => 'fail',
            'message' => 'Enter a valid domain like example.com.',
        ]);
    }

    $exists = ve_db()->prepare('SELECT id FROM custom_domains WHERE lower(domain) = lower(:domain) LIMIT 1');
    $exists->execute([':domain' => $domain]);

    if ($exists->fetchColumn() !== false) {
        ve_json([
            'status' => 'fail',
            'message' => 'That domain is already attached to an account.',
        ]);
    }

    $check = ve_check_domain_status($domain);

    if (($check['status'] ?? '') !== 'active') {
        ve_json([
            'status' => 'fail',
            'message' => (string) ($check['user_message'] ?? ('Point the domain to ' . $check['dns_target'] . ' before adding it.')),
            'dns_target' => $check['dns_target'],
            'dns_status' => $check['status'],
            'resolved_ips' => $check['resolved_ips'] ?? [],
        ], 422);
    }

    $stmt = ve_db()->prepare(
        'INSERT INTO custom_domains (user_id, domain, status, dns_target, dns_last_checked_at, dns_check_error, created_at, updated_at)
         VALUES (:user_id, :domain, :status, :dns_target, :dns_last_checked_at, :dns_check_error, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':user_id' => (int) $user['id'],
        ':domain' => $domain,
        ':status' => $check['status'],
        ':dns_target' => $check['dns_target'],
        ':dns_last_checked_at' => $check['dns_last_checked_at'],
        ':dns_check_error' => $check['dns_check_error'],
        ':created_at' => ve_now(),
        ':updated_at' => ve_now(),
    ]);

    ve_add_notification((int) $user['id'], 'Custom domain added', 'The custom domain ' . $domain . ' was added to your account.');
    ve_json([
        'status' => 'ok',
        'message' => 'Domain connected successfully.',
        'domains' => ve_list_custom_domains((int) $user['id']),
        'dns_target' => ve_config()['custom_domain_target'],
    ]);
}

function ve_handle_custom_domain_delete(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());
    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));

    $stmt = ve_db()->prepare('DELETE FROM custom_domains WHERE user_id = :user_id AND lower(domain) = lower(:domain)');
    $stmt->execute([
        ':user_id' => (int) $user['id'],
        ':domain' => $domain,
    ]);

    ve_add_notification((int) $user['id'], 'Custom domain removed', 'The custom domain ' . $domain . ' was removed from your account.');
    ve_json([
        'status' => 'ok',
        'message' => 'Domain removed successfully.',
        'domains' => ve_list_custom_domains((int) $user['id']),
        'dns_target' => ve_config()['custom_domain_target'],
    ]);
}

function ve_handle_delete_account(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $password = (string) ($_POST['password_confirmation'] ?? '');

    if ($reasonCode === '' || $reason === '' || $password === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Select a reason, add written details, and enter your current password.',
        ]);
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        ve_json([
            'status' => 'fail',
            'message' => 'Your current password is incorrect.',
        ]);
    }

    $pdo = ve_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO account_deletion_requests (user_id, reason_code, reason, status, created_at)
             VALUES (:user_id, :reason_code, :reason, :status, :created_at)'
        );
        $stmt->execute([
            ':user_id' => (int) $user['id'],
            ':reason_code' => $reasonCode,
            ':reason' => $reason,
            ':status' => 'processed',
            ':created_at' => ve_now(),
        ]);

        $suffix = (string) ((int) $user['id']) . '-' . ve_timestamp();
        $scrub = $pdo->prepare(
            'UPDATE users SET
                username = :username,
                email = :email,
                status = :status,
                deleted_at = :deleted_at,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $scrub->execute([
            ':username' => 'deleted_' . $suffix,
            ':email' => 'deleted+' . $suffix . '@local.invalid',
            ':status' => 'deleted',
            ':deleted_at' => ve_now(),
            ':updated_at' => ve_now(),
            ':id' => (int) $user['id'],
        ]);

        $pdo->prepare('DELETE FROM custom_domains WHERE user_id = :user_id')->execute([':user_id' => (int) $user['id']]);
        $pdo->prepare('UPDATE user_sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND revoked_at IS NULL')->execute([
            ':revoked_at' => ve_now(),
            ':user_id' => (int) $user['id'],
        ]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        $pdo->rollBack();
        ve_json([
            'status' => 'fail',
            'message' => 'Unable to delete the account right now.',
        ], 500);
    }

    ve_logout_current_user();
    ve_flash('success', 'Your account was deleted successfully.');

    ve_json([
        'status' => 'redirect',
        'message' => ve_url('/'),
    ]);
}

function ve_runtime_script_tag(): string
{
    return '<script>window.VE_BASE_PATH=' . json_encode(ve_base_path(), JSON_UNESCAPED_SLASHES) . ';window.VE_CSRF_TOKEN=' . json_encode(ve_csrf_token(), JSON_UNESCAPED_SLASHES) . ';</script>';
}

function ve_site_page_key(string $relativePath): string
{
    return match ($relativePath) {
        'index.html' => 'home',
        'pages/earn-money.html' => 'earn-money',
        'pages/premium.html' => 'premium',
        default => '',
    };
}

function ve_site_nav_item(string $label, string $url, bool $active = false): string
{
    $class = $active ? 'nav-item active' : 'nav-item';

    return '<li class="' . $class . '"> <a class="nav-link" href="' . ve_h($url) . '">' . ve_h($label) . '</a> </li>';
}

function ve_site_navbar_contents(string $relativePath, ?array $user): string
{
    $pageKey = ve_site_page_key($relativePath);
    $homeItem = ve_site_nav_item('Home', ve_url('/'), $pageKey === 'home');
    $earnMoneyItem = ve_site_nav_item('Earn Money', ve_url('/earn-money'), $pageKey === 'earn-money');
    $premiumItem = ve_site_nav_item('Premium', ve_url('/premium'), $pageKey === 'premium');

    if (is_array($user)) {
        $dashboardItem = ve_site_nav_item('Dashboard', ve_url('/dashboard'));
        $logoutUrl = ve_h(ve_url('/logout'));

        return <<<HTML
 <button class="navbar-toggler d-block d-sm-none" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"> <i class="fad fa-times"></i> </button> <ul class="navbar-nav ml-auto"> {$homeItem} {$earnMoneyItem} {$premiumItem} {$dashboardItem} </ul> <div class="form-inline ml-0 ml-sm-3"> <a href="{$logoutUrl}" class="btn btn-danger" type="button">Logout <i class="fad fa-sign-out-alt ml-2"></i></a> </div>
HTML;
    }

    $signInItem = '<li class="nav-item"> <a class="nav-link" data-toggle="modal" data-target="#login" href="#login">Sign in</a> </li>';

    return <<<HTML
 <button class="navbar-toggler d-block d-sm-none" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"> <i class="fad fa-times"></i> </button> <ul class="navbar-nav ml-auto"> {$homeItem} {$earnMoneyItem} {$premiumItem} {$signInItem} </ul> <div class="form-inline ml-0 ml-sm-3"> <a href="#register" class="btn btn-primary" data-toggle="modal" data-target="#register">Sign up</a> </div>
HTML;
}

function ve_runtime_html_transform(string $html, string $relativePath = ''): string
{
    $runtimeScript = ve_runtime_script_tag();
    $mainScriptUrl = ve_h(ve_url('/assets/js/main.js'));
    $legacyAdapterTag = '<script src="' . ve_h(ve_url('/assets/js/legacy_api_adapter.js')) . '"></script>';

    if (str_contains($html, '</head>')) {
        $html = str_replace('</head>', $runtimeScript . '</head>', $html);
    } else {
        $html = $runtimeScript . $html;
    }

    $html = str_replace('src="/assets/js/main__q_8bb33b25bfc8.js"', 'src="' . $mainScriptUrl . '"', $html);
    $html = str_replace('src="/assets/js/main__q_1404624346b5.js"', 'src="' . $mainScriptUrl . '"', $html);

    if ($relativePath !== '' && str_starts_with($relativePath, 'dashboard/') && str_contains($html, '</head>')) {
        $html = str_replace('</head>', $legacyAdapterTag . '</head>', $html);
    }

    if ($relativePath !== '' && str_starts_with($relativePath, 'dashboard/')) {
        $user = ve_current_user();

        if (is_array($user)) {
            $html = str_replace('videoengine', (string) $user['username'], $html);
        }

        $html = str_replace('href="/?op=logout"', 'href="/logout"', $html);
        $html = str_replace('href="/logout" class="nav-link"', 'href="/logout" class="nav-link logout"', $html);
        $html = str_replace('href="/logout" class="dropdown-item"', 'href="/logout" class="dropdown-item logout"', $html);
        $html = str_replace("href='/logout' class='nav-link'", "href='/logout' class='nav-link logout'", $html);
        $html = str_replace("href='/logout' class='dropdown-item'", "href='/logout' class='dropdown-item logout'", $html);
    }

    if ($relativePath === 'index.html' || str_starts_with($relativePath, 'pages/')) {
        $html = ve_html_replace_element_contents_by_id(
            $html,
            'navbarSupportedContent',
            ve_site_navbar_contents($relativePath, ve_current_user())
        );
    }

    if ($relativePath === 'index.html') {
        $html = str_replace(
            '<form method="POST" action="/" name="FL" class="js_auth">',
            '<form method="POST" action="/api/auth/login" name="FL" class="js_auth">',
            $html
        );
        $html = str_replace(
            '<form method="POST" onSubmit="return CheckForm(this)" class="js_auth">',
            '<form method="POST" action="/api/auth/register" onSubmit="return CheckForm(this)" class="js_auth">',
            $html
        );
        $html = str_replace(
            '<form method="POST" class="js_auth">',
            '<form method="POST" action="/api/auth/forgot" class="js_auth">',
            $html
        );
    }

    if ($relativePath === 'dashboard/request-payout.html') {
        $html = str_replace('<form method="POST">', '<form method="POST" action="/api/payouts/request">', $html);
    }

    return $html;
}

function ve_html_set_input_value(string $html, string $name, string $value): string
{
    $quotedName = preg_quote($name, '/');
    $escapedValue = ve_h($value);
    $pattern = '/<input\b(?=[^>]*\bname="' . $quotedName . '")[^>]*>/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($escapedValue): string {
        $input = $matches[0];

        if (preg_match('/\bvalue="[^"]*"/i', $input) === 1) {
            return (string) preg_replace('/\bvalue="[^"]*"/i', 'value="' . $escapedValue . '"', $input, 1);
        }

        return rtrim($input, '>') . ' value="' . $escapedValue . '">';
    }, $html, 1);
}

function ve_html_set_checkbox(string $html, string $name, bool $checked): string
{
    $quotedName = preg_quote($name, '/');
    $pattern = '/<input\b(?=[^>]*\bname="' . $quotedName . '")[^>]*>/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($checked): string {
        $input = preg_replace('/\schecked(?:="checked")?/i', '', $matches[0]) ?? $matches[0];

        if ($checked) {
            $input = rtrim($input, '>') . ' checked="checked">';
        }

        return $input;
    }, $html, 1);
}

function ve_html_set_select_value(string $html, string $name, string $value): string
{
    $quotedName = preg_quote($name, '/');
    $pattern = '/(<select\b(?=[^>]*\bname="' . $quotedName . '")[^>]*>)(.*?)(<\/select>)/is';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($value): string {
        $options = preg_replace('/\sselected(?:="selected")?/i', '', $matches[2]) ?? $matches[2];
        $quotedValue = preg_quote($value, '/');
        $optionPattern = '/(<option\b(?=[^>]*\bvalue="' . $quotedValue . '")[^>]*)(>)/i';
        $options = preg_replace($optionPattern, '$1 selected="selected"$2', $options, 1) ?? $options;

        return $matches[1] . $options . $matches[3];
    }, $html, 1);
}

function ve_html_append_class_attribute(string $attributes, string $className): string
{
    if (preg_match('/\bclass="([^"]*)"/i', $attributes, $matches) === 1) {
        $classes = preg_split('/\s+/', trim((string) $matches[1])) ?: [];
        $classes = array_values(array_filter($classes, static fn ($item): bool => is_string($item) && $item !== ''));

        if (!in_array($className, $classes, true)) {
            $classes[] = $className;
        }

        return (string) (preg_replace(
            '/\bclass="[^"]*"/i',
            'class="' . ve_h(implode(' ', $classes)) . '"',
            $attributes,
            1
        ) ?? $attributes);
    }

    return rtrim($attributes) . ' class="' . ve_h($className) . '"';
}

function ve_settings_bind_form(string $html, string $op, string $action): string
{
    $quotedOp = preg_quote($op, '/');
    $token = ve_h(ve_csrf_token());
    $pattern = '/<form\b([^>]*)>\s*<input type="hidden" name="op" value="' . $quotedOp . '">\s*(?:<input type="hidden" name="token" value="[^"]*">\s*)?/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($action, $op, $token): string {
        $attributes = preg_replace('/\saction="[^"]*"/i', '', $matches[1]) ?? $matches[1];
        $attributes = ve_html_append_class_attribute($attributes, 'js-settings-form');

        return '<form' . $attributes . ' action="' . ve_h($action) . '">' . "\n                        "
            . '<input type="hidden" name="token" value="' . $token . '">' . "\n                        "
            . '<input type="hidden" name="op" value="' . ve_h($op) . '">' . "\n                        "
            . '<div class="settings-inline-feedback alert d-none js-form-feedback" role="alert"></div>' . "\n                        ";
    }, $html, 1);
}

function ve_settings_bind_delete_account_form(string $html): string
{
    $token = ve_h(ve_csrf_token());
    $pattern = '/<form class="delete-account-form"([^>]*)>\s*(?:<input type="hidden" name="token" value="[^"]*">\s*)?/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($token): string {
        $attributes = $matches[1];
        $attributes = preg_replace('/\saction="[^"]*"/i', '', $attributes) ?? $attributes;

        return '<form class="delete-account-form js-settings-form"' . $attributes . ' action="/account/delete">' . "\n                        "
            . '<input type="hidden" name="token" value="' . $token . '">' . "\n                        "
            . '<div class="settings-inline-feedback alert d-none js-form-feedback" role="alert"></div>' . "\n                        ";
    }, $html, 1);
}

function ve_html_replace_element_contents_by_id(string $html, string $id, string $content): string
{
    $quotedId = preg_quote($id, '/');
    $openPattern = '/<(?P<tag>[A-Za-z0-9]+)\b(?=[^>]*\bid=(["\'])' . $quotedId . '\2)[^>]*>/i';

    if (preg_match($openPattern, $html, $openMatches, PREG_OFFSET_CAPTURE) !== 1) {
        return $html;
    }

    $openingTag = $openMatches[0][0];
    $openingTagStart = (int) $openMatches[0][1];
    $openingTagEnd = $openingTagStart + strlen($openingTag);
    $tagName = strtolower((string) $openMatches['tag'][0]);
    $tokenPattern = '/<\/?' . preg_quote($tagName, '/') . '\b[^>]*>/i';
    $depth = 1;
    $offset = $openingTagEnd;

    while (preg_match($tokenPattern, $html, $tokenMatches, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $token = $tokenMatches[0][0];
        $tokenStart = (int) $tokenMatches[0][1];
        $offset = $tokenStart + strlen($token);

        if (str_starts_with($token, '</')) {
            $depth--;

            if ($depth === 0) {
                return substr($html, 0, $openingTagEnd) . $content . substr($html, $tokenStart);
            }

            continue;
        }

        if (!preg_match('/\/\s*>$/', $token)) {
            $depth++;
        }
    }

    return $html;
}

function ve_render_api_activity_rows_html(array $activity): string
{
    if ($activity === []) {
        return '<tr><td colspan="6" class="text-center text-muted">No API activity recorded yet.</td></tr>';
    }

    $rows = [];

    foreach ($activity as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $rows[] = sprintf(
            '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td><span class="%s">%s (%d)</span></td><td>%s</td></tr>',
            ve_h((string) ($entry['created_at_label'] ?? 'Unknown')),
            ve_h((string) ($entry['http_method'] ?? 'GET')),
            ve_h((string) ($entry['endpoint'] ?? '')),
            ve_h(ucfirst((string) ($entry['request_kind'] ?? 'request'))),
            ve_h((string) ($entry['status_class'] ?? 'text-muted')),
            ve_h((string) ($entry['status_label'] ?? 'Unknown')),
            (int) ($entry['status_code'] ?? 0),
            ve_h((string) ($entry['bytes_in_human'] ?? '0 B'))
        );
    }

    return implode("\n", $rows);
}

function ve_settings_script(): string
{
    $domainTarget = json_encode(ve_config()['custom_domain_target'], JSON_UNESCAPED_SLASHES);

    return <<<HTML
<script type="text/javascript">
    $(document).ready(function () {
        var basePath = window.VE_BASE_PATH || '';
        var csrfToken = window.VE_CSRF_TOKEN || '';
        var dnsTarget = {$domainTarget};
        var ajaxHeaders = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };

        function csrfAjaxHeaders() {
            return $.extend({}, ajaxHeaders, {
                'X-CSRF-Token': csrfToken
            });
        }

        function appUrl(path) {
            if (!path) {
                return basePath || '/';
            }

            if (/^(?:[a-z][a-z0-9+.-]*:)?\\/\\//i.test(path)) {
                return path;
            }

            if (basePath && (path === basePath || path.indexOf(basePath + '/') === 0)) {
                return path;
            }

            if (path.charAt(0) !== '/') {
                path = '/' + path;
            }

            return basePath + path;
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (character) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[character];
            });
        }

        function clearLegacyFlash() {
            $('.details.settings_data > .alert').remove();
        }

        function clearFeedbackElement(\$feedback) {
            clearFeedbackTimer(\$feedback);
            \$feedback
                .stop(true, true)
                .addClass('d-none')
                .removeClass('alert-success alert-danger alert-warning')
                .removeAttr('style aria-live')
                .text('');
        }

        function ensureFormFeedback(\$form) {
            var \$feedback = \$form.children('.js-form-feedback').first();

            if (!\$feedback.length) {
                \$feedback = $('<div class="settings-inline-feedback alert d-none js-form-feedback" role="alert"></div>');
                var \$firstVisibleField = \$form.children().not('input[type="hidden"]').first();

                if (\$firstVisibleField.length) {
                    \$firstVisibleField.before(\$feedback);
                } else {
                    \$form.prepend(\$feedback);
                }
            }

            return \$feedback;
        }

        function clearFeedbackTimer(\$feedback) {
            var timerId = \$feedback.data('dismissTimer');

            if (timerId) {
                window.clearTimeout(timerId);
                \$feedback.removeData('dismissTimer');
            }
        }

        function updateFeedback(\$feedback, type, message, autoDismiss) {
            clearFeedbackTimer(\$feedback);
            if (!message) {
                clearFeedbackElement(\$feedback);
                return;
            }

            var alertClass = 'alert-danger';

            if (type === 'success') {
                alertClass = 'alert-success';
            } else if (type === 'warning') {
                alertClass = 'alert-warning';
            }

            \$feedback.stop(true, true);
            \$feedback
                .removeClass('d-none alert-success alert-danger alert-warning')
                .addClass(alertClass)
                .attr('aria-live', type === 'success' ? 'polite' : 'assertive')
                .text(message || '')
                .hide()
                .slideDown(160);

            if (!autoDismiss) {
                return;
            }

            \$feedback.data('dismissTimer', window.setTimeout(function () {
                \$feedback.stop(true, true).slideUp(180, function () {
                    clearFeedbackElement(\$feedback);
                });
            }, 4500));
        }

        function showFormFeedback(\$form, type, message) {
            var \$feedback = ensureFormFeedback(\$form);
            updateFeedback(\$feedback, type, message, type === 'success' && !!message);
        }

        function ensureSidebarFeedback() {
            var \$container = $('.settings_menu_wrap .form-group').first();
            var \$feedback = \$container.children('.js-sidebar-feedback').first();

            if (!\$feedback.length) {
                \$feedback = $('<div class="settings-inline-feedback alert d-none js-sidebar-feedback mt-3" role="alert"></div>');
                \$container.append(\$feedback);
            }

            return \$feedback;
        }

        function showSidebarFeedback(type, message) {
            var \$feedback = ensureSidebarFeedback();
            updateFeedback(\$feedback, type, message, type === 'success' && !!message);
        }

        function ensureDomainFeedback() {
            var \$feedback = $('#response');

            if (!\$feedback.length) {
                \$feedback = $('<div id="response" class="settings-inline-feedback alert d-none" role="alert"></div>');
                $('#custom_domain .settings-panel-subtitle').after(\$feedback);
            }

            return \$feedback;
        }

        function showDomainMessage(type, message) {
            updateFeedback(ensureDomainFeedback(), type, message, type === 'success' && !!message);
        }

        function ensureApiKeyModalFeedback() {
            return $('#apiKeyModal').find('.js-api-key-modal-feedback').first();
        }

        function showApiKeyModalFeedback(type, message) {
            updateFeedback(ensureApiKeyModalFeedback(), type, message, type === 'success' && !!message);
        }

        function clearPanelFeedback(\$scope) {
            \$scope.find('.js-form-feedback, .js-sidebar-feedback, .js-api-key-modal-feedback').each(function () {
                clearFeedbackElement($(this));
            });
            \$scope.find('#response, #delete-account-feedback').each(function () {
                clearFeedbackElement($(this));
            });
        }

        function clearAllPanelFeedback() {
            clearLegacyFlash();
            clearPanelFeedback($('.settings_data'));
            clearPanelFeedback($('.settings-page'));
            clearPanelFeedback($('#apiKeyModal'));
        }

        function isManagedSettingsAction(action) {
            return /^\/account\/(settings|password|email|player|advertising|api-settings|remote-upload)$/.test(action || '');
        }

        function handleAjaxError(xhr, fallbackMessage) {
            var response = xhr && xhr.responseJSON;

            if (response && response.message) {
                return response.message;
            }

            if (xhr && xhr.responseText) {
                try {
                    response = JSON.parse(xhr.responseText);

                    if (response && response.message) {
                        return response.message;
                    }
                } catch (error) {
                }
            }

            return fallbackMessage;
        }

        function activateSettingsPanel(hash) {
            if (!hash || !$(hash).length) {
                return;
            }

            var \$link = $('.settings_menu a[href="' + hash + '"]');

            if (\$link.length) {
                \$link.trigger('click');
            }
        }

        function syncPopupMode() {
            if ($('.pop_type').val() === '2') {
                $('.delay-s').attr('style', 'display: none !important');
                $('.dlink').removeClass('col-md-8').addClass('col-md-10');
                $('.pop_text').text('Popup javascript url');
                return;
            }

            $('.delay-s').attr('style', 'display: block !important');
            $('.dlink').removeClass('col-md-10').addClass('col-md-8');
            $('.pop_text').text('Popup direct url');
        }

        function resetCustomFileInput(\$input) {
            \$input.val('');

            if (\$input.length) {
                var fallbackLabel = \$input.attr('id') === 'splash_image' ? 'Choose splash image' : 'Choose logo';
                \$input.next('.custom-file-label').text(fallbackLabel);
            }
        }

        function renderCustomDomains(domains) {
            if (!domains.length) {
                $('#info-domain').text('No active domain. Add one to start routing custom traffic.');
                $('#domainWrapper').html('');
                return;
            }

            $('#info-domain').html('You have <b>' + domains.length + '</b> active domain' + (domains.length > 1 ? 's' : '') + '.');

            var rows = domains.map(function (domain) {
                var statusText = domain.status === 'active' ? 'Active' : 'Pending DNS';
                var helpText = domain.status === 'active'
                    ? 'DNS confirmed. Traffic can use this redirect domain.'
                    : (domain.message || ('Point the A record to ' + domain.dns_target + '.'));

                return [
                    '<tr>',
                    '<td>',
                    '<span class="' + (domain.status === 'active' ? 'text-success' : 'text-warning') + '"><i class="fad fa-globe mr-2"></i></span>' + escapeHtml(domain.domain),
                    '<p>Status: ' + escapeHtml(statusText) + '. ' + escapeHtml(helpText) + '</p>',
                    '</td>',
                    '<td class="text-center">',
                    '<button class="btn btn-sm btn-danger deleteBtn" data-domain="' + escapeHtml(domain.domain) + '" type="button">',
                    '<i class="fad fa-trash-alt"></i>',
                    '</button>',
                    '</td>',
                    '</tr>'
                ].join('');
            }).join('');

            $('#domainWrapper').html(
                '<table class="table domain-list-table">' +
                '<thead><tr><th>Domain</th><th>Action</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '</table>'
            );
        }

        function renderApiActivity(entries) {
            var rows = entries || [];

            if (!rows.length) {
                $('#api-activity-rows').html('<tr><td colspan="6" class="text-center text-muted">No API activity recorded yet.</td></tr>');
                return;
            }

            var html = rows.map(function (entry) {
                var statusClass = entry.status_class || 'text-muted';
                var statusLabel = entry.status_label || 'Unknown';
                var statusCode = entry.status_code || 0;

                return [
                    '<tr>',
                    '<td>' + escapeHtml(entry.created_at_label || 'Unknown') + '</td>',
                    '<td>' + escapeHtml(entry.http_method || 'GET') + '</td>',
                    '<td><code>' + escapeHtml(entry.endpoint || '') + '</code></td>',
                    '<td>' + escapeHtml(entry.request_kind || 'request') + '</td>',
                    '<td><span class="' + escapeHtml(statusClass) + '">' + escapeHtml(statusLabel) + ' (' + escapeHtml(String(statusCode)) + ')</span></td>',
                    '<td>' + escapeHtml(entry.bytes_in_human || '0 B') + '</td>',
                    '</tr>'
                ].join('');
            }).join('');

            $('#api-activity-rows').html(html);
        }

        function syncApiModalMeta(snapshot) {
            var apiSnapshot = snapshot || {};
            var usage = apiSnapshot.usage || {};

            $('[data-api-modal-status]').text(apiSnapshot.status_label || $('[data-api-status]').text() || 'Active');
            $('[data-api-modal-last-used]').text(usage.last_used_at || $('[data-api-last-used]').text() || 'Never used');
            $('[data-api-modal-last-rotated]').text(usage.last_rotated_at || $('[data-api-last-rotated]').text() || 'Not available');
        }

        function applyApiSnapshot(snapshot) {
            if (!snapshot) {
                return;
            }

            var usage = snapshot.usage || {};
            var limits = snapshot.limits || {};

            $('[data-api-status]').text(snapshot.status_label || (snapshot.enabled ? 'Active' : 'Disabled'));
            $('[data-api-last-rotated]').text(usage.last_rotated_at || 'Not available');
            $('[data-api-last-used]').text(usage.last_used_at || 'Never used');
            $('[data-api-requests-today]').text(usage.requests_today || 0);
            $('[data-api-card="requests_last_hour"]').text(usage.requests_last_hour || 0);
            $('[data-api-card="requests_today"]').text(usage.requests_today || 0);
            $('[data-api-card="requests_this_month"]').text(usage.requests_this_month || 0);
            $('[data-api-card="uploads_today"]').text(usage.uploads_today || 0);

            $('#api_enabled').prop('checked', !!snapshot.enabled);
            $('input[name="api_requests_per_hour"]').val(limits.requests_per_hour != null ? limits.requests_per_hour : 0);
            $('input[name="api_requests_per_day"]').val(limits.requests_per_day != null ? limits.requests_per_day : 0);
            $('input[name="api_uploads_per_day"]').val(limits.uploads_per_day != null ? limits.uploads_per_day : 0);

            renderApiActivity(snapshot.recent_activity || []);
            syncApiModalMeta(snapshot);
        }

        function applyRemoteUploadSnapshot(snapshot) {
            if (!snapshot || !snapshot.can_manage || typeof snapshot.panel_html !== 'string' || !snapshot.panel_html) {
                return;
            }

            var \$panel = $('#remote_upload_hosts');

            if (!\$panel.length) {
                return;
            }

            var wasActive = \$panel.hasClass('active');
            var \$replacement = $(snapshot.panel_html);

            if (!\$replacement.length) {
                return;
            }

            if (wasActive) {
                \$replacement.addClass('active');
            }

            \$panel.replaceWith(\$replacement);
        }

        function applyPlayerSnapshot(snapshot) {
            if (!snapshot) {
                return;
            }

            var \$playerForm = $('#player_settings form').first();

            if (!\$playerForm.length) {
                return;
            }

            \$playerForm.find('input[name="usr_embed_title"]').prop('checked', !!snapshot.show_embed_title);
            \$playerForm.find('input[name="usr_sub_auto_start"]').prop('checked', !!snapshot.auto_subtitle_start);
            \$playerForm.find('select[name="usr_player_image"]').val(snapshot.player_image_mode || '');
            \$playerForm.find('input[name="embedcode_width"]').val(snapshot.embed_width != null ? snapshot.embed_width : 600);
            \$playerForm.find('input[name="embedcode_height"]').val(snapshot.embed_height != null ? snapshot.embed_height : 480);

            var \$colourInput = \$playerForm.find('input[name="usr_player_colour"]').first();
            var colourValue = snapshot.player_colour || 'ff9900';

            if (\$colourInput.length) {
                \$colourInput.val(colourValue);

                var colourField = \$colourInput.get(0);

                if (colourField && colourField.jscolor && typeof colourField.jscolor.fromString === 'function') {
                    colourField.jscolor.fromString(colourValue);
                }
            }

            if (typeof snapshot.splash_preview_html === 'string') {
                $('#player-splash-preview').html(snapshot.splash_preview_html);
            }
        }

        function loadDomains(successMessage, successType) {
            $.getJSON(appUrl('/api/domains'))
                .done(function (response) {
                    dnsTarget = response.dns_target || dnsTarget;
                    renderCustomDomains(response.domains || []);

                    if (successMessage) {
                        showDomainMessage(successType || 'success', successMessage);
                    }
                })
                .fail(function () {
                    showDomainMessage('danger', 'Unable to load custom domains right now.');
                });
        }

        function loadApiUsage(successMessage) {
            var \$apiForm = $('#api_access form').first();

            $.ajax({
                type: 'GET',
                url: appUrl('/api/account/api-usage'),
                dataType: 'json',
                headers: ajaxHeaders
            }).done(function (response) {
                if (response.status !== 'ok' || !response.api) {
                    showFormFeedback(\$apiForm, 'danger', response.message || 'Unable to load API usage.');
                    return;
                }

                applyApiSnapshot(response.api);

                if (successMessage) {
                    showFormFeedback(\$apiForm, 'success', successMessage);
                }
            }).fail(function (xhr) {
                showFormFeedback(\$apiForm, 'danger', handleAjaxError(xhr, 'Unable to load API usage right now.'));
            });
        }

        function loadRemoteUploadSettings(successMessage) {
            var \$form = $('#remote_upload_hosts form').first();

            if (!\$form.length) {
                return;
            }

            $.ajax({
                type: 'GET',
                url: appUrl('/api/account/remote-upload'),
                dataType: 'json',
                headers: ajaxHeaders
            }).done(function (response) {
                if (response.status !== 'ok' || !response.remote_upload || !response.remote_upload.can_manage) {
                    showFormFeedback(\$form, 'danger', response.message || 'Unable to load remote-upload host settings.');
                    return;
                }

                applyRemoteUploadSnapshot(response.remote_upload);

                if (successMessage) {
                    showFormFeedback($('#remote_upload_hosts form').first(), 'success', successMessage);
                }
            }).fail(function (xhr) {
                showFormFeedback(\$form, 'danger', handleAjaxError(xhr, 'Unable to load remote-upload host settings right now.'));
            });
        }

        function resetApiKeyModalState() {
            var \$modal = $('#apiKeyModal');
            clearPanelFeedback(\$modal);
            \$modal.find('.js-api-key-modal-result').addClass('d-none');
            \$modal.find('#apiKeyModalValue').val('');
            \$modal.find('#copyApiKey').removeClass('copied').text('Copy');
            \$modal.find('#confirmRegenerateApiKey').removeData('submitting').prop('disabled', false).html('Generate new key <i class="fad fa-arrow-right ml-2"></i>');
            syncApiModalMeta();
        }

        function copyTextValue(value, onSuccess, onError) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(onSuccess).catch(onError);
                return;
            }

            var \$temporary = $('<input type="text" class="sr-only" aria-hidden="true">').val(value).appendTo('body');
            var field = \$temporary.get(0);

            if (field) {
                field.focus();
                field.select();
            }

            try {
                if (document.execCommand('copy')) {
                    onSuccess();
                } else {
                    onError();
                }
            } catch (error) {
                onError();
            } finally {
                \$temporary.remove();
            }
        }

        $('.pop_type').on('change', syncPopupMode);
        syncPopupMode();
        loadDomains();
        loadApiUsage();
        syncApiModalMeta();

        $('.custom-file-input').on('change', function () {
            var fileName = $(this).val().split(String.fromCharCode(92)).pop().split('/').pop();
            var fallbackLabel = $(this).attr('id') === 'splash_image' ? 'Choose splash image' : 'Choose logo';
            $(this).next('.custom-file-label').text(fileName || fallbackLabel);
        });

        $(document).on('click', '#listDomain', function () {
            clearPanelFeedback($('#custom_domain'));
            loadDomains('Domain list refreshed.');
        });

        $(document).on('click', '#addBtn', function () {
            var domain = $('#domainInput').val().trim().toLowerCase();

            if (!domain) {
                showDomainMessage('danger', 'Please enter a domain.');
                return;
            }

            $.ajax({
                type: 'POST',
                url: appUrl('/api/domains'),
                dataType: 'json',
                headers: csrfAjaxHeaders(),
                data: {
                    token: csrfToken,
                    domain: domain
                }
            }).done(function (response) {
                if (response.status !== 'ok') {
                    var messageType = response.dns_status === 'pending_dns' || response.dns_status === 'lookup_failed' ? 'warning' : 'danger';
                    showDomainMessage(messageType, response.message || 'Unable to add domain.');
                    return;
                }

                $('#domainInput').val('');
                renderCustomDomains(response.domains || []);
                showDomainMessage('success', response.message || ('Domain added. Point the A record to ' + dnsTarget + '.'));
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON;
                var messageType = response && (response.dns_status === 'pending_dns' || response.dns_status === 'lookup_failed') ? 'warning' : 'danger';
                showDomainMessage(messageType, handleAjaxError(xhr, 'Unable to add domain right now.'));
            });
        });

        $(document).on('click', '.deleteBtn', function () {
            var domain = $(this).data('domain');

            $.ajax({
                type: 'DELETE',
                url: appUrl('/api/domains/' + encodeURIComponent(domain)),
                dataType: 'json',
                headers: csrfAjaxHeaders(),
                data: {
                    token: csrfToken
                }
            }).done(function (response) {
                if (response.status !== 'ok') {
                    showDomainMessage('danger', response.message || 'Unable to remove domain.');
                    return;
                }

                renderCustomDomains(response.domains || []);
                showDomainMessage('success', response.message || 'Domain removed successfully.');
            }).fail(function (xhr) {
                showDomainMessage('danger', handleAjaxError(xhr, 'Unable to remove domain right now.'));
            });
        });

        activateSettingsPanel(window.location.hash);

        $(window).on('hashchange', function () {
            activateSettingsPanel(window.location.hash);
        });

        $('.settings_menu a').on('click', function () {
            clearAllPanelFeedback();

            if (history.replaceState) {
                history.replaceState(null, '', this.hash);
            } else {
                window.location.hash = this.hash;
            }
        });

        function submitSettingsForm(form) {
            var \$form = $(form);
            var action = \$form.attr('action') || '';

            if (!isManagedSettingsAction(action) || \$form.data('ajaxSubmitting')) {
                return false;
            }

            clearAllPanelFeedback();
            \$form.data('ajaxSubmitting', true);

            var \$submit = \$form.find('button[type="submit"]').first();
            var originalLabel = \$submit.html();
            var formData = new FormData(form);

            formData.set('token', csrfToken);
            \$submit.prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: appUrl(action),
                data: formData,
                dataType: 'json',
                processData: false,
                contentType: false,
                headers: csrfAjaxHeaders()
            }).done(function (response) {
                var messageType = response.status === 'warning' ? 'warning' : 'success';
                var \$feedbackForm = \$form;

                if (action === '/account/player' && response.player) {
                    applyPlayerSnapshot(response.player);
                }

                if (action === '/account/api-settings' && response.api) {
                    applyApiSnapshot(response.api);
                }

                if (action === '/account/remote-upload' && response.remote_upload) {
                    applyRemoteUploadSnapshot(response.remote_upload);
                    \$feedbackForm = $('#remote_upload_hosts form').first();
                }

                if (response.status !== 'ok' && response.status !== 'warning') {
                    showFormFeedback(\$feedbackForm, 'danger', response.message || 'Unable to save settings.');
                    return;
                }

                showFormFeedback(\$feedbackForm, messageType, response.message || 'Saved successfully.');

                if (action === '/account/password') {
                    \$form.trigger('reset');
                }

                if (action === '/account/email' && response.email) {
                    \$form.closest('.settings-panel').find('p.mb-4').html('Current email: <b>' + escapeHtml(response.email) + '</b>');
                    \$form.find('input[name="usr_email"], input[name="usr_email2"]').val('');
                }

                if (action === '/account/player') {
                    resetCustomFileInput(\$form.find('#logo_image'));
                    resetCustomFileInput(\$form.find('#splash_image'));
                }
            }).fail(function (xhr) {
                if (action === '/account/player' && xhr && xhr.responseJSON && xhr.responseJSON.player) {
                    applyPlayerSnapshot(xhr.responseJSON.player);
                    resetCustomFileInput(\$form.find('#logo_image'));
                    resetCustomFileInput(\$form.find('#splash_image'));
                }

                showFormFeedback(\$form, 'danger', handleAjaxError(xhr, 'Unable to save settings right now.'));
            }).always(function () {
                \$submit.prop('disabled', false).html(originalLabel);
                \$form.data('ajaxSubmitting', false);
            });

            return true;
        }

        function submitDeleteAccountForm(form) {
            var \$form = $(form);

            if (\$form.data('ajaxSubmitting')) {
                return false;
            }

            clearAllPanelFeedback();
            \$form.data('ajaxSubmitting', true);

            var payload = \$form.serializeArray();
            payload.push({ name: 'token', value: csrfToken });
            var \$submit = \$form.find('button[type="submit"]').first();
            var originalLabel = \$submit.html();

            \$submit.prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: appUrl('/account/delete'),
                dataType: 'json',
                headers: csrfAjaxHeaders(),
                data: $.param(payload)
            }).done(function (response) {
                if (response.status === 'redirect') {
                    window.location.href = response.message;
                    return;
                }

                showFormFeedback(\$form, response.status === 'ok' ? 'success' : 'danger', response.message || 'Unable to delete account.');
            }).fail(function (xhr) {
                showFormFeedback(\$form, 'danger', handleAjaxError(xhr, 'Unable to delete account right now.'));
            }).always(function () {
                \$submit.prop('disabled', false).html(originalLabel);
                \$form.data('ajaxSubmitting', false);
            });

            return true;
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            if (!form.matches('form.js-settings-form, form.delete-account-form')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            if (form.matches('form.delete-account-form')) {
                submitDeleteAccountForm(form);
                return;
            }

            submitSettingsForm(form);
        }, true);

        $(document).on('click', '.regenerate-key', function (event) {
            event.preventDefault();
            clearAllPanelFeedback();
            resetApiKeyModalState();
            $('#apiKeyModal').modal('show');
        });

        $('#apiKeyModal').on('show.bs.modal', function () {
            resetApiKeyModalState();
        }).on('hidden.bs.modal', function () {
            resetApiKeyModalState();
        });

        $(document).on('click', '#confirmRegenerateApiKey', function (event) {
            event.preventDefault();

            var \$button = $(this);

            if (\$button.data('submitting')) {
                return;
            }

            \$button.data('submitting', true).prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Generating');
            clearPanelFeedback($('#apiKeyModal'));

            $.ajax({
                type: 'POST',
                url: appUrl('/account/api-key/regenerate'),
                dataType: 'json',
                headers: csrfAjaxHeaders(),
                data: {
                    token: csrfToken
                }
            }).done(function (response) {
                if (response.status !== 'ok' || !response.api_key) {
                    showApiKeyModalFeedback('danger', response.message || 'Unable to regenerate the API key.');
                    return;
                }

                $('.add-key input').val(response.api_key);

                if (response.api) {
                    applyApiSnapshot(response.api);
                }

                $('#apiKeyModalValue').val(response.api_key);
                $('#apiKeyModal').find('.js-api-key-modal-result').removeClass('d-none');
                showApiKeyModalFeedback('success', response.message || 'API key regenerated successfully.');
                showSidebarFeedback('success', 'API key regenerated successfully.');
            }).fail(function (xhr) {
                var message = handleAjaxError(xhr, 'Unable to regenerate the API key right now.');
                showApiKeyModalFeedback('danger', message);
                showSidebarFeedback('danger', message);
            }).always(function () {
                \$button.removeData('submitting').prop('disabled', false).html('Generate new key <i class="fad fa-arrow-right ml-2"></i>');
            });
        });

        $(document).on('click', '#copyApiKey', function (event) {
            event.preventDefault();

            var \$button = $(this);
            var apiKey = $('#apiKeyModalValue').val();

            if (!apiKey) {
                return;
            }

            copyTextValue(String(apiKey), function () {
                \$button.addClass('copied').text('Copied');
            }, function () {
                showApiKeyModalFeedback('warning', 'Unable to copy automatically. Select the key and copy it manually.');
            });
        });

        $(document).on('click', '#refreshApiUsage', function (event) {
            event.preventDefault();
            clearAllPanelFeedback();
            loadApiUsage('API usage refreshed.');
        });

        $(document).on('click', '#refreshRemoteUploadHosts', function (event) {
            event.preventDefault();
            clearAllPanelFeedback();
            loadRemoteUploadSettings('Remote-upload host usage refreshed.');
        });

        $(document).on('input', '#domainInput', function () {
            clearPanelFeedback($('#custom_domain'));
        });

        $(document).on('input change', 'form.js-settings-form :input, form.delete-account-form :input', function () {
            var \$form = $(this).closest('form');

            if (!\$form.length) {
                return;
            }

            clearPanelFeedback(\$form);
        });
    });
</script>
HTML;
}

function ve_render_settings_page(): void
{
    $user = ve_require_auth();
    $settings = $user['settings'];
    $api = ve_api_usage_snapshot((int) $user['id']);
    $dashboard = ve_dashboard_summary((int) $user['id']);
    $remoteUploadSnapshot = ve_remote_host_dashboard_snapshot($user);
    ve_pull_flash();
    $splashPreviewHtml = ve_player_splash_preview_html($settings);

    $html = (string) file_get_contents(ve_root_path('dashboard', 'settings.html'));

    $html = ve_runtime_html_transform($html, 'dashboard/settings.html');
    $html = str_replace(
        '<span class="money d-block">$0</span>',
        '<span class="money d-block">' . ve_h((string) ($dashboard['widgets']['balance']['formatted'] ?? '$0.00000')) . '</span>',
        $html
    );
    $html = str_replace('Current email: <b>lzcoeyhl@telegmail.com</b>', 'Current email: <b>' . ve_h((string) $user['email']) . '</b>', $html);
    $html = str_replace('value="559348grlz3u7np0z0hccb"', 'value="' . ve_h(ve_user_api_key($user)) . '"', $html);
    $html = str_replace('value="8wmdu9ngch"', 'value="' . ve_h(ve_user_ftp_password($user)) . '"', $html);
    $html = str_replace('208.73.202.233', ve_h((string) ve_config()['custom_domain_target']), $html);
    $html = str_replace('href="/?op=logout"', 'href="/logout"', $html);
    $html = str_replace('href="/premium"', 'href="/premium-plans"', $html);
    $html = str_replace("href='/premium'", "href='/premium-plans'", $html);
    $html = str_replace(
        'Just purchase a domain and point the DNS as shown below. This frontend preview stores domains in the current browser only.',
        'Attach a redirect domain to your account and point its A record to the required target below.',
        $html
    );
    $html = str_replace(
        'Review this carefully before continuing. This panel is frontend-only for now and does not execute a live deletion.',
        'This workflow immediately closes the account, revokes active sessions, and prevents future logins.',
        $html
    );
    $html = str_replace(
        '<!--REMOTE_UPLOAD_HOSTS_MENU-->',
        (bool) ($remoteUploadSnapshot['can_manage'] ?? false) ? ve_remote_host_settings_menu_html() : '',
        $html
    );
    $html = str_replace(
        '<!--REMOTE_UPLOAD_HOSTS_PANEL-->',
        (string) ($remoteUploadSnapshot['panel_html'] ?? ''),
        $html
    );
    $html = ve_settings_bind_form($html, 'my_account', '/account/settings');
    $html = ve_settings_bind_form($html, 'my_password', '/account/password');
    $html = ve_settings_bind_form($html, 'my_email', '/account/email');
    $html = ve_settings_bind_form($html, 'upload_logo', '/account/player');
    $html = ve_settings_bind_form($html, 'premium_settings', '/account/advertising');
    $html = ve_settings_bind_form($html, 'api_settings', '/account/api-settings');
    $html = ve_settings_bind_delete_account_form($html);
    $html = str_replace('<div id="delete-account-feedback" class="settings-inline-feedback"></div>', '', $html);
    $html = preg_replace('/\sdisabled(?:="disabled")?/i', '', $html) ?? $html;

    $html = ve_html_set_select_value($html, 'usr_pay_type', (string) ($settings['payment_method'] ?? 'Webmoney'));
    $html = ve_html_set_input_value($html, 'usr_pay_email', (string) ($settings['payment_id'] ?? ''));
    $html = ve_html_set_select_value($html, 'dood_ads_mode', (string) ($settings['ads_mode'] ?? ''));
    $html = ve_html_set_select_value($html, 'usr_content_type', (string) ($settings['uploader_type'] ?? '0'));
    $html = ve_html_set_input_value($html, 'embed_domain_allowed', implode(', ', $settings['embed_domains_array'] ?? []));
    $html = ve_html_set_checkbox($html, 'usr_embed_access_only', (bool) ($settings['embed_access_only'] ?? false));
    $html = ve_html_set_checkbox($html, 'usr_disable_download', (bool) ($settings['disable_download'] ?? false));
    $html = ve_html_set_checkbox($html, 'usr_disable_adb', (bool) ($settings['disable_adblock'] ?? false));
    $html = ve_html_set_checkbox($html, 'usr_srt_burn', (bool) ($settings['extract_subtitles'] ?? false));
    $html = ve_html_set_checkbox($html, 'usr_embed_title', (bool) ($settings['show_embed_title'] ?? false));
    $html = ve_html_set_checkbox($html, 'usr_sub_auto_start', (bool) ($settings['auto_subtitle_start'] ?? false));
    $html = ve_html_set_select_value($html, 'usr_player_image', (string) ($settings['player_image_mode'] ?? ''));
    $html = ve_html_set_input_value($html, 'usr_player_colour', (string) ($settings['player_colour'] ?? 'ff9900'));
    $html = ve_html_set_input_value($html, 'embedcode_width', (string) ($settings['embed_width'] ?? 600));
    $html = ve_html_set_input_value($html, 'embedcode_height', (string) ($settings['embed_height'] ?? 480));
    $html = ve_html_replace_element_contents_by_id($html, 'player-splash-preview', $splashPreviewHtml);
    $html = ve_html_set_input_value($html, 'vast_url', (string) ($settings['vast_url'] ?? ''));
    $html = ve_html_set_select_value($html, 'pop_type', (string) ($settings['pop_type'] ?? '1'));
    $html = ve_html_set_input_value($html, 'pop_url', (string) ($settings['pop_url'] ?? ''));
    $html = ve_html_set_input_value($html, 'pop_cap', (string) ($settings['pop_cap'] ?? 0));
    $html = ve_html_set_checkbox($html, 'api_enabled', (bool) ($api['enabled'] ?? true));
    $html = ve_html_set_input_value($html, 'api_requests_per_hour', (string) ($api['limits']['requests_per_hour'] ?? 250));
    $html = ve_html_set_input_value($html, 'api_requests_per_day', (string) ($api['limits']['requests_per_day'] ?? 5000));
    $html = ve_html_set_input_value($html, 'api_uploads_per_day', (string) ($api['limits']['uploads_per_day'] ?? 25));
    $html = str_replace('data-api-status>Active</strong>', 'data-api-status>' . ve_h((string) ($api['status_label'] ?? 'Active')) . '</strong>', $html);
    $html = str_replace('data-api-last-rotated>Not available</strong>', 'data-api-last-rotated>' . ve_h((string) ($api['usage']['last_rotated_at'] ?? 'Not available')) . '</strong>', $html);
    $html = str_replace('data-api-last-used>Never used</strong>', 'data-api-last-used>' . ve_h((string) ($api['usage']['last_used_at'] ?? 'Never used')) . '</strong>', $html);
    $html = str_replace('data-api-requests-today>0</strong>', 'data-api-requests-today>' . ve_h((string) ($api['usage']['requests_today'] ?? 0)) . '</strong>', $html);
    $html = str_replace('data-api-card="requests_last_hour">0</div>', 'data-api-card="requests_last_hour">' . ve_h((string) ($api['usage']['requests_last_hour'] ?? 0)) . '</div>', $html);
    $html = str_replace('data-api-card="requests_today">0</div>', 'data-api-card="requests_today">' . ve_h((string) ($api['usage']['requests_today'] ?? 0)) . '</div>', $html);
    $html = str_replace('data-api-card="requests_this_month">0</div>', 'data-api-card="requests_this_month">' . ve_h((string) ($api['usage']['requests_this_month'] ?? 0)) . '</div>', $html);
    $html = str_replace('data-api-card="uploads_today">0</div>', 'data-api-card="uploads_today">' . ve_h((string) ($api['usage']['uploads_today'] ?? 0)) . '</div>', $html);
    $html = ve_html_replace_element_contents_by_id($html, 'api-activity-rows', ve_render_api_activity_rows_html((array) ($api['recent_activity'] ?? [])));
    $html = preg_replace(
        '/(<div class="data settings-panel" id="ftp_servers">[\s\S]*?<tbody>)[\s\S]*?(<\/tbody>)/',
        '$1' . "\n" . ve_render_ftp_servers_rows() . "\n" . '$2',
        $html,
        1
    ) ?? $html;

    $html = preg_replace('/<script type="text\/javascript">[\s\S]*?<\/script>\s*<\/body>/i', ve_settings_script() . "\n</body>", $html, 1) ?? $html;

    ve_html(ve_rewrite_html_paths($html));
}

function ve_dashboard_premium_plans_content(array $user): string
{
    $currentPlan = ve_user_is_premium($user) ? 'Premium active' : 'Free account';
    $currentPlanTone = ve_user_is_premium($user) ? 'text-success' : 'text-warning';
    $planCode = ucfirst(ve_user_plan_code($user));
    $premiumUntil = trim((string) ($user['premium_until'] ?? ''));
    $renewalLabel = $premiumUntil !== '' ? ve_format_datetime_label($premiumUntil, 'Active') : (ve_user_is_premium($user) ? 'Active' : 'Upgrade any time');
    $monthlyCheckoutUrl = ve_h(ve_url('/api/billing/paypal?amount=7.99'));
    $halfYearCheckoutUrl = ve_h(ve_url('/api/billing/paypal?amount=37.99'));
    $yearlyCheckoutUrl = ve_h(ve_url('/api/billing/paypal?amount=77.99'));
    $bandwidth500Url = ve_h(ve_url('/api/billing/paypal?amount=9.99&premium_bw=1'));
    $bandwidth2000Url = ve_h(ve_url('/api/billing/paypal?amount=24.99&premium_bw=1'));
    $bandwidth5000Url = ve_h(ve_url('/api/billing/paypal?amount=54.99&premium_bw=1'));

    return <<<HTML
<style type="text/css">
    .premium-shell .hero-card,
    .premium-shell .pricing-card,
    .premium-shell .feature-card,
    .premium-shell .usage-card {
        background: linear-gradient(180deg, rgba(34,34,34,0.98) 0%, rgba(24,24,24,0.98) 100%);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 8px;
        box-shadow: 0 20px 35px rgba(0,0,0,0.18);
    }

    .premium-shell .hero-card {
        overflow: hidden;
        position: relative;
        padding: 34px;
        margin-bottom: 26px;
        background:
            radial-gradient(circle at top right, rgba(255,153,0,0.22), transparent 34%),
            linear-gradient(180deg, rgba(34,34,34,0.98) 0%, rgba(22,22,22,0.98) 100%);
    }

    .premium-shell .hero-kicker,
    .premium-shell .section-kicker {
        display: inline-block;
        color: #ff9900;
        font-size: 12px;
        letter-spacing: .18em;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .premium-shell .hero-title {
        font-size: 2.4rem;
        line-height: 1.08;
        margin-bottom: 12px;
    }

    .premium-shell .hero-copy,
    .premium-shell .section-copy,
    .premium-shell .plan-description {
        color: #989898;
    }

    .premium-shell .usage-card {
        padding: 22px;
        height: 100%;
    }

    .premium-shell .usage-card .label {
        display: block;
        color: #7f7f7f;
        font-size: .8571428571rem;
        margin-bottom: 10px;
    }

    .premium-shell .usage-card .value {
        color: #fff;
        font-size: 1.7rem;
        font-weight: 700;
    }

    .premium-shell .pricing-card,
    .premium-shell .feature-card {
        height: 100%;
        padding: 28px 26px;
    }

    .premium-shell .pricing-card.popular {
        border-color: rgba(255,153,0,0.36);
        box-shadow: 0 24px 42px rgba(255,153,0,0.12);
        transform: translateY(-8px);
    }

    .premium-shell .plan-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255,153,0,0.16);
        color: #ffb347;
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .premium-shell .plan-price {
        display: flex;
        align-items: flex-start;
        color: #fff;
        margin: 18px 0 10px;
    }

    .premium-shell .plan-price .currency {
        font-size: 1.1rem;
        margin-top: 9px;
        margin-right: 6px;
        color: #ff9900;
    }

    .premium-shell .plan-price .amount {
        font-size: 3rem;
        line-height: 1;
        font-weight: 700;
    }

    .premium-shell .plan-price .period {
        margin-top: 18px;
        margin-left: 8px;
        color: #8b8b8b;
    }

    .premium-shell .plan-list,
    .premium-shell .feature-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .premium-shell .plan-list li,
    .premium-shell .feature-list li {
        display: flex;
        align-items: flex-start;
        color: #d8d8d8;
        margin-bottom: 12px;
        line-height: 1.5;
    }

    .premium-shell .plan-list li i,
    .premium-shell .feature-list li i {
        color: #42b983;
        margin-right: 10px;
        margin-top: 3px;
    }

    .premium-shell .pricing-actions {
        margin-top: 24px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .premium-shell .btn-outline-premium {
        border: 1px solid rgba(255,255,255,0.14);
        color: #fff;
        background: transparent;
    }

    .premium-shell .btn-outline-premium:hover {
        border-color: rgba(255,153,0,0.45);
        color: #ffb347;
    }

    .premium-shell .feature-card {
        background: linear-gradient(180deg, rgba(33,33,33,0.98) 0%, rgba(20,20,20,0.98) 100%);
    }

    .premium-shell .feature-note {
        color: #a8a8a8;
        margin-top: 20px;
        padding: 14px 16px;
        border-radius: 6px;
        background: rgba(255,153,0,0.08);
        border: 1px solid rgba(255,153,0,0.16);
    }

    .premium-shell .bandwidth-row {
        margin-top: 30px;
    }

    @media (max-width: 991.98px) {
        .premium-shell .hero-card {
            padding: 28px 22px;
        }

        .premium-shell .hero-title {
            font-size: 2rem;
        }

        .premium-shell .pricing-card.popular {
            transform: none;
        }
    }
</style>

<div class="premium-shell container-fluid">
    <div class="hero-card">
        <span class="hero-kicker">Premium Plans</span>
        <h1 class="hero-title">Choose the <strong>right</strong> plan for your account</h1>
        <p class="hero-copy mb-4">Unlock premium-only player customization, higher limits, priority support, and extra bandwidth without relying on the broken legacy dashboard bundle.</p>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="usage-card">
                    <span class="label">Current plan</span>
                    <div class="value {$currentPlanTone}">{$currentPlan}</div>
                    <small class="text-muted d-block mt-2">Tier: {$planCode}</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="usage-card">
                    <span class="label">Renewal / expiry</span>
                    <div class="value">{$renewalLabel}</div>
                    <small class="text-muted d-block mt-2">Premium access is evaluated server-side for gated features.</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="usage-card">
                    <span class="label">Included with premium</span>
                    <div class="value">Custom player colour</div>
                    <small class="text-muted d-block mt-2">Also unlocks ad-free dashboard usage and priority queues.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row align-items-stretch">
        <div class="col-lg-8">
            <div class="mb-3">
                <span class="section-kicker">Premium account</span>
                <h2 class="title mb-2">Upgrade the uploader experience</h2>
                <p class="section-copy mb-0">All premium plans include unlimited working storage, stable throughput, and immediate access to premium-gated player settings.</p>
            </div>
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="pricing-card">
                        <span class="plan-badge">Monthly</span>
                        <div class="plan-price"><span class="currency">$</span><span class="amount">7.99</span><span class="period">/ month</span></div>
                        <p class="plan-description">Best if you want premium gating removed immediately without a long-term commitment.</p>
                        <ul class="plan-list">
                            <li><i class="fad fa-badge-check"></i><span>Custom player colour and branding controls</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Priority encoding and higher upload comfort</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Faster support turnaround</span></li>
                        </ul>
                        <div class="pricing-actions"><a class="btn btn-primary" href="{$monthlyCheckoutUrl}">Upgrade monthly</a></div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="pricing-card popular">
                        <span class="plan-badge">Most popular</span>
                        <div class="plan-price"><span class="currency">$</span><span class="amount">37.99</span><span class="period">/ 6 months</span></div>
                        <p class="plan-description">The better-value plan for active uploaders who want a stable premium baseline.</p>
                        <ul class="plan-list">
                            <li><i class="fad fa-badge-check"></i><span>Everything in monthly, with lower effective cost</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Longer premium access for branding consistency</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Ideal for teams managing recurring embeds</span></li>
                        </ul>
                        <div class="pricing-actions">
                            <a class="btn btn-primary" href="{$halfYearCheckoutUrl}">Upgrade half yearly</a>
                            <a class="btn btn-outline-premium" href="{$monthlyCheckoutUrl}">Compare</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="pricing-card">
                        <span class="plan-badge">Yearly</span>
                        <div class="plan-price"><span class="currency">$</span><span class="amount">77.99</span><span class="period">/ year</span></div>
                        <p class="plan-description">For accounts that want the lowest relative cost and the least billing churn.</p>
                        <ul class="plan-list">
                            <li><i class="fad fa-badge-check"></i><span>Best value for long-running premium accounts</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Stable access to premium player customization</span></li>
                            <li><i class="fad fa-badge-check"></i><span>Predictable annual billing</span></li>
                        </ul>
                        <div class="pricing-actions"><a class="btn btn-primary" href="{$yearlyCheckoutUrl}">Upgrade yearly</a></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-4">
            <div class="feature-card">
                <span class="section-kicker">Included</span>
                <h3 class="title mb-3">Premium account includes</h3>
                <ul class="feature-list">
                    <li><i class="fad fa-badge-check"></i><span>Ad-free dashboard experience for the account owner</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Upload up to 20 GB per file</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Priority encoding and queue handling</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Custom player colour and branding flexibility</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Files never expire while premium remains active</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Priority remote upload and support handling</span></li>
                    <li><i class="fad fa-badge-check"></i><span>Maximum playback and download speed</span></li>
                </ul>
                <div class="feature-note">Local checkout routes are still stubbed in this environment, but the premium entitlement used by the settings backend is fully enforced server-side.</div>
            </div>
        </div>
    </div>

    <div class="bandwidth-row">
        <span class="section-kicker">Bandwidth add-ons</span>
        <h2 class="title mb-2">Scale traffic without changing the plan tier</h2>
        <p class="section-copy mb-4">Use bandwidth boosters if you need more traffic headroom for embeds, campaigns, or short-term spikes.</p>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="pricing-card">
                    <span class="plan-badge">Booster</span>
                    <div class="plan-price"><span class="currency">$</span><span class="amount">9.99</span><span class="period">/ 500 GB</span></div>
                    <p class="plan-description">A lightweight add-on for short campaigns or small traffic bursts.</p>
                    <div class="pricing-actions"><a class="btn btn-primary" href="{$bandwidth500Url}">Buy 500 GB</a></div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="pricing-card">
                    <span class="plan-badge">Growth</span>
                    <div class="plan-price"><span class="currency">$</span><span class="amount">24.99</span><span class="period">/ 2 TB</span></div>
                    <p class="plan-description">Balanced for accounts with sustained embed traffic and regular uploads.</p>
                    <div class="pricing-actions"><a class="btn btn-primary" href="{$bandwidth2000Url}">Buy 2 TB</a></div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="pricing-card">
                    <span class="plan-badge">High volume</span>
                    <div class="plan-price"><span class="currency">$</span><span class="amount">54.99</span><span class="period">/ 5 TB</span></div>
                    <p class="plan-description">Built for higher-volume accounts that need predictable traffic expansion.</p>
                    <div class="pricing-actions"><a class="btn btn-primary" href="{$bandwidth5000Url}">Buy 5 TB</a></div>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
}

function ve_premium_page_component_markup(array $pagePayload): string
{
    $json = json_encode($pagePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($json)) {
        $json = '{}';
    }

    return '<my-premium :page=\'' . ve_h($json) . '\'></my-premium>';
}

function ve_render_premium_plans_page(): void
{
    $user = ve_require_auth();
    $html = (string) file_get_contents(ve_root_path('dashboard', 'premium-plans.html'));
    $html = ve_runtime_html_transform($html, 'dashboard/premium-plans.html');
    $pageMarkup = ve_premium_page_component_markup(ve_premium_page_payload((int) $user['id']));
    $html = preg_replace_callback(
        '/<my-premium\b[^>]*><\/my-premium>/i',
        static fn (): string => $pageMarkup,
        $html,
        1
    ) ?? $html;

    $checkoutCss = '<link rel="stylesheet" type="text/css" href="' . ve_h(ve_url('/assets/css/premium_checkout_runtime.css')) . '">';
    $checkoutJs = '<script src="' . ve_h(ve_url('/assets/js/premium_checkout_runtime.js')) . '"></script>';

    if (str_contains($html, '</head>')) {
        $html = str_replace('</head>', $checkoutCss . '</head>', $html);
    } else {
        $html = $checkoutCss . $html;
    }

    if (str_contains($html, '</body>')) {
        $html = str_replace('</body>', $checkoutJs . '</body>', $html);
    } else {
        $html .= $checkoutJs;
    }

    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_dashboard_file(string $relativePath): void
{
    if ($relativePath === 'dashboard/premium-plans.html') {
        ve_render_premium_plans_page();
    }

    if ($relativePath === 'dashboard/request-payout.html' && function_exists('ve_render_payout_request_page')) {
        ve_render_payout_request_page();
    }

    if ($relativePath === 'dashboard/referrals.html' && function_exists('ve_render_referrals_page')) {
        ve_render_referrals_page();
    }

    $html = (string) file_get_contents(ve_root_path($relativePath));
    $html = ve_runtime_html_transform($html, $relativePath);
    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_reset_password_page(string $token): void
{
    $reset = ve_get_valid_reset_token($token);
    $homeUrl = ve_url('/');
    $resetUrl = ve_url('/api/auth/reset');
    $mainJsUrl = ve_url('/assets/js/main.js');
    $bootstrapCss = ve_url('/assets/css/bootstrap.min.css');
    $styleCss = ve_url('/assets/css/style.min.css');

    if ($reset === null) {
        ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Reset Password</title>
    <link rel="stylesheet" href="{$bootstrapCss}">
    <link rel="stylesheet" href="{$styleCss}">
</head>
<body>
    <div class="container py-5">
        <div class="alert alert-danger">This reset link is invalid or expired.</div>
        <a href="{$homeUrl}" class="btn btn-primary">Back to home</a>
    </div>
</body>
</html>
HTML);
    }

    $runtimeScript = ve_runtime_script_tag();

    ve_html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Reset Password</title>
    <link rel="stylesheet" href="{$bootstrapCss}">
    <link rel="stylesheet" href="{$styleCss}">
    {$runtimeScript}
</head>
<body>
    <div class="container py-5">
        <div class="auth-box d-flex flex-wrap mx-auto" style="max-width: 680px;">
            <div class="bg-auth text-center" style="flex:1 1 220px;">
                <h4 class="title">Reset your password</h4>
                <p class="text-light">Choose a new password for your dashboard account.</p>
            </div>
            <form method="POST" action="{$resetUrl}" class="js_auth" style="flex:1 1 360px;">
                <input type="hidden" name="op" value="reset_pass">
                <input type="hidden" name="sess_id" value="{$token}">
                <div class="form-group">
                    <label>New password</label>
                    <input type="password" name="password" class="form-control">
                </div>
                <div class="form-group">
                    <label>Confirm new password</label>
                    <input type="password" name="password2" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary">Reset password</button>
                <hr>
                <a href="{$homeUrl}" class="btn btn-default btn-block">Back to home</a>
            </form>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script src="{$mainJsUrl}" type="text/javascript"></script>
</body>
</html>
HTML);
}
