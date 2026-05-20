<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wali_portal.php';

ensure_santri_identity_columns($pdo);

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$waliSantriIdTentative = (int) ($_SESSION['wali']['santri_id'] ?? 0);
if ($waliSantriIdTentative <= 0) {
    header('Location: /pwa_nailulmuna/wali/login.php');
    exit;
}

$waliGroupId = (int) ($_SESSION['wali']['wali_santri_id'] ?? 0);

/** Hanya path di bawah portal wali. */
function wali_portal_safe_redirect_path(string $raw): string
{
    $r = trim($raw);
    if ($r !== '' && str_starts_with($r, '/pwa_nailulmuna/wali/') && !str_contains($r, "\r") && !str_contains($r, "\n")) {
        return $r;
    }

    return '/pwa_nailulmuna/wali/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wali_pilih_anak'])) {
    $sid = (int) ($_POST['santri_id'] ?? 0);
    $redirect = wali_portal_safe_redirect_path((string) ($_POST['redirect'] ?? ''));
    $ok = false;
    if ($sid > 0) {
        if ($waliGroupId > 0 && column_exists($pdo, 'santri', 'wali_santri_id')) {
            $c = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_santri FROM santri WHERE id = :id AND wali_santri_id = :w AND COALESCE(is_aktif,1)=1 LIMIT 1');
            $c->execute(['id' => $sid, 'w' => $waliGroupId]);
            $rw = $c->fetch(PDO::FETCH_ASSOC);
            if ($rw) {
                $ok = true;
                $_SESSION['wali']['santri_id'] = (int) $rw['id'];
                $_SESSION['wali']['nis'] = (string) ($rw['nis'] ?? '');
                $_SESSION['wali']['nama_santri'] = (string) ($rw['nama_santri'] ?? '');
            }
        }
        if (!$ok && $sid === $waliSantriIdTentative) {
            $ok = true;
        }
    }
    if ($ok) {
        set_flash('success', 'Menampilkan data anak yang dipilih.');
    } else {
        set_flash('error', 'Data santri tidak dapat diakses dari akun wali Anda.');
    }
    header('Location: ' . $redirect);
    exit;
}

$waliSantriId = (int) ($_SESSION['wali']['santri_id'] ?? 0);
if ($waliSantriId <= 0) {
    header('Location: /pwa_nailulmuna/wali/login.php');
    exit;
}

$cols = 'id, nis, ' . $nameCol . ' AS nama_tampil, tingkatan, kategori_kelas, no_wa_wali';
if (column_exists($pdo, 'santri', 'nama_kafil')) {
    $cols .= ', nama_kafil';
}
if (column_exists($pdo, 'santri', 'wali_santri_id')) {
    $cols .= ', wali_santri_id';
}
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $cols .= ', is_aktif';
}
$st = $pdo->prepare('SELECT ' . $cols . ' FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $waliSantriId]);
$waliSantriRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$waliSantriRow) {
    unset($_SESSION['wali']);
    header('Location: /pwa_nailulmuna/wali/login.php');
    exit;
}
if (column_exists($pdo, 'santri', 'is_aktif') && (int) ($waliSantriRow['is_aktif'] ?? 1) !== 1) {
    unset($_SESSION['wali']);
    set_flash('error', 'Akses portal dinonaktifkan untuk santri ini.');
    header('Location: /pwa_nailulmuna/wali/login.php');
    exit;
}

$waliKelasKategori = trim((string) ($waliSantriRow['kategori_kelas'] ?? ''));
if ($waliKelasKategori === '' && !empty($waliSantriRow['tingkatan'])) {
    $waliKelasKategori = (string) $waliSantriRow['tingkatan'];
}

$_SESSION['wali']['wali_santri_id'] = (int) ($waliSantriRow['wali_santri_id'] ?? 0);
$waliGroupId = (int) ($_SESSION['wali']['wali_santri_id'] ?? 0);

/** Daftar santri yang boleh dilihat wali: hanya anak dengan wali_santri_id sama, atau hanya anak login jika belum tertaut grup. */
$waliAnakRows = [];
if ($waliGroupId > 0 && column_exists($pdo, 'santri', 'wali_santri_id')) {
    $sq = 'SELECT id, nis, ' . $nameCol . ' AS nama_tampil, tingkatan FROM santri WHERE wali_santri_id = :w AND COALESCE(is_aktif,1)=1 ORDER BY ' . $nameCol . ' ASC';
    $wa = $pdo->prepare($sq);
    $wa->execute(['w' => $waliGroupId]);
    $waliAnakRows = $wa->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $waliAnakRows = [[
        'id' => (int) $waliSantriRow['id'],
        'nis' => (string) ($waliSantriRow['nis'] ?? ''),
        'nama_tampil' => (string) ($waliSantriRow['nama_tampil'] ?? ''),
        'tingkatan' => (string) ($waliSantriRow['tingkatan'] ?? ''),
    ]];
}

$waliAnakIds = array_map(static fn(array $r): int => (int) $r['id'], $waliAnakRows);
if (!in_array($waliSantriId, $waliAnakIds, true)) {
    unset($_SESSION['wali']);
    set_flash('error', 'Sesi tidak valid. Silakan masuk kembali.');
    header('Location: /pwa_nailulmuna/wali/login.php');
    exit;
}

$waliPortalGreeting = wali_portal_build_greeting($pdo, $waliSantriRow);
