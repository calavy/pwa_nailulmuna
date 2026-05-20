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
                        <img src="/<?= htmlspecialchars($values['logo_path']) ?>" alt="Logo pesantren" style="height:64px; width:64px; object-fit:cover; border-radius:10px;">
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
            <div class="col-md-4">
                <label class="form-label">Jam Kirim WA Otomatis</label>
                <input type="time" class="form-control" name="jam_kirim_wa_auto" value="<?= htmlspecialchars($values['jam_kirim_wa_auto']) ?>">
                <div class="form-text">Kosongkan jika kirim langsung saat trigger.</div>
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
            <div class="col-md-3">
                <label class="form-label">Kalender Tagihan</label>
                <select class="form-select" name="wa_tagihan_calendar">
                    <option value="MASEHI" <?= strtoupper((string) $values['wa_tagihan_calendar']) === 'MASEHI' ? 'selected' : '' ?>>Masehi</option>
                    <option value="HIJRIYAH" <?= strtoupper((string) $values['wa_tagihan_calendar']) === 'HIJRIYAH' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tanggal Kirim</label>
                <input type="number" min="1" max="30" class="form-control" name="wa_tagihan_day" value="<?= htmlspecialchars((string) $values['wa_tagihan_day']) ?>">
                <div class="form-text">Rentang 1-30 agar valid untuk kalender Hijriyah dan Masehi.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Jam Kirim Tagihan</label>
                <input type="time" class="form-control" name="wa_tagihan_send_time" value="<?= htmlspecialchars($values['wa_tagihan_send_time']) ?>">
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
            <div class="col-12" id="tahun-masehi-acuan"><hr class="my-1"></div>
            <div class="col-12">
                <h2 class="h6 mb-1">Tahun Masehi default (rekap &amp; laporan)</h2>
                <p class="small text-muted mb-2">Saat membuka rekap/laporan tanpa memilih tahun di URL. Mode <strong>berjalan</strong> mengikuti tahun dari tanggal server.</p>
            </div>
            <div class="col-md-6">
                <label class="form-label small">Sumber tahun default</label>
                <select class="form-select form-select-sm" name="app_tahun_masehi_mode">
                    <option value="BERJALAN" <?= ($values['app_tahun_masehi_mode'] ?? '') === 'BERJALAN' ? 'selected' : '' ?>>Otomatis tahun Masehi berjalan</option>
                    <option value="TETAP" <?= ($values['app_tahun_masehi_mode'] ?? '') === 'TETAP' ? 'selected' : '' ?>>Tetap ke tahun tertentu</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small">Tahun Masehi tetap</label>
                <input type="number" min="1900" max="2100" class="form-control form-control-sm" name="app_tahun_masehi_tetap" value="<?= htmlspecialchars((string) ($values['app_tahun_masehi_tetap'] ?? '')) ?>">
                <div class="form-text">Hanya jika memilih &quot;Tetap ke tahun tertentu&quot;.</div>
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

