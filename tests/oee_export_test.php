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
    'line_metrics' => [['line_code' => 'APP-06', 'availability' => 0.8, 'performance' => 0.9, 'quality' => 1, 'oee' => 0.72, 'utilization_24h' => 0.5]],
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
    file_put_contents($path, oee_export_xlsx($report));
    // openpyxl tolerates malformed OOXML. Use the strict OpenXML validator
    // when installed, so Excel-only incompatibilities cannot pass unnoticed.
    $validator = trim((string) shell_exec('command -v officecli'));
    if ($validator !== '') {
        exec(escapeshellarg($validator) . ' validate ' . escapeshellarg($path) . ' --json 2>&1', $validationOutput, $exitCode);
        export_check($exitCode === 0, 'OpenXML schema validation failed: ' . implode("\n", $validationOutput));
        echo "Strict OpenXML schema validation passed\n";
    } else {
        echo "Strict schema check skipped (install officecli to run it)\n";
    }
    echo "OEE export tests passed\n";
} finally {
    if (!isset($argv[1]) && is_file($path)) unlink($path);
    restore_error_handler();
}
