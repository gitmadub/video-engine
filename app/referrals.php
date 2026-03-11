<?php

declare(strict_types=1);

const VE_REFERRAL_PENDING_SESSION = 've_referral_pending_code';
const VE_REFERRAL_COOKIE = 've_referral';

function ve_referral_rate_basis_points(): int
{
    static $basisPoints;

    if (is_int($basisPoints)) {
        return $basisPoints;
    }

    $basisPoints = max(0, min(10000, (int) (getenv('VE_REFERRAL_RATE_BASIS_POINTS') ?: 1000)));

    return $basisPoints;
}

function ve_referral_premium_rate_basis_points(): int
{
    static $basisPoints;

    if (is_int($basisPoints)) {
        return $basisPoints;
    }

    $basisPoints = max(0, min(10000, (int) (getenv('VE_REFERRAL_PREMIUM_RATE_BASIS_POINTS') ?: 3000)));

    return $basisPoints;
}

function ve_referral_code_length(): int
{
    static $length;

    if (is_int($length)) {
        return $length;
    }

    $length = max(8, min(20, (int) (getenv('VE_REFERRAL_CODE_LENGTH') ?: 10)));

    return $length;
}

function ve_referral_cookie_ttl_seconds(): int
{
    static $ttl;

    if (is_int($ttl)) {
        return $ttl;
    }

    $ttl = max(86400, (int) (getenv('VE_REFERRAL_COOKIE_TTL_SECONDS') ?: 2592000));

    return $ttl;
}

function ve_referral_normalize_code(?string $code): string
{
    $code = strtolower(trim((string) $code));
    $code = preg_replace('/[^a-z0-9]/', '', $code) ?? '';

    return $code;
}

function ve_referral_is_valid_code(?string $code): bool
{
    $code = ve_referral_normalize_code($code);

    return $code !== '' && preg_match('/^[a-z0-9]{6,32}$/', $code) === 1;
}

function ve_find_user_by_referral_code(string $code): ?array
{
    $code = ve_referral_normalize_code($code);

    if (!ve_referral_is_valid_code($code)) {
        return null;
    }

    $stmt = ve_db()->prepare(
        'SELECT *
         FROM users
         WHERE referral_code = :referral_code
           AND status = :status
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':referral_code' => $code,
        ':status' => 'active',
    ]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function ve_referral_generate_code(): string
{
    $length = ve_referral_code_length();

    do {
        $candidate = substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    } while ($candidate === '' || ve_find_user_by_referral_code($candidate) !== null);

    return $candidate;
}

function ve_referral_unique_violation(PDOException $exception): bool
{
    $code = trim((string) $exception->getCode());

    if ($code === '19' || $code === '23000') {
        return true;
    }

    return stripos($exception->getMessage(), 'unique') !== false;
}

function ve_referral_ensure_user_code(int $userId): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid user id is required.');
    }

    $stmt = ve_db()->prepare('SELECT referral_code FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $currentCode = ve_referral_normalize_code((string) $stmt->fetchColumn());

    if (ve_referral_is_valid_code($currentCode)) {
        return $currentCode;
    }

    $update = ve_db()->prepare(
        'UPDATE users
         SET referral_code = :referral_code,
             updated_at = :updated_at
         WHERE id = :id
           AND COALESCE(referral_code, "") = ""'
    );

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $generated = ve_referral_generate_code();

        try {
            $update->execute([
                ':referral_code' => $generated,
                ':updated_at' => ve_now(),
                ':id' => $userId,
            ]);
        } catch (PDOException $exception) {
            if (ve_referral_unique_violation($exception)) {
                continue;
            }

            throw $exception;
        }

        $stmt->execute([':id' => $userId]);
        $resolvedCode = ve_referral_normalize_code((string) $stmt->fetchColumn());

        if (ve_referral_is_valid_code($resolvedCode)) {
            return $resolvedCode;
        }
    }

    throw new RuntimeException('Unable to assign a referral code.');
}

function ve_referrals_run_database_migrations(PDO $pdo): void
{
    ve_add_column_if_missing($pdo, 'users', 'referral_code', 'TEXT COLLATE NOCASE DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'referred_by_user_id', 'INTEGER DEFAULT NULL');
    ve_add_column_if_missing($pdo, 'users', 'referred_at', 'TEXT DEFAULT NULL');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS referral_earnings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            referrer_user_id INTEGER NOT NULL,
            referred_user_id INTEGER NOT NULL,
            source_type TEXT NOT NULL,
            source_key TEXT NOT NULL UNIQUE,
            amount_micro_usd INTEGER NOT NULL DEFAULT 0,
            stat_date TEXT NOT NULL,
            metadata_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL,
            FOREIGN KEY (referrer_user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE CASCADE
        )'
    );

    $userIds = $pdo->query('SELECT id FROM users WHERE COALESCE(referral_code, "") = ""')->fetchAll(PDO::FETCH_COLUMN);

    if (is_array($userIds)) {
        foreach ($userIds as $userId) {
            ve_referral_ensure_user_code((int) $userId);
        }
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_referred_by_created ON users(referred_by_user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_referral_earnings_referrer_date ON referral_earnings(referrer_user_id, stat_date DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_referral_earnings_referred_created ON referral_earnings(referred_user_id, created_at DESC)');
}

function ve_referral_cookie_options(int $expiresAt): array
{
    return [
        'expires' => $expiresAt,
        'path' => ve_base_path() !== '' ? ve_base_path() . '/' : '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function ve_referral_set_pending_code(string $code): void
{
    $code = ve_referral_normalize_code($code);

    if (!ve_referral_is_valid_code($code)) {
        ve_referral_clear_pending_code();
        return;
    }

    $_SESSION[VE_REFERRAL_PENDING_SESSION] = $code;
    setcookie(VE_REFERRAL_COOKIE, $code, ve_referral_cookie_options(ve_timestamp() + ve_referral_cookie_ttl_seconds()));
}

function ve_referral_clear_pending_code(): void
{
    unset($_SESSION[VE_REFERRAL_PENDING_SESSION]);
    setcookie(VE_REFERRAL_COOKIE, '', ve_referral_cookie_options(ve_timestamp() - 3600));
}

function ve_referral_pending_code(): ?string
{
    $sessionCode = ve_referral_normalize_code((string) ($_SESSION[VE_REFERRAL_PENDING_SESSION] ?? ''));

    if (ve_referral_is_valid_code($sessionCode)) {
        return $sessionCode;
    }

    $cookieCode = ve_referral_normalize_code((string) ($_COOKIE[VE_REFERRAL_COOKIE] ?? ''));

    if (!ve_referral_is_valid_code($cookieCode)) {
        return null;
    }

    $_SESSION[VE_REFERRAL_PENDING_SESSION] = $cookieCode;

    return $cookieCode;
}

function ve_referral_pending_referrer(): ?array
{
    $code = ve_referral_pending_code();

    if ($code === null) {
        return null;
    }

    $user = ve_find_user_by_referral_code($code);

    if (!is_array($user)) {
        ve_referral_clear_pending_code();
        return null;
    }

    return $user;
}

function ve_referral_handle_join(string $code): void
{
    $referrer = ve_find_user_by_referral_code($code);

    if (!is_array($referrer)) {
        ve_not_found();
    }

    $currentUser = ve_current_user();

    if (is_array($currentUser) && (int) $currentUser['id'] === (int) $referrer['id']) {
        ve_flash('warning', 'This is your own referral link.');
        ve_redirect('/dashboard/referrals');
    }

    $normalizedCode = ve_referral_normalize_code((string) $referrer['referral_code']);
    ve_referral_set_pending_code($normalizedCode);

    $destination = ve_url('/?ref=' . rawurlencode($normalizedCode));
    ve_redirect($destination . '#register');
}

function ve_referral_apply_pending_to_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    ve_referral_ensure_user_code($userId);

    $user = ve_get_user_by_id($userId);

    if (!is_array($user)) {
        ve_referral_clear_pending_code();
        return null;
    }

    if (!empty($user['referred_by_user_id'])) {
        ve_referral_clear_pending_code();
        return null;
    }

    $referrer = ve_referral_pending_referrer();

    if (!is_array($referrer) || (int) $referrer['id'] === $userId) {
        ve_referral_clear_pending_code();
        return null;
    }

    $stmt = ve_db()->prepare(
        'UPDATE users
         SET referred_by_user_id = :referred_by_user_id,
             referred_at = :referred_at,
             updated_at = :updated_at
         WHERE id = :id
           AND referred_by_user_id IS NULL'
    );
    $stmt->execute([
        ':referred_by_user_id' => (int) $referrer['id'],
        ':referred_at' => ve_now(),
        ':updated_at' => ve_now(),
        ':id' => $userId,
    ]);

    ve_referral_clear_pending_code();

    if ($stmt->rowCount() < 1) {
        return null;
    }

    $referrerName = (string) ($referrer['username'] ?? 'your referrer');
    $username = (string) ($user['username'] ?? 'A new user');
    ve_add_notification((int) $referrer['id'], 'New referral joined', '"' . $username . '" signed up using your referral link.');
    ve_add_notification($userId, 'Referral link applied', 'Your account is linked to ' . $referrerName . '\'s referral program.');

    return $referrer;
}

function ve_referral_find_referrer_for_user(int $userId): ?array
{
    $stmt = ve_db()->prepare(
        'SELECT referrer.*
         FROM users referred
         INNER JOIN users referrer ON referrer.id = referred.referred_by_user_id
         WHERE referred.id = :id
           AND referrer.status = :status
           AND referrer.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $userId,
        ':status' => 'active',
    ]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function ve_referral_insert_commission(
    int $referrerUserId,
    int $referredUserId,
    string $sourceType,
    string $sourceKey,
    int $amountMicroUsd,
    ?string $statDate = null,
    array $metadata = []
): ?array {
    $sourceKey = trim($sourceKey);
    $sourceType = trim($sourceType);
    $amountMicroUsd = max(0, $amountMicroUsd);
    $statDate = is_string($statDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statDate) === 1 ? $statDate : gmdate('Y-m-d');

    if ($referrerUserId <= 0 || $referredUserId <= 0 || $sourceType === '' || $sourceKey === '' || $amountMicroUsd <= 0) {
        return null;
    }

    $payload = [
        'referrer_user_id' => $referrerUserId,
        'referred_user_id' => $referredUserId,
        'source_type' => $sourceType,
        'source_key' => $sourceKey,
        'amount_micro_usd' => $amountMicroUsd,
        'stat_date' => $statDate,
        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => ve_now(),
    ];

    $stmt = ve_db()->prepare(
        'INSERT INTO referral_earnings (
            referrer_user_id, referred_user_id, source_type, source_key, amount_micro_usd,
            stat_date, metadata_json, created_at
         ) VALUES (
            :referrer_user_id, :referred_user_id, :source_type, :source_key, :amount_micro_usd,
            :stat_date, :metadata_json, :created_at
         )'
    );

    try {
        $stmt->execute($payload);
    } catch (PDOException $exception) {
        if (ve_referral_unique_violation($exception)) {
            return null;
        }

        throw $exception;
    }

    if (function_exists('ve_dashboard_record_referral_earning')) {
        ve_dashboard_record_referral_earning($referrerUserId, $amountMicroUsd, $statDate);
    }

    return $payload;
}

function ve_referral_record_video_view_commission(
    string $sourceKey,
    int $videoId,
    int $referredUserId,
    int $earnedMicroUsd,
    ?string $statDate = null
): ?array {
    $referrer = ve_referral_find_referrer_for_user($referredUserId);

    if (!is_array($referrer)) {
        return null;
    }

    $amountMicroUsd = (int) round(max(0, $earnedMicroUsd) * (ve_referral_rate_basis_points() / 10000));

    if ($amountMicroUsd <= 0) {
        return null;
    }

    return ve_referral_insert_commission(
        (int) $referrer['id'],
        $referredUserId,
        'video_view',
        'video_view:' . trim($sourceKey),
        $amountMicroUsd,
        $statDate,
        [
            'video_id' => $videoId,
            'gross_earned_micro_usd' => max(0, $earnedMicroUsd),
        ]
    );
}

function ve_referral_record_premium_commission(
    int $referredUserId,
    int $purchaseAmountMicroUsd,
    string $sourceKey,
    ?string $statDate = null,
    array $metadata = []
): ?array {
    $referrer = ve_referral_find_referrer_for_user($referredUserId);

    if (!is_array($referrer)) {
        return null;
    }

    $amountMicroUsd = (int) round(max(0, $purchaseAmountMicroUsd) * (ve_referral_premium_rate_basis_points() / 10000));

    if ($amountMicroUsd <= 0) {
        return null;
    }

    $metadata['gross_purchase_micro_usd'] = max(0, $purchaseAmountMicroUsd);

    return ve_referral_insert_commission(
        (int) $referrer['id'],
        $referredUserId,
        'premium_purchase',
        'premium_purchase:' . trim($sourceKey),
        $amountMicroUsd,
        $statDate,
        $metadata
    );
}

function ve_referral_total_earnings_micro_usd(int $userId): int
{
    $stmt = ve_db()->prepare(
        'SELECT COALESCE(SUM(amount_micro_usd), 0)
         FROM referral_earnings
         WHERE referrer_user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ve_referral_totals_breakdown(int $userId): array
{
    $stmt = ve_db()->prepare(
        'SELECT
            COALESCE(SUM(amount_micro_usd), 0) AS total_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "video_view" THEN amount_micro_usd ELSE 0 END), 0) AS video_view_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "premium_purchase" THEN amount_micro_usd ELSE 0 END), 0) AS premium_purchase_micro_usd
         FROM referral_earnings
         WHERE referrer_user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        $row = [];
    }

    return [
        'total_micro_usd' => (int) ($row['total_micro_usd'] ?? 0),
        'video_view_micro_usd' => (int) ($row['video_view_micro_usd'] ?? 0),
        'premium_purchase_micro_usd' => (int) ($row['premium_purchase_micro_usd'] ?? 0),
    ];
}

function ve_referral_daily_stats_map(int $userId, string $fromDate, string $toDate): array
{
    $stmt = ve_db()->prepare(
        'SELECT
            stat_date,
            COALESCE(SUM(amount_micro_usd), 0) AS total_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "video_view" THEN amount_micro_usd ELSE 0 END), 0) AS video_view_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "premium_purchase" THEN amount_micro_usd ELSE 0 END), 0) AS premium_purchase_micro_usd
         FROM referral_earnings
         WHERE referrer_user_id = :user_id
           AND stat_date BETWEEN :from_date AND :to_date
         GROUP BY stat_date
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
            'total_micro_usd' => (int) ($row['total_micro_usd'] ?? 0),
            'video_view_micro_usd' => (int) ($row['video_view_micro_usd'] ?? 0),
            'premium_purchase_micro_usd' => (int) ($row['premium_purchase_micro_usd'] ?? 0),
        ];
    }

    return $map;
}

function ve_referral_totals_for_date(int $userId, string $statDate): array
{
    $stmt = ve_db()->prepare(
        'SELECT
            COALESCE(SUM(amount_micro_usd), 0) AS total_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "video_view" THEN amount_micro_usd ELSE 0 END), 0) AS video_view_micro_usd,
            COALESCE(SUM(CASE WHEN source_type = "premium_purchase" THEN amount_micro_usd ELSE 0 END), 0) AS premium_purchase_micro_usd
         FROM referral_earnings
         WHERE referrer_user_id = :user_id
           AND stat_date = :stat_date'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':stat_date' => $statDate,
    ]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        $row = [];
    }

    return [
        'total_micro_usd' => (int) ($row['total_micro_usd'] ?? 0),
        'video_view_micro_usd' => (int) ($row['video_view_micro_usd'] ?? 0),
        'premium_purchase_micro_usd' => (int) ($row['premium_purchase_micro_usd'] ?? 0),
    ];
}

function ve_referral_count_for_user(int $userId): int
{
    $stmt = ve_db()->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE referred_by_user_id = :user_id
           AND deleted_at IS NULL'
    );
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ve_referral_list_for_user(int $userId, int $limit = 100): array
{
    $limit = max(1, min(250, $limit));
    $stmt = ve_db()->prepare(
        'SELECT
            referred.id,
            referred.username,
            referred.email,
            COALESCE(referred.referred_at, referred.created_at) AS joined_at,
            COALESCE(earnings.total_micro_usd, 0) AS total_micro_usd,
            COALESCE(earnings.video_view_micro_usd, 0) AS video_view_micro_usd,
            COALESCE(earnings.premium_purchase_micro_usd, 0) AS premium_purchase_micro_usd
         FROM users referred
         LEFT JOIN (
            SELECT
                referred_user_id,
                COALESCE(SUM(amount_micro_usd), 0) AS total_micro_usd,
                COALESCE(SUM(CASE WHEN source_type = "video_view" THEN amount_micro_usd ELSE 0 END), 0) AS video_view_micro_usd,
                COALESCE(SUM(CASE WHEN source_type = "premium_purchase" THEN amount_micro_usd ELSE 0 END), 0) AS premium_purchase_micro_usd
            FROM referral_earnings
            WHERE referrer_user_id = :user_id
            GROUP BY referred_user_id
         ) earnings ON earnings.referred_user_id = referred.id
         WHERE referred.referred_by_user_id = :user_id
           AND referred.deleted_at IS NULL
         ORDER BY joined_at DESC, referred.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        $joinedAt = (string) ($row['joined_at'] ?? '');
        $totalMicroUsd = (int) ($row['total_micro_usd'] ?? 0);
        $videoViewMicroUsd = (int) ($row['video_view_micro_usd'] ?? 0);
        $premiumMicroUsd = (int) ($row['premium_purchase_micro_usd'] ?? 0);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? 'Unknown'),
            'email' => (string) ($row['email'] ?? ''),
            'joined_at' => $joinedAt,
            'joined_at_label' => ve_format_datetime_label($joinedAt, 'Unknown'),
            'total_micro_usd' => $totalMicroUsd,
            'total' => ve_dashboard_format_currency_micro_usd($totalMicroUsd),
            'video_view_micro_usd' => $videoViewMicroUsd,
            'video_view' => ve_dashboard_format_currency_micro_usd($videoViewMicroUsd),
            'premium_purchase_micro_usd' => $premiumMicroUsd,
            'premium_purchase' => ve_dashboard_format_currency_micro_usd($premiumMicroUsd),
        ];
    }, array_filter($rows, static fn ($row): bool => is_array($row)));
}

function ve_referral_recent_earnings(int $userId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = ve_db()->prepare(
        'SELECT
            referral_earnings.source_type,
            referral_earnings.amount_micro_usd,
            referral_earnings.stat_date,
            referral_earnings.created_at,
            referred.username AS referred_username
         FROM referral_earnings
         INNER JOIN users referred ON referred.id = referral_earnings.referred_user_id
         WHERE referral_earnings.referrer_user_id = :user_id
         ORDER BY referral_earnings.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    return array_map(static function (array $row): array {
        $sourceType = (string) ($row['source_type'] ?? 'commission');
        $amountMicroUsd = (int) ($row['amount_micro_usd'] ?? 0);
        $createdAt = (string) ($row['created_at'] ?? '');

        return [
            'source_type' => $sourceType,
            'source_label' => $sourceType === 'premium_purchase' ? 'Premium purchase' : 'Video earnings',
            'amount_micro_usd' => $amountMicroUsd,
            'amount' => ve_dashboard_format_currency_micro_usd($amountMicroUsd),
            'stat_date' => (string) ($row['stat_date'] ?? ''),
            'created_at' => $createdAt,
            'created_at_label' => ve_format_datetime_label($createdAt, 'Unknown'),
            'referred_username' => (string) ($row['referred_username'] ?? 'Unknown'),
        ];
    }, array_filter($rows, static fn ($row): bool => is_array($row)));
}

function ve_referral_snapshot(int $userId): array
{
    $referralCode = ve_referral_ensure_user_code($userId);
    $range = ve_dashboard_normalize_date_range(null, null, 30);
    $dailyStats = ve_referral_daily_stats_map($userId, $range['from'], $range['to']);
    $chart = [];
    $last30DaysMicroUsd = 0;

    foreach (ve_dashboard_date_series($range['from'], $range['to']) as $date) {
        $row = $dailyStats[$date] ?? [
            'total_micro_usd' => 0,
            'video_view_micro_usd' => 0,
            'premium_purchase_micro_usd' => 0,
        ];
        $last30DaysMicroUsd += (int) $row['total_micro_usd'];
        $chart[] = [
            'time' => $date,
            'earned_micro_usd' => (int) $row['total_micro_usd'],
            'earned' => ve_dashboard_format_currency_micro_usd((int) $row['total_micro_usd']),
            'video_view_micro_usd' => (int) $row['video_view_micro_usd'],
            'premium_purchase_micro_usd' => (int) $row['premium_purchase_micro_usd'],
        ];
    }

    $link = ve_absolute_url('/join/' . rawurlencode($referralCode));
    $totals = ve_referral_totals_breakdown($userId);
    $count = ve_referral_count_for_user($userId);
    $banner728Image = ve_absolute_url('/assets/img/referral-banner-728x90.png');
    $banner468Image = ve_absolute_url('/assets/img/referral-banner-468x60.png');
    $banner728Code = '<a href="' . $link . '"><img style="width:100%;height:auto;max-width:720px;" src="' . $banner728Image . '" alt="FileHost.net referral"></a>';
    $banner468Code = '<a href="' . $link . '"><img style="width:100%;height:auto;max-width:480px;" src="' . $banner468Image . '" alt="FileHost.net referral"></a>';

    return [
        'status' => 'ok',
        'referral_code' => $referralCode,
        'referral_link' => $link,
        'rates' => [
            'video_views_basis_points' => ve_referral_rate_basis_points(),
            'premium_purchase_basis_points' => ve_referral_premium_rate_basis_points(),
        ],
        'counts' => [
            'referrals' => $count,
        ],
        'totals' => [
            'total_micro_usd' => $totals['total_micro_usd'],
            'total' => ve_dashboard_format_currency_micro_usd($totals['total_micro_usd']),
            'video_view_micro_usd' => $totals['video_view_micro_usd'],
            'video_view' => ve_dashboard_format_currency_micro_usd($totals['video_view_micro_usd']),
            'premium_purchase_micro_usd' => $totals['premium_purchase_micro_usd'],
            'premium_purchase' => ve_dashboard_format_currency_micro_usd($totals['premium_purchase_micro_usd']),
            'last_30_days_micro_usd' => $last30DaysMicroUsd,
            'last_30_days' => ve_dashboard_format_currency_micro_usd($last30DaysMicroUsd),
        ],
        'referrals' => ve_referral_list_for_user($userId),
        'recent_earnings' => ve_referral_recent_earnings($userId),
        'chart' => $chart,
        'range' => $range,
        'banners' => [
            '728x90' => [
                'image_url' => $banner728Image,
                'embed_code' => $banner728Code,
            ],
            '468x60' => [
                'image_url' => $banner468Image,
                'embed_code' => $banner468Code,
            ],
        ],
    ];
}

function ve_referral_percentage_label(int $basisPoints): string
{
    $percentage = $basisPoints / 100;
    $formatted = number_format($percentage, 2, '.', '');

    return rtrim(rtrim($formatted, '0'), '.');
}

function ve_referral_rows_html(array $referrals): string
{
    if ($referrals === []) {
        return '<tr><td colspan="2" class="text-center text-muted py-4">No referrals have joined yet.</td></tr>';
    }

    $rows = [];

    foreach ($referrals as $referral) {
        if (!is_array($referral)) {
            continue;
        }

        $name = trim((string) ($referral['username'] ?? ''));
        $email = trim((string) ($referral['email'] ?? ''));
        $primary = $name !== '' ? $name : ($email !== '' ? $email : ('User #' . (int) ($referral['id'] ?? 0)));
        $meta = sprintf(
            'Total earned: %s | Views: %s | Premium: %s',
            (string) ($referral['total'] ?? '$0.00000'),
            (string) ($referral['video_view'] ?? '$0.00000'),
            (string) ($referral['premium_purchase'] ?? '$0.00000')
        );

        $rows[] = '<tr>'
            . '<td><strong>' . ve_h($primary) . '</strong><div class="small text-muted mt-1">' . ve_h($meta) . '</div></td>'
            . '<td>' . ve_h((string) ($referral['joined_at_label'] ?? 'Unknown')) . '</td>'
            . '</tr>';
    }

    if ($rows === []) {
        return '<tr><td colspan="2" class="text-center text-muted py-4">No referrals have joined yet.</td></tr>';
    }

    return implode('', $rows);
}

function ve_referral_banner_embed_code(string $joinUrl, string $imageUrl, string $maxWidth, string $alt): string
{
    return '<a href="' . $joinUrl . '"><img style="width:100%;height:auto;max-width:' . $maxWidth . ';" src="' . $imageUrl . '" alt="' . $alt . '"></a>';
}

function ve_referral_page_html(array $user): string
{
    $snapshot = ve_referral_snapshot((int) $user['id']);
    $html = (string) file_get_contents(ve_root_path('dashboard', 'referrals.html'));
    $html = ve_runtime_html_transform($html, 'dashboard/referrals.html');

    $referralCode = (string) ($snapshot['referral_code'] ?? '');
    $referralLink = (string) ($snapshot['referral_link'] ?? '');
    $joinUrl = ve_absolute_url('/join/' . rawurlencode($referralCode));
    $referralCount = (int) ($snapshot['counts']['referrals'] ?? 0);
    $rowsHtml = ve_referral_rows_html((array) ($snapshot['referrals'] ?? []));
    $viewRate = ve_referral_percentage_label((int) ($snapshot['rates']['video_views_basis_points'] ?? 0));
    $premiumRate = ve_referral_percentage_label((int) ($snapshot['rates']['premium_purchase_basis_points'] ?? 0));
    $banner728Code = ve_referral_banner_embed_code(
        $joinUrl,
        ve_absolute_url('/assets/img/referral-banner-728x90.png'),
        '720px',
        'FileHost.net - Upload videos share & make money'
    );
    $banner468Code = ve_referral_banner_embed_code(
        $joinUrl,
        ve_absolute_url('/assets/img/referral-banner-468x60.png'),
        '480px',
        'FileHost.net - Upload videos share & make money'
    );

    $html = (string) preg_replace_callback(
        '/(<label>Refferal link<\/label>\s*<input\b[^>]*\bvalue=")[^"]*(")/i',
        static fn (array $matches): string => $matches[1] . ve_h($referralLink) . $matches[2],
        $html,
        1
    );
    $html = (string) preg_replace_callback(
        '/(<span class="d-block mt-1">Total referrals<\/span>\s*<div class="used">\s*<strong>)[^<]*(<\/strong>)/i',
        static fn (array $matches): string => $matches[1] . $referralCount . $matches[2],
        $html,
        1
    );
    $html = (string) preg_replace(
        '/Earn\s+\d+(?:\.\d+)?%\s+earnings of each referrals for lifetime\./i',
        'Earn ' . $viewRate . '% earnings of each referrals for lifetime.',
        $html,
        1
    );
    $html = (string) preg_replace(
        '/You will also earn\s+\d+(?:\.\d+)?%\s+if your referral purchases premium account including recurring purchase\./i',
        'You will also earn ' . $premiumRate . '% if your referral purchases premium account including recurring purchase.',
        $html,
        1
    );
    $html = (string) preg_replace_callback(
        '/<tbody>\s*<\/tbody>/i',
        static fn (): string => '<tbody>' . $rowsHtml . '</tbody>',
        $html,
        1
    );
    $html = (string) preg_replace_callback(
        '/(<h6>728x90 Banner<\/h6>\s*<img[^>]*>\s*<textarea[^>]*>)(.*?)(<\/textarea>)/is',
        static fn (array $matches): string => $matches[1] . $banner728Code . $matches[3],
        $html,
        1
    );
    $html = (string) preg_replace_callback(
        '/(<h6>468x60 Banner<\/h6>\s*<img[^>]*>\s*<textarea[^>]*>)(.*?)(<\/textarea>)/is',
        static fn (array $matches): string => $matches[1] . $banner468Code . $matches[3],
        $html,
        1
    );

    return ve_rewrite_html_paths($html);
}

function ve_render_referrals_page(): void
{
    $user = ve_require_auth();
    ve_html(ve_referral_page_html($user));
}
