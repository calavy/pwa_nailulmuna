<?php

declare(strict_types=1);

/**
 * Keaktivan hari ini — tab Ta'lim / Jama'ah.
 *
 * @var array<string,array<string,mixed>> $keaktivanPanels
 * @var string $today
 * @var PDO $pdo
 * @var string $jamServerLabel
 * @var string $tglLabel
 * @var callable(string): string $labelKegiatan
 * @var callable(int,int): float $barPct
 * @var callable(array,int): string $previewNames
 */
$keaktivanPanels = is_array($keaktivanPanels ?? null) ? $keaktivanPanels : [];
$today = trim((string) ($today ?? date('Y-m-d')));
if (!isset($pdo) || !($pdo instanceof PDO)) {
    global $pdo;
}
$jamServerLabel = trim((string) ($jamServerLabel ?? ''));
$tglLabel = trim((string) ($tglLabel ?? ''));
$labelKegiatan = $labelKegiatan ?? static fn (string $n): string => $n;
$barPct = $barPct ?? static fn (int $n, int $total): float => $total > 0 ? round(100 * $n / $total, 2) : 0.0;
$previewNames = $previewNames ?? static fn (array $s): string => '';

$panelOrder = ['TAALIM', 'JAMAAH'];
?>
<section class="pg-dash-keaktivan mb-4" id="pg-dash-keaktivan">
    <div class="card border-0 shadow-sm dash-panel dash-panel--lift">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Keaktivan hari ini</h2>
                    <p class="small text-muted mb-0">
                        <?php if (!empty($keaktivanModeLive)): ?>
                            Kegiatan sedang berlangsung · slot <span data-pg-sync-clock="hm"><?= htmlspecialchars($jamServerLabel) ?></span> WIB
                        <?php elseif (!empty($keaktivanModeProgress)): ?>
                            Semua kegiatan yang sudah berjalan hari ini · jam <span data-pg-sync-clock="hm"><?= htmlspecialchars($jamServerLabel) ?></span> WIB
                        <?php else: ?>
                            Ringkasan presensi hari ini · belum ada kegiatan berjalan di jam <span data-pg-sync-clock="hm"><?= htmlspecialchars($jamServerLabel) ?></span> WIB
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php')) ?>" class="btn btn-sm btn-outline-primary rounded-pill">
                    Laporan lengkap
                </a>
            </div>

            <div class="pg-dash-kat-tabs" role="tablist" aria-label="Kategori kegiatan">
                <?php foreach ($panelOrder as $idx => $panelKey):
                    if (!isset($keaktivanPanels[$panelKey])) {
                        continue;
                    }
                    $p = $keaktivanPanels[$panelKey];
                    $jumlahKeg = count($p['detailLive'] ?? []);
                    $isActive = $panelKey === 'TAALIM';
                    ?>
                <button type="button"
                    class="pg-dash-kat-tab<?= $isActive ? ' is-active' : '' ?>"
                    role="tab"
                    id="pg-dash-kat-tab-<?= htmlspecialchars((string) ($p['slug'] ?? '')) ?>"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                    aria-controls="pg-dash-kat-<?= htmlspecialchars((string) ($p['slug'] ?? '')) ?>"
                    data-pg-kat-tab="<?= htmlspecialchars($panelKey) ?>">
                    <?= htmlspecialchars((string) ($p['label'] ?? $panelKey)) ?>
                    <?php if ($jumlahKeg > 0): ?>
                        <span class="pg-dash-kat-tab__count"><?= (int) $jumlahKeg ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card-body px-4 pb-4 pt-3">
            <div class="kh-panduan kh-panduan--desktop d-none d-md-flex kh-section mb-3" role="note" aria-label="Cara membaca">
                <strong><i class="fa-solid fa-circle-info me-1 text-primary"></i>Cara membaca:</strong>
                <span class="kh-panduan__item kh-panduan__item--hadir">Hadir</span> sudah scan ·
                <span class="kh-panduan__item kh-panduan__item--izin">Izin</span>/<span class="kh-panduan__item kh-panduan__item--sakit">Sakit</span> ada keterangan ·
                <span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> tidak scan sampai jam kegiatan selesai · ketuk kotak jumlah untuk lihat nama santri.
            </div>

            <?php foreach ($panelOrder as $panelKey):
                if (!isset($keaktivanPanels[$panelKey])) {
                    continue;
                }
                $panel = $keaktivanPanels[$panelKey];
                $panelActive = $panelKey === 'TAALIM';
                require __DIR__ . '/dashboard_keaktivan_kategori_panel.php';
            endforeach; ?>
        </div>
    </div>
</section>

<script>
(function () {
    var tabs = document.querySelectorAll('[data-pg-kat-tab]');
    var panels = document.querySelectorAll('[data-pg-kat-panel]');
    if (!tabs.length || !panels.length) {
        return;
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-pg-kat-tab') || '';
            tabs.forEach(function (t) {
                var active = t === tab;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.classList.toggle('d-none', panel.getAttribute('data-pg-kat-panel') !== key);
            });
        });
    });
})();
</script>
