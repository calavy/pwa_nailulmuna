<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

$asOf = trim((string) ($_GET['per'] ?? date('Y-m-d')));
$status = keuangan_build_status_titipan_saku($pdo, $asOf);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$selisih = (int) ($status['selisih_saku_cashless'] ?? 0);
$auditSantri = (int) ($status['saku_audit_santri'] ?? 0);

$laporanWaStatus = cashless_wa_laporan_status_hari_ini($pdo);
$rekapKemarin = $laporanWaStatus['ringkasan'] ?? [];
$laporanTanggal = (string) ($laporanWaStatus['laporan_tanggal'] ?? cashless_wa_laporan_tanggal_data());
$waSudahDikirim = (($laporanWaStatus['last_laporan_tanggal'] ?? '') === $laporanTanggal);
$resetHint = cashless_operational_reset_hint($pdo);
$laporanKemarinUrl = app_href('/keuangan/cashless_laporan.php?' . http_build_query([
    'dari' => $laporanTanggal,
    'sampai' => $laporanTanggal,
]));

$pageTitle = 'Keuangan Cashless';
$bodyClass = keuangan_body_class('keuangan-saku-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Saku & Cashless</p>
    <h1 class="h4 mb-1">Dashboard Keuangan Cashless</h1>
    <p class="text-muted mb-0">Ringkasan titipan saku, saldo jajan santri, dan kas titipan — terpisah dari keuangan operasional pondok.</p>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label small mb-0" for="per">Per tanggal</label>
        <input type="date" class="form-control form-control-sm" id="per" name="per" value="<?= htmlspecialchars((string) ($status['as_of'] ?? date('Y-m-d'))) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Tampilkan</button>
    </div>
</form>

<?php if ($selisih !== 0 || $auditSantri > 0): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <?php if ($selisih !== 0): ?>
        Selisih pembayaran saku vs saldo cashless: <strong><?= htmlspecialchars($fmt(abs($selisih))) ?></strong>.
    <?php endif; ?>
    <?php if ($auditSantri > 0): ?>
        <?= $auditSantri ?> santri tidak selaras antara pembayaran saku dan top-up cashless.
    <?php endif; ?>
    <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-saku.php')) ?>" class="alert-link ms-1">Perbaikan saku →</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="small text-muted mb-1">Saldo cashless santri (2101)</div>
                <div class="h4 mb-0 text-success"><?= htmlspecialchars($fmt((int) ($status['saldo_cashless_2101'] ?? 0))) ?></div>
                <div class="small text-muted"><?= (int) ($status['jumlah_santri_aktif'] ?? 0) ?> santri punya saldo</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="small text-muted mb-1">Kas titipan fisik (1103)</div>
                <div class="h4 mb-0 text-primary"><?= htmlspecialchars($fmt((int) ($status['kas_titipan_1103'] ?? 0))) ?></div>
                <div class="small text-muted">Uang saku di bendahara</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="small text-muted mb-1">Menunggu setor koperasi (2103)</div>
                <div class="h4 mb-0 text-warning"><?= htmlspecialchars($fmt((int) ($status['pending_setor_2103'] ?? 0))) ?></div>
                <div class="small text-muted">Belum disetor ke koperasi</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted mb-1">Per <?= htmlspecialchars((string) ($status['as_of_label'] ?? '')) ?></div>
                <a href="<?= htmlspecialchars(app_href('/keuangan/neraca.php?view=saku&per=' . urlencode((string) ($status['as_of'] ?? date('Y-m-d'))))) ?>" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fa-solid fa-scale-balanced me-1"></i> Status titipan saku
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <div>
            <span class="fw-semibold"><i class="fa-brands fa-whatsapp text-success me-1"></i> Rekap transaksi kemarin</span>
            <span class="small text-muted ms-1">(sama dengan laporan WA harian)</span>
        </div>
        <span class="badge <?= $waSudahDikirim ? 'bg-success' : 'bg-warning text-dark' ?>">
            <?= $waSudahDikirim ? 'WA sudah dikirim' : 'WA belum dikirim' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($resetHint !== ''): ?>
            <p class="small text-muted mb-2"><i class="fa-solid fa-clock me-1"></i><?= htmlspecialchars($resetHint) ?></p>
        <?php endif; ?>
        <p class="small mb-2">
            Tanggal operasional: <strong><?= htmlspecialchars((string) ($rekapKemarin['tanggal_label'] ?? '')) ?></strong>
            · <?= (int) ($rekapKemarin['total_transaksi'] ?? 0) ?> transaksi
            · <strong><?= htmlspecialchars($fmt((int) ($rekapKemarin['total_nominal'] ?? 0))) ?></strong>
        </p>
        <?php if (($rekapKemarin['per_koperasi'] ?? []) !== []): ?>
            <ul class="small mb-3 ps-3">
                <?php foreach ($rekapKemarin['per_koperasi'] as $pk): ?>
                    <li><?= htmlspecialchars((string) ($pk['nama'] ?? '')) ?>: <?= (int) ($pk['jumlah'] ?? 0) ?> transaksi · <?= htmlspecialchars($fmt((int) ($pk['nominal'] ?? 0))) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ((int) ($rekapKemarin['total_transaksi'] ?? 0) === 0): ?>
            <p class="small text-muted mb-3">Tidak ada transaksi debit pada tanggal tersebut.</p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($laporanKemarinUrl) ?>" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-receipt me-1"></i> Lihat rincian di Laporan Koperasi
            </a>
            <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=cashless')) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-gear me-1"></i> Pengaturan WA cashless
            </a>
        </div>
        <?php if (($laporanWaStatus['last_sent_at'] ?? '') !== ''): ?>
            <p class="small text-muted mb-0 mt-2">Terakhir WA terkirim: <?= htmlspecialchars((string) $laporanWaStatus['last_sent_at']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-0">
    <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-saku.php')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-wrench me-1"></i> Audit saku
    </a>
    <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-wallet me-1"></i> Keuangan pondok
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
