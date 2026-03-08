CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    api_key_encrypted TEXT NOT NULL,
    ftp_username TEXT NOT NULL,
    ftp_password_encrypted TEXT NOT NULL,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT DEFAULT NULL
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
    vast_url TEXT NOT NULL DEFAULT '',
    pop_type TEXT NOT NULL DEFAULT '1',
    pop_url TEXT NOT NULL DEFAULT '',
    pop_cap INTEGER NOT NULL DEFAULT 0,
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
