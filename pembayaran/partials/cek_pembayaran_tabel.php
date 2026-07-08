<?php

declare(strict_types=1);

if (!function_exists('keuangan_cek_pembayaran_row_perlu_bayar')) {
    require_once __DIR__ . '/../../helpers/keuangan_cek_pembayaran.php';
}

/** @var string $jenis */
/** @var int $totalRows */
/** @var int $page */
/** @var int $totalPages */
/** @var int $sumTagihan */
/** @var int $sumBayar */
/** @var int $sumSisa */
/** @var int $countLunas */
/** @var int $countBelum */
/** @var int $countSebagian */
/** @var string $filterStatus */
/** @var list<array<string, mixed>> $bodyPage */
/** @var array<string, mixed> $queryBase */
/** @var int $bulanTagihan */
/** @var int $tahunAjaranMulai */
/** @var int $tahunAjaranSelesai */
/** @var array<string, string> $kelasLabels */

$isGabungan = $jenis === 'gabungan';
$isAwal = $jenis === 'awal_tahun';

?>
<p class="small text-muted mb-2">
    Menampilkan <strong><?= $totalRows ?></strong> santri
    <?php if ($filterStatus === 'harus_bayar' || $filterStatus === 'belum_lunas'): ?>(belum lunas)<?php endif; ?>
    · halaman <?= $page ?> / <?= $totalPages ?>
</p>

<div class="row g-2 mb-3">
    <?php if (!$isGabungan): ?>
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
    <?php endif; ?>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label"><?= $isGabungan ? 'Total sisa tagihan' : 'Sisa wajib' ?></div>
            <div class="app-mini-stat-value text-danger">Rp <?= number_format($sumSisa, 0, ',', '.') ?></div>
        </div>
    </div>
    <?php if (!$isGabungan): ?>
    <div class="col-4 col-md-1">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-lunas">
            <div class="app-mini-stat-label">Lunas</div>
            <div class="app-mini-stat-value"><?= $countLunas ?></div>
        </div>
    </div>
    <div class="col-4 col-md-1">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Belum</div>
            <div class="app-mini-stat-value text-danger"><?= $countBelum ?></div>
        </div>
    </div>
    <div class="col-4 col-md-1">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-sebagian">
            <div class="app-mini-stat-label">Sebagian</div>
            <div class="app-mini-stat-value text-warning"><?= $countSebagian ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Santri belum lunas</div>
            <div class="app-mini-stat-value text-danger"><?= $countBelum ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 bendahara-table">
            <thead class="table-light">
                <tr>
                    <th class="text-center" style="width:2.5rem">#</th>
                    <th>Santri</th>
                    <th>Kelas</th>
                    <?php if ($isAwal): ?>
                        <th>Jenis</th>
                    <?php endif; ?>
                    <?php if ($isGabungan): ?>
                        <th class="text-end">Bulanan</th>
                        <th class="text-end">Awal tahun</th>
                        <th class="text-end">Total sisa</th>
                    <?php else: ?>
                        <th class="text-end">Tagihan</th>
                        <th class="text-end">Bayar</th>
                        <th class="text-end">Sisa</th>
                        <th>Status</th>
                    <?php endif; ?>
                    <th class="text-end" style="width:6rem">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($bodyPage === []): ?>
                    <tr>
                        <td colspan="<?= $isGabungan ? 8 : ($isAwal ? 9 : 8) ?>" class="text-center text-muted py-4">
                            Tidak ada data untuk filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $offset = ($page - 1) * (int) ($queryBase['per_page'] ?? 50);
                    foreach ($bodyPage as $i => $r):
                        $no = $offset + $i + 1;
                        $sid = (int) ($r['id'] ?? 0);
                        $kelasKey = trim((string) ($r['kategori'] ?? ''));
                        $kelasLabel = $kelasLabels[$kelasKey] ?? ($kelasKey !== '' ? $kelasKey : '—');
                        $payUrl = app_href('/keuangan/pembayaran.php?' . http_build_query([
                            'santri_id' => $sid,
                            'mode' => $isAwal ? 'AWAL_TAHUN' : 'BULANAN',
                            'bulan' => $bulanTagihan,
                            'tm' => $tahunAjaranMulai,
                            'ts' => $tahunAjaranSelesai,
                        ]));
                        ?>
                        <tr>
                            <td class="text-center text-muted"><?= $no ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama'] ?? '')) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                            </td>
                            <td class="small">
                                <?= htmlspecialchars($kelasLabel) ?>
                                <?php if (trim((string) ($r['tingkatan'] ?? '')) !== ''): ?>
                                    <span class="text-muted">· <?= htmlspecialchars((string) $r['tingkatan']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isAwal): ?>
                                <td class="small"><?= htmlspecialchars((string) ($r['jenis_santri'] ?? '—')) ?></td>
                            <?php endif; ?>
                            <?php if ($isGabungan): ?>
                                <td class="text-end small">
                                    <span class="badge text-bg-<?= htmlspecialchars((string) ($r['bulanan_statusClass'] ?? 'secondary')) ?>">
                                        <?= htmlspecialchars((string) ($r['bulanan_status'] ?? '—')) ?>
                                    </span>
                                    <?php if ((int) ($r['bulanan_sisa'] ?? 0) > 0): ?>
                                        <div class="text-danger">Rp <?= number_format((int) $r['bulanan_sisa'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end small">
                                    <span class="badge text-bg-<?= htmlspecialchars((string) ($r['awal_statusClass'] ?? 'secondary')) ?>">
                                        <?= htmlspecialchars((string) ($r['awal_status'] ?? '—')) ?>
                                    </span>
                                    <?php if ((int) ($r['awal_sisa'] ?? 0) > 0): ?>
                                        <div class="text-danger">Rp <?= number_format((int) $r['awal_sisa'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-semibold <?= (int) ($r['total_sisa'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                                    Rp <?= number_format((int) ($r['total_sisa'] ?? 0), 0, ',', '.') ?>
                                </td>
                            <?php else: ?>
                                <td class="text-end">Rp <?= number_format((int) ($r['tagihan'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end text-success">Rp <?= number_format((int) ($r['bayar'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end <?= (int) ($r['sisa'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                    Rp <?= number_format((int) ($r['sisa'] ?? 0), 0, ',', '.') ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= htmlspecialchars((string) ($r['statusClass'] ?? 'secondary')) ?>">
                                        <?= htmlspecialchars((string) ($r['status'] ?? '—')) ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                            <td class="text-end">
                                <?php
                                $perluBayar = keuangan_cek_pembayaran_row_perlu_bayar($r);
                                if ($perluBayar):
                                    ?>
                                    <a href="<?= htmlspecialchars($payUrl) ?>" class="btn btn-success btn-sm">Bayar</a>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_url_santri($sid)) ?>" class="btn btn-outline-secondary btn-sm">Riwayat</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3" aria-label="Halaman tabel">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php
            $qPage = array_merge($queryBase, ['page' => $p]);
            ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= htmlspecialchars(http_build_query($qPage)) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
