<?php

declare(strict_types=1);

/** @var int $totalRows */
/** @var int $page */
/** @var int $totalPages */
/** @var int $sumTagihan */
/** @var int $sumBayar */
/** @var int $sumSisa */
/** @var int $countLunas */
/** @var int $countBelum */
/** @var int $countSebagian */
/** @var bool $ringkas */
/** @var string $filterStatus */
/** @var list<array<string, mixed>> $bodyPage */
/** @var list<array<string, mixed>> $bodyAll */
/** @var array<string, mixed> $queryBase */
/** @var int $bulanTagihan */
/** @var int $tahunAjaranMulai */
/** @var int $tahunAjaranSelesai */
/** @var array<string, string> $kelasLabels */

?>
<p class="small text-muted mb-2">
    Menampilkan <strong><?= $totalRows ?></strong> santri
    <?php if ($filterStatus === 'harus_bayar'): ?>(belum lunas tagihan wajib)<?php endif; ?>
    · halaman <?= $page ?> / <?= $totalPages ?>
    <?php if (count($bodyAll) !== $totalRows): ?>
        · dari <?= count($bodyAll) ?> santri aktif
    <?php endif; ?>
    <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" id="btn-tagihan-ringkas" aria-pressed="<?= $ringkas ? 'true' : 'false' ?>">
        <?= $ringkas ? 'Tampilkan detail kolom' : 'Mode ringkas' ?>
    </button>
    <form method="post" class="d-inline ms-2" onsubmit="return confirm('Kirim WA tagihan ke wali santri yang masih punya tagihan belum lunas? (Tidak mengganggu jadwal otomatis.)')">
        <input type="hidden" name="action" value="kirim_wa_tagihan">
        <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Kirim WA tagihan</button>
    </form>
</p>

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
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Sisa wajib</div>
            <div class="app-mini-stat-value text-danger">Rp <?= number_format($sumSisa, 0, ',', '.') ?></div>
        </div>
    </div>
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
</div>

<div class="card shadow-sm tagihan-list-card<?= $ringkas ? ' tagihan-list-card--ringkas' : '' ?>">
    <div class="card-body p-0 app-table-mobile">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 tagihan-santri-table" id="tabel-tagihan">
                <thead class="table-light">
                    <tr>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th class="tagihan-col-detail">Kelas</th>
                        <th class="text-end">Sisa wajib</th>
                        <th class="text-center">Status</th>
                        <th class="text-end" style="min-width:11rem">WA / Bayar</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($bodyPage === []): ?>
                    <tr><td colspan="6" class="text-muted text-center py-4">Tidak ada data atau tidak cocok filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($bodyPage as $r): ?>
                    <?php
                    $kkDisp = trim((string) ($r['kategori'] ?? ''));
                    $kelasLabel = $kkDisp === ''
                        ? (string) ($r['tingkatan'] !== '' ? $r['tingkatan'] : '—')
                        : ($kelasLabels[strtoupper($kkDisp)] ?? $kkDisp);
                    $hasOps = (int) ($r['mk_expected'] ?? 0) > 0 || (int) ($r['sk_expected'] ?? 0) > 0;
                    ?>
                    <tr class="tagihan-row" data-id="<?= (int) $r['id'] ?>">
                        <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars((string) $r['nama']) ?></span>
                            <?php if ($hasOps && $ringkas): ?>
                                <span class="badge text-bg-light text-dark border ms-1" style="font-size:.65rem">+opsional</span>
                            <?php endif; ?>
                        </td>
                        <td class="small tagihan-col-detail"><?= htmlspecialchars($kelasLabel) ?></td>
                        <td class="text-end font-monospace small fw-semibold <?= (int) $r['sisa'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= (int) $r['sisa'] > 0 ? 'Rp ' . number_format((int) $r['sisa'], 0, ',', '.') : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= htmlspecialchars((string) $r['statusClass']) ?>"><?= htmlspecialchars((string) $r['status']) ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary tagihan-btn-detail d-none d-md-inline-flex" data-row="<?= (int) $r['id'] ?>" title="Detail">
                                <i class="fa-solid fa-chevron-down"></i>
                            </button>
                            <?php
                            $punyaTagihan = (int) ($r['sisa'] ?? 0) > 0;
                            $punyaWa = trim((string) ($r['no_wa_wali'] ?? '')) !== '';
                            ?>
                            <?php if ($punyaTagihan && $punyaWa): ?>
                                <div class="btn-group btn-group-sm tagihan-wa-grup" role="group"
                                    data-santri-id="<?= (int) $r['id'] ?>"
                                    data-bulan="<?= (int) $bulanTagihan ?>"
                                    data-ta-mulai="<?= (int) $tahunAjaranMulai ?>"
                                    data-ta-selesai="<?= (int) $tahunAjaranSelesai ?>"
                                    data-nama="<?= htmlspecialchars((string) $r['nama'], ENT_QUOTES) ?>">
                                    <button type="button" class="btn btn-success tagihan-btn-wa-chat" title="Buka WhatsApp dengan teks tagihan">
                                        <i class="fa-brands fa-whatsapp me-1"></i><span class="d-none d-lg-inline">WA</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-success tagihan-btn-wa-gateway" title="Kirim otomatis via gateway">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </button>
                                </div>
                            <?php elseif ($punyaTagihan): ?>
                                <span class="badge text-bg-warning text-dark" title="Isi nomor WA wali di data santri">Tanpa no. WA</span>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php?santri_id=' . (int) $r['id'] . '&bulan=' . (int) $bulanTagihan . '&mode=BULANAN&mulai=1')) ?>"><i class="fa-solid fa-money-bill-wave me-1"></i> Bayar</a>
                        </td>
                    </tr>
                    <tr class="tagihan-row-detail d-none" id="tagihan-detail-<?= (int) $r['id'] ?>" data-parent="<?= (int) $r['id'] ?>">
                        <td colspan="6" class="small bg-light py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <strong class="text-success">Wajib · Syahriyah</strong>
                                    <?php if ((int) $r['sy_expected'] > 0): ?>
                                        <div>Sisa: <strong>Rp <?= number_format((int) $r['sy_sisa'], 0, ',', '.') ?></strong>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['sy_expected'], 0, ',', '.') ?></span></div>
                                        <?php if (!empty($r['sy_dijeda'])): ?>
                                            <div class="text-secondary">Potongan dijeda (tarif penuh)</div>
                                        <?php elseif ($r['sy_persen'] > 0 && $r['sy_ket_potongan'] !== ''): ?>
                                            <div class="text-warning">Potongan <?= rtrim(rtrim(number_format((float) $r['sy_persen'], 1, ',', '.'), '0'), ',') ?>% · <?= htmlspecialchars((string) $r['sy_ket_potongan']) ?></div>
                                        <?php endif; ?>
                                        <?php if ((int) ($r['sy_pkpps'] ?? 0) > 0): ?>
                                            <div class="text-info">Tambahan PKPPS: Rp <?= number_format((int) $r['sy_pkpps'], 0, ',', '.') ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-muted">Tidak ada tarif</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <strong class="text-info">Opsional · Makan</strong>
                                    <?php if ((int) $r['mk_expected'] > 0): ?>
                                        <div>Sisa: Rp <?= number_format((int) $r['mk_sisa'], 0, ',', '.') ?>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['mk_expected'], 0, ',', '.') ?></span></div>
                                    <?php else: ?>
                                        <div class="text-muted">—</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <strong class="text-info">Opsional · Saku</strong>
                                    <?php if ((int) $r['sk_expected'] > 0): ?>
                                        <div>Sisa: Rp <?= number_format((int) $r['sk_sisa'], 0, ',', '.') ?>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['sk_expected'], 0, ',', '.') ?></span></div>
                                    <?php else: ?>
                                        <div class="text-muted">—</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$ringkas): ?>
                            <div class="mt-1 text-muted">Terbayar wajib: Rp <?= number_format(min((int) $r['bayar'], (int) $r['tagihan']), 0, ',', '.') ?> · Tier <?= htmlspecialchars((string) $r['tier']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($bodyPage !== []): ?>
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td colspan="3">Jumlah total (<?= $totalRows ?> santri, semua halaman filter)</td>
                        <td class="text-end font-monospace text-danger">Rp <?= number_format($sumSisa, 0, ',', '.') ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3 d-flex flex-wrap justify-content-center gap-1 tagihan-pagination" aria-label="Halaman tagihan">
    <?php
    $pageWindow = 5;
    $startP = max(1, $page - (int) floor($pageWindow / 2));
    $endP = min($totalPages, $startP + $pageWindow - 1);
    $startP = max(1, $endP - $pageWindow + 1);
    if ($page > 1):
        ?>
        <button type="button" class="btn btn-sm btn-outline-secondary tagihan-page-link" data-page="<?= $page - 1 ?>">«</button>
    <?php endif;
    for ($p = $startP; $p <= $endP; $p++):
        ?>
        <button type="button" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?> tagihan-page-link" data-page="<?= $p ?>"><?= $p ?></button>
    <?php endfor;
    if ($page < $totalPages):
        ?>
        <button type="button" class="btn btn-sm btn-outline-secondary tagihan-page-link" data-page="<?= $page + 1 ?>">»</button>
    <?php endif; ?>
</nav>
<?php endif; ?>
