<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? app_tahun_masehi_default($pdo));
$tingkatan = trim($_GET['tingkatan'] ?? '');
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));
$lateTolerance = (int) app_setting($pdo, 'batas_telat_menit', '15');
if ($lateTolerance < 0) {
    $lateTolerance = 0;
}

$query = '
    SELECT i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.alasan, i.jenis_izin, i.status_izin, i.waktu_kembali,
           s.nama_santri, s.tingkatan, s.nis
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id
    WHERE i.status_izin = "KEMBALI"
      AND i.waktu_kembali IS NOT NULL
      AND i.tanggal_selesai BETWEEN :start_date AND :end_date
      AND i.jam_selesai IS NOT NULL
      AND TIMESTAMPDIFF(MINUTE, TIMESTAMP(i.tanggal_selesai, i.jam_selesai), i.waktu_kembali) > :late_tolerance
';
$params = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'late_tolerance' => $lateTolerance,
];
if ($tingkatan !== '') {
    $query .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
    $params['tingkatan'] = $tingkatan;
}
$query .= ' ORDER BY i.tanggal_selesai DESC, i.jam_selesai DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');

$totalKasus = count($rows);
$totalMenitTelat = 0;
$kasusBerat = 0;
foreach ($rows as $r) {
    $limitTsR = strtotime((string) ($r['tanggal_selesai'] ?? '') . ' ' . (string) ($r['jam_selesai'] ?? ''));
    $backTsR = strtotime((string) ($r['waktu_kembali'] ?? ''));
    if ($limitTsR !== false && $backTsR !== false && $backTsR > $limitTsR) {
        $diffMin = (int) floor(($backTsR - $limitTsR) / 60);
        $totalMenitTelat += $diffMin;
        if ($diffMin >= 60) {
            $kasusBerat++;
        }
    }
}
$rataMenit = $totalKasus > 0 ? (int) round($totalMenitTelat / $totalKasus) : 0;
$periodeLabelTelat = sprintf('%02d/%04d', $month, $year);

$pageTitle = 'Rekap Telat Perizinan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Rekap Telat</p>
    <h1 class="h4 mb-1">Rekap telat perizinan</h1>
    <p class="text-muted mb-0">Daftar santri yang kembali melebihi batas izin (toleransi <?= (int) $lateTolerance ?> menit) — periode <strong><?= htmlspecialchars($periodeLabelTelat) ?></strong>.</p>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total kasus</div>
            <div class="app-mini-stat-value"><?= $totalKasus ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total menit telat</div>
            <div class="app-mini-stat-value text-warning"><?= $totalMenitTelat ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rata-rata menit</div>
            <div class="app-mini-stat-value"><?= $rataMenit ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Kasus &ge; 60 menit</div>
            <div class="app-mini-stat-value text-danger"><?= $kasusBerat ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="small text-muted mb-2">Batas telat dari pengaturan: <strong><?= (int) $lateTolerance ?> menit</strong></div>
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label">Bulan</label>
                <input type="number" class="form-control" min="1" max="12" name="month" value="<?= htmlspecialchars((string) $month) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Tahun</label>
                <input type="number" class="form-control" min="1400" max="2100" name="year" value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Tingkatan (opsional)</label>
                <input type="text" class="form-control" name="tingkatan" value="<?= htmlspecialchars($tingkatan) ?>" placeholder="Contoh: SMP">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-success w-100">Terapkan filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Daftar telat kembali</h2>
            <span class="small text-muted"><?= $totalKasus ?> kasus pada periode ini</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal Izin</th>
                        <th>Batas Kembali</th>
                        <th>Waktu Kembali</th>
                        <th>Telat</th>
                        <th>Nama</th>
                        <th>NIS</th>
                        <th>Tingkatan</th>
                        <th>Jenis Izin</th>
                        <th>Alasan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $lateMinutes = 0;
                            if (!empty($row['waktu_kembali'])) {
                                $limitTs = strtotime((string) $row['tanggal_selesai'] . ' ' . (string) $row['jam_selesai']);
                                $backTs = strtotime((string) $row['waktu_kembali']);
                                if ($limitTs !== false && $backTs !== false && $backTs > $limitTs) {
                                    $lateMinutes = (int) floor(($backTs - $limitTs) / 60);
                                }
                            }
                            $lateBadge = $lateMinutes >= 60 ? 'danger' : ($lateMinutes >= 30 ? 'warning' : 'secondary');
                            ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars($row['tanggal_mulai']) ?> s/d <?= htmlspecialchars($row['tanggal_selesai']) ?></td>
                                <td class="font-monospace"><?= htmlspecialchars(substr((string) $row['jam_selesai'], 0, 5)) ?></td>
                                <td class="font-monospace"><?= htmlspecialchars(date('H:i', strtotime((string) ($row['waktu_kembali'] ?? 'now')))) ?></td>
                                <td><span class="badge text-bg-<?= $lateBadge ?>"><?= (int) $lateMinutes ?> menit</span></td>
                                <td class="fw-semibold"><?= htmlspecialchars($row['nama_santri']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars($row['nis']) ?></td>
                                <td><?= htmlspecialchars($row['tingkatan'] ?: '-') ?></td>
                                <td><span class="badge text-bg-light text-dark border"><?= htmlspecialchars($row['jenis_izin']) ?></span></td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string) $row['alasan']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">Tidak ada data telat perizinan di periode ini.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php';
