<?php elseif ($tab === 'asrama'): ?>
<div class="row g-3 santri-data-actions">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Riwayat penempatan asrama</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Gedung</th>
                                <th>Kamar</th>
                                <th>Kasur / ranjang</th>
                                <th>Periode</th>
                                <th class="text-end pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($asramaRows as $ar): ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars((string) ($ar['gedung'] ?? 'Asrama')) ?></td>
                                <td><?= htmlspecialchars((string) $ar['nama_kamar']) ?></td>
                                <td class="small"><?= htmlspecialchars(trim((string) ($ar['no_ranjang'] ?? '')) !== '' ? (string) $ar['no_ranjang'] : '—') ?></td>
                                <td class="small"><?= htmlspecialchars(santri_riwayat_asrama_periode_label($ar)) ?></td>
                                <td class="text-end pe-3 text-nowrap btn-group-actions">
                                    <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= $id ?>&tab=asrama&edit_asrama=<?= (int) $ar['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus riwayat asrama ini?');">
                                        <input type="hidden" name="action" value="delete_asrama">
                                        <input type="hidden" name="asrama_id" value="<?= (int) $ar['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($asramaRows === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada riwayat. Tambah manual atau ubah kamar di edit santri.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong><?= $editAsrama ? 'Edit penempatan' : 'Tambah penempatan' ?></strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_asrama">
                    <?php if ($editAsrama): ?>
                        <input type="hidden" name="asrama_id" value="<?= (int) $editAsrama['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label">Gedung</label>
                        <input type="text" name="gedung" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editAsrama['gedung'] ?? $gedungDefault)) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama kamar</label>
                        <input type="text" name="nama_kamar" class="form-control form-control-sm" required
                               value="<?= htmlspecialchars((string) ($editAsrama['nama_kamar'] ?? $santri['nama_kamar'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">No. kasur / ranjang</label>
                        <input type="text" name="no_ranjang" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string) ($editAsrama['no_ranjang'] ?? $santri['no_ranjang'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA mulai (tahun)</label>
                        <input type="number" name="tahun_ajaran_mulai" class="form-control form-control-sm" min="2000" max="2100"
                               value="<?= (int) ($editAsrama['tahun_ajaran_mulai'] ?? $taAktif['mulai']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA selesai (opsional)</label>
                        <input type="number" name="tahun_ajaran_selesai" class="form-control form-control-sm" min="2000" max="2100"
                               value="<?= !empty($editAsrama['tahun_ajaran_selesai']) ? (int) $editAsrama['tahun_ajaran_selesai'] : '' ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="catatan" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editAsrama['catatan'] ?? '')) ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Simpan</button>
                        <?php if ($editAsrama): ?>
                            <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= $id ?>&tab=asrama" class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
