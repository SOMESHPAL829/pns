<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/oee_ingest.php';
require_once dirname(__DIR__, 3) . '/includes/oee_calculator.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'POST is required.'], 405);
}

$config = app_config();
$configuredKey = (string) ($config['oee_ingest_api_key'] ?? '');
$providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    json_response(['error' => 'Authentication failed.'], 401);
}

$maxBytes = max(1024, (int) ($config['oee_max_payload_bytes'] ?? 5242880));
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBytes) {
    json_response(['error' => 'Payload is too large.'], 413);
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) === 0 || strlen($rawBody) > $maxBytes) {
    json_response(['error' => 'A JSON payload is required.'], 422);
}
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_response(['error' => 'Invalid JSON payload.'], 422);
}

try {
    $normalized = oee_validate_payload(
        $payload,
        max(1, (int) ($config['oee_max_rows'] ?? 5000)),
        $maxBytes,
    );
    $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $expectedIdempotencyKey = $normalized['source'] . ':' . $normalized['report_date'] . ':' . $normalized['batch_id'];
    if ($idempotencyKey !== '' && !hash_equals($expectedIdempotencyKey, $idempotencyKey)) {
        json_response(['error' => 'Idempotency-Key does not match the report identity.'], 422);
    }
    $pdo = db();
    $hash = oee_payload_hash($payload);
    $pdo->beginTransaction();

    $existing = $pdo->prepare('SELECT id, row_count, status, payload_sha256, raw_payload FROM oee_import_batches WHERE source = ? AND batch_id = ?');
    $existing->execute([$normalized['source'], $normalized['batch_id']]);
    $existingBatch = $existing->fetch();
    if ($existingBatch) {
        if (!hash_equals((string) $existingBatch['payload_sha256'], $hash)) {
            $existingPayload = json_decode((string) $existingBatch['raw_payload'], true);
            if (!is_array($existingPayload) || !hash_equals(oee_payload_hash($existingPayload), $hash)) {
                $pdo->rollBack();
                json_response(['error' => 'batch_id was already used with different contents.'], 409);
            }
            $refreshHash = $pdo->prepare('UPDATE oee_import_batches SET payload_sha256 = ? WHERE id = ?');
            $refreshHash->execute([$hash, $existingBatch['id']]);
        }
        $calculation = oee_calculate_daily($pdo, $normalized['line_code'], $normalized['report_date']);
        $pdo->commit();
        json_response([
            'ok' => true,
            'batch_id' => $normalized['batch_id'],
            'import_id' => (int) $existingBatch['id'],
            'status' => $existingBatch['status'],
            'inserted' => 0,
            'updated' => 0,
            'rejected' => 0,
            'replayed' => true,
            'calculation' => [
                'oee' => $calculation['oee'],
                'availability' => $calculation['availability'],
                'performance' => $calculation['performance'],
                'quality' => $calculation['quality'],
            ],
        ]);
    }

    $insertBatch = $pdo->prepare(
        'INSERT INTO oee_import_batches (batch_id, source, line_code, report_date, schema_version, payload_sha256, row_count, raw_payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertBatch->execute([
        $normalized['batch_id'],
        $normalized['source'],
        $normalized['line_code'],
        $normalized['report_date'],
        $normalized['schema_version'],
        $hash,
        count($normalized['rows']),
        $rawBody,
    ]);
    $importId = (int) $pdo->lastInsertId();
    $insertRow = $pdo->prepare(
        'INSERT INTO oee_fb_runs (import_batch_id, line_code, report_date, si_no, seq, part_code, grade, report_colour, used_colour, loading_at, unloading_at, pcs, surface_area, total_surface_area, is_productive, raw_stage_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($normalized['rows'] as $row) {
        $insertRow->execute([
            $importId,
            $normalized['line_code'],
            $normalized['report_date'],
            $row['si_no'],
            $row['seq'],
            $row['part_code'],
            $row['grade'],
            $row['report_colour'],
            $row['used_colour'],
            oee_combine_report_datetime($normalized['report_date'], $row['loading_time']),
            oee_combine_report_datetime($normalized['report_date'], $row['unloading_time']),
            $row['pcs'],
            $row['surface_area'],
            $row['total_surface_area'],
            $row['pcs'] > 0 ? 1 : 0,
            json_encode($row['raw_stage_data'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
    $updateBatch = $pdo->prepare("UPDATE oee_import_batches SET status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $updateBatch->execute([$importId]);
    $calculation = oee_calculate_daily($pdo, $normalized['line_code'], $normalized['report_date']);
    $sync = $pdo->prepare(
        "INSERT INTO oee_sync_state (source, last_batch_id, last_report_date, last_received_at, last_status) VALUES (?, ?, ?, CURRENT_TIMESTAMP, 'ok') ON DUPLICATE KEY UPDATE last_batch_id = VALUES(last_batch_id), last_report_date = VALUES(last_report_date), last_received_at = CURRENT_TIMESTAMP, last_status = 'ok', last_error = NULL"
    );
    $sync->execute([$normalized['source'], $normalized['batch_id'], $normalized['report_date']]);
    $pdo->commit();
    json_response([
        'ok' => true,
        'batch_id' => $normalized['batch_id'],
        'import_id' => $importId,
        'status' => 'processed',
        'inserted' => count($normalized['rows']),
        'updated' => 0,
        'rejected' => 0,
        'calculation' => [
            'oee' => $calculation['oee'],
            'availability' => $calculation['availability'],
            'performance' => $calculation['performance'],
            'quality' => $calculation['quality'],
        ],
    ], 202);
} catch (InvalidArgumentException $exception) {
    json_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('OEE ingest failed: ' . $exception->getMessage());
    json_response(['error' => 'The report could not be imported.'], 500);
}
