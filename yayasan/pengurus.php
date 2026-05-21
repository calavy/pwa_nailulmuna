<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$jabatanOpsi = yayasan_jabatan_opsi();
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM yayasan_pengurus WHERE id = :id LIMIT 1');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM yayasan_pengurus WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Pengurus dihapus.');
        }
        header('Location: ' . app_href('/yayasan/pengurus.php'));
        exit;
    }
    if ($action === 'toggle_aktif') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE yayasan_pengurus SET is_aktif = IF(is_aktif = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Status pengurus diperbarui.');
        }
        header('Location: ' . app_href('/yayasan/pengurus.php'));
        exit;
    }

    $nama = trim((string) ($_POST['nama'] ?? ''));
    $jabatan = trim((string) ($_POST['jabatan'] ?? 'Anggota'));
    if (!in_array($jabatan, $jabatanOpsi, true)) {
        $jabatan = 'Anggota';
    }
    $data = [
        'nama' => $nama,
        'jabatan' => $jabatan,
        'no_wa' => trim((string) ($_POST['no_wa'] ?? '')) ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'urutan' => max(0, (int) ($_POST['urutan'] ?? 0)),
        'catatan' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
    ];
    if ($nama === '') {
        set_flash('error', 'Nama pengurus wajib diisi.');
        header('Location: ' . app_href('/yayasan/pengurus.php' . ($editId > 0 ? '?edit=' . $editId : '')));
        exit;
    }

    $idPost = (int) ($_POST['id'] ?? 0);
    if ($idPost > 0) {
        $pdo->prepare('
            UPDATE yayasan_pengurus
            SET nama = :nama, jabatan = :jabatan, no_wa = :no_wa, email = :email, urutan = :urutan, catatan = :catatan
            WHERE id = :id
        ')->execute($data + ['id' => $idPost]);
        set_flash('success', 'Data pengurus diperbarui.');
    } else {
        $pdo->prepare('
            INSERT INTO yayasan_pengurus (nama, jabatan, no_wa, email, urutan, catatan)
            VALUES (:nama, :jabatan, :no_wa, :email, :urutan, :catatan)
        ')->execute($data);
        set_flash('success', 'Pengurus ditambahkan.');
    }
    header('Location: ' . app_href('/yayasan/pengurus.php'));
    exit;
}

$rows = $pdo->query('
    SELECT id, nama, jabatan, no_wa, email, urutan, is_aktif, catatan
    FROM yayasan_pengurus
    ORDER BY urutan ASC, jabatan ASC, nama ASC
')->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
$aktif = count(array_filter($rows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1));

$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$pageTitle = 'Pengurus Yayasan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · Pengurus</p>
    <h1 class="h4 mb-1">Pengurus yayasan</h1>
    <p class="text-muted mb-0">Daftar pengurus aktif yayasan / lembaga induk pondok.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total</div>
            <div class="app-mini-stat-value"><?= $total ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Aktif</div>
            <div class="app-mini-stat-value text-success"><?= $aktif ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Ubah pengurus' : 'Tambah pengurus' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted"><a href="<?= htmlspecialchars(app_href('/yayasan/pengurus.php')) ?>">← Batal edit</a></p>
                <?php endif; ?>
                <form method="post" class="row g-2">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nama</label>
                        <input class="form-control" name="nama" required value="<?= htmlspecialchars((string) ($editRow['nama'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Jabatan</label>
                        <select class="form-select" name="jabatan">
                            <?php foreach ($jabatanOpsi as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>" <?= ($editRow['jabatan'] ?? 'Anggota') === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">No. WA</label>
                        <input class="form-control" name="no_wa" value="<?= htmlspecialchars((string) ($editRow['no_wa'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Urutan tampil</label>
                        <input type="number" class="form-control" name="urutan" min="0" value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan</label>
                        <textarea class="form-control" name="catatan" rows="2"><?= htmlspecialchars((string) ($editRow['catatan'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success w-100"><?= $editRow ? 'Simpan perubahan' : 'Tambah' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar pengurus</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Jabatan</th>
                                <th>Kontak</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">Belum ada data pengurus.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['nama']) ?></strong>
                                        <?php if ((int) $row['urutan'] > 0): ?>
                                            <span class="badge text-bg-light border ms-1">#<?= (int) $row['urutan'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['jabatan']) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars((string) ($row['no_wa'] ?: '-')) ?>
                                        <?php if (!empty($row['email'])): ?>
                                            <br><span class="text-muted"><?= htmlspecialchars((string) $row['email']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= (int) $row['is_aktif'] === 1 ? 'success' : 'secondary' ?>">
                                            <?= (int) $row['is_aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/yayasan/pengurus.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Ubah status aktif?');">
                                            <input type="hidden" name="action" value="toggle_aktif">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Status</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengurus ini?');">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
