<?php

declare(strict_types=1);

/**
 * Panel idle saat tidak ada kegiatan berlangsung.
 *
 * @var string $idleContext admin|pembimbing|pengasuh
 * @var string $jamLabel
 * @var string $today
 * @var array{agenda:list,presensi:array,jadwal_berikutnya:list} $idleData
 * @var bool $canJadwalLink
 */
$idleContext = (string) ($idleContext ?? 'admin');
$jamLabel = trim((string) ($jamLabel ?? substr(date('H:i:s'), 0, 5)));
$today = trim((string) ($today ?? date('Y-m-d')));
$idleData = is_array($idleData ?? null) ? $idleData : ['agenda' => [], 'presensi' => [], 'jadwal_berikutnya' => []];
$canJadwalLink = !empty($canJadwalLink);

$agenda = is_array($idleData['agenda'] ?? null) ? $idleData['agenda'] : [];
$presensi = is_array($idleData['presensi'] ?? null) ? $idleData['presensi'] : [];
$nextSlots = is_array($idleData['jadwal_berikutnya'] ?? null) ? $idleData['jadwal_berikutnya'] : [];

$emptyTitle = match ($idleContext) {
    'pembimbing' => 'Tidak ada kegiatan di jam ini.',
    'pengasuh' => 'Tidak ada kegiatan berlangsung pukul ' . $jamLabel . '.',
    default => 'Tidak ada kegiatan pukul ' . $jamLabel . '.',
};

$hadir = (int) ($presensi['hadir'] ?? 0);
$alpa = (int) ($presensi['alpa'] ?? 0);
$izin = (int) ($presensi['izin'] ?? 0);
$sakit = (int) ($presensi['sakit'] ?? 0);
$presTotal = max(1, $hadir + $alpa + $izin + $sakit);
$pct = static fn (int $n): float => round(100 * $n / $presTotal, 1);
?>
<div class="dash-idle-panel">
    <div class="dash-empty-chart dash-empty-chart--compact py-4 text-center text-muted">
        <div class="dash-empty-chart__inner">
            <div class="dash-empty-chart__icon display-6 opacity-50" aria-hidden="true"><i class="fa-regular fa-calendar"></i></div>
            <p class="mb-0 fw-semibold"><?= htmlspecialchars($emptyTitle) ?></p>
            <?php if ($canJadwalLink && $idleContext === 'admin'): ?>
                <p class="small mb-0 mt-1">
                    <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="alert-link">Jadwal lengkap</a>
                </p>
            <?php elseif ($idleContext === 'pengasuh'): ?>
                <p class="small mb-0 mt-1">
                    <a href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php')) ?>" class="alert-link">Laporan hari</a>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($agenda !== [] || $hadir + $alpa + $izin + $sakit > 0 || $nextSlots !== []): ?>
    <div class="dash-idle-panel__body">
        <?php if ($agenda !== []): ?>
        <section class="dash-idle-block">
            <h3 class="dash-idle-block__title"><i class="fa-solid fa-bullhorn me-1"></i> Agenda hari ini</h3>
            <ul class="dash-idle-list list-unstyled mb-0">
                <?php foreach ($agenda as $ag):
                    $jm = substr((string) ($ag['jam_mulai'] ?? ''), 0, 5);
                    $prio = (string) ($ag['_prio'] ?? $ag['prioritas'] ?? 'sedang');
                ?>
                <li class="dash-idle-list__item">
                    <span class="dash-idle-list__time"><?= $jm !== '' ? htmlspecialchars($jm) : '—' ?></span>
                    <span class="dash-idle-list__label"><?= htmlspecialchars((string) ($ag['judul'] ?? 'Agenda')) ?></span>
                    <?php if ($prio === 'tinggi'): ?>
                        <span class="badge text-bg-warning text-dark">Penting</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($hadir + $alpa + $izin + $sakit > 0): ?>
        <section class="dash-idle-block">
            <h3 class="dash-idle-block__title"><i class="fa-solid fa-chart-simple me-1"></i> Kehadiran hari ini</h3>
            <div class="dash-idle-chart" role="img" aria-label="Ringkasan presensi hari ini">
                <?php foreach (['hadir' => $hadir, 'izin' => $izin, 'sakit' => $sakit, 'alpa' => $alpa] as $key => $n):
                    if ($n <= 0) {
                        continue;
                    }
                ?>
                <div class="dash-idle-chart__row">
                    <span class="dash-idle-chart__label"><?= ucfirst($key) ?></span>
                    <div class="dash-idle-chart__bar-wrap">
                        <div class="dash-idle-chart__bar dash-idle-chart__bar--<?= htmlspecialchars($key) ?>" style="width:<?= $pct($n) ?>%"></div>
                    </div>
                    <span class="dash-idle-chart__n"><?= (int) $n ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ((int) ($presensi['persen_partisipasi'] ?? 0) > 0): ?>
                <p class="dash-idle-block__meta small mb-0"><?= number_format((float) $presensi['persen_partisipasi'], 1, ',', '.') ?>% partisipasi</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($nextSlots !== []): ?>
        <section class="dash-idle-block">
            <h3 class="dash-idle-block__title"><i class="fa-regular fa-clock me-1"></i> Jadwal berikutnya</h3>
            <ul class="dash-idle-list list-unstyled mb-0">
                <?php foreach ($nextSlots as $slot): ?>
                <li class="dash-idle-list__item">
                    <span class="dash-idle-list__time"><?= htmlspecialchars(substr((string) ($slot['jam_mulai'] ?? ''), 0, 5)) ?></span>
                    <span class="dash-idle-list__label"><?= htmlspecialchars((string) ($slot['nama_kegiatan'] ?? 'Kegiatan')) ?></span>
                    <span class="dash-idle-list__meta"><?= htmlspecialchars((string) ($slot['tingkatan'] ?? '')) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
