<?php

declare(strict_types=1);

/** @var array<string, string> $kalenderV */

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Jadwal kirim otomatis</h2>
        <p class="small text-muted mb-3">Pengingat tagihan ke wali santri yang masih punya kekurangan — dapat dikirim harian sejak awal bulan dan menghitung tunggakan dari awal tahun ajaran s.d. bulan berjalan.</p>
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
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_tagihan_kumulatif" name="wa_tagihan_kumulatif" value="1" <?= !empty($waJadwal['kumulatif']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_tagihan_kumulatif">Hitung tunggakan kumulatif (awal TA → bulan berjalan)</label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_tagihan_recurring" name="wa_tagihan_recurring" value="1" <?= !empty($waJadwal['recurring']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_tagihan_recurring">Kirim tiap hari sejak awal bulan (selama masih ada kekurangan)</label>
                </div>
                <div class="form-text">Jika nonaktif, kirim sekali di <strong>hari kirim</strong> per periode kalender.</div>
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
