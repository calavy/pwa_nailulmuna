<?php
/** @var array<string, mixed>|null $editRow */
/** @var array<string, string> $filters */
$isEdit = $editRow !== null;
?>
<div id="alumni-form-panel" class="alumni-form-panel collapse<?= $showFormPanel ? ' show' : '' ?>">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <h2 class="h5 mb-0"><?= $isEdit ? 'Ubah alumni' : 'Tambah alumni' ?></h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#alumni-form-panel" aria-expanded="<?= $showFormPanel ? 'true' : 'false' ?>" aria-controls="alumni-form-panel">Tutup</button>
            </div>
            <form method="post" class="d-grid gap-2">
                <input type="hidden" name="action" value="simpan">
                <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                <?php alumni_render_filter_hiddens($filters); ?>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">NIS <span class="text-danger">*</span></label>
                        <input type="text" name="nis" class="form-control form-control-lg" required maxlength="32" value="<?= htmlspecialchars((string) ($editRow['nis'] ?? '')) ?>" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <input type="text" name="nama" class="form-control form-control-lg" required maxlength="200" value="<?= htmlspecialchars((string) ($editRow['nama'] ?? '')) ?>">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Dusun</label>
                        <input type="text" name="dusun" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['dusun'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">RT/RW</label>
                        <input type="text" name="rt_rw" class="form-control" maxlength="20" placeholder="001/002" value="<?= htmlspecialchars((string) ($editRow['rt_rw'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Desa/Kelurahan</label>
                        <input type="text" name="desa_kelurahan" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['desa_kelurahan'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Kecamatan</label>
                        <input type="text" name="kecamatan" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['kecamatan'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Kabupaten</label>
                        <input type="text" name="kabupaten" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['kabupaten'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Propinsi</label>
                        <input type="text" name="propinsi" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['propinsi'] ?? '')) ?>">
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Th. masuk</label>
                        <input type="number" name="th_masuk" class="form-control" min="1900" max="2100" step="1" value="<?= htmlspecialchars((string) ($editRow['th_masuk'] ?? '')) ?>" inputmode="numeric">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Th. keluar</label>
                        <input type="number" name="th_keluar" class="form-control" min="1900" max="2100" step="1" value="<?= htmlspecialchars((string) ($editRow['th_keluar'] ?? '')) ?>" inputmode="numeric">
                    </div>
                </div>
                <div>
                    <label class="form-label">Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="2"><?= htmlspecialchars((string) ($editRow['keterangan'] ?? '')) ?></textarea>
                </div>
                <div class="d-flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="btn btn-primary flex-grow-1">Simpan</button>
                    <?php if ($isEdit): ?>
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(alumni_page_url(['edit' => ''], $filters ?? [])) ?>">Batal</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#alumni-form-panel">Batal</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
