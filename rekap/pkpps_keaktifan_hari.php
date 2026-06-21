<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../helpers/rekap_pkpps_keaktifan_hari.php';

require_roles(['admin', 'pengurus', 'kiai', 'pembimbing']);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$filterTingkatan = (int) ($_GET['tingkatan'] ?? 0);
$forceRefresh = isset($_GET['sync']) && (string) $_GET['sync'] === '1';

$bundle = rekap_pkpps_keaktifan_hari_bundle(
    $pdo,
    $tanggal,
    $filterTingkatan > 0 ? $filterTingkatan : null,
    $forceRefresh
);
$detailKeg = $bundle['santri'];
$pembimbingCards = $bundle['pembimbing'];
$ringkasan = rekap_keaktifan_hari_ringkasan_from_detail($detailKeg);
$totals = rekap_keaktifan_hari_totals($ringkasan);

$tingkatanList = pkpps_tingkatan_list($pdo, true);

$bulanId = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$ts = strtotime($tanggal);
$tglLabel = $ts !== false
    ? (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts)
    : $tanggal;

$barPct = static fn (int $n, int $total): float => $total > 0 ? round(100 * $n / $total, 2) : 0.0;
$labelKegiatan = static fn (string $nama): string => $nama === '' ? '' : mb_convert_case(trim($nama), MB_CASE_TITLE, 'UTF-8');
$previewNames = static function (array $santriByStatus, int $limit = 3): string {
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

$pageTitle = 'Keaktifan PKPPS Hari Ini';
$pageStylesheets = [app_asset_href('/assets/css/keaktifan-hari.css')];
$bodyClass = 'page-keaktifan-hari page-pkpps-keaktifan-hari';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="kh-wrap">
    <div class="page-intro mb-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a></p>
            <h1 class="h4 mb-1">Keaktifan PKPPS hari ini</h1>
            <p class="text-muted mb-0 small">Presensi santri &amp; pembimbing per jadwal PKPPS — tampilan kartu seperti keaktifan harian.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php')) ?>"><i class="fa-solid fa-chart-line me-1"></i>Rekap periode</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>"><i class="fa-solid fa-arrow-left me-1"></i>Dashboard PKPPS</a>
        </div>
    </div>

    <form class="row g-2 align-items-end kh-section kh-filter-form mb-3" method="get">
        <div class="col-12 col-md-2">
            <label class="form-label small mb-0">Tanggal</label>
            <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggal) ?>">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-0">Tingkatan PKPPS</label>
            <select name="tingkatan" class="form-select form-select-sm">
                <option value="0">Semua tingkatan</option>
                <?php foreach ($tingkatanList as $t): ?>
                    <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= $filterTingkatan === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex flex-wrap gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktifan_hari.php?' . http_build_query(array_filter([
                'tanggal' => $tanggal,
                'tingkatan' => $filterTingkatan > 0 ? (string) $filterTingkatan : null,
                'sync' => '1',
            ])))) ?>">Sinkron</a>
        </div>
    </form>

    <div class="kh-hero kh-section mb-3">
        <div class="kh-hero__top">
            <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?><?= $filterTingkatan > 0 ? ' · filter tingkatan' : ' · semua tingkatan' ?></div>
            <div class="small text-muted"><?= count($detailKeg) ?> jadwal santri · <?= count($pembimbingCards) ?> pembimbing</div>
        </div>
        <div class="kh-totals">
            <div class="kh-total-pill kh-total-pill--hadir">
                <span class="kh-total-pill__n"><?= (int) $totals['hadir'] ?></span>
                <span class="kh-total-pill__l">Hadir</span>
            </div>
            <div class="kh-total-pill kh-total-pill--izin">
                <span class="kh-total-pill__n"><?= (int) $totals['izin'] ?></span>
                <span class="kh-total-pill__l">Izin</span>
            </div>
            <div class="kh-total-pill kh-total-pill--sakit">
                <span class="kh-total-pill__n"><?= (int) $totals['sakit'] ?></span>
                <span class="kh-total-pill__l">Sakit</span>
            </div>
            <div class="kh-total-pill kh-total-pill--alpa">
                <span class="kh-total-pill__n"><?= (int) $totals['alpa'] ?></span>
                <span class="kh-total-pill__l">Alpa</span>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/partials/pkpps_keaktifan_hari_panel.php'; ?>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
