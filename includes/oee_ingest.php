<?php
declare(strict_types=1);

function oee_string(mixed $value, string $field, int $maxLength, bool $required = true): string
{
    if ($value === null && !$required) {
        return '';
    }
    if (!is_string($value) && !is_numeric($value)) {
        throw new InvalidArgumentException($field . ' must be text.');
    }
    $text = trim((string) $value);
    if ($required && $text === '') {
        throw new InvalidArgumentException($field . ' is required.');
    }
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException($field . ' is too long.');
    }
    return $text;
}

function oee_row_value(array $row, string ...$keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }
    return null;
}

function oee_nullable_number(mixed $value, string $field): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value) || !is_finite((float) $value)) {
        throw new InvalidArgumentException($field . ' must be numeric.');
    }
    return (float) $value;
}

function oee_nonnegative_int(mixed $value, string $field): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
        throw new InvalidArgumentException($field . ' must be a non-negative integer.');
    }
    return (int) $value;
}

function oee_validate_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('report_date must use YYYY-MM-DD.');
    }
    return $value;
}

function oee_validate_payload(array $payload, int $maxRows, int $maxBytes): array
{
    if (strlen((string) json_encode($payload, JSON_UNESCAPED_SLASHES)) > $maxBytes) {
        throw new InvalidArgumentException('Payload is too large.');
    }
    if ((int) ($payload['schema_version'] ?? 0) !== 1) {
        throw new InvalidArgumentException('Unsupported schema_version.');
    }
    $source = oee_string($payload['source'] ?? null, 'source', 80);
    $lineCode = oee_string($payload['line_code'] ?? null, 'line_code', 40);
    // Keep accepting the legacy Unit 1 sender identity while storing APP-06 internally.
    if ($lineCode === 'UNIT-1') {
        $lineCode = 'APP-06';
    }
    $batchId = oee_string($payload['batch_id'] ?? null, 'batch_id', 120);
    $reportDate = oee_validate_date(oee_string($payload['report_date'] ?? null, 'report_date', 10));
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $source . ':' . $lineCode . ':' . $batchId)) {
        throw new InvalidArgumentException('source, line_code, or batch_id contains invalid characters.');
    }
    $rows = $payload['rows'] ?? null;
    if (!is_array($rows) || count($rows) > $maxRows) {
        throw new InvalidArgumentException('rows must contain between 0 and the configured maximum.');
    }

    $normalizedRows = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('rows[' . $index . '] must be an object.');
        }
        $siNo = oee_nonnegative_int(oee_row_value($row, 'si_no', 'SI_NO'), 'rows[' . $index . '].si_no');
        $pcs = oee_nonnegative_int(oee_row_value($row, 'pcs', 'stage_pieces', 'StagePieces') ?? 0, 'rows[' . $index . '].pcs');
        $normalizedRows[] = [
            'si_no' => $siNo,
            'seq' => oee_string(oee_row_value($row, 'seq', 'sequence', 'StageSeqNo') ?? '', 'rows[' . $index . '].seq', 120, false),
            'part_code' => oee_string(oee_row_value($row, 'part', 'part_code', 'stage_part_no', 'StagePartNo') ?? '', 'rows[' . $index . '].part', 120, false),
            'grade' => oee_string(oee_row_value($row, 'grade', 'part_grade') ?? '', 'rows[' . $index . '].grade', 80, false),
            'report_colour' => oee_string(oee_row_value($row, 'colour_selection', 'report_colour') ?? '', 'rows[' . $index . '].colour', 120, false),
            'used_colour' => oee_string(oee_row_value($row, 'colour', 'used_colour') ?? '', 'rows[' . $index . '].used_colour', 120, false),
            'loading_time' => oee_string(oee_row_value($row, 'loading', 'loading_time') ?? '', 'rows[' . $index . '].loading_time', 32, false),
            'unloading_time' => oee_string(oee_row_value($row, 'unloading', 'unloading_time') ?? '', 'rows[' . $index . '].unloading_time', 32, false),
            'pcs' => $pcs,
            'surface_area' => oee_nullable_number(oee_row_value($row, 'surface_area'), 'rows[' . $index . '].surface_area'),
            'total_surface_area' => oee_nullable_number(oee_row_value($row, 'total_surface_area'), 'rows[' . $index . '].total_surface_area'),
            'raw_stage_data' => $row,
        ];
    }
    return [
        'schema_version' => 1,
        'source' => $source,
        'line_code' => $lineCode,
        'batch_id' => $batchId,
        'report_date' => $reportDate,
        'generated_at' => oee_string($payload['generated_at'] ?? '', 'generated_at', 64, false),
        'columns' => is_array($payload['columns'] ?? null) ? array_values($payload['columns']) : [],
        'rows' => $normalizedRows,
    ];
}

function oee_payload_hash(array $payload): string
{
    $identityPayload = $payload;
    unset($identityPayload['generated_at']);
    return hash(
        'sha256',
        (string) json_encode($identityPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
    );
}

function oee_combine_report_datetime(string $reportDate, string $time): ?string
{
    if ($time === '') {
        return null;
    }
    foreach ([
        ['Y-m-d H:i:s', $time],
        ['Y-m-d H:i', $time],
        ['Y-m-d H:i:s', $reportDate . ' ' . $time],
        ['Y-m-d H:i', $reportDate . ' ' . $time],
    ] as [$format, $valueText]) {
        $value = DateTimeImmutable::createFromFormat($format, $valueText);
        if ($value !== false) {
            return $value->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Report times must use a valid time format.');
}
