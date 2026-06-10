<?php

declare(strict_types=1);

/** @var array<string, mixed> $row */
/** @var array<string, mixed> $kop */
/** @var string $headerColor */
/** @var string $kotaPonpes */

$nama = trim((string) ($row['nama_santri'] ?? ''));
$nis = trim((string) ($row['nis'] ?? ''));
?>
<div class="kartu-tes-sheet kartu-tes-sheet--kop-watermark">
    <?= pondok_kop_surat_html($kop, $headerColor) ?>

    <div class="kartu-tes-title">
        <strong>Kartu Tes Santri</strong>
    </div>

    <div class="kartu-tes-body-content">
        <table class="kartu-tes-info">
            <tr>
                <td>Nama Santri</td>
                <td class="val"><?= htmlspecialchars($nama !== '' ? $nama : '—') ?></td>
            </tr>
            <tr>
                <td>NIS</td>
                <td class="val font-monospace"><?= htmlspecialchars($nis !== '' ? $nis : '—') ?></td>
            </tr>
            <tr>
                <td>Tingkatan</td>
                <td class="val val--blank">........................................................</td>
            </tr>
        </table>

        <div class="kartu-tes-hasil">
            <p class="kartu-tes-hasil__label">Hasil Tes</p>
            <div class="kartu-tes-hasil__opsi">
                <span><i class="kartu-tes-kotak" aria-hidden="true"></i> Lulus</span>
                <span><i class="kartu-tes-kotak" aria-hidden="true"></i> Tidak Lulus</span>
            </div>
        </div>
    </div>

    <div class="kartu-tes-ttd">
        <div class="lokasi"><?= htmlspecialchars($kotaPonpes) ?>, ....................................</div>
        <div class="jabatan">Pengetes,</div>
        <div class="sign-space" aria-hidden="true"></div>
        <div class="garis-nama">( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</div>
    </div>
</div>
