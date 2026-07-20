<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

$asOf = trim((string) ($_GET['per'] ?? date('Y-m-d')));
$status = keuangan_build_status_titipan_saku($pdo, $asOf);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$selisih = (int) ($status['selisih_saku_cashless'] ?? 0);
$auditSantri = (int) ($status['saku_audit_santri'] ?? 0);

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

<div class="d-flex flex-wrap gap-2 mb-0">
    <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-saku.php')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-wrench me-1"></i> Audit saku
    </a>
    <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-wallet me-1"></i> Keuangan pondok
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
