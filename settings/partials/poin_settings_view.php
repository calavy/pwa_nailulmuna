<?php require __DIR__ . '/../../includes/partials/keaktifan_alpa_tanpa_scan_toggle.php'; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rule poin</div>
            <div class="app-mini-stat-value"><?= $totalRules ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Ambang sanksi</div>
            <div class="app-mini-stat-value"><?= $totalSanctions ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Auto ALPA</div>
            <div class="app-mini-stat-value text-danger">+<?= $pointAutoAlpa ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Auto TELAT</div>
            <div class="app-mini-stat-value text-warning">+<?= $pointAutoTelat ?></div>
        </div>
    </div>
</div>
<div class="alert alert-info small py-2">
    WA otomatis ke pengurus saat poin mencapai ambang (5, 10, 15, …) + jam kirim per ambang:
    <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=poin')) ?>">Pengaturan WA → Poin</a>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h6">Poin dari Presensi (tarik manual)</h1>
                <p class="small text-muted">Default: petugas menarik alpa/telat lewat form Input Poin. Angka di bawah = bobot per kejadian saat tombol <strong>Tarik ke poin</strong>.</p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_auto">
                    <div class="col-12">
                        <label class="form-label">Poin per ALPA (+)</label>
                        <input type="number" min="0" class="form-control" name="point_auto_alpa" value="<?= $pointAutoAlpa ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Poin per TELAT (+)</label>
                        <input type="number" min="0" class="form-control" name="point_auto_telat" value="<?= $pointAutoTelat ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Periode ringkasan di form</label>
                        <select class="form-select" name="point_presensi_periode">
                            <option value="bulan" <?= ($pointPresensiPeriode ?? 'bulan') === 'bulan' ? 'selected' : '' ?>>Bulan berjalan</option>
                            <option value="minggu" <?= ($pointPresensiPeriode ?? '') === 'minggu' ? 'selected' : '' ?>>Minggu berjalan</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="point_presensi_auto_sync" name="point_presensi_auto_sync" value="1" <?= ($pointPresensiAutoSync ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="point_presensi_auto_sync">Legacy: auto-sync background (tidak disarankan)</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Tambah Rule Penambahan Poin</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rule">
                    <input type="hidden" name="jenis_rule" value="PLUS">
                    <div class="col-md-3"><input type="text" class="form-control" name="kode_rule" placeholder="Kode"></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="kategori" placeholder="Kategori" required></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="nama_rule" placeholder="Nama rule" required></div>
                    <div class="col-md-3"><input type="number" min="1" class="form-control" name="bobot_poin" placeholder="Poin" required></div>
                    <div class="col-md-9"><input type="text" class="form-control" name="contoh_pelanggaran" placeholder="Contoh pelanggaran"></div>
                    <div class="col-md-3"><input type="number" class="form-control" name="urutan" placeholder="Urutan"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm">Tambah rule penambahan</button></div>
                </form>
                <hr>
                <h2 class="h6">Tambah Rule Pengurangan Poin (Remedial)</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rule">
                    <input type="hidden" name="jenis_rule" value="MINUS">
                    <div class="col-md-3"><input type="text" class="form-control" name="kode_rule" placeholder="Kode"></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="kategori" placeholder="Kategori remedial" required></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="nama_rule" placeholder="Nama kriteria" required></div>
                    <div class="col-md-3"><input type="number" min="1" class="form-control" name="bobot_poin" placeholder="Poin dikurangi" required></div>
                    <div class="col-md-9"><input type="text" class="form-control" name="contoh_pelanggaran" placeholder="Contoh tindakan remedial"></div>
                    <div class="col-md-3"><input type="number" class="form-control" name="urutan" placeholder="Urutan"></div>
                    <div class="col-12"><button class="btn btn-outline-success btn-sm">Tambah rule pengurangan</button></div>
                </form>
                <hr>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Jenis</th><th>Kode</th><th>Kategori</th><th>Rule</th><th>Poin</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $r): ?>
                        <?php $jr = strtoupper((string) ($r['jenis_rule'] ?? 'PLUS')); ?>
                        <tr>
                            <td><span class="badge text-bg-<?= $jr === 'MINUS' ? 'success' : 'danger' ?>"><?= $jr === 'MINUS' ? 'Kurang' : 'Tambah' ?></span></td>
                            <td><?= htmlspecialchars($r['kode_rule']) ?></td>
                            <td><?= htmlspecialchars($r['kategori']) ?></td>
                            <td><?= htmlspecialchars($r['nama_rule']) ?></td>
                            <td><?= (int) $r['bobot_poin'] ?></td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Hapus rule ini?')">
                                    <input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6">Tambah Ambang Sanksi</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_sanction">
                    <div class="col-md-3"><input type="number" min="1" class="form-control" name="ambang_poin" placeholder="Ambang poin" required></div>
                    <div class="col-md-7"><input type="text" class="form-control" name="tindakan" placeholder="Tindakan / Ta'zir" required></div>
                    <div class="col-md-2"><input type="number" class="form-control" name="urutan" placeholder="Urutan"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm">Tambah Ambang</button></div>
                </form>
                <hr>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Ambang</th><th>Tindakan</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($sanctions as $s): ?>
                        <tr>
                            <td><?= (int) $s['ambang_poin'] ?></td>
                            <td><?= htmlspecialchars($s['tindakan']) ?></td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Hapus ambang ini?')">
                                    <input type="hidden" name="action" value="delete_sanction">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h6">Master Peringan (efek %, form Sedang+)</h2>
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="action" value="add_peringan">
                    <div class="col-md-2"><input type="text" class="form-control" name="kode" placeholder="Kode" required></div>
                    <div class="col-md-5"><input type="text" class="form-control" name="nama" placeholder="Nama" required></div>
                    <div class="col-md-2"><input type="number" class="form-control" name="efek_persen" placeholder="Efek %" value="0"></div>
                    <div class="col-md-1"><input type="number" class="form-control" name="urutan" placeholder="#"></div>
                    <div class="col-md-2"><button class="btn btn-success btn-sm w-100">Tambah</button></div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Kode</th><th>Nama</th><th>Efek %</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($peringanRows ?? [] as $pr): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $pr['kode']) ?></td>
                                <td><?= htmlspecialchars((string) $pr['nama']) ?></td>
                                <td><?= (int) $pr['efek_persen'] ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus?')">
                                        <input type="hidden" name="action" value="delete_peringan">
                                        <input type="hidden" name="id" value="<?= (int) $pr['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h6">Master Pemberat (efek %, form Sedang+)</h2>
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="action" value="add_pemberat">
                    <div class="col-md-2"><input type="text" class="form-control" name="kode" placeholder="Kode" required></div>
                    <div class="col-md-5"><input type="text" class="form-control" name="nama" placeholder="Nama" required></div>
                    <div class="col-md-2"><input type="number" class="form-control" name="efek_persen" placeholder="Efek %" value="50"></div>
                    <div class="col-md-1"><input type="number" class="form-control" name="urutan" placeholder="#"></div>
                    <div class="col-md-2"><button class="btn btn-success btn-sm w-100">Tambah</button></div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Kode</th><th>Nama</th><th>Efek %</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($pemberatRows ?? [] as $pb): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $pb['kode']) ?></td>
                                <td><?= htmlspecialchars((string) $pb['nama']) ?></td>
                                <td><?= (int) $pb['efek_persen'] ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus?')">
                                        <input type="hidden" name="action" value="delete_pemberat">
                                        <input type="hidden" name="id" value="<?= (int) $pb['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
