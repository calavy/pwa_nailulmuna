<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';
require_once __DIR__ . '/../helpers/wa_templates.php';
require_once __DIR__ . '/../helpers/wa_pembimbing_scan.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
pembimbing_ensure_wa_scan_reminder_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_wa_templates') {
        $res = wa_template_save_all($pdo, $_POST);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
    } elseif ($action === 'save_wa_scan_settings') {
        save_setting($pdo, 'wa_pembimbing_scan_enabled', isset($_POST['wa_pembimbing_scan_enabled']) ? '1' : '0');
        $menit = max(5, min(30, (int) ($_POST['wa_pembimbing_scan_menit_sebelum'] ?? 10)));
        save_setting($pdo, 'wa_pembimbing_scan_menit_sebelum', (string) $menit);
        set_flash('success', 'Pengaturan WA scan pembimbing disimpan.');
    }
    header('Location: ' . app_href('/settings/wa_pesan.php'));
    exit;
}

$defs = wa_template_definitions();
$values = [];
foreach ($defs as $slug => $meta) {
    $values[$slug] = wa_template_get($pdo, $slug);
}
$scanEnabled = trim((string) app_setting($pdo, 'wa_pembimbing_scan_enabled', '1')) === '1';
$scanMenit = max(5, min(30, (int) app_setting($pdo, 'wa_pembimbing_scan_menit_sebelum', '10')));

$pageTitle = 'Template Pesan WA';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/wa_pesan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">Pusat WA Otomatis</a></p>
    <h1 class="h4 mb-1">Template pesan WA otomatis</h1>
    <p class="text-muted mb-0 small">Sesuaikan teks pesan. Gunakan placeholder dalam kurung kurawal — contoh: <code>{nama_santri}</code>.</p>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Pengingat scan pembimbing / munawib</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save_wa_scan_settings">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="wa_pembimbing_scan_enabled" name="wa_pembimbing_scan_enabled" value="1" <?= $scanEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_pembimbing_scan_enabled">Kirim WA otomatis</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Menit sebelum kegiatan selesai</label>
                <input type="number" class="form-control form-control-sm" name="wa_pembimbing_scan_menit_sebelum" min="5" max="30" value="<?= (int) $scanMenit ?>">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm">Simpan pengaturan scan</button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2">Toggle per pembimbing ada di <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>">Data Pembimbing → Edit</a>.</p>
    </div>
</div>

<form method="post">
    <input type="hidden" name="action" value="save_wa_templates">
    <?php foreach ($defs as $slug => $meta): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h2 class="h6 mb-1"><?= htmlspecialchars((string) $meta['label']) ?></h2>
            <p class="small text-muted mb-2"><?= htmlspecialchars((string) $meta['hint']) ?></p>
            <p class="small mb-2"><strong>Placeholder:</strong> <code><?= htmlspecialchars((string) $meta['placeholders']) ?></code></p>
            <textarea class="form-control font-monospace" name="wa_tpl_<?= htmlspecialchars($slug) ?>" rows="6"><?= htmlspecialchars($values[$slug]) ?></textarea>
            <details class="mt-2">
                <summary class="small text-muted">Reset ke default</summary>
                <pre class="small bg-light p-2 rounded mt-1 mb-0"><?= htmlspecialchars((string) $meta['default']) ?></pre>
            </details>
        </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan semua template</button>
    <a class="btn btn-outline-secondary ms-2" href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">Kembali</a>
</form>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
