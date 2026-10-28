<?php
declare(strict_types=1);

function breakdown_tables_ready(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $table = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='oee_reason_codes'")->fetchColumn() === 1;
        if (!$table) return false;
        $hasLossType = false;
        $hasArchived = false;
        foreach ($pdo->query('PRAGMA table_info(oee_reason_codes)')->fetchAll() as $column) {
            if ($column['name'] === 'loss_type') $hasLossType = true;
            if ($column['name'] === 'is_archived') $hasArchived = true;
        }
        return $hasLossType && $hasArchived;
    }
    return (bool) $pdo->query("SHOW TABLES LIKE 'oee_reason_codes'")->fetchColumn()
        && (bool) $pdo->query("SHOW COLUMNS FROM oee_reason_codes LIKE 'loss_type'")->fetch()
        && (bool) $pdo->query("SHOW COLUMNS FROM oee_reason_codes LIKE 'is_archived'")->fetch();
}

function breakdown_default_catalog(): array
{
    return [
        ['NO_PLAN', 'No Plan', 'planned'],
        ['PREVENTIVE_MAINTENANCE', 'Preventive Maintenance', 'planned'],
        ['TEA_LUNCH_DINNER_BREAK', 'Tea, Lunch & Dinner Break', 'planned'],
        ['TOOL_BOX_TALK', 'Tool Box Talk', 'planned'],
        ['WORKERS_TRAINING', 'Workers Training', 'planned'],
        ['AIR_KNIFE_NOT_WORKING', 'Air Knife Not Working', 'unplanned'],
        ['CHEMICAL_ADDITION', 'Chemical Addition', 'unplanned'],
        ['CHEMICAL_FLOW_PUMP_NOT_WORKING', 'Chemical Flow Pump Not Working', 'unplanned'],
        ['CHEMICAL_NOT_AVAILABLE', 'Chemical Not Available', 'unplanned'],
        ['CHEMICAL_TANK_LEAKAGE', 'Chemical Tank Leakage', 'unplanned'],
        ['CHILLER_PROBLEM', 'Chiller Problem', 'unplanned'],
        ['COMPRESSED_AIR_PRESSURE_NOT_AVAILABLE', 'Compressed Air Pressure Not Available', 'unplanned'],
        ['HEATER_PROBLEM', 'Heater Problem', 'unplanned'],
        ['JIG_AND_FLIGHT_BAR_FAILURE', 'Jig and Flight Bar Failure', 'unplanned'],
        ['LIMIT_SWITCH_FAILURE', 'Limit Switch Failure', 'unplanned'],
        ['NEW_BATH_PREPARATION', 'New Bath Preparation', 'unplanned'],
        ['NO_MANPOWER', 'No Manpower', 'unplanned'],
        ['NO_MATERIAL', 'No Material', 'unplanned'],
        ['OTHER', 'Other', 'unplanned'],
        ['OVEN_BREAKDOWN', 'Oven Breakdown', 'unplanned'],
        ['PLC_SYSTEM_NOT_WORKING', 'PLC System Not Working', 'unplanned'],
        ['PNG_GAS_SUPPLY_NOT_AVAILABLE', 'PNG Gas Supply Not Available', 'unplanned'],
        ['POWER_SUPPLY_FAILURE', 'Power Supply Failure', 'unplanned'],
        ['POWER_SUPPLY_FLUCTUATION', 'Power Supply Fluctuation', 'unplanned'],
        ['RECTIFIER_PROBLEM', 'Rectifier Problem', 'unplanned'],
        ['SENSOR_NOT_WORKING', 'Sensor Not Working', 'unplanned'],
        ['SHOT_BLASTING_MACHINE_NOT_WORKING', 'Shot Blasting Machine Not Working', 'unplanned'],
        ['TRANSPORTER_BREAKDOWN', 'Transporter Breakdown', 'unplanned'],
        ['V_BLOCK_DAMAGED', 'V-Block Damaged', 'unplanned'],
        ['WATER_SUPPLY_NOT_AVAILABLE', 'Water Supply Not Available', 'unplanned'],
    ];
}

function breakdown_sync_default_catalog(PDO $pdo): void
{
    if (!breakdown_tables_ready($pdo)) {
        throw new DomainException('Downtime registration migration is not installed yet.', 503);
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $defaults = breakdown_default_catalog();
        $codes = array_column($defaults, 0);
        $existing = $pdo->query('SELECT code FROM oee_reason_codes WHERE code<>\'UNK\'')->fetchAll(PDO::FETCH_COLUMN);
        $usage = $pdo->prepare('SELECT COUNT(*) FROM oee_downtime_events WHERE reason_code=?');
        $archive = $pdo->prepare('UPDATE oee_reason_codes SET is_active=0,is_archived=1 WHERE code=?');
        $remove = $pdo->prepare('DELETE FROM oee_reason_codes WHERE code=?');
        foreach ($existing as $code) {
            if (in_array($code, $codes, true)) continue;
            $usage->execute([$code]);
            if ((int) $usage->fetchColumn() > 0) $archive->execute([$code]);
            else $remove->execute([$code]);
        }

        $find = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE code=?');
        $update = $pdo->prepare('UPDATE oee_reason_codes SET label=?,loss_type=?,is_active=1,is_archived=0 WHERE code=?');
        $insert = $pdo->prepare('INSERT INTO oee_reason_codes(code,label,loss_type,is_active,is_archived) VALUES (?,?,?,1,0)');
        foreach ($defaults as [$code, $label, $lossType]) {
            $find->execute([$code]);
            if ($find->fetchColumn()) $update->execute([$label, $lossType, $code]);
            else $insert->execute([$code, $label, $lossType]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function breakdown_text(mixed $value, string $field, int $maximum): string
{
    if (!is_string($value)) throw new InvalidArgumentException($field . ' must be text.');
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($text === '') throw new InvalidArgumentException($field . ' is required.');
    if (mb_strlen($text, 'UTF-8') > $maximum) throw new InvalidArgumentException($field . ' is too long.');
    return $text;
}

function breakdown_loss_type(mixed $value): string
{
    $type = strtolower(breakdown_text($value, 'Loss Type', 20));
    if (!in_array($type, ['planned', 'unplanned'], true)) throw new InvalidArgumentException('Loss Type must be Planned or Unplanned.');
    return $type;
}

function breakdown_list(PDO $pdo, mixed $lossType = null): array
{
    if (!breakdown_tables_ready($pdo)) {
        return ['available' => false, 'message' => 'Downtime registration migration is not installed yet.', 'breakdowns' => []];
    }
    $sql = 'SELECT r.id,r.code,r.label,r.loss_type,r.is_active ' .
        'FROM oee_reason_codes r WHERE r.code<>\'UNK\' AND r.is_archived=0';
    $parameters = [];
    if ($lossType !== null) {
        $type = breakdown_loss_type($lossType);
        $sql .= ' AND r.loss_type=? AND r.is_active=1';
        $parameters[] = $type;
    }
    $sql .= ' ORDER BY CASE r.loss_type WHEN \'planned\' THEN 0 ELSE 1 END,r.label';
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $rows = $query->fetchAll();
    return ['available' => true, 'breakdowns' => $rows];
}

function breakdown_code(PDO $pdo, string $name): string
{
    $base = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name)));
    $base = trim($base, '_');
    if ($base === '') $base = 'BREAKDOWN_' . strtoupper(substr(hash('sha256', $name), 0, 16));
    $base = substr($base, 0, 36);
    $code = $base;
    $suffix = 1;
    $query = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE code=?');
    while (true) {
        $query->execute([$code]);
        if (!$query->fetchColumn()) return $code;
        $suffix++;
        $tail = '_' . $suffix;
        $code = substr($base, 0, 40 - strlen($tail)) . $tail;
        if ($suffix > 999) throw new DomainException('Unable to generate a unique loss code.', 409);
    }
}

function breakdown_save(PDO $pdo, array $data, int $actorId): array
{
    if (!breakdown_tables_ready($pdo)) throw new DomainException('Breakdown registration migration is not installed yet.', 503);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $type = breakdown_loss_type($data['loss_type'] ?? null);
    $name = breakdown_text($data['loss_name'] ?? null, 'Loss Name', 120);
    $isActive = $data['is_active'] ?? true;
    if (!is_bool($isActive)) throw new InvalidArgumentException('Status must be Active or Inactive.');
    $duplicate = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE is_archived=0 AND LOWER(label)=LOWER(?) AND id<>? LIMIT 1');
    $duplicate->execute([$name, $id ?? 0]);
    if ($duplicate->fetchColumn()) throw new DomainException('That Loss Name is already registered.', 409);
    if ($id !== null) {
        $query = $pdo->prepare('UPDATE oee_reason_codes SET label=?,loss_type=?,is_active=?,is_archived=0 WHERE id=?');
        $query->execute([$name, $type, (int) $isActive, $id]);
        if ($query->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE id=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('Breakdown registration not found.', 404);
        }
    } else {
        $code = breakdown_code($pdo, $name);
        $query = $pdo->prepare('INSERT INTO oee_reason_codes(code,label,loss_type,is_active,is_archived,created_by) VALUES (?,?,?,?,0,?)');
        $query->execute([$code, $name, $type, (int) $isActive, $actorId]);
        $id = (int) $pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}
