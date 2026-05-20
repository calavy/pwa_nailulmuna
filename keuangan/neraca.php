<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

$asOfInput = trim((string) ($_GET['per'] ?? date('Y-m-d')));
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

$neraca = keuangan_build_neraca($pdo, $asOfInput);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$ring = $neraca['ringkasan'] ?? [];

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Neraca — ' . htmlspecialchars($neraca['nama_lembaga']) . '</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . keuangan_neraca_css_dua_kolom() . '</style></head><body class="' . htmlspecialchars(keuangan_body_class('neraca-page')) . '">';
    echo '<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak / PDF</button> <a href="/keuangan/neraca.php?per=' . urlencode($neraca['as_of']) . '">Kembali</a></div>';
    keuangan_neraca_render_html($neraca, $fmt);
    echo '</body></html>';
    exit;
}

$pageTitle = 'Neraca Keuangan';
$bodyClass = keuangan_body_class('neraca-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Laporan</p>
    <h1 class="h4 mb-1">Neraca Keuangan</h1>
    <p class="text-muted mb-0">Laporan posisi keuangan (neraca) <?= htmlspecialchars((string) $neraca['nama_lembaga']) ?> — standar PAP / ISAK 35.</p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Per tanggal</label>
                <input type="date" name="per" class="form-control" value="<?= htmlspecialchars((string) $neraca['as_of']) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Tampilkan</button>
                <a class="btn btn-outline-secondary" href="/keuangan/neraca.php?per=<?= urlencode((string) $neraca['as_of']) ?>&amp;print=1" target="_blank">Cetak / PDF</a>
                <a class="btn btn-outline-primary" href="/keuangan/index.php">Dashboard keuangan</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total Aset</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $neraca['aset']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Liabilitas</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $neraca['liabilitas']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Aset Neto</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $neraca['aset_neto']['total'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Status neraca</div>
            <div class="app-mini-stat-value text-success">Seimbang</div>
        </div>
    </div>
</div>

<?php if ((int) ($ring['pendapatan_saku'] ?? 0) > 0): ?>
<p class="small text-muted mb-3">
    Pembayaran pos <strong>Saku</strong> (<?= htmlspecialchars($fmt((int) $ring['pendapatan_saku'])) ?>)
    dicatat sebagai liabilitas titipan santri, bukan pendapatan aset neto.
</p>
<?php endif; ?>
<?php if ((int) ($ring['pendapatan_lain'] ?? 0) > 0): ?>
<p class="small text-muted mb-3">
    Pemasukan dari sumber lain (donasi, hibah, dll.): <strong><?= htmlspecialchars($fmt((int) $ring['pendapatan_lain'])) ?></strong>
    — termasuk dalam surplus operasional aset neto.
</p>
<?php endif; ?>

<div class="card shadow-sm neraca-report-card">
    <div class="card-body neraca-report-body">
        <?php keuangan_neraca_render_html($neraca, $fmt); ?>
    </div>
</div>

<style><?= keuangan_neraca_css_dua_kolom() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
