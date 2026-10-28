<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';

start_app_session();
if (!empty($_SESSION['user_id'])) {
    header('Location: app.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $attemptKey = hash('sha256', normalize_email($login) . '|' . $ip);

        try {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM portal_login_attempts WHERE locked_until IS NOT NULL AND locked_until <= NOW()')->execute();
            $pdo->prepare('UPDATE portal_login_attempts SET attempts = 0, window_started_at = NOW(), locked_until = NULL WHERE window_started_at < NOW() - INTERVAL 15 MINUTE')->execute();
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO portal_login_attempts (attempt_key, attempts, window_started_at) VALUES (?, 0, NOW())'
            );
            $insert->execute([$attemptKey]);
            $attemptQuery = $pdo->prepare('SELECT * FROM portal_login_attempts WHERE attempt_key = ? FOR UPDATE');
            $attemptQuery->execute([$attemptKey]);
            $attempt = $attemptQuery->fetch();
            $isLocked = !empty($attempt['locked_until']) && strtotime($attempt['locked_until']) > time();

            $normalizedLogin = normalize_email($login);
            $userQuery = $pdo->prepare(
              'SELECT id, username, email, full_name, password_hash, role, is_active, auth_version FROM portal_users WHERE email = ? LIMIT 1'
            );
            $userQuery->execute([$normalizedLogin]);
            $user = $userQuery->fetch();
            if (!$user) {
              $userQuery = $pdo->prepare(
                'SELECT id, username, email, full_name, password_hash, role, is_active, auth_version FROM portal_users WHERE username = ? LIMIT 1'
              );
              $userQuery->execute([$login]);
              $user = $userQuery->fetch();
            }
            $dummyHash = password_hash('not-a-real-user-password', PASSWORD_DEFAULT);
            $passwordMatches = password_verify($password, $user['password_hash'] ?? $dummyHash);

            if (!$isLocked && $user && (int) $user['is_active'] === 1 && $passwordMatches) {
                $pdo->prepare('DELETE FROM portal_login_attempts WHERE attempt_key = ?')->execute([$attemptKey]);
                $pdo->commit();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['auth_version'] = (int) $user['auth_version'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['welcome_message'] = 'Welcome, ' . $user['full_name'] . '!';
                header('Location: app.php');
                exit;
            }

            if ($isLocked) {
                $pdo->commit();
                $error = 'This account was temporarily locked after multiple failed sign-in attempts. Please try again shortly.';
            } else {
                $inWindow = empty($attempt['locked_until'])
                  && strtotime($attempt['window_started_at']) >= time() - 900;
                $attempts = $inWindow ? (int) $attempt['attempts'] + 1 : 1;
                $windowStarted = $inWindow ? $attempt['window_started_at'] : date('Y-m-d H:i:s');
                $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $update = $pdo->prepare(
                    'UPDATE portal_login_attempts SET attempts = ?, window_started_at = ?, locked_until = ? WHERE attempt_key = ?'
                );
                $update->execute([$attempts, $windowStarted, $lockedUntil, $attemptKey]);
                $pdo->commit();
                $error = 'The login details were not accepted. Check them or try again later.';
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Login failed due to a server error: ' . $exception->getMessage());
            $error = 'Sign-in is temporarily unavailable. Please try again shortly.';
        }
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KARAM Portal — Sign In</title>
  <link rel="stylesheet" href="css/portal.css?v=<?= filemtime(__DIR__ . '/css/portal.css') ?>">
</head>
<body>
  <main class="login-page">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-card-head">
        <div class="app-sidebar-logo" aria-hidden="true">K</div>
        <div>
          <div class="app-topbar-title" id="login-title">App Portal</div>
          <div class="app-topbar-sub">Sign In to Continue</div>
        </div>
      </header>
      <form class="login-card-body" method="post" action="index.php" data-loading-form>
        <input type="hidden" name="csrf_token" value="<?= escape_html($csrf) ?>">
        <?php if (($_GET['reset'] ?? '') === 'success'): ?>
          <p class="auth-message" role="status">Your password was updated. Sign in with the new password.</p>
        <?php endif; ?>
        <?php if (($_GET['logged_out'] ?? '') === '1'): ?>
          <p class="auth-message auth-message-transient" role="status">You Have Signed Out Successfully.</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <p class="auth-message error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <div>
          <label class="form-label" for="login">Email or Username</label>
          <input class="form-input" id="login" name="login" autocomplete="username" placeholder="Enter your email or username" required autofocus>
        </div>
        <div>
          <label class="form-label" for="password">Password</label>
          <input class="form-input" id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="login-submit">Sign In</button>
        <a class="auth-link" href="password.php">Forgot Your Password?</a>
      </form>
    </section>
  </main>
  <div id="page-transition" class="page-transition" role="status" aria-live="polite" hidden><div class="page-transition-dialog"><span class="loading-spinner" aria-hidden="true"></span><div class="page-transition-copy"><strong id="page-transition-message">Signing In…</strong><span id="page-transition-note">Verifying your account securely.</span></div></div></div>
  <script src="js/auth.js?v=<?= filemtime(__DIR__ . '/js/auth.js') ?>"></script>
</body>
</html>
