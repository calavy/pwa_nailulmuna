<?php

declare(strict_types=1);

/**
 * Kartu kegiatan harian (dipakai rekap kajian & yayasan).
 *
 * @var list<array<string,mixed>> $detailKeg
 * @var list<array<string,mixed>> $ringkasan
 * @var int $kegiatanId
 * @var callable(array): string $filterBase
 * @var callable(string): string $labelKegiatan
 * @var callable(int,int): float $barPct
 * @var callable(array,int): string $previewNames
 * @var bool $khShowPanduan
 */

$khShowPanduan = $khShowPanduan ?? true;

if (!isset($totalPerhatian)) {
    $totalPerhatian = 0;
    foreach ($detailKeg as $dkSum) {
        $totalPerhatian += (int) ($dkSum['alpa'] ?? 0);
    }
}
if (!isset($kegiatanPerhatian)) {
    $kegiatanPerhatian = array_values(array_filter(
        $detailKeg,
        static fn (array $dk): bool => ((int) ($dk['alpa'] ?? 0)) > 0
    ));
}

if ($khShowPanduan): ?>
<div class="kh-panduan kh-panduan--desktop d-none d-md-flex kh-section" role="note" aria-label="Cara membaca">
    <strong><i class="fa-solid fa-circle-info me-1 text-primary"></i>Cara membaca:</strong>
    <span class="kh-panduan__item kh-panduan__item--hadir">Hadir</span> sudah scan ·
    <span class="kh-panduan__item kh-panduan__item--izin">Izin</span>/<span class="kh-panduan__item kh-panduan__item--sakit">Sakit</span> ada keterangan ·
    <span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> tidak scan sampai jam kegiatan selesai (tanpa izin/sakit) · ketuk kotak jumlah untuk lihat nama · geser tab kegiatan.
</div>
<?php endif; ?>

<?php if ($totalPerhatian > 0): ?>
<div class="card border-warning kh-section kh-banner-attn shadow-sm">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="fw-semibold text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= (int) $totalPerhatian ?> santri perlu perhatian</span>
                <span class="text-muted small ms-1">(alpa)</span>
            </div>
            <span class="small text-muted"><?= count($kegiatanPerhatian) ?> kegiatan terdampak</span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($khShowHero) && isset($totals, $tglLabel)): ?>
<?php
$khHeroSantri = function_exists('rekap_keaktifan_hari_santri_agregat')
    ? rekap_keaktifan_hari_santri_agregat($detailKeg)
    : ['HADIR' => [], 'IZIN' => [], 'SAKIT' => [], 'ALPA' => []];
$khHeroStatItems = [
    ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) $totals['hadir']],
    ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) $totals['izin']],
    ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) $totals['sakit']],
    ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) $totals['alpa']],
];
?>
<div class="kh-hero kh-section" id="khHero">
    <div class="kh-hero__top">
        <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?><?= !empty($khHeroSubtitle) ? ' · ' . htmlspecialchars((string) $khHeroSubtitle) : '' ?></div>
        <div class="small text-muted"><?= count($detailKeg) ?> kegiatan · <?= (int) $totals['total'] ?> <?= htmlspecialchars((string) ($khHeroEntriLabel ?? 'pencatatan (santri × kegiatan)')) ?></div>
    </div>
    <div class="kh-totals">
        <?php foreach ($khHeroStatItems as $hi): ?>
        <button type="button"
            class="kh-total-pill kh-total-pill--<?= htmlspecialchars($hi['key']) ?> kh-total-pill--clickable"
            data-kh-stat-tab="<?= htmlspecialchars($hi['tab']) ?>"
            data-kh-stat-scope="hero"
            aria-expanded="false"
            aria-haspopup="true">
            <?php if ($hi['key'] === 'hadir'): ?>
            <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
            <div class="kh-total-pill__pct"><?= number_format((float) ($totals['persen'] ?? 0), 1, ',', '.') ?>% hadir</div>
            <?php else: ?>
            <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
            <?php endif; ?>
            <div class="kh-total-pill__l"><?= htmlspecialchars($hi['label']) ?></div>
        </button>
        <?php endforeach; ?>
    </div>
    <script type="application/json" class="kh-santri-data kh-santri-data--hero"><?= json_encode($khHeroSantri, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <div class="kh-stat-popup d-none" data-kh-stat-popup data-kh-stat-scope="hero" role="region" aria-live="polite"></div>
    <div class="kh-legend">
        <span class="l-hadir">Hadir</span>
        <span class="l-izin">Izin</span>
        <span class="l-sakit">Sakit</span>
        <span class="l-alpa">Alpa</span>
    </div>
</div>
<?php endif; ?>

<div class="kh-section kh-chips-toolbar">
    <div class="kh-chips-scroll" tabindex="0" aria-label="Filter kegiatan">
        <div class="kh-chips" role="tablist">
            <a class="kh-chip <?= $kegiatanId === 0 ? 'is-active' : '' ?>" href="<?= htmlspecialchars($filterBase(['kegiatan_id' => null])) ?>" role="tab">Semua kegiatan</a>
            <?php foreach ($ringkasan as $rg): ?>
                <?php $kid = (int) ($rg['kegiatan_id'] ?? 0); ?>
                <a class="kh-chip <?= $kegiatanId === $kid ? 'is-active' : '' ?>" href="<?= htmlspecialchars($filterBase(['kegiatan_id' => $kid])) ?>" role="tab">
                    <?= htmlspecialchars($labelKegiatan((string) $rg['nama_kegiatan'])) ?>
                    <span class="badge rounded-pill <?= $kegiatanId === $kid ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $rg['hadir'] ?>/<?= (int) $rg['total'] ?></span>
                    <?php $rgPerlu = (int) ($rg['alpa'] ?? 0); ?>
                    <?php if ($rgPerlu > 0): ?>
                        <span class="badge rounded-pill text-bg-warning text-dark"><?= $rgPerlu ?> perlu</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary kh-toggle-all" id="khToggleAll" data-expanded="0">Buka semua detail</button>
</div>

<div class="kh-grid kh-section" id="khGrid">
    <?php foreach ($detailKeg as $dk):
        $kid = (int) ($dk['kegiatan_id'] ?? 0);
        $total = (int) ($dk['total'] ?? 0);
        $hadir = (int) ($dk['hadir'] ?? 0);
        $pctHadir = $total > 0 ? round(100 * $hadir / $total, 0) : 0;
        $santri = $dk['santri'] ?? [];
        $perlu = (int) ($dk['alpa'] ?? 0);
        $preview = $previewNames(is_array($santri) ? $santri : []);
        $focus = $kegiatanId > 0 && $kegiatanId === $kid;
        $needsAttention = $perlu > 0;
        $barAman = $total > 0 && (int) ($dk['alpa'] ?? 0) === 0;
        $statItems = [
            ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) ($dk['hadir'] ?? 0)],
            ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) ($dk['izin'] ?? 0)],
            ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) ($dk['sakit'] ?? 0)],
            ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) ($dk['alpa'] ?? 0)],
        ];
        ?>
    <article class="kh-card<?= $focus ? ' is-focus' : '' ?><?= $needsAttention ? ' kh-card--warning' : '' ?>" id="keg-<?= $kid ?>" data-kegiatan-id="<?= $kid ?>">
        <div class="kh-card__head">
            <h2 class="kh-card__title"><?= htmlspecialchars($labelKegiatan((string) $dk['nama_kegiatan'])) ?></h2>
            <div class="kh-card__meta"><?= $hadir ?> hadir dari <?= $total ?> santri · <strong><?= (int) $pctHadir ?>%</strong></div>
            <div class="kh-bar<?= $barAman ? ' kh-bar--aman' : '' ?>" role="img" aria-label="<?= $barAman ? 'Kegiatan aman, tanpa alpa' : 'Distribusi presensi' ?>">
                <?php if ($barAman): ?>
                <span class="kh-bar__seg kh-bar__seg--aman" style="width:100%" title="Tidak ada alpa"></span>
                <?php else: ?>
                <?php foreach (['hadir' => 'hadir', 'izin' => 'izin', 'sakit' => 'sakit', 'alpa' => 'alpa'] as $key => $cls):
                    $n = (int) ($dk[$key] ?? 0);
                    $w = $barPct($n, $total);
                    if ($w <= 0) {
                        continue;
                    }
                ?>
                <span class="kh-bar__seg kh-bar__seg--<?= $cls ?>" style="width:<?= $w ?>%" title="<?= ucfirst($key) ?> <?= $n ?>"></span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="kh-stats">
            <?php foreach ($statItems as $si): ?>
            <button type="button"
                class="kh-stat kh-stat--<?= htmlspecialchars($si['key']) ?> kh-stat--clickable"
                data-kh-stat-tab="<?= htmlspecialchars($si['tab']) ?>"
                aria-expanded="false"
                aria-haspopup="true">
                <span class="kh-stat__n"><?= $si['n'] ?></span>
                <span class="kh-stat__l"><?= htmlspecialchars($si['label']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="kh-stat-popup d-none" data-kh-stat-popup role="region" aria-live="polite"></div>
        <?php if ($perlu > 0): ?>
        <div class="kh-card__alert" title="Perlu tindak lanjut">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-triangle-exclamation kh-card__alert-icon" aria-hidden="true"></i>
                <span class="kh-card__alert-count"><?= $perlu ?> santri perlu perhatian</span>
            </div>
            <?php if ($preview !== ''): ?>
            <div class="kh-card__alert-names d-none d-md-block"><?= htmlspecialchars($preview) ?></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="kh-card__alert kh-card__alert--ok">
            <div class="kh-card__alert-head">
                <i class="fa-solid fa-circle-check kh-card__alert-icon" aria-hidden="true"></i>
                <span>Semua santri sudah tercatat hadir/izin/sakit</span>
            </div>
        </div>
        <?php endif; ?>
        <div class="kh-card__body">
            <button type="button" class="kh-detail-toggle" data-bs-toggle="collapse" data-bs-target="#kh-detail-<?= $kid ?>" aria-expanded="<?= $focus ? 'true' : 'false' ?>" data-kh-detail-btn>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                <span>Daftar santri<?= $perlu > 0 ? ' (' . $perlu . ' perlu)' : '' ?></span>
            </button>
        </div>
        <div class="collapse<?= $focus ? ' show' : '' ?>" id="kh-detail-<?= $kid ?>">
            <div class="kh-detail-panel">
                <div class="kh-tabs" role="tablist">
                    <button type="button" class="kh-tab is-active" data-kh-tab="perlu" data-kh-card="<?= $kid ?>">Perlu ditindak (<?= $perlu ?>)</button>
                    <button type="button" class="kh-tab" data-kh-tab="HADIR" data-kh-card="<?= $kid ?>">Hadir (<?= (int) $dk['hadir'] ?>)</button>
                    <button type="button" class="kh-tab" data-kh-tab="ALPA" data-kh-card="<?= $kid ?>">Alpa (<?= (int) $dk['alpa'] ?>)</button>
                    <button type="button" class="kh-tab" data-kh-tab="IZIN" data-kh-card="<?= $kid ?>">Izin</button>
                    <button type="button" class="kh-tab" data-kh-tab="SAKIT" data-kh-card="<?= $kid ?>">Sakit (<?= (int) $dk['sakit'] ?>)</button>
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
                <ul class="kh-list<?= $tabKey === 'perlu' ? '' : ' d-none' ?>" data-kh-list="<?= htmlspecialchars((string) $tabKey) ?>" data-kh-card="<?= $kid ?>" data-kh-lazy="1" data-kh-empty-msg="<?= htmlspecialchars($tabKey === 'perlu' ? 'Semua santri sudah tercatat hadir/izin/sakit.' : 'Tidak ada data.') ?>"></ul>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
