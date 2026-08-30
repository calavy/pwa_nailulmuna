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
                <textarea class="form-control" name="alamat_ponpes" rows="2" placeholder="Jl. ..., Kec. ..., Kab. ..."><?= htmlspecialchars((string) ($values['alamat_ponpes'] ?? '')) ?></textarea>
                <div class="form-text">Tampil di halaman login, kop surat, dan laporan. Bisa lebih dari satu baris.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Telepon (kop surat)</label>
                <input type="text" class="form-control" name="telp_ponpes" value="<?= htmlspecialchars((string) ($values['telp_ponpes'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Website (kop surat)</label>
                <input type="text" class="form-control" name="website_ponpes" value="<?= htmlspecialchars((string) ($values['website_ponpes'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kota (tempat TTD)</label>
                <input type="text" class="form-control" name="kota_ponpes" value="<?= htmlspecialchars((string) (($values['kota_ponpes'] ?? '') !== '' ? $values['kota_ponpes'] : 'Muntilan')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama pengasuh</label>
                <input type="text" class="form-control" name="nama_pengasuh" value="<?= htmlspecialchars($values['nama_pengasuh']) ?>">
                <div class="form-text">Tampil di blok tanda tangan surat cetak (pengasuh).</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Ketua yayasan (surat cetak)</label>
                <div class="form-control bg-light">
                    <?php if (($ketuaYayasanNama ?? '') !== ''): ?>
                        <?= htmlspecialchars((string) $ketuaYayasanNama) ?>
                    <?php else: ?>
                        <span class="text-muted">Belum diisi di struktur yayasan</span>
                    <?php endif; ?>
                </div>
                <div class="form-text">
                    Diambil otomatis dari jabatan <strong>Ketua Yayasan</strong> di
                    <a href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=yayasan')) ?>">SDM Kepengurusan Yayasan</a>.
                </div>
            </div>
            <div class="col-md-12">
                <label class="form-label">Logo pesantren</label>
                <input type="file" class="form-control" name="logo_file" accept=".jpg,.jpeg,.png,.webp">
                <div class="form-text">Format: JPG, PNG, WEBP. Latar putih dihilangkan otomatis; gambar diperkecil &amp; dikompres saat disimpan. Setelah <strong>Simpan</strong>, ikon PWA di layar utama ikut diperbarui — hapus pintasan PWA lama di HP lalu pasang ulang.<?php if (!empty($values['logo_path'])): ?> <span class="text-success">Logo aktif.</span><?php endif; ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Stempel surat resmi</label>
                <input type="file" class="form-control" name="stampel_surat_file" accept=".jpg,.jpeg,.png,.webp">
                <div class="form-text">Dipakai di blok tanda tangan surat izin dan surat cetak lain. PNG transparan disarankan. Kosongkan file = pakai stempel bawaan. <span class="<?= $stampelSuratConfigured ? 'text-success' : 'text-muted' ?>"><?= $stampelSuratConfigured ? 'Stempel custom aktif.' : 'Memakai stempel default.' ?></span></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Stempel bukti pembayaran / kuitansi</label>
                <input type="file" class="form-control" name="stampel_kuitansi_file" accept=".jpg,.jpeg,.png,.webp">
                <div class="form-text">Dipakai di kuitansi keuangan (print resmi &amp; download PNG). Terpisah dari stempel surat. <span class="<?= $stampelKuitansiConfigured ? 'text-success' : 'text-muted' ?>"><?= $stampelKuitansiConfigured ? 'Stempel custom aktif.' : 'Memakai stempel default.' ?></span></div>
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
            <?php foreach ($pondokIdentityPreserveFields as $preserveKey): ?>
                <input type="hidden" name="<?= htmlspecialchars($preserveKey) ?>" value="<?= htmlspecialchars((string) ($values[$preserveKey] ?? '')) ?>">
            <?php endforeach; ?>
            <div class="col-md-4">
                <label class="form-label">Password petugas presensi</label>
                <input type="password" class="form-control" name="presensi_password" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password">
                <div class="form-text">Hanya password. Akun pengurus di menu Pengguna.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tanggal mulai scan keaktivan</label>
                <input type="date" class="form-control" name="keaktifan_tanggal_mulai_scan" value="<?= htmlspecialchars((string) ($values['keaktifan_tanggal_mulai_scan'] ?? '')) ?>">
                <div class="form-text">
                    Rekap keaktivan (portal wali/santri, pembimbing, yayasan, rekap resmi), poin otomatis ALPA/telat,
                    dan hitungan ALPA untuk notifikasi WA (ambang/silang ambang)
                    hanya dihitung mulai tanggal ini. Data sebelum tanggal tetap tersimpan, tetapi tidak terhitung.
                    Kosongkan jika semua riwayat dihitung.
                    <?php if ($keaktifanScanSuggest !== ''): ?>
                        Presensi pertama di database: <strong><?= htmlspecialchars(app_format_tanggal_id($keaktifanScanSuggest)) ?></strong>.
                    <?php endif; ?>
                </div>
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
