<?php

declare(strict_types=1);

/**
 * Tampilan nilai keaktifan (read-only).
 *
 * @var PDO $pdo
 * @var int $santriId
 * @var bool $showPresensiDetail tampilkan kolom hadir/izin/alpa
 */

$santriId = (int) ($santriId ?? 0);
$showPresensiDetail = (bool) ($showPresensiDetail ?? true);
if ($santriId <= 0) {
    return;
}

require_once __DIR__ . '/../../helpers/santri_keaktifan_nilai.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';
$rows = santri_keaktifan_tampilan_per_tahun($pdo, $santriId);
?>
<div class="card border-0 shadow-sm">
    <div class="card-header py-2">
        <strong><i class="fa-solid fa-star-half-stroke me-1 text-warning"></i> Nilai Keaktifan</strong>
    </div>
    <div class="card-body p-0">
        <?php if ($rows === []): ?>
            <p class="text-muted small text-center py-4 mb-0">Belum ada penilaian keaktifan untuk Anda.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Tahun</th>
                        <th>Nilai</th>
                        <?php if ($showPresensiDetail): ?>
                            <th class="text-end d-none d-sm-table-cell">% Hadir</th>
                            <th class="text-end d-none d-md-table-cell">ALPA</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $ka): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= (int) $ka['th'] ?></td>
                        <td>
                            <span class="badge <?= santri_riwayat_keaktifan_badge_class((string) $ka['label']) ?>"><?= htmlspecialchars((string) $ka['label']) ?></span>
                            <?php if (($ka['sumber'] ?? '') === 'pengasuh'): ?>
                                <span class="text-muted small ms-1">pengasuh</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showPresensiDetail): ?>
                            <td class="text-end d-none d-sm-table-cell small text-muted">
                                <?= (int) ($ka['total'] ?? 0) > 0 ? number_format((float) $ka['persen_hadir'], 1, ',', '.') . '%' : '—' ?>
                            </td>
                            <td class="text-end d-none d-md-table-cell small text-muted"><?= (int) ($ka['alpa'] ?? 0) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php if (trim((string) ($ka['catatan_pengasuh'] ?? '')) !== ''): ?>
                    <tr class="table-borderless">
                        <td></td>
                        <td colspan="<?= $showPresensiDetail ? 3 : 1 ?>" class="small text-muted pt-0 pb-2"><?= htmlspecialchars((string) $ka['catatan_pengasuh']) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
        <p class="small text-muted mt-2 mb-0">Pengasuh dapat menetapkan <strong>Baik</strong>, <strong>Cukup</strong>, <strong>Sedang</strong>, <strong>Kurang</strong>, atau <strong>Buruk</strong>. Tanpa penilaian pengasuh, rumus PRESNA: ABSENSI = N.HARI − (Alpa×4 + Izin×2 + Sakit×1 + Telat×3), minimum 0; % kehadiran = ABSENSI ÷ N.HARI. Predikat: Baik 81–100%, Cukup 61–80%, Sedang 41–60%, Kurang 21–40%, Buruk ≤20%. Sumber: <?= htmlspecialchars(rekap_keaktifan_rekap_footnote($pdo)) ?>.</p>
