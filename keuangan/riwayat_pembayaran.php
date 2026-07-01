<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_riwayat_pembayaran.php';
require_once __DIR__ . '/../helpers/keuangan_pengeluaran_riwayat.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';

require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$filter = keuangan_riwayat_pembayaran_parse_filter($_GET);
$isSuperAdmin = is_super_admin();
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$redirectQs = keuangan_riwayat_pembayaran_query_string($filter);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSuperAdmin) {
    $action = (string) ($_POST['action'] ?? '');
    $postFilter = keuangan_riwayat_pembayaran_parse_filter([
        'dari' => (string) ($_POST['_filter_dari'] ?? $filter['dari']),
        'sampai' => (string) ($_POST['_filter_sampai'] ?? $filter['sampai']),
        'arah' => (string) ($_POST['_filter_arah'] ?? $filter['arah']),
        'pos' => (string) ($_POST['_filter_pos'] ?? $filter['pos']),
    ]);
    $backQs = keuangan_riwayat_pembayaran_query_string($postFilter);

    if ($action === 'update_pengeluaran') {
        $res = keuangan_pengeluaran_update($pdo, $_POST, $currentUserId);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/keuangan/riwayat_pembayaran.php?' . $backQs));
        exit;
    }
    if ($action === 'delete_pengeluaran') {
        $res = keuangan_pengeluaran_delete($pdo, (int) ($_POST['id'] ?? 0), $currentUserId);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/keuangan/riwayat_pembayaran.php?' . $backQs));
        exit;
    }
}

$data = keuangan_riwayat_pembayaran_fetch($pdo, $filter);
$rows = $data['rows'];
$posOptions = keuangan_riwayat_pembayaran_pos_options($pdo);
$periodeLabel = keuangan_riwayat_pembayaran_label_periode($filter['dari'], $filter['sampai']);

$editKeluarId = $isSuperAdmin ? (int) ($_GET['edit_keluar'] ?? 0) : 0;
$editKeluarRow = $editKeluarId > 0 ? keuangan_pengeluaran_get($pdo, $editKeluarId) : null;
$akunRows = keuangan_fetch_akun_aktif($pdo);
$alokasiPengeluaranOpts = keuangan_pengeluaran_alokasi_options($pdo);

$pageTitle = 'Riwayat Pembayaran';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Keuangan · Transaksi</p>
    <h1 class="h4 mb-1">Riwayat Masuk & Keluar</h1>
    <p class="text-muted mb-0 small">
        Uang <strong>masuk</strong> dari pembayaran santri dan <strong>keluar</strong> dari pengeluaran kas.
        Filter per rentang tanggal dan pos keuangan.
        <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a>
        · <a href="<?= htmlspecialchars(app_href('/keuangan/pengeluaran.php')) ?>">Input pengeluaran</a>
    </p>
</div>

<?php if ($isSuperAdmin): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="fa-solid fa-user-shield me-1"></i>
    Super admin — Anda dapat mengedit dan menghapus transaksi dari halaman ini.
</div>
<?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Dari tanggal</label>
        <input type="date" name="dari" class="form-control form-control-sm" value="<?= htmlspecialchars($filter['dari']) ?>" required>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Sampai tanggal</label>
        <input type="date" name="sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($filter['sampai']) ?>" required>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold">Arah</label>
        <select name="arah" class="form-select form-select-sm">
            <option value="" <?= $filter['arah'] === '' ? 'selected' : '' ?>>Semua (masuk & keluar)</option>
            <option value="masuk" <?= $filter['arah'] === 'masuk' ? 'selected' : '' ?>>Masuk saja</option>
            <option value="keluar" <?= $filter['arah'] === 'keluar' ? 'selected' : '' ?>>Keluar saja</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold text-primary">
            <i class="fa-solid fa-tags me-1"></i>Pos keuangan
        </label>
        <select name="pos" class="form-select form-select-sm border-primary-subtle">
            <option value="">Semua pos</option>
            <?php
            $lastGroup = '';
            foreach ($posOptions as $opt):
                $grp = (string) ($opt['group'] ?? '');
                if ($grp !== '' && $grp !== $lastGroup):
                    if ($lastGroup !== '') {
                        echo '</optgroup>';
                    }
                    echo '<optgroup label="' . htmlspecialchars($grp) . '">';
                    $lastGroup = $grp;
                endif;
                $val = (string) ($opt['value'] ?? '');
                ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $filter['pos'] === $val ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($opt['label'] ?? $val)) ?>
                </option>
            <?php endforeach;
            if ($lastGroup !== '') {
                echo '</optgroup>';
            }
            ?>
        </select>
    </div>
    <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php')) ?>" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100 border-start border-success border-3">
            <div class="card-body py-2">
                <div class="small text-muted">Total masuk · <?= htmlspecialchars($periodeLabel) ?></div>
                <div class="h5 mb-0 text-success"><?= keuangan_format_rupiah((int) $data['total_masuk']) ?></div>
                <div class="small text-muted"><?= (int) $data['jumlah_masuk'] ?> transaksi</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100 border-start border-danger border-3">
            <div class="card-body py-2">
                <div class="small text-muted">Total keluar · <?= htmlspecialchars($periodeLabel) ?></div>
                <div class="h5 mb-0 text-danger"><?= keuangan_format_rupiah((int) $data['total_keluar']) ?></div>
                <div class="small text-muted"><?= (int) $data['jumlah_keluar'] ?> transaksi</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-2">
                <div class="small text-muted">Selisih (masuk − keluar)</div>
                <?php $selisih = (int) $data['total_masuk'] - (int) $data['total_keluar']; ?>
                <div class="h5 mb-0 <?= $selisih >= 0 ? 'text-success' : 'text-danger' ?>"><?= keuangan_format_rupiah($selisih) ?></div>
                <div class="small text-muted"><?= count($rows) ?> baris ditampilkan</div>
            </div>
        </div>
    </div>
</div>

<?php if ($editKeluarRow): ?>
<div class="card shadow-sm border-warning mb-3">
    <div class="card-header bg-warning bg-opacity-10 fw-semibold">Edit pengeluaran #<?= (int) $editKeluarRow['id'] ?></div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="update_pengeluaran">
            <input type="hidden" name="id" value="<?= (int) $editKeluarRow['id'] ?>">
            <input type="hidden" name="_filter_dari" value="<?= htmlspecialchars($filter['dari']) ?>">
            <input type="hidden" name="_filter_sampai" value="<?= htmlspecialchars($filter['sampai']) ?>">
            <input type="hidden" name="_filter_arah" value="<?= htmlspecialchars($filter['arah']) ?>">
            <input type="hidden" name="_filter_pos" value="<?= htmlspecialchars($filter['pos']) ?>">
            <div class="col-md-3">
                <label class="form-label small">Tanggal</label>
                <input type="date" class="form-control form-control-sm" name="tanggal" value="<?= htmlspecialchars((string) $editKeluarRow['tanggal']) ?>" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small">Penanggung jawab</label>
                <input class="form-control form-control-sm" name="penanggung_jawab" value="<?= htmlspecialchars((string) $editKeluarRow['penanggung_jawab']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Pos</label>
                <input class="form-control form-control-sm" name="pos" value="<?= htmlspecialchars((string) $editKeluarRow['pos']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Alokasi dana <span class="text-danger">*</span></label>
                <?php
                $alokasiSelected = (string) ($editKeluarRow['alokasi_nama'] ?? '');
                $alokasiRequired = true;
                require __DIR__ . '/partials/alokasi_pengeluaran_select.php';
                ?>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Nominal</label>
                <input class="form-control form-control-sm" name="nominal" value="<?= (int) ($editKeluarRow['nominal'] ?? 0) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Metode</label>
                <select class="form-select form-select-sm" name="metode_keluar">
                    <option value="KAS" <?= strtoupper((string) ($editKeluarRow['metode_keluar'] ?? '')) === 'KAS' ? 'selected' : '' ?>>KAS</option>
                    <option value="TRANSFER" <?= strtoupper((string) ($editKeluarRow['metode_keluar'] ?? '')) === 'TRANSFER' ? 'selected' : '' ?>>TRANSFER</option>
                </select>
            </div>
            <?php if ($akunRows !== []): ?>
            <div class="col-md-4">
                <label class="form-label small">Akun kas/bank</label>
                <select class="form-select form-select-sm" name="akun_id">
                    <?php foreach ($akunRows as $ar): ?>
                        <option value="<?= (int) ($ar['id'] ?? 0) ?>" <?= (int) ($editKeluarRow['akun_id'] ?? 0) === (int) ($ar['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small">No. bukti</label>
                <input class="form-control form-control-sm" name="no_bukti" value="<?= htmlspecialchars((string) ($editKeluarRow['no_bukti'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label small">Keterangan</label>
                <textarea class="form-control form-control-sm" name="keterangan" rows="2"><?= htmlspecialchars((string) ($editKeluarRow['keterangan'] ?? '')) ?></textarea>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-warning btn-sm">Simpan perubahan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php?' . $redirectQs)) ?>">Batal</a>
            </div>
        </form>
        <hr class="my-3">
        <form method="post" class="mb-0" onsubmit="return confirm('Yakin hapus pengeluaran #<?= (int) $editKeluarRow['id'] ?>? Jurnal terkait ikut dihapus.');">
            <input type="hidden" name="action" value="delete_pengeluaran">
            <input type="hidden" name="id" value="<?= (int) $editKeluarRow['id'] ?>">
            <input type="hidden" name="_filter_dari" value="<?= htmlspecialchars($filter['dari']) ?>">
            <input type="hidden" name="_filter_sampai" value="<?= htmlspecialchars($filter['sampai']) ?>">
            <input type="hidden" name="_filter_arah" value="<?= htmlspecialchars($filter['arah']) ?>">
            <input type="hidden" name="_filter_pos" value="<?= htmlspecialchars($filter['pos']) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash-can me-1"></i> Hapus pengeluaran</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Transaksi <?= htmlspecialchars($periodeLabel) ?></span>
        <?php if ($filter['arah'] !== '' || $filter['pos'] !== ''): ?>
            <span class="badge text-bg-primary fw-normal">Filter aktif</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0 app-table-mobile">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Arah</th>
                        <th>Subjek</th>
                        <th>POS / komponen</th>
                        <th>Keterangan</th>
                        <th>Metode</th>
                        <th class="text-end">Nominal</th>
                        <?php if ($isSuperAdmin): ?><th class="text-end">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $isMasuk = ($r['arah'] ?? '') === 'masuk';
                    $rowId = (int) ($r['id'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars(app_format_tanggal_id((string) ($r['tanggal'] ?? ''))) ?></td>
                        <td>
                            <?php if ($isMasuk): ?>
                                <span class="badge text-bg-success">Masuk</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Keluar</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($r['subjek'] ?? '—')) ?></div>
                            <?php if (trim((string) ($r['subjek_extra'] ?? '')) !== ''): ?>
                                <div class="text-muted font-monospace" style="font-size:0.75rem"><?= htmlspecialchars((string) $r['subjek_extra']) ?></div>
                            <?php endif; ?>
                            <?php if (!$isMasuk): ?>
                                <div class="text-muted" style="font-size:0.7rem">Pengeluaran #<?= $rowId ?></div>
                            <?php else: ?>
                                <div class="text-muted" style="font-size:0.7rem">Pembayaran #<?= $rowId ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small" style="max-width:12rem"><?= htmlspecialchars((string) ($r['pos'] ?? '—')) ?></td>
                        <td class="small text-muted text-truncate" style="max-width:10rem"><?= htmlspecialchars(trim((string) ($r['keterangan'] ?? '')) !== '' ? (string) $r['keterangan'] : '—') ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($r['metode'] ?? 'KAS')) ?></td>
                        <td class="text-end font-monospace small fw-semibold <?= $isMasuk ? 'text-success' : 'text-danger' ?>">
                            <?= $isMasuk ? '' : '−' ?><?= keuangan_format_rupiah((int) ($r['nominal'] ?? 0)) ?>
                        </td>
                        <?php if ($isSuperAdmin): ?>
                        <td class="text-end text-nowrap">
                            <?php if ($isMasuk): ?>
                                <a class="btn btn-outline-warning btn-sm py-0 px-1 me-1"
                                   href="<?= htmlspecialchars(app_url('pembayaran/riwayat_edit.php?id=' . $rowId . '&return=' . rawurlencode(app_href('/keuangan/riwayat_pembayaran.php?' . $redirectQs)))) ?>"
                                   title="Edit / hapus pembayaran">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                <a class="btn btn-outline-secondary btn-sm py-0 px-1"
                                   href="<?= htmlspecialchars(app_href('/keuangan/kuitansi.php?id=' . $rowId)) ?>"
                                   target="_blank" rel="noopener" title="Kuitansi">
                                    <i class="fa-solid fa-receipt"></i>
                                </a>
                            <?php else: ?>
                                <a class="btn btn-outline-warning btn-sm py-0 px-1"
                                   href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php?' . keuangan_riwayat_pembayaran_query_string($filter, ['edit_keluar' => (string) $rowId]))) ?>"
                                   title="Edit / hapus pengeluaran">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= $isSuperAdmin ? 8 : 7 ?>" class="text-center text-muted py-4">
                            Tidak ada transaksi pada filter ini.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
