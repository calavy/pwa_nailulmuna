<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';

set_flash('info', 'Portal Koperasi diganti akun Petugas Koperasi. Login di halaman utama dengan username & password dari Kelola user.');
app_redirect('login.php');
