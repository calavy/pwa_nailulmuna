<?php

declare(strict_types=1);

/**
 * Kartu keaktifan PKPPS (santri per jadwal + pembimbing).
 *
 * @var list<array<string,mixed>> $detailKeg
 * @var list<array<string,mixed>> $pembimbingCards
 * @var callable(string): string $labelKegiatan
 * @var callable(int,int): float $barPct
 * @var callable(array,int): string $previewNames
 */

$labelKegiatan = $labelKegiatan ?? static fn (string $n): string => $n === '' ? '' : mb_convert_case(trim($n), MB_CASE_TITLE, 'UTF-8');
$barPct = $barPct ?? static fn (int $n, int $total): float => $total > 0 ? round(100 * $n / $total, 2) : 0.0;
$previewNames = $previewNames ?? static function (array $santriByStatus, int $limit = 3): string {
    $names = [];
    foreach ($santriByStatus['ALPA'] ?? [] as $s) {
        $nama = trim((string) ($s['nama_santri'] ?? ''));
        if ($nama !== '') {
            $names[] = $nama;
        }
        if (count($names) >= $limit) {
            break;
        }
    }
    if ($names === []) {
        return '';
    }
    $more = count($santriByStatus['ALPA'] ?? []) - count($names);

    return implode(', ', $names) . ($more > 0 ? ' +' . $more : '');
};

$totalAlpaSantri = 0;
foreach ($detailKeg as $dkSum) {
    $totalAlpaSantri += (int) ($dkSum['alpa'] ?? 0);
}

$pbBelumTotal = 0;
foreach ($pembimbingCards as $pc) {
    $pbBelumTotal += (int) ($pc['belum'] ?? 0);
}
?>

<?php if ($totalAlpaSantri > 0): ?>
<div class="card border-warning kh-section kh-banner-attn shadow-sm mb-3">
    <div class="card-body py-2">
        <span class="fw-semibold text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= (int) $totalAlpaSantri ?> santri PKPPS perlu perhatian (alpa)</span>
    </div>
</div>
<?php endif; ?>

<h2 class="h6 kh-section mb-2"><i class="fa-solid fa-user-graduate me-1 text-primary"></i> Keaktifan santri PKPPS</h2>

<?php if ($detailKeg === []): ?>
<div class="card shadow-sm border-0 kh-section mb-4">
    <div class="card-body text-center text-muted py-4">
        <p class="mb-0 fw-semibold">Tidak ada jadwal PKPPS atau santri aktif</p>
        <p class="small mb-0">Periksa jadwal PKPPS dan keanggotaan santri.</p>
    </div>
</div>
<?php else: ?>
<div class="kh-grid kh-section mb-4" id="pkppsKhGridSantri">
    <?php foreach ($detailKeg as $dk):
        $kid = (int) ($dk['kegiatan_id'] ?? 0);
        $total = (int) ($dk['total'] ?? 0);
        $hadir = (int) ($dk['hadir'] ?? 0);
        $pctHadir = $total > 0 ? round(100 * $hadir / $total, 0) : 0;
        $santri = $dk['santri'] ?? [];
        $perlu = (int) ($dk['alpa'] ?? 0);
        $preview = $previewNames(is_array($santri) ? $santri : []);
        $needsAttention = $perlu > 0;
        $barAman = $total > 0 && $perlu === 0;
        $subParts = array_filter([
            trim((string) ($dk['jam_label'] ?? '')),
            trim((string) ($dk['pembimbing'] ?? '')) !== '' ? 'Pb: ' . trim((string) $dk['pembimbing']) : '',
            trim((string) ($dk['tempat'] ?? '')),
        ]);
        $statItems = [
            ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) ($dk['hadir'] ?? 0)],
            ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) ($dk['izin'] ?? 0)],
            ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) ($dk['sakit'] ?? 0)],
            ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) ($dk['alpa'] ?? 0)],
        ];
        ?>
    <article class="kh-card<?= $needsAttention ? ' kh-card--warning' : '' ?>" id="pkpps-keg-<?= $kid ?>" data-kegiatan-id="<?= $kid ?>">
        <div class="kh-card__head">
            <h2 class="kh-card__title"><?= htmlspecialchars($labelKegiatan((string) $dk['nama_kegiatan'])) ?></h2>
            <?php if ($subParts !== []): ?>
            <div class="small text-muted mb-1"><?= htmlspecialchars(implode(' · ', $subParts)) ?></div>
            <?php endif; ?>
            <div class="kh-card__meta"><?= $hadir ?> hadir dari <?= $total ?> santri · <strong><?= (int) $pctHadir ?>%</strong></div>
            <div class="kh-bar<?= $barAman ? ' kh-bar--aman' : '' ?>">
                <?php if ($barAman): ?>
                <span class="kh-bar__seg kh-bar__seg--aman" style="width:100%"></span>
                <?php else: ?>
                <?php foreach (['hadir' => 'hadir', 'izin' => 'izin', 'sakit' => 'sakit', 'alpa' => 'alpa'] as $key => $cls):
                    $n = (int) ($dk[$key] ?? 0);
                    $w = $barPct($n, $total);
                    if ($w <= 0) {
                        continue;
                    }
                ?>
                <span class="kh-bar__seg kh-bar__seg--<?= $cls ?>" style="width:<?= $w ?>%"></span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="kh-stats">
            <?php foreach ($statItems as $si): ?>
            <button type="button" class="kh-stat kh-stat--<?= htmlspecialchars($si['key']) ?> kh-stat--clickable" data-kh-stat-tab="<?= htmlspecialchars($si['tab']) ?>" aria-expanded="false">
                <span class="kh-stat__n"><?= $si['n'] ?></span>
                <span class="kh-stat__l"><?= htmlspecialchars($si['label']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="kh-stat-popup d-none" data-kh-stat-popup></div>
        <?php if ($perlu > 0): ?>
        <div class="kh-card__alert">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-triangle-exclamation kh-card__alert-icon"></i>
                <span class="kh-card__alert-count"><?= $perlu ?> santri perlu perhatian</span>
            </div>
            <?php if ($preview !== ''): ?>
            <div class="kh-card__alert-names d-none d-md-block"><?= htmlspecialchars($preview) ?></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="kh-card__alert kh-card__alert--ok">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-circle-check kh-card__alert-icon"></i>
                <span>Semua santri sudah tercatat</span>
            </div>
        </div>
        <?php endif; ?>
        <div class="kh-card__body">
            <button type="button" class="kh-detail-toggle" data-bs-toggle="collapse" data-bs-target="#pkpps-kh-detail-<?= $kid ?>" data-kh-detail-btn>
                <i class="fa-solid fa-chevron-down"></i>
                <span>Daftar santri<?= $perlu > 0 ? ' (' . $perlu . ' perlu)' : '' ?></span>
            </button>
        </div>
        <div class="collapse" id="pkpps-kh-detail-<?= $kid ?>">
            <div class="kh-detail-panel">
                <div class="kh-tabs" role="tablist">
                    <button type="button" class="kh-tab is-active" data-kh-tab="perlu" data-kh-card="<?= $kid ?>">Perlu (<?= $perlu ?>)</button>
                    <button type="button" class="kh-tab" data-kh-tab="HADIR" data-kh-card="<?= $kid ?>">Hadir</button>
                    <button type="button" class="kh-tab" data-kh-tab="ALPA" data-kh-card="<?= $kid ?>">Alpa</button>
                    <button type="button" class="kh-tab" data-kh-tab="IZIN" data-kh-card="<?= $kid ?>">Izin</button>
                    <button type="button" class="kh-tab" data-kh-tab="SAKIT" data-kh-card="<?= $kid ?>">Sakit</button>
                </div>
                <?php
                $listsPayload = [
                    'perlu' => $santri['ALPA'] ?? [],
                    'HADIR' => $santri['HADIR'] ?? [],
                    'ALPA' => $santri['ALPA'] ?? [],
                    'IZIN' => $santri['IZIN'] ?? [],
                    'SAKIT' => $santri['SAKIT'] ?? [],
                ];
                ?>
                <script type="application/json" class="kh-santri-data"><?= json_encode($listsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                <?php foreach (['perlu', 'HADIR', 'ALPA', 'IZIN', 'SAKIT'] as $tabKey): ?>
                <ul class="kh-list<?= $tabKey === 'perlu' ? '' : ' d-none' ?>" data-kh-list="<?= htmlspecialchars($tabKey) ?>" data-kh-card="<?= $kid ?>" data-kh-lazy="1" data-kh-empty-msg="Tidak ada data."></ul>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pbBelumTotal > 0): ?>
<div class="card border-warning kh-section kh-banner-attn shadow-sm mb-3">
    <div class="card-body py-2">
        <span class="fw-semibold text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= (int) $pbBelumTotal ?> jadwal pembimbing belum scan</span>
    </div>
</div>
<?php endif; ?>

<h2 class="h6 kh-section mb-2 mt-2"><i class="fa-solid fa-chalkboard-user me-1 text-primary"></i> Keaktifan pembimbing PKPPS</h2>

<?php if ($pembimbingCards === []): ?>
<div class="card shadow-sm border-0 kh-section">
    <div class="card-body text-center text-muted py-4">
        <p class="mb-0 fw-semibold">Belum ada pembimbing di jadwal PKPPS hari ini</p>
    </div>
</div>
<?php else: ?>
<div class="kh-grid kh-section" id="pkppsKhGridPb">
    <?php foreach ($pembimbingCards as $pc):
        $pid = (int) ($pc['pembimbing_id'] ?? 0);
        $totalJ = (int) ($pc['total'] ?? 0);
        $hadirJ = (int) ($pc['hadir'] ?? 0);
        $belumJ = (int) ($pc['belum'] ?? 0);
        $pct = $totalJ > 0 ? round(100 * $hadirJ / $totalJ, 0) : 0;
        $barAman = $totalJ > 0 && $belumJ === 0;
        ?>
    <article class="kh-card<?= $belumJ > 0 ? ' kh-card--warning' : '' ?>" id="pkpps-pb-<?= $pid ?>">
        <div class="kh-card__head">
            <h2 class="kh-card__title"><?= htmlspecialchars((string) ($pc['nama_pembimbing'] ?? '')) ?></h2>
            <?php if (trim((string) ($pc['nip'] ?? '')) !== ''): ?>
            <div class="small text-muted font-monospace mb-1"><?= htmlspecialchars((string) $pc['nip']) ?></div>
            <?php endif; ?>
            <div class="kh-card__meta"><?= $hadirJ ?> dari <?= $totalJ ?> jadwal sudah scan · <strong><?= (int) $pct ?>%</strong></div>
            <div class="kh-bar<?= $barAman ? ' kh-bar--aman' : '' ?>">
                <?php if ($barAman): ?>
                <span class="kh-bar__seg kh-bar__seg--aman" style="width:100%"></span>
                <?php else: ?>
                <span class="kh-bar__seg kh-bar__seg--hadir" style="width:<?= $barPct($hadirJ, $totalJ) ?>%"></span>
                <span class="kh-bar__seg kh-bar__seg--alpa" style="width:<?= $barPct($belumJ, $totalJ) ?>%"></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="kh-stats">
            <div class="kh-stat kh-stat--hadir">
                <span class="kh-stat__n"><?= $hadirJ ?></span>
                <span class="kh-stat__l">Sudah scan</span>
            </div>
            <div class="kh-stat kh-stat--alpa">
                <span class="kh-stat__n"><?= $belumJ ?></span>
                <span class="kh-stat__l">Belum</span>
            </div>
        </div>
        <?php if ($belumJ > 0): ?>
        <div class="kh-card__alert">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-triangle-exclamation kh-card__alert-icon"></i>
                <span><?= $belumJ ?> jadwal belum scan</span>
            </div>
        </div>
        <?php else: ?>
        <div class="kh-card__alert kh-card__alert--ok">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-circle-check kh-card__alert-icon"></i>
                <span>Semua jadwal sudah scan</span>
            </div>
        </div>
        <?php endif; ?>
        <div class="kh-card__body">
            <button type="button" class="kh-detail-toggle" data-bs-toggle="collapse" data-bs-target="#pkpps-pb-detail-<?= $pid ?>">
                <i class="fa-solid fa-chevron-down"></i>
                <span>Detail jadwal (<?= $totalJ ?>)</span>
            </button>
        </div>
        <div class="collapse" id="pkpps-pb-detail-<?= $pid ?>">
            <div class="kh-detail-panel">
                <ul class="kh-list mb-0">
                    <?php foreach ($pc['jadwal'] ?? [] as $jw): ?>
                    <li class="kh-list__item d-flex justify-content-between gap-2 align-items-start">
                        <div class="min-w-0">
                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($jw['kegiatan'] ?? '')) ?></div>
                            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($jw['tingkatan'] ?? '')) ?> · <?= htmlspecialchars((string) ($jw['jam'] ?? '')) ?></div>
                        </div>
                        <span class="badge text-bg-<?= ($jw['status'] ?? '') === 'HADIR' ? 'success' : 'danger' ?>"><?= ($jw['status'] ?? '') === 'HADIR' ? ($jw['jam_scan'] ?? 'Hadir') : 'Belum' ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
