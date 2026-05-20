<?php elseif ($tab === 'domisili'): ?>
<?php
$domisiliMengaji = santri_riwayat_domisili_list($pdo, $id, 'MENGAJI');
$domisiliKhidmah = santri_riwayat_domisili_list($pdo, $id, 'KHIDMAH');
$editDom = null;
$editDomId = (int) ($_GET['edit_domisili'] ?? 0);
if ($editDomId > 0) {
    foreach (array_merge($domisiliMengaji, $domisiliKhidmah) as $dr) {
        if ((int) ($dr['id'] ?? 0) === $editDomId) {
            $editDom = $dr;
            break;
        }
    }
}
?>
<div class="row g-3 santri-data-actions">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2"><strong>Domisili mengaji</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th class="ps-3">Gedung</th><th>Kamar</th><th>Ranjang</th><th>Periode</th><th class="text-end pe-3">Aksi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($domisiliMengaji as $dm): ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars((string) $dm['gedung']) ?></td>
                                <td><?= htmlspecialchars((string) $dm['nama_kamar']) ?></td>
                                <td class="small"><?= htmlspecialchars(trim((string) ($dm['no_ranjang'] ?? '')) !== '' ? (string) $dm['no_ranjang'] : '—') ?></td>
                                <td class="small"><?= htmlspecialchars(santri_riwayat_domisili_periode_label($dm)) ?></td>
                                <td class="text-end pe-3 text-nowrap btn-group-actions">
                                    <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= $id ?>&tab=domisili&edit_domisili=<?= (int) $dm['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus riwayat domisili ini?');">
                                        <input type="hidden" name="action" value="delete_domisili">
                                        <input type="hidden" name="domisili_id" value="<?= (int) $dm['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($domisiliMengaji === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada riwayat domisili mengaji.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Domisili khidmah / pengabdian</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th class="ps-3">Gedung</th><th>Kamar</th><th>Ranjang</th><th>Periode</th><th class="text-end pe-3">Aksi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($domisiliKhidmah as $dk): ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars((string) $dk['gedung']) ?></td>
                                <td><?= htmlspecialchars((string) $dk['nama_kamar']) ?></td>
                                <td class="small"><?= htmlspecialchars(trim((string) ($dk['no_ranjang'] ?? '')) !== '' ? (string) $dk['no_ranjang'] : '—') ?></td>
                                <td class="small"><?= htmlspecialchars(santri_riwayat_domisili_periode_label($dk)) ?></td>
                                <td class="text-end pe-3 text-nowrap btn-group-actions">
                                    <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= $id ?>&tab=domisili&edit_domisili=<?= (int) $dk['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus riwayat domisili ini?');">
                                        <input type="hidden" name="action" value="delete_domisili">
                                        <input type="hidden" name="domisili_id" value="<?= (int) $dk['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($domisiliKhidmah === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada riwayat domisili khidmah.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong><?= $editDom ? 'Edit domisili' : 'Tambah domisili' ?></strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_domisili">
                    <?php if ($editDom): ?>
                        <input type="hidden" name="domisili_id" value="<?= (int) $editDom['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label">Jenis domisili</label>
                        <select name="jenis_domisili" class="form-select form-select-sm" required>
                            <?php foreach (santri_riwayat_domisili_jenis_options() as $jopt): ?>
                                <option value="<?= htmlspecialchars($jopt) ?>" <?= (($editDom['jenis_domisili'] ?? 'MENGAJI') === $jopt) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(santri_riwayat_domisili_jenis_label($jopt)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Gedung / lokasi</label>
                        <input type="text" name="gedung" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editDom['gedung'] ?? $gedungDefault)) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama kamar / unit</label>
                        <input type="text" name="nama_kamar" class="form-control form-control-sm" required value="<?= htmlspecialchars((string) ($editDom['nama_kamar'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">No. kasur / ranjang</label>
                        <input type="text" name="no_ranjang" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editDom['no_ranjang'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA mulai (tahun)</label>
                        <input type="number" name="tahun_ajaran_mulai" class="form-control form-control-sm" min="2000" max="2100"
                               value="<?= (int) ($editDom['tahun_ajaran_mulai'] ?? $taAktif['mulai']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">TA selesai (opsional)</label>
                        <input type="number" name="tahun_ajaran_selesai" class="form-control form-control-sm" min="2000" max="2100"
                               value="<?= !empty($editDom['tahun_ajaran_selesai']) ? (int) $editDom['tahun_ajaran_selesai'] : '' ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editDom['tanggal_mulai'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editDom['tanggal_selesai'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="catatan" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($editDom['catatan'] ?? '')) ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Simpan</button>
                        <?php if ($editDom): ?>
                            <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= $id ?>&tab=domisili" class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
                <p class="small text-muted mt-2 mb-0">Data asrama lama disalin otomatis ke domisili mengaji saat pertama kali dibuka.</p>
            </div>
        </div>
    </div>
</div>
