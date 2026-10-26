<?php
declare(strict_types=1);

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
    $limits = array_fill_keys(['APP-06', 'APP-07', 'APP-10'], 20.0);
    foreach ($pdo->query('SELECT line_code, gap_threshold_minutes FROM oee_line_settings')->fetchAll() as $row) {
        if (array_key_exists($row['line_code'], $limits)) {
            $limits[$row['line_code']] = oee_validate_gap_threshold($row['gap_threshold_minutes']);
        }
    }
    return $limits;
}

function oee_save_gap_threshold(PDO $pdo, string $line, mixed $value, int $userId): float
{
    if (!in_array($line, ['APP-06', 'APP-07', 'APP-10'], true)) {
        throw new InvalidArgumentException('Choose APP-06, APP-07 or APP-10 for the gap threshold.');
    }
    $minutes = oee_validate_gap_threshold($value);
    $sql = 'INSERT INTO oee_line_settings (line_code, gap_threshold_minutes, updated_by) VALUES (?, ?, ?) ';
    $sql .= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'ON CONFLICT(line_code) DO UPDATE SET gap_threshold_minutes=excluded.gap_threshold_minutes, updated_by=excluded.updated_by, updated_at=CURRENT_TIMESTAMP'
        : 'ON DUPLICATE KEY UPDATE gap_threshold_minutes=VALUES(gap_threshold_minutes), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$line, $minutes, $userId]);
    return $minutes;
}
