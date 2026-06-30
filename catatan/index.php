<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/user_catatan.php';

require_login();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
user_catatan_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $judul = trim((string) ($_POST['judul'] ?? ''));
        $tipe = user_catatan_normalize_tipe((string) ($_POST['tipe'] ?? 'internal'));
        $sheetUrl = trim((string) ($_POST['sheet_url'] ?? ''));
        try {
            $newId = user_catatan_create($pdo, $userId, $judul, $tipe, $sheetUrl);
            set_flash('success', 'Catatan baru dibuat.');
            header('Location: ' . app_href('/catatan/edit.php?id=' . $newId));
            exit;
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (user_catatan_delete($pdo, $id, $userId)) {
            set_flash('success', 'Catatan dihapus.');
        } else {
            set_flash('error', 'Catatan tidak ditemukan.');
        }
        header('Location: ' . app_href('/catatan/index.php'));
        exit;
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $judul = trim((string) ($_POST['judul'] ?? ''));
        if (user_catatan_update_judul($pdo, $id, $userId, $judul)) {
            set_flash('success', 'Judul diperbarui.');
        } else {
            set_flash('error', 'Catatan tidak ditemukan.');
        }
        header('Location: ' . app_href('/catatan/index.php'));
        exit;
    }
}

$items = user_catatan_list($pdo, $userId);

$pageTitle = 'Buku Catatan';
$bodyClass = 'catatan-module-page';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>">Beranda</a>
        · Akun
    </p>
    <h1 class="h4 mb-1">Buku Catatan</h1>
    <p class="text-muted mb-0">Catatan pribadi — editor ringan di aplikasi, atau tautkan Google Sheets yang dibagikan (lebih cepat untuk data besar).</p>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">Catatan baru</h2>
        <form method="post" class="row g-3" id="form-catatan-baru">
            <input type="hidden" name="action" value="create">
            <div class="col-md-6">
                <label class="form-label small">Judul</label>
                <input type="text" name="judul" class="form-control" maxlength="120" placeholder="Mis. Catatan kajian" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small">Jenis</label>
                <select name="tipe" class="form-select" id="catatan-tipe-select">
                    <option value="shared">Spreadsheet dibagikan (Google Sheets) — disarankan</option>
                    <option value="internal">Catatan internal (editor di aplikasi)</option>
                </select>
            </div>
            <div class="col-12" id="catatan-sheet-url-wrap">
                <label class="form-label small">Link Google Sheets</label>
                <input type="url" name="sheet_url" id="catatan-sheet-url" class="form-control"
                       placeholder="https://docs.google.com/spreadsheets/d/... (bagikan ke email Anda, izin edit)">
                <div class="form-text">Buat sheet di Google Drive → Bagikan → salin link. Edit dilakukan di Google, bukan di aplikasi ini.</div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Buat catatan
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('catatan-tipe-select');
    var wrap = document.getElementById('catatan-sheet-url-wrap');
    var urlInput = document.getElementById('catatan-sheet-url');
    function sync() {
        var shared = sel && sel.value === 'shared';
        if (wrap) wrap.style.display = shared ? '' : 'none';
        if (urlInput) urlInput.required = !!shared;
    }
    if (sel) sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php if ($items === []): ?>
    <div class="alert alert-secondary">
        Belum ada catatan. Buat catatan pertama dengan form di atas.
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Judul</th>
                        <th class="d-none d-md-table-cell">Jenis</th>
                        <th class="d-none d-lg-table-cell">Terakhir diubah</th>
                        <th class="text-end" style="min-width:14rem">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $id = (int) ($item['id'] ?? 0);
                        $judul = (string) ($item['judul'] ?? '');
                        $tipe = user_catatan_normalize_tipe((string) ($item['tipe'] ?? 'internal'));
                        $isItemShared = $tipe === 'shared';
                        $updatedRaw = (string) ($item['updated_at'] ?? '');
                        $updatedLabel = $updatedRaw !== '' ? date('d/m/Y H:i', strtotime($updatedRaw) ?: time()) : '-';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($judul) ?></strong>
                                <div class="small text-muted d-md-none">
                                    <?= htmlspecialchars(user_catatan_tipe_label($tipe)) ?> · <?= htmlspecialchars($updatedLabel) ?>
                                </div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <span class="badge <?= $isItemShared ? 'text-bg-success' : 'text-bg-light text-dark border' ?>">
                                    <?= $isItemShared ? 'Google Sheets' : 'Internal' ?>
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell text-muted small"><?= htmlspecialchars($updatedLabel) ?></td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/catatan/edit.php?id=' . $id)) ?>">
                                        <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i> Buka
                                    </a>
                                    <?php if (!$isItemShared): ?>
                                    <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/catatan/edit.php?id=' . $id . '&export=xlsx')) ?>">
                                        <i class="fa-solid fa-file-excel me-1" aria-hidden="true"></i> Unduh
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#renameModal<?= $id ?>">
                                        <i class="fa-solid fa-i-cursor" aria-hidden="true"></i>
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus catatan ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php foreach ($items as $item): ?>
        <?php
        $id = (int) ($item['id'] ?? 0);
        $judul = (string) ($item['judul'] ?? '');
        ?>
        <div class="modal fade" id="renameModal<?= $id ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" class="modal-content">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="modal-header">
                        <h3 class="modal-title h6">Ubah judul</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="text" name="judul" class="form-control" maxlength="120" value="<?= htmlspecialchars($judul) ?>" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="mt-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Kembali ke profil
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
