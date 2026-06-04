<?php

declare(strict_types=1);

/**
 * Kartu kegiatan berlangsung + ringkasan presensi (dashboard pembimbing).
 *
 * @var list<array<string,mixed>> $kegiatanAktifPresensi
 * @var bool $inBanner Tampil di dalam banner hijau (home)
 */
$kegiatanAktifPresensi = $kegiatanAktifPresensi ?? [];
$inBanner = !empty($inBanner);

if ($kegiatanAktifPresensi === []) {
    return;
}
?>
<div class="pb-dash-live-kegiatan<?= $inBanner ? ' pb-dash-live-kegiatan--banner' : '' ?>" aria-label="Kegiatan berlangsung hari ini">
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
        ?>
    <article class="pb-dash-live-keg<?= $semuaHadir ? ' pb-dash-live-keg--lengkap' : '' ?>">
        <div class="pb-dash-live-keg__head">
            <div class="pb-dash-live-keg__title-wrap">
                <h3 class="pb-dash-live-keg__nama"><?= htmlspecialchars($nama) ?></h3>
                <?php if ($jam !== '' && !$semuaHadir): ?>
                    <span class="pb-dash-live-keg__jam"><?= htmlspecialchars($jam) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($total > 0): ?>
                <span class="pb-dash-live-keg__ratio" title="<?= $semuaHadir ? 'Semua santri hadir' : 'Hadir dari santri di jadwal' ?>"><?= htmlspecialchars($ratio) ?></span>
            <?php endif; ?>
        </div>
        <?php if (!$semuaHadir && $total > 0 && ($hadir > 0 || $izin > 0 || $sakit > 0 || $alpa > 0)): ?>
        <div class="pb-dash-live-keg__stats" role="list">
            <?php if ($hadir > 0): ?>
                <span class="pb-dash-live-keg__stat pb-dash-live-keg__stat--hadir" role="listitem">Hadir <?= (int) $hadir ?></span>
            <?php endif; ?>
            <?php if ($izin > 0): ?>
                <span class="pb-dash-live-keg__stat pb-dash-live-keg__stat--izin" role="listitem">Izin <?= (int) $izin ?></span>
            <?php endif; ?>
            <?php if ($sakit > 0): ?>
                <span class="pb-dash-live-keg__stat pb-dash-live-keg__stat--sakit" role="listitem">Sakit <?= (int) $sakit ?></span>
            <?php endif; ?>
            <?php if ($alpa > 0): ?>
                <span class="pb-dash-live-keg__stat pb-dash-live-keg__stat--alpa" role="listitem">Alpa <?= (int) $alpa ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
