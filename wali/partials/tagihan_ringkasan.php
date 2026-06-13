<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/keuangan_kelas_makan.php';

/**
 *
 * @var array<string, mixed> $tagihanKumulatif hasil wali_portal_tagihan_sampai_bulan_berjalan()
 * @var bool $compact tampilan ringkas untuk beranda
 * @var bool $hideTagihanLink sembunyikan tombol ke halaman tagihan
 */

$tagihanKumulatif = $tagihanKumulatif ?? [];
$compact = !empty($compact);
$hideTagihanLink = !empty($hideTagihanLink);
$berjalan = (array) ($tagihanKumulatif['berjalan'] ?? []);
$sisaTotal = (int) ($tagihanKumulatif['sisa_total'] ?? 0);
$tunggakan = (array) ($tagihanKumulatif['per_bulan_tunggakan'] ?? []);
?>
<div class="card shadow-sm wali-card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div class="wali-kicker mb-0">Tagihan s.d. bulan berjalan</div>
            <span class="badge text-bg-<?= htmlspecialchars((string) ($tagihanKumulatif['statusClass'] ?? 'secondary')) ?>">
                <?= htmlspecialchars((string) ($tagihanKumulatif['status'] ?? '—')) ?>
            </span>
        </div>
        <div class="small text-muted mb-3">
            <span class="badge text-bg-primary me-1" style="font-size:.65rem">Kumulatif</span>
            Bulan 1 s.d. <strong><?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'] ?? '')) ?></strong>
            · TA <?= htmlspecialchars((string) ($berjalan['ta_label'] ?? '')) ?>
        </div>
        <div class="d-flex justify-content-between mb-1 small">
            <span class="wali-stat-label">Syahriyah</span>
            <span class="font-monospace">
                Rp <?= number_format((int) ($tagihanKumulatif['sy_paid'] ?? 0), 0, ',', '.') ?>
                / <?= number_format((int) ($tagihanKumulatif['sy_expected'] ?? 0), 0, ',', '.') ?>
            </span>
        </div>
        <?php if ((int) ($tagihanKumulatif['mk_expected'] ?? 0) > 0): ?>
        <div class="d-flex justify-content-between mb-2 small">
            <span class="wali-stat-label"><?= htmlspecialchars(isset($pdo) ? keuangan_makan_pos_nama($pdo) : 'Makan') ?> <span class="text-muted">(ops.)</span></span>
            <span class="font-monospace">
                Rp <?= number_format((int) ($tagihanKumulatif['mk_paid'] ?? 0), 0, ',', '.') ?>
                / <?= number_format((int) ($tagihanKumulatif['mk_expected'] ?? 0), 0, ',', '.') ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between mb-2">
            <span class="wali-stat-label">Total tagihan</span>
            <span class="font-monospace wali-stat-value" style="font-size:1rem">Rp <?= number_format((int) ($tagihanKumulatif['expected_total'] ?? 0), 0, ',', '.') ?></span>
        </div>
        <div class="d-flex justify-content-between mb-2">
            <span class="wali-stat-label">Terbayar</span>
            <span class="font-monospace text-success fw-bold">Rp <?= number_format((int) ($tagihanKumulatif['paid_total'] ?? 0), 0, ',', '.') ?></span>
        </div>
        <div class="d-flex justify-content-between mb-<?= $compact ? '2' : '3' ?>">
            <span class="wali-stat-label">Sisa</span>
            <span class="font-monospace fw-bold <?= $sisaTotal > 0 ? 'text-danger' : 'text-success' ?>">Rp <?= number_format($sisaTotal, 0, ',', '.') ?></span>
        </div>
        <?php if (!$compact && $tunggakan !== []): ?>
            <div class="small border-top pt-2 mb-3">
                <div class="text-muted mb-1">Bulan dengan tunggakan:</div>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($tunggakan as $tb): ?>
                        <li class="d-flex justify-content-between py-1">
                            <span><?= htmlspecialchars((string) ($tb['label'] ?? '')) ?></span>
                            <span class="font-monospace text-danger">Rp <?= number_format((int) ($tb['sisa_total'] ?? 0), 0, ',', '.') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!$compact): ?>
            <a class="btn btn-sm btn-teal w-100" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=bayar')) ?>">Riwayat Keuangan &amp; bukti</a>
        <?php endif; ?>
        <?php if (!$hideTagihanLink): ?>
        <a class="btn btn-sm btn-outline-secondary w-100 mt-2" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=tagihan')) ?>">Detail per bulan</a>
        <?php endif; ?>
        <?php if (!$compact): ?>
            <p class="small text-muted mt-2 mb-0">Pembayaran dilakukan melalui pengurus pondok. Bulan setelah periode berjalan belum ditagihkan.</p>
        <?php endif; ?>
    </div>
</div>
