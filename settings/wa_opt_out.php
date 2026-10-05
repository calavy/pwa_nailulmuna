<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_outbound_dispatch.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

wa_outbound_ensure_schema($pdo);

$statusFilter = trim((string) ($_GET['status'] ?? 'opt_out'));
if (!in_array($statusFilter, ['opt_out', 'opt_in', 'all'], true)) {
    $statusFilter = 'opt_out';
}
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'add_opt_out') {
        $res = wa_opt_out_set(
            $pdo,
            trim((string) ($_POST['target_phone'] ?? '')),
            trim((string) ($_POST['category'] ?? 'all')),
            'opt_out',
            trim((string) ($_POST['note'] ?? ''))
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($action === 'opt_in') {
        $res = wa_opt_out_set(
            $pdo,
            trim((string) ($_POST['target_phone'] ?? '')),
            trim((string) ($_POST['category'] ?? 'all')),
            'opt_in',
            trim((string) ($_POST['note'] ?? 'Dicabut via pengaturan'))
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    header('Location: ' . app_href('/settings/wa_opt_out.php?status=' . rawurlencode($statusFilter)
        . ($categoryFilter !== '' ? '&category=' . rawurlencode($categoryFilter) : '')));
    exit;
}

$listStatus = $statusFilter === 'all' ? null : $statusFilter;
$listCat = $categoryFilter !== '' ? $categoryFilter : null;
$list = wa_opt_out_list($pdo, $listStatus, $listCat, $perPage, $offset);
$rows = $list['rows'];
$total = (int) $list['total'];
$totalPages = max(1, (int) ceil($total / $perPage));
$categories = wa_opt_out_category_whitelist();

$pageTitle = 'Opt-out WA Otomatis';
$settingsNavActive = '/settings/wa_otomatis.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Opt-out penerima WA otomatis</h1>
    <p class="text-muted mb-0 small">Nomor yang meminta tidak menerima kategori tertentu tidak akan di-enqueue ke antrian global.</p>
    <p class="small mb-0 mt-1"><a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=log')) ?>">← Kembali ke WA Otomatis (Riwayat)</a></p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">Tambah opt-out</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="add_opt_out">
            <div class="col-md-4">
                <label class="form-label">Nomor WA</label>
                <input type="text" name="target_phone" class="form-control" placeholder="08xxx / 62xxx" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Kategori</label>
                <select name="category" class="form-select">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat === 'all' ? 'Semua kategori' : $cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Catatan (opsional)</label>
                <input type="text" name="note" class="form-control" maxlength="255" placeholder="Permintaan wali, dll.">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Simpan opt-out</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="opt_out" <?= $statusFilter === 'opt_out' ? 'selected' : '' ?>>Opt-out aktif</option>
                    <option value="opt_in" <?= $statusFilter === 'opt_in' ? 'selected' : '' ?>>Opt-in (dicabut)</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Kategori</label>
                <select name="category" class="form-select">
                    <option value="">Semua</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nomor</th>
                    <th>Kategori</th>
                    <th>Status</th>
                    <th>Catatan</th>
                    <th>Sejak</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Belum ada data.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars((string) ($row['target_phone'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($row['category'] ?? '')) ?></td>
                        <td class="small"><?= ($row['status'] ?? '') === 'opt_out' ? '<span class="badge text-bg-warning">Opt-out</span>' : '<span class="badge text-bg-success">Opt-in</span>' ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($row['note'] ?? '')) ?></td>
                        <td class="text-nowrap small"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                        <td>
                            <?php if (($row['status'] ?? '') === 'opt_out'): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="opt_in">
                                    <input type="hidden" name="target_phone" value="<?= htmlspecialchars((string) ($row['target_phone'] ?? '')) ?>">
                                    <input type="hidden" name="category" value="<?= htmlspecialchars((string) ($row['category'] ?? 'all')) ?>">
                                    <button type="submit" class="btn btn-outline-success btn-sm">Cabut opt-out</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalPages && $p <= 10; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?status=<?= rawurlencode($statusFilter) ?>&category=<?= rawurlencode($categoryFilter) ?>&page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
