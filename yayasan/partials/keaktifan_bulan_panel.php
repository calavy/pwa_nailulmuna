<?php

declare(strict_types=1);

/**
 * Panel rekap keaktifan bulanan (di dalam collapse dashboard yayasan).
 *
 * @var array<string, mixed> $kb
 * @var string $kbFormAction
 * @var list<string> $kbSaran
 */
if (empty($kb['ready'])) {
    return;
}

$kbMonth = (int) ($kb['month'] ?? 1);
$kbYear = (int) ($kb['year'] ?? 1400);
$kbTingkatan = (string) ($kb['tingkatan'] ?? '');
$kbHijriMonths = (array) ($kb['hijri_months'] ?? []);
$kbGoodMax = (int) ($kb['good_max'] ?? 1);
$kbMediumMax = (int) ($kb['medium_max'] ?? 3);
$kbPeriodeLabel = (string) ($kb['periode_label'] ?? '');
$kbStart = (string) ($kb['start_date'] ?? '');
$kbEnd = (string) ($kb['end_date'] ?? '');
$kbTingkatanPersen = (array) ($kb['tingkatan_persen'] ?? []);
$kbTingkatanChart = (array) ($kb['tingkatan_chart'] ?? []);
$kbKegiatanKosong = (array) ($kb['kegiatan_tanpa_scan'] ?? []);
$kbSantriKosong = (array) ($kb['santri_tanpa_scan'] ?? []);
$kbTingkatanList = (array) ($kb['tingkatan_list'] ?? []);
$kbShowChart = !empty($kb['show_chart']);
$kbChartUid = 'ypKb' . substr(md5($kbFormAction . $kbMonth . $kbYear), 0, 8);
$kbSaran = $kbSaran ?? yayasan_keaktifan_bulan_saran($kb);
?>

<section id="yp-keaktifan-bulan" class="yp-keaktifan-bulan mt-3 mb-4">
    <div class="card border-0 shadow-sm border-start border-4 border-info">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Rekap Keaktifan — <?= htmlspecialchars($kbPeriodeLabel) ?></h2>
                    <p class="small text-muted mb-0">
                        <?= htmlspecialchars(date('d-m-Y', strtotime($kbStart))) ?> s.d. <?= htmlspecialchars(date('d-m-Y', strtotime($kbEnd))) ?>
                        <?= $kbTingkatan !== '' ? ' · ' . htmlspecialchars($kbTingkatan) : '' ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-outline-info" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">
                        <i class="fa-solid fa-signal me-1"></i>Hari ini
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ypKeaktifanBulanPanel" aria-controls="ypKeaktifanBulanPanel">
                        <i class="fa-solid fa-xmark me-1"></i>Tutup
                    </button>
                </div>
            </div>

            <form method="get" action="<?= htmlspecialchars(app_href($kbFormAction)) ?>#yp-keaktifan-bulan" class="row g-2 align-items-end mb-3 yp-filter-bar">
                <input type="hidden" name="kb_mode" value="hijriyah">
                <input type="hidden" name="kb_open" value="1">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-0">Bulan Hijriyah</label>
                    <select name="kb_month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>"<?= $kbMonth === $m ? ' selected' : '' ?>><?= htmlspecialchars((string) ($kbHijriMonths[$m] ?? $m)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-0">Tahun Hijriyah</label>
                    <input type="number" class="form-control form-control-sm" name="kb_year" min="1300" max="1700" value="<?= htmlspecialchars((string) $kbYear) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-0">Tingkatan</label>
                    <?php if ($kbTingkatanList !== []): ?>
                        <select name="kb_tingkatan" class="form-select form-select-sm">
                            <option value="">Semua tingkatan</option>
                            <?php foreach ($kbTingkatanList as $tk): ?>
                                <option value="<?= htmlspecialchars((string) $tk) ?>"<?= strcasecmp($kbTingkatan, (string) $tk) === 0 ? ' selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control form-control-sm" name="kb_tingkatan" value="<?= htmlspecialchars($kbTingkatan) ?>" placeholder="Opsional">
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
                </div>
            </form>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="yp-mini-stat">
                        <div class="yp-mini-stat__label">Santri terhitung</div>
                        <div class="yp-mini-stat__value"><?= (int) ($kb['total_santri'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="yp-mini-stat">
                        <div class="yp-mini-stat__label">% Hadir</div>
                        <div class="yp-mini-stat__value text-success"><?= htmlspecialchars((string) ($kb['rata_hadir'] ?? 0)) ?>%</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="yp-mini-stat">
                        <div class="yp-mini-stat__label">Jadwal tanpa scan</div>
                        <div class="yp-mini-stat__value text-danger"><?= count($kbKegiatanKosong) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="yp-mini-stat">
                        <div class="yp-mini-stat__label">Santri tanpa scan</div>
                        <div class="yp-mini-stat__value text-danger"><?= count($kbSantriKosong) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($kbSaran !== []): ?>
            <div class="alert alert-light border mb-3 py-2">
                <div class="fw-semibold small mb-2"><i class="fa-solid fa-lightbulb text-warning me-1"></i>Saran perbaikan</div>
                <ul class="small mb-0 ps-3">
                    <?php foreach ($kbSaran as $tip): ?>
                        <li class="mb-1"><?= htmlspecialchars($tip) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge text-bg-success">Bagus: Alpa = 0</span>
                <span class="badge text-bg-info">Baik: Alpa 1–<?= $kbGoodMax ?></span>
                <span class="badge text-bg-warning">Sedang: Alpa <?= $kbGoodMax + 1 ?>–<?= $kbMediumMax ?></span>
                <span class="badge text-bg-danger">Buruk: Alpa &gt; <?= $kbMediumMax ?></span>
            </div>

            <?php if ($kbShowChart): ?>
            <div class="mb-4">
                <h3 class="h6 text-secondary mb-2">Perbandingan kategori per tingkatan</h3>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Tingkatan</th>
                            <th class="text-center">Santri</th>
                            <th class="text-center text-info">% Baik</th>
                            <th class="text-center text-warning">% Sedang</th>
                            <th class="text-center text-danger">% Buruk</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kbTingkatanPersen as $tg => $tkRow): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string) $tg) ?></td>
                                <td class="text-center"><?= (int) ($tkRow['santri_count'] ?? 0) ?></td>
                                <?php foreach (rekap_keaktifan_kategori_perbandingan() as $katKey): ?>
                                    <td class="text-center fw-semibold"><?= htmlspecialchars((string) ($tkRow['persen'][$katKey] ?? 0)) ?>%</td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-xl-7">
                        <div class="position-relative" style="min-height:260px">
                            <canvas id="chart<?= htmlspecialchars($kbChartUid) ?>Grouped" aria-label="Grafik Baik Sedang Buruk per tingkatan"></canvas>
                        </div>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="position-relative" style="min-height:260px">
                            <canvas id="chart<?= htmlspecialchars($kbChartUid) ?>Stacked" aria-label="Komposisi kategori per tingkatan"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <h3 class="h6 mb-2">Jadwal tanpa scan hadir (<?= count($kbKegiatanKosong) ?>)</h3>
                    <?php if ($kbKegiatanKosong === []): ?>
                        <div class="alert alert-success py-2 small mb-0">Semua jadwal kegiatan yang sudah lewat waktu pada periode ini sudah pernah discan hadir.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Kegiatan</th>
                                    <th>Waktu</th>
                                    <th>Tingkatan</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kbKegiatanKosong as $idx => $kgRow): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td class="small text-nowrap">
                                            <?= htmlspecialchars((string) ($kgRow['tanggal_tampil'] ?? '')) ?>
                                            <span class="text-muted d-block"><?= htmlspecialchars((string) ($kgRow['hari'] ?? '')) ?></span>
                                        </td>
                                        <td class="fw-semibold text-danger"><?= htmlspecialchars((string) $kgRow['nama_kegiatan']) ?></td>
                                        <td class="small text-nowrap"><?= htmlspecialchars((string) ($kgRow['jam'] ?? '')) ?></td>
                                        <td class="small"><?= htmlspecialchars((string) $kgRow['tingkatan_label']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-lg-6">
                    <h3 class="h6 mb-2">Santri tanpa scan hadir</h3>
                    <?php if ($kbSantriKosong === []): ?>
                        <div class="alert alert-success py-2 small mb-0">Semua santri terikat jadwal sudah pernah scan hadir.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                <tr><th>No</th><th>NIS</th><th>Nama</th><th>Tingkatan</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kbSantriKosong as $idx => $sRow): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><?= htmlspecialchars((string) $sRow['nis']) ?></td>
                                        <td class="fw-semibold text-danger"><?= htmlspecialchars((string) $sRow['nama_santri']) ?></td>
                                        <td><?= htmlspecialchars((string) $sRow['tingkatan']) ?></td>
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
</section>

<?php if ($kbShowChart): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?= json_encode($kbTingkatanChart['labels'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const grouped = <?= json_encode($kbTingkatanChart['datasets'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const stacked = <?= json_encode($kbTingkatanChart['stacked_datasets'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const uid = <?= json_encode($kbChartUid, JSON_UNESCAPED_UNICODE) ?>;
    let chartsReady = false;

    function initKbCharts() {
        if (chartsReady || typeof Chart === 'undefined' || !labels.length) {
            return;
        }
        const groupedEl = document.getElementById('chart' + uid + 'Grouped');
        const stackedEl = document.getElementById('chart' + uid + 'Stacked');
        if (!groupedEl && !stackedEl) {
            return;
        }
        chartsReady = true;

        if (groupedEl) {
            new Chart(groupedEl, {
                type: 'bar',
                data: { labels: labels, datasets: grouped },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
                }
            });
        }
        if (stackedEl) {
            new Chart(stackedEl, {
                type: 'bar',
                data: { labels: labels, datasets: stacked },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } }
                }
            });
        }
    }

    const panel = document.getElementById('ypKeaktifanBulanPanel');
    if (panel) {
        panel.addEventListener('shown.bs.collapse', initKbCharts);
        if (panel.classList.contains('show')) {
            initKbCharts();
        }
    }
})();
</script>
<?php endif; ?>

<script>
(function () {
    var panel = document.getElementById('ypKeaktifanBulanPanel');
    var toggle = document.getElementById('ypKeaktifanBulanToggle');
    if (!panel || !toggle) {
        return;
    }
    function syncHint() {
        var open = panel.classList.contains('show');
        var hint = toggle.querySelector('.yp-nav-card__hint-text');
        if (hint) {
            hint.textContent = open ? 'Ketuk untuk tutup' : 'Ketuk untuk buka';
        }
    }
    panel.addEventListener('shown.bs.collapse', syncHint);
    panel.addEventListener('hidden.bs.collapse', syncHint);
    if (window.location.hash === '#yp-keaktifan-bulan' && !panel.classList.contains('show')) {
        if (window.bootstrap && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).show();
        }
    }
    panel.addEventListener('shown.bs.collapse', function () {
        var anchor = document.getElementById('yp-keaktifan-bulan');
        if (anchor && typeof anchor.scrollIntoView === 'function') {
            anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();
</script>
