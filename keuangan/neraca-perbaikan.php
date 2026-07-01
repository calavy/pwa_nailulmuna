<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_neraca_perbaikan.php';
require_once __DIR__ . '/../helpers/keuangan_jurnal.php';
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
keuangan_ensure_schema_deferred($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$asOfInput = trim((string) ($_GET['per'] ?? $_POST['per'] ?? date('Y-m-d')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backfill_jurnal') {
    $asOfPost = trim((string) ($_POST['per'] ?? date('Y-m-d')));
    $hasil = keuangan_jurnal_backfill_operasional($pdo, $asOfPost, $userId);
    keuangan_neraca_invalidate_cache();
    keuangan_dashboard_cache_invalidate();
    set_flash($hasil['ok'] ? 'success' : 'warning', $hasil['message']);
    if ($hasil['gagal'] !== []) {
        set_flash('error', 'Beberapa jurnal gagal: ' . implode('; ', array_slice($hasil['gagal'], 0, 3)));
    }
    header('Location: ' . app_href('/keuangan/neraca-perbaikan.php?per=' . urlencode($asOfPost)));
    exit;
}

$neraca = keuangan_build_neraca($pdo, $asOfInput);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$kesehatan = keuangan_neraca_kesehatan($pdo, $neraca);
$selisihNeraca = (int) ($neraca['selisih'] ?? 0);
$seimbang = abs($selisihNeraca) < 1;
$penyesuaianAbs = (int) ($kesehatan['penyesuaian_abs'] ?? 0);
$analisis = keuangan_neraca_analisis_selisih($pdo, $neraca);
$saran = keuangan_neraca_saran_perbaikan($pdo, $neraca, $analisis);
$adaBackfill = (int) ($kesehatan['jumlah_tanpa_jurnal'] ?? 0) > 0;
$perluPerhatian = !$seimbang || $penyesuaianAbs > 0 || $adaBackfill || abs((int) ($kesehatan['selisih_saku_cashless'] ?? 0)) >= 1000;

$pageTitle = 'Saran Perbaikan Neraca';
$bodyClass = keuangan_body_class('neraca-perbaikan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="/keuangan/index.php">Keuangan</a> ·
        <a href="/keuangan/neraca.php?per=<?= urlencode((string) $neraca['as_of']) ?>">Neraca</a> · Perbaikan
    </p>
    <h1 class="h4 mb-1">Saran Perbaikan Neraca</h1>
    <p class="text-muted mb-0">
        Panduan menyeimbangkan neraca <?= htmlspecialchars((string) $neraca['nama_lembaga']) ?>
        per <?= htmlspecialchars((string) $neraca['as_of_label']) ?>.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Per tanggal</label>
                <input type="date" name="per" class="form-control" value="<?= htmlspecialchars((string) $neraca['as_of']) ?>">
            </div>
            <div class="col-md-8 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Analisis ulang</button>
                <a class="btn btn-outline-secondary" href="/keuangan/neraca.php?per=<?= urlencode((string) $neraca['as_of']) ?>">Lihat neraca</a>
                <a class="btn btn-outline-primary" href="/keuangan/rekap-kas-bulan.php">Rekap kas bulanan</a>
                <a class="btn btn-outline-primary" href="/keuangan/arus-kas.php">Arus kas</a>
            </div>
        </form>
    </div>
</div>

<?php if ($seimbang && !$perluPerhatian): ?>
<div class="alert alert-success mb-3">
    <i class="fa-solid fa-scale-balanced me-1"></i>
    Neraca <strong>seimbang</strong> tanpa penyesuaian penyeimbang. Data operasional dan buku besar selaras.
</div>
<?php elseif ($seimbang && $penyesuaianAbs > 0): ?>
<div class="alert alert-warning mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            Neraca tampak seimbang, tetapi ada penyesuaian penyeimbang
            <strong><?= htmlspecialchars($fmt($penyesuaianAbs)) ?></strong>.
            Lihat saran di bawah untuk menelusuri penyebab.
        </div>
        <?php if ($adaBackfill): ?>
        <form method="post" class="mb-0" onsubmit="return confirm('Buat jurnal otomatis untuk transaksi yang belum punya jurnal?');">
            <input type="hidden" name="action" value="backfill_jurnal">
            <input type="hidden" name="per" value="<?= htmlspecialchars((string) $neraca['as_of']) ?>">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="fa-solid fa-rotate me-1"></i> Sinkronkan jurnal
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($seimbang): ?>
<div class="alert alert-info mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <i class="fa-solid fa-scale-balanced me-1"></i>
            Neraca seimbang. Beberapa indikator di bawah masih perlu dicek.
        </div>
        <?php if ($adaBackfill): ?>
        <form method="post" class="mb-0" onsubmit="return confirm('Buat jurnal otomatis untuk transaksi yang belum punya jurnal?');">
            <input type="hidden" name="action" value="backfill_jurnal">
            <input type="hidden" name="per" value="<?= htmlspecialchars((string) $neraca['as_of']) ?>">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="fa-solid fa-rotate me-1"></i> Sinkronkan jurnal
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <i class="fa-solid fa-scale-unbalanced me-1"></i>
            Selisih neraca: <strong><?= htmlspecialchars($fmt(abs($selisihNeraca))) ?></strong>
            (<?= $selisihNeraca > 0 ? 'aktiva lebih besar' : 'pasiva lebih besar' ?>).
        </div>
        <?php if ($adaBackfill): ?>
        <form method="post" class="mb-0" onsubmit="return confirm('Buat jurnal otomatis untuk transaksi yang belum punya jurnal?');">
            <input type="hidden" name="action" value="backfill_jurnal">
            <input type="hidden" name="per" value="<?= htmlspecialchars((string) $neraca['as_of']) ?>">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="fa-solid fa-rotate me-1"></i> Sinkronkan jurnal
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($perluPerhatian && $seimbang): ?>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Penyesuaian neraca</div>
            <div class="app-mini-stat-value <?= $penyesuaianAbs >= keuangan_neraca_penyesuaian_threshold() ? 'text-danger' : 'text-success' ?>">
                <?= htmlspecialchars($fmt((int) ($kesehatan['penyesuaian_neraca'] ?? 0))) ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Tanpa jurnal</div>
            <div class="app-mini-stat-value <?= $adaBackfill ? 'text-warning' : 'text-success' ?>"><?= (int) ($kesehatan['jumlah_tanpa_jurnal'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Selisih saku/cashless</div>
            <div class="app-mini-stat-value <?= abs((int) ($kesehatan['selisih_saku_cashless'] ?? 0)) >= 1000 ? 'text-warning' : 'text-success' ?>">
                <?= htmlspecialchars($fmt((int) ($kesehatan['selisih_saku_cashless'] ?? 0))) ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Saran prioritas tinggi</div>
            <div class="app-mini-stat-value text-danger"><?= count(array_filter($saran, static fn (array $s): bool => ($s['prioritas'] ?? '') === 'tinggi')) ?></div>
        </div>
    </div>
</div>
<?php elseif (!$seimbang): ?>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total aktiva</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $analisis['total_aset'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total pasiva</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $analisis['total_pasiva'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Tanpa jurnal</div>
            <div class="app-mini-stat-value text-warning"><?= (int) ($analisis['jumlah_tanpa_jurnal'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Saran prioritas tinggi</div>
            <div class="app-mini-stat-value text-danger"><?= count(array_filter($saran, static fn (array $s): bool => ($s['prioritas'] ?? '') === 'tinggi')) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<h2 class="h6 fw-semibold mb-3">Daftar saran perbaikan</h2>
<?php keuangan_neraca_perbaikan_render_saran($saran, $fmt); ?>

<?php if ($adaBackfill && ($analisis['transaksi_tanpa_jurnal'] ?? []) !== []): ?>
<div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold">Transaksi tanpa jurnal (<?= (int) $analisis['jumlah_tanpa_jurnal'] ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Keterangan</th>
                        <th class="text-end">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($analisis['transaksi_tanpa_jurnal'], 0, 25) as $tx): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($tx['tanggal'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($tx['tipe'] ?? '')) ?> #<?= (int) ($tx['id'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($tx['keterangan'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($tx['nominal'] ?? 0))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<style><?= keuangan_neraca_perbaikan_css() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
