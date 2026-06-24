<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';

require_roles(['admin', 'pengurus', 'kiai']);

$periode = rekap_resolve_periode($pdo, $_GET);
$periodeLabelPkpps = (string) ($periode['label'] ?? '');
$rentangPkpps = (string) ($periode['rentang_tampilan'] ?? '');
$filterMode = ($_GET['filter_mode'] ?? 'bulan') === 'rentang' ? 'rentang' : 'bulan';

if ($filterMode === 'bulan') {
    $dari = $periode['start_date'];
    $sampai = $periode['end_date'];
    $tahun = $periode['mode'] === 'masehi' ? (int) $periode['year'] : (int) date('Y', strtotime($dari));
} else {
    $tahun = (int) ($_GET['tahun'] ?? date('Y'));
    if ($tahun < 2000 || $tahun > 2100) {
        $tahun = (int) date('Y');
    }
    $dari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
    $sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
        $dari = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $sampai = date('Y-m-d');
    }
}

$syncPresensi = isset($_GET['sync']) && (string) $_GET['sync'] === '1';
if ($syncPresensi) {
    $today = date('Y-m-d');
    $finalizeEnd = $sampai > $today ? $today : $sampai;
    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    if ($dari <= $finalizeEnd) {
        presensi_finalize_date_range($pdo, $dari, $finalizeEnd, $auditUserId > 0 ? $auditUserId : 1);
    }
    unset($_SESSION['pkpps_keaktivan_tahun_' . $tahun]);
}

$santriRows = pkpps_rekap_keaktivan_santri_tahun($pdo, $tahun, !$syncPresensi);

$pembimbingRows = [];
if (table_exists($pdo, 'pkpps_jadwal') && table_exists($pdo, 'presensi_pembimbing')) {
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT
            b.id,
            b.nama_pembimbing,
            b.nip,
            COUNT(DISTINCT j.pkpps_tingkatan_id) AS jumlah_tingkatan,
            COUNT(DISTINCT j.id) AS jumlah_jadwal,
            COUNT(pp.id) AS total_hadir,
            COUNT(DISTINCT pp.tanggal) AS hari_hadir
        FROM pembimbing b
        INNER JOIN pkpps_jadwal j ON j.pembimbing_id = b.id AND j.is_aktif = 1
        LEFT JOIN presensi_pembimbing pp
          ON pp.pembimbing_id = b.id
         AND pp.tanggal BETWEEN :dari AND :sampai
        GROUP BY b.id, b.nama_pembimbing, b.nip
        ORDER BY total_hadir DESC, ' . pembimbing_list_order_sql('b') . '
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $pembimbingRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Keaktivan PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a></p>
    <h1 class="h4 mb-1">Laporan Keaktivan PKPPS</h1>
    <p class="text-muted mb-0 small">Rekap kehadiran santri PKPPS (tahun) dan pembimbing PKPPS (periode). Untuk tampilan harian kartu, buka <a href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktifan_hari.php')) ?>">Keaktifan PKPPS hari ini</a>.</p>
</div>

<div class="mb-3">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <form method="get" class="d-inline">
            <?php foreach (['mode' => $periode['mode'], 'month' => $periode['month'], 'year' => $periode['year']] as $hk => $hv): ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="filter_mode" value="bulan">
            <input type="hidden" name="tahun" value="<?= (int) $tahun ?>">
            <button type="submit" class="btn btn-sm <?= $filterMode === 'bulan' ? 'btn-primary' : 'btn-outline-secondary' ?>">Per bulan</button>
        </form>
        <form method="get" class="d-inline">
            <input type="hidden" name="filter_mode" value="rentang">
            <input type="hidden" name="tahun" value="<?= (int) $tahun ?>">
            <input type="hidden" name="dari" value="<?= htmlspecialchars($dari) ?>">
            <input type="hidden" name="sampai" value="<?= htmlspecialchars($sampai) ?>">
            <button type="submit" class="btn btn-sm <?= $filterMode === 'rentang' ? 'btn-primary' : 'btn-outline-secondary' ?>">Rentang tanggal</button>
        </form>
    </div>
    <?php if ($filterMode === 'bulan'): ?>
        <?php
        $wrapCard = false;
        $extraHidden = ['filter_mode' => 'bulan', 'tahun' => (string) $tahun];
        $periodeNote = 'Santri PKPPS mengikuti tahun masehi periode';
        require __DIR__ . '/../includes/partials/rekap_kalender_bulan_filter.php';
        ?>
    <?php else: ?>
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="filter_mode" value="rentang">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tahun santri</label>
                <input type="number" name="tahun" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $tahun ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Dari (pembimbing)</label>
                <input type="date" name="dari" class="form-control form-control-sm" value="<?= htmlspecialchars($dari) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Sampai</label>
                <input type="date" name="sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($sampai) ?>">
            </div>
            <div class="col-auto d-flex flex-wrap gap-1">
                <button class="btn btn-primary btn-sm">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php?' . http_build_query(array_filter([
                    'filter_mode' => 'rentang',
                    'tahun' => (string) $tahun,
                    'dari' => $dari,
                    'sampai' => $sampai,
                    'sync' => '1',
                ])))) ?>" data-no-transition="1">Sinkron presensi</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>Keaktivan Santri PKPPS — Tahun masehi <?= (int) $tahun ?></strong> <span class="small text-muted fw-normal">(Jan–Des, terpisah dari filter bulan)</span></div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th>Tingkatan</th>
                <th>NIS</th>
                <th>Nama</th>
                <th class="text-center">H</th>
                <th class="text-center">I</th>
                <th class="text-center">S</th>
                <th class="text-center">A</th>
                <th class="text-center">%</th>
                <th>Kategori</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($santriRows === []): ?>
                <tr><td colspan="9" class="text-center text-muted py-3 small">Belum ada data santri PKPPS.</td></tr>
            <?php endif; ?>
            <?php foreach ($santriRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($r['pkpps_tingkatan'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></td>
                    <td class="text-center"><?= (int) ($r['hadir'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['izin'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['sakit'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['alpa'] ?? 0) ?></td>
                    <td class="text-center"><?= (float) ($r['persen'] ?? 0) ?>%</td>
                    <td><?= htmlspecialchars((string) ($r['kategori'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>Keaktivan Pembimbing PKPPS — <?= $filterMode === 'bulan' ? htmlspecialchars($periodeLabelPkpps . ' (' . $rentangPkpps . ')') : htmlspecialchars($dari . ' s/d ' . $sampai) ?></strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th>NIP</th>
                <th>Nama</th>
                <th class="text-center">Tingkatan</th>
                <th class="text-center">Jadwal</th>
                <th class="text-center">Hadir</th>
                <th class="text-center">Hari</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($pembimbingRows === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-3 small">Belum ada pembimbing dengan jadwal PKPPS.</td></tr>
            <?php endif; ?>
            <?php foreach ($pembimbingRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($r['nip'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['nama_pembimbing'] ?? '')) ?></td>
                    <td class="text-center"><?= (int) ($r['jumlah_tingkatan'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['jumlah_jadwal'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['total_hadir'] ?? 0) ?></td>
                    <td class="text-center"><?= (int) ($r['hari_hadir'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted">Data dibaca langsung dari presensi. Jika angka ALPA belum lengkap, klik <strong>Sinkron presensi</strong> (sekali) untuk memperbarui hari yang belum difinalisasi.</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
