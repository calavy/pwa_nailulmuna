<?php

declare(strict_types=1);

/** @var string $kotaPonpes */
/** @var string $pemberiIzin */
/** @var string|null $ttdTanggal */
/** @var PDO|null $pdo */

if (!function_exists('pondok_stampel_href')) {
    require_once __DIR__ . '/../../helpers/pondok_stampel.php';
}

$kotaPonpes = trim((string) ($kotaPonpes ?? ''));
$pemberiIzin = trim((string) ($pemberiIzin ?? ''));
$ttdTanggal = trim((string) ($ttdTanggal ?? app_format_tanggal_id(date('Y-m-d'))));
$tempatTtd = $kotaPonpes !== '' ? $kotaPonpes . ', ' . $ttdTanggal : $ttdTanggal;
$stampelSurat = '';
if (isset($pdo) && $pdo instanceof PDO) {
    $stampelSurat = pondok_stampel_href($pdo, 'surat');
}
?>
<div class="surat-ttd">
    <p class="surat-ttd__tempat"><?= htmlspecialchars($tempatTtd) ?></p>
    <div class="surat-ttd__blok">
        <p class="surat-ttd__jabatan">Pemberi Izin,</p>
        <div class="surat-ttd__ruang" aria-hidden="true">
            <?php if ($stampelSurat !== ''): ?>
                <img src="<?= htmlspecialchars($stampelSurat) ?>" alt="Stempel resmi" class="surat-ttd__stampel">
            <?php endif; ?>
        </div>
        <p class="surat-ttd__nama"><?= htmlspecialchars($pemberiIzin !== '' ? $pemberiIzin : '(_____________________)') ?></p>
    </div>
</div>
