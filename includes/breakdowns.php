<?php
declare(strict_types=1);

function breakdown_tables_ready(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $table = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='oee_reason_codes'")->fetchColumn() === 1;
        if (!$table) return false;
        foreach ($pdo->query('PRAGMA table_info(oee_reason_codes)')->fetchAll() as $column) {
            if ($column['name'] === 'loss_type') return true;
        }
        return false;
    }
    return (bool) $pdo->query("SHOW TABLES LIKE 'oee_reason_codes'")->fetchColumn()
        && (bool) $pdo->query("SHOW COLUMNS FROM oee_reason_codes LIKE 'loss_type'")->fetch();
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
        return ['available' => false, 'message' => 'Breakdown registration migration is not installed yet.', 'breakdowns' => []];
    }
    $sql = 'SELECT r.id,r.code,r.label,r.loss_type,r.is_active,' .
        '(SELECT COUNT(*) FROM oee_downtime_events e WHERE e.reason_code=r.code) AS assigned_count ' .
        'FROM oee_reason_codes r WHERE r.code<>\'UNK\'';
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
    $duplicate = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE LOWER(label)=LOWER(?) AND id<>? LIMIT 1');
    $duplicate->execute([$name, $id ?? 0]);
    if ($duplicate->fetchColumn()) throw new DomainException('That Loss Name is already registered.', 409);
    if ($id !== null) {
        $query = $pdo->prepare('UPDATE oee_reason_codes SET label=?,loss_type=?,is_active=1 WHERE id=?');
        $query->execute([$name, $type, $id]);
        if ($query->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT id FROM oee_reason_codes WHERE id=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('Breakdown registration not found.', 404);
        }
    } else {
        $code = breakdown_code($pdo, $name);
        $query = $pdo->prepare('INSERT INTO oee_reason_codes(code,label,loss_type,is_active,created_by) VALUES (?,?,?,1,?)');
        $query->execute([$code, $name, $type, $actorId]);
        $id = (int) $pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}

function breakdown_delete(PDO $pdo, mixed $idValue): array
{
    if (!breakdown_tables_ready($pdo)) throw new DomainException('Breakdown registration migration is not installed yet.', 503);
    $id = filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new InvalidArgumentException('Choose a valid breakdown registration.');
    $query = $pdo->prepare('SELECT code,label FROM oee_reason_codes WHERE id=?');
    $query->execute([$id]);
    $reason = $query->fetch();
    if (!$reason) throw new DomainException('Breakdown registration not found.', 404);
    $assigned = $pdo->prepare('SELECT COUNT(*) FROM oee_downtime_events WHERE reason_code=?');
    $assigned->execute([$reason['code']]);
    if ((int) $assigned->fetchColumn() > 0) throw new DomainException('Cannot delete. This breakdown reason is already used in downtime records.', 409);
    if ($reason['code'] === 'UNK') throw new DomainException('The system Not Yet Classified reason cannot be deleted.', 409);
    $pdo->prepare('DELETE FROM oee_reason_codes WHERE id=?')->execute([$id]);
    return ['ok' => true, 'id' => (int) $id];
}
