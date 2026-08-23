<?php

declare(strict_types=1);

/**
 * Kartu penilaian keaktifan PRESNA 5 kategori (tahunan atau bulanan).
 *
 * @var array<string,mixed> $keaktifanPenilaian
 * @var bool $waliKeaktifanPenilaianCompact ringkas untuk dashboard
 */
$keaktifanPenilaian = $keaktifanPenilaian ?? ['scope' => 'tahun', 'tahun' => (int) date('Y'), 'row' => null, 'riwayat' => []];
$waliKeaktifanPenilaianCompact = !empty($waliKeaktifanPenilaianCompact);
$scope = (string) ($keaktifanPenilaian['scope'] ?? 'tahun');
$isBulan = $scope === 'bulan';
$row = is_array($keaktifanPenilaian['row'] ?? null) ? $keaktifanPenilaian['row'] : null;
$riwayat = (array) ($keaktifanPenilaian['riwayat'] ?? []);

require_once __DIR__ . '/../../helpers/santri_riwayat.php';

$periodeLabel = $isBulan
    ? (string) ($keaktifanPenilaian['label_bulan'] ?? '')
    : 'Tahun ' . (int) ($keaktifanPenilaian['tahun'] ?? date('Y'));

$label = $row ? (string) ($row['label'] ?? '—') : 'Belum ada';
$sumber = $row ? (string) ($row['sumber'] ?? '') : '';
$badgeClass = $row ? santri_riwayat_keaktifan_badge_class($label) : 'text-bg-secondary';
$cardTone = match ($label) {
    'Baik' => 'baik',
    'Cukup' => 'cukup',
    'Sedang' => 'sedang',
    'Kurang' => 'kurang',
    'Buruk' => 'buruk',
    default => 'netral',
};
$persenHadir = $row && (int) ($row['total'] ?? 0) > 0
    ? number_format((float) ($row['persen_hadir'] ?? 0), 1, ',', '.')
    : null;
$catatan = $row ? trim((string) ($row['catatan_pengasuh'] ?? '')) : '';
$keterangan = $row ? trim((string) ($row['keterangan'] ?? '')) : '';
$detailUrl = $isBulan
    ? app_href('/wali/keaktifan.php?tahun_h=' . (int) ($keaktifanPenilaian['year'] ?? 0) . '&bulan_h=' . (int) ($keaktifanPenilaian['month'] ?? 0))
    : app_href('/wali/keaktifan.php');

$aktifYear = $isBulan ? (int) ($keaktifanPenilaian['year'] ?? 0) : 0;
$aktifMonth = $isBulan ? (int) ($keaktifanPenilaian['month'] ?? 0) : 0;
$aktifTahun = $isBulan ? 0 : (int) ($keaktifanPenilaian['tahun'] ?? date('Y'));

$riwayatLain = [];
foreach ($riwayat as $thRow) {
    if ($isBulan) {
        if ((int) ($thRow['year'] ?? 0) === $aktifYear && (int) ($thRow['month'] ?? 0) === $aktifMonth) {
            continue;
        }
    } elseif ((int) ($thRow['th'] ?? 0) === $aktifTahun) {
        continue;
    }
    $riwayatLain[] = $thRow;
}

$cardUid = $isBulan
    ? 'bulan-' . $aktifYear . '-' . $aktifMonth
    : 'tahun-' . $aktifTahun;
$riwayatCollapseId = 'waliNilaiRiwayat-' . $cardUid;
$riwayatToggleLabel = $isBulan
    ? 'Lihat riwayat bulan lain (' . count($riwayatLain) . ')'
    : 'Lihat riwayat tahun lain (' . count($riwayatLain) . ')';
?>
<div class="card wali-card shadow-sm mb-3 wali-nilai-card wali-nilai-card--<?= htmlspecialchars($cardTone) ?>">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div>
                <div class="wali-kicker mb-1">Penilaian keaktifan<?= $isBulan ? ' bulanan' : '' ?></div>
                <div class="small text-muted"><?= htmlspecialchars($periodeLabel) ?></div>
            </div>
            <?php if (!$waliKeaktifanPenilaianCompact): ?>
                <a class="small fw-semibold text-nowrap" href="<?= htmlspecialchars(app_href('/wali/index.php')) ?>">Beranda</a>
            <?php else: ?>
                <a class="small fw-semibold text-nowrap" href="<?= htmlspecialchars($detailUrl) ?>">Detail</a>
            <?php endif; ?>
        </div>

        <div class="d-flex align-items-center gap-3 mb-2">
            <span class="wali-nilai-badge badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($label) ?></span>
            <div class="small text-muted">
                <?php if ($sumber === 'pengasuh'): ?>
                    Ditetapkan <strong>pengasuh</strong> pondok
                <?php elseif ($row): ?>
                    Berdasarkan rekap presensi<?= $isBulan ? ' bulan ini' : ' tahun ini' ?>
                <?php else: ?>
                    Belum ada data presensi<?= $isBulan ? '' : ' tahun ' . (int) ($keaktifanPenilaian['tahun'] ?? date('Y')) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($row && $persenHadir !== null): ?>
            <div class="row g-2 text-center small mb-0">
                <div class="col-3">
                    <div class="rounded-2 bg-white bg-opacity-75 py-2">
                        <div class="text-muted">Hadir</div>
                        <div class="fw-bold text-success"><?= (int) ($row['hadir'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-2 bg-white bg-opacity-75 py-2">
                        <div class="text-muted">Telat</div>
                        <div class="fw-bold"><?= (int) ($row['telat'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-2 bg-white bg-opacity-75 py-2">
                        <div class="text-muted">Alpa</div>
                        <div class="fw-bold text-danger"><?= (int) ($row['alpa'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-2 bg-white bg-opacity-75 py-2">
                        <div class="text-muted">%</div>
                        <div class="fw-bold"><?= htmlspecialchars($persenHadir) ?>%</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($keterangan !== ''): ?>
            <p class="small text-muted mb-0 mt-2"><?= htmlspecialchars($keterangan) ?></p>
        <?php endif; ?>

        <?php if ($catatan !== ''): ?>
            <p class="small text-muted mb-0 mt-2"><i class="fa-solid fa-comment-dots me-1"></i><?= htmlspecialchars($catatan) ?></p>
        <?php endif; ?>

        <?php if (!$waliKeaktifanPenilaianCompact && $riwayatLain !== []): ?>
            <div class="mt-3">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary w-100 wali-nilai-riwayat-toggle"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= htmlspecialchars($riwayatCollapseId) ?>"
                    aria-expanded="false"
                    aria-controls="<?= htmlspecialchars($riwayatCollapseId) ?>"
                >
                    <span class="wali-nilai-riwayat-toggle__label"><?= htmlspecialchars($riwayatToggleLabel) ?></span>
                    <i class="fa-solid fa-chevron-down ms-1 wali-nilai-riwayat-toggle__icon" aria-hidden="true"></i>
                </button>
                <div class="collapse mt-2" id="<?= htmlspecialchars($riwayatCollapseId) ?>">
                    <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.68rem;">
                        Riwayat penilaian<?= $isBulan ? ' bulanan' : '' ?>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($riwayatLain as $thRow): ?>
                            <?php
                            $thLabel = (string) ($thRow['label'] ?? '—');
                            $thBadge = santri_riwayat_keaktifan_badge_class($thLabel);
                            $riwLabel = $isBulan
                                ? (string) ($thRow['label_bulan'] ?? '')
                                : (string) ((int) ($thRow['th'] ?? 0));
                            $riwUrl = $isBulan
                                ? app_href('/wali/keaktifan.php?tahun_h=' . (int) ($thRow['year'] ?? 0) . '&bulan_h=' . (int) ($thRow['month'] ?? 0))
                                : '';
                            ?>
                            <?php if ($isBulan && $riwUrl !== ''): ?>
                                <a class="d-flex justify-content-between align-items-center small text-decoration-none wali-nilai-riwayat-link" href="<?= htmlspecialchars($riwUrl) ?>">
                                    <span class="fw-semibold text-body"><?= htmlspecialchars($riwLabel) ?></span>
                                    <span class="badge <?= htmlspecialchars($thBadge) ?>"><?= htmlspecialchars($thLabel) ?></span>
                                </a>
                            <?php else: ?>
                                <div class="d-flex justify-content-between align-items-center small">
                                    <span class="fw-semibold"><?= htmlspecialchars($riwLabel) ?></span>
                                    <span class="badge <?= htmlspecialchars($thBadge) ?>"><?= htmlspecialchars($thLabel) ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
