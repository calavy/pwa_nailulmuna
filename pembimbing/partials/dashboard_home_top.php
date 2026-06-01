<?php



declare(strict_types=1);



/** @var string $labelUser */

/** @var int $jumlahTingkatan */

/** @var int $totalSantri */

/** @var list<string> $pbDashTickerItems */

/** @var string $keaktivanUrl */

/** @var array<string,list<array<string,mixed>>> $santriMapPerTingkatan */
/** @var string $pbSantriMapApiUrl */

/** @var list<array<string,mixed>> $tingkatanBaris */

/** @var string $pbDashHijriLabel */

/** @var string $pbDashPasaran */

/** @var bool $pbDashHasPkpps */

/** @var bool $pbSudahHadir */


$tickerItems = $pbDashTickerItems !== [] ? $pbDashTickerItems : ['Belum ada jadwal kelas mendatang hari ini'];

$tickerLoop = array_merge($tickerItems, $tickerItems);

$jumlahTingkatanPick = count($tingkatanBaris);

$santriMenuLabel = (int) $jumlahTingkatan . ' tingkatan · ' . (int) $totalSantri . ' santri dibimbing';

?>

<section class="pb-dash-home-top" aria-label="Dashboard pembimbing">

    <div class="pb-dash-hero-banner">

        <div class="pb-dash-hero-banner__head">

            <div class="pb-dash-hero-banner__identity">

                <p class="pb-dash-hero-banner__kicker mb-0">

                    Portal Pembimbing<?= !empty($pbDashHasPkpps) ? ' · PKPPS' : '' ?>

                </p>

                <div class="pb-dash-hero-banner__name-row">

                    <h1 class="pb-dash-hero-banner__name mb-0"><?= htmlspecialchars($labelUser) ?></h1>
                    <span class="badge <?= $pbSudahHadir ? 'text-bg-success' : 'text-bg-secondary' ?>">
                        <i class="fa-solid <?= $pbSudahHadir ? 'fa-circle-check' : 'fa-clock' ?> me-1" aria-hidden="true"></i>
                        <?= $pbSudahHadir ? 'Hadir' : 'Belum scan' ?>
                    </span>

                    <div class="pb-dash-hero-banner__clock" aria-live="polite">

                        <div class="pb-dash-hero-banner__clock-time" id="dashboard-live-clock">--:--:--</div>

                    </div>

                </div>

                <div class="pb-dash-hero-banner__meta-row pb-dash-hero-banner__meta-row--clock-only">

                    <div class="pb-dash-hero-banner__clock-meta">

                        <div class="pb-dash-hero-banner__clock-date" id="dashboard-live-date">—</div>

                        <?php if ($pbDashHijriLabel !== '' || $pbDashPasaran !== ''): ?>

                        <div class="pb-dash-hero-banner__clock-extra">

                            <?php if ($pbDashHijriLabel !== ''): ?>

                                <span><i class="fa-solid fa-moon me-1" aria-hidden="true"></i><?= htmlspecialchars($pbDashHijriLabel) ?></span>

                            <?php endif; ?>

                            <?php if ($pbDashPasaran !== ''): ?>

                                <span class="ms-2"><i class="fa-solid fa-sun me-1" aria-hidden="true"></i>Pasaran <?= htmlspecialchars($pbDashPasaran) ?></span>

                            <?php endif; ?>

                        </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>



        <div class="pb-dash-hero-banner__ticker pb-dash-ticker pb-dash-ticker--in-banner" aria-live="polite">

            <div class="pb-dash-ticker__label"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i></div>

            <div class="pb-dash-ticker__viewport">

                <div class="pb-dash-ticker__track">

                    <?php foreach ($tickerLoop as $ti): ?>

                        <span class="pb-dash-ticker__item"><?= htmlspecialchars((string) $ti) ?></span>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </div>



    <?php if ($jumlahTingkatanPick > 1): ?>

    <div class="pb-dash-tk-pick d-none" id="pb-tk-pick" hidden>

        <p class="pb-dash-tk-pick__hint mb-1">Pilih tingkatan</p>

        <div class="pb-dash-tk-pick__btns">

            <?php foreach ($tingkatanBaris as $tkRow):

                $tkName = (string) ($tkRow['tingkatan'] ?? '');

                if ($tkName === '') {

                    continue;

                }

            ?>

                <button type="button" class="btn btn-sm btn-outline-secondary js-pb-pick-tingkatan" data-tingkatan="<?= htmlspecialchars($tkName) ?>">

                    <?= htmlspecialchars($tkName) ?>

                    <span class="text-muted">(<?= (int) ($tkRow['total'] ?? 0) ?>)</span>

                </button>

            <?php endforeach; ?>

        </div>

    </div>

    <?php endif; ?>



    <div id="pb-santri-panel" class="pb-dash-santri-panel d-none" hidden>

        <div class="pb-dash-santri-panel__head">

            <h3 class="pb-dash-santri-panel__title h6 mb-0" id="pb-santri-panel-title">Daftar santri</h3>

            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="pb-santri-panel-close">Tutup</button>

        </div>

        <ul class="pb-dash-santri-panel__list mb-0" id="pb-santri-panel-list"></ul>

    </div>



    <nav class="pb-dash-menu-cards" aria-label="Menu cepat pembimbing">

        <button type="button" class="pb-dash-menu-card pb-dash-menu-card--santri js-pb-lihat-santri" aria-expanded="false" aria-controls="pb-santri-panel">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-address-book"></i></span>

            <span class="pb-dash-menu-card__label pb-dash-menu-card__label--wrap"><?= htmlspecialchars($santriMenuLabel) ?></span>

        </button>

        <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php')) ?>" class="pb-dash-menu-card pb-dash-menu-card--nilai">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-star"></i></span>

            <span class="pb-dash-menu-card__label">Penilaian</span>

        </a>

        <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php')) ?>" class="pb-dash-menu-card pb-dash-menu-card--izin">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>

            <span class="pb-dash-menu-card__label">Perizinan</span>

        </a>

        <a href="<?= htmlspecialchars($keaktivanUrl) ?>" class="pb-dash-menu-card pb-dash-menu-card--keaktifan">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>

            <span class="pb-dash-menu-card__label">Keaktivan</span>

        </a>

    </nav>

</section>



<script type="application/json" id="pb-santri-map-json"><?= json_encode($santriMapPerTingkatan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (($pbSantriMapApiUrl ?? '') !== ''): ?>
<script type="application/json" id="pb-santri-map-config"><?= json_encode(['api' => $pbSantriMapApiUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

