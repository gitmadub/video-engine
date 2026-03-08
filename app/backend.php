<?php

declare(strict_types=1);

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
        'custom_domain_target' => getenv('VE_CUSTOM_DOMAIN_TARGET') ?: '208.73.202.233',
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

function ve_run_database_migrations(PDO $pdo): void
{
    ve_add_column_if_missing($pdo, 'users', 'api_key_hash', "TEXT NOT NULL DEFAULT ''");
    ve_add_column_if_missing($pdo, 'users', 'api_key_last_rotated_at', 'TEXT DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'api_key_last_used_at', 'TEXT DEFAULT NULL');

    ve_add_column_if_missing($pdo, 'user_settings', 'api_enabled', 'INTEGER NOT NULL DEFAULT 1');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_requests_per_hour', 'INTEGER NOT NULL DEFAULT 250');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_requests_per_day', 'INTEGER NOT NULL DEFAULT 5000');
    ve_add_column_if_missing($pdo, 'user_settings', 'api_uploads_per_day', 'INTEGER NOT NULL DEFAULT 25');
    ve_add_column_if_missing($pdo, 'user_settings', 'splash_image_path', "TEXT NOT NULL DEFAULT ''");

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

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_api_key_hash ON users(api_key_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_created ON api_request_logs(user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_kind_created ON api_request_logs(user_id, request_kind, created_at DESC)');
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
        ':ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
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

    $precision = $unit === 0 ? 0 : 1;

    return number_format($size, $precision) . ' ' . $units[$unit];
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
        ':client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
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

function ve_handle_login_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Enter your username/email and password.',
        ]);
    }

    $user = ve_find_user_by_login($login);

    if (!is_array($user) || $user['status'] !== 'active' || $user['deleted_at'] !== null || !password_verify($password, (string) $user['password_hash'])) {
        ve_json([
            'status' => 'fail',
            'message' => 'Invalid login credentials.',
        ]);
    }

    ve_login_user($user);
    ve_add_notification((int) $user['id'], 'New login', 'A new dashboard session was started successfully.');
    ve_json([
        'status' => 'redirect',
        'message' => ve_url('/dashboard'),
    ]);
}

function ve_handle_registration_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $username = trim((string) ($_POST['usr_login'] ?? ''));
    $email = strtolower(trim((string) ($_POST['usr_email'] ?? '')));
    $password = (string) ($_POST['usr_password'] ?? '');
    $password2 = (string) ($_POST['usr_password2'] ?? '');

    $error = ve_validate_username($username)
        ?? ve_validate_email($email)
        ?? ve_validate_password($password, $password2);

    if ($error !== null) {
        ve_json([
            'status' => 'fail',
            'message' => $error,
        ]);
    }

    if (ve_find_user_by_login($username) !== null) {
        ve_json([
            'status' => 'fail',
            'message' => 'That username is already taken.',
        ]);
    }

    $stmt = ve_db()->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);

    if ($stmt->fetchColumn() !== false) {
        ve_json([
            'status' => 'fail',
            'message' => 'That email address is already in use.',
        ]);
    }

    ve_create_user($username, $email, $password);

    ve_json([
        'status' => 'ok',
        'message' => 'Registration completed. You can log in now.',
    ]);
}

function ve_get_valid_reset_token(string $rawToken): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT * FROM password_reset_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= :now
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':token_hash' => hash('sha256', $rawToken),
        ':now' => ve_now(),
    ]);
    $token = $stmt->fetch();

    return is_array($token) ? $token : null;
}

function ve_handle_forgot_password_ajax(): void
{
    ve_require_csrf(ve_request_csrf_token());
    $token = trim((string) ($_POST['sess_id'] ?? ''));

    if ($token !== '') {
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        $error = ve_validate_password($password, $password2);

        if ($error !== null) {
            ve_json([
                'status' => 'fail',
                'message' => $error,
            ]);
        }

        $reset = ve_get_valid_reset_token($token);

        if (!is_array($reset)) {
            ve_json([
                'status' => 'fail',
                'message' => 'This password reset link is invalid or expired.',
            ]);
        }

        $stmt = ve_db()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => ve_now(),
            ':id' => (int) $reset['user_id'],
        ]);

        $consume = ve_db()->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE id = :id');
        $consume->execute([
            ':used_at' => ve_now(),
            ':id' => (int) $reset['id'],
        ]);

        ve_add_notification((int) $reset['user_id'], 'Password updated', 'Your account password was reset successfully.');

        ve_json([
            'status' => 'ok',
            'message' => 'Password updated successfully. You can log in now.',
        ]);
    }

    $login = trim((string) ($_POST['usr_login'] ?? ''));

    if ($login === '') {
        ve_json([
            'status' => 'fail',
            'message' => 'Enter your username or email address.',
        ]);
    }

    $user = ve_find_user_by_login($login);

    if (!is_array($user) || $user['deleted_at'] !== null) {
        ve_json([
            'status' => 'ok',
            'message' => 'If the account exists, password reset instructions were generated.',
        ]);
    }

    $rawToken = ve_random_token(24);
    $stmt = ve_db()->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, created_at)
         VALUES (:user_id, :token_hash, :expires_at, NULL, :created_at)'
    );
    $stmt->execute([
        ':user_id' => (int) $user['id'],
        ':token_hash' => hash('sha256', $rawToken),
        ':expires_at' => gmdate('Y-m-d H:i:s', ve_timestamp() + 3600),
        ':created_at' => ve_now(),
    ]);

    $resetUrl = ve_absolute_url('/reset-password?token=' . rawurlencode($rawToken));
    ve_add_notification((int) $user['id'], 'Password reset requested', 'A password reset link was generated for your account.');

    ve_json([
        'status' => 'ok',
        'message' => 'Reset link generated. <a href="' . ve_h($resetUrl) . '">Open the password reset page</a>.',
    ]);
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

    $playerImageMode = trim((string) ($_POST['usr_player_image'] ?? ''));
    $playerColour = ltrim(trim((string) ($_POST['usr_player_colour'] ?? 'ff9900')), '#');
    $embedWidth = max(200, min(4000, (int) ($_POST['embedcode_width'] ?? 600)));
    $embedHeight = max(200, min(4000, (int) ($_POST['embedcode_height'] ?? 480)));
    $settings = ve_get_user_settings($userId);
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
        ':show_embed_title' => isset($_POST['usr_embed_title']) ? 1 : 0,
        ':auto_subtitle_start' => isset($_POST['usr_sub_auto_start']) ? 1 : 0,
        ':player_image_mode' => $playerImageMode,
        ':player_colour' => strtolower($playerColour),
        ':logo_path' => $logoPath,
        ':splash_image_path' => $splashImagePath,
        ':embed_width' => $embedWidth,
        ':embed_height' => $embedHeight,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    ve_add_notification($userId, 'Player settings updated', 'Your player appearance defaults were saved.');
    ve_success_form_submission('Player settings saved successfully.', '/dashboard/settings#player_settings', [
        'player' => [
            'player_image_mode' => $playerImageMode,
            'player_colour' => strtolower($playerColour),
            'embed_width' => $embedWidth,
            'embed_height' => $embedHeight,
            'logo_path' => $logoPath,
            'splash_image_path' => $splashImagePath,
        ],
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

function ve_check_domain_status(string $domain): array
{
    $target = ve_config()['custom_domain_target'];
    $status = 'pending_dns';
    $error = '';

    if (function_exists('dns_get_record')) {
        try {
            $records = @dns_get_record($domain, DNS_A);

            if (is_array($records) && $records !== []) {
                $ips = array_map(static fn(array $record): string => (string) ($record['ip'] ?? ''), $records);
                $status = in_array($target, $ips, true) ? 'active' : 'pending_dns';
                $error = $status === 'active' ? '' : 'A record does not point to the required target.';
            } else {
                $error = 'No A record found yet.';
            }
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }
    }

    return [
        'status' => $status,
        'dns_target' => $target,
        'dns_check_error' => $error,
        'dns_last_checked_at' => ve_now(),
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
        'message' => $check['status'] === 'active' ? 'Domain connected successfully.' : 'Domain saved. Point its A record to ' . $check['dns_target'] . '.',
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

function ve_runtime_html_transform(string $html, string $relativePath = ''): string
{
    $runtimeScript = ve_runtime_script_tag();

    if (str_contains($html, '</head>')) {
        $html = str_replace('</head>', $runtimeScript . '</head>', $html);
    } else {
        $html = $runtimeScript . $html;
    }

    if ($relativePath !== '' && str_starts_with($relativePath, 'dashboard/')) {
        $user = ve_current_user();

        if (is_array($user)) {
            $html = str_replace('videoengine', (string) $user['username'], $html);
        }

        $html = str_replace('href="/?op=logout"', 'href="/logout"', $html);
    }

    if ($relativePath === 'index.html') {
        $html = str_replace(
            '<form method="POST" action="/" name="FL" class="js_auth">',
            '<form method="POST" action="/login" name="FL" class="js_auth">',
            $html
        );
        $html = str_replace(
            '<form method="POST" onSubmit="return CheckForm(this)" class="js_auth">',
            '<form method="POST" action="/register" onSubmit="return CheckForm(this)" class="js_auth">',
            $html
        );
        $html = str_replace(
            '<form method="POST" class="js_auth">',
            '<form method="POST" action="/password/forgot" class="js_auth">',
            $html
        );
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

function ve_settings_bind_form(string $html, string $op, string $action): string
{
    $quotedOp = preg_quote($op, '/');
    $token = ve_h(ve_csrf_token());
    $pattern = '/<form\b([^>]*)>\s*<input type="hidden" name="op" value="' . $quotedOp . '">\s*(?:<input type="hidden" name="token" value="[^"]*">\s*)?/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($action, $op, $token): string {
        $attributes = preg_replace('/\saction="[^"]*"/i', '', $matches[1]) ?? $matches[1];

        return '<form' . $attributes . ' action="' . ve_h($action) . '">' . "\n                        "
            . '<input type="hidden" name="op" value="' . ve_h($op) . '">' . "\n                        "
            . '<input type="hidden" name="token" value="' . $token . '">' . "\n                        ";
    }, $html, 1);
}

function ve_settings_bind_delete_account_form(string $html): string
{
    $token = ve_h(ve_csrf_token());
    $pattern = '/<form class="delete-account-form"([^>]*)>\s*(?:<input type="hidden" name="token" value="[^"]*">\s*)?/i';

    return (string) preg_replace_callback($pattern, static function (array $matches) use ($token): string {
        $attributes = $matches[1];
        $attributes = preg_replace('/\saction="[^"]*"/i', '', $attributes) ?? $attributes;

        return '<form class="delete-account-form"' . $attributes . ' action="/account/delete">' . "\n                        "
            . '<input type="hidden" name="token" value="' . $token . '">' . "\n                        ";
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

        function ensurePanelFeedback(\$panel) {
            var \$feedback = \$panel.find('.js-panel-feedback').first();

            if (!\$feedback.length) {
                \$feedback = $('<div class="settings-inline-feedback alert d-none js-panel-feedback" role="alert"></div>');
                var \$subtitle = \$panel.find('.settings-panel-subtitle').first();

                if (\$subtitle.length) {
                    \$subtitle.after(\$feedback);
                } else {
                    \$panel.prepend(\$feedback);
                }
            }

            return \$feedback;
        }

        function clearPanelFeedback(\$scope) {
            \$scope.find('.js-panel-feedback').addClass('d-none').removeClass('alert-success alert-danger').text('');
            \$scope.find('#response, #delete-account-feedback').text('').removeAttr('style');
        }

        function clearAllPanelFeedback() {
            clearLegacyFlash();
            clearPanelFeedback($('.settings_data'));
        }

        function showFormFeedback(\$panel, type, message) {
            var \$feedback = ensurePanelFeedback(\$panel);
            \$feedback.removeClass('d-none alert-success alert-danger').addClass(type === 'success' ? 'alert-success' : 'alert-danger').text(message || '');
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

        function showDomainMessage(message, color) {
            $('#response').text(message).css('color', color || '');
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
                    '<span class="' + (domain.status === 'active' ? 'text-success' : 'text-warning') + '"><i class="fad fa-globe mr-2"></i></span>' + domain.domain,
                    '<p>Status: ' + statusText + '. ' + helpText + '</p>',
                    '</td>',
                    '<td class="text-center">',
                    '<button class="btn btn-sm btn-danger deleteBtn" data-domain="' + domain.domain + '" type="button">',
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
        }

        function loadDomains(successMessage) {
            $.getJSON(appUrl('/api/domains'))
                .done(function (response) {
                    dnsTarget = response.dns_target || dnsTarget;
                    renderCustomDomains(response.domains || []);

                    if (successMessage) {
                        showDomainMessage(successMessage, '#42b983');
                    }
                })
                .fail(function () {
                    showDomainMessage('Unable to load custom domains right now.', '#dc3545');
                });
        }

        function loadApiUsage(successMessage) {
            $.ajax({
                type: 'GET',
                url: appUrl('/api/account/api-usage'),
                dataType: 'json',
                headers: ajaxHeaders
            }).done(function (response) {
                if (response.status !== 'ok' || !response.api) {
                    showFormFeedback($('#api_access'), 'danger', response.message || 'Unable to load API usage.');
                    return;
                }

                applyApiSnapshot(response.api);

                if (successMessage) {
                    showFormFeedback($('#api_access'), 'success', successMessage);
                }
            }).fail(function (xhr) {
                showFormFeedback($('#api_access'), 'danger', handleAjaxError(xhr, 'Unable to load API usage right now.'));
            });
        }

        $('.pop_type').on('change', syncPopupMode);
        syncPopupMode();
        loadDomains();
        loadApiUsage();

        $('.custom-file-input').on('change', function () {
            var fileName = $(this).val().split('\\\\').pop();
            $(this).next('.custom-file-label').text(fileName || 'Choose logo');
        });

        $(document).on('click', '#listDomain', function () {
            loadDomains('Domain list refreshed.');
        });

        $(document).on('click', '#addBtn', function () {
            var domain = $('#domainInput').val().trim().toLowerCase();

            if (!domain) {
                showDomainMessage('Please enter a domain.', '#dc3545');
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
                    showDomainMessage(response.message || 'Unable to add domain.', '#dc3545');
                    return;
                }

                $('#domainInput').val('');
                renderCustomDomains(response.domains || []);
                showDomainMessage(response.message || ('Domain added. Point the A record to ' + dnsTarget + '.'), '#42b983');
            }).fail(function () {
                showDomainMessage('Unable to add domain right now.', '#dc3545');
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
                    showDomainMessage(response.message || 'Unable to remove domain.', '#dc3545');
                    return;
                }

                renderCustomDomains(response.domains || []);
                showDomainMessage(response.message || 'Domain removed successfully.', '#42b983');
            }).fail(function () {
                showDomainMessage('Unable to remove domain right now.', '#dc3545');
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

        $(document).on('submit', '.settings-panel form[action]', function (event) {
            var action = $(this).attr('action') || '';

            if (!/^\\/account\\/(settings|password|email|player|advertising|api-settings)$/.test(action)) {
                return;
            }

            event.preventDefault();
            clearAllPanelFeedback();

            var \$form = $(this);
            var \$panel = \$form.closest('.settings-panel');
            var \$submit = \$form.find('button[type="submit"]').first();
            var originalLabel = \$submit.html();
            var formData = new FormData(this);

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
                if (response.status !== 'ok') {
                    showFormFeedback(\$panel, 'danger', response.message || 'Unable to save settings.');
                    return;
                }

                showFormFeedback(\$panel, 'success', response.message || 'Saved successfully.');

                if (action === '/account/password') {
                    \$form.trigger('reset');
                }

                if (action === '/account/email' && response.email) {
                    \$panel.find('p.mb-4').html('Current email: <b>' + escapeHtml(response.email) + '</b>');
                    \$form.find('input[name="usr_email"], input[name="usr_email2"]').val('');
                }

                if (action === '/account/player') {
                    \$form.find('input[type="file"]').val('');
                    \$form.find('.custom-file-label').text('Choose logo');
                }

                if (action === '/account/api-settings' && response.api) {
                    applyApiSnapshot(response.api);
                }
            }).fail(function (xhr) {
                showFormFeedback(\$panel, 'danger', handleAjaxError(xhr, 'Unable to save settings right now.'));
            }).always(function () {
                \$submit.prop('disabled', false).html(originalLabel);
            });
        });

        $(document).on('submit', '.delete-account-form', function (event) {
            event.preventDefault();
            clearAllPanelFeedback();

            var payload = $(this).serializeArray();
            payload.push({ name: 'token', value: csrfToken });

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

                $('#delete-account-feedback').text(response.message || 'Unable to delete account.').css('color', response.status === 'ok' ? '#42b983' : '#dc3545');
            }).fail(function (xhr) {
                $('#delete-account-feedback').text(handleAjaxError(xhr, 'Unable to delete account right now.')).css('color', '#dc3545');
            });
        });

        $(document).on('click', '.regenerate-key', function (event) {
            event.preventDefault();

            if (!confirm('Are you sure you want to regenerate the API key?')) {
                return;
            }

            clearAllPanelFeedback();

            $.ajax({
                type: 'POST',
                url: appUrl('/account/api-key/regenerate'),
                dataType: 'json',
                headers: csrfAjaxHeaders(),
                data: {
                    token: csrfToken
                }
            }).done(function (response) {
                var \$sidebar = $('.settings-page');

                if (response.status !== 'ok' || !response.api_key) {
                    showFormFeedback(\$sidebar, 'danger', response.message || 'Unable to regenerate the API key.');
                    return;
                }

                $('.add-key input').val(response.api_key);
                if (response.api) {
                    applyApiSnapshot(response.api);
                }
                showFormFeedback(\$sidebar, 'success', response.message || 'API key regenerated successfully.');
            }).fail(function (xhr) {
                showFormFeedback($('.settings-page'), 'danger', handleAjaxError(xhr, 'Unable to regenerate the API key right now.'));
            });
        });

        $(document).on('click', '#refreshApiUsage', function (event) {
            event.preventDefault();
            clearAllPanelFeedback();
            loadApiUsage('API usage refreshed.');
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
    $flash = ve_pull_flash();
    $splashPreviewHtml = '<div class="text-muted small">No splash image uploaded yet.</div>';
    $splashPath = ve_storage_relative_path_to_absolute((string) ($settings['splash_image_path'] ?? ''));

    if ($splashPath !== '' && is_file($splashPath)) {
        $splashPreviewUrl = ve_h(ve_url('/account/player/splash-preview?ts=' . rawurlencode((string) ($settings['updated_at'] ?? ve_timestamp()))));
        $splashPreviewHtml = '<div class="small text-muted mb-2">Current protected splash image</div>' .
            '<img src="' . $splashPreviewUrl . '" alt="Splash preview" style="display:block;width:100%;max-width:100%;border-radius:12px;border:1px solid rgba(255,255,255,0.08);background:#0c0c0c;">';
    }

    $html = (string) file_get_contents(ve_root_path('dashboard', 'settings.html'));

    $html = ve_runtime_html_transform($html, 'dashboard/settings.html');
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
    $html = ve_settings_bind_form($html, 'my_account', '/account/settings');
    $html = ve_settings_bind_form($html, 'my_password', '/account/password');
    $html = ve_settings_bind_form($html, 'my_email', '/account/email');
    $html = ve_settings_bind_form($html, 'upload_logo', '/account/player');
    $html = ve_settings_bind_form($html, 'premium_settings', '/account/advertising');
    $html = ve_settings_bind_form($html, 'api_settings', '/account/api-settings');
    $html = ve_settings_bind_delete_account_form($html);
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

    if ($flash !== null) {
        $flashHtml = '<div class="alert alert-' . ve_h($flash['type']) . ' mb-4">' . ve_h($flash['message']) . '</div>';
        $html = str_replace('<div class="details settings_data">', '<div class="details settings_data">' . $flashHtml, $html);
    }

    $html = preg_replace('/<script type="text\/javascript">[\s\S]*?<\/script>\s*<\/body>/i', ve_settings_script() . "\n</body>", $html, 1) ?? $html;

    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_dashboard_file(string $relativePath): void
{
    $html = (string) file_get_contents(ve_root_path($relativePath));
    $html = ve_runtime_html_transform($html, $relativePath);
    ve_html(ve_rewrite_html_paths($html));
}

function ve_render_reset_password_page(string $token): void
{
    $reset = ve_get_valid_reset_token($token);
    $homeUrl = ve_url('/');
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
            <form method="POST" class="js_auth" style="flex:1 1 360px;">
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
