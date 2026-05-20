<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_portal.php';

ensure_santri_portal_pin_column($pdo);

$santriPortalId = (int) ($_SESSION['santri_portal']['santri_id'] ?? 0);
if ($santriPortalId <= 0) {
    header('Location: /santri_portal/login.php');
    exit;
}

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$st = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan, kategori_kelas'
    . (column_exists($pdo, 'santri', 'is_aktif') ? ', is_aktif' : '')
    . ' FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $santriPortalId]);
$santriPortalRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$santriPortalRow) {
    unset($_SESSION['santri_portal']);
    header('Location: /santri_portal/login.php');
    exit;
}
if (column_exists($pdo, 'santri', 'is_aktif') && (int) ($santriPortalRow['is_aktif'] ?? 1) !== 1) {
    unset($_SESSION['santri_portal']);
    set_flash('error', 'Akses portal dinonaktifkan.');
    header('Location: /santri_portal/login.php');
    exit;
}
