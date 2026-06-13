<?php

declare(strict_types=1);

/** @var array{disetujui:bool,keterangan:string,nama:string,waktu:string} $pengasuhBlok */
$pengasuhBlok = $pengasuhBlok ?? ['disetujui' => false, 'keterangan' => '', 'nama' => '', 'waktu' => ''];
if (empty($pengasuhBlok['disetujui'])) {
    return;
}
$namaPengasuh = trim((string) ($pengasuhBlok['nama'] ?? ''));
$waktuPengasuh = trim((string) ($pengasuhBlok['waktu'] ?? ''));
if ($namaPengasuh === '') {
    return;
}
?>
<div class="pengasuh-paraf" role="note" aria-label="Persetujuan pengasuh">
    <div class="pengasuh-paraf__badge">✓</div>
    <div class="pengasuh-paraf__body">
        <div class="pengasuh-paraf__head">Persetujuan Pengasuh</div>
        <p class="pengasuh-paraf__text">Permohonan izin ini telah disetujui oleh Pengasuh pondok pesantren.</p>
        <p class="pengasuh-paraf__nama"><?= htmlspecialchars($namaPengasuh) ?></p>
        <?php if ($waktuPengasuh !== ''): ?>
            <p class="pengasuh-paraf__waktu">Disetujui pada <?= htmlspecialchars($waktuPengasuh) ?></p>
        <?php endif; ?>
    </div>
</div>
