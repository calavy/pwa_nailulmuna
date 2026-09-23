<?php

declare(strict_types=1);

/** @var array<string,list<array<string,mixed>>> $santriMapPerTingkatan */

$santriMapPerTingkatan = is_array($santriMapPerTingkatan ?? null) ? $santriMapPerTingkatan : [];
$tingkatanKeys = array_keys($santriMapPerTingkatan);
sort($tingkatanKeys, SORT_NATURAL | SORT_FLAG_CASE);
$totalSantriListed = 0;
foreach ($tingkatanKeys as $tkKey) {
    $totalSantriListed += count($santriMapPerTingkatan[$tkKey] ?? []);
}
?>
<section class="pb-dash-subpage pb-dash-subpage--santri" aria-label="Daftar santri bimbingan">
    <?php if ($tingkatanKeys === [] || $totalSantriListed === 0): ?>
        <div class="pb-dash-subpage__card">
            <p class="small text-muted mb-0">Belum ada santri dibimbing.</p>
        </div>
    <?php else: ?>
        <?php foreach ($tingkatanKeys as $tkName):
            $rows = $santriMapPerTingkatan[$tkName] ?? [];
            if ($rows === []) {
                continue;
            }
            ?>
            <div class="pb-dash-subpage__card pb-dash-subpage__card--tk">
                <h2 class="pb-dash-subpage__title h6 mb-2"><?= htmlspecialchars($tkName) ?></h2>
                <p class="pb-dash-subpage__meta small text-muted mb-2"><?= count($rows) ?> santri</p>
                <ul class="pb-dash-santri-subpage-list list-unstyled mb-0">
                    <?php foreach ($rows as $row): ?>
                        <li class="pb-dash-santri-subpage-list__item">
                            <span class="pb-dash-santri-subpage-list__name"><?= htmlspecialchars((string) ($row['nama_santri'] ?? '—')) ?></span>
                            <?php if (trim((string) ($row['nis'] ?? '')) !== ''): ?>
                                <span class="pb-dash-santri-subpage-list__nis text-muted"><?= htmlspecialchars((string) $row['nis']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
