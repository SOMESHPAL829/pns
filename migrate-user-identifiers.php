<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    foreach (['username', 'email'] as $column) {
        $duplicate = $pdo->query(
            "SELECT LOWER(TRIM($column)) FROM portal_users GROUP BY LOWER(TRIM($column)) HAVING COUNT(*) > 1 LIMIT 1"
        )->fetchColumn();
        if ($duplicate !== false) {
            throw new RuntimeException("Lowercase migration would create a duplicate $column. Resolve the conflicting accounts first.");
        }
    }
    $sql = file_get_contents(__DIR__ . '/migrations/019_lowercase_portal_user_identifiers.sql');
    if ($sql === false) throw new RuntimeException('Lowercase user identifier migration SQL not found.');
    $pdo->beginTransaction();
    $affected = $pdo->exec($sql);
    $pdo->commit();
    echo "Lowercase user identifiers installed. Updated " . (int) $affected . " account(s).\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Lowercase user identifier migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
