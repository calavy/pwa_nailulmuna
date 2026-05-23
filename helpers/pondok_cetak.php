<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** Data kop surat pondok (logo, nama, alamat, kontak). */
function pondok_kop_data(PDO $pdo): array
{
    $logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
    $logoUrl = trim((string) app_setting($pdo, 'logo_url', ''));
    $logo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrl;

    return [
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'jenis_pendidikan' => trim((string) app_setting($pdo, 'jenis_pendidikan', '')),
        'alamat_ponpes' => trim((string) app_setting($pdo, 'alamat_ponpes', '')),
        'telp_ponpes' => trim((string) app_setting($pdo, 'telp_ponpes', '')),
        'website_ponpes' => trim((string) app_setting($pdo, 'website_ponpes', '')),
        'kota_ponpes' => trim((string) app_setting($pdo, 'kota_ponpes', 'Muntilan')) ?: 'Muntilan',
        'logo' => $logo,
        'nama_pengasuh' => trim((string) app_setting($pdo, 'nama_pengasuh', '')),
    ];
}
