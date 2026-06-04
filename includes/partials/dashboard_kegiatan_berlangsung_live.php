<?php

declare(strict_types=1);

/**
 * Kartu kegiatan berlangsung — dashboard utama (admin/pengurus).
 *
 * @var list<array<string,mixed>> $kegiatanAktifPresensi
 */
$kegiatanAktifPresensi = $kegiatanAktifPresensi ?? [];

if ($kegiatanAktifPresensi === []) {
    return;
}
$liveCount = count($kegiatanAktifPresensi);
$liveGridClass = $liveCount > 1 ? ' dash-live-kegiatan--multi' : '';
?>
<div class="dash-live-kegiatan<?= $liveGridClass ?>" aria-label="Kegiatan berlangsung hari ini">
    <?php foreach ($kegiatanAktifPresensi as $kg):
        $nama = (string) ($kg['nama_kegiatan'] ?? '—');
        $jam = trim((string) ($kg['jam_label'] ?? ''));
        $total = (int) ($kg['total'] ?? 0);
        $hadir = (int) ($kg['hadir'] ?? 0);
        $izin = (int) ($kg['izin'] ?? 0);
        $sakit = (int) ($kg['sakit'] ?? 0);
        $alpa = (int) ($kg['alpa'] ?? 0);
        $semuaHadir = !empty($kg['semua_hadir']);
        $ratio = (string) ($kg['ratio_label'] ?? ($total > 0 ? $hadir . '/' . $total : '—'));
        $pembimbingList = is_array($kg['pembimbing_list'] ?? null) ? $kg['pembimbing_list'] : [];
        $tingkatanList = is_array($kg['tingkatan_list'] ?? null) ? $kg['tingkatan_list'] : [];
        $pctHadir = $total > 0 ? min(100, (int) round($hadir / $total * 100)) : 0;
        ?>
    <article class="dash-live-keg<?= $semuaHadir ? ' dash-live-keg--lengkap' : '' ?>">
        <div class="dash-live-keg__accent" aria-hidden="true"></div>
        <div class="dash-live-keg__inner">
            <div class="dash-live-keg__head">
                <div class="dash-live-keg__title-wrap">
                    <h3 class="dash-live-keg__nama"><?= htmlspecialchars($nama) ?></h3>
                    <?php if ($jam !== ''): ?>
                        <span class="dash-live-keg__jam"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= htmlspecialchars($jam) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($total > 0): ?>
                    <div class="dash-live-keg__ratio-wrap" title="Santri hadir dari total di jadwal">
                        <span class="dash-live-keg__ratio"><?= htmlspecialchars($ratio) ?></span>
                        <span class="dash-live-keg__ratio-sub">hadir</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($tingkatanList !== []): ?>
                <div class="dash-live-keg__tingkatan">
                    <?php
                    $tkMax = $liveCount > 1 ? 2 : 4;
                    foreach (array_slice($tingkatanList, 0, $tkMax) as $tk):
                    ?>
                        <span class="dash-live-keg__tk"><?= htmlspecialchars((string) $tk) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($tingkatanList) > $tkMax): ?>
                        <span class="dash-live-keg__tk dash-live-keg__tk--more">+<?= count($tingkatanList) - $tkMax ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($pembimbingList !== []): ?>
                <ul class="dash-live-keg__pb-list">
                    <?php foreach ($pembimbingList as $pb):
                        $pbScan = !empty($pb['sudah_scan']);
                        ?>
                        <li class="dash-live-keg__pb">
                            <span class="dash-live-keg__dot dash-live-keg__dot--<?= $pbScan ? 'ok' : 'no' ?>" title="<?= $pbScan ? 'Sudah scan' : 'Belum scan' ?>" aria-hidden="true"></span>
                            <span class="dash-live-keg__pb-nama"><?= htmlspecialchars((string) ($pb['nama'] ?? 'Pembimbing')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($total > 0): ?>
                <div class="dash-live-keg__progress" role="progressbar" aria-valuenow="<?= $pctHadir ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Kehadiran santri">
                    <div class="dash-live-keg__progress-bar" style="width:<?= $pctHadir ?>%"></div>
                </div>
            <?php endif; ?>

            <?php if (!$semuaHadir && $total > 0 && ($izin > 0 || $sakit > 0 || $alpa > 0)): ?>
                <div class="dash-live-keg__stats" role="list">
                    <?php if ($izin > 0): ?>
                        <span class="dash-live-keg__stat dash-live-keg__stat--izin" role="listitem">Izin <?= (int) $izin ?></span>
                    <?php endif; ?>
                    <?php if ($sakit > 0): ?>
                        <span class="dash-live-keg__stat dash-live-keg__stat--sakit" role="listitem">Sakit <?= (int) $sakit ?></span>
                    <?php endif; ?>
                    <?php if ($alpa > 0): ?>
                        <span class="dash-live-keg__stat dash-live-keg__stat--alpa" role="listitem">Alpa <?= (int) $alpa ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
    <p class="dash-live-kegiatan__legend">
        <span class="dash-live-keg__dot dash-live-keg__dot--ok" aria-hidden="true"></span> pembimbing sudah scan
        <span class="dash-live-keg__dot dash-live-keg__dot--no ms-2" aria-hidden="true"></span> belum scan
    </p>
</div>
