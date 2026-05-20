<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_aruskas.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

$dariInput = trim((string) ($_GET['dari'] ?? ''));
$sampaiInput = trim((string) ($_GET['sampai'] ?? ''));
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

$lak = keuangan_build_arus_kas($pdo, $dariInput !== '' ? $dariInput : null, $sampaiInput !== '' ? $sampaiInput : null);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Arus Kas — ' . htmlspecialchars($lak['nama_lembaga']) . '</title>';
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

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Laporan</p>
    <h1 class="h4 mb-1">Laporan Arus Kas</h1>
    <p class="text-muted mb-0">Arus kas operasi, investasi, dan pendanaan <?= htmlspecialchars((string) $lak['nama_lembaga']) ?> — PAP / ISAK 35.</p>
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
                <a class="btn btn-outline-primary" href="/keuangan/neraca.php">Neraca</a>
                <a class="btn btn-outline-primary" href="/keuangan/index.php">Dashboard keuangan</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas operasi</div>
            <div class="app-mini-stat-value <?= (int) $lak['operasi']['total'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($fmt((int) $lak['operasi']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas investasi</div>
            <div class="app-mini-stat-value text-danger"><?= htmlspecialchars($fmt((int) $lak['investasi']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kas pendanaan</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $lak['pendanaan']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kenaikan kas bersih</div>
            <div class="app-mini-stat-value <?= (int) $lak['kenaikan_kas'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($fmt((int) $lak['kenaikan_kas'])) ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm aruskas-report-card">
    <div class="card-body aruskas-report-body">
        <?php keuangan_aruskas_render_html($lak, $fmt); ?>
    </div>
</div>

<style><?= keuangan_aruskas_css() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
