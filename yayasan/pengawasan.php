<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_dashboard.php';
require_once __DIR__ . '/../helpers/yayasan_portal.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$snap = yayasan_dashboard_snapshot_cached($pdo);
$ketertiban = yayasan_ketertiban_ringkasan($pdo);
$months = $snap['months'] ?? [];
$masuk = $snap['keuangan_masuk'] ?? [];
$keluar = $snap['keuangan_keluar'] ?? [];
$maxKeu = max(1, (int) ($snap['max_keuangan'] ?? 1));
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Dashboard Pengawasan Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · <a href="<?= htmlspecialchars(app_href('/yayasan/operasional.php')) ?>">Operasional</a></p>
        <h1 class="h3 mb-1">Dashboard Pengawasan</h1>
        <p class="text-muted mb-0">Manajemen khusus — arus kas periodik & ketertiban hari ini.</p>
    </header>

    <section class="mb-4">
        <h2 class="h5 mb-3"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Keuangan — 6 Bulan Terakhir</h2>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-2 mb-3 text-center small">
                    <div class="col-4">
                        <div class="text-muted">Masuk bln ini</div>
                        <div class="fw-bold text-success"><?= $fmt((int) ($snap['masuk_bulan_ini'] ?? 0)) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Keluar bln ini</div>
                        <div class="fw-bold text-danger"><?= $fmt((int) ($snap['keluar_bulan_ini'] ?? 0)) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Net</div>
                        <div class="fw-bold <?= (int) ($snap['net_bulan_ini'] ?? 0) < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $fmt((int) ($snap['net_bulan_ini'] ?? 0)) ?>
                        </div>
                    </div>
                </div>
                <div class="yp-chart">
                    <?php foreach ($months as $i => $label): ?>
                        <?php
                        $mIn = (int) ($masuk[$i] ?? 0);
                        $mOut = (int) ($keluar[$i] ?? 0);
                        $hIn = $maxKeu > 0 ? round(100 * $mIn / $maxKeu) : 0;
                        $hOut = $maxKeu > 0 ? round(100 * $mOut / $maxKeu) : 0;
                        ?>
                        <div class="yp-chart-col">
                            <div class="yp-chart-bars">
                                <div class="yp-bar yp-bar--in" style="height:<?= $hIn ?>%" title="Masuk <?= $fmt($mIn) ?>"></div>
                                <div class="yp-bar yp-bar--out" style="height:<?= $hOut ?>%" title="Keluar <?= $fmt($mOut) ?>"></div>
                            </div>
                            <div class="yp-chart-label"><?= htmlspecialchars((string) $label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-3 justify-content-center small mt-2">
                    <span><span class="yp-dot yp-dot--in"></span> Pemasukan</span>
                    <span><span class="yp-dot yp-dot--out"></span> Pengeluaran</span>
                </div>
                <div class="text-center mt-3">
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/arus-kas.php')) ?>">Detail arus kas</a>
                </div>
            </div>
        </div>
    </section>

    <section class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0"><i class="fa-solid fa-gavel me-2 text-warning"></i>Ketertiban Hari Ini</h2>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-danger" href="<?= htmlspecialchars(app_href('/yayasan/kesehatan.php')) ?>">
                    <i class="fa-solid fa-heart-pulse me-1"></i>Laporan kesehatan
                </a>
                <a class="btn btn-sm btn-warning" href="<?= htmlspecialchars(app_href('/yayasan/ketertiban.php')) ?>">Menu Ketertiban</a>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-danger"><?= (int) $ketertiban['izin_lewat'] ?></div>
                        <div class="fw-semibold">Izin Melewati Toleransi</div>
                        <p class="small text-muted mb-0">Belum kembali setelah batas izin + grace.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= (int) $ketertiban['sakit'] ?></div>
                        <div class="fw-semibold">Sakit Perlu Penanganan</div>
                        <p class="small text-muted mb-0">Izin sakit aktif atau presensi sakit hari ini.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-dark">
                    <div class="card-body">
                        <div class="fs-2 fw-bold"><?= (int) $ketertiban['alpa_beruntun'] ?></div>
                        <div class="fw-semibold">Alpa Kebangetan</div>
                        <p class="small text-muted mb-0">Bolos berturut-turut tanpa keterangan.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php if ((int) $ketertiban['total'] > 0): ?>
        <div class="alert alert-warning mt-3 mb-0 small">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            <?= (int) $ketertiban['total'] ?> santri membutuhkan tindakan disiplin hari ini.
            <a href="<?= htmlspecialchars(app_href('/yayasan/ketertiban.php')) ?>" class="alert-link">Buka detail</a>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
