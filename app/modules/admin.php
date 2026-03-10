<?php

declare(strict_types=1);

const VE_SESSION_IMPERSONATOR_ID = 've_impersonator_id';
const VE_ADMIN_PAGE_SIZE = 25;

function ve_admin_sections(): array
{
    return [
        'overview' => ['label' => 'Overview', 'icon' => 'fa-chart-bar', 'permission' => 'admin.access'],
        'users' => ['label' => 'Users', 'icon' => 'fa-users', 'permission' => 'admin.users.manage'],
        'videos' => ['label' => 'Files', 'icon' => 'fa-photo-video', 'permission' => 'admin.videos.manage'],
        'remote-uploads' => ['label' => 'Remote Uploads', 'icon' => 'fa-cloud-download-alt', 'permission' => 'admin.remote_uploads.manage'],
        'dmca' => ['label' => 'DMCA', 'icon' => 'fa-folder-times', 'permission' => 'admin.dmca.manage'],
        'payouts' => ['label' => 'Payouts', 'icon' => 'fa-wallet', 'permission' => 'admin.payouts.manage'],
        'domains' => ['label' => 'Domains', 'icon' => 'fa-globe', 'permission' => 'admin.domains.manage'],
        'app' => ['label' => 'App Settings', 'icon' => 'fa-sliders-h', 'permission' => 'admin.settings.manage'],
        'infrastructure' => ['label' => 'Infrastructure', 'icon' => 'fa-server', 'permission' => 'admin.infrastructure.manage'],
        'audit' => ['label' => 'Audit', 'icon' => 'fa-clipboard-list', 'permission' => 'admin.audit.view'],
    ];
}

function ve_admin_permission_catalog(): array
{
    return [
        'admin.access' => ['label' => 'Backend access', 'group_code' => 'core'],
        'admin.users.manage' => ['label' => 'Manage users', 'group_code' => 'users'],
        'admin.users.delete' => ['label' => 'Hard-delete users', 'group_code' => 'users'],
        'admin.users.impersonate' => ['label' => 'Impersonate users', 'group_code' => 'users'],
        'admin.videos.manage' => ['label' => 'Manage files and videos', 'group_code' => 'videos'],
        'admin.videos.delete' => ['label' => 'Hard-delete files and videos', 'group_code' => 'videos'],
        'admin.remote_uploads.manage' => ['label' => 'Manage remote uploads', 'group_code' => 'remote_uploads'],
        'admin.dmca.manage' => ['label' => 'Manage DMCA cases', 'group_code' => 'dmca'],
        'admin.payouts.manage' => ['label' => 'Manage payout requests', 'group_code' => 'billing'],
        'admin.domains.manage' => ['label' => 'Manage custom domains', 'group_code' => 'domains'],
        'admin.settings.manage' => ['label' => 'Manage app settings', 'group_code' => 'settings'],
        'admin.infrastructure.manage' => ['label' => 'Manage infrastructure', 'group_code' => 'infrastructure'],
        'admin.audit.view' => ['label' => 'View audit log', 'group_code' => 'audit'],
    ];
}

function ve_admin_role_catalog(): array
{
    $permissions = array_keys(ve_admin_permission_catalog());

    return [
        'admin' => [
            'label' => 'Admin',
            'permissions' => [
                'admin.access',
                'admin.users.manage',
                'admin.videos.manage',
                'admin.remote_uploads.manage',
                'admin.dmca.manage',
                'admin.payouts.manage',
                'admin.domains.manage',
                'admin.settings.manage',
                'admin.audit.view',
            ],
        ],
        'super_admin' => [
            'label' => 'Super Admin',
            'permissions' => $permissions,
        ],
    ];
}

function ve_admin_bootstrap_logins(): array
{
    $raw = trim((string) (getenv('VE_ADMIN_BOOTSTRAP_LOGINS') ?: 'test'));
    $values = [];

    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
        $normalized = strtolower(trim((string) $value));

        if ($normalized !== '') {
            $values[$normalized] = $normalized;
        }
    }

    return array_values($values);
}

function ve_admin_default_settings(): array
{
    return [
        'payout_minimum_micro_usd' => '10000000',
        'admin_default_page_size' => (string) VE_ADMIN_PAGE_SIZE,
        'admin_recent_audit_limit' => '12',
        'remote_max_queue_per_user' => '25',
    ];
}

function ve_admin_run_migrations(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            group_code TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_role_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            granted_by_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT DEFAULT NULL,
            revoked_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
            FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_role_assignments_user_role ON user_role_assignments(user_id, role_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_role_assignments_active ON user_role_assignments(user_id, revoked_at, expires_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER DEFAULT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER DEFAULT NULL,
            event_code TEXT NOT NULL,
            before_json TEXT NOT NULL DEFAULT "{}",
            after_json TEXT NOT NULL DEFAULT "{}",
            ip_address TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_target_created ON audit_logs(target_type, target_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_actor_created ON audit_logs(actor_user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_event_created ON audit_logs(event_code, created_at DESC)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payout_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            amount_micro_usd INTEGER NOT NULL,
            payout_method TEXT NOT NULL,
            payout_destination_masked TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL DEFAULT "pending",
            notes TEXT NOT NULL DEFAULT "",
            reviewed_by_user_id INTEGER DEFAULT NULL,
            reviewed_at TEXT DEFAULT NULL,
            rejection_reason TEXT NOT NULL DEFAULT "",
            transfer_reference TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payout_requests_user_status_created ON payout_requests(user_id, status, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payout_requests_status_created ON payout_requests(status, created_at DESC)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payout_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payout_request_id INTEGER NOT NULL UNIQUE,
            provider_transfer_ref TEXT NOT NULL DEFAULT "",
            gross_amount_micro_usd INTEGER NOT NULL,
            fee_micro_usd INTEGER NOT NULL DEFAULT 0,
            net_amount_micro_usd INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "sent",
            sent_at TEXT DEFAULT NULL,
            settled_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (payout_request_id) REFERENCES payout_requests (id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payout_transfers_status_sent ON payout_transfers(status, sent_at DESC)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS storage_nodes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            hostname TEXT NOT NULL UNIQUE,
            public_base_url TEXT NOT NULL DEFAULT "",
            upload_base_url TEXT NOT NULL DEFAULT "",
            health_status TEXT NOT NULL DEFAULT "healthy",
            available_bytes INTEGER NOT NULL DEFAULT 0,
            used_bytes INTEGER NOT NULL DEFAULT 0,
            max_ingest_qps INTEGER NOT NULL DEFAULT 0,
            max_stream_qps INTEGER NOT NULL DEFAULT 0,
            notes TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_storage_nodes_health ON storage_nodes(health_status, updated_at DESC)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS storage_volumes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            storage_node_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            mount_path TEXT NOT NULL,
            capacity_bytes INTEGER NOT NULL DEFAULT 0,
            used_bytes INTEGER NOT NULL DEFAULT 0,
            reserved_bytes INTEGER NOT NULL DEFAULT 0,
            health_status TEXT NOT NULL DEFAULT "healthy",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (storage_node_id) REFERENCES storage_nodes (id) ON DELETE CASCADE,
            UNIQUE (storage_node_id, code)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_storage_volumes_node_health ON storage_volumes(storage_node_id, health_status)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS upload_endpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            storage_node_id INTEGER NOT NULL,
            code TEXT NOT NULL UNIQUE,
            protocol TEXT NOT NULL DEFAULT "https",
            host TEXT NOT NULL,
            path_prefix TEXT NOT NULL DEFAULT "",
            weight INTEGER NOT NULL DEFAULT 100,
            is_active INTEGER NOT NULL DEFAULT 1,
            max_file_size_bytes INTEGER NOT NULL DEFAULT 0,
            accepts_remote_upload INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (storage_node_id) REFERENCES storage_nodes (id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_upload_endpoints_node_active ON upload_endpoints(storage_node_id, is_active, weight)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS delivery_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL UNIQUE,
            purpose TEXT NOT NULL DEFAULT "watch",
            status TEXT NOT NULL DEFAULT "active",
            tls_mode TEXT NOT NULL DEFAULT "managed",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_domains_status_purpose ON delivery_domains(status, purpose)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS storage_maintenance_windows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            storage_node_id INTEGER NOT NULL,
            starts_at TEXT NOT NULL,
            ends_at TEXT NOT NULL,
            mode TEXT NOT NULL DEFAULT "drain",
            reason TEXT NOT NULL DEFAULT "",
            created_by_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (storage_node_id) REFERENCES storage_nodes (id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_storage_maintenance_node_start ON storage_maintenance_windows(storage_node_id, starts_at DESC)');

    ve_admin_seed_roles_and_permissions($pdo);
    ve_admin_seed_app_settings($pdo);
    ve_admin_bootstrap_accounts($pdo);
}

function ve_admin_seed_roles_and_permissions(PDO $pdo): void
{
    $now = ve_now();
    $roleStmt = $pdo->prepare(
        'INSERT INTO roles (code, name, created_at)
         VALUES (:code, :name, :created_at)
         ON CONFLICT(code) DO UPDATE SET name = excluded.name'
    );

    foreach (ve_admin_role_catalog() as $code => $role) {
        $roleStmt->execute([
            ':code' => $code,
            ':name' => (string) ($role['label'] ?? $code),
            ':created_at' => $now,
        ]);
    }

    $permissionStmt = $pdo->prepare(
        'INSERT INTO permissions (code, name, group_code, created_at)
         VALUES (:code, :name, :group_code, :created_at)
         ON CONFLICT(code) DO UPDATE SET
            name = excluded.name,
            group_code = excluded.group_code'
    );

    foreach (ve_admin_permission_catalog() as $code => $permission) {
        $permissionStmt->execute([
            ':code' => $code,
            ':name' => (string) ($permission['label'] ?? $code),
            ':group_code' => (string) ($permission['group_code'] ?? 'core'),
            ':created_at' => $now,
        ]);
    }

    $roleIdMap = [];
    foreach ($pdo->query('SELECT id, code FROM roles') as $row) {
        if (is_array($row)) {
            $roleIdMap[(string) ($row['code'] ?? '')] = (int) ($row['id'] ?? 0);
        }
    }

    $permissionIdMap = [];
    foreach ($pdo->query('SELECT id, code FROM permissions') as $row) {
        if (is_array($row)) {
            $permissionIdMap[(string) ($row['code'] ?? '')] = (int) ($row['id'] ?? 0);
        }
    }

    $pdo->exec('DELETE FROM role_permissions');
    $rolePermissionStmt = $pdo->prepare(
        'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
         VALUES (:role_id, :permission_id, :created_at)'
    );

    foreach (ve_admin_role_catalog() as $roleCode => $role) {
        $roleId = (int) ($roleIdMap[$roleCode] ?? 0);

        if ($roleId <= 0) {
            continue;
        }

        foreach ((array) ($role['permissions'] ?? []) as $permissionCode) {
            $permissionId = (int) ($permissionIdMap[(string) $permissionCode] ?? 0);

            if ($permissionId <= 0) {
                continue;
            }

            $rolePermissionStmt->execute([
                ':role_id' => $roleId,
                ':permission_id' => $permissionId,
                ':created_at' => $now,
            ]);
        }
    }
}

function ve_admin_seed_app_settings(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)'
    );
    $updatedAt = ve_now();

    foreach (ve_admin_default_settings() as $key => $value) {
        $stmt->execute([
            ':setting_key' => $key,
            ':setting_value' => $value,
            ':updated_at' => $updatedAt,
        ]);
    }
}

function ve_admin_bootstrap_accounts(PDO $pdo): void
{
    $superAdminRoleId = ve_admin_role_id_by_code_from_pdo($pdo, 'super_admin');

    if ($superAdminRoleId <= 0) {
        return;
    }

    foreach (ve_admin_bootstrap_logins() as $login) {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE deleted_at IS NULL
               AND (lower(username) = lower(:login) OR lower(email) = lower(:login))
             LIMIT 1'
        );
        $stmt->execute([':login' => $login]);
        $userId = (int) $stmt->fetchColumn();

        if ($userId <= 0) {
            continue;
        }

        $active = $pdo->prepare(
            'SELECT id
             FROM user_role_assignments
             WHERE user_id = :user_id
               AND role_id = :role_id
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at >= :now)
             LIMIT 1'
        );
        $active->execute([
            ':user_id' => $userId,
            ':role_id' => $superAdminRoleId,
            ':now' => ve_now(),
        ]);

        if ($active->fetchColumn() !== false) {
            continue;
        }

        $pdo->prepare(
            'INSERT INTO user_role_assignments (user_id, role_id, granted_by_user_id, created_at, expires_at, revoked_at)
             VALUES (:user_id, :role_id, NULL, :created_at, NULL, NULL)'
        )->execute([
            ':user_id' => $userId,
            ':role_id' => $superAdminRoleId,
            ':created_at' => ve_now(),
        ]);
    }
}

function ve_admin_role_id_by_code_from_pdo(PDO $pdo, string $roleCode): int
{
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $roleCode]);
    return (int) $stmt->fetchColumn();
}

function ve_admin_role_id_by_code(string $roleCode): int
{
    return ve_admin_role_id_by_code_from_pdo(ve_db(), $roleCode);
}

function ve_admin_role_label(string $roleCode): string
{
    $catalog = ve_admin_role_catalog();
    return (string) ($catalog[$roleCode]['label'] ?? 'User');
}

function ve_admin_permission_codes_for_user_id(int $userId, bool $refresh = false): array
{
    static $cache = [];

    if ($refresh) {
        unset($cache[$userId]);
    }

    if (isset($cache[$userId]) && is_array($cache[$userId])) {
        return $cache[$userId];
    }

    if ($userId <= 0) {
        return [];
    }

    $stmt = ve_db()->prepare(
        'SELECT DISTINCT permissions.code
         FROM user_role_assignments
         INNER JOIN role_permissions ON role_permissions.role_id = user_role_assignments.role_id
         INNER JOIN permissions ON permissions.id = role_permissions.permission_id
         WHERE user_role_assignments.user_id = :user_id
           AND user_role_assignments.revoked_at IS NULL
           AND (user_role_assignments.expires_at IS NULL OR user_role_assignments.expires_at >= :now)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':now' => ve_now(),
    ]);

    $codes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $code) {
        if (is_string($code) && $code !== '') {
            $codes[$code] = $code;
        }
    }

    $cache[$userId] = array_values($codes);
    return $cache[$userId];
}

function ve_admin_role_codes_for_user_id(int $userId, bool $refresh = false): array
{
    static $cache = [];

    if ($refresh) {
        unset($cache[$userId]);
    }

    if (isset($cache[$userId]) && is_array($cache[$userId])) {
        return $cache[$userId];
    }

    if ($userId <= 0) {
        return [];
    }

    $stmt = ve_db()->prepare(
        'SELECT roles.code
         FROM user_role_assignments
         INNER JOIN roles ON roles.id = user_role_assignments.role_id
         WHERE user_role_assignments.user_id = :user_id
           AND user_role_assignments.revoked_at IS NULL
           AND (user_role_assignments.expires_at IS NULL OR user_role_assignments.expires_at >= :now)
         ORDER BY roles.id ASC'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':now' => ve_now(),
    ]);

    $codes = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $code) {
        if (is_string($code) && $code !== '') {
            $codes[$code] = $code;
        }
    }

    $cache[$userId] = array_values($codes);
    return $cache[$userId];
}

function ve_admin_primary_role_code_for_user_id(int $userId): string
{
    $codes = ve_admin_role_codes_for_user_id($userId);

    if (in_array('super_admin', $codes, true)) {
        return 'super_admin';
    }

    if (in_array('admin', $codes, true)) {
        return 'admin';
    }

    return '';
}

function ve_user_has_permission(?array $user, string $permissionCode): bool
{
    if (!is_array($user)) {
        return false;
    }

    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0) {
        return false;
    }

    return in_array($permissionCode, ve_admin_permission_codes_for_user_id($userId), true);
}

function ve_admin_current_impersonator_id(): int
{
    $value = $_SESSION[VE_SESSION_IMPERSONATOR_ID] ?? null;
    return (is_int($value) || ctype_digit((string) $value)) ? (int) $value : 0;
}

function ve_admin_actor_user(bool $refresh = false): ?array
{
    static $actorUser;

    if ($refresh) {
        $actorUser = null;
    }

    if (is_array($actorUser)) {
        return $actorUser;
    }

    $impersonatorId = ve_admin_current_impersonator_id();

    if ($impersonatorId > 0) {
        $impersonator = ve_get_user_by_id($impersonatorId);

        if (is_array($impersonator) && (string) ($impersonator['status'] ?? '') === 'active' && ($impersonator['deleted_at'] ?? null) === null) {
            $impersonator['settings'] = ve_get_user_settings($impersonatorId);
            $actorUser = $impersonator;
            return $actorUser;
        }

        unset($_SESSION[VE_SESSION_IMPERSONATOR_ID]);
    }

    $actorUser = ve_current_user($refresh);
    return $actorUser;
}

function ve_admin_is_impersonating(): bool
{
    $impersonatorId = ve_admin_current_impersonator_id();
    $currentUser = ve_current_user();

    return $impersonatorId > 0
        && is_array($currentUser)
        && (int) ($currentUser['id'] ?? 0) > 0
        && (int) ($currentUser['id'] ?? 0) !== $impersonatorId;
}

function ve_admin_has_backend_access(?array $actorUser = null): bool
{
    if (!is_array($actorUser)) {
        $actorUser = ve_admin_actor_user();
    }

    return ve_user_has_permission($actorUser, 'admin.access');
}

function ve_admin_require_permission(string $permissionCode = 'admin.access'): array
{
    $actorUser = ve_admin_actor_user();

    if (!ve_admin_has_backend_access($actorUser)) {
        ve_not_found();
    }

    if (!ve_user_has_permission($actorUser, $permissionCode)) {
        ve_flash('danger', 'You do not have access to that backend section.');
        ve_redirect('/backend');
    }

    return $actorUser;
}

function ve_admin_stop_impersonation(): void
{
    $impersonatorId = ve_admin_current_impersonator_id();

    if ($impersonatorId <= 0) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION[VE_SESSION_USER_ID] = $impersonatorId;
    $_SESSION[VE_SESSION_CSRF] = bin2hex(random_bytes(16));
    unset($_SESSION[VE_SESSION_IMPERSONATOR_ID]);
    ve_current_user(true);
    ve_admin_actor_user(true);
}

function ve_admin_start_impersonation(array $actorUser, int $targetUserId): bool
{
    if (!ve_user_has_permission($actorUser, 'admin.users.impersonate')) {
        return false;
    }

    $actorUserId = (int) ($actorUser['id'] ?? 0);

    if ($actorUserId <= 0 || $targetUserId <= 0 || $actorUserId === $targetUserId) {
        return false;
    }

    $targetUser = ve_get_user_by_id($targetUserId);

    if (!is_array($targetUser) || (string) ($targetUser['status'] ?? '') !== 'active' || ($targetUser['deleted_at'] ?? null) !== null) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION[VE_SESSION_USER_ID] = $targetUserId;
    $_SESSION[VE_SESSION_IMPERSONATOR_ID] = $actorUserId;
    $_SESSION[VE_SESSION_CSRF] = bin2hex(random_bytes(16));
    ve_store_session_record($targetUserId);
    ve_current_user(true);
    ve_admin_actor_user(true);

    return true;
}

function ve_admin_set_user_primary_role(int $targetUserId, string $roleCode, ?int $grantedByUserId = null): void
{
    $roleCode = trim($roleCode);
    $pdo = ve_db();
    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'UPDATE user_role_assignments
             SET revoked_at = :revoked_at
             WHERE user_id = :user_id
               AND revoked_at IS NULL'
        )->execute([
            ':revoked_at' => ve_now(),
            ':user_id' => $targetUserId,
        ]);

        if ($roleCode !== '') {
            $roleId = ve_admin_role_id_by_code($roleCode);

            if ($roleId <= 0) {
                throw new RuntimeException('Role not found.');
            }

            $pdo->prepare(
                'INSERT INTO user_role_assignments (user_id, role_id, granted_by_user_id, created_at, expires_at, revoked_at)
                 VALUES (:user_id, :role_id, :granted_by_user_id, :created_at, NULL, NULL)'
            )->execute([
                ':user_id' => $targetUserId,
                ':role_id' => $roleId,
                ':granted_by_user_id' => $grantedByUserId,
                ':created_at' => ve_now(),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }

    ve_admin_role_codes_for_user_id($targetUserId, true);
    ve_admin_permission_codes_for_user_id($targetUserId, true);
    ve_admin_actor_user(true);
}

function ve_admin_log_event(
    string $eventCode,
    string $targetType,
    ?int $targetId = null,
    array $before = [],
    array $after = [],
    ?int $actorUserId = null
): void {
    if ($actorUserId === null) {
        $actor = ve_admin_actor_user();
        $actorUserId = is_array($actor) ? (int) ($actor['id'] ?? 0) : null;
    }

    ve_db()->prepare(
        'INSERT INTO audit_logs (
            actor_user_id, target_type, target_id, event_code, before_json, after_json, ip_address, created_at
         ) VALUES (
            :actor_user_id, :target_type, :target_id, :event_code, :before_json, :after_json, :ip_address, :created_at
         )'
    )->execute([
        ':actor_user_id' => $actorUserId,
        ':target_type' => $targetType,
        ':target_id' => $targetId,
        ':event_code' => $eventCode,
        ':before_json' => json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':after_json' => json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':ip_address' => ve_client_ip(),
        ':created_at' => ve_now(),
    ]);
}

function ve_admin_parse_amount_to_micro_usd(string $rawAmount): int
{
    $normalized = trim(str_replace([',', '$'], '', $rawAmount));

    if ($normalized === '' || preg_match('/^\d+(?:\.\d{1,6})?$/', $normalized) !== 1) {
        return 0;
    }

    return (int) round((float) $normalized * 1000000);
}

function ve_admin_mask_payout_destination(string $destination): string
{
    $destination = trim($destination);

    if ($destination === '') {
        return '';
    }

    if (strlen($destination) <= 6) {
        return str_repeat('*', strlen($destination));
    }

    return substr($destination, 0, 3) . str_repeat('*', max(4, strlen($destination) - 6)) . substr($destination, -3);
}

function ve_admin_payout_minimum_micro_usd(): int
{
    return ve_get_app_setting_int('payout_minimum_micro_usd', 10000000, 1000000, 1000000000);
}

function ve_admin_payout_open_statuses(): array
{
    return ['pending', 'approved'];
}

function ve_admin_has_open_payout_request(int $userId): bool
{
    $stmt = ve_db()->prepare(
        'SELECT 1
         FROM payout_requests
         WHERE user_id = :user_id
           AND status IN ("pending", "approved")
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchColumn() !== false;
}

function ve_admin_payout_ledger_entry_exists(string $sourceKey, string $entryType): bool
{
    $stmt = ve_db()->prepare(
        'SELECT 1
         FROM account_balance_ledger
         WHERE source_type = "payout_request"
           AND source_key = :source_key
           AND entry_type = :entry_type
         LIMIT 1'
    );
    $stmt->execute([
        ':source_key' => $sourceKey,
        ':entry_type' => $entryType,
    ]);

    return $stmt->fetchColumn() !== false;
}

function ve_admin_create_payout_request(int $userId, int $amountMicroUsd, string $notes = ''): array
{
    $user = ve_get_user_by_id($userId);

    if (!is_array($user) || (string) ($user['status'] ?? '') !== 'active' || ($user['deleted_at'] ?? null) !== null) {
        throw new RuntimeException('Account is not eligible for payouts.');
    }

    $settings = ve_get_user_settings($userId);
    $paymentMethod = trim((string) ($settings['payment_method'] ?? ''));
    $paymentId = trim((string) ($settings['payment_id'] ?? ''));

    if (!in_array($paymentMethod, ve_allowed_payment_methods(), true)) {
        throw new RuntimeException('Configure a valid payout method in settings first.');
    }

    if ($paymentId === '') {
        throw new RuntimeException('Add a payout destination in settings first.');
    }

    $minimum = ve_admin_payout_minimum_micro_usd();

    if ($amountMicroUsd < $minimum) {
        throw new RuntimeException('The minimum payout is ' . ve_dashboard_format_currency_micro_usd($minimum) . '.');
    }

    $availableBalance = ve_dashboard_balance_micro_usd($userId);

    if ($amountMicroUsd > $availableBalance) {
        throw new RuntimeException('You do not have enough balance available.');
    }

    if (ve_admin_has_open_payout_request($userId)) {
        throw new RuntimeException('You already have an open payout request.');
    }

    $pdo = ve_db();
    $now = ve_now();
    $publicId = 'po_' . strtolower(bin2hex(random_bytes(6)));
    $masked = ve_admin_mask_payout_destination($paymentId);
    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'INSERT INTO payout_requests (
                public_id, user_id, amount_micro_usd, payout_method, payout_destination_masked, status,
                notes, reviewed_by_user_id, reviewed_at, rejection_reason, transfer_reference, created_at, updated_at
             ) VALUES (
                :public_id, :user_id, :amount_micro_usd, :payout_method, :payout_destination_masked, "pending",
                :notes, NULL, NULL, "", "", :created_at, :updated_at
             )'
        )->execute([
            ':public_id' => $publicId,
            ':user_id' => $userId,
            ':amount_micro_usd' => $amountMicroUsd,
            ':payout_method' => $paymentMethod,
            ':payout_destination_masked' => $masked,
            ':notes' => $notes,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $requestId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO account_balance_ledger (
                user_id, entry_type, source_type, source_key, amount_micro_usd, description, metadata_json, created_at
             ) VALUES (
                :user_id, "hold", "payout_request", :source_key, :amount_micro_usd, :description, :metadata_json, :created_at
             )'
        )->execute([
            ':user_id' => $userId,
            ':source_key' => $publicId,
            ':amount_micro_usd' => -$amountMicroUsd,
            ':description' => 'Payout request hold',
            ':metadata_json' => json_encode([
                'request_id' => $requestId,
                'payout_method' => $paymentMethod,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
        ]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }

    ve_add_notification($userId, 'Payout request submitted', 'Your payout request for ' . ve_dashboard_format_currency_micro_usd($amountMicroUsd) . ' is now pending review.');
    ve_admin_log_event(
        'payout.requested',
        'payout_request',
        isset($requestId) ? $requestId : null,
        [],
        [
            'public_id' => $publicId,
            'amount_micro_usd' => $amountMicroUsd,
            'payout_method' => $paymentMethod,
        ],
        $userId
    );

    $created = ve_admin_payout_request_by_public_id($publicId);

    return is_array($created) ? $created : [];
}

function ve_admin_payout_request_by_public_id(string $publicId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT payout_requests.*, users.username, users.email
         FROM payout_requests
         INNER JOIN users ON users.id = payout_requests.user_id
         WHERE payout_requests.public_id = :public_id
         LIMIT 1'
    );
    $stmt->execute([':public_id' => $publicId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_payout_request_by_id(int $requestId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT payout_requests.*, users.username, users.email
         FROM payout_requests
         INNER JOIN users ON users.id = payout_requests.user_id
         WHERE payout_requests.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_list_user_payout_requests(int $userId, int $limit = 20): array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM payout_requests
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ve_handle_payout_request_api(): void
{
    $user = ve_require_auth();
    ve_require_csrf(ve_request_csrf_token());

    $amountMicroUsd = ve_admin_parse_amount_to_micro_usd((string) ($_POST['amount'] ?? ''));

    if ($amountMicroUsd <= 0) {
        $amountMicroUsd = ve_dashboard_balance_micro_usd((int) $user['id']);
    }

    try {
        $request = ve_admin_create_payout_request((int) $user['id'], $amountMicroUsd, trim((string) ($_POST['notes'] ?? '')));
    } catch (Throwable $throwable) {
        ve_json([
            'status' => 'fail',
            'message' => $throwable->getMessage(),
        ], 422);
    }

    ve_json([
        'status' => 'ok',
        'message' => 'Payout request submitted successfully.',
        'request' => $request,
        'balance_micro_usd' => ve_dashboard_balance_micro_usd((int) $user['id']),
        'balance_label' => ve_dashboard_format_currency_micro_usd(ve_dashboard_balance_micro_usd((int) $user['id'])),
    ]);
}

function ve_admin_page_size(): int
{
    return ve_get_app_setting_int('admin_default_page_size', VE_ADMIN_PAGE_SIZE, 10, 100);
}

function ve_admin_recent_audit_limit(): int
{
    return ve_get_app_setting_int('admin_recent_audit_limit', 12, 5, 50);
}

function ve_admin_allowed_sections_for_user(array $actorUser): array
{
    $allowed = [];

    foreach (ve_admin_sections() as $code => $section) {
        $permission = (string) ($section['permission'] ?? '');

        if ($permission !== '' && ve_user_has_permission($actorUser, $permission)) {
            $allowed[$code] = $section;
        }
    }

    return $allowed;
}

function ve_admin_current_section(array $actorUser): string
{
    $requested = ve_admin_request_path_info()['section'];
    $allowed = ve_admin_allowed_sections_for_user($actorUser);

    if ($requested !== '' && isset($allowed[$requested])) {
        return $requested;
    }

    return array_key_first($allowed) ?: 'overview';
}

function ve_admin_request_page(): int
{
    $page = (int) ($_GET['page'] ?? 1);
    return max(1, $page);
}

function ve_admin_backend_view_definitions(): array
{
    return [
        'overview' => [
            ['slug' => 'overview-service', 'label' => 'Service totals', 'icon' => 'fa-th-large', 'kind' => 'sidebar'],
            ['slug' => 'overview-users', 'label' => 'User trends', 'icon' => 'fa-users', 'kind' => 'sidebar'],
            ['slug' => 'overview-usage', 'label' => 'Usage trends', 'icon' => 'fa-play-circle', 'kind' => 'sidebar'],
            ['slug' => 'overview-traffic', 'label' => 'Traffic trends', 'icon' => 'fa-chart-line', 'kind' => 'sidebar'],
        ],
        'users' => [
            ['slug' => 'users-directory', 'label' => 'Directory', 'icon' => 'fa-address-book', 'kind' => 'sidebar'],
            ['slug' => 'users-segments', 'label' => 'Segments', 'icon' => 'fa-layer-group', 'kind' => 'sidebar'],
            ['slug' => 'users-operations', 'label' => 'Operations', 'icon' => 'fa-user-clock', 'kind' => 'sidebar'],
            ['slug' => 'users-profile', 'label' => 'Profile', 'icon' => 'fa-id-card', 'kind' => 'detail'],
            ['slug' => 'users-activity', 'label' => 'Activity', 'icon' => 'fa-chart-line', 'kind' => 'detail'],
            ['slug' => 'users-access', 'label' => 'Access & Billing', 'icon' => 'fa-wallet', 'kind' => 'detail'],
            ['slug' => 'users-related', 'label' => 'Related', 'icon' => 'fa-link', 'kind' => 'detail'],
        ],
        'videos' => [
            ['slug' => 'videos-library', 'label' => 'Library', 'icon' => 'fa-folder-open', 'kind' => 'sidebar'],
            ['slug' => 'videos-detail', 'label' => 'File detail', 'icon' => 'fa-file-video', 'kind' => 'detail'],
        ],
        'remote-uploads' => [
            ['slug' => 'remote-uploads-queue', 'label' => 'Queue', 'icon' => 'fa-tasks', 'kind' => 'sidebar'],
            ['slug' => 'remote-uploads-detail', 'label' => 'Job detail', 'icon' => 'fa-cloud-download', 'kind' => 'detail'],
        ],
        'dmca' => [
            ['slug' => 'dmca-queue', 'label' => 'Case queue', 'icon' => 'fa-balance-scale', 'kind' => 'sidebar'],
            ['slug' => 'dmca-detail', 'label' => 'Case detail', 'icon' => 'fa-file-certificate', 'kind' => 'detail'],
            ['slug' => 'dmca-events', 'label' => 'Case events', 'icon' => 'fa-stream', 'kind' => 'detail'],
        ],
        'payouts' => [
            ['slug' => 'payouts-queue', 'label' => 'Queue', 'icon' => 'fa-wallet', 'kind' => 'sidebar'],
            ['slug' => 'payouts-detail', 'label' => 'Request detail', 'icon' => 'fa-receipt', 'kind' => 'detail'],
            ['slug' => 'payouts-transfer', 'label' => 'Transfer tracking', 'icon' => 'fa-exchange-alt', 'kind' => 'detail'],
        ],
        'domains' => [
            ['slug' => 'domains-directory', 'label' => 'Directory', 'icon' => 'fa-globe', 'kind' => 'sidebar'],
            ['slug' => 'domains-detail', 'label' => 'Domain detail', 'icon' => 'fa-browser', 'kind' => 'detail'],
        ],
        'app' => [
            ['slug' => 'app-general', 'label' => 'General', 'icon' => 'fa-sliders-h', 'kind' => 'sidebar'],
            ['slug' => 'app-roles', 'label' => 'Roles', 'icon' => 'fa-user-shield', 'kind' => 'sidebar'],
            ['slug' => 'app-permissions', 'label' => 'Permissions', 'icon' => 'fa-key', 'kind' => 'sidebar'],
        ],
        'infrastructure' => [
            ['slug' => 'infra-nodes', 'label' => 'Nodes', 'icon' => 'fa-server', 'kind' => 'sidebar'],
            ['slug' => 'infra-volumes', 'label' => 'Volumes', 'icon' => 'fa-hdd', 'kind' => 'sidebar'],
            ['slug' => 'infra-endpoints', 'label' => 'Upload endpoints', 'icon' => 'fa-upload', 'kind' => 'sidebar'],
            ['slug' => 'infra-delivery', 'label' => 'Delivery domains', 'icon' => 'fa-broadcast-tower', 'kind' => 'sidebar'],
            ['slug' => 'infra-maintenance', 'label' => 'Maintenance', 'icon' => 'fa-tools', 'kind' => 'sidebar'],
        ],
        'audit' => [
            ['slug' => 'audit-feed', 'label' => 'Log feed', 'icon' => 'fa-clipboard-list', 'kind' => 'sidebar'],
            ['slug' => 'audit-detail', 'label' => 'Audit detail', 'icon' => 'fa-search', 'kind' => 'detail'],
        ],
    ];
}

function ve_admin_backend_subview_catalog(): array
{
    $catalog = [];

    foreach (ve_admin_backend_view_definitions() as $section => $definitions) {
        foreach ($definitions as $definition) {
            $slug = trim((string) ($definition['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $catalog[$slug] = [
                'section' => $section,
                'kind' => (string) ($definition['kind'] ?? 'sidebar'),
                'label' => (string) ($definition['label'] ?? $slug),
                'icon' => (string) ($definition['icon'] ?? 'fa-circle'),
            ];
        }
    }

    $catalog['users-charts'] = ['section' => 'users', 'kind' => 'alias', 'canonical' => 'users-activity'];
    $catalog['users-sessions'] = ['section' => 'users', 'kind' => 'alias', 'canonical' => 'users-access'];
    $catalog['users-billing'] = ['section' => 'users', 'kind' => 'alias', 'canonical' => 'users-access'];

    return $catalog;
}

function ve_admin_canonical_subview(string $slug): string
{
    $catalog = ve_admin_backend_subview_catalog();
    $entry = $catalog[$slug] ?? null;

    if (is_array($entry) && isset($entry['canonical']) && is_string($entry['canonical']) && $entry['canonical'] !== '') {
        return (string) $entry['canonical'];
    }

    return $slug;
}

function ve_admin_default_subview(string $section, int $resourceId = 0): string
{
    return match ($section) {
        'overview' => 'overview-service',
        'users' => $resourceId > 0 ? 'users-profile' : 'users-directory',
        'videos' => $resourceId > 0 ? 'videos-detail' : 'videos-library',
        'remote-uploads' => $resourceId > 0 ? 'remote-uploads-detail' : 'remote-uploads-queue',
        'dmca' => $resourceId > 0 ? 'dmca-detail' : 'dmca-queue',
        'payouts' => $resourceId > 0 ? 'payouts-detail' : 'payouts-queue',
        'domains' => $resourceId > 0 ? 'domains-detail' : 'domains-directory',
        'app' => 'app-general',
        'infrastructure' => 'infra-nodes',
        'audit' => $resourceId > 0 ? 'audit-detail' : 'audit-feed',
        default => $section,
    };
}

function ve_admin_sidebar_active_subview(string $section, string $activeSubview): string
{
    return match ($section) {
        'users' => in_array($activeSubview, ['users-profile', 'users-activity', 'users-access', 'users-related', 'users-charts', 'users-sessions', 'users-billing'], true)
            ? 'users-directory'
            : $activeSubview,
        'videos' => $activeSubview === 'videos-detail' ? 'videos-library' : $activeSubview,
        'remote-uploads' => $activeSubview === 'remote-uploads-detail' ? 'remote-uploads-queue' : $activeSubview,
        'dmca' => in_array($activeSubview, ['dmca-detail', 'dmca-events'], true) ? 'dmca-queue' : $activeSubview,
        'payouts' => in_array($activeSubview, ['payouts-detail', 'payouts-transfer'], true) ? 'payouts-queue' : $activeSubview,
        'domains' => $activeSubview === 'domains-detail' ? 'domains-directory' : $activeSubview,
        'audit' => $activeSubview === 'audit-detail' ? 'audit-feed' : $activeSubview,
        default => $activeSubview,
    };
}

function ve_admin_request_path_info(): array
{
    $path = ve_request_path();
    $prefix = '/backend';

    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
        return [
            'section' => 'overview',
            'resource' => '',
            'segments' => [],
        ];
    }

    $suffix = trim(substr($path, strlen($prefix)) ?: '', '/');
    $segments = $suffix === '' ? [] : array_values(array_filter(explode('/', $suffix), static fn ($segment): bool => $segment !== ''));
    $catalog = ve_admin_backend_subview_catalog();
    $firstSegment = $segments[0] ?? '';
    $subsection = '';
    $section = 'overview';
    $resource = '';

    if ($firstSegment === '') {
        $subsection = ve_admin_default_subview('overview');
    } elseif (isset($catalog[$firstSegment])) {
        $subsection = $firstSegment;
        $section = (string) ($catalog[$firstSegment]['section'] ?? 'overview');
        $resource = isset($segments[1]) ? rawurldecode((string) $segments[1]) : '';
    } else {
        $section = $firstSegment;
        $resource = isset($segments[1]) ? rawurldecode((string) $segments[1]) : '';
        $resourceId = ctype_digit($resource) ? (int) $resource : 0;
        $subsection = ve_admin_default_subview($section, $resourceId);
    }

    return [
        'section' => $section,
        'subsection' => $subsection,
        'resource' => $resource,
        'segments' => $segments,
    ];
}

function ve_admin_current_resource_token(): string
{
    return (string) (ve_admin_request_path_info()['resource'] ?? '');
}

function ve_admin_current_resource_id(): int
{
    $resource = ve_admin_current_resource_token();
    return ctype_digit($resource) ? (int) $resource : 0;
}

function ve_admin_current_subview_slug(?string $section = null): string
{
    $pathInfo = ve_admin_request_path_info();
    $resourceId = ve_admin_current_resource_id();
    $currentSection = (string) ($pathInfo['section'] ?? 'overview');
    $subsection = ve_admin_canonical_subview(trim((string) ($pathInfo['subsection'] ?? '')));

    if ($section !== null && $section !== '' && $section !== $currentSection) {
        return ve_admin_default_subview($section, $resourceId);
    }

    if ($subsection !== '') {
        return $subsection;
    }

    return ve_admin_default_subview($currentSection, $resourceId);
}

function ve_admin_subsection_url(string $subsection, string|int|null $resource = null, array $overrides = [], bool $preserveCurrent = false): string
{
    $params = $preserveCurrent ? $_GET : [];

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }

        $params[$key] = $value;
    }

    $path = '/backend/' . rawurlencode($subsection);

    if ($resource !== null && (string) $resource !== '') {
        $path .= '/' . rawurlencode((string) $resource);
    }

    $query = http_build_query($params);

    return ve_url($path . ($query !== '' ? '?' . $query : ''));
}

function ve_admin_url(array $overrides = [], bool $preserveCurrent = true): string
{
    $pathInfo = $preserveCurrent ? ve_admin_request_path_info() : [
        'section' => 'overview',
        'resource' => '',
    ];
    $params = $preserveCurrent ? $_GET : [];
    $section = (string) ($pathInfo['section'] ?? 'overview');
    $resource = (string) ($pathInfo['resource'] ?? '');

    foreach ($overrides as $key => $value) {
        if ($key === 'section') {
            $section = trim((string) $value);
            continue;
        }

        if ($key === 'resource') {
            $resource = trim((string) $value);
            continue;
        }

        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }

        $params[$key] = $value;
    }

    $path = '/backend';

    if ($section !== '' && $section !== 'overview') {
        $path .= '/' . rawurlencode($section);
    }

    if ($resource !== '') {
        $path .= '/' . rawurlencode($resource);
    }

    $query = http_build_query($params);
    return ve_url($path . ($query !== '' ? '?' . $query : ''));
}

function ve_admin_pagination_html(int $page, int $totalRows, int $pageSize): string
{
    $totalPages = max(1, (int) ceil($totalRows / max(1, $pageSize)));

    if ($totalPages <= 1) {
        return '';
    }

    $items = [];
    $pathInfo = ve_admin_request_path_info();
    $currentSubsection = trim((string) ($pathInfo['subsection'] ?? ''));
    $currentResource = trim((string) ($pathInfo['resource'] ?? ''));

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $page ? ' active' : '';
        $url = $currentSubsection !== ''
            ? ve_h(ve_admin_subsection_url($currentSubsection, $currentResource !== '' ? $currentResource : null, ['page' => $i], true))
            : ve_h(ve_admin_url(['page' => $i]));
        $items[] = '<li class="page-item' . $active . '"><a class="page-link" href="' . $url . '">' . $i . '</a></li>';
    }

    return '<nav aria-label="Pagination"><ul class="pagination pagination-sm flex-wrap">' . implode('', $items) . '</ul></nav>';
}

function ve_admin_sql_like(string $value): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($value)) . '%';
}

function ve_admin_overview_snapshot(): array
{
    $pdo = ve_db();
    $today = gmdate('Y-m-d');

    $users = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN status = 'suspended' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS suspended_users,
            SUM(CASE WHEN deleted_at IS NULL AND substr(created_at, 1, 10) = '" . $today . "' THEN 1 ELSE 0 END) AS users_today
         FROM users
         WHERE deleted_at IS NULL"
    )->fetch() ?: []);

    $videos = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS total_videos,
            SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready_videos,
            COALESCE(SUM(CASE WHEN processed_size_bytes > 0 THEN processed_size_bytes ELSE original_size_bytes END), 0) AS storage_bytes
         FROM videos
         WHERE deleted_at IS NULL"
    )->fetch() ?: []);

    $remote = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS total_jobs,
            SUM(CASE WHEN status IN ('pending', 'resolving', 'downloading', 'importing') THEN 1 ELSE 0 END) AS queued_jobs,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_jobs
         FROM remote_uploads
         WHERE deleted_at IS NULL"
    )->fetch() ?: []);

    $dmca = (array) ($pdo->query(
        "SELECT
             COUNT(*) AS total_notices,
             SUM(CASE WHEN status IN ('pending_review', 'content_disabled', 'counter_submitted', 'response_submitted') THEN 1 ELSE 0 END) AS open_notices
         FROM dmca_notices"
    )->fetch() ?: []);

    $payouts = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS total_payouts,
            SUM(CASE WHEN status IN ('pending', 'approved') THEN 1 ELSE 0 END) AS open_payouts,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_micro_usd ELSE 0 END), 0) AS paid_micro_usd
         FROM payout_requests"
    )->fetch() ?: []);

    $domains = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS custom_domains,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_domains
         FROM custom_domains"
    )->fetch() ?: []);

    $infrastructure = (array) ($pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM storage_nodes) AS storage_nodes,
            (SELECT COUNT(*) FROM upload_endpoints WHERE is_active = 1) AS active_upload_endpoints,
            (SELECT COUNT(*) FROM delivery_domains WHERE status = 'active') AS active_delivery_domains"
    )->fetch() ?: []);

    return [
        'users' => $users,
        'videos' => $videos,
        'remote' => $remote,
        'dmca' => $dmca,
        'payouts' => $payouts,
        'domains' => $domains,
        'infrastructure' => $infrastructure,
        'recent_audit' => ve_admin_list_audit_logs(1, ve_admin_recent_audit_limit()),
    ];
}

function ve_admin_list_users(string $query = '', string $status = '', string $roleCode = '', int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['users.deleted_at IS NULL'];
    $params = [];

    if ($query !== '') {
        $where[] = '(users.username LIKE :query ESCAPE "\\" OR users.email LIKE :query ESCAPE "\\" OR CAST(users.id AS TEXT) = :query_exact)';
        $params[':query'] = ve_admin_sql_like($query);
        $params[':query_exact'] = $query;
    }

    if ($status !== '') {
        $where[] = 'users.status = :status';
        $params[':status'] = $status;
    }

    if ($roleCode !== '') {
        $where[] = 'EXISTS (
            SELECT 1
            FROM user_role_assignments ura
            INNER JOIN roles r ON r.id = ura.role_id
            WHERE ura.user_id = users.id
              AND ura.revoked_at IS NULL
              AND (ura.expires_at IS NULL OR ura.expires_at >= :role_now)
              AND r.code = :role_code
        )';
        $params[':role_now'] = ve_now();
        $params[':role_code'] = $roleCode;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $sql = 'SELECT
                users.*,
                user_settings.payment_method,
                user_settings.payment_id,
                user_settings.api_enabled,
                COALESCE(video_stats.video_count, 0) AS video_count,
                COALESCE(video_stats.storage_bytes, 0) AS storage_bytes,
                COALESCE(role_map.role_code, "") AS role_code
            FROM users
            LEFT JOIN user_settings ON user_settings.user_id = users.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS video_count, COALESCE(SUM(CASE WHEN processed_size_bytes > 0 THEN processed_size_bytes ELSE original_size_bytes END), 0) AS storage_bytes
                FROM videos
                WHERE deleted_at IS NULL
                GROUP BY user_id
            ) AS video_stats ON video_stats.user_id = users.id
            LEFT JOIN (
                SELECT ura.user_id, COALESCE(MAX(CASE WHEN roles.code = "super_admin" THEN "super_admin" ELSE roles.code END), "") AS role_code
                FROM user_role_assignments ura
                INNER JOIN roles ON roles.id = ura.role_id
                WHERE ura.revoked_at IS NULL
                  AND (ura.expires_at IS NULL OR ura.expires_at >= :roles_now)
                GROUP BY ura.user_id
            ) AS role_map ON role_map.user_id = users.id
            WHERE ' . $whereSql . '
            ORDER BY users.id DESC
            LIMIT :limit OFFSET :offset';

    $stmt = ve_db()->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':roles_now', ve_now());
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_user_detail(int $userId): ?array
{
    $user = ve_get_user_by_id($userId);

    if (!is_array($user) || ($user['deleted_at'] ?? null) !== null) {
        return null;
    }

    $user['settings'] = ve_get_user_settings($userId);
    $user['role_codes'] = ve_admin_role_codes_for_user_id($userId);
    $user['primary_role_code'] = ve_admin_primary_role_code_for_user_id($userId);
    $user['balance_micro_usd'] = ve_dashboard_balance_micro_usd($userId);
    $user['recent_videos'] = ve_admin_list_videos('', '', $userId, 1, 5)['rows'];
    $user['recent_remote_uploads'] = ve_admin_list_remote_uploads('', '', $userId, 1, 5)['rows'];
    $user['recent_payouts'] = ve_admin_list_payouts('', $userId, 1, 5)['rows'];
    $user['custom_domains'] = ve_list_custom_domains($userId);

    $dmcaStmt = ve_db()->prepare(
        'SELECT *
         FROM dmca_notices
         WHERE user_id = :user_id
         ORDER BY received_at DESC, id DESC
         LIMIT 5'
    );
    $dmcaStmt->execute([':user_id' => $userId]);
    $user['recent_dmca'] = $dmcaStmt->fetchAll() ?: [];

    return $user;
}

function ve_admin_list_videos(string $query = '', string $status = '', int $userId = 0, int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['videos.deleted_at IS NULL'];
    $params = [];

    if ($query !== '') {
        $where[] = '(videos.title LIKE :query ESCAPE "\\" OR videos.public_id = :query_exact OR users.username LIKE :query ESCAPE "\\")';
        $params[':query'] = ve_admin_sql_like($query);
        $params[':query_exact'] = $query;
    }

    if ($status !== '') {
        $where[] = 'videos.status = :status';
        $params[':status'] = $status;
    }

    if ($userId > 0) {
        $where[] = 'videos.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM videos INNER JOIN users ON users.id = videos.user_id WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $stmt = ve_db()->prepare(
        'SELECT videos.*, users.username
         FROM videos
         INNER JOIN users ON users.id = videos.user_id
         WHERE ' . $whereSql . '
         ORDER BY videos.created_at DESC, videos.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_video_detail(int $videoId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT videos.*, users.username
         FROM videos
         INNER JOIN users ON users.id = videos.user_id
         WHERE videos.id = :id
           AND videos.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $videoId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_list_remote_uploads(string $query = '', string $status = '', int $userId = 0, int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['remote_uploads.deleted_at IS NULL'];
    $params = [];

    if ($query !== '') {
        $where[] = '(remote_uploads.source_url LIKE :query ESCAPE "\\" OR remote_uploads.video_public_id = :query_exact OR users.username LIKE :query ESCAPE "\\")';
        $params[':query'] = ve_admin_sql_like($query);
        $params[':query_exact'] = $query;
    }

    if ($status !== '') {
        $where[] = 'remote_uploads.status = :status';
        $params[':status'] = $status;
    }

    if ($userId > 0) {
        $where[] = 'remote_uploads.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM remote_uploads INNER JOIN users ON users.id = remote_uploads.user_id WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $stmt = ve_db()->prepare(
        'SELECT remote_uploads.*, users.username
         FROM remote_uploads
         INNER JOIN users ON users.id = remote_uploads.user_id
         WHERE ' . $whereSql . '
         ORDER BY remote_uploads.created_at DESC, remote_uploads.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_remote_upload_detail(int $jobId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT remote_uploads.*, users.username
         FROM remote_uploads
         INNER JOIN users ON users.id = remote_uploads.user_id
         WHERE remote_uploads.id = :id
           AND remote_uploads.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_list_dmca_notices(string $status = '', string $query = '', int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['1 = 1'];
    $params = [];

    if ($status === 'open') {
        $where[] = 'dmca_notices.status IN ("pending_review", "content_disabled", "counter_submitted", "response_submitted")';
    } elseif ($status === 'resolved') {
        $where[] = 'dmca_notices.status IN ("restored", "rejected", "withdrawn", "uploader_deleted", "auto_deleted")';
    } elseif ($status !== '') {
        $where[] = 'dmca_notices.status = :status';
        $params[':status'] = $status;
    }

    if ($query !== '') {
        $where[] = '(dmca_notices.case_code LIKE :query ESCAPE "\\" OR dmca_notices.reported_url LIKE :query ESCAPE "\\" OR COALESCE(dmca_notices.video_title_snapshot, "") LIKE :query ESCAPE "\\" OR users.username LIKE :query ESCAPE "\\")';
        $params[':query'] = ve_admin_sql_like($query);
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM dmca_notices INNER JOIN users ON users.id = dmca_notices.user_id WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $stmt = ve_db()->prepare(
        'SELECT dmca_notices.*, users.username, videos.public_id AS video_public_id, videos.title AS video_title
         FROM dmca_notices
         INNER JOIN users ON users.id = dmca_notices.user_id
         LEFT JOIN videos ON videos.id = dmca_notices.video_id
         WHERE ' . $whereSql . '
         ORDER BY dmca_notices.received_at DESC, dmca_notices.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_dmca_detail(int $noticeId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM dmca_notices
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $noticeId]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return null;
    }

    $row['payload'] = ve_dmca_notice_payload($row);

    $eventsStmt = ve_db()->prepare(
        'SELECT *
         FROM dmca_notice_events
         WHERE notice_id = :notice_id
         ORDER BY created_at DESC, id DESC'
    );
    $eventsStmt->execute([':notice_id' => $noticeId]);
    $row['events'] = $eventsStmt->fetchAll() ?: [];

    return $row;
}

function ve_admin_list_payouts(string $status = '', int $userId = 0, int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['1 = 1'];
    $params = [];

    if ($status !== '') {
        $where[] = 'payout_requests.status = :status';
        $params[':status'] = $status;
    }

    if ($userId > 0) {
        $where[] = 'payout_requests.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM payout_requests INNER JOIN users ON users.id = payout_requests.user_id WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $stmt = ve_db()->prepare(
        'SELECT payout_requests.*, users.username, users.email
         FROM payout_requests
         INNER JOIN users ON users.id = payout_requests.user_id
         WHERE ' . $whereSql . '
         ORDER BY payout_requests.created_at DESC, payout_requests.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_list_custom_domains(string $status = '', string $query = '', int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $where = ['1 = 1'];
    $params = [];

    if ($status !== '') {
        $where[] = 'custom_domains.status = :status';
        $params[':status'] = $status;
    }

    if ($query !== '') {
        $where[] = '(custom_domains.domain LIKE :query ESCAPE "\\" OR users.username LIKE :query ESCAPE "\\")';
        $params[':query'] = ve_admin_sql_like($query);
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = ve_db()->prepare('SELECT COUNT(*) FROM custom_domains INNER JOIN users ON users.id = custom_domains.user_id WHERE ' . $whereSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }

    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $offset = max(0, ($page - 1) * $pageSize);

    $stmt = ve_db()->prepare(
        'SELECT custom_domains.*, users.username
         FROM custom_domains
         INNER JOIN users ON users.id = custom_domains.user_id
         WHERE ' . $whereSql . '
         ORDER BY custom_domains.created_at DESC, custom_domains.id DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_infrastructure_snapshot(): array
{
    return [
        'storage_nodes' => ve_db()->query('SELECT * FROM storage_nodes ORDER BY hostname ASC')->fetchAll() ?: [],
        'storage_volumes' => ve_db()->query('SELECT storage_volumes.*, storage_nodes.hostname FROM storage_volumes INNER JOIN storage_nodes ON storage_nodes.id = storage_volumes.storage_node_id ORDER BY storage_nodes.hostname ASC, storage_volumes.code ASC')->fetchAll() ?: [],
        'upload_endpoints' => ve_db()->query('SELECT upload_endpoints.*, storage_nodes.hostname FROM upload_endpoints INNER JOIN storage_nodes ON storage_nodes.id = upload_endpoints.storage_node_id ORDER BY upload_endpoints.is_active DESC, upload_endpoints.weight DESC, upload_endpoints.code ASC')->fetchAll() ?: [],
        'delivery_domains' => ve_db()->query('SELECT * FROM delivery_domains ORDER BY domain ASC')->fetchAll() ?: [],
        'maintenance_windows' => ve_db()->query('SELECT storage_maintenance_windows.*, storage_nodes.hostname FROM storage_maintenance_windows INNER JOIN storage_nodes ON storage_nodes.id = storage_maintenance_windows.storage_node_id ORDER BY storage_maintenance_windows.starts_at DESC')->fetchAll() ?: [],
    ];
}

function ve_admin_list_audit_logs(int $page = 1, ?int $pageSize = null): array
{
    $pageSize = $pageSize ?? ve_admin_page_size();
    $offset = max(0, ($page - 1) * $pageSize);

    $total = (int) ve_db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $stmt = ve_db()->prepare(
        'SELECT audit_logs.*, users.username AS actor_username
         FROM audit_logs
         LEFT JOIN users ON users.id = audit_logs.actor_user_id
         ORDER BY audit_logs.created_at DESC, audit_logs.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function ve_admin_save_user_profile(int $userId, array $payload, int $actorUserId): void
{
    $before = ve_admin_user_detail($userId);

    if (!is_array($before)) {
        throw new RuntimeException('User not found.');
    }

    $status = trim((string) ($payload['status'] ?? 'active'));
    $planCode = trim((string) ($payload['plan_code'] ?? 'free'));
    $premiumUntil = trim((string) ($payload['premium_until'] ?? ''));
    $paymentMethod = trim((string) ($payload['payment_method'] ?? 'Webmoney'));
    $paymentId = trim((string) ($payload['payment_id'] ?? ''));
    $apiEnabled = isset($payload['api_enabled']) ? 1 : 0;
    $roleCode = trim((string) ($payload['role_code'] ?? ''));

    if (!in_array($status, ['active', 'suspended'], true)) {
        throw new RuntimeException('Choose a valid user status.');
    }

    if (!in_array($paymentMethod, ve_allowed_payment_methods(), true)) {
        throw new RuntimeException('Choose a valid payout method.');
    }

    ve_db()->prepare(
        'UPDATE users
         SET status = :status,
             plan_code = :plan_code,
             premium_until = :premium_until,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':status' => $status,
        ':plan_code' => $planCode === '' ? 'free' : $planCode,
        ':premium_until' => $premiumUntil !== '' ? $premiumUntil : null,
        ':updated_at' => ve_now(),
        ':id' => $userId,
    ]);

    ve_db()->prepare(
        'UPDATE user_settings
         SET payment_method = :payment_method,
             payment_id = :payment_id,
             api_enabled = :api_enabled,
             updated_at = :updated_at
         WHERE user_id = :user_id'
    )->execute([
        ':payment_method' => $paymentMethod,
        ':payment_id' => $paymentId,
        ':api_enabled' => $apiEnabled,
        ':updated_at' => ve_now(),
        ':user_id' => $userId,
    ]);

    ve_admin_set_user_primary_role($userId, $roleCode, $actorUserId);
    ve_admin_log_event('admin.user.updated', 'user', $userId, $before, ve_admin_user_detail($userId) ?? [], $actorUserId);
}

function ve_admin_delete_user_forever(int $userId, int $actorUserId): void
{
    $user = ve_admin_user_detail($userId);

    if (!is_array($user)) {
        throw new RuntimeException('User not found.');
    }

    if ($userId === $actorUserId) {
        throw new RuntimeException('You cannot delete your own account.');
    }

    $videos = ve_db()->prepare('SELECT * FROM videos WHERE user_id = :user_id AND deleted_at IS NULL');
    $videos->execute([':user_id' => $userId]);
    $videoRows = $videos->fetchAll() ?: [];

    $remote = ve_db()->prepare('SELECT id FROM remote_uploads WHERE user_id = :user_id AND deleted_at IS NULL');
    $remote->execute([':user_id' => $userId]);

    foreach ($remote->fetchAll() ?: [] as $row) {
        if (is_array($row)) {
            ve_remote_cleanup_job_files((int) ($row['id'] ?? 0));
        }
    }

    if ($videoRows !== []) {
        ve_video_delete_video_rows($videoRows);
    }

    $settings = ve_get_user_settings($userId);

    foreach (['logo_path', 'splash_image_path'] as $pathKey) {
        $path = trim((string) ($settings[$pathKey] ?? ''));
        $absolute = $path !== '' ? ve_storage_relative_path_to_absolute($path) : '';

        if ($absolute !== '' && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    ve_db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
    ve_admin_log_event('admin.user.deleted', 'user', $userId, $user, [], $actorUserId);
}

function ve_admin_delete_video(int $videoId, int $actorUserId): void
{
    $video = ve_admin_video_detail($videoId);

    if (!is_array($video)) {
        throw new RuntimeException('Video not found.');
    }

    ve_video_delete_video_rows([$video]);
    ve_admin_log_event('admin.video.deleted', 'video', $videoId, $video, [], $actorUserId);
}

function ve_admin_set_video_visibility(int $videoId, bool $isPublic, int $actorUserId): void
{
    $before = ve_admin_video_detail($videoId);

    if (!is_array($before)) {
        throw new RuntimeException('Video not found.');
    }

    ve_db()->prepare(
        'UPDATE videos
         SET is_public = :is_public,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':is_public' => $isPublic ? 1 : 0,
        ':updated_at' => ve_now(),
        ':id' => $videoId,
    ]);

    ve_admin_log_event('admin.video.visibility', 'video', $videoId, $before, ve_admin_video_detail($videoId) ?? [], $actorUserId);
}

function ve_admin_delete_remote_upload(int $jobId, int $actorUserId): void
{
    $before = ve_admin_remote_upload_detail($jobId);

    if (!is_array($before)) {
        throw new RuntimeException('Remote upload not found.');
    }

    ve_db()->prepare(
        'UPDATE remote_uploads
         SET deleted_at = :deleted_at,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':deleted_at' => ve_now(),
        ':updated_at' => ve_now(),
        ':id' => $jobId,
    ]);

    ve_remote_cleanup_job_files($jobId);
    ve_admin_log_event('admin.remote_upload.deleted', 'remote_upload', $jobId, $before, [], $actorUserId);
}

function ve_admin_retry_remote_upload(int $jobId, int $actorUserId): void
{
    $before = ve_admin_remote_upload_detail($jobId);

    if (!is_array($before)) {
        throw new RuntimeException('Remote upload not found.');
    }

    ve_db()->prepare(
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
         WHERE id = :id'
    )->execute([
        ':status' => VE_REMOTE_STATUS_PENDING,
        ':status_message' => 'Queued for remote download.',
        ':updated_at' => ve_now(),
        ':id' => $jobId,
    ]);

    ve_remote_cleanup_job_files($jobId);
    ve_remote_host_reset_job_log($jobId);
    ve_remote_maybe_spawn_worker();
    ve_admin_log_event('admin.remote_upload.retried', 'remote_upload', $jobId, $before, ve_admin_remote_upload_detail($jobId) ?? [], $actorUserId);
}

function ve_admin_update_dmca_status(int $noticeId, string $status, string $note, int $actorUserId): void
{
    $before = ve_admin_dmca_detail($noticeId);

    if (!is_array($before)) {
        throw new RuntimeException('DMCA case not found.');
    }

    if (!array_key_exists($status, ve_dmca_notice_status_catalog())) {
        throw new RuntimeException('Choose a valid DMCA status.');
    }

    $title = match ($status) {
        VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED => 'Content disabled by admin',
        VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED,
        VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED => 'Uploader response marked by admin',
        VE_DMCA_NOTICE_STATUS_UPLOADER_DELETED => 'Video deleted by admin',
        VE_DMCA_NOTICE_STATUS_AUTO_DELETED => 'Auto deletion confirmed by admin',
        VE_DMCA_NOTICE_STATUS_RESTORED => 'Content restored by admin',
        VE_DMCA_NOTICE_STATUS_REJECTED => 'Notice rejected by admin',
        VE_DMCA_NOTICE_STATUS_WITHDRAWN => 'Notice withdrawn by admin',
        default => 'DMCA status updated',
    };

    ve_dmca_update_notice_status($noticeId, $status, 'admin_status_change', $title, $note);
    ve_admin_log_event('admin.dmca.updated', 'dmca_notice', $noticeId, $before, ve_admin_dmca_detail($noticeId) ?? [], $actorUserId);
}

function ve_admin_release_payout_hold_if_needed(array $request): void
{
    $sourceKey = (string) ($request['public_id'] ?? '');
    $amountMicroUsd = (int) ($request['amount_micro_usd'] ?? 0);
    $userId = (int) ($request['user_id'] ?? 0);

    if ($sourceKey === '' || $amountMicroUsd <= 0 || $userId <= 0) {
        return;
    }

    if (ve_admin_payout_ledger_entry_exists($sourceKey, 'release')) {
        return;
    }

    ve_db()->prepare(
        'INSERT INTO account_balance_ledger (
            user_id, entry_type, source_type, source_key, amount_micro_usd, description, metadata_json, created_at
         ) VALUES (
            :user_id, "release", "payout_request", :source_key, :amount_micro_usd, :description, :metadata_json, :created_at
         )'
    )->execute([
        ':user_id' => $userId,
        ':source_key' => $sourceKey,
        ':amount_micro_usd' => $amountMicroUsd,
        ':description' => 'Payout request released',
        ':metadata_json' => json_encode(['status' => 'rejected'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => ve_now(),
    ]);
}

function ve_admin_update_payout_request(int $requestId, string $action, array $payload, int $actorUserId): void
{
    $before = ve_admin_payout_request_by_id($requestId);

    if (!is_array($before)) {
        throw new RuntimeException('Payout request not found.');
    }

    $now = ve_now();

    if ($action === 'approve_payout') {
        ve_db()->prepare(
            'UPDATE payout_requests
             SET status = "approved",
                 notes = :notes,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = :reviewed_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = "pending"'
        )->execute([
            ':notes' => trim((string) ($payload['notes'] ?? '')),
            ':reviewed_by_user_id' => $actorUserId,
            ':reviewed_at' => $now,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]);

        ve_add_notification((int) ($before['user_id'] ?? 0), 'Payout approved', 'Your payout request ' . (string) ($before['public_id'] ?? '') . ' was approved.');
    } elseif ($action === 'reject_payout') {
        $reason = trim((string) ($payload['rejection_reason'] ?? ''));

        if ($reason === '') {
            throw new RuntimeException('Provide a rejection reason.');
        }

        ve_db()->prepare(
            'UPDATE payout_requests
             SET status = "rejected",
                 notes = :notes,
                 rejection_reason = :rejection_reason,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = :reviewed_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status IN ("pending", "approved")'
        )->execute([
            ':notes' => trim((string) ($payload['notes'] ?? '')),
            ':rejection_reason' => $reason,
            ':reviewed_by_user_id' => $actorUserId,
            ':reviewed_at' => $now,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]);

        ve_admin_release_payout_hold_if_needed($before);
        ve_add_notification((int) ($before['user_id'] ?? 0), 'Payout rejected', 'Your payout request ' . (string) ($before['public_id'] ?? '') . ' was rejected: ' . $reason);
    } elseif ($action === 'mark_payout_paid') {
        $feeMicroUsd = ve_admin_parse_amount_to_micro_usd((string) ($payload['fee_amount'] ?? '0'));
        $transferReference = trim((string) ($payload['transfer_reference'] ?? ''));
        $gross = (int) ($before['amount_micro_usd'] ?? 0);
        $net = max(0, $gross - $feeMicroUsd);

        ve_db()->prepare(
            'UPDATE payout_requests
             SET status = "paid",
                 notes = :notes,
                 transfer_reference = :transfer_reference,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = :reviewed_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status IN ("pending", "approved")'
        )->execute([
            ':notes' => trim((string) ($payload['notes'] ?? '')),
            ':transfer_reference' => $transferReference,
            ':reviewed_by_user_id' => $actorUserId,
            ':reviewed_at' => $now,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]);

        ve_db()->prepare(
            'INSERT INTO payout_transfers (
                payout_request_id, provider_transfer_ref, gross_amount_micro_usd, fee_micro_usd, net_amount_micro_usd,
                status, sent_at, settled_at, created_at, updated_at
             ) VALUES (
                :payout_request_id, :provider_transfer_ref, :gross_amount_micro_usd, :fee_micro_usd, :net_amount_micro_usd,
                "sent", :sent_at, NULL, :created_at, :updated_at
             )
             ON CONFLICT(payout_request_id) DO UPDATE SET
                provider_transfer_ref = excluded.provider_transfer_ref,
                gross_amount_micro_usd = excluded.gross_amount_micro_usd,
                fee_micro_usd = excluded.fee_micro_usd,
                net_amount_micro_usd = excluded.net_amount_micro_usd,
                status = excluded.status,
                sent_at = excluded.sent_at,
                updated_at = excluded.updated_at'
        )->execute([
            ':payout_request_id' => $requestId,
            ':provider_transfer_ref' => $transferReference,
            ':gross_amount_micro_usd' => $gross,
            ':fee_micro_usd' => $feeMicroUsd,
            ':net_amount_micro_usd' => $net,
            ':sent_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        ve_add_notification((int) ($before['user_id'] ?? 0), 'Payout sent', 'Your payout request ' . (string) ($before['public_id'] ?? '') . ' was marked as paid.');
    } else {
        throw new RuntimeException('Unknown payout action.');
    }

    ve_admin_log_event('admin.payout.updated', 'payout_request', $requestId, $before, ve_admin_payout_request_by_id($requestId) ?? [], $actorUserId);
}

function ve_admin_refresh_custom_domain(int $domainId, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM custom_domains WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $domainId]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Custom domain not found.');
    }

    $status = ve_check_domain_status((string) ($before['domain'] ?? ''));

    ve_db()->prepare(
        'UPDATE custom_domains
         SET status = :status,
             dns_target = :dns_target,
             dns_last_checked_at = :dns_last_checked_at,
             dns_check_error = :dns_check_error,
             updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':status' => (string) ($status['status'] ?? 'pending_dns'),
        ':dns_target' => (string) ($status['dns_target'] ?? ''),
        ':dns_last_checked_at' => (string) ($status['dns_last_checked_at'] ?? ve_now()),
        ':dns_check_error' => (string) ($status['dns_check_error'] ?? ''),
        ':updated_at' => ve_now(),
        ':id' => $domainId,
    ]);

    $afterStmt = ve_db()->prepare('SELECT * FROM custom_domains WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $domainId]);
    $after = $afterStmt->fetch();

    ve_admin_log_event('admin.domain.refreshed', 'custom_domain', $domainId, $before, is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_custom_domain(int $domainId, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM custom_domains WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $domainId]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Custom domain not found.');
    }

    ve_db()->prepare('DELETE FROM custom_domains WHERE id = :id')->execute([':id' => $domainId]);
    ve_admin_log_event('admin.domain.deleted', 'custom_domain', $domainId, $before, [], $actorUserId);
}

function ve_admin_save_app_settings(array $payload, int $actorUserId): void
{
    $before = [];
    $after = [];

    foreach (ve_admin_default_settings() as $key => $defaultValue) {
        $before[$key] = ve_get_app_setting($key, (string) $defaultValue);
        $value = trim((string) ($payload[$key] ?? $defaultValue));
        $after[$key] = $value;
        ve_set_app_setting($key, $value);
    }

    ve_admin_log_event('admin.settings.updated', 'app_settings', null, $before, $after, $actorUserId);
}

function ve_admin_upsert_storage_node(array $payload, int $actorUserId): void
{
    $id = (int) ($payload['id'] ?? 0);
    $before = null;

    if ($id > 0) {
        $stmt = ve_db()->prepare('SELECT * FROM storage_nodes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
    }

    $fields = [
        ':code' => trim((string) ($payload['code'] ?? '')),
        ':hostname' => trim((string) ($payload['hostname'] ?? '')),
        ':public_base_url' => trim((string) ($payload['public_base_url'] ?? '')),
        ':upload_base_url' => trim((string) ($payload['upload_base_url'] ?? '')),
        ':health_status' => trim((string) ($payload['health_status'] ?? 'healthy')),
        ':available_bytes' => max(0, (int) ($payload['available_bytes'] ?? 0)),
        ':used_bytes' => max(0, (int) ($payload['used_bytes'] ?? 0)),
        ':max_ingest_qps' => max(0, (int) ($payload['max_ingest_qps'] ?? 0)),
        ':max_stream_qps' => max(0, (int) ($payload['max_stream_qps'] ?? 0)),
        ':notes' => trim((string) ($payload['notes'] ?? '')),
        ':updated_at' => ve_now(),
    ];

    if ($fields[':code'] === '' || $fields[':hostname'] === '') {
        throw new RuntimeException('Storage node code and hostname are required.');
    }

    if ($id > 0) {
        ve_db()->prepare(
            'UPDATE storage_nodes
             SET code = :code,
                 hostname = :hostname,
                 public_base_url = :public_base_url,
                 upload_base_url = :upload_base_url,
                 health_status = :health_status,
                 available_bytes = :available_bytes,
                 used_bytes = :used_bytes,
                 max_ingest_qps = :max_ingest_qps,
                 max_stream_qps = :max_stream_qps,
                 notes = :notes,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute($fields + [':id' => $id]);
    } else {
        ve_db()->prepare(
            'INSERT INTO storage_nodes (
                code, hostname, public_base_url, upload_base_url, health_status, available_bytes, used_bytes,
                max_ingest_qps, max_stream_qps, notes, created_at, updated_at
             ) VALUES (
                :code, :hostname, :public_base_url, :upload_base_url, :health_status, :available_bytes, :used_bytes,
                :max_ingest_qps, :max_stream_qps, :notes, :created_at, :updated_at
             )'
        )->execute($fields + [':created_at' => ve_now()]);
        $id = (int) ve_db()->lastInsertId();
    }

    $afterStmt = ve_db()->prepare('SELECT * FROM storage_nodes WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    $after = $afterStmt->fetch();
    ve_admin_log_event('admin.infrastructure.storage_node', 'storage_node', $id, is_array($before) ? $before : [], is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_storage_node(int $id, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM storage_nodes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Storage node not found.');
    }

    ve_db()->prepare('DELETE FROM storage_nodes WHERE id = :id')->execute([':id' => $id]);
    ve_admin_log_event('admin.infrastructure.storage_node_deleted', 'storage_node', $id, $before, [], $actorUserId);
}

function ve_admin_upsert_storage_volume(array $payload, int $actorUserId): void
{
    $id = (int) ($payload['id'] ?? 0);
    $before = null;

    if ($id > 0) {
        $stmt = ve_db()->prepare('SELECT * FROM storage_volumes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
    }

    $fields = [
        ':storage_node_id' => (int) ($payload['storage_node_id'] ?? 0),
        ':code' => trim((string) ($payload['code'] ?? '')),
        ':mount_path' => trim((string) ($payload['mount_path'] ?? '')),
        ':capacity_bytes' => max(0, (int) ($payload['capacity_bytes'] ?? 0)),
        ':used_bytes' => max(0, (int) ($payload['used_bytes'] ?? 0)),
        ':reserved_bytes' => max(0, (int) ($payload['reserved_bytes'] ?? 0)),
        ':health_status' => trim((string) ($payload['health_status'] ?? 'healthy')),
        ':updated_at' => ve_now(),
    ];

    if ($fields[':storage_node_id'] <= 0 || $fields[':code'] === '' || $fields[':mount_path'] === '') {
        throw new RuntimeException('Storage volume node, code, and mount path are required.');
    }

    if ($id > 0) {
        ve_db()->prepare(
            'UPDATE storage_volumes
             SET storage_node_id = :storage_node_id,
                 code = :code,
                 mount_path = :mount_path,
                 capacity_bytes = :capacity_bytes,
                 used_bytes = :used_bytes,
                 reserved_bytes = :reserved_bytes,
                 health_status = :health_status,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute($fields + [':id' => $id]);
    } else {
        ve_db()->prepare(
            'INSERT INTO storage_volumes (
                storage_node_id, code, mount_path, capacity_bytes, used_bytes, reserved_bytes, health_status, created_at, updated_at
             ) VALUES (
                :storage_node_id, :code, :mount_path, :capacity_bytes, :used_bytes, :reserved_bytes, :health_status, :created_at, :updated_at
             )'
        )->execute($fields + [':created_at' => ve_now()]);
        $id = (int) ve_db()->lastInsertId();
    }

    $afterStmt = ve_db()->prepare('SELECT * FROM storage_volumes WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    $after = $afterStmt->fetch();
    ve_admin_log_event('admin.infrastructure.storage_volume', 'storage_volume', $id, is_array($before) ? $before : [], is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_storage_volume(int $id, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM storage_volumes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Storage volume not found.');
    }

    ve_db()->prepare('DELETE FROM storage_volumes WHERE id = :id')->execute([':id' => $id]);
    ve_admin_log_event('admin.infrastructure.storage_volume_deleted', 'storage_volume', $id, $before, [], $actorUserId);
}

function ve_admin_upsert_upload_endpoint(array $payload, int $actorUserId): void
{
    $id = (int) ($payload['id'] ?? 0);
    $before = null;

    if ($id > 0) {
        $stmt = ve_db()->prepare('SELECT * FROM upload_endpoints WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
    }

    $fields = [
        ':storage_node_id' => (int) ($payload['storage_node_id'] ?? 0),
        ':code' => trim((string) ($payload['code'] ?? '')),
        ':protocol' => trim((string) ($payload['protocol'] ?? 'https')),
        ':host' => trim((string) ($payload['host'] ?? '')),
        ':path_prefix' => trim((string) ($payload['path_prefix'] ?? '')),
        ':weight' => max(0, (int) ($payload['weight'] ?? 100)),
        ':is_active' => isset($payload['is_active']) ? 1 : 0,
        ':max_file_size_bytes' => max(0, (int) ($payload['max_file_size_bytes'] ?? 0)),
        ':accepts_remote_upload' => isset($payload['accepts_remote_upload']) ? 1 : 0,
        ':updated_at' => ve_now(),
    ];

    if ($fields[':storage_node_id'] <= 0 || $fields[':code'] === '' || $fields[':host'] === '') {
        throw new RuntimeException('Upload endpoint node, code, and host are required.');
    }

    if ($id > 0) {
        ve_db()->prepare(
            'UPDATE upload_endpoints
             SET storage_node_id = :storage_node_id,
                 code = :code,
                 protocol = :protocol,
                 host = :host,
                 path_prefix = :path_prefix,
                 weight = :weight,
                 is_active = :is_active,
                 max_file_size_bytes = :max_file_size_bytes,
                 accepts_remote_upload = :accepts_remote_upload,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute($fields + [':id' => $id]);
    } else {
        ve_db()->prepare(
            'INSERT INTO upload_endpoints (
                storage_node_id, code, protocol, host, path_prefix, weight, is_active, max_file_size_bytes,
                accepts_remote_upload, created_at, updated_at
             ) VALUES (
                :storage_node_id, :code, :protocol, :host, :path_prefix, :weight, :is_active, :max_file_size_bytes,
                :accepts_remote_upload, :created_at, :updated_at
             )'
        )->execute($fields + [':created_at' => ve_now()]);
        $id = (int) ve_db()->lastInsertId();
    }

    $afterStmt = ve_db()->prepare('SELECT * FROM upload_endpoints WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    $after = $afterStmt->fetch();
    ve_admin_log_event('admin.infrastructure.upload_endpoint', 'upload_endpoint', $id, is_array($before) ? $before : [], is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_upload_endpoint(int $id, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM upload_endpoints WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Upload endpoint not found.');
    }

    ve_db()->prepare('DELETE FROM upload_endpoints WHERE id = :id')->execute([':id' => $id]);
    ve_admin_log_event('admin.infrastructure.upload_endpoint_deleted', 'upload_endpoint', $id, $before, [], $actorUserId);
}

function ve_admin_upsert_delivery_domain(array $payload, int $actorUserId): void
{
    $id = (int) ($payload['id'] ?? 0);
    $before = null;

    if ($id > 0) {
        $stmt = ve_db()->prepare('SELECT * FROM delivery_domains WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
    }

    $domain = strtolower(trim((string) ($payload['domain'] ?? '')));
    $purpose = trim((string) ($payload['purpose'] ?? 'watch'));
    $status = trim((string) ($payload['status'] ?? 'active'));
    $tlsMode = trim((string) ($payload['tls_mode'] ?? 'managed'));

    if ($domain === '' || !ve_is_valid_domain($domain)) {
        throw new RuntimeException('Enter a valid delivery domain.');
    }

    if ($id > 0) {
        ve_db()->prepare(
            'UPDATE delivery_domains
             SET domain = :domain,
                 purpose = :purpose,
                 status = :status,
                 tls_mode = :tls_mode,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':domain' => $domain,
            ':purpose' => $purpose,
            ':status' => $status,
            ':tls_mode' => $tlsMode,
            ':updated_at' => ve_now(),
            ':id' => $id,
        ]);
    } else {
        ve_db()->prepare(
            'INSERT INTO delivery_domains (domain, purpose, status, tls_mode, created_at, updated_at)
             VALUES (:domain, :purpose, :status, :tls_mode, :created_at, :updated_at)'
        )->execute([
            ':domain' => $domain,
            ':purpose' => $purpose,
            ':status' => $status,
            ':tls_mode' => $tlsMode,
            ':created_at' => ve_now(),
            ':updated_at' => ve_now(),
        ]);
        $id = (int) ve_db()->lastInsertId();
    }

    $afterStmt = ve_db()->prepare('SELECT * FROM delivery_domains WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    $after = $afterStmt->fetch();
    ve_admin_log_event('admin.infrastructure.delivery_domain', 'delivery_domain', $id, is_array($before) ? $before : [], is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_delivery_domain(int $id, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM delivery_domains WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Delivery domain not found.');
    }

    ve_db()->prepare('DELETE FROM delivery_domains WHERE id = :id')->execute([':id' => $id]);
    ve_admin_log_event('admin.infrastructure.delivery_domain_deleted', 'delivery_domain', $id, $before, [], $actorUserId);
}

function ve_admin_add_maintenance_window(array $payload, int $actorUserId): void
{
    $storageNodeId = (int) ($payload['storage_node_id'] ?? 0);
    $startsAt = trim((string) ($payload['starts_at'] ?? ''));
    $endsAt = trim((string) ($payload['ends_at'] ?? ''));
    $mode = trim((string) ($payload['mode'] ?? 'drain'));
    $reason = trim((string) ($payload['reason'] ?? ''));

    if ($storageNodeId <= 0 || $startsAt === '' || $endsAt === '') {
        throw new RuntimeException('Maintenance window node and time range are required.');
    }

    ve_db()->prepare(
        'INSERT INTO storage_maintenance_windows (
            storage_node_id, starts_at, ends_at, mode, reason, created_by_user_id, created_at
         ) VALUES (
            :storage_node_id, :starts_at, :ends_at, :mode, :reason, :created_by_user_id, :created_at
         )'
    )->execute([
        ':storage_node_id' => $storageNodeId,
        ':starts_at' => $startsAt,
        ':ends_at' => $endsAt,
        ':mode' => $mode,
        ':reason' => $reason,
        ':created_by_user_id' => $actorUserId,
        ':created_at' => ve_now(),
    ]);

    $id = (int) ve_db()->lastInsertId();
    $stmt = ve_db()->prepare('SELECT * FROM storage_maintenance_windows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $after = $stmt->fetch();
    ve_admin_log_event('admin.infrastructure.maintenance_window', 'maintenance_window', $id, [], is_array($after) ? $after : [], $actorUserId);
}

function ve_admin_delete_maintenance_window(int $id, int $actorUserId): void
{
    $stmt = ve_db()->prepare('SELECT * FROM storage_maintenance_windows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $before = $stmt->fetch();

    if (!is_array($before)) {
        throw new RuntimeException('Maintenance window not found.');
    }

    ve_db()->prepare('DELETE FROM storage_maintenance_windows WHERE id = :id')->execute([':id' => $id]);
    ve_admin_log_event('admin.infrastructure.maintenance_window_deleted', 'maintenance_window', $id, $before, [], $actorUserId);
}

function ve_admin_badge_html(string $label, string $tone = 'secondary'): string
{
    $classMap = [
        'success' => 'badge-success',
        'danger' => 'badge-danger',
        'warning' => 'badge-warning',
        'info' => 'badge-info',
        'primary' => 'badge-primary',
        'secondary' => 'badge-secondary',
        'dark' => 'badge-dark',
    ];
    $class = $classMap[$tone] ?? 'badge-secondary';

    return '<span class="badge ' . $class . '">' . ve_h($label) . '</span>';
}

function ve_admin_status_badge_html(string $status): string
{
    $tone = match ($status) {
        'active', 'ready', 'complete', 'paid', 'healthy', VE_DMCA_NOTICE_STATUS_RESTORED => 'success',
        'suspended', 'error', 'rejected', 'withdrawn', 'lookup_failed', 'offline', 'disabled' => 'danger',
        'pending', 'approved', 'pending_dns', VE_DMCA_NOTICE_STATUS_PENDING_REVIEW, VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'warning',
        'downloading', 'importing', 'resolving', 'degraded', 'draining', VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED, VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED => 'info',
        default => 'secondary',
    };

    return ve_admin_badge_html(str_replace('_', ' ', $status), $tone);
}

function ve_admin_flash_html(): string
{
    $flash = ve_pull_flash();

    if (!is_array($flash)) {
        return '';
    }

    $type = trim((string) ($flash['type'] ?? 'info'));
    $message = trim((string) ($flash['message'] ?? ''));
    $class = match ($type) {
        'success' => 'alert-success',
        'danger', 'error' => 'alert-danger',
        'warning' => 'alert-warning',
        default => 'alert-info',
    };

    return '<div class="alert ' . $class . '">' . ve_h($message) . '</div>';
}

function ve_admin_backend_link_html(): string
{
    $actor = ve_admin_actor_user();

    if (!ve_admin_has_backend_access($actor)) {
        return '';
    }

    if (ve_request_path() === '/backend' || str_starts_with(ve_request_path(), '/backend/')) {
        return '<a href="' . ve_h(ve_url('/dashboard')) . '" class="dropdown-item"><i class="fad fa-shapes"></i>Dashboard</a>';
    }

    return '<a href="' . ve_h(ve_url('/backend')) . '" class="dropdown-item"><i class="fad fa-shield-alt"></i>Backend</a>';
}

function ve_admin_impersonation_banner_html(): string
{
    if (!ve_admin_is_impersonating()) {
        return '';
    }

    $actor = ve_admin_actor_user();
    $currentUser = ve_current_user();

    if (!is_array($actor) || !is_array($currentUser)) {
        return '';
    }

    return <<<HTML
<div class="admin-impersonation-note">
    Viewing the product as <strong>{$currentUser['username']}</strong>. Backend actions still execute as <strong>{$actor['username']}</strong>.
</div>
HTML;
}

function ve_admin_impersonation_stop_control_html(string $className = 'btn btn-sm btn-secondary admin-stop-button', bool $wrapNavItem = false): string
{
    if (!ve_admin_is_impersonating()) {
        return '';
    }

    $currentUser = ve_current_user();

    if (!is_array($currentUser)) {
        return '';
    }

    $targetUserId = (int) ($currentUser['id'] ?? 0);

    if ($targetUserId <= 0) {
        return '';
    }

    $actionUrl = ve_h(ve_admin_url([
        'section' => 'users',
        'resource' => (string) $targetUserId,
        'page' => null,
    ], false));
    $token = ve_h(ve_csrf_token());
    $control = '<form method="POST" action="' . $actionUrl . '" class="admin-stop-form">'
        . '<input type="hidden" name="token" value="' . $token . '">'
        . '<input type="hidden" name="action" value="stop_impersonation">'
        . '<input type="hidden" name="return_to" value="' . $actionUrl . '">'
        . '<button type="submit" class="' . ve_h($className) . '"><i class="fad fa-user-secret"></i><span>Stop impersonation</span></button>'
        . '</form>';

    return $wrapNavItem ? '<li class="nav-item admin-header-stop">' . $control . '</li>' : $control;
}

function ve_admin_backend_header_nav_html(array $actorUser, string $activeSection): string
{
    $items = [];

    foreach (ve_admin_allowed_sections_for_user($actorUser) as $code => $section) {
        $activeClass = $code === $activeSection ? ' active' : '';
        $icon = ve_h((string) ($section['icon'] ?? 'fa-circle'));
        $label = ve_h((string) ($section['label'] ?? ucfirst($code)));
        $url = ve_h(ve_admin_url(['section' => $code, 'resource' => null, 'page' => null], false));
        $items[] = '<li class="nav-item"><a href="' . $url . '" data-admin-nav="1" class="nav-link' . $activeClass . '"><i class="fad ' . $icon . '"></i><span>' . $label . '</span></a></li>';
    }

    return implode('', $items);
}

function ve_admin_user_dropdown_html(array $user): string
{
    $backendLink = ve_admin_backend_link_html();

    return <<<HTML
<div aria-labelledby="navbarDropdown" class="dropdown-menu dropdown-menu-right">
    <a href="{$user['dmca_url']}" class="dropdown-item"><i class="fad fa-folder-times"></i>DMCA Manager</a>
    <a href="{$user['api_docs_url']}" class="dropdown-item"><i class="fad fa-brackets-curly"></i>API</a>
    <a href="{$user['referrals_url']}" class="dropdown-item"><i class="fad fa-solar-system"></i>Referral</a>
    <a href="{$user['settings_url']}" class="dropdown-item"><i class="fad fa-cog"></i>Settings</a>
    {$backendLink}
    <div class="dropdown-divider"></div>
    <a href="{$user['logout_url']}" class="dropdown-item logout"><i class="fad fa-power-off text-danger"></i>Logout</a>
</div>
HTML;
}

function ve_admin_dashboard_shell(
    array $currentUser,
    string $title,
    string $sidebarHtml,
    string $contentHtml,
    string $widgetsHtml = '',
    string $extraHeadHtml = ''
): string {
    $username = ve_h((string) ($currentUser['username'] ?? 'videoengine'));
    $runtimeScript = ve_runtime_script_tag();
    $impersonationBanner = ve_admin_impersonation_banner_html();
    $flashHtml = ve_admin_flash_html();
    $headerNavHtml = trim((string) ($currentUser['header_nav_html'] ?? ''));
    $headerNavStrip = $headerNavHtml !== ''
        ? '<div class="admin-header-strip d-none d-lg-block"><div class="container-fluid"><ul class="nav justify-content-center admin-header-nav">' . $headerNavHtml . '</ul></div></div>'
        : '';
    $headerActionHtml = (string) ($currentUser['header_action_html'] ?? '');
    $dropdown = ve_admin_user_dropdown_html([
        'dmca_url' => ve_h(ve_url('/dmca-manager')),
        'api_docs_url' => ve_h(ve_url('/api-docs')),
        'referrals_url' => ve_h(ve_url('/referrals')),
        'settings_url' => ve_h(ve_url('/settings')),
        'logout_url' => ve_h(ve_url('/logout')),
    ]);
    $baseStyles = <<<CSS
    <style>
        .admin-shell { padding-bottom: 18px; }
        .admin-header-strip {
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            background: #131313;
        }
        .admin-header-nav {
            gap: 4px;
            padding: 0;
            margin: 0;
            flex-wrap: nowrap;
            overflow-x: auto;
        }
        .admin-header-nav .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 54px;
            padding: 0 16px;
            color: #a9a9a9;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
        }
        .admin-header-nav .nav-link:hover,
        .admin-header-nav .nav-link:focus {
            color: #fff;
        }
        .admin-header-nav .nav-link.active {
            color: #fff;
            border-bottom-color: #ff9900;
        }
        .admin-header-stop {
            display: inline-flex;
            align-items: center;
            margin-right: 8px;
        }
        .admin-stop-form { margin: 0; }
        .admin-stop-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 14px;
            white-space: nowrap;
        }
        .admin-shell .widget_area {
            display: grid !important;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            align-items: stretch;
            margin-bottom: 24px;
        }
        .admin-shell .widget_area:before,
        .admin-shell .widget_area:after {
            content: none !important;
            display: none !important;
        }
        .admin-shell .widget_area .widget_box {
            width: auto !important;
            min-width: 0;
            margin: 0 !important;
            min-height: 112px;
            padding: 20px 18px;
            background: #151515;
            border: 1px solid rgba(255, 255, 255, 0.06);
            float: none !important;
            clear: none !important;
        }
        .admin-shell .widget_area .widget_box:nth-of-type(4n),
        .admin-shell .widget_area .widget_box:nth-of-type(4n + 1) {
            width: auto !important;
            margin-right: 0 !important;
            float: none !important;
            clear: none !important;
        }
        .admin-shell .widget_area .widget_box .info {
            min-width: 0;
            max-width: calc(100% - 64px);
        }
        .admin-shell .widget_area .widget_box .money {
            font-size: 1.65rem;
            line-height: 1;
        }
        .admin-shell .widget_area .widget_box .icon {
            flex: 0 0 auto;
        }
        .admin-shell .widget_area .widget_box a {
            display: inline-flex;
            align-items: center;
            margin-top: 10px;
        }
        .admin-shell .the_box {
            display: grid !important;
            grid-template-columns: 270px minmax(0, 1fr);
            align-items: flex-start;
            gap: 18px;
            width: 100%;
        }
        .admin-shell .sidebar.settings-page {
            width: 270px;
            margin-right: 0 !important;
            position: sticky;
            top: 132px;
        }
        .admin-shell .details.settings_data {
            min-width: 0;
            width: 100% !important;
            max-width: none !important;
            flex: 1 1 auto;
        }
        .admin-shell .admin-sidebar-eyebrow {
            display: inline-block;
            color: #ff9900;
            font-size: .76rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .admin-shell .admin-sidebar-subtitle {
            color: #8c8c8c;
            margin-top: 10px;
            margin-bottom: 0;
        }
        .admin-shell .settings-panel {
            padding: 24px 26px;
            background: #151515;
            border: 1px solid rgba(255, 255, 255, 0.06);
            width: 100%;
        }
        .admin-shell .settings_menu a {
            border-radius: 0;
            min-height: 54px;
            padding: 0 18px;
        }
        .admin-shell .settings_menu i {
            width: 18px;
            margin-right: 10px;
            text-align: center;
        }
        .admin-shell .settings_menu a.active { background: rgba(255, 153, 0, 0.13); color: #ffb347; }
        .admin-shell .settings_menu a.active span,
        .admin-shell .settings_menu a.active i { color: inherit; }
        .admin-shell .admin-sidebar-set {
            display: none;
        }
        .admin-shell .admin-sidebar-set.active {
            display: block;
        }
        .admin-shell .settings-panel-title { font-size: 1.2857142857rem; font-weight: 700; margin-bottom: 8px; }
        .admin-shell .settings-panel-subtitle { color: #7f7f7f; margin-bottom: 24px; }
        .admin-shell .admin-panel-stack {
            width: 100%;
        }
        .admin-shell .admin-view-panel {
            display: none;
        }
        .admin-shell .admin-view-panel.is-active {
            display: block;
        }
        .admin-shell .admin-view-panel[data-admin-loaded="0"] [data-admin-content] {
            visibility: hidden;
        }
        .admin-shell .admin-view-panel.is-loading [data-admin-skeleton] {
            display: block;
        }
        .admin-shell .admin-view-panel.is-loading [data-admin-content] {
            visibility: hidden;
        }
        .admin-shell [data-admin-skeleton] {
            display: none;
            margin-top: 8px;
        }
        .admin-shell .admin-skeleton-row,
        .admin-shell .admin-skeleton-card,
        .admin-shell .admin-skeleton-chip {
            position: relative;
            overflow: hidden;
            background: #191919;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .admin-shell .admin-skeleton-row::after,
        .admin-shell .admin-skeleton-card::after,
        .admin-shell .admin-skeleton-chip::after {
            content: "";
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent);
            animation: adminShimmer 1.15s linear infinite;
        }
        .admin-shell .admin-skeleton-title {
            width: clamp(180px, 24vw, 320px);
            height: 16px;
            margin-bottom: 10px;
        }
        .admin-shell .admin-skeleton-copy {
            width: min(100%, 560px);
            height: 12px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-skeleton-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-skeleton-card {
            min-height: 96px;
        }
        .admin-shell .admin-skeleton-table {
            display: grid;
            gap: 10px;
        }
        .admin-shell .admin-skeleton-row {
            height: 54px;
        }
        .admin-shell .admin-skeleton-chip {
            width: 128px;
            height: 36px;
        }
        .admin-shell .admin-section-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-render-block + .admin-render-block {
            margin-top: 18px;
        }
        .admin-shell .admin-panel-feedback:empty {
            display: none;
        }
        .admin-shell .admin-panel-feedback {
            margin-bottom: 18px;
        }
        .admin-shell .admin-chart-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 240px;
            background: #171717;
            border: 1px solid rgba(255,255,255,0.05);
            color: #8a8a8a;
        }
        .admin-shell .admin-chart-frame.is-loading .admin-chart-tooltip {
            display: none;
        }
        @keyframes adminShimmer {
            100% {
                transform: translateX(100%);
            }
        }
        .admin-shell .settings-table-wrap {
            padding: 0;
            background: #171717;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 0;
            margin-bottom: 30px;
            overflow-x: auto;
        }
        .admin-shell .settings-table-wrap .table { margin-bottom: 0; }
        .admin-shell .settings-table-wrap .table thead th {
            padding: 14px 16px;
            font-size: .76rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #9a9a9a;
            background: rgba(255, 255, 255, 0.02);
            border-top: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
        }
        .admin-shell .settings-table-wrap .table tbody td {
            padding: 15px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            background: transparent;
            color: #e6e6e6;
        }
        .admin-shell .settings-table-wrap .table tbody tr:first-child td { border-top: 0; }
        .admin-shell .settings-table-wrap .table tbody tr:nth-child(even) { background: rgba(255, 255, 255, 0.015); }
        .admin-shell .settings-table-wrap .table tbody tr:hover { background: rgba(255, 153, 0, 0.04); }
        .admin-shell .settings-table-wrap .table td code {
            color: #f7f7f7;
            background: rgba(0, 0, 0, 0.24);
            padding: 2px 5px;
        }
        .admin-shell .settings-table-wrap .table td small {
            display: block;
            color: #8e8e8e;
            margin-top: 4px;
        }
        .admin-shell .admin-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .admin-shell .admin-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            align-items: flex-end;
            padding: 18px;
            background: #181818;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .admin-shell .admin-toolbar .form-group { margin-bottom: 0; min-width: 180px; flex: 1 1 180px; }
        .admin-shell .admin-toolbar .form-group--action { flex: 0 0 auto; min-width: 0; }
        .admin-shell .admin-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .admin-shell .admin-kv__item { padding: 16px 18px; background: #191919; border: 1px solid rgba(255, 255, 255, 0.05); }
        .admin-shell .admin-kv__item span { color: #7f7f7f; display: block; font-size: .8rem; margin-bottom: 4px; }
        .admin-shell .admin-kv__item strong { color: #fff; display: block; }
        .admin-shell .admin-kv__item small { color: #9a9a9a; display: block; margin-top: 6px; }
        .admin-shell .admin-detail-panels,
        .admin-shell .admin-section-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .admin-shell .admin-detail-panel,
        .admin-shell .admin-subsection { background: #171717; padding: 20px; margin-bottom: 18px; border: 1px solid rgba(255, 255, 255, 0.05); }
        .admin-shell .admin-subsection:last-child { margin-bottom: 0; }
        .admin-shell .admin-detail-panel h5,
        .admin-shell .admin-subsection h5 { margin-bottom: 14px; }
        .admin-shell .admin-detail-panel p:last-child,
        .admin-shell .admin-subsection p:last-child { margin-bottom: 0; }
        .admin-shell .admin-mini-list { list-style: none; padding: 0; margin: 0; }
        .admin-shell .admin-mini-list li { padding: 10px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .admin-shell .admin-mini-list li:last-child { border-bottom: 0; }
        .admin-shell .admin-mini-list small { color: #7f7f7f; display: block; }
        .admin-shell .admin-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .admin-shell .admin-form-grid .form-group { margin-bottom: 0; }
        .admin-shell .admin-stack > * + * { margin-top: 14px; }
        .admin-shell .admin-table-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .admin-shell .admin-form-card { background: #181818; border: 1px solid rgba(255, 255, 255, 0.05); padding: 18px; }
        .admin-shell .admin-form-card h6 { font-size: .95rem; font-weight: 700; margin-bottom: 12px; }
        .admin-shell .admin-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 16px; }
        .admin-shell .admin-meta-item span { color: #7f7f7f; display: block; font-size: .8rem; margin-bottom: 4px; }
        .admin-shell .admin-meta-item strong,
        .admin-shell .admin-meta-item div { color: #fff; word-break: break-word; }
        .admin-shell .admin-muted { color: #7f7f7f; }
        .admin-shell .admin-code-block { background: #111; border: 1px solid rgba(255, 255, 255, 0.06); padding: 12px; overflow-x: auto; }
        .admin-shell .admin-timeline { list-style: none; padding: 0; margin: 0; }
        .admin-shell .admin-timeline li { padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .admin-shell .admin-timeline li:last-child { border-bottom: 0; }
        .admin-shell .admin-timeline strong { display: block; }
        .admin-shell .admin-empty { color: #9a9a9a; margin: 0; }
        .admin-shell .admin-selected-row { background: rgba(255, 153, 0, 0.08) !important; }
        .admin-shell .page-link { background: #2f3131; border-color: #434645; color: #fff; }
        .admin-shell .page-item.active .page-link { background: #ff9900; border-color: #ff9900; }
        .admin-shell .table td,
        .admin-shell .table th { vertical-align: middle; }
        .admin-shell .admin-impersonation-note {
            margin-bottom: 18px;
            padding: 14px 18px;
            background: rgba(255, 153, 0, 0.08);
            border: 1px solid rgba(255, 153, 0, 0.18);
            color: #f1d2a2;
        }
        .admin-shell .admin-chart-card,
        .admin-shell .admin-profile-card {
            background: #171717;
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 22px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-chart-copy {
            color: #8f8f8f;
            margin-bottom: 18px;
        }
        .admin-shell .admin-period-switch {
            justify-content: flex-end;
        }
        .admin-shell .admin-chart-frame {
            position: relative;
            min-height: 256px;
        }
        .admin-shell .admin-chart-svg {
            width: 100%;
            height: clamp(230px, 28vw, 290px);
            display: block;
        }
        .admin-shell .admin-chart-hit {
            pointer-events: all;
        }
        .admin-shell .admin-chart-tooltip {
            position: absolute;
            left: 0;
            top: 0;
            z-index: 4;
            min-width: 148px;
            padding: 10px 12px;
            background: #101010;
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #f2f2f2;
            pointer-events: none;
            transform: translate(-50%, calc(-100% - 12px));
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.28);
        }
        .admin-shell .admin-chart-tooltip strong,
        .admin-shell .admin-chart-tooltip span,
        .admin-shell .admin-chart-tooltip small {
            display: block;
        }
        .admin-shell .admin-chart-tooltip span {
            color: #ffb347;
            font-size: .78rem;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .admin-shell .admin-chart-tooltip small {
            color: #8f8f8f;
            margin-top: 4px;
        }
        .admin-shell .admin-chart-grid line {
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 1;
        }
        .admin-shell .admin-chart-labels text {
            fill: #7d7d7d;
            font-size: 12px;
        }
        .admin-shell .admin-chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 18px;
        }
        .admin-shell .admin-chart-legend-item {
            min-width: 150px;
        }
        .admin-shell .admin-chart-legend-item span {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #8c8c8c;
            font-size: .82rem;
            margin-bottom: 4px;
        }
        .admin-shell .admin-chart-legend-item strong {
            display: block;
            color: #fff;
            font-size: 1rem;
        }
        .admin-shell .admin-chart-swatch {
            width: 10px;
            height: 10px;
            display: inline-block;
            background: #ff9900;
        }
        .admin-shell .admin-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-overview-stat {
            padding: 18px;
            background: #171717;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .admin-shell .admin-overview-stat span {
            display: block;
            color: #8a8a8a;
            font-size: .8rem;
            margin-bottom: 8px;
        }
        .admin-shell .admin-overview-stat strong {
            display: block;
            color: #fff;
            font-size: 1.5rem;
            line-height: 1.1;
        }
        .admin-shell .admin-overview-stat small {
            display: block;
            color: #8f8f8f;
            margin-top: 8px;
        }
        .admin-shell .admin-profile-head {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(320px, 1fr);
            gap: 18px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-group-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-group-card {
            background: #171717;
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 20px;
        }
        .admin-shell .admin-group-card h6 {
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .admin-shell .admin-group-card p {
            color: #878787;
            margin-bottom: 14px;
        }
        .admin-shell .admin-group-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .admin-shell .admin-group-card li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .admin-shell .admin-group-card li:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .admin-shell .admin-group-card li span {
            color: #8a8a8a;
        }
        .admin-shell .admin-group-card li strong {
            color: #fff;
            text-align: right;
        }
        .admin-shell .admin-subnav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .admin-shell .admin-subnav a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 14px;
            background: #1a1a1a;
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: #b8b8b8;
        }
        .admin-shell .admin-subnav a.active {
            background: rgba(255, 153, 0, 0.12);
            border-color: rgba(255, 153, 0, 0.28);
            color: #ffb347;
        }
        .admin-shell .admin-subnav a i {
            width: 16px;
            text-align: center;
        }
        .admin-shell .admin-chart-grid-layout {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 18px;
        }
        .admin-shell .admin-profile-identity h3 {
            margin-bottom: 8px;
        }
        .admin-shell .admin-profile-identity p {
            color: #8d8d8d;
            margin-bottom: 16px;
        }
        .admin-shell .admin-pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .admin-shell .admin-pill {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 0 12px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.05);
            color: #e1e1e1;
            font-size: .82rem;
        }
        .admin-shell .admin-profile-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .admin-shell.is-loading {
            opacity: .6;
            transition: opacity .18s ease;
        }
        .admin-shell .admin-list-tight li {
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .admin-shell .admin-list-tight li:last-child {
            border-bottom: 0;
        }
        .admin-shell textarea.form-control { min-height: 110px; }
        .admin-shell .form-control,
        .admin-shell .custom-file-label,
        .admin-shell .custom-select {
            background: #222;
            border-color: #3b3b3b;
            color: #fff;
        }
        .admin-shell select.form-control option { color: #111; }
        .admin-shell .btn-secondary {
            background: #2c2c2c;
            border-color: #3b3b3b;
        }
        .admin-shell .status-block {
            background: #171717;
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 18px;
            margin-bottom: 0 !important;
        }
        .admin-shell .status-block .text-muted {
            color: #8a8a8a !important;
        }
        @media (max-width: 1399.98px) {
            .admin-shell .widget_area { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 991.98px) {
            .admin-shell .widget_area { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .admin-shell .the_box { display: block !important; }
            .admin-shell .sidebar.settings-page,
            .admin-shell .details.settings_data {
                position: static;
                width: 100%;
            }
            .admin-shell .sidebar.settings-page { margin-bottom: 18px; }
            .admin-shell .admin-profile-head { grid-template-columns: 1fr; }
            .admin-shell .admin-period-switch { justify-content: flex-start; }
        }
        @media (max-width: 575.98px) {
            .admin-shell .widget_area { grid-template-columns: 1fr; }
            .admin-shell .settings-panel,
            .admin-shell .admin-subsection,
            .admin-shell .admin-detail-panel,
            .admin-shell .admin-form-card,
            .admin-shell .admin-toolbar,
            .admin-shell .status-block { padding: 18px; }
        }
    </style>
CSS;

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
    <link rel="preconnect" href="//cdnjs.cloudflare.com">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="{$currentUser['bootstrap_css']}">
    <meta name="description" content="Video Engine backend">
    <meta name="keywords" content="video,backend,admin">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/css/iziToast.min.css">
    <link rel="stylesheet" type="text/css" href="{$currentUser['panel_css']}">
    {$runtimeScript}
    {$baseStyles}
    {$extraHeadHtml}
</head>
<body>
    <div class="modal fade" id="notifications" tabindex="-1" role="dialog" aria-labelledby="notificationsLabel" aria-hidden="true">
        <div class="modal-dialog" role="document"><div class="modal-content"></div></div>
    </div>

    <nav class="navbar navbar-expand-lg main-menu">
        <div class="container-fluid">
            <a href="{$currentUser['home_url']}" class="navbar-brand">
                <img src="{$currentUser['logo_url']}" height="30" alt="" class="d-inline-block align-top">
            </a>

            <ul class="notifications-mobile d-block d-sm-none m-0 p-0 ml-auto">
                <li class="nav-item dropdown notifications">
                    <a href="#" id="notifications-mobile-toggle" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="nav-link dropdown-toggle">
                        <i class="fad fa-bell"></i>
                    </a>
                    <div aria-labelledby="notifications-mobile-toggle" class="dropdown-menu dropdown-menu-right notifications-box">
                        <div class="title d-flex flex-wrap align-items-center justify-content-between"><span>Notifications</span></div>
                        <ul class="notifications-list m-0 p-0"></ul>
                    </div>
                </li>
            </ul>

            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Toggle navigation">
                <i class="fad fa-bars"></i>
            </button>

            <ul class="navbar-nav px-3 ml-auto d-none d-sm-flex">
                {$headerActionHtml}
                <li class="nav-item dropdown notifications">
                    <a href="#" id="notifications-desktop-toggle" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="nav-link dropdown-toggle">
                        <i class="fad fa-bell"></i>
                    </a>
                    <div aria-labelledby="notifications-desktop-toggle" class="dropdown-menu dropdown-menu-right notifications-box">
                        <div class="title d-flex flex-wrap align-items-center justify-content-between"><span>Notifications</span></div>
                        <ul class="notifications-list m-0 p-0"></ul>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="nav-link dropdown-toggle">
                        <i class="fad fa-user-circle"></i> {$username} <span class="far fa-chevron-down arrow"></span>
                    </a>
                    {$dropdown}
                </li>
            </ul>
        </div>
    </nav>
    {$headerNavStrip}

    <nav class="sidebar collapse" id="menu">
        <button class="navbar-toggler d-block d-sm-none" type="button" data-toggle="collapse" data-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fad fa-times"></i>
        </button>
        <div class="container-fluid"><ul class="nav justify-content-center">{$currentUser['mobile_nav_html']}</ul></div>
    </nav>

    <div class="container-fluid pt-3 pt-sm-5 mt-sm-5 admin-shell" data-admin-shell="1">
        {$widgetsHtml}
        {$impersonationBanner}
        {$flashHtml}
        <div class="d-flex justify-content-between flex-wrap the_box">
            {$sidebarHtml}
            <div class="details settings_data">{$contentHtml}</div>
        </div>
    </div>

    <footer class="footer mt-4">
        <div class="container">
            <div class="row">
                <div class="col-md-2"><img class="logo" src="{$currentUser['logo_url']}" alt="Logo"></div>
                <div class="col-md-10 text-right">
                    <ul class="menu m-0 p-0 d-flex align-items-center justify-content-center justify-content-sm-end flex-wrap">
                        <li><a href="{$currentUser['home_url']}">Home</a></li>
                        <li><a href="{$currentUser['copyright_url']}">Copyright policy</a></li>
                        <li><a href="{$currentUser['terms_url']}">Terms &amp; conditions</a></li>
                        <li><a href="{$currentUser['contact_url']}">Contact Us</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.15.0/umd/popper.min.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.2.1/js/bootstrap.min.js" type="text/javascript"></script>
    <script src="{$currentUser['main_js_url']}" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/js/iziToast.min.js" type="text/javascript"></script>
    <script src="{$currentUser['dood_load_url']}" type="module" defer></script>
</body>
</html>
HTML;
}

function ve_admin_dashboard_shell_context(array $currentUser): array
{
    return [
        'bootstrap_css' => ve_h(ve_url('/assets/css/bootstrap.min.css')),
        'panel_css' => ve_h(ve_url('/assets/css/panel.min__q_e2207d238712.css')),
        'main_js_url' => ve_h(ve_url('/assets/js/main.js')),
        'dood_load_url' => ve_h(ve_url('/assets/js/dood_load.js')),
        'home_url' => ve_h(ve_url('/')),
        'logo_url' => ve_h(ve_url('/assets/img/logo-s.png')),
        'copyright_url' => ve_h(ve_url('/copyright')),
        'terms_url' => ve_h(ve_url('/terms-and-conditions')),
        'contact_url' => ve_h(ve_url('/contact')),
        'settings_url' => ve_h(ve_url('/settings')),
        'header_nav_html' => '',
        'header_action_html' => '',
        'mobile_nav_html' => '',
        'username' => (string) ($currentUser['username'] ?? 'videoengine'),
    ];
}

function ve_render_payout_request_page(): void
{
    $user = ve_require_auth();
    $context = ve_admin_dashboard_shell_context($user);
    $context['header_action_html'] = ve_admin_impersonation_stop_control_html('btn btn-sm btn-secondary admin-stop-button', true);
    $context['mobile_nav_html'] = '<li class="nav-item"><a href="' . ve_h(ve_url('/dashboard')) . '" class="nav-link"><i class="fad fa-shapes"></i>Dashboard</a></li>'
        . '<li class="nav-item"><a href="' . ve_h(ve_url('/videos')) . '" class="nav-link"><i class="fad fa-camera-movie"></i>Videos</a></li>'
        . '<li class="nav-item"><a href="' . ve_h(ve_url('/reports')) . '" class="nav-link"><i class="fad fa-chart-line"></i>Reports</a></li>'
        . '<li class="nav-item"><a href="' . ve_h(ve_url('/settings')) . '" class="nav-link"><i class="fad fa-cog"></i>Settings</a></li>'
        . '<li class="nav-item"><a href="' . ve_h(ve_url('/logout')) . '" class="nav-link logout"><i class="fad fa-power-off text-danger"></i>Logout</a></li>';

    $balanceMicroUsd = ve_dashboard_balance_micro_usd((int) $user['id']);
    $minimumMicroUsd = ve_admin_payout_minimum_micro_usd();
    $settings = ve_get_user_settings((int) $user['id']);
    $history = ve_admin_list_user_payout_requests((int) $user['id'], 20);
    $requestDisabled = $balanceMicroUsd < $minimumMicroUsd || trim((string) ($settings['payment_id'] ?? '')) === '' || ve_admin_has_open_payout_request((int) $user['id']);
    $amountValue = ve_h(number_format($balanceMicroUsd / 1000000, 2, '.', ''));
    $token = ve_h(ve_csrf_token());
    $balanceLabel = ve_h(ve_dashboard_format_currency_micro_usd($balanceMicroUsd));
    $minimumLabel = ve_h(ve_dashboard_format_currency_micro_usd($minimumMicroUsd));
    $paymentMethodLabel = ve_h((string) ($settings['payment_method'] ?? 'Not configured'));
    $destinationLabel = ve_h(ve_admin_mask_payout_destination((string) ($settings['payment_id'] ?? '')) ?: 'Not configured');
    $payoutActionUrl = ve_h(ve_url('/api/payouts/request'));

    $menuHtml = implode('', [
        '<li><a href="' . ve_h(ve_url('/dashboard')) . '" class="d-flex flex-wrap align-items-center"><i class="fad fa-shapes"></i><span>Overview</span></a></li>',
        '<li><a href="' . ve_h(ve_url('/settings')) . '" class="d-flex flex-wrap align-items-center"><i class="fad fa-cog"></i><span>Settings</span></a></li>',
        '<li><a href="' . ve_h(ve_url('/request-payout')) . '" class="d-flex flex-wrap align-items-center active"><i class="fad fa-wallet"></i><span>Request payout</span></a></li>',
    ]);

    $historyRows = '';

    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }

        $historyRows .= '<tr>'
            . '<td>' . ve_h((string) ($item['public_id'] ?? '')) . '</td>'
            . '<td>' . ve_admin_status_badge_html((string) ($item['status'] ?? 'pending')) . '</td>'
            . '<td>' . ve_h((string) ($item['payout_method'] ?? '')) . '</td>'
            . '<td>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($item['amount_micro_usd'] ?? 0))) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($item['created_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($historyRows === '') {
        $historyRows = '<tr><td colspan="5" class="text-center text-muted">No payout requests yet.</td></tr>';
    }

    $buttonAttrs = $requestDisabled ? ' disabled="disabled"' : '';
    $hint = $requestDisabled
        ? '<div class="alert alert-warning mb-4">Configure a payout destination, reach the minimum balance, and wait for any open payout request to be processed before requesting another payout.</div>'
        : '';

    $contentHtml = <<<HTML
<div class="data settings-panel" id="payout_request">
    <div class="settings-panel-title">Payout requests</div>
    <p class="settings-panel-subtitle">Request a withdrawal from your account balance and track the review status without leaving the dashboard.</p>
    {$hint}
    <div class="admin-kv">
        <div class="admin-kv__item"><span>Available balance</span><strong>{$balanceLabel}</strong></div>
        <div class="admin-kv__item"><span>Minimum payout</span><strong>{$minimumLabel}</strong></div>
        <div class="admin-kv__item"><span>Payout method</span><strong>{$paymentMethodLabel}</strong></div>
        <div class="admin-kv__item"><span>Destination</span><strong>{$destinationLabel}</strong></div>
    </div>

    <form method="POST" action="{$payoutActionUrl}" class="mb-4 js-settings-form">
        <input type="hidden" name="token" value="{$token}">
        <div class="row">
            <div class="col-md-4 mb-3">
                <label>Amount (USD)</label>
                <input type="text" name="amount" value="{$amountValue}" class="form-control">
            </div>
            <div class="col-md-8 mb-3">
                <label>Operator note</label>
                <input type="text" name="notes" value="" class="form-control" placeholder="Optional note for the payout team">
            </div>
        </div>
        <div class="admin-actions">
            <button type="submit" class="btn btn-primary"{$buttonAttrs}>Request payout <i class="fad fa-check ml-2"></i></button>
            <a href="{$context['settings_url']}" class="btn btn-secondary">Update payout settings</a>
        </div>
    </form>

    <div class="settings-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Request</th>
                    <th>Status</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>{$historyRows}</tbody>
        </table>
    </div>
</div>
HTML;

    $sidebarIntroHtml = '<div class="status-block d-flex align-items-center mb-4">'
        . '<i class="fad fa-wallet"></i>'
        . '<div class="info ml-3"><h4 class="m-0">Payments</h4><a href="' . ve_h(ve_url('/settings')) . '">Update payout settings <i class="fad fa-arrow-to-right"></i></a></div>'
        . '</div>';

    ve_html(ve_rewrite_html_paths(ve_admin_dashboard_shell(
        $context,
        'Request payout - Video Engine',
        '<div class="sidebar settings-page">' . $sidebarIntroHtml . '<hr><div class="menu settings_menu"><ul class="p-0 m-0 mb-4">' . $menuHtml . '</ul></div></div>',
        $contentHtml
    )));
}

function ve_admin_return_to_input_html(): string
{
    $pathInfo = ve_admin_request_path_info();
    $subsection = trim((string) ($pathInfo['subsection'] ?? ''));
    $resource = trim((string) ($pathInfo['resource'] ?? ''));
    $url = $subsection !== ''
        ? ve_admin_subsection_url($subsection, $resource !== '' ? $resource : null, [], true)
        : ve_admin_url([], true);

    return '<input type="hidden" name="return_to" value="' . ve_h($url) . '">';
}

function ve_admin_backend_sidebar_intro_html(array $actorUser, string $activeSection): string
{
    $sectionMeta = ve_admin_backend_section_meta($activeSection);
    $sectionLabel = ve_h((string) ((ve_admin_sections()[$activeSection]['label'] ?? 'Backend')));
    $roleLabel = ve_h(ve_admin_role_label(ve_admin_primary_role_code_for_user_id((int) ($actorUser['id'] ?? 0))));
    $eyebrow = ve_h((string) ($sectionMeta['eyebrow'] ?? 'Backend'));
    $description = ve_h((string) ($sectionMeta['description'] ?? ''));

    return '<div class="status-block">'
        . '<span class="admin-sidebar-eyebrow">' . $eyebrow . '</span>'
        . '<h4 class="m-0">' . $sectionLabel . '</h4>'
        . '<p class="admin-sidebar-subtitle">' . $description . '</p>'
        . '<div class="admin-actions mt-3"><a href="' . ve_h(ve_url('/dashboard')) . '" class="btn btn-secondary btn-sm">Return to dashboard</a></div>'
        . '<div class="text-muted mt-3">' . $roleLabel . '</div>'
        . '</div>';
}

function ve_admin_backend_sidebar_menu_html(array $actorUser, string $activeSection): string
{
    unset($actorUser);

    $activeSubview = ve_admin_sidebar_active_subview($activeSection, ve_admin_current_subview_slug($activeSection));
    $items = [];
    $definitions = ve_admin_backend_sidebar_definitions($activeSection);

    foreach ($definitions as $definition) {
        $slug = trim((string) ($definition['slug'] ?? ''));
        $url = trim((string) ($definition['url'] ?? ($slug !== '' ? ve_admin_subsection_url($slug) : '#')));
        $activeClass = $slug !== '' && $slug === $activeSubview ? ' active' : '';
        $icon = ve_h((string) ($definition['icon'] ?? 'fa-circle'));
        $label = ve_h((string) ($definition['label'] ?? 'Link'));
        $items[] = '<li><a href="' . ve_h($url) . '" data-admin-nav="1" class="d-flex flex-wrap align-items-center' . $activeClass . '"><i class="fad ' . $icon . '"></i><span>' . $label . '</span></a></li>';
    }

    return implode('', $items);
}

function ve_admin_backend_sidebar_definitions(string $activeSection): array
{
    $definitions = [];

    foreach ((array) (ve_admin_backend_view_definitions()[$activeSection] ?? []) as $definition) {
        if ((string) ($definition['kind'] ?? 'sidebar') !== 'sidebar') {
            continue;
        }

        $definitions[] = $definition;
    }

    return $definitions !== []
        ? $definitions
        : [['label' => 'Section', 'icon' => 'fa-circle', 'slug' => ve_admin_default_subview($activeSection)]];
}

function ve_admin_backend_allowed_view_definitions(array $actorUser): array
{
    $allowedSections = ve_admin_allowed_sections_for_user($actorUser);
    $allowed = [];

    foreach (ve_admin_backend_view_definitions() as $section => $definitions) {
        if (!isset($allowedSections[$section])) {
            continue;
        }

        $allowed[$section] = $definitions;
    }

    return $allowed;
}

function ve_admin_backend_sidebar_set_html(array $actorUser, string $section, bool $isActive = false): string
{
    $activeClass = $isActive ? ' active' : '';
    $sidebarIntroHtml = ve_admin_backend_sidebar_intro_html($actorUser, $section);
    $menuHtml = ve_admin_backend_sidebar_menu_html($actorUser, $section);

    return '<div class="admin-sidebar-set' . $activeClass . '" data-admin-sidebar-section="' . ve_h($section) . '">'
        . $sidebarIntroHtml
        . '<hr>'
        . '<div class="menu settings_menu"><ul class="p-0 m-0 mb-4">' . $menuHtml . '</ul></div>'
        . '</div>';
}

function ve_admin_backend_sidebar_collection_html(array $actorUser, string $activeSection): string
{
    $html = '';

    foreach (ve_admin_backend_allowed_view_definitions($actorUser) as $section => $definitions) {
        unset($definitions);
        $html .= ve_admin_backend_sidebar_set_html($actorUser, $section, $section === $activeSection);
    }

    return '<div class="sidebar settings-page" data-admin-sidebar-container="1">' . $html . '</div>';
}

function ve_admin_backend_panel_shell_html(string $section, string $slug, bool $isActive = false): string
{
    $activeClass = $isActive ? ' is-active' : '';

    return <<<HTML
<section class="data settings-panel admin-view-panel{$activeClass}" data-admin-section="{$section}" data-admin-view="{$slug}" data-admin-loaded="0" data-admin-loading="0">
    <div data-admin-skeleton="1">
        <div class="admin-skeleton-row admin-skeleton-title"></div>
        <div class="admin-skeleton-row admin-skeleton-copy"></div>
        <div class="admin-skeleton-metrics">
            <div class="admin-skeleton-card"></div>
            <div class="admin-skeleton-card"></div>
            <div class="admin-skeleton-card"></div>
            <div class="admin-skeleton-card"></div>
        </div>
        <div class="admin-skeleton-table">
            <div class="admin-skeleton-chip"></div>
            <div class="admin-skeleton-row"></div>
            <div class="admin-skeleton-row"></div>
            <div class="admin-skeleton-row"></div>
        </div>
    </div>
    <div data-admin-content="1">
        <div class="admin-panel-feedback" data-admin-feedback></div>
        <div class="settings-panel-title" data-admin-title></div>
        <p class="settings-panel-subtitle" data-admin-subtitle></p>
        <div class="admin-section-actions" data-admin-actions></div>
        <div class="admin-panel-metrics" data-admin-metrics></div>
        <div class="admin-panel-body" data-admin-body></div>
    </div>
</section>
HTML;
}

function ve_admin_backend_content_shell_html(array $actorUser, string $activeSubview): string
{
    $html = '<div class="admin-panel-stack" data-admin-panel-stack="1">';

    foreach (ve_admin_backend_allowed_view_definitions($actorUser) as $section => $definitions) {
        foreach ($definitions as $definition) {
            $slug = trim((string) ($definition['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $html .= ve_admin_backend_panel_shell_html($section, $slug, $slug === $activeSubview);
        }
    }

    return $html . '</div>';
}

function ve_admin_backend_boot_script_html(array $bootConfig): string
{
    $json = json_encode($bootConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json)
        ? '<script type="application/json" id="admin-backend-config">' . $json . '</script>'
        : '';
}

function ve_admin_backend_boot_config(array $actorUser, string $activeSection, string $activeSubview): array
{
    $sections = [];
    $allowedSections = ve_admin_allowed_sections_for_user($actorUser);

    foreach (ve_admin_backend_allowed_view_definitions($actorUser) as $section => $definitions) {
        $sidebarViews = [];
        $detailViews = [];

        foreach ($definitions as $definition) {
            $entry = [
                'slug' => (string) ($definition['slug'] ?? ''),
                'label' => (string) ($definition['label'] ?? ''),
                'icon' => (string) ($definition['icon'] ?? 'fa-circle'),
            ];

            if ((string) ($definition['kind'] ?? 'sidebar') === 'detail') {
                $detailViews[] = $entry;
                continue;
            }

            $sidebarViews[] = $entry;
        }

        $sections[$section] = [
            'label' => (string) (($allowedSections[$section]['label'] ?? ucfirst($section))),
            'default_list' => ve_admin_default_subview($section, 0),
            'default_detail' => ve_admin_default_subview($section, 1),
            'sidebar_views' => $sidebarViews,
            'detail_views' => $detailViews,
        ];
    }

    return [
        'base_path' => ve_url('/backend'),
        'sections' => $sections,
        'catalog' => ve_admin_backend_subview_catalog(),
        'current' => [
            'section' => $activeSection,
            'subview' => $activeSubview,
            'resource' => ve_admin_current_resource_token(),
            'query' => $_GET,
            'url' => (string) ($_SERVER['REQUEST_URI'] ?? ve_url('/backend')),
        ],
    ];
}

function ve_admin_backend_widgets_html(array $snapshot): string
{
    $cards = [
        [
            'value' => (string) (int) ($snapshot['users']['total_users'] ?? 0),
            'label' => 'Users',
            'link_label' => (string) (int) ($snapshot['users']['users_today'] ?? 0) . ' new today',
            'url' => ve_admin_url(['section' => 'users'], false),
            'icon' => 'fa-users',
        ],
        [
            'value' => (string) (int) ($snapshot['videos']['total_videos'] ?? 0),
            'label' => 'Files',
            'link_label' => ve_human_bytes((int) ($snapshot['videos']['storage_bytes'] ?? 0)) . ' stored',
            'url' => ve_admin_url(['section' => 'videos'], false),
            'icon' => 'fa-copy',
        ],
        [
            'value' => (string) (int) ($snapshot['remote']['queued_jobs'] ?? 0),
            'label' => 'Remote queue',
            'link_label' => (string) (int) ($snapshot['remote']['error_jobs'] ?? 0) . ' failed',
            'url' => ve_admin_url(['section' => 'remote-uploads'], false),
            'icon' => 'fa-cloud-download-alt',
        ],
        [
            'value' => (string) (int) ($snapshot['dmca']['open_notices'] ?? 0),
            'label' => 'Open DMCA',
            'link_label' => (string) (int) ($snapshot['dmca']['total_notices'] ?? 0) . ' total',
            'url' => ve_admin_url(['section' => 'dmca'], false),
            'icon' => 'fa-folder-times',
        ],
        [
            'value' => (string) (int) ($snapshot['payouts']['open_payouts'] ?? 0),
            'label' => 'Open payouts',
            'link_label' => ve_dashboard_format_currency_micro_usd((int) ($snapshot['payouts']['paid_micro_usd'] ?? 0)) . ' paid',
            'url' => ve_admin_url(['section' => 'payouts'], false),
            'icon' => 'fa-wallet',
        ],
    ];

    $html = '';

    foreach ($cards as $card) {
        $html .= '<div class="widget_box admin-widget-card d-flex justify-content-between flex-wrap align-items-center">'
            . '<div class="info">'
            . '<span class="money d-block">' . ve_h((string) ($card['value'] ?? '0')) . '</span>'
            . '<span class="d-block">' . ve_h((string) ($card['label'] ?? '')) . '</span>'
            . '<a href="' . ve_h((string) ($card['url'] ?? '#')) . '">' . ve_h((string) ($card['link_label'] ?? 'Open')) . ' <i class="fad fa-arrow-to-right"></i></a>'
            . '</div>'
            . '<div class="icon"><i class="fad ' . ve_h((string) ($card['icon'] ?? 'fa-circle')) . '"></i></div>'
            . '</div>';
    }

    return '<div class="d-flex widget_area admin-widget-area justify-content-between flex-wrap">' . $html . '</div>';
}

function ve_admin_empty_table_row_html(int $colspan, string $message): string
{
    return '<tr><td colspan="' . $colspan . '" class="text-center text-muted">' . ve_h($message) . '</td></tr>';
}

function ve_admin_pretty_json_html($value): string
{
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        }
    }

    if (!is_array($value)) {
        $value = ['value' => $value];
    }

    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return '<pre class="mb-0 small">' . ve_h(is_string($json) ? $json : '{}') . '</pre>';
}

function ve_admin_render_overview_section(): string
{
    $snapshot = ve_admin_overview_snapshot();
    $auditRows = '';

    foreach ((array) ($snapshot['recent_audit']['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $detailUrl = ve_h(ve_admin_url(['section' => 'audit', 'resource' => (string) (int) ($row['id'] ?? 0)], false));
        $auditRows .= '<tr>'
            . '<td><a href="' . $detailUrl . '">' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</a></td>'
            . '<td>' . ve_h((string) ($row['actor_username'] ?? 'System')) . '</td>'
            . '<td>' . ve_h((string) ($row['event_code'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($row['target_type'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($row['ip_address'] ?? '')) . '</td>'
            . '</tr>';
    }

    if ($auditRows === '') {
        $auditRows = ve_admin_empty_table_row_html(5, 'No backend activity recorded yet.');
    }

    $activeUsers = ve_h((string) (int) ($snapshot['users']['active_users'] ?? 0));
    $suspendedUsers = ve_h((string) (int) ($snapshot['users']['suspended_users'] ?? 0));
    $readyVideos = ve_h((string) (int) ($snapshot['videos']['ready_videos'] ?? 0));
    $storageLabel = ve_h(ve_human_bytes((int) ($snapshot['videos']['storage_bytes'] ?? 0)));
    $queuedJobs = ve_h((string) (int) ($snapshot['remote']['queued_jobs'] ?? 0));
    $errorJobs = ve_h((string) (int) ($snapshot['remote']['error_jobs'] ?? 0));
    $openDmca = ve_h((string) (int) ($snapshot['dmca']['open_notices'] ?? 0));
    $openPayouts = ve_h((string) (int) ($snapshot['payouts']['open_payouts'] ?? 0));
    $activeDomains = ve_h((string) (int) ($snapshot['domains']['active_domains'] ?? 0));
    $activeDeliveryDomains = ve_h((string) (int) ($snapshot['infrastructure']['active_delivery_domains'] ?? 0));
    $storageNodes = ve_h((string) (int) ($snapshot['infrastructure']['storage_nodes'] ?? 0));
    $uploadEndpoints = ve_h((string) (int) ($snapshot['infrastructure']['active_upload_endpoints'] ?? 0));
    $quickLinks = implode('', [
        '<li><a href="' . ve_h(ve_admin_url(['section' => 'users'], false)) . '">User moderation</a></li>',
        '<li><a href="' . ve_h(ve_admin_url(['section' => 'remote-uploads'], false)) . '">Remote queue</a></li>',
        '<li><a href="' . ve_h(ve_admin_url(['section' => 'dmca'], false)) . '">DMCA review queue</a></li>',
        '<li><a href="' . ve_h(ve_admin_url(['section' => 'payouts'], false)) . '">Payout approvals</a></li>',
        '<li><a href="' . ve_h(ve_admin_url(['section' => 'infrastructure'], false)) . '">Infrastructure controls</a></li>',
    ]);

    return <<<HTML
<div class="data settings-panel" id="overview">
    <div class="settings-panel-title">Operational overview</div>
    <p class="settings-panel-subtitle">Track moderation queues, billing pressure, and infrastructure inventory from the same dashboard shell used across the uploader product.</p>
    <div class="admin-detail-panels">
        <div class="admin-detail-panel">
            <h5 class="mb-3">Footprint</h5>
            <div class="admin-kv">
                <div class="admin-kv__item"><span>Active users</span><strong>{$activeUsers}</strong></div>
                <div class="admin-kv__item"><span>Suspended users</span><strong>{$suspendedUsers}</strong></div>
                <div class="admin-kv__item"><span>Ready files</span><strong>{$readyVideos}</strong></div>
                <div class="admin-kv__item"><span>Stored media</span><strong>{$storageLabel}</strong></div>
            </div>
        </div>
        <div class="admin-detail-panel">
            <h5 class="mb-3">Queues</h5>
            <div class="admin-kv">
                <div class="admin-kv__item"><span>Remote jobs</span><strong>{$queuedJobs}</strong></div>
                <div class="admin-kv__item"><span>Remote failures</span><strong>{$errorJobs}</strong></div>
                <div class="admin-kv__item"><span>Open DMCA</span><strong>{$openDmca}</strong></div>
                <div class="admin-kv__item"><span>Open payouts</span><strong>{$openPayouts}</strong></div>
            </div>
        </div>
        <div class="admin-detail-panel">
            <h5 class="mb-3">Network and delivery</h5>
            <div class="admin-kv">
                <div class="admin-kv__item"><span>Active custom domains</span><strong>{$activeDomains}</strong></div>
                <div class="admin-kv__item"><span>Delivery domains</span><strong>{$activeDeliveryDomains}</strong></div>
                <div class="admin-kv__item"><span>Storage nodes</span><strong>{$storageNodes}</strong></div>
                <div class="admin-kv__item"><span>Upload endpoints</span><strong>{$uploadEndpoints}</strong></div>
            </div>
        </div>
        <div class="admin-detail-panel">
            <h5 class="mb-3">Quick links</h5>
            <ul class="admin-mini-list">{$quickLinks}</ul>
        </div>
    </div>
    <div class="admin-detail-panel mt-4">
        <h5 class="mb-3">Recent audit events</h5>
        <div class="settings-table-wrap">
            <table class="table">
                <thead><tr><th>Time</th><th>Actor</th><th>Event</th><th>Target</th><th>IP</th></tr></thead>
                <tbody>{$auditRows}</tbody>
            </table>
        </div>
    </div>
</div>
HTML;
}

function ve_admin_render_users_section(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $roleCode = trim((string) ($_GET['role'] ?? ''));
    $page = ve_admin_request_page();
    $list = ve_admin_list_users($query, $status, $roleCode, $page);
    $detail = (int) ($_GET['user'] ?? 0) > 0 ? ve_admin_user_detail((int) $_GET['user']) : null;
    $backendUrl = ve_h(ve_url('/backend'));
    $queryValue = ve_h($query);
    $statusValue = ve_h($status);
    $roleValue = ve_h($roleCode);
    $rowsHtml = '';

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $detailUrl = ve_h(ve_admin_url(['user' => (int) ($row['id'] ?? 0)]));
        $rowsHtml .= '<tr>'
            . '<td><a href="' . $detailUrl . '">#' . (int) ($row['id'] ?? 0) . '</a></td>'
            . '<td>' . ve_h((string) ($row['username'] ?? '')) . '<br><small>' . ve_h((string) ($row['email'] ?? '')) . '</small></td>'
            . '<td>' . ve_admin_status_badge_html((string) ($row['status'] ?? 'active')) . '</td>'
            . '<td>' . ve_h(ve_admin_role_label((string) ($row['role_code'] ?? ''))) . '</td>'
            . '<td>' . ve_h((string) ($row['plan_code'] ?? 'free')) . '</td>'
            . '<td>' . ve_h((string) ($row['video_count'] ?? 0)) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($row['storage_bytes'] ?? 0))) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['last_login_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="8" class="text-center text-muted">No users matched the current filters.</td></tr>';
    }

    $detailHtml = '';

    if (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $roleOptions = '<option value="">User</option>';
        $detailId = (int) ($detail['id'] ?? 0);
        $detailUsername = ve_h((string) ($detail['username'] ?? ''));
        $detailBalance = ve_h(ve_dashboard_format_currency_micro_usd((int) ($detail['balance_micro_usd'] ?? 0)));
        $detailPlan = ve_h((string) ($detail['plan_code'] ?? 'free'));
        $detailLastLogin = ve_h(ve_format_datetime_label((string) ($detail['last_login_at'] ?? '')));
        $detailFormAction = ve_h(ve_admin_url(['section' => 'users', 'user' => $detailId], false));
        $deleteFormAction = ve_h(ve_admin_url(['section' => 'users'], false));
        $returnToInput = ve_admin_return_to_input_html();
        $planCodeValue = ve_h((string) ($detail['plan_code'] ?? 'free'));
        $premiumUntilValue = ve_h((string) ($detail['premium_until'] ?? ''));
        $paymentMethodValue = ve_h((string) ($detail['settings']['payment_method'] ?? 'Webmoney'));
        $paymentIdValue = ve_h((string) ($detail['settings']['payment_id'] ?? ''));
        $statusActiveSelected = (string) ($detail['status'] ?? '') === 'active' ? ' selected="selected"' : '';
        $statusSuspendedSelected = (string) ($detail['status'] ?? '') === 'suspended' ? ' selected="selected"' : '';
        $apiEnabledChecked = (int) ($detail['settings']['api_enabled'] ?? 1) === 1 ? ' checked="checked"' : '';

        foreach (ve_admin_role_catalog() as $roleKey => $roleMeta) {
            $selected = $roleKey === ($detail['primary_role_code'] ?? '') ? ' selected="selected"' : '';
            $roleOptions .= '<option value="' . ve_h($roleKey) . '"' . $selected . '>' . ve_h((string) ($roleMeta['label'] ?? $roleKey)) . '</option>';
        }

        $recentVideos = '';

        foreach ((array) ($detail['recent_videos'] ?? []) as $video) {
            if (!is_array($video)) {
                continue;
            }

            $recentVideos .= '<li><strong>' . ve_h((string) ($video['title'] ?? 'Untitled')) . '</strong><small>' . ve_h((string) ($video['public_id'] ?? '')) . ' • ' . ve_h((string) ($video['status'] ?? '')) . '</small></li>';
        }

        if ($recentVideos === '') {
            $recentVideos = '<li class="text-muted">No files yet.</li>';
        }

        $recentPayouts = '';

        foreach ((array) ($detail['recent_payouts'] ?? []) as $payout) {
            if (!is_array($payout)) {
                continue;
            }

            $recentPayouts .= '<li><strong>' . ve_h((string) ($payout['public_id'] ?? '')) . '</strong><small>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($payout['amount_micro_usd'] ?? 0))) . ' • ' . ve_h((string) ($payout['status'] ?? '')) . '</small></li>';
        }

        if ($recentPayouts === '') {
            $recentPayouts = '<li class="text-muted">No payouts yet.</li>';
        }

        $detailHtml = <<<HTML
<div class="admin-detail-panel mt-4">
    <h5>User detail: {$detailUsername}</h5>
    <div class="admin-kv">
        <div class="admin-kv__item"><span>User ID</span><strong>#{$detailId}</strong></div>
        <div class="admin-kv__item"><span>Balance</span><strong>{$detailBalance}</strong></div>
        <div class="admin-kv__item"><span>Plan</span><strong>{$detailPlan}</strong></div>
        <div class="admin-kv__item"><span>Last login</span><strong>{$detailLastLogin}</strong></div>
    </div>
    <form method="POST" action="{$detailFormAction}" class="mb-4">
        <input type="hidden" name="token" value="{$token}">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="user_id" value="{$detailId}">
        {$returnToInput}
        <div class="row">
            <div class="col-md-3 mb-3">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="active"{$statusActiveSelected}>Active</option>
                    <option value="suspended"{$statusSuspendedSelected}>Suspended</option>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label>Role</label>
                <select name="role_code" class="form-control">{$roleOptions}</select>
            </div>
            <div class="col-md-3 mb-3">
                <label>Plan</label>
                <input type="text" name="plan_code" value="{$planCodeValue}" class="form-control">
            </div>
            <div class="col-md-3 mb-3">
                <label>Premium until</label>
                <input type="text" name="premium_until" value="{$premiumUntilValue}" class="form-control" placeholder="YYYY-MM-DD HH:MM:SS">
            </div>
            <div class="col-md-3 mb-3">
                <label>Payout method</label>
                <input type="text" name="payment_method" value="{$paymentMethodValue}" class="form-control">
            </div>
            <div class="col-md-5 mb-3">
                <label>Payout destination</label>
                <input type="text" name="payment_id" value="{$paymentIdValue}" class="form-control">
            </div>
            <div class="col-md-4 mb-3 d-flex align-items-end">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="api_enabled_user_{$detailId}" name="api_enabled" value="1"{$apiEnabledChecked}>
                    <label class="custom-control-label" for="api_enabled_user_{$detailId}">API access enabled</label>
                </div>
            </div>
        </div>
        <div class="admin-actions">
            <button type="submit" class="btn btn-primary">Save user</button>
        </div>
    </form>
    <div class="admin-actions mb-4">
        <form method="POST" action="{$detailFormAction}">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="impersonate_user">
            <input type="hidden" name="user_id" value="{$detailId}">
            {$returnToInput}
            <button type="submit" class="btn btn-secondary">Impersonate</button>
        </form>
        <form method="POST" action="{$deleteFormAction}" onsubmit="return confirm('Delete this user and all owned data permanently?');">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="{$detailId}">
            {$returnToInput}
            <button type="submit" class="btn btn-danger">Delete user</button>
        </form>
    </div>
    <div class="admin-detail-panels">
        <div class="admin-detail-panel"><h5>Recent files</h5><ul class="admin-mini-list">{$recentVideos}</ul></div>
        <div class="admin-detail-panel"><h5>Recent payouts</h5><ul class="admin-mini-list">{$recentPayouts}</ul></div>
    </div>
</div>
HTML;
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));

    return <<<HTML
<div class="data settings-panel" id="users">
    <div class="settings-panel-title">User management</div>
    <p class="settings-panel-subtitle">Search, suspend, promote, impersonate, and delete accounts without leaving the backend.</p>
    <form method="GET" action="{$backendUrl}" class="admin-toolbar">
        <input type="hidden" name="section" value="users">
        <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Username, email, or ID"></div>
        <div class="form-group"><label>Status</label><input type="text" name="status" value="{$statusValue}" class="form-control" placeholder="active or suspended"></div>
        <div class="form-group"><label>Role</label><input type="text" name="role" value="{$roleValue}" class="form-control" placeholder="admin or super_admin"></div>
        <div class="form-group"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>User</th><th>Status</th><th>Role</th><th>Plan</th><th>Files</th><th>Storage</th><th>Last login</th></tr></thead>
            <tbody>{$rowsHtml}</tbody>
        </table>
    </div>
    {$pagination}
    {$detailHtml}
</div>
HTML;
}

function ve_admin_render_videos_section(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = ve_admin_request_page();
    $list = ve_admin_list_videos($query, $status, 0, $page);
    $backendUrl = ve_h(ve_url('/backend'));
    $queryValue = ve_h($query);
    $statusValue = ve_h($status);
    $token = ve_h(ve_csrf_token());
    $rowsHtml = '';

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $toggleAction = (int) ($row['is_public'] ?? 1) === 1 ? 'make_video_private' : 'make_video_public';
        $toggleLabel = (int) ($row['is_public'] ?? 1) === 1 ? 'Make private' : 'Make public';
        $rowsHtml .= '<tr>'
            . '<td>#' . (int) ($row['id'] ?? 0) . '</td>'
            . '<td>' . ve_h((string) ($row['title'] ?? 'Untitled')) . '<br><small>' . ve_h((string) ($row['public_id'] ?? '')) . '</small></td>'
            . '<td>' . ve_h((string) ($row['username'] ?? '')) . '</td>'
            . '<td>' . ve_admin_status_badge_html((string) ($row['status'] ?? 'queued')) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) (($row['processed_size_bytes'] ?? 0) > 0 ? $row['processed_size_bytes'] : $row['original_size_bytes'] ?? 0))) . '</td>'
            . '<td>' . ((int) ($row['is_public'] ?? 1) === 1 ? ve_admin_badge_html('public', 'success') : ve_admin_badge_html('private', 'secondary')) . '</td>'
            . '<td><div class="admin-actions">'
            . '<form method="POST" action="' . ve_h(ve_admin_url(['section' => 'videos'], false)) . '"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="' . $toggleAction . '"><input type="hidden" name="video_id" value="' . (int) ($row['id'] ?? 0) . '">' . ve_admin_return_to_input_html() . '<button type="submit" class="btn btn-sm btn-secondary">' . $toggleLabel . '</button></form>'
            . '<form method="POST" action="' . ve_h(ve_admin_url(['section' => 'videos'], false)) . '" onsubmit="return confirm(\'Delete this file permanently?\');"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="delete_video"><input type="hidden" name="video_id" value="' . (int) ($row['id'] ?? 0) . '">' . ve_admin_return_to_input_html() . '<button type="submit" class="btn btn-sm btn-danger">Delete</button></form>'
            . '</div></td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="7" class="text-center text-muted">No files matched the current filters.</td></tr>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));

    return <<<HTML
<div class="data settings-panel" id="videos">
    <div class="settings-panel-title">Files and videos</div>
    <p class="settings-panel-subtitle">Moderate storage-heavy content with simple indexed filters and one-click actions.</p>
    <form method="GET" action="{$backendUrl}" class="admin-toolbar">
        <input type="hidden" name="section" value="videos">
        <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Title, public ID, or owner"></div>
        <div class="form-group"><label>Status</label><input type="text" name="status" value="{$statusValue}" class="form-control" placeholder="ready, queued, processing"></div>
        <div class="form-group"><button type="submit" class="btn btn-primary">Filter</button></div>
    </form>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>File</th><th>Owner</th><th>Status</th><th>Size</th><th>Access</th><th>Actions</th></tr></thead>
            <tbody>{$rowsHtml}</tbody>
        </table>
    </div>
    {$pagination}
</div>
HTML;
}

function ve_admin_return_to_hidden_html(string $url): string
{
    return '<input type="hidden" name="return_to" value="' . ve_h($url) . '">';
}

function ve_admin_select_options_html(array $options, string $selectedValue, bool $includeBlank = false, string $blankLabel = 'All'): string
{
    $html = $includeBlank
        ? '<option value="">' . ve_h($blankLabel) . '</option>'
        : '';

    foreach ($options as $value => $label) {
        $selected = (string) $value === $selectedValue ? ' selected="selected"' : '';
        $html .= '<option value="' . ve_h((string) $value) . '"' . $selected . '>' . ve_h((string) $label) . '</option>';
    }

    return $html;
}

function ve_admin_metric_items_html(array $items): string
{
    $html = '';

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = ve_h((string) ($item['label'] ?? ''));
        $value = ve_h((string) ($item['value'] ?? '0'));
        $meta = trim((string) ($item['meta'] ?? ''));
        $metaHtml = $meta !== '' ? '<small>' . ve_h($meta) . '</small>' : '';
        $html .= '<div class="admin-kv__item"><span>' . $label . '</span><strong>' . $value . '</strong>' . $metaHtml . '</div>';
    }

    return '<div class="admin-kv">' . $html . '</div>';
}

function ve_admin_backend_section_meta(string $section): array
{
    $meta = [
        'overview' => [
            'eyebrow' => 'Service pulse',
            'description' => 'Service-wide totals, traffic movement, and daily platform trends.',
        ],
        'users' => [
            'eyebrow' => 'Accounts',
            'description' => 'Search accounts, review account health, and inspect complete user profiles.',
        ],
        'videos' => [
            'eyebrow' => 'Library',
            'description' => 'Moderate uploaded files, inspect ownership, and resolve storage-heavy content issues.',
        ],
        'remote-uploads' => [
            'eyebrow' => 'Ingest queue',
            'description' => 'Track remote import throughput, failures, and source reliability.',
        ],
        'dmca' => [
            'eyebrow' => 'Compliance',
            'description' => 'Manage takedown cases, evidence, and restoration workflow.',
        ],
        'payouts' => [
            'eyebrow' => 'Billing',
            'description' => 'Review payout requests, approve transfers, and inspect payout readiness.',
        ],
        'domains' => [
            'eyebrow' => 'Routing',
            'description' => 'Inspect custom domain health, ownership, and DNS readiness.',
        ],
        'app' => [
            'eyebrow' => 'Configuration',
            'description' => 'Control operational defaults, payout policy, and backend behavior.',
        ],
        'infrastructure' => [
            'eyebrow' => 'Delivery plane',
            'description' => 'Manage nodes, volumes, upload endpoints, delivery domains, and maintenance windows.',
        ],
        'audit' => [
            'eyebrow' => 'Traceability',
            'description' => 'Inspect backend actions with actor, target, and before/after payload context.',
        ],
    ];

    return $meta[$section] ?? [
        'eyebrow' => 'Backend',
        'description' => 'Operational controls for the uploader service.',
    ];
}

function ve_admin_number_label(float $value, int $decimals = 0): string
{
    return number_format($value, $decimals, '.', ',');
}

function ve_admin_series_total(array $points, string $key): int
{
    $total = 0;

    foreach ($points as $point) {
        if (!is_array($point)) {
            continue;
        }

        $total += (int) ($point[$key] ?? 0);
    }

    return $total;
}

function ve_admin_series_peak(array $points, string $key): int
{
    $peak = 0;

    foreach ($points as $point) {
        if (!is_array($point)) {
            continue;
        }

        $peak = max($peak, (int) ($point[$key] ?? 0));
    }

    return $peak;
}

function ve_admin_series_average(array $points, string $key): float
{
    if ($points === []) {
        return 0.0;
    }

    return ve_admin_series_total($points, $key) / max(1, count($points));
}

function ve_admin_service_trend_snapshot(int $lookbackDays = 14): array
{
    $pdo = ve_db();
    $range = ve_dashboard_normalize_date_range(null, null, $lookbackDays);
    $points = [];

    foreach (ve_dashboard_date_series($range['from'], $range['to']) as $date) {
        $points[$date] = [
            'date' => $date,
            'new_users' => 0,
            'active_users' => 0,
            'views' => 0,
            'bandwidth_bytes' => 0,
            'premium_bandwidth_bytes' => 0,
            'earned_micro_usd' => 0,
            'uploads' => 0,
            'uploaded_bytes' => 0,
            'remote_jobs' => 0,
            'remote_failed' => 0,
            'dmca_notices' => 0,
            'payout_requests' => 0,
            'payout_amount_micro_usd' => 0,
        ];
    }

    $statements = [
        'users' => $pdo->prepare(
            'SELECT substr(created_at, 1, 10) AS stat_date, COUNT(*) AS new_users
             FROM users
             WHERE deleted_at IS NULL
               AND substr(created_at, 1, 10) BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
        'activity' => $pdo->prepare(
            'SELECT
                stat_date,
                COUNT(DISTINCT CASE
                    WHEN views > 0
                      OR earned_micro_usd > 0
                      OR referral_earned_micro_usd > 0
                      OR bandwidth_bytes > 0
                      OR premium_bandwidth_bytes > 0
                    THEN user_id
                END) AS active_users,
                COALESCE(SUM(views), 0) AS views,
                COALESCE(SUM(bandwidth_bytes), 0) AS bandwidth_bytes,
                COALESCE(SUM(premium_bandwidth_bytes), 0) AS premium_bandwidth_bytes,
                COALESCE(SUM(earned_micro_usd + referral_earned_micro_usd), 0) AS earned_micro_usd
             FROM user_stats_daily
             WHERE stat_date BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
        'uploads' => $pdo->prepare(
            'SELECT
                substr(created_at, 1, 10) AS stat_date,
                COUNT(*) AS uploads,
                COALESCE(SUM(CASE
                    WHEN processed_size_bytes > 0 THEN processed_size_bytes
                    ELSE original_size_bytes
                END), 0) AS uploaded_bytes
             FROM videos
             WHERE deleted_at IS NULL
               AND substr(created_at, 1, 10) BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
        'remote' => $pdo->prepare(
            'SELECT
                substr(created_at, 1, 10) AS stat_date,
                COUNT(*) AS remote_jobs,
                COALESCE(SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END), 0) AS remote_failed
             FROM remote_uploads
             WHERE deleted_at IS NULL
               AND substr(created_at, 1, 10) BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
        'dmca' => $pdo->prepare(
            'SELECT substr(received_at, 1, 10) AS stat_date, COUNT(*) AS dmca_notices
             FROM dmca_notices
             WHERE substr(received_at, 1, 10) BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
        'payouts' => $pdo->prepare(
            'SELECT
                substr(created_at, 1, 10) AS stat_date,
                COUNT(*) AS payout_requests,
                COALESCE(SUM(amount_micro_usd), 0) AS payout_amount_micro_usd
             FROM payout_requests
             WHERE substr(created_at, 1, 10) BETWEEN :from_date AND :to_date
             GROUP BY stat_date'
        ),
    ];

    foreach ($statements as $statement) {
        $statement->execute([
            ':from_date' => $range['from'],
            ':to_date' => $range['to'],
        ]);

        foreach ($statement->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string) ($row['stat_date'] ?? '');

            if ($date === '' || !isset($points[$date])) {
                continue;
            }

            foreach ($row as $key => $value) {
                if ($key === 'stat_date' || is_int($key)) {
                    continue;
                }

                $points[$date][$key] = (int) $value;
            }
        }
    }

    $now = ve_now();
    $liveWatchersStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM video_playback_sessions
         WHERE revoked_at IS NULL
           AND expires_at >= :now
           AND last_seen_at >= :active_since'
    );
    $liveWatchersStmt->execute([
        ':now' => $now,
        ':active_since' => gmdate('Y-m-d H:i:s', ve_timestamp() - 600),
    ]);

    $activeSessionsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM user_sessions
         WHERE revoked_at IS NULL
           AND expires_at >= :now
           AND last_seen_at >= :active_since'
    );
    $activeSessionsStmt->execute([
        ':now' => $now,
        ':active_since' => gmdate('Y-m-d H:i:s', ve_timestamp() - 1800),
    ]);

    $series = array_values($points);

    return [
        'range' => $range,
        'points' => $series,
        'traffic_total_bytes' => ve_admin_series_total($series, 'bandwidth_bytes'),
        'premium_traffic_total_bytes' => ve_admin_series_total($series, 'premium_bandwidth_bytes'),
        'views_total' => ve_admin_series_total($series, 'views'),
        'uploads_total' => ve_admin_series_total($series, 'uploads'),
        'new_users_total' => ve_admin_series_total($series, 'new_users'),
        'active_users_peak' => ve_admin_series_peak($series, 'active_users'),
        'traffic_peak_bytes' => ve_admin_series_peak($series, 'bandwidth_bytes'),
        'views_peak' => ve_admin_series_peak($series, 'views'),
        'uploaded_bytes_total' => ve_admin_series_total($series, 'uploaded_bytes'),
        'payout_amount_total_micro_usd' => ve_admin_series_total($series, 'payout_amount_micro_usd'),
        'earned_total_micro_usd' => ve_admin_series_total($series, 'earned_micro_usd'),
        'live_watchers' => (int) $liveWatchersStmt->fetchColumn(),
        'active_sessions' => (int) $activeSessionsStmt->fetchColumn(),
    ];
}

function ve_admin_request_range_days(int $default = 14): int
{
    $requested = (int) ($_GET['days'] ?? $default);
    $allowed = [7, 14, 30, 90];

    return in_array($requested, $allowed, true) ? $requested : $default;
}

function ve_admin_period_switch_html(array $options, int $currentDays, string $subsection, string|int|null $resource = null): string
{
    $items = [];

    foreach ($options as $days) {
        $className = $days === $currentDays ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-secondary';
        $items[] = '<a href="' . ve_h(ve_admin_subsection_url($subsection, $resource, ['days' => $days], false)) . '" data-admin-nav="1" class="' . $className . '">' . ve_h((string) $days) . ' days</a>';
    }

    return '<div class="admin-actions admin-period-switch">' . implode('', $items) . '</div>';
}

function ve_admin_chart_value_label(int $value, string $format): string
{
    return match ($format) {
        'bytes' => ve_human_bytes($value),
        'currency' => ve_dashboard_format_currency_micro_usd($value),
        default => ve_admin_number_label((float) $value, 0),
    };
}

function ve_admin_active_subsection_html(string $activeSubview, array $panels, string $fallbackSubview): string
{
    if (isset($panels[$activeSubview]) && is_string($panels[$activeSubview])) {
        return (string) $panels[$activeSubview];
    }

    if (isset($panels[$fallbackSubview]) && is_string($panels[$fallbackSubview])) {
        return (string) $panels[$fallbackSubview];
    }

    foreach ($panels as $panelHtml) {
        if (is_string($panelHtml) && $panelHtml !== '') {
            return $panelHtml;
        }
    }

    return '';
}

function ve_admin_subsection_notice_html(string $message): string
{
    return '<div class="admin-subsection"><p class="admin-empty">' . ve_h($message) . '</p></div>';
}

function ve_admin_group_card_html(string $title, string $description, array $items): string
{
    $rows = '';

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = ve_h((string) ($item['label'] ?? ''));
        $value = ve_h((string) ($item['value'] ?? '0'));
        $rows .= '<li><span>' . $label . '</span><strong>' . $value . '</strong></li>';
    }

    if ($rows === '') {
        $rows = '<li><span>No data</span><strong>0</strong></li>';
    }

    return '<div class="admin-group-card"><h6>' . ve_h($title) . '</h6><p>' . ve_h($description) . '</p><ul>' . $rows . '</ul></div>';
}

function ve_admin_user_segments_snapshot(): array
{
    $pdo = ve_db();
    $summary = (array) ($pdo->query(
        "SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_users,
            SUM(CASE WHEN plan_code <> 'free' THEN 1 ELSE 0 END) AS paid_plan_users,
            SUM(CASE WHEN premium_until IS NOT NULL AND premium_until >= '" . ve_now() . "' THEN 1 ELSE 0 END) AS premium_users,
            SUM(CASE WHEN COALESCE(us.api_enabled, 1) = 1 THEN 1 ELSE 0 END) AS api_enabled_users,
            SUM(CASE WHEN COALESCE(domain_counts.active_domains, 0) > 0 THEN 1 ELSE 0 END) AS branded_users,
            SUM(CASE WHEN COALESCE(video_counts.video_total, 0) >= 25 THEN 1 ELSE 0 END) AS library_users,
            SUM(CASE WHEN substr(users.created_at, 1, 10) >= '" . gmdate('Y-m-d', ve_timestamp() - (30 * 86400)) . "' THEN 1 ELSE 0 END) AS new_last_30_days
         FROM users
         LEFT JOIN user_settings us ON us.user_id = users.id
         LEFT JOIN (
            SELECT user_id, COUNT(*) AS video_total
            FROM videos
            WHERE deleted_at IS NULL
            GROUP BY user_id
         ) AS video_counts ON video_counts.user_id = users.id
         LEFT JOIN (
            SELECT user_id, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_domains
            FROM custom_domains
            GROUP BY user_id
         ) AS domain_counts ON domain_counts.user_id = users.id
         WHERE users.deleted_at IS NULL"
    )->fetch() ?: []);

    $newUsers = $pdo->query(
        "SELECT id, username, email, created_at
         FROM users
         WHERE deleted_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 6"
    )->fetchAll() ?: [];

    $storageLeaders = $pdo->query(
        "SELECT users.id, users.username,
                COUNT(videos.id) AS video_total,
                COALESCE(SUM(CASE WHEN videos.processed_size_bytes > 0 THEN videos.processed_size_bytes ELSE videos.original_size_bytes END), 0) AS storage_bytes
         FROM users
         LEFT JOIN videos ON videos.user_id = users.id AND videos.deleted_at IS NULL
         WHERE users.deleted_at IS NULL
         GROUP BY users.id, users.username
         ORDER BY storage_bytes DESC, video_total DESC, users.id DESC
         LIMIT 6"
    )->fetchAll() ?: [];

    return [
        'summary' => $summary,
        'new_users' => $newUsers,
        'storage_leaders' => $storageLeaders,
    ];
}

function ve_admin_user_operations_snapshot(int $lookbackDays = 14): array
{
    $pdo = ve_db();
    $range = ve_dashboard_normalize_date_range(null, null, $lookbackDays);
    $recentLogins = $pdo->query(
        "SELECT id, username, email, last_login_at
         FROM users
         WHERE deleted_at IS NULL
           AND last_login_at IS NOT NULL
         ORDER BY last_login_at DESC, id DESC
         LIMIT 8"
    )->fetchAll() ?: [];

    $topTrafficStmt = $pdo->prepare(
        'SELECT users.id, users.username,
                COALESCE(SUM(stats.views), 0) AS views_total,
                COALESCE(SUM(stats.bandwidth_bytes), 0) AS bandwidth_total,
                COALESCE(SUM(stats.earned_micro_usd + stats.referral_earned_micro_usd), 0) AS earnings_total
         FROM user_stats_daily stats
         INNER JOIN users ON users.id = stats.user_id
         WHERE stats.stat_date BETWEEN :from_date AND :to_date
           AND users.deleted_at IS NULL
         GROUP BY users.id, users.username
         ORDER BY bandwidth_total DESC, views_total DESC, users.id DESC
         LIMIT 8'
    );
    $topTrafficStmt->execute([
        ':from_date' => $range['from'],
        ':to_date' => $range['to'],
    ]);

    $apiLeaders = $pdo->query(
        "SELECT users.id, users.username, users.api_key_last_used_at,
                COALESCE(us.api_requests_per_hour, 250) AS api_requests_per_hour,
                COALESCE(us.api_requests_per_day, 5000) AS api_requests_per_day
         FROM users
         LEFT JOIN user_settings us ON us.user_id = users.id
         WHERE users.deleted_at IS NULL
         ORDER BY CASE WHEN users.api_key_last_used_at IS NULL THEN 1 ELSE 0 END, users.api_key_last_used_at DESC, users.id DESC
         LIMIT 8"
    )->fetchAll() ?: [];

    return [
        'range' => $range,
        'recent_logins' => $recentLogins,
        'top_traffic' => $topTrafficStmt->fetchAll() ?: [],
        'api_leaders' => $apiLeaders,
    ];
}

function ve_admin_user_detail_nav_html(int $userId, string $activeSubview): string
{
    $items = [
        ['slug' => 'users-profile', 'label' => 'Profile', 'icon' => 'fa-id-card'],
        ['slug' => 'users-activity', 'label' => 'Activity', 'icon' => 'fa-chart-line'],
        ['slug' => 'users-access', 'label' => 'Access & Billing', 'icon' => 'fa-wallet'],
        ['slug' => 'users-related', 'label' => 'Related', 'icon' => 'fa-link'],
    ];
    $html = [];

    foreach ($items as $item) {
        $slug = (string) ($item['slug'] ?? '');
        $activeClass = $activeSubview === $slug ? ' active' : '';
        $html[] = '<a href="' . ve_h(ve_admin_subsection_url($slug, $userId, [], true)) . '" data-admin-nav="1" class="' . $activeClass . '"><i class="fad ' . ve_h((string) ($item['icon'] ?? 'fa-circle')) . '"></i><span>' . ve_h((string) ($item['label'] ?? '')) . '</span></a>';
    }

    return '<div class="admin-subnav">' . implode('', $html) . '</div>';
}

function ve_admin_chart_svg_html(array $points, array $seriesDefinitions): string
{
    $width = 720.0;
    $height = 230.0;
    $paddingLeft = 18.0;
    $paddingRight = 18.0;
    $paddingTop = 16.0;
    $paddingBottom = 30.0;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;
    $maxValue = 0;

    foreach ($seriesDefinitions as $series) {
        $key = (string) ($series['key'] ?? '');

        if ($key === '') {
            continue;
        }

        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $maxValue = max($maxValue, (int) ($point[$key] ?? 0));
        }
    }

    $maxValue = max(1, $maxValue);
    $count = max(1, count($points));
    $stepX = $count > 1 ? $plotWidth / ($count - 1) : 0.0;
    $gridHtml = '';

    for ($i = 0; $i <= 4; $i++) {
        $y = $paddingTop + (($plotHeight / 4) * $i);
        $gridHtml .= '<line x1="' . $paddingLeft . '" y1="' . ve_h((string) $y) . '" x2="' . ($width - $paddingRight) . '" y2="' . ve_h((string) $y) . '" />';
    }

    $seriesHtml = '';
    $hitHtml = '';

    foreach ($seriesDefinitions as $series) {
        $key = (string) ($series['key'] ?? '');

        if ($key === '') {
            continue;
        }

        $stroke = (string) ($series['stroke'] ?? '#ff9900');
        $fill = (string) ($series['fill'] ?? 'none');
        $strokeWidth = (float) ($series['stroke_width'] ?? 2);
        $pointRadius = (float) ($series['point_radius'] ?? 2.5);
        $seriesLabel = (string) ($series['label'] ?? ucwords(str_replace('_', ' ', $key)));
        $valueFormat = (string) ($series['format'] ?? 'number');
        $polylinePoints = [];

        foreach (array_values($points) as $index => $point) {
            $value = is_array($point) ? (int) ($point[$key] ?? 0) : 0;
            $x = $paddingLeft + ($stepX * $index);
            $y = $paddingTop + $plotHeight - (($value / $maxValue) * $plotHeight);
            $polylinePoints[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
        }

        if ($polylinePoints === []) {
            continue;
        }

        if ($fill !== 'none') {
            $fillPoints = $polylinePoints;
            $fillPoints[] = number_format($paddingLeft + ($stepX * (count($polylinePoints) - 1)), 2, '.', '') . ',' . number_format($paddingTop + $plotHeight, 2, '.', '');
            $fillPoints[] = number_format($paddingLeft, 2, '.', '') . ',' . number_format($paddingTop + $plotHeight, 2, '.', '');
            $seriesHtml .= '<polygon points="' . ve_h(implode(' ', $fillPoints)) . '" fill="' . ve_h($fill) . '"></polygon>';
        }

        $seriesHtml .= '<polyline points="' . ve_h(implode(' ', $polylinePoints)) . '" stroke="' . ve_h($stroke) . '" stroke-width="' . ve_h((string) $strokeWidth) . '" fill="none"></polyline>';

        foreach (array_values($points) as $index => $point) {
            $value = is_array($point) ? (int) ($point[$key] ?? 0) : 0;
            $date = is_array($point) ? (string) ($point['date'] ?? '') : '';
            $dateLabel = $date !== '' ? gmdate('M j, Y', strtotime($date . ' 00:00:00 UTC')) : 'Unknown date';
            $x = $paddingLeft + ($stepX * $index);
            $y = $paddingTop + $plotHeight - (($value / $maxValue) * $plotHeight);
            $seriesHtml .= '<circle cx="' . ve_h((string) $x) . '" cy="' . ve_h((string) $y) . '" r="' . ve_h((string) $pointRadius) . '" fill="' . ve_h($stroke) . '"></circle>';
            $hitHtml .= '<circle class="admin-chart-hit" cx="' . ve_h((string) $x) . '" cy="' . ve_h((string) $y) . '" r="11" fill="transparent" data-series-label="' . ve_h($seriesLabel) . '" data-date-label="' . ve_h($dateLabel) . '" data-value-label="' . ve_h(ve_admin_chart_value_label($value, $valueFormat)) . '"></circle>';
        }
    }

    $labels = '';
    $labelIndexes = array_values(array_unique([0, (int) floor(($count - 1) / 2), max(0, $count - 1)]));

    foreach ($labelIndexes as $index) {
        if (!isset($points[$index]) || !is_array($points[$index])) {
            continue;
        }

        $date = (string) ($points[$index]['date'] ?? '');
        $label = $date !== '' ? gmdate('M j', strtotime($date . ' 00:00:00 UTC')) : '';
        $x = $paddingLeft + ($stepX * $index);
        $labels .= '<text x="' . ve_h((string) $x) . '" y="' . ($height - 8) . '" text-anchor="middle">' . ve_h($label) . '</text>';
    }

    return <<<HTML
<div class="admin-chart-frame">
    <svg class="admin-chart-svg" viewBox="0 0 720 230" role="img" aria-hidden="true">
        <g class="admin-chart-grid">{$gridHtml}</g>
        <g class="admin-chart-series">{$seriesHtml}</g>
        <g class="admin-chart-hits">{$hitHtml}</g>
        <g class="admin-chart-labels">{$labels}</g>
    </svg>
    <div class="admin-chart-tooltip" hidden></div>
</div>
HTML;
}

function ve_admin_recent_user_sessions(int $userId, int $limit = 8): array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM user_sessions
         WHERE user_id = :user_id
         ORDER BY last_seen_at DESC, created_at DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll() ?: [];
}

function ve_admin_recent_balance_ledger_entries(int $userId, int $limit = 8): array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM account_balance_ledger
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll() ?: [];
}

function ve_admin_recent_user_audit_events(int $userId, int $limit = 8): array
{
    $stmt = ve_db()->prepare(
        'SELECT audit_logs.*, users.username AS actor_username
         FROM audit_logs
         LEFT JOIN users ON users.id = audit_logs.actor_user_id
         WHERE audit_logs.actor_user_id = :user_id
            OR (audit_logs.target_type = "user" AND audit_logs.target_id = :user_id)
         ORDER BY audit_logs.created_at DESC, audit_logs.id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll() ?: [];
}

function ve_admin_user_profile_snapshot(int $userId, int $lookbackDays = 14): ?array
{
    $detail = ve_admin_user_detail($userId);

    if (!is_array($detail)) {
        return null;
    }

    $reports = ve_dashboard_reports_snapshot($userId, null, null);
    $chart = (array) ($reports['chart'] ?? []);
    $settings = (array) ($detail['settings'] ?? []);
    $premiumBandwidth = ve_premium_bandwidth_totals($userId);
    $premiumState = ve_premium_bandwidth_feature_state($userId, $settings, $premiumBandwidth);
    $apiUsage = ve_api_usage_snapshot($userId);
    $topFiles = ve_dashboard_top_files(
        $userId,
        (string) (($reports['range']['from'] ?? gmdate('Y-m-d'))),
        (string) (($reports['range']['to'] ?? gmdate('Y-m-d'))),
        5
    );

    $countsStmt = ve_db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM videos WHERE user_id = :user_id AND deleted_at IS NULL) AS videos_total,
            (SELECT COUNT(*) FROM remote_uploads WHERE user_id = :user_id AND deleted_at IS NULL) AS remote_total,
            (SELECT COUNT(*) FROM payout_requests WHERE user_id = :user_id) AS payout_total,
            (SELECT COUNT(*) FROM payout_requests WHERE user_id = :user_id AND status IN ("pending", "approved")) AS payout_open_total,
            (SELECT COUNT(*) FROM dmca_notices WHERE user_id = :user_id) AS dmca_total,
            (SELECT COUNT(*) FROM custom_domains WHERE user_id = :user_id) AS domain_total,
            (SELECT COUNT(*) FROM custom_domains WHERE user_id = :user_id AND status = "active") AS active_domain_total'
    );
    $countsStmt->execute([':user_id' => $userId]);
    $counts = (array) ($countsStmt->fetch() ?: []);

    return [
        'detail' => $detail,
        'reports' => $reports,
        'chart' => $chart,
        'settings' => $settings,
        'premium_bandwidth' => $premiumBandwidth,
        'premium_state' => $premiumState,
        'api_usage' => $apiUsage,
        'top_files' => $topFiles,
        'storage_bytes' => ve_dashboard_storage_bytes($userId),
        'online_watchers' => ve_dashboard_online_watchers($userId),
        'recent_sessions' => ve_admin_recent_user_sessions($userId),
        'ledger_entries' => ve_admin_recent_balance_ledger_entries($userId),
        'audit_events' => ve_admin_recent_user_audit_events($userId),
        'counts' => $counts,
        'lookback_days' => $lookbackDays,
    ];
}

function ve_admin_custom_domain_detail(int $domainId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT custom_domains.*, users.username, users.email
         FROM custom_domains
         INNER JOIN users ON users.id = custom_domains.user_id
         WHERE custom_domains.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $domainId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_audit_log_detail(int $logId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT audit_logs.*, users.username AS actor_username
         FROM audit_logs
         LEFT JOIN users ON users.id = audit_logs.actor_user_id
         WHERE audit_logs.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $logId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_payout_transfer_by_request_id(int $requestId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT *
         FROM payout_transfers
         WHERE payout_request_id = :payout_request_id
         LIMIT 1'
    );
    $stmt->execute([':payout_request_id' => $requestId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function ve_admin_audit_target_url(array $row): string
{
    $targetId = (int) ($row['target_id'] ?? 0);

    if ($targetId <= 0) {
        return '';
    }

    return match ((string) ($row['target_type'] ?? '')) {
        'user' => ve_admin_url(['section' => 'users', 'resource' => (string) $targetId], false),
        'video' => ve_admin_url(['section' => 'videos', 'resource' => (string) $targetId], false),
        'remote_upload' => ve_admin_url(['section' => 'remote-uploads', 'resource' => (string) $targetId], false),
        'dmca_notice' => ve_admin_url(['section' => 'dmca', 'resource' => (string) $targetId], false),
        'payout_request' => ve_admin_url(['section' => 'payouts', 'resource' => (string) $targetId], false),
        'custom_domain' => ve_admin_url(['section' => 'domains', 'resource' => (string) $targetId], false),
        default => '',
    };
}

function ve_admin_render_overview_section_deep(): string
{
    $snapshot = ve_admin_overview_snapshot();
    $activeSubview = ve_admin_current_subview_slug('overview');
    $rangeDays = ve_admin_request_range_days();
    $trend = ve_admin_service_trend_snapshot($rangeDays);
    $points = (array) ($trend['points'] ?? []);
    $range = (array) ($trend['range'] ?? []);
    $rangeLabel = ve_h(
        gmdate('M j', strtotime(((string) ($range['from'] ?? gmdate('Y-m-d'))) . ' 00:00:00 UTC'))
        . ' to '
        . gmdate('M j', strtotime(((string) ($range['to'] ?? gmdate('Y-m-d'))) . ' 00:00:00 UTC'))
    );
    $storageLabel = ve_h(ve_human_bytes((int) ($snapshot['videos']['storage_bytes'] ?? 0)));
    $trafficLabel = ve_h(ve_human_bytes((int) ($trend['traffic_total_bytes'] ?? 0)));
    $premiumTrafficLabel = ve_h(ve_human_bytes((int) ($trend['premium_traffic_total_bytes'] ?? 0)));
    $revenueLabel = ve_h(ve_dashboard_format_currency_micro_usd((int) ($trend['earned_total_micro_usd'] ?? 0)));
    $payoutDemandLabel = ve_h(ve_dashboard_format_currency_micro_usd((int) ($trend['payout_amount_total_micro_usd'] ?? 0)));
    $uploadedBytesLabel = ve_h(ve_human_bytes((int) ($trend['uploaded_bytes_total'] ?? 0)));
    $rangeDaysLabel = ve_h((string) $rangeDays);
    $periodSwitchHtml = ve_admin_period_switch_html([7, 14, 30, 90], $rangeDays, $activeSubview);
    $userChart = ve_admin_chart_svg_html($points, [
        ['key' => 'new_users', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'New users', 'format' => 'number'],
        ['key' => 'active_users', 'stroke' => '#d6d6d6', 'label' => 'Active users', 'format' => 'number'],
    ]);
    $usageChart = ve_admin_chart_svg_html($points, [
        ['key' => 'views', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Views', 'format' => 'number'],
        ['key' => 'uploads', 'stroke' => '#8f8f8f', 'label' => 'Uploads', 'format' => 'number'],
    ]);
    $trafficChart = ve_admin_chart_svg_html($points, [
        ['key' => 'bandwidth_bytes', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Traffic', 'format' => 'bytes'],
        ['key' => 'premium_bandwidth_bytes', 'stroke' => '#8ad0ff', 'label' => 'Premium traffic', 'format' => 'bytes'],
    ]);
    $avgNewUsers = ve_h(ve_admin_number_label(ve_admin_series_average($points, 'new_users'), 1));
    $peakActiveUsers = ve_h((string) ve_admin_series_peak($points, 'active_users'));
    $avgViews = ve_h(ve_admin_number_label(ve_admin_series_average($points, 'views'), 0));
    $uploadsTotal = ve_h((string) ve_admin_series_total($points, 'uploads'));
    $trafficPeakLabel = ve_h(ve_human_bytes((int) ($trend['traffic_peak_bytes'] ?? 0)));

    $serviceTotalsHtml = '<div class="admin-group-grid">'
        . ve_admin_group_card_html('Accounts', 'Current account mix and operator-facing demand.', [
            ['label' => 'Total accounts', 'value' => (string) ($snapshot['users']['total_users'] ?? 0)],
            ['label' => 'Active accounts', 'value' => (string) ($snapshot['users']['active_users'] ?? 0)],
            ['label' => 'Suspended accounts', 'value' => (string) ($snapshot['users']['suspended_users'] ?? 0)],
            ['label' => 'Created today', 'value' => (string) ($snapshot['users']['users_today'] ?? 0)],
        ])
        . ve_admin_group_card_html('Content & Storage', 'What the library currently holds and what entered the system during the window.', [
            ['label' => 'Stored files', 'value' => (string) ($snapshot['videos']['total_videos'] ?? 0)],
            ['label' => 'Ready files', 'value' => (string) ($snapshot['videos']['ready_videos'] ?? 0)],
            ['label' => 'Stored media', 'value' => $storageLabel],
            ['label' => 'Uploaded in window', 'value' => $uploadedBytesLabel],
        ])
        . ve_admin_group_card_html('Traffic & Delivery', 'Delivery footprint, current demand, and the last-period traffic load.', [
            ['label' => 'Views served', 'value' => (string) ($trend['views_total'] ?? 0)],
            ['label' => 'Traffic served', 'value' => $trafficLabel],
            ['label' => 'Premium traffic', 'value' => $premiumTrafficLabel],
            ['label' => 'Live watchers', 'value' => (string) ($trend['live_watchers'] ?? 0)],
        ])
        . ve_admin_group_card_html('Revenue & Risk', 'Money movement and the queues that can slow support or delivery.', [
            ['label' => 'Revenue generated', 'value' => $revenueLabel],
            ['label' => 'Payout demand', 'value' => $payoutDemandLabel],
            ['label' => 'Open payouts', 'value' => (string) ($snapshot['payouts']['open_payouts'] ?? 0)],
            ['label' => 'Open DMCA', 'value' => (string) ($snapshot['dmca']['open_notices'] ?? 0)],
        ])
        . ve_admin_group_card_html('Infrastructure', 'Capacity endpoints backing uploads and stream delivery right now.', [
            ['label' => 'Storage nodes', 'value' => (string) ($snapshot['infrastructure']['storage_nodes'] ?? 0)],
            ['label' => 'Upload endpoints', 'value' => (string) ($snapshot['infrastructure']['active_upload_endpoints'] ?? 0)],
            ['label' => 'Delivery domains', 'value' => (string) ($snapshot['infrastructure']['active_delivery_domains'] ?? 0)],
            ['label' => 'Active sessions', 'value' => (string) ($trend['active_sessions'] ?? 0)],
        ])
        . '</div>';
    $panels = [
        'overview-service' => <<<HTML
<div class="admin-subsection" id="overview-service">
    <div class="mb-4">
        <h5 class="mb-1">Service totals</h5>
        <p class="admin-chart-copy mb-0">The stable state of the platform right now, grouped by operator concern instead of mixing totals and period-based movement together.</p>
    </div>
    {$serviceTotalsHtml}
    <div class="admin-chart-card mb-0">
        <div class="admin-actions justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">Time-window trends</h5>
                <p class="admin-chart-copy mb-0">Rolling activity across {$rangeLabel}. Switch the range without leaving the overview.</p>
            </div>
            {$periodSwitchHtml}
        </div>
        <div class="admin-chart-grid-layout">
            <div>
                <h5 class="mb-2">Daily users</h5>
                {$userChart}
            </div>
            <div>
                <h5 class="mb-2">Daily traffic</h5>
                {$trafficChart}
            </div>
        </div>
    </div>
</div>
HTML,
        'overview-users' => <<<HTML
<div class="admin-chart-card" id="overview-users">
    <div class="admin-actions justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Daily users</h5>
            <p class="admin-chart-copy mb-0">Account creation and active upload or earning behavior across {$rangeLabel}.</p>
        </div>
        {$periodSwitchHtml}
    </div>
    {$userChart}
    <div class="admin-chart-legend">
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch"></i>New users</span><strong>{$avgNewUsers} avg/day</strong></div>
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch" style="background:#d6d6d6;"></i>Active users</span><strong>{$peakActiveUsers} peak/day</strong></div>
    </div>
</div>
HTML,
        'overview-usage' => <<<HTML
<div class="admin-chart-card" id="overview-usage">
    <div class="admin-actions justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Daily usage</h5>
            <p class="admin-chart-copy mb-0">Views served and files added to the library over {$rangeLabel}.</p>
        </div>
        {$periodSwitchHtml}
    </div>
    {$usageChart}
    <div class="admin-chart-legend">
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch"></i>Views</span><strong>{$avgViews} avg/day</strong></div>
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch" style="background:#8f8f8f;"></i>Uploads</span><strong>{$uploadsTotal} total</strong></div>
    </div>
</div>
HTML,
        'overview-traffic' => <<<HTML
<div class="admin-chart-card" id="overview-traffic">
    <div class="admin-actions justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Daily traffic</h5>
            <p class="admin-chart-copy mb-0">Delivered traffic, including premium-served bandwidth, across {$rangeLabel}.</p>
        </div>
        {$periodSwitchHtml}
    </div>
    {$trafficChart}
    <div class="admin-chart-legend">
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch"></i>Total traffic</span><strong>{$trafficLabel}</strong></div>
        <div class="admin-chart-legend-item"><span><i class="admin-chart-swatch" style="background:#8ad0ff;"></i>Premium traffic</span><strong>{$premiumTrafficLabel}</strong></div>
    </div>
</div>
HTML,
    ];
    $panelHtml = ve_admin_active_subsection_html($activeSubview, $panels, 'overview-service');

    return <<<HTML
<div class="data settings-panel active" id="overview">
    <div class="settings-panel-title">Service overview</div>
    <p class="settings-panel-subtitle">Platform-wide service numbers, traffic totals, and daily trend panels that can be switched without leaving the backend shell.</p>
    {$panelHtml}
</div>
HTML;
}

function ve_admin_render_users_section_deep(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $roleCode = trim((string) ($_GET['role'] ?? ''));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('users');
    $activeDetailSubview = match ($activeSubview) {
        'users-charts' => 'users-activity',
        'users-sessions', 'users-billing' => 'users-access',
        default => $activeSubview,
    };
    $selectedUserId = ve_admin_current_resource_id();
    $list = ve_admin_list_users($query, $status, $roleCode, $page);
    $profile = $selectedUserId > 0 ? ve_admin_user_profile_snapshot($selectedUserId) : null;
    $detail = is_array($profile) ? (array) ($profile['detail'] ?? []) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'users', 'resource' => null, 'page' => null], false));
    $directoryUrl = ve_h(ve_admin_subsection_url('users-directory'));
    $segmentsUrl = ve_h(ve_admin_subsection_url('users-segments'));
    $operationsUrl = ve_h(ve_admin_subsection_url('users-operations'));
    $statusOptions = ve_admin_select_options_html([
        'active' => 'Active',
        'suspended' => 'Suspended',
    ], $status, true);
    $roleOptions = ['admin' => 'Admin', 'super_admin' => 'Super Admin'];
    $roleFilterOptions = ve_admin_select_options_html($roleOptions, $roleCode, true);
    $rowsHtml = '';
    $activeCount = 0;
    $suspendedCount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedUserId === $userId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) $userId], true));
        $statusCode = (string) ($row['status'] ?? 'active');

        if ($statusCode === 'active') {
            $activeCount++;
        } elseif ($statusCode === 'suspended') {
            $suspendedCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">#' . $userId . '</a></td>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['username'] ?? '')) . '</a><br><small>' . ve_h((string) ($row['email'] ?? '')) . '</small></td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h(ve_admin_role_label((string) ($row['role_code'] ?? ''))) . '</td>'
            . '<td>' . ve_h((string) ($row['plan_code'] ?? 'free')) . '</td>'
            . '<td>' . ve_h((string) ($row['video_count'] ?? 0)) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($row['storage_bytes'] ?? 0))) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['last_login_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(8, 'No users matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible users', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Active in result', 'value' => (string) $activeCount],
        ['label' => 'Suspended in result', 'value' => (string) $suspendedCount],
        ['label' => 'Page', 'value' => (string) (int) ($list['page'] ?? 1), 'meta' => 'Page size ' . (string) (int) ($list['page_size'] ?? ve_admin_page_size())],
    ]);
    $detailHtml = '';
    $segmentsSnapshot = ve_admin_user_segments_snapshot();
    $segmentsSummary = (array) ($segmentsSnapshot['summary'] ?? []);
    $newUsersList = '';
    $storageLeadersList = '';

    foreach ((array) ($segmentsSnapshot['new_users'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $newUsersList .= '<li><a href="' . ve_h(ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a><small>'
            . ve_h((string) ($row['email'] ?? '')) . ' / ' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</small></li>';
    }

    foreach ((array) ($segmentsSnapshot['storage_leaders'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $storageLeadersList .= '<li><a href="' . ve_h(ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a><small>'
            . ve_h((string) ($row['video_total'] ?? 0)) . ' files / ' . ve_h(ve_human_bytes((int) ($row['storage_bytes'] ?? 0))) . '</small></li>';
    }

    if ($newUsersList === '') {
        $newUsersList = '<li class="text-muted">No recent signups.</li>';
    }

    if ($storageLeadersList === '') {
        $storageLeadersList = '<li class="text-muted">No uploader footprint yet.</li>';
    }

    $segmentsHtml = '<div class="admin-subsection" id="users-segments">'
        . '<div class="mb-4"><h5 class="mb-1">User segments</h5><p class="admin-chart-copy mb-0">Stable slices of the account base that help you understand who is using the service, who is monetizing it, and where support load is likely to appear.</p></div>'
        . '<div class="admin-group-grid">'
        . ve_admin_group_card_html('Account state', 'Core account health and moderation load.', [
            ['label' => 'Total accounts', 'value' => (string) ($segmentsSummary['total_users'] ?? 0)],
            ['label' => 'Active accounts', 'value' => (string) ($segmentsSummary['active_users'] ?? 0)],
            ['label' => 'Suspended accounts', 'value' => (string) ($segmentsSummary['suspended_users'] ?? 0)],
            ['label' => 'Joined last 30 days', 'value' => (string) ($segmentsSummary['new_last_30_days'] ?? 0)],
        ])
        . ve_admin_group_card_html('Commercial footprint', 'Accounts already paying, branded, or likely to need billing support.', [
            ['label' => 'Paid-plan users', 'value' => (string) ($segmentsSummary['paid_plan_users'] ?? 0)],
            ['label' => 'Premium-active users', 'value' => (string) ($segmentsSummary['premium_users'] ?? 0)],
            ['label' => 'API-enabled users', 'value' => (string) ($segmentsSummary['api_enabled_users'] ?? 0)],
            ['label' => 'Branded domain users', 'value' => (string) ($segmentsSummary['branded_users'] ?? 0)],
        ])
        . ve_admin_group_card_html('Uploader density', 'How concentrated content creation is across the account base.', [
            ['label' => '25+ file libraries', 'value' => (string) ($segmentsSummary['library_users'] ?? 0)],
            ['label' => 'Visible result set', 'value' => (string) (int) ($list['total'] ?? 0)],
            ['label' => 'Active in current filter', 'value' => (string) $activeCount],
            ['label' => 'Suspended in current filter', 'value' => (string) $suspendedCount],
        ])
        . '</div>'
        . '<div class="admin-detail-panels"><div class="admin-detail-panel"><h5>Newest accounts</h5><ul class="admin-mini-list admin-list-tight">' . $newUsersList . '</ul></div>'
        . '<div class="admin-detail-panel"><h5>Largest libraries</h5><ul class="admin-mini-list admin-list-tight">' . $storageLeadersList . '</ul></div></div>'
        . '</div>';

    $operationsDays = ve_admin_request_range_days(30);
    $operationsSnapshot = ve_admin_user_operations_snapshot($operationsDays);
    $operationsRangeSwitch = ve_admin_period_switch_html([7, 14, 30, 90], $operationsDays, 'users-operations');
    $recentLoginRows = '';
    $topTrafficRows = '';
    $apiLeaderRows = '';

    foreach ((array) ($operationsSnapshot['recent_logins'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $recentLoginRows .= '<tr><td><a href="' . ve_h(ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a><br><small>' . ve_h((string) ($row['email'] ?? '')) . '</small></td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['last_login_at'] ?? ''))) . '</td></tr>';
    }

    foreach ((array) ($operationsSnapshot['top_traffic'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $topTrafficRows .= '<tr><td><a href="' . ve_h(ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td>' . ve_h((string) ($row['views_total'] ?? 0)) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($row['bandwidth_total'] ?? 0))) . '</td>'
            . '<td>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($row['earnings_total'] ?? 0))) . '</td></tr>';
    }

    foreach ((array) ($operationsSnapshot['api_leaders'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $apiLeaderRows .= '<tr><td><a href="' . ve_h(ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['api_key_last_used_at'] ?? ''), 'Never')) . '</td>'
            . '<td>' . ve_h((string) ($row['api_requests_per_hour'] ?? 250)) . '/hr</td>'
            . '<td>' . ve_h((string) ($row['api_requests_per_day'] ?? 5000)) . '/day</td></tr>';
    }

    if ($recentLoginRows === '') {
        $recentLoginRows = ve_admin_empty_table_row_html(2, 'No recent logins recorded.');
    }

    if ($topTrafficRows === '') {
        $topTrafficRows = ve_admin_empty_table_row_html(4, 'No user traffic recorded in this window.');
    }

    if ($apiLeaderRows === '') {
        $apiLeaderRows = ve_admin_empty_table_row_html(4, 'No API activity recorded yet.');
    }

    $operationsHtml = '<div class="admin-subsection" id="users-operations">'
        . '<div class="admin-actions justify-content-between align-items-start mb-3"><div><h5 class="mb-1">User operations</h5><p class="admin-chart-copy mb-0">Recent account movement, high-traffic operators, and API-heavy accounts across the last ' . ve_h((string) $operationsDays) . ' days.</p></div>' . $operationsRangeSwitch . '</div>'
        . '<div class="admin-detail-panels">'
        . '<div class="admin-detail-panel"><h5>Recent logins</h5><div class="settings-table-wrap"><table class="table"><thead><tr><th>User</th><th>Last login</th></tr></thead><tbody>' . $recentLoginRows . '</tbody></table></div></div>'
        . '<div class="admin-detail-panel"><h5>Top traffic accounts</h5><div class="settings-table-wrap"><table class="table"><thead><tr><th>User</th><th>Views</th><th>Traffic</th><th>Earnings</th></tr></thead><tbody>' . $topTrafficRows . '</tbody></table></div></div>'
        . '</div>'
        . '<div class="admin-detail-panel"><h5>API access leaders</h5><div class="settings-table-wrap"><table class="table"><thead><tr><th>User</th><th>Last used</th><th>Hourly limit</th><th>Daily limit</th></tr></thead><tbody>' . $apiLeaderRows . '</tbody></table></div></div>'
        . '</div>';

    if ($selectedUserId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected user could not be found.</div>';
    } elseif (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $listReturn = ve_admin_return_to_hidden_html(ve_admin_url(['section' => 'users', 'resource' => null], false));
        $saveReturn = ve_admin_return_to_input_html();
        $roleSelectOptions = '<option value="">User</option>' . ve_admin_select_options_html($roleOptions, (string) ($detail['primary_role_code'] ?? ''));
        $statusActiveSelected = (string) ($detail['status'] ?? '') === 'active' ? ' selected="selected"' : '';
        $statusSuspendedSelected = (string) ($detail['status'] ?? '') === 'suspended' ? ' selected="selected"' : '';
        $apiEnabledChecked = (int) ($detail['settings']['api_enabled'] ?? 1) === 1 ? ' checked="checked"' : '';
        $paymentMethodCatalog = ve_allowed_payment_methods();
        $paymentMethodOptions = ve_admin_select_options_html(array_combine($paymentMethodCatalog, $paymentMethodCatalog), (string) ($detail['settings']['payment_method'] ?? 'Webmoney'));
        $detailUsername = ve_h((string) ($detail['username'] ?? ''));
        $detailBalance = ve_h(ve_dashboard_format_currency_micro_usd((int) ($detail['balance_micro_usd'] ?? 0)));
        $detailPlan = ve_h((string) ($detail['plan_code'] ?? 'free'));
        $detailLastLogin = ve_h(ve_format_datetime_label((string) ($detail['last_login_at'] ?? '')));
        $detailCreated = ve_h(ve_format_datetime_label((string) ($detail['created_at'] ?? '')));
        $detailRole = ve_h(ve_admin_role_label((string) ($detail['primary_role_code'] ?? '')));
        $premiumUntilValue = ve_h((string) ($detail['premium_until'] ?? ''));
        $paymentDestinationValue = ve_h((string) ($detail['settings']['payment_id'] ?? ''));
        $planCodeValue = ve_h((string) ($detail['plan_code'] ?? 'free'));
        $reports = is_array($profile) ? (array) ($profile['reports'] ?? []) : [];
        $chart = is_array($profile) ? (array) ($profile['chart'] ?? []) : [];
        $apiUsage = is_array($profile) ? (array) ($profile['api_usage'] ?? []) : [];
        $counts = is_array($profile) ? (array) ($profile['counts'] ?? []) : [];
        $premiumState = is_array($profile) ? (array) ($profile['premium_state'] ?? []) : [];
        $premiumBandwidth = is_array($profile) ? (array) ($profile['premium_bandwidth'] ?? []) : [];
        $viewsChart = ve_admin_chart_svg_html($chart, [
            ['key' => 'views', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Views', 'format' => 'number'],
        ]);
        $trafficChart = ve_admin_chart_svg_html($chart, [
            ['key' => 'bandwidth_bytes', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Traffic', 'format' => 'bytes'],
        ]);
        $revenueChart = ve_admin_chart_svg_html($chart, [
            ['key' => 'total_profit_micro_usd', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Revenue', 'format' => 'currency'],
        ]);
        $sessionRows = '';
        $ledgerRows = '';
        $auditList = '';
        $topFilesList = '';
        $videoList = '';
        $remoteList = '';
        $dmcaList = '';
        $payoutList = '';
        $domainList = '';

        foreach ((array) ($detail['recent_videos'] ?? []) as $video) {
            if (!is_array($video)) {
                continue;
            }

            $videoUrl = ve_h(ve_admin_url(['section' => 'videos', 'resource' => (string) (int) ($video['id'] ?? 0)], false));
            $videoList .= '<li><a href="' . $videoUrl . '">' . ve_h((string) ($video['title'] ?? 'Untitled')) . '</a><small>'
                . ve_h((string) ($video['public_id'] ?? '')) . ' / ' . ve_h((string) ($video['status'] ?? '')) . '</small></li>';
        }

        foreach ((array) ($detail['recent_remote_uploads'] ?? []) as $job) {
            if (!is_array($job)) {
                continue;
            }

            $jobUrl = ve_h(ve_admin_url(['section' => 'remote-uploads', 'resource' => (string) (int) ($job['id'] ?? 0)], false));
            $remoteList .= '<li><a href="' . $jobUrl . '">Remote job #' . (int) ($job['id'] ?? 0) . '</a><small>'
                . ve_h((string) ($job['status'] ?? 'pending')) . ' / ' . ve_h((string) ($job['source_url'] ?? '')) . '</small></li>';
        }

        foreach ((array) ($detail['recent_dmca'] ?? []) as $notice) {
            if (!is_array($notice)) {
                continue;
            }

            $noticeUrl = ve_h(ve_admin_url(['section' => 'dmca', 'resource' => (string) (int) ($notice['id'] ?? 0)], false));
            $dmcaList .= '<li><a href="' . $noticeUrl . '">' . ve_h((string) ($notice['case_code'] ?? '')) . '</a><small>'
                . ve_h((string) ($notice['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW)) . ' / ' . ve_h(ve_format_datetime_label((string) ($notice['received_at'] ?? ''))) . '</small></li>';
        }

        foreach ((array) ($detail['recent_payouts'] ?? []) as $payout) {
            if (!is_array($payout)) {
                continue;
            }

            $payoutUrl = ve_h(ve_admin_url(['section' => 'payouts', 'resource' => (string) (int) ($payout['id'] ?? 0)], false));
            $payoutList .= '<li><a href="' . $payoutUrl . '">' . ve_h((string) ($payout['public_id'] ?? '')) . '</a><small>'
                . ve_h(ve_dashboard_format_currency_micro_usd((int) ($payout['amount_micro_usd'] ?? 0))) . ' / ' . ve_h((string) ($payout['status'] ?? 'pending')) . '</small></li>';
        }

        foreach ((array) ($detail['custom_domains'] ?? []) as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $domainUrl = ve_h(ve_admin_url(['section' => 'domains', 'resource' => (string) (int) ($domain['id'] ?? 0)], false));
            $domainList .= '<li><a href="' . $domainUrl . '">' . ve_h((string) ($domain['domain'] ?? '')) . '</a><small>'
                . ve_h((string) ($domain['status'] ?? 'pending_dns')) . '</small></li>';
        }

        foreach ((array) ($profile['recent_sessions'] ?? []) as $session) {
            if (!is_array($session)) {
                continue;
            }

            $sessionRows .= '<tr>'
                . '<td>' . ve_h(ve_format_datetime_label((string) ($session['last_seen_at'] ?? ''))) . '</td>'
                . '<td>' . ve_h((string) ($session['ip_address'] ?? '')) . '</td>'
                . '<td>' . ve_h((string) ($session['user_agent'] ?? 'Unknown device')) . '</td>'
                . '<td>' . ve_h(ve_format_datetime_label((string) ($session['created_at'] ?? ''))) . '</td>'
                . '</tr>';
        }

        foreach ((array) ($profile['ledger_entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $ledgerRows .= '<tr>'
                . '<td>' . ve_h(ve_format_datetime_label((string) ($entry['created_at'] ?? ''))) . '</td>'
                . '<td>' . ve_h((string) ($entry['entry_type'] ?? '')) . '</td>'
                . '<td>' . ve_h((string) ($entry['source_type'] ?? '')) . '</td>'
                . '<td>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($entry['amount_micro_usd'] ?? 0))) . '</td>'
                . '<td>' . ve_h((string) ($entry['description'] ?? '')) . '</td>'
                . '</tr>';
        }

        foreach ((array) ($profile['audit_events'] ?? []) as $audit) {
            if (!is_array($audit)) {
                continue;
            }

            $auditUrl = ve_h(ve_admin_url(['section' => 'audit', 'resource' => (string) (int) ($audit['id'] ?? 0)], false));
            $auditList .= '<li><a href="' . $auditUrl . '">' . ve_h((string) ($audit['event_code'] ?? '')) . '</a><small>'
                . ve_h(ve_format_datetime_label((string) ($audit['created_at'] ?? ''))) . ' / ' . ve_h((string) ($audit['actor_username'] ?? 'System')) . '</small></li>';
        }

        foreach ((array) ($profile['top_files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }

            $topFilesList .= '<li><strong>' . ve_h((string) ($file['title'] ?? 'Untitled')) . '</strong><small>'
                . ve_h((string) ($file['views'] ?? 0)) . ' views / ' . ve_h((string) ($file['bandwidth'] ?? '0 B')) . '</small></li>';
        }

        if ($videoList === '') {
            $videoList = '<li class="text-muted">No recent files.</li>';
        }

        if ($remoteList === '') {
            $remoteList = '<li class="text-muted">No remote uploads.</li>';
        }

        if ($dmcaList === '') {
            $dmcaList = '<li class="text-muted">No DMCA cases.</li>';
        }

        if ($payoutList === '') {
            $payoutList = '<li class="text-muted">No payout history.</li>';
        }

        if ($domainList === '') {
            $domainList = '<li class="text-muted">No custom domains.</li>';
        }

        if ($sessionRows === '') {
            $sessionRows = ve_admin_empty_table_row_html(4, 'No tracked sessions for this account yet.');
        }

        if ($ledgerRows === '') {
            $ledgerRows = ve_admin_empty_table_row_html(5, 'No balance ledger entries found.');
        }

        if ($auditList === '') {
            $auditList = '<li class="text-muted">No audit events found.</li>';
        }

        if ($topFilesList === '') {
            $topFilesList = '<li class="text-muted">No top files yet.</li>';
        }

        $searchSimilarUrl = ve_h(ve_admin_url(['section' => 'users', 'resource' => null, 'q' => (string) ($detail['username'] ?? '')], false));
        $dashboardUrl = ve_h(ve_url('/dashboard'));
        $paymentMethodLabel = ve_h((string) ($detail['settings']['payment_method'] ?? 'Not configured'));
        $maskedDestinationLabel = ve_h(ve_admin_mask_payout_destination((string) ($detail['settings']['payment_id'] ?? '')) ?: 'Not configured');
        $apiLastUsedLabel = ve_h((string) (($apiUsage['usage']['last_used_at'] ?? 'Never used')));
        $apiRequestsTodayLabel = ve_h((string) (($apiUsage['usage']['requests_today'] ?? 0)));
        $apiRequestsHourLabel = ve_h((string) (($apiUsage['usage']['requests_last_hour'] ?? 0)));
        $premiumStatusLabel = ve_h((string) ($premiumState['status_label'] ?? 'Inactive'));
        $premiumBandwidthLabel = ve_h(ve_human_bytes((int) ($premiumBandwidth['available_bytes'] ?? 0)));
        $storageLabel = ve_h(ve_human_bytes((int) ($profile['storage_bytes'] ?? 0)));
        $viewsTotalLabel = ve_h((string) ($reports['totals']['views'] ?? 0));
        $trafficTotalLabel = ve_h((string) ($reports['totals']['traffic'] ?? '0 B'));
        $revenueTotalLabel = ve_h((string) ($reports['totals']['total'] ?? '$0.00000'));
        $directRevenueLabel = ve_h((string) ($reports['totals']['profit'] ?? '$0.00000'));
        $referralRevenueLabel = ve_h((string) ($reports['totals']['referral_share'] ?? '$0.00000'));
        $detailEmail = ve_h((string) ($detail['email'] ?? ''));
        $statusLabel = ve_h((string) ($detail['status'] ?? 'active'));
        $apiStatusLabel = ve_h((int) ($detail['settings']['api_enabled'] ?? 1) === 1 ? 'enabled' : 'disabled');
        $premiumUntilLabel = ve_h(ve_format_datetime_label((string) ($detail['premium_until'] ?? ''), 'No expiry set'));
        $videosTotalLabel = ve_h((string) ($counts['videos_total'] ?? 0));
        $remoteTotalLabel = ve_h((string) ($counts['remote_total'] ?? 0));
        $dmcaTotalLabel = ve_h((string) ($counts['dmca_total'] ?? 0));
        $activeDomainTotalLabel = ve_h((string) ($counts['active_domain_total'] ?? 0));
        $domainTotalLabel = ve_h((string) ($counts['domain_total'] ?? 0));
        $payoutOpenTotalLabel = ve_h((string) ($counts['payout_open_total'] ?? 0));
        $payoutTotalLabel = ve_h((string) ($counts['payout_total'] ?? 0));
        $liveViewersLabel = ve_h((string) ($profile['online_watchers'] ?? 0));
        $sessionCountLabel = ve_h((string) count((array) ($profile['recent_sessions'] ?? [])));
        $detailNavHtml = ve_admin_user_detail_nav_html($detailId, $activeDetailSubview);
        $profileHtml = <<<HTML
<div class="admin-subsection" id="users-profile">
    <div class="admin-profile-head">
        <div class="admin-profile-card admin-profile-identity">
            <h3>{$detailUsername}</h3>
            <p>{$detailEmail}</p>
            <div class="admin-pill-row">
                <div class="admin-pill">{$statusLabel}</div>
                <div class="admin-pill">{$detailRole}</div>
                <div class="admin-pill">Plan {$detailPlan}</div>
                <div class="admin-pill">Premium {$premiumStatusLabel}</div>
                <div class="admin-pill">API {$apiStatusLabel}</div>
            </div>
            <div class="admin-meta-grid mb-4">
                <div class="admin-meta-item"><span>User ID</span><strong>#{$detailId}</strong></div>
                <div class="admin-meta-item"><span>Created</span><strong>{$detailCreated}</strong></div>
                <div class="admin-meta-item"><span>Last login</span><strong>{$detailLastLogin}</strong></div>
                <div class="admin-meta-item"><span>Balance</span><strong>{$detailBalance}</strong></div>
                <div class="admin-meta-item"><span>Payout method</span><strong>{$paymentMethodLabel}</strong></div>
                <div class="admin-meta-item"><span>Payout destination</span><strong>{$maskedDestinationLabel}</strong></div>
                <div class="admin-meta-item"><span>Premium until</span><strong>{$premiumUntilLabel}</strong></div>
                <div class="admin-meta-item"><span>API last used</span><strong>{$apiLastUsedLabel}</strong></div>
                <div class="admin-meta-item"><span>Premium bandwidth</span><strong>{$premiumBandwidthLabel}</strong></div>
            </div>
            <div class="admin-profile-actions">
                <a href="{$searchSimilarUrl}" class="btn btn-secondary">Find similar users</a>
                <a href="{$dashboardUrl}" class="btn btn-secondary">Open dashboard shell</a>
            </div>
        </div>
        <div class="admin-profile-card">
            <h5 class="mb-3">Operator actions</h5>
            <div class="admin-meta-grid mb-4">
                <div class="admin-meta-item"><span>Plan</span><strong>{$detailPlan}</strong></div>
                <div class="admin-meta-item"><span>Premium until</span><strong>{$premiumUntilLabel}</strong></div>
                <div class="admin-meta-item"><span>API last used</span><strong>{$apiLastUsedLabel}</strong></div>
                <div class="admin-meta-item"><span>Payout destination</span><strong>{$maskedDestinationLabel}</strong></div>
            </div>
            <div class="admin-stack">
                <form method="POST" action="{$detailActionUrl}">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="action" value="impersonate_user">
                    <input type="hidden" name="user_id" value="{$detailId}">
                    {$saveReturn}
                    <button type="submit" class="btn btn-secondary">Impersonate account</button>
                </form>
                <form method="POST" action="{$sectionUrl}" onsubmit="return confirm('Delete this user and all owned data permanently?');">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="{$detailId}">
                    {$listReturn}
                    <button type="submit" class="btn btn-danger">Delete user</button>
                </form>
                <a href="{$closeUrl}" class="btn btn-secondary">Back to directory</a>
            </div>
        </div>
    </div>
    <div class="admin-overview-grid">
        <div class="admin-overview-stat"><span>Lifetime views</span><strong>{$viewsTotalLabel}</strong><small>{$trafficTotalLabel} traffic</small></div>
        <div class="admin-overview-stat"><span>Lifetime revenue</span><strong>{$revenueTotalLabel}</strong><small>{$directRevenueLabel} direct + {$referralRevenueLabel} referral</small></div>
        <div class="admin-overview-stat"><span>Stored media</span><strong>{$storageLabel}</strong><small>{$videosTotalLabel} files</small></div>
        <div class="admin-overview-stat"><span>Remote imports</span><strong>{$remoteTotalLabel}</strong><small>{$dmcaTotalLabel} DMCA cases</small></div>
        <div class="admin-overview-stat"><span>Domains</span><strong>{$activeDomainTotalLabel}</strong><small>{$domainTotalLabel} mapped total</small></div>
        <div class="admin-overview-stat"><span>Payout pressure</span><strong>{$payoutOpenTotalLabel}</strong><small>{$payoutTotalLabel} requests total</small></div>
        <div class="admin-overview-stat"><span>API requests today</span><strong>{$apiRequestsTodayLabel}</strong><small>{$apiRequestsHourLabel} in the last hour</small></div>
        <div class="admin-overview-stat"><span>Live viewers</span><strong>{$liveViewersLabel}</strong><small>{$sessionCountLabel} tracked sessions shown</small></div>
    </div>
    {$detailNavHtml}
    <div class="admin-group-grid">
        <div class="admin-group-card">
            <h6>Account identity</h6>
            <p>Core record data and account state.</p>
            <ul>
                <li><span>User ID</span><strong>#{$detailId}</strong></li>
                <li><span>Created</span><strong>{$detailCreated}</strong></li>
                <li><span>Last login</span><strong>{$detailLastLogin}</strong></li>
                <li><span>Role</span><strong>{$detailRole}</strong></li>
            </ul>
        </div>
        <div class="admin-group-card">
            <h6>Billing footprint</h6>
            <p>How this account is configured to receive money.</p>
            <ul>
                <li><span>Balance</span><strong>{$detailBalance}</strong></li>
                <li><span>Payout method</span><strong>{$paymentMethodLabel}</strong></li>
                <li><span>Payout destination</span><strong>{$maskedDestinationLabel}</strong></li>
                <li><span>Premium bandwidth</span><strong>{$premiumBandwidthLabel}</strong></li>
            </ul>
        </div>
        <div class="admin-group-card">
            <h6>API & access</h6>
            <p>Useful access signals before making support or moderation decisions.</p>
            <ul>
                <li><span>API status</span><strong>{$apiStatusLabel}</strong></li>
                <li><span>API last used</span><strong>{$apiLastUsedLabel}</strong></li>
                <li><span>Requests today</span><strong>{$apiRequestsTodayLabel}</strong></li>
                <li><span>Requests last hour</span><strong>{$apiRequestsHourLabel}</strong></li>
            </ul>
        </div>
    </div>
</div>
HTML;
        $activityHtml = <<<HTML
<div class="admin-subsection" id="users-activity">
    {$detailNavHtml}
    <div class="admin-chart-grid-layout">
        <div class="admin-chart-card">
            <h5 class="mb-2">Daily views</h5>
            <p class="admin-chart-copy">View activity for the selected account.</p>
            {$viewsChart}
        </div>
        <div class="admin-chart-card">
            <h5 class="mb-2">Daily traffic</h5>
            <p class="admin-chart-copy">Traffic served over the reporting window.</p>
            {$trafficChart}
        </div>
        <div class="admin-chart-card">
            <h5 class="mb-2">Daily revenue</h5>
            <p class="admin-chart-copy">Combined direct and referral earnings.</p>
            {$revenueChart}
        </div>
    </div>
    <div class="admin-detail-panel">
        <h5>Recent sessions</h5>
        <div class="settings-table-wrap">
            <table class="table">
                <thead><tr><th>Last seen</th><th>IP</th><th>User agent</th><th>Started</th></tr></thead>
                <tbody>{$sessionRows}</tbody>
            </table>
        </div>
    </div>
</div>
HTML;
        $accessHtml = <<<HTML
<div class="admin-subsection" id="users-access">
    {$detailNavHtml}
    <div class="admin-detail-panels">
        <div class="admin-detail-panel">
            <h5>Account controls</h5>
            <form method="POST" action="{$detailActionUrl}" class="admin-stack">
                <input type="hidden" name="token" value="{$token}">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" value="{$detailId}">
                {$saveReturn}
                <div class="admin-form-grid">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active"{$statusActiveSelected}>Active</option>
                            <option value="suspended"{$statusSuspendedSelected}>Suspended</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role_code" class="form-control">{$roleSelectOptions}</select>
                    </div>
                    <div class="form-group">
                        <label>Plan code</label>
                        <input type="text" name="plan_code" value="{$planCodeValue}" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Premium until</label>
                        <input type="text" name="premium_until" value="{$premiumUntilValue}" class="form-control" placeholder="YYYY-MM-DD HH:MM:SS">
                    </div>
                    <div class="form-group">
                        <label>Payout method</label>
                        <select name="payment_method" class="form-control">{$paymentMethodOptions}</select>
                    </div>
                    <div class="form-group">
                        <label>Payout destination</label>
                        <input type="text" name="payment_id" value="{$paymentDestinationValue}" class="form-control">
                    </div>
                    <div class="form-group d-flex align-items-end">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="admin_user_api_enabled_{$detailId}" name="api_enabled" value="1"{$apiEnabledChecked}>
                            <label class="custom-control-label" for="admin_user_api_enabled_{$detailId}">API access enabled</label>
                        </div>
                    </div>
                </div>
                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary">Save user</button>
                </div>
            </form>
        </div>
        <div class="admin-detail-panel">
            <h5>API status</h5>
            <div class="admin-meta-grid">
                <div class="admin-meta-item"><span>Status</span><strong>{$apiStatusLabel}</strong></div>
                <div class="admin-meta-item"><span>Last used</span><strong>{$apiLastUsedLabel}</strong></div>
                <div class="admin-meta-item"><span>Requests today</span><strong>{$apiRequestsTodayLabel}</strong></div>
                <div class="admin-meta-item"><span>Requests last hour</span><strong>{$apiRequestsHourLabel}</strong></div>
                <div class="admin-meta-item"><span>Premium state</span><strong>{$premiumStatusLabel}</strong></div>
                <div class="admin-meta-item"><span>Premium until</span><strong>{$premiumUntilLabel}</strong></div>
            </div>
        </div>
    </div>
    <div class="admin-detail-panel">
        <h5>Balance ledger</h5>
        <div class="settings-table-wrap">
            <table class="table">
                <thead><tr><th>Time</th><th>Type</th><th>Source</th><th>Amount</th><th>Description</th></tr></thead>
                <tbody>{$ledgerRows}</tbody>
            </table>
        </div>
    </div>
</div>
HTML;
        $relatedHtml = <<<HTML
<div class="admin-subsection" id="users-related">
    {$detailNavHtml}
    <div class="admin-detail-panels">
        <div class="admin-detail-panel"><h5>Top files</h5><ul class="admin-mini-list admin-list-tight">{$topFilesList}</ul></div>
        <div class="admin-detail-panel"><h5>Recent files</h5><ul class="admin-mini-list admin-list-tight">{$videoList}</ul></div>
        <div class="admin-detail-panel"><h5>Remote uploads</h5><ul class="admin-mini-list admin-list-tight">{$remoteList}</ul></div>
        <div class="admin-detail-panel"><h5>DMCA cases</h5><ul class="admin-mini-list admin-list-tight">{$dmcaList}</ul></div>
        <div class="admin-detail-panel"><h5>Payouts</h5><ul class="admin-mini-list admin-list-tight">{$payoutList}</ul></div>
        <div class="admin-detail-panel"><h5>Domains</h5><ul class="admin-mini-list admin-list-tight">{$domainList}</ul></div>
        <div class="admin-detail-panel"><h5>Audit trail</h5><ul class="admin-mini-list admin-list-tight">{$auditList}</ul></div>
    </div>
</div>
HTML;
        $detailPanels = [
            'users-profile' => $profileHtml,
            'users-activity' => $activityHtml,
            'users-access' => $accessHtml,
            'users-related' => $relatedHtml,
            'users-charts' => $activityHtml,
            'users-sessions' => $accessHtml,
            'users-billing' => $accessHtml,
        ];
        $detailHtml = ve_admin_active_subsection_html($activeDetailSubview, $detailPanels, 'users-profile');
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $queryValue = ve_h($query);
    $directoryHtml = <<<HTML
<form method="GET" action="{$directoryUrl}" class="admin-toolbar" id="users-directory">
    <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Username, email, or user ID"></div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusOptions}</select></div>
    <div class="form-group"><label>Role</label><select name="role" class="form-control">{$roleFilterOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$directoryUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>ID</th><th>User</th><th>Status</th><th>Role</th><th>Plan</th><th>Files</th><th>Storage</th><th>Last login</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = match ($activeDetailSubview) {
        'users-segments' => $segmentsHtml,
        'users-operations' => $operationsHtml,
        'users-profile', 'users-activity', 'users-access', 'users-related' => is_array($detail)
            ? $detailHtml
            : $directoryHtml . ve_admin_subsection_notice_html('Select a user from the directory to open this user-level view.'),
        default => $directoryHtml,
    };

    return <<<HTML
<div class="data settings-panel" id="users">
    <div class="settings-panel-title">User management</div>
    <p class="settings-panel-subtitle">Search, segment, and operate on the account base from one place. User-level tabs only appear once an account is selected so the section navigation stays predictable.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_videos_section_deep(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $userId = max(0, (int) ($_GET['user_id'] ?? 0));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('videos');
    $selectedVideoId = ve_admin_current_resource_id();
    $list = ve_admin_list_videos($query, $status, $userId, $page);
    $detail = $selectedVideoId > 0 ? ve_admin_video_detail($selectedVideoId) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'videos', 'resource' => null, 'page' => null], false));
    $libraryUrl = ve_h(ve_admin_subsection_url('videos-library'));
    $statusOptions = ve_admin_select_options_html([
        'queued' => 'Queued',
        'processing' => 'Processing',
        'ready' => 'Ready',
        'error' => 'Error',
    ], $status, true);
    $rowsHtml = '';
    $readyCount = 0;
    $publicCount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $videoId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedVideoId === $videoId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'videos', 'resource' => (string) $videoId], true));
        $statusCode = (string) ($row['status'] ?? 'queued');
        $isPublic = (int) ($row['is_public'] ?? 1) === 1;

        if ($statusCode === 'ready') {
            $readyCount++;
        }

        if ($isPublic) {
            $publicCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">#' . $videoId . '</a></td>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['title'] ?? 'Untitled')) . '</a><br><small>' . ve_h((string) ($row['public_id'] ?? '')) . '</small></td>'
            . '<td><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($row['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) (($row['processed_size_bytes'] ?? 0) > 0 ? $row['processed_size_bytes'] : $row['original_size_bytes'] ?? 0))) . '</td>'
            . '<td>' . ($isPublic ? ve_admin_badge_html('public', 'success') : ve_admin_badge_html('private', 'secondary')) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(7, 'No files matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible files', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Ready in result', 'value' => (string) $readyCount],
        ['label' => 'Public in result', 'value' => (string) $publicCount],
        ['label' => 'Owner filter', 'value' => $userId > 0 ? '#' . $userId : 'All owners'],
    ]);
    $detailHtml = '';

    if ($selectedVideoId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected file could not be found.</div>';
    } elseif (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'videos', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $listReturn = ve_admin_return_to_hidden_html(ve_admin_url(['section' => 'videos', 'resource' => null], false));
        $saveReturn = ve_admin_return_to_input_html();
        $watchUrl = trim((string) ($detail['public_id'] ?? '')) !== '' ? ve_h(ve_url('/d/' . rawurlencode((string) ($detail['public_id'] ?? '')))) : '';
        $visibilityAction = (int) ($detail['is_public'] ?? 1) === 1 ? 'make_video_private' : 'make_video_public';
        $visibilityLabel = (int) ($detail['is_public'] ?? 1) === 1 ? 'Make private' : 'Make public';
        $detailTitle = ve_h((string) ($detail['title'] ?? 'Untitled'));
        $sizeLabel = ve_h(ve_human_bytes((int) (($detail['processed_size_bytes'] ?? 0) > 0 ? $detail['processed_size_bytes'] : $detail['original_size_bytes'] ?? 0)));
        $processingError = trim((string) ($detail['processing_error'] ?? ''));
        $resolutionLabel = ((int) ($detail['width'] ?? 0) > 0 && (int) ($detail['height'] ?? 0) > 0)
            ? (string) ((int) ($detail['width'] ?? 0) . 'x' . (int) ($detail['height'] ?? 0))
            : 'Unknown';
        $durationLabel = ($detail['duration_seconds'] ?? null) !== null
            ? number_format((float) ($detail['duration_seconds'] ?? 0), 1) . 's'
            : 'Unknown';
        $ownerUrl = ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($detail['user_id'] ?? 0)], false));
        $detailHtml = '<div class="admin-subsection" id="videos-detail">'
            . '<h5>File detail: ' . $detailTitle . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Video ID</span><strong>#' . $detailId . '</strong></div>'
            . '<div class="admin-meta-item"><span>Public ID</span><strong>' . ve_h((string) ($detail['public_id'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Owner</span><div><a href="' . $ownerUrl . '">' . ve_h((string) ($detail['username'] ?? '')) . '</a></div></div>'
            . '<div class="admin-meta-item"><span>Status</span><div>' . ve_admin_status_badge_html((string) ($detail['status'] ?? 'queued')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Visibility</span><div>' . ((int) ($detail['is_public'] ?? 1) === 1 ? ve_admin_badge_html('public', 'success') : ve_admin_badge_html('private', 'secondary')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Stored size</span><strong>' . $sizeLabel . '</strong></div>'
            . '<div class="admin-meta-item"><span>Resolution</span><strong>' . ve_h($resolutionLabel) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Duration</span><strong>' . ve_h($durationLabel) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Video codec</span><strong>' . ve_h((string) ($detail['video_codec'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Audio codec</span><strong>' . ve_h((string) ($detail['audio_codec'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Created</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['created_at'] ?? ''))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Ready at</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['ready_at'] ?? ''), 'Pending')) . '</strong></div>'
            . '</div>'
            . '<div class="admin-actions mb-4">'
            . '<form method="POST" action="' . $detailActionUrl . '"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="' . $visibilityAction . '"><input type="hidden" name="video_id" value="' . $detailId . '">' . $saveReturn . '<button type="submit" class="btn btn-secondary">' . ve_h($visibilityLabel) . '</button></form>'
            . '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this file permanently?\');"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="delete_video"><input type="hidden" name="video_id" value="' . $detailId . '">' . $listReturn . '<button type="submit" class="btn btn-danger">Delete file</button></form>'
            . ($watchUrl !== '' ? '<a href="' . $watchUrl . '" class="btn btn-secondary" target="_blank" rel="noopener">Open watch page</a>' : '')
            . '<a href="' . $closeUrl . '" class="btn btn-secondary">Close detail</a>'
            . '</div>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>Processing state</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Status message</span><div>' . ve_h((string) ($detail['status_message'] ?? '')) . '</div></div>'
            . '<div class="admin-meta-item"><span>DMCA holds</span><strong>' . ve_h((string) (int) ($detail['dmca_hold_count'] ?? 0)) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Queued at</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['queued_at'] ?? ''))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Processing started</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['processing_started_at'] ?? ''), 'Not started')) . '</strong></div>'
            . '</div>'
            . ($processingError !== '' ? '<pre class="admin-code-block mt-3">' . ve_h($processingError) . '</pre>' : '<p class="admin-empty mt-3">No processing error stored.</p>')
            . '</div>'
            . '<div class="admin-detail-panel"><h5>Storage accounting</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Original size</span><strong>' . ve_h(ve_human_bytes((int) ($detail['original_size_bytes'] ?? 0))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Processed size</span><strong>' . ve_h(ve_human_bytes((int) ($detail['processed_size_bytes'] ?? 0))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Compression ratio</span><strong>' . ve_h(($detail['compression_ratio'] ?? null) !== null ? number_format((float) ($detail['compression_ratio'] ?? 0), 2) : 'N/A') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Source extension</span><strong>' . ve_h((string) ($detail['source_extension'] ?? '')) . '</strong></div>'
            . '</div></div>'
            . '</div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $userIdInput = $userId > 0 ? '<input type="hidden" name="user_id" value="' . $userId . '">' : '';
    $queryValue = ve_h($query);
    $libraryHtml = <<<HTML
<form method="GET" action="{$libraryUrl}" class="admin-toolbar" id="videos-library">
    {$userIdInput}
    <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Title, public ID, or owner"></div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$libraryUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>ID</th><th>File</th><th>Owner</th><th>Status</th><th>Size</th><th>Access</th><th>Created</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = $libraryHtml;

    if ($activeSubview === 'videos-detail') {
        $bodyHtml = $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select a valid file from the library to open file detail.');
    }

    return <<<HTML
<div class="data settings-panel" id="videos">
    <div class="settings-panel-title">Files and videos</div>
    <p class="settings-panel-subtitle">Moderate uploader content, inspect processing state, and control public visibility without leaving the backend shell.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_remote_uploads_section_deep(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $userId = max(0, (int) ($_GET['user_id'] ?? 0));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('remote-uploads');
    $selectedJobId = ve_admin_current_resource_id();
    $list = ve_admin_list_remote_uploads($query, $status, $userId, $page);
    $detail = $selectedJobId > 0 ? ve_admin_remote_upload_detail($selectedJobId) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'remote-uploads', 'resource' => null, 'page' => null], false));
    $queueUrl = ve_h(ve_admin_subsection_url('remote-uploads-queue'));
    $statusOptions = ve_admin_select_options_html([
        VE_REMOTE_STATUS_PENDING => 'Pending',
        VE_REMOTE_STATUS_RESOLVING => 'Resolving',
        VE_REMOTE_STATUS_DOWNLOADING => 'Downloading',
        VE_REMOTE_STATUS_IMPORTING => 'Importing',
        VE_REMOTE_STATUS_COMPLETE => 'Complete',
        VE_REMOTE_STATUS_ERROR => 'Error',
    ], $status, true);
    $rowsHtml = '';
    $errorCount = 0;
    $activeCount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedJobId === $jobId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'remote-uploads', 'resource' => (string) $jobId], true));
        $statusCode = (string) ($row['status'] ?? VE_REMOTE_STATUS_PENDING);

        if ($statusCode === VE_REMOTE_STATUS_ERROR) {
            $errorCount++;
        }

        if (in_array($statusCode, [VE_REMOTE_STATUS_PENDING, VE_REMOTE_STATUS_RESOLVING, VE_REMOTE_STATUS_DOWNLOADING, VE_REMOTE_STATUS_IMPORTING], true)) {
            $activeCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">#' . $jobId . '</a></td>'
            . '<td><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($row['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['source_url'] ?? '')) . '</a></td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h(number_format((float) ($row['progress_percent'] ?? 0), 1) . '%') . '</td>'
            . '<td>' . ve_h((string) (int) ($row['attempt_count'] ?? 0)) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['updated_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(7, 'No remote uploads matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible jobs', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Active in result', 'value' => (string) $activeCount],
        ['label' => 'Errors in result', 'value' => (string) $errorCount],
        ['label' => 'Owner filter', 'value' => $userId > 0 ? '#' . $userId : 'All owners'],
    ]);
    $detailHtml = '';

    if ($selectedJobId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected remote job could not be found.</div>';
    } elseif (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'remote-uploads', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $listReturn = ve_admin_return_to_hidden_html(ve_admin_url(['section' => 'remote-uploads', 'resource' => null], false));
        $saveReturn = ve_admin_return_to_input_html();
        $linkedVideoUrl = (int) ($detail['video_id'] ?? 0) > 0
            ? ve_h(ve_admin_url(['section' => 'videos', 'resource' => (string) (int) ($detail['video_id'] ?? 0)], false))
            : '';
        $detailHtml = '<div class="admin-subsection" id="remote-uploads-detail">'
            . '<h5>Remote job detail: #' . $detailId . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Owner</span><div><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($detail['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($detail['username'] ?? '')) . '</a></div></div>'
            . '<div class="admin-meta-item"><span>Status</span><div>' . ve_admin_status_badge_html((string) ($detail['status'] ?? VE_REMOTE_STATUS_PENDING)) . '</div></div>'
            . '<div class="admin-meta-item"><span>Progress</span><strong>' . ve_h(number_format((float) ($detail['progress_percent'] ?? 0), 1) . '%') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Attempts</span><strong>' . ve_h((string) (int) ($detail['attempt_count'] ?? 0)) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Downloaded</span><strong>' . ve_h(ve_human_bytes((int) ($detail['bytes_downloaded'] ?? 0))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Total bytes</span><strong>' . ve_h(ve_human_bytes((int) ($detail['bytes_total'] ?? 0))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Speed</span><strong>' . ve_h(ve_human_bytes((int) ($detail['speed_bytes_per_second'] ?? 0))) . '/s</strong></div>'
            . '<div class="admin-meta-item"><span>Content type</span><strong>' . ve_h((string) ($detail['content_type'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Started</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['started_at'] ?? ''), 'Not started')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Completed</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['completed_at'] ?? ''), 'Not completed')) . '</strong></div>'
            . '</div>'
            . '<div class="admin-actions mb-4">'
            . '<form method="POST" action="' . $detailActionUrl . '"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="retry_remote_upload"><input type="hidden" name="job_id" value="' . $detailId . '">' . $saveReturn . '<button type="submit" class="btn btn-primary">Retry job</button></form>'
            . '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this remote job?\');"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="delete_remote_upload"><input type="hidden" name="job_id" value="' . $detailId . '">' . $listReturn . '<button type="submit" class="btn btn-danger">Delete job</button></form>'
            . ($linkedVideoUrl !== '' ? '<a href="' . $linkedVideoUrl . '" class="btn btn-secondary">Open linked file</a>' : '')
            . '<a href="' . $closeUrl . '" class="btn btn-secondary">Close detail</a>'
            . '</div>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>Source addresses</h5><div class="admin-stack">'
            . '<div><span class="admin-muted d-block">Source URL</span><code>' . ve_h((string) ($detail['source_url'] ?? '')) . '</code></div>'
            . '<div><span class="admin-muted d-block">Normalized URL</span><code>' . ve_h((string) ($detail['normalized_url'] ?? '')) . '</code></div>'
            . '<div><span class="admin-muted d-block">Resolved URL</span><code>' . ve_h((string) ($detail['resolved_url'] ?? '')) . '</code></div>'
            . '</div></div>'
            . '<div class="admin-detail-panel"><h5>Worker state</h5><div class="admin-stack">'
            . '<div><span class="admin-muted d-block">Host key</span><code>' . ve_h((string) ($detail['host_key'] ?? '')) . '</code></div>'
            . '<div><span class="admin-muted d-block">Original filename</span><code>' . ve_h((string) ($detail['original_filename'] ?? '')) . '</code></div>'
            . '<div><span class="admin-muted d-block">Status message</span><div>' . ve_h((string) ($detail['status_message'] ?? '')) . '</div></div>'
            . '<div><span class="admin-muted d-block">Error</span><div>' . (trim((string) ($detail['error_message'] ?? '')) !== '' ? ve_h((string) ($detail['error_message'] ?? '')) : 'No error stored.') . '</div></div>'
            . '</div></div>'
            . '</div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $userIdInput = $userId > 0 ? '<input type="hidden" name="user_id" value="' . $userId . '">' : '';
    $queryValue = ve_h($query);
    $queueHtml = <<<HTML
<form method="GET" action="{$queueUrl}" class="admin-toolbar" id="remote-uploads-queue">
    {$userIdInput}
    <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Source URL, video public ID, or owner"></div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$queueUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>ID</th><th>User</th><th>Source</th><th>Status</th><th>Progress</th><th>Attempts</th><th>Updated</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = $queueHtml;

    if ($activeSubview === 'remote-uploads-detail') {
        $bodyHtml = $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select a valid remote upload job to open detail.');
    }

    return <<<HTML
<div class="data settings-panel" id="remote_uploads">
    <div class="settings-panel-title">Remote uploads</div>
    <p class="settings-panel-subtitle">Inspect ingest jobs deeply enough to see queue state, worker progress, source normalization, and linked file creation.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_dmca_section_deep(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? 'open'));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('dmca');
    $selectedNoticeId = ve_admin_current_resource_id();
    $list = ve_admin_list_dmca_notices($status, $query, $page);
    $detail = $selectedNoticeId > 0 ? ve_admin_dmca_detail($selectedNoticeId) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'dmca', 'resource' => null, 'page' => null], false));
    $queueUrl = ve_h(ve_admin_subsection_url('dmca-queue'));
    $statusOptions = ['open' => 'Open', 'resolved' => 'Resolved'];

    foreach (ve_dmca_notice_status_catalog() as $code => $meta) {
        $statusOptions[$code] = (string) ($meta['label'] ?? $code);
    }

    $statusSelectOptions = ve_admin_select_options_html($statusOptions, $status, true, 'All');
    $rowsHtml = '';
    $openCount = 0;
    $resolvedCount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $noticeId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedNoticeId === $noticeId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'dmca', 'resource' => (string) $noticeId], true));
        $statusCode = (string) ($row['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
        $videoTitle = trim((string) ($row['video_title_snapshot'] ?? '')) !== ''
            ? (string) ($row['video_title_snapshot'] ?? '')
            : (string) ($row['video_title'] ?? 'Removed video');
        $deadlineLabel = trim((string) ($row['auto_delete_at'] ?? '')) !== ''
            ? ve_format_datetime_label((string) ($row['auto_delete_at'] ?? ''))
            : 'Not scheduled';

        if (ve_dmca_notice_is_open($statusCode)) {
            $openCount++;
        } else {
            $resolvedCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['case_code'] ?? '')) . '</a></td>'
            . '<td><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($row['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td>' . ve_h($videoTitle) . '</td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['received_at'] ?? ''))) . '</td>'
            . '<td>' . ve_h($deadlineLabel) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(6, 'No DMCA cases matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible cases', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Open in result', 'value' => (string) $openCount],
        ['label' => 'Resolved in result', 'value' => (string) $resolvedCount],
        ['label' => 'Filter', 'value' => $status === '' ? 'All statuses' : str_replace('_', ' ', $status)],
    ]);
    $detailHtml = '';
    $eventsHtml = '';

    if ($selectedNoticeId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected DMCA case could not be found.</div>';
    } elseif (is_array($detail)) {
        $payload = (array) ($detail['payload'] ?? []);
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'dmca', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $currentStatus = (string) ($detail['status'] ?? VE_DMCA_NOTICE_STATUS_PENDING_REVIEW);
        $statusCatalog = ve_dmca_notice_status_catalog();
        $statusFormOptions = '';

        foreach ($statusCatalog as $code => $meta) {
            $selected = $code === $currentStatus ? ' selected="selected"' : '';
            $statusFormOptions .= '<option value="' . ve_h($code) . '"' . $selected . '>' . ve_h((string) ($meta['label'] ?? $code)) . '</option>';
        }

        $video = (array) ($payload['video'] ?? []);
        $uploaderResponse = is_array($payload['uploader_response'] ?? null) ? $payload['uploader_response'] : null;
        $timelineHtml = '';

        foreach ((array) ($payload['timeline'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $timelineHtml .= '<li><strong>' . ve_h((string) ($event['title'] ?? 'Event')) . '</strong><small>'
                . ve_h((string) ($event['created_label'] ?? '')) . '</small><div class="admin-muted">'
                . ve_h((string) ($event['body'] ?? '')) . '</div></li>';
        }

        if ($timelineHtml === '') {
            $timelineHtml = '<li class="text-muted">No timeline events stored.</li>';
        }

        $evidenceHtml = '';

        foreach ((array) ($payload['evidence_urls'] ?? []) as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $evidenceHtml .= '<li><a href="' . ve_h($url) . '" target="_blank" rel="noopener">' . ve_h($url) . '</a></li>';
        }

        if ($evidenceHtml === '') {
            $evidenceHtml = '<li class="text-muted">No evidence links attached.</li>';
        }

        $uploaderResponseHtml = $uploaderResponse !== null
            ? '<div class="admin-meta-grid">'
                . '<div class="admin-meta-item"><span>Contact email</span><strong>' . ve_h((string) ($uploaderResponse['contact_email'] ?? '')) . '</strong></div>'
                . '<div class="admin-meta-item"><span>Contact phone</span><strong>' . ve_h((string) ($uploaderResponse['contact_phone'] ?? '')) . '</strong></div>'
                . '<div class="admin-meta-item"><span>Notes</span><div>' . ve_h((string) ($uploaderResponse['notes'] ?? '')) . '</div></div>'
                . '</div>'
            : '<p class="admin-empty">No uploader response has been stored.</p>';

        $eventsHtml = '<div class="admin-subsection" id="dmca-events"><h5>Case timeline</h5><ul class="admin-timeline">' . $timelineHtml . '</ul></div>';
        $detailHtml = '<div class="admin-subsection" id="dmca-detail">'
            . '<h5>DMCA case: ' . ve_h((string) ($payload['case_code'] ?? '')) . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Status</span><div>' . ve_admin_status_badge_html($currentStatus) . '</div></div>'
            . '<div class="admin-meta-item"><span>Uploader</span><div><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($detail['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($detail['username'] ?? '')) . '</a></div></div>'
            . '<div class="admin-meta-item"><span>Received</span><strong>' . ve_h((string) ($payload['received_label'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Updated</span><strong>' . ve_h((string) ($payload['updated_label'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Resolved</span><strong>' . ve_h((string) ($payload['resolved_label'] ?? 'Open')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Auto delete deadline</span><strong>' . ve_h((string) ($payload['auto_delete_label'] ?? 'Not scheduled')) . '</strong></div>'
            . '</div>'
            . '<form method="POST" action="' . $detailActionUrl . '" class="admin-stack mb-4">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="update_dmca_status">'
            . '<input type="hidden" name="notice_id" value="' . $detailId . '">'
            . ve_admin_return_to_input_html()
            . '<div class="admin-form-grid">'
            . '<div class="form-group"><label>Status</label><select name="status" class="form-control">' . $statusFormOptions . '</select></div>'
            . '<div class="form-group" style="grid-column: 1 / -1;"><label>Operator note</label><textarea name="note" class="form-control" placeholder="Explain why the status changed."></textarea></div>'
            . '</div>'
            . '<div class="admin-actions"><button type="submit" class="btn btn-primary">Update case</button><a href="' . $closeUrl . '" class="btn btn-secondary">Close detail</a></div>'
            . '</form>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>Complaint</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Claimed work</span><div>' . ve_h((string) ($payload['claimed_work'] ?? '')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Reported URL</span><div>' . ve_h((string) ($payload['reported_url'] ?? '')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Reference URL</span><div>' . ve_h((string) ($payload['work_reference_url'] ?? '')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Notes</span><div>' . ve_h((string) ($payload['notes'] ?? '')) . '</div></div>'
            . '</div><ul class="admin-mini-list mt-3">' . $evidenceHtml . '</ul></div>'
            . '<div class="admin-detail-panel"><h5>Complainant</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Name</span><strong>' . ve_h((string) ($payload['complainant_name'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Company</span><strong>' . ve_h((string) ($payload['complainant_company'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Email</span><strong>' . ve_h((string) ($payload['complainant_email'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Phone</span><strong>' . ve_h((string) ($payload['complainant_phone'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Country</span><strong>' . ve_h((string) ($payload['complainant_country'] ?? '')) . '</strong></div>'
            . '</div></div>'
            . '<div class="admin-detail-panel"><h5>Video state</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Video title</span><div>' . ve_h((string) ($video['title'] ?? 'Removed video')) . '</div></div>'
            . '<div class="admin-meta-item"><span>Public ID</span><strong>' . ve_h((string) ($video['public_id'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Exists</span><strong>' . ((bool) ($video['exists'] ?? false) ? 'Yes' : 'No') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Status</span><strong>' . ve_h((string) ($video['status'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Public</span><strong>' . (((int) ($video['is_public'] ?? 0)) === 1 ? 'Yes' : 'No') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Deadline</span><strong>' . ve_h((string) ($payload['auto_delete_remaining_label'] ?? 'No deadline')) . '</strong></div>'
            . '</div></div>'
            . '<div class="admin-detail-panel"><h5>Uploader response</h5>' . $uploaderResponseHtml . '</div>'
            . '</div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $queryValue = ve_h($query);
    $queueHtml = <<<HTML
<form method="GET" action="{$queueUrl}" class="admin-toolbar" id="dmca-queue">
    <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Case code, URL, title, or uploader"></div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusSelectOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$queueUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>Case</th><th>Uploader</th><th>Video</th><th>Status</th><th>Received</th><th>Deadline</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = match ($activeSubview) {
        'dmca-detail' => $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select a valid DMCA case to open detail.'),
        'dmca-events' => $eventsHtml !== '' ? $eventsHtml : ve_admin_subsection_notice_html('Select a valid DMCA case to inspect the case timeline.'),
        default => $queueHtml,
    };

    return <<<HTML
<div class="data settings-panel" id="dmca">
    <div class="settings-panel-title">DMCA operations</div>
    <p class="settings-panel-subtitle">Work the complaint queue with full visibility into complainant data, uploader response state, deletion deadlines, and event history.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_payouts_section_deep(): string
{
    $status = trim((string) ($_GET['status'] ?? ''));
    $userId = max(0, (int) ($_GET['user_id'] ?? 0));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('payouts');
    $selectedRequestId = ve_admin_current_resource_id();
    $list = ve_admin_list_payouts($status, $userId, $page);
    $detail = $selectedRequestId > 0 ? ve_admin_payout_request_by_id($selectedRequestId) : null;
    $transfer = is_array($detail) ? ve_admin_payout_transfer_by_request_id((int) ($detail['id'] ?? 0)) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'payouts', 'resource' => null, 'page' => null], false));
    $queueUrl = ve_h(ve_admin_subsection_url('payouts-queue'));
    $statusOptions = ve_admin_select_options_html([
        'pending' => 'Pending',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
    ], $status, true);
    $rowsHtml = '';
    $openCount = 0;
    $paidCount = 0;
    $visibleAmount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $requestId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedRequestId === $requestId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'payouts', 'resource' => (string) $requestId], true));
        $statusCode = (string) ($row['status'] ?? 'pending');
        $visibleAmount += (int) ($row['amount_micro_usd'] ?? 0);

        if (in_array($statusCode, ['pending', 'approved'], true)) {
            $openCount++;
        }

        if ($statusCode === 'paid') {
            $paidCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['public_id'] ?? '')) . '</a></td>'
            . '<td><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($row['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a><br><small>' . ve_h((string) ($row['email'] ?? '')) . '</small></td>'
            . '<td>' . ve_h((string) ($row['payout_method'] ?? '')) . '</td>'
            . '<td>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($row['amount_micro_usd'] ?? 0))) . '</td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(6, 'No payout requests matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible requests', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Open in result', 'value' => (string) $openCount],
        ['label' => 'Paid in result', 'value' => (string) $paidCount],
        ['label' => 'Visible amount', 'value' => ve_dashboard_format_currency_micro_usd($visibleAmount)],
    ]);
    $detailHtml = '';
    $transferHtml = '';

    if ($selectedRequestId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected payout request could not be found.</div>';
    } elseif (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'payouts', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $transferHtml = '<div class="admin-detail-panel" id="payouts-transfer"><h5>Transfer tracking</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Reference</span><strong>' . ve_h((string) ($detail['transfer_reference'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Transfer row</span><strong>' . (is_array($transfer) ? ve_h((string) ($transfer['status'] ?? 'sent')) : 'Not created') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Fee</span><strong>' . (is_array($transfer) ? ve_h(ve_dashboard_format_currency_micro_usd((int) ($transfer['fee_micro_usd'] ?? 0))) : '$0.00') . '</strong></div>'
            . '<div class="admin-meta-item"><span>Net</span><strong>' . (is_array($transfer) ? ve_h(ve_dashboard_format_currency_micro_usd((int) ($transfer['net_amount_micro_usd'] ?? 0))) : ve_h(ve_dashboard_format_currency_micro_usd((int) ($detail['amount_micro_usd'] ?? 0)))) . '</strong></div>'
            . '</div></div>';
        $detailHtml = '<div class="admin-subsection" id="payouts-detail">'
            . '<h5>Payout request: ' . ve_h((string) ($detail['public_id'] ?? '')) . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Status</span><div>' . ve_admin_status_badge_html((string) ($detail['status'] ?? 'pending')) . '</div></div>'
            . '<div class="admin-meta-item"><span>User</span><div><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($detail['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($detail['username'] ?? '')) . '</a></div></div>'
            . '<div class="admin-meta-item"><span>Email</span><strong>' . ve_h((string) ($detail['email'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Amount</span><strong>' . ve_h(ve_dashboard_format_currency_micro_usd((int) ($detail['amount_micro_usd'] ?? 0))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Method</span><strong>' . ve_h((string) ($detail['payout_method'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Destination</span><strong>' . ve_h((string) ($detail['payout_destination_masked'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Created</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['created_at'] ?? ''))) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Reviewed</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['reviewed_at'] ?? ''), 'Not reviewed')) . '</strong></div>'
            . '</div>'
            . '<div class="admin-detail-panels mb-4">'
            . '<div class="admin-detail-panel"><h5>Operator notes</h5><p>' . ve_h((string) ($detail['notes'] ?? 'No notes.')) . '</p><p class="admin-muted">Rejection reason: ' . ve_h((string) ($detail['rejection_reason'] ?? '')) . '</p></div>'
            . $transferHtml
            . '</div>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>Approve request</h5><form method="POST" action="' . $detailActionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="approve_payout"><input type="hidden" name="request_id" value="' . $detailId . '">' . ve_admin_return_to_input_html() . '<div class="form-group"><label>Operator note</label><textarea name="notes" class="form-control" placeholder="Optional approval note.">' . ve_h((string) ($detail['notes'] ?? '')) . '</textarea></div><button type="submit" class="btn btn-primary">Approve payout</button></form></div>'
            . '<div class="admin-detail-panel"><h5>Reject request</h5><form method="POST" action="' . $detailActionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="reject_payout"><input type="hidden" name="request_id" value="' . $detailId . '">' . ve_admin_return_to_input_html() . '<div class="form-group"><label>Rejection reason</label><input type="text" name="rejection_reason" value="" class="form-control" placeholder="Explain why this request is rejected."></div><div class="form-group"><label>Operator note</label><textarea name="notes" class="form-control" placeholder="Optional internal note."></textarea></div><button type="submit" class="btn btn-danger">Reject payout</button></form></div>'
            . '<div class="admin-detail-panel"><h5>Mark paid</h5><form method="POST" action="' . $detailActionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="mark_payout_paid"><input type="hidden" name="request_id" value="' . $detailId . '">' . ve_admin_return_to_input_html() . '<div class="form-group"><label>Transfer reference</label><input type="text" name="transfer_reference" value="' . ve_h((string) ($detail['transfer_reference'] ?? '')) . '" class="form-control" placeholder="Provider transfer reference"></div><div class="form-group"><label>Fee amount (USD)</label><input type="text" name="fee_amount" value="' . (is_array($transfer) ? ve_h(number_format(((int) ($transfer['fee_micro_usd'] ?? 0)) / 1000000, 2, '.', '')) : '0.00') . '" class="form-control"></div><div class="form-group"><label>Operator note</label><textarea name="notes" class="form-control" placeholder="Optional payout note."></textarea></div><button type="submit" class="btn btn-primary">Mark payout paid</button></form></div>'
            . '</div>'
            . '<div class="admin-actions mt-4"><a href="' . $closeUrl . '" class="btn btn-secondary">Close detail</a></div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $userIdInput = $userId > 0 ? '<input type="hidden" name="user_id" value="' . $userId . '">' : '';
    $queueHtml = <<<HTML
<form method="GET" action="{$queueUrl}" class="admin-toolbar" id="payouts-queue">
    {$userIdInput}
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$queueUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>Request</th><th>User</th><th>Method</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = match ($activeSubview) {
        'payouts-detail' => $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select a valid payout request to open detail.'),
        'payouts-transfer' => $transferHtml !== '' ? $transferHtml : ve_admin_subsection_notice_html('Select a valid payout request to inspect transfer tracking.'),
        default => $queueHtml,
    };

    return <<<HTML
<div class="data settings-panel" id="payouts">
    <div class="settings-panel-title">Payout operations</div>
    <p class="settings-panel-subtitle">Review withdrawal requests with direct access to approval, rejection, payout settlement, and transfer accounting.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_domains_section_deep(): string
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('domains');
    $selectedDomainId = ve_admin_current_resource_id();
    $list = ve_admin_list_custom_domains($status, $query, $page);
    $detail = $selectedDomainId > 0 ? ve_admin_custom_domain_detail($selectedDomainId) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'domains', 'resource' => null, 'page' => null], false));
    $directoryUrl = ve_h(ve_admin_subsection_url('domains-directory'));
    $statusOptions = ve_admin_select_options_html([
        'active' => 'Active',
        'pending_dns' => 'Pending DNS',
        'lookup_failed' => 'Lookup failed',
    ], $status, true);
    $rowsHtml = '';
    $activeCount = 0;
    $pendingCount = 0;

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $domainId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedDomainId === $domainId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'domains', 'resource' => (string) $domainId], true));
        $statusCode = (string) ($row['status'] ?? 'pending_dns');

        if ($statusCode === 'active') {
            $activeCount++;
        } else {
            $pendingCount++;
        }

        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">' . ve_h((string) ($row['domain'] ?? '')) . '</a></td>'
            . '<td><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($row['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($row['username'] ?? '')) . '</a></td>'
            . '<td>' . ve_admin_status_badge_html($statusCode) . '</td>'
            . '<td>' . ve_h((string) ($row['dns_target'] ?? '')) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['dns_last_checked_at'] ?? ''), 'Never')) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(6, 'No custom domains matched the current filters.');
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible domains', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Active in result', 'value' => (string) $activeCount],
        ['label' => 'Needs attention', 'value' => (string) $pendingCount],
        ['label' => 'Expected DNS target', 'value' => (string) (ve_config()['custom_domain_target'] ?? '')],
    ]);
    $detailHtml = '';

    if ($selectedDomainId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected custom domain could not be found.</div>';
    } elseif (is_array($detail)) {
        $token = ve_h(ve_csrf_token());
        $detailId = (int) ($detail['id'] ?? 0);
        $detailActionUrl = ve_h(ve_admin_url(['section' => 'domains', 'resource' => (string) $detailId], false));
        $closeUrl = $sectionUrl;
        $listReturn = ve_admin_return_to_hidden_html(ve_admin_url(['section' => 'domains', 'resource' => null], false));
        $detailHtml = '<div class="admin-subsection" id="domains-detail">'
            . '<h5>Domain detail: ' . ve_h((string) ($detail['domain'] ?? '')) . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Status</span><div>' . ve_admin_status_badge_html((string) ($detail['status'] ?? 'pending_dns')) . '</div></div>'
            . '<div class="admin-meta-item"><span>User</span><div><a href="' . ve_h(ve_admin_url(['section' => 'users', 'resource' => (string) (int) ($detail['user_id'] ?? 0)], false)) . '">' . ve_h((string) ($detail['username'] ?? '')) . '</a></div></div>'
            . '<div class="admin-meta-item"><span>User email</span><strong>' . ve_h((string) ($detail['email'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>DNS target</span><strong>' . ve_h((string) ($detail['dns_target'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Last checked</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['dns_last_checked_at'] ?? ''), 'Never')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Created</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['created_at'] ?? ''))) . '</strong></div>'
            . '</div>'
            . '<div class="admin-actions mb-4">'
            . '<form method="POST" action="' . $detailActionUrl . '"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="refresh_domain"><input type="hidden" name="domain_id" value="' . $detailId . '">' . ve_admin_return_to_input_html() . '<button type="submit" class="btn btn-primary">Refresh DNS</button></form>'
            . '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this domain mapping?\');"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="delete_domain"><input type="hidden" name="domain_id" value="' . $detailId . '">' . $listReturn . '<button type="submit" class="btn btn-danger">Delete domain</button></form>'
            . '<a href="' . $closeUrl . '" class="btn btn-secondary">Close detail</a>'
            . '</div>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>DNS state</h5><div class="admin-meta-grid">'
            . '<div class="admin-meta-item"><span>Stored target</span><strong>' . ve_h((string) ($detail['dns_target'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Platform target</span><strong>' . ve_h((string) (ve_config()['custom_domain_target'] ?? '')) . '</strong></div>'
            . '</div></div>'
            . '<div class="admin-detail-panel"><h5>Resolver error</h5><p>' . (trim((string) ($detail['dns_check_error'] ?? '')) !== '' ? ve_h((string) ($detail['dns_check_error'] ?? '')) : 'No resolver error stored.') . '</p></div>'
            . '</div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $queryValue = ve_h($query);
    $directoryHtml = <<<HTML
<form method="GET" action="{$directoryUrl}" class="admin-toolbar" id="domains-directory">
    <div class="form-group"><label>Search</label><input type="text" name="q" value="{$queryValue}" class="form-control" placeholder="Domain or uploader"></div>
    <div class="form-group"><label>Status</label><select name="status" class="form-control">{$statusOptions}</select></div>
    <div class="form-group form-group--action"><button type="submit" class="btn btn-primary">Apply filters</button></div>
    <div class="form-group form-group--action"><a href="{$directoryUrl}" class="btn btn-secondary">Reset</a></div>
</form>
<div class="settings-table-wrap">
    <table class="table">
        <thead><tr><th>Domain</th><th>User</th><th>Status</th><th>DNS target</th><th>Last checked</th><th>Created</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = $directoryHtml;

    if ($activeSubview === 'domains-detail') {
        $bodyHtml = $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select a valid domain record to open detail.');
    }

    return <<<HTML
<div class="data settings-panel" id="domains">
    <div class="settings-panel-title">Domain operations</div>
    <p class="settings-panel-subtitle">Track account-level custom domains, validate DNS resolution, and remove broken mappings without leaving the backend.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_render_app_section_deep(): string
{
    $activeSubview = ve_admin_current_subview_slug('app');
    $sectionUrl = ve_h(ve_admin_url(['section' => 'app', 'resource' => null, 'page' => null], false));
    $token = ve_h(ve_csrf_token());
    $returnToInput = ve_admin_return_to_input_html();
    $settings = [];

    foreach (ve_admin_default_settings() as $key => $defaultValue) {
        $settings[$key] = ve_h(ve_get_app_setting($key, (string) $defaultValue) ?? (string) $defaultValue);
    }

    $roleRows = '';

    foreach (ve_admin_role_catalog() as $code => $meta) {
        $permissions = implode(', ', array_map(static fn ($item): string => (string) $item, (array) ($meta['permissions'] ?? [])));
        $roleRows .= '<tr>'
            . '<td><code>' . ve_h($code) . '</code></td>'
            . '<td>' . ve_h((string) ($meta['label'] ?? $code)) . '</td>'
            . '<td>' . ve_h($permissions) . '</td>'
            . '</tr>';
    }

    $permissionRows = '';

    foreach (ve_admin_permission_catalog() as $code => $meta) {
        $permissionRows .= '<tr>'
            . '<td><code>' . ve_h($code) . '</code></td>'
            . '<td>' . ve_h((string) ($meta['label'] ?? $code)) . '</td>'
            . '<td>' . ve_h((string) ($meta['group_code'] ?? 'core')) . '</td>'
            . '</tr>';
    }

    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Bootstrap admins', 'value' => implode(', ', ve_admin_bootstrap_logins()) ?: 'None'],
        ['label' => 'Permission codes', 'value' => (string) count(ve_admin_permission_catalog())],
        ['label' => 'Roles', 'value' => (string) count(ve_admin_role_catalog())],
        ['label' => 'Custom domain target', 'value' => (string) (ve_config()['custom_domain_target'] ?? '')],
    ]);

    $panels = [
        'app-general' => <<<HTML
<div class="admin-subsection" id="app-general">
    <h5>Operator thresholds</h5>
    <form method="POST" action="{$sectionUrl}" class="admin-stack">
        <input type="hidden" name="token" value="{$token}">
        <input type="hidden" name="action" value="save_app_settings">
        {$returnToInput}
        <div class="admin-form-grid">
            <div class="form-group">
                <label>Payout minimum (micro USD)</label>
                <input type="text" name="payout_minimum_micro_usd" value="{$settings['payout_minimum_micro_usd']}" class="form-control">
            </div>
            <div class="form-group">
                <label>Default page size</label>
                <input type="text" name="admin_default_page_size" value="{$settings['admin_default_page_size']}" class="form-control">
            </div>
            <div class="form-group">
                <label>Recent audit limit</label>
                <input type="text" name="admin_recent_audit_limit" value="{$settings['admin_recent_audit_limit']}" class="form-control">
            </div>
            <div class="form-group">
                <label>Remote queue max per user</label>
                <input type="text" name="remote_max_queue_per_user" value="{$settings['remote_max_queue_per_user']}" class="form-control">
            </div>
        </div>
        <div class="admin-actions">
            <button type="submit" class="btn btn-primary">Save app settings</button>
        </div>
    </form>
</div>
HTML,
        'app-roles' => <<<HTML
<div class="admin-subsection" id="app-roles">
    <h5>Role catalog</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Label</th><th>Permissions</th></tr></thead>
            <tbody>{$roleRows}</tbody>
        </table>
    </div>
</div>
HTML,
        'app-permissions' => <<<HTML
<div class="admin-subsection" id="app-permissions">
    <h5>Permission inventory</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Label</th><th>Group</th></tr></thead>
            <tbody>{$permissionRows}</tbody>
        </table>
    </div>
</div>
HTML,
    ];
    $panelHtml = ve_admin_active_subsection_html($activeSubview, $panels, 'app-general');

    return <<<HTML
<div class="data settings-panel" id="app">
    <div class="settings-panel-title">App settings</div>
    <p class="settings-panel-subtitle">Control backend operating thresholds and keep the role and permission model visible to anyone maintaining this panel.</p>
    {$metricsHtml}
    {$panelHtml}
</div>
HTML;
}

function ve_admin_storage_node_options_html(array $nodes, int $selectedId = 0): string
{
    $html = '';

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $nodeId = (int) ($node['id'] ?? 0);
        $selected = $nodeId === $selectedId ? ' selected="selected"' : '';
        $label = (string) ($node['hostname'] ?? ('Node #' . $nodeId));
        $html .= '<option value="' . $nodeId . '"' . $selected . '>' . ve_h($label) . '</option>';
    }

    return $html;
}

function ve_admin_render_infrastructure_section_deep(): string
{
    $activeSubview = ve_admin_current_subview_slug('infrastructure');
    $sectionUrl = ve_h(ve_admin_url(['section' => 'infrastructure', 'resource' => null, 'page' => null], false));
    $token = ve_h(ve_csrf_token());
    $returnToInput = ve_admin_return_to_input_html();
    $snapshot = ve_admin_infrastructure_snapshot();
    $nodes = (array) ($snapshot['storage_nodes'] ?? []);
    $volumes = (array) ($snapshot['storage_volumes'] ?? []);
    $endpoints = (array) ($snapshot['upload_endpoints'] ?? []);
    $deliveryDomains = (array) ($snapshot['delivery_domains'] ?? []);
    $maintenanceWindows = (array) ($snapshot['maintenance_windows'] ?? []);
    $nodeOptions = ve_admin_storage_node_options_html($nodes);
    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Storage nodes', 'value' => (string) count($nodes)],
        ['label' => 'Volumes', 'value' => (string) count($volumes)],
        ['label' => 'Upload endpoints', 'value' => (string) count($endpoints)],
        ['label' => 'Delivery domains', 'value' => (string) count($deliveryDomains), 'meta' => (string) count($maintenanceWindows) . ' maintenance windows'],
    ]);

    $nodeRows = '';

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $nodeRows .= '<tr>'
            . '<td>' . ve_h((string) ($node['code'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($node['hostname'] ?? '')) . '</td>'
            . '<td>' . ve_admin_status_badge_html((string) ($node['health_status'] ?? 'healthy')) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($node['used_bytes'] ?? 0))) . ' / ' . ve_h(ve_human_bytes((int) ($node['available_bytes'] ?? 0))) . '</td>'
            . '<td>' . ve_h((string) (int) ($node['max_ingest_qps'] ?? 0)) . '</td>'
            . '<td>' . ve_h((string) (int) ($node['max_stream_qps'] ?? 0)) . '</td>'
            . '</tr>';
    }

    if ($nodeRows === '') {
        $nodeRows = ve_admin_empty_table_row_html(6, 'No storage nodes configured.');
    }

    $volumeRows = '';

    foreach ($volumes as $volume) {
        if (!is_array($volume)) {
            continue;
        }

        $volumeRows .= '<tr>'
            . '<td>' . ve_h((string) ($volume['hostname'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($volume['code'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($volume['mount_path'] ?? '')) . '</td>'
            . '<td>' . ve_admin_status_badge_html((string) ($volume['health_status'] ?? 'healthy')) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($volume['used_bytes'] ?? 0))) . ' / ' . ve_h(ve_human_bytes((int) ($volume['capacity_bytes'] ?? 0))) . '</td>'
            . '</tr>';
    }

    if ($volumeRows === '') {
        $volumeRows = ve_admin_empty_table_row_html(5, 'No storage volumes configured.');
    }

    $endpointRows = '';

    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }

        $endpointRows .= '<tr>'
            . '<td>' . ve_h((string) ($endpoint['code'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($endpoint['hostname'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($endpoint['protocol'] ?? 'https')) . '://' . ve_h((string) ($endpoint['host'] ?? '')) . ve_h((string) ($endpoint['path_prefix'] ?? '')) . '</td>'
            . '<td>' . (((int) ($endpoint['is_active'] ?? 0)) === 1 ? ve_admin_badge_html('active', 'success') : ve_admin_badge_html('inactive', 'secondary')) . '</td>'
            . '<td>' . ve_h((string) (int) ($endpoint['weight'] ?? 0)) . '</td>'
            . '<td>' . ve_h(ve_human_bytes((int) ($endpoint['max_file_size_bytes'] ?? 0))) . '</td>'
            . '</tr>';
    }

    if ($endpointRows === '') {
        $endpointRows = ve_admin_empty_table_row_html(6, 'No upload endpoints configured.');
    }

    $deliveryRows = '';

    foreach ($deliveryDomains as $domain) {
        if (!is_array($domain)) {
            continue;
        }

        $deliveryRows .= '<tr>'
            . '<td>' . ve_h((string) ($domain['domain'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($domain['purpose'] ?? 'watch')) . '</td>'
            . '<td>' . ve_admin_status_badge_html((string) ($domain['status'] ?? 'active')) . '</td>'
            . '<td>' . ve_h((string) ($domain['tls_mode'] ?? 'managed')) . '</td>'
            . '</tr>';
    }

    if ($deliveryRows === '') {
        $deliveryRows = ve_admin_empty_table_row_html(4, 'No delivery domains configured.');
    }

    $maintenanceRows = '';

    foreach ($maintenanceWindows as $window) {
        if (!is_array($window)) {
            continue;
        }

        $deleteForm = '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this maintenance window?\');">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="delete_maintenance_window">'
            . '<input type="hidden" name="maintenance_window_id" value="' . (int) ($window['id'] ?? 0) . '">'
            . $returnToInput
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button>'
            . '</form>';
        $maintenanceRows .= '<tr>'
            . '<td>' . ve_h((string) ($window['hostname'] ?? '')) . '</td>'
            . '<td>' . ve_h((string) ($window['mode'] ?? 'drain')) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($window['starts_at'] ?? ''))) . '</td>'
            . '<td>' . ve_h(ve_format_datetime_label((string) ($window['ends_at'] ?? ''))) . '</td>'
            . '<td>' . ve_h((string) ($window['reason'] ?? '')) . '</td>'
            . '<td>' . $deleteForm . '</td>'
            . '</tr>';
    }

    if ($maintenanceRows === '') {
        $maintenanceRows = ve_admin_empty_table_row_html(6, 'No maintenance windows scheduled.');
    }

    $nodeCards = '';

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $nodeId = (int) ($node['id'] ?? 0);
        $healthOptions = ve_admin_select_options_html([
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'offline' => 'Offline',
        ], (string) ($node['health_status'] ?? 'healthy'));
        $deleteForm = '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this storage node?\');">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="delete_storage_node">'
            . '<input type="hidden" name="storage_node_id" value="' . $nodeId . '">'
            . $returnToInput
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button>'
            . '</form>';
        $nodeCards .= '<div class="admin-form-card"><h6>' . ve_h((string) ($node['hostname'] ?? 'Node')) . '</h6>'
            . '<form method="POST" action="' . $sectionUrl . '" class="admin-stack">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="save_storage_node">'
            . '<input type="hidden" name="id" value="' . $nodeId . '">'
            . $returnToInput
            . '<div class="admin-form-grid">'
            . '<div class="form-group"><label>Code</label><input type="text" name="code" value="' . ve_h((string) ($node['code'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Hostname</label><input type="text" name="hostname" value="' . ve_h((string) ($node['hostname'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Public base URL</label><input type="text" name="public_base_url" value="' . ve_h((string) ($node['public_base_url'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Upload base URL</label><input type="text" name="upload_base_url" value="' . ve_h((string) ($node['upload_base_url'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Health</label><select name="health_status" class="form-control">' . $healthOptions . '</select></div>'
            . '<div class="form-group"><label>Available bytes</label><input type="text" name="available_bytes" value="' . ve_h((string) ($node['available_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Used bytes</label><input type="text" name="used_bytes" value="' . ve_h((string) ($node['used_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Max ingest QPS</label><input type="text" name="max_ingest_qps" value="' . ve_h((string) ($node['max_ingest_qps'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Max stream QPS</label><input type="text" name="max_stream_qps" value="' . ve_h((string) ($node['max_stream_qps'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group" style="grid-column: 1 / -1;"><label>Notes</label><textarea name="notes" class="form-control">' . ve_h((string) ($node['notes'] ?? '')) . '</textarea></div>'
            . '</div><div class="admin-actions"><button type="submit" class="btn btn-primary">Save node</button>' . $deleteForm . '</div></form></div>';
    }

    $volumeCards = '';

    foreach ($volumes as $volume) {
        if (!is_array($volume)) {
            continue;
        }

        $volumeId = (int) ($volume['id'] ?? 0);
        $nodeSelect = ve_admin_storage_node_options_html($nodes, (int) ($volume['storage_node_id'] ?? 0));
        $healthOptions = ve_admin_select_options_html([
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'offline' => 'Offline',
        ], (string) ($volume['health_status'] ?? 'healthy'));
        $deleteForm = '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this storage volume?\');">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="delete_storage_volume">'
            . '<input type="hidden" name="storage_volume_id" value="' . $volumeId . '">'
            . $returnToInput
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button>'
            . '</form>';
        $volumeCards .= '<div class="admin-form-card"><h6>' . ve_h((string) ($volume['hostname'] ?? 'Node')) . ' / ' . ve_h((string) ($volume['code'] ?? '')) . '</h6>'
            . '<form method="POST" action="' . $sectionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="save_storage_volume"><input type="hidden" name="id" value="' . $volumeId . '">' . $returnToInput
            . '<div class="admin-form-grid"><div class="form-group"><label>Node</label><select name="storage_node_id" class="form-control">' . $nodeSelect . '</select></div>'
            . '<div class="form-group"><label>Code</label><input type="text" name="code" value="' . ve_h((string) ($volume['code'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Mount path</label><input type="text" name="mount_path" value="' . ve_h((string) ($volume['mount_path'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Health</label><select name="health_status" class="form-control">' . $healthOptions . '</select></div>'
            . '<div class="form-group"><label>Capacity bytes</label><input type="text" name="capacity_bytes" value="' . ve_h((string) ($volume['capacity_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Used bytes</label><input type="text" name="used_bytes" value="' . ve_h((string) ($volume['used_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Reserved bytes</label><input type="text" name="reserved_bytes" value="' . ve_h((string) ($volume['reserved_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '</div><div class="admin-actions"><button type="submit" class="btn btn-primary">Save volume</button>' . $deleteForm . '</div></form></div>';
    }

    $endpointCards = '';

    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }

        $endpointId = (int) ($endpoint['id'] ?? 0);
        $nodeSelect = ve_admin_storage_node_options_html($nodes, (int) ($endpoint['storage_node_id'] ?? 0));
        $protocolOptions = ve_admin_select_options_html(['https' => 'https', 'http' => 'http'], (string) ($endpoint['protocol'] ?? 'https'));
        $activeChecked = (int) ($endpoint['is_active'] ?? 0) === 1 ? ' checked="checked"' : '';
        $remoteChecked = (int) ($endpoint['accepts_remote_upload'] ?? 0) === 1 ? ' checked="checked"' : '';
        $deleteForm = '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this upload endpoint?\');">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="delete_upload_endpoint">'
            . '<input type="hidden" name="upload_endpoint_id" value="' . $endpointId . '">'
            . $returnToInput
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button>'
            . '</form>';
        $endpointCards .= '<div class="admin-form-card"><h6>' . ve_h((string) ($endpoint['code'] ?? 'Endpoint')) . '</h6>'
            . '<form method="POST" action="' . $sectionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="save_upload_endpoint"><input type="hidden" name="id" value="' . $endpointId . '">' . $returnToInput
            . '<div class="admin-form-grid"><div class="form-group"><label>Node</label><select name="storage_node_id" class="form-control">' . $nodeSelect . '</select></div>'
            . '<div class="form-group"><label>Code</label><input type="text" name="code" value="' . ve_h((string) ($endpoint['code'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Protocol</label><select name="protocol" class="form-control">' . $protocolOptions . '</select></div>'
            . '<div class="form-group"><label>Host</label><input type="text" name="host" value="' . ve_h((string) ($endpoint['host'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Path prefix</label><input type="text" name="path_prefix" value="' . ve_h((string) ($endpoint['path_prefix'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Weight</label><input type="text" name="weight" value="' . ve_h((string) ($endpoint['weight'] ?? 100)) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Max file size bytes</label><input type="text" name="max_file_size_bytes" value="' . ve_h((string) ($endpoint['max_file_size_bytes'] ?? 0)) . '" class="form-control"></div>'
            . '<div class="form-group d-flex align-items-end"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="infra_endpoint_active_' . $endpointId . '" name="is_active" value="1"' . $activeChecked . '><label class="custom-control-label" for="infra_endpoint_active_' . $endpointId . '">Endpoint active</label></div></div>'
            . '<div class="form-group d-flex align-items-end"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="infra_endpoint_remote_' . $endpointId . '" name="accepts_remote_upload" value="1"' . $remoteChecked . '><label class="custom-control-label" for="infra_endpoint_remote_' . $endpointId . '">Accepts remote uploads</label></div></div>'
            . '</div><div class="admin-actions"><button type="submit" class="btn btn-primary">Save endpoint</button>' . $deleteForm . '</div></form></div>';
    }

    $deliveryCards = '';

    foreach ($deliveryDomains as $domain) {
        if (!is_array($domain)) {
            continue;
        }

        $domainId = (int) ($domain['id'] ?? 0);
        $purposeOptions = ve_admin_select_options_html(['watch' => 'Watch', 'download' => 'Download', 'embed' => 'Embed'], (string) ($domain['purpose'] ?? 'watch'));
        $statusOptions = ve_admin_select_options_html(['active' => 'Active', 'draining' => 'Draining', 'disabled' => 'Disabled'], (string) ($domain['status'] ?? 'active'));
        $tlsOptions = ve_admin_select_options_html(['managed' => 'Managed', 'bring_your_own' => 'Bring your own'], (string) ($domain['tls_mode'] ?? 'managed'));
        $deleteForm = '<form method="POST" action="' . $sectionUrl . '" onsubmit="return confirm(\'Delete this delivery domain?\');">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<input type="hidden" name="action" value="delete_delivery_domain">'
            . '<input type="hidden" name="delivery_domain_id" value="' . $domainId . '">'
            . $returnToInput
            . '<button type="submit" class="btn btn-sm btn-danger">Delete</button>'
            . '</form>';
        $deliveryCards .= '<div class="admin-form-card"><h6>' . ve_h((string) ($domain['domain'] ?? 'Domain')) . '</h6>'
            . '<form method="POST" action="' . $sectionUrl . '" class="admin-stack"><input type="hidden" name="token" value="' . $token . '"><input type="hidden" name="action" value="save_delivery_domain"><input type="hidden" name="id" value="' . $domainId . '">' . $returnToInput
            . '<div class="admin-form-grid"><div class="form-group"><label>Domain</label><input type="text" name="domain" value="' . ve_h((string) ($domain['domain'] ?? '')) . '" class="form-control"></div>'
            . '<div class="form-group"><label>Purpose</label><select name="purpose" class="form-control">' . $purposeOptions . '</select></div>'
            . '<div class="form-group"><label>Status</label><select name="status" class="form-control">' . $statusOptions . '</select></div>'
            . '<div class="form-group"><label>TLS mode</label><select name="tls_mode" class="form-control">' . $tlsOptions . '</select></div>'
            . '</div><div class="admin-actions"><button type="submit" class="btn btn-primary">Save delivery domain</button>' . $deleteForm . '</div></form></div>';
    }

    $panels = [
        'infra-nodes' => <<<HTML
<div class="admin-subsection" id="infra-nodes">
    <h5>Storage nodes</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Hostname</th><th>Health</th><th>Capacity</th><th>Ingest QPS</th><th>Stream QPS</th></tr></thead>
            <tbody>{$nodeRows}</tbody>
        </table>
    </div>
    <div class="admin-form-card mb-3">
        <h6>Add storage node</h6>
        <form method="POST" action="{$sectionUrl}" class="admin-stack">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="save_storage_node">
            {$returnToInput}
            <div class="admin-form-grid">
                <div class="form-group"><label>Code</label><input type="text" name="code" value="" class="form-control"></div>
                <div class="form-group"><label>Hostname</label><input type="text" name="hostname" value="" class="form-control"></div>
                <div class="form-group"><label>Public base URL</label><input type="text" name="public_base_url" value="" class="form-control"></div>
                <div class="form-group"><label>Upload base URL</label><input type="text" name="upload_base_url" value="" class="form-control"></div>
                <div class="form-group"><label>Health</label><select name="health_status" class="form-control"><option value="healthy">Healthy</option><option value="degraded">Degraded</option><option value="offline">Offline</option></select></div>
                <div class="form-group"><label>Available bytes</label><input type="text" name="available_bytes" value="0" class="form-control"></div>
                <div class="form-group"><label>Used bytes</label><input type="text" name="used_bytes" value="0" class="form-control"></div>
                <div class="form-group"><label>Max ingest QPS</label><input type="text" name="max_ingest_qps" value="0" class="form-control"></div>
                <div class="form-group"><label>Max stream QPS</label><input type="text" name="max_stream_qps" value="0" class="form-control"></div>
                <div class="form-group" style="grid-column: 1 / -1;"><label>Notes</label><textarea name="notes" class="form-control"></textarea></div>
            </div>
            <div class="admin-actions"><button type="submit" class="btn btn-primary">Add node</button></div>
        </form>
    </div>
    <div class="admin-section-grid">{$nodeCards}</div>
</div>
HTML,
        'infra-volumes' => <<<HTML
<div class="admin-subsection" id="infra-volumes">
    <h5>Storage volumes</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Node</th><th>Code</th><th>Mount path</th><th>Health</th><th>Usage</th></tr></thead>
            <tbody>{$volumeRows}</tbody>
        </table>
    </div>
    <div class="admin-form-card mb-3">
        <h6>Add storage volume</h6>
        <form method="POST" action="{$sectionUrl}" class="admin-stack">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="save_storage_volume">
            {$returnToInput}
            <div class="admin-form-grid">
                <div class="form-group"><label>Node</label><select name="storage_node_id" class="form-control">{$nodeOptions}</select></div>
                <div class="form-group"><label>Code</label><input type="text" name="code" value="" class="form-control"></div>
                <div class="form-group"><label>Mount path</label><input type="text" name="mount_path" value="" class="form-control"></div>
                <div class="form-group"><label>Health</label><select name="health_status" class="form-control"><option value="healthy">Healthy</option><option value="degraded">Degraded</option><option value="offline">Offline</option></select></div>
                <div class="form-group"><label>Capacity bytes</label><input type="text" name="capacity_bytes" value="0" class="form-control"></div>
                <div class="form-group"><label>Used bytes</label><input type="text" name="used_bytes" value="0" class="form-control"></div>
                <div class="form-group"><label>Reserved bytes</label><input type="text" name="reserved_bytes" value="0" class="form-control"></div>
            </div>
            <div class="admin-actions"><button type="submit" class="btn btn-primary">Add volume</button></div>
        </form>
    </div>
    <div class="admin-section-grid">{$volumeCards}</div>
</div>
HTML,
        'infra-endpoints' => <<<HTML
<div class="admin-subsection" id="infra-endpoints">
    <h5>Upload endpoints</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Node</th><th>Address</th><th>Status</th><th>Weight</th><th>Max file size</th></tr></thead>
            <tbody>{$endpointRows}</tbody>
        </table>
    </div>
    <div class="admin-form-card mb-3">
        <h6>Add upload endpoint</h6>
        <form method="POST" action="{$sectionUrl}" class="admin-stack">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="save_upload_endpoint">
            {$returnToInput}
            <div class="admin-form-grid">
                <div class="form-group"><label>Node</label><select name="storage_node_id" class="form-control">{$nodeOptions}</select></div>
                <div class="form-group"><label>Code</label><input type="text" name="code" value="" class="form-control"></div>
                <div class="form-group"><label>Protocol</label><select name="protocol" class="form-control"><option value="https">https</option><option value="http">http</option></select></div>
                <div class="form-group"><label>Host</label><input type="text" name="host" value="" class="form-control"></div>
                <div class="form-group"><label>Path prefix</label><input type="text" name="path_prefix" value="" class="form-control"></div>
                <div class="form-group"><label>Weight</label><input type="text" name="weight" value="100" class="form-control"></div>
                <div class="form-group"><label>Max file size bytes</label><input type="text" name="max_file_size_bytes" value="0" class="form-control"></div>
                <div class="form-group d-flex align-items-end"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="infra_endpoint_add_active" name="is_active" value="1" checked="checked"><label class="custom-control-label" for="infra_endpoint_add_active">Endpoint active</label></div></div>
                <div class="form-group d-flex align-items-end"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="infra_endpoint_add_remote" name="accepts_remote_upload" value="1" checked="checked"><label class="custom-control-label" for="infra_endpoint_add_remote">Accepts remote uploads</label></div></div>
            </div>
            <div class="admin-actions"><button type="submit" class="btn btn-primary">Add endpoint</button></div>
        </form>
    </div>
    <div class="admin-section-grid">{$endpointCards}</div>
</div>
HTML,
        'infra-delivery' => <<<HTML
<div class="admin-subsection" id="infra-delivery">
    <h5>Delivery domains</h5>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Domain</th><th>Purpose</th><th>Status</th><th>TLS mode</th></tr></thead>
            <tbody>{$deliveryRows}</tbody>
        </table>
    </div>
    <div class="admin-form-card mb-3">
        <h6>Add delivery domain</h6>
        <form method="POST" action="{$sectionUrl}" class="admin-stack">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="save_delivery_domain">
            {$returnToInput}
            <div class="admin-form-grid">
                <div class="form-group"><label>Domain</label><input type="text" name="domain" value="" class="form-control"></div>
                <div class="form-group"><label>Purpose</label><select name="purpose" class="form-control"><option value="watch">Watch</option><option value="download">Download</option><option value="embed">Embed</option></select></div>
                <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="draining">Draining</option><option value="disabled">Disabled</option></select></div>
                <div class="form-group"><label>TLS mode</label><select name="tls_mode" class="form-control"><option value="managed">Managed</option><option value="bring_your_own">Bring your own</option></select></div>
            </div>
            <div class="admin-actions"><button type="submit" class="btn btn-primary">Add delivery domain</button></div>
        </form>
    </div>
    <div class="admin-section-grid">{$deliveryCards}</div>
</div>
HTML,
        'infra-maintenance' => <<<HTML
<div class="admin-subsection" id="infra-maintenance">
    <h5>Maintenance windows</h5>
    <div class="admin-form-card mb-3">
        <h6>Schedule maintenance window</h6>
        <form method="POST" action="{$sectionUrl}" class="admin-stack">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="action" value="add_maintenance_window">
            {$returnToInput}
            <div class="admin-form-grid">
                <div class="form-group"><label>Node</label><select name="storage_node_id" class="form-control">{$nodeOptions}</select></div>
                <div class="form-group"><label>Start (UTC)</label><input type="text" name="starts_at" value="" class="form-control" placeholder="YYYY-MM-DD HH:MM:SS"></div>
                <div class="form-group"><label>End (UTC)</label><input type="text" name="ends_at" value="" class="form-control" placeholder="YYYY-MM-DD HH:MM:SS"></div>
                <div class="form-group"><label>Mode</label><select name="mode" class="form-control"><option value="drain">Drain</option><option value="offline">Offline</option></select></div>
                <div class="form-group" style="grid-column: 1 / -1;"><label>Reason</label><textarea name="reason" class="form-control"></textarea></div>
            </div>
            <div class="admin-actions"><button type="submit" class="btn btn-primary">Schedule maintenance</button></div>
        </form>
    </div>
    <div class="settings-table-wrap">
        <table class="table">
            <thead><tr><th>Node</th><th>Mode</th><th>Starts</th><th>Ends</th><th>Reason</th><th>Action</th></tr></thead>
            <tbody>{$maintenanceRows}</tbody>
        </table>
    </div>
</div>
HTML,
    ];
    $panelHtml = ve_admin_active_subsection_html($activeSubview, $panels, 'infra-nodes');

    return <<<HTML
<div class="data settings-panel" id="infrastructure">
    <div class="settings-panel-title">Infrastructure</div>
    <p class="settings-panel-subtitle">Operate the storage and delivery plane from one backend surface: nodes, volumes, upload endpoints, delivery domains, and maintenance scheduling.</p>
    {$metricsHtml}
    {$panelHtml}
</div>
HTML;
}

function ve_admin_render_audit_section_deep(): string
{
    $page = ve_admin_request_page();
    $activeSubview = ve_admin_current_subview_slug('audit');
    $selectedLogId = ve_admin_current_resource_id();
    $list = ve_admin_list_audit_logs($page);
    $detail = $selectedLogId > 0 ? ve_admin_audit_log_detail($selectedLogId) : null;
    $sectionUrl = ve_h(ve_admin_url(['section' => 'audit', 'resource' => null, 'page' => null], false));
    $feedUrl = ve_h(ve_admin_subsection_url('audit-feed'));
    $rowsHtml = '';
    $actorCount = [];

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $logId = (int) ($row['id'] ?? 0);
        $selectedClass = $selectedLogId === $logId ? ' class="admin-selected-row"' : '';
        $detailUrl = ve_h(ve_admin_url(['section' => 'audit', 'resource' => (string) $logId], true));
        $actorKey = trim((string) ($row['actor_username'] ?? 'System'));
        $actorCount[$actorKey] = ($actorCount[$actorKey] ?? 0) + 1;
        $targetUrl = ve_admin_audit_target_url($row);
        $targetHtml = $targetUrl !== ''
            ? '<a href="' . ve_h($targetUrl) . '">' . ve_h((string) ($row['target_type'] ?? '')) . ' #' . (int) ($row['target_id'] ?? 0) . '</a>'
            : ve_h((string) ($row['target_type'] ?? '')) . ' #' . (int) ($row['target_id'] ?? 0);
        $rowsHtml .= '<tr' . $selectedClass . '>'
            . '<td><a href="' . $detailUrl . '">' . ve_h(ve_format_datetime_label((string) ($row['created_at'] ?? ''))) . '</a></td>'
            . '<td>' . ve_h($actorKey) . '</td>'
            . '<td>' . ve_h((string) ($row['event_code'] ?? '')) . '</td>'
            . '<td>' . $targetHtml . '</td>'
            . '<td>' . ve_h((string) ($row['ip_address'] ?? '')) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = ve_admin_empty_table_row_html(5, 'No audit logs recorded yet.');
    }

    arsort($actorCount);
    $topActor = array_key_first($actorCount) ?: 'None';
    $topActorCount = $topActor !== 'None' ? (int) ($actorCount[$topActor] ?? 0) : 0;
    $metricsHtml = ve_admin_metric_items_html([
        ['label' => 'Visible entries', 'value' => (string) (int) ($list['total'] ?? 0)],
        ['label' => 'Current page', 'value' => (string) (int) ($list['page'] ?? 1)],
        ['label' => 'Top actor on page', 'value' => $topActor, 'meta' => $topActor !== 'None' ? (string) $topActorCount . ' events' : 'No activity'],
    ]);
    $detailHtml = '';

    if ($selectedLogId > 0 && !is_array($detail)) {
        $detailHtml = '<div class="alert alert-warning">The selected audit record could not be found.</div>';
    } elseif (is_array($detail)) {
        $targetUrl = ve_admin_audit_target_url($detail);
        $targetHtml = $targetUrl !== ''
            ? '<a href="' . ve_h($targetUrl) . '">' . ve_h((string) ($detail['target_type'] ?? '')) . ' #' . (int) ($detail['target_id'] ?? 0) . '</a>'
            : ve_h((string) ($detail['target_type'] ?? '')) . ' #' . (int) ($detail['target_id'] ?? 0);
        $beforeHtml = ve_admin_pretty_json_html((string) ($detail['before_json'] ?? '{}'));
        $afterHtml = ve_admin_pretty_json_html((string) ($detail['after_json'] ?? '{}'));
        $detailHtml = '<div class="admin-subsection" id="audit-detail">'
            . '<h5>Audit detail: #' . (int) ($detail['id'] ?? 0) . '</h5>'
            . '<div class="admin-meta-grid mb-4">'
            . '<div class="admin-meta-item"><span>Actor</span><strong>' . ve_h((string) ($detail['actor_username'] ?? 'System')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Event code</span><strong>' . ve_h((string) ($detail['event_code'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Target</span><div>' . $targetHtml . '</div></div>'
            . '<div class="admin-meta-item"><span>IP address</span><strong>' . ve_h((string) ($detail['ip_address'] ?? '')) . '</strong></div>'
            . '<div class="admin-meta-item"><span>Created</span><strong>' . ve_h(ve_format_datetime_label((string) ($detail['created_at'] ?? ''))) . '</strong></div>'
            . '</div>'
            . '<div class="admin-detail-panels">'
            . '<div class="admin-detail-panel"><h5>Before</h5>' . $beforeHtml . '</div>'
            . '<div class="admin-detail-panel"><h5>After</h5>' . $afterHtml . '</div>'
            . '</div>'
            . '<div class="admin-actions mt-4"><a href="' . $sectionUrl . '" class="btn btn-secondary">Close detail</a></div>'
            . '</div>';
    }

    $pagination = ve_admin_pagination_html((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()));
    $feedHtml = <<<HTML
<div class="settings-table-wrap" id="audit-feed">
    <table class="table">
        <thead><tr><th>Time</th><th>Actor</th><th>Event</th><th>Target</th><th>IP</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
{$pagination}
HTML;
    $bodyHtml = $feedHtml;

    if ($activeSubview === 'audit-detail') {
        $bodyHtml = $detailHtml !== '' ? $detailHtml : ve_admin_subsection_notice_html('Select an audit entry from the feed to open detail.');
    }

    return <<<HTML
<div class="data settings-panel" id="audit">
    <div class="settings-panel-title">Audit log</div>
    <p class="settings-panel-subtitle">Inspect backend actions with exact timestamps, actor identity, target records, and before/after payloads.</p>
    {$metricsHtml}
    {$bodyHtml}
</div>
HTML;
}

function ve_admin_backend_mobile_nav_html(array $actorUser, string $activeSection): string
{
    $items = [];

    foreach (ve_admin_allowed_sections_for_user($actorUser) as $code => $section) {
        $activeClass = $code === $activeSection ? ' active' : '';
        $items[] = '<li class="nav-item"><a href="' . ve_h(ve_admin_url(['section' => $code, 'resource' => null, 'page' => null], false)) . '" data-admin-nav="1" class="nav-link' . $activeClass . '"><i class="fad ' . ve_h((string) ($section['icon'] ?? 'fa-circle')) . '"></i>' . ve_h((string) ($section['label'] ?? ucfirst($code))) . '</a></li>';
    }

    $items[] = '<li class="nav-item"><a href="' . ve_h(ve_url('/dashboard')) . '" class="nav-link"><i class="fad fa-shapes"></i>Dashboard</a></li>';

    $stopControl = ve_admin_impersonation_stop_control_html('nav-link btn btn-link admin-stop-button', false);

    if ($stopControl !== '') {
        $items[] = '<li class="nav-item">' . $stopControl . '</li>';
    }

    return implode('', $items);
}

function ve_admin_unimplemented_section_html(string $section): string
{
    $meta = ve_admin_sections()[$section] ?? ['label' => ucfirst(str_replace('-', ' ', $section))];
    $label = ve_h((string) ($meta['label'] ?? $section));

    return <<<HTML
<div class="data settings-panel" id="{$section}">
    <div class="settings-panel-title">{$label}</div>
    <p class="settings-panel-subtitle">The backend data layer for this section exists, but the management UI has not been finished in this build yet.</p>
</div>
HTML;
}

function ve_admin_section_content_html(string $section): string
{
    return match ($section) {
        'overview' => ve_admin_render_overview_section_deep(),
        'users' => ve_admin_render_users_section_deep(),
        'videos' => ve_admin_render_videos_section_deep(),
        'remote-uploads' => ve_admin_render_remote_uploads_section_deep(),
        'dmca' => ve_admin_render_dmca_section_deep(),
        'payouts' => ve_admin_render_payouts_section_deep(),
        'domains' => ve_admin_render_domains_section_deep(),
        'app' => ve_admin_render_app_section_deep(),
        'infrastructure' => ve_admin_render_infrastructure_section_deep(),
        'audit' => ve_admin_render_audit_section_deep(),
        default => ve_admin_unimplemented_section_html($section),
    };
}

function ve_admin_request_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return ve_admin_request_is_partial() || str_contains($accept, 'application/json');
}

function ve_admin_action_link_payload(string $label, string $href, string $tone = 'secondary', string $icon = '', bool $adminNav = true): array
{
    return [
        'type' => 'link',
        'label' => $label,
        'href' => $href,
        'tone' => $tone,
        'icon' => $icon,
        'admin_nav' => $adminNav,
    ];
}

function ve_admin_action_form_payload(string $label, string $action, string $tone, array $hidden = [], string $icon = '', string $confirm = ''): array
{
    return [
        'type' => 'form',
        'label' => $label,
        'action' => $action,
        'method' => 'POST',
        'tone' => $tone,
        'icon' => $icon,
        'confirm' => $confirm,
        'hidden' => $hidden,
    ];
}

function ve_admin_form_hidden_inputs(array $pairs): array
{
    $fields = [];

    foreach ($pairs as $name => $value) {
        $fields[] = [
            'type' => 'hidden',
            'name' => (string) $name,
            'value' => is_scalar($value) || $value === null ? (string) $value : '',
        ];
    }

    return $fields;
}

function ve_admin_status_payload(string $status): array
{
    $tone = match ($status) {
        'active', 'ready', 'complete', 'paid', 'healthy', VE_DMCA_NOTICE_STATUS_RESTORED => 'success',
        'suspended', 'error', 'rejected', 'withdrawn', 'lookup_failed', 'offline', 'disabled' => 'danger',
        'pending', 'approved', 'pending_dns', VE_DMCA_NOTICE_STATUS_PENDING_REVIEW, VE_DMCA_NOTICE_STATUS_COUNTER_SUBMITTED => 'warning',
        'downloading', 'importing', 'resolving', 'degraded', 'draining', VE_DMCA_NOTICE_STATUS_CONTENT_DISABLED, VE_DMCA_NOTICE_STATUS_RESPONSE_SUBMITTED => 'info',
        default => 'secondary',
    };

    return [
        'label' => str_replace('_', ' ', $status),
        'tone' => $tone,
    ];
}

function ve_admin_table_pagination_payload(int $page, int $totalRows, int $pageSize, string $subsection, string|int|null $resource = null, array $params = []): array
{
    $totalPages = (int) max(1, ceil($totalRows / max(1, $pageSize)));

    if ($totalPages <= 1) {
        return [];
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    $items = [];

    $items[] = [
        'label' => 'Previous',
        'href' => ve_admin_subsection_url($subsection, $resource, $params + ['page' => max(1, $page - 1)], false),
        'disabled' => $page <= 1,
    ];

    for ($index = $start; $index <= $end; $index++) {
        $items[] = [
            'label' => (string) $index,
            'href' => ve_admin_subsection_url($subsection, $resource, $params + ['page' => $index], false),
            'active' => $index === $page,
        ];
    }

    $items[] = [
        'label' => 'Next',
        'href' => ve_admin_subsection_url($subsection, $resource, $params + ['page' => min($totalPages, $page + 1)], false),
        'disabled' => $page >= $totalPages,
    ];

    return $items;
}

function ve_admin_period_switch_payload(array $options, int $currentDays, string $subsection, string|int|null $resource = null): array
{
    $items = [];

    foreach ($options as $days) {
        $items[] = [
            'label' => (string) $days . ' days',
            'href' => ve_admin_subsection_url($subsection, $resource, ['days' => $days], false),
            'tone' => $days === $currentDays ? 'primary' : 'secondary',
            'admin_nav' => true,
            'active' => $days === $currentDays,
        ];
    }

    return $items;
}

function ve_admin_chart_payload(array $points, array $seriesDefinitions): array
{
    $series = [];

    foreach ($seriesDefinitions as $definition) {
        $series[] = [
            'key' => (string) ($definition['key'] ?? ''),
            'label' => (string) ($definition['label'] ?? ''),
            'stroke' => (string) ($definition['stroke'] ?? '#ff9900'),
            'fill' => (string) ($definition['fill'] ?? 'none'),
            'format' => (string) ($definition['format'] ?? 'number'),
        ];
    }

    return [
        'points' => array_values($points),
        'series' => $series,
    ];
}

function ve_admin_user_detail_subnav_payload(int $userId, string $activeSubview): array
{
    $items = [];

    foreach ([
        ['slug' => 'users-profile', 'label' => 'Profile', 'icon' => 'fa-id-card'],
        ['slug' => 'users-activity', 'label' => 'Activity', 'icon' => 'fa-chart-line'],
        ['slug' => 'users-access', 'label' => 'Access & Billing', 'icon' => 'fa-wallet'],
        ['slug' => 'users-related', 'label' => 'Related', 'icon' => 'fa-link'],
    ] as $item) {
        $items[] = [
            'label' => (string) $item['label'],
            'href' => ve_admin_subsection_url((string) $item['slug'], $userId, [], true),
            'icon' => (string) $item['icon'],
            'active' => $activeSubview === (string) $item['slug'],
            'admin_nav' => true,
        ];
    }

    return $items;
}

function ve_admin_view_base_payload(string $title, string $subtitle, array $metrics = [], array $actions = [], array $blocks = []): array
{
    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'metrics' => $metrics,
        'actions' => $actions,
        'blocks' => $blocks,
    ];
}

function ve_admin_metric_payload(string $label, string $value, string $meta = ''): array
{
    return [
        'label' => $label,
        'value' => $value,
        'meta' => $meta,
    ];
}

function ve_admin_text_cell(string $primary, string $secondary = ''): array
{
    return [
        'type' => 'text',
        'primary' => $primary,
        'secondary' => $secondary,
    ];
}

function ve_admin_link_cell(string $label, string $href, string $secondary = ''): array
{
    return [
        'type' => 'link',
        'label' => $label,
        'href' => $href,
        'secondary' => $secondary,
        'admin_nav' => str_contains($href, '/backend'),
    ];
}

function ve_admin_status_cell(string $status): array
{
    return ['type' => 'status'] + ve_admin_status_payload($status);
}

function ve_admin_code_cell(string $value, string $secondary = ''): array
{
    return [
        'type' => 'code',
        'primary' => $value,
        'secondary' => $secondary,
    ];
}

function ve_admin_actions_cell(array $actions): array
{
    return [
        'type' => 'actions',
        'actions' => $actions,
    ];
}

function ve_admin_form_field(string $type, string $name, string $label = '', string $value = '', array $extra = []): array
{
    return array_merge([
        'type' => $type,
        'name' => $name,
        'label' => $label,
        'value' => $value,
    ], $extra);
}

function ve_admin_request_is_partial(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function ve_admin_backend_overview_view_payload(string $activeSubview): array
{
    $snapshot = ve_admin_overview_snapshot();
    $rangeDays = ve_admin_request_range_days();
    $trend = ve_admin_service_trend_snapshot($rangeDays);
    $points = (array) ($trend['points'] ?? []);
    $range = (array) ($trend['range'] ?? []);
    $rangeLabel = gmdate('M j', strtotime(((string) ($range['from'] ?? gmdate('Y-m-d'))) . ' 00:00:00 UTC'))
        . ' to '
        . gmdate('M j', strtotime(((string) ($range['to'] ?? gmdate('Y-m-d'))) . ' 00:00:00 UTC'));
    $storageLabel = ve_human_bytes((int) ($snapshot['videos']['storage_bytes'] ?? 0));
    $trafficLabel = ve_human_bytes((int) ($trend['traffic_total_bytes'] ?? 0));
    $premiumTrafficLabel = ve_human_bytes((int) ($trend['premium_traffic_total_bytes'] ?? 0));
    $revenueLabel = ve_dashboard_format_currency_micro_usd((int) ($trend['earned_total_micro_usd'] ?? 0));
    $payoutDemandLabel = ve_dashboard_format_currency_micro_usd((int) ($trend['payout_amount_total_micro_usd'] ?? 0));
    $uploadedBytesLabel = ve_human_bytes((int) ($trend['uploaded_bytes_total'] ?? 0));
    $chartUsers = ve_admin_chart_payload($points, [
        ['key' => 'new_users', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'New users', 'format' => 'number'],
        ['key' => 'active_users', 'stroke' => '#d6d6d6', 'label' => 'Active users', 'format' => 'number'],
    ]);
    $chartUsage = ve_admin_chart_payload($points, [
        ['key' => 'views', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Views', 'format' => 'number'],
        ['key' => 'uploads', 'stroke' => '#8f8f8f', 'label' => 'Uploads', 'format' => 'number'],
    ]);
    $chartTraffic = ve_admin_chart_payload($points, [
        ['key' => 'bandwidth_bytes', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Traffic', 'format' => 'bytes'],
        ['key' => 'premium_bandwidth_bytes', 'stroke' => '#8ad0ff', 'label' => 'Premium traffic', 'format' => 'bytes'],
    ]);
    $metrics = [
        ve_admin_metric_payload('Accounts', (string) ($snapshot['users']['total_users'] ?? 0), (string) ($snapshot['users']['active_users'] ?? 0) . ' active'),
        ve_admin_metric_payload('Stored media', $storageLabel, (string) ($snapshot['videos']['ready_videos'] ?? 0) . ' ready files'),
        ve_admin_metric_payload('Traffic window', $trafficLabel, $rangeDays . ' day window'),
        ve_admin_metric_payload('Revenue window', $revenueLabel, $payoutDemandLabel . ' payout demand'),
    ];
    $period = ve_admin_period_switch_payload([7, 14, 30, 90], $rangeDays, $activeSubview);

    if ($activeSubview === 'overview-users') {
        return ve_admin_view_base_payload('Daily users', 'Account creation and active uploader behavior across ' . $rangeLabel . '.', $metrics, [], [[
            'type' => 'chart_cards',
            'period' => $period,
            'cards' => [[
                'title' => 'User movement',
                'subtitle' => 'New accounts vs active accounts per day.',
                'chart' => $chartUsers,
                'legend' => [
                    ['label' => 'New users avg/day', 'value' => ve_admin_number_label(ve_admin_series_average($points, 'new_users'), 1), 'color' => '#ff9900'],
                    ['label' => 'Peak active users', 'value' => (string) ve_admin_series_peak($points, 'active_users'), 'color' => '#d6d6d6'],
                ],
            ]],
        ]]);
    }

    if ($activeSubview === 'overview-usage') {
        return ve_admin_view_base_payload('Usage trends', 'Views and new uploads served across ' . $rangeLabel . '.', $metrics, [], [[
            'type' => 'chart_cards',
            'period' => $period,
            'cards' => [[
                'title' => 'Views and uploads',
                'subtitle' => 'Daily watch demand and files entering the library.',
                'chart' => $chartUsage,
                'legend' => [
                    ['label' => 'Average views/day', 'value' => ve_admin_number_label(ve_admin_series_average($points, 'views'), 0), 'color' => '#ff9900'],
                    ['label' => 'Uploads in window', 'value' => (string) ve_admin_series_total($points, 'uploads'), 'color' => '#8f8f8f'],
                ],
            ]],
        ]]);
    }

    if ($activeSubview === 'overview-traffic') {
        return ve_admin_view_base_payload('Traffic trends', 'Delivery load across ' . $rangeLabel . ', including premium-served bandwidth.', $metrics, [], [[
            'type' => 'chart_cards',
            'period' => $period,
            'cards' => [[
                'title' => 'Bandwidth served',
                'subtitle' => 'Combined traffic split by standard and premium delivery.',
                'chart' => $chartTraffic,
                'legend' => [
                    ['label' => 'Traffic total', 'value' => $trafficLabel, 'color' => '#ff9900'],
                    ['label' => 'Premium total', 'value' => $premiumTrafficLabel, 'color' => '#8ad0ff'],
                ],
            ]],
        ]]);
    }

    return ve_admin_view_base_payload('Service overview', 'Stable service totals and time-window movement grouped by operator concern.', $metrics, [], [
        [
            'type' => 'group_grid',
            'cards' => [
                ['title' => 'Accounts', 'description' => 'Current account mix and moderation demand.', 'items' => [
                    ['label' => 'Total accounts', 'value' => (string) ($snapshot['users']['total_users'] ?? 0)],
                    ['label' => 'Active accounts', 'value' => (string) ($snapshot['users']['active_users'] ?? 0)],
                    ['label' => 'Suspended accounts', 'value' => (string) ($snapshot['users']['suspended_users'] ?? 0)],
                    ['label' => 'Created today', 'value' => (string) ($snapshot['users']['users_today'] ?? 0)],
                ]],
                ['title' => 'Content & storage', 'description' => 'Library size and incoming media in the current window.', 'items' => [
                    ['label' => 'Stored files', 'value' => (string) ($snapshot['videos']['total_videos'] ?? 0)],
                    ['label' => 'Ready files', 'value' => (string) ($snapshot['videos']['ready_videos'] ?? 0)],
                    ['label' => 'Stored media', 'value' => $storageLabel],
                    ['label' => 'Uploaded in window', 'value' => $uploadedBytesLabel],
                ]],
                ['title' => 'Traffic & delivery', 'description' => 'Demand and current service pressure on the delivery plane.', 'items' => [
                    ['label' => 'Views served', 'value' => (string) ($trend['views_total'] ?? 0)],
                    ['label' => 'Traffic served', 'value' => $trafficLabel],
                    ['label' => 'Live watchers', 'value' => (string) ($trend['live_watchers'] ?? 0)],
                    ['label' => 'Active sessions', 'value' => (string) ($trend['active_sessions'] ?? 0)],
                ]],
                ['title' => 'Revenue & risk', 'description' => 'Financial and compliance queues that create operator load.', 'items' => [
                    ['label' => 'Revenue generated', 'value' => $revenueLabel],
                    ['label' => 'Payout demand', 'value' => $payoutDemandLabel],
                    ['label' => 'Open payouts', 'value' => (string) ($snapshot['payouts']['open_payouts'] ?? 0)],
                    ['label' => 'Open DMCA', 'value' => (string) ($snapshot['dmca']['open_notices'] ?? 0)],
                ]],
                ['title' => 'Infrastructure', 'description' => 'Capacity currently backing ingest and playback.', 'items' => [
                    ['label' => 'Storage nodes', 'value' => (string) ($snapshot['infrastructure']['storage_nodes'] ?? 0)],
                    ['label' => 'Upload endpoints', 'value' => (string) ($snapshot['infrastructure']['active_upload_endpoints'] ?? 0)],
                    ['label' => 'Delivery domains', 'value' => (string) ($snapshot['infrastructure']['active_delivery_domains'] ?? 0)],
                    ['label' => 'Premium traffic', 'value' => $premiumTrafficLabel],
                ]],
            ],
        ],
        [
            'type' => 'chart_cards',
            'title' => 'Time-window trends',
            'subtitle' => 'Rolling activity across ' . $rangeLabel . '.',
            'period' => $period,
            'columns' => 2,
            'cards' => [
                ['title' => 'Daily users', 'subtitle' => 'New accounts and active accounts.', 'chart' => $chartUsers, 'legend' => [
                    ['label' => 'Avg new users/day', 'value' => ve_admin_number_label(ve_admin_series_average($points, 'new_users'), 1), 'color' => '#ff9900'],
                    ['label' => 'Peak active users', 'value' => (string) ve_admin_series_peak($points, 'active_users'), 'color' => '#d6d6d6'],
                ]],
                ['title' => 'Daily traffic', 'subtitle' => 'Total and premium-served bandwidth.', 'chart' => $chartTraffic, 'legend' => [
                    ['label' => 'Traffic total', 'value' => $trafficLabel, 'color' => '#ff9900'],
                    ['label' => 'Peak daily traffic', 'value' => ve_human_bytes((int) ($trend['traffic_peak_bytes'] ?? 0)), 'color' => '#8ad0ff'],
                ]],
            ],
        ],
    ]);
}

function ve_admin_backend_user_detail_view_payload(array $actorUser, string $activeSubview, int $selectedUserId): array
{
    if ($selectedUserId <= 0) {
        return ve_admin_view_base_payload(
            'User detail',
            'Select a user from the directory to inspect a full operator profile.',
            [],
            [],
            [['type' => 'notice', 'message' => 'Choose a user from Directory or use a direct /backend/users-profile/{id} route.']]
        );
    }

    $profile = ve_admin_user_profile_snapshot($selectedUserId);

    if (!is_array($profile)) {
        return ve_admin_view_base_payload(
            'User detail',
            'The selected user could not be found.',
            [],
            [],
            [['type' => 'notice', 'tone' => 'warning', 'message' => 'The selected user could not be found.']]
        );
    }

    $detail = (array) ($profile['detail'] ?? []);
    $settings = (array) ($profile['settings'] ?? []);
    $counts = (array) ($profile['counts'] ?? []);
    $reports = (array) ($profile['reports'] ?? []);
    $apiUsage = (array) ($profile['api_usage'] ?? []);
    $token = ve_csrf_token();
    $returnTo = ve_admin_subsection_url($activeSubview, $selectedUserId, [], true);
    $actionUrl = ve_admin_subsection_url('users-profile', $selectedUserId, [], false);
    $actions = [];

    if (ve_user_has_permission($actorUser, 'admin.users.impersonate')) {
        $actions[] = ve_admin_action_form_payload('Impersonate user', $actionUrl, 'secondary', ve_admin_form_hidden_inputs([
            'token' => $token,
            'action' => 'impersonate_user',
            'user_id' => $selectedUserId,
            'return_to' => $returnTo,
        ]), 'fa-user-secret');
    }

    if (ve_user_has_permission($actorUser, 'admin.users.delete') && (int) ($actorUser['id'] ?? 0) !== $selectedUserId) {
        $actions[] = ve_admin_action_form_payload('Delete user', $actionUrl, 'danger', ve_admin_form_hidden_inputs([
            'token' => $token,
            'action' => 'delete_user',
            'user_id' => $selectedUserId,
            'return_to' => ve_admin_subsection_url('users-directory'),
        ]), 'fa-trash', 'Delete this user permanently?');
    }

    $metrics = [
        ve_admin_metric_payload('User ID', '#' . $selectedUserId, (string) ($detail['email'] ?? '')),
        ve_admin_metric_payload('Plan', (string) ($detail['plan_code'] ?? 'free'), ve_admin_role_label((string) ($detail['primary_role_code'] ?? 'user'))),
        ve_admin_metric_payload('Balance', ve_dashboard_format_currency_micro_usd((int) ($detail['balance_micro_usd'] ?? 0)), (string) ($counts['payout_open_total'] ?? 0) . ' open payouts'),
        ve_admin_metric_payload('Storage', ve_human_bytes((int) ($profile['storage_bytes'] ?? 0)), (string) ($counts['videos_total'] ?? 0) . ' files'),
    ];

    if ($activeSubview === 'users-activity') {
        $chartPoints = array_values((array) ($reports['chart'] ?? []));

        return ve_admin_view_base_payload('User activity', 'Traffic, views, and earnings for ' . (string) ($detail['username'] ?? 'user') . '.', $metrics, $actions, [
            ['type' => 'subnav', 'items' => ve_admin_user_detail_subnav_payload($selectedUserId, $activeSubview)],
            [
                'type' => 'chart_cards',
                'cards' => [[
                    'title' => 'Daily performance',
                    'subtitle' => 'Views, traffic, and direct earnings.',
                    'chart' => ve_admin_chart_payload($chartPoints, [
                        ['key' => 'views', 'stroke' => '#ff9900', 'fill' => 'rgba(255,153,0,0.08)', 'label' => 'Views', 'format' => 'number'],
                        ['key' => 'bandwidth_bytes', 'stroke' => '#8ad0ff', 'label' => 'Traffic', 'format' => 'bytes'],
                        ['key' => 'earned_micro_usd', 'stroke' => '#d6d6d6', 'label' => 'Earnings', 'format' => 'currency'],
                    ]),
                    'legend' => [
                        ['label' => 'Views', 'value' => (string) (($reports['totals']['views'] ?? 0)), 'color' => '#ff9900'],
                        ['label' => 'Traffic', 'value' => (string) (($reports['totals']['traffic'] ?? '0 B')), 'color' => '#8ad0ff'],
                        ['label' => 'Profit', 'value' => (string) (($reports['totals']['profit'] ?? '$0.00')), 'color' => '#d6d6d6'],
                    ],
                ]],
            ],
        ]);
    }

    if ($activeSubview === 'users-access') {
        $ledgerRows = [];
        foreach ((array) ($profile['ledger_entries'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ledgerRows[] = ['cells' => [
                ve_admin_text_cell((string) ($row['entry_type'] ?? 'entry')),
                ve_admin_text_cell(ve_dashboard_format_currency_micro_usd((int) ($row['amount_micro_usd'] ?? 0))),
                ve_admin_text_cell(ve_format_datetime_label((string) ($row['created_at'] ?? ''))),
            ]];
        }

        return ve_admin_view_base_payload('Access & billing', 'API state, payment destination, and recent balance ledger entries.', $metrics, $actions, [
            ['type' => 'subnav', 'items' => ve_admin_user_detail_subnav_payload($selectedUserId, $activeSubview)],
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'API access', 'items' => [
                    ['label' => 'Status', 'value' => (string) ($apiUsage['status_label'] ?? 'Unknown')],
                    ['label' => 'Requests / hour', 'value' => (string) (($apiUsage['limits']['requests_per_hour'] ?? 0))],
                    ['label' => 'Requests / day', 'value' => (string) (($apiUsage['limits']['requests_per_day'] ?? 0))],
                    ['label' => 'Last used', 'value' => (string) (($apiUsage['usage']['last_used_at'] ?? 'Never'))],
                ]],
                ['title' => 'Billing settings', 'items' => [
                    ['label' => 'Payout method', 'value' => (string) ($settings['payment_method'] ?? 'Not configured')],
                    ['label' => 'Destination', 'value' => ve_admin_mask_payout_destination((string) ($settings['payment_id'] ?? '')) ?: 'Not configured'],
                    ['label' => 'Open payouts', 'value' => (string) ($counts['payout_open_total'] ?? 0)],
                    ['label' => 'Total payout requests', 'value' => (string) ($counts['payout_total'] ?? 0)],
                ]],
                ['title' => 'Recent balance ledger', 'table' => [
                    'columns' => ['Type', 'Amount', 'Created'],
                    'rows' => $ledgerRows,
                    'empty' => 'No recent balance ledger entries.',
                ]],
            ]],
        ]);
    }

    if ($activeSubview === 'users-related') {
        $recentVideos = [];
        $recentRemote = [];
        $recentDomains = [];

        foreach ((array) ($detail['recent_videos'] ?? []) as $row) {
            if (is_array($row)) {
                $recentVideos[] = ['primary' => (string) ($row['title'] ?? $row['public_id'] ?? 'Video'), 'secondary' => (string) ($row['status'] ?? 'unknown') . ' / ' . ve_format_datetime_label((string) ($row['created_at'] ?? '')), 'href' => ve_admin_subsection_url('videos-detail', (int) ($row['id'] ?? 0))];
            }
        }

        foreach ((array) ($detail['recent_remote_uploads'] ?? []) as $row) {
            if (is_array($row)) {
                $recentRemote[] = ['primary' => (string) ($row['source_url'] ?? 'Remote upload'), 'secondary' => (string) ($row['status'] ?? 'unknown') . ' / ' . ve_format_datetime_label((string) ($row['created_at'] ?? '')), 'href' => ve_admin_subsection_url('remote-uploads-detail', (int) ($row['id'] ?? 0))];
            }
        }

        foreach ((array) ($detail['custom_domains'] ?? []) as $row) {
            if (is_array($row)) {
                $recentDomains[] = ['primary' => (string) ($row['domain'] ?? 'Domain'), 'secondary' => (string) ($row['status'] ?? 'unknown'), 'href' => ve_admin_subsection_url('domains-detail', (int) ($row['id'] ?? 0))];
            }
        }

        return ve_admin_view_base_payload('Related records', 'Recent files, remote uploads, domains, and account-linked records.', $metrics, $actions, [
            ['type' => 'subnav', 'items' => ve_admin_user_detail_subnav_payload($selectedUserId, $activeSubview)],
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Recent files', 'list' => $recentVideos],
                ['title' => 'Recent remote uploads', 'list' => $recentRemote],
                ['title' => 'Custom domains', 'list' => $recentDomains],
            ]],
        ]);
    }

    $roleOptions = [];
    foreach (ve_admin_role_catalog() as $code => $meta) {
        $roleOptions[] = ['value' => $code, 'label' => (string) ($meta['label'] ?? $code)];
    }

    $paymentMethodOptions = [];
    foreach (ve_allowed_payment_methods() as $method) {
        $paymentMethodOptions[] = ['value' => $method, 'label' => $method];
    }

    return ve_admin_view_base_payload('User profile', 'Full operator profile for ' . (string) ($detail['username'] ?? 'user') . '.', $metrics, $actions, [
        ['type' => 'subnav', 'items' => ve_admin_user_detail_subnav_payload($selectedUserId, $activeSubview)],
        ['type' => 'cards', 'layout' => 'grid', 'cards' => [
            ['title' => 'Identity', 'items' => [
                ['label' => 'Username', 'value' => (string) ($detail['username'] ?? '')],
                ['label' => 'Email', 'value' => (string) ($detail['email'] ?? '')],
                ['label' => 'Status', 'value' => (string) ($detail['status'] ?? 'active')],
                ['label' => 'Premium until', 'value' => ve_format_datetime_label((string) ($detail['premium_until'] ?? ''), 'Not set')],
            ]],
            ['title' => 'Service footprint', 'items' => [
                ['label' => 'Files', 'value' => (string) ($counts['videos_total'] ?? 0)],
                ['label' => 'Remote uploads', 'value' => (string) ($counts['remote_total'] ?? 0)],
                ['label' => 'DMCA notices', 'value' => (string) ($counts['dmca_total'] ?? 0)],
                ['label' => 'Active domains', 'value' => (string) ($counts['active_domain_total'] ?? 0)],
            ]],
            ['title' => 'Update account', 'form' => [
                'action' => $actionUrl,
                'method' => 'POST',
                'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'save_user', 'user_id' => $selectedUserId, 'return_to' => $returnTo]),
                'fields' => [
                    ve_admin_form_field('select', 'status', 'Status', (string) ($detail['status'] ?? 'active'), ['options' => [['value' => 'active', 'label' => 'Active'], ['value' => 'suspended', 'label' => 'Suspended']]]),
                    ve_admin_form_field('select', 'role_code', 'Role', (string) ($detail['primary_role_code'] ?? 'user'), ['options' => $roleOptions]),
                    ve_admin_form_field('text', 'plan_code', 'Plan code', (string) ($detail['plan_code'] ?? 'free')),
                    ve_admin_form_field('text', 'premium_until', 'Premium until (UTC)', (string) ($detail['premium_until'] ?? '')),
                    ve_admin_form_field('select', 'payment_method', 'Payout method', (string) ($settings['payment_method'] ?? 'Webmoney'), ['options' => $paymentMethodOptions]),
                    ve_admin_form_field('text', 'payment_id', 'Payout destination', (string) ($settings['payment_id'] ?? '')),
                    ve_admin_form_field('checkbox', 'api_enabled', 'API enabled', ((int) ($settings['api_enabled'] ?? 1)) === 1 ? '1' : '0', ['checked' => ((int) ($settings['api_enabled'] ?? 1)) === 1]),
                ],
                'actions' => [['type' => 'submit', 'label' => 'Save user', 'tone' => 'primary']],
            ]],
        ]],
    ]);
}

function ve_admin_backend_users_view_payload(array $actorUser, string $activeSubview): array
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $roleCode = trim((string) ($_GET['role'] ?? ''));
    $page = ve_admin_request_page();
    $selectedUserId = ve_admin_current_resource_id();
    $list = ve_admin_list_users($query, $status, $roleCode, $page);

    if (in_array($activeSubview, ['users-profile', 'users-activity', 'users-access', 'users-related'], true)) {
        return ve_admin_backend_user_detail_view_payload($actorUser, $activeSubview, $selectedUserId);
    }

    if ($activeSubview === 'users-segments') {
        $snapshot = ve_admin_user_segments_snapshot();
        $summary = (array) ($snapshot['summary'] ?? []);
        $newUsers = [];
        $leaders = [];

        foreach ((array) ($snapshot['new_users'] ?? []) as $row) {
            if (is_array($row)) {
                $newUsers[] = ['primary' => (string) ($row['username'] ?? ''), 'secondary' => (string) ($row['email'] ?? '') . ' / ' . ve_format_datetime_label((string) ($row['created_at'] ?? '')), 'href' => ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))];
            }
        }

        foreach ((array) ($snapshot['storage_leaders'] ?? []) as $row) {
            if (is_array($row)) {
                $leaders[] = ['primary' => (string) ($row['username'] ?? ''), 'secondary' => (string) ($row['video_total'] ?? 0) . ' files / ' . ve_human_bytes((int) ($row['storage_bytes'] ?? 0)), 'href' => ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))];
            }
        }

        return ve_admin_view_base_payload('User segments', 'Stable slices of the account base that help you understand who is using the service and where support load is likely to land.', [
            ve_admin_metric_payload('Total accounts', (string) ($summary['total_users'] ?? 0)),
            ve_admin_metric_payload('Paid-plan users', (string) ($summary['paid_plan_users'] ?? 0)),
            ve_admin_metric_payload('Branded users', (string) ($summary['branded_users'] ?? 0)),
            ve_admin_metric_payload('25+ file libraries', (string) ($summary['library_users'] ?? 0)),
        ], [], [
            ['type' => 'group_grid', 'cards' => [
                ['title' => 'Account state', 'description' => 'Core account health and moderation load.', 'items' => [
                    ['label' => 'Total accounts', 'value' => (string) ($summary['total_users'] ?? 0)],
                    ['label' => 'Active accounts', 'value' => (string) ($summary['active_users'] ?? 0)],
                    ['label' => 'Suspended accounts', 'value' => (string) ($summary['suspended_users'] ?? 0)],
                    ['label' => 'Joined last 30 days', 'value' => (string) ($summary['new_last_30_days'] ?? 0)],
                ]],
                ['title' => 'Commercial footprint', 'description' => 'Accounts most likely to need billing and API support.', 'items' => [
                    ['label' => 'Paid-plan users', 'value' => (string) ($summary['paid_plan_users'] ?? 0)],
                    ['label' => 'Premium-active users', 'value' => (string) ($summary['premium_users'] ?? 0)],
                    ['label' => 'API-enabled users', 'value' => (string) ($summary['api_enabled_users'] ?? 0)],
                    ['label' => 'Branded users', 'value' => (string) ($summary['branded_users'] ?? 0)],
                ]],
            ]],
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Newest accounts', 'list' => $newUsers],
                ['title' => 'Largest libraries', 'list' => $leaders],
            ]],
        ]);
    }

    if ($activeSubview === 'users-operations') {
        $days = ve_admin_request_range_days(30);
        $snapshot = ve_admin_user_operations_snapshot($days);
        $recentLogins = [];
        $topTraffic = [];
        $apiLeaders = [];

        foreach ((array) ($snapshot['recent_logins'] ?? []) as $row) {
            if (is_array($row)) {
                $recentLogins[] = ['cells' => [ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0)), (string) ($row['email'] ?? '')), ve_admin_text_cell(ve_format_datetime_label((string) ($row['last_login_at'] ?? '')))]];
            }
        }

        foreach ((array) ($snapshot['top_traffic'] ?? []) as $row) {
            if (is_array($row)) {
                $topTraffic[] = ['cells' => [ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))), ve_admin_text_cell((string) ($row['views_total'] ?? 0)), ve_admin_text_cell(ve_human_bytes((int) ($row['bandwidth_total'] ?? 0))), ve_admin_text_cell(ve_dashboard_format_currency_micro_usd((int) ($row['earnings_total'] ?? 0)))]];
            }
        }

        foreach ((array) ($snapshot['api_leaders'] ?? []) as $row) {
            if (is_array($row)) {
                $apiLeaders[] = ['cells' => [ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['id'] ?? 0))), ve_admin_text_cell(ve_format_datetime_label((string) ($row['api_key_last_used_at'] ?? ''), 'Never')), ve_admin_text_cell((string) ($row['api_requests_per_hour'] ?? 250) . '/hr'), ve_admin_text_cell((string) ($row['api_requests_per_day'] ?? 5000) . '/day')]];
            }
        }

        return ve_admin_view_base_payload('User operations', 'Recent logins, traffic leaders, and API-heavy accounts for the selected time window.', [
            ve_admin_metric_payload('Lookback window', (string) $days . ' days'),
            ve_admin_metric_payload('Recent logins', (string) count($recentLogins)),
            ve_admin_metric_payload('Traffic leaders', (string) count($topTraffic)),
            ve_admin_metric_payload('API leaders', (string) count($apiLeaders)),
        ], [], [
            ['type' => 'chart_cards', 'period' => ve_admin_period_switch_payload([7, 14, 30, 90], $days, 'users-operations'), 'cards' => []],
            ['type' => 'cards', 'layout' => 'stack', 'cards' => [
                ['title' => 'Recent logins', 'table' => ['columns' => ['User', 'Last login'], 'rows' => $recentLogins, 'empty' => 'No recent logins recorded.']],
                ['title' => 'Top traffic accounts', 'table' => ['columns' => ['User', 'Views', 'Bandwidth', 'Earnings'], 'rows' => $topTraffic, 'empty' => 'No user traffic recorded in this window.']],
                ['title' => 'API-heavy accounts', 'table' => ['columns' => ['User', 'Last used', 'Hourly limit', 'Daily limit'], 'rows' => $apiLeaders, 'empty' => 'No API usage recorded yet.']],
            ]],
        ]);
    }

    $rows = [];

    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userId = (int) ($row['id'] ?? 0);
        $detailUrl = ve_admin_subsection_url('users-profile', $userId, [], true);
        $rows[] = ['cells' => [
            ve_admin_link_cell('#' . $userId, $detailUrl),
            ve_admin_link_cell((string) ($row['username'] ?? ''), $detailUrl, (string) ($row['email'] ?? '')),
            ve_admin_status_cell((string) ($row['status'] ?? 'active')),
            ve_admin_text_cell(ve_admin_role_label((string) ($row['role_code'] ?? 'user'))),
            ve_admin_text_cell((string) ($row['plan_code'] ?? 'free')),
            ve_admin_text_cell((string) ($row['video_count'] ?? 0)),
            ve_admin_text_cell(ve_human_bytes((int) ($row['storage_bytes'] ?? 0))),
            ve_admin_text_cell(ve_format_datetime_label((string) ($row['last_login_at'] ?? ''), 'Never')),
        ]];
    }

    $roleOptions = [['value' => '', 'label' => 'All roles']];
    foreach (ve_admin_role_catalog() as $code => $meta) {
        $roleOptions[] = ['value' => (string) $code, 'label' => (string) ($meta['label'] ?? $code)];
    }

    return ve_admin_view_base_payload('User directory', 'Search accounts, isolate moderation cases, and jump into the full operator profile for a selected user.', [
        ve_admin_metric_payload('Visible users', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1), 'Page size ' . (string) (int) ($list['page_size'] ?? ve_admin_page_size())),
        ve_admin_metric_payload('Status filter', $status !== '' ? ucfirst($status) : 'All'),
        ve_admin_metric_payload('Role filter', $roleCode !== '' ? ve_admin_role_label($roleCode) : 'All roles'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('users-directory'), 'method' => 'GET', 'items' => [
            ve_admin_form_field('text', 'q', 'Search', $query, ['placeholder' => 'Username, email, or id']),
            ve_admin_form_field('select', 'status', 'Status', $status, ['options' => [['value' => '', 'label' => 'All statuses'], ['value' => 'active', 'label' => 'Active'], ['value' => 'suspended', 'label' => 'Suspended']]]),
            ve_admin_form_field('select', 'role', 'Role', $roleCode, ['options' => $roleOptions]),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('users-directory'), 'admin_nav' => true],
        ]],
        ['type' => 'table', 'columns' => ['ID', 'User', 'Status', 'Role', 'Plan', 'Files', 'Storage', 'Last login'], 'rows' => $rows, 'empty' => 'No users matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'users-directory', null, ['q' => $query, 'status' => $status, 'role' => $roleCode])],
    ]);
}

function ve_admin_backend_videos_view_payload(string $activeSubview): array
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = ve_admin_request_page();
    $selectedVideoId = ve_admin_current_resource_id();
    $list = ve_admin_list_videos($query, $status, 0, $page);

    if ($activeSubview === 'videos-detail') {
        $detail = $selectedVideoId > 0 ? ve_admin_video_detail($selectedVideoId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('File detail', 'Select a file from the library to inspect ownership and moderation state.', [], [], [['type' => 'notice', 'message' => 'Choose a file from the library to inspect it.']]);
        }

        $token = ve_csrf_token();
        $actionUrl = ve_admin_subsection_url('videos-detail', $selectedVideoId, [], false);
        $toggleAction = ((int) ($detail['is_public'] ?? 0)) === 1 ? 'make_video_private' : 'make_video_public';
        $toggleLabel = ((int) ($detail['is_public'] ?? 0)) === 1 ? 'Make private' : 'Make public';
        $actions = [
            ve_admin_action_form_payload($toggleLabel, $actionUrl, 'secondary', ve_admin_form_hidden_inputs([
                'token' => $token,
                'action' => $toggleAction,
                'video_id' => $selectedVideoId,
                'return_to' => ve_admin_subsection_url('videos-detail', $selectedVideoId, [], true),
            ]), 'fa-eye'),
            ve_admin_action_form_payload('Delete file', $actionUrl, 'danger', ve_admin_form_hidden_inputs([
                'token' => $token,
                'action' => 'delete_video',
                'video_id' => $selectedVideoId,
                'return_to' => ve_admin_subsection_url('videos-library'),
            ]), 'fa-trash', 'Delete this file permanently?'),
        ];

        return ve_admin_view_base_payload('File detail', 'Ownership, storage footprint, and moderation actions for the selected file.', [
            ve_admin_metric_payload('Video ID', '#' . $selectedVideoId, (string) ($detail['public_id'] ?? '')),
            ve_admin_metric_payload('Owner', (string) ($detail['username'] ?? ''), 'User #' . (string) ($detail['user_id'] ?? 0)),
            ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
            ve_admin_metric_payload('Size', ve_human_bytes((int) (($detail['processed_size_bytes'] ?? 0) > 0 ? $detail['processed_size_bytes'] : ($detail['original_size_bytes'] ?? 0)))),
        ], $actions, [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'File facts', 'items' => [
                    ['label' => 'Title', 'value' => (string) ($detail['title'] ?? 'Untitled')],
                    ['label' => 'Public ID', 'value' => (string) ($detail['public_id'] ?? '')],
                    ['label' => 'Status', 'value' => (string) ($detail['status'] ?? 'unknown')],
                    ['label' => 'Visibility', 'value' => ((int) ($detail['is_public'] ?? 0)) === 1 ? 'Public' : 'Private'],
                    ['label' => 'Created', 'value' => ve_format_datetime_label((string) ($detail['created_at'] ?? ''))],
                ]],
                ['title' => 'Storage & delivery', 'items' => [
                    ['label' => 'Original size', 'value' => ve_human_bytes((int) ($detail['original_size_bytes'] ?? 0))],
                    ['label' => 'Processed size', 'value' => ve_human_bytes((int) ($detail['processed_size_bytes'] ?? 0))],
                    ['label' => 'Folder', 'value' => (string) ($detail['folder_id'] ?? 0)],
                    ['label' => 'Owner', 'value' => (string) ($detail['username'] ?? '')],
                ]],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $videoId = (int) ($row['id'] ?? 0);
        $detailUrl = ve_admin_subsection_url('videos-detail', $videoId, [], true);
        $rows[] = ['cells' => [
            ve_admin_link_cell('#' . $videoId, $detailUrl),
            ve_admin_link_cell((string) ($row['title'] ?? $row['public_id'] ?? 'Untitled'), $detailUrl, (string) ($row['public_id'] ?? '')),
            ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['user_id'] ?? 0))),
            ve_admin_status_cell((string) ($row['status'] ?? 'unknown')),
            ve_admin_text_cell(ve_human_bytes((int) (($row['processed_size_bytes'] ?? 0) > 0 ? $row['processed_size_bytes'] : ($row['original_size_bytes'] ?? 0)))),
            ve_admin_text_cell(((int) ($row['is_public'] ?? 0)) === 1 ? 'Public' : 'Private'),
        ]];
    }

    return ve_admin_view_base_payload('Files and videos', 'Moderate stored content with direct access to file ownership and moderation actions.', [
        ve_admin_metric_payload('Visible files', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
        ve_admin_metric_payload('Status filter', $status !== '' ? $status : 'All'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('videos-library'), 'method' => 'GET', 'items' => [
            ve_admin_form_field('text', 'q', 'Search', $query, ['placeholder' => 'Title, public id, or owner']),
            ve_admin_form_field('text', 'status', 'Status', $status, ['placeholder' => 'ready, queued, processing']),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('videos-library'), 'admin_nav' => true],
        ]],
        ['type' => 'table', 'columns' => ['ID', 'File', 'Owner', 'Status', 'Size', 'Access'], 'rows' => $rows, 'empty' => 'No files matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'videos-library', null, ['q' => $query, 'status' => $status])],
    ]);
}

function ve_admin_backend_remote_uploads_view_payload(string $activeSubview): array
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = ve_admin_request_page();
    $selectedJobId = ve_admin_current_resource_id();
    $list = ve_admin_list_remote_uploads($query, $status, 0, $page);

    if ($activeSubview === 'remote-uploads-detail') {
        $detail = $selectedJobId > 0 ? ve_admin_remote_upload_detail($selectedJobId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('Remote upload detail', 'Select a remote upload job to inspect source and retry state.', [], [], [['type' => 'notice', 'message' => 'Choose a remote upload job from the queue.']]);
        }

        $token = ve_csrf_token();
        $actionUrl = ve_admin_subsection_url('remote-uploads-detail', $selectedJobId, [], false);
        $actions = [
            ve_admin_action_form_payload('Retry job', $actionUrl, 'primary', ve_admin_form_hidden_inputs([
                'token' => $token,
                'action' => 'retry_remote_upload',
                'job_id' => $selectedJobId,
                'return_to' => ve_admin_subsection_url('remote-uploads-detail', $selectedJobId, [], true),
            ]), 'fa-redo'),
            ve_admin_action_form_payload('Delete job', $actionUrl, 'danger', ve_admin_form_hidden_inputs([
                'token' => $token,
                'action' => 'delete_remote_upload',
                'job_id' => $selectedJobId,
                'return_to' => ve_admin_subsection_url('remote-uploads-queue'),
            ]), 'fa-trash', 'Delete this remote upload job?'),
        ];

        return ve_admin_view_base_payload('Remote upload detail', 'Source reliability, progress, and resolution information for the selected job.', [
            ve_admin_metric_payload('Job ID', '#' . $selectedJobId),
            ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
            ve_admin_metric_payload('Owner', (string) ($detail['username'] ?? ''), 'User #' . (string) ($detail['user_id'] ?? 0)),
            ve_admin_metric_payload('Progress', number_format((float) ($detail['progress_percent'] ?? 0), 1) . '%'),
        ], $actions, [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Job facts', 'items' => [
                    ['label' => 'Source URL', 'value' => (string) ($detail['source_url'] ?? '')],
                    ['label' => 'Resolved URL', 'value' => (string) ($detail['resolved_url'] ?? 'Not resolved')],
                    ['label' => 'Status message', 'value' => (string) ($detail['status_message'] ?? '')],
                    ['label' => 'Error message', 'value' => (string) ($detail['error_message'] ?? 'None')],
                ]],
                ['title' => 'Transfer state', 'items' => [
                    ['label' => 'Bytes downloaded', 'value' => ve_human_bytes((int) ($detail['bytes_downloaded'] ?? 0))],
                    ['label' => 'Bytes total', 'value' => ve_human_bytes((int) ($detail['bytes_total'] ?? 0))],
                    ['label' => 'Speed', 'value' => ve_human_bytes((int) ($detail['speed_bytes_per_second'] ?? 0)) . '/s'],
                    ['label' => 'Video', 'value' => (string) ($detail['video_public_id'] ?? 'Not attached')],
                ]],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobId = (int) ($row['id'] ?? 0);
        $detailUrl = ve_admin_subsection_url('remote-uploads-detail', $jobId, [], true);
        $rows[] = ['cells' => [
            ve_admin_link_cell('#' . $jobId, $detailUrl),
            ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['user_id'] ?? 0))),
            ve_admin_text_cell((string) ($row['source_url'] ?? '')),
            ve_admin_status_cell((string) ($row['status'] ?? 'unknown')),
            ve_admin_text_cell(number_format((float) ($row['progress_percent'] ?? 0), 1) . '%'),
            ve_admin_text_cell((string) ($row['video_public_id'] ?? '')),
        ]];
    }

    return ve_admin_view_base_payload('Remote uploads', 'Track queued imports, broken sources, and ingest throughput without leaving the backend.', [
        ve_admin_metric_payload('Visible jobs', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
        ve_admin_metric_payload('Status filter', $status !== '' ? $status : 'All'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('remote-uploads-queue'), 'method' => 'GET', 'items' => [
            ve_admin_form_field('text', 'q', 'Search', $query, ['placeholder' => 'Source URL, public id, or owner']),
            ve_admin_form_field('text', 'status', 'Status', $status, ['placeholder' => 'pending, downloading, error']),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('remote-uploads-queue'), 'admin_nav' => true],
        ]],
        ['type' => 'table', 'columns' => ['Job', 'User', 'Source', 'Status', 'Progress', 'Video'], 'rows' => $rows, 'empty' => 'No remote upload jobs matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'remote-uploads-queue', null, ['q' => $query, 'status' => $status])],
    ]);
}

function ve_admin_backend_dmca_view_payload(string $activeSubview): array
{
    $status = trim((string) ($_GET['status'] ?? ''));
    $query = trim((string) ($_GET['q'] ?? ''));
    $page = ve_admin_request_page();
    $selectedNoticeId = ve_admin_current_resource_id();
    $list = ve_admin_list_dmca_notices($status, $query, $page);

    if (in_array($activeSubview, ['dmca-detail', 'dmca-events'], true)) {
        $detail = $selectedNoticeId > 0 ? ve_admin_dmca_detail($selectedNoticeId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('DMCA case', 'Select a case from the queue to inspect the record and its event history.', [], [], [['type' => 'notice', 'message' => 'Choose a DMCA case from the queue.']]);
        }

        if ($activeSubview === 'dmca-events') {
            $eventCards = [];
            foreach ((array) ($detail['events'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $eventCards[] = [
                    'title' => (string) ($row['title'] ?? ($row['event_type'] ?? 'Event')),
                    'description' => ve_format_datetime_label((string) ($row['created_at'] ?? '')),
                    'text' => (string) ($row['note'] ?? ''),
                    'items' => [
                        ['label' => 'Type', 'value' => (string) ($row['event_type'] ?? 'event')],
                    ],
                ];
            }

            return ve_admin_view_base_payload('DMCA events', 'Case timeline and operator-facing event history.', [
                ve_admin_metric_payload('Case', (string) ($detail['case_code'] ?? '')),
                ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
                ve_admin_metric_payload('Events', (string) count((array) ($detail['events'] ?? []))),
            ], [ve_admin_action_link_payload('Case detail', ve_admin_subsection_url('dmca-detail', $selectedNoticeId, [], true), 'secondary', 'fa-arrow-left')], [
                ['type' => 'cards', 'layout' => 'stack', 'cards' => $eventCards],
            ]);
        }

        $token = ve_csrf_token();
        $actionUrl = ve_admin_subsection_url('dmca-detail', $selectedNoticeId, [], false);
        $statusOptions = [];
        foreach (ve_dmca_notice_status_catalog() as $code => $label) {
            $statusOptions[] = ['value' => (string) $code, 'label' => (string) $label];
        }

        return ve_admin_view_base_payload('DMCA case detail', 'Complaint data, stored payload, and operator workflow for the selected notice.', [
            ve_admin_metric_payload('Case code', (string) ($detail['case_code'] ?? '')),
            ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
            ve_admin_metric_payload('User', (string) ($detail['user_id'] ?? 0)),
            ve_admin_metric_payload('Received', ve_format_datetime_label((string) ($detail['received_at'] ?? ''))),
        ], [ve_admin_action_link_payload('Open events', ve_admin_subsection_url('dmca-events', $selectedNoticeId, [], true), 'secondary', 'fa-stream')], [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Case facts', 'items' => [
                    ['label' => 'Reported URL', 'value' => (string) ($detail['reported_url'] ?? '')],
                    ['label' => 'Video title', 'value' => (string) ($detail['video_title_snapshot'] ?? '')],
                    ['label' => 'Complainant', 'value' => (string) ($detail['complainant_name'] ?? '')],
                    ['label' => 'Complainant email', 'value' => (string) ($detail['complainant_email'] ?? '')],
                ]],
                ['title' => 'Update case', 'form' => [
                    'action' => $actionUrl,
                    'method' => 'POST',
                    'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'update_dmca_status', 'notice_id' => $selectedNoticeId, 'return_to' => ve_admin_subsection_url('dmca-detail', $selectedNoticeId, [], true)]),
                    'fields' => [
                        ve_admin_form_field('select', 'status', 'Status', (string) ($detail['status'] ?? ''), ['options' => $statusOptions]),
                        ve_admin_form_field('textarea', 'note', 'Operator note', ''),
                    ],
                    'actions' => [['type' => 'submit', 'label' => 'Save case update', 'tone' => 'primary']],
                ]],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $noticeId = (int) ($row['id'] ?? 0);
        $rows[] = ['cells' => [
            ve_admin_link_cell((string) ($row['case_code'] ?? ''), ve_admin_subsection_url('dmca-detail', $noticeId, [], true)),
            ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['user_id'] ?? 0))),
            ve_admin_text_cell((string) ($row['video_title'] ?? $row['video_title_snapshot'] ?? '')),
            ve_admin_status_cell((string) ($row['status'] ?? 'unknown')),
            ve_admin_text_cell(ve_format_datetime_label((string) ($row['received_at'] ?? ''))),
        ]];
    }

    return ve_admin_view_base_payload('DMCA operations', 'Work the complaint queue with direct access to case detail and event history.', [
        ve_admin_metric_payload('Visible cases', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
        ve_admin_metric_payload('Status filter', $status !== '' ? $status : 'All'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('dmca-queue'), 'method' => 'GET', 'items' => [
            ve_admin_form_field('text', 'q', 'Search', $query, ['placeholder' => 'Case code, url, title, or user']),
            ve_admin_form_field('text', 'status', 'Status', $status, ['placeholder' => 'open, resolved, pending_review']),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('dmca-queue'), 'admin_nav' => true],
        ]],
        ['type' => 'table', 'columns' => ['Case', 'User', 'Video', 'Status', 'Received'], 'rows' => $rows, 'empty' => 'No DMCA notices matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'dmca-queue', null, ['q' => $query, 'status' => $status])],
    ]);
}

function ve_admin_backend_payouts_view_payload(string $activeSubview): array
{
    $status = trim((string) ($_GET['status'] ?? ''));
    $userId = max(0, (int) ($_GET['user_id'] ?? 0));
    $page = ve_admin_request_page();
    $selectedRequestId = ve_admin_current_resource_id();
    $list = ve_admin_list_payouts($status, $userId, $page);

    if (in_array($activeSubview, ['payouts-detail', 'payouts-transfer'], true)) {
        $detail = $selectedRequestId > 0 ? ve_admin_payout_request_by_id($selectedRequestId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('Payout request', 'Select a payout request from the queue to inspect approval and transfer state.', [], [], [['type' => 'notice', 'message' => 'Choose a payout request from the queue.']]);
        }

        $transfer = ve_admin_payout_transfer_by_request_id($selectedRequestId);

        if ($activeSubview === 'payouts-transfer') {
            return ve_admin_view_base_payload('Transfer tracking', 'Settlement and transfer information for the selected payout request.', [
                ve_admin_metric_payload('Request', (string) ($detail['public_id'] ?? '')),
                ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
                ve_admin_metric_payload('Transfer row', is_array($transfer) ? (string) ($transfer['status'] ?? 'sent') : 'Not created'),
            ], [ve_admin_action_link_payload('Request detail', ve_admin_subsection_url('payouts-detail', $selectedRequestId, [], true), 'secondary', 'fa-arrow-left')], [
                ['type' => 'cards', 'layout' => 'grid', 'cards' => [[
                    'title' => 'Transfer data',
                    'items' => [
                        ['label' => 'Reference', 'value' => (string) ($detail['transfer_reference'] ?? '')],
                        ['label' => 'Gross', 'value' => ve_dashboard_format_currency_micro_usd((int) ($detail['amount_micro_usd'] ?? 0))],
                        ['label' => 'Fee', 'value' => is_array($transfer) ? ve_dashboard_format_currency_micro_usd((int) ($transfer['fee_micro_usd'] ?? 0)) : '$0.00'],
                        ['label' => 'Net', 'value' => is_array($transfer) ? ve_dashboard_format_currency_micro_usd((int) ($transfer['net_amount_micro_usd'] ?? 0)) : ve_dashboard_format_currency_micro_usd((int) ($detail['amount_micro_usd'] ?? 0))],
                    ],
                ]]],
            ]);
        }

        $token = ve_csrf_token();
        $actionUrl = ve_admin_subsection_url('payouts-detail', $selectedRequestId, [], false);

        return ve_admin_view_base_payload('Payout request detail', 'Approval, rejection, and settlement actions for the selected payout request.', [
            ve_admin_metric_payload('Request', (string) ($detail['public_id'] ?? '')),
            ve_admin_metric_payload('Amount', ve_dashboard_format_currency_micro_usd((int) ($detail['amount_micro_usd'] ?? 0))),
            ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
            ve_admin_metric_payload('User', (string) ($detail['username'] ?? ''), (string) ($detail['email'] ?? '')),
        ], [ve_admin_action_link_payload('Transfer tracking', ve_admin_subsection_url('payouts-transfer', $selectedRequestId, [], true), 'secondary', 'fa-exchange-alt')], [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Request facts', 'items' => [
                    ['label' => 'Method', 'value' => (string) ($detail['payout_method'] ?? '')],
                    ['label' => 'Destination', 'value' => (string) ($detail['payout_destination_masked'] ?? '')],
                    ['label' => 'Created', 'value' => ve_format_datetime_label((string) ($detail['created_at'] ?? ''))],
                    ['label' => 'Reviewed', 'value' => ve_format_datetime_label((string) ($detail['reviewed_at'] ?? ''), 'Not reviewed')],
                ]],
                ['title' => 'Approve request', 'form' => [
                    'action' => $actionUrl, 'method' => 'POST',
                    'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'approve_payout', 'request_id' => $selectedRequestId, 'return_to' => ve_admin_subsection_url('payouts-detail', $selectedRequestId, [], true)]),
                    'fields' => [ve_admin_form_field('textarea', 'notes', 'Operator note', (string) ($detail['notes'] ?? ''))],
                    'actions' => [['type' => 'submit', 'label' => 'Approve payout', 'tone' => 'primary']],
                ]],
                ['title' => 'Reject request', 'form' => [
                    'action' => $actionUrl, 'method' => 'POST',
                    'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'reject_payout', 'request_id' => $selectedRequestId, 'return_to' => ve_admin_subsection_url('payouts-detail', $selectedRequestId, [], true)]),
                    'fields' => [ve_admin_form_field('text', 'rejection_reason', 'Rejection reason', ''), ve_admin_form_field('textarea', 'notes', 'Operator note', '')],
                    'actions' => [['type' => 'submit', 'label' => 'Reject payout', 'tone' => 'danger']],
                ]],
                ['title' => 'Mark paid', 'form' => [
                    'action' => $actionUrl, 'method' => 'POST',
                    'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'mark_payout_paid', 'request_id' => $selectedRequestId, 'return_to' => ve_admin_subsection_url('payouts-detail', $selectedRequestId, [], true)]),
                    'fields' => [ve_admin_form_field('text', 'transfer_reference', 'Transfer reference', (string) ($detail['transfer_reference'] ?? '')), ve_admin_form_field('text', 'fee_amount', 'Fee amount (USD)', is_array($transfer) ? number_format(((int) ($transfer['fee_micro_usd'] ?? 0)) / 1000000, 2, '.', '') : '0.00'), ve_admin_form_field('textarea', 'notes', 'Operator note', '')],
                    'actions' => [['type' => 'submit', 'label' => 'Mark payout paid', 'tone' => 'primary']],
                ]],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $requestId = (int) ($row['id'] ?? 0);
        $rows[] = ['cells' => [
            ve_admin_link_cell((string) ($row['public_id'] ?? ''), ve_admin_subsection_url('payouts-detail', $requestId, [], true)),
            ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['user_id'] ?? 0)), (string) ($row['email'] ?? '')),
            ve_admin_text_cell((string) ($row['payout_method'] ?? '')),
            ve_admin_text_cell(ve_dashboard_format_currency_micro_usd((int) ($row['amount_micro_usd'] ?? 0))),
            ve_admin_status_cell((string) ($row['status'] ?? 'unknown')),
            ve_admin_text_cell(ve_format_datetime_label((string) ($row['created_at'] ?? ''))),
        ]];
    }

    return ve_admin_view_base_payload('Payout operations', 'Review payout requests with direct access to approval and settlement actions.', [
        ve_admin_metric_payload('Visible requests', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
        ve_admin_metric_payload('Status filter', $status !== '' ? $status : 'All'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('payouts-queue'), 'method' => 'GET', 'items' => array_values(array_filter([
            $userId > 0 ? ve_admin_form_field('hidden', 'user_id', '', (string) $userId) : null,
            ve_admin_form_field('text', 'status', 'Status', $status, ['placeholder' => 'pending, approved, paid']),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('payouts-queue'), 'admin_nav' => true],
        ]))],
        ['type' => 'table', 'columns' => ['Request', 'User', 'Method', 'Amount', 'Status', 'Created'], 'rows' => $rows, 'empty' => 'No payout requests matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'payouts-queue', null, ['status' => $status, 'user_id' => $userId > 0 ? $userId : null])],
    ]);
}

function ve_admin_backend_domains_view_payload(string $activeSubview): array
{
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = ve_admin_request_page();
    $selectedDomainId = ve_admin_current_resource_id();
    $list = ve_admin_list_custom_domains($status, $query, $page);

    if ($activeSubview === 'domains-detail') {
        $detail = $selectedDomainId > 0 ? ve_admin_custom_domain_detail($selectedDomainId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('Domain detail', 'Select a domain from the directory to inspect DNS and owner state.', [], [], [['type' => 'notice', 'message' => 'Choose a domain from the directory.']]);
        }

        $token = ve_csrf_token();
        $actionUrl = ve_admin_subsection_url('domains-detail', $selectedDomainId, [], false);
        $actions = [
            ve_admin_action_form_payload('Refresh DNS', $actionUrl, 'primary', ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'refresh_domain', 'domain_id' => $selectedDomainId, 'return_to' => ve_admin_subsection_url('domains-detail', $selectedDomainId, [], true)]), 'fa-sync'),
            ve_admin_action_form_payload('Delete domain', $actionUrl, 'danger', ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'delete_domain', 'domain_id' => $selectedDomainId, 'return_to' => ve_admin_subsection_url('domains-directory')]), 'fa-trash', 'Delete this domain mapping?'),
        ];

        return ve_admin_view_base_payload('Domain detail', 'DNS readiness and ownership data for the selected custom domain.', [
            ve_admin_metric_payload('Domain', (string) ($detail['domain'] ?? '')),
            ve_admin_metric_payload('Status', (string) ($detail['status'] ?? 'unknown')),
            ve_admin_metric_payload('Owner', (string) ($detail['username'] ?? ''), (string) ($detail['email'] ?? '')),
        ], $actions, [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'DNS state', 'items' => [
                    ['label' => 'Stored target', 'value' => (string) ($detail['dns_target'] ?? '')],
                    ['label' => 'Platform target', 'value' => (string) (ve_config()['custom_domain_target'] ?? '')],
                    ['label' => 'Last checked', 'value' => ve_format_datetime_label((string) ($detail['dns_last_checked_at'] ?? ''), 'Never')],
                    ['label' => 'Resolver error', 'value' => (string) ($detail['dns_check_error'] ?? 'None')],
                ]],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $domainId = (int) ($row['id'] ?? 0);
        $rows[] = ['cells' => [
            ve_admin_link_cell((string) ($row['domain'] ?? ''), ve_admin_subsection_url('domains-detail', $domainId, [], true)),
            ve_admin_link_cell((string) ($row['username'] ?? ''), ve_admin_subsection_url('users-profile', (int) ($row['user_id'] ?? 0))),
            ve_admin_status_cell((string) ($row['status'] ?? 'unknown')),
            ve_admin_text_cell((string) ($row['dns_target'] ?? '')),
            ve_admin_text_cell(ve_format_datetime_label((string) ($row['dns_last_checked_at'] ?? ''), 'Never')),
        ]];
    }

    return ve_admin_view_base_payload('Domain operations', 'Track custom domains, validate DNS resolution, and remove broken mappings.', [
        ve_admin_metric_payload('Visible domains', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
        ve_admin_metric_payload('Status filter', $status !== '' ? $status : 'All'),
    ], [], [
        ['type' => 'toolbar', 'action' => ve_admin_subsection_url('domains-directory'), 'method' => 'GET', 'items' => [
            ve_admin_form_field('text', 'q', 'Search', $query, ['placeholder' => 'Domain or uploader']),
            ve_admin_form_field('text', 'status', 'Status', $status, ['placeholder' => 'active, pending_dns, lookup_failed']),
            ['type' => 'submit', 'label' => 'Apply filters', 'tone' => 'primary'],
            ['type' => 'link', 'label' => 'Reset', 'tone' => 'secondary', 'href' => ve_admin_subsection_url('domains-directory'), 'admin_nav' => true],
        ]],
        ['type' => 'table', 'columns' => ['Domain', 'User', 'Status', 'DNS target', 'Last checked'], 'rows' => $rows, 'empty' => 'No domains matched the current filters.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'domains-directory', null, ['q' => $query, 'status' => $status])],
    ]);
}

function ve_admin_backend_app_view_payload(string $activeSubview): array
{
    $token = ve_csrf_token();
    $settings = [];

    foreach (ve_admin_default_settings() as $key => $defaultValue) {
        $settings[$key] = (string) (ve_get_app_setting($key, (string) $defaultValue) ?? (string) $defaultValue);
    }

    if ($activeSubview === 'app-roles') {
        $rows = [];
        foreach (ve_admin_role_catalog() as $code => $meta) {
            $rows[] = ['cells' => [
                ve_admin_code_cell((string) $code),
                ve_admin_text_cell((string) ($meta['label'] ?? $code)),
                ve_admin_text_cell(implode(', ', array_map(static fn ($item): string => (string) $item, (array) ($meta['permissions'] ?? [])))),
            ]];
        }

        return ve_admin_view_base_payload('Role catalog', 'Primary backend roles and their built-in permission sets.', [
            ve_admin_metric_payload('Roles', (string) count(ve_admin_role_catalog())),
        ], [], [
            ['type' => 'table', 'columns' => ['Code', 'Label', 'Permissions'], 'rows' => $rows, 'empty' => 'No roles defined.'],
        ]);
    }

    if ($activeSubview === 'app-permissions') {
        $rows = [];
        foreach (ve_admin_permission_catalog() as $code => $meta) {
            $rows[] = ['cells' => [
                ve_admin_code_cell((string) $code),
                ve_admin_text_cell((string) ($meta['label'] ?? $code)),
                ve_admin_text_cell((string) ($meta['group_code'] ?? 'core')),
            ]];
        }

        return ve_admin_view_base_payload('Permission inventory', 'Permission codes available to backend roles and bootstrap admins.', [
            ve_admin_metric_payload('Permission codes', (string) count(ve_admin_permission_catalog())),
        ], [], [
            ['type' => 'table', 'columns' => ['Code', 'Label', 'Group'], 'rows' => $rows, 'empty' => 'No permission codes defined.'],
        ]);
    }

    return ve_admin_view_base_payload('App settings', 'Backend operating thresholds and bootstrap access defaults.', [
        ve_admin_metric_payload('Bootstrap admins', implode(', ', ve_admin_bootstrap_logins()) ?: 'None'),
        ve_admin_metric_payload('Custom domain target', (string) (ve_config()['custom_domain_target'] ?? '')),
    ], [], [
        ['type' => 'cards', 'layout' => 'stack', 'cards' => [[
            'title' => 'Operator thresholds',
            'form' => [
                'action' => ve_admin_subsection_url('app-general', null, [], false),
                'method' => 'POST',
                'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'save_app_settings', 'return_to' => ve_admin_subsection_url('app-general', null, [], true)]),
                'fields' => [
                    ve_admin_form_field('text', 'payout_minimum_micro_usd', 'Payout minimum (micro USD)', $settings['payout_minimum_micro_usd'] ?? ''),
                    ve_admin_form_field('text', 'admin_default_page_size', 'Default page size', $settings['admin_default_page_size'] ?? ''),
                    ve_admin_form_field('text', 'admin_recent_audit_limit', 'Recent audit limit', $settings['admin_recent_audit_limit'] ?? ''),
                    ve_admin_form_field('text', 'remote_max_queue_per_user', 'Remote queue max per user', $settings['remote_max_queue_per_user'] ?? ''),
                ],
                'actions' => [['type' => 'submit', 'label' => 'Save app settings', 'tone' => 'primary']],
            ],
        ]]],
    ]);
}

function ve_admin_backend_infrastructure_view_payload(string $activeSubview): array
{
    $snapshot = ve_admin_infrastructure_snapshot();
    $nodes = (array) ($snapshot['storage_nodes'] ?? []);
    $volumes = (array) ($snapshot['storage_volumes'] ?? []);
    $endpoints = (array) ($snapshot['upload_endpoints'] ?? []);
    $deliveryDomains = (array) ($snapshot['delivery_domains'] ?? []);
    $maintenanceWindows = (array) ($snapshot['maintenance_windows'] ?? []);
    $token = ve_csrf_token();

    $nodeOptions = [];
    foreach ($nodes as $node) {
        if (is_array($node)) {
            $nodeOptions[] = ['value' => (string) ($node['id'] ?? 0), 'label' => (string) ($node['hostname'] ?? ('Node #' . ($node['id'] ?? 0)))];
        }
    }

    $metrics = [
        ve_admin_metric_payload('Storage nodes', (string) count($nodes)),
        ve_admin_metric_payload('Volumes', (string) count($volumes)),
        ve_admin_metric_payload('Upload endpoints', (string) count($endpoints)),
        ve_admin_metric_payload('Delivery domains', (string) count($deliveryDomains), (string) count($maintenanceWindows) . ' maintenance windows'),
    ];

    if ($activeSubview === 'infra-volumes') {
        $rows = [];
        foreach ($volumes as $row) {
            if (is_array($row)) {
                $rows[] = ['cells' => [
                    ve_admin_text_cell((string) ($row['hostname'] ?? '')),
                    ve_admin_text_cell((string) ($row['code'] ?? '')),
                    ve_admin_text_cell((string) ($row['mount_path'] ?? '')),
                    ve_admin_status_cell((string) ($row['health_status'] ?? 'healthy')),
                    ve_admin_text_cell(ve_human_bytes((int) ($row['used_bytes'] ?? 0)) . ' / ' . ve_human_bytes((int) ($row['capacity_bytes'] ?? 0))),
                ]];
            }
        }

        return ve_admin_view_base_payload('Storage volumes', 'Capacity, mount paths, and health state for configured storage volumes.', $metrics, [], [
            ['type' => 'table', 'columns' => ['Node', 'Code', 'Mount path', 'Health', 'Usage'], 'rows' => $rows, 'empty' => 'No storage volumes configured.'],
        ]);
    }

    if ($activeSubview === 'infra-endpoints') {
        $rows = [];
        foreach ($endpoints as $row) {
            if (is_array($row)) {
                $rows[] = ['cells' => [
                    ve_admin_text_cell((string) ($row['code'] ?? '')),
                    ve_admin_text_cell((string) ($row['hostname'] ?? '')),
                    ve_admin_text_cell((string) ($row['protocol'] ?? 'https') . '://' . (string) ($row['host'] ?? '') . (string) ($row['path_prefix'] ?? '')),
                    ve_admin_text_cell(((int) ($row['is_active'] ?? 0)) === 1 ? 'Active' : 'Inactive'),
                    ve_admin_text_cell((string) ($row['weight'] ?? 0)),
                    ve_admin_text_cell(ve_human_bytes((int) ($row['max_file_size_bytes'] ?? 0))),
                ]];
            }
        }

        return ve_admin_view_base_payload('Upload endpoints', 'Ingest endpoints, routing weight, and per-node upload capacity.', $metrics, [], [
            ['type' => 'table', 'columns' => ['Code', 'Node', 'Address', 'Status', 'Weight', 'Max file size'], 'rows' => $rows, 'empty' => 'No upload endpoints configured.'],
        ]);
    }

    if ($activeSubview === 'infra-delivery') {
        $rows = [];
        foreach ($deliveryDomains as $row) {
            if (is_array($row)) {
                $rows[] = ['cells' => [
                    ve_admin_text_cell((string) ($row['domain'] ?? '')),
                    ve_admin_text_cell((string) ($row['purpose'] ?? 'watch')),
                    ve_admin_status_cell((string) ($row['status'] ?? 'active')),
                    ve_admin_text_cell((string) ($row['tls_mode'] ?? 'managed')),
                ]];
            }
        }

        return ve_admin_view_base_payload('Delivery domains', 'Streaming and download domains used by the delivery plane.', $metrics, [], [
            ['type' => 'table', 'columns' => ['Domain', 'Purpose', 'Status', 'TLS mode'], 'rows' => $rows, 'empty' => 'No delivery domains configured.'],
        ]);
    }

    if ($activeSubview === 'infra-maintenance') {
        $rows = [];
        foreach ($maintenanceWindows as $row) {
            if (is_array($row)) {
                $rows[] = ['cells' => [
                    ve_admin_text_cell((string) ($row['hostname'] ?? '')),
                    ve_admin_text_cell((string) ($row['mode'] ?? 'drain')),
                    ve_admin_text_cell(ve_format_datetime_label((string) ($row['starts_at'] ?? ''))),
                    ve_admin_text_cell(ve_format_datetime_label((string) ($row['ends_at'] ?? ''))),
                    ve_admin_text_cell((string) ($row['reason'] ?? '')),
                    ve_admin_actions_cell([ve_admin_action_form_payload('Delete', ve_admin_subsection_url('infra-maintenance', null, [], false), 'danger', ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'delete_maintenance_window', 'maintenance_window_id' => (int) ($row['id'] ?? 0), 'return_to' => ve_admin_subsection_url('infra-maintenance', null, [], true)]), '', 'Delete this maintenance window?')]),
                ]];
            }
        }

        return ve_admin_view_base_payload('Maintenance windows', 'Scheduled node maintenance and drain windows.', $metrics, [], [
            ['type' => 'cards', 'layout' => 'stack', 'cards' => [[
                'title' => 'Schedule maintenance window',
                'form' => [
                    'action' => ve_admin_subsection_url('infra-maintenance', null, [], false),
                    'method' => 'POST',
                    'hidden' => ve_admin_form_hidden_inputs(['token' => $token, 'action' => 'add_maintenance_window', 'return_to' => ve_admin_subsection_url('infra-maintenance', null, [], true)]),
                    'fields' => [
                        ve_admin_form_field('select', 'storage_node_id', 'Node', '', ['options' => $nodeOptions]),
                        ve_admin_form_field('text', 'starts_at', 'Start (UTC)', '', ['placeholder' => 'YYYY-MM-DD HH:MM:SS']),
                        ve_admin_form_field('text', 'ends_at', 'End (UTC)', '', ['placeholder' => 'YYYY-MM-DD HH:MM:SS']),
                        ve_admin_form_field('select', 'mode', 'Mode', 'drain', ['options' => [['value' => 'drain', 'label' => 'Drain'], ['value' => 'offline', 'label' => 'Offline']]]),
                        ve_admin_form_field('textarea', 'reason', 'Reason', ''),
                    ],
                    'actions' => [['type' => 'submit', 'label' => 'Schedule maintenance', 'tone' => 'primary']],
                ],
            ]]],
            ['type' => 'table', 'columns' => ['Node', 'Mode', 'Starts', 'Ends', 'Reason', 'Action'], 'rows' => $rows, 'empty' => 'No maintenance windows scheduled.'],
        ]);
    }

    $rows = [];
    foreach ($nodes as $row) {
        if (is_array($row)) {
            $rows[] = ['cells' => [
                ve_admin_text_cell((string) ($row['code'] ?? '')),
                ve_admin_text_cell((string) ($row['hostname'] ?? '')),
                ve_admin_status_cell((string) ($row['health_status'] ?? 'healthy')),
                ve_admin_text_cell(ve_human_bytes((int) ($row['used_bytes'] ?? 0)) . ' / ' . ve_human_bytes((int) ($row['available_bytes'] ?? 0))),
                ve_admin_text_cell((string) ($row['max_ingest_qps'] ?? 0)),
                ve_admin_text_cell((string) ($row['max_stream_qps'] ?? 0)),
            ]];
        }
    }

    return ve_admin_view_base_payload('Infrastructure', 'Operate the storage and delivery plane from one backend surface.', $metrics, [], [
        ['type' => 'table', 'columns' => ['Code', 'Hostname', 'Health', 'Capacity', 'Ingest QPS', 'Stream QPS'], 'rows' => $rows, 'empty' => 'No storage nodes configured.'],
    ]);
}

function ve_admin_backend_audit_view_payload(string $activeSubview): array
{
    $page = ve_admin_request_page();
    $selectedLogId = ve_admin_current_resource_id();
    $list = ve_admin_list_audit_logs($page);

    if ($activeSubview === 'audit-detail') {
        $detail = $selectedLogId > 0 ? ve_admin_audit_log_detail($selectedLogId) : null;

        if (!is_array($detail)) {
            return ve_admin_view_base_payload('Audit detail', 'Select an audit record from the log feed to inspect actor and payload changes.', [], [], [['type' => 'notice', 'message' => 'Choose an audit log entry from the feed.']]);
        }

        $beforeDecoded = json_decode((string) ($detail['before_json'] ?? '{}'), true);
        $afterDecoded = json_decode((string) ($detail['after_json'] ?? '{}'), true);

        return ve_admin_view_base_payload('Audit detail', 'Actor, target, and before/after payload for the selected backend action.', [
            ve_admin_metric_payload('Actor', (string) ($detail['actor_username'] ?? 'System')),
            ve_admin_metric_payload('Event code', (string) ($detail['event_code'] ?? '')),
            ve_admin_metric_payload('IP', (string) ($detail['ip_address'] ?? '')),
        ], [], [
            ['type' => 'cards', 'layout' => 'grid', 'cards' => [
                ['title' => 'Record facts', 'items' => [
                    ['label' => 'Target type', 'value' => (string) ($detail['target_type'] ?? '')],
                    ['label' => 'Target ID', 'value' => (string) ($detail['target_id'] ?? 0)],
                    ['label' => 'Created', 'value' => ve_format_datetime_label((string) ($detail['created_at'] ?? ''))],
                ]],
                ['title' => 'Before payload', 'code' => json_encode(is_array($beforeDecoded) ? $beforeDecoded : ['value' => $detail['before_json'] ?? ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                ['title' => 'After payload', 'code' => json_encode(is_array($afterDecoded) ? $afterDecoded : ['value' => $detail['after_json'] ?? ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ]],
        ]);
    }

    $rows = [];
    foreach ((array) ($list['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = ['cells' => [
            ve_admin_link_cell(ve_format_datetime_label((string) ($row['created_at'] ?? '')), ve_admin_subsection_url('audit-detail', (int) ($row['id'] ?? 0), [], true)),
            ve_admin_text_cell((string) ($row['actor_username'] ?? 'System')),
            ve_admin_text_cell((string) ($row['event_code'] ?? '')),
            ve_admin_text_cell((string) ($row['target_type'] ?? '') . ' #' . (string) ($row['target_id'] ?? 0)),
            ve_admin_text_cell((string) ($row['ip_address'] ?? '')),
        ]];
    }

    return ve_admin_view_base_payload('Audit log feed', 'Recent backend actions with actor, target, and IP context.', [
        ve_admin_metric_payload('Visible entries', (string) (int) ($list['total'] ?? 0)),
        ve_admin_metric_payload('Current page', (string) (int) ($list['page'] ?? 1)),
    ], [], [
        ['type' => 'table', 'columns' => ['Time', 'Actor', 'Event', 'Target', 'IP'], 'rows' => $rows, 'empty' => 'No audit logs recorded yet.', 'pagination' => ve_admin_table_pagination_payload((int) ($list['page'] ?? 1), (int) ($list['total'] ?? 0), (int) ($list['page_size'] ?? ve_admin_page_size()), 'audit-feed')],
    ]);
}

function ve_admin_backend_view_payload(array $actorUser, string $activeSection, string $activeSubview): array
{
    return match ($activeSection) {
        'overview' => ve_admin_backend_overview_view_payload($activeSubview),
        'users' => ve_admin_backend_users_view_payload($actorUser, $activeSubview),
        'videos' => ve_admin_backend_videos_view_payload($activeSubview),
        'remote-uploads' => ve_admin_backend_remote_uploads_view_payload($activeSubview),
        'dmca' => ve_admin_backend_dmca_view_payload($activeSubview),
        'payouts' => ve_admin_backend_payouts_view_payload($activeSubview),
        'domains' => ve_admin_backend_domains_view_payload($activeSubview),
        'app' => ve_admin_backend_app_view_payload($activeSubview),
        'infrastructure' => ve_admin_backend_infrastructure_view_payload($activeSubview),
        'audit' => ve_admin_backend_audit_view_payload($activeSubview),
        default => ve_admin_view_base_payload('Backend', 'This backend section is not available.', [], [], [['type' => 'notice', 'message' => 'This backend section is not available.']]),
    };
}

function ve_admin_partial_payload(
    array $actorUser,
    array $context,
    string $activeSection,
    string $title,
    string $sidebarIntroHtml,
    string $menuHtml,
    string $contentHtml
): array {
    return [
        'status' => 'ok',
        'title' => $title,
        'active_section' => $activeSection,
        'header_nav_html' => (string) ($context['header_nav_html'] ?? ''),
        'header_action_html' => (string) ($context['header_action_html'] ?? ''),
        'mobile_nav_html' => (string) ($context['mobile_nav_html'] ?? ''),
        'sidebar_html' => '<div class="sidebar settings-page">' . $sidebarIntroHtml . '<hr><div class="menu settings_menu"><ul class="p-0 m-0 mb-4">' . $menuHtml . '</ul></div></div>',
        'content_html' => '<div class="details settings_data">' . $contentHtml . '</div>',
        'widgets_html' => '',
        'user_menu_html' => ve_admin_user_dropdown_html([
            'dmca_url' => ve_h(ve_url('/dmca-manager')),
            'api_docs_url' => ve_h(ve_url('/api-docs')),
            'referrals_url' => ve_h(ve_url('/referrals')),
            'settings_url' => ve_h(ve_url('/settings')),
            'logout_url' => ve_h(ve_url('/logout')),
        ]),
        'username' => ve_h((string) ($context['username'] ?? ($actorUser['username'] ?? 'videoengine'))),
    ];
}

function ve_admin_post_redirect_target(): string
{
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    $adminBase = ve_url('/backend');

    foreach ([$adminBase, '/backend'] as $prefix) {
        if ($returnTo === $prefix || str_starts_with($returnTo, $prefix . '/') || str_starts_with($returnTo, $prefix . '?')) {
            return $returnTo;
        }
    }

    return $adminBase;
}

function ve_handle_backend_request(): void
{
    $actorUser = ve_admin_require_permission();

    if (ve_is_method('POST')) {
        ve_require_csrf(ve_request_csrf_token());

        $action = trim((string) ($_POST['action'] ?? ''));
        $actorUserId = (int) ($actorUser['id'] ?? 0);

        try {
            switch ($action) {
                case 'stop_impersonation':
                    ve_admin_stop_impersonation();
                    ve_flash('success', 'Impersonation stopped.');
                    break;

                case 'save_user':
                    if (!ve_user_has_permission($actorUser, 'admin.users.manage')) {
                        throw new RuntimeException('You do not have permission to manage users.');
                    }

                    ve_admin_save_user_profile((int) ($_POST['user_id'] ?? 0), $_POST, $actorUserId);
                    ve_flash('success', 'User updated successfully.');
                    break;

                case 'impersonate_user':
                    if (!ve_user_has_permission($actorUser, 'admin.users.impersonate')) {
                        throw new RuntimeException('You do not have permission to impersonate users.');
                    }

                    if (!ve_admin_start_impersonation($actorUser, (int) ($_POST['user_id'] ?? 0))) {
                        throw new RuntimeException('Unable to impersonate that user.');
                    }

                    ve_flash('success', 'Impersonation started.');
                    ve_redirect(ve_url('/dashboard'));

                case 'delete_user':
                    if (!ve_user_has_permission($actorUser, 'admin.users.delete')) {
                        throw new RuntimeException('You do not have permission to delete users.');
                    }

                    ve_admin_delete_user_forever((int) ($_POST['user_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'User deleted permanently.');
                    break;

                case 'make_video_private':
                    if (!ve_user_has_permission($actorUser, 'admin.videos.manage')) {
                        throw new RuntimeException('You do not have permission to manage videos.');
                    }

                    ve_admin_set_video_visibility((int) ($_POST['video_id'] ?? 0), false, $actorUserId);
                    ve_flash('success', 'Video is now private.');
                    break;

                case 'make_video_public':
                    if (!ve_user_has_permission($actorUser, 'admin.videos.manage')) {
                        throw new RuntimeException('You do not have permission to manage videos.');
                    }

                    ve_admin_set_video_visibility((int) ($_POST['video_id'] ?? 0), true, $actorUserId);
                    ve_flash('success', 'Video is now public.');
                    break;

                case 'delete_video':
                    if (!ve_user_has_permission($actorUser, 'admin.videos.delete')) {
                        throw new RuntimeException('You do not have permission to delete videos.');
                    }

                    ve_admin_delete_video((int) ($_POST['video_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Video deleted permanently.');
                    break;

                case 'retry_remote_upload':
                    if (!ve_user_has_permission($actorUser, 'admin.remote_uploads.manage')) {
                        throw new RuntimeException('You do not have permission to manage remote uploads.');
                    }

                    ve_admin_retry_remote_upload((int) ($_POST['job_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Remote job queued again.');
                    break;

                case 'delete_remote_upload':
                    if (!ve_user_has_permission($actorUser, 'admin.remote_uploads.manage')) {
                        throw new RuntimeException('You do not have permission to manage remote uploads.');
                    }

                    ve_admin_delete_remote_upload((int) ($_POST['job_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Remote job deleted.');
                    break;

                case 'update_dmca_status':
                    if (!ve_user_has_permission($actorUser, 'admin.dmca.manage')) {
                        throw new RuntimeException('You do not have permission to manage DMCA cases.');
                    }

                    ve_admin_update_dmca_status(
                        (int) ($_POST['notice_id'] ?? 0),
                        trim((string) ($_POST['status'] ?? '')),
                        trim((string) ($_POST['note'] ?? '')),
                        $actorUserId
                    );
                    ve_flash('success', 'DMCA case updated.');
                    break;

                case 'approve_payout':
                case 'reject_payout':
                case 'mark_payout_paid':
                    if (!ve_user_has_permission($actorUser, 'admin.payouts.manage')) {
                        throw new RuntimeException('You do not have permission to manage payout requests.');
                    }

                    ve_admin_update_payout_request((int) ($_POST['request_id'] ?? 0), $action, $_POST, $actorUserId);
                    ve_flash('success', 'Payout request updated.');
                    break;

                case 'refresh_domain':
                    if (!ve_user_has_permission($actorUser, 'admin.domains.manage')) {
                        throw new RuntimeException('You do not have permission to manage domains.');
                    }

                    ve_admin_refresh_custom_domain((int) ($_POST['domain_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Domain DNS status refreshed.');
                    break;

                case 'delete_domain':
                    if (!ve_user_has_permission($actorUser, 'admin.domains.manage')) {
                        throw new RuntimeException('You do not have permission to manage domains.');
                    }

                    ve_admin_delete_custom_domain((int) ($_POST['domain_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Domain mapping deleted.');
                    break;

                case 'save_app_settings':
                    if (!ve_user_has_permission($actorUser, 'admin.settings.manage')) {
                        throw new RuntimeException('You do not have permission to manage app settings.');
                    }

                    ve_admin_save_app_settings($_POST, $actorUserId);
                    ve_flash('success', 'App settings saved.');
                    break;

                case 'save_storage_node':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_upsert_storage_node($_POST, $actorUserId);
                    ve_flash('success', 'Storage node saved.');
                    break;

                case 'delete_storage_node':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_delete_storage_node((int) ($_POST['storage_node_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Storage node deleted.');
                    break;

                case 'save_storage_volume':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_upsert_storage_volume($_POST, $actorUserId);
                    ve_flash('success', 'Storage volume saved.');
                    break;

                case 'delete_storage_volume':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_delete_storage_volume((int) ($_POST['storage_volume_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Storage volume deleted.');
                    break;

                case 'save_upload_endpoint':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_upsert_upload_endpoint($_POST, $actorUserId);
                    ve_flash('success', 'Upload endpoint saved.');
                    break;

                case 'delete_upload_endpoint':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_delete_upload_endpoint((int) ($_POST['upload_endpoint_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Upload endpoint deleted.');
                    break;

                case 'save_delivery_domain':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_upsert_delivery_domain($_POST, $actorUserId);
                    ve_flash('success', 'Delivery domain saved.');
                    break;

                case 'delete_delivery_domain':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_delete_delivery_domain((int) ($_POST['delivery_domain_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Delivery domain deleted.');
                    break;

                case 'add_maintenance_window':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_add_maintenance_window($_POST, $actorUserId);
                    ve_flash('success', 'Maintenance window added.');
                    break;

                case 'delete_maintenance_window':
                    if (!ve_user_has_permission($actorUser, 'admin.infrastructure.manage')) {
                        throw new RuntimeException('You do not have permission to manage infrastructure.');
                    }

                    ve_admin_delete_maintenance_window((int) ($_POST['maintenance_window_id'] ?? 0), $actorUserId);
                    ve_flash('success', 'Maintenance window deleted.');
                    break;

                default:
                    throw new RuntimeException('Unknown backend action.');
            }
        } catch (Throwable $exception) {
            ve_flash('danger', $exception->getMessage());
        }

        ve_redirect(ve_admin_post_redirect_target());
    }

    $activeSection = ve_admin_current_section($actorUser);
    $currentUser = ve_current_user();
    $shellUser = is_array($currentUser) ? $currentUser : $actorUser;
    $context = ve_admin_dashboard_shell_context($shellUser);
    $context['header_nav_html'] = ve_admin_backend_header_nav_html($actorUser, $activeSection);
    $context['header_action_html'] = ve_admin_impersonation_stop_control_html('btn btn-sm btn-secondary admin-stop-button', true);
    $context['mobile_nav_html'] = ve_admin_backend_mobile_nav_html($actorUser, $activeSection);
    $activeSubview = ve_admin_current_subview_slug($activeSection);
    $viewPayload = ve_admin_backend_view_payload($actorUser, $activeSection, $activeSubview);
    $title = (string) (($viewPayload['title'] ?? (ve_admin_sections()[$activeSection]['label'] ?? 'Backend')) . ' - Video Engine');
    $sidebarHtml = ve_admin_backend_sidebar_collection_html($actorUser, $activeSection);
    $contentHtml = ve_admin_backend_content_shell_html($actorUser, $activeSubview);
    $bootHtml = ve_admin_backend_boot_script_html(ve_admin_backend_boot_config($actorUser, $activeSection, $activeSubview));
    $widgetsHtml = '';

    if (ve_admin_request_wants_json()) {
        ve_json([
            'status' => 'ok',
            'title' => $title,
            'active_section' => $activeSection,
            'active_subview' => $activeSubview,
            'sidebar_subview' => ve_admin_sidebar_active_subview($activeSection, $activeSubview),
            'resource' => ve_admin_current_resource_token(),
            'view' => $viewPayload,
        ]);
    }

    ve_html(ve_rewrite_html_paths(ve_admin_dashboard_shell(
        $context,
        $title,
        $sidebarHtml,
        $contentHtml,
        $widgetsHtml,
        $bootHtml
    )));
}
