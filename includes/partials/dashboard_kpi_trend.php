<?php

declare(strict_types=1);

/** @var array{show?:bool,direction?:string,label?:string}|null $dashKpiTrend */
$dashKpiTrend = is_array($dashKpiTrend ?? null) ? $dashKpiTrend : null;
if ($dashKpiTrend === null || empty($dashKpiTrend['show'])) {
    return;
}
$dir = (string) ($dashKpiTrend['direction'] ?? 'flat');
$mod = in_array($dir, ['up', 'down', 'flat'], true) ? $dir : 'flat';
$label = trim((string) ($dashKpiTrend['label'] ?? ''));
if ($label === '') {
    return;
}
$icon = match ($mod) {
    'up' => 'fa-arrow-trend-up',
    'down' => 'fa-arrow-trend-down',
    default => 'fa-minus',
};
?>
<div class="dash-kpi-box__trend dash-kpi-box__trend--<?= htmlspecialchars($mod) ?>">
    <i class="fa-solid <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
    <span><?= htmlspecialchars($label) ?></span>
</div>
