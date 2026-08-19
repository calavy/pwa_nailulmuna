<?php

declare(strict_types=1);

/** @var array<string, string> $kalenderV */
/** @var bool $waPembayaranWaliEnabled */
/** @var bool $waMasterOn */
/** @var string|null $waGatewayErr */

$waPembayaranSiap = $waMasterOn
    && $waGatewayErr === null
    && $waPembayaranWaliEnabled;

?>
<?php $delayKind = 'tagihan'; require __DIR__ . '/wa_otomatis_delay_card.php'; ?>
<div class="card shadow-sm border-0 mb-3 border-success-subtle">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-receipt text-success me-1"></i> Pembayaran tercatat → wali santri</h2>
        <p class="small text-muted mb-3">
            WA otomatis ke wali saat admin <strong>menyimpan pembayaran baru</strong> di menu Keuangan → Input Pembayaran.
            Terpisah dari pengingat tagihan. Template pesan di tab
            <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">Template</a>.
        </p>
        <ul class="small mb-3 ps-3">
            <li>Master WA: <?= $waMasterOn ? '<span class="text-success">Aktif</span>' : '<span class="text-warning">Nonaktif</span>' ?></li>
            <li>Gateway: <?= $waGatewayErr === null ? '<span class="text-success">Siap</span>' : '<span class="text-warning">' . htmlspecialchars($waGatewayErr) . '</span>' ?></li>
            <li>Notifikasi pembayaran: <?= $waPembayaranWaliEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
        </ul>
        <?php if (!$waPembayaranSiap && $waPembayaranWaliEnabled): ?>
            <div class="alert alert-warning py-2 small mb-3">Toggle aktif, tetapi WA belum siap kirim — periksa master WA &amp; gateway di tab Gateway.</div>
        <?php endif; ?>
        <form method="post" class="mb-0">
            <input type="hidden" name="action" value="save_pembayaran_wali_wa">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="wa_pembayaran_wali_enabled" name="wa_pembayaran_wali_enabled" value="1" <?= !empty($waPembayaranWaliEnabled) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="wa_pembayaran_wali_enabled">Kirim WA ke wali saat pembayaran disimpan</label>
            </div>
            <button type="submit" class="btn btn-success btn-sm">Simpan toggle</button>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3 border-primary-subtle">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-calendar-plus text-primary me-1"></i> Pengingat pembayaran awal tahun</h2>
        <p class="small text-muted mb-3">
            Kirim WA ke wali santri yang masih punya <strong>tunggakan komponen awal tahun</strong> (sesuai pengaturan komponen berlaku di
            <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=tarif#pos-awal-jenis')) ?>">Keuangan → Tarif</a>).
            Sekali per tahun ajaran aktif. Terpisah dari pengingat tagihan bulanan.
        </p>
        <form method="post" class="row g-3 mb-3">
            <input type="hidden" name="action" value="save_awal_tahun_wa">
            <input type="hidden" name="redirect_tab" value="tagihan">
            <div class="col-md-4">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="wa_awal_tahun_auto_enabled" name="wa_awal_tahun_auto_enabled" value="1" <?= !empty($waAwalTahunEnabled) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_awal_tahun_auto_enabled">Kirim otomatis (cron)</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="wa_awal_tahun_send_time">Jam kirim</label>
                <input type="time" class="form-control" id="wa_awal_tahun_send_time" name="wa_awal_tahun_send_time" value="<?= htmlspecialchars($waAwalTahunJam) ?>">
            </div>
            <div class="col-md-5 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm">Simpan pengingat awal tahun</button>
            </div>
        </form>
        <ul class="small text-muted mb-3 ps-3">
            <li>Status: <strong><?= !empty($waAwalTahunEnabled) ? 'Aktif' : 'Nonaktif' ?></strong> · jam <?= htmlspecialchars($waAwalTahunJam) ?></li>
            <li>Terakhir sukses: <strong><?= $waAwalTahunLastAt !== '' ? htmlspecialchars($waAwalTahunLastAt) : 'Belum pernah' ?></strong></li>
            <?php if ($waAwalTahunLastStats): ?>
                <li>Run terakhir: terkirim <?= (int) ($waAwalTahunLastStats['sent'] ?? 0) ?>, gagal <?= (int) ($waAwalTahunLastStats['failed'] ?? 0) ?>, tanpa nomor <?= (int) ($waAwalTahunLastStats['skipped'] ?? 0) ?></li>
            <?php endif; ?>
        </ul>
        <form method="post" class="d-inline" onsubmit="return confirm('Kirim pengingat tunggakan awal tahun ke semua wali yang bersangkutan?');">
            <input type="hidden" name="action" value="jalankan_wa_awal_tahun">
            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-brands fa-whatsapp me-1"></i> Kirim pengingat awal tahun sekarang</button>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Pengingat tagihan otomatis</h2>
        <p class="small text-muted mb-3">
            Jadwal kalender, hari kirim, dan jam kirim diatur di
            <a href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>"><strong>Kalender Pondok</strong></a>
            (berlaku untuk tagihan, rekap, dan presensi).
        </p>
        <div class="alert alert-light border small mb-3 py-2">
            <div class="row g-2">
                <div class="col-md-4"><span class="text-muted">Kalender</span><br><strong><?= $kalenderV['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'Hijriyah' : 'Masehi' ?></strong></div>
                <div class="col-md-4"><span class="text-muted">Hari kirim</span><br><strong>Ke-<?= htmlspecialchars($kalenderV['wa_tagihan_day']) ?></strong></div>
                <div class="col-md-4"><span class="text-muted">Jam kirim</span><br><strong><?= htmlspecialchars($kalenderV['wa_tagihan_send_time']) ?></strong></div>
            </div>
            <?php if (trim((string) ($kalenderV['wa_tagihan_custom_masehi_dates'] ?? '')) !== ''): ?>
                <div class="mt-2 text-muted">Tanggal Masehi khusus: <?= htmlspecialchars((string) $kalenderV['wa_tagihan_custom_masehi_dates']) ?></div>
            <?php endif; ?>
        </div>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_tagihan_jadwal">
            <input type="hidden" name="redirect_tab" value="tagihan">
            <div class="col-md-4">
                <label class="form-label">Status pengingat</label>
                <select class="form-select" name="wa_tagihan_auto_enabled">
                    <option value="1" <?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_tagihan_auto_enabled'] ?? '') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_tagihan_kumulatif" name="wa_tagihan_kumulatif" value="1" <?= !empty($waJadwal['kumulatif']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_tagihan_kumulatif">Hitung tunggakan kumulatif (awal TA → bulan berjalan)</label>
                    <div class="form-text">Syahriyah dan makan yang belum lunas digabung dalam satu pesan.</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_tagihan_recurring" name="wa_tagihan_recurring" value="1" <?= !empty($waJadwal['recurring']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_tagihan_recurring">Kirim pengingat harian (opsional)</label>
                </div>
                <div class="form-text">Default: sekali per periode pada hari kirim.</div>
            </div>
            <div class="col-md-6">
                <?php
                $delayFieldName = 'wa_delay_tagihan';
                $delayFieldValue = (string) ($values['wa_delay_tagihan'] ?? '');
                require __DIR__ . '/wa_otomatis_delay_field.php';
                ?>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success btn-sm">Simpan pengaturan</button>
                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>">Ubah jadwal di Kalender Pondok</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Status hari ini</h2>
        <ul class="small text-muted mb-0 ps-3">
            <li>Kalender: <strong><?= $waJadwal['calendar'] === 'HIJRIYAH' ? 'Hijriyah' : 'Masehi' ?></strong> · hari ke-<strong><?= (int) $waJadwal['due_day'] ?></strong> (hari ini ke-<?= (int) $waJadwal['today_day'] ?>)</li>
            <li>Mode: <?= !empty($waJadwal['kumulatif']) ? '<span class="text-primary">Tunggakan kumulatif</span>' : 'Bulan berjalan saja' ?>
                · <?= !empty($waJadwal['recurring']) ? '<span class="text-primary">Kirim harian</span>' : 'Sekali per periode' ?></li>
            <li>Hari kirim? <?= $waJadwal['is_send_day'] ? '<span class="text-success">Ya</span>' : '<span class="text-muted">Tidak</span>' ?>
                · Jam kirim <?= $waJadwal['send_time_ok'] ? '<span class="text-success">sudah lewat</span>' : '<span class="text-warning">belum</span>' ?>
                · Hari ini <?= !empty($waJadwal['period_already_sent']) ? '<span class="text-muted">sudah dikirim</span>' : '<span class="text-success">belum dikirim</span>' ?></li>
            <li>Periode terakhir sukses: <strong><?= $waJadwal['last_sent_at'] !== '' ? htmlspecialchars($waJadwal['last_sent_at']) : 'Belum pernah' ?></strong>
                <?php if (!empty($waJadwal['last_sent_date'])): ?> (tanggal <?= htmlspecialchars((string) $waJadwal['last_sent_date']) ?>)<?php endif; ?></li>
            <?php if ($waLastStats): ?>
                <li>Run terakhir: terkirim <?= (int) ($waLastStats['sent'] ?? 0) ?>, gagal <?= (int) ($waLastStats['failed'] ?? 0) ?>, dilewati <?= (int) ($waLastStats['skipped'] ?? 0) ?></li>
            <?php endif; ?>
            <?php if ($waPartialFail): ?>
                <li class="text-warning">Gagal parsial: <?= (int) ($waPartialFail['failed'] ?? 0) ?> dari <?= (int) ($waPartialFail['eligible'] ?? 0) ?> — jalankan manual atau tunggu periode berikutnya.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Kirim manual sekarang</h2>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end" onsubmit="return confirm('Jalankan kirim WA tagihan sekarang?');">
            <input type="hidden" name="action" value="jalankan_wa_tagihan">
            <div>
                <label class="form-label small mb-0">Bulan tagihan</label>
                <input type="number" name="bulan_tagihan" class="form-control form-control-sm" min="0" max="12" value="0" style="width:6rem">
                <div class="form-text">0 = bulan berjalan</div>
            </div>
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Kirim sekarang</button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">Tagihan per santri</a>
        </form>
    </div>
</div>
