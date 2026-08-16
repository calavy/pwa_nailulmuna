<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/auth_portal_links.php';

$portalLinks = auth_portal_alt_portal_links();
?>
<nav class="auth-portal-topnav" aria-label="Portal lain">
    <span class="auth-portal-topnav__label d-none d-md-inline">Portal lain</span>
    <div class="auth-portal-topnav__links">
        <?php foreach ($portalLinks as $link): ?>
            <?php
            $href = function_exists('app_href') ? app_href((string) $link['href']) : (string) $link['href'];
            $icon = htmlspecialchars((string) ($link['icon'] ?? 'fa-circle'));
            $label = htmlspecialchars((string) ($link['label'] ?? ''));
            $shortLabel = htmlspecialchars((string) ($link['short_label'] ?? $label));
            ?>
            <a href="<?= htmlspecialchars($href) ?>" class="auth-portal-topnav__link" title="<?= $label ?>">
                <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i>
                <span class="auth-portal-topnav__text auth-portal-topnav__text--full d-none d-lg-inline"><?= $label ?></span>
                <span class="auth-portal-topnav__text auth-portal-topnav__text--short d-inline d-lg-none"><?= $shortLabel ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
