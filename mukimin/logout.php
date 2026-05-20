<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

unset($_SESSION['mukimin']);
set_flash('success', 'Anda sudah keluar dari portal mukimin.');
header('Location: /pwa_nailulmuna/mukimin/login.php');
exit;
