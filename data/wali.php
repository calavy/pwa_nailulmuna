<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wali.php';

require_roles(['admin', 'pengurus']);
ensure_wali_santri_table($pdo);
ensure_santri_identity_columns($pdo);

$roleUser = (string) ($_SESSION['user']['role'] ?? '');
$canMutate = is_super_admin() || $roleUser === 'admin' || user_can_access_permission_key('santri_create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canMutate) {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $noWa = trim((string) ($_POST['no_wa'] ?? ''));
        $alamat = trim((string) ($_POST['alamat'] ?? ''));
        $nomorId = trim((string) ($_POST['nomor_id'] ?? ''));
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($nama === '') {
            set_flash('error', 'Nama wali wajib diisi.');
        } else {
            $uid = $userId > 0 ? $userId : null;
            if ($uid !== null) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $chk->execute(['id' => $uid]);
                if (!$chk->fetch()) {
                    $uid = null;
                }
            }
            if ($nomorId !== '') {
                $dup = $pdo->prepare('SELECT id FROM wali_santri WHERE nomor_id = :n LIMIT 1');
                $dup->execute(['n' => mb_substr($nomorId, 0, 40)]);
                if ($dup->fetch()) {
                    set_flash('error', 'No. ID wali sudah dipakai data lain.');
                    header('Location: /pwa_nailulmuna/data/wali.php');
                    exit;
                }
            }
            try {
                $pdo->prepare('INSERT INTO wali_santri (nama, no_wa, alamat, nomor_id, user_id) VALUES (:nama, :no_wa, :alamat, :nomor_id, :uid)')->execute([
                    'nama' => mb_substr($nama, 0, 120),
                    'no_wa' => $noWa !== '' ? mb_substr($noWa, 0, 40) : null,
                    'alamat' => $alamat !== '' ? $alamat : null,
                    'nomor_id' => $nomorId !== '' ? mb_substr($nomorId, 0, 40) : null,
                    'uid' => $uid,
                ]);
            } catch (PDOException $e) {
                set_flash('error', 'Gagal menyimpan (No. ID bentrok atau data tidak valid).');
                header('Location: /pwa_nailulmuna/data/wali.php');
                exit;
            }
            $newWaliId = (int) $pdo->lastInsertId();
            if ($nomorId === '' && $newWaliId > 0) {
                wali_santri_ensure_automatic_nomor($pdo, $newWaliId);
            }
            set_flash('success', 'Data wali ditambahkan.');
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $noWa = trim((string) ($_POST['no_wa'] ?? ''));
        $alamat = trim((string) ($_POST['alamat'] ?? ''));
        $nomorId = trim((string) ($_POST['nomor_id'] ?? ''));
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($id <= 0 || $nama === '') {
            set_flash('error', 'Data tidak valid.');
        } else {
            $uid = $userId > 0 ? $userId : null;
            if ($uid !== null) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $chk->execute(['id' => $uid]);
                if (!$chk->fetch()) {
                    $uid = null;
                }
            }
            if ($nomorId !== '') {
                $dup = $pdo->prepare('SELECT id FROM wali_santri WHERE nomor_id = :n AND id <> :id LIMIT 1');
                $dup->execute(['n' => mb_substr($nomorId, 0, 40), 'id' => $id]);
                if ($dup->fetch()) {
                    set_flash('error', 'No. ID wali sudah dipakai data lain.');
                    header('Location: /pwa_nailulmuna/data/wali.php');
                    exit;
                }
            }
            try {
                $pdo->prepare('UPDATE wali_santri SET nama = :nama, no_wa = :no_wa, alamat = :alamat, nomor_id = :nomor_id, user_id = :uid WHERE id = :id')->execute([
                    'nama' => mb_substr($nama, 0, 120),
                    'no_wa' => $noWa !== '' ? mb_substr($noWa, 0, 40) : null,
                    'alamat' => $alamat !== '' ? $alamat : null,
                    'nomor_id' => $nomorId !== '' ? mb_substr($nomorId, 0, 40) : null,
                    'uid' => $uid,
                    'id' => $id,
                ]);
            } catch (PDOException $e) {
                set_flash('error', 'Gagal menyimpan (No. ID bentrok atau data tidak valid).');
                header('Location: /pwa_nailulmuna/data/wali.php');
                exit;
            }
            if ($nomorId === '') {
                wali_santri_ensure_automatic_nomor($pdo, $id);
            }
            set_flash('success', 'Data wali diperbarui.');
        }
    } elseif ($action === 'set_portal_pin') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $pinBaru = trim((string) ($_POST['wali_pin_baru'] ?? ''));
        $pinKonf = trim((string) ($_POST['wali_pin_konfirmasi'] ?? ''));
        if ($santriId <= 0) {
            set_flash('error', 'Santri tidak valid.');
        } elseif (strlen($pinBaru) < 6) {
            set_flash('error', 'PIN portal minimal 6 karakter.');
        } elseif ($pinBaru !== $pinKonf) {
            set_flash('error', 'PIN dan konfirmasi tidak sama.');
        } else {
            $pdo->prepare('UPDATE santri SET wali_portal_pin_hash = :h WHERE id = :id')->execute([
                'h' => password_hash($pinBaru, PASSWORD_DEFAULT),
                'id' => $santriId,
            ]);
            set_flash('success', 'PIN portal wali berhasil disimpan.');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            if (column_exists($pdo, 'santri', 'wali_santri_id')) {
                $pdo->prepare('UPDATE santri SET wali_santri_id = NULL WHERE wali_santri_id = :id')->execute(['id' => $id]);
            }
            $pdo->prepare('DELETE FROM wali_santri WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Data wali dihapus.');
        }
    }
    header('Location: /pwa_nailulmuna/data/wali.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canMutate) {
    set_flash('error', 'Anda tidak punya izin mengubah data wali.');
    header('Location: /pwa_nailulmuna/data/wali.php');
    exit;
}

$usersPick = [];
if (table_exists($pdo, 'users')) {
    $usersPick = $pdo->query('SELECT id, nama, username, role FROM users ORDER BY nama ASC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$sqlList = "
    SELECT w.*, u.nama AS user_nama, u.username AS user_username,
        (SELECT COUNT(*) FROM santri s WHERE s.wali_santri_id = w.id) AS jumlah_santri,
        (SELECT SUBSTRING(GROUP_CONCAT(CONCAT(IFNULL(NULLIF(TRIM(s.nis), ''), '-'), ' ', IFNULL(s.nama_santri, '')) ORDER BY s.nis SEPARATOR ' · '), 1, 320)
         FROM santri s WHERE s.wali_santri_id = w.id) AS santri_ringkas
    FROM wali_santri w
    LEFT JOIN users u ON u.id = w.user_id
    ORDER BY w.nama ASC
";
$rows = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$total = count($rows);
$linked = count(array_filter($rows, static fn(array $r): bool => !empty($r['user_id'])));

$portalSantriRows = [];
if (column_exists($pdo, 'santri', 'wali_portal_pin_hash')) {
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $portalSantriRows = $pdo->query("
        SELECT s.id, s.nis, s.{$nameCol} AS nama_santri,
               (s.wali_portal_pin_hash IS NOT NULL AND s.wali_portal_pin_hash <> '') AS pin_ada
        FROM santri s
        ORDER BY s.nama_santri ASC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Wali santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="sdm-hub-hero mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-lg-8">
            <p class="sdm-hub-kicker mb-1">Manajemen SDM</p>
            <h1 class="h3 mb-2 sdm-hub-title">Wali santri</h1>
            <p class="text-muted mb-0 small">
                Data wali pondok dan <strong>PIN portal</strong> untuk login wali di <a href="/pwa_nailulmuna/wali/login.php" target="_blank" rel="noopener">portal wali</a> (NIS + PIN).
                Profil wali dapat diedit di tabel; PIN portal per santri di bagian bawah.
            </p>
        </div>
        <div class="col-lg-4">
            <div class="row g-2 text-center">
                <div class="col-6">
                    <div class="sdm-stat-pill h-100">
                        <div class="sdm-stat-value"><?= (int) $total ?></div>
                        <div class="sdm-stat-label">Total wali</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sdm-stat-pill h-100">
                        <div class="sdm-stat-value"><?= (int) $linked ?></div>
                        <div class="sdm-stat-label">Terhubung user</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$canMutate): ?>
    <div class="alert alert-info">Anda dapat melihat daftar. Untuk menambah / mengubah / menghapus, minta izin <strong>Tambah/Edit Santri</strong> kepada admin.</div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($canMutate): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 sdm-form-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                        <span class="sdm-icon-dot sdm-dot-teal"></span> Data wali
                    </h2>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#form-tambah-wali" aria-expanded="false" aria-controls="form-tambah-wali">
                        <i class="fa-solid fa-plus me-1"></i> Tambah wali
                    </button>
                </div>
                <div id="form-tambah-wali" class="collapse">
                <form method="post" class="d-grid gap-2 border-top pt-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label small mb-0">Nama</label>
                        <input type="text" name="nama" class="form-control" required maxlength="120" placeholder="Nama lengkap wali">
                    </div>
                    <div>
                        <label class="form-label small mb-0">No. ID (opsional)</label>
                        <input type="text" name="nomor_id" class="form-control" maxlength="40" placeholder="Kosong = otomatis WS-000001">
                    </div>
                    <div>
                        <label class="form-label small mb-0">No. WhatsApp</label>
                        <input type="text" name="no_wa" class="form-control" maxlength="40" placeholder="628…">
                    </div>
                    <div>
                        <label class="form-label small mb-0">Alamat</label>
                        <textarea name="alamat" class="form-control" rows="2" placeholder="Alamat domisili"></textarea>
                    </div>
                    <div>
                        <label class="form-label small mb-0">Akun pengguna (opsional)</label>
                        <select name="user_id" class="form-select">
                            <option value="0">— Tidak dihubungkan —</option>
                            <?php foreach ($usersPick as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars((string) $u['nama']) ?> (@<?= htmlspecialchars((string) $u['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary mt-1">Simpan</button>
                </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?= $canMutate ? 'col-lg-8' : 'col-12' ?>">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-0">
                <div class="px-3 py-3 border-bottom bg-light bg-opacity-50">
                    <h2 class="h6 mb-0">Daftar wali</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($canMutate): ?>
                                    <th>Profil &amp; kontak</th>
                                    <th class="text-end" style="width:6rem">Aksi</th>
                                <?php else: ?>
                                    <th>Nama &amp; alamat</th>
                                    <th class="text-nowrap">No. ID</th>
                                    <th>Santri</th>
                                    <th>WhatsApp</th>
                                    <th>Akun pengguna</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="<?= $canMutate ? 2 : 5 ?>" class="text-center text-muted py-4">Belum ada data wali.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <?php if ($canMutate): ?>
                                    <td>
                                        <form method="post" class="d-grid gap-2 sdm-inline-form">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <div class="small text-muted mb-2 pb-2 border-bottom">
                                                <span class="badge text-bg-light border font-monospace"><?= htmlspecialchars((string) ($r['nomor_id'] ?? '—')) ?></span>
                                                <span class="ms-1"><?= (int) ($r['jumlah_santri'] ?? 0) ?> santri</span>
                                                <?php if (($r['santri_ringkas'] ?? '') !== ''): ?>
                                                    <div class="mt-1 text-wrap" style="font-size:0.8rem"><?= htmlspecialchars((string) $r['santri_ringkas']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <label class="form-label small mb-0 text-muted">Nama</label>
                                                    <input type="text" name="nama" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $r['nama']) ?>" required maxlength="120">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small mb-0 text-muted">No. ID</label>
                                                    <input type="text" name="nomor_id" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars((string) ($r['nomor_id'] ?? '')) ?>" maxlength="40" placeholder="Kosong = otomatis">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small mb-0 text-muted">WhatsApp</label>
                                                    <input type="text" name="no_wa" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars((string) ($r['no_wa'] ?? '')) ?>" maxlength="40" placeholder="628…">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small mb-0 text-muted">Alamat</label>
                                                    <textarea name="alamat" class="form-control form-control-sm" rows="2" placeholder="Alamat"><?= htmlspecialchars((string) ($r['alamat'] ?? '')) ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small mb-0 text-muted">Akun pengguna</label>
                                                    <select name="user_id" class="form-select form-select-sm">
                                                        <option value="0">— Tanpa akun —</option>
                                                        <?php foreach ($usersPick as $u): ?>
                                                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($r['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars((string) $u['nama']) ?> (@<?= htmlspecialchars((string) $u['username']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td class="text-end align-top">
                                        <form method="post" onsubmit="return confirm('Hapus data wali ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                <?php else: ?>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) $r['nama']) ?></div>
                                        <div class="small text-muted"><?= ($r['alamat'] ?? '') !== '' ? nl2br(htmlspecialchars((string) $r['alamat'])) : '—' ?></div>
                                    </td>
                                    <td class="small font-monospace"><?= htmlspecialchars((string) ($r['nomor_id'] ?? '—')) ?></td>
                                    <td class="small">
                                        <div><?= (int) ($r['jumlah_santri'] ?? 0) ?> orang</div>
                                        <?php if (($r['santri_ringkas'] ?? '') !== ''): ?>
                                            <div class="text-muted text-wrap" style="font-size:0.75rem"><?= htmlspecialchars((string) $r['santri_ringkas']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap small font-monospace"><?= htmlspecialchars((string) ($r['no_wa'] ?? '—')) ?></td>
                                    <td class="small">
                                        <?php if (!empty($r['user_id'])): ?>
                                            <span class="badge text-bg-light border"><?= htmlspecialchars((string) ($r['user_nama'] ?? '')) ?></span>
                                            <div class="text-muted" style="font-size:0.75rem">@<?= htmlspecialchars((string) ($r['user_username'] ?? '')) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canMutate && $portalSantriRows !== []): ?>
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-white fw-semibold small">PIN portal wali (per santri)</div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-2">Login portal: <strong>NIS</strong> + PIN. Minimal 6 karakter.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>NIS</th><th>Nama</th><th class="text-center">Status</th><th>Atur PIN</th></tr>
                </thead>
                <tbody>
                <?php foreach ($portalSantriRows as $ps): ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars((string) $ps['nis']) ?></td>
                        <td class="small"><?= htmlspecialchars((string) $ps['nama_santri']) ?></td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= !empty($ps['pin_ada']) ? 'success' : 'warning' ?>"><?= !empty($ps['pin_ada']) ? 'Sudah' : 'Belum' ?></span>
                        </td>
                        <td>
                            <form method="post" class="d-flex flex-wrap gap-1">
                                <input type="hidden" name="action" value="set_portal_pin">
                                <input type="hidden" name="santri_id" value="<?= (int) $ps['id'] ?>">
                                <input type="password" name="wali_pin_baru" class="form-control form-control-sm" style="max-width:6.5rem" minlength="6" placeholder="PIN" required autocomplete="new-password">
                                <input type="password" name="wali_pin_konfirmasi" class="form-control form-control-sm" style="max-width:6.5rem" minlength="6" placeholder="Ulangi" required autocomplete="new-password">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Simpan</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-user-lock text-primary me-1"></i> Portal mukimin (alumni)</h2>
        <p class="small text-muted mb-3">
            Akses login alumni <strong>hanya untuk yang didaftarkan</strong> pengurus (username, password, dan sektor).
        </p>
        <a class="btn btn-outline-primary btn-sm" href="/pwa_nailulmuna/settings/akses_mukimin.php">Kelola akses portal mukimin</a>
        <a class="btn btn-outline-secondary btn-sm ms-1" href="/pwa_nailulmuna/mukimin/login.php" target="_blank" rel="noopener">Buka halaman login</a>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
