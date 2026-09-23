<?php

declare(strict_types=1);

/** @var string $baseDashQuery */
/** @var bool $isMunawibPortal */
/** @var bool $showSetoranTile */
/** @var array<string,mixed> $setoranEntry */

$isMunawibPortal = !empty($isMunawibPortal);
$baseDashQuery = trim((string) ($baseDashQuery ?? ''));
$queryPrefix = $baseDashQuery !== '' ? $baseDashQuery . '&' : '';
$kajianHref = app_href('/pembimbing/dashboard.php?' . $queryPrefix . 'view=kajian');
$santriHref = app_href('/pembimbing/dashboard.php?' . $queryPrefix . 'view=santri');
$penilaianHref = app_href('/pembimbing/dashboard.php?' . $queryPrefix . 'view=penilaian');
$kehadiranHref = app_href('/pembimbing/dashboard.php?' . $queryPrefix . 'view=kehadiran_saya');
$perizinanHref = app_href('/pembimbing/perizinan.php');
$showPerizinan = !$isMunawibPortal;
$setoranEntry = is_array($setoranEntry ?? null) ? $setoranEntry : [];
$showSetoranTile = isset($showSetoranTile) ? (bool) $showSetoranTile : (!$isMunawibPortal && !empty($setoranEntry['portal_ok']));
$setoranPortalOk = !empty($setoranEntry['portal_ok']);
?>
<section class="pb-dash-simple-main" aria-label="Menu utama">
    <div class="pb-dash-simple-main__grid" role="group" aria-label="Pilihan">
        <a href="<?= htmlspecialchars($kajianHref) ?>" class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--kajian">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Kajian saya</span>
                <span class="pb-dash-simple-tile__hint">Jadwal ta&apos;lim</span>
            </span>
        </a>

        <a href="<?= htmlspecialchars($santriHref) ?>" class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--santri">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid fa-user-graduate"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Daftar santri</span>
                <span class="pb-dash-simple-tile__hint">Nama santri bimbingan</span>
            </span>
        </a>

        <?php if ($showPerizinan): ?>
        <a href="<?= htmlspecialchars($perizinanHref) ?>" class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--izin">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid fa-user-clock"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Perizinan</span>
                <span class="pb-dash-simple-tile__hint">Cari munawib &amp; pengganti</span>
            </span>
        </a>
        <?php endif; ?>

        <?php if (!$isMunawibPortal): ?>
        <a href="<?= htmlspecialchars($penilaianHref) ?>" class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--penilaian">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid fa-pen-to-square"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Penilaian</span>
                <span class="pb-dash-simple-tile__hint">Nilai santri &amp; tugas</span>
            </span>
        </a>
        <?php endif; ?>

        <?php if ($showSetoranTile): ?>
        <a href="<?= htmlspecialchars((string) $setoranEntry['href']) ?>"
           class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--setoran<?= !$setoranPortalOk ? ' pb-dash-simple-tile--muted' : '' ?>">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars((string) ($setoranEntry['icon'] ?? 'fa-book-quran')) ?>"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Setoran</span>
                <span class="pb-dash-simple-tile__hint"><?= htmlspecialchars((string) ($setoranEntry['desc'] ?? 'Portal setoran hafalan')) ?></span>
            </span>
        </a>
        <?php endif; ?>

        <?php if (!$isMunawibPortal): ?>
        <a href="<?= htmlspecialchars($kehadiranHref) ?>" class="pb-dash-simple-tile pb-dash-simple-tile--link pb-dash-simple-tile--kehadiran">
            <span class="pb-dash-simple-tile__icon" aria-hidden="true"><i class="fa-solid fa-clipboard-check"></i></span>
            <span class="pb-dash-simple-tile__text">
                <span class="pb-dash-simple-tile__label">Kehadiran saya</span>
                <span class="pb-dash-simple-tile__hint">Rekap scan per bulan</span>
            </span>
        </a>
        <?php endif; ?>
    </div>
</section>
