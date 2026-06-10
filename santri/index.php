<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_status.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus']);
santri_list_sort_mode($_GET['santri_sort'] ?? null);
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/kelas_ruangan.php';
ensure_santri_keluar_columns($pdo);
ensure_kelas_ruangan_table($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));

$extraRuanganSelect = '';
if (column_exists($pdo, 'santri', 'kelas_ruangan_id') && table_exists($pdo, 'kelas_ruangan')) {
    $extraRuanganSelect = ', (SELECT kr.nama_ruangan FROM kelas_ruangan kr WHERE kr.id = santri.kelas_ruangan_id LIMIT 1) AS nama_ruangan_kelas';
}

$aktifWhere = santri_sql_aktif_only('santri');
$whereParts = [$aktifWhere];
$params = [];
if ($q !== '') {
    $whereParts[] = '(LOWER(santri.nama_santri) LIKE :q OR LOWER(santri.nis) LIKE :q2 OR LOWER(COALESCE(santri.qr, \'\')) LIKE :q3)';
    $like = '%' . mb_strtolower($q) . '%';
    $params['q'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}
$whereSql = implode(' AND ', $whereParts);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM santri WHERE ' . $whereSql);
$countStmt->execute($params);
$totalFiltered = (int) ($countStmt->fetchColumn() ?: 0);
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = '
    SELECT id, qr, nis, nama_santri, nik, jenis_kelamin, tingkatan, kategori_kelas, no_wa_wali, is_aktif, status_santri, keluar_kategori, alasan_keluar, tanggal_keluar, nama_kamar, no_ranjang, keluar_settled_at' . $extraRuanganSelect . '
    FROM santri
    WHERE ' . $whereSql . '
    ORDER BY ' . santri_list_order_sql('santri') . '
    LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$santri = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalAktif = (int) ($pdo->query('SELECT COUNT(*) FROM santri WHERE ' . santri_sql_aktif_only('santri'))->fetchColumn() ?: 0);
$totalNonAktif = (int) ($pdo->query('
    SELECT COUNT(*) FROM santri WHERE NOT (' . santri_sql_aktif_only('santri') . ')
')->fetchColumn() ?: 0);

$kelasKeuanganLabels = kelas_keuangan_label_map($pdo);

$pageTitle = 'Data Santri Aktif';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="page-intro w-100 me-3">
        <p class="page-intro-kicker mb-1">Manajemen SDM</p>
        <h1 class="h3 mb-1">Santri Aktif</h1>
        <p class="text-muted mb-0">Santri yang masih mondok. Non aktifkan dari tabel — otomatis masuk <a href="/santri/mukimin.php">Data Mukimin</a>. Penyelesaian keuangan &amp; surat lewat <strong>Administrasi keluar</strong>. Biodata lengkap di <a href="/santri/semua_jati.php">Data induk santri</a>.</p>
        <p class="small text-muted mt-2 mb-0">Unduh daftar: file <strong>CSV UTF-8</strong> (titik koma) — cocok dibuka di Excel; berisi kolom biodata, orang tua, kafil, dan alamat.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/santri/semua_jati.php" class="btn btn-outline-primary btn-sm">Data induk</a>
        <a href="/santri/mukimin.php" class="btn btn-outline-secondary btn-sm">Data Mukimin</a>
        <a href="/santri/keluar.php" class="btn btn-outline-danger btn-sm" data-sdm-modal="/santri/keluar.php" data-sdm-title="Administrasi keluar">Administrasi keluar</a>
        <a href="/santri/export_excel.php" class="btn btn-outline-primary btn-sm" title="CSV UTF-8">Export</a>
        <a href="/santri/create.php" class="btn btn-success btn-sm" data-sdm-modal="/santri/create.php" data-sdm-title="Tambah santri">+ Tambah</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total santri</div>
            <div class="app-mini-stat-value"><?= $totalAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Status aktif</div>
            <div class="app-mini-stat-value text-success"><?= $totalAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Non aktif (muqim/boyong)</div>
            <div class="app-mini-stat-value text-secondary"><?= $totalNonAktif ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4 col-lg-3">
                <label class="form-label small text-muted mb-1" for="santri-aktif-cari">Cari nama atau NIS</label>
                <input type="search" name="q" id="santri-aktif-cari" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off">
            </div>
            <div class="col-auto d-flex align-items-end pb-1">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="santri-tampilan-ringkas" checked>
                    <label class="form-check-label small" for="santri-tampilan-ringkas">Tampilan ringkas</label>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Per halaman</label>
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([30, 50, 80, 100] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary btn-sm">Cari</button>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-0">
                    Menampilkan <strong><?= count($santri) ?></strong> dari <strong><?= $totalFiltered ?></strong> santri aktif
                    (hal <?= $page ?> / <?= $totalPages ?>).
                    Lihat skor keaktifan lengkap di <a href="/rekap/santri_bagus.php">Rekap Keaktifan</a>.
                </p>
            </div>
        </form>
        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-2">
            <button type="button" class="btn btn-outline-success btn-sm" id="btn-cetak-kartu-batch" disabled>
                <i class="fa-solid fa-file-lines me-1"></i> Cetak Kartu Tes Terpilih
            </button>
        </div>
        <form id="form-kartu-batch" method="get" action="<?= htmlspecialchars(app_href('/santri/kartu_batch.php')) ?>">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0 santri-aktif-table santri-aktif-table--ringkas" id="santri-aktif-table">
                <thead>
                <tr>
                    <th class="text-center" style="width:38px">
                        <input type="checkbox" id="chk-all-santri" title="Pilih semua di halaman ini">
                    </th>
                    <th class="santri-col-extra">QR</th>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th class="santri-col-extra">NIK</th>
                    <th class="santri-col-extra">JK</th>
                    <th class="santri-col-detail">Tingkatan</th>
                    <th class="santri-col-extra">Kamar/Ranjang</th>
                    <th class="santri-col-detail">Kelas</th>
                    <th class="santri-col-extra">Ruangan</th>
                    <th class="santri-col-extra">WA Wali</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($santri): ?>
                    <?php foreach ($santri as $item):
                        $sid = (int) $item['id'];
                        ?>
                        <tr class="santri-aktif-row">
                            <td class="text-center">
                                <input type="checkbox" class="chk-santri-batch" name="ids[]" value="<?= $sid ?>">
                            </td>
                            <td class="santri-col-extra"><?= htmlspecialchars($item['qr'] ?: '-') ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars($item['nis']) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($item['nama_santri']) ?></td>
                            <td class="santri-col-extra"><?= htmlspecialchars($item['nik'] ?: '-') ?></td>
                            <td class="santri-col-extra"><?= htmlspecialchars($item['jenis_kelamin'] ?: '-') ?></td>
                            <td class="santri-col-detail"><?= htmlspecialchars($item['tingkatan'] ?: '-') ?></td>
                            <td class="santri-col-extra">
                                <?php
                                $kamar = trim((string) ($item['nama_kamar'] ?? ''));
                                $ranjang = trim((string) ($item['no_ranjang'] ?? ''));
                                echo htmlspecialchars(($kamar !== '' ? $kamar : '-') . ($ranjang !== '' ? ' / ' . $ranjang : ''));
                                ?>
                            </td>
                            <td class="santri-col-detail"><?php
                                $katK = strtoupper(trim((string) ($item['kategori_kelas'] ?? '')));
                                echo htmlspecialchars($katK !== '' ? ($kelasKeuanganLabels[$katK] ?? $katK) : '-');
                            ?></td>
                            <td class="santri-col-extra"><?= htmlspecialchars(trim((string) ($item['nama_ruangan_kelas'] ?? '')) !== '' ? (string) $item['nama_ruangan_kelas'] : '-') ?></td>
                            <td class="santri-col-extra"><?= htmlspecialchars($item['no_wa_wali'] ?: '-') ?></td>
                            <td>
                                <?php $status = santri_status_from_row($item); ?>
                                <span class="badge <?= santri_status_badge_class($status) ?>">
                                    <?= htmlspecialchars(santri_status_label($status)) ?>
                                </span>
                                <?php if (!santri_status_is_aktif_list($status) && (trim((string) ($item['alasan_keluar'] ?? '')) !== '' || trim((string) ($item['tanggal_keluar'] ?? '')) !== '')): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string) ($item['tanggal_keluar'] ?? '-')) ?> · <?= htmlspecialchars((string) ($item['alasan_keluar'] ?? '-')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="santri-aksi-cell">
                                <div class="santri-aksi-stack">
                                    <div class="santri-aksi-row">
                                        <a href="<?= htmlspecialchars(app_href('/santri/kartu_qr.php?id=' . $sid)) ?>" class="btn btn-outline-primary btn-santri-mini" title="Cetak QR" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-qrcode"></i> QR
                                        </a>
                                        <a href="<?= htmlspecialchars(app_href('/santri/kartu.php?id=' . $sid)) ?>" class="btn btn-outline-success btn-santri-mini" title="Cetak kartu tes A5" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-file-lines"></i> Tes
                                        </a>
                                    </div>
                                    <div class="santri-aksi-row">
                                        <a href="<?= htmlspecialchars(app_href('/santri/riwayat.php?id=' . $sid)) ?>" class="btn btn-outline-info btn-santri-mini">Riwayat</a>
                                        <a href="<?= htmlspecialchars(app_href('/santri/edit.php?id=' . $sid)) ?>"
                                           class="btn btn-warning btn-santri-mini"
                                           data-sdm-modal="<?= htmlspecialchars(app_href('/santri/edit.php?id=' . $sid)) ?>"
                                           data-sdm-title="Edit santri">Edit</a>
                                        <span class="santri-col-extra santri-aksi-row">
                                            <a href="<?= htmlspecialchars(app_href('/santri/nonaktif_cepat.php?id=' . $sid)) ?>" class="btn btn-outline-danger btn-santri-mini">Status</a>
                                            <a href="<?= htmlspecialchars(app_href('/santri/delete.php?id=' . $sid)) ?>" class="btn btn-danger btn-santri-mini" onclick="return confirm('Hapus data ini?')">Hapus</a>
                                        </span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="13" class="text-center text-muted">Belum ada data santri aktif.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($totalPages > 1):
            $pageBase = ['per_page' => $perPage];
            if ($q !== '') {
                $pageBase['q'] = $q;
            }
            ?>
        <nav class="mt-3 d-flex flex-wrap justify-content-center gap-1" aria-label="Halaman santri">
            <?php if ($page > 1):
                $prev = $pageBase;
                $prev['page'] = $page - 1;
                ?>
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
            if ($page < $totalPages):
                $next = $pageBase;
                $next['page'] = $page + 1;
                ?>
                <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($next)) ?>">»</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</div>

<style>
.santri-aktif-table--ringkas .santri-col-extra { display: none; }
.santri-aksi-cell { min-width: 7.5rem; vertical-align: middle; }
.santri-aksi-stack { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.santri-aksi-row { display: inline-flex; flex-wrap: wrap; justify-content: flex-end; gap: 3px; }
.btn-santri-mini {
    --bs-btn-padding-y: .1rem;
    --bs-btn-padding-x: .35rem;
    font-size: .68rem;
    line-height: 1.2;
    border-radius: .2rem;
}
.btn-santri-mini .fa-solid { font-size: .62rem; }
</style>
<script>
(function () {
    var inp = document.getElementById('santri-aktif-cari');
    var rows = document.querySelectorAll('.santri-aktif-row');
    var visibleEl = document.getElementById('santri-aktif-visible');
    var noMatch = document.getElementById('santri-aktif-no-match');
    var table = document.getElementById('santri-aktif-table');
    var ringkasToggle = document.getElementById('santri-tampilan-ringkas');
    var storageKey = 'santri_aktif_ringkas_v1';

    if (ringkasToggle && table) {
        var saved = localStorage.getItem(storageKey);
        if (saved === '0') {
            ringkasToggle.checked = false;
            table.classList.remove('santri-aktif-table--ringkas');
        }
        ringkasToggle.addEventListener('change', function () {
            var on = ringkasToggle.checked;
            table.classList.toggle('santri-aktif-table--ringkas', on);
            localStorage.setItem(storageKey, on ? '1' : '0');
        });
    }

    if (inp) {
        var debounce;
        inp.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                if (inp.form) {
                    if (inp.form.requestSubmit) {
                        inp.form.requestSubmit();
                    } else {
                        inp.form.submit();
                    }
                }
            }, 400);
        });
    }

    var chkAll = document.getElementById('chk-all-santri');
    var chks = document.querySelectorAll('.chk-santri-batch');
    var btnBatch = document.getElementById('btn-cetak-kartu-batch');
    var formBatch = document.getElementById('form-kartu-batch');

    function syncBatchBtn() {
        if (!btnBatch) return;
        var selected = document.querySelectorAll('.chk-santri-batch:checked').length;
        btnBatch.disabled = selected === 0;
        btnBatch.innerHTML = '<i class="fa-solid fa-file-lines me-1"></i> Cetak Kartu Tes Terpilih' + (selected > 0 ? ' (' + selected + ')' : '');
    }

    if (chkAll) {
        chkAll.addEventListener('change', function () {
            chks.forEach(function (c) { c.checked = chkAll.checked; });
            syncBatchBtn();
        });
    }
    chks.forEach(function (c) {
        c.addEventListener('change', syncBatchBtn);
    });
    if (btnBatch && formBatch) {
        btnBatch.addEventListener('click', function () {
            if (document.querySelectorAll('.chk-santri-batch:checked').length === 0) return;
            formBatch.submit();
        });
    }
    syncBatchBtn();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
