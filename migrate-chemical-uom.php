<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $before = (int) $pdo->query("SELECT COUNT(*) FROM chemical_items WHERE UPPER(TRIM(uom)) = 'L'")->fetchColumn();
    $sql = file_get_contents(__DIR__ . '/migrations/014_normalize_chemical_uom_ltr.sql');
    if ($sql === false) throw new RuntimeException('Chemical UOM normalization SQL not found.');

    $pdo->beginTransaction();
    $updated = $pdo->exec($sql);
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM chemical_items WHERE UPPER(TRIM(uom)) = 'L'")->fetchColumn();
    if ($remaining !== 0) throw new RuntimeException('Chemical UOM normalization did not update every L value.');
    $pdo->commit();

    echo sprintf("Chemical UOM normalization complete on %s: %d found, %d updated, %d remaining.\n", $database, $before, (int) $updated, $remaining);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Chemical UOM normalization failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
