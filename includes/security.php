<?php
declare(strict_types=1);

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function password_is_valid(string $password): bool
{
    return mb_strlen($password, 'UTF-8') >= 12 && strlen($password) <= 4096;
}

function generate_reset_token(): string
{
    return bin2hex(random_bytes(32));
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_app_session();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    start_app_session();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function current_user_id(): ?int
{
    start_app_session();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function require_authentication(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        http_response_code(401);
        header('Location: index.php');
        exit;
    }
    return $userId;
}

function require_admin(): void
{
    start_app_session();
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function escape_html(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}