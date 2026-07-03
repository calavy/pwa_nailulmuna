<?php



declare(strict_types=1);



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../helpers/app.php';

require_once __DIR__ . '/../helpers/pkpps.php';

pkpps_ensure_schema($pdo);

$stats = pkpps_dashboard_stats($pdo);
$loadMingguDetail = isset($_GET['minggu']) && (string) $_GET['minggu'] === '1';
$mingguKeaktivan = $loadMingguDetail
    ? pkpps_dashboard_keaktivan_minggu_cached($pdo)
    : null;



$hubLinks = [

    ['path' => '/pkpps/import_santri.php', 'icon' => 'fa-solid fa-file-import', 'label' => 'Import santri', 'desc' => 'Unggah data santri PKPPS dari spreadsheet'],

    ['path' => '/pkpps/import.php', 'icon' => 'fa-solid fa-file-import', 'label' => 'Import jadwal', 'desc' => 'Unggah jadwal kegiatan PKPPS'],
    ['path' => '/pkpps/rapor.php', 'icon' => 'fa-solid fa-file-lines', 'label' => 'Rapor PKPPS', 'desc' => 'Kelola rapor program PKPPS & unggah PDF'],
    ['path' => '/pkpps/pengaturan_rapor.php', 'icon' => 'fa-solid fa-sliders', 'label' => 'Pengaturan rapor', 'desc' => 'Label, judul cetak, dan pesan WA rapor PKPPS'],

    ['path' => '/settings/tingkatan.php#pkpps', 'icon' => 'fa-solid fa-layer-group', 'label' => 'Tingkatan PKPPS', 'desc' => 'Master tingkatan program'],

];



$pageTitle = 'Dashboard PKPPS';

require_once __DIR__ . '/../includes/header.php';

?>



<div class="page-intro mb-3">

    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-pkpps')) ?>">PKPPS</a></p>

    <h1 class="h4 mb-1">Dashboard PKPPS</h1>

    <p class="text-muted mb-0 small">Ringkasan data santri, jadwal, dan keaktivan — navigasi modul lewat tab di atas.</p>

</div>



<div class="row g-2 mb-3">

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Santri aktif</div>

            <div class="app-mini-stat-value text-primary"><?= (int) $stats['santri_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Tingkatan</div>

            <div class="app-mini-stat-value"><?= (int) $stats['tingkatan_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Jadwal aktif</div>

            <div class="app-mini-stat-value text-success"><?= (int) $stats['jadwal_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Pembimbing di jadwal</div>

            <div class="app-mini-stat-value"><?= (int) $stats['pembimbing_jadwal'] ?></div>

        </div>

    </div>

</div>



<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <div>
            <h2 class="h6 mb-0">Keaktivan 1 minggu</h2>
            <?php if ($loadMingguDetail && is_array($mingguKeaktivan)): ?>
                <div class="small text-muted"><?= htmlspecialchars((string) $mingguKeaktivan['label']) ?></div>
            <?php else: ?>
                <div class="small text-muted">Ringkasan presensi 7 hari — dimuat on demand agar halaman cepat.</div>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <?php if (!$loadMingguDetail): ?>
                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(app_href('/pkpps/index.php?minggu=1')) ?>">Muat ringkasan minggu</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">Sembunyikan</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php')) ?>">Rekap lengkap</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$loadMingguDetail || !is_array($mingguKeaktivan)): ?>
            <p class="small text-muted mb-0">Klik <strong>Muat ringkasan minggu</strong> untuk melihat hadir/izin/alpa per hari dan tingkatan. Statistik di atas tetap tampil instan.</p>
        <?php elseif (!table_exists($pdo, 'presensi')): ?>
            <p class="small text-muted mb-0">Modul presensi belum diaktifkan.</p>
        <?php else:
            $mingguTotals = $mingguKeaktivan['totals'];
            $mingguPersen = $mingguTotals['total'] > 0
                ? round(($mingguTotals['hadir'] / $mingguTotals['total']) * 100, 1)
                : 0;
            $pbMinggu = $mingguKeaktivan['pembimbing'];
        ?>
            <div class="row g-2 mb-3 text-center">
                <div class="col-6 col-md">
                    <div class="app-mini-stat h-100">
                        <div class="app-mini-stat-label text-success">Hadir</div>
                        <div class="app-mini-stat-value text-success"><?= (int) $mingguTotals['hadir'] ?></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="app-mini-stat h-100">
                        <div class="app-mini-stat-label text-warning">Izin</div>
                        <div class="app-mini-stat-value text-warning"><?= (int) $mingguTotals['izin'] ?></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="app-mini-stat h-100">
                        <div class="app-mini-stat-label text-primary">Sakit</div>
                        <div class="app-mini-stat-value text-primary"><?= (int) $mingguTotals['sakit'] ?></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="app-mini-stat h-100">
                        <div class="app-mini-stat-label text-danger">Alpa</div>
                        <div class="app-mini-stat-value text-danger"><?= (int) $mingguTotals['alpa'] ?></div>
                    </div>
                </div>
                <div class="col-12 col-md">
                    <div class="app-mini-stat h-100">
                        <div class="app-mini-stat-label">Total sesi · kehadiran</div>
                        <div class="app-mini-stat-value"><?= (int) $mingguTotals['total'] ?> <span class="fs-6 text-muted">/ <?= htmlspecialchars((string) $mingguPersen) ?>%</span></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:.05em;">Per hari (santri PKPPS)</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Hari</th>
                                    <th class="text-center">H</th>
                                    <th class="text-center">I</th>
                                    <th class="text-center">S</th>
                                    <th class="text-center">A</th>
                                    <th class="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($mingguKeaktivan['per_hari'] as $hariRow): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars((string) ($hariRow['label'] ?? '')) ?></td>
                                    <td class="text-center text-success"><?= (int) ($hariRow['hadir'] ?? 0) ?></td>
                                    <td class="text-center text-warning"><?= (int) ($hariRow['izin'] ?? 0) ?></td>
                                    <td class="text-center text-primary"><?= (int) ($hariRow['sakit'] ?? 0) ?></td>
                                    <td class="text-center text-danger"><?= (int) ($hariRow['alpa'] ?? 0) ?></td>
                                    <td class="text-center"><?= (int) ($hariRow['total'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:.05em;">Per tingkatan</div>
                    <?php if ($mingguKeaktivan['per_tingkatan'] === []): ?>
                        <p class="small text-muted mb-3">Belum ada data presensi minggu ini.</p>
                    <?php else: ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tingkatan</th>
                                        <th class="text-center">H</th>
                                        <th class="text-center">A</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($mingguKeaktivan['per_tingkatan'] as $tkRow): ?>
                                    <tr>
                                        <td class="small"><?= htmlspecialchars((string) ($tkRow['nama_tingkatan'] ?? '')) ?></td>
                                        <td class="text-center text-success"><?= (int) ($tkRow['hadir'] ?? 0) ?></td>
                                        <td class="text-center text-danger"><?= (int) ($tkRow['alpa'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:.05em;">Pembimbing</div>
                    <p class="small mb-2">
                        <strong><?= (int) ($pbMinggu['pembimbing_hadir'] ?? 0) ?></strong> pembimbing hadir
                        · total scan <strong><?= (int) ($pbMinggu['total_hadir'] ?? 0) ?></strong>
                    </p>
                    <?php if (($pbMinggu['rows'] ?? []) !== []): ?>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach ($pbMinggu['rows'] as $pbRow): ?>
                                <li class="d-flex justify-content-between border-bottom py-1">
                                    <span><?= htmlspecialchars((string) ($pbRow['nama_pembimbing'] ?? '')) ?></span>
                                    <span class="text-muted"><?= (int) ($pbRow['total_hadir'] ?? 0) ?>× · <?= (int) ($pbRow['hari_hadir'] ?? 0) ?> hari</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Belum ada presensi pembimbing minggu ini.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info py-2 small mb-3">
    <strong>Tarif syahriyah PKPPS</strong> di
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=tarif#tambahan-pkpps')) ?>">Keuangan → Pengaturan → Tarif</a>.
    Import data: <a href="<?= htmlspecialchars(app_href('/pkpps/import_santri.php')) ?>">santri</a> ·
    <a href="<?= htmlspecialchars(app_href('/pkpps/import.php')) ?>">jadwal</a>.
    · <a href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktifan_hari.php')) ?>"><strong>Keaktifan PKPPS hari ini</strong></a> (tampilan kartu)
</div>



<div class="row g-3">

    <?php foreach ($hubLinks as $link): ?>

    <div class="col-sm-6 col-lg-4">

        <a href="<?= htmlspecialchars(app_href((string) $link['path'])) ?>" class="card h-100 text-decoration-none hub-link-card">

            <div class="card-body">

                <div class="d-flex align-items-start gap-3">

                    <span class="hub-link-card__icon" aria-hidden="true"><i class="<?= htmlspecialchars((string) $link['icon']) ?>"></i></span>

                    <div class="min-w-0">

                        <h2 class="h6 mb-1 text-dark"><?= htmlspecialchars((string) $link['label']) ?></h2>

                        <p class="small text-muted mb-0"><?= htmlspecialchars((string) $link['desc']) ?></p>

                    </div>

                </div>

            </div>

        </a>

    </div>

    <?php endforeach; ?>

</div>



<style>

.hub-link-card { border: 1px solid #e2e8f0; transition: border-color .15s, box-shadow .15s; }

.hub-link-card:hover { border-color: #0f766e; box-shadow: 0 4px 14px rgba(15,118,110,.12); }

.hub-link-card__icon { flex-shrink: 0; width: 2.5rem; height: 2.5rem; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: rgba(15,118,110,.1); color: #0f766e; font-size: 1.1rem; }

</style>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>


