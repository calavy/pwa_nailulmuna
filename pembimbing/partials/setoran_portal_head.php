<?php

declare(strict_types=1);

/** @var string $labelUser @var bool $isMunawib @var int $jumlahTingkatan @var int $jumlahSantri */
/** @var array{setor:int,belum:int,izin:int,libur:int} $setoranHariIni */
/** @var string $pbDashHijriLabel @var string $pbDashPasaran */
/** @var list<string> $tingkatanList @var array<string,mixed>|null $munawibKonteks */

?>
<section class="pb-dash-home-top st-portal-dash-top" aria-label="Portal penerima setoran">
    <div class="pb-dash-hero-banner st-portal-hero-banner">
        <div class="pb-dash-hero-banner__head">
            <?php if (($appLogoHref ?? '') !== ''): ?>
            <div class="pb-dash-hero-banner__logo-wrap" aria-hidden="true">
                <img src="<?= htmlspecialchars((string) $appLogoHref) ?>" alt="" class="pb-dash-hero-banner__logo" decoding="async" data-pondok-cache="1">
            </div>
            <?php endif; ?>
            <div class="pb-dash-hero-banner__identity">
                <p class="pb-dash-hero-banner__kicker mb-0">
                    <?php if ($isMunawib): ?>
                        Portal Penerima Setoran · Munawib
                        <?php if ($munawibKonteks !== null && ($munawibKonteks['pembimbing_nama'] ?? '') !== ''): ?>
                            · Pengganti <?= htmlspecialchars((string) $munawibKonteks['pembimbing_nama']) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        Portal Penerima Setoran · Pembimbing
                    <?php endif; ?>
                </p>
                <div class="pb-dash-hero-banner__name-row">
                    <h1 class="pb-dash-hero-banner__name mb-0"><?= htmlspecialchars($labelUser) ?></h1>
                    <div class="pb-dash-hero-banner__clock" aria-live="polite">
                        <div class="pb-dash-hero-banner__clock-time" id="st-portal-live-clock">--:--:--</div>
                    </div>
                </div>
                <div class="pb-dash-hero-banner__meta-row pb-dash-hero-banner__meta-row--clock-only">
                    <div class="pb-dash-hero-banner__clock-meta">
                        <div class="pb-dash-hero-banner__clock-date" id="st-portal-live-date">—</div>
                        <?php if ($pbDashHijriLabel !== '' || $pbDashPasaran !== ''): ?>
                        <div class="pb-dash-hero-banner__clock-extra">
                            <?php if ($pbDashHijriLabel !== ''): ?><?= htmlspecialchars($pbDashHijriLabel) ?><?php endif; ?>
                            <?php if ($pbDashPasaran !== ''): ?> · <?= htmlspecialchars($pbDashPasaran) ?><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="small mb-0 mt-1 opacity-90">
                    Dashboard setoran · <?= (int) $jumlahTingkatan ?> tingkatan · <?= (int) $jumlahSantri ?> santri
                </p>
            </div>
        </div>
        <div class="st-portal-kpi row g-2 mx-0">
            <div class="col-3 px-1">
                <div class="st-portal-kpi__box st-portal-kpi__box--setor">
                    <div class="st-portal-kpi__val"><?= (int) $setoranHariIni['setor'] ?></div>
                    <div class="st-portal-kpi__lbl">Setor</div>
                </div>
            </div>
            <div class="col-3 px-1">
                <div class="st-portal-kpi__box st-portal-kpi__box--belum">
                    <div class="st-portal-kpi__val"><?= (int) $setoranHariIni['belum'] ?></div>
                    <div class="st-portal-kpi__lbl">Belum</div>
                </div>
            </div>
            <div class="col-3 px-1">
                <div class="st-portal-kpi__box st-portal-kpi__box--izin">
                    <div class="st-portal-kpi__val"><?= (int) $setoranHariIni['izin'] ?></div>
                    <div class="st-portal-kpi__lbl">Izin</div>
                </div>
            </div>
            <div class="col-3 px-1">
                <div class="st-portal-kpi__box st-portal-kpi__box--total">
                    <div class="st-portal-kpi__val"><?= (int) $jumlahSantri ?></div>
                    <div class="st-portal-kpi__lbl">Santri</div>
                </div>
            </div>
        </div>
    </div>
</section>
