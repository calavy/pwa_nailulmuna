<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';

ensure_mukimin_portal_columns($pdo);

if (isset($_SESSION['mukimin']['alumni_id'])) {
    header('Location: /mukimin/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        set_flash('error', 'Username dan password wajib diisi.');
        header('Location: /mukimin/login.php');
        exit;
    }
    $row = mukimin_portal_authenticate($pdo, $username, $password);
    if (!$row) {
        set_flash('error', 'Username atau password salah, atau akses belum didaftarkan pengurus.');
        header('Location: /mukimin/login.php');
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['mukimin'] = [
        'alumni_id' => (int) $row['id'],
        'nis' => (string) $row['nis'],
        'nama' => (string) $row['nama'],
        'username' => (string) ($row['portal_username'] ?? $username),
        'sektor' => (string) ($row['sektor'] ?? ''),
    ];
    header('Location: /mukimin/index.php');
    exit;
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$pageTitle = 'Portal Mukimin';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Portal Mukimin',
    'welcome' => 'Portal Mukimin',
    'subtitle' => 'Masuk dengan username dan password yang diberikan pengurus.',
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
