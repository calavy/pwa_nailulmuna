<?php
/** @var array<string, string> $values */
/** @var array<string, mixed>|null $waTestResult */
/** @var string $appNama */
?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Pengaturan WhatsApp</h2>
            <a href="<?= htmlspecialchars(app_href('/settings/pesantren.php')) ?>" class="btn btn-outline-secondary btn-sm">Profil Pondok</a>
        </div>
        <?php if (is_array($waTestResult)): ?>
            <div class="alert alert-<?= !empty($waTestResult['success']) ? 'success' : 'danger' ?>">
                <strong>Hasil tes WA:</strong><br>
                Target: <?= htmlspecialchars((string) ($waTestResult['target'] ?? '-')) ?><br>
                HTTP: <?= (int) ($waTestResult['http_code'] ?? 0) ?><br>
                Status: <?= !empty($waTestResult['success']) ? 'Berhasil' : 'Gagal' ?><br>
                Error: <?= htmlspecialchars((string) (($waTestResult['error'] ?? '') !== '' ? $waTestResult['error'] : '-')) ?><br>
                Respon: <code><?= htmlspecialchars((string) ($waTestResult['response'] ?? '')) ?></code>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_wa_settings">
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0">Gateway</h3>
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Gateway URL</label>
                <input type="text" class="form-control" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
                <div class="form-text">Kosong + token terisi → Fonnte <code>api.fonnte.com/send</code></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Gateway Token</label>
                <input type="text" class="form-control" name="wa_gateway_token" value="<?= htmlspecialchars($values['wa_gateway_token']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Sender</label>
                <input type="text" class="form-control" name="wa_sender" value="<?= htmlspecialchars($values['wa_sender']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA Pengurus (notifikasi alpa)</label>
                <input type="text" class="form-control" name="wa_pengurus" value="<?= htmlspecialchars($values['wa_pengurus']) ?>">
                <div class="form-text">Beberapa nomor: pisahkan koma atau spasi.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA Petugas Pendidikan</label>
                <input type="text" class="form-control" name="wa_petugas_pendidikan" value="<?= htmlspecialchars((string) ($values['wa_petugas_pendidikan'] ?? '')) ?>">
                <div class="form-text">Izin pembimbing / mudabir. Kosong = pakai No WA Pengurus.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam kirim WA otomatis</label>
                <input type="time" class="form-control input-time-24" name="jam_kirim_wa_auto" value="<?= htmlspecialchars(app_format_jam($values['jam_kirim_wa_auto'])) ?>">
                <div class="form-text">Kosong = kirim langsung saat trigger.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Notif mudabir belum hadir</label>
                <select class="form-select" name="wa_notif_mudabir_enabled">
                    <option value="1" <?= ($values['wa_notif_mudabir_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_notif_mudabir_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas mudabir (menit)</label>
                <input type="number" min="5" max="180" class="form-control" name="mudabir_batas_menit" value="<?= htmlspecialchars((string) (($values['mudabir_batas_menit'] ?? '') !== '' ? $values['mudabir_batas_menit'] : '30')) ?>">
            </div>
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0 mt-1">Kelas kosong (pembimbing &amp; munawib tidak masuk)</h3>
            </div>
            <div class="col-md-3">
                <label class="form-label">Aktifkan</label>
                <select class="form-select" name="wa_kelas_kosong_enabled">
                    <option value="1" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Batas awal (menit)</label>
                <input type="number" min="5" max="180" class="form-control" name="wa_kelas_kosong_batas_menit" value="<?= htmlspecialchars((string) (($values['wa_kelas_kosong_batas_menit'] ?? '') !== '' ? $values['wa_kelas_kosong_batas_menit'] : '20')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan ke-1</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_1" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_1'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan ke-3</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_3" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_3'] ?? '')) ?>">
            </div>
            <?php
            $kelasKosongLastAtRaw = trim((string) ($values['wa_kelas_kosong_last_sent_at'] ?? ''));
            $kelasKosongLastLevel = trim((string) ($values['wa_kelas_kosong_last_level'] ?? ''));
            $kelasKosongLastLabel = '-';
            if ($kelasKosongLastAtRaw !== '') {
                $tsKelasKosong = strtotime($kelasKosongLastAtRaw);
                $kelasKosongLastLabel = $tsKelasKosong ? date('d/m/Y H:i', $tsKelasKosong) : $kelasKosongLastAtRaw;
            }
            ?>
            <div class="col-12">
                <div class="alert alert-secondary py-2 small mb-0">
                    <strong>Kirim terakhir kelas kosong:</strong> <?= htmlspecialchars($kelasKosongLastLabel) ?>
                    <?php if ($kelasKosongLastLevel !== ''): ?> · level <?= htmlspecialchars($kelasKosongLastLevel) ?><?php endif; ?>
                    · <a href="<?= htmlspecialchars(app_href('/settings/wa_laporan_kelas_kosong.php')) ?>">Lihat riwayat</a>
                </div>
            </div>
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0 mt-1">Tagihan otomatis ke wali</h3>
            </div>
            <div class="col-md-4">
                <label class="form-label">Auto tagihan wali</label>
                <select class="form-select" name="wa_tagihan_auto_enabled">
                    <option value="1" <?= $values['wa_tagihan_auto_enabled'] === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= $values['wa_tagihan_auto_enabled'] !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-8">
                <div class="alert alert-secondary small mb-0 py-2">
                    Jadwal kalender &amp; tanggal kirim tagihan di
                    <a href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>" class="fw-semibold">Pengaturan Kalender</a>.
                    Tier alpa di <a href="<?= htmlspecialchars(app_href('/settings/alpa_notif.php')) ?>">Notifikasi Alpa</a>.
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Keterangan Pengurus Bidang Keuangan (pesan WA)</label>
                <textarea class="form-control" name="keterangan_pengurus_bidang_keuangan" rows="2" maxlength="500"><?= htmlspecialchars((string) ($values['keterangan_pengurus_bidang_keuangan'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas alpa kirim WA</label>
                <input type="number" min="1" class="form-control" name="batas_alpa_notif" value="<?= htmlspecialchars($values['batas_alpa_notif']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas telat (menit)</label>
                <input type="number" min="0" class="form-control" name="batas_telat_menit" value="<?= htmlspecialchars($values['batas_telat_menit']) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-success" type="submit">Simpan pengaturan WA</button>
            </div>
        </form>
        <hr class="my-4">
        <h2 class="h6 mb-3">Uji kirim WA (satu nomor)</h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="test_wa">
            <div class="col-md-6">
                <label class="form-label">WA Gateway URL</label>
                <input type="text" class="form-control" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Gateway Token</label>
                <input type="text" class="form-control" name="wa_gateway_token" value="<?= htmlspecialchars($values['wa_gateway_token']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">WA Sender</label>
                <input type="text" class="form-control" name="wa_sender" value="<?= htmlspecialchars($values['wa_sender']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Nomor tujuan tes</label>
                <input type="text" class="form-control" name="wa_test_target" placeholder="08xxxxxxxxxx" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Pesan tes</label>
                <input type="text" class="form-control" name="wa_test_message" value="Tes WA dari <?= htmlspecialchars($appNama) ?>.">
            </div>
            <div class="col-12">
                <button class="btn btn-outline-primary" type="submit">Kirim tes WA</button>
            </div>
        </form>
    </div>
</div>
