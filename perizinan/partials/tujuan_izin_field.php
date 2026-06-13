<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/perizinan_jenis.php';

/** @var string $tujuanWrapId */
/** @var string|null $tujuanValue */
/** @var bool|null $tujuanAlwaysVisible */
/** @var string|null $tujuanJenisSelectId */
$tujuanWrapId = (string) ($tujuanWrapId ?? 'wrap-tujuan-izin');
$tujuanValue = (string) ($tujuanValue ?? '');
$tujuanAlwaysVisible = !empty($tujuanAlwaysVisible);
$tujuanJenisSelectId = (string) ($tujuanJenisSelectId ?? '');
?>
<div class="col-12 perizinan-tujuan-wrap<?= $tujuanAlwaysVisible ? '' : ' d-none' ?>"
     id="<?= htmlspecialchars($tujuanWrapId) ?>"
     <?= $tujuanJenisSelectId !== '' ? ' data-jenis-select="' . htmlspecialchars($tujuanJenisSelectId) . '"' : '' ?>
     <?= $tujuanAlwaysVisible ? ' data-always-visible="1"' : '' ?>>
    <label class="form-label<?= isset($tujuanLabelClass) ? ' ' . htmlspecialchars((string) $tujuanLabelClass) : '' ?>">Tujuan <span class="text-danger perizinan-tujuan-req">*</span></label>
    <input type="text"
           class="form-control<?= isset($tujuanInputClass) ? ' ' . htmlspecialchars((string) $tujuanInputClass) : '' ?> perizinan-tujuan-input"
           name="tujuan"
           maxlength="255"
           value="<?= htmlspecialchars($tujuanValue) ?>"
           placeholder="Contoh: rumah orang tua, masjid, acara di …"
           <?= $tujuanAlwaysVisible ? 'required' : '' ?>>
    <div class="form-text">Wajib untuk izin keluar, tugas, dan syar'i.</div>
</div>
