<?php

declare(strict_types=1);

/**
 * Strip timer + marquee kegiatan berlangsung (scan petugas, Multi Scan login, musyawarah).
 *
 * @var array<string, mixed> $scanJadwalCtx
 * @var string $timerState
 * @var string $timerClass
 * @var string $timerClockInit
 */
$scanJadwalCtx = is_array($scanJadwalCtx ?? null) ? $scanJadwalCtx : ['state' => 'none'];
$timerState = $timerState ?? (string) ($scanJadwalCtx['state'] ?? 'none');
$timerClass = $timerClass ?? $timerState;
$timerClockInit = $timerClockInit ?? '00:00:00';
$scanTimerNoneLabel = $scanTimerNoneLabel ?? 'Belum ada jadwal';
$scanTimerActiveFallback = $scanTimerActiveFallback ?? 'Kegiatan aktif';
$scanTimerUpcomingFallback = $scanTimerUpcomingFallback ?? 'Menunggu jadwal';
$scanTimerEndedLabel = $scanTimerEndedLabel ?? 'Di luar jadwal';
$scanTimerAriaLabel = $scanTimerAriaLabel ?? 'Kegiatan berlangsung';
$scanTimerShowWall = $scanTimerShowWall ?? true;

require_once __DIR__ . '/../../helpers/presensi_scan_jadwal.php';
$activeSlotsTimer = presensi_scan_marquee_slots($scanJadwalCtx);
$activeSlotCount = count($activeSlotsTimer);
$showScanMarquee = $timerState === 'active' && $activeSlotCount > 0;
$scanMarqueeTrackHtml = $showScanMarquee ? presensi_scan_marquee_track_html($activeSlotsTimer) : '';
?>
    <div id="presensi-scan-timer" class="presensi-scan-timer is-<?= htmlspecialchars($timerClass) ?><?= $showScanMarquee ? ' has-marquee' : '' ?><?= !empty($scanTimerShowWall) ? ' has-wall' : '' ?>" aria-live="polite">
        <?php if ($scanTimerShowWall): ?>
        <span id="presensi-scan-timer-wall" class="presensi-scan-timer-wall" aria-label="Waktu sekarang">
            <span class="presensi-scan-timer-wall__label">Waktu sekarang :</span>
            <span id="presensi-scan-timer-wall-value" class="presensi-scan-timer-wall__value">--:--:--</span>
        </span>
        <?php endif; ?>
        <div class="presensi-scan-timer-inner" role="button" tabindex="0" aria-expanded="false" title="Ketuk untuk lihat jadwal">
            <div id="presensi-scan-timer-marquee" class="presensi-scan-timer-marquee<?= $showScanMarquee ? ' is-always-scroll is-ready' : ' d-none' ?>" aria-label="<?= htmlspecialchars($scanTimerAriaLabel) ?>">
                <div class="presensi-scan-timer-marquee__viewport">
                    <div id="presensi-scan-timer-marquee-track" class="presensi-scan-timer-marquee__track"><?= $scanMarqueeTrackHtml ?></div>
                </div>
            </div>
            <span id="presensi-scan-timer-title" class="presensi-scan-timer-title"><?php
                if ($timerState === 'active') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: $scanTimerActiveFallback));
                } elseif ($timerState === 'upcoming') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: $scanTimerUpcomingFallback));
                } elseif ($timerState === 'libur') {
                    echo 'Hari libur';
                } elseif ($timerState === 'ended') {
                    echo htmlspecialchars($scanTimerEndedLabel);
                } else {
                    echo htmlspecialchars($scanTimerNoneLabel);
                }
            ?></span>
            <span id="presensi-scan-timer-range" class="presensi-scan-timer-range"><?php
                if (!empty($scanJadwalCtx['jam_mulai']) && !empty($scanJadwalCtx['jam_selesai'])) {
                    echo htmlspecialchars(substr((string) $scanJadwalCtx['jam_mulai'], 0, 5) . ' – ' . substr((string) $scanJadwalCtx['jam_selesai'], 0, 5));
                    if (!empty($scanJadwalCtx['tingkatan'])) {
                        echo ' · ' . htmlspecialchars((string) $scanJadwalCtx['tingkatan']);
                    }
                }
            ?></span>
            <div class="presensi-scan-timer-remain">
            <span id="presensi-scan-timer-hint" class="presensi-scan-timer-hint" aria-live="polite"><?php
                if ($timerState === 'active') {
                    echo 'Sisa waktu scan';
                } elseif ($timerState === 'upcoming') {
                    echo 'Mulai scan dalam';
                } elseif ($timerState === 'libur') {
                    echo 'Hari libur — scan ditolak';
                } elseif ($timerState === 'ended') {
                    echo 'Di luar jadwal — scan ditolak';
                } else {
                    echo 'Belum ada jadwal aktif';
                }
            ?></span>
            <span id="presensi-scan-timer-clock" class="presensi-scan-timer-clock"><?= htmlspecialchars($timerClockInit) ?></span>
            </div>
        </div>
    </div>
    <script type="application/json" id="presensi-scan-timer-data"><?= json_encode($scanJadwalCtx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
