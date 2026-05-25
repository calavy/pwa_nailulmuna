<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/santri_portal.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

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
        set_flash('error', 'NIS atau PIN salah. Pastikan PIN portal santri atau PIN cashless sudah diatur pengurus.');
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

$brandNama = auth_portal_brand_nama($pdo);
$welcome = auth_portal_welcome_copy($pdo);

auth_portal_layout_begin([
    'title' => 'Portal Santri',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => $welcome['tagline_portal'],
    'subtitle' => 'Masuk dengan NIS dan PIN (portal santri atau PIN cashless) untuk tugas ikhtibar dan riwayat pribadi.',
    'nama_ponpes' => $brandNama,
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
        <label class="form-label">PIN</label>
        <input type="password" name="pin" class="form-control" required minlength="4" maxlength="32" inputmode="numeric" autocomplete="current-password" placeholder="PIN portal atau cashless">
    </div>
    <button type="submit" class="btn btn-auth-primary w-100">Masuk</button>
</form>
<p class="small text-muted text-center mt-3 mb-0">PIN bisa dari <strong>portal santri</strong> (edit santri) atau <strong>PIN cashless</strong> (menu keuangan). Berbeda dari PIN wali.</p>
<p class="small text-center mt-2 mb-0">
    <a href="<?= htmlspecialchars(app_href('/wali/login.php')) ?>">Masuk sebagai wali santri</a>
    · <a href="<?= htmlspecialchars(app_href('/login.php')) ?>">Portal pengurus</a>
</p>
<?php
auth_portal_layout_end();
