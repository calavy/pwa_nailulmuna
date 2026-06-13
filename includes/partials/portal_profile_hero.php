<?php

declare(strict_types=1);

/**
 * Kartu profil santri untuk portal wali / santri.
 *
 * @var array<string, mixed> $portalProfileRow baris santri (nama, nis, foto_profil, jenis_kelamin, tingkatan, …)
 * @var PDO $pdo
 * @var string $portalProfileContext 'wali'|'santri'
 * @var bool $portalProfileShowLogout
 * @var string|null $portalProfileExtraHtml HTML opsional di bawah badge
 */

if (!function_exists('santri_foto_render_avatar')) {
    require_once __DIR__ . '/../../helpers/santri_foto.php';
}

$portalProfileRow = $portalProfileRow ?? [];
$portalProfileContext = ($portalProfileContext ?? 'wali') === 'santri' ? 'santri' : 'wali';
$portalProfileShowLogout = !empty($portalProfileShowLogout);
$meta = portal_profile_meta_from_santri($pdo, $portalProfileRow);
$logoutHref = $portalProfileContext === 'santri'
    ? app_href('/santri_portal/logout.php')
    : app_href('/wali/logout.php');
?>
<div class="portal-profile-hero wali-card mb-3">
    <div class="portal-profile-hero__glow" aria-hidden="true"></div>
    <div class="portal-profile-hero__inner">
        <div class="portal-profile-hero__avatar-wrap">
            <?= santri_foto_render_avatar($portalProfileRow, 'app-user-avatar--xl portal-avatar--hero') ?>
        </div>
        <div class="portal-profile-hero__body flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0">
                    <h2 class="portal-profile-hero__name mb-0"><?= htmlspecialchars($meta['nama'] !== '' ? $meta['nama'] : 'Santri') ?></h2>
                    <?php if ($meta['subtitle'] !== ''): ?>
                        <div class="portal-profile-hero__sub font-monospace"><?= htmlspecialchars($meta['subtitle']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($portalProfileShowLogout): ?>
                    <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= htmlspecialchars($logoutHref) ?>">Keluar</a>
                <?php endif; ?>
            </div>
            <?php if ($meta['badges'] !== []): ?>
                <div class="portal-profile-hero__badges mt-2">
                    <?php foreach ($meta['badges'] as $badge): ?>
                        <span class="badge rounded-pill text-bg-light border"><?= htmlspecialchars($badge) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($portalProfileExtraHtml)): ?>
                <div class="portal-profile-hero__extra small text-muted mt-2"><?= $portalProfileExtraHtml ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
