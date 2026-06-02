<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    foreach (all_users() as $user) {
        if ($user['username'] !== $username) {
            continue;
        }

        if (password_verify($password, $user['password_hash'])) {
            login_user($user);
            header('Location: index.php');
            exit;
        }
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | NU LIPA SACE</title>
  <link rel="stylesheet" href="../styles.css?v=20260602-admin9">
</head>
<body class="admin-body">
  <main class="admin-shell admin-login-shell">
    <div class="admin-login-toplink">
      <a class="secondary-link" href="../index.html">Back to Home</a>
    </div>
    <section class="admin-login-card glass-panel">
      <p class="eyebrow">Bulletin Administrator Access</p>
      <h1>NU LIPA SACE Bulletin Board</h1>
      <p class="admin-intro">Authorized academic administrators may publish official announcements and notices for faculty and students. Program chairs manage their assigned board, while the Dean oversees all bulletin boards.</p>

      <?php if ($error !== ''): ?>
        <p class="admin-alert"><?= e($error) ?></p>
      <?php endif; ?>

      <form method="post" class="admin-form-stack">
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

      <div class="admin-login-help">
        <p><strong>Default accounts</strong></p>
        <p>The Dean account uses the `dean` username. Program chair usernames follow `chair.architecture`, `chair.computer-science`, `chair.information-technology`, `chair.engineering`, `chair.mma`, and `chair.cpe`.</p>
      </div>
    </section>
  </main>
</body>
</html>
