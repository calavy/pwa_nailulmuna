<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $keuRingkas */
$keuRingkas = is_array($keuRingkas ?? null) ? $keuRingkas : null;
if ($keuRingkas === null || ($keuRingkas['status_label'] ?? '') === 'tidak_ditemukan') {
    return;
}
$variant = (string) ($keuRingkas['badge_variant'] ?? 'secondary');
$class = match ($variant) {
    'success' => 'text-bg-success',
    'warning' => 'text-bg-warning text-dark',
    'info' => 'text-bg-info text-dark',
    default => 'text-bg-light border text-secondary',
};
$label = trim((string) ($keuRingkas['badge_label'] ?? ''));
if ($label === '') {
    return;
}
?>
<span class="badge <?= htmlspecialchars($class) ?>"><?= htmlspecialchars($label) ?></span>
