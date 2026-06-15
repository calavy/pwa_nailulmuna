<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';

$bulanFilter = wali_portal_keaktifan_bulan_parse($pdo, $_GET);
$hijriBulanList = hijri_nama_bulan_list();
$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

$rekap = wali_portal_keaktifan_per_kegiatan(
    $pdo,
    $waliSantriId,
    (string) $bulanFilter['start'],
    (string) $bulanFilter['end'],
    trim((string) ($waliSantriRow['tingkatan'] ?? ''))
);
$totals = $rekap['totals'];
$kegiatanRows = $rekap['kegiatan'];
$tingkatanTampil = trim((string) ($rekap['tingkatan'] ?? ''));
$persenHadir = $totals['total'] > 0
    ? round(($totals['hadir'] / $totals['total']) * 100, 1)
    : 0;

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Keaktivan — Portal Wali', true, 'keaktifan');
$waliSwitcherRedirect = '/wali/keaktifan.php?tahun_h=' . (int) $bulanFilter['year'] . '&bulan_h=' . (int) $bulanFilter['month'];
require __DIR__ . '/partials/anak_switcher.php';
?>

        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Keaktivan Bulanan</h1>
                <p class="small text-muted mb-0">
                    Rekap per kegiatan jadwal tingkatan
                    <?php if ($tingkatanTampil !== ''): ?>
                        <strong><?= htmlspecialchars($tingkatanTampil) ?></strong>
                    <?php endif; ?>
                    — <?= htmlspecialchars((string) ($waliSantriRow['nama_tampil'] ?? '')) ?>.
                </p>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/wali/logout.php')) ?>">Keluar</a>
        </div>

        <div class="card wali-card shadow-sm mb-3">
            <div class="card-body py-3">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-5 col-sm-4">
                        <label class="form-label small mb-0">Bulan Hijriyah</label>
                        <select name="bulan_h" class="form-select form-select-sm" required>
                            <?php foreach ($hijriBulanList as $idx => $nama): ?>
                                <option value="<?= (int) $idx ?>"<?= (int) $bulanFilter['month'] === (int) $idx ? ' selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4 col-sm-3">
                        <label class="form-label small mb-0">Tahun H</label>
                        <input type="number" name="tahun_h" class="form-control form-control-sm" min="1300" max="1700" value="<?= (int) $bulanFilter['year'] ?>" required>
                    </div>
                    <div class="col-3 col-sm-3">
                        <button type="submit" class="btn btn-teal btn-sm w-100">Tampilkan</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!table_exists($pdo, 'presensi')): ?>
            <div class="card wali-card"><div class="card-body small text-muted text-center py-4">Modul presensi belum diaktifkan.</div></div>
        <?php else: ?>
            <div class="wali-hero mb-3">
                <div class="small text-muted mb-2">Ringkasan <?= htmlspecialchars((string) $bulanFilter['label']) ?></div>
                <div class="row g-2 text-center">
                    <div class="col-3">
                        <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                            <div class="small text-muted">Hadir</div>
                            <div class="fs-5 fw-bold text-success"><?= (int) $totals['hadir'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                            <div class="small text-muted">Izin</div>
                            <div class="fs-5 fw-bold text-warning"><?= (int) $totals['izin'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                            <div class="small text-muted">Sakit</div>
                            <div class="fs-5 fw-bold text-primary"><?= (int) $totals['sakit'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                            <div class="small text-muted">Alpa</div>
                            <div class="fs-5 fw-bold text-danger"><?= (int) $totals['alpa'] ?></div>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-2 text-center">
                    Total sesi: <strong><?= (int) $totals['total'] ?></strong>
                    · Kehadiran: <strong><?= htmlspecialchars((string) $persenHadir) ?>%</strong>
                </div>
            </div>

            <?php if ($kegiatanRows === []): ?>
                <div class="card wali-card"><div class="card-body small text-muted text-center py-4">Belum ada data presensi pada bulan ini.</div></div>
            <?php else: ?>
                <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.7rem;">Per kegiatan</div>
                <div class="card wali-card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kegiatan</th>
                                        <th class="text-center">H</th>
                                        <th class="text-center">I</th>
                                        <th class="text-center">S</th>
                                        <th class="text-center">A</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-end">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kegiatanRows as $kg): ?>
                                    <?php
                                    $kgTotal = (int) ($kg['total'] ?? 0);
                                    $kgHadir = (int) ($kg['hadir'] ?? 0);
                                    $kgAlpa = (int) ($kg['alpa'] ?? 0);
                                    $kgPersen = $kgTotal > 0 ? round(($kgHadir / $kgTotal) * 100, 1) : 0;
                                    $kategori = santri_category($kgAlpa, $goodMax, $mediumMax);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '-')) ?></div>
                                            <div class="text-muted" style="font-size:0.72rem;">Kategori: <?= htmlspecialchars($kategori) ?></div>
                                        </td>
                                        <td class="text-center text-success"><?= $kgHadir ?></td>
                                        <td class="text-center text-warning"><?= (int) ($kg['izin'] ?? 0) ?></td>
                                        <td class="text-center text-primary"><?= (int) ($kg['sakit'] ?? 0) ?></td>
                                        <td class="text-center text-danger"><?= $kgAlpa ?></td>
                                        <td class="text-center"><?= $kgTotal ?></td>
                                        <td class="text-end small"><?= htmlspecialchars((string) $kgPersen) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light fw-semibold small">
                                    <tr>
                                        <td>Jumlah bulan ini</td>
                                        <td class="text-center text-success"><?= (int) $totals['hadir'] ?></td>
                                        <td class="text-center text-warning"><?= (int) $totals['izin'] ?></td>
                                        <td class="text-center text-primary"><?= (int) $totals['sakit'] ?></td>
                                        <td class="text-center text-danger"><?= (int) $totals['alpa'] ?></td>
                                        <td class="text-center"><?= (int) $totals['total'] ?></td>
                                        <td class="text-end"><?= htmlspecialchars((string) $persenHadir) ?>%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0">H = Hadir, I = Izin, S = Sakit, A = Alpa. Hanya kegiatan yang masuk jadwal tingkatan santri yang dihitung. Periode mengikuti bulan Hijriyah pondok.</p>
            <?php endif; ?>
        <?php endif; ?>

        <p class="small text-muted text-center mt-4 mb-0">Data hanya untuk anak yang Anda akses melalui portal wali.</p>
<?php
wali_layout_foot(true, 'keaktifan');
