<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $sql = file_get_contents(__DIR__ . '/migrations/009_chemical_registration.sql');
    if ($sql === false) throw new RuntimeException('Chemical registration migration SQL not found.');
    db()->exec($sql);
    echo "Chemical registration tables installed. Existing portal and OEE data are unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Chemical registration migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
