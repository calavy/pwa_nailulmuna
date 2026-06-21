<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_portal_banner.php';

require_roles(['admin']);
require_super_admin();

$variants = pembimbing_portal_banner_variants();
$variantLabels = [
    'default' => 'Default (Beranda)',
    'kajian' => 'Kajian (Ta\'lim)',
    'pkpps' => 'PKPPS',
    'jamaah' => 'Jama\'ah',
];
$activeVariant = strtolower(trim((string) ($_GET['tema'] ?? 'default')));
if (!in_array($activeVariant, $variants, true)) {
    $activeVariant = 'default';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_portal_banner') {
    $saveVariant = strtolower(trim((string) ($_POST['variant'] ?? '')));
    if (!in_array($saveVariant, $variants, true)) {
        set_flash('error', 'Tema banner tidak valid.');
        header('Location: ' . app_href('/settings/portal_pembimbing.php'));
        exit;
    }
    pembimbing_portal_banner_save($pdo, $saveVariant, [
        'enabled' => $_POST['enabled'] ?? '',
        'kicker' => (string) ($_POST['kicker'] ?? ''),
        'title' => (string) ($_POST['title'] ?? ''),
        'subtitle' => (string) ($_POST['subtitle'] ?? ''),
        'tagline' => (string) ($_POST['tagline'] ?? ''),
        'icon' => (string) ($_POST['icon'] ?? ''),
        'gradient_from' => (string) ($_POST['gradient_from'] ?? ''),
        'gradient_via' => (string) ($_POST['gradient_via'] ?? ''),
        'gradient_to' => (string) ($_POST['gradient_to'] ?? ''),
        'accent' => (string) ($_POST['accent'] ?? ''),
        'glow' => (string) ($_POST['glow'] ?? ''),
        'pattern' => (string) ($_POST['pattern'] ?? ''),
    ]);
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
    set_flash('success', 'Banner portal "' . ($variantLabels[$saveVariant] ?? $saveVariant) . '" disimpan.');
    header('Location: ' . app_href('/settings/portal_pembimbing.php?tema=' . rawurlencode($saveVariant)));
    exit;
}

$configs = [];
foreach ($variants as $v) {
    $configs[$v] = pembimbing_portal_banner_get($pdo, $v);
}
$cfg = $configs[$activeVariant];
$defaults = pembimbing_portal_banner_defaults($activeVariant);

$pageTitle = 'Banner Portal Pembimbing';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/portal_pembimbing.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1"><i class="fa-solid fa-panorama me-2 text-primary"></i>Banner Portal Pembimbing</h1>
    <p class="text-muted mb-0 small">
        Atur tampilan banner beranda portal pembimbing untuk tema <strong>Default</strong>, <strong>Kajian</strong>, <strong>PKPPS</strong>, dan <strong>Jama'ah</strong>.
        Kosongkan judul untuk memakai nama pembimbing otomatis.
    </p>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <?php foreach ($variants as $v): ?>
        <li class="nav-item">
            <a class="nav-link<?= $activeVariant === $v ? ' active' : '' ?>"
               href="<?= htmlspecialchars(app_href('/settings/portal_pembimbing.php?tema=' . rawurlencode($v))) ?>">
                <?= htmlspecialchars($variantLabels[$v] ?? $v) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" class="card border-0 shadow-sm">
            <div class="card-body">
                <input type="hidden" name="action" value="save_portal_banner">
                <input type="hidden" name="variant" value="<?= htmlspecialchars($activeVariant) ?>">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="enabled" id="banner_enabled" value="1" <?= ($cfg['enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="banner_enabled">Banner tema ini aktif</label>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Kicker (label atas)</label>
                        <input type="text" class="form-control form-control-sm" name="kicker" value="<?= htmlspecialchars((string) ($cfg['kicker'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Icon Font Awesome</label>
                        <input type="text" class="form-control form-control-sm font-monospace" name="icon" value="<?= htmlspecialchars((string) ($cfg['icon'] ?? '')) ?>" placeholder="fa-book-open">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Judul kustom <span class="text-muted">(kosong = nama pembimbing)</span></label>
                        <input type="text" class="form-control form-control-sm" name="title" value="<?= htmlspecialchars((string) ($cfg['title'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Subjudul</label>
                        <textarea class="form-control form-control-sm" name="subtitle" rows="2"><?= htmlspecialchars((string) ($cfg['subtitle'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Tagline (slogan pendek)</label>
                        <input type="text" class="form-control form-control-sm" name="tagline" value="<?= htmlspecialchars((string) ($cfg['tagline'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Gradient awal</label>
                        <input type="color" class="form-control form-control-color w-100" name="gradient_from" value="<?= htmlspecialchars((string) ($cfg['gradient_from'] ?? $defaults['gradient_from'])) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Gradient tengah</label>
                        <input type="color" class="form-control form-control-color w-100" name="gradient_via" value="<?= htmlspecialchars((string) ($cfg['gradient_via'] ?? $defaults['gradient_via'])) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Gradient akhir</label>
                        <input type="color" class="form-control form-control-color w-100" name="gradient_to" value="<?= htmlspecialchars((string) ($cfg['gradient_to'] ?? $defaults['gradient_to'])) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Aksen</label>
                        <input type="color" class="form-control form-control-color w-100" name="accent" value="<?= htmlspecialchars((string) ($cfg['accent'] ?? $defaults['accent'])) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Glow</label>
                        <input type="color" class="form-control form-control-color w-100" name="glow" value="<?= htmlspecialchars((string) ($cfg['glow'] ?? $defaults['glow'])) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Pola dekorasi</label>
                        <select class="form-select form-select-sm" name="pattern">
                            <?php foreach (['dots' => 'Titik', 'grid' => 'Grid', 'rays' => 'Sinar', 'waves' => 'Gelombang'] as $patVal => $patLabel): ?>
                                <option value="<?= htmlspecialchars($patVal) ?>"<?= ($cfg['pattern'] ?? '') === $patVal ? ' selected' : '' ?>><?= htmlspecialchars($patLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan tema <?= htmlspecialchars($variantLabels[$activeVariant] ?? $activeVariant) ?></button>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php')) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Lihat portal pembimbing</a>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Pratinjau</div>
            <div class="card-body p-2">
                <?php
                $labelUser = 'Ust. Contoh Pembimbing';
                $pbSudahHadir = true;
                $pbDashHijriLabel = '15 Ramadhan 1447 H';
                $pbDashPasaran = 'Legi';
                $isMunawibPortal = false;
                $pbBannerCfg = $cfg;
                $pbBannerVariant = $activeVariant;
                $totalSantri = 42;
                $jumlahTingkatanHome = 3;
                $pbDashHasPkpps = $activeVariant === 'pkpps';
                $kegiatanAktifPresensi = $activeVariant !== 'default' ? [['nama' => 'Contoh']] : [];
                $appLogoHref = app_pondok_logo_href($pdo);
                require __DIR__ . '/../pembimbing/partials/portal_banner.php';
                ?>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">
            Tema otomatis: beranda memakai <em>Default</em> atau <em>PKPPS</em> jika pembimbing hanya PKPPS;
            halaman keaktivan memakai <em>Kajian</em> atau <em>PKPPS</em> sesuai tab rekap.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/settings_nav.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
