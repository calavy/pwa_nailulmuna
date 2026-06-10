<?php

declare(strict_types=1);

/** @var array<string, string> $values */
/** @var array<string, mixed>|null $waTestResult */

?>
<?php if (is_array($waTestResult)): ?>
    <div class="alert alert-<?= !empty($waTestResult['success']) ? 'success' : 'danger' ?> py-2 small">
        <strong>Hasil tes:</strong>
        <?= !empty($waTestResult['success']) ? 'Berhasil' : 'Gagal' ?>
        ke <?= htmlspecialchars((string) ($waTestResult['target'] ?? '-')) ?>
        <?php if (!empty($waTestResult['error'])): ?> — <?= htmlspecialchars((string) $waTestResult['error']) ?><?php endif; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">1. Koneksi gateway (Fonnte)</h2>
        <p class="small text-muted mb-3">Token dari dashboard Fonnte. URL kosong = otomatis <code>api.fonnte.com/send</code>.</p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_gateway">
            <input type="hidden" name="redirect_tab" value="gateway">
            <div class="col-md-6">
                <label class="form-label">URL gateway</label>
                <input type="text" class="form-control" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Token</label>
                <input type="text" class="form-control" name="wa_gateway_token" value="<?= htmlspecialchars($values['wa_gateway_token']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Sender (opsional)</label>
                <input type="text" class="form-control" name="wa_sender" value="<?= htmlspecialchars($values['wa_sender']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Kunci cron <span class="text-muted">(opsional)</span></label>
                <input type="text" class="form-control font-monospace" name="wa_auto_cron_key" value="<?= htmlspecialchars($waCronKey) ?>" placeholder="Rahasia untuk URL cron">
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_otomatis_master_enabled" name="wa_otomatis_master_enabled" value="1" <?= $waMasterOn ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_otomatis_master_enabled">Master WA otomatis aktif</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Mode notifikasi aplikasi</label>
                <select class="form-select" name="fcm_notify_mode">
                    <option value="both" <?= $notifyMode === 'both' ? 'selected' : '' ?>>WA + Push</option>
                    <option value="wa" <?= $notifyMode === 'wa' ? 'selected' : '' ?>>WA saja</option>
                    <option value="push" <?= $notifyMode === 'push' ? 'selected' : '' ?>>Push saja</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan gateway</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">2. Tes kirim (personal atau grup)</h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="test_wa">
            <input type="hidden" name="redirect_tab" value="gateway">
            <div class="col-md-4">
                <label class="form-label">Nomor / ID grup</label>
                <input type="text" class="form-control" name="wa_test_target" placeholder="08xxx atau 120363...@g.us" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Pesan</label>
                <input type="text" class="form-control" name="wa_test_message" value="Tes WA dari <?= htmlspecialchars($appNama) ?>.">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">Kirim tes</button>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-0">Gunakan field token di atas saat tes; simpan dulu jika ingin permanen.</p>
            </div>
            <input type="hidden" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
            <input type="hidden" name="wa_gateway_token" value="<?= htmlspecialchars($values['wa_gateway_token']) ?>">
            <input type="hidden" name="wa_sender" value="<?= htmlspecialchars($values['wa_sender']) ?>">
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h6 mb-2">3. Nomor penerima umum</h2>
        <p class="small text-muted mb-3">Digunakan untuk rekap alpa, notifikasi pengurus, dan fallback petugas pendidikan.</p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_penerima">
            <input type="hidden" name="redirect_tab" value="gateway">
            <div class="col-md-6">
                <label class="form-label">No. pengurus (alpa &amp; umum)</label>
                <input type="text" class="form-control" name="wa_pengurus" value="<?= htmlspecialchars($values['wa_pengurus']) ?>">
                <div class="form-text">Beberapa nomor: pisah koma.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">No. petugas pendidikan</label>
                <input type="text" class="form-control" name="wa_petugas_pendidikan" value="<?= htmlspecialchars((string) ($values['wa_petugas_pendidikan'] ?? '')) ?>">
                <div class="form-text">Mudabir / kelas kosong. Kosong = pakai pengurus.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam kirim WA otomatis (alpa)</label>
                <input type="time" class="form-control input-time-24" name="jam_kirim_wa_auto" value="<?= htmlspecialchars(app_format_jam($values['jam_kirim_wa_auto'])) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas alpa (mode lama)</label>
                <input type="number" min="1" class="form-control" name="batas_alpa_notif" value="<?= htmlspecialchars($values['batas_alpa_notif']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas telat presensi (menit)</label>
                <input type="number" min="0" class="form-control" name="batas_telat_menit" value="<?= htmlspecialchars($values['batas_telat_menit']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Keterangan pengurus keuangan (di pesan tagihan)</label>
                <textarea class="form-control" name="keterangan_pengurus_bidang_keuangan" rows="2" maxlength="500"><?= htmlspecialchars((string) ($values['keterangan_pengurus_bidang_keuangan'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan penerima</button>
            </div>
        </form>
    </div>
</div>
