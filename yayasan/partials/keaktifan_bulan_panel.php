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

if (!function_exists('yayasan_home_href')) {
    require_once __DIR__ . '/../../helpers/yayasan.php';
}

$kbMonth = (int) ($kb['month'] ?? 1);
$kbYear = (int) ($kb['year'] ?? 1400);
$kbTingkatan = (string) ($kb['tingkatan'] ?? '');
$kbHijriMonths = (array) ($kb['bulan_names'] ?? $kb['hijri_months'] ?? []);
$kbRentangTampilan = (string) ($kb['rentang_tampilan'] ?? '');
$kbGoodMax = (int) ($kb['good_max'] ?? 1);
$kbMediumMax = (int) ($kb['medium_max'] ?? 3);
$kbPeriodeLabel = (string) ($kb['periode_label'] ?? '');
$kbStart = (string) ($kb['start_date'] ?? '');
$kbEnd = (string) ($kb['end_date'] ?? '');
$kbKegiatanKosong = (array) ($kb['kegiatan_tanpa_scan'] ?? []);
$kbSantriKosong = (array) ($kb['santri_tanpa_scan'] ?? []);
$kbJadwalTanpaScanCount = rekap_keaktifan_kegiatan_tanpa_scan_total_jadwal($kbKegiatanKosong);
$kbKegiatanTanpaScanCount = count(rekap_keaktifan_kegiatan_tanpa_scan_group_by_kegiatan($kbKegiatanKosong));
$kbTingkatanList = (array) ($kb['tingkatan_list'] ?? []);
$kbIncludeTanpaScan = !empty($kb['include_tanpa_scan']);
$kbSaran = $kbSaran ?? yayasan_keaktifan_bulan_saran($kb);
?>

<section id="yp-keaktifan-bulan" class="yp-keaktifan-bulan mt-3 mb-4">
    <div class="card border-0 shadow-sm border-start border-4 border-info">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Rekap Keaktifan — <?= htmlspecialchars($kbPeriodeLabel) ?></h2>
                    <p class="small text-muted mb-0">
                        <?php if ($kbRentangTampilan !== ''): ?>
                            <?= htmlspecialchars($kbRentangTampilan) ?>
                        <?php else: ?>
                            <?= htmlspecialchars(date('d-m-Y', strtotime($kbStart))) ?> s.d. <?= htmlspecialchars(date('d-m-Y', strtotime($kbEnd))) ?>
                        <?php endif; ?>
                        <?= $kbTingkatan !== '' ? ' · ' . htmlspecialchars($kbTingkatan) : '' ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(yayasan_home_href()) ?>">
                        <i class="fa-solid fa-house me-1"></i>Dashboard
                    </a>
                    <a class="btn btn-sm btn-outline-info" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">
                        <i class="fa-solid fa-signal me-1"></i>Hari ini
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ypKeaktifanBulanPanel" aria-controls="ypKeaktifanBulanPanel">
                        <i class="fa-solid fa-xmark me-1"></i>Tutup
                    </button>
                </div>
            </div>

            <?php
            $periodeLabel = $kbPeriodeLabel;
            require __DIR__ . '/../../includes/partials/yayasan_periode_rekap_link.php';
            ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-yp-kb-refresh="1" title="Muat ulang ringkasan bulan ini">
                    <i class="fa-solid fa-rotate-right me-1"></i>Segarkan
                </button>
                <a class="btn btn-outline-info btn-sm" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan_ranking.php')) ?>">
                    <i class="fa-solid fa-ranking-star me-1"></i>Ranking tingkatan
                </a>
            </div>
            <p class="small text-muted mb-3"><?= htmlspecialchars(ucfirst(rekap_keaktifan_rekap_footnote($pdo))) ?>.</p>

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
                        <div class="yp-mini-stat__label">Waktu tanpa scan</div>
                        <div class="yp-mini-stat__value text-danger"><?= (int) $kbJadwalTanpaScanCount ?></div>
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
                <span class="badge text-bg-success">Baik: 81–100%</span>
                <span class="badge text-bg-info">Cukup: 61–80%</span>
                <span class="badge text-bg-warning">Sedang: 41–60%</span>
                <span class="badge text-bg-kurang">Kurang: 21–40%</span>
                <span class="badge text-bg-danger">Buruk: ≤ 20%</span>
            </div>
            <p class="small text-muted mb-3">ABSENSI = N.HARI − (Alpa×4 + Izin×2 + Sakit×1 + Telat×3), minimum 0. % kehadiran = ABSENSI ÷ N.HARI. HADIR lewat batas telat dihitung Telat.</p>

            <div class="alert alert-light border mb-3 py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold small mb-1">Perbandingan per tingkatan</div>
                        <p class="small text-muted mb-0">Detail grafik &amp; ranking tingkatan ada di halaman khusus — tidak ditampilkan di sini agar dashboard tetap ringan.</p>
                    </div>
                    <a class="btn btn-sm btn-outline-info" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan_ranking.php')) ?>">
                        <i class="fa-solid fa-ranking-star me-1"></i>Ranking tingkatan
                    </a>
                </div>
            </div>

            <div class="row g-3" id="yp-kb-tanpa-scan">
                <?php if (!$kbIncludeTanpaScan): ?>
                <div class="col-12">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-yp-kb-load-tanpa-scan="1">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>Muat jadwal &amp; santri tanpa scan
                    </button>
                    <p class="small text-muted mb-0 mt-2">Bagian ini membutuhkan query tambahan — muat bila perlu audit ketertiban scan.</p>
                </div>
                <?php else: ?>
                <div class="col-12 col-lg-6">
                    <h3 class="h6 mb-2">Waktu tanpa scan hadir (<?= (int) $kbJadwalTanpaScanCount ?> waktu · <?= (int) $kbKegiatanTanpaScanCount ?> kegiatan)</h3>
                    <?php if ($kbKegiatanKosong === []): ?>
                        <div class="alert alert-success py-2 small mb-0">Semua jadwal kegiatan yang sudah lewat waktu pada periode ini sudah pernah discan hadir.</div>
                    <?php else: ?>
                        <?php
                        $ktsSlotRows = $kbKegiatanKosong;
                        $ktsListPrefix = 'ypkb';
                        require __DIR__ . '/../../includes/partials/kegiatan_tanpa_scan_grouped.php';
                        ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

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
