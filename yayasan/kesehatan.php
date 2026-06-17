<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_kesehatan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$pack = yayasan_kesehatan_pack($pdo, [
    'mode' => $_GET['mode'] ?? 'hijriyah',
    'month' => $_GET['month'] ?? null,
    'year' => $_GET['year'] ?? null,
    'tingkatan' => $_GET['tingkatan'] ?? '',
]);

$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$summary = (array) ($pack['summary'] ?? []);
$mode = (string) ($pack['mode'] ?? 'hijriyah');
$month = (int) ($pack['month'] ?? 1);
$year = (int) ($pack['year'] ?? 1400);
$tingkatan = (string) ($pack['tingkatan'] ?? '');
$hijriMonths = (array) ($pack['hijri_months'] ?? []);
$tingkatanList = (array) ($pack['tingkatan_list'] ?? []);

$pageTitle = 'Laporan Kesehatan Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1">
            <a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a>
            · <a href="<?= htmlspecialchars(app_href('/yayasan/operasional.php')) ?>">Operasional</a>
        </p>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
            <div>
                <h1 class="h3 mb-1">Laporan Kesehatan</h1>
                <p class="text-muted mb-0">Rekap izin sakit disetujui &amp; catatan E-Health — <?= htmlspecialchars((string) ($pack['periode_label'] ?? '')) ?></p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">
                <i class="fa-solid fa-notes-medical me-1"></i>Input izin sakit
            </a>
        </div>
    </header>

    <?php if (empty($pack['ready'])): ?>
        <div class="alert alert-warning">Modul perizinan belum tersedia.</div>
    <?php else: ?>

    <form method="get" class="card border-0 shadow-sm mb-4">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Kalender</label>
                <select name="mode" class="form-select form-select-sm">
                    <option value="hijriyah"<?= $mode === 'hijriyah' ? ' selected' : '' ?>>Hijriyah</option>
                    <option value="masehi"<?= $mode === 'masehi' ? ' selected' : '' ?>>Masehi</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <?php
                        $mLabel = $mode === 'hijriyah'
                            ? (string) ($hijriMonths[$m] ?? $m)
                            : date('F', mktime(0, 0, 0, $m, 1));
                        ?>
                        <option value="<?= $m ?>"<?= $month === $m ? ' selected' : '' ?>><?= htmlspecialchars($mLabel) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tahun</label>
                <input type="number" class="form-control form-control-sm" name="year" min="1300" max="2100" value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Tingkatan</label>
                <select name="tingkatan" class="form-select form-select-sm">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($tingkatanList as $tk): ?>
                        <option value="<?= htmlspecialchars((string) $tk) ?>"<?= strcasecmp($tingkatan, (string) $tk) === 0 ? ' selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Tampilkan</button>
            </div>
        </div>
        <div class="card-footer small text-muted py-2">
            Periode masehi: <?= htmlspecialchars(date('d-m-Y', strtotime((string) $pack['start_date']))) ?>
            s.d. <?= htmlspecialchars(date('d-m-Y', strtotime((string) $pack['end_date']))) ?>
            <?php if ($mode === 'masehi' && !empty($pack['hijri_label'])): ?>
                · setara <?= htmlspecialchars((string) $pack['hijri_label']) ?>
            <?php endif; ?>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="small text-muted text-uppercase fw-bold">Kasus izin sakit</div>
                    <div class="fs-2 fw-bold text-info"><?= (int) ($summary['total_kasus'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($summary['total_santri'] ?? 0) ?> santri unik</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="small text-muted text-uppercase fw-bold">Total hari sakit</div>
                    <div class="fs-2 fw-bold text-primary"><?= (int) ($summary['total_hari_sakit'] ?? 0) ?></div>
                    <div class="small text-muted">Rata <?= htmlspecialchars((string) ($summary['rata_hari_per_santri'] ?? 0)) ?> hari/santri</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body">
                    <div class="small text-muted text-uppercase fw-bold">Sakit aktif hari ini</div>
                    <div class="fs-2 fw-bold text-warning"><?= (int) ($summary['sakit_aktif_hari_ini'] ?? 0) ?></div>
                    <div class="small text-muted">Izin sakit / presensi sakit</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="small text-muted text-uppercase fw-bold">E-Health &amp; suhu tinggi</div>
                    <div class="fs-2 fw-bold text-danger"><?= (int) ($summary['ehealth_records'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($summary['suhu_tinggi'] ?? 0) ?> catatan ≥38°C</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Tren 6 Bulan — Kasus &amp; Santri</div>
                <div class="card-body">
                    <div style="height:260px"><canvas id="chartKesehatanBulan"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Per Tingkatan — Hari Sakit</div>
                <div class="card-body">
                    <div style="height:260px"><canvas id="chartKesehatanTingkatan"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Status Penanganan (E-Health)</div>
                <div class="card-body d-flex justify-content-center">
                    <div style="height:220px;width:100%;max-width:320px"><canvas id="chartKesehatanStatus"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Distribusi Suhu Tubuh</div>
                <div class="card-body">
                    <div style="height:220px"><canvas id="chartKesehatanSuhu"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($pack['gejala_top'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Gejala Terbanyak (E-Health)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Gejala</th><th class="text-end">Frekuensi</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $pack['gejala_top'] as $g): ?>
                        <tr>
                            <td><?= htmlspecialchars(ucfirst((string) ($g['gejala'] ?? ''))) ?></td>
                            <td class="text-end font-monospace"><?= (int) ($g['jumlah'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Ranking Santri</span>
                    <span class="badge text-bg-secondary"><?= count((array) ($pack['per_santri'] ?? [])) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:360px;overflow-y:auto">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light sticky-top"><tr><th>Santri</th><th class="text-end">Kasus</th><th class="text-end">Hari</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($pack['per_santri'] ?? []) as $ps): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars((string) ($ps['nama_santri'] ?? '')) ?></div>
                                        <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($ps['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($ps['tingkatan'] ?? '')) ?></div>
                                    </td>
                                    <td class="text-end small"><?= (int) ($ps['kasus'] ?? 0) ?></td>
                                    <td class="text-end small fw-semibold text-primary"><?= (int) ($ps['hari_sakit'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($pack['per_santri'] ?? []) === []): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Sakit Aktif Hari Ini</div>
                <div class="card-body p-0">
                    <?php $aktif = (array) ($pack['aktif_hari_ini'] ?? []); ?>
                    <?php if ($aktif === []): ?>
                        <div class="p-4 text-center text-muted">Tidak ada santri sakit yang perlu perhatian hari ini.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light"><tr><th>Santri</th><th>Periode</th><th>Sumber</th><th>Alasan</th></tr></thead>
                                <tbody>
                                <?php foreach ($aktif as $a): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($a['nama_santri'] ?? '')) ?></div>
                                            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($a['tingkatan'] ?? '')) ?></div>
                                        </td>
                                        <td class="small text-nowrap"><?= htmlspecialchars((string) ($a['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($a['tanggal_selesai'] ?? '')) ?></td>
                                        <td class="small"><span class="badge text-bg-info"><?= htmlspecialchars(str_replace('_', ' ', (string) ($a['sumber'] ?? ''))) ?></span></td>
                                        <td class="small"><?= htmlspecialchars((string) ($a['alasan'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span>Detail Izin Sakit — <?= htmlspecialchars((string) ($pack['periode_label'] ?? '')) ?></span>
            <span class="badge text-bg-info"><?= count((array) ($pack['detail_rows'] ?? [])) ?> kasus</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Santri</th>
                            <th>Periode izin</th>
                            <th class="text-end">Hari</th>
                            <th>Gejala / alasan</th>
                            <th>Suhu</th>
                            <th>Status</th>
                            <th>Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ((array) ($pack['detail_rows'] ?? []) as $dr): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars((string) ($dr['nama_santri'] ?? '')) ?></div>
                                <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($dr['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($dr['tingkatan'] ?? '')) ?></div>
                            </td>
                            <td class="small text-nowrap">
                                <?= htmlspecialchars((string) ($dr['tanggal_mulai'] ?? '')) ?>
                                – <?= htmlspecialchars((string) ($dr['tanggal_selesai'] ?? '')) ?>
                            </td>
                            <td class="text-end small fw-semibold"><?= (int) ($dr['hari_efektif'] ?? 0) ?></td>
                            <td class="small" style="max-width:14rem">
                                <?= htmlspecialchars((string) (($dr['gejala'] ?? '') !== '' ? $dr['gejala'] : ($dr['alasan'] ?? '—'))) ?>
                            </td>
                            <td class="small font-monospace">
                                <?= $dr['suhu_tubuh'] !== null && $dr['suhu_tubuh'] !== '' ? htmlspecialchars((string) $dr['suhu_tubuh']) . '°C' : '—' ?>
                            </td>
                            <td class="small"><?= htmlspecialchars(yayasan_kesehatan_status_label((string) ($dr['status_kesehatan'] ?? ''))) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string) ($dr['tindakan'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($pack['detail_rows'] ?? []) === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada izin sakit pada periode ini.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($pack['per_tingkatan'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Ringkasan per Tingkatan</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tingkatan</th><th class="text-end">Santri</th><th class="text-end">Kasus</th><th class="text-end">Hari sakit</th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $pack['per_tingkatan'] as $pt): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars((string) ($pt['tingkatan'] ?? '')) ?></td>
                            <td class="text-end"><?= (int) ($pt['jumlah_santri'] ?? 0) ?></td>
                            <td class="text-end"><?= (int) ($pt['kasus'] ?? 0) ?></td>
                            <td class="text-end fw-semibold text-primary"><?= (int) ($pt['hari_sakit'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        const bulan = <?= json_encode($pack['chart_bulan'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const tingkatan = <?= json_encode($pack['chart_tingkatan'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const status = <?= json_encode($pack['chart_status'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
        const suhu = <?= json_encode($pack['chart_suhu'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

        const elBulan = document.getElementById('chartKesehatanBulan');
        if (elBulan && bulan.labels && bulan.labels.length) {
            new Chart(elBulan, {
                type: 'line',
                data: {
                    labels: bulan.labels,
                    datasets: [
                        {
                            label: 'Kasus izin sakit',
                            data: bulan.kasus,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.12)',
                            fill: true,
                            tension: 0.25,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Santri unik',
                            data: bulan.santri,
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.08)',
                            fill: false,
                            tension: 0.25,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
                }
            });
        }

        const elTingkatan = document.getElementById('chartKesehatanTingkatan');
        if (elTingkatan && tingkatan.labels && tingkatan.labels.length) {
            new Chart(elTingkatan, {
                type: 'bar',
                data: {
                    labels: tingkatan.labels,
                    datasets: [
                        { label: 'Hari sakit', data: tingkatan.hari, backgroundColor: '#3b82f6' },
                        { label: 'Kasus', data: tingkatan.kasus, backgroundColor: '#93c5fd' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
                }
            });
        }

        const elStatus = document.getElementById('chartKesehatanStatus');
        if (elStatus && status.labels && status.labels.length) {
            new Chart(elStatus, {
                type: 'doughnut',
                data: {
                    labels: status.labels,
                    datasets: [{
                        data: status.values,
                        backgroundColor: ['#0d6efd', '#dc3545', '#fd7e14', '#198754']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
                }
            });
        }

        const elSuhu = document.getElementById('chartKesehatanSuhu');
        if (elSuhu && suhu.labels && suhu.labels.length) {
            new Chart(elSuhu, {
                type: 'bar',
                data: {
                    labels: suhu.labels,
                    datasets: [{
                        label: 'Catatan',
                        data: suhu.values,
                        backgroundColor: ['#22c55e', '#facc15', '#fb923c', '#ef4444', '#94a3b8']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }
    })();
    </script>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
