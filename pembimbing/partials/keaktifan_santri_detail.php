<?php

declare(strict_types=1);

/** @var array<string,mixed> $santri */
/** @var array<string,mixed> $summary */
/** @var list<array<string,mixed>> $perKegiatan */
/** @var string $badgeClass */
require_once __DIR__ . '/../../helpers/penilaian_kehadiran.php';
?>
<div class="pb-keaktifan-kpi mb-3" role="list" aria-label="Ringkasan keaktifan santri">
    <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--bagus" role="listitem">
        <div class="pb-keaktifan-kpi__label">Hadir</div>
        <div class="pb-keaktifan-kpi__value text-success"><?= (int) ($summary['hadir'] ?? 0) ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card" role="listitem">
        <div class="pb-keaktifan-kpi__label">Izin</div>
        <div class="pb-keaktifan-kpi__value"><?= (int) ($summary['izin'] ?? 0) ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card" role="listitem">
        <div class="pb-keaktifan-kpi__label">Sakit</div>
        <div class="pb-keaktifan-kpi__value"><?= (int) ($summary['sakit'] ?? 0) ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card" role="listitem">
        <div class="pb-keaktifan-kpi__label">Telat</div>
        <div class="pb-keaktifan-kpi__value"><?= (int) ($summary['telat'] ?? 0) ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--alpa" role="listitem">
        <div class="pb-keaktifan-kpi__label">Alpa</div>
        <div class="pb-keaktifan-kpi__value"><?= (int) ($summary['alpa'] ?? 0) ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card" role="listitem">
        <div class="pb-keaktifan-kpi__label">Kehadiran</div>
        <div class="pb-keaktifan-kpi__value"><?= (int) ($summary['total'] ?? 0) > 0 ? number_format((float) ($summary['persen_hadir'] ?? 0), 1, ',', '.') . '%' : '—' ?></div>
    </div>
    <div class="pb-keaktifan-kpi__card" role="listitem">
        <div class="pb-keaktifan-kpi__label">Kategori</div>
        <div class="pb-keaktifan-kpi__value" style="font-size:1rem">
            <span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars((string) ($summary['label'] ?? '—')) ?></span>
            <?php if (($summary['sumber'] ?? '') === 'pengasuh'): ?>
                <span class="badge text-bg-info-subtle text-info border small ms-1">pengasuh</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h6 mb-1 fw-bold"><i class="fa-solid fa-list-check me-1"></i> Rekap per kegiatan</h2>
        <p class="small text-muted mb-0">Hanya kegiatan dalam jadwal yang Anda bimbing.</p>
    </div>
    <div class="card-body p-0 pt-2">
        <?php if ($perKegiatan === []): ?>
            <p class="small text-center text-muted py-4 mb-0">Belum ada data presensi tahun ini untuk santri ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 pb-keaktifan-table">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Kegiatan</th>
                            <th class="text-center">Hadir</th>
                            <th class="text-center">Telat</th>
                            <th class="text-center">Izin</th>
                            <th class="text-center">Sakit</th>
                            <th class="text-center">Alpa</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">%</th>
                            <th class="pe-3">Kategori</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($perKegiatan as $kg):
                        $kgBadge = penilaian_kehadiran_badge_class((string) ($kg['kategori'] ?? ''));
                    ?>
                        <tr>
                            <td class="ps-3 small fw-semibold"><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '—')) ?></td>
                            <td class="text-center small text-success"><?= (int) ($kg['hadir'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($kg['telat'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($kg['izin'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($kg['sakit'] ?? 0) ?></td>
                            <td class="text-center small text-danger"><?= (int) ($kg['alpa'] ?? 0) ?></td>
                            <td class="text-center small fw-semibold"><?= (int) ($kg['total'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($kg['total'] ?? 0) > 0 ? number_format((float) ($kg['persen_hadir'] ?? 0), 0, ',', '.') . '%' : '—' ?></td>
                            <td class="pe-3"><span class="badge <?= htmlspecialchars($kgBadge) ?>"><?= htmlspecialchars((string) ($kg['kategori'] ?? '—')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
