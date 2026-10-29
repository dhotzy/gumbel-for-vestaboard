-- SQLite für lokale Entwicklung und Tests. Muss inhaltlich schema.mysql.sql entsprechen.

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    role TEXT NOT NULL,
    login_type TEXT NOT NULL,
    secret_hash TEXT NOT NULL,
    source TEXT NOT NULL,
    can_force INTEGER NOT NULL DEFAULT 0,
    default_force INTEGER NOT NULL DEFAULT 0,
    can_jump INTEGER NOT NULL DEFAULT 0,
    default_jump INTEGER NOT NULL DEFAULT 0,
    can_schedule INTEGER NOT NULL DEFAULT 0,
    cooldown_min INTEGER NULL,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    text_raw TEXT NOT NULL,
    align TEXT NOT NULL DEFAULT 'center',
    grid TEXT NOT NULL,
    forced INTEGER NOT NULL DEFAULT 0,
    jump INTEGER NOT NULL DEFAULT 0,
    sent_forced INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    not_before TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NULL,
    shown_at TEXT NULL,
    ended_at TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_attempt_at TEXT NULL,
    last_error TEXT NULL,
    deleted_by INTEGER NULL,
    deleted_at TEXT NULL
);

CREATE INDEX idx_messages_status ON messages (status, not_before);
CREATE INDEX idx_messages_created ON messages (created_at);
CREATE INDEX idx_messages_user ON messages (user_id, created_at);

CREATE TABLE settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE api_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NULL,
    action TEXT NOT NULL,
    http_status INTEGER NOT NULL,
    ok INTEGER NOT NULL,
    error TEXT NULL,
    duration_ms INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_api_log_created ON api_log (created_at);
