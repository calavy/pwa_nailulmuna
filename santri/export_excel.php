<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);

$onlyAktif = !isset($_GET['semua']) || (string) ($_GET['semua'] ?? '') !== '1';

$sql = '
    SELECT id, qr, nis, nama_santri, nik, jenis_kelamin, tingkatan, kategori_kelas, no_wa_wali, is_aktif,
        tempat_lahir_kab, tanggal_lahir, tahun_lahir,
        nama_kafil, status_kafil, pekerjaan_kafil, no_kontak_kafil,
        dusun, desa_kelurahan, kecamatan, kabupaten, propinsi,
        nama_ayah, no_kontak_ayah, nama_ibu, no_kontak_ibu,
        tanggal_masuk
    FROM santri
';
if ($onlyAktif && column_exists($pdo, 'santri', 'is_aktif')) {
    $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
require_once __DIR__ . '/../helpers/santri_list_sort.php';
santri_list_sort_mode($_GET['santri_sort'] ?? null);
$sql .= ' ORDER BY ' . santri_list_order_sql('santri');

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$fn = 'Data_Santri_Aktif_' . date('Y-m-d') . ($onlyAktif ? '' : '_semua') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
$headers = [
    'QR', 'NIS', 'Nama', 'NIK', 'Jenis kelamin', 'Tingkatan', 'Kategori kelas (kode)', 'WA Wali', 'Aktif (1/0)',
    'Tempat lahir', 'Tgl lahir (tanggal)', 'Tahun lahir',
    'Nama kafil', 'Status kafil', 'Pekerjaan kafil', 'Kontak kafil',
    'Dusun', 'Desa/Kel', 'Kecamatan', 'Kabupaten', 'Propinsi',
    'Nama ayah', 'Kontak ayah', 'Nama ibu', 'Kontak ibu',
    'Tanggal masuk',
];
fputcsv($out, $headers, ';');
foreach ($rows as $r) {
    fputcsv($out, [
        (string) ($r['qr'] ?? ''),
        (string) ($r['nis'] ?? ''),
        (string) ($r['nama_santri'] ?? ''),
        (string) ($r['nik'] ?? ''),
        (string) ($r['jenis_kelamin'] ?? ''),
        (string) ($r['tingkatan'] ?? ''),
        (string) ($r['kategori_kelas'] ?? ''),
        (string) ($r['no_wa_wali'] ?? ''),
        (string) ($r['is_aktif'] ?? '1'),
        (string) ($r['tempat_lahir_kab'] ?? ''),
        (string) ($r['tanggal_lahir'] ?? ''),
        (string) ($r['tahun_lahir'] ?? ''),
        (string) ($r['nama_kafil'] ?? ''),
        (string) ($r['status_kafil'] ?? ''),
        (string) ($r['pekerjaan_kafil'] ?? ''),
        (string) ($r['no_kontak_kafil'] ?? ''),
        (string) ($r['dusun'] ?? ''),
        (string) ($r['desa_kelurahan'] ?? ''),
        (string) ($r['kecamatan'] ?? ''),
        (string) ($r['kabupaten'] ?? ''),
        (string) ($r['propinsi'] ?? ''),
        (string) ($r['nama_ayah'] ?? ''),
        (string) ($r['no_kontak_ayah'] ?? ''),
        (string) ($r['nama_ibu'] ?? ''),
        (string) ($r['no_kontak_ibu'] ?? ''),
        (string) ($r['tanggal_masuk'] ?? ''),
    ], ';');
}
fclose($out);
