<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_alpa_santri.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

extract(rekap_alpa_santri_load($pdo, 'putri', $_GET), EXTR_SKIP);

require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/partials/rekap_alpa_santri_body.php';
require_once __DIR__ . '/../includes/footer.php';
