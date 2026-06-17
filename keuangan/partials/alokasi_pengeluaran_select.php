<?php

declare(strict_types=1);

/** @var list<array{value:string,label:string,group:string}> $alokasiPengeluaranOpts */
/** @var string $alokasiSelected */
/** @var string $alokasiFieldName */
/** @var bool $alokasiRequired */

$alokasiSelected = (string) ($alokasiSelected ?? '');
$alokasiFieldName = (string) ($alokasiFieldName ?? 'alokasi_nama');
$alokasiRequired = !empty($alokasiRequired);
?>
<select class="form-select" name="<?= htmlspecialchars($alokasiFieldName) ?>"<?= $alokasiRequired ? ' required' : '' ?>>
    <option value="" disabled<?= $alokasiSelected === '' ? ' selected' : '' ?>>— Pilih alokasi dana —</option>
    <?php
    $lastGroup = '';
    foreach ($alokasiPengeluaranOpts as $opt):
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
        <option value="<?= htmlspecialchars($val) ?>"<?= $alokasiSelected === $val ? ' selected' : '' ?>>
            <?= htmlspecialchars((string) ($opt['label'] ?? '')) ?>
        </option>
    <?php endforeach;
    if ($lastGroup !== '') {
        echo '</optgroup>';
    }
    ?>
</select>
<?php if ($alokasiPengeluaranOpts === []): ?>
    <div class="form-text text-warning">Belum ada komponen alokasi aktif. Atur di <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">Pengaturan alokasi</a>.</div>
<?php else: ?>
    <div class="form-text">Wajib — selaras dengan laporan alokasi dana.</div>
<?php endif; ?>
