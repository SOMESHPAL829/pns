<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc < 4) {
    fwrite(STDERR, "Usage: php setup.php <email> <username> <full-name>\n");
    exit(2);
}

$email = normalize_email((string) $argv[1]);
$username = trim((string) $argv[2]);
$fullName = trim(implode(' ', array_slice($argv, 3)));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username) || $fullName === '') {
    fwrite(STDERR, "Invalid email, username, or full name.\n");
    exit(2);
}

exec('stty -g 2>/dev/null', $terminalState, $terminalStatus);
$canHideInput = $terminalStatus === 0 && isset($terminalState[0]);
if ($canHideInput) {
    exec('stty -echo');
}
fwrite(STDOUT, 'Admin password (at least 12 characters): ');
$password = trim((string) fgets(STDIN));
if ($canHideInput) {
    exec('stty ' . escapeshellarg($terminalState[0]));
    fwrite(STDOUT, "\n");
}

if (!password_is_valid($password)) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(2);
}

try {
    $pdo = db();
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    foreach (explode(';', (string) $schema) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM portal_users')->fetchColumn();
    if ($count !== 0) {
        fwrite(STDERR, "Portal tables already contain users; refusing to create a second bootstrap admin.\n");
        exit(1);
    }

    $insert = $pdo->prepare(
        'INSERT INTO portal_users (username, email, full_name, password_hash, role) VALUES (?, ?, ?, ?, \'admin\')'
    );
    $insert->execute([$username, $email, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Portal schema installed and the initial admin account was created.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Setup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}