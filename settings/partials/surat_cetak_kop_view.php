<?php

declare(strict_types=1);

/** @var array<string, string> $kopValues */
/** @var array<string, mixed> $kopPreview */
/** @var string $accentPreview */

?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Pengaturan kop</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save_kop">
                    <div class="col-md-6">
                        <label class="form-label">Telepon</label>
                        <input type="text" class="form-control" name="telp_ponpes" value="<?= htmlspecialchars($kopValues['telp_ponpes']) ?>" placeholder="Contoh: 0293-123456">
                        <div class="form-text">Tampil di baris kontak kop surat.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Website</label>
                        <input type="text" class="form-control" name="website_ponpes" value="<?= htmlspecialchars($kopValues['website_ponpes']) ?>" placeholder="Contoh: www.pondokpesantren.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kota (tempat TTD)</label>
                        <input type="text" class="form-control" name="kota_ponpes" value="<?= htmlspecialchars($kopValues['kota_ponpes']) ?>" placeholder="Muntilan">
                        <div class="form-text">Dipakai di baris tempat/tanggal surat (mis. Muntilan, 25-06-2026).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Warna aksen kop default</label>
                        <input type="color" class="form-control form-control-color w-100" name="kop_accent_color" value="<?= htmlspecialchars($accentPreview) ?>">
                        <div class="form-text">Garis bawah kop &amp; judul. Surat izin tetap memakai warna kategori sendiri.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Label jenis lembaga (cadangan)</label>
                        <input type="text" class="form-control" name="kop_jenis_fallback" value="<?= htmlspecialchars($kopValues['kop_jenis_fallback']) ?>" placeholder="Lembaga Pondok Pesantren">
                        <div class="form-text">Dipakai jika kolom jenis pendidikan di Profil Pondok kosong.</div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan kop</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Pratinjau kop</h2>
                <style><?= pondok_kop_surat_css($accentPreview, (string) ($kopPreview['logo_href'] ?? '')) ?></style>
                <div class="border rounded p-3 bg-white">
                    <?= pondok_kop_surat_html($kopPreview, $accentPreview) ?>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    Nama: <strong><?= htmlspecialchars((string) ($kopPreview['nama_ponpes'] ?? '')) ?></strong><br>
                    Alamat: <?= htmlspecialchars((string) ($kopPreview['alamat_ponpes'] ?? '—')) ?><br>
                    Ketua yayasan (TTD surat):
                    <strong><?= ($kopPreview['nama_ketua_yayasan'] ?? '') !== '' ? htmlspecialchars((string) $kopPreview['nama_ketua_yayasan']) : '—' ?></strong>
                    · <a href="<?= htmlspecialchars(app_href('/yayasan/sdm.php?tab=yayasan')) ?>">Ubah di SDM Yayasan</a><br>
                    <a href="<?= htmlspecialchars(app_href('/settings/pesantren.php')) ?>">Ubah nama, alamat, logo &rarr;</a>
                </p>
            </div>
        </div>
    </div>
</div>
