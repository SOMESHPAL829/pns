<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/chemical_consumption.php';

$source = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php import-chemical-consumption.php <tab-separated-file> [--dry-run]\n");
    exit(1);
}

$expected = ['Date','Machine','Shift Name','Operator Name','Process','Chemical Code','Chemical Name','Consumption Qty','UOM','Used For','Recommended By'];
$handle = fopen($source, 'rb');
if ($handle === false) throw new RuntimeException('Unable to open the import file.');
$header = fgetcsv($handle, 0, "\t");
if (!is_array($header)) throw new RuntimeException('The import file has no header row.');
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
if ($header !== $expected) throw new RuntimeException('Unexpected columns. Expected: ' . implode(' | ', $expected));

$rows = [];
$sourceKeys = [];
$line = 1;
while (($values = fgetcsv($handle, 0, "\t")) !== false) {
    $line++;
    if ($values === [null] || (count($values) === 1 && trim((string)$values[0]) === '')) continue;
    if (count($values) !== count($expected)) throw new RuntimeException("Line {$line} has " . count($values) . ' columns; expected ' . count($expected) . '.');
    $row = array_combine($expected, array_map(static fn($value): string => trim((string)$value), $values));
    foreach ($row as $column=>$value) if ($value === '') throw new RuntimeException("Line {$line} has a blank {$column} value.");
    $date = DateTimeImmutable::createFromFormat('!d-M-y', $row['Date']);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) throw new RuntimeException("Line {$line} has an invalid date.");
    $shift = preg_match('/^shift\s+/i', $row['Shift Name']) ? $row['Shift Name'] : 'Shift ' . mb_strtoupper($row['Shift Name'], 'UTF-8');
    $normalized = [
        'entry_date'=>$date->format('Y-m-d'),
        'shift_name'=>mb_strtoupper($shift, 'UTF-8'),
        'operator_name'=>mb_strtoupper($row['Operator Name'], 'UTF-8'),
        'machine_code'=>mb_strtoupper($row['Machine'], 'UTF-8'),
        'process_description'=>mb_strtoupper($row['Process'], 'UTF-8'),
        'chemical_code'=>mb_strtoupper($row['Chemical Code'], 'UTF-8'),
        'chemical_name'=>mb_strtoupper($row['Chemical Name'], 'UTF-8'),
        'consume_quantity'=>chemical_consumption_quantity($row['Consumption Qty']),
        'uom'=>mb_strtoupper($row['UOM'], 'UTF-8'),
        'used_for'=>mb_strtoupper($row['Used For'], 'UTF-8'),
        'recommended_by'=>mb_strtoupper($row['Recommended By'], 'UTF-8'),
        'remarks'=>'',
        'source_line'=>$line,
    ];
    if (!in_array($normalized['used_for'], ['FRESH MAKEUP','TOP-UP'], true)) throw new RuntimeException("Line {$line} has an invalid Used For value.");
    if (!in_array($normalized['recommended_by'], ['QC MANAGER','PRODUCTION MANAGER'], true)) throw new RuntimeException("Line {$line} has an invalid Recommended By value.");
    $key = json_encode(array_diff_key($normalized, ['source_line'=>true]), JSON_THROW_ON_ERROR);
    if (isset($sourceKeys[$key])) throw new RuntimeException("Lines {$sourceKeys[$key]} and {$line} are exact duplicates.");
    $sourceKeys[$key] = $line;
    $rows[] = $normalized;
}
fclose($handle);
if ($rows === []) throw new RuntimeException('The import file contains no data rows.');

$pdo = db();
$codes = array_values(array_unique(array_column($rows, 'chemical_code')));
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$itemsQuery = $pdo->prepare("SELECT id,item_code,description,uom,status FROM chemical_items WHERE item_code IN ({$placeholders})");
$itemsQuery->execute($codes);
$items = [];
foreach ($itemsQuery->fetchAll() as $item) $items[strtoupper((string)$item['item_code'])] = $item;
$missing = array_values(array_diff($codes, array_keys($items)));
if ($missing !== []) throw new RuntimeException('Chemical codes not registered: ' . implode(', ', $missing));
foreach ($codes as $code) if (($items[$code]['status'] ?? '') !== 'active') throw new RuntimeException("Chemical {$code} is inactive.");

$actorId = (int)$pdo->query("SELECT id FROM portal_users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
if ($actorId < 1) throw new RuntimeException('No active administrator account is available for import attribution.');
$duplicate = $pdo->prepare(
    "SELECT COUNT(*) FROM chemical_consumption_entries WHERE entry_date=? AND shift_name=? AND operator_name=? AND machine_code=? " .
    "AND process_description=? AND chemical_item_id=? AND consume_quantity=? AND uom=? AND used_for=? AND recommended_by=? " .
    "AND (remarks IS NULL OR TRIM(remarks)='')"
);

$ready = [];
$existing = 0;
foreach ($rows as $row) {
    $item = $items[$row['chemical_code']];
    $duplicate->execute([$row['entry_date'],$row['shift_name'],$row['operator_name'],$row['machine_code'],$row['process_description'],$item['id'],$row['consume_quantity'],$row['uom'],$row['used_for'],$row['recommended_by']]);
    $matchingRows=(int)$duplicate->fetchColumn();
    if($matchingRows>1)throw new RuntimeException("Source line {$row['source_line']} has {$matchingRows} matching database rows; duplicates must be reviewed.");
    if ($matchingRows===1) { $existing++; continue; }
    $row['chemical_item_id'] = (int)$item['id'];
    $ready[] = $row;
}

echo 'Validated rows: ' . count($rows) . PHP_EOL;
echo 'Registered chemical codes: ' . count($codes) . PHP_EOL;
echo 'Already present: ' . $existing . PHP_EOL;
echo 'Ready to insert: ' . count($ready) . PHP_EOL;
echo 'Current table rows: ' . (int)$pdo->query('SELECT COUNT(*) FROM chemical_consumption_entries')->fetchColumn() . PHP_EOL;
if ($dryRun) { echo "Dry run complete; database unchanged.\n"; exit(0); }

$pdo->beginTransaction();
try {
    foreach (array_chunk($ready, 200) as $chunk) {
        $valueSql=[];$parameters=[];
        foreach($chunk as $row){
            $valueSql[]='(?,?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push($parameters,$row['entry_date'],$row['shift_name'],$row['operator_name'],$row['machine_code'],$row['process_description'],
                $row['chemical_item_id'],$row['consume_quantity'],$row['uom'],$row['used_for'],$row['recommended_by'],null,$actorId,$actorId);
        }
        $insert=$pdo->prepare('INSERT INTO chemical_consumption_entries(entry_date,shift_name,operator_name,machine_code,process_description,' .
            'chemical_item_id,consume_quantity,uom,used_for,recommended_by,remarks,created_by,updated_by) VALUES '.implode(',',$valueSql));
        $insert->execute($parameters);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
echo 'Inserted rows: ' . count($ready) . PHP_EOL;
echo 'Import complete. Total source rows accounted for: ' . ($existing + count($ready)) . PHP_EOL;
