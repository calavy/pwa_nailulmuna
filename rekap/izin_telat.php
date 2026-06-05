<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_telat.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';

require_roles(['admin', 'pengurus']);

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'izin')));
if (!in_array($tab, ['izin', 'kegiatan'], true)) {
    $tab = 'izin';
}

$periode = rekap_periode_resolve($pdo, $_GET, 'rentang');
$startDate = (string) $periode['dari'];
$endDate = (string) $periode['sampai'];

$namaFilter = trim((string) ($_GET['nama'] ?? ''));
$kegiatanFilter = trim((string) ($_GET['kegiatan'] ?? ''));
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));

$lateTolerance = (int) app_setting($pdo, 'batas_telat_menit', '15');
if ($lateTolerance < 0) {
    $lateTolerance = 0;
}

$rowsIzin = rekap_telat_izin_kembali($pdo, $startDate, $endDate, $lateTolerance, $namaFilter, $tingkatan);
$rowsKeg = rekap_telat_kegiatan($pdo, $startDate, $endDate, $lateTolerance, $namaFilter, $kegiatanFilter, $tingkatan);
$statsIzin = rekap_telat_izin_stats($rowsIzin);
$statsKeg = rekap_telat_kegiatan_stats($rowsKeg);
$stats = $tab === 'kegiatan' ? $statsKeg : $statsIzin;

$filterQuery = static function (array $extra = []) use ($startDate, $endDate, $namaFilter, $kegiatanFilter, $tingkatan, $tab): string {
    $q = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'tab' => $tab,
    ];
    if ($namaFilter !== '') {
        $q['nama'] = $namaFilter;
    }
    if ($kegiatanFilter !== '') {
        $q['kegiatan'] = $kegiatanFilter;
    }
    if ($tingkatan !== '') {
        $q['tingkatan'] = $tingkatan;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return app_href('/rekap/izin_telat.php?' . http_build_query($q));
};

$pageTitle = 'Rekap Telat';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Rekap Telat</p>
    <h1 class="h4 mb-1">Rekap telat perizinan &amp; kegiatan</h1>
    <p class="text-muted mb-0">Toleransi keterlambatan: <strong><?= (int) $lateTolerance ?> menit</strong> (dari pengaturan pondok).</p>
</div>

<?php
$formAction = app_href('/rekap/izin_telat.php');
$extraHidden = ['tab' => $tab];
require __DIR__ . '/../includes/partials/rekap_periode_filter.php';
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'izin' ? 'active' : '' ?>" href="<?= htmlspecialchars($filterQuery(['tab' => 'izin'])) ?>">Telat kembali izin</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'kegiatan' ? 'active' : '' ?>" href="<?= htmlspecialchars($filterQuery(['tab' => 'kegiatan'])) ?>">Telat kegiatan (scan)</a>
    </li>
</ul>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total kasus</div>
            <div class="app-mini-stat-value"><?= (int) $stats['total'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total menit telat</div>
            <div class="app-mini-stat-value text-warning"><?= (int) $stats['menit'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rata-rata menit</div>
            <div class="app-mini-stat-value"><?= (int) $stats['rata'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Kasus &ge; 60 menit</div>
            <div class="app-mini-stat-value text-danger"><?= (int) $stats['berat'] ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="periode_mode" value="<?= htmlspecialchars((string) $periode['mode']) ?>">
            <input type="hidden" name="dari" value="<?= htmlspecialchars($startDate) ?>">
            <input type="hidden" name="sampai" value="<?= htmlspecialchars($endDate) ?>">
            <div class="col-12 col-md-3">
                <label class="form-label">Nama / NIS</label>
                <input type="text" class="form-control" name="nama" value="<?= htmlspecialchars($namaFilter) ?>" placeholder="Cari nama atau NIS">
            </div>
            <?php if ($tab === 'kegiatan'): ?>
            <div class="col-12 col-md-2">
                <label class="form-label">Kegiatan</label>
                <input type="text" class="form-control" name="kegiatan" value="<?= htmlspecialchars($kegiatanFilter) ?>" placeholder="Nama kegiatan">
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-2">
                <label class="form-label">Tingkatan</label>
                <input type="text" class="form-control" name="tingkatan" value="<?= htmlspecialchars($tingkatan) ?>" placeholder="Opsional">
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-success w-100">Terapkan filter</button>
            </div>
        </form>
    </div>
</div>

<?php if ($tab === 'izin'): ?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Telat kembali izin</h2>
            <span class="small text-muted"><?= count($rowsIzin) ?> kasus · <?= htmlspecialchars($startDate) ?> s/d <?= htmlspecialchars($endDate) ?></span>
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
                    <?php if ($rowsIzin): ?>
                        <?php foreach ($rowsIzin as $row): ?>
                            <?php
                            $lateMinutes = 0;
                            if (!empty($row['waktu_kembali'])) {
                                $limitTs = strtotime((string) $row['tanggal_selesai'] . ' ' . (string) $row['jam_selesai']);
                                $backTs = strtotime((string) $row['waktu_kembali']);
                                if ($limitTs !== false && $backTs !== false && $backTs > $limitTs) {
                                    $lateMinutes = (int) floor(($backTs - $limitTs) / 60);
                                }
                            }
                            ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars((string) $row['tanggal_mulai']) ?> s/d <?= htmlspecialchars((string) $row['tanggal_selesai']) ?></td>
                                <td class="font-monospace"><?= htmlspecialchars(substr((string) $row['jam_selesai'], 0, 5)) ?></td>
                                <td class="font-monospace"><?= htmlspecialchars(date('H:i', strtotime((string) ($row['waktu_kembali'] ?? 'now')))) ?></td>
                                <td><span class="badge text-bg-<?= rekap_telat_badge_class($lateMinutes) ?>"><?= (int) $lateMinutes ?> menit</span></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string) $row['nama_santri']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars((string) $row['nis']) ?></td>
                                <td><?= htmlspecialchars((string) ($row['tingkatan'] ?: '-')) ?></td>
                                <td><span class="badge text-bg-light text-dark border"><?= htmlspecialchars((string) $row['jenis_izin']) ?></span></td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string) $row['alasan']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center text-muted">Tidak ada data telat kembali izin pada filter ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Telat kegiatan (scan presensi)</h2>
            <span class="small text-muted"><?= count($rowsKeg) ?> kasus · <?= htmlspecialchars($startDate) ?> s/d <?= htmlspecialchars($endDate) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jam Scan</th>
                        <th>Kegiatan</th>
                        <th>Telat</th>
                        <th>Nama</th>
                        <th>NIS</th>
                        <th>Tingkatan</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rowsKeg): ?>
                        <?php foreach ($rowsKeg as $row): ?>
                            <?php $lateMinutes = (int) ($row['telat_menit'] ?? 0); ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars((string) $row['tanggal_presensi']) ?></td>
                                <td class="font-monospace"><?= htmlspecialchars(substr((string) ($row['jam_presensi'] ?? ''), 0, 5)) ?></td>
                                <td><?= htmlspecialchars((string) ($row['nama_kegiatan'] ?? '-')) ?></td>
                                <td><span class="badge text-bg-<?= rekap_telat_badge_class($lateMinutes) ?>"><?= (int) $lateMinutes ?> menit</span></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string) $row['nama_santri']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars((string) $row['nis']) ?></td>
                                <td><?= htmlspecialchars((string) ($row['tingkatan'] ?: '-')) ?></td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string) ($row['catatan'] ?? '')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted">Tidak ada data telat kegiatan pada filter ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php';
