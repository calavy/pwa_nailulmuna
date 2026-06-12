<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/perizinan_jenis.php';

/** @var string $selectedJenis */
/** @var bool $includeSakit */
$selectedJenis = strtoupper(trim((string) ($selectedJenis ?? 'KELUAR')));
if ($selectedJenis === 'PULANG') {
    $selectedJenis = 'TUGAS';
}
if (!empty($includeSakit)): ?>
    <option value="SAKIT" <?= $selectedJenis === 'SAKIT' ? 'selected' : '' ?>>Sakit</option>
<?php endif;
foreach (perizinan_jenis_izin_dropdown() as $kode => $label): ?>
    <option value="<?= htmlspecialchars($kode) ?>" <?= $selectedJenis === $kode ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
<?php endforeach; ?>
