<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_perbaikan_kas.php';
require_once __DIR__ . '/../helpers/keuangan_input_dobel.php';
require_once __DIR__ . '/../helpers/keuangan_validasi_pesan.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);
keuangan_input_dobel_ensure_schema($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$isSuperAdmin = is_super_admin();
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        if (!$isSuperAdmin) {
            set_flash('error', 'Hanya super admin yang dapat menghapus transaksi.');
            header('Location: ' . app_href('/keuangan/perbaikan-input-dobel.php'));
            exit;
        }
        $res = keuangan_perbaikan_kas_hapus(
            $pdo,
            (string) ($_POST['tipe'] ?? ''),
            (int) ($_POST['id'] ?? 0),
            $currentUserId,
            (string) ($_POST['alasan'] ?? '')
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-input-dobel.php'));
        exit;
    }
}

$ringkas = keuangan_input_dobel_ringkas($pdo);
$duplikatPembayaran = keuangan_perbaikan_kas_list_duplikat_mungkin($pdo, 50);
$duplikatPemasukan = keuangan_input_dobel_list_duplikat_pemasukan($pdo, 50);
$duplikatPengeluaran = keuangan_input_dobel_list_duplikat_pengeluaran($pdo, 50);

$pageTitle = 'Perbaikan Input Dobel';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a> · Perbaikan</p>
    <h1 class="h3 mb-1">Perbaikan Input Dobel</h1>
    <p class="text-muted small mb-0">
        Temukan dan bersihkan transaksi yang tercatat lebih dari sekali (pembayaran santri, pemasukan, pengeluaran).
        Input baru sudah ditolak otomatis jika identik dengan entri sebelumnya.
        <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>">Perbaikan Kas</a>
        · <a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php')) ?>">Riwayat masuk &amp; keluar</a>
    </p>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-danger h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Pembayaran dobel</div>
                <div class="h3 mb-0 text-danger"><?= (int) ($ringkas['pembayaran'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-warning h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Pemasukan dobel</div>
                <div class="h3 mb-0 text-warning"><?= (int) ($ringkas['pemasukan'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-secondary h-100">
            <div class="card-body text-center">
                <div class="text-muted small">Pengeluaran dobel</div>
                <div class="h3 mb-0"><?= (int) ($ringkas['pengeluaran'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (($ringkas['total'] ?? 0) === 0): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-1"></i> Tidak ada grup transaksi dobel terdeteksi saat ini.
</div>
<?php endif; ?>

<?php
$defPb = keuangan_kesalahan_kas_def('pembayaran_dobel');
$defPm = keuangan_kesalahan_kas_def('pemasukan_dobel');
$defPg = keuangan_kesalahan_kas_def('pengeluaran_dobel');
?>

<?php if ($duplikatPembayaran !== []): ?>
<div class="card shadow-sm mb-3 border-danger" id="duplikat-pembayaran">
    <div class="card-header bg-white">
        <strong><?= htmlspecialchars((string) $defPb['judul']) ?></strong>
        <span class="badge bg-danger ms-1"><?= count($duplikatPembayaran) ?></span>
    </div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-2 mb-0">
            <?= htmlspecialchars((string) $defPb['penjelasan'] . ' ' . $defPb['dampak']) ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Santri</th>
                        <th>Tanggal</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Jumlah</th>
                        <th>ID transaksi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($duplikatPembayaran as $dup):
                    $ids = keuangan_input_dobel_parse_ids($dup);
                    $idHapus = count($ids) > 1 ? (int) $ids[count($ids) - 1] : (int) ($dup['id_terakhir'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dup['nis'] ?? '') . ' — ' . (string) ($dup['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($dup['tanggal_bayar'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($dup['nominal'] ?? 0)))) ?></td>
                        <td class="text-center"><span class="badge bg-danger"><?= (int) ($dup['jumlah'] ?? 0) ?>×</span></td>
                        <td class="small text-muted"><?= htmlspecialchars(implode(', ', array_map(static fn($id) => '#' . $id, $ids))) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= htmlspecialchars(keuangan_perbaikan_kas_edit_url('pembayaran', $idHapus)) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($isSuperAdmin && $idHapus > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#hapusModal"
                                data-tipe="pembayaran" data-id="<?= $idHapus ?>"
                                data-label="Pembayaran #<?= $idHapus ?> — <?= htmlspecialchars((string) ($dup['nama_santri'] ?? '')) ?>">
                                Hapus duplikat
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($duplikatPemasukan !== []): ?>
<div class="card shadow-sm mb-3 border-warning" id="duplikat-pemasukan">
    <div class="card-header bg-white">
        <strong><?= htmlspecialchars((string) $defPm['judul']) ?></strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($duplikatPemasukan) ?></span>
    </div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-2 mb-0">
            <?= htmlspecialchars((string) $defPm['penjelasan'] . ' ' . $defPm['dampak']) ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Sumber</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Jumlah</th>
                        <th>ID transaksi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($duplikatPemasukan as $dup):
                    $ids = keuangan_input_dobel_parse_ids($dup);
                    $idHapus = count($ids) > 1 ? (int) $ids[count($ids) - 1] : (int) ($dup['id_terakhir'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dup['tanggal'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($dup['sumber'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($dup['nominal'] ?? 0)))) ?></td>
                        <td class="text-center"><span class="badge bg-warning text-dark"><?= (int) ($dup['jumlah'] ?? 0) ?>×</span></td>
                        <td class="small text-muted"><?= htmlspecialchars(implode(', ', array_map(static fn($id) => '#' . $id, $ids))) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= htmlspecialchars(keuangan_perbaikan_kas_edit_url('pemasukan', $idHapus)) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($isSuperAdmin && $idHapus > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#hapusModal"
                                data-tipe="pemasukan" data-id="<?= $idHapus ?>"
                                data-label="Pemasukan #<?= $idHapus ?> — <?= htmlspecialchars((string) ($dup['sumber'] ?? '')) ?>">
                                Hapus duplikat
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($duplikatPengeluaran !== []): ?>
<div class="card shadow-sm mb-3 border-secondary" id="duplikat-pengeluaran">
    <div class="card-header bg-white">
        <strong><?= htmlspecialchars((string) $defPg['judul']) ?></strong>
        <span class="badge bg-secondary ms-1"><?= count($duplikatPengeluaran) ?></span>
    </div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-2 mb-0">
            <?= htmlspecialchars((string) $defPg['penjelasan'] . ' ' . $defPg['dampak']) ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Pos / alokasi</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Jumlah</th>
                        <th>ID transaksi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($duplikatPengeluaran as $dup):
                    $ids = keuangan_input_dobel_parse_ids($dup);
                    $idHapus = count($ids) > 1 ? (int) $ids[count($ids) - 1] : (int) ($dup['id_terakhir'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dup['tanggal'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($dup['pos'] ?? '') . ' · ' . (string) ($dup['alokasi_nama'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($dup['nominal'] ?? 0)))) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int) ($dup['jumlah'] ?? 0) ?>×</span></td>
                        <td class="small text-muted"><?= htmlspecialchars(implode(', ', array_map(static fn($id) => '#' . $id, $ids))) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= htmlspecialchars(keuangan_perbaikan_kas_edit_url('pengeluaran', $idHapus)) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($isSuperAdmin && $idHapus > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#hapusModal"
                                data-tipe="pengeluaran" data-id="<?= $idHapus ?>"
                                data-label="Pengeluaran #<?= $idHapus ?> — <?= htmlspecialchars((string) ($dup['pos'] ?? '')) ?>">
                                Hapus duplikat
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isSuperAdmin): ?>
<div class="alert alert-light border small">
    Hapus baris duplikat hanya tersedia untuk <strong>super admin</strong>. Pengurus dapat membuka edit transaksi untuk koreksi manual.
</div>
<?php endif; ?>

<div class="modal fade" id="hapusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tipe" id="hapusTipe">
            <input type="hidden" name="id" id="hapusId">
            <div class="modal-header">
                <h5 class="modal-title">Hapus transaksi dobel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Anda akan menghapus: <strong id="hapusLabel"></strong></p>
                <p class="small text-muted">Biarkan satu baris asli; hapus hanya entri duplikat.</p>
                <label class="form-label">Alasan penghapusan <span class="text-danger">*</span></label>
                <textarea name="alasan" class="form-control" rows="3" required placeholder="Contoh: entri ganda karena double-click"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus permanen</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('hapusModal')?.addEventListener('show.bs.modal', function (ev) {
    const btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('hapusTipe').value = btn.getAttribute('data-tipe') || '';
    document.getElementById('hapusId').value = btn.getAttribute('data-id') || '';
    document.getElementById('hapusLabel').textContent = btn.getAttribute('data-label') || '';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
