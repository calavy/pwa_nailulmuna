<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';
require_once __DIR__ . '/../helpers/munawib_portal.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';

ikhtibar_require_pembimbing_access();
munawib_portal_require_konteks();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);

$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
$hasPkppsJadwal = $pembimbingId > 0 && pembimbing_pkpps_has_jadwal($pdo, $pembimbingId);
$hasKajianJadwal = $pembimbingId > 0 && pembimbing_dashboard_has_kajian_jadwal($pdo, $pembimbingId);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}

$rekapJenis = strtolower(trim((string) ($_GET['rekap_jenis'] ?? '')));
if (!in_array($rekapJenis, ['kajian', 'pkpps'], true)) {
    if ($hasPkppsJadwal && !$hasKajianJadwal) {
        $rekapJenis = 'pkpps';
    } else {
        $rekapJenis = 'kajian';
    }
}
if (!$bolehSemua) {
    if ($rekapJenis === 'pkpps' && !$hasPkppsJadwal) {
        $rekapJenis = 'kajian';
    }
    if ($rekapJenis === 'kajian' && !$hasKajianJadwal && $hasPkppsJadwal) {
        $rekapJenis = 'pkpps';
    }
}

if ($santriId <= 0) {
    set_flash('error', 'Pilih santri terlebih dahulu.');
    header('Location: ' . app_href('/pembimbing/dashboard.php?view=keaktivan&keaktifan_view=santri&tahun=' . $tahun . '&rekap_jenis=' . rawurlencode($rekapJenis)));
    exit;
}

$detail = pembimbing_dashboard_keaktifan_santri_detail($pdo, $santriId, $tahun, $pembimbingId, $bolehSemua, $rekapJenis);
if ($detail === null) {
    set_flash('error', 'Santri tidak ditemukan atau di luar lingkup asuhan Anda.');
    header('Location: ' . app_href('/pembimbing/dashboard.php?view=keaktivan&keaktifan_view=santri&tahun=' . $tahun . '&rekap_jenis=' . rawurlencode($rekapJenis)));
    exit;
}

$santri = $detail['santri'];
$summary = $detail['summary'];
$perKegiatan = $detail['per_kegiatan'];
$rekapJenisLabel = $rekapJenis === 'pkpps' ? 'PKPPS' : "Kajian (Ta'lim & Jama'ah)";
$listUrl = app_href('/pembimbing/dashboard.php?view=keaktivan&keaktifan_view=santri&tahun=' . $tahun . '&rekap_jenis=' . rawurlencode($rekapJenis));

$pageTitle = 'Keaktifan — ' . (string) ($santri['nama_santri'] ?? 'Santri');
$bodyClass = 'pembimbing-page pb-keaktifan-santri-page';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars($listUrl) ?>">Keaktivan santri</a>
        <span class="text-muted mx-1">/</span>
        <span><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></span>
    </p>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
            <h1 class="h4 mb-1"><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></h1>
            <p class="text-muted small mb-0">
                NIS <?= htmlspecialchars((string) ($santri['nis'] ?? '—')) ?>
                · <?= htmlspecialchars((string) ($santri['tingkatan'] ?? '—')) ?>
                · rekap <?= htmlspecialchars($rekapJenisLabel) ?> tahun <?= (int) $tahun ?>
                (<?= htmlspecialchars(app_format_tanggal_id((string) $detail['pres_start'])) ?> – <?= htmlspecialchars(app_format_tanggal_id((string) $detail['pres_end'])) ?>)
            </p>
            <p class="small text-muted mb-0"><?= htmlspecialchars(rekap_keaktifan_rekap_footnote($pdo)) ?>.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-sm btn-outline-secondary">← Daftar santri</a>
            <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?santri_id=' . $santriId . '&input_nilai=1')) ?>" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-pen-to-square me-1"></i> Nilai manual
            </a>
        </div>
    </div>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="santri_id" value="<?= (int) $santriId ?>">
    <div class="col-auto">
        <label class="form-label small mb-0" for="pb-santri-tahun">Tahun</label>
        <input id="pb-santri-tahun" type="number" name="tahun" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $tahun ?>" style="width:6rem">
    </div>
    <?php if ($hasKajianJadwal && $hasPkppsJadwal): ?>
        <div class="col-auto">
            <label class="form-label small mb-0" for="pb-santri-jenis">Jenis</label>
            <select id="pb-santri-jenis" name="rekap_jenis" class="form-select form-select-sm">
                <option value="kajian"<?= $rekapJenis === 'kajian' ? ' selected' : '' ?>>Kajian</option>
                <option value="pkpps"<?= $rekapJenis === 'pkpps' ? ' selected' : '' ?>>PKPPS</option>
            </select>
        </div>
    <?php else: ?>
        <input type="hidden" name="rekap_jenis" value="<?= htmlspecialchars($rekapJenis) ?>">
    <?php endif; ?>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Terapkan</button>
    </div>
</form>

<?php
$kat = strtoupper((string) ($summary['kategori'] ?? ''));
$badgeClass = match (true) {
    $kat === 'BAIK' || $kat === 'BAGUS' => 'badge-kat-bagus',
    $kat === 'SEDANG' => 'badge-kat-sedang',
    $kat === 'BURUK' || $kat === 'JELEK' => 'badge-kat-buruk',
    default => 'text-bg-secondary',
};
require __DIR__ . '/partials/keaktifan_santri_detail.php';
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
