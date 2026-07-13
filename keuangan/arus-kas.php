<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_aruskas.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_diagnostik.php';
require_once __DIR__ . '/../helpers/keuangan_riwayat_pembayaran.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
keuangan_ensure_schema_deferred($pdo);

$dariInput = trim((string) ($_GET['dari'] ?? ''));
$sampaiInput = trim((string) ($_GET['sampai'] ?? ''));
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

$lak = keuangan_build_arus_kas($pdo, $dariInput !== '' ? $dariInput : null, $sampaiInput !== '' ? $sampaiInput : null);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Arus Kas — ' . htmlspecialchars($lak['nama_lembaga']) . '</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . keuangan_aruskas_css() . '</style></head><body class="' . htmlspecialchars(keuangan_body_class('aruskas-page')) . '">';
    echo '<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak / PDF</button> <a href="/keuangan/arus-kas.php?dari=' . urlencode($lak['date_from']) . '&amp;sampai=' . urlencode($lak['date_to']) . '">Kembali</a></div>';
    keuangan_aruskas_render_html($lak, $fmt);
    echo '</body></html>';
    exit;
}

$pageTitle = 'Laporan Arus Kas';
$bodyClass = keuangan_body_class('aruskas-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div id="keuangan-offline-reader" data-kind="arus_kas" hidden>
    <div class="page-intro mb-3">
        <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Offline</p>
        <h1 class="h4 mb-1">Arus Kas (offline)</h1>
    </div>
    <div data-offline-body></div>
    <p class="small mt-3"><a href="<?= htmlspecialchars(app_href('/keuangan/offline-data.php')) ?>">Kelola data offline</a></p>
</div>

<div id="keuangan-online-content">
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Laporan</p>
    <h1 class="h4 mb-1">Laporan Arus Kas</h1>
    <p class="text-muted mb-0">Kas masuk &amp; keluar per pos pembayaran/pengeluaran <?= htmlspecialchars((string) $lak['nama_lembaga']) ?> — PAP / ISAK 35.
        <a href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href((string) $lak['date_from'], (string) $lak['date_to'])) ?>">Detail transaksi per POS</a>
        · <a href="<?= htmlspecialchars(app_href('/keuangan/offline-data.php')) ?>">Data offline</a>
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Dari tanggal</label>
                <input type="date" name="dari" class="form-control" value="<?= htmlspecialchars((string) $lak['date_from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Sampai tanggal</label>
                <input type="date" name="sampai" class="form-control" value="<?= htmlspecialchars((string) $lak['date_to']) ?>">
            </div>
            <div class="col-md-6 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Tampilkan</button>
                <a class="btn btn-outline-secondary" href="/keuangan/arus-kas.php?dari=<?= urlencode((string) $lak['date_from']) ?>&amp;sampai=<?= urlencode((string) $lak['date_to']) ?>&amp;print=1" target="_blank">Cetak / PDF</a>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href((string) $lak['date_from'], (string) $lak['date_to'])) ?>">Rekap masuk &amp; keluar</a>
                <a class="btn btn-outline-primary" href="/keuangan/rekap-kas-bulan.php">Rekap kas bulanan</a>
                <a class="btn btn-outline-primary" href="/keuangan/neraca.php">Neraca</a>
                <a class="btn btn-outline-primary" href="/keuangan/index.php">Dashboard keuangan</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas masuk (per pos)</div>
            <div class="app-mini-stat-value text-success"><?= htmlspecialchars($fmt((int) ($lak['operasi']['total_masuk'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas keluar (per pos)</div>
            <div class="app-mini-stat-value text-danger"><?= htmlspecialchars($fmt((int) ($lak['operasi']['total_keluar'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas bersih operasi</div>
            <div class="app-mini-stat-value <?= (int) $lak['operasi']['total'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($fmt((int) $lak['operasi']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas akhir periode (uang nyata)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $lak['kas_akhir'])) ?></div>
            <?php if ((int) ($lak['selisih_rekonsiliasi'] ?? 0) !== 0): ?>
                <div class="small text-warning">Hitung arus <?= htmlspecialchars($fmt((int) ($lak['kas_akhir_hitung'] ?? 0))) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $kasBankArus = keuangan_dashboard_kas_bank_detail($pdo); ?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="app-mini-stat border-start border-4 border-success">
            <div class="app-mini-stat-label">Kas fisik &amp; e-wallet (terkini)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($kasBankArus['total_kas'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat border-start border-4 border-primary">
            <div class="app-mini-stat-label">Rekening bank (terkini)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($kasBankArus['total_bank'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat border-start border-4 border-secondary">
            <div class="app-mini-stat-label">Total likuid (terkini)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($kasBankArus['total_likuid'] ?? 0))) ?></div>
            <div class="small text-muted">Per <?= htmlspecialchars((string) ($kasBankArus['as_of'] ?? '')) ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm aruskas-report-card">
    <div class="card-body aruskas-report-body">
        <?php keuangan_aruskas_render_html($lak, $fmt); ?>
    </div>
</div>

<style><?= keuangan_aruskas_css() ?></style>
</div><!-- #keuangan-online-content -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
