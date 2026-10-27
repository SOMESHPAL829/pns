<?php
declare(strict_types=1);

require_once __DIR__ . '/chemicals.php';

function chemical_consumption_tables_ready(PDO $pdo): bool
{
    if (!chemical_tables_ready($pdo)) return false;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $query = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='chemical_consumption_entries'");
        return (int) $query->fetchColumn() === 1;
    }
    return (bool) $pdo->query("SHOW TABLES LIKE 'chemical_consumption_entries'")->fetchColumn();
}

function chemical_consumption_list(PDO $pdo, string $defaultOperator = ''): array
{
    if (!chemical_consumption_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'Chemical consumption migration is not installed yet.', 'entries' => [], 'items' => []];
    }
    $entries = $pdo->query(
        'SELECT e.id,e.entry_date,e.shift_name,e.operator_name,e.machine_code,e.process_description,e.chemical_item_id,' .
        'e.consume_quantity,e.used_for,e.recommended_by,e.remarks,e.created_at,e.updated_at,' .
        'i.item_code,i.description AS chemical_name,i.uom,i.status AS chemical_status ' .
        'FROM chemical_consumption_entries e JOIN chemical_items i ON i.id=e.chemical_item_id ' .
        'ORDER BY e.entry_date DESC,e.id DESC LIMIT 1000'
    )->fetchAll();
    $items = $pdo->query('SELECT id,item_code,description,status,uom FROM chemical_items ORDER BY item_code LIMIT 1000')->fetchAll();
    return ['available' => true, 'entries' => $entries, 'items' => $items, 'default_operator' => $defaultOperator];
}

function chemical_consumption_report(PDO $pdo, mixed $fromValue, mixed $toValue, mixed $machineValue): array
{
    if (!chemical_consumption_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'Chemical consumption migration is not installed yet.', 'rows' => [], 'machines' => []];
    }
    $from = chemical_date($fromValue, 'From Date');
    $to = chemical_date($toValue, 'To Date');
    if ($to < $from) throw new InvalidArgumentException('To Date cannot be before From Date.');
    $machine = strtoupper(trim(is_string($machineValue) ? $machineValue : 'ALL'));
    if ($machine === '') $machine = 'ALL';
    if ($machine !== 'ALL' && !preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{0,79}$/', $machine)) {
        throw new InvalidArgumentException('Choose a valid Machine.');
    }

    $sql = 'SELECT e.id,e.entry_date,e.shift_name,e.operator_name,e.machine_code,e.process_description,' .
        'e.consume_quantity,e.used_for,e.recommended_by,e.remarks,i.item_code,i.description AS chemical_name,i.uom ' .
        'FROM chemical_consumption_entries e JOIN chemical_items i ON i.id=e.chemical_item_id ' .
        'WHERE e.entry_date BETWEEN ? AND ?';
    $parameters = [$from, $to];
    if ($machine !== 'ALL') {
        $sql .= ' AND e.machine_code=?';
        $parameters[] = $machine;
    }
    $sql .= ' ORDER BY e.entry_date,e.id LIMIT 5001';
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $rows = $query->fetchAll();
    $truncated = count($rows) > 5000;
    if ($truncated) array_pop($rows);
    $machines = $pdo->query('SELECT DISTINCT machine_code FROM chemical_consumption_entries ORDER BY machine_code')->fetchAll(PDO::FETCH_COLUMN);
    return [
        'available' => true,
        'from_date' => $from,
        'to_date' => $to,
        'machine' => $machine,
        'machines' => $machines,
        'rows' => $rows,
        'truncated' => $truncated,
    ];
}

function chemical_cost_report_tables_ready(PDO $pdo): bool
{
    if (!chemical_consumption_tables_ready($pdo)) return false;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $query = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='oee_fb_runs'");
        return (int) $query->fetchColumn() === 1;
    }
    return (bool) $pdo->query("SHOW TABLES LIKE 'oee_fb_runs'")->fetchColumn();
}

function chemical_cost_report_period(string $date, string $type, string $from, string $to): array
{
    $day = new DateTimeImmutable($date);
    if ($type === 'weekly') {
        $start = $day->modify('monday this week');
        $end = $start->modify('+6 days');
        return [$start->format('o-\WW'), 'Week ' . $start->format('W') . ' (' . $start->format('d M Y') . ' - ' . $end->format('d M Y') . ')'];
    }
    if ($type === 'monthly') {
        return [$day->format('Y-m'), $day->format('F Y')];
    }
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    return ['consolidated', $start->format('d M Y') . ' - ' . $end->format('d M Y')];
}

function chemical_cost_report(PDO $pdo, mixed $fromValue, mixed $toValue, mixed $machineValue, mixed $typeValue): array
{
    if (!chemical_cost_report_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'Chemical consumption and OEE production data must be installed before using this report.', 'rows' => [], 'machines' => []];
    }
    $from = chemical_date($fromValue, 'From Date');
    $to = chemical_date($toValue, 'To Date');
    if ($to < $from) throw new InvalidArgumentException('To Date cannot be before From Date.');
    $fromDate = new DateTimeImmutable($from);
    $toDate = new DateTimeImmutable($to);
    if ((int) $fromDate->diff($toDate)->format('%a') > 365) throw new InvalidArgumentException('Choose a date range of 366 days or fewer.');
    $machine = strtoupper(trim(is_string($machineValue) ? $machineValue : 'ALL'));
    if ($machine === '') $machine = 'ALL';
    if ($machine !== 'ALL' && !preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{0,79}$/', $machine)) {
        throw new InvalidArgumentException('Choose a valid Machine.');
    }
    $type = strtolower(trim(is_string($typeValue) ? $typeValue : 'weekly'));
    if (!in_array($type, ['weekly', 'monthly', 'consolidated'], true)) {
        throw new InvalidArgumentException('Report Type must be Weekly Basis, Monthly Basis, or Consolidated.');
    }

    $machineClause = $machine === 'ALL' ? '' : ' AND line_code=?';
    $surface = $pdo->prepare(
        'SELECT line_code AS machine_code,report_date,COALESCE(SUM(total_surface_area),0) AS total_surface_area ' .
        'FROM oee_fb_runs WHERE report_date BETWEEN ? AND ?' . $machineClause . ' GROUP BY line_code,report_date'
    );
    $surface->execute($machine === 'ALL' ? [$from, $to] : [$from, $to, $machine]);

    $consumptionMachineClause = $machine === 'ALL' ? '' : ' AND e.machine_code=?';
    $cost = $pdo->prepare(
        'SELECT e.machine_code,e.entry_date AS report_date,' .
        'COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE e.consume_quantity*r.rate_inr END),0) AS priced_cost,' .
        'SUM(CASE WHEN r.id IS NULL THEN 1 ELSE 0 END) AS unpriced_entries ' .
        'FROM chemical_consumption_entries e LEFT JOIN chemical_item_rates r ON r.chemical_item_id=e.chemical_item_id ' .
        'AND r.effective_from<=e.entry_date AND (r.effective_to IS NULL OR r.effective_to>=e.entry_date) ' .
        'WHERE e.entry_date BETWEEN ? AND ?' . $consumptionMachineClause . ' GROUP BY e.machine_code,e.entry_date'
    );
    $cost->execute($machine === 'ALL' ? [$from, $to] : [$from, $to, $machine]);

    $groups = [];
    $add = static function (array $daily, string $source) use (&$groups, $type, $from, $to): void {
        [$periodKey, $periodLabel] = chemical_cost_report_period((string) $daily['report_date'], $type, $from, $to);
        $key = $daily['machine_code'] . '|' . $periodKey;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'machine_code' => $daily['machine_code'],
                'period_key' => $periodKey,
                'report_period' => $periodLabel,
                'total_surface_area' => 0.0,
                'priced_chemical_cost' => 0.0,
                'unpriced_entries' => 0,
            ];
        }
        if ($source === 'surface') {
            $groups[$key]['total_surface_area'] += (float) $daily['total_surface_area'];
        } else {
            $groups[$key]['priced_chemical_cost'] += (float) $daily['priced_cost'];
            $groups[$key]['unpriced_entries'] += (int) $daily['unpriced_entries'];
        }
    };
    foreach ($surface->fetchAll() as $daily) $add($daily, 'surface');
    foreach ($cost->fetchAll() as $daily) $add($daily, 'cost');

    $rows = array_values($groups);
    usort($rows, static fn(array $left, array $right): int => [$left['period_key'], $left['machine_code']] <=> [$right['period_key'], $right['machine_code']]);
    foreach ($rows as &$row) {
        $row['total_surface_area'] = round($row['total_surface_area'], 4);
        if ($row['unpriced_entries'] > 0) {
            $row['total_chemical_cost'] = null;
            $row['chemical_cost_per_dm2'] = null;
        } else {
            $row['total_chemical_cost'] = round($row['priced_chemical_cost'], 4);
            $row['chemical_cost_per_dm2'] = $row['total_surface_area'] > 0
                ? round($row['total_chemical_cost'] / $row['total_surface_area'], 6)
                : null;
        }
        unset($row['priced_chemical_cost']);
    }
    unset($row);

    $machines = $pdo->query(
        'SELECT machine_code FROM (' .
        'SELECT DISTINCT machine_code FROM chemical_consumption_entries UNION SELECT DISTINCT line_code AS machine_code FROM oee_fb_runs' .
        ') report_machines ORDER BY machine_code'
    )->fetchAll(PDO::FETCH_COLUMN);
    return [
        'available' => true,
        'from_date' => $from,
        'to_date' => $to,
        'machine' => $machine,
        'report_type' => $type,
        'machines' => $machines,
        'rows' => $rows,
        'formula' => 'Total Chemical Cost (INR) / Total Surface Area (dm²)',
    ];
}

function chemical_consumption_quantity(mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Consume Quantity must be a number.');
    }
    $quantity = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/', $quantity) || (float) $quantity <= 0) {
        throw new InvalidArgumentException('Consume Quantity must be greater than zero with up to four decimal places.');
    }
    return $quantity;
}

function chemical_consumption_save(PDO $pdo, array $data, int $actorId): array
{
    if (!chemical_consumption_tables_ready($pdo)) throw new DomainException('Chemical consumption migration is not installed yet.', 503);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $entryDate = chemical_date($data['entry_date'] ?? null, 'Date');
    $shift = chemical_text($data['shift_name'] ?? null, 'Shift', 40);
    $operator = chemical_text($data['operator_name'] ?? null, 'Operator Name', 120);
    $machine = strtoupper(chemical_text($data['machine_code'] ?? null, 'Machine Code', 80));
    $process = chemical_text($data['process_description'] ?? null, 'Process Description', 500);
    $chemicalId = filter_var($data['chemical_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$chemicalId) throw new InvalidArgumentException('Choose a Chemical Name.');
    $quantity = chemical_consumption_quantity($data['consume_quantity'] ?? null);
    $usedFor = chemical_text($data['used_for'] ?? null, 'Used For', 500);
    if (!in_array($usedFor, ['Fresh Makeup', 'Top-Up'], true)) {
        throw new InvalidArgumentException('Used For must be Fresh Makeup or Top-Up.');
    }
    $recommendedBy = chemical_text($data['recommended_by'] ?? null, 'Recommended By', 120);
    $remarksRaw = $data['remarks'] ?? '';
    if (!is_string($remarksRaw)) throw new InvalidArgumentException('Remarks must be text.');
    $remarks = trim($remarksRaw);
    if (mb_strlen($remarks, 'UTF-8') > 1000) throw new InvalidArgumentException('Remarks is too long.');
    if (!preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]*$/', $machine)) throw new InvalidArgumentException('Enter a valid Machine Code.');

    $item = $pdo->prepare('SELECT id,status FROM chemical_items WHERE id=?');
    $item->execute([$chemicalId]);
    $chemical = $item->fetch();
    if (!$chemical) throw new DomainException('Chemical item not found.', 404);
    if ($chemical['status'] !== 'active') {
        if ($id === null) throw new DomainException('Inactive chemical items cannot be used for new entries.', 409);
        $existing = $pdo->prepare('SELECT chemical_item_id FROM chemical_consumption_entries WHERE id=?');
        $existing->execute([$id]);
        $existingChemicalId = $existing->fetchColumn();
        if ($existingChemicalId === false) throw new DomainException('Chemical consumption entry not found.', 404);
        if ((int) $existingChemicalId !== (int) $chemicalId) {
            throw new DomainException('An entry cannot be changed to an inactive chemical item.', 409);
        }
    }

    if ($id !== null) {
        $query = $pdo->prepare(
            'UPDATE chemical_consumption_entries SET entry_date=?,shift_name=?,operator_name=?,machine_code=?,process_description=?,' .
            'chemical_item_id=?,consume_quantity=?,used_for=?,recommended_by=?,remarks=?,updated_by=? WHERE id=?'
        );
        $query->execute([$entryDate, $shift, $operator, $machine, $process, $chemicalId, $quantity, $usedFor, $recommendedBy, $remarks ?: null, $actorId, $id]);
        if ($query->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT id FROM chemical_consumption_entries WHERE id=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('Chemical consumption entry not found.', 404);
        }
    } else {
        $query = $pdo->prepare(
            'INSERT INTO chemical_consumption_entries(entry_date,shift_name,operator_name,machine_code,process_description,' .
            'chemical_item_id,consume_quantity,used_for,recommended_by,remarks,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $query->execute([$entryDate, $shift, $operator, $machine, $process, $chemicalId, $quantity, $usedFor, $recommendedBy, $remarks ?: null, $actorId, $actorId]);
        $id = (int) $pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}
