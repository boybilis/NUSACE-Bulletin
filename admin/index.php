<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user = current_user();
$boardCatalog = board_catalog();
$roleOptions = role_options();
$noticeCategories = notice_categories();
$canManageUsers = can_manage_users($user);
$canManageNoticeModule = can_manage_notice_module($user);
$canManageCalendarModule = can_manage_calendar_module($user);
$canManageNewsLinks = ($user['role'] ?? '') === 'admin';
$availableBoards = accessible_boards($user);
$availableCalendarBoards = accessible_calendar_boards($user);
$allNotices = all_notices();
$allManualCalendarEvents = all_manual_calendar_events();
$allUsers = all_users();
$allNewsLinks = $canManageNewsLinks ? all_news_links() : [];
$today = today_ymd();
$totpRequired = user_requires_totp($user);
$totpEnabled = user_has_totp_enabled($user);
$totpSetupSecret = current_totp_setup_secret($user);

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

$managedUserForm = [
    'original_username' => '',
    'username' => '',
    'name' => '',
    'role' => 'program_chair',
    'board_id' => '',
    'default_username' => '',
    'default_password' => '',
    'login_password' => '',
    'is_locked' => false,
];

$formCalendarEvent = [
    'id' => '',
    'board_id' => array_key_first($availableCalendarBoards) ?: 'sace',
    'title' => '',
    'event_date' => date('Y-m-d'),
    'start_time' => '08:00',
    'end_time' => '09:00',
    'updated_at' => '',
    'created_by' => (string) $user['username'],
    'created_by_name' => (string) $user['name'],
];

$newsLinkForm = [
    'id' => 0,
    'title' => '',
    'summary' => '',
    'facebook_url' => '',
    'image_url' => '',
];

$editing = false;
$editingCalendarEvent = false;
$editingManagedUser = false;
$editingNewsLink = false;
$error = '';
$success = '';
$accountSuccess = '';
$userSuccess = '';
$totpSuccess = '';
$uploadedAttachmentForCleanup = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($totpRequired && !$totpEnabled && !in_array($action, ['start_totp_setup', 'confirm_totp_setup', 'reset_own_totp', 'update_account'], true)) {
            throw new RuntimeException('Complete authenticator setup before using the admin dashboard.');
        }

        if ($action === 'start_totp_setup') {
            $totpSetupSecret = issue_totp_setup_secret($user);
            header('Location: index.php');
            exit;
        }

        if ($action === 'confirm_totp_setup') {
            $totpSetupSecret = current_totp_setup_secret($user);
            if ($totpSetupSecret === '') {
                throw new RuntimeException('Start 2FA setup first.');
            }

            $verificationCode = trim((string) ($_POST['totp_code'] ?? ''));
            if (!verify_totp_code($totpSetupSecret, $verificationCode)) {
                throw new RuntimeException('Invalid authenticator code. Please try again.');
            }

            mutate_users(function (array $users) use ($user, $totpSetupSecret): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== ($user['username'] ?? '')) {
                        continue;
                    }

                    $users[$index]['totp_secret'] = $totpSetupSecret;
                    $users[$index]['totp_enabled'] = true;
                    $users[$index]['totp_enabled_at'] = gmdate('c');
                    return $users;
                }

                throw new RuntimeException('Unable to update authenticator settings.');
            });

            clear_totp_setup_secret($user);
            $refreshedUsers = all_users();
            $updatedRecord = find_user_by_username($refreshedUsers, (string) $user['username']);
            if ($updatedRecord !== null) {
                login_user($updatedRecord);
                $user = current_user();
            }

            header('Location: index.php?totp_success=Authenticator+enabled');
            exit;
        }

        if ($action === 'save_notice') {
            if (!$canManageNoticeModule) {
                throw new RuntimeException('You do not have access to notice publishing.');
            }

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

            mutate_notices(function (array $notices) use ($payload, $user, $originalUpdatedAt, $attachment, &$attachmentToDeleteAfterSave): array {
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

        if ($action === 'save_calendar_event') {
            if (!$canManageCalendarModule) {
                throw new RuntimeException('You do not have access to calendar management.');
            }

            $eventId = trim((string) ($_POST['calendar_event_id'] ?? ''));
            $boardId = trim((string) ($_POST['board_id'] ?? ''));
            $payload = [
                'id' => $eventId !== '' ? $eventId : uniqid('calendar_', true),
                'board_id' => $boardId,
                'title' => trim((string) ($_POST['title'] ?? '')),
                'event_date' => trim((string) ($_POST['event_date'] ?? '')),
                'start_time' => substr(trim((string) ($_POST['start_time'] ?? '')), 0, 5),
                'end_time' => substr(trim((string) ($_POST['end_time'] ?? '')), 0, 5),
                'created_by' => (string) $user['username'],
                'created_by_name' => (string) $user['name'],
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];

            foreach (['board_id', 'title', 'event_date', 'start_time', 'end_time'] as $field) {
                if ($payload[$field] === '') {
                    throw new RuntimeException('Board, title, date, start time, and end time are required.');
                }
            }

            if (!isset($boardCatalog[$boardId])) {
                throw new RuntimeException('Invalid board selected.');
            }

            if (!can_manage_calendar_board($user, $boardId)) {
                throw new RuntimeException('You do not have permission to manage that board.');
            }

            if ($payload['start_time'] >= $payload['end_time']) {
                throw new RuntimeException('End time must be later than the start time.');
            }

            $originalUpdatedAt = trim((string) ($_POST['original_updated_at'] ?? ''));

            mutate_manual_calendar_events(function (array $events) use ($payload, $user, $originalUpdatedAt): array {
                $updated = false;

                foreach ($events as $index => $event) {
                    if (($event['id'] ?? '') !== $payload['id']) {
                        continue;
                    }

                    if (!can_edit_manual_calendar_event($user, $event)) {
                        throw new RuntimeException('You can only edit manual calendar entries that you created.');
                    }

                    $currentUpdatedAt = (string) ($event['updated_at'] ?? '');
                    if ($originalUpdatedAt !== $currentUpdatedAt) {
                        throw new RuntimeException('This calendar entry was updated by another admin. Refresh and try again.');
                    }

                    $payload['created_at'] = (string) ($event['created_at'] ?? '');
                    $payload['created_by'] = (string) ($event['created_by'] ?? $user['username']);
                    $payload['created_by_name'] = (string) ($event['created_by_name'] ?? $user['name']);
                    $events[$index] = $payload;
                    $updated = true;
                    break;
                }

                if (!$updated) {
                    $events[] = $payload;
                }

                return $events;
            });

            header('Location: index.php?success=Calendar entry saved');
            exit;
        }

        if ($action === 'save_news_link') {
            if (!$canManageNewsLinks) {
                throw new RuntimeException('Only admin users can manage news links.');
            }

            $newsId = max(0, (int) ($_POST['news_id'] ?? 0));
            $title = trim((string) ($_POST['news_title'] ?? ''));
            $summary = trim((string) ($_POST['news_summary'] ?? ''));
            $facebookUrl = trim((string) ($_POST['facebook_url'] ?? ''));
            $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
            $newsLinkForm = [
                'id' => $newsId,
                'title' => $title,
                'summary' => $summary,
                'facebook_url' => $facebookUrl,
                'image_url' => $imageUrl,
            ];
            $editingNewsLink = $newsId > 0;

            if ($title === '' || $summary === '' || $facebookUrl === '') {
                throw new RuntimeException('Title, description, and Facebook URL are required.');
            }

            if (strlen($title) > 255) {
                throw new RuntimeException('News title must be 255 characters or fewer.');
            }

            if (strlen($summary) > 1000) {
                throw new RuntimeException('News description must be 1,000 characters or fewer.');
            }

            $facebookParts = parse_url($facebookUrl);
            $facebookHost = strtolower((string) ($facebookParts['host'] ?? ''));
            $isFacebookHost = $facebookHost === 'facebook.com'
                || str_ends_with($facebookHost, '.facebook.com')
                || $facebookHost === 'fb.watch';
            if (
                filter_var($facebookUrl, FILTER_VALIDATE_URL) === false
                || strtolower((string) ($facebookParts['scheme'] ?? '')) !== 'https'
                || !$isFacebookHost
            ) {
                throw new RuntimeException('Enter a valid HTTPS Facebook or fb.watch URL.');
            }

            if ($imageUrl !== '') {
                $imageParts = parse_url($imageUrl);
                if (
                    filter_var($imageUrl, FILTER_VALIDATE_URL) === false
                    || strtolower((string) ($imageParts['scheme'] ?? '')) !== 'https'
                ) {
                    throw new RuntimeException('The optional image must use a valid HTTPS URL.');
                }
            }

            try {
                save_news_link([
                    'id' => $newsId,
                    'title' => $title,
                    'summary' => $summary,
                    'facebook_url' => $facebookUrl,
                    'image_url' => $imageUrl,
                    'created_by' => (string) $user['username'],
                ]);
            } catch (Throwable $exception) {
                if (is_missing_table_exception($exception, 'news_links')) {
                    throw new RuntimeException('Install the news links database upgrade before publishing news.');
                }

                throw $exception;
            }

            header('Location: index.php?success=' . urlencode($newsId > 0 ? 'News link updated' : 'News link published'));
            exit;
        }

        if ($action === 'delete_news_link') {
            if (!$canManageNewsLinks) {
                throw new RuntimeException('Only admin users can manage news links.');
            }

            $newsId = max(0, (int) ($_POST['news_id'] ?? 0));
            if ($newsId <= 0) {
                throw new RuntimeException('News link not found.');
            }

            try {
                delete_news_link($newsId);
            } catch (Throwable $exception) {
                if (is_missing_table_exception($exception, 'news_links')) {
                    throw new RuntimeException('Install the news links database upgrade before managing news.');
                }

                throw $exception;
            }
            header('Location: index.php?success=News+link+deleted');
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

        if ($action === 'reset_own_totp') {
            $currentPassword = (string) ($_POST['current_password_for_totp'] ?? '');

            $freshUsers = all_users();
            $currentRecord = find_user_by_username($freshUsers, (string) $user['username']);
            if ($currentRecord === null) {
                throw new RuntimeException('Unable to locate your account.');
            }

            if (!password_verify($currentPassword, (string) $currentRecord['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }

            mutate_users(function (array $users) use ($user): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== ($user['username'] ?? '')) {
                        continue;
                    }

                    $users[$index]['totp_secret'] = '';
                    $users[$index]['totp_enabled'] = false;
                    $users[$index]['totp_enabled_at'] = '';
                    return $users;
                }

                throw new RuntimeException('Unable to reset authenticator settings.');
            });

            clear_totp_setup_secret($user);
            $refreshedUsers = all_users();
            $updatedRecord = find_user_by_username($refreshedUsers, (string) $user['username']);
            if ($updatedRecord !== null) {
                login_user($updatedRecord);
                $user = current_user();
            }

            header('Location: index.php?totp_success=Authenticator+reset');
            exit;
        }

        if ($action === 'save_managed_user') {
            if (!$canManageUsers) {
                throw new RuntimeException('You do not have access to user management.');
            }

            $originalUsername = trim((string) ($_POST['original_username'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $role = trim((string) ($_POST['role'] ?? 'program_chair'));
            $boardId = trim((string) ($_POST['board_id'] ?? ''));
            $defaultUsername = trim((string) ($_POST['default_username'] ?? ''));
            $defaultPassword = (string) ($_POST['default_password'] ?? '');
            $loginPassword = (string) ($_POST['login_password'] ?? '');
            $isLocked = isset($_POST['is_locked']);
            $isCreating = $originalUsername === '';

            if ($username === '' || $name === '') {
                throw new RuntimeException('Username and name are required.');
            }

            if (!isset($roleOptions[$role])) {
                throw new RuntimeException('Please select a valid user role.');
            }

            if ($defaultUsername === '') {
                $defaultUsername = $username;
            }

            if (!$isCreating && $originalUsername === (string) $user['username']) {
                throw new RuntimeException('Use Account Settings to update your own account.');
            }

            $boardIds = board_ids_for_role($role, $boardId !== '' ? [$boardId] : []);
            if (in_array($role, ['program_chair', 'student_officer'], true) && $boardIds === []) {
                throw new RuntimeException('Program chair and student officer accounts must be assigned to a department.');
            }

            if ($isCreating && $defaultPassword === '') {
                throw new RuntimeException('Default password is required when creating a user.');
            }

            $renamedFromUsername = null;

            mutate_users(function (array $users) use (
                $originalUsername,
                $username,
                $name,
                $role,
                $defaultUsername,
                $defaultPassword,
                $loginPassword,
                $isLocked,
                $boardIds,
                $isCreating,
                &$renamedFromUsername
            ): array {
                foreach ($users as $record) {
                    $recordUsername = (string) ($record['username'] ?? '');
                    if ($recordUsername === $originalUsername) {
                        continue;
                    }

                    if ($recordUsername === $username) {
                        throw new RuntimeException('That username is already in use.');
                    }

                    if ($recordUsername === $defaultUsername) {
                        throw new RuntimeException('That default username is already in use.');
                    }
                }

                if ($isCreating) {
                    $users[] = [
                        'username' => $username,
                        'name' => $name,
                        'role' => $role,
                        'default_username' => $defaultUsername,
                        'default_password_hash' => password_hash($defaultPassword, PASSWORD_DEFAULT),
                        'password_hash' => password_hash($loginPassword !== '' ? $loginPassword : $defaultPassword, PASSWORD_DEFAULT),
                        'is_locked' => $isLocked,
                        'totp_secret' => '',
                        'totp_enabled' => false,
                        'totp_enabled_at' => '',
                        'board_ids' => $boardIds,
                    ];

                    return $users;
                }

                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== $originalUsername) {
                        continue;
                    }

                    $renamedFromUsername = $originalUsername !== $username ? $originalUsername : null;
                    $users[$index]['username'] = $username;
                    $users[$index]['name'] = $name;
                    $users[$index]['role'] = $role;
                    $users[$index]['default_username'] = $defaultUsername;
                    $users[$index]['is_locked'] = $isLocked;
                    $users[$index]['board_ids'] = $boardIds;

                    if ($defaultPassword !== '') {
                        $users[$index]['default_password_hash'] = password_hash($defaultPassword, PASSWORD_DEFAULT);
                    }

                    if ($loginPassword !== '') {
                        $users[$index]['password_hash'] = password_hash($loginPassword, PASSWORD_DEFAULT);
                    }

                    return $users;
                }

                throw new RuntimeException('Unable to locate the selected user.');
            });

            if ($renamedFromUsername !== null) {
                update_notice_owner_references($renamedFromUsername, $username, $name);
            } elseif (!$isCreating) {
                update_notice_owner_references($username, $username, $name);
            }

            header('Location: index.php?user_success=' . urlencode($isCreating ? 'User created' : 'User updated'));
            exit;
        }

        if ($action === 'reset_managed_user_account') {
            if (!$canManageUsers) {
                throw new RuntimeException('You do not have access to user management.');
            }

            $targetUsername = trim((string) ($_POST['target_username'] ?? ''));
            if ($targetUsername === '' || $targetUsername === (string) $user['username']) {
                throw new RuntimeException('You cannot reset your own account here.');
            }

            $targetRecord = find_user_by_username($allUsers, $targetUsername);
            if ($targetRecord === null) {
                throw new RuntimeException('User account not found.');
            }

            $defaultUsername = (string) ($targetRecord['default_username'] ?? $targetRecord['username']);
            $defaultPasswordHash = (string) ($targetRecord['default_password_hash'] ?? $targetRecord['password_hash']);
            $targetName = (string) ($targetRecord['name'] ?? '');

            mutate_users(function (array $users) use ($targetUsername, $defaultUsername, $defaultPasswordHash): array {
                foreach ($users as $record) {
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
                    $users[$index]['is_locked'] = false;
                    return $users;
                }

                throw new RuntimeException('Unable to reset the selected account.');
            });

            update_notice_owner_references($targetUsername, $defaultUsername, $targetName);
            header('Location: index.php?user_success=User account reset');
            exit;
        }

        if ($action === 'toggle_managed_user_lock') {
            if (!$canManageUsers) {
                throw new RuntimeException('You do not have access to user management.');
            }

            $targetUsername = trim((string) ($_POST['target_username'] ?? ''));
            if ($targetUsername === '' || $targetUsername === (string) $user['username']) {
                throw new RuntimeException('You cannot lock or unlock your own account here.');
            }

            mutate_users(function (array $users) use ($targetUsername): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== $targetUsername) {
                        continue;
                    }

                    $users[$index]['is_locked'] = !empty($record['is_locked']) ? false : true;
                    return $users;
                }

                throw new RuntimeException('User account not found.');
            });

            header('Location: index.php?user_success=User lock status updated');
            exit;
        }

        if ($action === 'reset_managed_user_totp') {
            if (!$canManageUsers) {
                throw new RuntimeException('You do not have access to user management.');
            }

            $targetUsername = trim((string) ($_POST['target_username'] ?? ''));
            if ($targetUsername === '' || $targetUsername === (string) $user['username']) {
                throw new RuntimeException('You cannot reset your own authenticator here.');
            }

            mutate_users(function (array $users) use ($targetUsername): array {
                foreach ($users as $index => $record) {
                    if (($record['username'] ?? '') !== $targetUsername) {
                        continue;
                    }

                    $users[$index]['totp_secret'] = '';
                    $users[$index]['totp_enabled'] = false;
                    $users[$index]['totp_enabled_at'] = '';
                    return $users;
                }

                throw new RuntimeException('User account not found.');
            });

            header('Location: index.php?user_success=User authenticator reset');
            exit;
        }

        if ($action === 'delete_notice') {
            if (!$canManageNoticeModule) {
                throw new RuntimeException('You do not have access to notice publishing.');
            }

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

        if ($action === 'delete_calendar_event') {
            if (!$canManageCalendarModule) {
                throw new RuntimeException('You do not have access to calendar management.');
            }

            $eventId = trim((string) ($_POST['calendar_event_id'] ?? ''));
            $target = find_manual_calendar_event_by_id($allManualCalendarEvents, $eventId);
            if ($target === null) {
                throw new RuntimeException('Calendar entry not found.');
            }

            if (!can_edit_manual_calendar_event($user, $target)) {
                throw new RuntimeException('You can only delete manual calendar entries that you created.');
            }

            mutate_manual_calendar_events(static function (array $events) use ($eventId, $user): array {
                foreach ($events as $event) {
                    if (($event['id'] ?? '') === $eventId && !can_edit_manual_calendar_event($user, $event)) {
                        throw new RuntimeException('You can only delete manual calendar entries that you created.');
                    }
                }

                return array_values(array_filter(
                    $events,
                    static fn (array $event): bool => ($event['id'] ?? '') !== $eventId
                ));
            });

            header('Location: index.php?success=Calendar entry deleted');
            exit;
        }
    }
} catch (RuntimeException $exception) {
    if ($uploadedAttachmentForCleanup !== null) {
        delete_attachment_file($uploadedAttachmentForCleanup);
    }
    $error = $exception->getMessage();
}

if ($canManageNoticeModule && isset($_GET['edit'])) {
    $editing = true;
    $notice = find_notice_by_id($allNotices, (string) $_GET['edit']);
    if ($notice !== null && can_edit_notice($user, $notice)) {
        $formNotice = $notice;
    } elseif ($notice !== null) {
        $error = 'You can only edit notices that you created.';
        $editing = false;
    }
}

if ($canManageCalendarModule && isset($_GET['calendar_edit'])) {
    $editingCalendarEvent = true;
    $calendarEvent = find_manual_calendar_event_by_id($allManualCalendarEvents, (string) $_GET['calendar_edit']);
    if ($calendarEvent !== null && can_edit_manual_calendar_event($user, $calendarEvent)) {
        $formCalendarEvent = $calendarEvent;
    } elseif ($calendarEvent !== null) {
        $error = 'You can only edit calendar entries that you created.';
        $editingCalendarEvent = false;
    }
}

if ($canManageUsers && isset($_GET['user_edit'])) {
    $editingManagedUser = true;
    $managedRecord = find_user_by_username($allUsers, trim((string) $_GET['user_edit']));
    if ($managedRecord !== null && (string) $managedRecord['username'] !== (string) $user['username']) {
        $managedUserForm = [
            'original_username' => (string) $managedRecord['username'],
            'username' => (string) $managedRecord['username'],
            'name' => (string) $managedRecord['name'],
            'role' => (string) $managedRecord['role'],
            'board_id' => primary_board_id_for_user($managedRecord),
            'default_username' => (string) ($managedRecord['default_username'] ?? $managedRecord['username']),
            'default_password' => '',
            'login_password' => '',
            'is_locked' => !empty($managedRecord['is_locked']),
        ];
    } else {
        $error = 'Unable to edit the selected user.';
        $editingManagedUser = false;
    }
}

if ($canManageNewsLinks && isset($_GET['news_edit'])) {
    $editingNewsLink = true;
    $newsLink = find_news_link_by_id($allNewsLinks, max(0, (int) $_GET['news_edit']));
    if ($newsLink !== null) {
        $newsLinkForm = $newsLink;
    } else {
        $error = 'Unable to edit the selected news link.';
        $editingNewsLink = false;
    }
}

$success = trim((string) ($_GET['success'] ?? ''));
$accountSuccess = trim((string) ($_GET['account_success'] ?? ''));
$userSuccess = trim((string) ($_GET['user_success'] ?? ''));
$totpSuccess = trim((string) ($_GET['totp_success'] ?? ''));
$totpEnabled = user_has_totp_enabled($user);
$totpSetupSecret = current_totp_setup_secret($user);

$visibleNotices = array_values(array_filter(
    $allNotices,
    static fn (array $notice): bool => can_edit_notice($user, $notice)
));
sort_notices($visibleNotices);

$managedUsers = $allUsers;
usort($managedUsers, static function (array $left, array $right): int {
    $roleOrder = ['dean' => 0, 'admin' => 1, 'program_chair' => 2, 'student_officer' => 2];
    $leftRole = $roleOrder[$left['role'] ?? 'program_chair'] ?? 99;
    $rightRole = $roleOrder[$right['role'] ?? 'program_chair'] ?? 99;

    if ($leftRole !== $rightRole) {
        return $leftRole <=> $rightRole;
    }

    return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
});

$deanFeedbackByBoard = [];
if (($user['role'] ?? '') === 'dean') {
    foreach ($boardCatalog as $boardId => $_board) {
        $feedback = feedback_for_board($boardId);
        sort_feedback_latest_first($feedback);
        $deanFeedbackByBoard[$boardId] = $feedback;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard | NU LIPA SACE</title>
  <link rel="stylesheet" href="../styles.css?v=20260730-password-toggle1">
  <script src="../password-toggle.js?v=20260730-password-toggle1" defer></script>
</head>
<body class="admin-body">
  <main class="admin-shell">
    <section class="admin-topbar glass-panel">
      <div>
        <p class="eyebrow">Bulletin Admin</p>
        <h1>Welcome, <?= e((string) $user['name']) ?></h1>
        <p class="admin-intro">
          <?php if (($user['role'] ?? '') === 'dean'): ?>
            Dean access: publish and manage official announcements across all bulletin boards, and manage administrator accounts.
          <?php elseif (($user['role'] ?? '') === 'admin'): ?>
            Admin access: manage user accounts, departments, resets, lock status, home-page news links, and database backups.
          <?php elseif (($user['role'] ?? '') === 'student_officer'): ?>
            Student officer access: publish notices and calendar entries for your assigned academic department, and manage only the content you personally created.
          <?php else: ?>
            Program chair access: publish notices to your assigned academic board and manage only the notices you personally created.
          <?php endif; ?>
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

    <?php if ($userSuccess !== ''): ?>
      <p class="admin-success"><?= e($userSuccess) ?></p>
    <?php endif; ?>

    <?php if ($totpSuccess !== ''): ?>
      <p class="admin-success"><?= e($totpSuccess) ?></p>
    <?php endif; ?>

    <?php if ($totpRequired && !$totpEnabled): ?>
      <p class="admin-alert">Authenticator setup is required before you can use the full dashboard.</p>
    <?php endif; ?>

    <?php if ($canManageNoticeModule && (!$totpRequired || $totpEnabled)): ?>
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
                <?php foreach ($availableCalendarBoards as $board): ?>
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
          <div class="admin-table-toolbar">
            <label class="admin-table-search">
              <span>Search</span>
              <input type="search" id="adminNoticeSearch" placeholder="Search title, board, category, audience, tags">
            </label>
          </div>
          <div class="admin-table-wrap">
            <table class="admin-data-table" id="adminNoticeTable">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Board</th>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="adminNoticeTableBody">
                <tr>
                  <td colspan="6" class="admin-table-empty">Loading notices...</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="admin-table-scrollhint">Swipe horizontally to see the full table on smaller screens.</p>
          <div class="admin-table-pagination" id="adminNoticePagination" hidden>
            <p class="admin-table-pageinfo" id="adminNoticePageInfo">Showing 0 to 0 of 0 notices</p>
            <div class="admin-table-pageactions">
              <button type="button" class="secondary-link admin-table-link" id="adminNoticePrev">Previous</button>
              <div class="admin-table-pages" id="adminNoticePages"></div>
              <button type="button" class="secondary-link admin-table-link" id="adminNoticeNext">Next</button>
            </div>
          </div>
        </article>
      </section>
    <?php endif; ?>

    <?php if ($canManageCalendarModule && (!$totpRequired || $totpEnabled)): ?>
      <section class="admin-grid">
        <article class="admin-editor glass-panel">
          <p class="eyebrow"><?= $editingCalendarEvent ? 'Edit Calendar Entry' : 'Create Calendar Entry' ?></p>
          <h2><?= $editingCalendarEvent ? 'Update a manual calendar card' : 'Add a manual calendar card for the public calendar' ?></h2>
          <form method="post" class="admin-form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_calendar_event">
            <input type="hidden" name="calendar_event_id" value="<?= e((string) $formCalendarEvent['id']) ?>">
            <input type="hidden" name="original_updated_at" value="<?= e((string) ($formCalendarEvent['updated_at'] ?? '')) ?>">

            <label class="admin-field">
              <span>Board</span>
              <select name="board_id" required>
                <?php foreach ($availableBoards as $board): ?>
                  <option value="<?= e($board['id']) ?>" <?= $formCalendarEvent['board_id'] === $board['id'] ? 'selected' : '' ?>>
                    <?= e($board['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="admin-field-help">Deans may post school-wide or departmental events. Program chairs and student officers may post only to their assigned board.</small>
            </label>

            <label class="admin-field">
              <span>Event Title</span>
              <input type="text" name="title" value="<?= e((string) $formCalendarEvent['title']) ?>" required>
            </label>

            <label class="admin-field">
              <span>Date</span>
              <input type="date" name="event_date" value="<?= e((string) $formCalendarEvent['event_date']) ?>" required>
            </label>

            <label class="admin-field">
              <span>Start Time</span>
              <input type="time" name="start_time" value="<?= e((string) $formCalendarEvent['start_time']) ?>" required>
            </label>

            <label class="admin-field">
              <span>End Time</span>
              <input type="time" name="end_time" value="<?= e((string) $formCalendarEvent['end_time']) ?>" required>
            </label>

            <div class="admin-actions">
              <button type="submit" class="install-btn admin-submit"><?= $editingCalendarEvent ? 'Update Calendar Entry' : 'Save Calendar Entry' ?></button>
              <?php if ($editingCalendarEvent): ?>
                <a class="secondary-link" href="index.php">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </article>

        <article class="admin-list glass-panel">
          <p class="eyebrow">Your Calendar Entries</p>
          <h2>Manual calendar entries you created</h2>
          <div class="admin-table-toolbar">
            <label class="admin-table-search">
              <span>Search Calendar Entries</span>
              <input type="search" id="adminCalendarSearch" placeholder="Search title, board, date, or time">
            </label>
          </div>
          <div class="admin-table-wrap admin-calendar-table-wrap">
            <table class="admin-data-table admin-responsive-data-table datatable" id="adminCalendarTable">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Time</th>
                  <th>Board</th>
                  <th>Title</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="adminCalendarTableBody">
                <tr>
                  <td colspan="5" class="admin-table-empty">Loading calendar entries...</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="admin-table-scrollhint admin-calendar-table-scrollhint">Search or scroll horizontally to review calendar entries and actions.</p>
          <div class="admin-table-pagination" id="adminCalendarPagination" hidden>
            <p class="admin-table-pageinfo" id="adminCalendarPageInfo">Showing 0 to 0 of 0 entries</p>
            <div class="admin-table-pageactions">
              <button type="button" class="secondary-link admin-table-link" id="adminCalendarPrev">Previous</button>
              <div class="admin-table-pages" id="adminCalendarPages"></div>
              <button type="button" class="secondary-link admin-table-link" id="adminCalendarNext">Next</button>
            </div>
          </div>
        </article>
      </section>
    <?php endif; ?>

    <?php if ($canManageNewsLinks && (!$totpRequired || $totpEnabled)): ?>
      <section class="admin-grid">
        <article class="admin-editor glass-panel">
          <p class="eyebrow"><?= $editingNewsLink ? 'Edit News Link' : 'Publish News Link' ?></p>
          <h2><?= $editingNewsLink ? 'Update the selected home-page news card' : 'Add a Facebook news link to the home page' ?></h2>
          <form method="post" class="admin-form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_news_link">
            <input type="hidden" name="news_id" value="<?= (int) ($newsLinkForm['id'] ?? 0) ?>">

            <label class="admin-field">
              <span>Title</span>
              <input type="text" name="news_title" maxlength="255" value="<?= e((string) ($newsLinkForm['title'] ?? '')) ?>" required>
            </label>

            <label class="admin-field">
              <span>Short Description</span>
              <textarea name="news_summary" rows="5" maxlength="1000" required><?= e((string) ($newsLinkForm['summary'] ?? '')) ?></textarea>
              <small class="admin-field-help">A concise summary displayed on the news card.</small>
            </label>

            <label class="admin-field">
              <span>Facebook URL</span>
              <input type="url" name="facebook_url" placeholder="https://web.facebook.com/share/p/..." value="<?= e((string) ($newsLinkForm['facebook_url'] ?? '')) ?>" required>
              <small class="admin-field-help">Use a secure facebook.com or fb.watch post URL.</small>
            </label>

            <label class="admin-field">
              <span>Image URL (Optional)</span>
              <input type="url" name="image_url" placeholder="https://..." value="<?= e((string) ($newsLinkForm['image_url'] ?? '')) ?>">
              <small class="admin-field-help">Use a direct HTTPS image URL. A Facebook placeholder is shown when left blank.</small>
            </label>

            <div class="admin-actions">
              <button type="submit" class="install-btn admin-submit"><?= $editingNewsLink ? 'Update News Link' : 'Publish News Link' ?></button>
              <?php if ($editingNewsLink): ?>
                <a class="secondary-link" href="index.php">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </article>

        <article class="admin-list glass-panel">
          <p class="eyebrow">Home Page News</p>
          <h2>Published Facebook news links</h2>
          <div class="admin-table-wrap">
            <table class="admin-data-table admin-responsive-data-table datatable">
              <thead>
                <tr>
                  <th>Published</th>
                  <th>Title</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($allNewsLinks === []): ?>
                  <tr>
                    <td colspan="3" class="admin-table-empty">No news links published yet.</td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($allNewsLinks as $newsLink): ?>
                  <tr>
                    <td data-label="Published"><?= e(format_datetime_label((string) ($newsLink['created_at'] ?? ''))) ?></td>
                    <td data-label="Title">
                      <div class="admin-table-title">
                        <strong><?= e((string) ($newsLink['title'] ?? '')) ?></strong>
                        <span><?= e((string) ($newsLink['facebook_url'] ?? '')) ?></span>
                      </div>
                    </td>
                    <td data-label="Actions">
                      <div class="admin-table-actions">
                        <a class="admin-action-badge is-view" href="<?= e((string) ($newsLink['facebook_url'] ?? '#')) ?>" target="_blank" rel="noopener">View</a>
                        <a class="admin-action-badge is-edit" href="index.php?news_edit=<?= (int) ($newsLink['id'] ?? 0) ?>">Edit</a>
                        <form method="post" class="admin-inline-form" onsubmit="return confirm('Delete this news link?');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="delete_news_link">
                          <input type="hidden" name="news_id" value="<?= (int) ($newsLink['id'] ?? 0) ?>">
                          <button type="submit" class="admin-action-badge is-delete">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="admin-table-scrollhint">News cards appear newest first on the public home-page carousel.</p>
        </article>
      </section>
    <?php endif; ?>

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

        <div class="admin-feedback-block" style="margin-top: 24px;">
          <p class="eyebrow">Authenticator 2FA</p>
          <?php if ($totpEnabled): ?>
            <p class="admin-notice-meta">Status: Enabled<?= !empty($user['totp_enabled_at']) ? ' | Activated ' . e(format_datetime_label((string) $user['totp_enabled_at'])) : '' ?></p>
            <form method="post" class="admin-form-stack" style="margin-top: 12px;">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reset_own_totp">
              <label class="admin-field">
                <span>Current Password</span>
                <input type="password" name="current_password_for_totp" required>
                <small class="admin-field-help">Reset your authenticator if you changed phones or need to pair a new device.</small>
              </label>
              <div class="admin-actions">
                <button type="submit" class="admin-delete-btn" onclick="return confirm('Reset your authenticator setup? You will need to enroll again.');">Reset Authenticator</button>
              </div>
            </form>
          <?php else: ?>
            <?php if ($totpSetupSecret === ''): ?>
              <p class="admin-notice-meta">No authenticator is configured yet.</p>
              <form method="post" class="admin-inline-form" style="margin-top: 12px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="start_totp_setup">
                <button type="submit" class="install-btn admin-submit">Start Authenticator Setup</button>
              </form>
            <?php else: ?>
              <p class="admin-notice-meta">Add this account in Google Authenticator or Microsoft Authenticator using the secret below, then verify one 6-digit code.</p>
              <label class="admin-field">
                <span>Secret Key</span>
                <input type="text" value="<?= e($totpSetupSecret) ?>" readonly>
              </label>
              <label class="admin-field">
                <span>Setup URI</span>
                <textarea rows="3" readonly><?= e(totp_provisioning_uri($user, $totpSetupSecret)) ?></textarea>
              </label>
              <form method="post" class="admin-form-stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm_totp_setup">
                <label class="admin-field">
                  <span>Authenticator Code</span>
                  <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                </label>
                <div class="admin-actions">
                  <button type="submit" class="install-btn admin-submit">Enable Authenticator</button>
                </div>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (($user['role'] ?? '') === 'admin' && (!$totpRequired || $totpEnabled)): ?>
          <div class="admin-feedback-block">
            <p class="eyebrow">Database Backup</p>
            <h2>Download a complete copy of the website data</h2>
            <p class="admin-notice-meta">Creates a timestamped SQL file containing the current database structure and records. Store the downloaded file securely because it includes administrator accounts and submitted feedback.</p>
            <form method="post" action="backup.php" class="admin-form-stack">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <div class="admin-actions">
                <button type="submit" class="install-btn admin-submit">Backup Data</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </article>

      <?php if ($canManageUsers && (!$totpRequired || $totpEnabled)): ?>
        <article class="admin-list glass-panel">
          <p class="eyebrow">User Management</p>
          <h2><?= $editingManagedUser ? 'Edit administrator account' : 'Create and manage administrator accounts' ?></h2>
          <form method="post" class="admin-form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_managed_user">
            <input type="hidden" name="original_username" value="<?= e((string) $managedUserForm['original_username']) ?>">

            <label class="admin-field">
              <span>Full Name</span>
              <input type="text" name="name" value="<?= e((string) $managedUserForm['name']) ?>" required>
            </label>

            <label class="admin-field">
              <span>Username</span>
              <input type="text" name="username" value="<?= e((string) $managedUserForm['username']) ?>" required>
            </label>

            <label class="admin-field">
              <span>Role</span>
              <select name="role" required>
                <?php foreach ($roleOptions as $roleValue => $roleLabel): ?>
                  <option value="<?= e($roleValue) ?>" <?= $managedUserForm['role'] === $roleValue ? 'selected' : '' ?>>
                    <?= e($roleLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="admin-field">
              <span>Department</span>
              <select name="board_id">
                <option value="">No department</option>
                <?php foreach ($boardCatalog as $board): ?>
                  <option value="<?= e($board['id']) ?>" <?= $managedUserForm['board_id'] === $board['id'] ? 'selected' : '' ?>>
                    <?= e($board['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="admin-field-help">Required for program chair and student officer accounts. Dean gets all departments. Admin gets none.</small>
            </label>

            <label class="admin-field">
              <span>Default Username</span>
              <input type="text" name="default_username" value="<?= e((string) $managedUserForm['default_username']) ?>" required>
              <small class="admin-field-help">This username is restored when the account is reset.</small>
            </label>

            <label class="admin-field">
              <span>Default Password <?= $editingManagedUser ? '(leave blank to keep current)' : '' ?></span>
              <input type="password" name="default_password" <?= $editingManagedUser ? '' : 'required' ?>>
            </label>

            <label class="admin-field">
              <span>Login Password <?= $editingManagedUser ? '(leave blank to keep current)' : '(optional; defaults to the default password)' ?></span>
              <input type="password" name="login_password">
            </label>

            <label class="admin-check">
              <input type="checkbox" name="is_locked" value="1" <?= !empty($managedUserForm['is_locked']) ? 'checked' : '' ?>>
              <span>Lock this user account</span>
            </label>

            <div class="admin-actions">
              <button type="submit" class="install-btn admin-submit"><?= $editingManagedUser ? 'Update User' : 'Create User' ?></button>
              <?php if ($editingManagedUser): ?>
                <a class="secondary-link" href="index.php">Cancel</a>
              <?php endif; ?>
            </div>
          </form>

          <div class="admin-table-toolbar">
            <label class="admin-table-search">
              <span>Search Users</span>
              <input type="search" id="adminUserSearch" placeholder="Search name, username, or department">
            </label>
          </div>
          <div class="admin-table-wrap admin-user-table-wrap">
            <table class="admin-data-table admin-user-data-table admin-responsive-data-table datatable" id="adminUserTable">
              <thead>
                <tr>
                  <th>User Name</th>
                  <th>Department</th>
                  <th>Authenticator</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="adminUserTableBody">
                <?php foreach ($managedUsers as $account): ?>
                  <?php
                    $accountBoardId = primary_board_id_for_user($account);
                    $accountDepartment = (string) ($boardCatalog[$accountBoardId]['name'] ?? ($accountBoardId !== '' ? $accountBoardId : 'None'));
                    $isCurrentAccount = (string) $account['username'] === (string) $user['username'];
                  ?>
                  <tr data-user-row data-user-search="<?= e(strtolower(implode(' ', [
                    (string) $account['name'],
                    (string) $account['username'],
                    $accountDepartment,
                  ]))) ?>">
                    <td data-label="User Name">
                      <div class="admin-table-title">
                        <strong><?= e((string) $account['name']) ?></strong>
                        <?php if ($isCurrentAccount): ?>
                          <span>Current account</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td data-label="Department"><?= e($accountDepartment) ?></td>
                    <td data-label="Authenticator"><?= !empty($account['totp_enabled']) ? 'Enabled' : 'Not set' ?></td>
                    <td data-label="Actions">
                      <div class="admin-table-actions">
                        <?php if (!$isCurrentAccount): ?>
                          <a class="admin-action-badge is-edit" href="index.php?user_edit=<?= urlencode((string) $account['username']) ?>">Edit</a>
                          <form method="post" class="admin-inline-form" onsubmit="return confirm('Reset this account to its default username and password?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reset_managed_user_account">
                            <input type="hidden" name="target_username" value="<?= e((string) $account['username']) ?>">
                            <button type="submit" class="admin-action-badge is-reset">Reset</button>
                          </form>
                          <form method="post" class="admin-inline-form" onsubmit="return confirm('Change this user lock status?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_managed_user_lock">
                            <input type="hidden" name="target_username" value="<?= e((string) $account['username']) ?>">
                            <button type="submit" class="admin-action-badge <?= !empty($account['is_locked']) ? 'is-unlock' : 'is-lock' ?>"><?= !empty($account['is_locked']) ? 'Unlock' : 'Lock' ?></button>
                          </form>
                          <form method="post" class="admin-inline-form" onsubmit="return confirm('Reset this user\\'s authenticator setup?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reset_managed_user_totp">
                            <input type="hidden" name="target_username" value="<?= e((string) $account['username']) ?>">
                            <button type="submit" class="admin-action-badge is-2fa">Reset 2FA</button>
                          </form>
                        <?php else: ?>
                          <span class="admin-notice-meta">Use Account Settings</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr id="adminUserEmptyRow" hidden>
                  <td colspan="4" class="admin-table-empty">No users match your search.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="admin-table-scrollhint admin-user-table-scrollhint">Search or scroll horizontally to review users and account actions.</p>
          <div class="admin-table-pagination" id="adminUserPagination" hidden>
            <p class="admin-table-pageinfo" id="adminUserPageInfo">Showing 0 to 0 of 0 users</p>
            <div class="admin-table-pageactions">
              <button type="button" class="secondary-link admin-table-link" id="adminUserPrev">Previous</button>
              <div class="admin-table-pages" id="adminUserPages"></div>
              <button type="button" class="secondary-link admin-table-link" id="adminUserNext">Next</button>
            </div>
          </div>

          <?php if (($user['role'] ?? '') === 'dean'): ?>
            <?php foreach ($managedUsers as $account): ?>
              <?php $accountBoardId = primary_board_id_for_user($account); ?>
              <?php if ($accountBoardId !== '' && isset($deanFeedbackByBoard[$accountBoardId])): ?>
                <div class="admin-feedback-block">
                  <p class="admin-notice-meta">Feedback for <?= e((string) ($boardCatalog[$accountBoardId]['name'] ?? $accountBoardId)) ?></p>
                  <?php if ($deanFeedbackByBoard[$accountBoardId] === []): ?>
                    <p class="admin-notice-meta">No feedback submitted yet.</p>
                  <?php else: ?>
                    <?php foreach ($deanFeedbackByBoard[$accountBoardId] as $feedback): ?>
                      <article class="admin-feedback-item">
                        <p class="admin-notice-meta"><?= e(feedback_type_label((string) $feedback['type'])) ?> | <?= e(format_datetime_label((string) $feedback['created_at'])) ?></p>
                        <p class="admin-notice-meta"><?= !empty($feedback['is_anonymous']) ? 'Anonymous sender' : 'Sender email: ' . e((string) $feedback['email']) ?></p>
                        <p class="admin-notice-body"><?= nl2br(e((string) $feedback['message'])) ?></p>
                      </article>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </article>
      <?php elseif (($user['role'] ?? '') === 'dean'): ?>
        <article class="admin-list glass-panel">
          <p class="eyebrow">Dean Controls</p>
          <h2>School-wide feedback</h2>
          <?php if (!empty($deanFeedbackByBoard['sace'])): ?>
            <div class="admin-feedback-block">
              <p class="admin-notice-board">NULIPA-SACE School-wide Feedback</p>
              <?php foreach ($deanFeedbackByBoard['sace'] as $feedback): ?>
                <article class="admin-feedback-item">
                  <p class="admin-notice-meta"><?= e(feedback_type_label((string) $feedback['type'])) ?> | <?= e(format_datetime_label((string) $feedback['created_at'])) ?></p>
                  <p class="admin-notice-meta"><?= !empty($feedback['is_anonymous']) ? 'Anonymous sender' : 'Sender email: ' . e((string) $feedback['email']) ?></p>
                  <p class="admin-notice-body"><?= nl2br(e((string) $feedback['message'])) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="admin-notice-meta">No school-wide feedback submitted yet.</p>
          <?php endif; ?>
        </article>
      <?php endif; ?>
    </section>

  </main>

  <div class="attachment-modal" id="adminNoticeModal" hidden>
    <div class="attachment-modal-backdrop" data-close-admin-notice-modal></div>
    <div class="attachment-modal-dialog glass-panel">
      <div class="attachment-modal-head">
        <div>
          <p class="eyebrow">Notice Preview</p>
          <h2 id="adminNoticeModalTitle">Notice</h2>
        </div>
        <button type="button" class="attachment-modal-close" data-close-admin-notice-modal aria-label="Close notice preview">×</button>
      </div>
      <div class="attachment-modal-body">
        <div class="admin-preview-body" id="adminNoticeModalContent"></div>
      </div>
    </div>
  </div>

  <script>
    (() => {
      const apiUrl = 'notices-api.php';
      const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
      const searchInput = document.getElementById('adminNoticeSearch');
      const tableBody = document.getElementById('adminNoticeTableBody');
      const paginationWrap = document.getElementById('adminNoticePagination');
      const pageInfo = document.getElementById('adminNoticePageInfo');
      const pageButtons = document.getElementById('adminNoticePages');
      const prevButton = document.getElementById('adminNoticePrev');
      const nextButton = document.getElementById('adminNoticeNext');
      const modal = document.getElementById('adminNoticeModal');
      const modalTitle = document.getElementById('adminNoticeModalTitle');
      const modalContent = document.getElementById('adminNoticeModalContent');
      let notices = [];
      let filteredNotices = [];
      let currentPage = 1;
      const pageSize = 10;

      function escapeHtml(value) {
        return String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#39;');
      }

      function formatTags(tags) {
        if (!Array.isArray(tags) || tags.length === 0) {
          return '<span class="admin-preview-muted">No tags</span>';
        }

        return tags.map((tag) => `<span class="tag-chip">${escapeHtml(tag)}</span>`).join('');
      }

      function renderEmptyRow(message) {
        tableBody.innerHTML = `<tr><td colspan="6" class="admin-table-empty">${escapeHtml(message)}</td></tr>`;
        if (paginationWrap) {
          paginationWrap.hidden = true;
        }
      }

      function openModal(notice) {
        if (!modal || !modalTitle || !modalContent) {
          return;
        }

        modalTitle.textContent = notice.title || 'Notice';
        modalContent.innerHTML = `
          <div class="admin-preview-stack">
            <p class="admin-notice-meta"><strong>Board:</strong> ${escapeHtml(notice.board_name || '')}</p>
            <p class="admin-notice-meta"><strong>Category:</strong> ${escapeHtml(notice.category || '')}</p>
            <p class="admin-notice-meta"><strong>Audience:</strong> ${escapeHtml(notice.audience || '')}</p>
            <p class="admin-notice-meta"><strong>Date:</strong> ${escapeHtml(notice.date || '')}</p>
            <p class="admin-notice-meta"><strong>Visible:</strong> ${escapeHtml(notice.visible_from || '')} to ${escapeHtml(notice.visible_until || '')}</p>
            <p class="admin-notice-meta"><strong>Status:</strong> ${escapeHtml(notice.status || '')}${notice.pinned ? ' | Pinned' : ''}</p>
            <div class="admin-tag-row">${formatTags(notice.tags || [])}</div>
            <div class="admin-preview-text">${escapeHtml(notice.text || '').replaceAll('\n', '<br>')}</div>
            ${notice.attachment && notice.attachment.path
              ? `<p class="admin-notice-meta"><strong>Attachment:</strong> <a class="secondary-link secondary-link-inline" href="../${escapeHtml(notice.attachment.path)}" target="_blank" rel="noopener">${escapeHtml(notice.attachment.name || 'Open attachment')}</a></p>`
              : ''}
          </div>
        `;
        modal.hidden = false;
        document.body.classList.add('modal-open');
      }

      function closeModal() {
        if (!modal) {
          return;
        }
        modal.hidden = true;
        document.body.classList.remove('modal-open');
      }

      function renderPagination(totalItems) {
        if (!paginationWrap || !pageInfo || !pageButtons || !prevButton || !nextButton) {
          return;
        }

        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const start = totalItems === 0 ? 0 : ((currentPage - 1) * pageSize) + 1;
        const end = Math.min(totalItems, currentPage * pageSize);

        pageInfo.textContent = `Showing ${start} to ${end} of ${totalItems} notice${totalItems === 1 ? '' : 's'}`;
        paginationWrap.hidden = totalItems <= pageSize;
        prevButton.disabled = currentPage <= 1;
        nextButton.disabled = currentPage >= totalPages;

        const pages = [];
        for (let page = 1; page <= totalPages; page += 1) {
          if (
            page === 1
            || page === totalPages
            || Math.abs(page - currentPage) <= 1
          ) {
            pages.push(page);
          } else if (pages[pages.length - 1] !== '…') {
            pages.push('…');
          }
        }

        pageButtons.innerHTML = pages.map((page) => {
          if (page === '…') {
            return '<span class="admin-table-ellipsis">…</span>';
          }

          return `<button type="button" class="admin-table-pagebtn${page === currentPage ? ' is-active' : ''}" data-page="${page}">${page}</button>`;
        }).join('');
      }

      function renderRows(items) {
        if (!Array.isArray(items) || items.length === 0) {
          renderEmptyRow('No notices match your search.');
          return;
        }

        const totalItems = items.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const pageStart = (currentPage - 1) * pageSize;
        const visibleItems = items.slice(pageStart, pageStart + pageSize);

        tableBody.innerHTML = visibleItems.map((notice) => `
          <tr>
            <td>${escapeHtml(notice.date || '')}</td>
            <td>${escapeHtml(notice.board_name || '')}</td>
            <td>
              <div class="admin-table-title">
                <strong>${escapeHtml(notice.title || '')}</strong>
                <span>${notice.pinned ? 'Pinned' : ''}</span>
              </div>
            </td>
            <td>${escapeHtml(notice.category || '')}</td>
            <td>${escapeHtml(notice.status || '')}</td>
            <td>
              <div class="admin-table-actions">
                <button type="button" class="admin-action-badge is-view" data-action="view" data-id="${escapeHtml(notice.id || '')}">View</button>
                <a class="admin-action-badge is-edit" href="${escapeHtml(notice.edit_url || '#')}">Edit</a>
                <button type="button" class="admin-action-badge is-delete" data-action="delete" data-id="${escapeHtml(notice.id || '')}">Delete</button>
              </div>
            </td>
          </tr>
        `).join('');

        renderPagination(totalItems);
      }

      function applyFilter() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        if (query === '') {
          filteredNotices = [...notices];
          currentPage = 1;
          renderRows(filteredNotices);
          return;
        }

        filteredNotices = notices.filter((notice) => {
          const haystack = [
            notice.title,
            notice.board_name,
            notice.category,
            notice.audience,
            notice.status,
            ...(Array.isArray(notice.tags) ? notice.tags : [])
          ].join(' ').toLowerCase();

          return haystack.includes(query);
        });

        currentPage = 1;
        renderRows(filteredNotices);
      }

      async function loadNotices() {
        renderEmptyRow('Loading notices...');

        try {
          const response = await fetch(apiUrl, {
            headers: {
              'Accept': 'application/json'
            }
          });
          const payload = await response.json();

          if (!response.ok) {
            throw new Error(payload.error || 'Unable to load notices.');
          }

          notices = Array.isArray(payload.notices) ? payload.notices : [];
          filteredNotices = [...notices];
          applyFilter();
        } catch (error) {
          renderEmptyRow(error instanceof Error ? error.message : 'Unable to load notices.');
        }
      }

      async function deleteNotice(noticeId) {
        if (!window.confirm('Delete this notice?')) {
          return;
        }

        const formData = new FormData();
        formData.set('csrf_token', csrfToken);
        formData.set('action', 'delete_notice');
        formData.set('notice_id', noticeId);

        try {
          const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
              'Accept': 'application/json'
            }
          });
          const payload = await response.json();

          if (!response.ok) {
            throw new Error(payload.error || 'Unable to delete notice.');
          }

          notices = notices.filter((notice) => notice.id !== noticeId);
          applyFilter();
        } catch (error) {
          window.alert(error instanceof Error ? error.message : 'Unable to delete notice.');
        }
      }

      tableBody?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }

        const action = target.dataset.action || '';
        const noticeId = target.dataset.id || '';
        if (noticeId === '') {
          return;
        }

        const notice = notices.find((item) => item.id === noticeId);
        if (!notice) {
          return;
        }

        if (action === 'view') {
          openModal(notice);
          return;
        }

        if (action === 'delete') {
          deleteNotice(noticeId);
        }
      });

      pageButtons?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }

        const page = Number.parseInt(target.dataset.page || '', 10);
        if (!Number.isInteger(page) || page < 1) {
          return;
        }

        currentPage = page;
        renderRows(filteredNotices);
      });

      prevButton?.addEventListener('click', () => {
        if (currentPage <= 1) {
          return;
        }
        currentPage -= 1;
        renderRows(filteredNotices);
      });

      nextButton?.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filteredNotices.length / pageSize));
        if (currentPage >= totalPages) {
          return;
        }
        currentPage += 1;
        renderRows(filteredNotices);
      });

      searchInput?.addEventListener('input', applyFilter);
      modal?.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLElement && target.hasAttribute('data-close-admin-notice-modal')) {
          closeModal();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeModal();
        }
      });

      loadNotices();
    })();

    (() => {
      const apiUrl = 'calendar-api.php';
      const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
      const searchInput = document.getElementById('adminCalendarSearch');
      const tableBody = document.getElementById('adminCalendarTableBody');
      const paginationWrap = document.getElementById('adminCalendarPagination');
      const pageInfo = document.getElementById('adminCalendarPageInfo');
      const pageButtons = document.getElementById('adminCalendarPages');
      const prevButton = document.getElementById('adminCalendarPrev');
      const nextButton = document.getElementById('adminCalendarNext');
      if (
        !searchInput
        || !tableBody
        || !paginationWrap
        || !pageInfo
        || !pageButtons
        || !prevButton
        || !nextButton
      ) {
        return;
      }

      let events = [];
      let filteredEvents = [];
      let currentPage = 1;
      const pageSize = 5;

      function escapeHtml(value) {
        return String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#39;');
      }

      function renderEmptyRow(message) {
        tableBody.innerHTML = `<tr><td colspan="5" class="admin-table-empty">${escapeHtml(message)}</td></tr>`;
        paginationWrap.hidden = true;
      }

      function renderPagination(totalItems) {
        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const start = totalItems === 0 ? 0 : ((currentPage - 1) * pageSize) + 1;
        const end = Math.min(totalItems, currentPage * pageSize);

        pageInfo.textContent = `Showing ${start} to ${end} of ${totalItems} entr${totalItems === 1 ? 'y' : 'ies'}`;
        paginationWrap.hidden = totalItems <= pageSize;
        prevButton.disabled = currentPage <= 1;
        nextButton.disabled = currentPage >= totalPages;
        pageButtons.innerHTML = Array.from({ length: totalPages }, (_, index) => index + 1)
          .map((page) => (
            `<button type="button" class="admin-table-pagebtn${page === currentPage ? ' is-active' : ''}" data-calendar-page="${page}">${page}</button>`
          ))
          .join('');
      }

      function renderRows(items) {
        if (!Array.isArray(items) || items.length === 0) {
          renderEmptyRow('No calendar entries match your search.');
          return;
        }

        const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const pageStart = (currentPage - 1) * pageSize;
        const visibleItems = items.slice(pageStart, pageStart + pageSize);

        tableBody.innerHTML = visibleItems.map((event) => `
          <tr>
            <td data-label="Date">${escapeHtml(event.event_date || '')}</td>
            <td data-label="Time">${escapeHtml(event.start_time || '')} to ${escapeHtml(event.end_time || '')}</td>
            <td data-label="Board">${escapeHtml(event.board_name || '')}</td>
            <td data-label="Title">
              <div class="admin-table-title">
                <strong>${escapeHtml(event.title || '')}</strong>
                <span>${escapeHtml(event.created_by_name || '')}</span>
              </div>
            </td>
            <td data-label="Actions">
              <div class="admin-table-actions">
                <a class="admin-action-badge is-edit" href="${escapeHtml(event.edit_url || '#')}">Edit</a>
                <button type="button" class="admin-action-badge is-delete" data-calendar-action="delete" data-calendar-id="${escapeHtml(event.id || '')}">Delete</button>
              </div>
            </td>
          </tr>
        `).join('');

        renderPagination(items.length);
      }

      function applyFilter() {
        const query = searchInput.value.trim().toLowerCase();
        filteredEvents = events.filter((event) => {
          const haystack = [
            event.title,
            event.board_name,
            event.event_date,
            event.start_time,
            event.end_time,
            event.created_by_name
          ].join(' ').toLowerCase();

          return query === '' || haystack.includes(query);
        });
        currentPage = 1;
        renderRows(filteredEvents);
      }

      async function loadEvents() {
        renderEmptyRow('Loading calendar entries...');

        try {
          const response = await fetch(apiUrl, {
            headers: {
              'Accept': 'application/json'
            }
          });
          const payload = await response.json();
          if (!response.ok) {
            throw new Error(payload.error || 'Unable to load calendar entries.');
          }

          events = Array.isArray(payload.events) ? payload.events : [];
          filteredEvents = [...events];
          applyFilter();
        } catch (error) {
          renderEmptyRow(error instanceof Error ? error.message : 'Unable to load calendar entries.');
        }
      }

      async function deleteEvent(eventId) {
        if (!window.confirm('Delete this calendar entry?')) {
          return;
        }

        const formData = new FormData();
        formData.set('csrf_token', csrfToken);
        formData.set('action', 'delete_calendar_event');
        formData.set('calendar_event_id', eventId);

        try {
          const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
              'Accept': 'application/json'
            }
          });
          const payload = await response.json();
          if (!response.ok) {
            throw new Error(payload.error || 'Unable to delete the calendar entry.');
          }

          events = events.filter((event) => event.id !== eventId);
          applyFilter();
        } catch (error) {
          window.alert(error instanceof Error ? error.message : 'Unable to delete the calendar entry.');
        }
      }

      tableBody.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || target.dataset.calendarAction !== 'delete') {
          return;
        }

        const eventId = target.dataset.calendarId || '';
        if (eventId !== '') {
          deleteEvent(eventId);
        }
      });

      pageButtons.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }

        const page = Number.parseInt(target.dataset.calendarPage || '', 10);
        if (!Number.isInteger(page) || page < 1) {
          return;
        }

        currentPage = page;
        renderRows(filteredEvents);
      });

      prevButton.addEventListener('click', () => {
        if (currentPage <= 1) {
          return;
        }
        currentPage -= 1;
        renderRows(filteredEvents);
      });

      nextButton.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filteredEvents.length / pageSize));
        if (currentPage >= totalPages) {
          return;
        }
        currentPage += 1;
        renderRows(filteredEvents);
      });

      searchInput.addEventListener('input', applyFilter);
      loadEvents();
    })();

    (() => {
      const searchInput = document.getElementById('adminUserSearch');
      const tableBody = document.getElementById('adminUserTableBody');
      const emptyRow = document.getElementById('adminUserEmptyRow');
      const paginationWrap = document.getElementById('adminUserPagination');
      const pageInfo = document.getElementById('adminUserPageInfo');
      const pageButtons = document.getElementById('adminUserPages');
      const prevButton = document.getElementById('adminUserPrev');
      const nextButton = document.getElementById('adminUserNext');
      if (
        !searchInput
        || !tableBody
        || !emptyRow
        || !paginationWrap
        || !pageInfo
        || !pageButtons
        || !prevButton
        || !nextButton
      ) {
        return;
      }

      const userRows = Array.from(tableBody.querySelectorAll('[data-user-row]'));
      const pageSize = 5;
      let currentPage = 1;
      let filteredRows = [...userRows];

      function renderPage() {
        const totalItems = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const pageStart = (currentPage - 1) * pageSize;
        const pageEnd = Math.min(totalItems, pageStart + pageSize);
        const visibleRows = new Set(filteredRows.slice(pageStart, pageEnd));

        userRows.forEach((row) => {
          row.hidden = !visibleRows.has(row);
        });

        emptyRow.hidden = totalItems !== 0;
        pageInfo.textContent = `Showing ${totalItems === 0 ? 0 : pageStart + 1} to ${pageEnd} of ${totalItems} user${totalItems === 1 ? '' : 's'}`;
        paginationWrap.hidden = totalItems <= pageSize;
        prevButton.disabled = currentPage <= 1;
        nextButton.disabled = currentPage >= totalPages;

        pageButtons.innerHTML = Array.from({ length: totalPages }, (_, index) => index + 1)
          .map((page) => (
            `<button type="button" class="admin-table-pagebtn${page === currentPage ? ' is-active' : ''}" data-user-page="${page}">${page}</button>`
          ))
          .join('');
      }

      function filterUsers() {
        const query = searchInput.value.trim().toLowerCase();
        filteredRows = userRows.filter((row) => {
          const searchableText = row.dataset.userSearch || (row.textContent || '').toLowerCase();
          return query === '' || searchableText.includes(query);
        });
        currentPage = 1;
        renderPage();
      }

      pageButtons.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }

        const page = Number.parseInt(target.dataset.userPage || '', 10);
        if (!Number.isInteger(page) || page < 1) {
          return;
        }

        currentPage = page;
        renderPage();
      });

      prevButton.addEventListener('click', () => {
        if (currentPage <= 1) {
          return;
        }
        currentPage -= 1;
        renderPage();
      });

      nextButton.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        if (currentPage >= totalPages) {
          return;
        }
        currentPage += 1;
        renderPage();
      });

      searchInput.addEventListener('input', filterUsers);
      renderPage();
    })();
  </script>
</body>
</html>
