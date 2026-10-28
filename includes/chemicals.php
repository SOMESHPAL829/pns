<?php
declare(strict_types=1);

function chemical_tables_ready(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $count = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('chemical_items','chemical_item_rates')")->fetchColumn();
        return (int) $count === 2;
    }
    return (bool) $pdo->query("SHOW TABLES LIKE 'chemical_items'")->fetchColumn()
        && (bool) $pdo->query("SHOW TABLES LIKE 'chemical_item_rates'")->fetchColumn();
}

function chemical_text(mixed $value, string $field, int $maximum): string
{
    if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException($field . ' must be text.');
    $text = trim((string) $value);
    if ($text === '') throw new InvalidArgumentException($field . ' is required.');
    if (mb_strlen($text, 'UTF-8') > $maximum) throw new InvalidArgumentException($field . ' is too long.');
    return $text;
}

function chemical_date(mixed $value, string $field, bool $required = true): ?string
{
    if (($value === null || $value === '') && !$required) return null;
    if (!is_string($value)) throw new InvalidArgumentException($field . ' must use YYYY-MM-DD.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($field . ' must use YYYY-MM-DD.');
    }
    return $value;
}

function chemical_list(PDO $pdo): array
{
    if (!chemical_tables_ready($pdo)) return ['available' => false, 'message' => 'Chemical registration migration is not installed yet.', 'items' => [], 'rates' => []];
    $items = $pdo->query('SELECT id,item_code,description,status,uom,created_at,updated_at FROM chemical_items ORDER BY item_code LIMIT 1000')->fetchAll();
    $rates = $pdo->query('SELECT r.id,r.chemical_item_id,i.item_code,r.rate_inr,r.effective_from,r.effective_to,r.created_at,r.updated_at FROM chemical_item_rates r JOIN chemical_items i ON i.id=r.chemical_item_id ORDER BY i.item_code,r.effective_from DESC,r.id DESC LIMIT 5000')->fetchAll();
    return ['available' => true, 'currency' => 'INR', 'items' => $items, 'rates' => $rates];
}

function chemical_save_item(PDO $pdo, array $data, int $actorId): array
{
    if (!chemical_tables_ready($pdo)) throw new DomainException('Chemical registration migration is not installed yet.', 503);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $code = strtoupper(chemical_text($data['item_code'] ?? null, 'Item code', 80));
    $description = chemical_text($data['description'] ?? null, 'Description', 500);
    $status = strtolower(chemical_text($data['status'] ?? null, 'Status', 16));
    $uom = strtoupper(chemical_text($data['uom'] ?? null, 'UOM', 40));
    if ($uom === 'L') $uom = 'LTR';
    if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/', $code)) throw new InvalidArgumentException('Item code may contain letters, numbers, dots, underscores, slashes and hyphens.');
    if (!in_array($status, ['active', 'inactive'], true)) throw new InvalidArgumentException('Status must be Active or Inactive.');
    if (!preg_match('/^[A-Z0-9][A-Z0-9 ._\/%-]*$/', $uom)) throw new InvalidArgumentException('Enter a valid UOM.');
    try {
        if ($id !== null) {
            $query = $pdo->prepare('UPDATE chemical_items SET item_code=?,description=?,status=?,uom=?,updated_by=? WHERE id=?');
            $query->execute([$code, $description, $status, $uom, $actorId, $id]);
            if ($query->rowCount() < 1) {
                $exists = $pdo->prepare('SELECT id FROM chemical_items WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) throw new DomainException('Chemical item not found.', 404);
            }
        } else {
            $query = $pdo->prepare('INSERT INTO chemical_items(item_code,description,status,uom,created_by,updated_by) VALUES (?,?,?,?,?,?)');
            $query->execute([$code, $description, $status, $uom, $actorId, $actorId]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') throw new DomainException('That chemical item code is already registered.', 409);
        throw $exception;
    }
    return ['ok' => true, 'id' => $id, 'item_code' => $code];
}

function chemical_save_rate(PDO $pdo, array $data, int $actorId): array
{
    if (!chemical_tables_ready($pdo)) throw new DomainException('Chemical registration migration is not installed yet.', 503);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $itemId = filter_var($data['chemical_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$itemId) throw new InvalidArgumentException('Choose a chemical item.');
    $rawRate = $data['rate_inr'] ?? null;
    if (!is_string($rawRate) && !is_int($rawRate) && !is_float($rawRate)) throw new InvalidArgumentException('Rate must be a number in INR.');
    $rateText = trim((string) $rawRate);
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/', $rateText) || (float) $rateText <= 0) throw new InvalidArgumentException('Rate must be greater than zero with up to four decimal places.');
    $from = chemical_date($data['effective_from'] ?? null, 'Effective From');
    $to = chemical_date($data['effective_to'] ?? null, 'Effective To', false);
    if ($to !== null && $to < $from) throw new InvalidArgumentException('Effective To cannot be before Effective From.');
    $item = $pdo->prepare('SELECT id FROM chemical_items WHERE id=?');
    $item->execute([$itemId]);
    if (!$item->fetchColumn()) throw new DomainException('Chemical item not found.', 404);
    $overlap = $pdo->prepare("SELECT id FROM chemical_item_rates WHERE chemical_item_id=? AND id<>? AND effective_from<=COALESCE(?,'9999-12-31') AND COALESCE(effective_to,'9999-12-31')>=? LIMIT 1");
    $overlap->execute([$itemId, $id ?? 0, $to, $from]);
    if ($overlap->fetchColumn()) throw new DomainException('The effective date range overlaps an existing rate for this item.', 409);
    try {
        if ($id !== null) {
            $query = $pdo->prepare('UPDATE chemical_item_rates SET chemical_item_id=?,rate_inr=?,effective_from=?,effective_to=?,updated_by=? WHERE id=?');
            $query->execute([$itemId, $rateText, $from, $to, $actorId, $id]);
            if ($query->rowCount() < 1) {
                $exists = $pdo->prepare('SELECT id FROM chemical_item_rates WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) throw new DomainException('Chemical rate not found.', 404);
            }
        } else {
            $query = $pdo->prepare('INSERT INTO chemical_item_rates(chemical_item_id,rate_inr,effective_from,effective_to,created_by,updated_by) VALUES (?,?,?,?,?,?)');
            $query->execute([$itemId, $rateText, $from, $to, $actorId, $actorId]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') throw new DomainException('A rate already starts on that date for this item.', 409);
        throw $exception;
    }
    return ['ok' => true, 'id' => $id, 'currency' => 'INR'];
}
