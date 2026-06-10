<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';

require_roles(['admin', 'pengurus', 'kiai']);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$role = strtolower(trim((string) ($_GET['role'] ?? 'pembimbing')));
if (!in_array($role, ['pembimbing', 'munawib'], true)) {
    $role = 'pembimbing';
}

$sdm = rekap_keaktifan_hari_sdm($pdo, $tanggal);
$data = $sdm[$role] ?? ['masuk' => 0, 'total' => 0, 'tidak_hadir' => []];
$label = $role === 'munawib' ? 'Munawib' : 'Pembimbing';

$pageTitle = 'Keaktifan ' . $label . ' — ' . $tanggal;
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1">
            <a href="<?= htmlspecialchars(app_href('/pengasuh/dashboard.php')) ?>">Dashboard Pengasuh</a>
            ·
            <a href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php?tanggal=' . urlencode($tanggal))) ?>">Laporan Hari Ini</a>
        </p>
        <h1 class="h4 mb-1"><?= htmlspecialchars($label) ?> — <?= htmlspecialchars(date('d F Y', strtotime($tanggal))) ?></h1>
        <p class="text-muted mb-0">Hadir <?= (int) $data['masuk'] ?> dari <?= (int) $data['total'] ?> · data scan hari ini</p>
    </header>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Belum datang hari ini</div>
        <div class="card-body p-0">
            <?php $absent = $data['tidak_hadir'] ?? []; ?>
            <?php if ($absent === []): ?>
                <div class="p-4 text-center text-success">
                    <i class="fa-solid fa-circle-check fs-3 mb-2"></i>
                    <p class="mb-0">Semua <?= strtolower($label) ?> sudah scan hadir.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>Nama</th><th>NIP</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($absent as $r): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($r['nama'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['nip'] ?? '-')) ?></td>
                                <td>
                                    <?php
                                    $st = (string) ($r['status'] ?? 'Tanpa Keterangan');
                                    $badge = match ($st) {
                                        'Sakit' => 'info',
                                        'Izin' => 'warning',
                                        default => 'secondary',
                                    };
                                    ?>
                                    <span class="badge text-bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($st) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php?tanggal=' . urlencode($tanggal))) ?>">
            <i class="fa-solid fa-arrow-left me-1"></i>Kembali ke Laporan Hari Ini
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
