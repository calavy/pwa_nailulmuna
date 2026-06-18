<?php

declare(strict_types=1);

/**
 * Pilih banyak tingkatan — checkbox terlihat jelas (bukan chip tersembunyi).
 *
 * @var list<string> $tingkatanPickerList
 * @var list<string> $tingkatanPickerSelected
 * @var string $tingkatanPickerName default tingkatan[]
 * @var string $tingkatanPickerId prefix id elemen
 */
$tingkatanPickerList = $tingkatanPickerList ?? $tingkatanList ?? [];
$tingkatanPickerSelected = $tingkatanPickerSelected ?? $selectedTingkatan ?? [];
$tingkatanPickerName = $tingkatanPickerName ?? 'tingkatan[]';
$tingkatanPickerId = $tingkatanPickerId ?? 'tingkatan-pick';
?>
<div class="tingkatan-multi-picker border rounded bg-light" id="<?= htmlspecialchars($tingkatanPickerId) ?>-wrap">
    <div class="d-flex flex-wrap gap-2 p-2 border-bottom">
        <button type="button" class="btn btn-sm btn-outline-primary js-tingkatan-pilih-semua" data-target="<?= htmlspecialchars($tingkatanPickerId) ?>">
            <i class="fa-solid fa-check-double me-1"></i> Pilih semua
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary js-tingkatan-bersihkan" data-target="<?= htmlspecialchars($tingkatanPickerId) ?>">
            Bersihkan
        </button>
    </div>
    <div class="tingkatan-multi-picker__scroll p-2" style="max-height:min(14rem,45vh);overflow-y:auto">
        <?php if ($tingkatanPickerList === []): ?>
            <p class="text-muted small text-center mb-0 py-2">Belum ada data tingkatan. Isi master tingkatan atau data santri terlebih dahulu.</p>
        <?php endif; ?>
        <div class="row g-1">
            <?php foreach ($tingkatanPickerList as $tg):
                $tgText = (string) $tg;
                $tgId = $tingkatanPickerId . '-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($tgText));
                $checked = in_array($tgText, $tingkatanPickerSelected, true);
                $isSemua = $tgText === 'Semua Tingkatan';
                ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="form-check mb-0">
                        <input class="form-check-input tingkatan-multi-cb" type="checkbox"
                               name="<?= htmlspecialchars($tingkatanPickerName) ?>"
                               id="<?= htmlspecialchars($tgId) ?>"
                               value="<?= htmlspecialchars($tgText) ?>"
                               data-semua="<?= $isSemua ? '1' : '0' ?>"
                            <?= $checked ? ' checked' : '' ?>>
                        <label class="form-check-label small" for="<?= htmlspecialchars($tgId) ?>">
                            <?= htmlspecialchars($tgText) ?>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<p class="form-text small mb-0 mt-1">Centang satu atau lebih tingkatan. Pilih <strong>Semua Tingkatan</strong> saja jika berlaku untuk seluruh pondok.</p>
