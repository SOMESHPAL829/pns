<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/breakdowns.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

function breakdown_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE oee_reason_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, label TEXT, loss_type TEXT, is_active INTEGER DEFAULT 1, created_by INTEGER)');
$pdo->exec('CREATE TABLE oee_downtime_events (id INTEGER PRIMARY KEY, reason_code TEXT)');
$pdo->exec("INSERT INTO oee_reason_codes (code,label,loss_type,is_active) VALUES ('UNK','Not yet classified','unplanned',1),('LEGACY','Legacy inactive','planned',0),('CHILLER','Chiller Problem','unplanned',1)");

$created = breakdown_save($pdo, ['loss_type' => 'planned', 'loss_name' => 'Tea, Lunch & Dinner Break'], 7);
breakdown_check($created['id'] > 0, 'Breakdown creation did not return an ID.');
$listed = breakdown_list($pdo);
breakdown_check($listed['available'] && count($listed['breakdowns']) === 3, 'List must include every registered breakdown and exclude the internal fallback.');
breakdown_check(in_array('Tea, Lunch & Dinner Break', array_column($listed['breakdowns'], 'label'), true), 'Created loss name was not returned.');
$planned = breakdown_list($pdo, 'planned');
breakdown_check(count($planned['breakdowns']) === 1 && $planned['breakdowns'][0]['loss_type'] === 'planned', 'Loss-type query did not filter active planned reasons.');
$unplanned = breakdown_list($pdo, 'UNPLANNED');
breakdown_check(count($unplanned['breakdowns']) === 1 && $unplanned['breakdowns'][0]['code'] === 'CHILLER', 'Loss-type query did not filter active unplanned reasons.');

breakdown_save($pdo, ['id' => $created['id'], 'loss_type' => 'unplanned', 'loss_name' => 'Updated Breakdown'], 8);
$updated = $pdo->query('SELECT label,loss_type FROM oee_reason_codes WHERE id=' . (int) $created['id'])->fetch();
breakdown_check($updated === ['label' => 'Updated Breakdown', 'loss_type' => 'unplanned'], 'Breakdown update did not persist.');

$duplicateRejected = false;
try {
    breakdown_save($pdo, ['loss_type' => 'planned', 'loss_name' => 'updated breakdown'], 7);
} catch (DomainException $exception) {
    $duplicateRejected = $exception->getCode() === 409;
}
breakdown_check($duplicateRejected, 'Duplicate loss name was accepted.');

$pdo->prepare('INSERT INTO oee_downtime_events (id,reason_code) VALUES (1,?)')->execute([$updated['label']]);
$pdo->prepare('UPDATE oee_downtime_events SET reason_code=? WHERE id=1')->execute([$pdo->query('SELECT code FROM oee_reason_codes WHERE id=' . (int) $created['id'])->fetchColumn()]);
$deleteMessage = '';
try {
    breakdown_delete($pdo, $created['id']);
} catch (DomainException $exception) {
    $deleteMessage = $exception->getMessage();
}
breakdown_check($deleteMessage === 'Cannot delete. This breakdown reason is already used in downtime records.', 'Assigned reason delete did not return the required message.');

$unassigned = breakdown_save($pdo, ['loss_type' => 'planned', 'loss_name' => 'Unused Reason'], 7);
breakdown_delete($pdo, $unassigned['id']);
breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_reason_codes WHERE label='Unused Reason'")->fetchColumn() === 0, 'Unused reason was not deleted.');
$systemReasonRejected = false;
try {
    breakdown_delete($pdo, 1);
} catch (DomainException $exception) {
    $systemReasonRejected = $exception->getCode() === 409;
}
breakdown_check($systemReasonRejected, 'System fallback reason was deleted.');

breakdown_check(portal_api_requirement('breakdowns', 'list', 'GET') === ['breakdown-registration', 'view'], 'Master list permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'by-type', 'GET') === ['downtime', 'view'], 'Downtime reason lookup permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'save', 'POST') === ['breakdown-registration', 'create'], 'Breakdown create permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'save', 'POST', ['id' => 5]) === ['breakdown-registration', 'update'], 'Breakdown update permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'delete', 'POST') === ['breakdown-registration', 'delete'], 'Breakdown delete permission route is incorrect.');

$migration = file_get_contents(dirname(__DIR__) . '/migrations/011_breakdown_registration.sql');
$defaults = [
    'planned' => ['Tea, Lunch & Dinner Break', 'Preventive Maintenance', 'Worker Training', 'Tool Box Talk', 'No Plan'],
    'unplanned' => ['Transporter Breakdown', 'Chiller Problem', 'Heater Problem', 'Rectifier Problem', 'No Manpower', 'No Material', 'Chemical Addition', 'New Bath Preparation', 'Compressed Air Pressure Not Available', 'PNG Gas Supply Not Available', 'Oven Breakdown', 'Chemical Flow Pump Not Working', 'Power Supply Failure', 'Jig and Flight Bar Failure', 'Air Knife Not Working', 'V-Block Damaged', 'Chemical Not Available', 'Shot Blasting Machine Not Working', 'Limit Switch Failure', 'Water Supply Not Available', 'Chemical Tank Leakage', 'Power Supply Fluctuation', 'PLC System Not Working', 'Sensor Not Working'],
];
foreach ($defaults as $type => $names) {
    foreach ($names as $name) {
        breakdown_check(
            is_string($migration) && str_contains($migration, "'" . str_replace("'", "''", $name) . "', '" . $type . "'"),
            'Default ' . $type . ' reason is missing from the migration: ' . $name,
        );
    }
}

echo "Breakdown registration, filtering, permissions and delete protection tests passed\n";
