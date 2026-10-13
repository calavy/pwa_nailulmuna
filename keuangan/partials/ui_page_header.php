<?php

declare(strict_types=1);

/**
 * Header halaman modul keuangan (UI only).
 *
 * @var array{kicker?: string, title: string, subtitle?: string, actions?: string, class?: string} $keuUiHeader
 */
$keuUiHeader = is_array($keuUiHeader ?? null) ? $keuUiHeader : [];
$kicker = (string) ($keuUiHeader['kicker'] ?? '');
$title = (string) ($keuUiHeader['title'] ?? '');
$subtitle = (string) ($keuUiHeader['subtitle'] ?? '');
$actions = (string) ($keuUiHeader['actions'] ?? '');
$extraClass = trim((string) ($keuUiHeader['class'] ?? ''));

if ($title === '') {
    return;
}
?>
<div class="keu-page-header page-intro mb-3<?= $extraClass !== '' ? ' ' . htmlspecialchars($extraClass) : '' ?>">
    <div class="keu-page-header__main">
        <?php if ($kicker !== ''): ?>
            <p class="page-intro-kicker keu-page-header__kicker mb-1"><?= $kicker // allow breadcrumb links ?></p>
        <?php endif; ?>
        <h1 class="h4 keu-page-header__title mb-1"><?= htmlspecialchars($title) ?></h1>
        <?php if ($subtitle !== ''): ?>
            <p class="text-muted keu-page-header__subtitle mb-0"><?= $subtitle // may contain inline links ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?>
        <div class="keu-page-header__actions"><?= $actions ?></div>
    <?php endif; ?>
</div>
