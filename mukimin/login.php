<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

ensure_mukimin_portal_columns($pdo);

if (isset($_SESSION['mukimin']['alumni_id'])) {
    app_redirect('mukimin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        set_flash('error', 'Username dan password wajib diisi.');
        app_redirect('mukimin/login.php');
    }
    $row = mukimin_portal_authenticate($pdo, $username, $password);
    if (!$row) {
        set_flash('error', 'Username atau password salah, atau akses belum didaftarkan pengurus.');
        app_redirect('mukimin/login.php');
    }
    session_regenerate_id(true);
    $_SESSION['mukimin'] = [
        'alumni_id' => (int) $row['id'],
        'nis' => (string) $row['nis'],
        'nama' => (string) $row['nama'],
        'username' => (string) ($row['portal_username'] ?? $username),
        'sektor' => (string) ($row['sektor'] ?? ''),
    ];
    app_redirect('mukimin/index.php');
}

$brandNama = auth_portal_brand_nama($pdo);
$welcome = auth_portal_welcome_copy($pdo);
$pageTitle = 'Portal Mukimin';

auth_portal_layout_begin([
    'title' => 'Portal Mukimin',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => $welcome['tagline_portal'],
    'subtitle' => 'Masuk dengan username dan password yang diberikan pengurus.',
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
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required maxlength="60" autocomplete="username"
            pattern="[a-zA-Z0-9._-]+">
    </div>
    <div>
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required minlength="6" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-auth-primary w-100">Masuk</button>
</form>
<p class="small text-muted text-center mt-3 mb-0">Hubungi pengurus jika belum punya akun portal.</p>
<?php
auth_portal_layout_end();
?>
