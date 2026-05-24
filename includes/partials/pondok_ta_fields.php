<?php

declare(strict_types=1);

/**
 * Input tahun ajaran mulai/selesai (selaras kalender pondok).
 *
 * @var PDO $pdo
 * @var int $taMulai
 * @var int $taSelesai
 * @var string $nameMulai default tahun_ajaran_mulai
 * @var string $nameSelesai default tahun_ajaran_selesai
 * @var string $inputClass default form-control
 * @var bool $selesaiReadonly default true in hijri mode
 */
if (!function_exists('keuangan_ta_pilihan_options')) {
    require_once __DIR__ . '/../../helpers/keuangan_ta_context.php';
}

$taMeta = pondok_ta_form_meta($pdo);
$nameMulai = $nameMulai ?? 'tahun_ajaran_mulai';
$nameSelesai = $nameSelesai ?? 'tahun_ajaran_selesai';
$inputClass = $inputClass ?? 'form-control';
$selesaiReadonly = $selesaiReadonly ?? pondok_kalender_hijriyah($pdo);
$taColClass = $taColClass ?? 'col-md-3';
$taMulai = (int) ($taMulai ?? 0);
$taSelesai = (int) ($taSelesai ?? ($taMulai + 1));
$taInputMode = $taInputMode ?? 'dropdown';
$taOptions = $taInputMode === 'dropdown' ? keuangan_ta_pilihan_options($pdo) : [];
?>
<?php if ($taInputMode === 'dropdown'): ?>
<div class="<?= htmlspecialchars($taColClass) ?> pondok-ta-field pondok-ta-field--dropdown" data-ta-hijri="<?= pondok_kalender_hijriyah($pdo) ? '1' : '0' ?>">
    <label class="form-label">Tahun ajaran</label>
    <select class="<?= htmlspecialchars($inputClass) ?> pondok-ta-select" name="<?= htmlspecialchars($nameMulai) ?>" required>
        <?php foreach ($taOptions as $opt): ?>
            <?php $m = (int) $opt['mulai']; ?>
            <option value="<?= $m ?>" data-ts="<?= (int) $opt['selesai'] ?>" <?= $m === $taMulai ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $opt['label']) ?>
                <?php if (!empty($opt['is_aktif'])): ?> (aktif)<?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" class="pondok-ta-selesai-hidden" name="<?= htmlspecialchars($nameSelesai) ?>" value="<?= $taSelesai ?>">
    <?php if ($selesaiReadonly): ?>
        <div class="form-text">Tahun selesai otomatis +1<?= htmlspecialchars($taMeta['suffix']) ?> (Hijriyah).</div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="<?= htmlspecialchars($taColClass) ?> pondok-ta-field" data-ta-hijri="<?= pondok_kalender_hijriyah($pdo) ? '1' : '0' ?>">
    <label class="form-label"><?= htmlspecialchars($taMeta['label_mulai']) ?></label>
    <input type="number" class="<?= htmlspecialchars($inputClass) ?> pondok-ta-mulai" name="<?= htmlspecialchars($nameMulai) ?>"
           min="<?= (int) $taMeta['min'] ?>" max="<?= (int) $taMeta['max'] ?>"
           value="<?= $taMulai ?>" required>
</div>
<div class="<?= htmlspecialchars($taColClass) ?> pondok-ta-field">
    <label class="form-label"><?= htmlspecialchars($taMeta['label_selesai']) ?></label>
    <input type="number" class="<?= htmlspecialchars($inputClass) ?> pondok-ta-selesai" name="<?= htmlspecialchars($nameSelesai) ?>"
           min="<?= (int) $taMeta['min'] ?>" max="<?= (int) $taMeta['max'] ?>"
           value="<?= $taSelesai ?>" <?= $selesaiReadonly ? 'readonly' : '' ?> required>
    <?php if ($selesaiReadonly): ?>
        <div class="form-text">Otomatis <?= (int) $taMulai + 1 ?><?= htmlspecialchars($taMeta['suffix']) ?> (TA Hijriyah).</div>
    <?php endif; ?>
</div>
<?php endif; ?>
