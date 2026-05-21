<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/includes/auth_portal_layout.php';

if (isset($_SESSION['user'])) {
    $role = (string) ($_SESSION['user']['role'] ?? '');
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    app_redirect('dashboard.php');
}

$peran = strtolower(trim((string) ($_GET['peran'] ?? $_POST['peran'] ?? '')));
$peranValid = ['pengurus', 'petugas', 'wali', 'pembimbing'];
if ($peran !== '' && !in_array($peran, $peranValid, true)) {
    $peran = '';
}

if ($peran === 'wali') {
    app_redirect('wali/login.php');
}

if ($peran === 'petugas' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('presensi/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($peran, ['pengurus', 'pembimbing'], true)) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $isValidLogin = false;
    $userName = 'Administrator';
    $userRow = null;

    if (table_exists($pdo, 'users')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi') NOT NULL DEFAULT 'pengurus'");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
        $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $userRow = $statement->fetch();

        if ($userRow && password_verify($password, $userRow['password'])) {
            $isValidLogin = true;
            $userName = $userRow['nama'];
        }
    }

    if (!$isValidLogin && $username === 'admin' && $password === 'admin123') {
        $isValidLogin = true;
    }

    if ($isValidLogin) {
        $isSuperAdmin = (int) ($userRow['is_super_admin'] ?? 0) === 1;
        if ($username === 'admin') {
            $isSuperAdmin = true;
        }
        $_SESSION['user'] = [
            'id' => (int) ($userRow['id'] ?? 1),
            'nama' => $userName,
            'username' => $username,
            'role' => $userRow['role'] ?? 'admin',
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
        ];
        set_flash('success', 'Login berhasil.');
        app_redirect('dashboard.php');
    }

    set_flash('error', 'Username atau password salah.');
    header('Location: ' . app_url('login.php') . '?peran=' . urlencode($peran));
    exit;
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrlSetting = trim((string) app_setting($pdo, 'logo_url', ''));
$heroLogo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrlSetting;

$peranLabel = $peran === 'pembimbing' ? 'Pembimbing' : ($peran === 'pengurus' ? 'Pengurus / Admin' : '');
$portalSubtitle = $peran === ''
    ? 'Silakan pilih peran Anda untuk masuk ke sistem.'
    : 'Masukkan username dan password akun ' . strtolower($peranLabel) . '.';

auth_portal_layout_begin([
    'title' => $peran === '' ? 'Masuk' : 'Login ' . $peranLabel,
    'welcome' => 'Selamat datang',
    'subtitle' => $portalSubtitle,
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $namaPonpes,
    'logo_url' => $heroLogo !== '' ? app_href($heroLogo) : '',
    'max_width' => $peran === '' ? '520px' : '420px',
    'accent' => 'teal',
]);

$err = get_flash('error');
$ok = get_flash('success');
?>
                <?php if ($err): ?>
                    <div class="alert alert-danger py-2 small" role="alert"><?= htmlspecialchars($err) ?></div>
                <?php endif; ?>
                <?php if ($ok): ?>
                    <div class="alert alert-success py-2 small" role="status"><?= htmlspecialchars($ok) ?></div>
                <?php endif; ?>

                <?php if ($peran === ''): ?>
                    <p class="text-muted small text-center mb-3">Pilih cara masuk sesuai tugas Anda.</p>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pengurus')) ?>" class="btn btn-outline-success w-100 py-3 text-start">
                                <i class="fa-solid fa-user-tie fa-lg me-2"></i>
                                <strong>Pengurus / Admin</strong>
                                <span class="d-block small text-muted mt-1">Username &amp; password</span>
                            </a>
                        </div>
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="btn btn-outline-primary w-100 py-3 text-start">
                                <i class="fa-solid fa-chalkboard-user fa-lg me-2"></i>
                                <strong>Pembimbing</strong>
                                <span class="d-block small text-muted mt-1">Username &amp; password</span>
                            </a>
                        </div>
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/presensi/login.php')) ?>" class="btn btn-outline-info w-100 py-3 text-start">
                                <i class="fa-solid fa-qrcode fa-lg me-2"></i>
                                <strong>Petugas presensi</strong>
                                <span class="d-block small text-muted mt-1">Password saja</span>
                            </a>
                        </div>
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/wali/login.php')) ?>" class="btn btn-outline-success w-100 py-3 text-start border-2">
                                <i class="fa-solid fa-mobile-screen-button fa-lg me-2"></i>
                                <strong>Portal wali (HP)</strong>
                                <span class="d-block small text-muted mt-1">Cari anak · NIS · PIN</span>
                            </a>
                        </div>
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/santri_portal/login.php')) ?>" class="btn btn-outline-success w-100 py-3 text-start">
                                <i class="fa-solid fa-user-graduate fa-lg me-2"></i>
                                <strong>Portal santri</strong>
                                <span class="d-block small text-muted mt-1">NIS · PIN santri</span>
                            </a>
                        </div>
                        <div class="col-sm-6">
                            <a href="<?= htmlspecialchars(app_href('/mukimin/login.php')) ?>" class="btn btn-outline-secondary w-100 py-3 text-start">
                                <i class="fa-solid fa-book-open fa-lg me-2"></i>
                                <strong>Portal mukimin</strong>
                                <span class="d-block small text-muted mt-1">Alumni terdaftar · username &amp; password</span>
                            </a>
                        </div>
                    </div>
                    <p class="small text-muted text-center mt-3 mb-0">Logo &amp; nama pondok dapat diubah di menu <strong>Pengaturan</strong> setelah login pengurus.</p>
                <?php else: ?>
                    <div class="d-flex justify-content-end mb-2">
                        <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="btn btn-link btn-sm">Ganti peran</a>
                    </div>
                    <form method="post">
                        <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-auth-primary w-100">Masuk</button>
                    </form>
                    <?php if ($peran === 'pengurus'): ?>
                        <p class="small text-muted mt-3 mb-0 text-center">Akun awal: <strong>admin</strong> / <strong>admin123</strong></p>
                    <?php endif; ?>
                <?php endif; ?>
<?php
auth_portal_layout_end();
