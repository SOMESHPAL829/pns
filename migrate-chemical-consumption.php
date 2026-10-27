<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'chemical_items'")->fetchColumn()) {
        throw new RuntimeException('Install the chemical registration migration first.');
    }
    $sql = file_get_contents(__DIR__ . '/migrations/010_chemical_consumption_entries.sql');
    if ($sql === false) throw new RuntimeException('Chemical consumption migration SQL not found.');
    $pdo->exec($sql);
    echo "Chemical consumption entries installed. Existing data is unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Chemical consumption migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
