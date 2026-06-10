<?php

declare(strict_types=1);

/** @var array<string, array{label:string,icon:string,desc:string}> $waTabs */
/** @var string $waActiveTab */

?>
<nav class="wa-otomatis-nav list-group mb-3 mb-lg-0" aria-label="Bagian pengaturan WA">
    <?php foreach ($waTabs as $key => $tab): ?>
        <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=' . rawurlencode($key))) ?>"
           class="list-group-item list-group-item-action wa-otomatis-nav__item<?= $waActiveTab === $key ? ' active' : '' ?>"
           <?= $waActiveTab === $key ? ' aria-current="page"' : '' ?>>
            <span class="wa-otomatis-nav__icon"><i class="fa-solid <?= htmlspecialchars($tab['icon']) ?>" aria-hidden="true"></i></span>
            <span class="wa-otomatis-nav__text">
                <strong><?= htmlspecialchars($tab['label']) ?></strong>
                <small><?= htmlspecialchars($tab['desc']) ?></small>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
