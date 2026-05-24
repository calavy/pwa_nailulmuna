<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/user_profil.php';
require_once __DIR__ . '/includes/auth_portal_layout.php';

if (isset($_SESSION['user'])) {
    $role = (string) ($_SESSION['user']['role'] ?? '');
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    if (is_super_admin()) {
        app_redirect('dashboard.php');
    }
    if ($pdo instanceof PDO && function_exists('get_allowed_permission_key_map')) {
        $allowedMap = get_allowed_permission_key_map($pdo);
        if ($allowedMap === null) {
            app_redirect('dashboard.php');
        }
        if ($allowedMap === []) {
            unset($_SESSION['user']);
            set_flash('error', 'Akun belum memiliki hak akses. Hubungi admin super.');
            header('Location: ' . app_url('login.php'));
            exit;
        }
        if (!function_exists('user_permission_path_map')) {
            require_once __DIR__ . '/helpers/user_permissions.php';
        }
        $fallback = app_acl_first_allowed_path(user_permission_path_map(), $allowedMap);
        if ($fallback !== null) {
            app_redirect_path($fallback);
        }
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
        user_profil_ensure_schema($pdo);
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi') NOT NULL DEFAULT 'pengurus'");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
        $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin, foto_profil FROM users WHERE username = :username LIMIT 1');
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
            'foto_profil' => trim((string) ($userRow['foto_profil'] ?? '')),
        ];
        set_flash('success', 'Login berhasil.');
        if ($peran === 'pembimbing') {
            app_redirect('pembimbing/tugas/index.php');
        }
        app_redirect('dashboard.php');
    }

    set_flash('error', 'Username atau password salah.');
    header('Location: ' . app_url('login.php') . '?peran=' . urlencode($peran));
    exit;
}

$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrlSetting = trim((string) app_setting($pdo, 'logo_url', ''));
$heroLogo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrlSetting;

$welcome = auth_portal_welcome_copy($pdo);
$peranLabel = $peran === 'pembimbing' ? 'Pembimbing' : ($peran === 'pengurus' ? 'Pengurus / Admin' : '');
$portalSubtitleMobile = $peran === ''
    ? 'Pilih peran di bawah untuk masuk ke sistem manajemen pondok.'
    : 'Masukkan kredensial akun ' . strtolower($peranLabel) . ' Anda.';
$portalSubtitleDesktop = $peran === ''
    ? 'Pilih peran di samping untuk masuk ke sistem manajemen pondok.'
    : $portalSubtitleMobile;

auth_portal_layout_begin([
    'title' => $peran === '' ? 'Portal Masuk' : 'Login ' . $peranLabel,
    'welcome_salam' => $welcome['salam'],
    'welcome_tagline' => $welcome['tagline'],
    'subtitle_mobile' => $portalSubtitleMobile,
    'subtitle_desktop' => $portalSubtitleDesktop,
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => $heroLogo !== '' ? app_href($heroLogo) : '',
    'layout' => $peran === '' ? 'split' : 'stack',
    'card_title' => $peran === '' ? 'Pilih cara masuk' : 'Masuk ke akun',
    'card_meta' => $peran === '' ? 'Satu portal untuk pengurus, pembimbing, dan layanan terkait' : 'Data Anda aman · gunakan akun resmi pondok',
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
                    <div class="auth-portal-role-grid">
                        <?php
                        auth_portal_role_link([
                            'href' => app_href('/login.php?peran=pengurus'),
                            'icon' => 'fa-user-tie',
                            'icon_mod' => 'pengurus',
                            'title' => 'Pengurus / Admin',
                            'desc' => 'Username & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/login.php?peran=pembimbing'),
                            'icon' => 'fa-chalkboard-user',
                            'icon_mod' => 'pembimbing',
                            'title' => 'Pembimbing',
                            'desc' => 'Username & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/presensi/login.php'),
                            'icon' => 'fa-qrcode',
                            'icon_mod' => 'presensi',
                            'title' => 'Petugas presensi',
                            'desc' => 'Password presensi',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/wali/login.php'),
                            'icon' => 'fa-mobile-screen-button',
                            'icon_mod' => 'wali',
                            'title' => 'Portal wali',
                            'desc' => 'NIS · PIN wali santri',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/santri_portal/login.php'),
                            'icon' => 'fa-user-graduate',
                            'icon_mod' => 'santri',
                            'title' => 'Portal santri',
                            'desc' => 'NIS · PIN santri',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/koperasi/index.php'),
                            'icon' => 'fa-store',
                            'icon_mod' => 'koperasi',
                            'title' => 'Koperasi cashless',
                            'desc' => 'Pilih koperasi · password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/mukimin/login.php'),
                            'icon' => 'fa-book-open',
                            'icon_mod' => 'mukimin',
                            'title' => 'Portal mukimin',
                            'desc' => 'Alumni · username & password',
                            'full' => true,
                        ]);
                        ?>
                    </div>
                    <p class="auth-portal-footnote">Logo dan nama pondok dapat diubah di menu <strong>Pengaturan</strong> setelah login pengurus.</p>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="auth-portal-back">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali pilih peran
                    </a>
                    <form method="post" class="auth-portal-form">
                        <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="login-username">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="Masukkan username">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="login-password">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-auth-primary w-100">
                            <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk ke portal
                        </button>
                    </form>
                    <?php if ($peran === 'pengurus'): ?>
                        <p class="auth-portal-footnote">Akun percobaan: <strong>admin</strong> / <strong>admin123</strong></p>
                    <?php endif; ?>
                <?php endif; ?>
<?php
auth_portal_layout_end();
