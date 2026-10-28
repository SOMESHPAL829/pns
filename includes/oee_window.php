<?php
declare(strict_types=1);

require_once __DIR__ . '/oee_settings.php';

function oee_supported_window_hours(): array
{
    return [8, 10, 12, 16, 24];
}

function oee_window_definition(string $date, int $hours = 24, string $startTime = '00:00'): array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date || !in_array($hours, oee_supported_window_hours(), true) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $startTime) || ($hours === 24 && $startTime !== '00:00')) {
        throw new InvalidArgumentException('Choose a supported duration (480, 600, 720, 960, or 1440 minutes) and a valid start time (24h starts at 00:00).');
    }
    $start = new DateTimeImmutable($date . ' ' . $startTime . ':00');
    return ['duration_hours' => $hours, 'duration_minutes' => $hours * 60, 'start_time' => $startTime, 'date' => $date,
        'window_start' => $start->format('Y-m-d H:i:s'), 'window_end' => $start->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s'),
        'calendar_minutes' => $hours * 60, 'shift_code' => $hours === 24 ? 'DAY' : 'H' . $hours . '_' . str_replace(':', '', $startTime)];
}

/** Both reporting dates are inclusive; each day has its own selected window. */
function oee_date_range_definition(string $from, string $to, int $hours = 24, string $startTime = '00:00'): array
{
    $first = oee_window_definition($from, $hours, $startTime);
    $last = oee_window_definition($to, $hours, $startTime);
    $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a') + 1;
    if ($days < 1 || $days > 93) throw new InvalidArgumentException('Choose From date before or equal to To date, up to 93 days inclusive.');
    $windows = [];
    for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
        $windows[] = oee_window_definition($day->format('Y-m-d'), $hours, $startTime);
    }
    return array_replace($first, ['from_date' => $from, 'to_date' => $to, 'day_count' => $days,
        'calendar_minutes' => $days * $hours * 60, 'window_end' => $last['window_end'], 'windows' => $windows]);
}

function oee_window_metrics(array $window, array $rows, array $events, array $quality): array
{
    $flags = [];
    $productive = 0;
    $ideal = 0.0;
    foreach ($rows as $row) {
        $load = $row['loading_at'] ?? '';
        if (!$load || $load < $window['window_start'] || $load >= $window['window_end'] || (int) ($row['pcs'] ?? 0) <= 0) continue;
        if (!isset($row['cycle_minutes']) || (float) $row['cycle_minutes'] <= 0) throw new InvalidArgumentException('Applied cycle time missing for selected FB.');
        $productive++;
        $ideal += (float) $row['cycle_minutes'];
        $rowFlags = is_array($row['flags'] ?? null) ? $row['flags'] : json_decode((string) ($row['flags'] ?? '{}'), true);
        if (!empty($rowFlags['colour_no_standard'])) {
            $flags['missing_cycle_time'] = true;
            $flags['missing_cycle_colours'][] = $row['used_colour'] ?: ($row['report_colour'] ?: 'NO_COLOUR');
        }
    }
    if (!empty($flags['missing_cycle_colours'])) $flags['missing_cycle_colours'] = array_values(array_unique($flags['missing_cycle_colours']));
    $start = strtotime($window['window_start']);
    $end = strtotime($window['window_end']);
    $boundaries = [$start, $end];
    $clipped = [];
    foreach ($events as $event) {
        if (!in_array($event['status'], ['proposed', 'confirmed', 'approved'], true)) continue;
        $a = max($start, strtotime($event['starts_at']));
        $b = min($end, strtotime($event['ends_at']));
        if ($b <= $a) continue;
        $clipped[] = ['start' => $a, 'end' => $b, 'type' => $event['event_type']];
        $boundaries[] = $a;
        $boundaries[] = $b;
    }
    sort($boundaries, SORT_NUMERIC);
    $boundaries = array_values(array_unique($boundaries));
    $planned = $unplanned = 0.0;
    for ($i = 0, $count = count($boundaries) - 1; $i < $count; $i++) {
        $active = array_filter($clipped, static fn (array $event): bool => $event['start'] < $boundaries[$i + 1] && $event['end'] > $boundaries[$i]);
        if (!$active) continue;
        if (count($active) > 1) $flags['overlapping_downtime'] = true;
        $minutes = ($boundaries[$i + 1] - $boundaries[$i]) / 60;
        if (array_filter($active, static fn (array $event): bool => $event['type'] === 'unplanned')) $unplanned += $minutes;
        else $planned += $minutes;
    }
    $rejected = $reworked = $qualityCount = 0;
    foreach ($quality as $entry) {
        if (($entry['report_date'] ?? $window['date']) !== $window['date']) continue;
        if ($window['duration_hours'] !== 24 && ($entry['shift_code'] ?? '') !== $window['shift_code']) continue;
        $qualityCount++;
        $rejected += (int) $entry['rejected_fb'];
        $reworked += (int) $entry['reworked_fb'];
    }
    if (!$qualityCount) {
        $flags['quality_not_entered_assumed_100'] = true;
        if ($window['duration_hours'] !== 24) $flags['shift_quality_not_entered'] = true;
    }
    if (!$productive) $flags['no_data'] = true;
    $metrics = oee_metrics($window['calendar_minutes'], round($planned, 3), round($unplanned, 3), round($ideal, 3), $productive, $rejected, $reworked);
    if ($metrics['performance'] > 1) $flags['performance_above_100'] = true;
    return array_merge($metrics, ['window_start' => $window['window_start'], 'window_end' => $window['window_end'], 'window_minutes' => $window['calendar_minutes'],
        'calendar_minutes' => $window['calendar_minutes'], 'planned_stop_minutes' => round($planned, 3), 'unplanned_minutes' => round($unplanned, 3),
        'rejected_fb' => $rejected, 'reworked_fb' => $reworked, 'utilization_window' => $metrics['run_minutes'] / $window['calendar_minutes'], 'calculation_flags' => $flags]);
}

function oee_window_aggregate(array $lines): array
{
    $totals = array_fill_keys(['productive_fb', 'good_fb', 'planned_minutes', 'planned_stop_minutes', 'unplanned_minutes', 'run_minutes', 'ideal_output_minutes', 'window_minutes', 'calendar_minutes'], 0);
    $flags = [];
    $calendar24 = 0;
    foreach ($lines as $line) {
        $calendar24 += $line['calendar_24h_minutes'] ?? 1440;
        foreach ($totals as $key => $value) $totals[$key] += $line[$key];
        foreach ($line['calculation_flags'] as $key => $value) {
            $flags[$key] = is_array($value) ? array_values(array_unique(array_merge($flags[$key] ?? [], $value))) : $value;
        }
    }
    $totals['availability'] = $totals['planned_minutes'] > 0 ? $totals['run_minutes'] / $totals['planned_minutes'] : 0.0;
    $totals['performance'] = $totals['run_minutes'] > 0 ? $totals['ideal_output_minutes'] / $totals['run_minutes'] : 0.0;
    $totals['quality'] = $totals['productive_fb'] > 0 ? $totals['good_fb'] / $totals['productive_fb'] : 0.0;
    $totals['oee'] = $totals['availability'] * $totals['performance'] * $totals['quality'];
    $totals['utilization_window'] = $totals['calendar_minutes'] > 0 ? $totals['run_minutes'] / $totals['calendar_minutes'] : 0.0;
    $totals['calendar_24h_minutes'] = $calendar24;
    $totals['utilization_24h'] = $calendar24 > 0 ? $totals['run_minutes'] / $calendar24 : 0.0;
    $totals['calculation_flags'] = $flags;
    return $totals;
}

function oee_window_report(PDO $pdo, string $date, string $machine, array $window): array
{
    $knownLines = oee_known_lines($pdo);
    $lines = $machine === 'ALL' ? $knownLines : [$machine];
    $result = ['available' => true, 'date' => $date, 'machine' => $machine, 'line_code' => $machine, 'machines' => $knownLines, 'window' => $window,
        'rows' => [], 'line_metrics' => [], 'downtime_events' => [], 'quality_entries' => [],
        'metrics' => ['total_fb' => 0, 'productive_fb' => 0, 'empty_fb' => 0, 'pcs' => 0, 'total_surface_area' => 0.0]];
    if ($lines === []) {
        $result['oee'] = oee_window_aggregate([]);
        $result['sync'] = [];
        $result['imports'] = [];
        return $result;
    }
    $rowsQuery = $pdo->prepare('SELECT line_code, si_no, seq, part_code, grade, report_colour, used_colour, loading_at, unloading_at, pcs, surface_area, total_surface_area, is_productive, cycle_minutes, flags FROM oee_fb_runs WHERE line_code = ? AND loading_at >= ? AND loading_at < ? ORDER BY loading_at, si_no');
    $eventQuery = $pdo->prepare('SELECT line_code, starts_at, ends_at, event_type, status, source, reason_code, notes FROM oee_downtime_events WHERE line_code = ? AND starts_at < ? AND ends_at > ? ORDER BY starts_at, id');
    $qualityQuery = $pdo->prepare('SELECT line_code, report_date, shift_code, rejected_fb, reworked_fb, notes FROM oee_quality_entries WHERE line_code = ? AND report_date = ?' . ($window['duration_hours'] !== 24 ? ' AND shift_code = ?' : ''));
    foreach ($lines as $line) {
        // Refresh colour-cycle snapshots and inferred events for every day the
        // selected window touches. Only DAY snapshots are persisted.
        for ($day = new DateTimeImmutable($date); $day->format('Y-m-d 00:00:00') < $window['window_end']; $day = $day->modify('+1 day')) {
            oee_calculate_daily($pdo, $line, $day->format('Y-m-d'));
        }
        $rowsQuery->execute([$line, $window['window_start'], $window['window_end']]);
        $rows = $rowsQuery->fetchAll();
        $eventQuery->execute([$line, $window['window_end'], $window['window_start']]);
        $events = $eventQuery->fetchAll();
        $qualityQuery->execute($window['duration_hours'] !== 24 ? [$line, $date, $window['shift_code']] : [$line, $date]);
        $quality = $qualityQuery->fetchAll();
        $result['line_metrics'][] = array_merge(['line_code' => $line], oee_window_metrics($window, $rows, $events, $quality));
        array_push($result['rows'], ...$rows);
        array_push($result['downtime_events'], ...$events);
        array_push($result['quality_entries'], ...$quality);
        foreach ($rows as $row) {
            $result['metrics']['total_fb']++;
            $result['metrics'][(int) $row['pcs'] > 0 ? 'productive_fb' : 'empty_fb']++;
            $result['metrics']['pcs'] += (int) $row['pcs'];
            $result['metrics']['total_surface_area'] += (float) ($row['total_surface_area'] ?? 0);
        }
    }
    $result['oee'] = oee_window_aggregate($result['line_metrics']);
    $result['sync'] = $pdo->query('SELECT source, last_batch_id, last_report_date, last_received_at, last_status, last_error FROM oee_sync_state ORDER BY updated_at DESC LIMIT 10')->fetchAll();
    $in = implode(',', array_fill(0, count($lines), '?'));
    $imports = $pdo->prepare('SELECT id, batch_id, source, report_date, row_count, status, received_at, processed_at, error_message FROM oee_import_batches WHERE line_code IN (' . $in . ') ORDER BY received_at DESC LIMIT 8');
    $imports->execute($lines);
    $result['imports'] = $imports->fetchAll();
    return $result;
}

function oee_date_range_report(PDO $pdo, string $machine, array $range): array
{
    $result = ['available' => true, 'date' => $range['from_date'], 'from_date' => $range['from_date'], 'to_date' => $range['to_date'],
        'machine' => $machine, 'window' => $range, 'rows' => [], 'window_metrics' => [], 'line_metrics' => [],
        'downtime_events' => [], 'quality_entries' => [],
        'metrics' => ['total_fb' => 0, 'productive_fb' => 0, 'empty_fb' => 0, 'pcs' => 0, 'total_surface_area' => 0.0]];
    $byLine = [];
    foreach ($range['windows'] as $window) {
        $daily = oee_window_report($pdo, $window['date'], $machine, $window);
        foreach ($daily['line_metrics'] as $line) {
            $line['report_date'] = $window['date'];
            $line['shift_code'] = $window['shift_code'];
            $line['duration_hours'] = $window['duration_hours'];
            $result['window_metrics'][] = $line;
            $byLine[$line['line_code']][] = $line;
        }
        foreach ($daily['downtime_events'] as $event) {
            // A spanning event may appear on several days, each clipped only
            // within that day's window. Preserve that scope for Excel formulas.
            $event['window_date'] = $window['date'];
            $result['downtime_events'][] = $event;
        }
        array_push($result['rows'], ...$daily['rows']);
        array_push($result['quality_entries'], ...$daily['quality_entries']);
        foreach ($result['metrics'] as $key => $value) $result['metrics'][$key] += $daily['metrics'][$key];
        $result['sync'] = $daily['sync'];
        $result['imports'] = $daily['imports'];
    }
    foreach ($byLine as $code => $windows) $result['line_metrics'][] = array_merge(['line_code' => $code], oee_window_aggregate($windows));
    $result['oee'] = oee_window_aggregate($result['line_metrics']);
    return $result;
}
