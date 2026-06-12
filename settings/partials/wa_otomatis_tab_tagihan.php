<?php

declare(strict_types=1);

/** @var array<string, string> $kalenderV */
/** @var bool $cashlessSaldoRendahWaEnabled */
/** @var int $cashlessSaldoRendahWaAmbang */

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Jadwal kirim otomatis</h2>
        <p class="small text-muted mb-3">Pengingat tagihan syahriyah ke nomor wali santri yang belum lunas.</p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_tagihan_jadwal">
            <input type="hidden" name="redirect_tab" value="tagihan">
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="wa_tagihan_auto_enabled">
                    <option value="1" <?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_tagihan_auto_enabled'] ?? '') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Kalender jadwal</label>
                <select name="wa_tagihan_calendar" class="form-select" id="wa-sel-kalender">
                    <option value="HIJRIYAH" <?= $kalenderV['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'selected' : '' ?>>Hijriyah</option>
                    <option value="MASEHI" <?= $kalenderV['wa_tagihan_calendar'] === 'MASEHI' ? 'selected' : '' ?>>Masehi</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Hari kirim</label>
                <input type="number" name="wa_tagihan_day" class="form-control" min="1" max="30" value="<?= htmlspecialchars($kalenderV['wa_tagihan_day']) ?>">
                <div class="form-text">Ke-1 s/d 30</div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam kirim</label>
                <input type="time" name="wa_tagihan_send_time" class="form-control" value="<?= htmlspecialchars($kalenderV['wa_tagihan_send_time']) ?>">
            </div>
            <div class="col-12" id="wa-wrap-custom-masehi" <?= $kalenderV['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'style="display:none"' : '' ?>>
                <label class="form-label">Tanggal Masehi khusus <span class="text-muted">(opsional)</span></label>
                <input type="text" name="wa_tagihan_custom_masehi_dates" class="form-control" value="<?= htmlspecialchars((string) ($kalenderV['wa_tagihan_custom_masehi_dates'] ?? '')) ?>" placeholder="2026-05-28, 2026-06-02">
                <div class="form-text">Jika diisi, kirim hanya di tanggal ini (mode Masehi).</div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan jadwal</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Status hari ini</h2>
        <ul class="small text-muted mb-0 ps-3">
            <li>Kalender: <strong><?= $waJadwal['calendar'] === 'HIJRIYAH' ? 'Hijriyah' : 'Masehi' ?></strong> · hari ke-<strong><?= (int) $waJadwal['due_day'] ?></strong> (hari ini ke-<?= (int) $waJadwal['today_day'] ?>)</li>
            <li>Hari kirim? <?= $waJadwal['is_send_day'] ? '<span class="text-success">Ya</span>' : '<span class="text-muted">Tidak</span>' ?>
                · Jam kirim <?= $waJadwal['send_time_ok'] ? '<span class="text-success">sudah lewat</span>' : '<span class="text-warning">belum</span>' ?></li>
            <li>Periode terakhir sukses: <strong><?= $waJadwal['last_sent_at'] !== '' ? htmlspecialchars($waJadwal['last_sent_at']) : 'Belum pernah' ?></strong></li>
            <?php if ($waLastStats): ?>
                <li>Run terakhir: terkirim <?= (int) ($waLastStats['sent'] ?? 0) ?>, gagal <?= (int) ($waLastStats['failed'] ?? 0) ?>, dilewati <?= (int) ($waLastStats['skipped'] ?? 0) ?></li>
            <?php endif; ?>
            <?php if ($waPartialFail): ?>
                <li class="text-warning">Gagal parsial: <?= (int) ($waPartialFail['failed'] ?? 0) ?> dari <?= (int) ($waPartialFail['eligible'] ?? 0) ?> — jalankan manual atau tunggu periode berikutnya.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div class="card shadow-sm border-0">
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
<div class="card shadow-sm border-0 mb-3 border-warning-subtle">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-wallet text-warning me-1"></i> Saldo cashless rendah → wali santri</h2>
        <p class="small text-muted mb-3">
            Kirim WA otomatis ke nomor wali saat saldo cashless (saku) santri turun ke ambang atau di bawahnya.
            Notifikasi dikirim sekali per periode saldo rendah; reset otomatis setelah top-up melebihi ambang.
        </p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_cashless_saldo_wa">
            <input type="hidden" name="redirect_tab" value="tagihan">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="cashless_saldo_rendah_wa_enabled" name="cashless_saldo_rendah_wa_enabled" value="1" <?= $cashlessSaldoRendahWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="cashless_saldo_rendah_wa_enabled">Aktifkan notifikasi</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="cashless_saldo_rendah_wa_ambang">Ambang saldo (Rp)</label>
                <input type="number" class="form-control" id="cashless_saldo_rendah_wa_ambang" name="cashless_saldo_rendah_wa_ambang" min="0" step="1000" value="<?= (int) $cashlessSaldoRendahWaAmbang ?>">
                <div class="form-text">Default: Rp 30.000</div>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-2">Template pesan di tab <strong>Template</strong> (saldo cashless rendah → wali santri).</p>
                <button type="submit" class="btn btn-success btn-sm">Simpan saldo cashless</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('wa-sel-kalender');
    var wrap = document.getElementById('wa-wrap-custom-masehi');
    if (!sel || !wrap) return;
    sel.addEventListener('change', function () {
        wrap.style.display = sel.value === 'MASEHI' ? '' : 'none';
    });
})();
</script>
