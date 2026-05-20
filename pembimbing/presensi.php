<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

header('Location: /presensi/scan.php');
exit;
