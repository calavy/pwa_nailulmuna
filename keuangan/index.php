<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_aruskas.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';

require_login();
require_roles(['admin', 'pengurus']);

/** Redirect tab lama ke halaman yang masih ada (tanpa rebuild modul besar). */
$tabRedirects = [
    'a' => '/pwa_nailulmuna/keuangan/pengaturan.php',
    'b' => '/pwa_nailulmuna/keuangan/pembayaran.php',
    'c' => '/pwa_nailulmuna/pembayaran/laporan.php',
    'd' => '/pwa_nailulmuna/keuangan/pengaturan.php?bagian=tarif',
    'e' => '/pwa_nailulmuna/keuangan/pengeluaran.php',
    'f' => '/pwa_nailulmuna/pembayaran/riwayat.php',
    'g' => '/pwa_nailulmuna/keuangan/cashless_scan.php',
    'h' => '/pwa_nailulmuna/admin/cek_update.php',
    'j' => '/pwa_nailulmuna/keuangan/inventaris.php',
    'k' => '/pwa_nailulmuna/rekap/pembimbing.php',
];

$tab = trim((string) ($_GET['tab'] ?? 'dashboard'));
if ($tab !== 'dashboard' && $tab !== 'i' && isset($tabRedirects[$tab])) {
    $target = $tabRedirects[$tab];
    $extra = $_GET;
    unset($extra['tab']);
    if ($extra !== []) {
        $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($extra);
    }
    header('Location: ' . $target);
    exit;
}

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$needsImport = !table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran');

$neracaRingkas = null;
$lakRingkas = null;
$dashSnap = null;
if (!$needsImport) {
    $dashSnap = keuangan_dashboard_snapshot($pdo);
    $neracaRingkas = keuangan_build_neraca($pdo, date('Y-m-d'));
    $lakRingkas = keuangan_build_arus_kas($pdo, date('Y-01-01'), date('Y-m-d'));
}

$pageTitle = 'Keuangan';
$bodyClass = keuangan_body_class('keuangan-hub-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Keuangan</p>
    <h1 class="h4 mb-1">Dashboard Keuangan</h1>
    <p class="text-muted mb-0">Kondisi keuangan terkini, piutang tagihan bulan berjalan, dan tindakan prioritas.</p>
</div>

<?php if ($needsImport): ?>
<div class="alert alert-info border-info mb-4">
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-database me-1"></i> Pertama kali / database kosong?</h2>
    <p class="mb-2 small">Import <strong>sekali saja</strong> lewat phpMyAdmin (tidak perlu upload file PHP):</p>
    <ol class="small mb-2 ps-3">
        <li>Buka <a href="http://localhost/phpmyadmin" target="_blank" rel="noopener">phpMyAdmin</a></li>
        <li>Pilih database <code>pwa_nailulmuna</code> (atau buat baru)</li>
        <li>Tab <strong>Impor</strong> â†’ pilih file <code>impor_lengkap_pwa_nailulmuna.sql</code> di folder proyek</li>
        <li>Klik <strong>Kirim / Go</strong>, lalu refresh halaman ini</li>
    </ol>
    <p class="small text-muted mb-0">File ada di: <code>c:\xampp\htdocs\pwa_nailulmuna\impor_lengkap_pwa_nailulmuna.sql</code></p>
</div>
<?php endif; ?>

<?php if ($dashSnap !== null):
    $ner = $dashSnap['neraca'];
    $tag = $dashSnap['tagihan_bulan'];
    $wa = $dashSnap['wa_tagihan'];
    $seimbang = !empty($ner['seimbang']);
    $tagihanUrl = '/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php?bulan=' . (int) $tag['bulan']
        . '&tm=' . (int) $tag['tm'] . '&ts=' . (int) $tag['ts'];
?>
<section class="keu-dash-snapshot mb-4" aria-label="Kondisi keuangan terkini">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Kondisi terkini</h2>
            <p class="small text-muted mb-0">
                Tagihan wajib <strong><?= htmlspecialchars((string) $tag['bulan_label']) ?></strong>
                TA <?= htmlspecialchars((string) $tag['ta_label']) ?>
                Â· Neraca per <?= htmlspecialchars((string) $ner['as_of_label']) ?>
            </p>
        </div>
        <a href="<?= htmlspecialchars($tagihanUrl) ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-receipt me-1"></i> Detail tagihan
        </a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dash-kpi dash-kpi--neraca h-100 border-0 shadow-sm <?= $seimbang ? 'dash-kpi--ok' : 'dash-kpi--warn' ?>">
                <div class="card-body dash-kpi-inner">
                    <span class="dash-kpi-ico"><i class="fa-solid <?= $seimbang ? 'fa-scale-balanced' : 'fa-scale-unbalanced' ?>"></i></span>
                    <div>
                        <div class="dash-kpi-label">Neraca</div>
                        <div class="dash-kpi-value fs-6"><?= $seimbang ? 'Seimbang' : 'Belum seimbang' ?></div>
                        <?php if (!$seimbang): ?>
                            <div class="small text-danger">Selisih <?= htmlspecialchars($formatRupiah(abs((int) $ner['selisih']))) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-kpi dash-kpi--piutang h-100 border-0 shadow-sm">
                <div class="card-body dash-kpi-inner">
                    <span class="dash-kpi-ico"><i class="fa-solid fa-hand-holding-dollar"></i></span>
                    <div>
                        <div class="dash-kpi-label">Harus diterima</div>
                        <div class="dash-kpi-value" style="font-size:1.15rem"><?= htmlspecialchars($formatRupiah((int) $tag['total_piutang'])) ?></div>
                        <div class="small text-muted">dari <?= htmlspecialchars($formatRupiah((int) $tag['total_tagihan'])) ?> tagihan</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-kpi dash-kpi--penunggak h-100 border-0 shadow-sm">
                <div class="card-body dash-kpi-inner">
                    <span class="dash-kpi-ico"><i class="fa-solid fa-user-clock"></i></span>
                    <div>
                        <div class="dash-kpi-label">Penunggak</div>
                        <div class="dash-kpi-value"><?= (int) $tag['jumlah_penunggak'] ?></div>
                        <div class="small text-muted"><?= (int) $tag['jumlah_lunas'] ?> lunas · <?= (int) $tag['jumlah_sebagian'] ?> sebagian</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-kpi dash-kpi--tertagih h-100 border-0 shadow-sm">
                <div class="card-body dash-kpi-inner">
                    <span class="dash-kpi-ico"><i class="fa-solid fa-chart-line"></i></span>
                    <div class="w-100">
                        <div class="dash-kpi-label">Tertagih</div>
                        <div class="dash-kpi-value"><?= htmlspecialchars((string) $tag['persen_tertagih']) ?>%</div>
                        <div class="progress mt-2" style="height:6px" role="progressbar" aria-valuenow="<?= (int) $tag['persen_tertagih'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-success" style="width:<?= min(100, max(0, (float) $tag['persen_tertagih'])) ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm border-warning border-opacity-50 h-100">
                <div class="card-header bg-warning bg-opacity-10 fw-semibold">
                    <i class="fa-solid fa-list-check me-1"></i> Perlu segera dilakukan
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush keu-dash-tindakan">
                        <?php foreach ($dashSnap['tindakan'] as $t): ?>
                            <?php
                            $lvl = (string) ($t['level'] ?? 'secondary');
                            $badge = match ($lvl) {
                                'danger' => 'danger',
                                'warning' => 'warning',
                                'success' => 'success',
                                default => 'secondary',
                            };
                            ?>
                            <li class="list-group-item">
                                <a href="<?= htmlspecialchars((string) $t['href']) ?>" class="d-flex gap-3 align-items-start text-decoration-none text-body">
                                    <span class="badge text-bg-<?= $badge ?> mt-1"><i class="fa-solid <?= htmlspecialchars((string) $t['icon']) ?>"></i></span>
                                    <span>
                                        <span class="fw-semibold d-block"><?= htmlspecialchars((string) $t['judul']) ?></span>
                                        <span class="small text-muted"><?= htmlspecialchars((string) $t['deskripsi']) ?></span>
                                    </span>
                                    <i class="fa-solid fa-chevron-right ms-auto text-muted small mt-1"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100 border-success border-opacity-25">
                <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">
                    <i class="fa-brands fa-whatsapp me-1"></i> Tagihan WA ke wali
                </div>
                <div class="card-body">
                    <dl class="row small mb-0 g-2 keu-dash-wa-dl">
                        <dt class="col-5 text-muted">Status auto</dt>
                        <dd class="col-7 mb-0">
                            <?php if (!empty($wa['enabled'])): ?>
                                <span class="badge text-bg-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Nonaktif</span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5 text-muted">Jadwal kirim</dt>
                        <dd class="col-7 mb-0">Tgl <?= (int) $wa['due_day'] ?>
                            <?php if ((string) ($wa['send_time'] ?? '') !== ''): ?>
                                · jam <?= htmlspecialchars((string) $wa['send_time']) ?>
                            <?php endif; ?>
                            (<?= htmlspecialchars((string) $wa['calendar']) ?>)
                        </dd>
                        <dt class="col-5 text-muted">Periode ini</dt>
                        <dd class="col-7 mb-0">
                            <?= !empty($wa['period_sudah_kirim'])
                                ? '<span class="text-success">Sudah terkirim</span>'
                                : '<span class="text-warning">Belum terkirim</span>' ?>
                        </dd>
                        <?php if (!empty($wa['last_sent_at'])): ?>
                            <dt class="col-5 text-muted">Terakhir kirim</dt>
                            <dd class="col-7 mb-0"><?= htmlspecialchars((string) $wa['last_sent_at']) ?></dd>
                        <?php endif; ?>
                        <dt class="col-5 text-muted">Siap WA</dt>
                        <dd class="col-7 mb-0"><?= (int) $wa['penunggak_dengan_wa'] ?> wali
                            <?php if ((int) ($wa['penunggak_tanpa_wa'] ?? 0) > 0): ?>
                                <span class="text-danger">· <?= (int) $wa['penunggak_tanpa_wa'] ?> tanpa nomor</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="/pwa_nailulmuna/settings/index.php" class="btn btn-sm btn-outline-success">
                            <i class="fa-solid fa-gear me-1"></i> Pengaturan WA
                        </a>
                        <?php if (!empty($wa['enabled']) && !empty($wa['hari_ini_jadwal_kirim']) && empty($wa['period_sudah_kirim'])): ?>
                            <span class="small text-danger align-self-center">
                                <i class="fa-solid fa-circle-exclamation me-1"></i> Hari ini jadwal kirim — pastikan cron aktif
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($neracaRingkas !== null && $lakRingkas !== null): ?>
<div class="row g-3 mb-4" id="laporan">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-primary border-opacity-25">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="fa-solid fa-scale-balanced text-primary me-1"></i> Neraca</h2>
                <p class="small text-muted mb-2">Per <?= htmlspecialchars((string) $neracaRingkas['as_of_label']) ?></p>
                <p class="mb-3">Total aset: <strong class="fs-5"><?= htmlspecialchars($formatRupiah((int) $neracaRingkas['aset']['total'])) ?></strong></p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/pwa_nailulmuna/keuangan/neraca.php" class="btn btn-primary btn-sm">Buka neraca</a>
                    <a href="/pwa_nailulmuna/keuangan/neraca.php?print=1" target="_blank" class="btn btn-outline-secondary btn-sm">Cetak PDF</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-success border-opacity-25">
            <div class="card-body">
                <h2 class="h5 mb-2"><i class="fa-solid fa-money-bill-transfer text-success me-1"></i> Arus kas</h2>
                <p class="small text-muted mb-2"><?= htmlspecialchars((string) $lakRingkas['periode_label']) ?></p>
                <p class="mb-3">Kenaikan kas: <strong class="fs-5"><?= htmlspecialchars($formatRupiah((int) $lakRingkas['kenaikan_kas'])) ?></strong></p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/pwa_nailulmuna/keuangan/arus-kas.php" class="btn btn-success btn-sm">Buka arus kas</a>
                    <a href="/pwa_nailulmuna/keuangan/arus-kas.php?print=1" target="_blank" class="btn btn-outline-secondary btn-sm">Cetak PDF</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">Laporan</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-primary text-start" href="/pwa_nailulmuna/keuangan/neraca.php"><i class="fa-solid fa-scale-balanced me-2"></i>Neraca</a>
                <a class="btn btn-outline-primary text-start" href="/pwa_nailulmuna/keuangan/arus-kas.php"><i class="fa-solid fa-money-bill-transfer me-2"></i>Arus kas</a>
                <a class="btn btn-outline-primary text-start" href="/pwa_nailulmuna/pembayaran/rekap_pos.php"><i class="fa-solid fa-chart-pie me-2"></i>Rekap per POS</a>
                <a class="btn btn-outline-primary text-start" href="/pwa_nailulmuna/pembayaran/laporan.php"><i class="fa-solid fa-chart-column me-2"></i>Laporan syahriyah</a>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-success border-opacity-25">
            <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">Transaksi</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-success text-start" href="/pwa_nailulmuna/keuangan/pembayaran.php"><i class="fa-solid fa-user-graduate me-2"></i>Input pembayaran santri</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php"><i class="fa-solid fa-receipt me-2"></i>Tagihan bulanan</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/pembayaran/riwayat.php"><i class="fa-solid fa-clock-rotate-left me-2"></i>Riwayat pembayaran</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/keuangan/pemasukan.php"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Pemasukan lain</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/keuangan/pengeluaran.php"><i class="fa-solid fa-minus-circle me-2"></i>Input pengeluaran</a>
                <a class="btn btn-success text-start" href="/pwa_nailulmuna/keuangan/talangan.php"><i class="fa-solid fa-arrows-left-right me-2"></i>Dana talangan antar-POS</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/keuangan/cashless_scan.php"><i class="fa-solid fa-qrcode me-2"></i>Top up cashless</a>
                <a class="btn btn-outline-success text-start" href="/pwa_nailulmuna/rekap/pembimbing.php"><i class="fa-solid fa-chalkboard-user me-2"></i>Gaji pembimbing</a>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100 border-secondary border-opacity-25">
            <div class="card-header bg-secondary bg-opacity-10 fw-semibold text-secondary">Pengaturan</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-secondary text-start" href="/pwa_nailulmuna/keuangan/pengaturan.php"><i class="fa-solid fa-sliders me-2"></i>Pengaturan keuangan &amp; tarif</a>
                <a class="btn btn-outline-secondary text-start" href="/pwa_nailulmuna/keuangan/potongan_syahriyah.php"><i class="fa-solid fa-percent me-2"></i>Potongan syahriyah per santri</a>
                <a class="btn btn-outline-secondary text-start" href="/pwa_nailulmuna/settings/kelas_keuangan.php"><i class="fa-solid fa-layer-group me-2"></i>Kelas keuangan</a>
                <a class="btn btn-outline-secondary text-start" href="/pwa_nailulmuna/keuangan/inventaris.php"><i class="fa-solid fa-warehouse me-2"></i>Inventaris aset</a>
                <a class="btn btn-outline-secondary text-start" href="/pwa_nailulmuna/keuangan/cashless_pin.php"><i class="fa-solid fa-key me-2"></i>Cashless &amp; uang saku</a>
            </div>
        </div>
    </div>
</div>

<style>
.keuangan-hub-page .card-header { font-size: 0.95rem; }
.keuangan-hub-page .btn.text-start { padding: 0.65rem 0.85rem; }
.keu-dash-snapshot .dash-kpi--neraca { border-left: 4px solid #6366f1; }
.keu-dash-snapshot .dash-kpi--neraca.dash-kpi--ok { border-left-color: #0f766e; }
.keu-dash-snapshot .dash-kpi--neraca.dash-kpi--warn { border-left-color: #dc2626; }
.keu-dash-snapshot .dash-kpi--piutang { border-left: 4px solid #d97706; }
.keu-dash-snapshot .dash-kpi--penunggak { border-left: 4px solid #b45309; }
.keu-dash-snapshot .dash-kpi--tertagih { border-left: 4px solid #059669; }
.keu-dash-snapshot .dash-kpi--piutang .dash-kpi-ico { color: #b45309; background: rgba(217,119,6,.12); }
.keu-dash-snapshot .dash-kpi--penunggak .dash-kpi-ico { color: #c2410c; background: rgba(234,88,12,.12); }
.keu-dash-snapshot .dash-kpi--tertagih .dash-kpi-ico { color: #059669; background: rgba(5,150,105,.12); }
.keu-dash-tindakan a:hover { background: rgba(15,118,110,.06); }
.keu-dash-wa-dl dt { font-weight: 500; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
