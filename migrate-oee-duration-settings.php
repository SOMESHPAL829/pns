<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $sql = file_get_contents(__DIR__ . '/migrations/015_oee_daily_duration_settings.sql');
    if ($sql === false) throw new RuntimeException('OEE daily duration migration SQL not found.');
    db()->exec($sql);
    echo "OEE daily calculation-duration settings installed. Existing OEE data is unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'OEE duration settings migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
