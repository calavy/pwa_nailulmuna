<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

require_once __DIR__ . '/includes/pondok_settings_logic.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
$keaktifanScanSuggest = rekap_keaktifan_suggest_tanggal_mulai_scan($pdo);

$pageTitle = 'Profil Pondok';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/pesantren.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Profil Pondok</h1>
    <p class="text-muted mb-0 small">Identitas pesantren, logo, mode tampilan, dan parameter presensi/izin. Pengaturan WhatsApp ada di <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">Pusat WA Otomatis</a>.</p>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Logo</div>
            <div class="app-mini-stat-value <?= $logoConfigured ? 'text-success' : 'text-warning' ?>"><?= $logoConfigured ? 'Siap' : 'Belum' ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Stempel surat</div>
            <div class="app-mini-stat-value <?= $stampelSuratConfigured ? 'text-success' : 'text-muted' ?>"><?= $stampelSuratConfigured ? 'Custom' : 'Default' ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Stempel kuitansi</div>
            <div class="app-mini-stat-value <?= $stampelKuitansiConfigured ? 'text-success' : 'text-muted' ?>"><?= $stampelKuitansiConfigured ? 'Custom' : 'Default' ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Nama tampil aplikasi</div>
            <div class="app-mini-stat-value" style="font-size:1rem;"><?= htmlspecialchars($values['nama_ponpes'] !== '' ? $values['nama_ponpes'] : $appNama) ?></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/pondok_identity_view.php'; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
