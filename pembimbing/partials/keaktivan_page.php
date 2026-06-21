<?php

declare(strict_types=1);

/** @var string $homeUrl */
/** @var int $tahun */
/** @var string $tingkatanFilter */
/** @var array<string,int> $kategoriRingkas */
/** @var array{hadir:int,alpa:int} $statPresensi */
/** @var string $keaktifanView */
/** @var list<array<string,mixed>> $rekapPerKegiatan */
/** @var array<string,list<array<string,mixed>>> $keaktivanByTingkatan */
/** @var list<array<string,mixed>> $keaktivanRows */
/** @var list<string> $semuaTingkatanList */
/** @var string $rekapJenis */
/** @var bool $hasKajianJadwal */
/** @var bool $hasPkppsJadwal */
/** @var string $baseDashQuery */

$rekapJenis = $rekapJenis ?? 'kajian';
$hasKajianJadwal = $hasKajianJadwal ?? false;
$hasPkppsJadwal = $hasPkppsJadwal ?? false;
$baseDashQuery = $baseDashQuery ?? ('tahun=' . (int) $tahun);
$showRekapTabs = $hasKajianJadwal && $hasPkppsJadwal;
$rekapJenisLabel = $rekapJenis === 'pkpps' ? 'PKPPS' : "Kajian (Ta'lim & Jama'ah)";
?>
<section class="pb-keaktivan-page" aria-label="Keaktivan santri">
    <?php
    if (!function_exists('pembimbing_portal_banner_resolve_variant')) {
        require_once __DIR__ . '/../../helpers/pembimbing_portal_banner.php';
    }
    $pbKeaktivanBannerVariant = pembimbing_portal_banner_resolve_variant(
        (bool) ($isMunawibPortal ?? false),
        $hasPkppsJadwal,
        $hasKajianJadwal,
        $rekapJenis
    );
    $pbBannerVariant = $pbKeaktivanBannerVariant;
    $pbBannerCfg = pembimbing_portal_banner_get($pdo, $pbKeaktivanBannerVariant);
    if (($pbBannerCfg['enabled'] ?? '1') !== '1') {
        $pbBannerCfg = pembimbing_portal_banner_defaults($pbKeaktivanBannerVariant);
    }
    $jumlahTingkatanHome = count($semuaTingkatanList ?? []);
    require __DIR__ . '/portal_banner.php';
    ?>
    <div class="pb-keaktivan-page__top">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
            <div>
                <h1 class="pb-keaktivan-page__title mb-1">Keaktivan Santri</h1>
                <p class="pb-keaktivan-page__sub text-muted mb-0">
                    Rekap <?= htmlspecialchars($rekapJenisLabel) ?> · tahun Masehi <?= (int) $tahun ?> (s/d hari ini)
                    — <?= htmlspecialchars(rekap_keaktifan_rekap_footnote($pdo)) ?>.
                    <?php if ($tingkatanFilter !== ''): ?>
                        · tingkatan <?= htmlspecialchars($tingkatanFilter) ?>
                    <?php elseif ($semuaTingkatanList !== []): ?>
                        · <?= count($semuaTingkatanList) ?> tingkatan diasuh
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn btn-sm btn-outline-secondary">← Beranda</a>
        </div>

        <?php if ($showRekapTabs): ?>
            <nav class="nav nav-pills gap-1 mb-3 pb-keaktivan-jenis-tabs" aria-label="Jenis rekap keaktivan">
                <a class="nav-link<?= $rekapJenis === 'kajian' ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&view=keaktivan&rekap_jenis=kajian')) ?>">
                    Kajian (Ta'lim &amp; Jama'ah)
                </a>
                <a class="nav-link<?= $rekapJenis === 'pkpps' ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&view=keaktivan&rekap_jenis=pkpps')) ?>">
                    PKPPS
                </a>
            </nav>
        <?php elseif ($rekapJenis === 'pkpps'): ?>
            <p class="small mb-2"><span class="badge text-bg-primary">PKPPS</span> Hanya kegiatan &amp; santri program PKPPS Anda.</p>
        <?php else: ?>
            <p class="small mb-2"><span class="badge text-bg-secondary">Kajian</span> Ta'lim &amp; jama'ah sesuai jadwal Anda.</p>
        <?php endif; ?>

        <form method="get" class="row g-2 align-items-end pb-keaktivan-page__filter">
            <input type="hidden" name="view" value="keaktivan">
            <input type="hidden" name="mode" value="ringkas">
            <input type="hidden" name="keaktifan_view" value="<?= htmlspecialchars($keaktifanView) ?>">
            <input type="hidden" name="rekap_jenis" value="<?= htmlspecialchars($rekapJenis) ?>">
            <?php if ($semuaTingkatanList !== []): ?>
                <div class="col-auto">
                    <label class="form-label small mb-0" for="pb-keaktivan-tingkatan">Tingkatan</label>
                    <select id="pb-keaktivan-tingkatan" name="tingkatan" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <?php foreach ($semuaTingkatanList as $tkOpt): ?>
                            <option value="<?= htmlspecialchars($tkOpt) ?>"<?= $tingkatanFilter === $tkOpt ? ' selected' : '' ?>><?= htmlspecialchars($tkOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-auto">
                <label class="form-label small mb-0" for="pb-keaktivan-tahun">Tahun</label>
                <input id="pb-keaktivan-tahun" type="number" name="tahun" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $tahun ?>" style="width:6rem">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Terapkan</button>
            </div>
            <div class="col-auto">
                <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?view=keaktivan&rekap_jenis=' . rawurlencode($rekapJenis) . '&tahun=' . (int) $tahun . ($tingkatanFilter !== '' ? '&tingkatan=' . rawurlencode($tingkatanFilter) : '') . '&refresh=1')) ?>" class="btn btn-sm btn-link">Segarkan data</a>
            </div>
        </form>
    </div>

    <div class="pb-keaktivan-page__kpi pb-keaktifan-kpi" role="list" aria-label="Ringkasan kategori">
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--bagus" role="listitem">
            <div class="pb-keaktifan-kpi__label">Bagus</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['bagus'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--sedang" role="listitem">
            <div class="pb-keaktifan-kpi__label">Sedang</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['sedang'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--buruk" role="listitem">
            <div class="pb-keaktifan-kpi__label">Buruk</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['buruk'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--alpa" role="listitem">
            <div class="pb-keaktifan-kpi__label">Alpa hari ini</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $statPresensi['alpa'] ?></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm pb-keaktivan-page__rekap">
        <div class="card-body p-3 p-md-4">
            <?php
            $rekapPanelClass = 'pb-dash-rekap-keaktivan--page';
            $rekapFormMode = 'ringkas';
            $rekapDashView = 'keaktivan';
            require __DIR__ . '/rekap_keaktivan_inline.php';
            ?>
        </div>
    </div>
</section>
