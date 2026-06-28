<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/pkpps_rapor.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = pkpps_rapor_settings_save($pdo, $_POST);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/pkpps/pengaturan_rapor.php'));
    exit;
}

$values = pkpps_rapor_settings_values($pdo);
$defaults = pkpps_rapor_setting_defaults();

$pageTitle = 'Pengaturan Rapor PKPPS';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a> · <a href="<?= htmlspecialchars(app_href('/pkpps/rapor.php')) ?>">Rapor</a></p>
    <h1 class="h4 mb-1">Pengaturan rapor PKPPS</h1>
    <p class="text-muted mb-0 small">Label tampilan portal wali dan judul cetak rapor PKPPS. Template pesan WA otomatis di <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">Pengaturan → WA Otomatis → Template</a>. Rapor pesantren umum di <a href="<?= htmlspecialchars(app_href('/settings/surat_cetak.php?tab=template')) ?>">Kop &amp; Template Surat</a>.</p>
</div>

<form method="post" class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Judul cetak rapor PKPPS</label>
        <input type="text" class="form-control" name="pkpps_rapor_judul_cetak" value="<?= htmlspecialchars($values['pkpps_rapor_judul_cetak']) ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Placeholder judul periode (form input)</label>
        <input type="text" class="form-control" name="pkpps_rapor_judul_placeholder" value="<?= htmlspecialchars($values['pkpps_rapor_judul_placeholder']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Label bagian keaktivan</label>
        <input type="text" class="form-control" name="pkpps_rapor_label_presensi" value="<?= htmlspecialchars($values['pkpps_rapor_label_presensi']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Label bagian setoran</label>
        <input type="text" class="form-control" name="pkpps_rapor_label_setoran" value="<?= htmlspecialchars($values['pkpps_rapor_label_setoran']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Label bagian tugas PKPPS</label>
        <input type="text" class="form-control" name="pkpps_rapor_label_tugas" value="<?= htmlspecialchars($values['pkpps_rapor_label_tugas']) ?>">
    </div>
    <div class="col-12">
        <label class="form-label">Info di portal wali</label>
        <textarea class="form-control" name="pkpps_rapor_info_portal" rows="2"><?= htmlspecialchars($values['pkpps_rapor_info_portal']) ?></textarea>
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan pengaturan</button>
        <a href="<?= htmlspecialchars(app_href('/pkpps/rapor.php')) ?>" class="btn btn-outline-secondary ms-1">Kembali ke rapor PKPPS</a>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
