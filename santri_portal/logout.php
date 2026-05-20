<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

unset($_SESSION['santri_portal']);
header('Location: /pwa_nailulmuna/santri_portal/login.php');
exit;
