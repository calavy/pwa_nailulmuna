<?php

declare(strict_types=1);

/** @var array<string,list<array<string,mixed>>> $santriMapPerTingkatan */
/** @var list<array{label: string, icon: string, path: string, build: callable(int): string}> $pbPenilaianTemplates */

$santriMapPerTingkatan = is_array($santriMapPerTingkatan ?? null) ? $santriMapPerTingkatan : [];
$pbPenilaianTemplates = is_array($pbPenilaianTemplates ?? null) ? $pbPenilaianTemplates : [];

$penilaianRows = [];
foreach ($santriMapPerTingkatan as $tkName => $rows) {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $penilaianRows[] = $row + ['tingkatan' => (string) ($row['tingkatan'] ?? $tkName)];
    }
}
usort(
    $penilaianRows,
    static function (array $a, array $b): int {
        return strcasecmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    }
);
?>
<section class="pb-dash-subpage pb-dash-subpage--penilaian" aria-label="Penilaian santri">
    <div class="pb-dash-subpage__card">
        <h2 class="pb-dash-subpage__title h6 mb-3">Penilaian santri</h2>
        <?php if ($pbPenilaianTemplates === []): ?>
            <p class="small text-muted mb-0">Belum ada menu penilaian untuk akun Anda.</p>
        <?php elseif ($penilaianRows === []): ?>
            <p class="small text-muted mb-0">Belum ada santri dibimbing.</p>
        <?php else: ?>
            <ul class="pb-dash-penilaian-list list-unstyled mb-0">
                <?php foreach ($penilaianRows as $row):
                    $santriId = (int) ($row['id'] ?? $row['santri_id'] ?? 0);
                    $actions = pembimbing_dashboard_home_penilaian_actions_for_santri($pbPenilaianTemplates, $santriId);
                    $nis = trim((string) ($row['nis'] ?? ''));
                    $tkLabel = trim((string) ($row['tingkatan'] ?? ''));
                    ?>
                    <li class="pb-dash-penilaian-list__item">
                        <div class="pb-dash-penilaian-list__name">
                            <?= htmlspecialchars((string) ($row['nama_santri'] ?? '—')) ?>
                            <?php if ($nis !== '' || $tkLabel !== ''): ?>
                                <span class="text-muted fw-normal small">
                                    <?php if ($nis !== ''): ?>
                                        · <?= htmlspecialchars($nis) ?>
                                    <?php endif; ?>
                                    <?php if ($tkLabel !== ''): ?>
                                        · <?= htmlspecialchars($tkLabel) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($actions !== []): ?>
                            <div class="pb-dash-penilaian-list__actions">
                                <?php foreach ($actions as $act): ?>
                                    <a href="<?= htmlspecialchars((string) ($act['href'] ?? '#')) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="<?= htmlspecialchars((string) ($act['icon'] ?? 'fa-solid fa-circle')) ?> me-1" aria-hidden="true"></i><?= htmlspecialchars((string) ($act['label'] ?? 'Menu')) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
