<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/oee_export.php';

function export_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Fail on warnings too: warning text in an HTTP download corrupts the ZIP.
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$report = [
    'date' => '2026-10-22', 'machine' => 'ALL',
    'oee' => ['availability' => 0.8, 'performance' => 0.9, 'quality' => 1, 'oee' => 0.72, 'utilization_24h' => 0.5],
    // Saved percentages deliberately disagree: the workbook must derive them
    // from the minutes and counts, rather than copy these stale results.
    'line_metrics' => [['line_code' => 'APP-06', 'availability' => 0.1, 'performance' => 0.1, 'quality' => 0.1, 'oee' => 0.001, 'utilization_24h' => 0.1,
        'window_start' => '2026-10-22 00:00:00', 'window_end' => '2026-10-23 00:00:00',
        'window_minutes' => 1440, 'planned_stop_minutes' => 540, 'unplanned_minutes' => 180, 'ideal_output_minutes' => 648, 'productive_fb' => 2, 'good_fb' => 2, 'calendar_minutes' => 1440]],
    'rows' => [
        ['line_code' => 'APP-06', 'si_no' => 1, 'seq' => '00123', 'part_code' => '=1+1', 'grade' => 'A&B <test>', 'used_colour' => 'BLACK-1', 'pcs' => 12, 'total_surface_area' => 1.25, 'is_productive' => 1, 'cycle_minutes' => 324],
        ['line_code' => 'APP-06', 'si_no' => 2, 'part_code' => 'Part B', 'pcs' => 10, 'cycle_minutes' => 324],
        ['line_code' => 'APP-06', 'si_no' => 3, 'part_code' => 'EMPTY', 'pcs' => 0, 'cycle_minutes' => null],
    ],
    'downtime_events' => [
        ['line_code' => 'APP-06', 'starts_at' => '2026-10-22 00:00:00', 'ends_at' => '2026-10-22 09:00:00', 'event_type' => 'planned', 'status' => 'confirmed', 'source' => 'manual'],
        ['line_code' => 'APP-06', 'starts_at' => '2026-10-22 09:00:00', 'ends_at' => '2026-10-22 12:00:00', 'event_type' => 'unplanned', 'status' => 'proposed', 'source' => 'gap'],
    ],
    'quality_entries' => [],
];
$path = $argv[1] ?? tempnam(sys_get_temp_dir(), 'pns-export-test-');
if (!isset($argv[1])) {
    $xlsxPath = $path . '.xlsx';
    rename($path, $xlsxPath);
    $path = $xlsxPath;
}
try {
    $bytes = oee_export_xlsx($report);
    export_check(str_starts_with($bytes, "PK\x03\x04"), 'Export must start with ZIP signature');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    export_check($zip->open($path, ZipArchive::CHECKCONS) === true, 'ZIP integrity failed');
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $xml = new DOMDocument();
        export_check($xml->loadXML($zip->getFromIndex($index)), 'Invalid package XML');
    }
    $workbook = new DOMDocument();
    $workbook->loadXML($zip->getFromName('xl/workbook.xml'));
    $names = [];
    foreach ($workbook->documentElement->childNodes as $node) {
        if ($node instanceof DOMElement) $names[] = $node->localName;
    }
    export_check(array_search('sheets', $names, true) < array_search('calcPr', $names, true), 'Excel requires sheets before calcPr');
    $styles = new DOMDocument();
    $styles->loadXML($zip->getFromName('xl/styles.xml'));
    foreach ($styles->getElementsByTagName('fgColor') as $colour) {
        export_check((bool) preg_match('/^[0-9A-Fa-f]{8}$/', $colour->getAttribute('rgb')), 'Excel requires eight-digit ARGB colours');
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    export_check(str_contains($sheet, '<f>IF(I2&gt;0,I4/I2,0)</f>'), 'Availability must be a live formula using summed inputs');
    export_check(str_contains($sheet, '<f>IF(I4&gt;0,I5/I4,0)</f>'), 'Performance must be a live formula');
    export_check(str_contains($sheet, '<f>IF(I6&gt;0,I7/I6,0)</f>'), 'Quality must be a live formula');
    export_check(str_contains($sheet, '<f>IF(I8&gt;0,I4/I8,0)</f>'), 'Utilization must divide run time by summed calendar time');
    export_check(str_contains($sheet, '<f>MAX(0,G13-H13)</f>'), 'Planned production time must be derived');
    export_check(str_contains($sheet, '<f>MAX(0,I13-J13)</f>'), 'Run time must subtract downtime');
    export_check(str_contains($sheet, '<f>SUM(K13:K13)</f>'), 'Aggregate runtime must sum line runtimes');
    $xml = new DOMDocument();
    $xml->loadXML($sheet);
    $formulaPath = new DOMXPath($xml);
    $formulaPath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach (['G13', 'H13', 'J13', 'L13', 'M13', 'N13', 'O13', 'R13', 'S13'] as $reference) {
        export_check($formulaPath->evaluate('count(//s:c[@r="' . $reference . '"]/s:f)') === 1.0, 'Source calculation is still hard-coded: ' . $reference);
    }
    export_check(str_contains($sheet, '<f>B6*B7*B8</f>'), 'Formula must omit leading equals sign');
    export_check(str_contains($sheet, '<v>0.72</v>'), 'Cached OEE value must match formula');
    export_check(str_contains($sheet, '<t>00123</t>'), 'Sequence leading zeros must be preserved');
    export_check(str_contains($sheet, '<t>=1+1</t>'), 'Source text must not become a formula');
    $zip->close();
    // Overlaps, rejected events and midnight clipping must match the server's
    // union-of-intervals rule, with unplanned taking precedence over planned.
    $overlap = $report;
    $overlap['downtime_events'][] = ['line_code' => 'APP-06', 'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 13:00:00', 'event_type' => 'unplanned', 'status' => 'approved', 'source' => 'manual'];
    $overlap['downtime_events'][] = ['line_code' => 'APP-06', 'starts_at' => '2026-10-22 08:30:00', 'ends_at' => '2026-10-22 10:00:00', 'event_type' => 'unplanned', 'status' => 'confirmed', 'source' => 'manual'];
    $overlap['downtime_events'][] = ['line_code' => 'APP-06', 'starts_at' => '2026-10-21 23:50:00', 'ends_at' => '2026-10-22 00:10:00', 'event_type' => 'unplanned', 'status' => 'proposed', 'source' => 'manual'];
    $overlap['downtime_events'][] = ['line_code' => 'APP-06', 'starts_at' => '2026-10-22 00:00:00', 'ends_at' => '2026-10-23 00:00:00', 'event_type' => 'unplanned', 'status' => 'rejected', 'source' => 'manual'];
    $source = oee_export_sources($overlap)['lines'][0];
    export_check($source['unplanned_minutes'] === 280.0 && $source['planned_stop_minutes'] === 500.0, 'Overlapping or rejected downtime counted incorrectly');
    // Imported SCADA text must never make invalid XML or emit PHP warnings.
    $originalRows = $report['rows'];
    $report['rows'] = [['part_code' => "A\x00B\x01C\xFF", 'report_colour' => 'ब्लैक & Black']];
    file_put_contents($path, oee_export_xlsx($report));
    $zip = new ZipArchive();
    $zip->open($path);
    $sheet = new DOMDocument();
    export_check($sheet->loadXML($zip->getFromName('xl/worksheets/sheet1.xml')), 'SCADA text must remain valid XML');
    $zip->close();
    $report['rows'] = $originalRows;
    // All lines must use ratios of totals, never mean percentages.
    $singleLineReport = $report;
    $report['line_metrics'][] = ['line_code' => 'APP-07', 'window_start' => '2026-10-22 00:00:00', 'window_end' => '2026-10-23 00:00:00'];
    $report['rows'][] = ['line_code' => 'APP-07', 'pcs' => 1, 'cycle_minutes' => 75];
    $report['rows'][] = ['line_code' => 'APP-07', 'pcs' => 1, 'cycle_minutes' => 75];
    $report['quality_entries'][] = ['line_code' => 'APP-07', 'rejected_fb' => 1, 'reworked_fb' => 0];
    $report['downtime_events'][] = ['line_code' => 'APP-07', 'starts_at' => '2026-10-22 00:00:00', 'ends_at' => '2026-10-22 19:00:00', 'event_type' => 'planned', 'status' => 'approved', 'source' => 'manual'];
    file_put_contents($path, oee_export_xlsx($report));
    $zip = new ZipArchive();
    $zip->open($path);
    $sheet = new DOMDocument();
    $sheet->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $xpath = new DOMXPath($sheet);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach (['B6' => 1020 / 1200, 'B7' => 798 / 1020, 'B8' => 3 / 4, 'B9' => (798 / 1200) * (3 / 4), 'B10' => 1020 / 2880] as $reference => $expected) {
        $actual = (float) $xpath->evaluate('string(//s:c[@r="' . $reference . '"]/s:v)');
        export_check(abs($actual - $expected) < 1e-10, 'Incorrect weighted calculation: ' . $reference);
    }
    $zip->close();
    // No-data reports must have valid formulas with safe zero denominators.
    file_put_contents($path, oee_export_xlsx(['date' => '2000-01-01', 'machine' => 'ALL']));
    $zip = new ZipArchive();
    $zip->open($path);
    export_check(str_contains($zip->getFromName('xl/worksheets/sheet1.xml'), '<f>IF(I2&gt;0,I4/I2,0)</f>'), 'No-data formula guard missing');
    $zip->close();
    try {
        oee_export_xlsx(['line_metrics' => [['line_code' => 'APP-06']]]);
        throw new RuntimeException('Missing source inputs must not silently become zero');
    } catch (InvalidArgumentException $expected) {
        // Expected: do not invent inputs from saved percentages.
    }
    $report = $singleLineReport;
    file_put_contents($path, oee_export_xlsx($report));
    // openpyxl tolerates malformed OOXML. Use the strict OpenXML validator
    // when installed, so Excel-only incompatibilities cannot pass unnoticed.
    $validator = trim((string) shell_exec('command -v officecli'));
    if ($validator !== '') {
        exec(escapeshellarg($validator) . ' validate ' . escapeshellarg($path) . ' --json 2>&1', $validationOutput, $exitCode);
        export_check($exitCode === 0, 'OpenXML schema validation failed: ' . implode("\n", $validationOutput));
        echo "Strict OpenXML schema validation passed\n";
        // Independently evaluate after editing three source inputs. This
        // proves dependent percentages are live formulas, not cached values.
        $recalculationPath = $path . '.recalc.xlsx';
        copy($path, $recalculationPath);
        try {
            $commands = [
                ['command' => 'set', 'path' => '/Downtime/C3', 'props' => ['value' => (string) oee_excel_datetime('2026-10-22 08:00:00')]],
                ['command' => 'set', 'path' => '/Downtime/C4', 'props' => ['value' => (string) oee_excel_datetime('2026-10-22 13:00:00')]],
                ['command' => 'set', 'path' => '/OEE Report/L17', 'props' => ['value' => '300']],
                ['command' => 'set', 'path' => '/Quality/C2', 'props' => ['value' => '1']],
                ['command' => 'get', 'path' => '/OEE Report/B6:B10'],
            ];
            exec('OFFICECLI_NO_AUTO_RESIDENT=1 ' . escapeshellarg($validator) . ' batch ' . escapeshellarg($recalculationPath) . ' --commands ' . escapeshellarg(json_encode($commands)) . ' --json 2>&1', $recalculationOutput, $exitCode);
            export_check($exitCode === 0, 'Formula recalculation failed: ' . implode("\n", $recalculationOutput));
            $result = json_decode(implode("\n", $recalculationOutput), true, flags: JSON_THROW_ON_ERROR);
            $cells = $result['data']['results'][4]['output']['results'][0]['children'] ?? [];
            export_check(count($cells) === 5, 'Formula evaluator must return all five aggregate cells');
            foreach ([0.75, 624 / 720, 0.5, 0.325, 0.5] as $index => $expected) {
                $format = $cells[$index]['format'];
                export_check(($format['evaluated'] ?? false) && abs((float) $format['computedValue'] - $expected) < 1e-10, 'Input edit did not recalculate ' . $cells[$index]['path']);
            }
            echo "Independent input-change recalculation passed\n";
            $commands = [
                ['command' => 'set', 'path' => '/OEE Report/I18', 'props' => ['value' => '0']],
                ['command' => 'set', 'path' => '/Quality/C2', 'props' => ['value' => '0']],
                ['command' => 'set', 'path' => '/OEE Report/Q13', 'props' => ['value' => (string) oee_excel_datetime('2026-10-23 01:00:00')]],
                ['command' => 'get', 'path' => '/OEE Report/J13:O13'],
            ];
            $recalculationOutput = [];
            exec('OFFICECLI_NO_AUTO_RESIDENT=1 ' . escapeshellarg($validator) . ' batch ' . escapeshellarg($recalculationPath) . ' --commands ' . escapeshellarg(json_encode($commands)) . ' --json 2>&1', $recalculationOutput, $exitCode);
            export_check($exitCode === 0, 'Source-row recalculation failed');
            $result = json_decode(implode("\n", $recalculationOutput), true, flags: JSON_THROW_ON_ERROR);
            $cells = $result['data']['results'][3]['output']['results'][0]['children'] ?? [];
            export_check(count($cells) === 6, 'Source-row evaluator must return J13:O13');
            foreach ([240, 780, 300, 1, 1, 1500] as $index => $expected) {
                $format = $cells[$index]['format'];
                export_check(($format['evaluated'] ?? false) && abs((float) $format['computedValue'] - $expected) < 1e-8, 'FB/window edit did not recalculate ' . $cells[$index]['path']);
            }
            echo "Productive/good FB, ideal minutes and calendar source edits passed\n";
        } finally {
            if (is_file($recalculationPath)) unlink($recalculationPath);
        }
    } else {
        echo "Strict schema check skipped (install officecli to run it)\n";
    }
    echo "OEE export tests passed\n";
} finally {
    if (!isset($argv[1]) && is_file($path)) unlink($path);
    restore_error_handler();
}
