<?php

declare(strict_types=1);

/**
 * Blok rincian izin tetap — label & isi berdampingan kiri-kanan (hemat ruang).
 *
 * @var bool $isGabungan
 * @var array<string, mixed> $izin
 * @var int $totalSantri
 * @var array<string, mixed> $suratKonteks
 * @var string $kategoriHidmahLabel
 * @var string $periodeTampil
 * @var string $slotHtml
 */
$detailTeks = trim((string) ($suratKonteks['detail_teks'] ?? ''));
if ($detailTeks === '') {
    $detailTeks = '—';
}

/** @var list<array{label:string,value:string,html?:bool,wide?:bool}> */
$infoFields = [];

if ($isGabungan) {
    $infoFields[] = ['label' => 'Jumlah santri', 'value' => (int) $totalSantri . ' orang'];
} else {
    $infoFields[] = ['label' => 'Nama Santri', 'value' => (string) ($izin['nama_santri'] ?? '-')];
    $infoFields[] = ['label' => 'NIS', 'value' => (string) ($izin['nis'] ?? '-')];
    $infoFields[] = ['label' => 'Tingkatan', 'value' => (string) ($izin['tingkatan'] ?? '-')];
}

$infoFields[] = ['label' => 'Jenis Izin', 'value' => (string) ($suratKonteks['jenis_label'] ?? '')];

if (!($suratKonteks['is_tugas'] ?? false) && $kategoriHidmahLabel !== '') {
    $infoFields[] = ['label' => 'Kategori Hidmah', 'value' => $kategoriHidmahLabel];
}

$infoFields[] = [
    'label' => (string) ($suratKonteks['label_uraian'] ?? 'Uraian'),
    'value' => $detailTeks,
];
$infoFields[] = ['label' => 'Masa Berlaku', 'value' => $periodeTampil];
$infoFields[] = [
    'label' => (string) ($suratKonteks['label_jadwal'] ?? 'Hari & Waktu'),
    'value' => $slotHtml,
    'html' => true,
];

$infoPairs = array_chunk($infoFields, 2);
?>
<table class="info info-izin-tetap info-izin-tetap--compact">
    <?php foreach ($infoPairs as $pair): ?>
        <tr>
            <?php foreach ($pair as $field): ?>
                <td class="info-pair">
                    <span class="info-pair__label"><?= htmlspecialchars((string) $field['label']) ?></span>
                    <span class="info-pair__sep">:</span>
                    <span class="info-pair__value">
                        <?php if (!empty($field['html'])): ?>
                            <?= $field['value'] ?>
                        <?php else: ?>
                            <?= htmlspecialchars((string) $field['value']) ?>
                        <?php endif; ?>
                    </span>
                </td>
            <?php endforeach; ?>
            <?php if (count($pair) === 1): ?>
                <td class="info-pair info-pair--empty"></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
</table>
