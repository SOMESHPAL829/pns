<?php
declare(strict_types=1);
require_once __DIR__ . '/oee_gap_test.php';

// Arrange: identical productive loads, with a configurable plant limit.
$loads = [gap_row('2026-10-23 10:00:00'), gap_row('2026-10-23 10:25:00')];
gap_check(count(oee_gap_candidates($loads, 20)) === 1, '20-minute default must retain existing behaviour');
gap_check(oee_gap_candidates($loads, 30) === [], '30-minute plant threshold must suppress a 25-minute gap');
gap_check(oee_gap_candidates($loads, 25) === [], 'Threshold equality must not propose downtime');
$fractional = oee_gap_candidates($loads, 24.999);
gap_check(count($fractional) === 1 && str_contains($fractional[0]['notes'], '24.999'), 'Evidence must show applied fractional threshold');
foreach ([null, true, [], '', 'NaN', INF, -1, 0, 1440.001, 20.0001] as $bad) {
    $failed = false;
    try { oee_validate_gap_threshold($bad); } catch (InvalidArgumentException $e) { $failed = true; }
    gap_check($failed, 'Invalid threshold accepted: ' . get_debug_type($bad));
}
gap_check(oee_validate_gap_threshold('0.001') === 0.001 && oee_validate_gap_threshold('1440') === 1440.0, 'Valid threshold bounds rejected');

// Act / Assert: save and reload, isolated plants, refresh proposals, preserve tags.
gap_check(oee_gap_thresholds($pdo)['APP-06'] === 20.0, 'Missing settings must use 20-minute default');
oee_save_gap_threshold($pdo, 'APP-07', 30, 1);
oee_propose_gap_downtime($pdo, 'APP-07', '2026-10-23');
gap_check((int) $pdo->query("SELECT COUNT(*) FROM oee_downtime_events WHERE line_code='APP-07' AND starts_at='2026-10-23 01:07:00'")->fetchColumn() === 0, 'Raising threshold must remove stale proposed gap');
gap_check(oee_gap_thresholds($pdo)['APP-06'] === 20.0 && oee_gap_thresholds($pdo)['APP-07'] === 30.0, 'Setting leaked between plants');
oee_save_gap_threshold($pdo, 'APP-07', 20, 1);
oee_propose_gap_downtime($pdo, 'APP-07', '2026-10-23');
gap_check((bool) $pdo->query("SELECT id FROM oee_downtime_events WHERE line_code='APP-07' AND starts_at='2026-10-23 01:07:00'")->fetchColumn(), 'Lowering threshold must restore eligible proposal');
$decisions = $pdo->query("SELECT * FROM oee_downtime_events WHERE status <> 'proposed'")->fetchAll();
oee_save_gap_threshold($pdo, 'APP-06', 60, 1);
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-22');
oee_propose_gap_downtime($pdo, 'APP-06', '2026-10-23');
gap_check($pdo->query("SELECT * FROM oee_downtime_events WHERE status <> 'proposed'")->fetchAll() === $decisions, 'Threshold change altered operator decisions');
gap_check((int) $pdo->query("SELECT COUNT(*) FROM oee_downtime_events WHERE line_code='APP-06' AND starts_at='2026-10-22 23:44:22'")->fetchColumn() === 0, 'Midnight stale proposal not removed');
gap_check(count(oee_gap_candidates($emptyRows, 1440)) === 1, 'Threshold must not change separate empty-rack rule');
$failed = false;
try { oee_save_gap_threshold($pdo, 'ALL', 20, 1); } catch (InvalidArgumentException $e) { $failed = true; }
gap_check($failed, 'ALL is not a configurable plant');
echo "Plant-specific gap threshold tests passed\n";
