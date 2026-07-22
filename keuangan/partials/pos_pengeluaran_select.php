<?php

declare(strict_types=1);

/** @var list<string> $posPengeluaranOpts */
/** @var string $posSelected */
/** @var string $posFieldName */
/** @var bool $posRequired */

$posSelected = (string) ($posSelected ?? '');
$posFieldName = (string) ($posFieldName ?? 'pos_pengeluaran');
$posRequired = !empty($posRequired);
$opts = is_array($posPengeluaranOpts ?? null) ? $posPengeluaranOpts : [];
if ($posSelected !== '' && !in_array($posSelected, $opts, true)) {
    array_unshift($opts, $posSelected);
}
?>
<select class="form-select" name="<?= htmlspecialchars($posFieldName) ?>"<?= $posRequired ? ' required' : '' ?>>
    <option value="" disabled<?= $posSelected === '' ? ' selected' : '' ?>>— Pilih pos / jenis beban —</option>
    <?php foreach ($opts as $posOpt): ?>
        <option value="<?= htmlspecialchars((string) $posOpt) ?>"<?= $posSelected === (string) $posOpt ? ' selected' : '' ?>>
            <?= htmlspecialchars((string) $posOpt) ?>
        </option>
    <?php endforeach; ?>
</select>
<?php if ($opts === []): ?>
    <div class="form-text text-warning">Belum ada daftar pos. Hubungi admin atau catat pengeluaran pertama lewat impor Excel.</div>
<?php else: ?>
    <div class="form-text">Pilih jenis beban — daftar dari pengaturan alokasi &amp; riwayat pengeluaran.</div>
<?php endif; ?>
