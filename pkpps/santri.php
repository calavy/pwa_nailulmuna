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
    }
    header('Location: ' . app_href('/pkpps/santri.php'));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$pickSantriId = (int) ($_GET['pick'] ?? 0);
$tingkatanFilter = (int) ($_GET['tingkatan'] ?? 0);

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

$tingkatanList = pkpps_tingkatan_list($pdo, true);
$pageTitle = 'Santri PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Data Santri PKPPS</h1>
    <p class="text-muted small mb-0">
        Diambil dari data santri pusat. Presensi tetap memakai <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">scan utama</a>.
    </p>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2"><strong>Tambah santri PKPPS</strong></div>
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
