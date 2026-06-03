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
/** @var bool $isMunawibPortal */
/** @var array<string,mixed>|null $munawibPortalKonteks */
/** @var list<array<string,mixed>> $kegiatanAktifPresensi */

$isMunawibPortal = $isMunawibPortal ?? false;
$kegiatanAktifPresensi = $kegiatanAktifPresensi ?? [];
$munawibPortalKonteks = $munawibPortalKonteks ?? null;

$tickerItems = $pbDashTickerItems !== [] ? $pbDashTickerItems : ['Belum ada jadwal kelas mendatang hari ini'];

$tickerLoop = array_merge($tickerItems, $tickerItems);

$jumlahTingkatanPick = count($tingkatanBaris);

$santriMenuLabel = (int) $jumlahTingkatan . ' tingkatan · ' . (int) $totalSantri . ' santri dibimbing';

require_once __DIR__ . '/../../helpers/login_pembimbing.php';
global $pdo;
$setoranEntry = login_pembimbing_setoran_entry_meta($pdo instanceof PDO ? $pdo : null);

?>

<section class="pb-dash-home-top" aria-label="Dashboard pembimbing">

    <div class="pb-dash-hero-banner">

        <div class="pb-dash-hero-banner__head">

            <?php if (($appLogoHref ?? '') !== ''): ?>
            <div class="pb-dash-hero-banner__logo-wrap" aria-hidden="true">
                <img src="<?= htmlspecialchars((string) $appLogoHref) ?>" alt="" class="pb-dash-hero-banner__logo" decoding="async" data-pondok-cache="1">
            </div>
            <?php endif; ?>

            <div class="pb-dash-hero-banner__identity">

                <p class="pb-dash-hero-banner__kicker mb-0">

                    <?php if ($isMunawibPortal): ?>
                    Portal Munawib · Pengganti pembimbing
                    <?php else: ?>
                    Portal Pembimbing<?= !empty($pbDashHasPkpps) ? ' · PKPPS' : '' ?>
                    <?php endif; ?>

                </p>

                <div class="pb-dash-hero-banner__name-row">

                    <h1 class="pb-dash-hero-banner__name mb-0"><?= htmlspecialchars($labelUser) ?></h1>
                    <?php if (!$isMunawibPortal): ?>
                    <span class="badge <?= $pbSudahHadir ? 'text-bg-success' : 'text-bg-secondary' ?>">
                        <i class="fa-solid <?= $pbSudahHadir ? 'fa-circle-check' : 'fa-clock' ?> me-1" aria-hidden="true"></i>
                        <?= $pbSudahHadir ? 'Hadir' : 'Belum scan' ?>
                    </span>
                    <?php endif; ?>

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



        <?php
        $tickerHasLive = $kegiatanAktifPresensi !== [];
        $tickerItemCount = count($tickerItems);
        ?>
        <div class="pb-dash-hero-banner__ticker pb-dash-ticker pb-dash-ticker--in-banner<?= $tickerHasLive ? ' pb-dash-ticker--live' : '' ?><?= $tickerItemCount <= 1 ? ' pb-dash-ticker--single' : '' ?>" aria-live="polite">

            <div class="pb-dash-ticker__label"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i></div>

            <div class="pb-dash-ticker__viewport">

                <div class="pb-dash-ticker__track">

                    <?php foreach ($tickerLoop as $ti): ?>

                        <span class="pb-dash-ticker__item"><?= htmlspecialchars((string) $ti) ?></span>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

        <?php if ($kegiatanAktifPresensi !== []): ?>
            <?php $inBanner = true; require __DIR__ . '/dashboard_kegiatan_berlangsung_cards.php'; ?>
        <?php endif; ?>

    </div>



    <?php if ($isMunawibPortal && is_array($munawibPortalKonteks)): ?>
    <div class="alert alert-info py-2 px-3 mb-2 small d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>
            <i class="fa-solid fa-user-clock me-1"></i>
            Menggantikan <strong><?= htmlspecialchars((string) ($munawibPortalKonteks['pembimbing_nama'] ?? 'pembimbing')) ?></strong>
            · <?= htmlspecialchars((string) ($munawibPortalKonteks['kegiatan_nama'] ?? 'Kegiatan')) ?>
            <?php if (($munawibPortalKonteks['jam_mulai'] ?? '') !== '' && ($munawibPortalKonteks['jam_selesai'] ?? '') !== ''): ?>
                <span class="text-muted">(<?= htmlspecialchars((string) $munawibPortalKonteks['jam_mulai']) ?>–<?= htmlspecialchars((string) $munawibPortalKonteks['jam_selesai']) ?>)</span>
            <?php endif; ?>
        </span>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/munawib_portal.php?reset=1')) ?>" class="btn btn-sm btn-outline-primary">Ganti pembimbing</a>
    </div>
    <?php endif; ?>



    <?php if (!$isMunawibPortal && $jumlahTingkatanPick > 1): ?>

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



    <?php if (!$isMunawibPortal): ?>

    <div id="pb-santri-panel" class="pb-dash-santri-panel d-none" hidden>

        <div class="pb-dash-santri-panel__head">

            <h3 class="pb-dash-santri-panel__title h6 mb-0" id="pb-santri-panel-title">Daftar santri</h3>

            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="pb-santri-panel-close">Tutup</button>

        </div>

        <ul class="pb-dash-santri-panel__list mb-0" id="pb-santri-panel-list"></ul>

    </div>

    <?php endif; ?>



    <nav class="pb-dash-menu-cards<?= $isMunawibPortal ? ' pb-dash-menu-cards--munawib' : '' ?>" aria-label="Menu cepat pembimbing">

        <?php if (!$isMunawibPortal): ?>
        <button type="button" class="pb-dash-menu-card pb-dash-menu-card--santri js-pb-lihat-santri" aria-expanded="false" aria-controls="pb-santri-panel">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-address-book"></i></span>

            <span class="pb-dash-menu-card__label pb-dash-menu-card__label--wrap"><?= htmlspecialchars($santriMenuLabel) ?></span>

        </button>
        <?php endif; ?>

        <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php')) ?>" class="pb-dash-menu-card pb-dash-menu-card--nilai">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-star"></i></span>

            <span class="pb-dash-menu-card__label">Penilaian</span>

        </a>

        <?php if (!$isMunawibPortal): ?>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php')) ?>" class="pb-dash-menu-card pb-dash-menu-card--izin">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>

            <span class="pb-dash-menu-card__label">Perizinan</span>

        </a>
        <?php endif; ?>

        <a href="<?= htmlspecialchars($keaktivanUrl) ?>" class="pb-dash-menu-card pb-dash-menu-card--keaktifan">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>

            <span class="pb-dash-menu-card__label">Keaktivan</span>

        </a>

        <a href="<?= htmlspecialchars($setoranEntry['href']) ?>" class="pb-dash-menu-card pb-dash-menu-card--setoran pb-dash-menu-card--setoran-wide d-md-none">

            <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($setoranEntry['icon']) ?>"></i></span>

            <span class="pb-dash-menu-card__label pb-dash-menu-card__label--wrap"><?= htmlspecialchars($setoranEntry['title']) ?></span>

        </a>

    </nav>

</section>



<script type="application/json" id="pb-santri-map-json"><?= json_encode($santriMapPerTingkatan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (($pbSantriMapApiUrl ?? '') !== ''): ?>
<script type="application/json" id="pb-santri-map-config"><?= json_encode(['api' => $pbSantriMapApiUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

