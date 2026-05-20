<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

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

$permissionOptions = [
    'dashboard' => 'Dashboard',
    'santri_index' => 'Santri Aktif',
    'santri_create' => 'Tambah/Edit Santri',
    'santri_import' => 'Import Santri',
    'presensi_scan' => 'Scan Presensi',
    'jadwal' => 'Jadwal Kegiatan',
    'perizinan' => 'Perizinan (review &amp; setujui)',
    'perizinan_permohonan' => 'Permohonan Izin (ajukan)',
    'perizinan_scan' => 'Scan Izin Keluar/Kembali',
    'pembimbing' => 'Data Pembimbing',
    'pembimbing_perizinan' => 'Izin Pembimbing',
    'rekap_keaktifan' => 'Rekap Keaktifan Santri',
    'rekap' => 'Rekap Presensi',
    'rekap_telat' => 'Rekap Telat',
    'rekap_pembimbing' => 'Rekap Pembimbing',
    'poin_input' => 'Input Poin',
    'poin_rekap' => 'Rekap Poin',
    'poin_settings' => 'Setting Poin',
    'keuangan' => 'Menu Keuangan',
    'keuangan_cashless_scan' => 'Scan Cashless',
    'keuangan_cashless_pin' => 'Cashless & Uang Saku',
    'pengaturan' => 'Pengaturan (pondok, master data, poin, PIN cashless, dll.)',
    'settings_umum' => 'Settings Umum (legacy â€” gunakan Pengaturan)',
    'settings_admin' => 'Kelola Akses User',
    'akademik_hafalan' => 'Akademik: setoran hafalan (bait & Qur\'an), kalender libur, rapor',
];

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'create_user'));

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
            set_flash('success', 'Hak akses user berhasil diperbarui.');
        } else {
            set_flash('error', 'Hak akses hanya bisa diatur untuk user non super admin.');
        }
        header('Location: /pwa_nailulmuna/settings/admin.php?akses=' . $targetUserId);
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
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
        }
        if ($nama === '' || $username === '') {
            set_flash('error', 'Nama dan username tidak boleh kosong.');
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
        }

        $superCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1')->fetchColumn();
        if ((int) $existing['is_super_admin'] === 1 && $isSuperAdmin === 0 && $superCount <= 1) {
            set_flash('error', 'Tidak bisa menonaktifkan super admin terakhir.');
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
        }

        $checkUname = $pdo->prepare('SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1');
        $checkUname->execute(['u' => $username, 'id' => $targetUserId]);
        if ($checkUname->fetch()) {
            set_flash('error', 'Username sudah dipakai user lain.');
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
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
        header('Location: /pwa_nailulmuna/settings/admin.php');
        exit;
    }

    if ($action === 'delete_user') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        if ($targetUserId === $currentUserId) {
            set_flash('error', 'Anda tidak bisa menghapus akun sendiri.');
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, is_super_admin FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $targetUserId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            set_flash('error', 'User tidak ditemukan.');
            header('Location: /pwa_nailulmuna/settings/admin.php');
            exit;
        }
        if ((int) $existing['is_super_admin'] === 1) {
            $superCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1')->fetchColumn();
            if ($superCount <= 1) {
                set_flash('error', 'Tidak bisa menghapus super admin terakhir.');
                header('Location: /pwa_nailulmuna/settings/admin.php');
                exit;
            }
        }
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $targetUserId]);
        set_flash('success', 'User berhasil dihapus.');
        header('Location: /pwa_nailulmuna/settings/admin.php');
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
            set_flash('success', 'User baru berhasil ditambahkan.');
        }
    } else {
        set_flash('error', 'Nama, username, dan password wajib diisi.');
    }
    header('Location: /pwa_nailulmuna/settings/admin.php');
    exit;
}

$users = $pdo->query('SELECT id, nama, username, role, is_super_admin, created_at FROM users ORDER BY id DESC')->fetchAll();
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
$settingsNavActive = '/pwa_nailulmuna/settings/admin.php';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/pwa_nailulmuna/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a> Â· Akses</p>
    <h1 class="h4 mb-1">Kelola user &amp; hak akses</h1>
    <p class="text-muted mb-0">Buat akun lalu atur hak akses fitur per user. Super admin tetap memiliki akses penuh.</p>
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
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_user">
                    <div class="col-12"><input class="form-control" type="text" name="nama" placeholder="Nama" required></div>
                    <div class="col-12"><input class="form-control" type="text" name="username" placeholder="Username" required></div>
                    <div class="col-12"><input class="form-control" type="password" name="password" placeholder="Password" required></div>
                    <div class="col-12">
                        <select class="form-select" name="role">
                            <option value="pengurus">Pengurus</option>
                            <option value="admin">Admin</option>
                            <option value="petugas_absensi">Petugas Absensi</option>
                            <option value="kiai">Kiai</option>
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
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($u['nama']) ?> <?php if ($isSelf): ?><span class="badge text-bg-light text-dark border ms-1">Anda</span><?php endif; ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars($u['username']) ?></td>
                            <td><span class="badge text-bg-<?= $roleBadge ?>"><?= htmlspecialchars($role) ?></span></td>
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
                <p class="text-muted mb-0 small">Klik tombol <strong class="text-success">Atur akses</strong> pada baris user di tabel. Centang fitur yang diizinkan, lalu simpan. User <strong>super admin</strong> otomatis akses penuh.</p>
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
                <form method="post">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit user: <?= htmlspecialchars($u['nama']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
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
                                    <option value="kiai" <?= $rolev === 'kiai' ? 'selected' : '' ?>>Kiai</option>
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
        <div class="modal fade" id="accessUserModal<?= $uid ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" class="perm-form">
                        <input type="hidden" name="action" value="save_access">
                        <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Atur akses: <?= htmlspecialchars($u['nama']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3">
                                <span class="font-monospace"><?= htmlspecialchars($u['username']) ?></span>
                                · role <span class="badge text-bg-light text-dark border"><?= htmlspecialchars($rolev) ?></span>
                                · <span class="perm-count-label"><?= $permCount ?></span> dari <?= $permTotal ?> fitur aktif
                            </p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm perm-check-all">Centang semua</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm perm-uncheck-all">Kosongkan</button>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($permissionOptions as $key => $label): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input perm-checkbox" type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" id="perm_<?= $uid ?>_<?= htmlspecialchars($key) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="perm_<?= $uid ?>_<?= htmlspecialchars($key) ?>"><?= $label ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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

        document.querySelectorAll('.perm-form').forEach(function (form) {
            const checks = form.querySelectorAll('.perm-checkbox');
            const checkAll = form.querySelector('.perm-check-all');
            const uncheckAll = form.querySelector('.perm-uncheck-all');
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
        });

        <?php if ($openAksesUserId > 0): ?>
        const openModal = document.getElementById('accessUserModal<?= $openAksesUserId ?>');
        if (openModal && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(openModal).show();
        }
        <?php endif; ?>
    })();
</script>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
