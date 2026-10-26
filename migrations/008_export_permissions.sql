-- Grant preservation is performed transactionally by migrate-export-permissions.php.
-- The ledger prevents subsequent runs from restoring intentionally revoked access.
CREATE TABLE IF NOT EXISTS portal_schema_migrations (
    migration_code VARCHAR(80) NOT NULL PRIMARY KEY,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
