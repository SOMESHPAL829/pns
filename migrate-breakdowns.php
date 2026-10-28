<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'oee_reason_codes'")->fetchColumn()) {
        throw new RuntimeException('Install the OEE reason master migration first.');
    }
    if (!$pdo->query("SHOW COLUMNS FROM oee_reason_codes LIKE 'loss_type'")->fetch()) {
        $pdo->exec("ALTER TABLE oee_reason_codes ADD COLUMN loss_type ENUM('planned','unplanned') NOT NULL DEFAULT 'unplanned' AFTER label");
        $pdo->exec("UPDATE oee_reason_codes SET loss_type='planned' WHERE code='PLANNED_STOP'");
    }
    $sql = file_get_contents(__DIR__ . '/migrations/011_breakdown_registration.sql');
    if ($sql === false) throw new RuntimeException('Breakdown registration migration SQL not found.');
    $pdo->exec($sql);
    if (!$pdo->query("SHOW COLUMNS FROM oee_reason_codes LIKE 'is_archived'")->fetch()) {
        $archiveSql = file_get_contents(__DIR__ . '/migrations/013_downtime_registration_archive.sql');
        if ($archiveSql === false) throw new RuntimeException('Downtime registration archive migration SQL not found.');
        $pdo->exec($archiveSql);
    }
    require_once __DIR__ . '/includes/breakdowns.php';
    breakdown_sync_default_catalog($pdo);
    echo "Downtime registration refreshed with the approved reason list. Referenced legacy reasons are archived; downtime history is unchanged.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Breakdown registration migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
