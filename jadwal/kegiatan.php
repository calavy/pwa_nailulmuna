<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/jadwal_pembimbing.php';

jadwal_require_module_access();

if (!table_exists($pdo, 'kegiatan')) {
    set_flash('error', 'Tabel kegiatan belum ada. Jalankan schema presensi terlebih dahulu.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

ensure_kegiatan_kategori_column($pdo);

$jadwalPembimbingScope = jadwal_is_pembimbing_scope();
$filterKat = strtoupper(trim((string) ($_GET['filter_kat'] ?? '')));
if (!in_array($filterKat, ['JAMAAH', 'TAALIM'], true)) {
    $filterKat = '';
}
$searchQ = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit_id'] ?? 0);
$editRow = null;

if ($editId > 0) {
    $stEdit = $pdo->prepare('
        SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan, COALESCE(is_active, 1) AS is_active
        FROM kegiatan WHERE id = :id LIMIT 1
    ');
    $stEdit->execute(['id' => $editId]);
    $editRow = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Kegiatan tidak ditemukan.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'hapus_kegiatan') {
        if ($jadwalPembimbingScope) {
            set_flash('error', 'Hapus master kegiatan hanya untuk pengurus.');
            header('Location: ' . app_href('/jadwal/kegiatan.php'));
            exit;
        }
        $idHapus = (int) ($_POST['id'] ?? 0);
        if ($idHapus <= 0) {
            set_flash('error', 'ID kegiatan tidak valid.');
            header('Location: ' . app_href('/jadwal/kegiatan.php'));
            exit;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE kegiatan_id = :id');
        $st->execute(['id' => $idHapus]);
        if ((int) $st->fetchColumn() > 0) {
            set_flash('error', 'Kegiatan masih dipakai di jadwal. Hapus atau pindahkan slot jadwal terlebih dahulu.');
            header('Location: ' . app_href('/jadwal/kegiatan.php?edit_id=' . $idHapus));
            exit;
        }
        $stN = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id LIMIT 1');
        $stN->execute(['id' => $idHapus]);
        $nama = (string) ($stN->fetchColumn() ?: '');
        $pdo->prepare('DELETE FROM kegiatan WHERE id = :id')->execute(['id' => $idHapus]);
        set_flash('success', 'Kegiatan "' . $nama . '" dihapus.');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }

    $namaKegiatan = trim((string) ($_POST['nama_kegiatan'] ?? ''));
    $kategoriKegiatan = strtoupper(trim((string) ($_POST['kategori_kegiatan'] ?? 'TAALIM')));
    $isActive = ((int) ($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
    if (!in_array($kategoriKegiatan, ['JAMAAH', 'TAALIM'], true)) {
        $kategoriKegiatan = 'TAALIM';
    }
    if ($namaKegiatan === '') {
        set_flash('error', 'Nama kegiatan wajib diisi.');
        header('Location: ' . app_href('/jadwal/kegiatan.php' . ($editId > 0 ? '?edit_id=' . $editId : '')));
        exit;
    }

    if ($action === 'edit') {
        $idEditPost = (int) ($_POST['id'] ?? 0);
        if ($idEditPost <= 0) {
            set_flash('error', 'ID kegiatan tidak valid.');
            header('Location: ' . app_href('/jadwal/kegiatan.php'));
            exit;
        }
        $pdo->prepare('UPDATE kegiatan SET nama_kegiatan = :nama, kategori_kegiatan = :kat, is_active = :aktif WHERE id = :id')
            ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan, 'aktif' => $isActive, 'id' => $idEditPost]);
        set_flash('success', 'Kegiatan "' . $namaKegiatan . '" diperbarui (' . jadwal_kegiatan_kategori_label($kategoriKegiatan) . ').');
        header('Location: ' . app_href('/jadwal/kegiatan.php'));
        exit;
    }

    if ($action === 'tambah') {
        $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:nama, :kat, 1)')
            ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan]);
        $newId = (int) $pdo->lastInsertId();
        set_flash('success', 'Kegiatan "' . $namaKegiatan . '" ditambahkan. Lanjut buat jadwal jika perlu.');
        header('Location: ' . app_href('/jadwal/index.php?panel=jadwal&kegiatan_id=' . $newId));
        exit;
    }
}

$listSql = '
    SELECT k.id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan,
           COALESCE(k.is_active, 1) AS is_active,
           COUNT(j.id) AS jumlah_jadwal
    FROM kegiatan k
    LEFT JOIN jadwal_kegiatan j ON j.kegiatan_id = k.id
    WHERE 1=1
';
$listParams = [];
if ($filterKat !== '') {
    $listSql .= ' AND k.kategori_kegiatan = :kat';
    $listParams['kat'] = $filterKat;
}
if ($searchQ !== '') {
    $listSql .= ' AND k.nama_kegiatan LIKE :q';
    $listParams['q'] = '%' . $searchQ . '%';
}
$listSql .= ' GROUP BY k.id ORDER BY k.kategori_kegiatan ASC, k.nama_kegiatan ASC';
$stList = $pdo->prepare($listSql);
$stList->execute($listParams);
$kegiatanRows = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

$countAll = (int) $pdo->query('SELECT COUNT(*) FROM kegiatan')->fetchColumn();
$countTaalim = (int) $pdo->query('SELECT COUNT(*) FROM kegiatan WHERE kategori_kegiatan = "TAALIM"')->fetchColumn();
$countJamaah = (int) $pdo->query('SELECT COUNT(*) FROM kegiatan WHERE kategori_kegiatan = "JAMAAH"')->fetchColumn();

function jadwal_kegiatan_kategori_label(string $kat): string
{
    return strtoupper(trim($kat)) === 'JAMAAH' ? "Jama'ah" : "Ta'lim & Ta'alum";
}

function jadwal_kegiatan_filter_qs(array $extra = []): string
{
    global $filterKat, $searchQ;
    $q = [];
    if ($filterKat !== '') {
        $q['filter_kat'] = $filterKat;
    }
    if ($searchQ !== '') {
        $q['q'] = $searchQ;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return $q === [] ? '' : ('?' . http_build_query($q));
}

$pageTitle = 'Kegiatan — Ta\'lim & Jama\'ah';
$bodyClass = 'jadwal-page jadwal-kegiatan-page';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a>
        <span class="text-muted mx-1">/</span>
        <span>Kegiatan</span>
    </p>
    <h1 class="h4 mb-1">Pengaturan kegiatan</h1>
    <p class="text-muted small mb-2">
        Tentukan setiap kegiatan sebagai <strong>Ta'lim & Ta'alum</strong> atau <strong>Jama'ah</strong>.
        Kategori ini dipakai presensi scan, hari libur akademik, dan import jadwal.
    </p>
    <p class="small mb-0 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>"><i class="fa-solid fa-calendar me-1"></i> Daftar jadwal</a>
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah')) ?>"><i class="fa-solid fa-mosque me-1"></i> Atur waktu Jama'ah</a>
        <?php if (!$jadwalPembimbingScope): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/import.php')) ?>"><i class="fa-solid fa-file-import me-1"></i> Import jadwal</a>
        <?php endif; ?>
        <a class="btn btn-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?panel=jadwal')) ?>"><i class="fa-solid fa-calendar-plus me-1"></i> Tambah slot jadwal</a>
    </p>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3 text-center">
                <div class="display-6 fw-bold text-primary mb-0"><?= (int) $countAll ?></div>
                <div class="small text-muted">Total kegiatan</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3 text-center">
                <div class="display-6 fw-bold text-success mb-0"><?= (int) $countTaalim ?></div>
                <div class="small text-muted">Ta'lim & Ta'alum</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3 text-center">
                <div class="display-6 fw-bold text-warning mb-0"><?= (int) $countJamaah ?></div>
                <div class="small text-muted">Jama'ah</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3" id="form-kegiatan">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><?= $editRow ? 'Edit kegiatan' : 'Tambah kegiatan baru' ?></strong>
        <?php if ($editRow): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php' . jadwal_kegiatan_filter_qs())) ?>">Batal edit</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'tambah' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <label class="form-label">Nama kegiatan</label>
                <input type="text" class="form-control" name="nama_kegiatan" required maxlength="120"
                       placeholder="Mis. Sholat Subuh, Ngaji Pagi"
                       value="<?= htmlspecialchars((string) ($editRow['nama_kegiatan'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kategori</label>
                <select class="form-select" name="kategori_kegiatan">
                    <option value="TAALIM" <?= strtoupper((string) ($editRow['kategori_kegiatan'] ?? $_POST['kategori_kegiatan'] ?? 'TAALIM')) === 'TAALIM' ? 'selected' : '' ?>>Ta'lim & Ta'alum</option>
                    <option value="JAMAAH" <?= strtoupper((string) ($editRow['kategori_kegiatan'] ?? '')) === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah</option>
                </select>
                <div class="form-text">Sholat berjamaah → Jama'ah. Kajian/kelas → Ta'lim.</div>
            </div>
            <?php if ($editRow): ?>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="is_active">
                    <option value="1" <?= (int) ($editRow['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= (int) ($editRow['is_active'] ?? 1) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-floppy-disk me-1"></i> <?= $editRow ? 'Simpan perubahan' : 'Simpan kegiatan' ?>
                </button>
                <?php if ($editRow && (int) ($editRow['id'] ?? 0) > 0): ?>
                    <a class="btn btn-outline-primary" href="<?= htmlspecialchars(app_href('/jadwal/index.php?panel=jadwal&kegiatan_id=' . (int) $editRow['id'])) ?>">
                        <i class="fa-solid fa-calendar-plus me-1"></i> Kelola jadwal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <strong>Daftar kegiatan</strong>
            <span class="small text-muted"><?= count($kegiatanRows) ?> tampil</span>
        </div>
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-0">Cari nama</label>
                <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="Ketik nama kegiatan…">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0">Filter kategori</label>
                <select name="filter_kat" class="form-select form-select-sm">
                    <option value="">Semua kategori</option>
                    <option value="TAALIM" <?= $filterKat === 'TAALIM' ? 'selected' : '' ?>>Ta'lim & Ta'alum</option>
                    <option value="JAMAAH" <?= $filterKat === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Terapkan</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
        <div class="d-flex flex-wrap gap-1 mt-2">
            <a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>" class="btn btn-sm <?= $filterKat === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">Semua</a>
            <a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php?filter_kat=TAALIM')) ?>" class="btn btn-sm <?= $filterKat === 'TAALIM' ? 'btn-primary' : 'btn-outline-success' ?>">Ta'lim</a>
            <a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php?filter_kat=JAMAAH')) ?>" class="btn btn-sm <?= $filterKat === 'JAMAAH' ? 'btn-primary' : 'btn-outline-warning' ?>">Jama'ah</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nama kegiatan</th>
                    <th>Kategori</th>
                    <th class="text-center">Slot jadwal</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($kegiatanRows === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada kegiatan<?= $searchQ !== '' || $filterKat !== '' ? ' untuk filter ini' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($kegiatanRows as $row): ?>
                <?php
                $kat = strtoupper((string) ($row['kategori_kegiatan'] ?? 'TAALIM'));
                $rowId = (int) ($row['id'] ?? 0);
                $isEditingThis = $editRow && (int) ($editRow['id'] ?? 0) === $rowId;
                ?>
                <tr class="<?= $isEditingThis ? 'table-warning' : '' ?>">
                    <td class="fw-semibold"><?= htmlspecialchars((string) ($row['nama_kegiatan'] ?? '')) ?></td>
                    <td>
                        <?php if ($kat === 'JAMAAH'): ?>
                            <span class="badge text-bg-warning">Jama'ah</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">Ta'lim</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center small"><?= (int) ($row['jumlah_jadwal'] ?? 0) ?></td>
                    <td class="small"><?= (int) ($row['is_active'] ?? 1) === 1 ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php' . jadwal_kegiatan_filter_qs(['edit_id' => (string) $rowId]) . '#form-kegiatan')) ?>">
                            <i class="fa-solid fa-pen-to-square"></i><span class="d-none d-sm-inline ms-1">Edit</span>
                        </a>
                        <?php if ((int) ($row['jumlah_jadwal'] ?? 0) > 0): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/jadwal/index.php?kegiatan_id=' . $rowId)) ?>" title="Lihat di jadwal">
                                <i class="fa-solid fa-calendar"></i>
                            </a>
                        <?php elseif (!$jadwalPembimbingScope): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus kegiatan ini?');">
                                <input type="hidden" name="action" value="hapus_kegiatan">
                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    <i class="fa-solid fa-circle-info me-1"></i>
    Setelah import Excel, periksa kategori di sini — import menimpa kategori lama jika nama kegiatan sama.
</p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
