<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_offline_pack.php';

require_login();
require_roles(['admin', 'pengurus']);

$pageTitle = 'Data Offline Keuangan';
$bodyClass = keuangan_body_class('keuangan-offline-data-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3" id="keuangan-offline-data-page">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a> · Offline</p>
    <h1 class="h4 mb-1">Data Offline Keuangan</h1>
    <p class="text-muted mb-0">
        Unduh database keuangan ke perangkat ini (baca-saja). Laporan bisa dibuka tanpa internet;
        input pembayaran/pengeluaran tetap membutuhkan koneksi.
    </p>
</div>

<div class="alert alert-info small mb-3">
    <i class="fa-solid fa-circle-info me-1"></i>
    Data disimpan di browser (IndexedDB). Gunakan perangkat yang sama setelah unduh.
    Default: <strong><?= (int) keuangan_offline_pack_years_default() ?> tahun terakhir</strong>.
    PIN cashless tidak diunduh.
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Status di perangkat</div>
    <div class="card-body" id="keu-offline-status">
        <p class="text-muted small mb-0">Memuat…</p>
    </div>
</div>

<div class="card shadow-sm mb-3" id="keu-offline-progress" hidden>
    <div class="card-body">
        <div class="progress mb-2" role="progressbar" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="keu-offline-progress-bar" style="width:0%">0%</div>
        </div>
        <p class="small text-muted mb-0" id="keu-offline-progress-msg"></p>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <button type="button" class="btn btn-primary" id="keu-offline-download">
        <i class="fa-solid fa-download me-1"></i> Unduh / perbarui (<?= (int) keuangan_offline_pack_years_default() ?> thn)
    </button>
    <button type="button" class="btn btn-outline-secondary" id="keu-offline-download-all">
        Unduh semua waktu
    </button>
    <button type="button" class="btn btn-outline-secondary" id="keu-offline-refresh-meta">
        Segarkan status
    </button>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Laporan yang bisa dibaca offline</div>
    <div class="card-body">
        <ul class="mb-0">
            <li><a href="<?= htmlspecialchars(app_href('/keuangan/neraca.php')) ?>">Neraca</a></li>
            <li><a href="<?= htmlspecialchars(app_href('/keuangan/arus-kas.php')) ?>">Arus kas</a></li>
            <li><a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php')) ?>">Riwayat masuk &amp; keluar</a></li>
            <li><a href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php')) ?>">Laporan cashless</a></li>
        </ul>
        <p class="small text-muted mt-2 mb-0">
            Saat offline, halaman menampilkan snapshot dari unduhan terakhir.
            Saat online, data live dari server + unduhan diperbarui di latar belakang.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
