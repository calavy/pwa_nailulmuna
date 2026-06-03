<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/user_profil.php';
require_once __DIR__ . '/helpers/login_pembimbing.php';
require_once __DIR__ . '/helpers/munawib.php';
require_once __DIR__ . '/includes/auth_portal_layout.php';

if (isset($_SESSION['user']) && $pdo instanceof PDO) {
    $peranGate = strtolower(trim((string) ($_GET['peran'] ?? $_POST['peran'] ?? '')));
    $pbActGate = strtolower(trim((string) ($_GET['act'] ?? '')));
    $pbDestGate = login_pembimbing_sanitize_dest($_GET['dest'] ?? $_POST['login_dest'] ?? '');
    $allowPbQrWhileLoggedIn = ($peranGate === 'pembimbing' && $pbActGate === 'qr');
    if ($pbDestGate === 'setoran') {
        require_once __DIR__ . '/helpers/akademik_setoran.php';
        $portalGate = akademik_setoran_portal_access_status($pdo);
        if ($portalGate['ok']) {
            app_redirect('pembimbing/setoran_dashboard.php');
        }
        if (!$allowPbQrWhileLoggedIn) {
            set_flash('error', akademik_setoran_portal_denial_message($portalGate));
            header('Location: ' . app_url('login.php?peran=pembimbing&act=qr&dest=setoran'));
            exit;
        }
    } elseif (!$allowPbQrWhileLoggedIn) {
        app_post_login_redirect($pdo);
    }
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
    $loginMethod = strtolower(trim((string) ($_POST['login_method'] ?? 'password')));
    $loginDest = login_pembimbing_sanitize_dest($_POST['login_dest'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $qrCode = trim((string) ($_POST['qr_code'] ?? ''));
    $isValidLogin = false;
    $userName = 'Administrator';
    $userRow = null;

    if (table_exists($pdo, 'users')) {
        user_profil_ensure_schema($pdo);
        if (empty($_SESSION['users_role_enum_v2'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
            try {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
            } catch (PDOException $e) { /* abaikan */ }
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
            $_SESSION['users_role_enum_v2'] = 1;
        }

        if ($peran === 'pembimbing' && $loginMethod === 'qr' && $qrCode !== '') {
            if (table_exists($pdo, 'pembimbing')) {
                $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
                    ? ' AND COALESCE(p.is_aktif, 1) = 1'
                    : '';
                $stmtPb = $pdo->prepare('
                    SELECT p.id AS pembimbing_id, p.nip, p.nama_pembimbing
                    FROM pembimbing p
                    WHERE (p.qr = :code OR p.nip = :code)' . $aktifSql . '
                    LIMIT 1
                ');
                $stmtPb->execute(['code' => $qrCode]);
                $pbRow = $stmtPb->fetch();
                if ($pbRow) {
                    $pbIdQr = (int) ($pbRow['pembimbing_id'] ?? 0);
                    if ($loginDest === 'setoran' && $pbIdQr > 0) {
                        require_once __DIR__ . '/helpers/akademik_setoran.php';
                        if (!akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pbIdQr)) {
                            set_flash('error', 'Kartu pembimbing dikenali, tetapi belum ditugaskan sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.');
                            header('Location: ' . app_url('login.php?peran=pembimbing&act=qr&dest=setoran'));
                            exit;
                        }
                    }
                    $stmtUser = $pdo->prepare('
                        SELECT id, nama, username, role, is_super_admin, foto_profil
                        FROM users
                        WHERE TRIM(username) = :nip
                        LIMIT 1
                    ');
                    $stmtUser->execute(['nip' => trim((string) $pbRow['nip'])]);
                    $userRow = $stmtUser->fetch();
                    if ($userRow) {
                        $isValidLogin = true;
                        $userName = (string) ($userRow['nama'] ?? $pbRow['nama_pembimbing']);
                        $username = (string) $userRow['username'];
                    } else {
                        set_flash('error', 'Kartu QR dikenali (' . (string) $pbRow['nama_pembimbing'] . '), tetapi akun login pembimbing belum dibuat. Hubungi pengurus.');
                        header('Location: ' . app_url('login.php?peran=pembimbing&act=qr'));
                        exit;
                    }
                }
            }
            if (!$isValidLogin) {
                munawib_ensure_schema($pdo);
                $mwLogin = munawib_buat_sesi_portal($pdo, $qrCode, $loginDest === 'setoran');
                if ($mwLogin['ok'] && isset($mwLogin['session']['user']) && is_array($mwLogin['session']['user'])) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = $mwLogin['session']['user'];
                    $_SESSION['munawib_id'] = (int) ($mwLogin['session']['munawib_id'] ?? 0);
                    $_SESSION['munawib_tingkatan'] = $mwLogin['session']['munawib_tingkatan'] ?? [];
                    unset(
                        $_SESSION['munawib_pembimbing_id'],
                        $_SESSION['munawib_kegiatan_id'],
                        $_SESSION['munawib_penugasan_id'],
                        $_SESSION['munawib_pembimbing_nama'],
                        $_SESSION['munawib_kegiatan_nama'],
                        $_SESSION['munawib_portal_tingkatan'],
                        $_SESSION['munawib_portal_jam_mulai'],
                        $_SESSION['munawib_portal_jam_selesai'],
                        $_SESSION['setoran_pembimbing_id']
                    );
                    set_flash('success', 'Kartu munawib dikenali.');
                    if ($loginDest === 'setoran') {
                        app_redirect('pembimbing/setoran_dashboard.php');
                    }
                    app_redirect('pembimbing/munawib_portal.php');
                }
                if (!$isValidLogin && ($mwLogin['message'] ?? '') !== '') {
                    set_flash('error', (string) $mwLogin['message']);
                    $mwFailUrl = app_url('login.php?peran=pembimbing&act=qr');
                    if ($loginDest === 'setoran') {
                        $mwFailUrl .= '&dest=setoran';
                    }
                    header('Location: ' . $mwFailUrl);
                    exit;
                }
            }
        }

        if (!$isValidLogin && $loginMethod !== 'qr') {
            $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin, foto_profil FROM users WHERE username = :username LIMIT 1');
            $statement->execute(['username' => $username]);
            $userRow = $statement->fetch();

            if ($userRow && password_verify($password, $userRow['password'])) {
                $isValidLogin = true;
                $userName = $userRow['nama'];
            }
        }
    }

    if (!$isValidLogin && $loginMethod !== 'qr' && app_is_local_dev() && $username === 'admin' && $password === 'admin123') {
        $isValidLogin = true;
    }

    if ($isValidLogin) {
        session_regenerate_id(true);
        $isSuperAdmin = (int) ($userRow['is_super_admin'] ?? 0) === 1;
        if ($username === 'admin') {
            $isSuperAdmin = true;
        }
        $userId = (int) ($userRow['id'] ?? 1);

        $sessionRole = (string) ($userRow['role'] ?? 'admin');
        $isRegisteredPembimbing = false;
        if ($peran === 'pembimbing' && !$isSuperAdmin && $username !== '' && table_exists($pdo, 'pembimbing')) {
            $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
                ? ' AND COALESCE(is_aktif, 1) = 1'
                : '';
            $chk = $pdo->prepare('SELECT 1 FROM pembimbing WHERE TRIM(nip) = :u' . $aktifSql . ' LIMIT 1');
            $chk->execute(['u' => $username]);
            $isRegisteredPembimbing = (bool) $chk->fetchColumn();
        }
        unset($_SESSION['munawib_id'], $_SESSION['munawib_tingkatan'], $_SESSION['munawib_pembimbing_id'], $_SESSION['setoran_pembimbing_id']);

        $pembimbingIdLogin = 0;
        if ($isRegisteredPembimbing && table_exists($pdo, 'pembimbing')) {
            $sessionRole = 'pembimbing';
            if ($userId > 0) {
                try {
                    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id AND COALESCE(is_super_admin, 0) = 0')
                        ->execute(['r' => 'pembimbing', 'id' => $userId]);
                } catch (PDOException $e) { /* abaikan */ }
            }
            require_once __DIR__ . '/helpers/pembimbing_dashboard.php';
            $pbLogin = pembimbing_dashboard_current_pembimbing($pdo, $userId);
            if (is_array($pbLogin) && empty($pbLogin['munawib_mode'])) {
                $pembimbingIdLogin = (int) ($pbLogin['id'] ?? 0);
            }
            if ($loginDest === 'setoran' && $pembimbingIdLogin > 0) {
                require_once __DIR__ . '/helpers/akademik_setoran.php';
                if (!akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pembimbingIdLogin)) {
                    set_flash('error', 'Akun pembimbing belum ditugaskan sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.');
                    header('Location: ' . app_url('login.php?peran=pembimbing&act=portal&dest=setoran'));
                    exit;
                }
            }
        }

        $_SESSION['user'] = [
            'id' => $userId,
            'nama' => $userName,
            'username' => $username,
            'role' => $sessionRole,
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
            'foto_profil' => trim((string) ($userRow['foto_profil'] ?? '')),
        ];
        if ($isRegisteredPembimbing && $userId > 0) {
            login_pembimbing_ensure_acl($pdo, $userId);
        }
        if ($pembimbingIdLogin > 0) {
            require_once __DIR__ . '/helpers/akademik_setoran.php';
            akademik_setoran_session_set_pembimbing_id($pembimbingIdLogin);
        }
        if ($loginMethod === 'qr' && isset($pbRow) && is_array($pbRow)) {
            $pbIdFromQr = (int) ($pbRow['pembimbing_id'] ?? 0);
            if ($pbIdFromQr > 0) {
                require_once __DIR__ . '/helpers/akademik_setoran.php';
                akademik_setoran_session_set_pembimbing_id($pbIdFromQr);
            }
        }
        set_flash('success', $loginMethod === 'qr' ? 'Scan kartu berhasil.' : 'Login berhasil.');
        if ($isRegisteredPembimbing) {
            app_redirect(login_pembimbing_post_login_path($loginDest));
        }
        app_post_login_redirect($pdo);
    }

    set_flash('error', $loginMethod === 'qr'
        ? 'Kartu QR tidak dikenali (pembimbing/munawib) atau tidak aktif.'
        : 'Username atau password salah.');
    $failAct = ($peran === 'pembimbing' && $loginMethod === 'qr') ? 'qr' : ($peran === 'pembimbing' ? 'portal' : '');
    $failUrl = app_url('login.php?peran=' . urlencode($peran) . ($failAct !== '' ? '&act=' . urlencode($failAct) : ''));
    if ($peran === 'pembimbing' && $loginDest === 'setoran') {
        $failUrl .= '&dest=setoran';
    }
    header('Location: ' . $failUrl);
    exit;
}

$pbAct = strtolower(trim((string) ($_GET['act'] ?? '')));
$pbDest = login_pembimbing_sanitize_dest($_GET['dest'] ?? '');
$pbDestQs = $pbDest !== '' ? '&dest=' . rawurlencode($pbDest) : '';
$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$peranLabel = match ($peran) {
    'pembimbing' => 'Pembimbing',
    'pengurus' => 'Pengurus / Admin',
    default => '',
};
$pbCardTitle = match (true) {
    $peran === 'pembimbing' && $pbAct === 'qr' && $pbDest === 'setoran' => 'Masuk untuk input setoran · scan',
    $peran === 'pembimbing' && $pbAct === 'portal' && $pbDest === 'setoran' => 'Masuk untuk input setoran',
    $peran === 'pembimbing' && $pbAct === 'setoran' => 'Input setoran hafalan',
    $peran === 'pembimbing' && $pbAct === 'qr' => 'Masuk portal · cukup scan',
    $peran === 'pembimbing' && $pbAct === 'portal' => 'NIP & password',
    $peran === 'pembimbing' => 'Masuk sebagai pembimbing',
    $peran === 'pengurus' => 'Masuk pengurus / admin',
    default => 'Pilih portal',
};

auth_portal_layout_begin([
    'title' => $peran === '' ? 'Portal Masuk' : 'Login ' . $peranLabel,
    'headline' => '',
    'welcome_salam' => '',
    'welcome_salam_waktu' => '',
    'welcome_tagline' => '',
    'formal_body' => $peran === '' ? '1' : '',
    'subtitle_mobile' => $peran === '' ? 'Pilih peran sesuai tugas Anda.' : '',
    'subtitle_desktop' => $peran === '' ? 'Pilih peran sesuai tugas Anda.' : '',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => '',
    'layout' => $peran === '' ? 'split' : 'stack',
    'shell_mod' => ($peran === 'pembimbing' && $pbAct === 'qr') ? 'pb_scan' : 'default',
    'card_title' => $pbCardTitle,
    'accent' => 'teal',
]);

$err = get_flash('error');
$ok = get_flash('success');
?>
                <?php if ($err): ?>
                    <?php if (!($peran === 'pembimbing' && in_array($pbAct, ['qr', 'portal'], true))): ?>
                    <div class="alert alert-danger py-2 small" role="alert"><?= htmlspecialchars($err) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($ok): ?>
                    <div class="alert alert-success py-2 small" role="status"><?= htmlspecialchars($ok) ?></div>
                <?php endif; ?>

                <?php if ($peran === ''): ?>
                    <?php require __DIR__ . '/includes/partials/auth_portal_role_grid.php'; ?>

                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="auth-portal-back">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali pilih portal
                    </a>

                    <?php if ($peran === 'pengurus'): ?>
                        <form method="post" class="auth-portal-form mt-2">
                            <input type="hidden" name="peran" value="pengurus">
                            <input type="hidden" name="login_method" value="password">
                            <div class="mb-3">
                                <label class="form-label" for="login-username">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                    <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="Username">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="login-password">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                    <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Password">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-auth-primary w-100">
                                <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk
                            </button>
                        </form>

                    <?php elseif ($peran === 'pembimbing'): ?>
                        <?php if ($pbAct === ''): ?>
                            <div class="login-pb-options">
                                <a href="<?= htmlspecialchars(app_href('/presensi/scan.php?portal=1')) ?>" class="login-pb-option login-pb-option--presensi">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-camera"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">Scan presensi</strong>
                                        <span class="login-pb-option__desc">Langsung buka kamera · catat kehadiran tanpa login</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=portal')) ?>" class="login-pb-option login-pb-option--portal">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">Masuk dengan NIP &amp; password</strong>
                                        <span class="login-pb-option__desc">Akun portal yang diberikan pengurus</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=qr')) ?>" class="login-pb-option login-pb-option--scan">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">Masuk portal</strong>
                                        <span class="login-pb-option__desc">Cukup scan kartu pembimbing / munawib</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=qr&dest=setoran')) ?>" class="login-pb-option login-pb-option--setoran">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-book-quran"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">Input setoran hafalan</strong>
                                        <span class="login-pb-option__desc">Scan kartu penerima setoran · masuk portal setoran</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                            </div>

                        <?php elseif ($pbAct === 'setoran'): ?>
                            <?php
                            header('Location: ' . app_href('/login.php?peran=pembimbing&act=qr&dest=setoran'));
                            exit;
                            ?>

                        <?php elseif ($pbAct === 'portal'): ?>
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="auth-portal-back">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali
                            </a>
                            <form method="post" class="auth-portal-form mt-2" autocomplete="on">
                                <input type="hidden" name="peran" value="pembimbing">
                                <input type="hidden" name="login_method" value="password">
                                <?php if ($pbDest === 'setoran'): ?>
                                <input type="hidden" name="login_dest" value="setoran">
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label" for="login-username">NIP / username</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                        <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="NIP pembimbing">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="login-password">Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                        <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Password">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-auth-primary w-100">
                                    <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> <?= $pbDest === 'setoran' ? 'Masuk &amp; input setoran' : 'Masuk ke portal' ?>
                                </button>
                            </form>
                            <?php if ($err): ?>
                            <div id="presensi-scan-result" class="visually-hidden" data-type="danger" data-speak="<?= htmlspecialchars($err) ?>" aria-hidden="true">
                                <span class="presensi-scan-result-text"><?= htmlspecialchars($err) ?></span>
                            </div>
                            <?php endif; ?>

                        <?php elseif ($pbAct === 'qr'): ?>
                            <div class="login-pb-scan presensi-scan-app mt-2">
                                <div class="login-pb-qr__head presensi-scan-top">
                                    <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i> Kembali</a>
                                    <span class="login-pb-qr__title">
                                        <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>
                                        <?= $pbDest === 'setoran' ? 'Masuk untuk setoran' : 'Masuk portal' ?>
                                    </span>
                                    <span id="login-pb-status" class="presensi-scan-status is-waiting">Menyiapkan…</span>
                                </div>
                                <div class="text-center py-2 d-none" id="login-pb-start-wrap">
                                    <button type="button" class="btn btn-primary btn-lg px-4" id="login-pb-start-scan">
                                        <i class="fa-solid fa-qrcode me-2"></i> Buka kamera
                                    </button>
                                </div>
                                <div class="login-pb-qr__viewport presensi-scan-viewport" id="login-pb-camera-wrap">
                                    <div id="login-pb-reader" aria-label="Kamera scan kartu pembimbing"></div>
                                    <div class="presensi-scan-frame" aria-hidden="true"><div class="presensi-scan-frame-box"></div></div>
                                    <div id="login-pb-error" class="presensi-scan-error d-none" role="alert">
                                        <div>
                                            <p class="fw-semibold mb-2" id="login-pb-error-text">Gagal membuka kamera</p>
                                            <button type="button" class="btn btn-light btn-sm" id="login-pb-retry">Coba lagi</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="presensi-scan-controls login-pb-qr__controls" id="login-pb-controls">
                                    <button type="button" class="btn-scan-ctl" id="login-pb-flip" title="Ganti kamera"><i class="fa-solid fa-camera-rotate"></i></button>
                                    <button type="button" class="btn-scan-ctl" id="login-pb-restart" title="Ulangi scan"><i class="fa-solid fa-rotate-right"></i></button>
                                </div>
                                <p class="login-pb-qr__hint small text-muted text-center mb-0" id="login-pb-hint"><?= $pbDest === 'setoran' ? 'Scan kartu pembimbing/munawib — masuk dashboard portal setoran.' : 'Arahkan QR kartu ke kotak hijau.' ?></p>
                                <form method="post" id="login-pb-qr-form" class="visually-hidden" autocomplete="off">
                                    <input type="hidden" name="peran" value="pembimbing">
                                    <input type="hidden" name="login_method" value="qr">
                                    <?php if ($pbDest === 'setoran'): ?>
                                    <input type="hidden" name="login_dest" value="setoran">
                                    <?php endif; ?>
                                    <input type="text" name="qr_code" id="login-pb-qr-code" readonly>
                                </form>
                            </div>
                            <p class="login-pb-alt small text-muted text-center mt-3 mb-0">
                                <a href="<?= htmlspecialchars(app_href('/presensi/scan.php?portal=1')) ?>">Scan presensi saja</a>
                                · <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=portal' . $pbDestQs)) ?>">masuk dengan NIP &amp; password</a>
                            </p>
                            <?php if ($err): ?>
                            <div id="presensi-scan-result" class="visually-hidden" data-type="danger" data-speak="<?= htmlspecialchars($err) ?>" aria-hidden="true">
                                <span class="presensi-scan-result-text"><?= htmlspecialchars($err) ?></span>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <?php
                            header('Location: ' . app_href('/login.php?peran=pembimbing'));
                            exit;
                            ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($peran === 'pembimbing' && $pbAct === 'qr'): ?>
                <link href="<?= htmlspecialchars(app_url('assets/css/presensi-scan.css')) ?>" rel="stylesheet">
                <style>
                    .login-pb-options { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 0.75rem; }
                    .login-pb-option { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 0.85rem; border-radius: 14px; border: 1px solid #e2e8f0; border-left: 4px solid #0f766e; background: #fff; text-decoration: none; color: #0f172a; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
                    .login-pb-option--scan {
                        border: none;
                        background: linear-gradient(135deg, #4338ca 0%, #6366f1 55%, #818cf8 100%);
                        color: #fff;
                        box-shadow: 0 8px 22px rgba(79, 70, 229, 0.38);
                    }
                    .login-pb-option--scan .login-pb-option__title,
                    .login-pb-option--scan .login-pb-option__desc,
                    .login-pb-option--scan .login-pb-option__go { color: #fff; }
                    .login-pb-option--scan .login-pb-option__desc { opacity: 0.9; }
                    .login-pb-option--scan .login-pb-option__icon { background: rgba(255, 255, 255, 0.22); }
                    .login-pb-option--presensi {
                        border: none;
                        background: linear-gradient(135deg, #047857 0%, #059669 55%, #10b981 100%);
                        color: #fff;
                        box-shadow: 0 8px 22px rgba(5, 150, 105, 0.35);
                    }
                    .login-pb-option--presensi .login-pb-option__title,
                    .login-pb-option--presensi .login-pb-option__desc,
                    .login-pb-option--presensi .login-pb-option__go { color: #fff; }
                    .login-pb-option--presensi .login-pb-option__desc { opacity: 0.92; }
                    .login-pb-option--presensi .login-pb-option__icon { background: rgba(255, 255, 255, 0.22); }
                    .login-pb-option--portal { border-left-color: #0f766e; }
                    .login-pb-option--setoran {
                        border-left-color: #b45309;
                        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
                    }
                    .login-pb-option--setoran .login-pb-option__icon { background: linear-gradient(135deg, #b45309, #d97706); }
                    .login-pb-option__icon { flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff; }
                    .login-pb-option--scan .login-pb-option__icon { background: linear-gradient(135deg, #4338ca, #6366f1); }
                    .login-pb-option--portal .login-pb-option__icon { background: linear-gradient(135deg, #0f766e, #0891b2); }
                    .login-pb-option__text { flex: 1 1 auto; min-width: 0; }
                    .login-pb-option__title { display: block; font-size: 0.92rem; font-weight: 800; }
                    .login-pb-option__desc { display: block; margin-top: 0.2rem; font-size: 0.78rem; color: #64748b; }
                    .login-pb-option__go { align-self: center; color: #94a3b8; }
                    #login-pb-start-scan {
                        background: linear-gradient(135deg, #4338ca 0%, #6366f1 55%, #818cf8 100%);
                        border: none;
                        box-shadow: 0 8px 22px rgba(79, 70, 229, 0.38);
                    }
                    #login-pb-start-scan:hover { filter: brightness(1.05); }
                </style>
                <?php require_once __DIR__ . '/helpers/app_vendor.php'; require __DIR__ . '/includes/partials/app_html5_qrcode_script.php'; ?>
                <script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-feedback.js')) ?>"></script>
                <script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
                <script>
                (function () {
                    if (typeof window.PresensiScanCamera === 'undefined') return;
                    var formEl = document.getElementById('login-pb-qr-form');
                    var inputEl = document.getElementById('login-pb-qr-code');
                    var startBtn = document.getElementById('login-pb-start-scan');
                    var startWrap = document.getElementById('login-pb-start-wrap');
                    var cameraWrap = document.getElementById('login-pb-camera-wrap');
                    var hintEl = document.getElementById('login-pb-hint');
                    var controlsEl = document.getElementById('login-pb-controls');
                    var statusEl = document.getElementById('login-pb-status');
                    if (!formEl || !inputEl) return;
                    var submitted = false;
                    var scanner = null;
                    function showCameraUi() {
                        if (startWrap) startWrap.classList.add('d-none');
                        if (cameraWrap) cameraWrap.classList.remove('d-none');
                        if (hintEl) hintEl.classList.remove('d-none');
                        if (controlsEl) controlsEl.classList.remove('d-none');
                        if (statusEl) {
                            statusEl.classList.remove('d-none');
                            statusEl.textContent = 'Menyiapkan kamera…';
                            statusEl.className = 'presensi-scan-status is-waiting';
                        }
                    }
                    function bootScanner() {
                        if (scanner) {
                            try { scanner.restart(); } catch (e) {}
                            return;
                        }
                        scanner = new window.PresensiScanCamera({
                            readerId: 'login-pb-reader',
                            statusEl: statusEl,
                            errorPanel: document.getElementById('login-pb-error'),
                            errorText: document.getElementById('login-pb-error-text'),
                            btnFlip: document.getElementById('login-pb-flip'),
                            btnRestart: document.getElementById('login-pb-restart'),
                            btnRetry: document.getElementById('login-pb-retry'),
                            onSubmit: function (code) {
                                if (submitted) return;
                                submitted = true;
                                inputEl.value = code;
                                try { scanner.stop(); } catch (e) {}
                                if (window.PresensiScanFeedback && typeof window.PresensiScanFeedback.show === 'function') {
                                    window.PresensiScanFeedback.show('success', 'Kartu terbaca, memproses masuk…');
                                    setTimeout(function () { formEl.submit(); }, 550);
                                } else {
                                    formEl.submit();
                                }
                            }
                        });
                        scanner.init();
                    }
                    if (startBtn) {
                        startBtn.addEventListener('click', function () {
                            showCameraUi();
                            bootScanner();
                        });
                    }
                    showCameraUi();
                    bootScanner();
                })();
                </script>
                <?php elseif ($peran === 'pembimbing' && $pbAct === ''): ?>
                <style>
                    .login-pb-options { display: flex; flex-direction: column; gap: 0.75rem; }
                    .login-pb-option { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 0.85rem; border-radius: 14px; border: 1px solid #e2e8f0; border-left: 4px solid #0f766e; background: #fff; text-decoration: none; color: #0f172a; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
                    .login-pb-option--scan {
                        border: none;
                        background: linear-gradient(135deg, #4338ca 0%, #6366f1 55%, #818cf8 100%);
                        color: #fff;
                        box-shadow: 0 8px 22px rgba(79, 70, 229, 0.38);
                    }
                    .login-pb-option--scan .login-pb-option__title,
                    .login-pb-option--scan .login-pb-option__desc,
                    .login-pb-option--scan .login-pb-option__go { color: #fff; }
                    .login-pb-option--scan .login-pb-option__desc { opacity: 0.9; }
                    .login-pb-option--scan .login-pb-option__icon { background: rgba(255, 255, 255, 0.22); }
                    .login-pb-option--presensi {
                        border: none;
                        background: linear-gradient(135deg, #047857 0%, #059669 55%, #10b981 100%);
                        color: #fff;
                        box-shadow: 0 8px 22px rgba(5, 150, 105, 0.35);
                    }
                    .login-pb-option--presensi .login-pb-option__title,
                    .login-pb-option--presensi .login-pb-option__desc,
                    .login-pb-option--presensi .login-pb-option__go { color: #fff; }
                    .login-pb-option--presensi .login-pb-option__desc { opacity: 0.92; }
                    .login-pb-option--presensi .login-pb-option__icon { background: rgba(255, 255, 255, 0.22); }
                    .login-pb-option--portal { border-left-color: #0f766e; }
                    .login-pb-option--setoran {
                        border-left-color: #b45309;
                        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
                    }
                    .login-pb-option--setoran .login-pb-option__icon { background: linear-gradient(135deg, #b45309, #d97706); }
                    .login-pb-option__icon { flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff; }
                    .login-pb-option--scan .login-pb-option__icon { background: linear-gradient(135deg, #4338ca, #6366f1); }
                    .login-pb-option--portal .login-pb-option__icon { background: linear-gradient(135deg, #0f766e, #0891b2); }
                    .login-pb-option__text { flex: 1 1 auto; min-width: 0; }
                    .login-pb-option__title { display: block; font-size: 0.92rem; font-weight: 800; }
                    .login-pb-option__desc { display: block; margin-top: 0.2rem; font-size: 0.78rem; color: #64748b; }
                    .login-pb-option__go { align-self: center; color: #94a3b8; }
                </style>
                <?php endif; ?>

<?php if ($peran === 'pembimbing' && $pbAct === 'portal' && $err): ?>
<link href="<?= htmlspecialchars(app_url('assets/css/presensi-scan.css')) ?>" rel="stylesheet">
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-feedback.js')) ?>"></script>
<?php endif; ?>

<?php
auth_portal_layout_end();
