<?php

declare(strict_types=1);

/** @var array{salam:string,nama_wali:string,nama_anak:string,line:string,subline:string} $waliPortalGreeting */
if (!isset($waliPortalGreeting) || !is_array($waliPortalGreeting)) {
    return;
}
$line = trim((string) ($waliPortalGreeting['line'] ?? ''));
$subline = trim((string) ($waliPortalGreeting['subline'] ?? ''));
if ($line === '' && $subline === '') {
    return;
}
?>
<div class="wali-greeting mb-3">
    <?php if ($line !== ''): ?>
        <p class="wali-greeting-line mb-1 fw-semibold"><?= htmlspecialchars($line) ?></p>
    <?php endif; ?>
    <?php if ($subline !== ''): ?>
        <p class="small text-muted mb-0"><?= htmlspecialchars($subline) ?></p>
    <?php endif; ?>
</div>
