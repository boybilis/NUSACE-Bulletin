<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
$boardCatalog = board_catalog();
$noticeCategories = notice_categories();
$availableBoards = accessible_boards($user);
$allNotices = all_notices();
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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_notice') {
            $noticeId = trim((string) ($_POST['notice_id'] ?? ''));
            $boardId = trim((string) ($_POST['board_id'] ?? ''));
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

            mutate_notices(function (array $notices) use ($payload, $user, $originalUpdatedAt): array {
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
                    $notices[$index] = $payload;
                    $updated = true;
                    break;
                }

                if (!$updated) {
                    $notices[] = $payload;
                }

                return $notices;
            });

            header('Location: /NUSACE-Bulletin/admin/index.php?success=Notice saved');
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

            header('Location: /NUSACE-Bulletin/admin/index.php?success=Notice deleted');
            exit;
        }
    }
} catch (RuntimeException $exception) {
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
  <link rel="stylesheet" href="/NUSACE-Bulletin/styles.css?v=20260602-admin6">
</head>
<body class="admin-body">
  <main class="admin-shell">
    <section class="admin-topbar glass-panel">
      <div>
        <p class="eyebrow">Bulletin Admin</p>
        <h1>Welcome, <?= e((string) $user['name']) ?></h1>
        <p class="admin-intro">
          <?= $user['role'] === 'dean'
              ? 'Dean access: publish and manage official announcements and notices across all academic bulletin boards, including the NULIPA-SACE umbrella board.'
              : 'Program chair access: publish notices to your assigned academic board and manage only the notices you personally created.' ?>
        </p>
      </div>
      <div class="admin-actions">
        <a class="secondary-link" href="/NUSACE-Bulletin/index.html">View Public Board</a>
        <a class="secondary-link" href="/NUSACE-Bulletin/admin/logout.php">Sign Out</a>
      </div>
    </section>

    <?php if ($error !== ''): ?>
      <p class="admin-alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <p class="admin-success"><?= e($success) ?></p>
    <?php endif; ?>

    <section class="admin-grid">
      <article class="admin-editor glass-panel">
        <p class="eyebrow"><?= $editing ? 'Edit Notice' : 'Create Notice' ?></p>
        <h2><?= $editing ? 'Update official bulletin content' : 'Publish a new official bulletin notice' ?></h2>
        <form method="post" class="admin-form-stack">
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
              <a class="secondary-link" href="/NUSACE-Bulletin/admin/index.php">Cancel</a>
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
              <div class="admin-actions">
                <a class="secondary-link" href="/NUSACE-Bulletin/admin/index.php?edit=<?= urlencode((string) $notice['id']) ?>">Edit</a>
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
  </main>
</body>
</html>
