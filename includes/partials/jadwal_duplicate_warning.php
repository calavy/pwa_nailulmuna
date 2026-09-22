<?php

declare(strict_types=1);

/**
 * Banner peringatan jadwal dobel (nama kegiatan + tingkatan sama, ≥2 master ID).
 *
 * @var list<array<string, mixed>> $jadwalDuplicateGroups
 */
$jadwalDuplicateGroups = is_array($jadwalDuplicateGroups ?? null) ? $jadwalDuplicateGroups : [];
if ($jadwalDuplicateGroups === []) {
    return;
}
?>
<div class="alert alert-warning py-2 small mb-3" role="alert">
    <p class="fw-semibold mb-2 mb-md-1">
        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
        Jadwal dobel: nama kegiatan + tingkatan sama
    </p>
    <p class="mb-2">Lebih dari satu master kegiatan (ID berbeda) memakai <strong>nama sama</strong> untuk <strong>tingkatan yang sama</strong>. Absensi Multi Scan bisa ambigu — rapikan di master kegiatan atau hapus salah satu slot.</p>
    <ul class="mb-2 ps-3">
        <?php foreach ($jadwalDuplicateGroups as $grp): ?>
            <?php if (!is_array($grp)) {
                continue;
            } ?>
            <li><?= htmlspecialchars(jadwal_duplicate_nama_tingkatan_message($grp)) ?></li>
        <?php endforeach; ?>
    </ul>
    <a class="alert-link" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>">Kelola master kegiatan</a>
</div>
