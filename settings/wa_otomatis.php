<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
require_once __DIR__ . '/includes/wa_otomatis_logic.php';

$pageTitle = 'WA Otomatis';
$bodyClass = 'settings-module-page wa-otomatis-page';
$settingsNavActive = '/settings/wa_otomatis.php';
require_once __DIR__ . '/../includes/header.php';

$tabPartial = match ($waActiveTab) {
    'gateway' => 'wa_otomatis_tab_gateway.php',
    'tagihan' => 'wa_otomatis_tab_tagihan.php',
    'cashless' => 'wa_otomatis_tab_cashless.php',
    'presensi' => 'wa_otomatis_tab_presensi.php',
    'alpa' => 'wa_otomatis_tab_alpa.php',
    'poin' => 'wa_otomatis_tab_poin.php',
    'izin' => 'wa_otomatis_tab_izin.php',
    'template' => 'wa_otomatis_tab_template.php',
    'log' => 'wa_otomatis_tab_log.php',
    default => 'wa_otomatis_tab_ringkasan.php',
};
?>

<style>
.wa-otomatis-page .wa-otomatis-nav__item { display: flex; align-items: flex-start; gap: .65rem; padding: .55rem .75rem; border-radius: .5rem; }
.wa-otomatis-page .wa-otomatis-nav__item.active { background: var(--bs-primary); border-color: var(--bs-primary); color: #fff; }
.wa-otomatis-page .wa-otomatis-nav__item.active small { color: rgba(255,255,255,.82); }
.wa-otomatis-page .wa-otomatis-nav__icon { width: 1.35rem; text-align: center; margin-top: .1rem; opacity: .9; flex-shrink: 0; }
.wa-otomatis-page .wa-otomatis-nav__text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.wa-otomatis-page .wa-otomatis-nav__text small { color: var(--bs-secondary-color); font-size: .72rem; }
.wa-otomatis-page .wa-otomatis-panel-title { font-size: 1rem; font-weight: 600; margin-bottom: .25rem; }
.wa-otomatis-page .wa-otomatis-panel { min-width: 0; }
.wa-otomatis-page .card-body { min-width: 0; }
.wa-otomatis-page .table-responsive { -webkit-overflow-scrolling: touch; }
@media (max-width: 991.98px) {
    .wa-otomatis-page .wa-otomatis-nav {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        gap: .35rem;
        overflow-x: auto;
        overflow-y: hidden;
        padding-bottom: .35rem;
        margin-bottom: 1rem !important;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }
    .wa-otomatis-page .wa-otomatis-nav__item {
        flex: 0 0 auto;
        min-width: 7.5rem;
        max-width: 10rem;
        padding: .5rem .65rem;
        white-space: normal;
    }
    .wa-otomatis-page .wa-otomatis-nav__text strong { font-size: .82rem; line-height: 1.2; }
    .wa-otomatis-page .wa-otomatis-nav__text small { display: none; }
    .wa-otomatis-page .wa-otomatis-layout > [class*="col-"] { width: 100%; max-width: 100%; }
}
@media (max-width: 575.98px) {
    .wa-otomatis-page .page-intro .h4 { font-size: 1.15rem; }
    .wa-otomatis-page .input-group { flex-wrap: wrap; }
    .wa-otomatis-page .input-group .form-control { min-width: 0; }
    .wa-otomatis-page .wa-otomatis-nav__item { min-width: 6.75rem; }
}
</style>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">WA Otomatis</h1>
    <p class="text-muted mb-0 small">Semua pengaturan WhatsApp otomatis dalam satu halaman — gateway, jadwal, presensi, alpa, poin, izin, dan template.</p>
</div>

<?php if ($msg = get_flash('success')): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>
<?php if ($msg = get_flash('error')): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>
<?php if ($msg = get_flash('warning')): ?>
    <div class="alert alert-warning py-2 small"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>

<div class="row g-3 wa-otomatis-layout">
    <div class="col-lg-3">
        <?php require __DIR__ . '/partials/wa_otomatis_tabs_nav.php'; ?>
    </div>
    <div class="col-lg-9 wa-otomatis-panel">
        <div class="wa-otomatis-panel-title">
            <?= htmlspecialchars($waTabs[$waActiveTab]['label'] ?? 'Ringkasan') ?>
        </div>
        <p class="small text-muted mb-3"><?= htmlspecialchars($waTabs[$waActiveTab]['desc'] ?? '') ?></p>
        <?php require __DIR__ . '/partials/' . $tabPartial; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
