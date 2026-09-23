<?php

declare(strict_types=1);

/** @var array<string,mixed> $pbHomeBundle */
/** @var bool $isMunawibPortal */

require_once __DIR__ . '/../../helpers/pembimbing_dashboard_home_ux.php';

$pbHomeBundle = is_array($pbHomeBundle ?? null) ? $pbHomeBundle : [];
$isMunawibPortal = !empty($isMunawibPortal);
$cta = pembimbing_dashboard_home_primary_cta($pbHomeBundle, $isMunawibPortal);
$tone = (string) ($cta['tone'] ?? 'neutral');

?>
<a href="<?= htmlspecialchars((string) $cta['href']) ?>"
   class="pb-dash-primary-cta pb-dash-primary-cta--<?= htmlspecialchars($tone) ?>"
   >
    <span class="pb-dash-primary-cta__icon" aria-hidden="true"><i class="<?= htmlspecialchars((string) $cta['icon']) ?>"></i></span>
    <span class="pb-dash-primary-cta__text">
        <strong class="pb-dash-primary-cta__label"><?= htmlspecialchars((string) $cta['label']) ?></strong>
        <span class="pb-dash-primary-cta__sub"><?= htmlspecialchars((string) $cta['sublabel']) ?></span>
    </span>
    <span class="pb-dash-primary-cta__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
</a>
