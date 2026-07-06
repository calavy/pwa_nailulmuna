<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$sumberSuggest = keuangan_pemasukan_sumber_suggest();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pemasukan') {
    $result = keuangan_save_pemasukan($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/keuangan/pemasukan.php'));
    exit;
}

$akunRows = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = 0;
foreach ($akunRows as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunId = (int) $ar['id'];
        break;
    }
}
if ($defaultAkunId <= 0 && $akunRows !== []) {
    $defaultAkunId = (int) ($akunRows[0]['id'] ?? 0);
}

$recentRows = keuangan_recent_pemasukan($pdo, 20);
$sumRecent = 0;
foreach ($recentRows as $pr) {
    $sumRecent += (int) round((float) ($pr['nominal'] ?? 0));
}

$pageTitle = 'Input Pemasukan Lain';
$bodyClass = keuangan_body_class('keuangan-form-page');
$pageScripts = [app_asset_href('/assets/js/keuangan-form-validasi.js')];
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($akunRows === []): ?>
<div class="alert alert-danger mb-3">
    <i class="fa-solid fa-circle-xmark me-1"></i>
    <strong>Tidak dapat input pemasukan.</strong> Belum ada akun kas/bank aktif.
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=akun')) ?>">Buat akun kas</a> dulu.
</div>
<?php endif; ?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pemasukan</p>
    <h1 class="h4 mb-1">Pemasukan dari Sumber Lain</h1>
    <p class="text-muted mb-0">
        Catat uang masuk selain pembayaran santri (donasi, hibah, bantuan, bunga bank, dll.).
        Untuk pembayaran syahriyah/saku santri gunakan
        <a href="/keuangan/pembayaran.php">Input pembayaran</a>.
        · <a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pemasukan.php')) ?>">Riwayat pemasukan</a>
        · <a href="/keuangan/index.php">Dashboard keuangan</a>
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-6" id="formulir-pemasukan">
        <div class="card shadow-sm">
            <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">Uang masuk</div>
            <div class="card-body">
                <form method="post" class="row g-2" data-keuangan-validasi data-keuangan-nominal="nominal_pemasukan"<?= $akunRows === [] ? ' data-keuangan-cek-akun="0"' : '' ?>>
                    <input type="hidden" name="action" value="save_pemasukan">
                    <div class="col-md-4">
                        <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tanggal_pemasukan" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Sumber / jenis pemasukan <span class="text-danger">*</span></label>
                        <input class="form-control" name="sumber_pemasukan" list="sumber-suggest" placeholder="Contoh: Donasi umum" required>
                        <datalist id="sumber-suggest">
                            <?php foreach ($sumberSuggest as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Dari pihak / pemberi</label>
                        <input class="form-control" name="dari_pihak" placeholder="Nama donatur, lembaga, atau keterangan singkat">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Metode</label>
                        <select class="form-select" name="metode_bayar">
                            <option value="KAS">Kas</option>
                            <option value="TRANSFER">Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Akun penerimaan <span class="text-danger">*</span></label>
                        <select class="form-select" name="akun_id" required>
                            <?php if ($akunRows === []): ?>
                                <option value="">Belum ada akun</option>
                            <?php else: ?>
                                <?php foreach ($akunRows as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $defaultAkunId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $ak['jenis_akun']) ?> — <?= htmlspecialchars((string) $ak['nama_akun']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nominal <span class="text-danger">*</span></label>
                        <input class="form-control" name="nominal_pemasukan" inputmode="numeric" placeholder="0" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">No. bukti</label>
                        <input class="form-control" name="no_bukti" placeholder="Opsional / wajib transfer">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Keterangan</label>
                        <input class="form-control" name="keterangan_pemasukan" placeholder="Catatan tambahan">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"<?= $akunRows === [] ? ' disabled' : '' ?>><i class="fa-solid fa-plus-circle me-1"></i> Simpan pemasukan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Pemasukan lain terakhir</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Sumber</th>
                                <th class="text-end">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentRows === []): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Belum ada pemasukan lain tercatat.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRows as $pr): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars((string) $pr['tanggal']) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars((string) $pr['sumber']) ?>
                                        <?php if (!empty($pr['dari_pihak'])): ?>
                                            <div class="text-muted"><?= htmlspecialchars((string) $pr['dari_pihak']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah((int) ((float) $pr['nominal']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if ($recentRows !== []): ?>
                        <tfoot class="table-light">
                            <tr class="fw-semibold">
                                <td colspan="2">Jumlah total (<?= count($recentRows) ?> entri)</td>
                                <td class="text-end"><?= htmlspecialchars($formatRupiah($sumRecent)) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
