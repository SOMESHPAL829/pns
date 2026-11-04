<?php
declare(strict_types=1);

/**
 * SCADA timestamps are plant-local wall-clock values stored without a timezone.
 * Parse them on a fixed timeline so the web server's DST rules cannot add or
 * remove an hour from measured production and downtime intervals.
 */
function oee_wall_datetime(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('Invalid OEE timestamp: ' . $value);
    }
    return $date;
}

function oee_wall_timestamp(string $value): int
{
    return oee_wall_datetime($value)->getTimestamp();
}
