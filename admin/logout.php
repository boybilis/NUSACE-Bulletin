<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

logout_user();

header('Location: /NUSACE-Bulletin/admin/login.php');
exit;
