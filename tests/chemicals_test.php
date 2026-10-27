<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/chemicals.php';

function chemical_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE chemical_items (id INTEGER PRIMARY KEY AUTOINCREMENT,item_code TEXT NOT NULL UNIQUE,description TEXT NOT NULL,status TEXT NOT NULL,uom TEXT NOT NULL,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE chemical_item_rates (id INTEGER PRIMARY KEY AUTOINCREMENT,chemical_item_id INTEGER NOT NULL,rate_inr NUMERIC NOT NULL,effective_from TEXT NOT NULL,effective_to TEXT,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(chemical_item_id,effective_from))');

$item = chemical_save_item($pdo, ['item_code' => 'chem-001', 'description' => 'Sulphuric Acid', 'status' => 'active', 'uom' => 'kg'], 7);
chemical_check($item['item_code'] === 'CHEM-001', 'Item code was not normalized.');
chemical_check((int) $pdo->query('SELECT created_by FROM chemical_items')->fetchColumn() === 7, 'Item actor was not stored.');

$rate = chemical_save_rate($pdo, ['chemical_item_id' => $item['id'], 'rate_inr' => '125.5000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31'], 7);
chemical_check($rate['currency'] === 'INR', 'Rate currency is not INR.');
chemical_save_rate($pdo, ['chemical_item_id' => $item['id'], 'rate_inr' => '130', 'effective_from' => '2026-04-01', 'effective_to' => ''], 8);

$overlapRejected = false;
try {
    chemical_save_rate($pdo, ['chemical_item_id' => $item['id'], 'rate_inr' => '128', 'effective_from' => '2026-03-15', 'effective_to' => '2026-04-15'], 8);
} catch (DomainException $exception) {
    $overlapRejected = $exception->getCode() === 409;
}
chemical_check($overlapRejected, 'Overlapping effective rate was accepted.');

$invalidDateRejected = false;
try {
    chemical_save_rate($pdo, ['chemical_item_id' => $item['id'], 'rate_inr' => '100', 'effective_from' => '2026-02-30', 'effective_to' => ''], 8);
} catch (InvalidArgumentException) {
    $invalidDateRejected = true;
}
chemical_check($invalidDateRejected, 'Invalid effective date was accepted.');

$duplicateRejected = false;
try {
    chemical_save_item($pdo, ['item_code' => 'CHEM-001', 'description' => 'Duplicate', 'status' => 'active', 'uom' => 'L'], 7);
} catch (DomainException $exception) {
    $duplicateRejected = $exception->getCode() === 409;
}
chemical_check($duplicateRejected, 'Duplicate item code was accepted.');

$list = chemical_list($pdo);
chemical_check($list['available'] && count($list['items']) === 1 && count($list['rates']) === 2, 'Chemical list did not return saved items and rates.');
chemical_check($list['currency'] === 'INR', 'Chemical list omitted INR currency.');

echo "Chemical registration and effective-rate tests passed\n";
