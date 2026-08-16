<?php

declare(strict_types=1);

/**
 * Link portal selain login password utama (wali, presensi, santri, dll.).
 * Fallback untuk layout lama — dipakai di halaman yang belum pakai topnav.
 */
require_once __DIR__ . '/../../helpers/auth_portal_links.php';

$portalLinks = auth_portal_alt_portal_links();
?>
<div class="auth-portal-alt-links mt-4 pt-3 border-top">
    <p class="small text-muted mb-2">Portal lain</p>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($portalLinks as $link): ?>
            <?php
            $href = function_exists('app_href') ? app_href((string) $link['href']) : (string) $link['href'];
            $icon = htmlspecialchars((string) ($link['icon'] ?? 'fa-circle'));
            $label = htmlspecialchars((string) ($link['label'] ?? ''));
            ?>
            <a href="<?= htmlspecialchars($href) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid <?= $icon ?> me-1" aria-hidden="true"></i> <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
