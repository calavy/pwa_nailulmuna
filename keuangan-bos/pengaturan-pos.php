<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pos_bos') {
    $result = bos_save_pos_pengeluaran_form($pdo, $_POST);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/keuangan-bos/pengaturan-pos.php'));
    exit;
}

$posRows = bos_fetch_pos_pengeluaran($pdo, false);
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editRow = null;
foreach ($posRows as $pr) {
    if ((int) ($pr['id'] ?? 0) === $editId) {
        $editRow = $pr;
        break;
    }
}

$pageTitle = 'Pos Pengeluaran Lain BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Pos Pengeluaran Lain</h1>
    <p class="text-muted mb-0">Kategori pengeluaran kustom (ATK, Transport, dll.) — terpisah dari alokasi beban COA standar.</p>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan.php')) ?>">Nominal &amp; Akun</a>
    </li>
    <li class="nav-item">
        <span class="nav-link active">Pos Pengeluaran Lain</span>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan.php#saldo-awal')) ?>">Saldo Awal Tahun</a>
    </li>
</ul>

<div class="row g-3">
    <div class="col-lg-5">
        <form method="post" class="card shadow-sm">
            <input type="hidden" name="action" value="save_pos_bos">
            <input type="hidden" name="pos_id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
            <div class="card-header fw-semibold"><?= $editRow ? 'Edit pos' : 'Tambah pos baru' ?></div>
            <div class="card-body row g-3">
                <div class="col-12">
                    <label class="form-label">Nama pos <span class="text-danger">*</span></label>
                    <input type="text" name="nama_pos" class="form-control" maxlength="120" required
                           value="<?= htmlspecialchars((string) ($editRow['nama_pos'] ?? '')) ?>"
                           placeholder="Contoh: ATK &amp; Alat Tulis">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jenjang default</label>
                    <select name="tag_jenjang" class="form-select">
                        <?php foreach (bos_jenjang_options() as $j): ?>
                            <option value="<?= htmlspecialchars($j) ?>" <?= ($editRow['tag_jenjang'] ?? BOS_JENJANG_UMUM) === $j ? 'selected' : '' ?>>
                                <?= htmlspecialchars(bos_label_jenjang($j)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Urutan</label>
                    <input type="number" name="urutan" class="form-control" min="0" value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
                </div>
                <div class="col-12">
                    <p class="small text-muted mb-0">Semua pos lain diposting ke COA <strong>5199</strong> — Beban Operasional Lain-lain.</p>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan</button>
                <?php if ($editRow): ?>
                    <a href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan-pos.php')) ?>" class="btn btn-outline-secondary btn-sm">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Daftar pos</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nama</th>
                            <th>Jenjang</th>
                            <th class="text-center">Urutan</th>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($posRows === []): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">Belum ada pos. Tambahkan di form kiri.</td></tr>
                        <?php else: ?>
                            <?php foreach ($posRows as $pr): ?>
                                <tr class="<?= (int) ($pr['is_active'] ?? 0) !== 1 ? 'text-muted' : '' ?>">
                                    <td><?= htmlspecialchars((string) ($pr['nama_pos'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(bos_label_jenjang((string) ($pr['tag_jenjang'] ?? ''))) ?></td>
                                    <td class="text-center"><?= (int) ($pr['urutan'] ?? 0) ?></td>
                                    <td class="text-center">
                                        <?php if ((int) ($pr['is_active'] ?? 0) === 1): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ((int) ($pr['is_active'] ?? 0) === 1): ?>
                                            <a href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan-pos.php?edit=' . (int) $pr['id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Nonaktifkan pos ini?');">
                                                <input type="hidden" name="action" value="save_pos_bos">
                                                <input type="hidden" name="pos_action" value="delete">
                                                <input type="hidden" name="pos_id" value="<?= (int) $pr['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Nonaktifkan</button>
                                            </form>
                                        <?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
