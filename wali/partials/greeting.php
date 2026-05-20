<?php

declare(strict_types=1);

/** @var array{salam:string,nama_wali:string,nama_anak:string,line:string,subline:string} $waliPortalGreeting */
if (!isset($waliPortalGreeting) || !is_array($waliPortalGreeting)) {
    return;
}
?>
<div class="wali-greeting mb-3">
    <div class="wali-kicker mb-1">Assalamu&rsquo;alaikum</div>
    <p class="wali-greeting-line mb-1"><?= htmlspecialchars((string) $waliPortalGreeting['line']) ?></p>
    <?php if (trim((string) ($waliPortalGreeting['subline'] ?? '')) !== ''): ?>
        <p class="small text-muted mb-0"><?= htmlspecialchars((string) $waliPortalGreeting['subline']) ?></p>
    <?php endif; ?>
</div>
