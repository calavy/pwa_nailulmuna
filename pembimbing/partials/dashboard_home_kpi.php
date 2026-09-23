<?php

declare(strict_types=1);

/** @var array<string,mixed> $pbHomeBundle */
/** @var bool $isMunawibPortal */
/** @var string $keaktivanUrl */

require_once __DIR__ . '/../../helpers/app_path.php';

$pbHomeBundle = is_array($pbHomeBundle ?? null) ? $pbHomeBundle : [];
$kpi = (array) ($pbHomeBundle['kpi'] ?? []);
$setoran = (array) ($pbHomeBundle['setoran'] ?? []);
$isMunawibPortal = !empty($isMunawibPortal);
$showSetoran = !$isMunawibPortal && !empty($setoran['ok']);
$keaktivanUrl = (string) ($keaktivanUrl ?? app_href('/pembimbing/dashboard.php?view=keaktivan'));
$pending = (int) ($kpi['penilaian_pending'] ?? 0);
$presensiHref = app_href('/presensi/scan.php');
$kegiatanHref = '#pb-dash-home-kegiatan';
$setoranHref = app_href('/pembimbing/setoran.php');
$penilaianHref = $pending > 0
    ? app_href('/pembimbing/tugas/nilai.php')
    : app_href('/pembimbing/tugas/index.php');

?>
<section class="pb-dash-home-kpi" aria-label="Ringkasan hari ini">
    <h2 class="pb-dash-home-kpi__title h6">Ringkasan hari ini</h2>
    <p class="pb-dash-home-kpi__lead">Ketuk kartu untuk membuka detail</p>
    <div class="pb-dash-home-kpi__grid<?= $showSetoran ? '' : ' pb-dash-home-kpi__grid--no-setoran' ?>">
        <a href="<?= htmlspecialchars($presensiHref) ?>" class="pb-dash-home-kpi-card pb-dash-home-kpi-card--presensi pb-dash-home-kpi-card--link">
            <div class="pb-dash-home-kpi-card__label">Absen pembimbing</div>
            <div class="pb-dash-home-kpi-card__value">
                <?php if (!empty($kpi['presensi_scan_ok'])): ?>
                    <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Sudah scan</span>
                <?php else: ?>
                    <span class="text-warning"><i class="fa-solid fa-clock me-1"></i>Belum scan</span>
                <?php endif; ?>
            </div>
            <?php if ((int) ($kpi['presensi_hadir'] ?? 0) > 0): ?>
                <div class="pb-dash-home-kpi-card__hint"><?= (int) $kpi['presensi_hadir'] ?> hadir tercatat</div>
            <?php endif; ?>
        </a>
        <a href="<?= htmlspecialchars($kegiatanHref) ?>" class="pb-dash-home-kpi-card pb-dash-home-kpi-card--kegiatan pb-dash-home-kpi-card--link">
            <div class="pb-dash-home-kpi-card__label">Kegiatan</div>
            <div class="pb-dash-home-kpi-card__value"><?= (int) ($kpi['kegiatan_hari_ini'] ?? 0) ?> kegiatan</div>
            <?php if ((int) ($kpi['kegiatan_live'] ?? 0) > 0): ?>
                <div class="pb-dash-home-kpi-card__hint"><?= (int) $kpi['kegiatan_live'] ?> berlangsung</div>
            <?php endif; ?>
        </a>
        <?php if ($showSetoran): ?>
        <a href="<?= htmlspecialchars($setoranHref) ?>" class="pb-dash-home-kpi-card pb-dash-home-kpi-card--setoran pb-dash-home-kpi-card--link">
            <div class="pb-dash-home-kpi-card__label">Setoran</div>
            <div class="pb-dash-home-kpi-card__value"><?= (int) ($setoran['setor'] ?? 0) ?> santri</div>
            <div class="pb-dash-home-kpi-card__hint">
                Belum <?= (int) ($setoran['belum'] ?? 0) ?>
                <?php if ((int) ($setoran['izin'] ?? 0) > 0): ?> · Izin <?= (int) $setoran['izin'] ?><?php endif; ?>
            </div>
        </a>
        <?php endif; ?>
        <?php if (!$isMunawibPortal): ?>
        <a href="<?= htmlspecialchars($penilaianHref) ?>" class="pb-dash-home-kpi-card pb-dash-home-kpi-card--penilaian pb-dash-home-kpi-card--link">
            <div class="pb-dash-home-kpi-card__label">Penilaian</div>
            <div class="pb-dash-home-kpi-card__value">
                <?= $pending > 0 ? $pending . ' perlu dinilai' : 'Selesai' ?>
            </div>
        </a>
        <?php endif; ?>
    </div>
</section>
