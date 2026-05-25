<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus']);
santri_list_sort_mode($_GET['santri_sort'] ?? null);
ensure_akademik_ikhtibar_tables($pdo);

$filterSantri = (int) ($_GET['santri_id'] ?? 0);
$filterTugas = (int) ($_GET['tugas_id'] ?? 0);

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $filterTugas > 0) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rekap-ikhtibar-' . $filterTugas . '.csv"');
    echo ikhtibar_export_nilai_csv($pdo, $filterTugas);
    exit;
}

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$santriList = $pdo->query('
    SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan FROM santri s
    ' . (column_exists($pdo, 'santri', 'is_aktif') ? 'WHERE COALESCE(s.is_aktif, 1) = 1' : '') . '
    ORDER BY ' . santri_list_order_sql('s') . ' LIMIT 800
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$tugasList = $pdo->query('
    SELECT id, judul, tanggal, mapel_label, status FROM ikhtibar_tugas
    ORDER BY tanggal DESC, id DESC LIMIT 120
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = [];
if ($filterSantri > 0) {
    $rows = ikhtibar_riwayat_hasil_santri($pdo, $filterSantri);
    if ($filterTugas > 0) {
        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['tugas_id'] ?? 0) === $filterTugas));
    }
} elseif ($filterTugas > 0) {
    $rows = ikhtibar_laporan_nilai_enriched($pdo, $filterTugas);
}

$pageTitle = 'Rekap Nilai Ikhtibar';
$bodyClass = 'ikhtibar-rekap-admin-page';
require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Akademik · Tugas Ikhtibar</p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-graduation-cap text-primary me-1"></i> Rekap Nilai Tugas Pembimbing</h1>
    <p class="text-muted mb-0">Lihat hasil ujian/tugas santri per orang atau per tugas. Pembimbing mengoreksi di menu <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/rekap.php')) ?>">Tugas Ikhtibar → Rekap</a>.</p>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small">Santri</label>
                <select name="santri_id" class="form-select form-select-sm">
                    <option value="0">— Semua (pilih santri) —</option>
                    <?php foreach ($santriList as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $filterSantri === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $s['nama_santri']) ?> (<?= htmlspecialchars((string) $s['nis']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small">Tugas (opsional)</label>
                <select name="tugas_id" class="form-select form-select-sm">
                    <option value="0">— Semua tugas —</option>
                    <?php foreach ($tugasList as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $filterTugas === (int) $t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $t['judul']) ?> · <?= htmlspecialchars((string) $t['tanggal']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<?php if ($filterSantri <= 0 && $filterTugas <= 0): ?>
    <div class="alert alert-light border text-center py-4">
        <i class="fa-solid fa-filter fa-2x text-muted mb-2"></i>
        <p class="mb-0 text-muted">Pilih santri atau tugas untuk menampilkan rekap nilai.</p>
    </div>
<?php elseif ($rows === []): ?>
    <div class="alert alert-warning py-2">Tidak ada data nilai untuk filter ini.</div>
<?php elseif ($filterSantri > 0): ?>
    <div class="row g-3">
        <?php foreach ($rows as $r):
            $nilai = $r['nilai_total'] !== null ? (float) $r['nilai_total'] : null;
            $pending = (int) ($r['esai_pending'] ?? 0) > 0;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card ikhtibar-result-card h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-bold"><?= htmlspecialchars((string) ($r['judul'] ?? '')) ?></h2>
                        <p class="small text-muted mb-2"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></p>
                        <?php if ($nilai !== null && !$pending): ?>
                            <div class="fs-3 fw-bold text-success"><?= htmlspecialchars(number_format($nilai, 1)) ?></div>
                            <span class="badge text-bg-<?= htmlspecialchars((string) ($r['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($r['predikat'] ?? '')) ?></span>
                        <?php elseif ($pending): ?>
                            <span class="badge text-bg-warning text-dark">Menunggu koreksi esai</span>
                        <?php else: ?>
                            <span class="text-muted small">Belum dinilai</span>
                        <?php endif; ?>
                        <div class="ikhtibar-score-grid mt-2">
                            <div class="ikhtibar-score-tile">
                                <div class="ikhtibar-score-tile__val small"><?= $r['skor_pg'] !== null ? (string) $r['skor_pg'] . '%' : '—' ?></div>
                                <div class="ikhtibar-score-tile__lbl">PG</div>
                            </div>
                            <div class="ikhtibar-score-tile">
                                <div class="ikhtibar-score-tile__val small"><?= $r['skor_esai'] !== null ? (string) $r['skor_esai'] : '—' ?></div>
                                <div class="ikhtibar-score-tile__lbl">Esai</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Peserta tugas</span>
            <a href="?tugas_id=<?= $filterTugas ?>&export=csv" class="btn btn-sm btn-outline-secondary">Export CSV</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Santri</th><th>NIS</th><th>PG</th><th>Esai</th><th>Total</th><th>Predikat</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                            <td><?= $r['skor_pg'] !== null ? (string) $r['skor_pg'] . '%' : '—' ?></td>
                            <td><?= (int) ($r['esai_pending'] ?? 0) > 0 ? 'Pending' : ($r['skor_esai'] !== null ? (string) $r['skor_esai'] : '—') ?></td>
                            <td class="fw-bold"><?= $r['nilai_total'] !== null ? (string) $r['nilai_total'] : '—' ?></td>
                            <td><span class="badge text-bg-<?= htmlspecialchars((string) ($r['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($r['predikat'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
