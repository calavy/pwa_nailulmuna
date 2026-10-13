<?php

declare(strict_types=1);

/**
 * Kartu KPI modul keuangan (UI only).
 *
 * @var array{
 *   label: string,
 *   value: string,
 *   hint?: string,
 *   variant?: string,
 *   icon?: string,
 *   extra_html?: string,
 *   value_class?: string
 * } $keuUiKpi
 */
$keuUiKpi = is_array($keuUiKpi ?? null) ? $keuUiKpi : [];
$label = (string) ($keuUiKpi['label'] ?? '');
$value = (string) ($keuUiKpi['value'] ?? '');
if ($label === '') {
    return;
}
$hint = (string) ($keuUiKpi['hint'] ?? '');
$variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($keuUiKpi['variant'] ?? 'neutral'))) ?: 'neutral';
$icon = trim((string) ($keuUiKpi['icon'] ?? ''));
$extraHtml = (string) ($keuUiKpi['extra_html'] ?? '');
$valueClass = trim((string) ($keuUiKpi['value_class'] ?? ''));
?>
<div class="card keu-kpi-card keu-kpi-card--<?= htmlspecialchars($variant) ?> h-100 border-0 shadow-sm">
    <div class="card-body keu-kpi-card__inner">
        <?php if ($icon !== ''): ?>
            <span class="keu-kpi-card__ico" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($icon) ?>"></i></span>
        <?php endif; ?>
        <div class="keu-kpi-card__body">
            <div class="keu-kpi-card__label"><?= htmlspecialchars($label) ?></div>
            <div class="keu-kpi-card__value<?= $valueClass !== '' ? ' ' . htmlspecialchars($valueClass) : '' ?>"><?= $value ?></div>
            <?php if ($hint !== ''): ?>
                <div class="keu-kpi-card__hint small text-muted"><?= htmlspecialchars($hint) ?></div>
            <?php endif; ?>
            <?= $extraHtml ?>
        </div>
    </div>
</div>
