<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/oee_calculator.php';
require_once dirname(__DIR__) . '/includes/oee_window.php';
function gap_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function gap_row(string $load, float $cycle = 10, int $pcs = 5, string $seq = ''): array
{
    return ['report_date' => substr($load, 0, 10), 'loading_at' => $load, 'cycle_minutes' => $cycle,
        'pcs' => $pcs, 'is_productive' => $pcs > 0 ? 1 : 0, 'seq' => $seq ?: $load, 'si_no' => 1,
        'used_colour' => 'BLACK-1', 'report_colour' => '', 'cycle_colour' => 'BLACK-1', 'flags' => []];
}
$rows = [gap_row('2026-10-23 00:30:25'), gap_row('2026-10-23 00:50:48')];
$events = oee_gap_candidates($rows);
gap_check(count($events) === 1 && $events[0]['starts_at'] === '2026-10-23 00:30:25' && $events[0]['ends_at'] === '2026-10-23 00:50:48', 'Qualifying gap must retain the complete SCADA interval');
gap_check(str_contains($events[0]['notes'], '10') && str_contains($events[0]['notes'], '20'), 'Gap evidence must explain cycle and threshold');
gap_check(count(oee_gap_candidates([gap_row('2026-10-23 00:30:25'), gap_row('2026-10-23 00:50:25')])) === 1, 'Gap equal to threshold must be shown');
gap_check(oee_gap_candidates([gap_row('2026-10-23 00:30:25'), gap_row('2026-10-23 00:50:24')]) === [], 'Below-threshold gap proposed a stop');
$longCycle = oee_gap_candidates([gap_row('2026-10-23 00:30:25'), gap_row('2026-10-23 00:50:48', 30)]);
gap_check(count($longCycle) === 1 && $longCycle[0]['starts_at'] === '2026-10-23 00:30:25', 'Cycle time incorrectly suppressed or shortened an above-threshold gap');
$dstFalsePositive = oee_gap_candidates([gap_row('2026-10-25 01:54:17', 12, 850, 'M10Y26-2642'), gap_row('2026-10-25 02:04:00', 12, 432, 'M10Y26-2643')]);
gap_check($dstFalsePositive === [], 'Server daylight-saving transition turned a 9.717-minute SCADA gap into 69.717 minutes');
$dstBoundary = oee_gap_candidates([gap_row('2026-10-25 01:54:17'), gap_row('2026-10-25 02:14:17')]);
gap_check(count($dstBoundary) === 1 && str_contains($dstBoundary[0]['notes'], 'raw gap 20.000 min'), 'Threshold equality changed across a server daylight-saving transition');
$night = oee_gap_candidates([gap_row('2026-10-22 23:34:22'), gap_row('2026-10-23 00:06:04')]);
gap_check(count($night) === 1 && $night[0]['starts_at'] === '2026-10-22 23:34:22' && $night[0]['report_date'] === '2026-10-22', 'Midnight event must retain full interval and start date');
$nightEvent = array_merge($night[0], ['status' => 'proposed', 'event_type' => 'unplanned']);
$left = oee_window_metrics(oee_window_definition('2026-10-22'), [], [$nightEvent], []);
$right = oee_window_metrics(oee_window_definition('2026-10-23'), [], [$nightEvent], []);
gap_check(abs($left['unplanned_minutes'] - 25.633) < 1e-9 && abs($right['unplanned_minutes'] - 6.067) < 1e-9, 'Midnight stop not clipped once to each date');
$transition = oee_gap_candidates([gap_row('2026-10-23 00:00:00', 7), gap_row('2026-10-23 00:25:00', 10)]);
gap_check($transition[0]['starts_at'] === '2026-10-23 00:00:00', 'Cycle time must not be subtracted from a qualifying transition gap');
gap_check(oee_gap_candidates([gap_row('2026-10-21 23:34:22'), gap_row('2026-10-23 00:06:04')]) === [], 'Must not infer a stop across a missing calendar date');
$emptyRows = [gap_row('2026-10-22 23:35:00'), gap_row('2026-10-22 23:50:00', 0, 0), gap_row('2026-10-23 00:05:00', 0, 0), gap_row('2026-10-23 00:15:00')];
$empty = oee_gap_candidates($emptyRows);
gap_check(count($empty) === 1 && $empty[0]['source'] === 'empty_rack' && $empty[0]['starts_at'] === '2026-10-22 23:35:00' && $empty[0]['ends_at'] === '2026-10-23 00:05:00', 'Empty run must retain its complete SCADA interval across midnight');
$shortEmpty = [gap_row('2026-10-25 04:23:16'), gap_row('2026-10-25 04:38:50', 0, 0), gap_row('2026-10-25 04:45:00')];
gap_check(oee_gap_candidates($shortEmpty, 20) === [], 'Below-threshold empty-rack downtime must not be displayed');
gap_check(count(oee_gap_candidates($shortEmpty, 15.566)) === 1, 'Qualifying empty-rack downtime must remain detectable');
$same = $rows; $same[0]['id'] = 999; $same[1]['id'] = 1000;
gap_check(oee_gap_candidates($same)[0]['evidence_key'] === $events[0]['evidence_key'], 'Re-import row IDs must not change evidence identity');

// Real persistence checks use an isolated in-memory database, never production.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE oee_line_settings (line_code TEXT PRIMARY KEY, gap_threshold_minutes REAL, updated_by INTEGER, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE oee_fb_runs (id INTEGER PRIMARY KEY, line_code TEXT, report_date TEXT, si_no INTEGER, seq TEXT, loading_at TEXT, used_colour TEXT, report_colour TEXT, pcs INTEGER, is_productive INTEGER, cycle_minutes REAL, flags TEXT)');
$pdo->exec('CREATE TABLE oee_cycle_time_standards (line_code TEXT, colour TEXT, minutes_per_fb REAL, effective_from TEXT, effective_to TEXT)');
$pdo->exec("INSERT INTO oee_cycle_time_standards VALUES ('APP-06','BLACK-1',10,'2026-01-01',NULL), ('APP-06','CLEAR',7,'2026-01-01',NULL), ('APP-06','BLACK-1',8,'2026-10-24',NULL)");
$pdo->exec('CREATE TABLE oee_downtime_events (id INTEGER PRIMARY KEY AUTOINCREMENT, line_code TEXT, report_date TEXT, starts_at TEXT, ends_at TEXT, event_type TEXT, source TEXT, status TEXT, reason_code TEXT, notes TEXT, evidence_key TEXT, UNIQUE(line_code,evidence_key))');
$insert = $pdo->prepare('INSERT INTO oee_fb_runs (line_code,report_date,si_no,seq,loading_at,used_colour,report_colour,pcs,is_productive,cycle_minutes,flags) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach ([gap_row('2026-10-22 23:34:22'), gap_row('2026-10-23 00:06:04'), ...$rows, gap_row('2026-10-24 00:10:00'), gap_row('2026-10-24 00:35:00')] as $row) {
    $insert->execute(['APP-06', $row['report_date'], 1, $row['seq'], $row['loading_at'], $row['used_colour'], '', 5, 1, 999, '{}']);
}
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-22');
$before = $pdo->query("SELECT id,starts_at,ends_at,evidence_key FROM oee_downtime_events WHERE starts_at < '2026-10-23 00:00:00'")->fetchAll();
gap_check(count($before) === 1 && $before[0]['starts_at'] === '2026-10-22 23:34:22', 'Boundary context missing or full SCADA start not persisted');
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-23');
$after = $pdo->query("SELECT id,starts_at,ends_at,evidence_key FROM oee_downtime_events WHERE starts_at < '2026-10-23 00:00:00'")->fetchAll();
gap_check($before === $after, 'Refresh order changed/duplicated crossing event');
$target = $pdo->query("SELECT * FROM oee_downtime_events WHERE starts_at = '2026-10-23 00:30:25'")->fetch();
gap_check((bool) $target, 'Full SCADA interval not applied by database path');
$update = $pdo->prepare("UPDATE oee_downtime_events SET status='confirmed', starts_at='2026-10-23 00:39:00', notes='Operator decision' WHERE id=?"); $update->execute([$target['id']]);
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-23');
$preserved = $pdo->query("SELECT * FROM oee_downtime_events WHERE evidence_key='" . $target['evidence_key'] . "'")->fetchAll();
gap_check(count($preserved) === 1 && $preserved[0]['notes'] === 'Operator decision' && $preserved[0]['starts_at'] === '2026-10-23 00:39:00', 'Human tag overwritten or duplicated');
$pdo->exec("UPDATE oee_downtime_events SET status='rejected', evidence_key=NULL WHERE id=" . (int) $target['id']);
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-23');
gap_check((int) $pdo->query("SELECT COUNT(*) FROM oee_downtime_events WHERE starts_at >= '2026-10-23 00:30:25' AND ends_at <= '2026-10-23 00:50:48'")->fetchColumn() === 1, 'Legacy false alarm recreated as proposed downtime');
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-24');
gap_check((bool) $pdo->query("SELECT id FROM oee_downtime_events WHERE starts_at='2026-10-24 00:10:00' AND ends_at='2026-10-24 00:35:00'")->fetchColumn(), 'Full SCADA interval changed with effective-date cycle master');
$pdo->exec("INSERT INTO oee_cycle_time_standards VALUES ('APP-07','CLEAR',7,'2026-01-01',NULL), ('APP-07','NO COLOUR',16,'2026-01-01',NULL)");
foreach ([['01:00:00','CLEAR'], ['01:25:00',''], ['02:00:00','UNKNOWN'], ['02:25:00','UNKNOWN']] as [$time, $colour]) {
    $insert->execute(['APP-07','2026-10-23',1,$time,'2026-10-23 ' . $time,$colour,'',5,1,999,'{}']);
}
oee_propose_gap_downtime($pdo, 'APP-07', '2026-10-23');
$noColour = $pdo->query("SELECT notes FROM oee_downtime_events WHERE line_code='APP-07' AND starts_at='2026-10-23 01:00:00' AND ends_at='2026-10-23 01:25:00'")->fetchColumn();
gap_check(is_string($noColour) && str_contains($noColour, '16.000 min (NO COLOUR)') && !str_contains($noColour, 'Missing standard'), 'Blank APP-07 colour did not use its NO COLOUR cycle standard');
gap_check(oee_cycle_colour('APP-03', '', '', 'BLACK-1') === 'NO COLOUR' && oee_cycle_colour('APP-04', '', '', 'CLEAR') === 'NO COLOUR' && oee_cycle_colour('APP-10', '', '', 'BLUE') === 'NO COLOUR', 'Configured blank-colour plants must not inherit a previous colour');
gap_check(oee_cycle_colour('APP-06', '', '', 'BLACK-1') === 'BLACK-1', 'Other plants must retain existing colour fill-forward behaviour');
$fallback = $pdo->query("SELECT notes FROM oee_downtime_events WHERE line_code='APP-07' AND starts_at='2026-10-23 02:00:00' AND ends_at='2026-10-23 02:25:00'")->fetchColumn();
gap_check(is_string($fallback) && str_contains($fallback, 'Missing standard'), 'Fallback cycle must be 12 and explicitly flagged');
$oldMetrics = oee_metrics(1440, 0, 26.567, 969, 99);
$newMetrics = oee_metrics(1440, 0, 59, 969, 99);
gap_check(abs($oldMetrics['oee'] - $newMetrics['oee']) < 1e-10 && $newMetrics['availability'] < $oldMetrics['availability'] && $newMetrics['performance'] > $oldMetrics['performance'], 'Downtime must not be charged twice in OEE');
echo "Full-interval gap and midnight persistence tests passed\n";
