<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/akademik.php';
require_once __DIR__ . '/../../helpers/datetime_display.php';

/** @var PDO $pdo */
$liburBanner = akademik_libur_presensi_tampilan($pdo);
if ($liburBanner === null) {
    return;
}

$nama = trim((string) ($liburBanner['nama'] ?? 'Hari libur'));
$sumber = (string) ($liburBanner['sumber'] ?? '');
$mode = (string) ($liburBanner['mode'] ?? '');
$selesai = trim((string) ($liburBanner['tanggal_selesai'] ?? ''));
$mulai = trim((string) ($liburBanner['tanggal_mulai'] ?? ''));
$hariKe = (int) ($liburBanner['hari_ke'] ?? 0);

$judul = 'Hari ini libur: ' . $nama . '.';
$detail = '';
if ($sumber === 'mingguan') {
    $namaHari = akademik_nama_hari_minggu()[$hariKe] ?? '';
    $judul = $namaHari !== ''
        ? 'Hari ini libur: ' . $nama . ' (setiap ' . $namaHari . ').'
        : 'Hari ini libur: ' . $nama . '.';
} elseif ($selesai !== '' && $mulai !== '' && $mulai !== $selesai) {
    $detail = 'Berlaku sampai ' . app_format_tanggal_id($selesai) . '.';
}

$sisa = '';
if ($mode === 'TAALIM_ONLY') {
    $sisa = "Jama'ah tetap berjalan.";
} elseif ($mode === 'JAMAAH_ONLY') {
    $sisa = "Ta'lim/Ta'alum tetap berjalan.";
}
?>
<div class="wali-libur-banner mb-3" role="status">
    <div class="wali-libur-banner__kicker">Kalender pondok</div>
    <p class="wali-libur-banner__title mb-1"><?= htmlspecialchars($judul) ?></p>
    <?php if ($detail !== ''): ?>
        <p class="wali-libur-banner__detail mb-0"><?= htmlspecialchars($detail) ?><?= $sisa !== '' ? ' ' . htmlspecialchars($sisa) : '' ?></p>
    <?php elseif ($sisa !== ''): ?>
        <p class="wali-libur-banner__detail mb-0"><?= htmlspecialchars($sisa) ?></p>
    <?php endif; ?>
</div>
