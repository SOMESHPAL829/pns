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
        'window_minutes' => 1000, 'planned_stop_minutes' => 100, 'unplanned_minutes' => 180, 'ideal_output_minutes' => 648, 'productive_fb' => 90, 'good_fb' => 90, 'calendar_minutes' => 1440]],
    'rows' => [['line_code' => 'APP-06', 'si_no' => 1, 'seq' => '00123', 'part_code' => '=1+1', 'grade' => 'A&B <test>', 'used_colour' => 'BLACK-1', 'pcs' => 12, 'total_surface_area' => 1.25, 'is_productive' => 1]],
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
    export_check(str_contains($sheet, '<f>B6*B7*B8</f>'), 'Formula must omit leading equals sign');
    export_check(str_contains($sheet, '<v>0.72</v>'), 'Cached OEE value must match formula');
    export_check(str_contains($sheet, '<t>00123</t>'), 'Sequence leading zeros must be preserved');
    export_check(str_contains($sheet, '<t>=1+1</t>'), 'Source text must not become a formula');
    $zip->close();
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
    $report['line_metrics'][] = ['line_code' => 'APP-07', 'window_minutes' => 400, 'planned_stop_minutes' => 100, 'unplanned_minutes' => 0, 'ideal_output_minutes' => 150, 'productive_fb' => 30, 'good_fb' => 15, 'calendar_minutes' => 1440];
    file_put_contents($path, oee_export_xlsx($report));
    $zip = new ZipArchive();
    $zip->open($path);
    $sheet = new DOMDocument();
    $sheet->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $xpath = new DOMXPath($sheet);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach (['B6' => 1020 / 1200, 'B7' => 798 / 1020, 'B8' => 105 / 120, 'B9' => (798 / 1200) * (105 / 120), 'B10' => 1020 / 2880] as $reference => $expected) {
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
                ['command' => 'set', 'path' => '/OEE Report/H13', 'props' => ['value' => '200']],
                ['command' => 'set', 'path' => '/OEE Report/L13', 'props' => ['value' => '324']],
                ['command' => 'set', 'path' => '/OEE Report/N13', 'props' => ['value' => '45']],
                ['command' => 'get', 'path' => '/OEE Report/B6:B10'],
            ];
            exec('OFFICECLI_NO_AUTO_RESIDENT=1 ' . escapeshellarg($validator) . ' batch ' . escapeshellarg($recalculationPath) . ' --commands ' . escapeshellarg(json_encode($commands)) . ' --json 2>&1', $recalculationOutput, $exitCode);
            export_check($exitCode === 0, 'Formula recalculation failed: ' . implode("\n", $recalculationOutput));
            $result = json_decode(implode("\n", $recalculationOutput), true, flags: JSON_THROW_ON_ERROR);
            $cells = $result['data']['results'][3]['output']['results'][0]['children'] ?? [];
            export_check(count($cells) === 5, 'Formula evaluator must return all five aggregate cells');
            foreach ([0.775, 324 / 620, 0.5, 0.2025, 620 / 1440] as $index => $expected) {
                $format = $cells[$index]['format'];
                export_check(($format['evaluated'] ?? false) && abs((float) $format['computedValue'] - $expected) < 1e-10, 'Input edit did not recalculate ' . $cells[$index]['path']);
            }
            echo "Independent input-change recalculation passed\n";
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
