-- KARAM OEE cloud foundation.
-- This migration is additive and does not alter the existing portal_* tables.

CREATE TABLE IF NOT EXISTS oee_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(120) NOT NULL,
    source VARCHAR(80) NOT NULL,
    line_code VARCHAR(40) NOT NULL,
    report_date DATE NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('received', 'processed', 'failed') NOT NULL DEFAULT 'received',
    raw_payload JSON NOT NULL,
    error_message VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    UNIQUE KEY oee_import_batch_source_key (source, batch_id),
    UNIQUE KEY oee_import_payload_key (source, report_date, payload_sha256),
    INDEX oee_import_report_date_idx (line_code, report_date),
    INDEX oee_import_status_idx (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_fb_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    line_code VARCHAR(40) NOT NULL,
    report_date DATE NOT NULL,
    si_no INT UNSIGNED NOT NULL,
    seq VARCHAR(120) NOT NULL DEFAULT '',
    part_code VARCHAR(120) NOT NULL DEFAULT '',
    grade VARCHAR(80) NOT NULL DEFAULT '',
    report_colour VARCHAR(120) NOT NULL DEFAULT '',
    used_colour VARCHAR(120) NOT NULL DEFAULT '',
    loading_at DATETIME NULL,
    unloading_at DATETIME NULL,
    pcs INT UNSIGNED NOT NULL DEFAULT 0,
    surface_area DECIMAL(14,4) NULL,
    total_surface_area DECIMAL(14,4) NULL,
    is_productive TINYINT(1) NOT NULL DEFAULT 0,
    raw_stage_data JSON NULL,
    flags JSON NULL,
    cycle_minutes DECIMAL(10,3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT oee_fb_import_fk FOREIGN KEY (import_batch_id) REFERENCES oee_import_batches(id),
    UNIQUE KEY oee_fb_batch_si_key (import_batch_id, si_no),
    INDEX oee_fb_report_idx (line_code, report_date, loading_at),
    INDEX oee_fb_seq_idx (line_code, report_date, seq),
    INDEX oee_fb_productive_idx (line_code, report_date, is_productive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_cycle_time_standards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(40) NOT NULL,
    colour VARCHAR(120) NOT NULL,
    minutes_per_fb DECIMAL(10,3) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY oee_cycle_range_key (line_code, colour, effective_from),
    INDEX oee_cycle_lookup_idx (line_code, colour, effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_shift_definitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(40) NOT NULL,
    shift_code VARCHAR(20) NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    crosses_midnight TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY oee_shift_line_code_key (line_code, shift_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_downtime_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(40) NOT NULL,
    report_date DATE NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    event_type ENUM('planned', 'unplanned') NOT NULL DEFAULT 'unplanned',
    source ENUM('gap', 'empty_rack', 'manual', 'planned') NOT NULL,
    status ENUM('proposed', 'confirmed', 'approved', 'rejected') NOT NULL DEFAULT 'proposed',
    reason_code VARCHAR(40) NOT NULL DEFAULT 'UNK',
    notes VARCHAR(1000) NOT NULL DEFAULT '',
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX oee_downtime_window_idx (line_code, starts_at, ends_at),
    INDEX oee_downtime_status_idx (status, reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_quality_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(40) NOT NULL,
    report_date DATE NOT NULL,
    shift_code VARCHAR(20) NOT NULL DEFAULT '',
    rejected_fb INT UNSIGNED NOT NULL DEFAULT 0,
    reworked_fb INT UNSIGNED NOT NULL DEFAULT 0,
    notes VARCHAR(1000) NOT NULL DEFAULT '',
    entered_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY oee_quality_day_shift_key (line_code, report_date, shift_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_code VARCHAR(40) NOT NULL,
    report_date DATE NOT NULL,
    shift_code VARCHAR(20) NOT NULL DEFAULT '',
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    productive_fb INT UNSIGNED NOT NULL DEFAULT 0,
    good_fb INT UNSIGNED NOT NULL DEFAULT 0,
    planned_minutes DECIMAL(12,3) NOT NULL DEFAULT 0,
    unplanned_minutes DECIMAL(12,3) NOT NULL DEFAULT 0,
    run_minutes DECIMAL(12,3) NOT NULL DEFAULT 0,
    ideal_output_minutes DECIMAL(12,3) NOT NULL DEFAULT 0,
    availability DECIMAL(10,6) NOT NULL DEFAULT 0,
    performance DECIMAL(10,6) NOT NULL DEFAULT 0,
    quality DECIMAL(10,6) NOT NULL DEFAULT 0,
    oee DECIMAL(10,6) NOT NULL DEFAULT 0,
    utilization_24h DECIMAL(10,6) NOT NULL DEFAULT 0,
    calculation_flags JSON NULL,
    frozen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY oee_snapshot_window_key (line_code, report_date, shift_code),
    INDEX oee_snapshot_date_idx (line_code, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_sync_state (
    source VARCHAR(80) NOT NULL PRIMARY KEY,
    last_batch_id VARCHAR(120) NULL,
    last_report_date DATE NULL,
    last_received_at TIMESTAMP NULL,
    last_status ENUM('ok', 'failed') NOT NULL DEFAULT 'ok',
    last_error VARCHAR(500) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oee_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX oee_audit_entity_idx (entity_type, entity_id, created_at),
    INDEX oee_audit_actor_idx (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
