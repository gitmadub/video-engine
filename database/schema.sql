CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    plan_code TEXT NOT NULL DEFAULT 'free',
    premium_until TEXT DEFAULT NULL,
    referral_code TEXT COLLATE NOCASE DEFAULT NULL,
    referred_by_user_id INTEGER DEFAULT NULL,
    referred_at TEXT DEFAULT NULL,
    api_key_encrypted TEXT NOT NULL,
    api_key_hash TEXT NOT NULL,
    api_key_last_rotated_at TEXT DEFAULT NULL,
    api_key_last_used_at TEXT DEFAULT NULL,
    ftp_username TEXT NOT NULL,
    ftp_password_encrypted TEXT NOT NULL,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS referral_earnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    referrer_user_id INTEGER NOT NULL,
    referred_user_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    source_key TEXT NOT NULL UNIQUE,
    amount_micro_usd INTEGER NOT NULL DEFAULT 0,
    stat_date TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    FOREIGN KEY (referrer_user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_settings (
    user_id INTEGER PRIMARY KEY,
    payment_method TEXT NOT NULL DEFAULT '',
    payment_id TEXT NOT NULL DEFAULT '',
    ads_mode TEXT NOT NULL DEFAULT '',
    uploader_type TEXT NOT NULL DEFAULT '0',
    embed_domains TEXT NOT NULL DEFAULT '[]',
    embed_access_only INTEGER NOT NULL DEFAULT 0,
    disable_download INTEGER NOT NULL DEFAULT 0,
    disable_adblock INTEGER NOT NULL DEFAULT 0,
    extract_subtitles INTEGER NOT NULL DEFAULT 0,
    show_embed_title INTEGER NOT NULL DEFAULT 0,
    auto_subtitle_start INTEGER NOT NULL DEFAULT 0,
    player_image_mode TEXT NOT NULL DEFAULT '',
    player_colour TEXT NOT NULL DEFAULT 'ff9900',
    embed_width INTEGER NOT NULL DEFAULT 600,
    embed_height INTEGER NOT NULL DEFAULT 480,
    logo_path TEXT NOT NULL DEFAULT '',
    splash_image_path TEXT NOT NULL DEFAULT '',
    vast_url TEXT NOT NULL DEFAULT '',
    pop_type TEXT NOT NULL DEFAULT '1',
    pop_url TEXT NOT NULL DEFAULT '',
    pop_cap INTEGER NOT NULL DEFAULT 0,
    api_enabled INTEGER NOT NULL DEFAULT 1,
    api_requests_per_hour INTEGER NOT NULL DEFAULT 250,
    api_requests_per_day INTEGER NOT NULL DEFAULT 5000,
    api_uploads_per_day INTEGER NOT NULL DEFAULT 25,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    read_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS custom_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    domain TEXT NOT NULL UNIQUE COLLATE NOCASE,
    status TEXT NOT NULL DEFAULT 'pending_dns',
    dns_target TEXT NOT NULL,
    dns_last_checked_at TEXT DEFAULT NULL,
    dns_check_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_id_hash TEXT NOT NULL UNIQUE,
    ip_address TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_request_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    api_key_hash TEXT NOT NULL,
    auth_type TEXT NOT NULL DEFAULT 'api_key',
    request_kind TEXT NOT NULL DEFAULT 'request',
    endpoint TEXT NOT NULL,
    http_method TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    bytes_in INTEGER NOT NULL DEFAULT 0,
    client_ip TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_balance_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    entry_type TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_key TEXT NOT NULL,
    amount_micro_usd INTEGER NOT NULL,
    description TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS premium_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_code TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    purchase_type TEXT NOT NULL,
    package_id TEXT NOT NULL,
    package_title TEXT NOT NULL,
    payment_method TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    amount_micro_usd INTEGER NOT NULL,
    bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
    plan_interval_spec TEXT NOT NULL DEFAULT '',
    crypto_currency_code TEXT NOT NULL DEFAULT '',
    crypto_currency_name TEXT NOT NULL DEFAULT '',
    crypto_amount TEXT NOT NULL DEFAULT '',
    crypto_address TEXT NOT NULL DEFAULT '',
    payment_uri TEXT NOT NULL DEFAULT '',
    qr_url TEXT NOT NULL DEFAULT '',
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    reason_code TEXT NOT NULL,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'processed',
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ftp_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL UNIQUE,
    location_name TEXT NOT NULL,
    flag_code TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'online'
);

CREATE TABLE IF NOT EXISTS video_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    parent_id INTEGER NOT NULL DEFAULT 0,
    public_code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    folder_id INTEGER NOT NULL DEFAULT 0,
    public_id TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    source_extension TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'queued',
    status_message TEXT NOT NULL DEFAULT '',
    duration_seconds REAL DEFAULT NULL,
    width INTEGER DEFAULT NULL,
    height INTEGER DEFAULT NULL,
    video_codec TEXT NOT NULL DEFAULT '',
    audio_codec TEXT NOT NULL DEFAULT '',
    original_size_bytes INTEGER NOT NULL DEFAULT 0,
    processed_size_bytes INTEGER NOT NULL DEFAULT 0,
    compression_ratio REAL DEFAULT NULL,
    processing_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    queued_at TEXT NOT NULL,
    processing_started_at TEXT DEFAULT NULL,
    ready_at TEXT DEFAULT NULL,
    deleted_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS remote_uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    normalized_url TEXT NOT NULL DEFAULT '',
    resolved_url TEXT NOT NULL DEFAULT '',
    host_key TEXT NOT NULL DEFAULT '',
    folder_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    status_message TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    original_filename TEXT NOT NULL DEFAULT '',
    content_type TEXT NOT NULL DEFAULT '',
    bytes_downloaded INTEGER NOT NULL DEFAULT 0,
    bytes_total INTEGER NOT NULL DEFAULT 0,
    speed_bytes_per_second INTEGER NOT NULL DEFAULT 0,
    progress_percent REAL NOT NULL DEFAULT 0,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    video_id INTEGER DEFAULT NULL,
    video_public_id TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    started_at TEXT DEFAULT NULL,
    completed_at TEXT DEFAULT NULL,
    deleted_at TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_video_folders_user_parent_name ON video_folders(user_id, parent_id, name);
CREATE INDEX IF NOT EXISTS idx_video_folders_user_parent_created ON video_folders(user_id, parent_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_videos_user_created ON videos(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_videos_user_folder_created ON videos(user_id, folder_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_videos_status_created ON videos(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_remote_uploads_user_created ON remote_uploads(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_remote_uploads_status_created ON remote_uploads(status, created_at ASC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_api_key_hash ON users(api_key_hash);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code);
CREATE INDEX IF NOT EXISTS idx_users_referred_by_created ON users(referred_by_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_created ON api_request_logs(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_api_request_logs_user_kind_created ON api_request_logs(user_id, request_kind, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_account_balance_ledger_user_created ON account_balance_ledger(user_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_account_balance_ledger_source_entry ON account_balance_ledger(source_type, source_key, entry_type);
CREATE INDEX IF NOT EXISTS idx_premium_orders_user_created ON premium_orders(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_premium_orders_status_created ON premium_orders(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_referral_earnings_referrer_date ON referral_earnings(referrer_user_id, stat_date DESC);
CREATE INDEX IF NOT EXISTS idx_referral_earnings_referred_created ON referral_earnings(referred_user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS video_playback_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    owner_user_id INTEGER DEFAULT NULL,
    ip_hash TEXT NOT NULL,
    user_agent_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    playback_started_at TEXT DEFAULT NULL,
    bandwidth_bytes_served INTEGER NOT NULL DEFAULT 0,
    uses_premium_bandwidth INTEGER NOT NULL DEFAULT 0,
    premium_bandwidth_bytes_served INTEGER NOT NULL DEFAULT 0,
    revoked_at TEXT DEFAULT NULL,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_video_sessions_video_expires ON video_playback_sessions(video_id, expires_at);

CREATE TABLE IF NOT EXISTS video_download_grants (
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
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_video_download_grants_video_expiry ON video_download_grants(video_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_video_download_grants_session_created ON video_download_grants(session_id_hash, created_at DESC);

CREATE TABLE IF NOT EXISTS user_stats_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    stat_date TEXT NOT NULL,
    views INTEGER NOT NULL DEFAULT 0,
    earned_micro_usd INTEGER NOT NULL DEFAULT 0,
    referral_earned_micro_usd INTEGER NOT NULL DEFAULT 0,
    bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
    premium_bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS video_stats_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id INTEGER NOT NULL,
    stat_date TEXT NOT NULL,
    views INTEGER NOT NULL DEFAULT 0,
    earned_micro_usd INTEGER NOT NULL DEFAULT 0,
    referral_earned_micro_usd INTEGER NOT NULL DEFAULT 0,
    bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
    premium_bandwidth_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_user_stats_daily_user_date ON user_stats_daily(user_id, stat_date);
CREATE INDEX IF NOT EXISTS idx_user_stats_daily_date ON user_stats_daily(stat_date);
CREATE UNIQUE INDEX IF NOT EXISTS idx_video_stats_daily_video_date ON video_stats_daily(video_id, stat_date);
CREATE INDEX IF NOT EXISTS idx_video_stats_daily_date ON video_stats_daily(stat_date);
