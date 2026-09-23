<?php

declare(strict_types=1);

/** @var array<string,mixed> $pbKehadiranPeriode */
/** @var list<array<string,mixed>> $pbKehadiranPerKegiatan */
/** @var string $baseDashQuery */

$pbKehadiranPeriode = is_array($pbKehadiranPeriode ?? null) ? $pbKehadiranPeriode : [];
$pbKehadiranPerKegiatan = is_array($pbKehadiranPerKegiatan ?? null) ? $pbKehadiranPerKegiatan : [];
$baseDashQuery = trim((string) ($baseDashQuery ?? ''));
$periodeLabel = (string) ($pbKehadiranPeriode['label'] ?? '');
$rentangTampilan = (string) ($pbKehadiranPeriode['rentang_tampilan'] ?? '');
$mode = (string) ($pbKehadiranPeriode['mode'] ?? 'hijriyah');
$month = (int) ($pbKehadiranPeriode['month'] ?? (int) date('n'));
$year = (int) ($pbKehadiranPeriode['year'] ?? (int) date('Y'));
$extraHidden = ['view' => 'kehadiran_saya'];
if ($baseDashQuery !== '') {
    parse_str($baseDashQuery, $dashParams);
    if (is_array($dashParams)) {
        foreach ($dashParams as $k => $v) {
            if (is_string($k) && $k !== '' && $k !== 'view') {
                $extraHidden[$k] = $v;
            }
        }
    }
}
$formAction = app_href('/pembimbing/dashboard.php');
$wrapCard = false;
$cardClass = '';
$periode = $pbKehadiranPeriode;
$periodeNote = $rentangTampilan !== '' ? $rentangTampilan : '';
?>
<section class="pb-dash-subpage pb-dash-subpage--kehadiran" aria-label="Kehadiran saya">
    <div class="pb-dash-subpage__card pb-dash-subpage__card--filter">
        <?php require __DIR__ . '/../../includes/partials/rekap_kalender_bulan_filter.php'; ?>
    </div>
    <div class="pb-dash-subpage__card">
        <h2 class="pb-dash-subpage__title h6 mb-1">Rekap per mata pelajaran</h2>
        <?php if ($periodeLabel !== ''): ?>
            <p class="pb-dash-subpage__meta small text-muted mb-3"><?= htmlspecialchars($periodeLabel) ?></p>
        <?php endif; ?>
        <?php if ($pbKehadiranPerKegiatan === []): ?>
            <p class="small text-muted mb-0">Belum ada jadwal kegiatan pada periode ini.</p>
        <?php else: ?>
            <ul class="pb-dash-kehadiran-list list-unstyled mb-0">
                <?php foreach ($pbKehadiranPerKegiatan as $kgRow): ?>
                    <li class="pb-dash-kehadiran-list__item">
                        <div class="pb-dash-kehadiran-list__name"><?= htmlspecialchars((string) ($kgRow['nama_kegiatan'] ?? '-')) ?></div>
                        <div class="pb-dash-kehadiran-list__stats">
                            <span class="pb-dash-kehadiran-list__stat pb-dash-kehadiran-list__stat--hadir">
                                Hadir <strong><?= (int) ($kgRow['hadir'] ?? 0) ?></strong>
                            </span>
                            <span class="pb-dash-kehadiran-list__stat">
                                Izin <strong><?= (int) ($kgRow['izin'] ?? 0) ?></strong>
                            </span>
                            <span class="pb-dash-kehadiran-list__stat pb-dash-kehadiran-list__stat--miss">
                                Tanpa scan <strong><?= (int) ($kgRow['tanpa_scan'] ?? 0) ?></strong>
                            </span>
                            <span class="pb-dash-kehadiran-list__pct">
                                <?= htmlspecialchars((string) ($kgRow['persen_hadir'] ?? 0)) ?>%
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
