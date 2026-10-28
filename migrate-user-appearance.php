<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $sql = file_get_contents(__DIR__ . '/migrations/012_user_appearance_preferences.sql');
    if ($sql === false) throw new RuntimeException('User appearance migration SQL not found.');
    db()->exec($sql);
    echo "User appearance preferences installed. Existing user accounts are unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'User appearance migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
