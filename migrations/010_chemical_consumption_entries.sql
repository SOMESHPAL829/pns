-- Daily chemical consumption entries linked to the chemical item master.
-- Additive: existing chemical items, rates, and portal records are unchanged.

CREATE TABLE IF NOT EXISTS chemical_consumption_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    shift_name VARCHAR(40) NOT NULL,
    operator_name VARCHAR(120) NOT NULL,
    machine_code VARCHAR(80) NOT NULL,
    process_description VARCHAR(500) NOT NULL,
    chemical_item_id BIGINT UNSIGNED NOT NULL,
    consume_quantity DECIMAL(14,4) NOT NULL,
    used_for VARCHAR(500) NOT NULL,
    recommended_by VARCHAR(120) NOT NULL,
    remarks VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chemical_consumption_item_fk FOREIGN KEY (chemical_item_id) REFERENCES chemical_items(id),
    INDEX chemical_consumption_date_idx (entry_date),
    INDEX chemical_consumption_machine_idx (machine_code),
    INDEX chemical_consumption_item_date_idx (chemical_item_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
