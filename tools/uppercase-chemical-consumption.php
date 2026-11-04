<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('This migration runner requires the configured MySQL database.');
}

$counts = static function (PDO $pdo): array {
    $entries = $pdo->query(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN ' .
        'BINARY shift_name<>BINARY UPPER(TRIM(shift_name)) OR ' .
        'BINARY operator_name<>BINARY UPPER(TRIM(operator_name)) OR ' .
        'BINARY machine_code<>BINARY UPPER(TRIM(machine_code)) OR ' .
        'BINARY process_description<>BINARY UPPER(TRIM(process_description)) OR ' .
        'BINARY uom<>BINARY UPPER(TRIM(uom)) OR ' .
        'BINARY used_for<>BINARY UPPER(TRIM(used_for)) OR ' .
        'BINARY recommended_by<>BINARY UPPER(TRIM(recommended_by)) OR ' .
        '(remarks IS NOT NULL AND BINARY remarks<>BINARY UPPER(TRIM(remarks))) ' .
        'THEN 1 ELSE 0 END) AS mixed_rows FROM chemical_consumption_entries'
    )->fetch();
    $items = $pdo->query(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN ' .
        'BINARY item_code<>BINARY UPPER(TRIM(item_code)) OR ' .
        'BINARY description<>BINARY UPPER(TRIM(description)) OR ' .
        'BINARY uom<>BINARY UPPER(TRIM(uom)) ' .
        'THEN 1 ELSE 0 END) AS mixed_rows FROM chemical_items'
    )->fetch();
    return ['entries' => $entries, 'items' => $items];
};

echo "Before:\n", json_encode($counts($pdo), JSON_PRETTY_PRINT), PHP_EOL;
if (!in_array('--apply', $argv, true)) {
    echo "Dry run only. Add --apply to normalize the database.\n";
    exit(0);
}

$sql = file_get_contents(dirname(__DIR__) . '/migrations/017_uppercase_chemical_consumption.sql');
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Uppercase migration SQL is missing.');

$pdo->beginTransaction();
try {
    $pdo->exec($sql);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

$after = $counts($pdo);
echo "After:\n", json_encode($after, JSON_PRETTY_PRINT), PHP_EOL;
if ((int) ($after['entries']['mixed_rows'] ?? 0) !== 0 || (int) ($after['items']['mixed_rows'] ?? 0) !== 0) {
    throw new RuntimeException('Uppercase verification failed.');
}
echo "Chemical consumption text normalization complete.\n";
