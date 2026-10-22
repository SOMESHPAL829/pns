CREATE TABLE IF NOT EXISTS portal_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    full_name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    reset_token_hash CHAR(64) NULL,
    reset_expires_at DATETIME NULL,
    reset_requested_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX users_reset_expiry (reset_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_login_attempts (
    attempt_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_number VARCHAR(24) NOT NULL UNIQUE,
    owner_id BIGINT UNSIGNED NOT NULL,
    record_type VARCHAR(40) NOT NULL,
    organization VARCHAR(120) NOT NULL,
    category VARCHAR(120) NOT NULL DEFAULT '',
    priority ENUM('Low', 'Normal', 'High') NOT NULL DEFAULT 'Normal',
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Open', 'In review', 'Closed') NOT NULL DEFAULT 'Open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT portal_records_owner_fk FOREIGN KEY (owner_id) REFERENCES portal_users(id),
    INDEX records_owner_created (owner_id, created_at),
    INDEX records_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
