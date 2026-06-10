<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

$dateFrom = trim((string) ($_GET['from'] ?? date('Y-m-d')));
$dateTo = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$status = trim((string) ($_GET['status'] ?? 'all'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if (!in_array($status, ['all', 'success', 'failed'], true)) {
    $status = 'all';
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$rows = [];
$summary = ['total' => 0, 'success' => 0, 'failed' => 0];
$hasWaLogs = table_exists($pdo, 'wa_logs');

if ($hasWaLogs) {
    $where = [
        'message LIKE :msg',
        'DATE(created_at) BETWEEN :from AND :to',
    ];
    if ($status === 'success') {
        $where[] = 'is_success = 1';
    } elseif ($status === 'failed') {
        $where[] = 'is_success = 0';
    }
    $sql = '
        SELECT id, target_phone, message, response_text, is_success, created_at
        FROM wa_logs
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id DESC
        LIMIT 300
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'msg' => '⚠️ Laporan kelas kosong%',
        'from' => $dateFrom,
        'to' => $dateTo,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stSum = $pdo->prepare('
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) AS total_success,
            SUM(CASE WHEN is_success = 0 THEN 1 ELSE 0 END) AS total_failed
        FROM wa_logs
        WHERE message LIKE :msg
          AND DATE(created_at) BETWEEN :from AND :to
    ');
    $stSum->execute([
        'msg' => '⚠️ Laporan kelas kosong%',
        'from' => $dateFrom,
        'to' => $dateTo,
    ]);
    $sumRow = $stSum->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary = [
        'total' => (int) ($sumRow['total'] ?? 0),
        'success' => (int) ($sumRow['total_success'] ?? 0),
        'failed' => (int) ($sumRow['total_failed'] ?? 0),
    ];
}

$pageTitle = 'Laporan WA Kelas Kosong';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/wa_otomatis.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Laporan WA Kelas Kosong</h1>
    <p class="text-muted mb-0 small">Riwayat notifikasi otomatis saat pada slot jadwal tidak ada pembimbing maupun munawib yang masuk.</p>
    <p class="small mb-0 mt-1"><a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=log')) ?>">← Kembali ke WA Otomatis</a></p>
</div>

<?php if (!$hasWaLogs): ?>
    <div class="alert alert-warning">Tabel <code>wa_logs</code> belum tersedia.</div>
<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Dari tanggal</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sampai tanggal</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status kirim</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Semua</option>
                        <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Berhasil</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Gagal</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/settings/wa_laporan_kelas_kosong.php')) ?>">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Total log</div>
                <div class="app-mini-stat-value"><?= (int) $summary['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Berhasil</div>
                <div class="app-mini-stat-value text-success"><?= (int) $summary['success'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Gagal</div>
                <div class="app-mini-stat-value text-danger"><?= (int) $summary['failed'] ?></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Riwayat kirim WA kelas kosong</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>No. Tujuan</th>
                        <th>Isi Pesan</th>
                        <th>Respon Gateway</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada log pada rentang tanggal ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="small text-nowrap"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
                        <td class="small">
                            <?php if ((int) ($r['is_success'] ?? 0) === 1): ?>
                                <span class="badge text-bg-success">Berhasil</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Gagal</span>
                            <?php endif; ?>
                        </td>
                        <td class="small font-monospace"><?= htmlspecialchars((string) ($r['target_phone'] ?? '-')) ?></td>
                        <td class="small" style="min-width:280px; white-space:pre-line"><?= htmlspecialchars((string) ($r['message'] ?? '')) ?></td>
                        <td class="small" style="min-width:220px; white-space:pre-line"><?= htmlspecialchars((string) ($r['response_text'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';

