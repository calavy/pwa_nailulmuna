<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';

require_roles(['admin', 'pengurus']);

ensure_mukimin_portal_columns($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'daftar_akses') {
        $result = mukimin_portal_simpan_akses(
            $pdo,
            (int) ($_POST['alumni_id'] ?? 0),
            (string) ($_POST['portal_username'] ?? ''),
            (string) ($_POST['portal_password'] ?? ''),
            (string) ($_POST['sektor'] ?? ''),
            isset($_POST['portal_aktif']),
            true
        );
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /settings/akses_mukimin.php?q=' . urlencode($q));
        exit;
    }
    if ($action === 'ubah_akses') {
        $id = (int) ($_POST['alumni_id'] ?? 0);
        $pwd = (string) ($_POST['portal_password'] ?? '');
        $result = mukimin_portal_simpan_akses(
            $pdo,
            $id,
            (string) ($_POST['portal_username'] ?? ''),
            $pwd,
            (string) ($_POST['sektor'] ?? ''),
            isset($_POST['portal_aktif']),
            false
        );
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /settings/akses_mukimin.php?edit=' . $id);
        exit;
    }
    if ($action === 'toggle_aktif') {
        $result = mukimin_portal_set_aktif($pdo, (int) ($_POST['alumni_id'] ?? 0), (int) ($_POST['aktif'] ?? 0) === 1);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /settings/akses_mukimin.php?q=' . urlencode($q));
        exit;
    }
    if ($action === 'cabut_akses') {
        $result = mukimin_portal_cabut_akses($pdo, (int) ($_POST['alumni_id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /settings/akses_mukimin.php?q=' . urlencode($q));
        exit;
    }
}

$registered = mukimin_portal_list_registered($pdo, $q);
$belumTerdaftar = mukimin_portal_list_belum_terdaftar($pdo, $q);
$sektorSuggest = mukimin_portal_sektor_suggest();

$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('
        SELECT id, nis, nama, sektor, portal_username, portal_aktif
        FROM akademik_alumni WHERE id = :id LIMIT 1
    ');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = 'Akses Portal Mukimin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="/settings/index.php">Pengaturan</a> · Manajemen SDM
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-user-lock text-primary me-1"></i> Akses Portal Mukimin</h1>
    <p class="text-muted mb-0 small">
        Hanya alumni yang <strong>didaftarkan</strong> di sini yang bisa login portal.
        Login memakai <strong>username</strong> dan <strong>password</strong> (bukan semua data mukimin otomatis).
        <a href="/mukimin/login.php" target="_blank" rel="noopener">Buka halaman login</a>
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                <?= $editRow ? 'Ubah akses terdaftar' : 'Daftarkan akses baru' ?>
            </div>
            <div class="card-body">
                <?php if ($editRow): ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="ubah_akses">
                        <input type="hidden" name="alumni_id" value="<?= (int) $editRow['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Mukimin</label>
                            <input type="text" class="form-control form-control-sm" readonly
                                value="<?= htmlspecialchars((string) $editRow['nis'] . ' — ' . $editRow['nama']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="portal_username" required maxlength="60"
                                pattern="[a-zA-Z0-9._-]+" autocomplete="off"
                                value="<?= htmlspecialchars((string) ($editRow['portal_username'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password baru</label>
                            <input type="password" class="form-control" name="portal_password" minlength="6" autocomplete="new-password"
                                placeholder="Kosongkan jika tidak diubah">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sektor</label>
                            <input type="text" class="form-control" name="sektor" list="sektor-suggest" maxlength="120"
                                value="<?= htmlspecialchars((string) ($editRow['sektor'] ?? '')) ?>">
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input class="form-check-input" type="checkbox" name="portal_aktif" value="1" id="portal_aktif_edit"
                                <?= (int) ($editRow['portal_aktif'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="portal_aktif_edit">Akses aktif (boleh login)</label>
                        </div>
                        <div class="col-12 d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">Simpan perubahan</button>
                            <a href="/settings/akses_mukimin.php" class="btn btn-outline-secondary btn-sm">Batal</a>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="daftar_akses">
                        <div class="col-12">
                            <label class="form-label">Pilih mukimin <span class="text-danger">*</span></label>
                            <select class="form-select" name="alumni_id" required>
                                <option value="">— pilih dari data mukimin —</option>
                                <?php foreach ($belumTerdaftar as $bt): ?>
                                    <option value="<?= (int) $bt['id'] ?>">
                                        <?= htmlspecialchars((string) $bt['nis'] . ' — ' . $bt['nama']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($belumTerdaftar === []): ?>
                                <p class="form-text mb-0">Semua mukimin sudah terdaftar atau tidak ada hasil filter. Tambah data di <a href="/santri/mukimin.php">Data Mukimin</a>.</p>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="portal_username" required maxlength="60"
                                pattern="[a-zA-Z0-9._-]+" autocomplete="off" placeholder="Mis. ahmad.mukimin">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="portal_password" required minlength="6" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sektor</label>
                            <input type="text" class="form-control" name="sektor" list="sektor-suggest" maxlength="120"
                                placeholder="Mis. Dalam pesantren / Luar negeri">
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input class="form-check-input" type="checkbox" name="portal_aktif" value="1" id="portal_aktif_new" checked>
                            <label class="form-check-label" for="portal_aktif_new">Aktifkan langsung</label>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100" <?= $belumTerdaftar === [] ? 'disabled' : '' ?>>
                                Daftarkan akses
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Alumni terdaftar portal</span>
                <span class="badge text-bg-primary"><?= count($registered) ?></span>
            </div>
            <div class="card-body border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <?php if ($editId > 0): ?>
                        <input type="hidden" name="edit" value="<?= $editId ?>">
                    <?php endif; ?>
                    <div class="col">
                        <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>"
                            placeholder="Cari nama, NIS, username, sektor">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Cari</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>NIS</th>
                                <th>Nama</th>
                                <th>Username</th>
                                <th>Sektor</th>
                                <th class="text-center">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($registered === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada alumni terdaftar.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($registered as $r): ?>
                            <tr>
                                <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                                <td class="small fw-semibold"><?= htmlspecialchars((string) $r['nama']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars((string) ($r['portal_username'] ?? '—')) ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($r['sektor'] ?? '—')) ?></td>
                                <td class="text-center">
                                    <?php if ((int) ($r['portal_aktif'] ?? 0) === 1): ?>
                                        <span class="badge text-bg-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int) $r['id'] ?>">Ubah</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_aktif">
                                        <input type="hidden" name="alumni_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="aktif" value="<?= (int) ($r['portal_aktif'] ?? 0) === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning"><?= (int) ($r['portal_aktif'] ?? 0) === 1 ? 'Stop' : 'Aktif' ?></button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Cabut akses login alumni ini?');">
                                        <input type="hidden" name="action" value="cabut_akses">
                                        <input type="hidden" name="alumni_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Cabut</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<datalist id="sektor-suggest">
    <?php foreach ($sektorSuggest as $ss): ?>
        <option value="<?= htmlspecialchars($ss) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
