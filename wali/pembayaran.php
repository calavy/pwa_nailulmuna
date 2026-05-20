<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$berjalan = keuangan_periode_berjalan($pdo);
$periodeMulai = $berjalan['mulai'];
$periodeSelesai = $berjalan['selesai'];
$tagihanBulanIni = tagihan_wajib_status_for_month($pdo, $waliSantriId, $berjalan['bulan'], $periodeMulai, $periodeSelesai, $waliKelasKategori);

$list = wali_portal_fetch_pembayaran_list($pdo, $waliSantriId, 80);
$ringkasanPos = wali_portal_ringkasan_pos($pdo, $waliSantriId, $periodeMulai, $periodeSelesai);
$tablesOk = table_exists($pdo, 'keuangan_pembayaran');

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Riwayat pembayaran — Portal Wali', true, 'pembayaran');
require __DIR__ . '/partials/greeting.php';
?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Riwayat pembayaran</h1>
                <p class="small text-muted mb-0">Tagihan wajib: syahriyah &amp; makan. Saku opsional (masuk cashless).</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="/wali/logout.php">Keluar</a>
        </div>

        <div class="alert alert-light border small mb-3 py-2">
            <strong>Tagihan <?= htmlspecialchars($berjalan['bulan_label']) ?> <?= (int) $berjalan['tahun_kalender'] ?></strong>
            (TA <?= htmlspecialchars($berjalan['ta_label']) ?>):
            <?php if ((int) ($tagihanBulanIni['sisa_total'] ?? 0) > 0): ?>
                sisa <span class="text-danger fw-semibold">Rp <?= number_format((int) $tagihanBulanIni['sisa_total'], 0, ',', '.') ?></span>
            <?php else: ?>
                <span class="text-success fw-semibold"><?= htmlspecialchars((string) ($tagihanBulanIni['status'] ?? 'Lunas')) ?></span>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="/wali/tagihan.php"><i class="fa-solid fa-receipt me-1"></i> Tagihan bulanan</a>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/keuangan.php"><i class="fa-solid fa-wallet me-1"></i> Ringkasan keuangan</a>
        </div>

        <?php if (!$tablesOk): ?>
            <div class="alert alert-warning small">Data pembayaran belum tersedia di sistem pondok.</div>
        <?php elseif ($ringkasanPos !== []): ?>
            <div class="card shadow-sm wali-card mb-3">
                <div class="card-body">
                    <div class="wali-kicker mb-2">Total terbayar TA <?= (int) $periodeMulai ?>/<?= (int) $periodeSelesai ?></div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($ringkasanPos as $rp): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><?= htmlspecialchars((string) ($rp['pos_nama'] ?? $rp['pos_slug'] ?? '')) ?></span>
                                <span class="font-monospace fw-semibold"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($rp['total'] ?? 0)))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tablesOk && $list === []): ?>
            <div class="card shadow-sm wali-card">
                <div class="card-body text-center text-muted py-4">
                    <i class="fa-solid fa-inbox fa-2x mb-2 opacity-50"></i>
                    <p class="mb-0">Belum ada pembayaran tercatat untuk anak ini.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($list as $trx): ?>
            <?php
            $pid = (int) ($trx['id'] ?? 0);
            $total = (int) round((float) ($trx['total_nominal'] ?? 0));
            $dets = (array) ($trx['details'] ?? []);
            ?>
            <div class="card shadow-sm wali-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="fw-bold font-monospace"><?= htmlspecialchars((string) ($trx['tanggal_bayar'] ?? '')) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string) ($trx['periode_label'] ?? wali_portal_label_periode($trx))) ?></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success font-monospace"><?= htmlspecialchars(wali_portal_format_rupiah($total)) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string) ($trx['metode_bayar'] ?? 'KAS')) ?></div>
                        </div>
                    </div>
                    <?php if ($dets !== []): ?>
                        <ul class="list-unstyled small mb-2 border-top pt-2">
                            <?php foreach ($dets as $d): ?>
                                <li class="d-flex justify-content-between py-1">
                                    <span><?= htmlspecialchars((string) ($d['pos_nama'] ?? '')) ?></span>
                                    <span class="font-monospace text-muted"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($d['nominal'] ?? 0)))) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-2">Tanpa rincian komponen.</p>
                    <?php endif; ?>
                    <?php if (trim((string) ($trx['keterangan'] ?? '')) !== ''): ?>
                        <p class="small text-muted mb-2"><strong>Catatan:</strong> <?= htmlspecialchars((string) $trx['keterangan']) ?></p>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-teal w-100" href="/wali/kuitansi.php?id=<?= $pid ?>">
                        <i class="fa-solid fa-receipt me-1"></i> Lihat bukti / kuitansi
                    </a>
                </div>
            </div>
        <?php endforeach; ?>

<?php
wali_layout_foot(true, 'pembayaran');
