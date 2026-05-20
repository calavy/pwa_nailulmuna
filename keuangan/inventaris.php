<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_inventaris.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_inventaris_tables($pdo);

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$kategoriOptions = keuangan_inventaris_kategori_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_aset_tetap') {
        $result = keuangan_save_aset_tetap($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'run_penyusutan_aset') {
        $result = keuangan_run_penyusutan_aset($pdo, (string) ($_POST['periode_penyusutan'] ?? date('Y-m')), (int) ($_SESSION['user']['id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'nonaktifkan_aset') {
        $id = (int) ($_POST['aset_id'] ?? 0);
        if (keuangan_nonaktifkan_aset($pdo, $id)) {
            set_flash('success', 'Aset dinonaktifkan dari daftar aktif.');
        } else {
            set_flash('error', 'Gagal menonaktifkan aset.');
        }
    }
    header('Location: /keuangan/inventaris.php');
    exit;
}

ensure_keuangan_transaksi_tables($pdo);
$asetRows = keuangan_fetch_aset_rows($pdo, true);
$ringkas = keuangan_inventaris_ringkas($pdo);
$chart = keuangan_aset_chart_data($asetRows);
$kodeBaru = keuangan_generate_kode_inventaris($pdo);
$akunList = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = 0;
foreach ($akunList as $a) {
    if (!empty($a['is_default'])) {
        $defaultAkunId = (int) ($a['id'] ?? 0);
        break;
    }
}
if ($defaultAkunId <= 0 && $akunList !== []) {
    $defaultAkunId = (int) ($akunList[0]['id'] ?? 0);
}

$pageTitle = 'Inventaris Aset Tetap';
$bodyClass = keuangan_body_class('keuangan-inventaris-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Aset &amp; inventaris</p>
    <h1 class="h4 mb-1">Inventarisasi Aset Tetap</h1>
    <p class="text-muted mb-0">
        Pencatatan aset pondok (tanah, bangunan, kendaraan, peralatan) dan penyusutan bulanan.
        Pembelian aset otomatis mengurangi kas/bank dan membuat jurnal <strong>Debit aset tetap / Kredit kas</strong>.
        Nilai buku masuk ke <a href="/keuangan/neraca.php">neraca</a> dan perolehan ke <a href="/keuangan/arus-kas.php">arus kas investasi</a>.
    </p>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Jumlah aset aktif</div>
            <div class="app-mini-stat-value"><?= (int) $ringkas['jumlah'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total harga perolehan</div>
            <div class="app-mini-stat-value small"><?= htmlspecialchars($formatRupiah((int) $ringkas['total_harga'])) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Akumulasi penyusutan</div>
            <div class="app-mini-stat-value small text-warning"><?= htmlspecialchars($formatRupiah((int) $ringkas['total_akumulasi'])) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Nilai buku</div>
            <div class="app-mini-stat-value small text-primary"><?= htmlspecialchars($formatRupiah((int) $ringkas['total_nilai_buku'])) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100 border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">Tambah aset</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_aset_tetap">
                    <div class="col-md-6">
                        <label class="form-label">Kode inventaris</label>
                        <input class="form-control" name="kode_inventaris" value="<?= htmlspecialchars($kodeBaru) ?>" placeholder="Otomatis jika kosong">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kategori</label>
                        <input class="form-control" name="kategori_aset" list="kat-aset" value="Peralatan" required>
                        <datalist id="kat-aset">
                            <?php foreach ($kategoriOptions as $kat): ?>
                                <option value="<?= htmlspecialchars($kat) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama aset <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_aset" placeholder="Contoh: Mobil operasional / Lemari besi" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lokasi</label>
                        <input class="form-control" name="lokasi" placeholder="Gedung A / Asrama">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal perolehan</label>
                        <input type="date" class="form-control" name="tanggal_perolehan" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Harga perolehan</label>
                        <input class="form-control" name="harga_perolehan" inputmode="numeric" placeholder="0" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nilai residu</label>
                        <input class="form-control" name="nilai_residu" inputmode="numeric" placeholder="0" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Umur manfaat (bulan)</label>
                        <input type="number" min="1" class="form-control" name="umur_manfaat_bulan" value="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Akun kas/bank sumber <span class="text-danger">*</span></label>
                        <select class="form-select" name="akun_id" required>
                            <?php if ($akunList === []): ?>
                                <option value="">— Belum ada akun —</option>
                            <?php else: ?>
                                <?php foreach ($akunList as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $defaultAkunId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $ak['nama_akun']) ?> (<?= htmlspecialchars((string) $ak['jenis_akun']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Metode bayar</label>
                        <select class="form-select" name="metode_keluar">
                            <option value="KAS">Kas</option>
                            <option value="TRANSFER">Transfer</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Keterangan</label>
                        <input class="form-control" name="keterangan" placeholder="Opsional">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100" <?= $akunList === [] ? 'disabled' : '' ?>><i class="fa-solid fa-plus me-1"></i> Simpan aset &amp; jurnal CapEx</button>
                    </div>
                </form>
                <hr>
                <h2 class="h6 mb-2">Penyusutan bulanan</h2>
                <p class="small text-muted">Metode garis lurus: (harga − residu) ÷ umur manfaat. Satu kali per aset per bulan.</p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="run_penyusutan_aset">
                    <div class="col-md-7">
                        <label class="form-label">Periode (YYYY-MM)</label>
                        <input class="form-control" name="periode_penyusutan" value="<?= htmlspecialchars(date('Y-m')) ?>" pattern="\d{4}-\d{2}" required>
                    </div>
                    <div class="col-md-5 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning w-100">Proses penyusutan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Daftar inventaris</span>
                <a class="btn btn-sm btn-outline-secondary" href="/keuangan/index.php">Dashboard</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Aset</th>
                                <th class="text-end">Harga</th>
                                <th class="text-end">Akumulasi</th>
                                <th class="text-end">Nilai buku</th>
                                <th>Penyusutan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($asetRows === []): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada aset tercatat.</td></tr>
                        <?php else: ?>
                            <?php foreach ($asetRows as $as): ?>
                                <?php
                                $harga = (int) round((float) ($as['harga_perolehan'] ?? 0));
                                $akum = (int) round((float) ($as['akumulasi_penyusutan'] ?? 0));
                                $buku = max(0, $harga - $akum);
                                $umur = max(1, (int) ($as['umur_manfaat_bulan'] ?? 12));
                                $bebanBulan = (int) floor(max(0, $harga - (int) round((float) ($as['nilai_residu'] ?? 0))) / $umur);
                                ?>
                                <tr>
                                    <td class="small font-monospace"><?= htmlspecialchars((string) ($as['kode_inventaris'] ?: '-')) ?></td>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars((string) $as['nama_aset']) ?></div>
                                        <div class="text-muted" style="font-size:0.75rem">
                                            <?= htmlspecialchars((string) $as['kategori_aset']) ?>
                                            <?php if (trim((string) ($as['lokasi'] ?? '')) !== ''): ?>
                                                · <?= htmlspecialchars((string) $as['lokasi']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah($harga)) ?></td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah($akum)) ?></td>
                                    <td class="text-end small fw-semibold"><?= htmlspecialchars($formatRupiah($buku)) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars((string) ($as['last_penyusutan_periode'] ?: '—')) ?>
                                        <div class="text-muted" style="font-size:0.7rem">±<?= htmlspecialchars($formatRupiah($bebanBulan)) ?>/bln</div>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Nonaktifkan aset ini?');">
                                            <input type="hidden" name="action" value="nonaktifkan_aset">
                                            <input type="hidden" name="aset_id" value="<?= (int) $as['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Nonaktifkan">×</button>
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
        <?php if ($chart['labels'] !== []): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Grafik nilai buku per aset</h2>
                <div style="height:280px"><canvas id="aset-nilai-chart"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($chart['labels'] !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const el = document.getElementById('aset-nilai-chart');
    if (!el || typeof Chart === 'undefined') return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart['labels'], JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Nilai buku',
                data: <?= json_encode($chart['values'], JSON_UNESCAPED_UNICODE) ?>,
                backgroundColor: '#0d6efd88',
                borderColor: '#0d6efd',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    ticks: {
                        callback: function (v) {
                            return 'Rp ' + Number(v).toLocaleString('id-ID');
                        }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
