<?php

declare(strict_types=1);

/**
 * Satu panel keaktivan (Ta'lim atau Jama'ah).
 *
 * @var array<string,mixed> $panel
 * @var bool $panelActive
 * @var string $today
 * @var PDO $pdo
 * @var string $jamServerLabel
 * @var string $tglLabel
 * @var callable(string): string $labelKegiatan
 * @var callable(int,int): float $barPct
 * @var callable(array,int): string $previewNames
 */
$panel = is_array($panel ?? null) ? $panel : [];
$panelActive = !empty($panelActive);
$panelSlug = (string) ($panel['slug'] ?? 'panel');
$panelKey = (string) ($panel['key'] ?? '');
$keaktivanByTingkatan = is_array($panel['keaktivanByTingkatan'] ?? null) ? $panel['keaktivanByTingkatan'] : [];
$detailLive = is_array($panel['detailLive'] ?? null) ? $panel['detailLive'] : [];
$totalsLive = is_array($panel['totalsLive'] ?? null) ? $panel['totalsLive'] : ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0, 'persen' => 0.0];
$sdmByTingkatan = is_array($panel['sdmByTingkatan'] ?? null) ? $panel['sdmByTingkatan'] : ['pembimbing' => [], 'munawib' => []];
$kegiatanAktifPanel = is_array($panel['kegiatanAktif'] ?? null) ? $panel['kegiatanAktif'] : [];
$panelMode = (string) ($panel['mode'] ?? 'hari');
$panelIsLive = $panelMode === 'live';
$panelIsProgress = $panelMode === 'progress';
$panelModeLabel = $panelIsLive
    ? 'Berlangsung <span data-pg-sync-clock="hm">' . htmlspecialchars($jamServerLabel) . '</span> WIB'
    : ($panelIsProgress ? 'Sudah berjalan hari ini' : 'Ringkasan hari ini');

$totalPerhatian = (int) ($totalsLive['alpa'] ?? 0);
$kegiatanPerhatian = array_values(array_filter(
    $detailLive,
    static fn (array $dk): bool => ((int) ($dk['alpa'] ?? 0)) > 0
));
$heroId = 'khHero-' . $panelSlug;
?>
<div class="pg-dash-kat-panel<?= $panelActive ? '' : ' d-none' ?>"
    data-pg-kat-panel="<?= htmlspecialchars($panelKey) ?>"
    id="pg-dash-kat-<?= htmlspecialchars($panelSlug) ?>">

    <?php if ($keaktivanByTingkatan === []): ?>
        <?php if ($panelIsLive && is_array($pgIdleData ?? null)): ?>
            <?php
            $idleContext = 'pengasuh';
            $jamLabel = $jamServerLabel;
            $idleData = is_array($pgIdleData) ? $pgIdleData : ['agenda' => [], 'presensi' => [], 'jadwal_berikutnya' => []];
            $canJadwalLink = false;
            require __DIR__ . '/../../includes/partials/dashboard_kegiatan_idle.php';
            ?>
        <?php else: ?>
        <div class="dash-empty-chart py-5 text-center text-muted">
            <div class="dash-empty-chart__inner">
                <div class="dash-empty-chart__icon display-6 opacity-50" aria-hidden="true"><i class="fa-regular fa-calendar"></i></div>
                <?php if ($panelIsLive): ?>
                    <?php $liburNamaPanel = trim((string) (is_array($liburTampil ?? null) ? ($liburTampil['nama'] ?? '') : '')); ?>
                    <?php if ($liburNamaPanel !== ''): ?>
                    <p class="mb-0 fw-semibold"><?= htmlspecialchars('Hari libur: ' . $liburNamaPanel) ?></p>
                    <?php else: ?>
                    <p class="mb-0 fw-semibold">Tidak ada kegiatan <?= htmlspecialchars((string) ($panel['label'] ?? '')) ?> pukul <span data-pg-sync-clock="hm"><?= htmlspecialchars($jamServerLabel) ?></span>.</p>
                    <?php endif; ?>
                <?php elseif ($panelIsProgress): ?>
                    <p class="mb-0 fw-semibold">Belum ada presensi <?= htmlspecialchars((string) ($panel['label'] ?? '')) ?> yang selesai hari ini.</p>
                <?php else: ?>
                    <p class="mb-0 fw-semibold">Belum ada data presensi <?= htmlspecialchars((string) ($panel['label'] ?? '')) ?> hari ini.</p>
                <?php endif; ?>
                <p class="small mb-0 mt-1">
                    <a href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php')) ?>">Laporan hari</a>
                </p>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($totalPerhatian > 0): ?>
        <div class="card border-warning kh-section kh-banner-attn shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-semibold text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= (int) $totalPerhatian ?> santri perlu perhatian</span>
                        <span class="text-muted small ms-1">(alpa)</span>
                    </div>
                    <span class="small text-muted"><?= count($kegiatanPerhatian) ?> kegiatan terdampak</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($totalsLive['total'] > 0):
            $khHeroSantri = rekap_keaktifan_hari_santri_agregat($detailLive);
            $khHeroStatItems = [
                ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) $totalsLive['hadir']],
                ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) $totalsLive['izin']],
                ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) $totalsLive['sakit']],
                ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) $totalsLive['alpa']],
            ];
            ?>
        <div class="kh-hero kh-section mb-4" id="<?= htmlspecialchars($heroId) ?>">
            <div class="kh-hero__top">
                <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?> · <?= htmlspecialchars((string) ($panel['label'] ?? '')) ?> · <?= $panelModeLabel ?></div>
                <div class="small text-muted"><?= count($detailLive) ?> kegiatan · <?= (int) $totalsLive['total'] ?> pencatatan (santri × kegiatan)</div>
            </div>
            <div class="kh-totals">
                <?php foreach ($khHeroStatItems as $hi): ?>
                <button type="button"
                    class="kh-total-pill kh-total-pill--<?= htmlspecialchars($hi['key']) ?> kh-total-pill--clickable"
                    data-kh-stat-tab="<?= htmlspecialchars($hi['tab']) ?>"
                    data-kh-stat-scope="hero"
                    aria-expanded="false"
                    aria-haspopup="true">
                    <?php if ($hi['key'] === 'hadir'): ?>
                    <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                    <div class="kh-total-pill__pct"><?= number_format((float) $totalsLive['persen'], 1, ',', '.') ?>% hadir</div>
                    <?php else: ?>
                    <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                    <?php endif; ?>
                    <div class="kh-total-pill__l"><?= htmlspecialchars($hi['label']) ?></div>
                </button>
                <?php endforeach; ?>
            </div>
            <script type="application/json" class="kh-santri-data kh-santri-data--hero"><?= json_encode($khHeroSantri, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="kh-stat-popup d-none" data-kh-stat-popup data-kh-stat-scope="hero" role="region" aria-live="polite"></div>
            <div class="kh-legend">
                <span class="l-hadir">Hadir</span>
                <span class="l-izin">Izin</span>
                <span class="l-sakit">Sakit</span>
                <span class="l-alpa">Alpa</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="pg-dash-tk-groups">
            <?php foreach ($keaktivanByTingkatan as $tkGroup):
                $tk = (string) ($tkGroup['tingkatan'] ?? '-');
                $kegiatanList = is_array($tkGroup['kegiatan'] ?? null) ? $tkGroup['kegiatan'] : [];
                $tkSlug = preg_replace('/[^a-z0-9]+/i', '-', $tk) ?: 'tk';
                ?>
            <div class="pg-dash-tk-group mb-4">
                <div class="pg-dash-tk-group__head">
                    <h3 class="pg-dash-tk-group__title">
                        <i class="fa-solid fa-layer-group me-1 text-primary"></i>
                        Tingkatan <?= htmlspecialchars($tk) ?>
                    </h3>
                    <?php if ((int) ($tkGroup['alpa_izin'] ?? 0) > 0): ?>
                        <span class="badge text-bg-warning text-dark"><?= (int) $tkGroup['alpa_izin'] ?> izin/alpa</span>
                    <?php else: ?>
                        <span class="badge text-bg-success-subtle text-success border border-success-subtle">Aman</span>
                    <?php endif; ?>
                </div>

                <div class="kh-grid kh-section">
                    <?php foreach ($kegiatanList as $dk):
                        $kid = (int) ($dk['kegiatan_id'] ?? 0);
                        $cardUid = $panelSlug . '-' . $kid . '-' . $tkSlug;
                        $total = (int) ($dk['total'] ?? 0);
                        $hadir = (int) ($dk['hadir'] ?? 0);
                        $alpa = (int) ($dk['alpa'] ?? 0);
                        $izin = (int) ($dk['izin'] ?? 0);
                        $sakit = (int) ($dk['sakit'] ?? 0);
                        $perlu = $alpa;
                        $pctHadir = $total > 0 ? (int) round(100 * $hadir / $total) : 0;
                        $santri = is_array($dk['santri'] ?? null) ? $dk['santri'] : [];
                        $preview = $previewNames($santri);
                        $needsAttention = $perlu > 0;
                        $barAman = $total > 0 && $alpa === 0;
                        $statItems = [
                            ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => $hadir],
                            ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => $izin],
                            ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => $sakit],
                            ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => $alpa],
                        ];
                        $sdmLabels = pengasuh_dashboard_sdm_status_for_card(
                            $pdo,
                            $today,
                            $kid,
                            $tk,
                            $kegiatanAktifPanel
                        );
                        ?>
                    <article class="kh-card<?= $needsAttention ? ' kh-card--warning' : '' ?>" id="keg-<?= htmlspecialchars($cardUid) ?>" data-kegiatan-id="<?= $kid ?>">
                        <div class="kh-card__head">
                            <div class="pg-dash-card-head-row">
                                <h2 class="kh-card__title"><?= htmlspecialchars($labelKegiatan((string) ($dk['nama_kegiatan'] ?? ''))) ?></h2>
                                <?php if ($sdmLabels !== []): ?>
                                <div class="pg-dash-sdm-badges" aria-label="Status kehadiran SDM">
                                    <?php foreach ($sdmLabels as $sl): ?>
                                    <span class="pg-dash-sdm-badge pg-dash-sdm-badge--<?= htmlspecialchars((string) ($sl['variant'] ?? 'neutral')) ?>">
                                        <?= htmlspecialchars((string) ($sl['text'] ?? '')) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="kh-card__meta"><?= $hadir ?> hadir dari <?= $total ?> santri · <strong><?= $pctHadir ?>%</strong></div>
                            <div class="kh-bar<?= $barAman ? ' kh-bar--aman' : '' ?>" role="img" aria-label="<?= $barAman ? 'Kegiatan aman, tanpa alpa' : 'Distribusi presensi' ?>">
                                <?php if ($barAman): ?>
                                <span class="kh-bar__seg kh-bar__seg--aman" style="width:100%" title="Tidak ada alpa"></span>
                                <?php else: ?>
                                <?php foreach (['hadir' => 'hadir', 'izin' => 'izin', 'sakit' => 'sakit', 'alpa' => 'alpa'] as $key => $cls):
                                    $n = (int) ($dk[$key] ?? 0);
                                    $w = $barPct($n, $total);
                                    if ($w <= 0) {
                                        continue;
                                    }
                                    ?>
                                <span class="kh-bar__seg kh-bar__seg--<?= $cls ?>" style="width:<?= $w ?>%" title="<?= ucfirst($key) ?> <?= $n ?>"></span>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="kh-stats">
                            <?php foreach ($statItems as $si): ?>
                            <button type="button"
                                class="kh-stat kh-stat--<?= htmlspecialchars($si['key']) ?> kh-stat--clickable"
                                data-kh-stat-tab="<?= htmlspecialchars($si['tab']) ?>"
                                aria-expanded="false"
                                aria-haspopup="true">
                                <span class="kh-stat__n"><?= $si['n'] ?></span>
                                <span class="kh-stat__l"><?= htmlspecialchars($si['label']) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="kh-stat-popup d-none" data-kh-stat-popup role="region" aria-live="polite"></div>
                        <?php if ($perlu > 0): ?>
                        <div class="kh-card__alert" title="Perlu tindak lanjut">
                            <div class="kh-card__alert-head">
                                <i class="fa-solid fa-triangle-exclamation kh-card__alert-icon" aria-hidden="true"></i>
                                <span class="kh-card__alert-count"><?= $perlu ?> santri perlu perhatian</span>
                            </div>
                            <?php if ($preview !== ''): ?>
                            <div class="kh-card__alert-names d-none d-md-block"><?= htmlspecialchars($preview) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="kh-card__alert kh-card__alert--ok">
                            <div class="kh-card__alert-head">
                                <i class="fa-solid fa-circle-check kh-card__alert-icon" aria-hidden="true"></i>
                                <span>Semua santri sudah tercatat hadir/izin/sakit</span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="kh-card__body">
                            <button type="button" class="kh-detail-toggle" data-bs-toggle="collapse" data-bs-target="#kh-detail-<?= htmlspecialchars($cardUid) ?>" aria-expanded="false" data-kh-detail-btn>
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                <span>Daftar santri<?= $perlu > 0 ? ' (' . $perlu . ' perlu)' : '' ?></span>
                            </button>
                        </div>
                        <div class="collapse" id="kh-detail-<?= htmlspecialchars($cardUid) ?>">
                            <div class="kh-detail-panel">
                                <div class="kh-tabs" role="tablist">
                                    <button type="button" class="kh-tab is-active" data-kh-tab="perlu" data-kh-card="<?= htmlspecialchars($cardUid) ?>">Perlu ditindak (<?= $perlu ?>)</button>
                                    <button type="button" class="kh-tab" data-kh-tab="HADIR" data-kh-card="<?= htmlspecialchars($cardUid) ?>">Hadir (<?= $hadir ?>)</button>
                                    <button type="button" class="kh-tab" data-kh-tab="ALPA" data-kh-card="<?= htmlspecialchars($cardUid) ?>">Alpa (<?= $alpa ?>)</button>
                                    <button type="button" class="kh-tab" data-kh-tab="IZIN" data-kh-card="<?= htmlspecialchars($cardUid) ?>">Izin (<?= $izin ?>)</button>
                                    <button type="button" class="kh-tab" data-kh-tab="SAKIT" data-kh-card="<?= htmlspecialchars($cardUid) ?>">Sakit (<?= $sakit ?>)</button>
                                </div>
                                <?php
                                $listsPayload = [
                                    'perlu' => $santri['ALPA'] ?? [],
                                    'HADIR' => $santri['HADIR'] ?? [],
                                    'ALPA' => $santri['ALPA'] ?? [],
                                    'IZIN' => $santri['IZIN'] ?? [],
                                    'SAKIT' => $santri['SAKIT'] ?? [],
                                ];
                                ?>
                                <script type="application/json" class="kh-santri-data"><?= json_encode($listsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                                <?php foreach (['perlu', 'HADIR', 'ALPA', 'IZIN', 'SAKIT'] as $tabKey): ?>
                                <ul class="kh-list<?= $tabKey === 'perlu' ? '' : ' d-none' ?>" data-kh-list="<?= htmlspecialchars($tabKey) ?>" data-kh-card="<?= htmlspecialchars($cardUid) ?>" data-kh-lazy="1" data-kh-empty-msg="<?= htmlspecialchars($tabKey === 'perlu' ? 'Semua santri sudah tercatat hadir/izin/sakit.' : 'Tidak ada data.') ?>"></ul>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        $sdmPb = is_array($sdmByTingkatan['pembimbing'] ?? null) ? $sdmByTingkatan['pembimbing'] : [];
        $sdmMw = is_array($sdmByTingkatan['munawib'] ?? null) ? $sdmByTingkatan['munawib'] : [];
        if ($sdmPb !== [] || $sdmMw !== []):
        ?>
        <div class="pg-dash-sdm-inline mt-4 pt-3 border-top">
            <h3 class="h6 fw-bold mb-3">Keaktivan SDM<?= $panelIsLive ? ' berlangsung' : ' hari ini' ?> · <?= htmlspecialchars((string) ($panel['label'] ?? '')) ?></h3>
            <div class="row g-4">
                <?php if ($sdmPb !== []): ?>
                <div class="col-lg-6">
                    <h4 class="h6 fw-semibold mb-2"><i class="fa-solid fa-user-tie text-success me-1"></i> Pembimbing</h4>
                    <?php foreach ($sdmPb as $grp): ?>
                    <div class="pg-dash-sdm-group mb-3">
                        <div class="pg-dash-sdm-group__head">
                            <span class="fw-semibold">Tingkatan <?= htmlspecialchars((string) ($grp['tingkatan'] ?? '-')) ?></span>
                            <?php if ((int) ($grp['masalah'] ?? 0) > 0): ?>
                                <span class="badge text-bg-danger-subtle text-danger border"><?= (int) $grp['masalah'] ?> belum hadir</span>
                            <?php endif; ?>
                        </div>
                        <ul class="pg-dash-sdm-list list-unstyled mb-0">
                            <?php foreach ((array) ($grp['items'] ?? []) as $it): ?>
                            <li class="pg-dash-sdm-item<?= empty($it['hadir']) ? ' pg-dash-sdm-item--no' : ' pg-dash-sdm-item--ok' ?>">
                                <span class="pg-dash-sdm-dot" aria-hidden="true"></span>
                                <span class="pg-dash-sdm-nama"><?= htmlspecialchars((string) ($it['nama'] ?? '-')) ?></span>
                                <span class="pg-dash-sdm-status small <?= empty($it['hadir']) ? 'text-danger' : 'text-success' ?>"><?= empty($it['hadir']) ? 'Belum hadir' : 'Sudah hadir' ?></span>
                                <span class="pg-dash-sdm-meta text-muted small"><?= htmlspecialchars((string) ($it['kegiatan'] ?? '')) ?> · <?= htmlspecialchars((string) ($it['jam'] ?? '')) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($sdmMw !== []): ?>
                <div class="col-lg-6">
                    <h4 class="h6 fw-semibold mb-2"><i class="fa-solid fa-user-clock text-info me-1"></i> Munawib</h4>
                    <?php foreach ($sdmMw as $grp): ?>
                    <div class="pg-dash-sdm-group mb-3">
                        <div class="pg-dash-sdm-group__head">
                            <span class="fw-semibold">Tingkatan <?= htmlspecialchars((string) ($grp['tingkatan'] ?? '-')) ?></span>
                            <?php if ((int) ($grp['masalah'] ?? 0) > 0): ?>
                                <span class="badge text-bg-danger-subtle text-danger border"><?= (int) $grp['masalah'] ?> belum hadir</span>
                            <?php endif; ?>
                        </div>
                        <ul class="pg-dash-sdm-list list-unstyled mb-0">
                            <?php foreach ((array) ($grp['items'] ?? []) as $it): ?>
                            <li class="pg-dash-sdm-item<?= empty($it['hadir']) ? ' pg-dash-sdm-item--no' : ' pg-dash-sdm-item--ok' ?>">
                                <span class="pg-dash-sdm-dot" aria-hidden="true"></span>
                                <span class="pg-dash-sdm-nama"><?= htmlspecialchars((string) ($it['nama'] ?? '-')) ?></span>
                                <span class="pg-dash-sdm-status small <?= empty($it['hadir']) ? 'text-danger' : 'text-success' ?>"><?= empty($it['hadir']) ? 'Belum hadir' : 'Sudah hadir' ?></span>
                                <span class="pg-dash-sdm-meta text-muted small"><?= htmlspecialchars((string) ($it['kegiatan'] ?? '')) ?> · <?= htmlspecialchars((string) ($it['jam'] ?? '')) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
