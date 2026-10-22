CREATE TABLE IF NOT EXISTS oee_reason_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY oee_reason_code_key (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO oee_reason_codes (code, label) VALUES
('UNK', 'Not yet classified'),
('BATH', 'Bath / chemical'),
('HOIST', 'Hoist / movement'),
('WAITING_MATERIAL', 'Waiting for material'),
('QUALITY', 'Quality hold'),
('PLANNED_STOP', 'Planned stop'),
('OTHER', 'Other');
