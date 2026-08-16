<?php



require_once __DIR__ . '/config/database.php';

require_once __DIR__ . '/config/session.php';

require_once __DIR__ . '/helpers/app.php';

require_once __DIR__ . '/helpers/app_path.php';

require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/helpers/user_profil.php';

require_once __DIR__ . '/helpers/login_pembimbing.php';

require_once __DIR__ . '/helpers/login_rate_limit.php';

require_once __DIR__ . '/helpers/login_qr_auth.php';

require_once __DIR__ . '/helpers/local_dev.php';

require_once __DIR__ . '/includes/auth_portal_layout.php';



if ($pdo instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {

    ensure_pondok_settings_defaults($pdo);

}



$loginDest = login_pembimbing_sanitize_dest($_GET['dest'] ?? $_POST['login_dest'] ?? '');

$scanMode = trim((string) ($_GET['scan'] ?? '')) === '1'

    || trim((string) ($_POST['scan'] ?? '')) === '1';



if (isset($_SESSION['user']) && $pdo instanceof PDO) {

    if ($loginDest === 'setoran') {

        require_once __DIR__ . '/helpers/akademik_setoran.php';

        $portalGate = akademik_setoran_portal_access_status($pdo);

        if ($portalGate['ok']) {

            app_redirect('pembimbing/setoran_dashboard.php');

        }

        set_flash('error', akademik_setoran_portal_denial_message($portalGate));

        header('Location: ' . app_url('login.php?dest=setoran' . ($scanMode ? '&scan=1' : '')));

        exit;

    }

    app_post_login_redirect($pdo);

}



$legacyPeran = strtolower(trim((string) ($_GET['peran'] ?? '')));

if ($legacyPeran === 'wali') {

    app_redirect('wali/login.php');

}

if ($legacyPeran === 'petugas') {

    app_redirect('presensi/login.php');

}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $loginMethod = strtolower(trim((string) ($_POST['login_method'] ?? 'password')));



    if ($loginMethod === 'qr' && $pdo instanceof PDO) {

        $qrCode = trim((string) ($_POST['qr_code'] ?? ''));

        $loginDest = login_pembimbing_sanitize_dest($_POST['login_dest'] ?? '');

        $qrFailUrl = app_url('login.php?scan=1' . ($loginDest === 'setoran' ? '&dest=setoran' : ''));



        $qrResult = login_qr_authenticate($pdo, $qrCode, $loginDest);

        if ($qrResult['ok']) {

            if (($qrResult['flash_success'] ?? '') !== '') {

                set_flash('success', (string) $qrResult['flash_success']);

            }

            if (($qrResult['redirect'] ?? '') !== '') {

                app_redirect((string) $qrResult['redirect']);

            }

            app_post_login_redirect($pdo);

        }



        set_flash('error', (string) ($qrResult['error'] ?? 'Kartu QR tidak dikenali.'));

        header('Location: ' . $qrFailUrl);

        exit;

    }



    $username = trim((string) ($_POST['username'] ?? ''));

    $password = (string) ($_POST['password'] ?? '');

    $loginDest = login_pembimbing_sanitize_dest($_POST['login_dest'] ?? '');

    $clientIp = login_rate_limit_client_ip();

    $failRedirect = app_url('login.php' . ($loginDest === 'setoran' ? '?dest=setoran' : ''));



    if ($pdo instanceof PDO && login_rate_limit_is_blocked($pdo, $clientIp, $username)) {

        set_flash('error', 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.');

        header('Location: ' . $failRedirect);

        exit;

    }



    $isValidLogin = false;

    $userName = 'Administrator';

    $userRow = null;



    if ($pdo instanceof PDO && table_exists($pdo, 'users')) {

        user_profil_ensure_schema($pdo);

        if (empty($_SESSION['users_role_enum_v3'])) {

            require_once __DIR__ . '/helpers/cashless_koperasi.php';
            cashless_koperasi_users_ensure_schema($pdo);

            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");

            $_SESSION['users_role_enum_v3'] = 1;

        }



        $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin, foto_profil, koperasi_id FROM users WHERE username = :username LIMIT 1');

        $statement->execute(['username' => $username]);

        $userRow = $statement->fetch();



        if ($userRow && auth_verify_user_password($password, (string) ($userRow['password'] ?? ''))) {

            $isValidLogin = true;

            $userName = (string) $userRow['nama'];

        }

    }



    if (!$isValidLogin && app_is_local_dev() && $username === 'admin' && $password === 'admin123' && $pdo instanceof PDO) {

        $bootAdmin = local_dev_ensure_admin_user($pdo);

        if (is_array($bootAdmin)) {

            $userRow = $bootAdmin;

            $userName = (string) ($bootAdmin['nama'] ?? 'Administrator');

            $isValidLogin = true;

        }

    }



    if ($isValidLogin && is_array($userRow)) {

        session_regenerate_id(true);

        $isSuperAdmin = (int) ($userRow['is_super_admin'] ?? 0) === 1;

        if ($username === 'admin') {

            $isSuperAdmin = true;

        }

        $userId = (int) ($userRow['id'] ?? 0);

        if ($userId <= 0) {

            if ($pdo instanceof PDO) {

                login_rate_limit_record_failure($pdo, $clientIp, $username);

            }

            set_flash('error', 'Identitas atau password salah.');

            header('Location: ' . $failRedirect);

            exit;

        }



        $sessionRole = (string) ($userRow['role'] ?? 'admin');

        $isRegisteredPembimbing = false;

        if (!$isSuperAdmin && $username !== '' && $pdo instanceof PDO && table_exists($pdo, 'pembimbing')) {

            $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')

                ? ' AND COALESCE(is_aktif, 1) = 1'

                : '';

            $chk = $pdo->prepare('SELECT 1 FROM pembimbing WHERE TRIM(nip) = :u' . $aktifSql . ' LIMIT 1');

            $chk->execute(['u' => $username]);

            $isRegisteredPembimbing = (bool) $chk->fetchColumn();

        }

        unset($_SESSION['munawib_id'], $_SESSION['munawib_tingkatan'], $_SESSION['munawib_pembimbing_id'], $_SESSION['setoran_pembimbing_id']);



        $pembimbingIdLogin = 0;

        if ($isRegisteredPembimbing && $pdo instanceof PDO && table_exists($pdo, 'pembimbing')) {

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

                    header('Location: ' . app_url('login.php?dest=setoran'));

                    exit;

                }

            }

        }



        $kopIdLogin = (int) ($userRow['koperasi_id'] ?? 0);
        $_SESSION['user'] = [

            'id' => $userId,

            'nama' => $userName,

            'username' => $username,

            'role' => $sessionRole,

            'is_super_admin' => $isSuperAdmin ? 1 : 0,

            'foto_profil' => trim((string) ($userRow['foto_profil'] ?? '')),

            'koperasi_id' => ($sessionRole === 'petugas_koperasi' && $kopIdLogin >= 1 && $kopIdLogin <= 3) ? $kopIdLogin : null,

        ];

        if ($sessionRole === 'petugas_koperasi' && $kopIdLogin >= 1 && $kopIdLogin <= 3) {
            require_once __DIR__ . '/helpers/cashless_koperasi.php';
            cashless_koperasi_login_from_user($pdo, $kopIdLogin);
        }

        if ($isRegisteredPembimbing && $userId > 0 && $pdo instanceof PDO) {

            login_pembimbing_ensure_acl($pdo, $userId);

        } elseif ($userId > 0 && $pdo instanceof PDO && in_array($sessionRole, ['admin', 'pengurus', 'petugas_absensi'], true)) {

            require_once __DIR__ . '/helpers/user_permissions.php';

            user_acl_ensure_legacy_configured($pdo, $userId);

            if (!user_acl_is_explicitly_configured($pdo, $userId)) {

                user_permission_ensure_role_defaults($pdo, $userId, $sessionRole);

            }

        }

        if (function_exists('app_acl_session_cache_clear')) {

            app_acl_session_cache_clear($userId);

        }

        app_menu_pack_invalidate();

        if ($pembimbingIdLogin > 0) {

            require_once __DIR__ . '/helpers/akademik_setoran.php';

            akademik_setoran_session_set_pembimbing_id($pembimbingIdLogin);

        }

        if ($pdo instanceof PDO) {

            login_rate_limit_clear($pdo, $clientIp, $username);

        }

        set_flash('success', 'Login berhasil.');

        if ($isRegisteredPembimbing) {

            app_redirect(login_pembimbing_post_login_path($loginDest));

        }

        app_post_login_redirect($pdo);

    }



    if ($pdo instanceof PDO) {

        login_rate_limit_record_failure($pdo, $clientIp, $username);

    }

    set_flash('error', 'Identitas atau password salah.');

    header('Location: ' . $failRedirect);

    exit;

}



$brandNama = auth_portal_brand_nama($pdo);

$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));

$loginScanDest = $loginDest === 'setoran' ? 'setoran' : '';



if ($scanMode) {

    $cardTitle = 'Multi Scan';

    auth_portal_layout_begin([

        'title' => 'Multi Scan',

        'headline' => '',

        'welcome_salam' => '',

        'welcome_salam_waktu' => '',

        'welcome_tagline' => '',

        'formal_body' => '',

        'subtitle_mobile' => '',

        'subtitle_desktop' => '',

        'kicker' => $jenisPendidikan,

        'nama_ponpes' => $brandNama,

        'logo_url' => '',

        'layout' => 'stack',

        'shell_mod' => 'pb_scan',

        'card_title' => $cardTitle,

        'accent' => 'teal',

    ]);

    $err = get_flash('error');

    require __DIR__ . '/includes/partials/login_scan_kegiatan.php';

    auth_portal_layout_end();

    return;

}



$cardSubtitle = $loginDest === 'setoran' ? 'Masuk untuk input setoran' : 'Masuk untuk melanjutkan';

$scanKegiatanHref = app_href('/login.php?scan=1' . ($loginDest === 'setoran' ? '&dest=setoran' : ''));



auth_portal_layout_begin([

    'title' => 'Portal Masuk',

    'headline' => '',

    'welcome_salam' => '',

    'welcome_salam_waktu' => '',

    'welcome_tagline' => '',

    'formal_body' => '',

    'subtitle_mobile' => '',

    'subtitle_desktop' => '',

    'kicker' => $jenisPendidikan,

    'nama_ponpes' => $brandNama,

    'logo_url' => '',

    'layout' => 'center_card',

    'card_style' => 'center',

    'card_subtitle' => $cardSubtitle,

    'show_alt_nav' => false,

    'shell_mod' => 'default',

    'card_title' => '',

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



                <form method="post" class="auth-portal-form auth-portal-form--center" autocomplete="on">

                    <?php if ($loginDest === 'setoran'): ?>

                    <input type="hidden" name="login_dest" value="setoran">

                    <?php endif; ?>

                    <div class="auth-portal-field-group auth-portal-field-group--center">

                        <div class="auth-portal-field auth-portal-field--center">

                            <input type="text" name="username" id="login-username" class="auth-portal-field__input auth-portal-field__input--center" required autocomplete="username" placeholder="Username, NIP, atau NIS">

                        </div>

                    </div>

                    <div class="auth-portal-field-group auth-portal-field-group--center">

                        <div class="auth-portal-field auth-portal-field--center">

                            <input type="password" name="password" id="login-password" class="auth-portal-field__input auth-portal-field__input--center" required autocomplete="current-password" placeholder="Password">

                        </div>

                    </div>

                    <button type="submit" class="btn btn-auth-center w-100">

                        <?= $loginDest === 'setoran' ? 'Masuk &amp; input setoran' : 'Masuk' ?>

                    </button>

                </form>



                <div class="auth-portal-divider" aria-hidden="true"><span>atau</span></div>



                <a href="<?= htmlspecialchars($scanKegiatanHref) ?>" class="btn btn-auth-scan w-100">

                    <i class="fa-solid fa-qrcode" aria-hidden="true"></i>

                    Multi Scan

                </a>

                <p class="auth-portal-scan-hint">Absensi &amp; portal otomatis</p>



                <p class="auth-portal-offline-note">

                    <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>

                    Scan tetap tersimpan meski tanpa internet

                </p>



<?php

auth_portal_layout_end();

