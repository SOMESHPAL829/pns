<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/oee_export.php';

function simple_export_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$report = [
    'window_metrics' => [
        ['report_date' => '2026-10-22', 'line_code' => 'APP-06', 'oee' => 0.5],
        ['report_date' => '2026-10-21', 'line_code' => 'APP-09', 'oee' => 0.812],
    ],
    'downtime_events' => [
        [
            'window_date' => '2026-10-21',
            'line_code' => 'APP-09',
            'reason_label' => 'Breakdown',
            'reason_code' => 'BD',
            'starts_at' => '2026-10-21 08:00:00',
            'ends_at' => '2026-10-21 09:15:00',
        ],
        [
            'window_date' => '2026-10-21',
            'line_code' => 'APP-09',
            'reason_label' => 'Breakdown',
            'reason_code' => 'BD',
            'starts_at' => '2026-10-21 08:00:00',
            'ends_at' => '2026-10-21 09:15:00',
        ],
        [
            'window_date' => '2026-10-22',
            'line_code' => 'APP-06',
            'reason_label' => '',
            'reason_code' => 'PLANNED_STOP',
            'starts_at' => '2026-10-22 10:00:00',
            'ends_at' => '2026-10-22 11:00:00',
        ],
    ],
];

$oeePath = sys_get_temp_dir() . '/pns-simple-oee-' . bin2hex(random_bytes(4)) . '.xlsx';
$dtPath = sys_get_temp_dir() . '/pns-simple-dt-' . bin2hex(random_bytes(4)) . '.xlsx';
try {
    file_put_contents($oeePath, oee_range_report_xlsx($report, 'oee'));
    file_put_contents($dtPath, oee_range_report_xlsx($report, 'downtime'));

    $zip = new ZipArchive();
    simple_export_check($zip->open($oeePath) === true, 'OEE workbook open failed');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    simple_export_check(str_contains($sheet, '>Date<') && str_contains($sheet, '>Plant<') && str_contains($sheet, '>OEE %<'), 'OEE headers missing');
    simple_export_check(str_contains($sheet, '>APP-09<') && str_contains($sheet, '<v>81.2</v>'), 'OEE row missing');
    simple_export_check(strpos($sheet, '2026-10-21') < strpos($sheet, '2026-10-22'), 'OEE rows must sort by date');

    $zip = new ZipArchive();
    simple_export_check($zip->open($dtPath) === true, 'Downtime workbook open failed');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    simple_export_check(str_contains($sheet, '>Downtime Reason<') && str_contains($sheet, '>From Date Time<') && str_contains($sheet, '>To Date Time<'), 'Downtime headers missing');
    simple_export_check(str_contains($sheet, '>Breakdown<') && str_contains($sheet, '>PLANNED_STOP<'), 'Downtime reasons missing');
    simple_export_check(substr_count($sheet, '2026-10-21 08:00:00') === 1, 'Duplicate downtime rows were not deduped');

    echo "Date-range simple OEE and Downtime Excel exports passed\n";
} finally {
    @unlink($oeePath);
    @unlink($dtPath);
}
