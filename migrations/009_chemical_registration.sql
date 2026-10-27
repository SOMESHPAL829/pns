-- Chemical item registration and effective-dated INR rates.
-- Additive: does not modify existing portal or OEE records.

CREATE TABLE IF NOT EXISTS chemical_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    uom VARCHAR(40) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY chemical_item_code_key (item_code),
    INDEX chemical_item_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chemical_item_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chemical_item_id BIGINT UNSIGNED NOT NULL,
    rate_inr DECIMAL(14,4) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chemical_rate_item_fk FOREIGN KEY (chemical_item_id) REFERENCES chemical_items(id),
    UNIQUE KEY chemical_rate_start_key (chemical_item_id, effective_from),
    INDEX chemical_rate_window_idx (chemical_item_id, effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
