<?php

declare(strict_types=1);

/** @var array<string,mixed> $pbHomeBundle */
/** @var bool $isMunawibPortal */

require_once __DIR__ . '/../../helpers/app_path.php';

$pbHomeBundle = is_array($pbHomeBundle ?? null) ? $pbHomeBundle : [];
$slots = (array) ($pbHomeBundle['slots_hari_ini'] ?? []);
$livePresensi = (array) ($pbHomeBundle['kegiatan_aktif_presensi'] ?? []);
$isMunawibPortal = !empty($isMunawibPortal);

if ($isMunawibPortal) {
    return;
}
?>
<section class="pb-dash-home-kegiatan" id="pb-dash-home-kegiatan" aria-label="Kegiatan hari ini">
    <?php if ($livePresensi !== []): ?>
        <div class="pb-dash-home-kegiatan__live">
            <h2 class="pb-dash-home-kegiatan__heading h6 mb-2">
                <i class="fa-solid fa-circle-play text-success me-1"></i> Kegiatan sedang berlangsung
            </h2>
            <?php
            $kegiatanAktifPresensi = $livePresensi;
            $inBanner = false;
            require __DIR__ . '/dashboard_kegiatan_berlangsung_cards.php';
            ?>
            <p class="mb-0 mt-2">
                <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>" class="btn btn-success btn-sm w-100">
                    <i class="fa-solid fa-qrcode me-1"></i> Scan presensi
                </a>
            </p>
        </div>
    <?php endif; ?>

    <?php
    $slotsList = $slots;
    if ($livePresensi !== []) {
        $slotsList = array_values(array_filter(
            $slots,
            static fn (array $slot): bool => empty($slot['is_live'])
        ));
    }
    ?>
    <?php if ($slotsList !== []): ?>
        <h2 class="pb-dash-home-kegiatan__heading h6 mt-3 mb-2">Kegiatan hari ini</h2>
        <ul class="pb-dash-home-kegiatan__list list-unstyled mb-0">
            <?php foreach ($slotsList as $slot):
                $nama = (string) ($slot['nama_kegiatan'] ?? '—');
                $tingkatan = trim((string) ($slot['tingkatan'] ?? ''));
                $jamMulai = substr((string) ($slot['jam_mulai'] ?? ''), 0, 5);
                $jamSelesai = substr((string) ($slot['jam_selesai'] ?? ''), 0, 5);
                $isLive = !empty($slot['is_live']);
                $bukaUrl = (string) ($slot['buka_kegiatan_url'] ?? app_href('/pembimbing/perizinan.php'));
                $scanUrl = (string) ($slot['scan_presensi_url'] ?? app_href('/presensi/scan.php'));
                ?>
            <li class="pb-dash-home-kegiatan__item<?= $isLive ? ' is-live' : '' ?>">
                <div class="pb-dash-home-kegiatan__item-head">
                    <span class="pb-dash-home-kegiatan__jam"><?= htmlspecialchars($jamMulai) ?> – <?= htmlspecialchars($jamSelesai) ?></span>
                    <?php if ($isLive): ?>
                        <span class="badge text-bg-success">Berlangsung</span>
                    <?php endif; ?>
                </div>
                <div class="pb-dash-home-kegiatan__nama fw-semibold"><?= htmlspecialchars($nama) ?></div>
                <?php if ($tingkatan !== ''): ?>
                    <div class="pb-dash-home-kegiatan__tingkatan small text-muted"><?= htmlspecialchars($tingkatan) ?></div>
                <?php endif; ?>
                <div class="pb-dash-home-kegiatan__actions d-flex flex-wrap gap-2 mt-2">
                    <?php if ($isLive): ?>
                        <a href="<?= htmlspecialchars($scanUrl) ?>" class="btn btn-sm btn-success">Scan presensi</a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($bukaUrl) ?>" class="btn btn-sm btn-outline-primary">Buka kegiatan</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($livePresensi === []): ?>
        <p class="small text-muted mb-0">Tidak ada jadwal kegiatan untuk hari ini.</p>
    <?php endif; ?>
</section>
