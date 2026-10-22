<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/oee_ingest.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validPayload = [
    'schema_version' => 1,
    'source' => 'UNIT-1-APP-06',
    'line_code' => 'UNIT-1',
    'report_date' => '2026-10-17',
    'batch_id' => 'unit1-app06-20261017',
    'generated_at' => '2026-10-21T10:00:00+05:30',
    'columns' => ['si_no', 'grade', 'colour'],
    'rows' => [[
        'si_no' => 1,
        'grade' => '7XXX',
        'colour' => 'BLACK-1',
        'loading_time' => '00:07:12',
        'unloading_time' => '01:58:17',
        'seq' => 'M10Y26-1531',
        'part' => 'AP-012(02)',
        'pcs' => 72,
        'surface_area' => 103.68,
        'raw_stage_data' => [],
    ]],
];

// Arrange / Act / Assert — the contract accepts a valid report.
$normalized = oee_validate_payload($validPayload, 100, 1024 * 1024);
assert_true($normalized['batch_id'] === $validPayload['batch_id'], 'batch id was not preserved');
assert_true(count($normalized['rows']) === 1, 'valid row was not accepted');

// Arrange / Act / Assert — malformed reports are rejected at the boundary.
$invalidPayload = $validPayload;
$invalidPayload['report_date'] = '17-10-2026';
$rejected = false;
try {
    oee_validate_payload($invalidPayload, 100, 1024 * 1024);
} catch (InvalidArgumentException $exception) {
    $rejected = true;
}
assert_true($rejected, 'invalid report date was accepted');

echo "OEE ingest contract tests passed\n";
