<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_pengeluaran_riwayat.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);
pembayaran_edit_token_ensure_schema($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'redeem_token') {
        $res = pembayaran_edit_token_redeem($pdo, $currentUserId, (string) ($_POST['token_plain'] ?? ''));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/riwayat_pengeluaran.php'));
        exit;
    }

    if ($action === 'update_pengeluaran') {
        $res = keuangan_pengeluaran_update($pdo, $_POST, $currentUserId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/riwayat_pengeluaran.php'));
        exit;
    }

    if ($action === 'delete_pengeluaran') {
        $res = keuangan_pengeluaran_delete($pdo, (int) ($_POST['id'] ?? 0), $currentUserId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/riwayat_pengeluaran.php'));
        exit;
    }
}

$tokenRequired = pembayaran_edit_token_required_for_current_user();
$tokenAktif = pembayaran_edit_token_session_aktif($pdo);
$canEdit = pembayaran_edit_token_user_boleh_edit($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$total = keuangan_pengeluaran_count($pdo);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$rows = keuangan_pengeluaran_list($pdo, $perPage, ($page - 1) * $perPage);
$sumPage = 0;
foreach ($rows as $r) {
    $sumPage += (int) ($r['nominal'] ?? 0);
}
$sumAll = keuangan_pengeluaran_sum_nominal($pdo);
$akunRows = keuangan_fetch_akun_aktif($pdo);
$alokasiPengeluaranOpts = keuangan_pengeluaran_alokasi_options($pdo);
$posPengeluaranOpts = keuangan_pengeluaran_pos_options($pdo);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 && $canEdit ? keuangan_pengeluaran_get($pdo, $editId) : null;

$pageTitle = 'Riwayat Pengeluaran';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Keuangan · Pengeluaran</p>
    <h1 class="h4 mb-1">Riwayat Pengeluaran</h1>
    <p class="text-muted mb-0 small">
        Daftar transaksi keluar kas. Koreksi data memerlukan <strong>token super admin</strong>
        (sama dengan token edit pembayaran).
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengeluaran.php')) ?>">Input pengeluaran</a>
        <?php if (is_super_admin()): ?>
            · <a href="<?= htmlspecialchars(app_href('/pembayaran/edit_token.php')) ?>">Kelola token</a>
        <?php endif; ?>
    </p>
</div>

<?php if ($tokenRequired && !$tokenAktif): ?>
<div class="alert alert-warning py-2 small mb-3">
    <i class="fa-solid fa-key me-1"></i>
    Untuk mengedit riwayat, masukkan token yang diterbitkan super admin:
    <form method="post" class="row g-2 align-items-end mt-2">
        <input type="hidden" name="action" value="redeem_token">
        <div class="col-md-6">
            <input class="form-control form-control-sm font-monospace" name="token_plain" placeholder="XXXX-XXXX-XXXX-XXXX" required autocomplete="off">
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-sm btn-warning w-100">Aktifkan token</button>
        </div>
    </form>
</div>
<?php elseif ($tokenRequired && $tokenAktif): ?>
<div class="alert alert-success py-2 small mb-3"><i class="fa-solid fa-unlock me-1"></i>Token aktif — Anda dapat mengoreksi pengeluaran hingga logout.</div>
<?php elseif (!$tokenRequired): ?>
<div class="alert alert-info py-2 small mb-3"><i class="fa-solid fa-user-shield me-1"></i>Super admin: koreksi tanpa token.</div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card shadow-sm border-warning mb-3">
    <div class="card-header bg-warning bg-opacity-10 fw-semibold">Edit pengeluaran #<?= (int) $editRow['id'] ?></div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="update_pengeluaran">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="col-md-3">
                <label class="form-label">Tanggal</label>
                <input type="date" class="form-control" name="tanggal" value="<?= htmlspecialchars((string) $editRow['tanggal']) ?>" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Penanggung jawab</label>
                <input class="form-control" name="penanggung_jawab" value="<?= htmlspecialchars((string) $editRow['penanggung_jawab']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Pos</label>
                <?php
                $posSelected = (string) ($editRow['pos'] ?? '');
                $posFieldName = 'pos';
                $posRequired = true;
                require __DIR__ . '/partials/pos_pengeluaran_select.php';
                ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Alokasi dana <span class="text-danger">*</span></label>
                <?php
                $alokasiSelected = (string) ($editRow['alokasi_nama'] ?? '');
                $alokasiRequired = true;
                require __DIR__ . '/partials/alokasi_pengeluaran_select.php';
                ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Nominal</label>
                <input class="form-control" name="nominal" value="<?= (int) ($editRow['nominal'] ?? 0) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Metode</label>
                <select class="form-select" name="metode_keluar">
                    <option value="KAS" <?= strtoupper((string) ($editRow['metode_keluar'] ?? '')) === 'KAS' ? 'selected' : '' ?>>KAS</option>
                    <option value="TRANSFER" <?= strtoupper((string) ($editRow['metode_keluar'] ?? '')) === 'TRANSFER' ? 'selected' : '' ?>>TRANSFER</option>
                </select>
            </div>
            <?php if ($akunRows !== []): ?>
            <div class="col-md-4">
                <label class="form-label">Akun kas/bank</label>
                <select class="form-select" name="akun_id">
                    <?php foreach ($akunRows as $ar): ?>
                        <option value="<?= (int) ($ar['id'] ?? 0) ?>" <?= (int) ($editRow['akun_id'] ?? 0) === (int) ($ar['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">No. bukti</label>
                <input class="form-control" name="no_bukti" value="<?= htmlspecialchars((string) ($editRow['no_bukti'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Keterangan</label>
                <textarea class="form-control" name="keterangan" rows="2"><?= htmlspecialchars((string) ($editRow['keterangan'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Alasan koreksi <span class="text-danger">*</span></label>
                <input class="form-control" name="alasan" required maxlength="500" placeholder="Mis. salah nominal / pos keliru">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-warning">Simpan perubahan</button>
                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pengeluaran.php')) ?>">Batal</a>
            </div>
        </form>
        <hr class="my-3">
        <form method="post" class="mb-0" onsubmit="return confirm('Yakin hapus pengeluaran #<?= (int) $editRow['id'] ?>? Jurnal terkait ikut dihapus.');">
            <input type="hidden" name="action" value="delete_pengeluaran">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash-can me-1"></i> Hapus pengeluaran</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0 app-table-mobile">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Tanggal</th>
                        <th>Pos</th>
                        <th>Alokasi</th>
                        <th>Penanggung jawab</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="font-monospace small text-muted"><?= (int) ($r['id'] ?? 0) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars((string) ($r['pos'] ?? '')) ?></span>
                            <?php if (trim((string) ($r['keterangan'] ?? '')) !== ''): ?>
                                <div class="text-muted small text-truncate" style="max-width:12rem"><?= htmlspecialchars((string) $r['keterangan']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars((string) ($r['alokasi_nama'] ?? '—')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($r['penanggung_jawab'] ?? '')) ?></td>
                        <td class="text-end font-monospace small fw-semibold text-danger"><?= keuangan_format_rupiah((int) ($r['nominal'] ?? 0)) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($canEdit): ?>
                                <a class="btn btn-sm btn-outline-warning" href="?edit=<?= (int) ($r['id'] ?? 0) ?>&page=<?= $page ?>" title="Edit / hapus"><i class="fa-solid fa-pen"></i></a>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada pengeluaran.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($rows !== []): ?>
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td colspan="5">Jumlah total halaman ini</td>
                        <td class="text-end font-monospace text-danger"><?= keuangan_format_rupiah($sumPage) ?></td>
                        <td></td>
                    </tr>
                    <tr class="small text-muted">
                        <td colspan="5">Jumlah total keseluruhan (<?= $total ?> transaksi)</td>
                        <td class="text-end font-monospace fw-semibold text-danger"><?= keuangan_format_rupiah($sumAll) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer py-2 d-flex justify-content-between align-items-center small">
        <span>Halaman <?= $page ?> / <?= $totalPages ?> · <?= $total ?> transaksi</span>
        <div class="btn-group btn-group-sm">
            <?php if ($page > 1): ?>
                <a class="btn btn-outline-secondary" href="?page=<?= $page - 1 ?>">←</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-outline-secondary" href="?page=<?= $page + 1 ?>">→</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
