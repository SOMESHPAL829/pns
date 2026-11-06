<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/chemical_consumption.php';

$source = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
$allowSourceDuplicates = in_array('--allow-source-duplicates', $argv, true);
$expectedPlant = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--expected-plant=')) {
        $expectedPlant = mb_strtoupper(trim(substr($argument, strlen('--expected-plant='))), 'UTF-8');
    }
}
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php import-chemical-consumption.php <xlsx-or-tab-separated-file> [--dry-run] [--allow-source-duplicates] [--expected-plant=APP-06]\n");
    exit(1);
}

$expected = ['Date','Machine','Shift Name','Operator Name','Process','Chemical Code','Chemical Name','Consumption Qty','UOM','Used For','Recommended By'];

/** @return list<list<string>> */
function chemical_import_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Unable to open the Excel workbook.');
    try {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $document = new DOMDocument();
            if (!$document->loadXML($sharedXml)) throw new RuntimeException('The Excel shared strings file is invalid.');
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('/*[local-name()="sst"]/*[local-name()="si"]') ?: [] as $item) {
                $value = '';
                foreach ($xpath->query('.//*[local-name()="t"]', $item) ?: [] as $textNode) $value .= $textNode->textContent;
                $sharedStrings[] = $value;
            }
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationsXml === false) throw new RuntimeException('The Excel workbook structure is incomplete.');
        $workbook = new DOMDocument();
        $relations = new DOMDocument();
        if (!$workbook->loadXML($workbookXml) || !$relations->loadXML($relationsXml)) throw new RuntimeException('The Excel workbook structure is invalid.');
        $workbookXpath = new DOMXPath($workbook);
        $firstSheet = $workbookXpath->query('//*[local-name()="sheets"]/*[local-name()="sheet"][1]')?->item(0);
        if (!$firstSheet instanceof DOMElement) throw new RuntimeException('The Excel workbook contains no worksheet.');
        $relationshipId = $firstSheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $relationsXpath = new DOMXPath($relations);
        $relationship = $relationsXpath->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"][@Id="' . $relationshipId . '"]')?->item(0);
        if (!$relationship instanceof DOMElement) throw new RuntimeException('The first worksheet relationship is missing.');
        $worksheetTarget = str_replace('\\', '/', $relationship->getAttribute('Target'));
        $worksheetPath = str_starts_with($worksheetTarget, '/')
            ? ltrim($worksheetTarget, '/')
            : 'xl/' . preg_replace('#^(?:\.\./)+#', '', $worksheetTarget);
        $worksheetXml = $zip->getFromName($worksheetPath);
        if ($worksheetXml === false) throw new RuntimeException('The first Excel worksheet could not be read.');

        $worksheet = new DOMDocument();
        if (!$worksheet->loadXML($worksheetXml)) throw new RuntimeException('The first Excel worksheet is invalid.');
        $worksheetXpath = new DOMXPath($worksheet);
        $result = [];
        foreach ($worksheetXpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $row = [];
            foreach ($worksheetXpath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cell) {
                if (!$cell instanceof DOMElement) continue;
                preg_match('/^[A-Z]+/i', $cell->getAttribute('r'), $columnMatch);
                $letters = strtoupper($columnMatch[0] ?? 'A');
                $columnIndex = 0;
                foreach (str_split($letters) as $letter) $columnIndex = ($columnIndex * 26) + (ord($letter) - 64);
                $columnIndex--;
                $valueNode = $worksheetXpath->query('./*[local-name()="v"]', $cell)?->item(0);
                $value = $valueNode?->textContent ?? '';
                $type = $cell->getAttribute('t');
                if ($type === 's') $value = $sharedStrings[(int)$value] ?? '';
                elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($worksheetXpath->query('.//*[local-name()="t"]', $cell) ?: [] as $textNode) $value .= $textNode->textContent;
                }
                $row[$columnIndex] = trim($value);
            }
            if ($row !== []) {
                $lastColumn = max(array_keys($row));
                $result[] = array_map(static fn(int $index): string => (string)($row[$index] ?? ''), range(0, $lastColumn));
            }
        }
        return $result;
    } finally {
        $zip->close();
    }
}

/** @return list<list<string>> */
function chemical_import_source_rows(string $path): array
{
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') return chemical_import_xlsx_rows($path);
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Unable to open the import file.');
    try {
        $rows = [];
        while (($values = fgetcsv($handle, 0, "\t")) !== false) $rows[] = array_map(static fn($value): string => trim((string)$value), $values);
        return $rows;
    } finally {
        fclose($handle);
    }
}

function chemical_import_date(string $value, int $line): DateTimeImmutable
{
    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial < 1 || $serial > 100000) throw new RuntimeException("Line {$line} has an invalid Excel date.");
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)floor($serial) . ' days');
    }
    foreach (['!d-M-y', '!Y-m-d', '!d-m-Y', '!d/m/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) return $date;
    }
    throw new RuntimeException("Line {$line} has an invalid date.");
}

$sourceRows = chemical_import_source_rows($source);
if ($sourceRows === []) throw new RuntimeException('The import file has no header row.');
$header = array_shift($sourceRows);
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($header[0] ?? ''));
$headerAliases = [
    'DATE'=>'Date', 'MACHINE'=>'Machine', 'SHIFT'=>'Shift Name', 'SHIFT NAME'=>'Shift Name',
    'OPERATOR'=>'Operator Name', 'OPERATOR NAME'=>'Operator Name', 'PROCESS'=>'Process',
    'CHEMICAL CODE'=>'Chemical Code', 'CHEMICAL NAME'=>'Chemical Name', 'CONSUMPTION QTY'=>'Consumption Qty',
    'UOM'=>'UOM', 'CONSUMPTION TYPE'=>'Used For', 'USED FOR'=>'Used For', 'RECOMMENDED BY'=>'Recommended By',
];
$canonicalHeader = array_map(static function(string $column) use ($headerAliases): string {
    $key = mb_strtoupper(trim($column), 'UTF-8');
    return $headerAliases[$key] ?? $column;
}, $header);
$requiredColumns = array_values(array_diff($expected, ['Recommended By']));
$missingColumns = array_values(array_diff($requiredColumns, $canonicalHeader));
if ($missingColumns !== []) throw new RuntimeException('Missing required columns: ' . implode(', ', $missingColumns));
if (count($canonicalHeader) !== count(array_unique($canonicalHeader))) throw new RuntimeException('The import file contains duplicate columns.');

$rows = [];
$sourceKeys = [];
$sourceDuplicateRows = 0;
$line = 1;
foreach ($sourceRows as $values) {
    $line++;
    if (count(array_filter($values, static fn($value): bool => trim((string)$value) !== '')) === 0) continue;
    $values = array_pad($values, count($canonicalHeader), '');
    if (count($values) !== count($canonicalHeader)) throw new RuntimeException("Line {$line} has " . count($values) . ' columns; expected ' . count($canonicalHeader) . '.');
    $row = array_combine($canonicalHeader, array_map(static fn($value): string => trim((string)$value), $values));
    $row['Recommended By'] = trim((string)($row['Recommended By'] ?? '')) ?: 'PRODUCTION MANAGER';
    foreach ($expected as $column) if (($row[$column] ?? '') === '') throw new RuntimeException("Line {$line} has a blank {$column} value.");
    $date = chemical_import_date($row['Date'], $line);
    $shift = preg_match('/^shift\s+/i', $row['Shift Name']) ? $row['Shift Name'] : 'Shift ' . mb_strtoupper($row['Shift Name'], 'UTF-8');
    $normalized = [
        'entry_date'=>$date->format('Y-m-d'),
        'shift_name'=>mb_strtoupper($shift, 'UTF-8'),
        'operator_name'=>mb_strtoupper($row['Operator Name'], 'UTF-8'),
        'machine_code'=>mb_strtoupper($row['Machine'], 'UTF-8'),
        'process_description'=>mb_strtoupper($row['Process'], 'UTF-8'),
        'chemical_code'=>mb_strtoupper($row['Chemical Code'], 'UTF-8'),
        'chemical_name'=>mb_strtoupper($row['Chemical Name'], 'UTF-8'),
        'consume_quantity'=>chemical_consumption_quantity((string)preg_replace('/^0+(?=\d)/', '', $row['Consumption Qty'])),
        'uom'=>mb_strtoupper($row['UOM'], 'UTF-8'),
        'used_for'=>mb_strtoupper($row['Used For'], 'UTF-8'),
        'recommended_by'=>mb_strtoupper($row['Recommended By'], 'UTF-8'),
        'remarks'=>'',
        'source_line'=>$line,
    ];
    if (!in_array($normalized['used_for'], ['FRESH MAKEUP','TOP-UP'], true)) throw new RuntimeException("Line {$line} has an invalid Used For value.");
    if (!in_array($normalized['recommended_by'], ['QC MANAGER','PRODUCTION MANAGER'], true)) throw new RuntimeException("Line {$line} has an invalid Recommended By value.");
    $key = json_encode(array_diff_key($normalized, ['source_line'=>true]), JSON_THROW_ON_ERROR);
    if (isset($sourceKeys[$key])) {
        if (!$allowSourceDuplicates) throw new RuntimeException("Lines {$sourceKeys[$key]} and {$line} are exact duplicates.");
        $sourceDuplicateRows++;
    }
    $sourceKeys[$key] = $line;
    $rows[] = $normalized;
}
if ($rows === []) throw new RuntimeException('The import file contains no data rows.');
if ($expectedPlant !== null && $expectedPlant !== '') {
    $unexpectedPlants = array_values(array_diff(array_unique(array_column($rows, 'machine_code')), [$expectedPlant]));
    if ($unexpectedPlants !== []) throw new RuntimeException('The import contains unexpected plants: ' . implode(', ', $unexpectedPlants));
}

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
echo 'Plant rows: ' . json_encode(array_count_values(array_column($rows, 'machine_code')), JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'Preserved source duplicate rows: ' . $sourceDuplicateRows . PHP_EOL;
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
