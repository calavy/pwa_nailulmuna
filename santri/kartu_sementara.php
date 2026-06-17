<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_kartu.php';
require_once __DIR__ . '/../helpers/santri_kartu_sementara.php';

require_roles(['admin', 'pengurus']);

santri_kartu_sementara_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$row = santri_kartu_fetch($pdo, $id);
if ($row === null) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

$tmpKode = '';
$tmpId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'issue') {
        $res = santri_kartu_sementara_issue($pdo, $id, $userId, trim((string) ($_POST['catatan'] ?? '')));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        if ($res['ok'] && !empty($res['kode_qr'])) {
            header('Location: ' . app_href('/santri/kartu_sementara.php?id=' . $id . '&kode=' . urlencode((string) $res['kode_qr'])));
            exit;
        }
    } elseif ($action === 'revoke_all') {
        $n = santri_kartu_sementara_revoke_all($pdo, $id, $userId);
        set_flash('success', $n > 0 ? $n . ' kartu sementara dinonaktifkan.' : 'Tidak ada kartu sementara aktif.');
        header('Location: ' . app_href('/santri/kartu_sementara.php?id=' . $id));
        exit;
    } elseif ($action === 'revoke_one') {
        $res = santri_kartu_sementara_revoke_one($pdo, (int) ($_POST['tmp_id'] ?? 0), $userId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/santri/kartu_sementara.php?id=' . $id));
        exit;
    }
}

$kodeGet = trim((string) ($_GET['kode'] ?? ''));
if ($kodeGet !== '') {
    $active = santri_kartu_sementara_get_active($pdo, $id);
    if (is_array($active) && (string) ($active['kode_qr'] ?? '') === $kodeGet) {
        $tmpKode = $kodeGet;
        $tmpId = (int) ($active['id'] ?? 0);
    }
}

$riwayat = santri_kartu_sementara_list($pdo, $id, 8);
$brand = santri_kartu_brand($pdo);
$nisSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($row['nis'] ?? 'santri')) ?: 'santri';

if ($tmpKode !== '') {
    $row = santri_kartu_prepare_with_qr($row, $tmpKode);
} else {
    $row = santri_kartu_prepare_row($row);
}

$downloadName = 'kartu-sementara-' . $nisSlug . ($tmpKode !== '' ? '-' . $tmpKode : '');
$kartuVariant = 'sementara';

$pageTitle = 'Kartu Santri Sementara';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Kartu Santri Sementara</h1>
        <p class="text-muted small mb-0">
            Untuk pengganti saat kartu hilang — QR berbeda tiap terbitan, bisa dipakai <strong>presensi</strong> &amp; <strong>cashless</strong>.
            <?= htmlspecialchars((string) ($row['nama_santri'] ?? '')) ?>
            <?php if ($tmpKode !== ''): ?>
                · QR: <code><?= htmlspecialchars($tmpKode) ?></code>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <a href="<?= htmlspecialchars(app_href('/santri/kartu_id.php?id=' . $id)) ?>" class="btn btn-outline-success btn-sm">
            <i class="fa-solid fa-id-card me-1"></i> Kartu utama
        </a>
        <?php if ($tmpKode !== ''): ?>
            <button class="btn btn-primary btn-sm" type="button" id="btnDownloadKartuJpg">
                <i class="fa-solid fa-image me-1"></i> Download JPG
            </button>
            <button class="btn btn-outline-primary btn-sm" type="button" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> Cetak
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 no-print mb-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-warning border-opacity-50">
            <div class="card-body">
                <h2 class="h6 mb-2">Terbitkan kartu baru</h2>
                <p class="small text-muted mb-2">
                    Setiap klik menghasilkan <strong>QR unik</strong> (format <code>STT-…</code>).
                    Kartu sementara aktif sebelumnya otomatis dinonaktifkan.
                </p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="issue">
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan (opsional)</label>
                        <input class="form-control form-control-sm" name="catatan" placeholder="Mis. kartu hilang 12/06/2026" maxlength="200">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="fa-solid fa-plus me-1"></i> Terbitkan &amp; tampilkan kartu
                        </button>
                    </div>
                </form>
                <form method="post" class="mt-2" onsubmit="return confirm('Nonaktifkan semua QR sementara santri ini?');">
                    <input type="hidden" name="action" value="revoke_all">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Nonaktifkan semua QR sementara</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold small">Riwayat QR sementara</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>QR</th><th>Status</th><th>Waktu</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($riwayat === []): ?>
                            <tr><td colspan="4" class="text-muted text-center py-3">Belum pernah diterbitkan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($riwayat as $r): ?>
                                <tr>
                                    <td class="font-monospace small"><?= htmlspecialchars((string) ($r['kode_qr'] ?? '')) ?></td>
                                    <td>
                                        <?php if ((int) ($r['is_aktif'] ?? 0) === 1): ?>
                                            <span class="badge text-bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ((int) ($r['is_aktif'] ?? 0) === 1): ?>
                                            <a class="btn btn-outline-primary btn-sm py-0" href="?id=<?= $id ?>&amp;kode=<?= urlencode((string) ($r['kode_qr'] ?? '')) ?>">Cetak</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($tmpKode !== ''): ?>
<?php require __DIR__ . '/partials/kartu_id_styles.php'; ?>
<div class="st-kartu-wrap">
    <?php
    $cardDomId = 'st-kartu-santri-card';
    require __DIR__ . '/partials/kartu_id_card.php';
    ?>
</div>
<?php require __DIR__ . '/partials/kartu_id_download.js.php'; ?>
<?php else: ?>
<div class="alert alert-info no-print">Terbitkan kartu sementara untuk melihat pratinjau dan mengunduh JPG.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
