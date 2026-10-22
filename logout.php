<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';

start_app_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php', true, 303);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: index.php', true, 303);
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: index.php?logged_out=1', true, 303);
exit;