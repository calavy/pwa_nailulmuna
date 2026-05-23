<?php

declare(strict_types=1);

/**
 * Hero kalender — selaras portal login (gradien tema aplikasi).
 *
 * @param array{
 *   kicker?: string,
 *   title: string,
 *   description?: string,
 *   brand?: string,
 *   today_masehi?: string,
 *   today_hijri?: string,
 *   status_label?: string,
 *   status_active?: bool
 * } $hero
 */
function render_kalender_page_hero(array $hero): void
{
    $kicker = trim((string) ($hero['kicker'] ?? ''));
    $title = trim((string) ($hero['title'] ?? 'Kalender'));
    $description = trim((string) ($hero['description'] ?? ''));
    $brand = trim((string) ($hero['brand'] ?? ''));
    $todayMasehi = trim((string) ($hero['today_masehi'] ?? ''));
    $todayHijri = trim((string) ($hero['today_hijri'] ?? ''));
    $statusLabel = trim((string) ($hero['status_label'] ?? ''));
    $statusActive = !empty($hero['status_active']);
    ?>
<div class="akad-cal-hero akad-cal-hero--portal mb-4">
    <?php if ($kicker !== ''): ?>
        <p class="akad-cal-hero-kicker mb-1"><?= htmlspecialchars($kicker) ?></p>
    <?php endif; ?>
    <?php if ($brand !== ''): ?>
        <p class="akad-cal-hero-brand mb-1"><?= htmlspecialchars($brand) ?></p>
    <?php endif; ?>
    <h1 class="h3 akad-cal-hero-title mb-2"><?= htmlspecialchars($title) ?></h1>
    <?php if ($description !== ''): ?>
        <p class="akad-cal-hero-desc mb-0"><?= htmlspecialchars($description) ?></p>
    <?php endif; ?>
    <?php if ($todayMasehi !== '' || $todayHijri !== '' || $statusLabel !== ''): ?>
        <div class="akad-cal-hero-today-row mt-3">
            <?php if ($todayMasehi !== ''): ?>
                <span class="akad-cal-hero-today-chip">
                    <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
                    <?= htmlspecialchars($todayMasehi) ?>
                </span>
            <?php endif; ?>
            <?php if ($todayHijri !== ''): ?>
                <span class="akad-cal-hero-today-chip">
                    <i class="fa-solid fa-moon" aria-hidden="true"></i>
                    <?= htmlspecialchars($todayHijri) ?>
                </span>
            <?php endif; ?>
            <?php if ($statusLabel !== ''): ?>
                <span class="badge akad-cal-hero-status <?= $statusActive ? 'akad-cal-hero-status--aktif' : 'akad-cal-hero-status--libur' ?>">
                    <?= htmlspecialchars($statusLabel) ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
    <?php
}
