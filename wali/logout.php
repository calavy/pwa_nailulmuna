<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

unset($_SESSION['wali']);
set_flash('success', 'Anda sudah keluar dari portal wali.');
header('Location: /wali/login.php');
exit;
