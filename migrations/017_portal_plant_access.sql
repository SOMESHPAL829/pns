CREATE TABLE IF NOT EXISTS portal_role_plants (
    role_id BIGINT UNSIGNED NOT NULL,
    plant_code VARCHAR(80) NOT NULL,
    PRIMARY KEY (role_id,plant_code),
    FOREIGN KEY (role_id) REFERENCES portal_access_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_user_plants (
    user_id BIGINT UNSIGNED NOT NULL,
    plant_code VARCHAR(80) NOT NULL,
    PRIMARY KEY (user_id,plant_code),
    FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_role_plants(role_id,plant_code)
SELECT id,'*' FROM portal_access_roles;
