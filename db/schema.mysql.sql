-- MariaDB/MySQL. Alle Zeitstempel in UTC ('Y-m-d H:i:s'), Umrechnung nach Europe/Berlin in PHP.

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    role VARCHAR(10) NOT NULL,
    login_type VARCHAR(10) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    source VARCHAR(20) NOT NULL,
    can_force TINYINT(1) NOT NULL DEFAULT 0,
    default_force TINYINT(1) NOT NULL DEFAULT 0,
    can_jump TINYINT(1) NOT NULL DEFAULT 0,
    default_jump TINYINT(1) NOT NULL DEFAULT 0,
    can_schedule TINYINT(1) NOT NULL DEFAULT 0,
    cooldown_min INT NULL,
    failed_logins INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NULL,
    text_raw TEXT NOT NULL,
    align VARCHAR(10) NOT NULL DEFAULT 'center',
    grid TEXT NOT NULL,
    forced TINYINT(1) NOT NULL DEFAULT 0,
    jump TINYINT(1) NOT NULL DEFAULT 0,
    sent_forced TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL,
    not_before DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    shown_at DATETIME NULL,
    ended_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    deleted_by INT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    INDEX idx_messages_status (status, not_before),
    INDEX idx_messages_created (created_at),
    INDEX idx_messages_user (user_id, created_at),
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name VARCHAR(50) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NULL,
    action VARCHAR(10) NOT NULL,
    http_status INT NOT NULL,
    ok TINYINT(1) NOT NULL,
    error VARCHAR(255) NULL,
    duration_ms INT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_api_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
