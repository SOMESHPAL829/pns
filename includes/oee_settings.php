<?php
declare(strict_types=1);

/** Accept plant codes discovered from SCADA ingest or entered in master data. */
function oee_line_code_ok(string $line): bool
{
    return (bool) preg_match('/^[A-Z][A-Z0-9._-]{0,39}$/', strtoupper(trim($line)));
}

/**
 * Dynamic plant list from live OEE tables — no hardcoded APP/CED codes.
 * New lines appear automatically after the first successful Unit 1 ingest
 * (or after a cycle/threshold/downtime row is saved for that line).
 */
function oee_known_lines(PDO $pdo): array
{
    $clean = [];
    foreach ([
        'oee_import_batches',
        'oee_fb_runs',
        'oee_snapshots',
        'oee_line_settings',
        'oee_cycle_time_standards',
        'oee_downtime_events',
    ] as $table) {
        try {
            $rows = $pdo->query(
                'SELECT DISTINCT line_code FROM ' . $table . ' WHERE line_code IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            continue;
        }
        foreach ($rows as $line) {
            $code = strtoupper(trim((string) $line));
            if (oee_line_code_ok($code)) {
                $clean[$code] = $code;
            }
        }
    }
    $lines = array_values($clean);
    sort($lines);
    return $lines;
}

function oee_is_known_line(PDO $pdo, string $line): bool
{
    return in_array(strtoupper(trim($line)), oee_known_lines($pdo), true);
}

/** Build <option> markup for machine selectors from a dynamic line list. */
function oee_machine_options_html(array $lines, bool $includeAll = false, string $allLabel = 'All Machines', string $labelSuffix = ''): string
{
    $html = $includeAll
        ? '<option value="ALL">' . htmlspecialchars($allLabel, ENT_QUOTES, 'UTF-8') . '</option>'
        : '';
    foreach ($lines as $line) {
        $code = strtoupper(trim((string) $line));
        if (!oee_line_code_ok($code)) {
            continue;
        }
        $label = $labelSuffix === '' ? $code : $code . $labelSuffix;
        $html .= '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function oee_validate_gap_threshold(mixed $value): float
{
    $minutes = (is_string($value) || is_int($value) || is_float($value))
        ? filter_var($value, FILTER_VALIDATE_FLOAT) : false;
    if ($minutes === false || !is_finite($minutes) || $minutes < 0.001 || $minutes > 1440
        || abs($minutes * 1000 - round($minutes * 1000)) > 0.000001) {
        throw new InvalidArgumentException('Gap threshold must be 0.001–1440 minutes, with at most three decimal places.');
    }
    return (float) $minutes;
}

/** Missing rows retain the original 20-minute limit; migrate the table first. */
function oee_gap_thresholds(PDO $pdo): array
{
    $limits = array_fill_keys(oee_known_lines($pdo), 20.0);
    try {
        foreach ($pdo->query('SELECT line_code, gap_threshold_minutes FROM oee_line_settings')->fetchAll() as $row) {
            $code = strtoupper(trim((string) $row['line_code']));
            if ($code === '') {
                continue;
            }
            $limits[$code] = oee_validate_gap_threshold($row['gap_threshold_minutes']);
        }
    } catch (Throwable) {
        // Settings table may be absent before migration.
    }
    ksort($limits);
    return $limits;
}

function oee_save_gap_threshold(PDO $pdo, string $line, mixed $value, int $userId): float
{
    $line = strtoupper(trim($line));
    if ($line === 'ALL' || !oee_line_code_ok($line)) {
        throw new InvalidArgumentException('Enter a valid plant / machine code for the gap threshold.');
    }
    $minutes = oee_validate_gap_threshold($value);
    $sql = 'INSERT INTO oee_line_settings (line_code, gap_threshold_minutes, updated_by) VALUES (?, ?, ?) ';
    $sql .= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'ON CONFLICT(line_code) DO UPDATE SET gap_threshold_minutes=excluded.gap_threshold_minutes, updated_by=excluded.updated_by, updated_at=CURRENT_TIMESTAMP'
        : 'ON DUPLICATE KEY UPDATE gap_threshold_minutes=VALUES(gap_threshold_minutes), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$line, $minutes, $userId]);
    return $minutes;
}
