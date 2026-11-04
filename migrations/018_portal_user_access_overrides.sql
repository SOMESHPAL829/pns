CREATE TABLE IF NOT EXISTS portal_user_access_overrides (
    user_id BIGINT UNSIGNED NOT NULL,
    custom_access TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_user_permissions (
    user_id BIGINT UNSIGNED NOT NULL,
    page_code VARCHAR(80) NOT NULL,
    action_code VARCHAR(40) NOT NULL,
    PRIMARY KEY (user_id,page_code,action_code),
    FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
