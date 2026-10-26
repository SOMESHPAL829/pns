<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/db.php';
try {
    $pdo = db();
    if (!$pdo->query("SHOW COLUMNS FROM oee_downtime_events LIKE 'evidence_key'")->fetch()) {
        $sql = file_get_contents(__DIR__ . '/migrations/005_oee_gap_evidence.sql');
        if ($sql === false) throw new RuntimeException('Gap migration SQL not found.');
        $pdo->exec($sql);
    } elseif (!$pdo->query("SHOW INDEX FROM oee_downtime_events WHERE Key_name = 'oee_downtime_evidence_key'")->fetch()) {
        $pdo->exec('ALTER TABLE oee_downtime_events ADD UNIQUE KEY oee_downtime_evidence_key (line_code, evidence_key)');
    }
    echo "OEE gap evidence migration installed (operator records unchanged).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Gap migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
