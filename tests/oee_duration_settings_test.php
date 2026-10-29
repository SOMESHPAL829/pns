<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/oee_ingest.php';
require_once __DIR__ . '/../includes/oee_window.php';
require_once __DIR__ . '/../includes/oee_duration_settings.php';

function duration_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE oee_import_batches (report_date TEXT)');
$pdo->exec("INSERT INTO oee_import_batches(report_date) VALUES ('2026-10-29')");
$pdo->exec('CREATE TABLE oee_daily_duration_settings (report_date TEXT PRIMARY KEY, duration_minutes INTEGER NOT NULL, updated_by INTEGER NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$default = oee_duration_setting($pdo, null);
duration_check($default['date'] === '2026-10-29' && !$default['configured'] && $default['duration_minutes'] === 1440, 'Unconfigured latest date must default to 24 hours.');
$october = oee_save_duration_setting($pdo, ['date' => '2026-10-01', 'duration_minutes' => 960], 7);
$november = oee_save_duration_setting($pdo, ['date' => '2026-11-01', 'duration_minutes' => 480], 8);
duration_check($october['configured'] && $october['duration_minutes'] === 960, 'October date duration was not saved.');
duration_check($november['duration_minutes'] === 480 && oee_duration_setting($pdo, '2026-10-01')['duration_minutes'] === 960, 'Different months must keep independent date settings.');
$updated = oee_save_duration_setting($pdo, ['date' => '2026-10-01', 'duration_minutes' => 720], 9);
duration_check($updated['duration_minutes'] === 720, 'Saved duration could not be corrected for its date.');
$rejected = false;
try {
    oee_save_duration_setting($pdo, ['date' => '2026-10-02', 'duration_minutes' => 840], 9);
} catch (InvalidArgumentException) {
    $rejected = true;
}
duration_check($rejected, 'Unsupported duration was accepted.');

echo "OEE date-specific duration setting tests passed\n";
