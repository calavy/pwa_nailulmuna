<?php

declare(strict_types=1);

/**
 * Pilih tingkatan ringkas (chip), bukan daftar checkbox besar.
 *
 * @var list<string> $tingkatanList
 * @var list<string> $selectedTingkatan
 * @var string $inputName default tingkatan[]
 */
$inputName = $inputName ?? 'tingkatan[]';
$selectedTingkatan = $selectedTingkatan ?? [];
?>
<div class="jadwal-tingkatan-chips" role="group" aria-label="Pilih tingkatan">
    <?php foreach ($tingkatanList as $tg): ?>
        <?php
        $tgText = (string) $tg;
        $tgId = 'tg-chip-' . md5($inputName . $tgText);
        $checked = in_array($tgText, $selectedTingkatan, true);
        ?>
        <input type="checkbox" class="btn-check" name="<?= htmlspecialchars($inputName) ?>" id="<?= htmlspecialchars($tgId) ?>"
               value="<?= htmlspecialchars($tgText) ?>" autocomplete="off"<?= $checked ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary btn-sm jadwal-tingkatan-chip" for="<?= htmlspecialchars($tgId) ?>">
            <?= htmlspecialchars($tgText) ?>
        </label>
    <?php endforeach; ?>
</div>
<p class="form-text small mb-0 mt-1">Centang satu atau lebih tingkatan. Tampilan ringkas — bukan kartu besar.</p>
