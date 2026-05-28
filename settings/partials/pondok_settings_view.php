<div class="card shadow-sm mb-3" id="theme-settings-card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h5 mb-1">Mode tampilan</h2>
                <p class="small text-muted mb-0">Pilih antara mode terang atau gelap. Pilihan ini disimpan di perangkat ini.</p>
            </div>
            <div class="btn-group app-theme-switch" role="group" aria-label="Pilih mode tampilan">
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-light" value="light" autocomplete="off">
                <label class="btn btn-outline-primary" for="theme-mode-light">
                    <span aria-hidden="true">&#9728;</span>
                    <span class="ms-1">Light Mode</span>
                </label>
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-dark" value="dark" autocomplete="off">
                <label class="btn btn-outline-primary" for="theme-mode-dark">
                    <span aria-hidden="true">&#9790;</span>
                    <span class="ms-1">Dark Mode</span>
                </label>
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        const radios = document.querySelectorAll('input[name="theme-mode"]');
        if (!radios.length) return;
        function currentMode() {
            return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        }
        function syncRadios(mode) {
            radios.forEach(function (r) { r.checked = (r.value === mode); });
        }
        syncRadios(currentMode());
        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (!r.checked) return;
                const next = r.value === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('theme-mode', next); } catch (e) {}
            });
        });
    })();
</script>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Form pengaturan</h2>
            <a href="/settings/admin.php" class="btn btn-outline-primary btn-sm">Kelola akses user</a>
        </div>
        <?php if (is_array($waTestResult)): ?>
            <div class="alert alert-<?= !empty($waTestResult['success']) ? 'success' : 'danger' ?>">
                <strong>Hasil Tes WA:</strong><br>
                Target: <?= htmlspecialchars((string) ($waTestResult['target'] ?? '-')) ?><br>
                HTTP: <?= (int) ($waTestResult['http_code'] ?? 0) ?><br>
                Status: <?= !empty($waTestResult['success']) ? 'BERHASIL' : 'GAGAL' ?><br>
                Error: <?= htmlspecialchars((string) (($waTestResult['error'] ?? '') !== '' ? $waTestResult['error'] : '-')) ?><br>
                Respon Gateway: <code><?= htmlspecialchars((string) ($waTestResult['response'] ?? '')) ?></code>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_settings">
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0">Identitas pesantren</h3>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama pesantren <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="nama_ponpes" value="<?= htmlspecialchars($values['nama_ponpes']) ?>" required>
                <div class="form-text">Tampil di navigasi, dashboard, kop surat, dan laporan.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis pendidikan</label>
                <input type="text" class="form-control" name="jenis_pendidikan" value="<?= htmlspecialchars($values['jenis_pendidikan']) ?>" placeholder="Contoh: Pondok Pesantren Putra / Putri">
                <div class="form-text">Subjudul di bawah nama pesantren pada bar navigasi.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Alamat pesantren</label>
                <input type="text" class="form-control" name="alamat_ponpes" value="<?= htmlspecialchars($values['alamat_ponpes']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama pengasuh</label>
                <input type="text" class="form-control" name="nama_pengasuh" value="<?= htmlspecialchars($values['nama_pengasuh']) ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label">Logo pesantren</label>
                <input type="file" class="form-control" name="logo_file" accept=".jpg,.jpeg,.png,.webp">
                <div class="form-text">Upload dari komputer/ponsel. Format: JPG, PNG, WEBP.</div>
                <?php if (!empty($values['logo_path'])): ?>
                    <div class="mt-2">
                        <img src="<?= htmlspecialchars(app_href('/' . ltrim((string) $values['logo_path'], '/'))) ?>" alt="Logo pesantren" class="pondok-logo-preview">
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0 mt-1">WhatsApp gateway</h3>
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Gateway URL</label>
                <input type="text" class="form-control" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
                <div class="form-text">Opsional. Jika kosong tapi token terisi, sistem otomatis memakai endpoint Fonnte: https://api.fonnte.com/send</div>
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
                <label class="form-label">No WA Pengurus (tujuan notifikasi alpa)</label>
                <input type="text" class="form-control" name="wa_pengurus" value="<?= htmlspecialchars($values['wa_pengurus']) ?>">
                <div class="form-text">Bisa lebih dari satu nomor. Pisahkan dengan koma, titik koma, atau spasi.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA Petugas Pendidikan (izin pembimbing / mudabir)</label>
                <input type="text" class="form-control" name="wa_petugas_pendidikan" value="<?= htmlspecialchars((string) ($values['wa_petugas_pendidikan'] ?? '')) ?>">
                <div class="form-text">Tujuan laporan saat pembimbing izin dan saat mudabir belum scan. Kosong = pakai No WA Pengurus.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam Kirim WA Otomatis</label>
                <input type="time" class="form-control" name="jam_kirim_wa_auto" value="<?= htmlspecialchars($values['jam_kirim_wa_auto']) ?>">
                <div class="form-text">Kosongkan jika kirim langsung saat trigger.</div>
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
                <div class="form-text">Contoh 30 = kirim laporan jika 30 menit dari jam mulai belum ada scan mudabir.</div>
            </div>
            <div class="col-12 mt-1">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0">Notifikasi kelas kosong (pembimbing &amp; munawib tidak masuk)</h3>
            </div>
            <div class="col-md-3">
                <label class="form-label">Aktifkan notifikasi</label>
                <select class="form-select" name="wa_kelas_kosong_enabled">
                    <option value="1" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Batas awal (menit)</label>
                <input type="number" min="5" max="180" class="form-control" name="wa_kelas_kosong_batas_menit" value="<?= htmlspecialchars((string) (($values['wa_kelas_kosong_batas_menit'] ?? '') !== '' ? $values['wa_kelas_kosong_batas_menit'] : '20')) ?>">
                <div class="form-text">Mulai hitung laporan setelah X menit dari jam mulai jadwal.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan ke-1</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_1" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_1'] ?? '')) ?>" placeholder="08xxxxxxxxxx">
                <div class="form-text">Nomor khusus laporan kelas kosong (bisa beda dari WA notifikasi alpa santri).</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan ke-3</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_3" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_3'] ?? '')) ?>" placeholder="08xxxxxxxxxx">
                <div class="form-text">Bisa isi lebih dari satu nomor (pisahkan koma/spasi).</div>
            </div>
            <div class="col-12">
                <?php
                $kelasKosongLastAtRaw = trim((string) ($values['wa_kelas_kosong_last_sent_at'] ?? ''));
                $kelasKosongLastLevel = trim((string) ($values['wa_kelas_kosong_last_level'] ?? ''));
                $kelasKosongLastLabel = '-';
                if ($kelasKosongLastAtRaw !== '') {
                    $tsKelasKosong = strtotime($kelasKosongLastAtRaw);
                    $kelasKosongLastLabel = $tsKelasKosong ? date('d/m/Y H:i', $tsKelasKosong) : $kelasKosongLastAtRaw;
                }
                ?>
                <div class="alert alert-light border py-2 small mb-0">
                    <strong>Status kirim terakhir kelas kosong:</strong>
                    <?= htmlspecialchars($kelasKosongLastLabel) ?>
                    <?php if ($kelasKosongLastLevel !== ''): ?>
                        · level laporan ke-<?= htmlspecialchars($kelasKosongLastLevel) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12">
                <h3 class="h6 text-primary border-bottom pb-2 mb-0 mt-2">Tagihan otomatis ke wali (WA)</h3>
            </div>
            <div class="col-md-3">
                <label class="form-label">Auto tagihan wali</label>
                <select class="form-select" name="wa_tagihan_auto_enabled">
                    <option value="1" <?= $values['wa_tagihan_auto_enabled'] === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= $values['wa_tagihan_auto_enabled'] !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-light border small mb-0 py-2">
                    <strong>Kalender &amp; jadwal tagihan</strong> (jenis kalender, tanggal/jam kirim tagihan, tahun rekap, libur akademik) dikelola di
                    <a href="/settings/kalender.php" class="fw-semibold">Pengaturan Kalender</a>.
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Keterangan Pengurus Bidang Keuangan</label>
                <textarea class="form-control" name="keterangan_pengurus_bidang_keuangan" rows="2" maxlength="500" placeholder="Teks di bawah nama Pengurus Bidang Keuangan pada pesan WA tagihan otomatis"><?= htmlspecialchars((string) ($values['keterangan_pengurus_bidang_keuangan'] ?? '')) ?></textarea>
                <div class="form-text">Ditampilkan di pesan WhatsApp tagihan otomatis, tepat di bawah frasa <em>Pengurus Bidang Keuangan</em>.</div>
            </div>
            <hr class="my-2">
            <div class="col-md-4">
                <label class="form-label">Batas Alpa Kirim WA</label>
                <input type="number" min="1" class="form-control" name="batas_alpa_notif" value="<?= htmlspecialchars($values['batas_alpa_notif']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas Telat (menit)</label>
                <input type="number" min="0" class="form-control" name="batas_telat_menit" value="<?= htmlspecialchars($values['batas_telat_menit']) ?>" placeholder="15">
            </div>
            <div class="col-12"><hr class="my-1"><h2 class="h6 mb-1">Login &amp; akses</h2></div>
            <div class="col-md-4">
                <label class="form-label">Password petugas presensi</label>
                <input type="password" class="form-control" name="presensi_password" placeholder="Kosongkan jika tidak ingin mengubah">
                <div class="form-text">Hanya password (tanpa username). Kosongkan jika tidak ingin mengubah. Akun pengurus/pembimbing di menu Pengguna.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori Baik (max alpa)</label>
                <input type="number" min="0" class="form-control" name="kategori_baik_max" value="<?= htmlspecialchars($values['kategori_baik_max']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori Sedang (max alpa)</label>
                <input type="number" min="0" class="form-control" name="kategori_sedang_max" value="<?= htmlspecialchars($values['kategori_sedang_max']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Maks. perpanjangan izin (hari)</label>
                <input type="number" min="1" max="90" class="form-control" name="izin_perpanjangan_max_hari" value="<?= htmlspecialchars((string) (($values['izin_perpanjangan_max_hari'] ?? '') !== '' ? $values['izin_perpanjangan_max_hari'] : '7')) ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label">Jenis izin boleh diperpanjang (koma)</label>
                <input type="text" class="form-control" name="izin_perpanjangan_jenis" value="<?= htmlspecialchars((string) (($values['izin_perpanjangan_jenis'] ?? '') !== '' ? $values['izin_perpanjangan_jenis'] : 'SAKIT,KELUAR')) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-success" type="submit">Simpan Pengaturan</button>
            </div>
        </form>
        <hr class="my-4">
        <h2 class="h5 mb-3">Uji Kirim WA (1 Nomor)</h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="test_wa">
            <div class="col-md-6">
                <label class="form-label">WA Gateway URL</label>
                <input type="text" class="form-control" name="wa_gateway_url" value="<?= htmlspecialchars($values['wa_gateway_url']) ?>">
                <div class="form-text">Opsional. Kosongkan jika menggunakan token Fonnte saja.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">WA Gateway Token</label>
                <input type="text" class="form-control" name="wa_gateway_token" value="<?= htmlspecialchars($values['wa_gateway_token']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">WA Sender (opsional)</label>
                <input type="text" class="form-control" name="wa_sender" value="<?= htmlspecialchars($values['wa_sender']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Nomor Tujuan Tes</label>
                <input type="text" class="form-control" name="wa_test_target" placeholder="08xxxxxxxxxx / 62xxxxxxxxxx" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Pesan Tes</label>
                <input type="text" class="form-control" name="wa_test_message" value="Tes WA dari <?= htmlspecialchars($appNama) ?>.">
            </div>
            <div class="col-12">
                <button class="btn btn-outline-primary" type="submit">Kirim Tes WA</button>
            </div>
        </form>
    </div>
</div>

