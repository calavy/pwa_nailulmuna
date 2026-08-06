<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

$useRange = isset($_GET['bulan_mulai']) || isset($_GET['bulan_selesai']);
$periodeRange = bos_resolve_periode_range($_GET);
$periodeMasehi = bos_resolve_periode_masehi($_GET);
$bulan = (int) $periodeMasehi['bulan'];
$tahun = (int) $periodeMasehi['tahun'];
$jenjang = strtolower(trim((string) ($_GET['jenjang'] ?? '')));

if ($useRange) {
    $rows = bos_fetch_riwayat($pdo, 0, 0, $jenjang, (string) $periodeRange['tgl_mulai'], (string) $periodeRange['tgl_selesai']);
    $filterLabel = (string) $periodeRange['label'];
} else {
    $rows = bos_fetch_riwayat($pdo, $bulan, $tahun, $jenjang);
    $filterLabel = $bulan >= 1 ? (string) $periodeMasehi['label'] : 'Semua periode ' . $tahun;
}

$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Riwayat Transaksi BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Riwayat Transaksi BOS</h1>
    <p class="text-muted mb-0">Filter periode menggunakan kalender Masehi (bulan tunggal atau rentang).</p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <ul class="nav nav-pills mb-3">
            <li class="nav-item">
                <a class="nav-link <?= !$useRange ? 'active' : '' ?>" href="<?= htmlspecialchars(app_href('/keuangan-bos/riwayat.php?bulan=' . $bulan . '&tahun=' . $tahun . ($jenjang !== '' ? '&jenjang=' . urlencode($jenjang) : ''))) ?>">Per bulan</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $useRange ? 'active' : '' ?>" href="<?= htmlspecialchars(app_href('/keuangan-bos/riwayat.php?bulan_mulai=' . (int) $periodeRange['bulan_mulai'] . '&tahun_mulai=' . (int) $periodeRange['tahun_mulai'] . '&bulan_selesai=' . (int) $periodeRange['bulan_selesai'] . '&tahun_selesai=' . (int) $periodeRange['tahun_selesai'] . ($jenjang !== '' ? '&jenjang=' . urlencode($jenjang) : ''))) ?>">Rentang bulan</a>
            </li>
        </ul>
        <form method="get" class="row g-2 align-items-end">
            <?php if ($useRange): ?>
                <?php
                $filterInline = true;
                require __DIR__ . '/partials/filter_periode_range.php';
                ?>
            <?php else: ?>
                <?php
                $showSemuaBulan = true;
                $filterInline = true;
                require __DIR__ . '/partials/filter_periode_masehi.php';
                ?>
            <?php endif; ?>
            <div class="col-auto">
                <label class="form-label small mb-0">Jenjang</label>
                <select name="jenjang" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach (bos_jenjang_options() as $j): ?>
                        <option value="<?= htmlspecialchars($j) ?>" <?= $jenjang === $j ? 'selected' : '' ?>><?= htmlspecialchars(bos_label_jenjang($j)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2">Menampilkan: <?= htmlspecialchars($filterLabel) ?></p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Tanggal</th>
                    <th>Periode Masehi</th>
                    <th>Jenis</th>
                    <th>Kategori</th>
                    <th>Jenjang</th>
                    <th>Sumber Dana</th>
                    <th class="text-end">Nominal</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-muted text-center py-4">Belum ada transaksi.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $bm = (int) ($r['bulan_tagihan'] ?? 0);
                        $th = (int) ($r['tahun_masehi'] ?? 0);
                        $periodeLabel = ($bm >= 1 && $th >= 2000) ? bos_bulan_label_masehi($bm, $th) : '—';
                        $posId = (int) ($r['pos_pengeluaran_id'] ?? 0);
                        $kategori = $posId > 0
                            ? (string) ($r['nama_pos'] ?? 'Pos lain')
                            : ((string) ($r['kode_akun_beban'] ?? '') !== '' ? (string) $r['kode_akun_beban'] : '—');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars($periodeLabel) ?></td>
                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string) ($r['jenis'] ?? '')) ?></span></td>
                            <td class="small"><?= htmlspecialchars($kategori) ?></td>
                            <td><?= htmlspecialchars(bos_label_jenjang((string) ($r['jenjang'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars(bos_label_sumber_dana((string) ($r['sumber_dana'] ?? ''))) ?></td>
                            <td class="text-end fw-semibold"><?= htmlspecialchars($fmt((int) round((float) ($r['nominal'] ?? 0)))) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($r['keterangan'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
