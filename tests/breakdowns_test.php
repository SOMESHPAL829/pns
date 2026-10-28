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
$pdo->exec('CREATE TABLE oee_reason_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, label TEXT, loss_type TEXT, is_active INTEGER DEFAULT 1, is_archived INTEGER DEFAULT 0, created_by INTEGER)');
$pdo->exec('CREATE TABLE oee_downtime_events (id INTEGER PRIMARY KEY, reason_code TEXT)');
$pdo->exec("INSERT INTO oee_reason_codes (code,label,loss_type,is_active) VALUES ('UNK','Not yet classified','unplanned',1),('LEGACY_USED','Old used reason','planned',1),('LEGACY_UNUSED','Old unused reason','planned',1)");
$pdo->exec("INSERT INTO oee_downtime_events (id,reason_code) VALUES (1,'LEGACY_USED')");
breakdown_sync_default_catalog($pdo);

breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_reason_codes WHERE code<>'UNK' AND is_archived=0")->fetchColumn() === 30, 'Catalogue refresh did not install exactly 30 current reasons.');
breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_reason_codes WHERE code='LEGACY_USED' AND is_active=0 AND is_archived=1")->fetchColumn() === 1, 'Referenced legacy reason was not archived and deactivated.');
breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_reason_codes WHERE code='LEGACY_UNUSED'")->fetchColumn() === 0, 'Unused legacy reason was not removed.');
breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_downtime_events WHERE reason_code='LEGACY_USED'")->fetchColumn() === 1, 'Catalogue refresh changed historical downtime.');
breakdown_sync_default_catalog($pdo);
breakdown_check((int) $pdo->query("SELECT COUNT(*) FROM oee_reason_codes WHERE code<>'UNK' AND is_archived=0")->fetchColumn() === 30, 'Repeated catalogue refresh created duplicates.');

$expectedDefaults = [
    'No Plan', 'Preventive Maintenance', 'Tea, Lunch & Dinner Break', 'Tool Box Talk', 'Workers Training',
    'Air Knife Not Working', 'Chemical Addition', 'Chemical Flow Pump Not Working', 'Chemical Not Available',
    'Chemical Tank Leakage', 'Chiller Problem', 'Compressed Air Pressure Not Available', 'Heater Problem',
    'Jig and Flight Bar Failure', 'Limit Switch Failure', 'New Bath Preparation', 'No Manpower', 'No Material',
    'Other', 'Oven Breakdown', 'PLC System Not Working', 'PNG Gas Supply Not Available', 'Power Supply Failure',
    'Power Supply Fluctuation', 'Rectifier Problem', 'Sensor Not Working', 'Shot Blasting Machine Not Working',
    'Transporter Breakdown', 'V-Block Damaged', 'Water Supply Not Available',
];
$catalog = breakdown_list($pdo);
breakdown_check(array_column($catalog['breakdowns'], 'label') === $expectedDefaults, 'Registered reasons do not match the approved catalogue.');

$created = breakdown_save($pdo, ['loss_type' => 'planned', 'loss_name' => 'Custom Planned Reason'], 7);
breakdown_check($created['id'] > 0, 'Breakdown creation did not return an ID.');
$listed = breakdown_list($pdo);
breakdown_check($listed['available'] && count($listed['breakdowns']) === 31, 'List must include all registered reasons and exclude the internal fallback.');
breakdown_check(in_array('Custom Planned Reason', array_column($listed['breakdowns'], 'label'), true), 'Created loss name was not returned.');
$planned = breakdown_list($pdo, 'planned');
$plannedCustom = array_values(array_filter($planned['breakdowns'], static fn(array $item): bool => $item['label'] === 'Custom Planned Reason'));
breakdown_check(count($plannedCustom) === 1 && $plannedCustom[0]['loss_type'] === 'planned', 'Loss-type query did not filter active planned reasons.');
$unplanned = breakdown_list($pdo, 'UNPLANNED');
breakdown_check(count($unplanned['breakdowns']) === 25 && $unplanned['breakdowns'][0]['loss_type'] === 'unplanned', 'Loss-type query did not filter active unplanned reasons.');

breakdown_save($pdo, ['id' => $created['id'], 'loss_type' => 'unplanned', 'loss_name' => 'Updated Downtime Reason', 'is_active' => false], 8);
$updated = $pdo->query('SELECT label,loss_type,is_active FROM oee_reason_codes WHERE id=' . (int) $created['id'])->fetch();
breakdown_check($updated === ['label' => 'Updated Downtime Reason', 'loss_type' => 'unplanned', 'is_active' => 0], 'Inactive downtime reason update did not persist.');
breakdown_check(count(breakdown_list($pdo, 'unplanned')['breakdowns']) === 25, 'Inactive reasons must be omitted from downtime choices.');
breakdown_check(!in_array('Updated Downtime Reason', array_column(breakdown_list($pdo, 'unplanned')['breakdowns'], 'label'), true), 'Inactive reason remained selectable for downtime.');
breakdown_check(in_array('Updated Downtime Reason', array_column(breakdown_list($pdo)['breakdowns'], 'label'), true), 'Inactive reasons must remain visible in the registration list.');
breakdown_save($pdo, ['id' => $created['id'], 'loss_type' => 'unplanned', 'loss_name' => 'Updated Downtime Reason', 'is_active' => true], 8);
$updated = $pdo->query('SELECT code,label FROM oee_reason_codes WHERE id=' . (int) $created['id'])->fetch();

$duplicateRejected = false;
try {
    breakdown_save($pdo, ['loss_type' => 'planned', 'loss_name' => 'updated downtime reason'], 7);
} catch (DomainException $exception) {
    $duplicateRejected = $exception->getCode() === 409;
}
breakdown_check($duplicateRejected, 'Duplicate loss name was accepted.');

breakdown_check($updated['label'] === 'Updated Downtime Reason' && $pdo->query('SELECT is_active FROM oee_reason_codes WHERE id=' . (int) $created['id'])->fetchColumn() === 1, 'Reactivation did not persist.');

breakdown_check(portal_api_requirement('breakdowns', 'list', 'GET') === ['breakdown-registration', 'view'], 'Master list permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'by-type', 'GET') === ['downtime', 'view'], 'Downtime reason lookup permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'save', 'POST') === ['breakdown-registration', 'create'], 'Breakdown create permission route is incorrect.');
breakdown_check(portal_api_requirement('breakdowns', 'save', 'POST', ['id' => 5]) === ['breakdown-registration', 'update'], 'Breakdown update permission route is incorrect.');
try {
    portal_api_requirement('breakdowns', 'delete', 'POST');
    breakdown_check(false, 'Breakdown delete API route must not be supported.');
} catch (DomainException $exception) {
    breakdown_check($exception->getCode() === 404, 'Unsupported delete route must return not found.');
}

echo "Downtime registration catalogue, archival, active status, filtering, and permissions tests passed\n";
