<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../includes/koperasi_portal_layout.php';

cashless_koperasi_ensure_schema($pdo);
cashless_koperasi_bootstrap_from_user_session($pdo);
$koperasi = cashless_koperasi_require_session($pdo);
$koperasiId = (int) $koperasi['id'];
$koperasiNama = (string) $koperasi['nama'];

$dari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
    $dari = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    $sampai = date('Y-m-d');
}
if ($dari > $sampai) {
    [$dari, $sampai] = [$sampai, $dari];
}

$laporan = cashless_koperasi_laporan_ringkas($pdo, $koperasiId, $dari, $sampai);
$kopTheme = cashless_koperasi_card_theme($koperasiId);
$periodeLabel = app_format_tanggal_id($dari) . ' — ' . app_format_tanggal_id($sampai);

koperasi_portal_layout_begin([
    'title' => 'Laporan — ' . $koperasiNama,
    'koperasi_nama' => $koperasiNama,
    'active' => 'laporan',
]);
?>
<div class="rounded-4 text-white p-3 mb-3 shadow-sm" style="background:<?= htmlspecialchars($kopTheme['gradient']) ?>">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
            <div class="small opacity-75 mb-1"><i class="fa-solid <?= htmlspecialchars($kopTheme['icon']) ?> me-1"></i> Koperasi <?= htmlspecialchars($kopTheme['chip']) ?></div>
            <h1 class="h5 mb-1 fw-bold"><?= htmlspecialchars($koperasiNama) ?></h1>
            <div class="small opacity-90"><?= htmlspecialchars($periodeLabel) ?></div>
        </div>
        <div class="text-end">
            <div class="small opacity-75">Total debit</div>
            <div class="fs-5 fw-bold">Rp <?= number_format((int) $laporan['total_debit'], 0, ',', '.') ?></div>
        </div>
    </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-sm-4">
        <label class="form-label small">Dari</label>
        <input type="date" name="dari" class="form-control" value="<?= htmlspecialchars($dari) ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label small">Sampai</label>
        <input type="date" name="sampai" class="form-control" value="<?= htmlspecialchars($sampai) ?>">
    </div>
    <div class="col-sm-4">
        <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Total Debit</div>
                <div class="h5 mb-0">Rp <?= number_format((int) $laporan['total_debit'], 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Jumlah Transaksi</div>
                <div class="h5 mb-0"><?= (int) $laporan['total_transaksi'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Santri Unik</div>
                <div class="h5 mb-0"><?= (int) $laporan['jumlah_santri'] ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Waktu</th><th>NIS</th><th>Nama</th><th>Tingkatan</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php if ($laporan['rows'] !== []): foreach ($laporan['rows'] as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars(app_format_datetime_id((string) $r['tanggal'])) ?></td>
                        <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                        <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                        <td><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['keterangan'] ?: '-')) ?></td>
                        <td class="text-end">Rp <?= number_format((int) round((float) ($r['nominal'] ?? 0)), 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
koperasi_portal_layout_end();
