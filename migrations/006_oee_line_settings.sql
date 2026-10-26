-- Additive. Re-running never resets an operator's configured threshold.
CREATE TABLE IF NOT EXISTS oee_line_settings (
    line_code VARCHAR(40) NOT NULL PRIMARY KEY,
    gap_threshold_minutes DECIMAL(7,3) NOT NULL DEFAULT 20.000,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
