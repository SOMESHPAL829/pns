<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';

start_app_session();
$token = trim((string) ($_GET['token'] ?? ''));
$isReset = $token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please reload and try again.';
    } elseif (($_POST['action'] ?? '') === 'request') {
        $email = normalize_email((string) ($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $pdo = db();
                $query = $pdo->prepare('SELECT id, email, reset_requested_at FROM portal_users WHERE email = ? AND is_active = 1');
                $query->execute([$email]);
                $user = $query->fetch();
                if ($user && (empty($user['reset_requested_at']) || strtotime($user['reset_requested_at']) < time() - 60)) {
                    $resetToken = generate_reset_token();
                    $update = $pdo->prepare(
                        'UPDATE portal_users SET reset_token_hash = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE), reset_requested_at = NOW() WHERE id = ?'
                    );
                    $update->execute([hash('sha256', $resetToken), $user['id']]);
                    $config = app_config();
                    $baseUrl = $config['app_base_url'] ?? '';
                    $from = $config['mail_from'] ?? '';
                    if ($baseUrl !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                        $link = $baseUrl . '/password.php?token=' . rawurlencode($resetToken);
                        $headers = "From: {$from}\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
                        $sent = mail($user['email'], 'Reset your App Portal password', "Use this one-time link within 30 minutes:\n\n{$link}\n", $headers);
                        if (!$sent) {
                            error_log('Password reset email could not be handed to the configured mail transport.');
                        }
                    } else {
                        error_log('Password reset email is not configured: APP_BASE_URL and APP_MAIL_FROM are required.');
                    }
                }
            } catch (Throwable $exception) {
                error_log('Password reset request failed: ' . $exception->getMessage());
            }
        }
        $message = 'If an active account matches that email, a reset link will be sent.';
    } elseif (($_POST['action'] ?? '') === 'reset') {
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $error = 'This reset link is invalid or has expired.';
        } elseif (!password_is_valid($password)) {
            $error = 'Use a password with at least 12 characters.';
        } elseif ($password !== (string) ($_POST['password_confirmation'] ?? '')) {
            $error = 'The passwords do not match.';
        } else {
            try {
                $pdo = db();
                $update = $pdo->prepare(
                    'UPDATE portal_users SET password_hash = ?, auth_version = auth_version + 1, reset_token_hash = NULL, reset_expires_at = NULL WHERE reset_token_hash = ? AND reset_expires_at > NOW() AND is_active = 1'
                );
                $update->execute([password_hash($password, PASSWORD_DEFAULT), hash('sha256', $token)]);
                if ($update->rowCount() === 1) {
                    header('Location: index.php?reset=success');
                    exit;
                }
            } catch (Throwable $exception) {
                error_log('Password update failed: ' . $exception->getMessage());
            }
            $error = 'This reset link is invalid or has expired.';
        }
        $isReset = true;
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isReset ? 'Set a new password' : 'Reset your password' ?> — App Portal</title>
  <link rel="stylesheet" href="css/portal.css">
</head>
<body>
  <main class="login-page">
    <section class="login-card" aria-labelledby="password-title">
      <header class="login-card-head">
        <div class="app-sidebar-logo" aria-hidden="true">K</div>
        <div>
          <div class="app-topbar-title" id="password-title"><?= $isReset ? 'Choose a new password' : 'Forgot password' ?></div>
          <div class="app-topbar-sub">App Portal account recovery</div>
        </div>
      </header>
      <form class="login-card-body" method="post" action="password.php<?= $isReset ? '?token=' . rawurlencode($token) : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= escape_html($csrf) ?>">
        <?php if ($message !== ''): ?><p class="auth-message" role="status"><?= escape_html($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="auth-message error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <?php if ($isReset): ?>
          <input type="hidden" name="action" value="reset">
          <input type="hidden" name="token" value="<?= escape_html($token) ?>">
          <div><label class="form-label" for="password">New password</label><input class="form-input" id="password" name="password" type="password" minlength="12" autocomplete="new-password" required></div>
          <div><label class="form-label" for="password-confirmation">Confirm new password</label><input class="form-input" id="password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></div>
          <button type="submit" class="login-submit">Update password</button>
        <?php else: ?>
          <input type="hidden" name="action" value="request">
          <p class="form-note">Enter the email address on your account. If it matches an active account, we’ll send a one-time link.</p>
          <div><label class="form-label" for="email">Email</label><input class="form-input" id="email" name="email" type="email" autocomplete="email" required></div>
          <button type="submit" class="login-submit">Send reset link</button>
        <?php endif; ?>
        <a class="auth-link" href="index.php">Back to sign in</a>
      </form>
    </section>
  </main>
</body>
</html>