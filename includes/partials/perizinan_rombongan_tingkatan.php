<?php
/**
 * Anggota izin rombongan per tingkatan (ringkasan + daftar lipat).
 *
 * @var array<string, list<array<string, mixed>>> $anggotaGrouped
 */
$anggotaGrouped = $anggotaGrouped ?? [];
if ($anggotaGrouped === []) {
    return;
}
$tkRingkas = [];
foreach ($anggotaGrouped as $tk => $tkRows) {
    $tkRingkas[] = $tk . ' ' . count($tkRows);
}
?>
<details class="pg-rombongan-tk">
    <summary class="small"><?= htmlspecialchars(implode(' · ', $tkRingkas)) ?></summary>
    <div class="pg-rombongan-tk__list">
        <?php foreach ($anggotaGrouped as $tk => $tkRows): ?>
            <div class="pg-rombongan-tk__group">
                <div class="pg-rombongan-tk__title"><?= htmlspecialchars((string) $tk) ?> <span class="text-muted">(<?= count($tkRows) ?>)</span></div>
                <ol class="pg-rombongan-tk__names">
                    <?php foreach ($tkRows as $ang): ?>
                        <li>
                            <?= htmlspecialchars((string) ($ang['nama_santri'] ?? '')) ?>
                            <?php if (trim((string) ($ang['nis'] ?? '')) !== ''): ?>
                                <span class="text-muted">· <?= htmlspecialchars((string) $ang['nis']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endforeach; ?>
    </div>
</details>
