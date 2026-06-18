<?php

declare(strict_types=1);

/**
 * Pilih banyak santri — dikelompokkan tingkatan, urut NIS.
 *
 * @var array<string, list<array<string, mixed>>> $rombonganSantriGrouped
 * @var string $rombonganPickerName nama field checkbox (santri_ids_rombongan[] / santri_kembali[])
 * @var string $rombonganPickerId prefix id elemen
 * @var bool $rombonganPickerShowToolbar tampilkan tombol pilih semua
 * @var bool $rombonganPickerHideBelumKembali sembunyikan tombol "pilih yang belum kembali"
 * @var array<int, true> $rombonganPickerChecked map santri_id => true (opsional)
 */

$rombonganPickerName = $rombonganPickerName ?? 'santri_ids_rombongan[]';
$rombonganPickerId = $rombonganPickerId ?? 'rombongan-pick';
$rombonganPickerShowToolbar = !isset($rombonganPickerShowToolbar) || $rombonganPickerShowToolbar;
$rombonganPickerHideBelumKembali = !empty($rombonganPickerHideBelumKembali);
$rombonganPickerChecked = $rombonganPickerChecked ?? [];
$rombonganSantriGrouped = $rombonganSantriGrouped ?? [];
?>
<div class="rombongan-santri-picker border rounded" id="<?= htmlspecialchars($rombonganPickerId) ?>-wrap">
    <?php if ($rombonganPickerShowToolbar): ?>
        <div class="d-flex flex-wrap gap-2 p-2 border-bottom bg-light">
            <button type="button" class="btn btn-sm btn-outline-primary js-rombongan-pilih-semua" data-target="<?= htmlspecialchars($rombonganPickerId) ?>">
                <i class="fa-solid fa-check-double me-1"></i> Pilih semua
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-rombongan-bersihkan" data-target="<?= htmlspecialchars($rombonganPickerId) ?>">
                Bersihkan
            </button>
            <?php if (!$rombonganPickerHideBelumKembali): ?>
            <button type="button" class="btn btn-sm btn-outline-success js-rombongan-pilih-belum" data-target="<?= htmlspecialchars($rombonganPickerId) ?>">
                Pilih yang belum kembali
            </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="rombongan-santri-picker__scroll" style="max-height:min(22rem,50vh);overflow-y:auto">
        <?php if ($rombonganSantriGrouped === []): ?>
            <p class="text-muted small text-center py-3 mb-0">Tidak ada santri.</p>
        <?php endif; ?>
        <?php foreach ($rombonganSantriGrouped as $tingkatanLabel => $santriRows): ?>
            <div class="rombongan-santri-picker__group" data-tingkatan="<?= htmlspecialchars($tingkatanLabel) ?>">
                <div class="rombongan-santri-picker__group-head sticky-top d-flex flex-wrap justify-content-between align-items-center gap-2 px-2 py-1">
                    <span class="fw-semibold small"><?= htmlspecialchars($tingkatanLabel) ?></span>
                    <span class="badge text-bg-secondary"><?= count($santriRows) ?> santri</span>
                    <?php if ($rombonganPickerShowToolbar): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 js-rombongan-pilih-tingkatan" data-target="<?= htmlspecialchars($rombonganPickerId) ?>" data-tingkatan="<?= htmlspecialchars($tingkatanLabel) ?>">
                            Pilih tingkatan ini
                        </button>
                    <?php endif; ?>
                </div>
                <?php foreach ($santriRows as $sr):
                    $sid = (int) ($sr['santri_id'] ?? $sr['id'] ?? 0);
                    $nis = trim((string) ($sr['nis'] ?? ''));
                    $nama = trim((string) ($sr['nama_santri'] ?? ''));
                    $sudah = !empty($rombonganPickerChecked[$sid]) || (int) ($sr['rombongan_kembali'] ?? 0) === 1;
                    ?>
                    <div class="rombongan-santri-picker__row px-2 py-1 border-top<?= $sudah ? ' bg-success bg-opacity-10' : '' ?>">
                        <?php if ($sudah && $rombonganPickerName === 'santri_kembali[]'): ?>
                            <span class="badge text-bg-success me-2"><i class="fa-solid fa-check"></i></span>
                            <span class="text-muted text-decoration-line-through small">
                                <span class="font-monospace"><?= htmlspecialchars($nis) ?></span>
                                — <?= htmlspecialchars($nama) ?>
                            </span>
                            <span class="small text-success ms-1">Sudah kembali</span>
                        <?php else: ?>
                            <div class="form-check mb-0">
                                <input class="form-check-input rombongan-santri-cb" type="checkbox"
                                       name="<?= htmlspecialchars($rombonganPickerName) ?>"
                                       id="<?= htmlspecialchars($rombonganPickerId) ?>-<?= $sid ?>"
                                       value="<?= $sid ?>"
                                       data-tingkatan="<?= htmlspecialchars($tingkatanLabel) ?>"
                                       data-belum-kembali="<?= $sudah ? '0' : '1' ?>"
                                    <?= !empty($rombonganPickerChecked[$sid]) ? ' checked' : '' ?>>
                                <label class="form-check-label small w-100" for="<?= htmlspecialchars($rombonganPickerId) ?>-<?= $sid ?>">
                                    <span class="font-monospace fw-semibold"><?= htmlspecialchars($nis) ?></span>
                                    <span class="mx-1">—</span>
                                    <?= htmlspecialchars($nama) ?>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
