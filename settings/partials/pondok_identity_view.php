<?php require __DIR__ . '/pondok_theme_toggle.php'; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Identitas pesantren</h2>
            <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>" class="btn btn-outline-primary btn-sm">Pusat WA Otomatis</a>
        </div>
        <form method="post" class="row g-3" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_pesantren">
            <div class="col-md-6">
                <label class="form-label">Nama pesantren <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="nama_ponpes" value="<?= htmlspecialchars($values['nama_ponpes']) ?>" required>
                <div class="form-text">Tampil di navigasi, dashboard, kop surat, dan laporan.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis pendidikan</label>
                <input type="text" class="form-control" name="jenis_pendidikan" value="<?= htmlspecialchars($values['jenis_pendidikan']) ?>" placeholder="Contoh: Pondok Pesantren Putra / Putri">
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
                <div class="form-text">Format: JPG, PNG, WEBP. Latar putih pada file akan dihilangkan otomatis. Setelah <strong>Simpan</strong>, ikon PWA di layar utama ikut diperbarui — hapus pintasan PWA lama di HP lalu pasang ulang.</div>
                <?php if (!empty($values['logo_path'])): ?>
                    <div class="mt-2">
                        <img src="<?= htmlspecialchars(app_href('/' . ltrim((string) $values['logo_path'], '/'))) ?>" alt="Logo pesantren" class="pondok-logo-preview">
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Warna tema PWA (status bar)</label>
                <input type="color" class="form-control form-control-color w-100" name="pwa_theme_color"
                    value="<?= htmlspecialchars(($values['pwa_theme_color'] ?? '') !== '' ? (string) $values['pwa_theme_color'] : '#0f766e') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Warna latar pasang PWA</label>
                <input type="color" class="form-control form-control-color w-100" name="pwa_background_color"
                    value="<?= htmlspecialchars(($values['pwa_background_color'] ?? '') !== '' ? (string) $values['pwa_background_color'] : '#0d9488') ?>">
                <div class="form-text">Tampil saat aplikasi dibuka dari ikon di HP (splash).</div>
            </div>
            <div class="col-12">
                <button class="btn btn-success" type="submit">Simpan identitas</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Operasional presensi &amp; izin</h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_pesantren">
            <input type="hidden" name="nama_ponpes" value="<?= htmlspecialchars($values['nama_ponpes']) ?>">
            <div class="col-md-4">
                <label class="form-label">Password petugas presensi</label>
                <input type="password" class="form-control" name="presensi_password" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password">
                <div class="form-text">Hanya password. Akun pengurus di menu Pengguna.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori baik (max alpa)</label>
                <input type="number" min="0" class="form-control" name="kategori_baik_max" value="<?= htmlspecialchars($values['kategori_baik_max']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori sedang (max alpa)</label>
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
                <button class="btn btn-success" type="submit">Simpan operasional</button>
                <a href="<?= htmlspecialchars(app_href('/settings/admin.php')) ?>" class="btn btn-outline-secondary ms-1">Kelola akses user</a>
            </div>
        </form>
    </div>
</div>
