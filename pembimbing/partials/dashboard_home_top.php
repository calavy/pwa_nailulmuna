<?php



declare(strict_types=1);



/** @var string $labelUser */

/** @var int $jumlahTingkatan */

/** @var int $totalSantri */

/** @var list<string> $pbDashTickerItems */

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

</section>



<script type="application/json" id="pb-santri-map-json"><?= json_encode($santriMapPerTingkatan, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (($pbSantriMapApiUrl ?? '') !== ''): ?>
<script type="application/json" id="pb-santri-map-config"><?= json_encode(['api' => $pbSantriMapApiUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

