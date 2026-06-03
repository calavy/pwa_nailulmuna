<?php

declare(strict_types=1);

/**
 * Tombol tetap di bawah layar (desktop/tablet) — masuk portal penerima setoran.
 *
 * @var PDO|null $pdo
 */

require_once __DIR__ . '/../../helpers/login_pembimbing.php';

$setoranEntry = login_pembimbing_setoran_entry_meta(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
?>
<div class="pb-dash-setoran-bottom d-none d-md-block" role="navigation" aria-label="Masuk penerima setoran">
    <a href="<?= htmlspecialchars($setoranEntry['href']) ?>" class="pb-dash-setoran-bottom__btn">
        <span class="pb-dash-setoran-bottom__icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($setoranEntry['icon']) ?>"></i></span>
        <span class="pb-dash-setoran-bottom__text">
            <strong class="pb-dash-setoran-bottom__title"><?= htmlspecialchars($setoranEntry['title']) ?></strong>
            <span class="pb-dash-setoran-bottom__desc"><?= htmlspecialchars($setoranEntry['desc']) ?></span>
        </span>
        <span class="pb-dash-setoran-bottom__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
    </a>
</div>
