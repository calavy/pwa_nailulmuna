<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/pkpps.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);
ensure_santri_identity_columns($pdo);

$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$tahunMasehi = (int) date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'tambah') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $tahun = (int) ($_POST['tahun_masehi'] ?? $tahunMasehi);
        if ($santriId > 0 && $tingkatId > 0) {
            $st = $pdo->prepare('
                INSERT INTO pkpps_santri (santri_id, pkpps_tingkatan_id, tahun_masehi, is_aktif, catatan)
                VALUES (:sid, :tid, :th, 1, :cat)
                ON DUPLICATE KEY UPDATE
                    pkpps_tingkatan_id = VALUES(pkpps_tingkatan_id),
                    tahun_masehi = VALUES(tahun_masehi),
                    is_aktif = 1,
                    catatan = VALUES(catatan)
            ');
            $st->execute([
                'sid' => $santriId,
                'tid' => $tingkatId,
                'th' => $tahun > 0 ? $tahun : null,
                'cat' => trim((string) ($_POST['catatan'] ?? '')),
            ]);
            set_flash('success', 'Santri PKPPS disimpan.');
        } else {
            set_flash('error', 'Pilih santri dan tingkatan PKPPS.');
        }
    } elseif ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM pkpps_santri WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Santri dihapus dari daftar PKPPS.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $aktif = (int) ($_POST['is_aktif'] ?? 0) === 1 ? 1 : 0;
        if ($id > 0) {
            $pdo->prepare('UPDATE pkpps_santri SET is_aktif = :a WHERE id = :id')->execute(['a' => $aktif, 'id' => $id]);
            set_flash('success', 'Status diperbarui.');
        }
    } elseif ($action === 'tambah_bulk' || $action === 'tambah_semua_terfilter') {
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $tahun = (int) ($_POST['tahun_masehi'] ?? $tahunMasehi);
        $ids = $_POST['santri_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        if ($action === 'tambah_semua_terfilter') {
            $ids = pkpps_santri_bulk_candidate_ids(
                $pdo,
                trim((string) ($_POST['bulk_tk'] ?? '')),
                trim((string) ($_POST['bulk_q'] ?? ''))
            );
        }
        $ok = 0;
        if ($tingkatId > 0 && $ids !== []) {
            $ins = $pdo->prepare('
                INSERT INTO pkpps_santri (santri_id, pkpps_tingkatan_id, tahun_masehi, is_aktif, catatan)
                VALUES (:sid, :tid, :th, 1, "")
                ON DUPLICATE KEY UPDATE
                    pkpps_tingkatan_id = VALUES(pkpps_tingkatan_id),
                    tahun_masehi = VALUES(tahun_masehi),
                    is_aktif = 1
            ');
            foreach ($ids as $rawId) {
                $sid = (int) $rawId;
                if ($sid <= 0) {
                    continue;
                }
                $ins->execute([
                    'sid' => $sid,
                    'tid' => $tingkatId,
                    'th' => $tahun > 0 ? $tahun : null,
                ]);
                $ok++;
            }
            set_flash('success', $ok . ' santri ditambahkan ke PKPPS dari data santri.');
        } else {
            set_flash('error', 'Pilih tingkatan PKPPS dan santri yang akan ditambahkan.');
        }
    } elseif ($action === 'ubah') {
        $id = (int) ($_POST['id'] ?? 0);
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $tahun = (int) ($_POST['tahun_masehi'] ?? 0);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $isAktif = (int) ($_POST['is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($id > 0 && $tingkatId > 0) {
            $pdo->prepare('
                UPDATE pkpps_santri
                SET pkpps_tingkatan_id = :tid, tahun_masehi = :th, catatan = :cat, is_aktif = :a
                WHERE id = :id
            ')->execute([
                'tid' => $tingkatId,
                'th' => $tahun > 0 ? $tahun : null,
                'cat' => mb_substr($catatan, 0, 255),
                'a' => $isAktif,
                'id' => $id,
            ]);
            set_flash('success', 'Data santri PKPPS diperbarui.');
        } else {
            set_flash('error', 'Data edit tidak lengkap.');
        }
    }
    $back = '/pkpps/santri.php';
    if (in_array($action, ['tambah_bulk', 'tambah_semua_terfilter'], true)) {
        $qs = array_filter([
            'bulk_tingkatan' => (int) ($_POST['pkpps_tingkatan_id'] ?? 0) ?: null,
            'bulk_tk' => trim((string) ($_POST['bulk_tk'] ?? '')) ?: null,
            'bulk_q' => trim((string) ($_POST['bulk_q'] ?? '')) ?: null,
        ]);
        if ($qs !== []) {
            $back .= '?' . http_build_query($qs);
        }
    }
    header('Location: ' . app_href($back));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$pickSantriId = (int) ($_GET['pick'] ?? 0);
$tingkatanFilter = (int) ($_GET['tingkatan'] ?? 0);
$bulkTingkatanId = (int) ($_GET['bulk_tingkatan'] ?? 0);
$bulkTingkatanKajian = trim((string) ($_GET['bulk_tk'] ?? ''));
$bulkQ = trim((string) ($_GET['bulk_q'] ?? ''));

$sql = '
    SELECT ps.id, ps.santri_id, ps.tahun_masehi, ps.is_aktif, ps.catatan,
           s.' . $namaCol . ' AS nama_santri, s.nis, s.qr,
           t.id AS tingkatan_id, t.urutan, t.nama_tingkatan
    FROM pkpps_santri ps
    INNER JOIN santri s ON s.id = ps.santri_id
    INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
    WHERE 1=1
';
$params = [];
if ($tingkatanFilter > 0) {
    $sql .= ' AND ps.pkpps_tingkatan_id = :tid';
    $params['tid'] = $tingkatanFilter;
}
if ($q !== '') {
    $sql .= ' AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY t.urutan ASC, s.' . $namaCol . ' ASC';
$stList = $pdo->prepare($sql);
$stList->execute($params);
$pkppsRows = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

$aktifSql = santri_sql_aktif_only('s');
$cariSql = '
    SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.qr, s.tingkatan
    FROM santri s
    WHERE ' . $aktifSql . '
      AND s.id NOT IN (SELECT santri_id FROM pkpps_santri)
';
if ($q !== '') {
    $cariSql .= ' AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)';
}
$cariSql .= ' ORDER BY s.' . $namaCol . ' ASC LIMIT 30';
$stCari = $pdo->prepare($cariSql);
$stCari->execute($q !== '' ? ['q' => '%' . $q . '%'] : []);
$santriPusat = $stCari->fetchAll(PDO::FETCH_ASSOC) ?: [];

$bulkSantriRows = [];
if ($bulkTingkatanId > 0) {
    $bulkSantriRows = pkpps_santri_bulk_candidates($pdo, $bulkTingkatanKajian, $bulkQ, 2000);
}

$tingkatanKajianList = [];
if (table_exists($pdo, 'santri')) {
    $tingkatanKajianList = $pdo->query(
        'SELECT DISTINCT TRIM(tingkatan) AS t FROM santri WHERE tingkatan IS NOT NULL AND TRIM(tingkatan)<>"" ORDER BY t'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$pickSantri = null;
if ($pickSantriId > 0) {
    foreach ($santriPusat as $s) {
        if ((int) ($s['id'] ?? 0) === $pickSantriId) {
            $pickSantri = $s;
            break;
        }
    }
    if ($pickSantri === null) {
        $stPick = $pdo->prepare('SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.qr, s.tingkatan FROM santri s WHERE s.id = :id AND ' . $aktifSql . ' AND s.id NOT IN (SELECT santri_id FROM pkpps_santri) LIMIT 1');
        $stPick->execute(['id' => $pickSantriId]);
        $pickSantri = $stPick->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    foreach ($pkppsRows as $r) {
        if ((int) ($r['id'] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
}

$tingkatanList = pkpps_tingkatan_list($pdo, true);
$pageTitle = 'Santri PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Data Santri PKPPS</h1>
    <p class="text-muted small mb-2">
        Diambil dari data santri pusat. Presensi tetap memakai <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">scan utama</a>.
    </p>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/pkpps/import_santri.php')) ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-file-import me-1"></i> Import Excel
        </a>
        <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-cash-register me-1"></i> Input pembayaran
        </a>
        <a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Pusat PKPPS</a>
    </div>
</div>

<div class="alert alert-info py-2 small mb-3">
    Tambahan syahriyah PKPPS mengikuti <strong>kelas keuangan</strong> santri (Wustho 1/2/3 = Wustho).
    Pengaturan &amp; pembayaran di menu <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>.
</div>

<?php if ($editRow !== null): ?>
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header py-2"><strong>Edit santri PKPPS</strong></div>
    <div class="card-body">
        <p class="small fw-semibold mb-2"><?= htmlspecialchars((string) ($editRow['nama_santri'] ?? '-')) ?> · NIS <?= htmlspecialchars((string) ($editRow['nis'] ?? '')) ?></p>
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="ubah">
            <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
            <div class="col-md-4">
                <label class="form-label small mb-0">Tingkatan PKPPS</label>
                <select name="pkpps_tingkatan_id" class="form-select form-select-sm" required>
                    <?php foreach ($tingkatanList as $t): ?>
                        <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= (int) ($editRow['tingkatan_id'] ?? 0) === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Tahun</label>
                <input type="number" name="tahun_masehi" class="form-control form-control-sm" min="2000" max="2100"
                       value="<?= (int) ($editRow['tahun_masehi'] ?? $tahunMasehi) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Status</label>
                <select name="is_aktif" class="form-select form-select-sm">
                    <option value="1" <?= (int) ($editRow['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= (int) ($editRow['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0">Catatan</label>
                <input type="text" name="catatan" class="form-control form-control-sm" maxlength="255"
                       value="<?= htmlspecialchars((string) ($editRow['catatan'] ?? '')) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2"><strong>Tambah massal dari data santri</strong></div>
            <div class="card-body">
                <p class="small text-muted mb-2">Ambil santri aktif dari data santri pusat yang belum terdaftar PKPPS.</p>
                <form method="get" class="row g-2 mb-3">
                    <div class="col-12">
                        <label class="form-label small mb-0">1. Tingkatan PKPPS tujuan</label>
                        <select name="bulk_tingkatan" class="form-select form-select-sm" required onchange="this.form.submit()">
                            <option value="">— Pilih tingkatan PKPPS —</option>
                            <?php foreach ($tingkatanList as $t): ?>
                                <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= $bulkTingkatanId === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($bulkTingkatanId > 0): ?>
                    <div class="col-md-6">
                        <label class="form-label small mb-0">Filter tingkatan kajian</label>
                        <select name="bulk_tk" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Semua tingkatan kajian</option>
                            <?php foreach ($tingkatanKajianList as $tk): ?>
                                <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $bulkTingkatanKajian === (string) $tk ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $tk) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-0">Cari nama / NIS</label>
                        <div class="input-group input-group-sm">
                            <input type="search" name="bulk_q" class="form-control" value="<?= htmlspecialchars($bulkQ) ?>" placeholder="Ketik lalu Enter">
                            <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
                <?php if ($bulkTingkatanId > 0): ?>
                    <?php if ($bulkSantriRows === []): ?>
                        <p class="small text-muted mb-0">Tidak ada santri tersedia (semua sudah PKPPS atau filter terlalu sempit).</p>
                    <?php else: ?>
                        <form method="post" class="mb-2">
                            <input type="hidden" name="action" value="tambah_bulk">
                            <input type="hidden" name="pkpps_tingkatan_id" value="<?= $bulkTingkatanId ?>">
                            <input type="hidden" name="tahun_masehi" value="<?= $tahunMasehi ?>">
                            <input type="hidden" name="bulk_tk" value="<?= htmlspecialchars($bulkTingkatanKajian) ?>">
                            <input type="hidden" name="bulk_q" value="<?= htmlspecialchars($bulkQ) ?>">
                            <p class="small text-muted mb-1">2. Centang santri (<?= count($bulkSantriRows) ?> dari data santri)</p>
                            <div class="border rounded p-2 mb-2" style="max-height:280px;overflow:auto">
                                <div class="form-check mb-1 border-bottom pb-1">
                                    <input class="form-check-input" type="checkbox" id="pkpps-check-all" checked>
                                    <label class="form-check-label small fw-semibold" for="pkpps-check-all">Pilih semua yang tampil</label>
                                </div>
                                <?php foreach ($bulkSantriRows as $bs): ?>
                                    <div class="form-check">
                                        <input class="form-check-input pkpps-santri-cb" type="checkbox" name="santri_ids[]"
                                               value="<?= (int) ($bs['id'] ?? 0) ?>" id="pbs-<?= (int) ($bs['id'] ?? 0) ?>" checked>
                                        <label class="form-check-label small" for="pbs-<?= (int) ($bs['id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) ($bs['nama_santri'] ?? '-')) ?>
                                            <span class="text-muted">· NIS <?= htmlspecialchars((string) ($bs['nis'] ?? '-')) ?> · <?= htmlspecialchars((string) ($bs['tingkatan'] ?? '')) ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Masukkan yang dicentang</button>
                            </div>
                        </form>
                        <form method="post" onsubmit="return confirm('Tambahkan semua <?= count($bulkSantriRows) ?> santri yang tampil ke PKPPS?');">
                            <input type="hidden" name="action" value="tambah_semua_terfilter">
                            <input type="hidden" name="pkpps_tingkatan_id" value="<?= $bulkTingkatanId ?>">
                            <input type="hidden" name="tahun_masehi" value="<?= $tahunMasehi ?>">
                            <input type="hidden" name="bulk_tk" value="<?= htmlspecialchars($bulkTingkatanKajian) ?>">
                            <input type="hidden" name="bulk_q" value="<?= htmlspecialchars($bulkQ) ?>">
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                Tambah semua yang tampil (<?= count($bulkSantriRows) ?> santri)
                            </button>
                        </form>
                        <script>
                        (function () {
                            var all = document.getElementById('pkpps-check-all');
                            if (!all) return;
                            all.addEventListener('change', function () {
                                document.querySelectorAll('.pkpps-santri-cb').forEach(function (cb) {
                                    cb.checked = all.checked;
                                });
                            });
                        })();
                        </script>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="small text-muted mb-0">Pilih tingkatan PKPPS dulu, lalu centang santri.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm h-100">
            <div class="card-header py-2"><strong>Tambah satu santri</strong></div>
            <div class="card-body">
                <form method="get" class="mb-3">
                    <label class="form-label small">Cari santri pusat (belum PKPPS)</label>
                    <div class="input-group input-group-sm">
                        <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama / NIS / QR lalu cari" autofocus>
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </div>
                    <div class="form-text">Pilih santri dari hasil pencarian — form pendaftaran muncul setelah dipilih.</div>
                </form>

                <?php if ($q === '' && $pickSantri === null): ?>
                    <p class="small text-muted mb-0 text-center py-3"><i class="fa-solid fa-search me-1"></i> Mulai dengan mengetik nama atau NIS santri.</p>
                <?php elseif ($pickSantri === null && $santriPusat === []): ?>
                    <p class="small text-muted mb-0">Tidak ada santri ditemukan. Coba kata kunci lain.</p>
                <?php elseif ($pickSantri === null): ?>
                    <div class="list-group list-group-flush border rounded">
                        <?php foreach ($santriPusat as $s): ?>
                            <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php?q=' . rawurlencode($q) . '&pick=' . (int) ($s['id'] ?? 0))) ?>"
                               class="list-group-item list-group-item-action py-2">
                                <div class="fw-semibold small"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '-')) ?></div>
                                <div class="text-muted small">NIS <?= htmlspecialchars((string) ($s['nis'] ?? '-')) ?> · <?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="border rounded p-3 bg-light">
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($pickSantri['nama_santri'] ?? '-')) ?></div>
                        <div class="text-muted small mb-3">NIS <?= htmlspecialchars((string) ($pickSantri['nis'] ?? '-')) ?> · <?= htmlspecialchars((string) ($pickSantri['tingkatan'] ?? '-')) ?></div>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="action" value="tambah">
                            <input type="hidden" name="santri_id" value="<?= (int) ($pickSantri['id'] ?? 0) ?>">
                            <div class="col-12">
                                <label class="form-label small mb-0">Tingkatan PKPPS</label>
                                <select name="pkpps_tingkatan_id" class="form-select form-select-sm" required>
                                    <option value="">Pilih tingkatan</option>
                                    <?php foreach ($tingkatanList as $t): ?>
                                        <option value="<?= (int) ($t['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-0">Tahun</label>
                                <input type="number" name="tahun_masehi" class="form-control form-control-sm" value="<?= $tahunMasehi ?>" min="2000" max="2100">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Masukkan PKPPS</button>
                                <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''))) ?>" class="btn btn-outline-secondary btn-sm">Batal</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Daftar santri PKPPS (<?= count($pkppsRows) ?>)</strong>
                <form method="get" class="d-flex gap-2">
                    <select name="tingkatan" class="form-select form-select-sm" style="max-width:12rem" onchange="this.form.submit()">
                        <option value="0">Semua tingkatan</option>
                        <?php foreach ($tingkatanList as $t): ?>
                            <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= $tingkatanFilter === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Tingkatan PKPPS</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($pkppsRows === []): ?>
                        <tr><td colspan="4" class="text-muted text-center py-4">Belum ada santri PKPPS.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pkppsRows as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                                </td>
                                <td><?= htmlspecialchars((string) ($r['nama_tingkatan'] ?? '')) ?></td>
                                <td><?= (int) ($r['is_aktif'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif' ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php?edit=' . (int) ($r['id'] ?? 0))) ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <input type="hidden" name="is_aktif" value="<?= (int) ($r['is_aktif'] ?? 0) === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Ubah status">⇄</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus dari daftar PKPPS?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
