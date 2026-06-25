<?php

declare(strict_types=1);

/**
 * Panel atur waktu kegiatan Jama'ah — terpisah Putra & Putri.
 *
 * @var list<array<string,mixed>> $jamaahEditorRows
 * @var bool $showJadwalAksi
 */
$jamaahEditorRows = $jamaahEditorRows ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;

$renderJamaahKelompokForm = static function (
    array $jgRow,
    string $kelompok,
    array $ring,
    array $saran,
    bool $showAksi,
    bool $blockSemua
) use ($showJadwalAksi): void {
    $kid = (int) ($jgRow['id'] ?? 0);
    $slotCount = (int) ($ring['slot_count'] ?? 0);
    $jm = (string) ($ring['jam_mulai_tampil'] ?? '');
    $js = (string) ($ring['jam_selesai_tampil'] ?? '');
    $seragam = (bool) ($ring['waktu_seragam'] ?? true);
    $saranJm = (string) ($saran['jam_mulai'] ?? '');
    $saranJs = (string) ($saran['jam_selesai'] ?? '');
    $label = jadwal_jamaah_kelompok_label($kelompok);
    $isPutra = $kelompok === 'putra';
    ?>
    <div class="jadwal-jamaah-kelompok jadwal-jamaah-kelompok--<?= htmlspecialchars($kelompok) ?>">
        <div class="jadwal-jamaah-kelompok__head">
            <span class="jadwal-jamaah-kelompok__badge">
                <i class="fa-solid <?= $isPutra ? 'fa-mars' : 'fa-venus' ?> me-1" aria-hidden="true"></i><?= htmlspecialchars($label) ?>
            </span>
            <?php if ($slotCount > 0): ?>
                <span class="jadwal-jamaah-kelompok__now font-monospace js-time-24 small">
                    <?= htmlspecialchars($jm !== '' && $js !== '' ? ($jm . ' – ' . $js) : '—') ?>
                </span>
            <?php endif; ?>
        </div>
        <p class="small text-muted mb-2">
            <?= $slotCount > 0 ? (int) $slotCount . ' slot' : 'Belum ada jadwal' ?>
            <?php if ($slotCount > 0 && !$seragam): ?>
                <span class="badge text-bg-warning">Waktu tidak seragam</span>
            <?php endif; ?>
        </p>

        <?php if ($showAksi && $showJadwalAksi && !$blockSemua): ?>
        <form method="post" class="jadwal-jamaah-kelompok__form" data-jamaah-form data-kelompok="<?= htmlspecialchars($kelompok) ?>">
            <input type="hidden" name="action" value="<?= $slotCount > 0 ? 'jamaah_waktu' : 'jamaah_buat_dasar' ?>">
            <input type="hidden" name="kegiatan_id" value="<?= $kid ?>">
            <input type="hidden" name="kelompok" value="<?= htmlspecialchars($kelompok) ?>">

            <div class="jadwal-jamaah-time-row jadwal-jamaah-time-row--compact">
                <div class="jadwal-jamaah-time-field">
                    <label class="form-label small mb-1">Mulai</label>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="jm" data-delta="-5" title="-5 menit">−5</button>
                        <input type="text" name="jam_mulai" class="form-control font-monospace input-time-24 jadwal-jamaah-jm"
                            inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" required
                            value="<?= htmlspecialchars($jm !== '' ? $jm : $saranJm) ?>">
                        <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="jm" data-delta="5" title="+5 menit">+5</button>
                    </div>
                </div>
                <div class="jadwal-jamaah-time-sep" aria-hidden="true">s/d</div>
                <div class="jadwal-jamaah-time-field">
                    <label class="form-label small mb-1">Selesai</label>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="js" data-delta="-5" title="-5 menit">−5</button>
                        <input type="text" name="jam_selesai" class="form-control font-monospace input-time-24 jadwal-jamaah-js"
                            inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" required
                            value="<?= htmlspecialchars($js !== '' ? $js : $saranJs) ?>">
                        <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="js" data-delta="5" title="+5 menit">+5</button>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    <?= $slotCount > 0 ? 'Simpan ' . htmlspecialchars($label) : 'Buat jadwal ' . htmlspecialchars($label) ?>
                </button>
                <?php if ($saranJm !== ''): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm jadwal-jamaah-isi-saran"
                        data-jm="<?= htmlspecialchars($saranJm) ?>" data-js="<?= htmlspecialchars($saranJs) ?>">
                        Saran
                    </button>
                <?php endif; ?>
            </div>
        </form>
        <?php elseif ($showJadwalAksi && $blockSemua): ?>
            <p class="small text-muted mb-0">Gunakan tombol pisah di atas untuk mengatur waktu <?= htmlspecialchars($label) ?>.</p>
        <?php else: ?>
            <p class="small text-muted mb-0">—</p>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="jadwal-jamaah-intro mb-3">
    <p class="small text-muted mb-2">
        Satu nama kegiatan jamaah (mis. Subuh) dipakai bersama; waktu bisa sama atau berbeda per kelompok tingkatan.
        <strong>Putra</strong> = semua tingkatan biasa (tanpa penanda).
        <strong>Putri</strong> = tingkatan yang ditandai sufiks <code>(putri)</code>, contoh <em>Tsanawiyah (putri)</em>.
    </p>
    <?php if ($showJadwalAksi): ?>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php?filter_kat=JAMAAH')) ?>">
            <i class="fa-solid fa-bookmark me-1"></i> Kelola nama kegiatan
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=daftar&filter_kat=JAMAAH')) ?>">
            <i class="fa-solid fa-list me-1"></i> Lihat di daftar jadwal
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($jamaahEditorRows === []): ?>
    <div class="jadwal-jamaah-empty text-center py-4 px-3">
        <div class="display-6 text-muted mb-2"><i class="fa-solid fa-mosque"></i></div>
        <p class="text-muted mb-2">Belum ada kegiatan berkategori <strong>Jama'ah</strong>.</p>
        <?php if ($showJadwalAksi): ?>
            <a class="btn btn-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php?filter_kat=JAMAAH')) ?>">
                <i class="fa-solid fa-plus me-1"></i> Tambah kegiatan Jama'ah
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
    $firstRow = $jamaahEditorRows[0] ?? [];
    $samplePutra = (array) ($firstRow['tingkatan_putra'] ?? []);
    $samplePutri = (array) ($firstRow['tingkatan_putri'] ?? []);
    if ($samplePutra === [] && $samplePutri === []): ?>
        <div class="alert alert-warning py-2 small">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            Belum ada tingkatan. Tambahkan di pengaturan tingkatan — Putri cukup ditandai <strong>(putri)</strong> di nama, Putra tanpa penanda.
        </div>
    <?php else: ?>
        <div class="jadwal-jamaah-tk-hint small text-muted mb-3">
            <?php if ($samplePutra !== []): ?>
                <div><strong>Putra:</strong> <?= htmlspecialchars(implode(', ', array_slice($samplePutra, 0, 8))) ?><?= count($samplePutra) > 8 ? '…' : '' ?></div>
            <?php endif; ?>
            <?php if ($samplePutri !== []): ?>
                <div><strong>Putri:</strong> <?= htmlspecialchars(implode(', ', array_slice($samplePutri, 0, 8))) ?><?= count($samplePutri) > 8 ? '…' : '' ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="jadwal-jamaah-grid">
        <?php foreach ($jamaahEditorRows as $jgRow):
            $kid = (int) ($jgRow['id'] ?? 0);
            $nama = (string) ($jgRow['nama_kegiatan'] ?? '');
            $aktif = (int) ($jgRow['is_active'] ?? 1) === 1;
            $semuaCount = (int) ($jgRow['semua_tingkatan_count'] ?? 0);
            $saran = (array) ($jgRow['saran'] ?? []);
            $ringPutra = (array) ($jgRow['putra'] ?? []);
            $ringPutri = (array) ($jgRow['putri'] ?? []);
            $totalSlots = (int) ($ringPutra['slot_count'] ?? 0) + (int) ($ringPutri['slot_count'] ?? 0) + $semuaCount;
            ?>
            <article class="jadwal-jamaah-card<?= !$aktif ? ' jadwal-jamaah-card--off' : '' ?>" data-kegiatan-id="<?= $kid ?>">
                <header class="jadwal-jamaah-card__head">
                    <div>
                        <h3 class="jadwal-jamaah-card__title mb-0"><?= htmlspecialchars($nama) ?></h3>
                        <div class="small text-muted mt-1">
                            <?= $totalSlots > 0 ? $totalSlots . ' slot jadwal' : 'Belum ada jadwal' ?>
                            <?php if ($semuaCount > 0): ?>
                                <span class="badge text-bg-warning ms-1"><?= $semuaCount ?> Semua Tingkatan</span>
                            <?php endif; ?>
                            <?php if (!$aktif): ?>
                                <span class="badge text-bg-secondary ms-1">Nonaktif</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </header>

                <?php if ($semuaCount > 0 && $showJadwalAksi): ?>
                    <div class="alert alert-warning py-2 px-2 small mb-3 jadwal-jamaah-pisah-alert">
                        <p class="mb-2">Jadwal masih memakai <strong>Semua Tingkatan</strong> (satu waktu untuk semua). Pisahkan agar Putra &amp; Putri bisa beda jam.</p>
                        <form method="post" class="jadwal-jamaah-pisah-form">
                            <input type="hidden" name="action" value="jamaah_pisah">
                            <input type="hidden" name="kegiatan_id" value="<?= $kid ?>">
                            <div class="jadwal-jamaah-pisah-grid mb-2">
                                <div>
                                    <span class="fw-semibold small">Putra</span>
                                    <div class="d-flex gap-1 mt-1">
                                        <input type="text" name="jam_mulai_putra" class="form-control form-control-sm font-monospace input-time-24 jadwal-jamaah-jm" placeholder="Mulai" required
                                            value="<?= htmlspecialchars((string) ($ringPutra['jam_mulai_tampil'] ?: ($saran['jam_mulai'] ?? ''))) ?>">
                                        <input type="text" name="jam_selesai_putra" class="form-control form-control-sm font-monospace input-time-24 jadwal-jamaah-js" placeholder="Selesai" required
                                            value="<?= htmlspecialchars((string) ($ringPutra['jam_selesai_tampil'] ?: ($saran['jam_selesai'] ?? ''))) ?>">
                                    </div>
                                </div>
                                <div>
                                    <span class="fw-semibold small">Putri</span>
                                    <div class="d-flex gap-1 mt-1">
                                        <input type="text" name="jam_mulai_putri" class="form-control form-control-sm font-monospace input-time-24 jadwal-jamaah-jm" placeholder="Mulai" required
                                            value="<?= htmlspecialchars((string) ($ringPutri['jam_mulai_tampil'] ?: ($saran['jam_mulai'] ?? ''))) ?>">
                                        <input type="text" name="jam_selesai_putri" class="form-control form-control-sm font-monospace input-time-24 jadwal-jamaah-js" placeholder="Selesai" required
                                            value="<?= htmlspecialchars((string) ($ringPutri['jam_selesai_tampil'] ?: ($saran['jam_selesai'] ?? ''))) ?>">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-warning btn-sm">
                                <i class="fa-solid fa-code-branch me-1"></i> Pisahkan Putra &amp; Putri
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="jadwal-jamaah-kelompok-row">
                    <?php $renderJamaahKelompokForm($jgRow, 'putra', $ringPutra, $saran, $showJadwalAksi, $semuaCount > 0); ?>
                    <?php $renderJamaahKelompokForm($jgRow, 'putri', $ringPutri, $saran, $showJadwalAksi, $semuaCount > 0); ?>
                </div>

                <?php if ($totalSlots > 0): ?>
                    <div class="text-end mt-2">
                        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=daftar&filter_kat=JAMAAH&kegiatan_id=' . $kid)) ?>">
                            Detail slot
                        </a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
