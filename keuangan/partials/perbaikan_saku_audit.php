<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var callable(int): string $fmt */
/** @var list<array<string,mixed>> $sakuAuditPerSantri */
/** @var list<array<string,mixed>> $sakuTanpaTopup */
/** @var string $sakuAuditQ */

?>
<div class="card shadow-sm mb-3 border-warning" id="saku-topup">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <strong>Saku &amp; cashless — audit &amp; perbaikan</strong>
            <?php if ($sakuAuditPerSantri !== []): ?>
                <span class="badge bg-danger ms-1"><?= count($sakuAuditPerSantri) ?> santri tidak selaras</span>
            <?php endif; ?>
            <?php if ($sakuTanpaTopup !== []): ?>
                <span class="badge bg-warning text-dark ms-1"><?= count($sakuTanpaTopup) ?> pembayaran tanpa top-up</span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($sakuTanpaTopup !== []): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Buat top-up cashless untuk semua pembayaran saku yang belum punya top-up? (hingga 10 batch)');">
                <input type="hidden" name="action" value="backfill_saku_topup">
                <button type="submit" class="btn btn-sm btn-warning">Backfill semua top-up</button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Samakan saldo cashless semua santri dari ledger transaksi?');">
                <input type="hidden" name="action" value="sync_cashless">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Samakan saldo cashless</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Setiap pembayaran pos Saku harus menghasilkan satu baris TOPUP cashless (jurnal Dr 1103 / Cr 2101).
            Modul ini terpisah dari perbaikan kas operasional pondok.
            <a href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href(null, null, 'masuk', 'kat:saku')) ?>">Rekap masuk pos Saku</a>
        </p>

        <?php if ($sakuAuditPerSantri !== []): ?>
        <div id="saku-audit-santri" class="mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <strong>Ketidaksesuaian per santri</strong>
                <form method="get" class="d-flex gap-2 align-items-center">
                    <input type="search" name="q" class="form-control form-control-sm" style="min-width:12rem"
                           placeholder="Cari nama santri…" value="<?= htmlspecialchars($sakuAuditQ) ?>"
                           autocomplete="off">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Cari</button>
                    <?php if ($sakuAuditQ !== ''): ?>
                        <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-saku.php#saku-audit-santri')) ?>" class="btn btn-sm btn-link">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:2rem"></th>
                            <th>Santri</th>
                            <th class="text-center">Bayar saku</th>
                            <th class="text-center">Top-up</th>
                            <th class="text-end">Total saku</th>
                            <th class="text-end">Total top-up</th>
                            <th class="text-end">Selisih</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sakuAuditPerSantri as $sa):
                        $sid = (int) ($sa['santri_id'] ?? 0);
                        $namaSantri = (string) ($sa['nama_santri'] ?? '');
                        $detailRows = $sid > 0 ? keuangan_saku_cashless_audit_detail_santri($pdo, $sid) : [];
                        $collapseId = 'sakuDetail' . $sid;
                        $adaOrphan = false;
                        foreach ($detailRows as $dr) {
                            if (empty($dr['punya_topup'])) {
                                $adaOrphan = true;
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-sm btn-link p-0 text-secondary"
                                        data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($collapseId) ?>"
                                        aria-expanded="false" title="Lihat rincian pembayaran">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </td>
                            <td id="saku-detail-<?= $sid ?>"><?= htmlspecialchars($namaSantri) ?></td>
                            <td class="text-center"><?= (int) ($sa['jumlah_pembayaran_saku'] ?? 0) ?>×</td>
                            <td class="text-center"><?= (int) ($sa['jumlah_topup_terkait'] ?? 0) ?>×</td>
                            <td class="text-end"><?= htmlspecialchars($fmt((int) ($sa['total_nominal_saku'] ?? 0))) ?></td>
                            <td class="text-end"><?= htmlspecialchars($fmt((int) ($sa['total_topup_terkait'] ?? 0))) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= htmlspecialchars($fmt((int) ($sa['selisih'] ?? 0))) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php?pos=saku&q=' . rawurlencode($namaSantri))) ?>">Riwayat bayar</a>
                                <?php if ($adaOrphan): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Buat top-up cashless untuk pembayaran saku santri ini yang belum punya top-up?');">
                                    <input type="hidden" name="action" value="backfill_saku_santri">
                                    <input type="hidden" name="santri_id" value="<?= $sid ?>">
                                    <?php if ($sakuAuditQ !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($sakuAuditQ) ?>"><?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-warning">Backfill</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="8" class="p-0 border-0">
                                <div class="collapse" id="<?= htmlspecialchars($collapseId) ?>">
                                <div class="bg-light py-2 px-3">
                                <table class="table table-sm table-bordered mb-0 bg-white">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID bayar</th>
                                            <th>Tanggal</th>
                                            <th class="text-end">Nominal saku</th>
                                            <th class="text-end">Top-up cashless</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($detailRows === []): ?>
                                        <tr><td colspan="5" class="text-muted small">Tidak ada pembayaran saku.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($detailRows as $dr): ?>
                                        <tr>
                                            <td>#<?= (int) ($dr['pembayaran_id'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars((string) ($dr['tanggal_bayar'] ?? '')) ?></td>
                                            <td class="text-end"><?= htmlspecialchars($fmt((int) ($dr['nominal_saku'] ?? 0))) ?></td>
                                            <td class="text-end"><?= !empty($dr['punya_topup']) ? htmlspecialchars($fmt((int) ($dr['topup_nominal'] ?? 0))) : '—' ?></td>
                                            <td>
                                                <?php if (!empty($dr['punya_topup'])): ?>
                                                    <span class="badge bg-success">OK</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Belum top-up</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                                </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sakuTanpaTopup !== []): ?>
        <div id="saku-orphan-list">
            <strong class="d-block mb-2">Pembayaran saku tanpa top-up cashless</strong>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID bayar</th><th>Santri</th><th>Tanggal</th><th class="text-end">Nominal saku</th></tr></thead>
                    <tbody>
                    <?php foreach ($sakuTanpaTopup as $so): ?>
                        <tr>
                            <td>#<?= (int) ($so['pembayaran_id'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string) ($so['nama_santri'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($so['tanggal_bayar'] ?? '')) ?></td>
                            <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($so['nominal_saku'] ?? 0)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
