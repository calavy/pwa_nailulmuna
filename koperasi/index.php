<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

cashless_koperasi_ensure_schema($pdo);

if (cashless_koperasi_session_active()) {
    app_redirect('koperasi/scan.php');
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$koperasiList = cashless_koperasi_list($pdo);

auth_portal_layout_begin([
    'title' => 'Portal Koperasi Cashless',
    'welcome' => 'Portal Koperasi',
    'subtitle' => 'Pilih koperasi untuk login petugas scan belanja cashless.',
    'nama_ponpes' => $namaPonpes,
    'max_width' => '520px',
    'accent' => 'teal',
]);
?>
<p class="text-muted small text-center mb-3">Tiga koperasi terpisah — masing-masing punya login dan laporan sendiri.</p>
<div class="row g-2">
    <?php foreach ($koperasiList as $kop): ?>
        <div class="col-12">
            <a href="<?= htmlspecialchars(app_href('/koperasi/login.php?k=' . (int) $kop['id'])) ?>" class="btn btn-outline-success w-100 py-3 text-start">
                <i class="fa-solid fa-store fa-lg me-2"></i>
                <strong><?= htmlspecialchars((string) $kop['nama']) ?></strong>
                <span class="d-block small text-muted mt-1">Login petugas · scan &amp; laporan</span>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-muted text-center mt-3 mb-0">Password diatur pengurus di menu <strong>Cashless &amp; Uang Saku</strong>.</p>
<?php
auth_portal_layout_end([
    ['href' => '/login.php', 'label' => 'Login pengurus / peran lain'],
]);
