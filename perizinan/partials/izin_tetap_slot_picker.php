<?php

declare(strict_types=1);

/**
 * @var array<int, string> $hariMap
 * @var list<array{hari:list<int>,jam_mulai:string,jam_selesai:string}> $izinTetapSlotBloks
 */
$hariMap = $hariMap ?? santri_izin_tetap_hari_map();
$izinTetapSlotBloks = $izinTetapSlotBloks ?? [['hari' => [1], 'jam_mulai' => '08:00', 'jam_selesai' => '12:00']];
?>
<div id="izin-tetap-slot-bloks" class="mb-2">
    <?php foreach ($izinTetapSlotBloks as $bi => $blok):
        $hariTerpilih = array_map('intval', (array) ($blok['hari'] ?? []));
        $jm = substr((string) ($blok['jam_mulai'] ?? '08:00'), 0, 5);
        $js = substr((string) ($blok['jam_selesai'] ?? '12:00'), 0, 5);
        ?>
        <div class="izin-tetap-slot-blok border rounded bg-light p-2 mb-2" data-blok-index="<?= (int) $bi ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
                <span class="small fw-semibold text-primary izin-tetap-blok-label">Blok waktu <?= (int) $bi + 1 ?></span>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-link btn-sm p-0 text-primary js-izin-tetap-hari-semua" data-blok="<?= (int) $bi ?>">Pilih semua hari</button>
                    <span class="text-muted">·</span>
                    <button type="button" class="btn btn-link btn-sm p-0 text-secondary js-izin-tetap-hari-bersih" data-blok="<?= (int) $bi ?>">Bersihkan</button>
                    <?php if ($bi > 0): ?>
                        <span class="text-muted">·</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-danger js-izin-tetap-hapus-blok" title="Hapus blok">Hapus blok</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row g-1 mb-2">
                <?php foreach ($hariMap as $hk => $hl):
                    $cbId = 'slot-hari-' . $bi . '-' . $hk;
                    ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="form-check mb-0">
                            <input class="form-check-input izin-tetap-hari-cb" type="checkbox"
                                   name="slot_hari[<?= (int) $bi ?>][]"
                                   id="<?= htmlspecialchars($cbId) ?>"
                                   value="<?= (int) $hk ?>"
                                <?= in_array((int) $hk, $hariTerpilih, true) ? ' checked' : '' ?>>
                            <label class="form-check-label small" for="<?= htmlspecialchars($cbId) ?>"><?= htmlspecialchars($hl) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-6">
                    <label class="form-label small mb-0">Jam mulai</label>
                    <input type="time" class="form-control form-control-sm izin-tetap-jam-mulai"
                           name="slot_jam_mulai[<?= (int) $bi ?>]" value="<?= htmlspecialchars($jm) ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-0">Jam selesai</label>
                    <input type="time" class="form-control form-control-sm izin-tetap-jam-selesai"
                           name="slot_jam_selesai[<?= (int) $bi ?>]" value="<?= htmlspecialchars($js) ?>" required>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<button type="button" class="btn btn-outline-secondary btn-sm mb-1" id="btn-tambah-blok-slot">
    <i class="fa-solid fa-plus me-1"></i> Tambah blok waktu lain
</button>
<p class="form-text small mb-0">Centang beberapa hari dengan jam yang sama dalam satu blok. Gunakan blok lain jika durasi berbeda.</p>

<template id="tpl-izin-tetap-slot-blok">
    <div class="izin-tetap-slot-blok border rounded bg-light p-2 mb-2" data-blok-index="__IDX__">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
            <span class="small fw-semibold text-primary izin-tetap-blok-label">Blok waktu __NUM__</span>
            <div class="d-flex flex-wrap gap-1">
                <button type="button" class="btn btn-link btn-sm p-0 text-primary js-izin-tetap-hari-semua" data-blok="__IDX__">Pilih semua hari</button>
                <span class="text-muted">·</span>
                <button type="button" class="btn btn-link btn-sm p-0 text-secondary js-izin-tetap-hari-bersih" data-blok="__IDX__">Bersihkan</button>
                <span class="text-muted">·</span>
                <button type="button" class="btn btn-link btn-sm p-0 text-danger js-izin-tetap-hapus-blok" title="Hapus blok">Hapus blok</button>
            </div>
        </div>
        <div class="row g-1 mb-2">
            <?php foreach ($hariMap as $hk => $hl):
                $cbId = 'slot-hari-__IDX__-' . $hk;
                ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="form-check mb-0">
                        <input class="form-check-input izin-tetap-hari-cb" type="checkbox"
                               name="slot_hari[__IDX__][]"
                               id="<?= htmlspecialchars($cbId) ?>"
                               value="<?= (int) $hk ?>">
                        <label class="form-check-label small" for="<?= htmlspecialchars($cbId) ?>"><?= htmlspecialchars($hl) ?></label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-6">
                <label class="form-label small mb-0">Jam mulai</label>
                <input type="time" class="form-control form-control-sm izin-tetap-jam-mulai"
                       name="slot_jam_mulai[__IDX__]" value="08:00" required>
            </div>
            <div class="col-6">
                <label class="form-label small mb-0">Jam selesai</label>
                <input type="time" class="form-control form-control-sm izin-tetap-jam-selesai"
                       name="slot_jam_selesai[__IDX__]" value="12:00" required>
            </div>
        </div>
    </div>
</template>
