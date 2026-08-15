<?php

declare(strict_types=1);

/** @var list<array{value:string,label:string,group:string}> $posPengeluaranOpts */
/** @var string $posSelected */
/** @var string $posFieldName */
/** @var bool $posRequired */

$posSelected = (string) ($posSelected ?? '');
$posFieldName = (string) ($posFieldName ?? 'pos_pengeluaran');
$posRequired = !empty($posRequired);
$opts = is_array($posPengeluaranOpts ?? null) ? $posPengeluaranOpts : [];
$allowedValues = [];
foreach ($opts as $opt) {
    $val = (string) ($opt['value'] ?? '');
    if ($val !== '') {
        $allowedValues[] = $val;
    }
}
$posLegacyInvalid = $posSelected !== '' && !in_array($posSelected, $allowedValues, true);
?>
<select class="form-select" name="<?= htmlspecialchars($posFieldName) ?>"<?= $posRequired ? ' required' : '' ?>>
    <option value="" disabled<?= $posSelected === '' || $posLegacyInvalid ? ' selected' : '' ?>>— Pilih pos / jenis beban —</option>
    <?php
    $lastGroup = '';
    foreach ($opts as $opt):
        $grp = (string) ($opt['group'] ?? '');
        if ($grp !== $lastGroup):
            if ($lastGroup !== '') {
                echo '</optgroup>';
            }
            echo '<optgroup label="' . htmlspecialchars($grp) . '">';
            $lastGroup = $grp;
        endif;
        $val = (string) ($opt['value'] ?? '');
        ?>
        <option value="<?= htmlspecialchars($val) ?>"<?= !$posLegacyInvalid && $posSelected === $val ? ' selected' : '' ?>>
            <?= htmlspecialchars((string) ($opt['label'] ?? '')) ?>
        </option>
    <?php endforeach;
    if ($lastGroup !== '') {
        echo '</optgroup>';
    }
    ?>
</select>
<?php if ($opts === []): ?>
    <div class="form-text text-warning">Belum ada kategori alokasi aktif. Atur di <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">Pengaturan alokasi</a>.</div>
<?php else: ?>
    <div class="form-text">Wajib — kategori dari pengaturan alokasi syahriyah, awal tahun, dan makan. <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">Pengaturan alokasi</a></div>
<?php endif; ?>
<?php if ($posLegacyInvalid): ?>
    <div class="form-text text-warning">Pos lama (<?= htmlspecialchars($posSelected) ?>) tidak ada di daftar alokasi aktif. Pilih ulang sebelum menyimpan.</div>
<?php endif; ?>
