<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/db.php';
try {
    $sql = file_get_contents(__DIR__ . '/migrations/006_oee_line_settings.sql');
    if ($sql === false) throw new RuntimeException('Plant settings migration SQL not found.');
    db()->exec($sql);
    echo "OEE plant settings installed (existing settings and downtime unchanged).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Plant settings migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
