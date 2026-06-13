<?php

declare(strict_types=1);

/** @var string $kotaPonpes */
/** @var string $pemberiIzin */
/** @var string|null $ttdTanggal */
$kotaPonpes = trim((string) ($kotaPonpes ?? ''));
$pemberiIzin = trim((string) ($pemberiIzin ?? ''));
$ttdTanggal = trim((string) ($ttdTanggal ?? app_format_tanggal_id(date('Y-m-d'))));
$tempatTtd = $kotaPonpes !== '' ? $kotaPonpes . ', ' . $ttdTanggal : $ttdTanggal;
?>
<div class="surat-ttd">
    <p class="surat-ttd__tempat"><?= htmlspecialchars($tempatTtd) ?></p>
    <div class="surat-ttd__blok">
        <p class="surat-ttd__jabatan">Pemberi Izin,</p>
        <div class="surat-ttd__ruang" aria-hidden="true"></div>
        <p class="surat-ttd__nama"><?= htmlspecialchars($pemberiIzin !== '' ? $pemberiIzin : '(_____________________)') ?></p>
    </div>
</div>
