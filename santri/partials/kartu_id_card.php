<?php

declare(strict_types=1);

/**
 * Satu kartu ID santri (86×54 mm).
 *
 * @var array<string,mixed> $row Harus sudah lewat santri_kartu_prepare_row
 * @var string $namaPonpes
 * @var string $alamatPonpes
 * @var string $logoUrl
 * @var string $cardStyleAttrs
 * @var string $cardDomId Opsional id untuk download JPG tunggal
 */
$row = is_array($row ?? null) ? $row : [];
$namaPonpes = (string) ($namaPonpes ?? '');
$alamatPonpes = trim((string) ($alamatPonpes ?? ''));
$logoUrl = trim((string) ($logoUrl ?? ''));
$cardStyleAttrs = (string) ($cardStyleAttrs ?? '');
$cardDomId = trim((string) ($cardDomId ?? ''));
$jk = trim((string) ($row['jenis_kelamin'] ?? ''));
$tk = trim((string) ($row['tingkatan'] ?? ''));
$aktif = (int) ($row['is_aktif'] ?? 1) === 1;
?>
<div class="st-id-card<?= $cardDomId === '' ? '' : '' ?>"<?= $cardDomId !== '' ? ' id="' . htmlspecialchars($cardDomId) . '"' : '' ?><?= $cardStyleAttrs ?>>
    <div class="st-id-top">
        <?php if ($logoUrl !== ''): ?>
            <span class="st-id-logo-wrap" aria-hidden="true">
                <img src="<?= htmlspecialchars($logoUrl) ?>" class="st-id-logo" alt="Logo pondok">
            </span>
        <?php endif; ?>
        <div class="st-id-brand">
            <div class="sub">KARTU SANTRI</div>
            <h2><?= htmlspecialchars($namaPonpes) ?></h2>
            <?php if ($alamatPonpes !== ''): ?>
                <div class="addr"><?= htmlspecialchars($alamatPonpes) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="st-id-body">
        <div class="st-id-meta">
            <p class="st-id-name"><?= htmlspecialchars((string) ($row['nama_santri'] ?? '-')) ?></p>
            <div class="st-id-line">NIS: <?= htmlspecialchars((string) ($row['nis'] ?? '-')) ?></div>
            <?php if ($tk !== ''): ?>
                <div class="st-id-line">Tingkatan: <?= htmlspecialchars($tk) ?></div>
            <?php endif; ?>
            <?php if ($jk !== ''): ?>
                <div class="st-id-line"><?= htmlspecialchars($jk) ?></div>
            <?php endif; ?>
            <div class="st-id-line">Status: <?= $aktif ? 'AKTIF' : 'NONAKTIF' ?></div>
        </div>
        <div class="st-id-qrbox">
            <img src="<?= htmlspecialchars((string) ($row['qr_url'] ?? '')) ?>" alt="QR Santri">
        </div>
    </div>
    <div class="st-id-foot"><?= htmlspecialchars((string) ($row['kode_qr_final'] ?? '')) ?></div>
</div>
