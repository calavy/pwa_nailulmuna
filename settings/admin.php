<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/user_profil.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

require_roles(['admin']);
require_super_admin();

if (!table_exists($pdo, 'users')) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(100) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM("admin","pengurus") NOT NULL DEFAULT "pengurus",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
}
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi','kiai') NOT NULL DEFAULT 'pengurus'");
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
user_profil_ensure_schema($pdo);
$pdo->exec('
    CREATE TABLE IF NOT EXISTS user_access_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        permission_key VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_permission (user_id, permission_key),
        CONSTRAINT fk_user_access_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
');

migrate_keuangan_permissions_split($pdo);
migrate_pkpps_permissions_split($pdo);
$permissionGroups = user_permission_groups();
$permissionOptions = user_permission_flat_options();

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

/**
 * Cek apakah target user adalah akun pembimbing. Kalau ya, halaman ini
 * tidak boleh menyentuh datanya — admin diarahkan ke halaman data pembimbing.
 */
$settings_admin_is_pembimbing_account = static function (PDO $pdo, int $targetUserId): bool {
    if ($targetUserId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $targetUserId]);
    $role = (string) ($st->fetchColumn() ?: '');
    return strtolower($role) === 'pembimbing';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'create_user'));
    $targetUserIdForGuard = (int) ($_POST['target_user_id'] ?? 0);

    if (
        $targetUserIdForGuard > 0
        && in_array($action, ['save_access', 'update_user', 'delete_user'], true)
        && $settings_admin_is_pembimbing_account($pdo, $targetUserIdForGuard)
    ) {
        set_flash('error', 'Akun pembimbing dikelola di halaman Data Pembimbing, bukan di sini.');
        header('Location: ' . app_href('/pembimbing/index.php'));
        exit;
    }

    if ($action === 'save_access') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $targetRoleStmt = $pdo->prepare('SELECT role, is_super_admin FROM users WHERE id = :id LIMIT 1');
        $targetRoleStmt->execute(['id' => $targetUserId]);
        $targetUser = $targetRoleStmt->fetch();
        if ($targetUser && (int) $targetUser['is_super_admin'] !== 1) {
            $pdo->prepare('DELETE FROM user_access_permissions WHERE user_id = :user_id')->execute(['user_id' => $targetUserId]);
            $selected = $_POST['permissions'] ?? [];
            if (is_array($selected)) {
                $insertPerm = $pdo->prepare('INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:user_id, :permission_key)');
                foreach ($selected as $key) {
                    $permissionKey = trim((string) $key);
                    if (isset($permissionOptions[$permissionKey])) {
                        $insertPerm->execute([
                            'user_id' => $targetUserId,
                            'permission_key' => $permissionKey,
                        ]);
                    }
                }
            }
            app_acl_session_cache_clear($targetUserId);
            user_acl_mark_configured($pdo, $targetUserId);
            set_flash('success', 'Hak akses user berhasil diperbarui. Minta user tersebut refresh halaman atau login ulang.');
        } else {
            set_flash('error', 'Hak akses hanya bisa diatur untuk user non super admin.');
        }
        header('Location: ' . app_rewrite_internal_url('/settings/admin.php?akses=' . $targetUserId));
        exit;
    }

    if ($action === 'update_user') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'pengurus');
        $isSuperAdmin = isset($_POST['is_super_admin']) ? 1 : 0;
        if (!in_array($role, ['admin', 'pengurus', 'petugas_absensi', 'kiai'], true)) {
            $role = 'pengurus';
        }

        $stmt = $pdo->prepare('SELECT id, role, is_super_admin FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $targetUserId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            set_flash('error', 'User tidak ditemukan.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }
        if ($nama === '' || $username === '') {
            set_flash('error', 'Nama dan username tidak boleh kosong.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }

        $superCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1')->fetchColumn();
        if ((int) $existing['is_super_admin'] === 1 && $isSuperAdmin === 0 && $superCount <= 1) {
            set_flash('error', 'Tidak bisa menonaktifkan super admin terakhir.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }

        $checkUname = $pdo->prepare('SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1');
        $checkUname->execute(['u' => $username, 'id' => $targetUserId]);
        if ($checkUname->fetch()) {
            set_flash('error', 'Username sudah dipakai user lain.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }

        $fotoStmt = $pdo->prepare('SELECT foto_profil FROM users WHERE id = :id LIMIT 1');
        $fotoStmt->execute(['id' => $targetUserId]);
        $fotoOld = trim((string) ($fotoStmt->fetchColumn() ?: ''));

        if (isset($_FILES['foto_profil']) && is_array($_FILES['foto_profil'])) {
            $uploadResult = user_profil_handle_upload($_FILES['foto_profil'], $fotoOld !== '' ? $fotoOld : null);
            if (!$uploadResult['ok']) {
                set_flash('error', (string) ($uploadResult['error'] ?? 'Upload foto gagal.'));
                header('Location: ' . app_href('/settings/admin.php'));
                exit;
            }
            if (isset($uploadResult['path'])) {
                $pdo->prepare('UPDATE users SET foto_profil = :f WHERE id = :id')->execute([
                    'f' => $uploadResult['path'],
                    'id' => $targetUserId,
                ]);
                user_profil_sync_session($pdo, $targetUserId);
            }
        }

        if ($password !== '') {
            $upd = $pdo->prepare('UPDATE users SET nama = :nama, username = :username, password = :password, role = :role, is_super_admin = :is_super_admin WHERE id = :id');
            $upd->execute([
                'nama' => $nama,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'is_super_admin' => $isSuperAdmin,
                'id' => $targetUserId,
            ]);
        } else {
            $upd = $pdo->prepare('UPDATE users SET nama = :nama, username = :username, role = :role, is_super_admin = :is_super_admin WHERE id = :id');
            $upd->execute([
                'nama' => $nama,
                'username' => $username,
                'role' => $role,
                'is_super_admin' => $isSuperAdmin,
                'id' => $targetUserId,
            ]);
        }

        if ($isSuperAdmin === 1) {
            $pdo->prepare('DELETE FROM user_access_permissions WHERE user_id = :id')->execute(['id' => $targetUserId]);
        }

        set_flash('success', 'Data user berhasil diperbarui.');
        header('Location: ' . app_href('/settings/admin.php'));
        exit;
    }

    if ($action === 'delete_user') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        if ($targetUserId === $currentUserId) {
            set_flash('error', 'Anda tidak bisa menghapus akun sendiri.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, is_super_admin FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $targetUserId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            set_flash('error', 'User tidak ditemukan.');
            header('Location: ' . app_href('/settings/admin.php'));
            exit;
        }
        if ((int) $existing['is_super_admin'] === 1) {
            $superCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1')->fetchColumn();
            if ($superCount <= 1) {
                set_flash('error', 'Tidak bisa menghapus super admin terakhir.');
                header('Location: ' . app_href('/settings/admin.php'));
                exit;
            }
        }
        $fotoStmt = $pdo->prepare('SELECT foto_profil FROM users WHERE id = :id LIMIT 1');
        $fotoStmt->execute(['id' => $targetUserId]);
        $fotoOld = trim((string) ($fotoStmt->fetchColumn() ?: ''));
        user_profil_delete_file($fotoOld !== '' ? $fotoOld : null);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $targetUserId]);
        set_flash('success', 'User berhasil dihapus.');
        header('Location: ' . app_href('/settings/admin.php'));
        exit;
    }

    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'pengurus';
    $isSuperAdmin = isset($_POST['is_super_admin']) ? 1 : 0;
    if ($nama !== '' && $username !== '' && $password !== '') {
        $checkUname = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $checkUname->execute(['u' => $username]);
        if ($checkUname->fetch()) {
            set_flash('error', 'Username sudah dipakai. Pilih username lain.');
        } else {
            $insert = $pdo->prepare('
                INSERT INTO users (nama, username, password, role, is_super_admin)
                VALUES (:nama, :username, :password, :role, :is_super_admin)
            ');
            $insert->execute([
                'nama' => $nama,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => in_array($role, ['admin', 'pengurus', 'petugas_absensi', 'kiai'], true) ? $role : 'pengurus',
                'is_super_admin' => $isSuperAdmin,
            ]);
            $newUserId = (int) $pdo->lastInsertId();
            if ($newUserId > 0 && isset($_FILES['foto_profil']) && is_array($_FILES['foto_profil'])) {
                $uploadResult = user_profil_handle_upload($_FILES['foto_profil']);
                if (!$uploadResult['ok']) {
                    set_flash('error', 'User dibuat, tetapi foto gagal: ' . (string) ($uploadResult['error'] ?? ''));
                } elseif (isset($uploadResult['path'])) {
                    $pdo->prepare('UPDATE users SET foto_profil = :f WHERE id = :id')->execute([
                        'f' => $uploadResult['path'],
                        'id' => $newUserId,
                    ]);
                }
            }
            set_flash('success', 'User baru berhasil ditambahkan.');
        }
    } else {
        set_flash('error', 'Nama, username, dan password wajib diisi.');
    }
    header('Location: ' . app_href('/settings/admin.php'));
    exit;
}

// Akun pembimbing tidak ditampilkan/diolah dari halaman ini — pengelolaannya
// dipusatkan di /pembimbing/index.php & /pembimbing/edit.php agar admin
// tidak perlu pindah-pindah halaman ketika membuat akun pembimbing.
$users = $pdo->query("SELECT id, nama, username, role, is_super_admin, foto_profil, created_at FROM users WHERE COALESCE(role, '') <> 'pembimbing' ORDER BY id DESC")->fetchAll();
$pembimbingUserCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(role, '') = 'pembimbing'")->fetchColumn();
$accessRows = $pdo->query('SELECT user_id, permission_key FROM user_access_permissions')->fetchAll();
$accessMap = [];
foreach ($accessRows as $row) {
    $uid = (int) $row['user_id'];
    if (!isset($accessMap[$uid])) {
        $accessMap[$uid] = [];
    }
    $accessMap[$uid][] = (string) $row['permission_key'];
}
$totalUsers = count($users);
$totalSuperAdmin = count(array_filter($users, static fn(array $u): bool => (int) ($u['is_super_admin'] ?? 0) === 1));
$totalPengurus = count(array_filter($users, static fn(array $u): bool => (string) ($u['role'] ?? '') === 'pengurus'));
$totalNonSuper = count(array_filter($users, static fn(array $u): bool => (int) ($u['is_super_admin'] ?? 0) !== 1));
$openAksesUserId = (int) ($_GET['akses'] ?? 0);

$pageTitle = 'Admin Tambahan';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/admin.php';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a> · Akses</p>
    <h1 class="h4 mb-1">Kelola user &amp; hak akses</h1>
    <p class="text-muted mb-0">Buat akun pengurus / admin / petugas lalu atur hak akses fitur per user. Super admin tetap memiliki akses penuh.</p>
</div>
<div class="alert alert-info d-flex align-items-start gap-2 small mb-3">
    <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
    <div>
        <strong>Akun pembimbing tidak diatur di sini.</strong>
        Kelola identitas pembimbing sekaligus akun loginnya (buat, reset password, hapus) dari
        <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>" class="alert-link">Data Pembimbing</a>.
        <?php if ($pembimbingUserCount > 0): ?>
            Saat ini ada <strong><?= $pembimbingUserCount ?></strong> akun pembimbing tersembunyi dari daftar di bawah.
        <?php endif; ?>
    </div>
</div>
<div class="card shadow-sm mb-3 border-primary-subtle">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-lg-between gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">
                    <i class="fa-solid fa-bolt me-1"></i> Pintasan
                </span>
                <span class="small text-muted">Pengaturan terkait pengurus &amp; jadwal:</span>
            </div>
            <div class="d-grid d-sm-flex flex-sm-wrap gap-2">
                <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-id-card-clip me-1"></i> Data Pengurus
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-calendar-days me-1"></i> Jadwal Kegiatan
                </a>
                <a href="<?= htmlspecialchars(app_href('/settings/tingkatan.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-layer-group me-1"></i> Tingkatan
                </a>
                <a href="<?= htmlspecialchars(app_href('/settings/tarif_payroll.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-coins me-1"></i> Tarif Payroll
                </a>
                <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-book-open me-1"></i> Beban Payroll Ta'lim
                </a>
                <a href="<?= htmlspecialchars(app_href('/pembayaran/edit_token.php')) ?>" class="btn btn-sm btn-outline-warning" title="Buat & kelola token sekali pakai untuk membuka mode edit pembayaran">
                    <i class="fa-solid fa-key me-1"></i> Token Edit Pembayaran
                </a>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total user</div>
            <div class="app-mini-stat-value"><?= $totalUsers ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Super admin</div>
            <div class="app-mini-stat-value text-danger"><?= $totalSuperAdmin ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Diatur per user</div>
            <div class="app-mini-stat-value"><?= $totalNonSuper ?></div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Tambah user</h1>
                <form method="post" class="row g-2" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_user">
                    <div class="col-12"><input class="form-control" type="text" name="nama" placeholder="Nama" required></div>
                    <div class="col-12"><input class="form-control" type="text" name="username" placeholder="Username" required></div>
                    <div class="col-12"><input class="form-control" type="password" name="password" placeholder="Password" required></div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Foto profil (opsional)</label>
                        <input class="form-control form-control-sm" type="file" name="foto_profil" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="col-12">
                        <select class="form-select" name="role">
                            <option value="pengurus">Pengurus</option>
                            <option value="admin">Admin</option>
                            <option value="petugas_absensi">Petugas Absensi</option>
                            <option value="kiai">Pengasuh</option>
                        </select>
                    </div>
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="is_super_admin" name="is_super_admin" value="1">
                        <label class="form-check-label" for="is_super_admin">Jadikan Admin Super</label>
                    </div>
                    <div class="col-12"><button class="btn btn-success">Simpan</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h5 mb-0">Daftar user</h2>
                    <span class="small text-muted"><?= $totalUsers ?> akun terdaftar</span>
                </div>
                <p class="small text-muted mb-3">Klik <strong>Edit</strong> untuk data akun, <strong>Atur akses</strong> untuk hak fitur (user non super admin), atau <strong>Hapus</strong> untuk menghapus akun.</p>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:3rem">Foto</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Super</th>
                            <th>Dibuat</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $uid = (int) $u['id'];
                        $isSelf = $uid === $currentUserId;
                        $role = (string) ($u['role'] ?? 'pengurus');
                        $roleBadge = 'secondary';
                        if ($role === 'admin') { $roleBadge = 'primary'; }
                        elseif ($role === 'pengurus') { $roleBadge = 'success'; }
                        elseif ($role === 'petugas_absensi') { $roleBadge = 'info'; }
                        elseif ($role === 'kiai') { $roleBadge = 'warning'; }
                        ?>
                        <tr>
                            <td class="text-center"><?= user_profil_render_avatar($u, 'app-user-avatar--table') ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($u['nama']) ?> <?php if ($isSelf): ?><span class="badge text-bg-light text-dark border ms-1">Anda</span><?php endif; ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars($u['username']) ?></td>
                            <td><span class="badge text-bg-<?= $roleBadge ?>"><?= htmlspecialchars(user_role_label($role)) ?></span></td>
                            <td><?= (int) $u['is_super_admin'] === 1 ? '<span class="badge text-bg-danger">Super</span>' : '<span class="text-muted">-</span>' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string) ($u['created_at'] ?? '')) ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $uid ?>">Edit</button>
                                <?php if ((int) ($u['is_super_admin'] ?? 0) !== 1): ?>
                                    <?php
                                    $permCount = count($accessMap[$uid] ?? []);
                                    $permTotal = count($permissionOptions);
                                    ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#accessUserModal<?= $uid ?>" title="Atur hak akses fitur">
                                        Atur akses
                                        <span class="badge text-bg-light text-dark border ms-1"><?= $permCount ?>/<?= $permTotal ?></span>
                                    </button>
                                <?php else: ?>
                                    <span class="badge text-bg-danger ms-1" title="Super admin â€” akses penuh">Akses penuh</span>
                                <?php endif; ?>
                                <?php if (!$isSelf): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus user <?= htmlspecialchars(addslashes($u['nama'])) ?>? Tindakan tidak bisa dibatalkan.');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mt-4 border-success border-opacity-25">
            <div class="card-body">
                <h2 class="h5 mb-2">Hak akses per user</h2>
                <p class="text-muted mb-2 small">Klik <strong class="text-success">Atur akses</strong> pada baris user. User <strong>super admin</strong> otomatis akses penuh.</p>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="fa-solid fa-wallet me-1"></i>
                    <strong>User bendahara / keuangan:</strong> di modal akses, bagian <strong>Keuangan (submenu)</strong> ada di urutan kedua (setelah Umum).
                    Centang submenu yang dibutuhkan — misalnya hanya <em>Laporan</em> + <em>Transaksi</em>, atau gunakan tombol preset <em>Operasional keuangan</em>.
                </div>
                <?php if ($totalNonSuper === 0): ?>
                    <div class="alert alert-light border mt-3 mb-0 small">Belum ada user non super admin. Tambahkan akun pengurus lalu atur hak aksesnya.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php foreach ($users as $u): ?>
    <?php
    $uid = (int) $u['id'];
    $rolev = (string) ($u['role'] ?? 'pengurus');
    ?>
    <div class="modal fade" id="editUserModal<?= $uid ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit user: <?= htmlspecialchars($u['nama']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?= user_profil_render_avatar($u, 'app-user-avatar--lg') ?>
                            <div class="small text-muted">Unggah foto baru di bawah. Kosongkan file jika tidak diubah.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Foto profil</label>
                                <input class="form-control" type="file" name="foto_profil" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">JPG, PNG, WEBP · maks. 2 MB</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nama</label>
                                <input class="form-control" type="text" name="nama" value="<?= htmlspecialchars($u['nama']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Username</label>
                                <input class="form-control" type="text" name="username" value="<?= htmlspecialchars($u['username']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="pengurus" <?= $rolev === 'pengurus' ? 'selected' : '' ?>>Pengurus</option>
                                    <option value="admin" <?= $rolev === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="petugas_absensi" <?= $rolev === 'petugas_absensi' ? 'selected' : '' ?>>Petugas Absensi</option>
                                    <option value="kiai" <?= $rolev === 'kiai' ? 'selected' : '' ?>>Pengasuh</option>
                                </select>
                            </div>
                            <div class="col-12 form-check ms-1 mt-3">
                                <input class="form-check-input" type="checkbox" name="is_super_admin" value="1" id="is_super_admin_<?= $uid ?>" <?= (int) $u['is_super_admin'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_super_admin_<?= $uid ?>">Jadikan Admin Super</label>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Password baru</label>
                                <input class="form-control" type="password" name="password" placeholder="Kosongkan jika tidak diubah">
                                <div class="form-text">Biarkan kosong untuk mempertahankan password lama.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ((int) ($u['is_super_admin'] ?? 0) !== 1): ?>
        <?php
        $selected = $accessMap[$uid] ?? [];
        $permCount = count($selected);
        $permTotal = count($permissionOptions);
        ?>
        <div class="modal fade modal-perm-access" id="accessUserModal<?= $uid ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-md-down">
                <div class="modal-content">
                    <form method="post" class="perm-form">
                        <input type="hidden" name="action" value="save_access">
                        <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Atur akses: <?= htmlspecialchars($u['nama']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                            <div class="perm-access-toolbar">
                                <p class="small text-muted mb-2">
                                    <span class="font-monospace"><?= htmlspecialchars($u['username']) ?></span>
                                    · role <span class="badge text-bg-light text-dark border"><?= htmlspecialchars(user_role_label($rolev)) ?></span>
                                    · <span class="perm-count-label"><?= $permCount ?></span> dari <?= $permTotal ?> fitur aktif
                                </p>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm perm-check-all">Centang semua</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm perm-uncheck-all">Kosongkan</button>
                                    <span class="text-muted small mx-1 d-none d-sm-inline">|</span>
                                    <button type="button" class="btn btn-outline-dark btn-sm perm-scroll-up" title="Geser ke atas" aria-label="Geser daftar ke atas">
                                        <i class="fa-solid fa-chevron-up"></i> Atas
                                    </button>
                                    <button type="button" class="btn btn-outline-dark btn-sm perm-scroll-down" title="Geser ke bawah" aria-label="Geser daftar ke bawah">
                                        <i class="fa-solid fa-chevron-down"></i> Bawah
                                    </button>
                                </div>
                                <div class="perm-access-jump" role="navigation" aria-label="Lompat ke bagian">
                                    <span class="small text-muted align-self-center me-1">Bagian:</span>
                                    <?php foreach ($permissionGroups as $jumpId => $jumpGroup): ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm perm-jump-group"
                                                data-jump-group="<?= htmlspecialchars($jumpId) ?>"
                                                title="<?= htmlspecialchars((string) $jumpGroup['label']) ?>">
                                            <?= htmlspecialchars($jumpId === 'keuangan' ? 'Keuangan' : mb_substr((string) $jumpGroup['label'], 0, 18)) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="perm-access-scroll-wrap">
                                <div class="perm-access-scroll-fab">
                                    <button type="button" class="btn btn-light btn-sm perm-scroll-up" title="Ke atas" aria-label="Ke atas">
                                        <i class="fa-solid fa-chevron-up"></i>
                                    </button>
                                    <button type="button" class="btn btn-light btn-sm perm-scroll-down" title="Ke bawah" aria-label="Ke bawah">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div class="perm-access-scroll" tabindex="0" aria-label="Daftar hak akses, geser atas bawah">
                            <?php foreach ($permissionGroups as $groupId => $group):
                                $isKeuanganGroup = $groupId === 'keuangan';
                                $isPkppsGroup = $groupId === 'pkpps_modul';
                                $isKajianGroup = $groupId === 'kajian';
                                $isJamaahGroup = $groupId === 'jamaah';
                            ?>
                                <div class="perm-group border rounded mb-3 p-2<?= $isKeuanganGroup ? ' border-primary border-2 bg-primary bg-opacity-10' : '' ?><?= $isPkppsGroup ? ' border-warning border-2 bg-warning bg-opacity-10' : '' ?><?= $isKajianGroup ? ' border-info border-2 bg-info bg-opacity-10' : '' ?><?= $isJamaahGroup ? ' border-success border-2 bg-success bg-opacity-10' : '' ?>"
                                     id="perm-group-<?= htmlspecialchars($groupId) ?>-<?= $uid ?>"
                                     data-group="<?= htmlspecialchars($groupId) ?>">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <h6 class="mb-0 fw-semibold<?= $isKeuanganGroup ? ' text-primary' : '' ?><?= $isPkppsGroup ? ' text-warning-emphasis' : '' ?><?= $isKajianGroup ? ' text-info-emphasis' : '' ?><?= $isJamaahGroup ? ' text-success-emphasis' : '' ?>">
                                            <?php if ($isKeuanganGroup): ?><i class="fa-solid fa-wallet me-1"></i><?php endif; ?>
                                            <?php if ($isPkppsGroup): ?><i class="fa-solid fa-graduation-cap me-1"></i><?php endif; ?>
                                            <?php if ($isKajianGroup): ?><i class="fa-solid fa-book-open me-1"></i><?php endif; ?>
                                            <?php if ($isJamaahGroup): ?><i class="fa-solid fa-people-group me-1"></i><?php endif; ?>
                                            <?= htmlspecialchars((string) $group['label']) ?>
                                        </h6>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if ($isKeuanganGroup): ?>
                                                <button type="button" class="btn btn-primary btn-sm perm-preset" data-preset="keuangan_semua">Semua keuangan</button>
                                                <button type="button" class="btn btn-outline-primary btn-sm perm-preset" data-preset="keuangan_operasional">Operasional keuangan</button>
                                                <button type="button" class="btn btn-outline-primary btn-sm perm-preset" data-preset="keuangan_laporan_saja">Laporan saja</button>
                                            <?php endif; ?>
                                            <?php if ($isPkppsGroup): ?>
                                                <button type="button" class="btn btn-warning btn-sm perm-preset" data-preset="pkpps_semua">Semua PKPPS</button>
                                            <?php endif; ?>
                                            <?php if ($isKajianGroup): ?>
                                                <button type="button" class="btn btn-info btn-sm perm-preset" data-preset="kajian_semua">Semua kajian</button>
                                            <?php endif; ?>
                                            <?php if ($isJamaahGroup): ?>
                                                <button type="button" class="btn btn-success btn-sm perm-preset" data-preset="jamaah_semua">Semua jama'ah</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm perm-group-check" data-group="<?= htmlspecialchars($groupId) ?>">Semua</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm perm-group-uncheck" data-group="<?= htmlspecialchars($groupId) ?>">Kosong</button>
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <?php foreach ($group['permissions'] as $key => $label):
                                            $submenus = user_permission_submenus_for_key((string) $key);
                                        ?>
                                            <div class="col-md-6">
                                                <div class="form-check perm-check-block">
                                                    <input class="form-check-input perm-checkbox" type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" id="perm_<?= $uid ?>_<?= htmlspecialchars($key) ?>" data-group="<?= htmlspecialchars($groupId) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
                                                    <label class="form-check-label small fw-semibold" for="perm_<?= $uid ?>_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars((string) $label) ?></label>
                                                    <?php if ($submenus !== []): ?>
                                                        <ul class="perm-submenu-list mb-0 mt-1 ps-3">
                                                            <?php foreach ($submenus as $sm): ?>
                                                                <li class="small text-muted">
                                                                    <i class="fa-solid fa-angle-right me-1 opacity-50"></i>
                                                                    <?= htmlspecialchars((string) ($sm['label'] ?? '')) ?>
                                                                    <span class="font-monospace opacity-75"><?= htmlspecialchars((string) ($sm['path'] ?? '')) ?></span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-success">Simpan akses</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
    (function () {
        function updatePermCount(form) {
            const label = form.querySelector('.perm-count-label');
            if (!label) return;
            label.textContent = String(form.querySelectorAll('.perm-checkbox:checked').length);
        }

        function permScrollEl(form) {
            return form.querySelector('.perm-access-scroll');
        }

        function permScrollBy(form, delta) {
            const el = permScrollEl(form);
            if (!el) return;
            el.scrollBy({ top: delta, behavior: 'smooth' });
        }

        function permScrollToGroup(form, groupId) {
            const scroll = permScrollEl(form);
            if (!scroll || !groupId) return;
            const block = scroll.querySelector('.perm-group[data-group="' + groupId + '"]');
            if (!block) return;
            const scrollRect = scroll.getBoundingClientRect();
            const blockRect = block.getBoundingClientRect();
            scroll.scrollTop += blockRect.top - scrollRect.top - 8;
        }

        document.querySelectorAll('.perm-form').forEach(function (form) {
            const checks = form.querySelectorAll('.perm-checkbox');
            const checkAll = form.querySelector('.perm-check-all');
            const uncheckAll = form.querySelector('.perm-uncheck-all');
            const scrollStep = 220;

            form.querySelectorAll('.perm-scroll-up').forEach(function (btn) {
                btn.addEventListener('click', function () { permScrollBy(form, -scrollStep); });
            });
            form.querySelectorAll('.perm-scroll-down').forEach(function (btn) {
                btn.addEventListener('click', function () { permScrollBy(form, scrollStep); });
            });
            form.querySelectorAll('.perm-jump-group').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    permScrollToGroup(form, btn.getAttribute('data-jump-group') || '');
                });
            });

            const scrollArea = permScrollEl(form);
            if (scrollArea) {
                scrollArea.addEventListener('wheel', function (e) {
                    e.stopPropagation();
                }, { passive: true });
                scrollArea.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        permScrollBy(form, scrollStep);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        permScrollBy(form, -scrollStep);
                    } else if (e.key === 'PageDown') {
                        e.preventDefault();
                        permScrollBy(form, scrollArea.clientHeight - 40);
                    } else if (e.key === 'PageUp') {
                        e.preventDefault();
                        permScrollBy(form, -(scrollArea.clientHeight - 40));
                    }
                });
            }
            checks.forEach(function (c) {
                c.addEventListener('change', function () { updatePermCount(form); });
            });
            if (checkAll) {
                checkAll.addEventListener('click', function () {
                    checks.forEach(function (c) { c.checked = true; });
                    updatePermCount(form);
                });
            }
            if (uncheckAll) {
                uncheckAll.addEventListener('click', function () {
                    checks.forEach(function (c) { c.checked = false; });
                    updatePermCount(form);
                });
            }
            form.querySelectorAll('.perm-group-check').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const g = btn.getAttribute('data-group');
                    checks.forEach(function (c) {
                        if (c.getAttribute('data-group') === g) { c.checked = true; }
                    });
                    updatePermCount(form);
                });
            });
            form.querySelectorAll('.perm-group-uncheck').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const g = btn.getAttribute('data-group');
                    checks.forEach(function (c) {
                        if (c.getAttribute('data-group') === g) { c.checked = false; }
                    });
                    updatePermCount(form);
                });
            });
            const presetKeys = <?= json_encode([
                'keuangan_semua' => user_permission_preset_keys('keuangan_semua'),
                'keuangan_operasional' => user_permission_preset_keys('keuangan_operasional'),
                'keuangan_laporan_saja' => user_permission_preset_keys('keuangan_laporan_saja'),
            ], JSON_THROW_ON_ERROR) ?>;
            form.querySelectorAll('.perm-preset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const preset = btn.getAttribute('data-preset') || '';
                    const keys = presetKeys[preset] || [];
                    checks.forEach(function (c) {
                        if (c.getAttribute('data-group') === 'keuangan') {
                            c.checked = keys.indexOf(c.value) >= 0;
                        }
                    });
                    updatePermCount(form);
                    permScrollToGroup(form, 'keuangan');
                });
            });
        });

        <?php if ($openAksesUserId > 0): ?>
        const openModal = document.getElementById('accessUserModal<?= $openAksesUserId ?>');
        if (openModal && typeof bootstrap !== 'undefined') {
            const inst = bootstrap.Modal.getOrCreateInstance(openModal);
            openModal.addEventListener('shown.bs.modal', function () {
                const form = openModal.querySelector('.perm-form');
                if (form) {
                    permScrollToGroup(form, 'keuangan');
                }
            }, { once: true });
            inst.show();
        }
        <?php endif; ?>
    })();
</script>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
