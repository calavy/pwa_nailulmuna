<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';

require_roles(['admin', 'pengurus', 'kiai']);
pkpps_ensure_schema($pdo);

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

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
$aktifSql = santri_sql_aktif_only('s');
$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$finalizeEnd = $sampai;
$today = date('Y-m-d');
$auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
if ($finalizeEnd > $today) {
    $finalizeEnd = $today;
}
if ($dari <= $finalizeEnd) {
    presensi_finalize_date_range($pdo, $dari, $finalizeEnd, $auditUserId > 0 ? $auditUserId : 1);
}

$santriRows = [];
if (table_exists($pdo, 'pkpps_santri') && table_exists($pdo, 'presensi')) {
    $santriDari = sprintf('%04d-01-01', $tahun);
    $santriSampai = sprintf('%04d-12-31', $tahun);
    if ($santriSampai > $today) {
        $santriSampai = $today;
    }
    if ($santriDari <= $santriSampai) {
        presensi_finalize_date_range($pdo, $santriDari, $santriSampai, $auditUserId > 0 ? $auditUserId : 1);
    }

    $st = $pdo->prepare('
        SELECT
            s.id AS santri_id,
            s.nis,
            s.' . $nameCol . ' AS nama_santri,
            t.nama_tingkatan AS pkpps_tingkatan
        FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        WHERE ps.is_aktif = 1 AND t.is_aktif = 1
        ORDER BY t.urutan ASC, s.' . $nameCol . ' ASC
    ');
    $st->execute();
    $baseRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
    $santriIds = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['santri_id'] ?? 0), $baseRows)));
    $eligibleAll = $santriDari <= $santriSampai
        ? rekap_keaktifan_fetch_eligible_rows($pdo, $santriDari, $santriSampai, $santriIds, 0, false)
        : [];
    $eligibleBySid = [];
    foreach ($eligibleAll as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $eligibleBySid[$sid][] = $row;
    }
    foreach ($baseRows as $r) {
        $sid = (int) ($r['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $totals = rekap_keaktifan_totals_from_rows($eligibleBySid[$sid] ?? []);
        $alpa = (int) ($totals['alpa'] ?? 0);
        $total = (int) ($totals['total'] ?? 0);
        $hadir = (int) ($totals['hadir'] ?? 0);
        $r['hadir'] = $hadir;
        $r['izin'] = (int) ($totals['izin'] ?? 0);
        $r['sakit'] = (int) ($totals['sakit'] ?? 0);
        $r['alpa'] = $alpa;
        $r['total'] = $total;
        $r['kategori'] = $total > 0 ? santri_category($alpa, $goodMax, $mediumMax) : '—';
        $r['persen'] = $total > 0 ? round($hadir / $total * 100, 1) : 0;
        $santriRows[] = $r;
    }
}

$pembimbingRows = [];
if (table_exists($pdo, 'pkpps_jadwal') && table_exists($pdo, 'presensi_pembimbing')) {
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

<form class="row g-2 align-items-end mb-3" method="get">
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
    <div class="col-auto"><button class="btn btn-primary btn-sm">Terapkan</button></div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>Keaktivan Santri PKPPS — Tahun <?= (int) $tahun ?></strong></div>
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
    <div class="card-header"><strong>Keaktivan Pembimbing PKPPS — <?= htmlspecialchars($dari) ?> s/d <?= htmlspecialchars($sampai) ?></strong></div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
