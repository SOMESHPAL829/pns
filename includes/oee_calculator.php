<?php
declare(strict_types=1);

require_once __DIR__ . '/oee_gap.php';

function oee_metrics(float $windowMinutes, float $plannedStop, float $unplannedStop, float $idealOutput, int $productiveFb, int $rejectedFb = 0, int $reworkedFb = 0, int $calendarMinutes = 1440): array
{
    $plannedTime = max(0.0, $windowMinutes - $plannedStop);
    $runTime = max(0.0, $plannedTime - $unplannedStop);
    $goodFb = max(0, $productiveFb - $rejectedFb - $reworkedFb);
    $availability = $plannedTime > 0 ? max(0.0, $runTime / $plannedTime) : 0.0;
    $performance = $runTime > 0 ? max(0.0, $idealOutput / $runTime) : 0.0;
    $quality = $productiveFb > 0 ? max(0.0, $goodFb / $productiveFb) : 0.0;
    return [
        'planned_minutes' => $plannedTime,
        'run_minutes' => $runTime,
        'ideal_output_minutes' => $idealOutput,
        'productive_fb' => $productiveFb,
        'good_fb' => $goodFb,
        'availability' => $availability,
        'performance' => $performance,
        'quality' => $quality,
        'oee' => $availability * $performance * $quality,
        'utilization_24h' => $calendarMinutes > 0 ? $runTime / $calendarMinutes : 0.0,
    ];
}

function oee_calculate_daily(PDO $pdo, string $lineCode, string $reportDate): array
{
    $rowsQuery = $pdo->prepare(
        'SELECT id, used_colour, report_colour, loading_at, unloading_at, pcs, is_productive FROM oee_fb_runs WHERE line_code = ? AND report_date = ? ORDER BY loading_at, si_no'
    );
    $rowsQuery->execute([$lineCode, $reportDate]);
    $rows = $rowsQuery->fetchAll();
    $proposedGaps = oee_propose_gap_downtime($pdo, $lineCode, $reportDate);
    $dayStart = $reportDate . ' 00:00:00';
    $dayEnd = date('Y-m-d H:i:s', strtotime($reportDate . ' +1 day'));
    $flags = [];
    $productive = 0;
    $idealOutput = 0.0;
    $first = null;
    $last = null;
    $cycleQuery = $pdo->prepare(
        'SELECT minutes_per_fb FROM oee_cycle_time_standards WHERE line_code = ? AND colour = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1'
    );
    $previousColour = '';
    $cycleUpdate = $pdo->prepare('UPDATE oee_fb_runs SET cycle_minutes = ?, flags = ? WHERE id = ?');
    foreach ($rows as $row) {
        $load = $row['loading_at'] ?: null;
        $unload = $row['unloading_at'] ?: $load;
        if ($load !== null && ($first === null || $load < $first)) $first = $load;
        if ($load !== null && ($last === null || $load > $last)) $last = $load;
        $colour = trim((string) ($row['used_colour'] ?: $row['report_colour']));
        if ($colour !== '') $previousColour = $colour;
        $rowFlags = [];
        if ((int) $row['is_productive'] !== 1) continue;
        $productive++;
        $colour = $colour !== '' ? $colour : $previousColour;
        if ($colour === '') $colour = 'NO_COLOUR';
        $cycleQuery->execute([$lineCode, $colour, $reportDate, $reportDate]);
        $cycle = $cycleQuery->fetchColumn();
        if ($cycle === false || (float) $cycle <= 0) {
            $cycle = 12.0;
            $flags['missing_cycle_time'] = true;
            $missingColour = $colour !== '' ? $colour : 'NO_COLOUR';
            $flags['missing_cycle_colours'] = array_values(array_unique(array_merge($flags['missing_cycle_colours'] ?? [], [$missingColour])));
            $rowFlags['colour_no_standard'] = true;
        }
        $cycleUpdate->execute([(float) $cycle, json_encode($rowFlags), $row['id']]);
        $idealOutput += (float) $cycle;
    }
    // Daily OEE uses the complete calendar day. The last observed load is
    // production evidence, not the end of the analysed window.
    $windowMinutes = 1440.0;
    $first = $dayStart;
    $last = $dayEnd;
    if ($productive === 0) $flags['no_data'] = true;

    $downtimeQuery = $pdo->prepare(
        "SELECT event_type, starts_at, ends_at FROM oee_downtime_events WHERE line_code = ? AND status IN ('proposed', 'confirmed', 'approved') AND starts_at < ? AND ends_at > ?"
    );
    $downtimeQuery->execute([$lineCode, $dayEnd, $dayStart]);
    $events = [];
    $boundaries = [strtotime($dayStart), strtotime($dayEnd)];
    foreach ($downtimeQuery->fetchAll() as $event) {
        $start = max(strtotime($dayStart), strtotime((string) $event['starts_at']));
        $end = min(strtotime($dayEnd), strtotime((string) $event['ends_at']));
        if ($end <= $start) continue;
        $events[] = [
            'start' => $start,
            'end' => $end,
            'type' => $event['event_type'] === 'planned' ? 'planned' : 'unplanned',
        ];
        $boundaries[] = $start;
        $boundaries[] = $end;
    }
    sort($boundaries, SORT_NUMERIC);
    $boundaries = array_values(array_unique($boundaries));
    $plannedStop = 0.0;
    $unplannedStop = 0.0;
    for ($index = 0, $count = count($boundaries) - 1; $index < $count; $index++) {
        $segmentStart = $boundaries[$index];
        $segmentEnd = $boundaries[$index + 1];
        if ($segmentEnd <= $segmentStart) continue;
        $active = array_filter($events, static fn (array $event): bool => $event['start'] < $segmentEnd && $event['end'] > $segmentStart);
        if ($active === []) continue;
        if (count($active) > 1) $flags['overlapping_downtime'] = true;
        $minutes = ($segmentEnd - $segmentStart) / 60;
        // Unplanned time takes precedence over planned time in an overlap,
        // so a minute is never subtracted from the schedule twice.
        $hasUnplanned = array_filter($active, static fn (array $event): bool => $event['type'] === 'unplanned') !== [];
        if ($hasUnplanned) $unplannedStop += $minutes;
        else $plannedStop += $minutes;
    }

    $qualityQuery = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(rejected_fb), 0), COALESCE(SUM(reworked_fb), 0) FROM oee_quality_entries WHERE line_code = ? AND report_date = ?');
    $qualityQuery->execute([$lineCode, $reportDate]);
    [$qualityEntries, $rejected, $reworked] = array_map('intval', $qualityQuery->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);
    if ($qualityEntries === 0) $flags['quality_not_entered_assumed_100'] = true;
    $metrics = oee_metrics($windowMinutes, $plannedStop, $unplannedStop, $idealOutput, $productive, $rejected, $reworked);
    $metrics['calculation_flags'] = $flags;
    if ($proposedGaps > 0) $metrics['calculation_flags']['gap_events_proposed'] = $proposedGaps;
    if ($metrics['performance'] > 1.0) $metrics['calculation_flags']['performance_above_100'] = true;

    $snapshot = $pdo->prepare(
        'INSERT INTO oee_snapshots (line_code, report_date, shift_code, window_start, window_end, productive_fb, good_fb, planned_minutes, unplanned_minutes, run_minutes, ideal_output_minutes, availability, performance, quality, oee, utilization_24h, calculation_flags) VALUES (?, ?, \'DAY\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), window_end = VALUES(window_end), productive_fb = VALUES(productive_fb), good_fb = VALUES(good_fb), planned_minutes = VALUES(planned_minutes), unplanned_minutes = VALUES(unplanned_minutes), run_minutes = VALUES(run_minutes), ideal_output_minutes = VALUES(ideal_output_minutes), availability = VALUES(availability), performance = VALUES(performance), quality = VALUES(quality), oee = VALUES(oee), utilization_24h = VALUES(utilization_24h), calculation_flags = VALUES(calculation_flags), frozen_at = CURRENT_TIMESTAMP'
    );
    $snapshot->execute([$lineCode, $reportDate, $first, $last, $metrics['productive_fb'], $metrics['good_fb'], $metrics['planned_minutes'], $unplannedStop, $metrics['run_minutes'], $metrics['ideal_output_minutes'], $metrics['availability'], $metrics['performance'], $metrics['quality'], $metrics['oee'], $metrics['utilization_24h'], json_encode($metrics['calculation_flags'])]);
    return $metrics;
}
