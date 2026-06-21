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

$jumlahTingkatanPick = count($tingkatanBaris);

$santriMenuLabel = (int) $jumlahTingkatan . ' tingkatan · ' . (int) $totalSantri . ' santri dibimbing';

require_once __DIR__ . '/../../helpers/login_pembimbing.php';
global $pdo;
$setoranEntry = login_pembimbing_setoran_entry_meta($pdo instanceof PDO ? $pdo : null);

?>

<section class="pb-dash-home-top" aria-label="Dashboard pembimbing">

    <?php
    $jumlahTingkatanHome = $jumlahTingkatanPick;
    require __DIR__ . '/portal_banner.php';
    ?>



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

