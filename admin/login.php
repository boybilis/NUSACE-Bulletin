<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$pendingUser = pending_totp_user();
$totpStep = $pendingUser !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'password_login'));

    if ($action === 'cancel_totp') {
        clear_pending_totp_login();
        header('Location: login.php');
        exit;
    }

    if ($action === 'verify_totp') {
        $pendingUser = pending_totp_user();
        $totpStep = $pendingUser !== null;

        if ($pendingUser === null) {
            $error = 'Your login session expired. Please sign in again.';
        } else {
            $code = trim((string) ($_POST['totp_code'] ?? ''));
            if (!user_has_totp_enabled($pendingUser)) {
                clear_pending_totp_login();
                login_user($pendingUser);
                header('Location: index.php');
                exit;
            }

            if (!verify_totp_code((string) $pendingUser['totp_secret'], $code)) {
                $error = 'Invalid authenticator code.';
            } else {
                clear_pending_totp_login();
                login_user($pendingUser);
                header('Location: index.php');
                exit;
            }
        }
    }

    if ($action === 'password_login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        foreach (all_users() as $candidate) {
            if (($candidate['username'] ?? '') !== $username) {
                continue;
            }

            if (!empty($candidate['is_locked'])) {
                $error = 'This account is locked. Please contact the dean or user administrator.';
                break;
            }

            if (!password_verify($password, (string) $candidate['password_hash'])) {
                break;
            }

            if (user_requires_totp($candidate) && user_has_totp_enabled($candidate)) {
                begin_pending_totp_login($candidate);
                header('Location: login.php?step=totp');
                exit;
            }

            login_user($candidate);
            header('Location: index.php');
            exit;
        }

        if ($error === '') {
            $error = 'Invalid username or password.';
        }
    }
}

$pendingUser = pending_totp_user();
$totpStep = $pendingUser !== null || (($_GET['step'] ?? '') === 'totp');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | NU LIPA SACE</title>
  <link rel="stylesheet" href="../styles.css?v=20260730-password-toggle1">
  <script src="../password-toggle.js?v=20260730-password-toggle1" defer></script>
</head>
<body class="admin-body">
  <main class="admin-shell admin-login-shell">
    <div class="admin-login-toplink">
      <a class="secondary-link" href="../index.html">Back to Home</a>
    </div>
    <section class="admin-login-card glass-panel">
      <p class="eyebrow">Bulletin Administrator Access</p>
      <h1>NU Lipa SACE Bulletin Board</h1>
      <p class="admin-intro">Authorized academic administrators may publish official announcements and notices for faculty and students. Program chairs and student officers manage their assigned board, the Dean oversees bulletin boards, and admin users manage user accounts.</p>

      <?php if ($error !== ''): ?>
        <p class="admin-alert"><?= e($error) ?></p>
      <?php endif; ?>

      <?php if ($totpStep): ?>
        <form method="post" class="admin-form-stack">
          <input type="hidden" name="action" value="verify_totp">
          <label class="admin-field">
            <span>Authenticator Code</span>
            <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
            <small class="admin-field-help">Enter the 6-digit code from Google Authenticator or Microsoft Authenticator.</small>
          </label>
          <div class="admin-actions">
            <button type="submit" class="install-btn admin-submit">Verify and Sign In</button>
          </div>
        </form>

        <form method="post" class="admin-inline-form" style="margin-top: 12px;">
          <input type="hidden" name="action" value="cancel_totp">
          <button type="submit" class="secondary-link" style="border: 0; background: transparent; cursor: pointer;">Back to Login</button>
        </form>
      <?php else: ?>
        <form method="post" class="admin-form-stack">
          <input type="hidden" name="action" value="password_login">
          <label class="admin-field">
            <span>Username</span>
            <input type="text" name="username" required>
          </label>
          <label class="admin-field">
            <span>Password</span>
            <input type="password" name="password" required>
          </label>
          <button type="submit" class="install-btn admin-submit">Sign In</button>
        </form>
      <?php endif; ?>

      <div class="admin-login-help">
        <p><strong>2FA</strong></p>
        <p>Google Authenticator and Microsoft Authenticator are supported through standard TOTP setup.</p>
      </div>
    </section>
  </main>
</body>
</html>
