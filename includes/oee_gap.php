<?php
declare(strict_types=1);
require_once __DIR__ . '/oee_settings.php';

/** Pure detection. Each input row has a resolved, effective cycle time. */
function oee_gap_candidates(array $rows, float $thresholdMinutes = 20.0): array
{
    $thresholdMinutes = oee_validate_gap_threshold($thresholdMinutes);
    $rows = array_values(array_filter($rows, static fn (array $row): bool => !empty($row['loading_at'])));
    usort($rows, static fn (array $a, array $b): int => [$a['loading_at'], $a['si_no']] <=> [$b['loading_at'], $b['si_no']]);
    $events = [];
    $identity = static fn (array $row): array => [substr($row['loading_at'], 0, 10), trim((string) ($row['seq'] ?? '')) ?: 'SI:' . $row['si_no']];
    $add = static function (string $source, array $before, array $after, array $cycleRow, ?array $firstEmpty = null) use (&$events, $identity, $thresholdMinutes): void {
        $cycle = (float) ($cycleRow['cycle_minutes'] ?? 0);
        if ($cycle <= 0 || !is_finite($cycle)) throw new InvalidArgumentException('Gap detection requires a resolved positive cycle time.');
        $start = date('Y-m-d H:i:s', strtotime($before['loading_at']) + (int) round($cycle * 60));
        if ($start >= $after['loading_at']) return;
        $gap = (strtotime($after['loading_at']) - strtotime($before['loading_at'])) / 60;
        $flags = $cycleRow['flags'] ?? [];
        if (!is_array($flags)) $flags = json_decode((string) $flags, true) ?: [];
        $events[] = ['report_date' => substr($start, 0, 10), 'starts_at' => $start, 'ends_at' => $after['loading_at'],
            'raw_start' => $before['loading_at'], 'raw_end' => $after['loading_at'], 'source' => $source,
            'evidence_key' => hash('sha256', json_encode([$source, $identity($before), $identity($firstEmpty ?? $after)])),
            'notes' => sprintf('SCADA %s: %s -> %s; raw gap %.3f min; cycle %.3f min (%s); %s.%s',
                $source, $before['seq'] ?? $before['si_no'], $after['seq'] ?? $after['si_no'], $gap, $cycle,
                $cycleRow['cycle_colour'] ?? $cycleRow['used_colour'] ?? '',
                $source === 'gap' ? sprintf('plant stop threshold >%.3f min', $thresholdMinutes) : 'separate empty-rack rule (no gap threshold)',
                !empty($flags['colour_no_standard']) ? ' Missing standard: default 12 min applied.' : '')];
    };
    $previous = $anchor = $firstEmpty = $lastEmpty = null;
    foreach ($rows as $row) {
        if ($previous !== null && (new DateTimeImmutable(substr($previous['loading_at'], 0, 10)))->diff(new DateTimeImmutable(substr($row['loading_at'], 0, 10)))->days > 1) {
            if ($anchor && $lastEmpty) $add('empty_rack', $anchor, $lastEmpty, $anchor, $firstEmpty);
            $previous = $anchor = $firstEmpty = $lastEmpty = null;
        }
        if ((int) $row['is_productive'] === 1) {
            if ($previous && (strtotime($row['loading_at']) - strtotime($previous['loading_at'])) > $thresholdMinutes * 60) $add('gap', $previous, $row, $row);
            if ($anchor && $lastEmpty) $add('empty_rack', $anchor, $lastEmpty, $anchor, $firstEmpty);
            $anchor = $row;
            $firstEmpty = $lastEmpty = null;
        } elseif ($anchor) {
            $firstEmpty ??= $row;
            $lastEmpty = $row;
        }
        $previous = $row;
    }
    if ($anchor && $lastEmpty) $add('empty_rack', $anchor, $lastEmpty, $anchor, $firstEmpty);
    return $events;
}

/** Refresh only automatic proposals; keyed operator decisions always win. */
function oee_propose_gap_downtime(PDO $pdo, string $lineCode, string $reportDate): int
{
    $day = new DateTimeImmutable($reportDate);
    $start = $day->format('Y-m-d 00:00:00');
    $end = $day->modify('+1 day')->format('Y-m-d 00:00:00');
    $context = $pdo->prepare('SELECT report_date, si_no, seq, loading_at, used_colour, report_colour, pcs, is_productive FROM oee_fb_runs WHERE line_code = ? AND report_date >= ? AND report_date <= ? AND loading_at IS NOT NULL ORDER BY loading_at, si_no');
    $context->execute([$lineCode, $day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')]);
    $rows = $context->fetchAll();
    $standards = $pdo->prepare('SELECT minutes_per_fb FROM oee_cycle_time_standards WHERE line_code = ? AND colour = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1');
    $colours = $cache = [];
    foreach ($rows as &$row) {
        $date = $row['report_date'];
        $colour = trim((string) ($row['used_colour'] ?: $row['report_colour']));
        if ($colour !== '') $colours[$date] = $colour;
        $row['cycle_colour'] = $colour ?: ($colours[$date] ?? 'NO_COLOUR');
        $key = $date . '|' . $row['cycle_colour'];
        if (!array_key_exists($key, $cache)) {
            $standards->execute([$lineCode, $row['cycle_colour'], $date, $date]);
            $cache[$key] = $standards->fetchColumn();
        }
        $missing = $cache[$key] === false || (float) $cache[$key] <= 0;
        $row['cycle_minutes'] = $missing ? 12.0 : (float) $cache[$key];
        $row['flags'] = $missing ? ['colour_no_standard' => true] : [];
    }
    unset($row);
    $thresholds = oee_gap_thresholds($pdo);
    $candidates = array_filter(oee_gap_candidates($rows, $thresholds[$lineCode] ?? 20.0), static fn (array $event): bool => $event['starts_at'] < $end && $event['ends_at'] > $start);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $decisions = $pdo->prepare("SELECT evidence_key, starts_at, ends_at FROM oee_downtime_events WHERE line_code = ? AND source IN ('gap','empty_rack') AND status <> 'proposed'");
        $decisions->execute([$lineCode]);
        $preserved = $decisions->fetchAll();
        $insert = $pdo->prepare("INSERT INTO oee_downtime_events (line_code, report_date, starts_at, ends_at, event_type, source, status, reason_code, notes, evidence_key) VALUES (?, ?, ?, ?, 'unplanned', ?, 'proposed', 'UNK', ?, ?)");
        $exists = $pdo->prepare('SELECT id, status FROM oee_downtime_events WHERE line_code = ? AND evidence_key = ?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $update = $pdo->prepare("UPDATE oee_downtime_events SET report_date = ?, starts_at = ?, ends_at = ?, notes = ? WHERE line_code = ? AND evidence_key = ? AND status = 'proposed'");
        $created = 0;
        $keep = [];
        foreach ($candidates as $event) {
            foreach ($preserved as $decision) {
                if ($decision['evidence_key'] === $event['evidence_key'] || (!$decision['evidence_key'] && $decision['starts_at'] < $event['raw_end'] && $decision['ends_at'] > $event['raw_start'])) continue 2;
            }
            $keep[] = $event['evidence_key'];
            $exists->execute([$lineCode, $event['evidence_key']]);
            $existing = $exists->fetch();
            if ($existing) {
                if ($existing['status'] === 'proposed') {
                    // Retain event IDs so an operator's open Tag form survives
                    // a dashboard refresh or a cumulative report re-import.
                    $update->execute([$event['report_date'], $event['starts_at'], $event['ends_at'], $event['notes'], $lineCode, $event['evidence_key']]);
                    $created++;
                }
                continue;
            }
            try {
                $insert->execute([$lineCode, $event['report_date'], $event['starts_at'], $event['ends_at'], $event['source'], $event['notes'], $event['evidence_key']]);
                $created++;
            } catch (PDOException $exception) {
                // A concurrent calculation may already have created/tagged the
                // same evidence. Never overwrite that row or swallow other errors.
                if ($exception->getCode() !== '23000') throw $exception;
                $exists->execute([$lineCode, $event['evidence_key']]);
                if (!$exists->fetchColumn()) throw $exception;
            }
        }
        $stale = $keep ? '(evidence_key IS NULL OR evidence_key NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . '))' : '1=1';
        $clear = $pdo->prepare("DELETE FROM oee_downtime_events WHERE line_code = ? AND source IN ('gap','empty_rack') AND status = 'proposed' AND (report_date = ? OR (starts_at < ? AND ends_at > ?)) AND " . $stale);
        $clear->execute([$lineCode, $reportDate, $end, $start, ...$keep]);
        if ($ownsTransaction) $pdo->commit();
        return $created;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
