<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'chemical_consumption_entries'")->fetchColumn()) {
        throw new RuntimeException('Install the chemical consumption migration first.');
    }

    $column = $pdo->query("SHOW COLUMNS FROM chemical_consumption_entries LIKE 'uom'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE chemical_consumption_entries ADD COLUMN uom VARCHAR(40) NULL AFTER consume_quantity');
    }
    $updated = $pdo->exec(
        "UPDATE chemical_consumption_entries e JOIN chemical_items i ON i.id=e.chemical_item_id " .
        "SET e.uom=i.uom WHERE e.uom IS NULL OR TRIM(e.uom)=''"
    );
    $missing = (int) $pdo->query("SELECT COUNT(*) FROM chemical_consumption_entries WHERE uom IS NULL OR TRIM(uom)=''")->fetchColumn();
    if ($missing !== 0) throw new RuntimeException('Some existing entries could not be assigned a UOM.');
    $pdo->exec('ALTER TABLE chemical_consumption_entries MODIFY uom VARCHAR(40) NOT NULL');

    echo "Chemical consumption entry UOM migration complete; {$updated} existing rows backfilled.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Chemical consumption entry UOM migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
