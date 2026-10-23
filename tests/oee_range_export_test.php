<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/oee_export.php';
function range_export_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$report = ['date' => '2026-10-21', 'from_date' => '2026-10-21', 'to_date' => '2026-10-22', 'machine' => 'APP-06',
    'window' => ['duration_minutes' => 720, 'start_time' => '18:00'], 'line_metrics' => [], 'rows' => [], 'downtime_events' => [], 'quality_entries' => []];
foreach (['2026-10-21', '2026-10-22'] as $date) {
    $report['line_metrics'][] = ['line_code' => 'APP-06', 'report_date' => $date, 'duration_hours' => 12, 'shift_code' => 'H12_1800',
        'window_start' => "$date 18:00:00", 'window_end' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 06:00:00'];
    $report['rows'][] = ['line_code' => 'APP-06', 'loading_at' => "$date 18:30:00", 'pcs' => 5, 'cycle_minutes' => 12];
    $report['rows'][] = ['line_code' => 'APP-06', 'loading_at' => "$date 19:30:00", 'pcs' => 5, 'cycle_minutes' => 8];
    $report['quality_entries'][] = ['line_code' => 'APP-06', 'report_date' => $date, 'shift_code' => 'H12_1800', 'rejected_fb' => 1, 'reworked_fb' => 0];
    $report['downtime_events'][] = ['line_code' => 'APP-06', 'window_date' => $date, 'starts_at' => "$date 18:00:00", 'ends_at' => "$date 19:00:00", 'event_type' => 'unplanned', 'status' => 'confirmed', 'source' => 'manual'];
}
$report['rows'][] = ['line_code' => 'APP-06', 'loading_at' => '2026-10-22 12:00:00', 'pcs' => 1, 'cycle_minutes' => 9999];
$report['quality_entries'][] = ['line_code' => 'APP-06', 'report_date' => '2026-10-22', 'shift_code' => 'DAY', 'rejected_fb' => 99, 'reworked_fb' => 0];
$lines = oee_export_sources($report)['lines'];
foreach ($lines as $line) {
    range_export_check($line['productive_fb'] === 2 && $line['good_fb'] === 1 && $line['ideal_output_minutes'] === 20.0, 'Repeated line across dates double-counted production/quality');
    range_export_check($line['unplanned_minutes'] === 60.0 && $line['calendar_minutes'] === 720.0, 'Daily window time is incorrect');
}
$path = $argv[1] ?? sys_get_temp_dir() . '/pns-range-formulas-' . bin2hex(random_bytes(6)) . '.xlsx';
try {
    file_put_contents($path, oee_export_xlsx($report));
    $zip = new ZipArchive(); $zip->open($path);
    $xml = new DOMDocument(); $xml->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $xp = new DOMXPath($xml); $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach (['I6' => 4, 'I7' => 2, 'I5' => 40, 'I8' => 1440, 'I3' => 120, 'B9' => 20 / 1440] as $ref => $expected) {
        range_export_check(abs((float) $xp->evaluate('string(//s:c[@r="' . $ref . '"]/s:v)') - $expected) < 1e-10, 'Wrong range aggregate: ' . $ref);
    }
    $formula = $xp->evaluate('string(//s:c[@r="M13"]/s:f)');
    range_export_check(str_contains($formula, 'P13') && str_contains($formula, 'Q13'), 'Productive FB formula must filter the daily window');
    $zip->close();
    $validator = trim((string) shell_exec('command -v officecli'));
    if ($validator !== '') {
        exec(escapeshellarg($validator) . ' validate ' . escapeshellarg($path) . ' --json 2>&1', $out, $code);
        range_export_check($code === 0, 'Range workbook schema invalid: ' . implode("\n", $out));
        exec('OFFICECLI_NO_AUTO_RESIDENT=1 ' . escapeshellarg($validator) . ' get ' . escapeshellarg($path) . ' ' . escapeshellarg('/OEE Report/B6:B10') . ' --json', $values, $code);
        range_export_check($code === 0, 'Independent calculation failed');
        $data = json_decode(implode("\n", $values), true, flags: JSON_THROW_ON_ERROR);
        $cells = $data['data']['results'][0]['children'] ?? [];
        range_export_check(count($cells) === 5, 'Independent evaluator missing cells');
        foreach ([1320 / 1440, 40 / 1320, 0.5, 20 / 1440, 1320 / 1440] as $index => $expected) {
            $format = $cells[$index]['format'];
            range_export_check(($format['evaluated'] ?? false) && abs((float) $format['computedValue'] - $expected) < 1e-9, 'Range Excel formula does not independently agree: ' . $index);
        }
        $commands = [
            ['command' => 'set', 'path' => '/OEE Report/P13', 'props' => ['value' => (string) oee_excel_datetime('2026-10-21 19:00:00')]],
            ['command' => 'get', 'path' => '/OEE Report/J13:O13'],
        ];
        exec('OFFICECLI_NO_AUTO_RESIDENT=1 ' . escapeshellarg($validator) . ' batch ' . escapeshellarg($path) . ' --commands ' . escapeshellarg(json_encode($commands)) . ' --json', $edited, $code);
        range_export_check($code === 0, 'Range input-edit evaluation failed');
        $data = json_decode(implode("\n", $edited), true, flags: JSON_THROW_ON_ERROR);
        $cells = $data['data']['results'][1]['output']['results'][0]['children'] ?? [];
        range_export_check(count($cells) === 6, 'Range edit evaluator missing cells');
        foreach ([0, 660, 8, 1, 0, 660] as $index => $expected) {
            $format = $cells[$index]['format'];
            range_export_check(($format['evaluated'] ?? false) && abs((float) $format['computedValue'] - $expected) < 1e-8, 'Range window edit did not recalculate source totals: ' . $index);
        }
        // Keep a requested output artifact in its original validated state.
        if (isset($argv[1])) file_put_contents($path, oee_export_xlsx($report));
    }
    echo "Date-range Excel cached values, formulas and schema passed\n";
} finally { if (!isset($argv[1]) && is_file($path)) unlink($path); }
