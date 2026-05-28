<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Form identitas pesantren</h2>
            <a href="/settings/wa_otomatis.php" class="btn btn-outline-primary btn-sm">Pusat WA Otomatis</a>
        </div>
        <form method="post" class="row g-3" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_settings">
            <div class="col-md-6">
                <label class="form-label">Nama pesantren <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="nama_ponpes" value="<?= htmlspecialchars($values['nama_ponpes']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis pendidikan</label>
                <input type="text" class="form-control" name="jenis_pendidikan" value="<?= htmlspecialchars($values['jenis_pendidikan']) ?>">
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
                <?php if (!empty($values['logo_path'])): ?>
                    <div class="mt-2">
                        <img src="<?= htmlspecialchars(app_href('/' . ltrim((string) $values['logo_path'], '/'))) ?>" alt="Logo pesantren" class="pondok-logo-preview">
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <button class="btn btn-success" type="submit">Simpan Identitas</button>
            </div>
        </form>
    </div>
</div>
