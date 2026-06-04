<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_portal.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$kas = yayasan_kas_status($pdo);
$tagihan = $kas['tagihan_bulan'] ?? [];
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Dashboard Operasional Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="yp-hero yp-hero--operasional mb-4">
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a></p>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
            <div>
                <h1 class="h3 mb-1">Dashboard Utama</h1>
                <p class="text-muted mb-0">Fokus keuangan & operasional cepat — <?= htmlspecialchars(date('l, d F Y')) ?></p>
            </div>
            <span class="yp-kas-badge yp-kas-badge--<?= htmlspecialchars((string) $kas['badge']) ?>">
                <i class="fa-solid fa-shield-halved me-1"></i><?= htmlspecialchars((string) $kas['label']) ?>
            </span>
        </div>
    </header>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 yp-card-kas yp-card-kas--<?= htmlspecialchars((string) $kas['level']) ?>">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold opacity-75 mb-1">Status Keuangan</div>
                    <div class="display-6 fw-bold mb-2"><?= htmlspecialchars((string) $kas['label']) ?></div>
                    <p class="small mb-3"><?= htmlspecialchars((string) $kas['ringkasan']) ?></p>
                    <div class="row g-2 small">
                        <div class="col-6">
                            <div class="text-muted">Saldo kas</div>
                            <div class="fw-semibold"><?= $fmt((int) $kas['saldo_kas']) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Net bulan ini</div>
                            <div class="fw-semibold <?= (int) $kas['net_bulan_ini'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $fmt((int) $kas['net_bulan_ini']) ?>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Tertagih</div>
                            <div class="fw-semibold"><?= number_format((float) $kas['persen_tertagih'], 1, ',', '.') ?>%</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Neraca</div>
                            <div class="fw-semibold"><?= $kas['neraca_seimbang'] ? 'Seimbang' : 'Selisih' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="small text-uppercase fw-bold text-muted">Tagihan Aktif</div>
                            <div class="fs-2 fw-bold text-danger"><?= $fmt((int) ($tagihan['total_piutang'] ?? 0)) ?></div>
                        </div>
                        <i class="fa-solid fa-file-invoice-dollar fs-3 text-danger opacity-50"></i>
                    </div>
                    <p class="small text-muted mb-2">
                        <?= (int) ($tagihan['jumlah_penunggak'] ?? 0) ?> santri penunggak
                        · <?= htmlspecialchars((string) ($tagihan['bulan_label'] ?? '')) ?>
                        <?= !empty($tagihan['ta_label']) ? 'TA ' . htmlspecialchars((string) $tagihan['ta_label']) : '' ?>
                    </p>
                    <div class="progress mb-2" style="height:8px">
                        <div class="progress-bar bg-success" style="width:<?= min(100, (float) ($tagihan['persen_tertagih'] ?? 0)) ?>%"></div>
                    </div>
                    <a class="btn btn-outline-danger btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">
                        <i class="fa-solid fa-list me-1"></i>Lihat tagihan
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-primary text-white">
                <div class="card-body d-flex flex-column">
                    <div class="small text-uppercase fw-bold opacity-75 mb-1">Pembayaran Cepat</div>
                    <p class="small opacity-90 mb-3">Catat transaksi pembayaran santri langsung di lokasi tanpa menu panjang.</p>
                    <div class="mt-auto d-grid gap-2">
                        <a class="btn btn-light fw-semibold" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">
                            <i class="fa-solid fa-bolt me-1"></i>Input Pembayaran
                        </a>
                        <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">
                            <i class="fa-solid fa-qrcode me-1"></i>Scan Presensi
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/pengawasan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-chart-line text-primary mb-2"></i>
                    <div class="fw-semibold text-dark">Dashboard Pengawasan</div>
                    <div class="small text-muted">Grafik arus kas & ringkasan ketertiban</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/ringkasan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-list-check text-success mb-2"></i>
                    <div class="fw-semibold text-dark">To-Do &amp; Agenda</div>
                    <div class="small text-muted">Tugas mendesak & kegiatan terdekat</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-signal text-info mb-2"></i>
                    <div class="fw-semibold text-dark">Keaktifan Hari Ini</div>
                    <div class="small text-muted">Rekap scan real-time & drill-down</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/ketertiban.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-shield-halved text-danger mb-2"></i>
                    <div class="fw-semibold text-dark">Ketertiban</div>
                    <div class="small text-muted">Pelanggaran & tindak lanjut</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/timeline.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-route text-warning mb-2"></i>
                    <div class="fw-semibold text-dark">Timeline &amp; Tugas</div>
                    <div class="small text-muted">Hasil rapat, progres, kalender</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/pengurus.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-users text-secondary mb-2"></i>
                    <div class="fw-semibold text-dark">Pengurus</div>
                    <div class="small text-muted">Struktur pengurus yayasan</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-handshake text-primary mb-2"></i>
                    <div class="fw-semibold text-dark">Rapat</div>
                    <div class="small text-muted">Jadwal & agenda rapat</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/notulen.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-file-lines text-muted mb-2"></i>
                    <div class="fw-semibold text-dark">Notulen</div>
                    <div class="small text-muted">Arsip notulen rapat</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/executive.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-chart-pie text-success mb-2"></i>
                    <div class="fw-semibold text-dark">Executive Summary</div>
                    <div class="small text-muted">Ringkasan eksekutif yayasan</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-user-check text-info mb-2"></i>
                    <div class="fw-semibold text-dark">Keaktifan SDM Hari Ini</div>
                    <div class="small text-muted">Pembimbing & munawib hadir</div>
                </div>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
