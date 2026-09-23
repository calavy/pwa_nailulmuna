<?php

declare(strict_types=1);

/** @var array<string,mixed> $pbHomeBundle */

$pbHomeBundle = is_array($pbHomeBundle ?? null) ? $pbHomeBundle : [];
$actionItems = (array) ($pbHomeBundle['action_items'] ?? []);

if ($actionItems === []) {
    return;
}

?>
<section class="pb-dash-home-actions" aria-label="Perlu tindakan">
    <div class="pb-dash-home-actions__todo mb-0">
        <h2 class="h6 mb-2">Perlu tindakan</h2>
        <ul class="list-unstyled mb-0 pb-dash-home-actions__todo-list">
            <?php foreach ($actionItems as $item):
                $sev = (string) ($item['severity'] ?? 'info');
                $href = (string) ($item['href'] ?? '#');
                $label = (string) ($item['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                ?>
            <li class="pb-dash-home-actions__todo-item pb-dash-home-actions__todo-item--<?= htmlspecialchars($sev) ?>">
                <span class="pb-dash-home-actions__todo-label"><?= htmlspecialchars($label) ?></span>
                <a href="<?= htmlspecialchars($href) ?>" class="btn btn-sm btn-outline-secondary">Periksa</a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
