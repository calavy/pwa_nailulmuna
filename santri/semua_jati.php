<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_santri_keluar_columns($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$filterStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));

$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (LOWER(nama_santri) LIKE :q OR LOWER(nis) LIKE :q2)';
    $params['q'] = '%' . strtolower($q) . '%';
    $params['q2'] = '%' . strtolower($q) . '%';
}
if ($filterStatus !== '' && in_array($filterStatus, santri_status_options(), true)) {
    $where .= ' AND UPPER(TRIM(COALESCE(status_santri, \'AKTIF\'))) = :st';
    $params['st'] = $filterStatus;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM santri' . $where);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetchColumn() ?: 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id, nis, nama_santri, tingkatan, tanggal_masuk, is_aktif, status_santri, keluar_kategori, tanggal_keluar, alasan_keluar FROM santri'
    . $where
    . ' ORDER BY FIELD(UPPER(TRIM(COALESCE(status_santri, \'AKTIF\'))), \'AKTIF\', \'KHIDMAH\', \'NONAKTIF\', \'NON_AKTIF\'), nama_santri ASC'
    . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Data induk santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <p class="page-intro-kicker mb-1">Manajemen SDM</p>
        <h1 class="h3 mb-1">Data induk santri</h1>
        <p class="text-muted small mb-0">Biodata seluruh santri — status <strong>Aktif</strong>, <strong>Nonaktif</strong>, atau <strong>Khidmah</strong>. Klik <strong>Edit</strong> untuk ubah status.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/santri/create.php" class="btn btn-success btn-sm" data-sdm-modal="/santri/create.php" data-sdm-title="Tambah santri">+ Tambah santri</a>
        <a href="/santri/keluar.php" class="btn btn-outline-danger btn-sm" data-sdm-modal="/santri/keluar.php" data-sdm-title="Administrasi keluar">Administrasi keluar</a>
    </div>
</div>

<form class="row g-2 mb-3" method="get" action="">
    <div class="col-md-5 col-lg-4">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" placeholder="Cari nama atau NIS">
    </div>
    <div class="col-md-4 col-lg-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">Semua status</option>
            <?php foreach (santri_status_options() as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>"<?= $filterStatus === $opt ? ' selected' : '' ?>><?= htmlspecialchars(santri_status_label($opt)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ([30, 50, 80, 100] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> / hal</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Cari</button>
    </div>
    <div class="col-12">
        <p class="small text-muted mb-0">Menampilkan <strong><?= count($rows) ?></strong> dari <strong><?= $totalRows ?></strong> santri (hal <?= $page ?> / <?= $totalPages ?>).</p>
    </div>
</form>

<div class="card shadow-sm santri-data-actions">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-sm">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">NIS</th>
                        <th>Nama</th>
                        <th>Tingkatan</th>
                        <th>Masuk</th>
                        <th>Status</th>
                        <th>Keluar / keterangan</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $statusRow = santri_status_from_row($r);
                    $kat = trim((string) ($r['keluar_kategori'] ?? ''));
                    ?>
                    <tr>
                        <td class="ps-3 font-monospace"><?= htmlspecialchars((string) $r['nis']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                        <td><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                        <td class="small text-nowrap">
                            <?php
                            $tglM = trim((string) ($r['tanggal_masuk'] ?? ''));
                            if ($tglM !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglM)) {
                                echo htmlspecialchars($tglM);
                                echo '<br><span class="text-muted">TA ' . htmlspecialchars(santri_tahun_ajaran_label(santri_tahun_ajaran_for_date($pdo, $tglM), $pdo)) . '</span>';
                            } else {
                                echo '<span class="text-muted">—</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="badge <?= santri_status_badge_class($statusRow) ?>"><?= htmlspecialchars(santri_status_label($statusRow)) ?></span>
                        </td>
                        <td class="small">
                            <?php if (trim((string) ($r['tanggal_keluar'] ?? '')) !== ''): ?>
                                <?= htmlspecialchars((string) $r['tanggal_keluar']) ?>
                                <?php if (trim((string) ($r['alasan_keluar'] ?? '')) !== ''): ?>
                                    <span class="text-muted d-block"><?= htmlspecialchars((string) $r['alasan_keluar']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <a href="/santri/riwayat.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-info">Riwayat</a>
                            <a href="<?= htmlspecialchars(app_href('/santri/edit.php?id=' . (int) $r['id'])) ?>"
                               class="btn btn-sm btn-warning"
                               data-sdm-modal="<?= htmlspecialchars(app_href('/santri/edit.php?id=' . (int) $r['id'])) ?>"
                               data-sdm-title="Edit santri">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1):
    $pageBase = ['per_page' => $perPage];
    if ($q !== '') {
        $pageBase['q'] = $q;
    }
    if ($filterStatus !== '') {
        $pageBase['status'] = $filterStatus;
    }
    ?>
<nav class="mt-3 d-flex flex-wrap justify-content-center gap-1" aria-label="Halaman data induk">
    <?php if ($page > 1): $prev = $pageBase; $prev['page'] = $page - 1; ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($prev)) ?>">«</a>
    <?php endif;
    $startP = max(1, $page - 2);
    $endP = min($totalPages, $startP + 4);
    $startP = max(1, $endP - 4);
    for ($p = $startP; $p <= $endP; $p++):
        $pq = $pageBase;
        $pq['page'] = $p;
        ?>
        <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= htmlspecialchars(http_build_query($pq)) ?>"><?= $p ?></a>
    <?php endfor;
    if ($page < $totalPages): $next = $pageBase; $next['page'] = $page + 1; ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($next)) ?>">»</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<div class="mt-3 small text-muted">
    <a href="/santri/index.php">Santri aktif</a> ·
    <a href="/santri/mukimin.php">Data Mukimin</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
