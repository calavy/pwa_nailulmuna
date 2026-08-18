<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/wali.php';
require_once __DIR__ . '/../helpers/wali_portal.php';

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
        if ($nama === '') {
            set_flash('error', 'Nama wali wajib diisi.');
        } else {
            $redirectAfter = '/data/wali.php';
            if ($nomorId !== '') {
                $dup = $pdo->prepare('SELECT id FROM wali_santri WHERE nomor_id = :n LIMIT 1');
                $dup->execute(['n' => mb_substr($nomorId, 0, 40)]);
                if ($dup->fetch()) {
                    set_flash('error', 'No. ID wali sudah dipakai data lain.');
                    header('Location: ' . app_href('/data/wali.php'));
                    exit;
                }
            }
            try {
                $pdo->prepare('INSERT INTO wali_santri (nama, no_wa, alamat, nomor_id, user_id) VALUES (:nama, :no_wa, :alamat, :nomor_id, NULL)')->execute([
                    'nama' => mb_substr($nama, 0, 120),
                    'no_wa' => $noWa !== '' ? mb_substr($noWa, 0, 40) : null,
                    'alamat' => $alamat !== '' ? $alamat : null,
                    'nomor_id' => $nomorId !== '' ? mb_substr($nomorId, 0, 40) : null,
                ]);
            } catch (PDOException $e) {
                set_flash('error', 'Gagal menyimpan (No. ID bentrok atau data tidak valid).');
                header('Location: ' . app_href('/data/wali.php'));
                exit;
            }
            $newWaliId = (int) $pdo->lastInsertId();
            if ($nomorId === '' && $newWaliId > 0) {
                wali_santri_ensure_automatic_nomor($pdo, $newWaliId);
            }
            set_flash('success', 'Data wali ditambahkan.');
            header('Location: ' . app_href('/data/wali.php?daftar=1'));
            exit;
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $noWa = trim((string) ($_POST['no_wa'] ?? ''));
        $alamat = trim((string) ($_POST['alamat'] ?? ''));
        $nomorId = trim((string) ($_POST['nomor_id'] ?? ''));
        $redirectAfter = '/data/wali.php?edit=' . max(0, $id);
        if ($id <= 0 || $nama === '') {
            set_flash('error', 'Data tidak valid.');
        } else {
            if ($nomorId !== '') {
                $dup = $pdo->prepare('SELECT id FROM wali_santri WHERE nomor_id = :n AND id <> :id LIMIT 1');
                $dup->execute(['n' => mb_substr($nomorId, 0, 40), 'id' => $id]);
                if ($dup->fetch()) {
                    set_flash('error', 'No. ID wali sudah dipakai data lain.');
                    header('Location: ' . app_href($redirectAfter));
                    exit;
                }
            }
            try {
                $pdo->prepare('UPDATE wali_santri SET nama = :nama, no_wa = :no_wa, alamat = :alamat, nomor_id = :nomor_id WHERE id = :id')->execute([
                    'nama' => mb_substr($nama, 0, 120),
                    'no_wa' => $noWa !== '' ? mb_substr($noWa, 0, 40) : null,
                    'alamat' => $alamat !== '' ? $alamat : null,
                    'nomor_id' => $nomorId !== '' ? mb_substr($nomorId, 0, 40) : null,
                    'id' => $id,
                ]);
            } catch (PDOException $e) {
                set_flash('error', 'Gagal menyimpan (No. ID bentrok atau data tidak valid).');
                header('Location: ' . app_href($redirectAfter));
                exit;
            }
            if ($nomorId === '') {
                wali_santri_ensure_automatic_nomor($pdo, $id);
            }
            set_flash('success', 'Data wali diperbarui.');
            header('Location: ' . app_href($redirectAfter));
            exit;
        }
    } elseif ($action === 'set_portal_settings' || $action === 'set_portal_pin') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $result = wali_portal_save_settings(
            $pdo,
            $santriId,
            trim((string) ($_POST['wali_pin_baru'] ?? '')),
            trim((string) ($_POST['wali_pin_konfirmasi'] ?? '')),
            trim((string) ($_POST['wali_nama'] ?? '')),
            trim((string) ($_POST['wali_no_wa'] ?? ''))
        );
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
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
    header('Location: ' . app_href('/data/wali.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canMutate) {
    set_flash('error', 'Anda tidak punya izin mengubah data wali.');
    header('Location: ' . app_href('/data/wali.php'));
    exit;
}

$sqlList = "
    SELECT w.*,
        (SELECT COUNT(*) FROM santri s WHERE s.wali_santri_id = w.id) AS jumlah_santri,
        (SELECT SUBSTRING(GROUP_CONCAT(CONCAT(IFNULL(NULLIF(TRIM(s.nis), ''), '-'), ' ', IFNULL(s.nama_santri, '')) ORDER BY s.nis SEPARATOR ' · '), 1, 320)
         FROM santri s WHERE s.wali_santri_id = w.id) AS santri_ringkas
    FROM wali_santri w
    ORDER BY w.nama ASC
";
$rows = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$total = count($rows);
$editOpenId = $canMutate ? (int) ($_GET['edit'] ?? 0) : 0;
$bukaDaftarWali = $editOpenId > 0 || isset($_GET['daftar']) || (isset($_GET['tambah']) && $canMutate);

$portalSantriRows = [];
ensure_santri_identity_columns($pdo);
if (table_exists($pdo, 'santri')) {
    ensure_wali_santri_table($pdo);
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $joinWali = column_exists($pdo, 'santri', 'wali_santri_id') && table_exists($pdo, 'wali_santri');
    $sqlPortal = "
        SELECT s.id, s.nis, s.{$nameCol} AS nama_santri, s.no_wa_wali, s.wali_portal_pin_hash,
               (s.wali_portal_pin_hash IS NOT NULL AND s.wali_portal_pin_hash <> '') AS pin_ada";
    if (column_exists($pdo, 'santri', 'nama_ayah')) {
        $sqlPortal .= ', s.nama_ayah';
    }
    if (column_exists($pdo, 'santri', 'nama_kafil')) {
        $sqlPortal .= ', s.nama_kafil';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ayah')) {
        $sqlPortal .= ', s.no_kontak_ayah';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ibu')) {
        $sqlPortal .= ', s.no_kontak_ibu';
    }
    if ($joinWali) {
        $sqlPortal .= ', s.wali_santri_id, w.nama AS wali_nama, w.no_wa AS wali_no_wa';
    }
    $sqlPortal .= "
        FROM santri s";
    if ($joinWali) {
        $sqlPortal .= ' LEFT JOIN wali_santri w ON w.id = s.wali_santri_id';
    }
    $sqlPortal .= "
        ORDER BY s.{$nameCol} ASC
        LIMIT 500";
    $portalSantriRows = $pdo->query($sqlPortal)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($portalSantriRows as &$psRow) {
        $contact = wali_portal_contact_from_row($pdo, $psRow);
        $psRow['wali_nama_tampil'] = $contact['nama'];
        $psRow['wali_wa_tampil'] = $contact['no_wa'];
        $psRow['pin_ada'] = $contact['pin_ada'];
    }
    unset($psRow);
}

$portalPinSudah = count(array_filter($portalSantriRows, static fn(array $r): bool => !empty($r['pin_ada'])));
$portalPinBelum = count($portalSantriRows) - $portalPinSudah;

$pageTitle = 'Wali santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="sdm-hub-hero mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-lg-8">
            <p class="sdm-hub-kicker mb-1">Manajemen SDM</p>
            <h1 class="h3 mb-2 sdm-hub-title">Wali santri</h1>
            <p class="text-muted mb-0 small">
                Atur <strong>portal wali</strong> per santri: wali masuk di <a href="<?= htmlspecialchars(app_wali_login_href()) ?>" target="_blank" rel="noopener">portal wali</a> memakai <strong>NIS atau nama santri</strong> + PIN.
                Tidak perlu akun pengurus. Profil wali pondok (nama, WhatsApp, alamat) di bagian daftar wali di bawah.
            </p>
        </div>
        <div class="col-lg-4">
            <div class="row g-2 text-center">
                <div class="col-6">
                    <div class="sdm-stat-pill h-100">
                        <div class="sdm-stat-value"><?= (int) $portalPinSudah ?></div>
                        <div class="sdm-stat-label">PIN sudah</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sdm-stat-pill h-100">
                        <div class="sdm-stat-value"><?= (int) $portalPinBelum ?></div>
                        <div class="sdm-stat-label">PIN belum</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$canMutate): ?>
    <div class="alert alert-info">Anda dapat melihat daftar. Untuk menambah / mengubah / menghapus, minta izin <strong>Tambah/Edit Santri</strong> kepada admin.</div>
<?php endif; ?>

<?php if ($portalSantriRows !== []): ?>
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-white fw-semibold small">Portal wali (per santri)</div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-2">
            Wali masuk dengan <strong>NIS atau nama santri</strong> + PIN. Isi PIN di bawah agar portal bisa diakses.
            Nama wali &amp; WhatsApp opsional — dipakai notifikasi otomatis (tagihan, izin, cashless, dll.).
            <?php if ($canMutate): ?>PIN kosong = tidak diubah.<?php endif; ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>NIS</th>
                        <th>Nama santri</th>
                        <th class="text-center">PIN</th>
                        <?php if ($canMutate): ?>
                            <th style="min-width:18rem">Nama wali · WhatsApp · PIN</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($portalSantriRows as $ps): ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars((string) $ps['nis']) ?></td>
                        <td class="small"><?= htmlspecialchars((string) $ps['nama_santri']) ?></td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= !empty($ps['pin_ada']) ? 'success' : 'warning' ?>"><?= !empty($ps['pin_ada']) ? 'Sudah' : 'Belum' ?></span>
                        </td>
                        <?php if ($canMutate): ?>
                        <td>
                            <form method="post" class="d-flex flex-wrap align-items-center gap-1">
                                <input type="hidden" name="action" value="set_portal_settings">
                                <input type="hidden" name="santri_id" value="<?= (int) $ps['id'] ?>">
                                <input type="text" name="wali_nama" class="form-control form-control-sm" style="min-width:7rem;max-width:9rem" maxlength="120" placeholder="Nama wali" value="<?= htmlspecialchars((string) ($ps['wali_nama_tampil'] ?? '')) ?>" aria-label="Nama wali">
                                <input type="text" name="wali_no_wa" class="form-control form-control-sm font-monospace" style="min-width:7rem;max-width:9.5rem" maxlength="40" placeholder="628…" value="<?= htmlspecialchars((string) ($ps['wali_wa_tampil'] ?? '')) ?>" inputmode="tel" aria-label="WhatsApp wali">
                                <input type="password" name="wali_pin_baru" class="form-control form-control-sm" style="max-width:5.5rem" minlength="6" placeholder="PIN" autocomplete="new-password" aria-label="PIN baru">
                                <input type="password" name="wali_pin_konfirmasi" class="form-control form-control-sm" style="max-width:5.5rem" minlength="6" placeholder="Ulangi" autocomplete="new-password" aria-label="Ulangi PIN">
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Simpan</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($canMutate): ?>
    <div class="alert alert-warning mt-4 mb-0">Belum ada data santri. Tambah santri terlebih dahulu, lalu atur PIN portal di sini.</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mt-4 mb-2">
    <button type="button"
        class="btn btn-outline-primary btn-sm"
        data-bs-toggle="collapse"
        data-bs-target="#daftar-wali-panel"
        aria-expanded="<?= $bukaDaftarWali ? 'true' : 'false' ?>"
        aria-controls="daftar-wali-panel">
        <i class="fa-solid fa-list me-1"></i> Daftar wali (<?= (int) $total ?>)
    </button>
    <?php if ($canMutate): ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#daftar-wali-panel, #form-tambah-wali" aria-expanded="false" aria-controls="form-tambah-wali">
            <i class="fa-solid fa-plus me-1"></i> Tambah wali
        </button>
    <?php endif; ?>
    <span class="small text-muted">Klik tombol untuk menampilkan / menyembunyikan daftar profil wali.</span>
</div>

<div class="collapse<?= $bukaDaftarWali ? ' show' : '' ?>" id="daftar-wali-panel">
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if ($canMutate): ?>
        <div id="form-tambah-wali" class="collapse border-bottom">
            <div class="p-3 bg-light bg-opacity-25">
                <h3 class="h6 mb-3 d-flex align-items-center gap-2">
                    <span class="sdm-icon-dot sdm-dot-teal"></span> Tambah data wali baru
                </h3>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="create">
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label small mb-0">Nama</label>
                        <input type="text" name="nama" class="form-control form-control-sm" required maxlength="120" placeholder="Nama lengkap wali">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small mb-0">No. ID (opsional)</label>
                        <input type="text" name="nomor_id" class="form-control form-control-sm font-monospace" maxlength="40" placeholder="WS-…">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label small mb-0">WhatsApp</label>
                        <input type="text" name="no_wa" class="form-control form-control-sm" maxlength="40" placeholder="628…">
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label small mb-0">Alamat</label>
                        <textarea name="alamat" class="form-control form-control-sm" rows="1" placeholder="Alamat domisili"></textarea>
                    </div>
                    <div class="col-12 col-lg-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama &amp; alamat</th>
                        <th class="text-nowrap">No. ID</th>
                        <th>Santri</th>
                        <th>WhatsApp</th>
                        <?php if ($canMutate): ?>
                            <th class="text-end text-nowrap" style="width:8.5rem">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $canMutate ? 5 : 4 ?>" class="text-center text-muted py-4">Belum ada data wali.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $waliId = (int) $r['id'];
                    $isEditing = $canMutate && $editOpenId === $waliId;
                    ?>
                    <tr>
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
                        <?php if ($canMutate): ?>
                            <td class="text-end text-nowrap">
                                <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#edit-wali-<?= $waliId ?>"
                                    aria-expanded="<?= $isEditing ? 'true' : 'false' ?>"
                                    aria-controls="edit-wali-<?= $waliId ?>">
                                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus data wali ini?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $waliId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1">Hapus</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php if ($canMutate): ?>
                    <tr class="collapse<?= $isEditing ? ' show' : '' ?>" id="edit-wali-<?= $waliId ?>">
                        <td colspan="5" class="bg-light bg-opacity-25 border-top-0 pt-0">
                            <form method="post" class="p-3 border rounded-3 bg-white shadow-sm">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $waliId ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                    <h3 class="h6 mb-0">Edit wali: <?= htmlspecialchars((string) $r['nama']) ?></h3>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#edit-wali-<?= $waliId ?>">Tutup</button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6 col-lg-4">
                                        <label class="form-label small mb-0">Nama</label>
                                        <input type="text" name="nama" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $r['nama']) ?>" required maxlength="120">
                                    </div>
                                    <div class="col-md-6 col-lg-3">
                                        <label class="form-label small mb-0">No. ID</label>
                                        <input type="text" name="nomor_id" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars((string) ($r['nomor_id'] ?? '')) ?>" maxlength="40" placeholder="Kosong = otomatis">
                                    </div>
                                    <div class="col-md-6 col-lg-3">
                                        <label class="form-label small mb-0">WhatsApp</label>
                                        <input type="text" name="no_wa" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars((string) ($r['no_wa'] ?? '')) ?>" maxlength="40" placeholder="628…">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-0">Alamat</label>
                                        <textarea name="alamat" class="form-control form-control-sm" rows="2" placeholder="Alamat"><?= htmlspecialchars((string) ($r['alamat'] ?? '')) ?></textarea>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan perubahan</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-user-lock text-primary me-1"></i> Portal mukimin (alumni)</h2>
        <p class="small text-muted mb-3">
            Akses login alumni <strong>hanya untuk yang didaftarkan</strong> pengurus (username, password, dan sektor).
        </p>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/settings/akses_mukimin.php')) ?>">Kelola akses portal mukimin</a>
        <a class="btn btn-outline-secondary btn-sm ms-1" href="<?= htmlspecialchars(app_href('/mukimin/login.php')) ?>" target="_blank" rel="noopener">Buka halaman login</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
