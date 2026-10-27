<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/chemical_consumption.php';

function consumption_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE chemical_items (id INTEGER PRIMARY KEY AUTOINCREMENT,item_code TEXT NOT NULL UNIQUE,description TEXT NOT NULL,status TEXT NOT NULL,uom TEXT NOT NULL,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE chemical_item_rates (id INTEGER PRIMARY KEY AUTOINCREMENT,chemical_item_id INTEGER NOT NULL,rate_inr NUMERIC NOT NULL,effective_from TEXT NOT NULL,effective_to TEXT,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(chemical_item_id,effective_from))');
$pdo->exec('CREATE TABLE chemical_consumption_entries (id INTEGER PRIMARY KEY AUTOINCREMENT,entry_date TEXT NOT NULL,shift_name TEXT NOT NULL,operator_name TEXT NOT NULL,machine_code TEXT NOT NULL,process_description TEXT NOT NULL,chemical_item_id INTEGER NOT NULL,consume_quantity NUMERIC NOT NULL,used_for TEXT NOT NULL,recommended_by TEXT NOT NULL,remarks TEXT,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE oee_fb_runs (line_code TEXT NOT NULL,report_date TEXT NOT NULL,total_surface_area NUMERIC)');
$pdo->exec("INSERT INTO chemical_items(item_code,description,status,uom) VALUES ('CHEM-001','Sulphuric Acid','active','KG'),('CHEM-002','Inactive Chemical','inactive','L')");
$pdo->exec("INSERT INTO chemical_item_rates(chemical_item_id,rate_inr,effective_from,effective_to) VALUES (1,20,'2026-01-01',NULL)");
$pdo->exec("INSERT INTO oee_fb_runs(line_code,report_date,total_surface_area) VALUES ('APP-07','2026-10-28',100),('APP-07','2026-10-28',10)");

$saved = chemical_consumption_save($pdo, [
    'entry_date' => '2026-10-27', 'shift_name' => 'Shift A', 'operator_name' => 'Aman Gupta',
    'machine_code' => 'app-06', 'process_description' => 'Surface Treatment', 'chemical_item_id' => 1,
    'consume_quantity' => '12.5000', 'used_for' => 'Fresh Makeup', 'recommended_by' => 'Process Team',
    'remarks' => 'Opening batch'
], 7);
consumption_check($saved['id'] === 1, 'Consumption entry was not created.');
$row = $pdo->query('SELECT * FROM chemical_consumption_entries')->fetch();
consumption_check($row['machine_code'] === 'APP-06' && (int) $row['created_by'] === 7, 'Machine normalization or actor audit failed.');

chemical_consumption_save($pdo, [
    'id' => 1, 'entry_date' => '2026-10-28', 'shift_name' => 'Shift B', 'operator_name' => 'Aman Gupta',
    'machine_code' => 'APP-07', 'process_description' => 'Surface Treatment', 'chemical_item_id' => 1,
    'consume_quantity' => '10', 'used_for' => 'Top-Up', 'recommended_by' => 'Process Team', 'remarks' => ''
], 8);
$row = $pdo->query('SELECT * FROM chemical_consumption_entries')->fetch();
consumption_check($row['entry_date'] === '2026-10-28' && (int) $row['updated_by'] === 8, 'Consumption entry was not updated.');

$inactiveRejected = false;
try {
    chemical_consumption_save($pdo, [
        'entry_date' => '2026-10-28', 'shift_name' => 'Shift B', 'operator_name' => 'Operator',
        'machine_code' => 'APP-07', 'process_description' => 'Test', 'chemical_item_id' => 2,
        'consume_quantity' => '1', 'used_for' => 'Top-Up', 'recommended_by' => 'Lead', 'remarks' => ''
    ], 8);
} catch (DomainException $exception) {
    $inactiveRejected = $exception->getCode() === 409;
}
consumption_check($inactiveRejected, 'Inactive chemical was accepted for a new entry.');

$inactiveChangeRejected = false;
try {
    chemical_consumption_save($pdo, [
        'id' => 1, 'entry_date' => '2026-10-28', 'shift_name' => 'Shift B', 'operator_name' => 'Operator',
        'machine_code' => 'APP-07', 'process_description' => 'Test', 'chemical_item_id' => 2,
        'consume_quantity' => '1', 'used_for' => 'Top-Up', 'recommended_by' => 'Lead', 'remarks' => ''
    ], 8);
} catch (DomainException $exception) {
    $inactiveChangeRejected = $exception->getCode() === 409;
}
consumption_check($inactiveChangeRejected, 'Existing entry was changed to an inactive chemical.');

$invalidQuantityRejected = false;
try {
    chemical_consumption_quantity('0');
} catch (InvalidArgumentException) {
    $invalidQuantityRejected = true;
}
consumption_check($invalidQuantityRejected, 'Zero consumption quantity was accepted.');

$invalidUsageRejected = false;
try {
    chemical_consumption_save($pdo, [
        'entry_date' => '2026-10-28', 'shift_name' => 'Shift B', 'operator_name' => 'Operator',
        'machine_code' => 'APP-07', 'process_description' => 'Test', 'chemical_item_id' => 1,
        'consume_quantity' => '1', 'used_for' => 'Cleaning', 'recommended_by' => 'Lead', 'remarks' => ''
    ], 8);
} catch (InvalidArgumentException) {
    $invalidUsageRejected = true;
}
consumption_check($invalidUsageRejected, 'Unsupported Used For value was accepted.');

$list = chemical_consumption_list($pdo, 'Default Operator');
consumption_check($list['available'] && count($list['entries']) === 1 && count($list['items']) === 2, 'Consumption list is incomplete.');
consumption_check($list['entries'][0]['chemical_name'] === 'Sulphuric Acid' && $list['entries'][0]['uom'] === 'KG', 'Chemical details were not joined.');
consumption_check($list['default_operator'] === 'Default Operator', 'Default operator was not returned.');

$report = chemical_consumption_report($pdo, '2026-10-01', '2026-10-31', 'APP-07');
consumption_check($report['available'] && count($report['rows']) === 1, 'Chemical consumption report did not return the filtered entry.');
consumption_check($report['rows'][0]['item_code'] === 'CHEM-001' && $report['rows'][0]['used_for'] === 'Top-Up', 'Chemical report row is incomplete.');
consumption_check(in_array('APP-07', $report['machines'], true), 'Chemical report machine filter list is incomplete.');

$invalidReportRangeRejected = false;
try {
    chemical_consumption_report($pdo, '2026-10-31', '2026-10-01', 'ALL');
} catch (InvalidArgumentException) {
    $invalidReportRangeRejected = true;
}
consumption_check($invalidReportRangeRejected, 'Chemical report accepted a reversed date range.');

$costReport = chemical_cost_report($pdo, '2026-10-01', '2026-10-31', 'APP-07', 'monthly');
consumption_check($costReport['available'] && count($costReport['rows']) === 1, 'Chemical cost report did not group the selected month.');
$costRow = $costReport['rows'][0];
consumption_check($costRow['report_period'] === 'October 2026' && $costRow['total_surface_area'] === 110.0, 'Chemical cost report surface area is incorrect.');
consumption_check($costRow['total_chemical_cost'] === 200.0, 'Chemical cost report did not apply the effective INR rate.');
consumption_check(abs($costRow['chemical_cost_per_dm2'] - (200 / 110)) < 0.000001, 'Chemical cost per dm2 formula is incorrect.');

$pdo->exec("INSERT INTO chemical_consumption_entries(entry_date,shift_name,operator_name,machine_code,process_description,chemical_item_id,consume_quantity,used_for,recommended_by) VALUES ('2026-10-28','Shift A','Operator','APP-07','Test',2,1,'Top-Up','Manager')");
$missingRateReport = chemical_cost_report($pdo, '2026-10-01', '2026-10-31', 'APP-07', 'consolidated');
consumption_check($missingRateReport['rows'][0]['unpriced_entries'] === 1, 'Chemical cost report did not flag a missing effective rate.');
consumption_check($missingRateReport['rows'][0]['total_chemical_cost'] === null && $missingRateReport['rows'][0]['chemical_cost_per_dm2'] === null, 'Incomplete chemical cost was reported as final.');

$invalidReportTypeRejected = false;
try {
    chemical_cost_report($pdo, '2026-10-01', '2026-10-31', 'ALL', 'daily');
} catch (InvalidArgumentException) {
    $invalidReportTypeRejected = true;
}
consumption_check($invalidReportTypeRejected, 'Unsupported chemical cost report type was accepted.');

echo "Chemical consumption entry tests passed\n";
