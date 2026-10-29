<?php
declare(strict_types=1);

function oee_duration_setting_date(PDO $pdo, ?string $date): string
{
    if (is_string($date) && trim($date) !== '') return oee_validate_date(trim($date));
    $latest = $pdo->query('SELECT MAX(report_date) FROM oee_import_batches')->fetchColumn();
    return is_string($latest) && $latest !== '' ? oee_validate_date($latest) : date('Y-m-d');
}

function oee_duration_setting(PDO $pdo, ?string $date): array
{
    $reportDate = oee_duration_setting_date($pdo, $date);
    $query = $pdo->prepare('SELECT duration_minutes, updated_at FROM oee_daily_duration_settings WHERE report_date = ?');
    $query->execute([$reportDate]);
    $setting = $query->fetch();
    return [
        'date' => $reportDate,
        'configured' => (bool) $setting,
        'duration_minutes' => $setting ? (int) $setting['duration_minutes'] : 1440,
        'updated_at' => $setting['updated_at'] ?? null,
    ];
}

function oee_save_duration_setting(PDO $pdo, array $data, int $userId): array
{
    $date = oee_validate_date(trim((string) ($data['date'] ?? '')));
    $duration = filter_var($data['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
    $supported = array_map(static fn (int $hours): int => $hours * 60, oee_supported_window_hours());
    if ($duration === false || !in_array($duration, $supported, true)) {
        throw new InvalidArgumentException('Duration must be 480, 600, 720, 960, or 1440 minutes.');
    }
    $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO oee_daily_duration_settings (report_date, duration_minutes, updated_by) VALUES (?, ?, ?) ON CONFLICT(report_date) DO UPDATE SET duration_minutes = excluded.duration_minutes, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP'
        : 'INSERT INTO oee_daily_duration_settings (report_date, duration_minutes, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE duration_minutes = VALUES(duration_minutes), updated_by = VALUES(updated_by)';
    $query = $pdo->prepare($upsert);
    $query->execute([$date, $duration, $userId]);
    return oee_duration_setting($pdo, $date);
}
