<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/oee_ingest.php';
require_once __DIR__ . '/includes/oee_calculator.php';
require_once __DIR__ . '/includes/oee_window.php';
require_once __DIR__ . '/includes/oee_export.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/oee_dashboard_view.php';
require_once __DIR__ . '/includes/oee_downtime_tag.php';
require_once __DIR__ . '/includes/chemicals.php';
require_once __DIR__ . '/includes/chemical_consumption.php';
require_once __DIR__ . '/includes/breakdowns.php';

function api_user(PDO $pdo): array
{
    $id = current_user_id();
    if ($id === null) {
        json_response(['error' => 'Sign in is required.'], 401);
    }
    $query = $pdo->prepare('SELECT id, username, email, full_name, role, is_active, auth_version, created_at FROM portal_users WHERE id = ?');
    $query->execute([$id]);
    $user = $query->fetch();
    if (!$user || (int) $user['is_active'] !== 1 || (int) $user['auth_version'] !== (int) ($_SESSION['auth_version'] ?? 0)) {
        start_app_session();
        $_SESSION = [];
        session_destroy();
        json_response(['error' => 'Sign in is required.'], 401);
    }
    $user['access'] = portal_user_access($pdo, $user);
    return $user;
}

function api_require_page(array $user, string $page, string $action='view'): void
{
    if(!portal_can($user['access']['permissions'],$page,$action))json_response(['error'=>'You do not have '.$action.' permission for this page.'],403);
}

function api_text(array $data, string $key, int $maxLength): string
{
    $rawValue = $data[$key] ?? '';
    if (!is_string($rawValue)) {
        json_response(['error' => ucfirst(str_replace('_', ' ', $key)) . ' must be text.'], 422);
    }
    $value = trim($rawValue);
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        json_response(['error' => ucfirst(str_replace('_', ' ', $key)) . ' is too long.'], 422);
    }
    return $value;
}

function oee_requested_date(): ?string
{
    $value = trim((string) ($_GET['date'] ?? ''));
    if ($value === '') {
        return null;
    }
    try {
        return oee_validate_date($value);
    } catch (InvalidArgumentException $exception) {
        json_response(['error' => $exception->getMessage()], 422);
    }
}

function oee_tables_ready(PDO $pdo): bool
{
    $query = $pdo->query("SHOW TABLES LIKE 'oee_import_batches'");
    return (bool) $query->fetchColumn();
}

function oee_machine_line(string $machine): string
{
    return $machine;
}

function oee_requested_machine(): string
{
    $machine = strtoupper(trim((string) ($_GET['machine'] ?? 'APP-06')));
    if (!in_array($machine, ['ALL', 'APP-06', 'APP-07', 'APP-10'], true)) {
        json_response(['error' => 'Unknown OEE machine.'], 422);
    }
    return $machine;
}

function oee_requested_window_selection(): array
{
    $hours = filter_var($_GET['duration_hours'] ?? 24, FILTER_VALIDATE_INT);
    if (!in_array($hours, oee_supported_window_hours(), true)) json_response(['error' => 'Duration must be 8, 10, 12, 16, or 24 hours.'], 422);
    $minutes = filter_var($_GET['duration_minutes'] ?? ($hours * 60), FILTER_VALIDATE_INT);
    $start = $_GET['start_time'] ?? '00:00';
    if (!in_array($minutes, array_map(static fn (int $duration): int => $duration * 60, oee_supported_window_hours()), true) || !is_string($start)) json_response(['error' => 'Choose a supported duration and a valid start time.'], 422);
    if (isset($_GET['duration_hours'], $_GET['duration_minutes']) && $minutes !== $hours * 60) json_response(['error' => 'Duration hours and minutes must agree.'], 422);
    $hours = intdiv($minutes, 60);
    try {
        oee_window_definition('2000-01-01', $hours, $start);
    } catch (InvalidArgumentException $exception) {
        json_response(['error' => $exception->getMessage()], 422);
    }
    return [$hours, $start];
}

function oee_dashboard(PDO $pdo, ?string $date, string $machine = 'APP-06', int $durationHours = 24, string $startTime = '00:00'): array
{
    if (!oee_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'OEE database migration is not installed yet.'];
    }
    if ($date === null) {
        $date = (string) ($pdo->query('SELECT MAX(report_date) FROM oee_import_batches')->fetchColumn() ?: date('Y-m-d'));
    }
    $window = oee_window_definition($date, $durationHours, $startTime);
    if ($durationHours !== 24) {
        $report = oee_window_report($pdo, $date, $machine, $window);
        unset($report['rows'], $report['downtime_events'], $report['quality_entries']);
        return $report;
    }
    $lines = $machine === 'ALL' ? ['APP-06', 'APP-07', 'APP-10'] : [oee_machine_line($machine)];
    // Cycle-time and downtime masters affect the stored snapshot. Recalculate
    // before reading it so dashboard refreshes always reflect the latest data.
    foreach ($lines as $line) {
        oee_calculate_daily($pdo, $line, $date);
    }
    $placeholders = implode(',', array_fill(0, count($lines), '?'));
    $summary = $pdo->prepare(
        'SELECT COUNT(*) AS total_fb, SUM(is_productive = 1) AS productive_fb, SUM(is_productive = 0) AS empty_fb, SUM(pcs) AS pcs, COALESCE(SUM(total_surface_area), 0) AS total_surface_area FROM oee_fb_runs WHERE line_code IN (' . $placeholders . ') AND report_date = ?'
    );
    $summary->execute([...$lines, $date]);
    $metrics = $summary->fetch() ?: [];
    $snapshot = $pdo->prepare(
        'SELECT SUM(productive_fb) AS productive_fb, SUM(good_fb) AS good_fb, SUM(planned_minutes) AS planned_minutes, GREATEST(0, SUM(TIMESTAMPDIFF(SECOND, window_start, window_end)) / 60 - SUM(planned_minutes)) AS planned_stop_minutes, SUM(unplanned_minutes) AS unplanned_minutes, SUM(run_minutes) AS run_minutes, SUM(ideal_output_minutes) AS ideal_output_minutes, SUM(run_minutes) / NULLIF(SUM(planned_minutes), 0) AS availability, SUM(ideal_output_minutes) / NULLIF(SUM(run_minutes), 0) AS performance, SUM(good_fb) / NULLIF(SUM(productive_fb), 0) AS quality, SUM(run_minutes) / NULLIF(SUM(planned_minutes), 0) * (SUM(ideal_output_minutes) / NULLIF(SUM(run_minutes), 0)) * (SUM(good_fb) / NULLIF(SUM(productive_fb), 0)) AS oee, SUM(run_minutes) / NULLIF(1440 * COUNT(*), 0) AS utilization_24h, MAX(calculation_flags) AS calculation_flags FROM oee_snapshots WHERE line_code IN (' . $placeholders . ') AND report_date = ?'
    );
    $snapshot->execute([...$lines, $date]);
    $oee = $snapshot->fetch() ?: null;
    if ($oee) $oee['utilization_window'] = $oee['utilization_24h'];
    $sync = $pdo->query(
        "SELECT source, last_batch_id, last_report_date, last_received_at, last_status, last_error FROM oee_sync_state ORDER BY updated_at DESC LIMIT 10"
    )->fetchAll();
    $imports = $pdo->prepare(
        'SELECT id, batch_id, source, report_date, row_count, status, received_at, processed_at, error_message FROM oee_import_batches WHERE line_code IN (' . $placeholders . ') ORDER BY received_at DESC LIMIT 8'
    );
    $imports->execute($lines);
    return [
        'available' => true,
        'window' => $window,
        'date' => $date,
        'machine' => $machine,
        'line_code' => $machine === 'ALL' ? 'ALL' : $lines[0],
        'metrics' => array_merge([
            'total_fb' => 0, 'productive_fb' => 0, 'empty_fb' => 0, 'pcs' => 0, 'total_surface_area' => 0,
        ], $metrics),
        'oee' => $oee,
        'sync' => $sync,
        'imports' => $imports->fetchAll(),
    ];
}

function oee_report(PDO $pdo, ?string $date, string $machine = 'APP-06', int $durationHours = 24, string $startTime = '00:00'): array
{
    if ($durationHours !== 24) {
        if (!oee_tables_ready($pdo)) return ['available' => false, 'message' => 'OEE database migration is not installed yet.'];
        $date ??= (string) ($pdo->query('SELECT MAX(report_date) FROM oee_import_batches')->fetchColumn() ?: date('Y-m-d'));
        return oee_window_report($pdo, $date, $machine, oee_window_definition($date, $durationHours, $startTime));
    }
    $dashboard = oee_dashboard($pdo, $date, $machine);
    if (!$dashboard['available']) {
        return $dashboard;
    }
    $lines = $machine === 'ALL' ? ['APP-06', 'APP-07', 'APP-10'] : [$dashboard['line_code']];
    $placeholders = implode(',', array_fill(0, count($lines), '?'));
    $rows = $pdo->prepare(
        'SELECT line_code, si_no, seq, part_code, grade, report_colour, used_colour, loading_at, unloading_at, pcs, surface_area, total_surface_area, is_productive, cycle_minutes, flags FROM oee_fb_runs WHERE line_code IN (' . $placeholders . ') AND report_date = ? ORDER BY line_code, si_no ASC'
    );
    $rows->execute([...$lines, $dashboard['date']]);
    $dashboard['rows'] = $rows->fetchAll();
    $lineMetrics = $pdo->prepare(
        'SELECT line_code, window_start, window_end, availability, performance, quality, oee, utilization_24h, TIMESTAMPDIFF(SECOND, window_start, window_end) / 60 AS window_minutes, GREATEST(0, TIMESTAMPDIFF(SECOND, window_start, window_end) / 60 - planned_minutes) AS planned_stop_minutes, planned_minutes, unplanned_minutes, run_minutes, ideal_output_minutes, productive_fb, good_fb, 1440 AS calendar_minutes FROM oee_snapshots WHERE line_code = ? AND report_date = ?'
    );
    $dashboard['line_metrics'] = [];
    foreach ($lines as $line) {
        $lineMetrics->execute([$line, $dashboard['date']]);
        $metric = $lineMetrics->fetch();
        if ($metric) {
            $metric['utilization_window'] = $metric['utilization_24h'];
            $dashboard['line_metrics'][] = $metric;
        }
    }
    return $dashboard;
}

function oee_logs(PDO $pdo): array
{
    if (!oee_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'OEE database migration is not installed yet.'];
    }
    $imports = $pdo->query(
        'SELECT id, batch_id, source, line_code, report_date, row_count, status, received_at, processed_at, error_message FROM oee_import_batches ORDER BY received_at DESC LIMIT 100'
    )->fetchAll();
    $sync = $pdo->query(
        'SELECT source, last_batch_id, last_report_date, last_received_at, last_status, last_error, updated_at FROM oee_sync_state ORDER BY updated_at DESC'
    )->fetchAll();
    return ['available' => true, 'imports' => $imports, 'sync' => $sync];
}

function oee_downtime(PDO $pdo, string $date, string $machine): array
{
    if ($machine === 'ALL') json_response(['error' => 'Select one machine to review downtime.'], 422);
    $lineCode = oee_machine_line($machine);
    oee_calculate_daily($pdo, $lineCode, $date);
    $dayStart = $date . ' 00:00:00';
    $dayEnd = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    $query = $pdo->prepare(
        'SELECT e.id,e.report_date,e.starts_at,e.ends_at,e.event_type,e.source,e.status,e.reason_code,COALESCE(r.label,e.reason_code) AS reason_label,e.notes,e.created_at,e.updated_at FROM oee_downtime_events e LEFT JOIN oee_reason_codes r ON r.code=e.reason_code WHERE e.line_code = ? AND e.starts_at < ? AND e.ends_at > ? ORDER BY e.starts_at ASC'
    );
    $query->execute([$lineCode, $dayEnd, $dayStart]);
    return ['available' => true, 'date' => $date, 'machine' => $machine, 'events' => $query->fetchAll()];
}

function oee_downtime_save(PDO $pdo, array $data): void
{
    $machine = strtoupper(api_text($data, 'machine', 20));
    if (!in_array($machine, ['APP-06', 'APP-07', 'APP-10'], true)) {
        json_response(['error' => 'Unknown OEE machine.'], 422);
    }
    $date = oee_validate_date(api_text($data, 'report_date', 10));
    $start = api_text($data, 'starts_at', 32);
    $end = api_text($data, 'ends_at', 32);
    $eventType = api_text($data, 'event_type', 20);
    $reason = strtoupper(api_text($data, 'reason_code', 40));
    $notes = api_text($data, 'notes', 500);
    if (!in_array($eventType, ['planned', 'unplanned'], true)) {
        json_response(['error' => 'Invalid downtime type or reason.'], 422);
    }
    $reasonQuery = $pdo->prepare('SELECT code FROM oee_reason_codes WHERE code=? AND loss_type=? AND is_active=1');
    $reasonQuery->execute([$reason, $eventType]);
    if (!$reasonQuery->fetchColumn()) json_response(['error' => 'Choose a registered reason matching the selected downtime type.'], 422);
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $end);
    if (!$startDate || !$endDate || $endDate <= $startDate) {
        json_response(['error' => 'Downtime start and end must be valid and end must be later than start.'], 422);
    }
    $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT);
    if ($eventId) {
        $update = $pdo->prepare(
            "UPDATE oee_downtime_events SET event_type = ?, status = 'confirmed', reason_code = ?, notes = ?, starts_at = ?, ends_at = ? WHERE id = ? AND line_code = ? AND starts_at < ? AND ends_at > ?"
        );
        $dayEnd = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
        $update->execute([$eventType, $reason, $notes, $startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s'), $eventId, oee_machine_line($machine), $dayEnd, $date . ' 00:00:00']);
        if ($update->rowCount() < 1) {
            json_response(['error' => 'Downtime event not found for this machine and date.'], 404);
        }
        json_response(['ok' => true, 'event_id' => (int) $eventId, 'updated' => true]);
    }
    $insert = $pdo->prepare(
        "INSERT INTO oee_downtime_events (line_code, report_date, starts_at, ends_at, event_type, source, status, reason_code, notes) VALUES (?, ?, ?, ?, ?, 'manual', 'confirmed', ?, ?)"
    );
    $insert->execute([oee_machine_line($machine), $date, $startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s'), $eventType, $reason, $notes]);
    json_response(['ok' => true, 'event_id' => (int) $pdo->lastInsertId()], 201);
}

function oee_masters(PDO $pdo): array
{
    $reasons = $pdo->query('SELECT id, code, label, loss_type, is_active FROM oee_reason_codes ORDER BY loss_type,label')->fetchAll();
    $cycles = $pdo->query('SELECT id, line_code, colour, minutes_per_fb, effective_from, effective_to FROM oee_cycle_time_standards ORDER BY line_code, colour, effective_from DESC')->fetchAll();
    $thresholds = [];
    foreach (oee_gap_thresholds($pdo) as $line => $minutes) {
        $thresholds[] = ['line_code' => $line, 'gap_threshold_minutes' => $minutes];
    }
    return ['available' => true, 'reasons' => $reasons, 'cycles' => $cycles, 'thresholds' => $thresholds];
}

function oee_master_save(PDO $pdo, array $data, array $user): void
{
    $type = api_text($data, 'master_type', 20);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if ($type === 'threshold') {
        $line = strtoupper(api_text($data, 'line_code', 40));
        try {
            $minutes = oee_save_gap_threshold($pdo, $line, $data['gap_threshold_minutes'] ?? null, (int) $user['id']);
        } catch (InvalidArgumentException $exception) {
            json_response(['error' => $exception->getMessage()], 422);
        }
        json_response(['ok' => true, 'line_code' => $line, 'gap_threshold_minutes' => $minutes]);
    }
    if ($type === 'cycle') {
        $line = strtoupper(api_text($data, 'line_code', 40));
        $colour = strtoupper(api_text($data, 'colour', 120));
        $minutes = filter_var($data['minutes_per_fb'] ?? null, FILTER_VALIDATE_FLOAT);
        $from = oee_validate_date(api_text($data, 'effective_from', 10));
        $to = api_text($data, 'effective_to', 10);
        if (!in_array($line, ['APP-06', 'APP-07', 'APP-10'], true) || $colour === '' || $minutes === false || $minutes <= 0) json_response(['error' => 'Enter a valid machine, colour, and positive cycle time.'], 422);
        $to = $to === '' ? null : oee_validate_date($to);
        if ($to !== null && $to < $from) json_response(['error' => 'Effective end date cannot be before the start date.'], 422);
        if ($id) {
            $q = $pdo->prepare('UPDATE oee_cycle_time_standards SET line_code = ?, colour = ?, minutes_per_fb = ?, effective_from = ?, effective_to = ? WHERE id = ?');
            $q->execute([$line, $colour, $minutes, $from, $to, $id]);
        } else {
            $q = $pdo->prepare('INSERT INTO oee_cycle_time_standards (line_code, colour, minutes_per_fb, effective_from, effective_to) VALUES (?, ?, ?, ?, ?)');
            $q->execute([$line, $colour, $minutes, $from, $to]);
        }
        json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown master data type.'], 422);
}

try {
    if (current_user_id() === null) {
        json_response(['error' => 'Sign in is required.'], 401);
    }
    $resource = (string) ($_GET['resource'] ?? '');
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = [];
    if ($method !== 'GET') {
        $data = request_data();
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null);
        if (!verify_csrf_token(is_string($csrf) ? $csrf : null)) {
            json_response(['error' => 'Your session expired. Reload and try again.'], 419);
        }
    }

    if ($action === 'logout' && $method === 'POST') {
        start_app_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        json_response(['ok' => true]);
    }

    $pdo = db();
    $user = api_user($pdo);
    $required = portal_api_requirement($resource,$action,$method,$data);
    // Downtime needs reason labels, not unrestricted access to cycle masters.
    $reasonSelector = $resource==='oee' && $action==='masters' && $method==='GET'
        && !portal_can($user['access']['permissions'],'oee-masters') && portal_can($user['access']['permissions'],'downtime');
    if($required!==null && !$reasonSelector)api_require_page($user,...$required);

    if ($method === 'GET') {
        if ($resource === 'oee') {
            $requestedDate = oee_requested_date();
            $requestedMachine = oee_requested_machine();
            [$durationHours, $startTime] = oee_requested_window_selection();
            if (in_array($action, ['range-dashboard', 'range-export'], true)) {
                $from = $_GET['from_date'] ?? null;
                $to = $_GET['to_date'] ?? null;
                if (!is_string($from) || !is_string($to)) json_response(['error' => 'Choose both From date and To date.'], 422);
                try {
                    $range = oee_date_range_definition($from, $to, $durationHours, $startTime);
                } catch (InvalidArgumentException $exception) {
                    json_response(['error' => $exception->getMessage()], 422);
                }
                if (!oee_tables_ready($pdo)) json_response(['available' => false, 'message' => 'OEE database migration is not installed yet.'], 503);
                $report = oee_date_range_report($pdo, $requestedMachine, $range);
                if ($action === 'range-dashboard') {
                    unset($report['rows'], $report['downtime_events'], $report['quality_entries']);
                    json_response($report);
                }
                $report['line_metrics'] = $report['window_metrics'];
                $workbook = oee_export_xlsx($report);
                $filename = 'pns-oee-' . $from . '-to-' . $to . '-' . strtolower($requestedMachine) . '-' . ($durationHours * 60) . 'min-' . str_replace(':', '', $startTime) . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store');
                echo $workbook;
                exit;
            }
            if ($action === 'dashboard') {
                json_response(oee_dashboard_view($pdo,oee_dashboard($pdo, $requestedDate, $requestedMachine, $durationHours, $startTime),$user['access']['permissions']));
            }
            if ($action === 'report') {
                json_response(oee_report($pdo, $requestedDate, $requestedMachine, $durationHours, $startTime));
            }
            if ($action === 'export') {
                $report = oee_report($pdo, $requestedDate, $requestedMachine, $durationHours, $startTime);
                if (!($report['available'] ?? false)) json_response($report, 503);
                $exportLines = array_column($report['line_metrics'], 'line_code');
                $report['downtime_events'] ??= [];
                $report['quality_entries'] ??= [];
                if ($exportLines !== [] && $durationHours === 24) {
                    $in = implode(',', array_fill(0, count($exportLines), '?'));
                    $dayStart = $report['date'] . ' 00:00:00';
                    $dayEnd = date('Y-m-d H:i:s', strtotime($report['date'] . ' +1 day'));
                    $events = $pdo->prepare('SELECT line_code, starts_at, ends_at, event_type, status, source, reason_code, notes FROM oee_downtime_events WHERE line_code IN (' . $in . ') AND starts_at < ? AND ends_at > ? ORDER BY line_code, starts_at, id');
                    $events->execute([...$exportLines, $dayEnd, $dayStart]);
                    $report['downtime_events'] = $events->fetchAll();
                    $quality = $pdo->prepare('SELECT line_code, shift_code, rejected_fb, reworked_fb, notes FROM oee_quality_entries WHERE line_code IN (' . $in . ') AND report_date = ? ORDER BY line_code, id');
                    $quality->execute([...$exportLines, $report['date']]);
                    $report['quality_entries'] = $quality->fetchAll();
                }
                $filename = 'pns-oee-' . ($report['date'] ?? date('Y-m-d')) . '-' . strtolower($requestedMachine) . '-' . ($durationHours * 60) . 'min-' . str_replace(':', '', $startTime) . '.xlsx';
                // Finish generation before declaring a binary response: errors
                // must return JSON rather than a partial spreadsheet download.
                $workbook = oee_export_xlsx($report);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store');
                echo $workbook;
                exit;
            }
            if ($action === 'logs') {
                json_response(oee_logs($pdo));
            }
            if ($action === 'downtime') {
                json_response(oee_downtime($pdo, $requestedDate ?: date('Y-m-d'), $requestedMachine));
            }
            if ($action === 'masters') {
                if($reasonSelector)json_response(['available'=>true,'reasons'=>$pdo->query('SELECT id,code,label,loss_type,is_active FROM oee_reason_codes ORDER BY loss_type,label')->fetchAll(),'cycles'=>[],'thresholds'=>[]]);
                json_response(oee_masters($pdo));
            }
            json_response(['error' => 'OEE action not found.'], 404);
        }
        if ($resource === 'bootstrap') {
            $ownerFilter = $user['role'] === 'admin' ? '' : ' WHERE r.owner_id = :owner_id';
            $records = $pdo->prepare(
                'SELECT r.*, u.full_name AS owner_name FROM portal_records r JOIN portal_users u ON u.id = r.owner_id' .
                $ownerFilter . ' ORDER BY r.created_at DESC LIMIT 100'
            );
            if ($user['role'] !== 'admin') {
                $records->bindValue(':owner_id', $user['id'], PDO::PARAM_INT);
            }
            $canRecords=portal_can($user['access']['permissions'],'records');
            $recordRows=[];
            if($canRecords){$records->execute();$recordRows=$records->fetchAll();}

            $countSql = 'SELECT COUNT(*) FROM portal_records' . ($user['role'] === 'admin' ? '' : ' WHERE owner_id = ?');
            $count = $pdo->prepare($countSql);
            $totalRecords=0;
            if($canRecords){$count->execute($user['role'] === 'admin' ? [] : [$user['id']]);$totalRecords=(int)$count->fetchColumn();}

            $userCount = portal_can($user['access']['permissions'],'users')
                ? (int) $pdo->query('SELECT COUNT(*) FROM portal_users WHERE is_active = 1')->fetchColumn()
                : null;
            $publicUser=$user;unset($publicUser['access'],$publicUser['auth_version']);
            json_response([
                'user' => $publicUser,
                'access' => $user['access'],
                'permission_catalog' => portal_permission_catalog(),
                'csrf_token' => csrf_token(),
                'is_admin' => $user['role'] === 'admin',
                'records' => $recordRows,
                'record_count' => $totalRecords,
                'user_count' => $userCount,
            ]);
        }

        if ($resource === 'records') {
            $sql = 'SELECT r.*, u.full_name AS owner_name FROM portal_records r JOIN portal_users u ON u.id = r.owner_id';
            if ($user['role'] !== 'admin') {
                $sql .= ' WHERE r.owner_id = ?';
            }
            $sql .= ' ORDER BY r.created_at DESC LIMIT 100';
            $query = $pdo->prepare($sql);
            $query->execute($user['role'] === 'admin' ? [] : [$user['id']]);
            json_response(['records' => $query->fetchAll()]);
        }

        if ($resource === 'users') {
            $query = $pdo->query(
                "SELECT u.id,u.username,u.email,u.full_name,u.role,u.is_active,u.created_at,ur.role_id AS access_role_id,CASE WHEN u.role='admin' THEN 'Administrator' ELSE COALESCE(r.name,'Standard user') END AS access_role_name FROM portal_users u LEFT JOIN portal_user_roles ur ON ur.user_id=u.id LEFT JOIN portal_access_roles r ON r.id=ur.role_id ORDER BY u.created_at DESC LIMIT 500"
            );
            $roles=array_values(array_filter(portal_list_roles($pdo),static fn(array $r):bool=>portal_grants_within($r['permissions'],$user['access']['permissions'])));
            json_response(['users' => $query->fetchAll(),'assignable_roles'=>$roles]);
        }

        if($resource==='roles')json_response(['roles'=>portal_list_roles($pdo),'catalog'=>portal_permission_catalog()]);

        if($resource==='chemicals'&&$action==='list')json_response(chemical_list($pdo));
        if($resource==='chemical-consumption'&&$action==='list')json_response(chemical_consumption_list($pdo,(string)$user['full_name']));
        if($resource==='chemical-consumption'&&$action==='report')json_response(chemical_consumption_report($pdo,$_GET['from_date']??null,$_GET['to_date']??null,$_GET['machine']??'ALL'));
        if($resource==='chemical-consumption'&&$action==='cost-report')json_response(chemical_cost_report($pdo,$_GET['from_date']??null,$_GET['to_date']??null,$_GET['machine']??'ALL',$_GET['report_type']??'weekly'));
        if($resource==='breakdowns'&&$action==='list')json_response(breakdown_list($pdo));
        if($resource==='breakdowns'&&$action==='by-type'){
            $lossType=$_GET['loss_type']??null;
            if(!is_string($lossType))json_response(['error'=>'Choose Planned or Unplanned loss type.'],422);
            json_response(breakdown_list($pdo,$lossType));
        }

        json_response(['error' => 'Resource not found.'], 404);
    }

    if ($resource === 'oee' && $action === 'downtime-tag' && $method === 'POST') {
        json_response(oee_tag_downtime($pdo, $data, $user['access']['permissions']));
    }
    if ($resource === 'oee' && $action === 'downtime-save' && $method === 'POST') {
        oee_downtime_save($pdo, $data);
    }
    if ($resource === 'oee' && $action === 'master-save' && $method === 'POST') {
        oee_master_save($pdo, $data, $user);
    }
    if($resource==='chemicals'&&$action==='item-save'&&$method==='POST'){
        json_response(chemical_save_item($pdo,$data,(int)$user['id']),filter_var($data['id']??null,FILTER_VALIDATE_INT)?200:201);
    }
    if($resource==='chemicals'&&$action==='rate-save'&&$method==='POST'){
        json_response(chemical_save_rate($pdo,$data,(int)$user['id']),filter_var($data['id']??null,FILTER_VALIDATE_INT)?200:201);
    }
    if($resource==='chemical-consumption'&&$action==='save'&&$method==='POST'){
        json_response(chemical_consumption_save($pdo,$data,(int)$user['id']),filter_var($data['id']??null,FILTER_VALIDATE_INT)?200:201);
    }
    if($resource==='breakdowns'&&$action==='save'&&$method==='POST'){
        json_response(breakdown_save($pdo,$data,(int)$user['id']),filter_var($data['id']??null,FILTER_VALIDATE_INT)?200:201);
    }
    if($resource==='breakdowns'&&$action==='delete'&&$method==='POST')json_response(breakdown_delete($pdo,$data['id']??null));

    if($resource==='roles'&&$action==='role-save'){
        $input=$data['permissions']??null;
        if(!is_array($input))json_response(['error'=>'Permissions must be a page/action object.'],422);
        $id=filter_var($data['id']??null,FILTER_VALIDATE_INT)?:null;
        $id=portal_save_role($pdo,$user,$id,api_text($data,'name',80),$input);
        json_response(['ok'=>true,'id'=>$id]);
    }
    if($resource==='roles'&&$action==='role-delete'){
        $id=filter_var($data['id']??null,FILTER_VALIDATE_INT);
        if(!$id)json_response(['error'=>'Choose a role.'],422);
        portal_delete_role($pdo,$user,$id);json_response(['ok'=>true]);
    }
    if($resource==='users'&&$action==='user-role'){
        $id=filter_var($data['id']??null,FILTER_VALIDATE_INT);$roleId=filter_var($data['role_id']??null,FILTER_VALIDATE_INT);
        if(!$id||!$roleId)json_response(['error'=>'Choose a user and role.'],422);
        portal_assign_role($pdo,$user,$id,$roleId);json_response(['ok'=>true]);
    }

    if ($action === 'profile' && $method === 'POST') {
        $name = api_text($data, 'full_name', 160);
        if ($name === '') {
            json_response(['error' => 'Enter a valid name.'], 422);
        }
        $query = $pdo->prepare('UPDATE portal_users SET full_name = ? WHERE id = ?');
        $query->execute([$name, $user['id']]);
        json_response(['ok' => true]);
    }

    if ($action === 'password' && $method === 'POST') {
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $query = $pdo->prepare('SELECT password_hash FROM portal_users WHERE id = ?');
        $query->execute([$user['id']]);
        $hash = (string) $query->fetchColumn();
        if (!password_verify($currentPassword, $hash)) {
            json_response(['error' => 'The current password is incorrect.'], 422);
        }
        if (!password_is_valid($newPassword)) {
            json_response(['error' => 'Use a password with at least 12 characters.'], 422);
        }
        $update = $pdo->prepare('UPDATE portal_users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?');
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        start_app_session();
        $_SESSION['auth_version'] = (int) $user['auth_version'] + 1;
        session_regenerate_id(true);
        json_response(['ok' => true]);
    }

    if ($action === 'record-create' && $method === 'POST') {
        $type = api_text($data, 'record_type', 40);
        $organization = api_text($data, 'organization', 120);
        $category = api_text($data, 'category', 120);
        $priority = api_text($data, 'priority', 16);
        $title = api_text($data, 'title', 200);
        $description = api_text($data, 'description', 10000);
        if ($type === '' || $organization === '' || $title === '' || !in_array($priority, ['Low', 'Normal', 'High'], true)) {
            json_response(['error' => 'Complete the required record fields.'], 422);
        }
        $number = 'REC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $insert = $pdo->prepare(
            'INSERT INTO portal_records (record_number, owner_id, record_type, organization, category, priority, title, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$number, $user['id'], $type, $organization, $category, $priority, $title, $description]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'record_number' => $number], 201);
    }

    if ($action === 'record-update' && $method === 'POST') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $title = api_text($data, 'title', 200);
        $description = api_text($data, 'description', 10000);
        if (!$id || $title === '') {
            json_response(['error' => 'A record and title are required.'], 422);
        }
        $accessSql = 'SELECT id FROM portal_records WHERE id = ?' . ($user['role'] === 'admin' ? '' : ' AND owner_id = ?');
        $accessQuery = $pdo->prepare($accessSql);
        $accessQuery->execute($user['role'] === 'admin' ? [$id] : [$id, $user['id']]);
        if (!$accessQuery->fetchColumn()) {
            json_response(['error' => 'Record not found or access denied.'], 404);
        }
        $sql = 'UPDATE portal_records SET title = ?, description = ? WHERE id = ?';
        $params = [$title, $description, $id];
        if ($user['role'] !== 'admin') {
            $sql .= ' AND owner_id = ?';
            $params[] = $user['id'];
        }
        $update = $pdo->prepare($sql);
        $update->execute($params);
        json_response(['ok' => true]);
    }

    if ($action === 'record-delete' && $method === 'POST') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            json_response(['error' => 'A valid record is required.'], 422);
        }
        $sql = 'DELETE FROM portal_records WHERE id = ?';
        $params = [$id];
        if ($user['role'] !== 'admin') {
            $sql .= ' AND owner_id = ?';
            $params[] = $user['id'];
        }
        $delete = $pdo->prepare($sql);
        $delete->execute($params);
        if ($delete->rowCount() !== 1) {
            json_response(['error' => 'Record not found or access denied.'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'user-create' && $method === 'POST') {
        $username = trim(api_text($data, 'username', 80));
        $email = normalize_email(api_text($data, 'email', 254));
        $name = api_text($data, 'full_name', 160);
        $password = (string) ($data['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !password_is_valid($password)) {
            json_response(['error' => 'Enter a valid username, email, name, and password of at least 12 characters.'], 422);
        }
        try {
            if(isset($data['role'])||isset($data['permissions']))json_response(['error'=>'Assign an existing access role; administrator flags cannot be supplied.'],422);
            $roleId=filter_var($data['role_id']??null,FILTER_VALIDATE_INT);
            if(!$roleId)$roleId=(int)$pdo->query("SELECT id FROM portal_access_roles WHERE is_builtin=1 AND name='Standard user'")->fetchColumn();
            if(!$roleId||!portal_grants_within(portal_role_permissions($pdo,$roleId),$user['access']['permissions']))json_response(['error'=>'Choose an assignable role within your own permissions.'],403);
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO portal_users (username, email, full_name, password_hash) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$username, $email, $name, password_hash($password, PASSWORD_DEFAULT)]);
            portal_assign_role($pdo,$user,(int)$pdo->lastInsertId(),$roleId,'create');
            $pdo->commit();
        } catch (PDOException $exception) {
            if($pdo->inTransaction())$pdo->rollBack();
            if ($exception->getCode() === '23000') {
                json_response(['error' => 'That username or email is already in use.'], 409);
            }
            throw $exception;
        }
        json_response(['ok' => true], 201);
    }

    if ($action === 'user-toggle' && $method === 'POST') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if(!$id)json_response(['error'=>'Choose a user.'],422);
        portal_toggle_user($pdo,$user,$id);
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Action not found.'], 404);
} catch (InvalidArgumentException | DomainException $exception) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    $status=$exception instanceof InvalidArgumentException?422:(in_array($exception->getCode(),[403,404,409,503],true)?$exception->getCode():403);
    json_response(['error'=>$exception->getMessage()],$status);
} catch (Throwable $exception) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if($exception instanceof PDOException && $exception->getCode()==='23000' && ($resource??'')==='roles')json_response(['error'=>'Role name is already used, or the role is still assigned to users.'],409);
    error_log('API request failed: ' . $exception->getMessage());
    json_response(['error' => 'The request could not be completed.'], 500);
}
