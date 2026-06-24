<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

yayasan_ensure_tables($pdo);

$periode = yayasan_periode_berjalan($pdo);
$periodeLabel = $periode['label'];

$kategoriRaw = trim((string) ($_GET['kategori'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($kategoriRaw !== '' ? $kategoriRaw : null);
$openDetail = trim((string) ($_GET['tingkatan'] ?? ''));

$pageTitle = 'Ranking Keaktifan per Tingkatan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
$pageScripts = [app_asset_href('/assets/js/yayasan-period.js')];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="yp-wrap yp-rank-page">
<div class="page-intro mb-3">
    <?php $yayasanCrumbTail = 'Ranking keaktifan'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
    <h1 class="h4 mb-1">Ranking keaktifan per tingkatan</h1>
    <p class="text-muted mb-0 small">
        Urutan <strong>#1 terbaik</strong> di atas. Klik kartu tingkatan untuk melihat <strong>ranking santri</strong> di dalamnya.
        <a href="<?= htmlspecialchars(yayasan_home_href()) ?>">Kembali ke dashboard</a>
        ·
        <a href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">Keaktifan hari ini</a>
    </p>
    <div id="yp-rank-hero-stats" class="yp-rank-hero-stats d-none"></div>
</div>

<?php require __DIR__ . '/../includes/partials/yayasan_periode_rekap_link.php'; ?>

<form method="get" action="<?= htmlspecialchars(app_href('/yayasan/keaktifan_ranking.php')) ?>" class="row g-2 align-items-end mb-3 yp-filter-bar" data-yp-period-ajax="1" data-yp-period-mount="yp-rank-mount" data-yp-period-api="<?= htmlspecialchars(app_href('/api/yayasan/rank_tingkatan.php')) ?>">
    <div class="col-md-3 col-6">
        <label class="form-label small mb-0">Kategori kegiatan</label>
        <select class="form-select form-select-sm" name="kategori">
            <option value=""<?= $kategori === null ? ' selected' : '' ?>>Semua</option>
            <option value="JAMAAH"<?= $kategori === 'JAMAAH' ? ' selected' : '' ?>>Jamaah</option>
            <option value="TAALIM"<?= $kategori === 'TAALIM' ? ' selected' : '' ?>>Taalim</option>
            <option value="PKPPS"<?= $kategori === 'PKPPS' ? ' selected' : '' ?>>PKPPS</option>
        </select>
    </div>
    <div class="col-md-auto col-6">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Terapkan filter</button>
        <button type="submit" name="refresh" value="1" class="btn btn-outline-secondary btn-sm" title="Muat ulang"><i class="fa-solid fa-rotate-right"></i></button>
    </div>
</form>

<div id="yp-rank-mount">
    <div class="text-center py-5 text-muted">
        <i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat ranking…
    </div>
</div>
</div>

<script>
window.__ypPeriodBoot = <?= json_encode([
    'type' => 'rank',
    'mount' => 'yp-rank-mount',
    'api' => app_href('/api/yayasan/rank_tingkatan.php'),
    'params' => array_filter([
        'kategori' => $kategoriRaw !== '' ? $kategoriRaw : null,
        'tingkatan' => $openDetail !== '' ? $openDetail : null,
    ], static fn ($v) => $v !== null && $v !== ''),
    'lockPeriode' => true,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
