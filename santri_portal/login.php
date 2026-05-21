<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/santri_portal.php';

ensure_santri_portal_pin_column($pdo);

if (isset($_SESSION['santri_portal']['santri_id'])) {
    app_redirect('santri_portal/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nis = trim((string) ($_POST['nis'] ?? ''));
    $pin = (string) ($_POST['pin'] ?? '');
    if ($nis === '' || $pin === '') {
        set_flash('error', 'NIS dan PIN wajib diisi.');
        app_redirect('santri_portal/login.php');
    }
    $row = santri_portal_verify_login($pdo, $nis, $pin);
    if (!$row) {
        set_flash('error', 'NIS atau PIN salah, atau PIN belum diatur pengurus.');
        app_redirect('santri_portal/login.php');
    }
    session_regenerate_id(true);
    $_SESSION['santri_portal'] = [
        'santri_id' => (int) $row['id'],
        'nis' => (string) $row['nis'],
        'nama_santri' => (string) ($row['nama_santri'] ?? ''),
    ];
    app_redirect('santri_portal/index.php');
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Portal Santri',
    'welcome' => 'Portal Santri',
    'subtitle' => 'Masuk dengan NIS dan PIN untuk melihat riwayat domisili dan pelanggaran Anda.',
    'nama_ponpes' => $namaPonpes,
    'max_width' => '420px',
    'accent' => 'teal',
]);
$err = get_flash('error');
?>
<?php if ($err): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>
<form method="post" class="d-grid gap-3">
    <div>
        <label class="form-label">NIS</label>
        <input type="text" name="nis" class="form-control" required inputmode="numeric" autocomplete="username">
    </div>
    <div>
        <label class="form-label">PIN portal santri</label>
        <input type="password" name="pin" class="form-control" required minlength="6" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-auth-primary w-100">Masuk</button>
</form>
<p class="small text-muted text-center mt-3 mb-0">PIN diatur pengurus pondok. Berbeda dari PIN portal wali.</p>
<p class="small text-center mt-2 mb-0">
    <a href="<?= htmlspecialchars(app_href('/wali/login.php')) ?>">Masuk sebagai wali santri</a>
    · <a href="<?= htmlspecialchars(app_href('/login.php')) ?>">Portal pengurus</a>
</p>
<?php
auth_portal_layout_end();
