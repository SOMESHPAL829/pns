<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/oee_calculator.php';
require_once dirname(__DIR__) . '/includes/oee_window.php';

function window_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$day = oee_window_definition('2026-10-22', 24, '00:00');
$morning = oee_window_definition('2026-10-22', 12, '00:00');
$evening = oee_window_definition('2026-10-22', 12, '12:00');
$night = oee_window_definition('2026-10-22', 12, '18:00');
window_check($morning['calendar_minutes'] === 720 && $day['calendar_minutes'] === 1440, 'duration minutes incorrect');
window_check($night['window_end'] === '2026-10-23 06:00:00', 'night window must cross midnight');
foreach ([[6, '00:00'], [12, '25:00'], [12, '6:00'], [24, '06:00']] as [$hours, $start]) {
    try {
        oee_window_definition('2026-10-22', $hours, $start);
        throw new RuntimeException('Invalid window accepted');
    } catch (InvalidArgumentException $expected) {}
}
$rows = [
    ['loading_at' => '2026-10-22 00:00:00', 'pcs' => 5, 'cycle_minutes' => 10],
    ['loading_at' => '2026-10-22 11:59:59', 'pcs' => 7, 'cycle_minutes' => 20],
    ['loading_at' => '2026-10-22 12:00:00', 'pcs' => 8, 'cycle_minutes' => 30],
    ['loading_at' => '2026-10-22 18:00:00', 'pcs' => 9, 'cycle_minutes' => 40],
    ['loading_at' => '2026-10-23 00:00:00', 'pcs' => 10, 'cycle_minutes' => 50],
    ['loading_at' => '2026-10-23 05:59:59', 'pcs' => 11, 'cycle_minutes' => 60],
    ['loading_at' => '2026-10-23 06:00:00', 'pcs' => 12, 'cycle_minutes' => 70],
];
$events = [
    ['starts_at' => '2026-10-22 11:30:00', 'ends_at' => '2026-10-22 12:30:00', 'event_type' => 'unplanned', 'status' => 'confirmed'],
    ['starts_at' => '2026-10-22 12:15:00', 'ends_at' => '2026-10-22 13:00:00', 'event_type' => 'unplanned', 'status' => 'proposed'],
    ['starts_at' => '2026-10-22 12:00:00', 'ends_at' => '2026-10-22 14:00:00', 'event_type' => 'planned', 'status' => 'approved'],
    ['starts_at' => '2026-10-22 00:00:00', 'ends_at' => '2026-10-23 00:00:00', 'event_type' => 'unplanned', 'status' => 'rejected'],
];
$m = oee_window_metrics($morning, $rows, $events, []);
$e = oee_window_metrics($evening, $rows, $events, []);
$d = oee_window_metrics($day, $rows, $events, []);
$n = oee_window_metrics($night, $rows, $events, []);
window_check($m['productive_fb'] === 2 && $e['productive_fb'] === 2 && $d['productive_fb'] === 4 && $n['productive_fb'] === 3, 'half-open production filtering failed');
window_check($m['ideal_output_minutes'] === 30.0 && $e['ideal_output_minutes'] === 70.0 && $n['ideal_output_minutes'] === 150.0, 'ideal time must use only the selected FB');
window_check($m['unplanned_minutes'] === 30.0 && $e['unplanned_minutes'] === 60.0 && $d['unplanned_minutes'] === 90.0, 'downtime clipping/overlap failed');
window_check($e['planned_stop_minutes'] === 60.0 && $d['planned_stop_minutes'] === 60.0, 'unplanned precedence failed');
window_check(abs($m['utilization_window'] - 690 / 720) < 1e-10 && abs($m['utilization_24h'] - 690 / 1440) < 1e-10, 'window and 24h utilization confused');
$dailyQuality = [['report_date' => '2026-10-22', 'shift_code' => '', 'rejected_fb' => 1, 'reworked_fb' => 0]];
window_check(oee_window_metrics($morning, $rows, $events, $dailyQuality)['good_fb'] === 2, 'daily losses must not be copied into a shift');
$shiftQuality = [['report_date' => '2026-10-22', 'shift_code' => 'H12_0000', 'rejected_fb' => 1, 'reworked_fb' => 0]];
window_check(oee_window_metrics($morning, $rows, $events, $shiftQuality)['good_fb'] === 1, 'matching shift quality not applied');
$all = oee_window_aggregate([$m, $e]);
window_check(abs($all['availability'] - 1290 / 1380) < 1e-10 && abs($all['utilization_window'] - 1290 / 1440) < 1e-10, 'weighted aggregate incorrect');
$range = oee_date_range_definition('2026-10-22', '2026-10-23', 12, '18:00');
window_check($range['day_count'] === 2 && $range['calendar_minutes'] === 1440, 'range must include both days and selected minutes per day');
window_check(count($range['windows']) === 2 && $range['windows'][1]['window_start'] === '2026-10-23 18:00:00' && $range['window_end'] === '2026-10-24 06:00:00', '12h range must contain disjoint daily windows');
window_check(oee_date_range_definition('2024-02-28', '2024-03-01')['day_count'] === 3, 'leap day missing');
window_check(oee_date_range_definition('2026-10-22', '2026-10-22')['calendar_minutes'] === 1440, 'single-day range changed');
foreach ([['2026-10-23', '2026-10-22'], ['2026-02-30', '2026-03-01'], ['2026-01-01', '2026-04-04']] as [$from, $to]) {
    try {
        oee_date_range_definition($from, $to);
        throw new RuntimeException('Invalid date range accepted');
    } catch (InvalidArgumentException $expected) {}
}
$rangeMetrics = array_map(static fn (array $w): array => oee_window_metrics($w, $rows, $events, []), $range['windows']);
$combined = oee_window_aggregate($rangeMetrics);
window_check($combined['productive_fb'] === 3 && $combined['ideal_output_minutes'] === 150.0, 'range includes off-shift rows or duplicates');
window_check($combined['calendar_minutes'] === 1440 && abs($combined['utilization_24h'] - $combined['run_minutes'] / 2880) < 1e-10, 'range calendar denominator incorrect');
$nested = oee_window_aggregate([$combined, $combined]);
window_check($nested['calendar_24h_minutes'] === 5760 && abs($nested['utilization_24h'] - $combined['utilization_24h']) < 1e-10, 'multi-line range lost the number of days');
$empty = oee_window_aggregate(array_map(static fn (array $w): array => oee_window_metrics($w, [], [], []), $range['windows']));
window_check($empty['productive_fb'] === 0 && $empty['calendar_minutes'] === 1440 && $empty['oee'] === 0.0, 'empty days must retain their selected time and zero OEE');
echo "OEE date-range and 12h/24h window tests passed\n";
