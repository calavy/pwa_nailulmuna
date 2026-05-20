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
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h6">Setting Auto Poin dari Presensi</h1>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_auto">
                    <div class="col-12">
                        <label class="form-label">ALPA otomatis (+)</label>
                        <input type="number" min="0" class="form-control" name="point_auto_alpa" value="<?= $pointAutoAlpa ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">TELAT otomatis (+)</label>
                        <input type="number" min="0" class="form-control" name="point_auto_telat" value="<?= $pointAutoTelat ?>">
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
                <h2 class="h6">Tambah Rule Poin</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rule">
                    <div class="col-md-3"><input type="text" class="form-control" name="kode_rule" placeholder="Kode"></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="kategori" placeholder="Kategori" required></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="nama_rule" placeholder="Nama rule" required></div>
                    <div class="col-md-3"><input type="number" min="1" class="form-control" name="bobot_poin" placeholder="Poin" required></div>
                    <div class="col-md-9"><input type="text" class="form-control" name="contoh_pelanggaran" placeholder="Contoh pelanggaran"></div>
                    <div class="col-md-3"><input type="number" class="form-control" name="urutan" placeholder="Urutan"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm">Tambah Rule</button></div>
                </form>
                <hr>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Kode</th><th>Kategori</th><th>Rule</th><th>Poin</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $r): ?>
                        <tr>
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
    </div>
</div>
