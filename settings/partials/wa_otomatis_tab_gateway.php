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
<?php elseif ($waGatewayLastErr !== '' && stripos($waGatewayLastErr, 'disconnected') !== false): ?>
    <div class="alert alert-warning py-2 small mb-0">
        <strong>Perangkat Fonnte terputus.</strong> Terakhir: <?= htmlspecialchars($waGatewayLastErr) ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">1. Koneksi gateway (Fonnte)</h2>
        <p class="small text-muted mb-3">
            Token dari dashboard Fonnte. URL kosong = otomatis <code>api.fonnte.com/send</code>.
            Atur <strong>nomor penerima</strong> notifikasi di
            <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php')) ?>">Nomor WhatsApp</a>.
            Jika tes gagal <em>disconnected device</em>, hubungkan ulang perangkat WA di
            <a href="https://md.fonnte.com" target="_blank" rel="noopener">dashboard Fonnte</a> (scan QR).
        </p>
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
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_fonnte_queue_offline" name="wa_fonnte_queue_offline" value="1" <?= ($values['wa_fonnte_queue_offline'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_fonnte_queue_offline">Antrekan saat perangkat offline</label>
                </div>
                <div class="form-text">Pesan ditahan Fonnte sampai WA terhubung lagi (bukan gagal langsung).</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Mode notifikasi aplikasi</label>
                <select class="form-select" name="fcm_notify_mode">
                    <option value="both" <?= $notifyMode === 'both' ? 'selected' : '' ?>>WA + Push</option>
                    <option value="wa" <?= $notifyMode === 'wa' ? 'selected' : '' ?>>WA saja</option>
                    <option value="push" <?= $notifyMode === 'push' ? 'selected' : '' ?>>Push saja</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="wa_fonnte_api_delay">Delay antar kirim Fonnte (detik)</label>
                <input type="text" class="form-control font-monospace" id="wa_fonnte_api_delay" name="wa_fonnte_api_delay"
                    value="<?= htmlspecialchars((string) ($values['wa_fonnte_api_delay'] ?? '3')) ?>"
                    placeholder="3 atau 3-8">
                <div class="form-text">
                    Default untuk semua kategori. Override per kategori di tab Tagihan, Cashless, Presensi, Alpa, Poin, Izin, dan Template.
                    Parameter <code>delay</code> di API Fonnte. Contoh: <code>3</code> (tetap) atau <code>3-8</code> (acak).
                    Kosongkan untuk menonaktifkan. Disarankan 3–10 detik agar nomor tidak terdeteksi spam.
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_auto_web_fallback_enabled" name="wa_auto_web_fallback_enabled" value="1"
                        <?= ($values['wa_auto_web_fallback_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_auto_web_fallback_enabled">Fallback cron saat staf buka app</label>
                </div>
                <div class="form-text">Nonaktifkan setelah cron server aktif — navigasi lebih ringan. Job tetap jalan via <code>cron/wa_auto.php</code>.</div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" id="wa_dispatch_strict_mode" name="wa_dispatch_strict_mode" value="1"
                        <?= ($values['wa_dispatch_strict_mode'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_dispatch_strict_mode">Cegah duplikat WA otomatis (1x per kejadian)</label>
                </div>
                <div class="form-text">Ledger <code>wa_dispatch_log</code> — nonaktifkan hanya untuk debug.</div>
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
        <h2 class="h6 mb-2">3. Presensi &amp; petugas pendidikan</h2>
        <p class="small text-muted mb-3">
            Nomor untuk <strong>munawib belum hadir</strong>, <strong>kelas kosong</strong>, dan laporan presensi terkait.
            Notifikasi <strong>alpa</strong> dan <strong>permohonan izin</strong> diatur terpisah di tab masing-masing.
        </p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_penerima">
            <input type="hidden" name="redirect_tab" value="gateway">
            <div class="col-md-6">
                <label class="form-label">No. petugas pendidikan</label>
                <input type="text" class="form-control" name="wa_petugas_pendidikan" value="<?= htmlspecialchars((string) ($values['wa_petugas_pendidikan'] ?? '')) ?>">
                <div class="form-text">Munawib belum hadir, kelas kosong (nomor personal). Grup WA: tab <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=presensi')) ?>">Presensi</a>. Kosong = fallback nomor alpa (tab Alpa). Kelola di <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php?peran=petugas_pendidikan')) ?>">Nomor WhatsApp</a>.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Batas telat presensi (menit)</label>
                <input type="number" min="0" class="form-control" name="batas_telat_menit" value="<?= htmlspecialchars($values['batas_telat_menit']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Keterangan pengurus keuangan (di pesan tagihan)</label>
                <textarea class="form-control" name="keterangan_pengurus_bidang_keuangan" rows="2" maxlength="500"><?= htmlspecialchars((string) ($values['keterangan_pengurus_bidang_keuangan'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-light border small mt-3 mb-0">
    <strong>Penerima lain:</strong>
    <a href="?tab=alpa">Alpa otomatis</a> ·
    <a href="?tab=izin">Permohonan izin baru</a> ·
    <a href="?tab=izin">Izin disetujui</a>
</div>
