<?php

declare(strict_types=1);

require_once __DIR__ . '/login_pembimbing.php';
require_once __DIR__ . '/munawib.php';
require_once __DIR__ . '/user_profil.php';

/**
 * Autentikasi masuk portal via scan kartu pembimbing/munawib.
 *
 * @return array{ok:bool, redirect:?string, error:?string, flash_success:?string}
 */
function login_qr_authenticate(PDO $pdo, string $qrCode, string $loginDest = ''): array
{
    $qrCode = trim($qrCode);
    $loginDest = login_pembimbing_sanitize_dest($loginDest);
    $fail = static function (string $error) use ($loginDest): array {
        return [
            'ok' => false,
            'redirect' => null,
            'error' => $error,
            'flash_success' => null,
        ];
    };

    if ($qrCode === '') {
        return $fail('Kartu QR tidak dikenali (pembimbing/munawib) atau tidak aktif.');
    }

    user_profil_ensure_schema($pdo);
    if (empty($_SESSION['users_role_enum_v2'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
        } catch (PDOException $e) { /* abaikan */ }
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
        $_SESSION['users_role_enum_v2'] = 1;
    }

    $pbRow = null;
    $userRow = null;
    $username = '';
    $userName = 'Administrator';

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
        $pbRow = $stmtPb->fetch(PDO::FETCH_ASSOC) ?: null;

        if (is_array($pbRow)) {
            $pbIdQr = (int) ($pbRow['pembimbing_id'] ?? 0);
            if ($loginDest === 'setoran' && $pbIdQr > 0) {
                require_once __DIR__ . '/akademik_setoran.php';
                if (!akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pbIdQr)) {
                    return $fail('Kartu pembimbing dikenali, tetapi belum ditugaskan sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.');
                }
            }

            if (table_exists($pdo, 'users')) {
                $stmtUser = $pdo->prepare('
                    SELECT id, nama, username, role, is_super_admin, foto_profil
                    FROM users
                    WHERE TRIM(username) = :nip
                    LIMIT 1
                ');
                $stmtUser->execute(['nip' => trim((string) $pbRow['nip'])]);
                $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($userRow) {
                $username = (string) $userRow['username'];
                $userName = (string) ($userRow['nama'] ?? $pbRow['nama_pembimbing']);
            } else {
                return $fail('Kartu QR dikenali (' . (string) $pbRow['nama_pembimbing'] . '), tetapi akun login pembimbing belum dibuat. Hubungi pengurus.');
            }
        }
    }

    if (!is_array($userRow)) {
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
            app_menu_pack_invalidate();

            $redirect = $loginDest === 'setoran'
                ? 'pembimbing/setoran_dashboard.php'
                : 'pembimbing/munawib_portal.php';

            return [
                'ok' => true,
                'redirect' => $redirect,
                'error' => null,
                'flash_success' => 'Kartu munawib dikenali.',
            ];
        }

        if (($mwLogin['message'] ?? '') !== '') {
            return $fail((string) $mwLogin['message']);
        }

        return $fail('Kartu QR tidak dikenali (pembimbing/munawib) atau tidak aktif.');
    }

    session_regenerate_id(true);
    $isSuperAdmin = (int) ($userRow['is_super_admin'] ?? 0) === 1;
    if ($username === 'admin') {
        $isSuperAdmin = true;
    }
    $userId = (int) ($userRow['id'] ?? 0);
    if ($userId <= 0) {
        return $fail('Akun login tidak valid. Hubungi admin.');
    }

    $sessionRole = (string) ($userRow['role'] ?? 'admin');
    $isRegisteredPembimbing = false;
    if (!$isSuperAdmin && $username !== '' && table_exists($pdo, 'pembimbing')) {
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
        try {
            $pdo->prepare('UPDATE users SET role = :r WHERE id = :id AND COALESCE(is_super_admin, 0) = 0')
                ->execute(['r' => 'pembimbing', 'id' => $userId]);
        } catch (PDOException $e) { /* abaikan */ }

        require_once __DIR__ . '/pembimbing_dashboard.php';
        $pbLogin = pembimbing_dashboard_current_pembimbing($pdo, $userId);
        if (is_array($pbLogin) && empty($pbLogin['munawib_mode'])) {
            $pembimbingIdLogin = (int) ($pbLogin['id'] ?? 0);
        }
        if ($loginDest === 'setoran' && $pembimbingIdLogin > 0) {
            require_once __DIR__ . '/akademik_setoran.php';
            if (!akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pembimbingIdLogin)) {
                return $fail('Akun pembimbing belum ditugaskan sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.');
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
    } elseif ($userId > 0 && in_array($sessionRole, ['admin', 'pengurus', 'petugas_absensi'], true)) {
        require_once __DIR__ . '/user_permissions.php';
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
        require_once __DIR__ . '/akademik_setoran.php';
        akademik_setoran_session_set_pembimbing_id($pembimbingIdLogin);
    } elseif (is_array($pbRow)) {
        $pbIdFromQr = (int) ($pbRow['pembimbing_id'] ?? 0);
        if ($pbIdFromQr > 0) {
            require_once __DIR__ . '/akademik_setoran.php';
            akademik_setoran_session_set_pembimbing_id($pbIdFromQr);
        }
    }

    $redirect = $isRegisteredPembimbing
        ? login_pembimbing_post_login_path($loginDest)
        : null;

    return [
        'ok' => true,
        'redirect' => $redirect,
        'error' => null,
        'flash_success' => 'Scan kartu berhasil.',
    ];
}
