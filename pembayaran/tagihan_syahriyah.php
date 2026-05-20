<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_once __DIR__ . '/../helpers/keuangan_syahriyah_potongan.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$bulanMap = keuangan_bulan_map();
$berjalan = keuangan_periode_berjalan($pdo);

$tahunAjaranMulai = max(2000, min(2100, (int) ($_GET['tm'] ?? $berjalan['mulai'])));
$tahunAjaranSelesai = max($tahunAjaranMulai, min(2105, (int) ($_GET['ts'] ?? $berjalan['selesai'])));
$bulanTagihan = max(1, min(12, (int) ($_GET['bulan'] ?? $berjalan['bulan'])));
$q = trim((string) ($_GET['q'] ?? ''));

$tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');

$sql = 'SELECT id, nis, nama_santri, tingkatan, kategori_kelas, is_aktif FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$sql .= ' ORDER BY nama_santri ASC';
$rows = $tablesOk ? $pdo->query($sql)->fetchAll() : [];

$body = [];
$sumTagihan = 0;
$sumBayar = 0;
$countLunas = 0;
$countBelum = 0;
$countSebagian = 0;

foreach ($rows as $s) {
    $namaCari = strtolower((string) ($s['nama_santri'] ?? '') . ' ' . (string) ($s['nis'] ?? ''));
    if ($q !== '' && !str_contains($namaCari, strtolower($q))) {
        continue;
    }
    $kelasKategori = trim((string) ($s['kategori_kelas'] ?? ''));
    if ($kelasKategori === '' && !empty($s['tingkatan'])) {
        $kelasKategori = (string) $s['tingkatan'];
    }
    $st = $tablesOk
        ? tagihan_wajib_status_for_month($pdo, (int) $s['id'], $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $kelasKategori)
        : [
            'expected_total' => 0,
            'paid_total' => 0,
            'sisa_total' => 0,
            'status' => '—',
            'statusClass' => 'secondary',
            'per_pos' => [],
        ];
    $expected = (int) ($st['expected_total'] ?? 0);
    $paid = (int) ($st['paid_total'] ?? 0);
    $sisa = (int) ($st['sisa_total'] ?? 0);
    $status = (string) ($st['status'] ?? '—');
    $statusClass = (string) ($st['statusClass'] ?? 'secondary');
    if ($status === 'Lunas') {
        $countLunas++;
    } elseif ($status === 'Belum') {
        $countBelum++;
    } elseif ($status === 'Sebagian') {
        $countSebagian++;
    }
    $sumTagihan += $expected;
    $sumBayar += min($paid, $expected);

    $perPos = (array) ($st['per_pos'] ?? []);
    $body[] = [
        'id' => (int) $s['id'],
        'nis' => (string) ($s['nis'] ?? ''),
        'nama' => (string) ($s['nama_santri'] ?? ''),
        'tingkatan' => trim((string) ($s['tingkatan'] ?? '')),
        'kategori' => trim((string) ($s['kategori_kelas'] ?? '')),
        'tier' => keuangan_tier_key_from_kelas($kelasKategori, $pdo),
        'tagihan' => $expected,
        'bayar' => $paid,
        'sisa' => $sisa,
        'status' => $status,
        'statusClass' => $statusClass,
        'sy_expected' => (int) (($perPos['syahriyah']['expected'] ?? 0)),
        'sy_dasar' => (int) (($perPos['syahriyah']['expected_dasar'] ?? $perPos['syahriyah']['expected'] ?? 0)),
        'sy_persen' => (float) (($perPos['syahriyah']['persen_potongan'] ?? 0)),
        'sy_ket_potongan' => (string) (($perPos['syahriyah']['keterangan_potongan'] ?? '')),
        'sy_dijeda' => !empty($perPos['syahriyah']['potongan_dijeda']),
        'sy_paid' => (int) (($perPos['syahriyah']['paid'] ?? 0)),
        'mk_expected' => (int) (($perPos['makan']['expected'] ?? 0)),
        'mk_paid' => (int) (($perPos['makan']['paid'] ?? 0)),
    ];
}

$pageTitle = 'Tagihan Bulanan';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconTagihan = bendahara_page_icon('tagihan');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1"></i>
        <a href="/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon"><i class="fa-solid <?= htmlspecialchars($iconTagihan) ?>"></i></span>
        Tagihan Bulanan
    </h1>
    <p class="text-muted mb-0">
        Tagihan wajib <strong>Syahriyah</strong> dan <strong>Makan</strong> per bulan.
        Potongan syahriyah per santri diatur di
        <a href="/keuangan/potongan_syahriyah.php">Pengaturan potongan syahriyah</a>.
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/keuangan/pembayaran.php">Input pembayaran</a> sekali untuk inisialisasi skema.</div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select class="form-select form-select-sm" name="bulan">
            <?php foreach ($bulanMap as $b => $label): ?>
                <option value="<?= $b ?>" <?= $b === $bulanTagihan ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran mulai</label>
        <input class="form-control form-control-sm" type="number" name="tm" value="<?= (int) $tahunAjaranMulai ?>" min="2000" max="2100">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran selesai</label>
        <input class="form-control form-control-sm" type="number" name="ts" value="<?= (int) $tahunAjaranSelesai ?>" min="2000" max="2105">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Opsional">
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-tagihan">
            <div class="app-mini-stat-label">Total tagihan (filter)</div>
            <div class="app-mini-stat-value">Rp <?= number_format($sumTagihan, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-bayar">
            <div class="app-mini-stat-label">Terpenuhi</div>
            <div class="app-mini-stat-value text-success">Rp <?= number_format($sumBayar, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-lunas">
            <div class="app-mini-stat-label">Lunas</div>
            <div class="app-mini-stat-value"><?= $countLunas ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-sebagian">
            <div class="app-mini-stat-label">Sebagian</div>
            <div class="app-mini-stat-value text-warning"><?= $countSebagian ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Belum</div>
            <div class="app-mini-stat-value text-danger"><?= $countBelum ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Kelas / kategori</th>
                        <th class="text-center">Tier</th>
                        <th class="text-end">Syahriyah</th>
                        <th class="text-end">Makan</th>
                        <th class="text-end">Total tagihan</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$body): ?>
                    <tr><td colspan="11" class="text-muted text-center py-4">Tidak ada data santri aktif atau tidak cocok filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($body as $r): ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars($r['nis']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama']) ?></td>
                        <td class="small">
                            <?php
                            $kkDisp = trim((string) ($r['kategori'] ?? ''));
                            if ($kkDisp === '') {
                                echo htmlspecialchars($r['tingkatan'] !== '' ? $r['tingkatan'] : '—');
                            } else {
                                echo htmlspecialchars(kelas_keuangan_label_for_kode($pdo, $kkDisp));
                            }
                            ?>
                        </td>
                        <td class="text-center small text-uppercase"><?= htmlspecialchars($r['tier']) ?></td>
                        <td class="text-end font-monospace small" title="Bayar / tagihan">
                            <?php if ($r['sy_expected'] > 0): ?>
                                Rp <?= number_format($r['sy_paid'], 0, ',', '.') ?> / <?= number_format($r['sy_expected'], 0, ',', '.') ?>
                                <?php if (!empty($r['sy_dijeda'])): ?>
                                    <span class="d-block text-secondary" style="font-size:.7rem" title="Tarif dasar Rp <?= number_format($r['sy_dasar'], 0, ',', '.') ?>">
                                        Potongan dijeda bulan ini (tarif penuh)
                                    </span>
                                <?php elseif ($r['sy_persen'] > 0 && $r['sy_ket_potongan'] !== ''): ?>
                                    <span class="d-block text-warning" style="font-size:.7rem" title="Tarif dasar Rp <?= number_format($r['sy_dasar'], 0, ',', '.') ?>">
                                        −<?= rtrim(rtrim(number_format($r['sy_persen'], 1, ',', '.'), '0'), ',') ?>% · <?= htmlspecialchars($r['sy_ket_potongan']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small" title="Bayar / tagihan">
                            <?php if ($r['mk_expected'] > 0): ?>
                                Rp <?= number_format($r['mk_paid'], 0, ',', '.') ?> / <?= number_format($r['mk_expected'], 0, ',', '.') ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small"><?= $r['tagihan'] > 0 ? 'Rp ' . number_format($r['tagihan'], 0, ',', '.') : '—' ?></td>
                        <td class="text-end font-monospace small"><?= $r['bayar'] > 0 ? 'Rp ' . number_format($r['bayar'], 0, ',', '.') : '—' ?></td>
                        <td class="text-end font-monospace small"><?= $r['sisa'] > 0 ? 'Rp ' . number_format($r['sisa'], 0, ',', '.') : '—' ?></td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= htmlspecialchars($r['statusClass']) ?>"><?= htmlspecialchars($r['status']) ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="/keuangan/pembayaran.php?santri_id=<?= (int) $r['id'] ?>&bulan=<?= (int) $bulanTagihan ?>&tm=<?= (int) $tahunAjaranMulai ?>&ts=<?= (int) $tahunAjaranSelesai ?>"><i class="fa-solid fa-money-bill-wave me-1"></i> Bayar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
