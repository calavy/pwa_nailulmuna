<?php

declare(strict_types=1);

/** @var int $tahun */
/** @var string $tingkatanFilter */
/** @var string $keaktifanView */
/** @var list<array<string,mixed>> $rekapPerKegiatan */
/** @var array<string,list<array<string,mixed>>> $keaktivanByTingkatan */
/** @var list<array<string,mixed>> $keaktivanRows */
/** @var string $rekapPanelClass Extra class on wrapper */
/** @var string $rekapFormMode Hidden mode value for filter form */
/** @var string $rekapDashView Optional view query (e.g. keaktivan) */
/** @var string $rekapJenis kajian|pkpps */

$rekapPanelClass = trim((string) ($rekapPanelClass ?? ''));
$rekapFormMode = trim((string) ($rekapFormMode ?? 'ringkas'));
$rekapDashView = trim((string) ($rekapDashView ?? ''));
$rekapJenis = trim((string) ($rekapJenis ?? ''));
?>
<div class="pb-dash-rekap-keaktivan <?= htmlspecialchars($rekapPanelClass) ?>">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
            <h3 class="pb-dash-rekap-keaktivan__title h6 mb-0 fw-bold">
                <i class="fa-solid fa-chart-line me-1"></i> Rekap keaktifan · tahun <?= (int) $tahun ?>
            </h3>
            <p class="small mb-0 pb-dash-rekap-keaktivan__sub">Kategori keaktifan santri berdasarkan presensi tahun berjalan. Klik nama santri untuk detail per kegiatan.</p>
        </div>
        <form method="get" class="d-flex flex-wrap align-items-center gap-2 m-0">
            <?php if ($tingkatanFilter !== ''): ?><input type="hidden" name="tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>"><?php endif; ?>
            <input type="hidden" name="tahun" value="<?= (int) $tahun ?>">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($rekapFormMode) ?>">
            <?php if ($rekapDashView !== ''): ?><input type="hidden" name="view" value="<?= htmlspecialchars($rekapDashView) ?>"><?php endif; ?>
            <?php if ($rekapJenis !== ''): ?><input type="hidden" name="rekap_jenis" value="<?= htmlspecialchars($rekapJenis) ?>"><?php endif; ?>
            <label class="small mb-0 pb-dash-rekap-keaktivan__sub" for="pb-keaktifan-view-<?= htmlspecialchars(md5($rekapPanelClass)) ?>">Tampilan</label>
            <select id="pb-keaktifan-view-<?= htmlspecialchars(md5($rekapPanelClass)) ?>" name="keaktifan_view" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="kegiatan"<?= $keaktifanView === 'kegiatan' ? ' selected' : '' ?>>Per kegiatan</option>
                <option value="santri"<?= $keaktifanView === 'santri' ? ' selected' : '' ?>>Per santri</option>
            </select>
        </form>
    </div>

    <?php if ($keaktifanView === 'kegiatan'): ?>
        <?php if ($rekapPerKegiatan === []): ?>
            <p class="small text-center py-3 mb-0 pb-dash-rekap-keaktivan__empty">Belum ada data presensi tahun ini.</p>
        <?php else: ?>
            <div class="table-responsive pb-dash-rekap-keaktivan__table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 pb-keaktifan-table">
                    <thead>
                        <tr>
                            <th class="ps-2">Kegiatan</th>
                            <th class="text-center">Jenis</th>
                            <th class="text-center">Hadir</th>
                            <th class="text-center">Izin</th>
                            <th class="text-center">Sakit</th>
                            <th class="text-center">Alpa</th>
                            <th class="text-center pe-2">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rekapPerKegiatan as $rk):
                        $katKeg = strtoupper((string) ($rk['kategori_kegiatan'] ?? 'TAALIM'));
                        $katBadge = match ($katKeg) {
                            'PKPPS' => 'text-bg-primary',
                            'JAMAAH' => 'text-bg-info',
                            default => 'text-bg-secondary',
                        };
                        $katLabel = match ($katKeg) {
                            'PKPPS' => 'PKPPS',
                            'JAMAAH' => "Jama'ah",
                            'TAALIM' => "Ta'lim",
                            default => $katKeg,
                        };
                    ?>
                        <tr>
                            <td class="ps-2 small fw-semibold"><?= htmlspecialchars((string) ($rk['nama_kegiatan'] ?? '—')) ?></td>
                            <td class="text-center"><span class="badge <?= $katBadge ?>"><?= htmlspecialchars($katLabel) ?></span></td>
                            <td class="text-center small text-success"><?= (int) ($rk['hadir'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($rk['izin'] ?? 0) ?></td>
                            <td class="text-center small"><?= (int) ($rk['sakit'] ?? 0) ?></td>
                            <td class="text-center small text-danger"><?= (int) ($rk['alpa'] ?? 0) ?></td>
                            <td class="text-center pe-2 small fw-semibold"><?= (int) ($rk['total'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($keaktivanByTingkatan === []): ?>
        <p class="small text-center py-3 mb-0 pb-dash-rekap-keaktivan__empty">Belum ada data keaktifan santri.</p>
    <?php else: ?>
        <div class="table-responsive pb-dash-rekap-keaktivan__table-wrap" style="max-height:18rem;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0 pb-keaktifan-table">
                <thead class="sticky-top">
                    <tr>
                        <th class="ps-2">Santri</th>
                        <th class="text-center">Hadir</th>
                        <th class="text-center">Alpa</th>
                        <th class="text-center">%</th>
                        <th class="pe-2">Kategori</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keaktivanRows as $r):
                    $kat = strtoupper((string) ($r['kategori'] ?? ''));
                    $badgeClass = match (true) {
                        $kat === 'BAIK' || $kat === 'BAGUS' => 'badge-kat-bagus',
                        $kat === 'SEDANG' => 'badge-kat-sedang',
                        $kat === 'BURUK' || $kat === 'JELEK' => 'badge-kat-buruk',
                        default => 'text-bg-secondary',
                    };
                    $santriDetailUrl = pembimbing_dashboard_keaktifan_santri_url((int) ($r['santri_id'] ?? 0), (int) $tahun, $rekapJenis);
                ?>
                    <tr>
                        <td class="ps-2 small">
                            <a href="<?= htmlspecialchars($santriDetailUrl) ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></a>
                            <div class="opacity-75" style="font-size:.72rem"><?= htmlspecialchars((string) $r['tingkatan']) ?></div>
                        </td>
                        <td class="text-center small text-success"><?= (int) $r['hadir'] ?></td>
                        <td class="text-center small text-danger"><?= (int) $r['alpa'] ?></td>
                        <td class="text-center small"><?= $r['total'] > 0 ? number_format((float) $r['persen_hadir'], 0, ',', '.') . '%' : '—' ?></td>
                        <td class="pe-2"><span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars((string) $r['label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
