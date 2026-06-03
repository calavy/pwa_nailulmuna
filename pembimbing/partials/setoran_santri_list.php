<?php

declare(strict_types=1);

/** @var array<string, list<array<string, mixed>>> $santriByTingkatan */

if (($santriByTingkatan ?? []) === []) {
    echo '<div class="alert alert-warning small mb-0">';
    echo '<strong>Belum ada santri</strong> pada tingkatan yang Anda terima setoran.';
    echo ' Pengurus mengatur di <a href="' . htmlspecialchars(app_href('/akademik/setoran_penerima.php')) . '">Penerima setoran</a> (tab Tingkatan).';
    echo '</div>';
    return;
}
?>
<div class="st-portal-santri card border-0 shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="fw-semibold small"><i class="fa-solid fa-user-group me-1 text-primary"></i> Santri terima setoran</span>
        <span class="badge text-bg-light text-dark"><?= array_sum(array_map('count', $santriByTingkatan)) ?> orang</span>
    </div>
    <div class="card-body p-0">
        <?php foreach ($santriByTingkatan as $tk => $rows): ?>
        <div class="st-portal-santri__tk">
            <div class="st-portal-santri__tk-head"><?= htmlspecialchars((string) $tk) ?> <span class="text-muted">(<?= count($rows) ?>)</span></div>
            <ul class="st-portal-santri__list mb-0">
                <?php foreach ($rows as $sr): ?>
                    <?php
                    $st = (string) ($sr['status_hari_ini'] ?? 'BELUM');
                    $badge = match ($st) {
                        'SETOR' => ['success', 'Sudah setor'],
                        'IZIN' => ['warning', 'Izin'],
                        'LIBUR' => ['secondary', 'Libur'],
                        default => ['danger', 'Belum'],
                    };
                    $sid = (int) ($sr['id'] ?? 0);
                    ?>
                <li class="st-portal-santri__item">
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran.php?santri_id=' . $sid)) ?>" class="st-portal-santri__link">
                        <span class="st-portal-santri__nama"><?= htmlspecialchars((string) ($sr['nama_santri'] ?? '')) ?></span>
                        <span class="st-portal-santri__nis text-muted"><?= htmlspecialchars((string) ($sr['nis'] ?? '')) ?></span>
                        <span class="badge text-bg-<?= $badge[0] ?> st-portal-santri__badge"><?= htmlspecialchars($badge[1]) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</div>
