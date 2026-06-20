<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_portal.php';
require_once __DIR__ . '/../helpers/santri_foto.php';

ensure_santri_portal_pin_column($pdo);
santri_foto_ensure_schema($pdo);

$santriPortalId = (int) ($_SESSION['santri_portal']['santri_id'] ?? 0);
if ($santriPortalId <= 0) {
    header('Location: ' . app_href('/santri_portal/login.php'));
    exit;
}

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$st = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan, kategori_kelas, jenis_kelamin, foto_profil'
    . (column_exists($pdo, 'santri', 'is_aktif') ? ', is_aktif' : '')
    . ' FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $santriPortalId]);
$santriPortalRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$santriPortalRow) {
    unset($_SESSION['santri_portal']);
    header('Location: ' . app_href('/santri_portal/login.php'));
    exit;
}
if (column_exists($pdo, 'santri', 'is_aktif') && (int) ($santriPortalRow['is_aktif'] ?? 1) !== 1) {
    unset($_SESSION['santri_portal']);
    set_flash('error', 'Akses portal dinonaktifkan.');
    header('Location: ' . app_href('/santri_portal/login.php'));
    exit;
}

require_once __DIR__ . '/../helpers/akademik_pkpps_tugas.php';
$santriPortalPkppsAktif = santri_portal_pkpps_aktif($pdo, $santriPortalId);
