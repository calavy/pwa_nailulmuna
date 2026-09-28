<?php

declare(strict_types=1);

/**
 * Rincian kekurangan tagihan & sisa uang cashless (sebelum administrasi keluar selesai).
 *
 * @var array<string, mixed> $ringkasan
 * @var PDO $pdo
 * @var int $santriId
 * @var bool $showPrintLink
 * @var bool $showDetailHeader
 * @var array<int, string>|null $bulanNama
 */
$ringkasan = is_array($ringkasan ?? null) ? $ringkasan : [];
$santriId = (int) ($santriId ?? ($ringkasan['santri_id'] ?? 0));
$showPrintLink = !empty($showPrintLink);
$showDetailHeader = !empty($showDetailHeader);
$bulanNama = is_array($bulanNama ?? null) ? $bulanNama : [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nop', 12 => 'Des',
];
$rows = is_array($ringkasan['outstanding_rows'] ?? null) ? $ringkasan['outstanding_rows'] : [];
$totalSisa = (int) ($ringkasan['total_sisa_tagihan'] ?? 0);
$cashless = (int) ($ringkasan['cashless_saldo'] ?? ($ringkasan['sisa_uang_cashless'] ?? 0));
$periodeMulai = (int) ($ringkasan['periode_mulai'] ?? 0);
$periodeSelesai = (int) ($ringkasan['periode_selesai'] ?? 0);
$proyeksiSisaTagihan = (int) ($ringkasan['proyeksi_sisa_tagihan_setelah_cashless'] ?? max(0, $totalSisa - $cashless));
$proyeksiSisaUang = (int) ($ringkasan['proyeksi_sisa_uang_setelah_tagihan'] ?? max(0, $cashless - $totalSisa));
$proyeksiCashlessKeTagihan = (int) ($ringkasan['proyeksi_cashless_ke_tagihan'] ?? min($cashless, $totalSisa));

$taLabel = '';
if (isset($pdo) && $pdo instanceof PDO && $periodeMulai > 0) {
    require_once __DIR__ . '/../../helpers/pondok_kalender.php';
    $taLabel = pondok_tahun_ajaran_label($pdo, ['mulai' => $periodeMulai, 'selesai' => $periodeSelesai]);
}
$printHref = app_href('/santri/keluar_kekurangan_print.php?id=' . $santriId);
?>
<div class="santri-keuangan-exit-detail mt-3 pt-3 border-top">
    <?php if ($showDetailHeader): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h3 class="h6 mb-1">Detail kekurangan &amp; sisa uang</h3>
            <?php if ($taLabel !== ''): ?>
                <p class="small text-muted mb-0">TA <?= htmlspecialchars($taLabel) ?>.</p>
            <?php endif; ?>
        </div>
        <?php if ($showPrintLink && $santriId > 0): ?>
            <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="<?= htmlspecialchars($printHref) ?>">Cetak ringkasan</a>
        <?php endif; ?>
    </div>
    <?php elseif ($taLabel !== ''): ?>
        <p class="small text-muted mb-2">Periode keuangan: <?= htmlspecialchars($taLabel) ?>.</p>
    <?php endif; ?>

    <div class="fw-semibold small mb-2">Rincian kekurangan tagihan bulanan (Keuangan pesantren)</div>
    <?php if ($rows === []): ?>
        <p class="small text-muted mb-0">Tidak ada sisa tagihan bulanan menurut data pembayaran.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle bg-white">
                <thead class="table-light">
                    <tr>
                        <th>Bln</th>
                        <th>Pos</th>
                        <th class="text-end">Tarif</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Sisa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $o): ?>
                        <tr>
                            <td class="text-nowrap"><?= (int) ($o['bulan'] ?? 0) ?> <?= htmlspecialchars($bulanNama[(int) ($o['bulan'] ?? 0)] ?? '') ?></td>
                            <td><?= htmlspecialchars((string) ($o['nama'] ?? '')) ?></td>
                            <td class="text-end font-monospace small">Rp <?= number_format((int) ($o['expected'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end font-monospace small">Rp <?= number_format((int) ($o['paid'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end font-monospace small">Rp <?= number_format((int) ($o['sisa'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($showPrintLink && !$showDetailHeader && $santriId > 0): ?>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="<?= htmlspecialchars($printHref) ?>">Cetak ringkasan kekurangan</a>
        </div>
    <?php endif; ?>
</div>
