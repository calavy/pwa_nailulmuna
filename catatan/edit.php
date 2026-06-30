<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/excel.php';
require_once __DIR__ . '/../helpers/user_catatan.php';

require_login();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$catatanId = (int) ($_GET['id'] ?? 0);
user_catatan_ensure_schema($pdo);

$row = user_catatan_get($pdo, $catatanId, $userId);
if ($row === null) {
    set_flash('error', 'Catatan tidak ditemukan.');
    header('Location: ' . app_href('/catatan/index.php'));
    exit;
}

$isShared = user_catatan_is_shared($row);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isShared) {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_sheet_url') {
        try {
            user_catatan_update_sheet_url($pdo, $catatanId, $userId, (string) ($_POST['sheet_url'] ?? ''));
            set_flash('success', 'Link spreadsheet disimpan.');
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }
        header('Location: ' . app_href('/catatan/edit.php?id=' . $catatanId));
        exit;
    }
}

if (!$isShared && ($_GET['export'] ?? '') === 'xlsx') {
    $grid = user_catatan_grid_from_row($row);
    $filename = user_catatan_safe_filename((string) ($row['judul'] ?? 'catatan'), $catatanId);
    send_xlsx_download($filename, user_catatan_grid_to_xlsx_rows($grid), (string) ($row['judul'] ?? 'Catatan'));
    exit;
}

$judul = (string) ($row['judul'] ?? 'Catatan');
$updatedRaw = (string) ($row['updated_at'] ?? '');
$updatedLabel = $updatedRaw !== '' ? date('d/m/Y H:i', strtotime($updatedRaw) ?: time()) : '-';
$sheetUrl = (string) ($row['sheet_url'] ?? '');
$openUrl = user_catatan_shared_open_url($sheetUrl);
$embedUrl = user_catatan_shared_embed_url($sheetUrl);

$pageTitle = 'Edit Catatan';
$bodyClass = 'catatan-module-page catatan-edit-page';
$pageStylesheets = [app_asset_href('/assets/css/user-catatan.css')];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/catatan/index.php')) ?>">Buku Catatan</a>
        · Edit
    </p>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
            <h1 class="h4 mb-1"><?= htmlspecialchars($judul) ?></h1>
            <p class="text-muted small mb-0">
                <?= htmlspecialchars(user_catatan_tipe_label((string) ($row['tipe'] ?? 'internal'))) ?>
                · Terakhir diubah: <?= htmlspecialchars($updatedLabel) ?>
            </p>
        </div>
        <?php if (!$isShared): ?>
            <span id="catatan-save-status" class="badge text-bg-secondary align-self-center">Memuat…</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($isShared): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h2 class="h6 text-success mb-2"><i class="fa-brands fa-google me-1" aria-hidden="true"></i> Spreadsheet dibagikan</h2>
            <p class="small text-muted mb-3">
                Catatan ini disimpan di Google Sheets (atau layanan spreadsheet online). Edit langsung di sana — lebih cepat dan bisa kolaborasi.
                Pastikan sheet sudah <strong>dibagikan</strong> ke akun Google Anda (izin edit).
            </p>
            <form method="post" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="action" value="save_sheet_url">
                <div class="col-md-9">
                    <label class="form-label small">Link Google Sheets</label>
                    <input type="url" name="sheet_url" class="form-control" value="<?= htmlspecialchars($sheetUrl) ?>"
                           placeholder="https://docs.google.com/spreadsheets/d/..." required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Simpan link</button>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($openUrl !== ''): ?>
                    <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($openUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i> Buka spreadsheet
                    </a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/catatan/index.php')) ?>">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Daftar
                </a>
            </div>
        </div>
    </div>
    <?php if ($embedUrl !== null): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body p-2">
                <iframe
                    class="user-catatan-embed"
                    src="<?= htmlspecialchars($embedUrl) ?>"
                    title="<?= htmlspecialchars($judul) ?>"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
                <p class="small text-muted mb-0 mt-2 px-1">
                    Jika pratinjau kosong atau minta login, gunakan tombol <strong>Buka spreadsheet</strong> di atas.
                </p>
            </div>
        </div>
    <?php elseif ($sheetUrl === ''): ?>
        <div class="alert alert-warning">Isi link Google Sheets di atas untuk mulai.</div>
    <?php else: ?>
        <div class="alert alert-info small mb-0">
            Link ini tidak bisa ditampilkan di dalam halaman. Gunakan tombol <strong>Buka spreadsheet</strong>.
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-success btn-sm" id="catatan-btn-save">
                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Simpan
                </button>
                <button type="button" class="btn btn-outline-dark btn-sm" id="catatan-add-row">+ Baris</button>
                <button type="button" class="btn btn-outline-dark btn-sm" id="catatan-add-col">+ Kolom</button>
                <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/catatan/edit.php?id=' . $catatanId . '&export=xlsx')) ?>">
                    <i class="fa-solid fa-file-arrow-down me-1" aria-hidden="true"></i> Unduh .xlsx
                </a>
                <label class="btn btn-outline-primary btn-sm mb-0">
                    <i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i> Impor .xlsx
                    <input type="file" id="catatan-import-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="d-none">
                </label>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/catatan/index.php')) ?>">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Daftar
                </a>
            </div>
            <p class="small text-muted mb-0 mt-2">Editor ringan — auto-simpan ~1,5 detik. Untuk sheet besar & kolaborasi, buat catatan tipe <strong>Spreadsheet dibagikan</strong> (Google Sheets).</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-2 p-md-3">
            <div id="catatan-spreadsheet" class="user-catatan-spreadsheet"></div>
        </div>
    </div>

    <script>
    window.UserCatatanConfig = {
        catatanId: <?= (int) $catatanId ?>,
        grid: <?= json_encode(user_catatan_grid_from_row($row), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        saveUrl: <?= json_encode(app_href('/api/catatan/save.php'), JSON_UNESCAPED_UNICODE) ?>,
        importUrl: <?= json_encode(app_href('/api/catatan/import.php'), JSON_UNESCAPED_UNICODE) ?>
    };
    </script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/user-catatan-grid.js')) ?>"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
