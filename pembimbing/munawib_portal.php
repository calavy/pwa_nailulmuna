<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/munawib_portal.php';

require_login();

$munawibId = munawib_session_id();
if ($munawibId <= 0) {
    set_flash('error', 'Halaman ini khusus login munawib.');
    app_redirect('login.php?peran=pembimbing&act=qr');
}

munawib_ensure_schema($pdo);

$today = date('Y-m-d');
$nowJam = date('H:i:s');
$munawibNama = trim((string) ($_SESSION['user']['nama'] ?? 'Munawib'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $penugasanId = (int) ($_POST['penugasan_id'] ?? 0);
    $pbId = (int) ($_POST['pembimbing_id'] ?? 0);
    $kid = (int) ($_POST['kegiatan_id'] ?? 0);

    $berlangsung = munawib_portal_penugasan_berlangsung($pdo, $munawibId, $today, $nowJam);
    $picked = null;
    foreach ($berlangsung as $row) {
        if ((int) ($row['penugasan_id'] ?? 0) === $penugasanId
            && (int) ($row['pembimbing_id'] ?? 0) === $pbId
            && (int) ($row['kegiatan_id'] ?? 0) === $kid) {
            $picked = $row;
            break;
        }
    }

    if ($picked === null) {
        set_flash('error', 'Penugasan tidak valid atau kegiatan sudah tidak berlangsung.');
        header('Location: ' . app_href('/pembimbing/munawib_portal.php'));
        exit;
    }

    munawib_portal_set_konteks($picked);
    $pbNama = trim((string) ($picked['pembimbing_nama'] ?? ''));
    if (!empty($_SESSION['setoran_portal_after_munawib'])) {
        unset($_SESSION['setoran_portal_after_munawib']);
        set_flash('success', 'Portal setoran dibuka — pengganti ' . ($pbNama !== '' ? $pbNama : 'pembimbing') . '.');
        app_redirect('pembimbing/setoran_dashboard.php');
    }
    set_flash('success', 'Portal pembimbing dibuka — pengganti ' . ($pbNama !== '' ? $pbNama : 'pembimbing') . '.');
    app_redirect('pembimbing/dashboard.php');
}

if (isset($_GET['reset']) && (string) $_GET['reset'] === '1') {
    munawib_portal_clear_konteks();
    set_flash('info', 'Pilihan pembimbing direset. Pilih ulang kegiatan yang sedang berlangsung.');
    header('Location: ' . app_href('/pembimbing/munawib_portal.php'));
    exit;
}

$berlangsung = munawib_portal_penugasan_berlangsung($pdo, $munawibId, $today, $nowJam);
$kegiatanGroups = munawib_portal_group_by_kegiatan($berlangsung);
$konteks = munawib_portal_konteks();

$pageTitle = 'Portal Munawib';
$pageStylesheets = [app_asset_href('/assets/css/pembimbing-dashboard.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page pb-dash-bg-putih">
    <div class="container py-3 py-md-4" style="max-width: 560px;">
        <div class="text-center mb-4">
            <p class="text-muted small mb-1"><i class="fa-solid fa-user-clock me-1"></i>Portal Munawib</p>
            <h1 class="h4 mb-1"><?= htmlspecialchars($munawibNama) ?></h1>
            <p class="text-muted small mb-0">
                Pilih kegiatan yang <strong>sedang berlangsung</strong>, lalu ketuk nama pembimbing untuk membuka portal terbatas (Penilaian, Setoran &amp; Keaktivan).
            </p>
        </div>

        <?php if ($konteks !== null): ?>
        <div class="alert alert-success py-2 small d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <i class="fa-solid fa-circle-check me-1"></i>
                Aktif: <strong><?= htmlspecialchars((string) ($konteks['pembimbing_nama'] ?: 'Pembimbing')) ?></strong>
                · <?= htmlspecialchars((string) ($konteks['kegiatan_nama'] ?: 'Kegiatan')) ?>
            </span>
            <span class="d-flex flex-wrap gap-1">
                <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>" class="btn btn-sm btn-warning text-dark">
                    <i class="fa-solid fa-book-quran me-1"></i> Portal setoran
                </a>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php')) ?>" class="btn btn-sm btn-success">Portal pembimbing</a>
            </span>
        </div>
        <?php endif; ?>

        <?php if ($kegiatanGroups === []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="display-6 text-muted mb-2"><i class="fa-solid fa-hourglass-half"></i></div>
                <h2 class="h6 mb-2">Belum ada kegiatan berlangsung</h2>
                <p class="text-muted small mb-0">
                    Saat ini tidak ada penugasan munawib dengan jadwal aktif pada jam <?= htmlspecialchars(substr($nowJam, 0, 5)) ?>.
                    Coba lagi saat waktu kegiatan dimulai, atau hubungi pengurus jika penugasan belum tercatat.
                </p>
            </div>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($kegiatanGroups as $kg): ?>
            <article class="card border-0 shadow-sm overflow-hidden">
                <div class="card-header bg-primary-subtle border-0 py-2">
                    <div class="fw-semibold text-primary-emphasis">
                        <i class="fa-solid fa-bolt me-1"></i><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '')) ?>
                    </div>
                    <div class="small text-muted mt-1">
                        <?php if (($kg['jam_label'] ?? '') !== ''): ?>
                            <span><i class="fa-regular fa-clock me-1"></i><?= htmlspecialchars((string) $kg['jam_label']) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string) ($kg['tingkatan'] ?? '')) !== ''): ?>
                            <span class="ms-2"><i class="fa-solid fa-layer-group me-1"></i><?= htmlspecialchars((string) $kg['tingkatan']) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string) ($kg['tempat'] ?? '')) !== ''): ?>
                            <span class="ms-2"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars((string) $kg['tempat']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body pt-2 pb-3">
                    <p class="small text-muted mb-2">Ketuk pembimbing yang diwakili:</p>
                    <div class="d-grid gap-2">
                        <?php foreach (($kg['pembimbing'] ?? []) as $pb): ?>
                        <form method="post" class="m-0">
                            <input type="hidden" name="penugasan_id" value="<?= (int) ($pb['penugasan_id'] ?? 0) ?>">
                            <input type="hidden" name="pembimbing_id" value="<?= (int) ($pb['pembimbing_id'] ?? 0) ?>">
                            <input type="hidden" name="kegiatan_id" value="<?= (int) ($kg['kegiatan_id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-outline-primary w-100 text-start d-flex align-items-center gap-2 py-2">
                                <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:2.25rem;height:2.25rem">
                                    <i class="fa-solid fa-chalkboard-user"></i>
                                </span>
                                <span>
                                    <span class="fw-semibold d-block"><?= htmlspecialchars((string) ($pb['pembimbing_nama'] ?? 'Pembimbing')) ?></span>
                                    <span class="small text-muted">Buka portal pembimbing atau setoran</span>
                                </span>
                                <i class="fa-solid fa-chevron-right ms-auto text-muted small"></i>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="text-center mt-4 mb-0">
            <a href="<?= htmlspecialchars(app_href('/logout.php')) ?>" class="btn btn-sm btn-outline-secondary">Keluar</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
