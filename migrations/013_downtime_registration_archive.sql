ALTER TABLE oee_reason_codes
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
