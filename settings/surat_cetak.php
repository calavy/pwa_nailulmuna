<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';
require_once __DIR__ . '/../helpers/surat_cetak_templates.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
ensure_pondok_settings_defaults($pdo);

$tab = trim((string) ($_GET['tab'] ?? 'kop'));
if (!in_array($tab, ['kop', 'template'], true)) {
    $tab = 'kop';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_kop') {
        $result = surat_cetak_kop_save($pdo, $_POST);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/settings/surat_cetak.php?tab=kop'));
        exit;
    }
    if ($action === 'save_templates') {
        $result = surat_cetak_template_save_all($pdo, $_POST);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/settings/surat_cetak.php?tab=template'));
        exit;
    }
}

$kopValues = surat_cetak_kop_values($pdo);
$kopPreview = pondok_kop_data($pdo);
$tplGroups = surat_cetak_template_groups();
$tplValues = surat_cetak_template_values($pdo);
$accentPreview = surat_cetak_kop_accent_color($pdo);

$pageTitle = 'Kop & Template Surat Cetak';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/surat_cetak.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Kop &amp; template surat cetak</h1>
    <p class="text-muted mb-0 small">
        Atur tampilan kop surat (kontak, kota, warna) dan teks isian semua dokumen cetak.
        Nama pondok, alamat, logo, dan stempel di <a href="<?= htmlspecialchars(app_href('/settings/pesantren.php')) ?>">Profil Pondok</a>.
    </p>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'kop' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/settings/surat_cetak.php?tab=kop')) ?>">Kop surat</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'template' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/settings/surat_cetak.php?tab=template')) ?>">Template isian</a>
    </li>
</ul>

<?php if ($tab === 'kop'): ?>
    <?php require __DIR__ . '/partials/surat_cetak_kop_view.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/partials/surat_cetak_template_view.php'; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
