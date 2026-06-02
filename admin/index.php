<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
$boardCatalog = board_catalog();
$noticeCategories = notice_categories();
$availableBoards = accessible_boards($user);
$allNotices = all_notices();
$allUsers = all_users();
$today = today_ymd();
$formNotice = [
    'id' => '',
    'board_id' => array_key_first($availableBoards) ?: 'sace',
    'category' => '',
    'audience' => '',
    'title' => '',
    'date' => date('Y-m-d'),
    'visible_from' => date('Y-m-d'),
    'visible_until' => date('Y-m-d', strtotime('+30 days')),
    'text' => '',
    'tag' => '',
    'tags' => [],
    'pinned' => false,
    'updated_at' => '',
    'created_by' => (string) $user['username'],
    'created_by_name' => (string) $user['name'],
];
$editing = false;
$error = '';
$success = '';
$accountSuccess = '';
$uploadedAttachmentForCleanup = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_notice') {
            $noticeId = trim((string) ($_POST['notice_id'] ?? ''));
            $boardId = trim((string) ($_POST['board_id'] ?? ''));
            $existingNotice = $noticeId !== '' ? find_notice_by_id($allNotices, $noticeId) : null;
            $existingAttachment = normalize_attachment_record($existingNotice['attachment'] ?? null);
            $attachment = $existingAttachment;
            $removeAttachment = isset($_POST['remove_attachment']);

            if ($removeAttachment) {
                $attachment = null;
            }

            $uploadError = (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $attachment = store_uploaded_attachment($_FILES['attachment']);
                $uploadedAttachmentForCleanup = $attachment;
            }

            $payload = [
                'id' => $noticeId !== '' ? $noticeId : uniqid('notice_', true),
                'board_id' => $boardId,
                'category' => trim((string) ($_POST['category'] ?? '')),
                'audience' => trim((string) ($_POST['audience'] ?? '')),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'date' => trim((string) ($_POST['date'] ?? '')),
                'visible_from' => trim((string) ($_POST['visible_from'] ?? '')),
                'visible_until' => trim((string) ($_POST['visible_until'] ?? '')),
                'text' => trim((string) ($_POST['text'] ?? '')),
                'tags' => array_values(array_unique(array_filter(array_map(
                    static fn (string $tag): string => trim($tag),
                    explode(',', (string) ($_POST['tag'] ?? ''))
                )))),
                'created_by' => (string) $user['username'],
                'created_by_name' => (string) $user['name'],
                'pinned' => isset($_POST['pinned']),
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'attachment' => $attachment,
            ];
            $payload['tag'] = implode(', ', $payload['tags']);

            foreach (['board_id', 'category', 'audience', 'title', 'date', 'visible_from', 'visible_until', 'text'] as $field) {
                if ($payload[$field] === '') {
                    throw new RuntimeException('All notice fields are required.');
                }
            }

            if ($payload['visible_from'] > $payload['visible_until']) {
                throw new RuntimeException('Visibility end date must be on or after the visibility start date.');
            }

            if ($payload['tags'] === []) {
                throw new RuntimeException('Please provide at least one tag.');
            }

            if (!isset($boardCatalog[$boardId])) {
                throw new RuntimeException('Invalid board selected.');
            }

            if (!can_manage_board($user, $boardId)) {
                throw new RuntimeException('You do not have permission to manage that board.');
            }

            $originalUpdatedAt = trim((string) ($_POST['original_updated_at'] ?? ''));

            $attachmentToDeleteAfterSave = null;

            mutate_notices(function (array $notices) use ($payload, $user, $originalUpdatedAt, $existingAttachment, $attachment, &$attachmentToDeleteAfterSave): array {
                $updated = false;

                foreach ($notices as $index => $notice) {
                    if (($notice['id'] ?? '') !== $payload['id']) {
                        continue;
                    }

                    if (!can_edit_notice($user, $notice)) {
                        throw new RuntimeException('You can only edit notices that you created.');
                    }

                    $currentUpdatedAt = (string) ($notice['updated_at'] ?? '');
                    if ($originalUpdatedAt !== $currentUpdatedAt) {
                        throw new RuntimeException('This notice was updated by another admin. Refresh and review the latest version before saving again.');
                    }

                    $payload['created_at'] = (string) ($notice['created_at'] ?? '');
                    $payload['created_by'] = (string) ($notice['created_by'] ?? $user['username']);
                    $payload['created_by_name'] = (string) ($notice['created_by_name'] ?? $user['name']);
                    $currentAttachment = normalize_attachment_record($notice['attachment'] ?? null);
                    if ($attachment !== $currentAttachment) {
                        $attachmentToDeleteAfterSave = $currentAttachment;
                    }
                    $notices[$index] = $payload;
                    $updated = true;
                    break;
                }

                if (!$updated) {
                    $notices[] = $payload;
                }

                return $notices;
            });

            if ($attachmentToDeleteAfterSave !== null && $attachmentToDeleteAfterSave !== $attachment) {
                delete_attachment_file($attachmentToDeleteAfterSave);
            }

            header('Location: index.php?success=Notice saved');
            exit;
        }

        if ($action === 'update_account') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newUsername = trim((string) ($_POST['new_username'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($newUsername === '') {
                throw new RuntimeException('Username is required.');
            }

            $freshUsers = all_users();
            $currentRecord = find_user_by_username($freshUsers, (string) $user['username']);
            if ($currentRecord === null) {
                throw new RuntimeException('Unable to locate your account.');
            }

            if (!password_verify($currentPassword, (string) $currentRecord['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }

            if ($newPassword !== '' && $newPassword !== $confirmPassword) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            mutate_users(function (array $users) use ($user, $newUsername, $newPassword): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== ($user['username'] ?? '')) {
                        if (($record['username'] ?? '') === $newUsername) {
                            throw new RuntimeException('That username is already in use.');
                        }
                        continue;
                    }

                    $users[$index]['username'] = $newUsername;
                    if ($newPassword !== '') {
                        $users[$index]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    }
                    return $users;
                }

                throw new RuntimeException('Unable to update your account.');
            });

            update_notice_owner_references((string) $user['username'], $newUsername, (string) $user['name']);
            $refreshedUsers = all_users();
            $updatedRecord = find_user_by_username($refreshedUsers, $newUsername);
            if ($updatedRecord !== null) {
                login_user($updatedRecord);
            }

            header('Location: index.php?account_success=Account updated');
            exit;
        }

        if ($action === 'reset_program_chair_account') {
            if (($user['role'] ?? '') !== 'dean') {
                throw new RuntimeException('Only the Dean can reset program chair accounts.');
            }

            $targetUsername = trim((string) ($_POST['target_username'] ?? ''));
            $targetRecord = find_user_by_username($allUsers, $targetUsername);
            if ($targetRecord === null) {
                throw new RuntimeException('Program chair account not found.');
            }

            if (($targetRecord['role'] ?? '') !== 'program_chair') {
                throw new RuntimeException('Only program chair accounts can be reset here.');
            }

            $defaultUsername = (string) ($targetRecord['default_username'] ?? $targetRecord['username']);
            $defaultPasswordHash = (string) ($targetRecord['default_password_hash'] ?? $targetRecord['password_hash']);
            $targetName = (string) ($targetRecord['name'] ?? '');

            mutate_users(function (array $users) use ($targetUsername, $defaultUsername, $defaultPasswordHash): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') === $defaultUsername && ($record['username'] ?? '') !== $targetUsername) {
                        throw new RuntimeException('The default username is currently used by another account.');
                    }
                }

                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== $targetUsername) {
                        continue;
                    }

                    $users[$index]['username'] = $defaultUsername;
                    $users[$index]['password_hash'] = $defaultPasswordHash;
                    return $users;
                }

                throw new RuntimeException('Unable to reset the selected account.');
            });

            update_notice_owner_references($targetUsername, $defaultUsername, $targetName);
            header('Location: index.php?account_success=Program chair account reset');
            exit;
        }

        if ($action === 'delete_notice') {
            $noticeId = trim((string) ($_POST['notice_id'] ?? ''));

            $target = find_notice_by_id($allNotices, $noticeId);
            if ($target === null) {
                throw new RuntimeException('Notice not found.');
            }

            if (!can_edit_notice($user, $target)) {
                throw new RuntimeException('You can only delete notices that you created.');
            }

            $attachmentToDelete = normalize_attachment_record($target['attachment'] ?? null);

            mutate_notices(static function (array $notices) use ($noticeId, $user): array {
                foreach ($notices as $notice) {
                    if (($notice['id'] ?? '') === $noticeId && !can_edit_notice($user, $notice)) {
                        throw new RuntimeException('You can only delete notices that you created.');
                    }
                }

                return array_values(array_filter(
                    $notices,
                    static fn (array $notice): bool => ($notice['id'] ?? '') !== $noticeId
                ));
            });

            delete_attachment_file($attachmentToDelete);

            header('Location: index.php?success=Notice deleted');
            exit;
        }
    }
} catch (RuntimeException $exception) {
    if ($uploadedAttachmentForCleanup !== null) {
        delete_attachment_file($uploadedAttachmentForCleanup);
    }
    $error = $exception->getMessage();
}

if (isset($_GET['edit'])) {
    $editing = true;
    $notice = find_notice_by_id($allNotices, (string) $_GET['edit']);
    if ($notice !== null && can_edit_notice($user, $notice)) {
        $formNotice = $notice;
    } elseif ($notice !== null) {
        $error = 'You can only edit notices that you created.';
        $editing = false;
    }
}

$success = trim((string) ($_GET['success'] ?? ''));
$accountSuccess = trim((string) ($_GET['account_success'] ?? ''));

$visibleNotices = array_values(array_filter(
    $allNotices,
    static fn (array $notice): bool => can_edit_notice($user, $notice)
));

sort_notices($visibleNotices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard | NU LIPA SACE</title>
  <link rel="stylesheet" href="../styles.css?v=20260602-admin11">
</head>
<body class="admin-body">
  <main class="admin-shell">
    <section class="admin-topbar glass-panel">
      <div>
        <p class="eyebrow">Bulletin Admin</p>
        <h1>Welcome, <?= e((string) $user['name']) ?></h1>
        <p class="admin-intro">
          <?= $user['role'] === 'dean'
              ? 'Dean access: publish and manage official announcements and notices across all academic bulletin boards, including the NULIPA-SACE School board.'
              : 'Program chair access: publish notices to your assigned academic board and manage only the notices you personally created.' ?>
        </p>
      </div>
      <div class="admin-actions">
        <a class="secondary-link" href="../index.html">View Public Board</a>
        <a class="secondary-link" href="logout.php">Sign Out</a>
      </div>
    </section>

    <?php if ($error !== ''): ?>
      <p class="admin-alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <p class="admin-success"><?= e($success) ?></p>
    <?php endif; ?>

    <?php if ($accountSuccess !== ''): ?>
      <p class="admin-success"><?= e($accountSuccess) ?></p>
    <?php endif; ?>

    <section class="admin-grid">
      <article class="admin-editor glass-panel">
        <p class="eyebrow"><?= $editing ? 'Edit Notice' : 'Create Notice' ?></p>
        <h2><?= $editing ? 'Update official bulletin content' : 'Publish a new official bulletin notice' ?></h2>
        <form method="post" class="admin-form-stack" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_notice">
          <input type="hidden" name="notice_id" value="<?= e((string) $formNotice['id']) ?>">
          <input type="hidden" name="original_updated_at" value="<?= e((string) ($formNotice['updated_at'] ?? '')) ?>">

          <label class="admin-field">
            <span>Board</span>
            <select name="board_id" required>
              <?php foreach ($availableBoards as $board): ?>
                <option value="<?= e($board['id']) ?>" <?= $formNotice['board_id'] === $board['id'] ? 'selected' : '' ?>>
                  <?= e($board['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="admin-field">
            <span>Category</span>
            <select name="category" required>
              <option value="" disabled <?= $formNotice['category'] === '' ? 'selected' : '' ?>>Select a category</option>
              <?php foreach ($noticeCategories as $category): ?>
                <option value="<?= e($category) ?>" <?= $formNotice['category'] === $category ? 'selected' : '' ?>>
                  <?= e($category) ?>
                </option>
              <?php endforeach; ?>
              <?php if ($formNotice['category'] !== '' && !in_array($formNotice['category'], $noticeCategories, true)): ?>
                <option value="<?= e((string) $formNotice['category']) ?>" selected>
                  <?= e((string) $formNotice['category']) ?> (Legacy)
                </option>
              <?php endif; ?>
            </select>
          </label>

          <label class="admin-field">
            <span>Audience</span>
            <input type="text" name="audience" value="<?= e((string) $formNotice['audience']) ?>" required>
          </label>

          <label class="admin-field">
            <span>Title</span>
            <input type="text" name="title" value="<?= e((string) $formNotice['title']) ?>" required>
          </label>

          <label class="admin-field">
            <span>Date</span>
            <input type="date" name="date" value="<?= e((string) $formNotice['date']) ?>" required>
          </label>

          <label class="admin-field">
            <span>Visible From</span>
            <input type="date" name="visible_from" value="<?= e((string) $formNotice['visible_from']) ?>" required>
            <small class="admin-field-help">The notice becomes visible on this date.</small>
          </label>

          <label class="admin-field">
            <span>Visible Until</span>
            <input type="date" name="visible_until" value="<?= e((string) $formNotice['visible_until']) ?>" required>
            <small class="admin-field-help">The notice is automatically hidden after this date.</small>
          </label>

          <label class="admin-field">
            <span>Notice text</span>
            <textarea name="text" rows="6" required><?= e((string) $formNotice['text']) ?></textarea>
          </label>

          <label class="admin-field">
            <span>Attachment</span>
            <input type="file" name="attachment" accept=".pdf,image/png,image/jpeg,image/gif,image/webp">
            <small class="admin-field-help">Optional. Attach only one file per notice: one PDF or one image, up to 10 MB.</small>
          </label>

          <?php if (!empty($formNotice['attachment'])): ?>
            <div class="admin-attachment-box">
              <p class="admin-notice-meta">Current attachment: <a class="secondary-link secondary-link-inline" href="../<?= e((string) $formNotice['attachment']['path']) ?>" target="_blank" rel="noopener"><?= e((string) $formNotice['attachment']['name']) ?></a></p>
              <label class="admin-check">
                <input type="checkbox" name="remove_attachment" value="1">
                <span>Remove the current attachment when saving this notice</span>
              </label>
            </div>
          <?php endif; ?>

          <label class="admin-field">
            <span>Tags</span>
            <input type="text" name="tag" value="<?= e(implode(', ', $formNotice['tags'] ?? [])) ?>" placeholder="exam, faculty, students" required>
            <small class="admin-field-help">Separate tags with commas. Example: <code>examination, faculty, students</code></small>
          </label>

          <label class="admin-check">
            <input type="checkbox" name="pinned" value="1" <?= !empty($formNotice['pinned']) ? 'checked' : '' ?>>
            <span>Pin this notice so it appears before regular notices</span>
          </label>

          <div class="admin-actions">
            <button type="submit" class="install-btn admin-submit"><?= $editing ? 'Update Notice' : 'Publish Notice' ?></button>
            <?php if ($editing): ?>
              <a class="secondary-link" href="index.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <article class="admin-list glass-panel">
        <p class="eyebrow">Your Notices</p>
        <h2>Official bulletin notices you created</h2>
        <div class="admin-notice-list">
          <?php foreach ($visibleNotices as $notice): ?>
            <article class="admin-notice-item">
              <div class="admin-notice-head">
                <div>
                  <p class="admin-notice-board"><?= e($boardCatalog[$notice['board_id']]['name'] ?? $notice['board_id']) ?></p>
                  <h3><?= e((string) $notice['title']) ?></h3>
                </div>
                <p class="notice-date"><?= e((string) $notice['date']) ?></p>
              </div>
              <p class="admin-notice-meta"><?= e((string) $notice['category']) ?> | <?= e((string) $notice['audience']) ?> | <?= ucfirst(notice_scope_status($notice, $today)) ?><?= !empty($notice['pinned']) ? ' | Pinned' : '' ?></p>
              <p class="admin-notice-meta">Owner: <?= e((string) ($notice['created_by_name'] ?? '')) ?></p>
              <p class="admin-notice-meta">Visible: <?= e((string) $notice['visible_from']) ?> to <?= e((string) $notice['visible_until']) ?></p>
              <div class="admin-tag-row">
                <?php foreach (($notice['tags'] ?? []) as $tag): ?>
                  <span class="tag-chip"><?= e((string) $tag) ?></span>
                <?php endforeach; ?>
              </div>
              <p class="admin-notice-body"><?= nl2br(e((string) $notice['text'])) ?></p>
              <?php if (!empty($notice['attachment'])): ?>
                <p class="admin-notice-meta">Attachment: <a class="secondary-link secondary-link-inline" href="../<?= e((string) $notice['attachment']['path']) ?>" target="_blank" rel="noopener"><?= e((string) $notice['attachment']['name']) ?></a></p>
              <?php endif; ?>
              <div class="admin-actions">
                <a class="secondary-link" href="index.php?edit=<?= urlencode((string) $notice['id']) ?>">Edit</a>
                <form method="post" class="admin-inline-form" onsubmit="return confirm('Delete this notice?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_notice">
                  <input type="hidden" name="notice_id" value="<?= e((string) $notice['id']) ?>">
                  <button type="submit" class="admin-delete-btn">Delete</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </article>
    </section>

    <section class="admin-grid">
      <article class="admin-editor glass-panel">
        <p class="eyebrow">Account Settings</p>
        <h2>Update your username and password</h2>
        <form method="post" class="admin-form-stack">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_account">

          <label class="admin-field">
            <span>Username</span>
            <input type="text" name="new_username" value="<?= e((string) $user['username']) ?>" required>
          </label>

          <label class="admin-field">
            <span>Current Password</span>
            <input type="password" name="current_password" required>
          </label>

          <label class="admin-field">
            <span>New Password</span>
            <input type="password" name="new_password">
            <small class="admin-field-help">Leave blank if you only want to change the username.</small>
          </label>

          <label class="admin-field">
            <span>Confirm New Password</span>
            <input type="password" name="confirm_password">
          </label>

          <div class="admin-actions">
            <button type="submit" class="install-btn admin-submit">Update Account</button>
          </div>
        </form>
      </article>

      <?php if (($user['role'] ?? '') === 'dean'): ?>
        <article class="admin-list glass-panel">
          <p class="eyebrow">Dean Controls</p>
          <h2>Reset program chair accounts</h2>
          <div class="admin-notice-list">
            <?php foreach ($allUsers as $account): ?>
              <?php if (($account['role'] ?? '') !== 'program_chair'): ?>
                <?php continue; ?>
              <?php endif; ?>
              <article class="admin-notice-item">
                <div class="admin-notice-head">
                  <div>
                    <p class="admin-notice-board"><?= e((string) $account['name']) ?></p>
                    <h3><?= e((string) $account['username']) ?></h3>
                  </div>
                </div>
                <p class="admin-notice-meta">Reset username to <?= e((string) ($account['default_username'] ?? $account['username'])) ?></p>
                <p class="admin-notice-meta">Reset password to the current default program chair password.</p>
                <form method="post" class="admin-inline-form" onsubmit="return confirm('Reset this program chair account to its default username and password?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="reset_program_chair_account">
                  <input type="hidden" name="target_username" value="<?= e((string) $account['username']) ?>">
                  <button type="submit" class="admin-delete-btn">Reset Account</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
