<?php

declare(strict_types=1);

/** @var string $setoranNavActive */

$setoranNavActive = $setoranNavActive ?? 'scan';
if (($setoranNavActive ?? '') === 'home') {
    return;
}
?>
<p class="mb-2">
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>" class="st-portal-back-home">
        <i class="fa-solid fa-house me-1" aria-hidden="true"></i> Beranda portal setoran
    </a>
</p>
<?php require __DIR__ . '/setoran_portal_menu_cards.php'; ?>
