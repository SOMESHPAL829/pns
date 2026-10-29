CREATE TABLE IF NOT EXISTS oee_daily_duration_settings (
    report_date DATE NOT NULL PRIMARY KEY,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT oee_daily_duration_user_fk FOREIGN KEY (updated_by) REFERENCES portal_users(id),
    CONSTRAINT oee_daily_duration_value_chk CHECK (duration_minutes IN (480, 600, 720, 960, 1440))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
