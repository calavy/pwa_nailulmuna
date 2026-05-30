<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_nilai_manual.php';

require_roles(['admin', 'pengurus', 'pembimbing']);

pembimbing_nilai_manual_ensure_schema($pdo);

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$pbAktif = pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingIdAktif = (int) ($pbAktif['id'] ?? 0);
$filterPb = $bolehSemua ? max(0, (int) ($_GET['pembimbing_id'] ?? 0)) : $pembimbingIdAktif;

$where = 'WHERE t.is_aktif = 1';
$params = [];
if ($filterPb > 0) {
    $where .= ' AND t.pembimbing_id = :pid';
    $params['pid'] = $filterPb;
}

$sql = '
    SELECT t.*, p.nama_pembimbing,
           (SELECT COUNT(*) FROM pembimbing_nilai_manual n WHERE n.target_id = t.id) AS jumlah_nilai
    FROM pembimbing_penilaian_target t
    INNER JOIN pembimbing p ON p.id = t.pembimbing_id
    ' . $where . '
    ORDER BY t.tanggal_mulai DESC, t.id DESC
    LIMIT 200
';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pembimbingList = [];
if ($bolehSemua && table_exists($pdo, 'pembimbing')) {
    $pembimbingList = $pdo->query('SELECT id, nama_pembimbing FROM pembimbing WHERE COALESCE(is_aktif,1)=1 ORDER BY nama_pembimbing')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Rekapan Nilai Manual';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php')) ?>">Nilai Manual</a></p>
    <h1 class="h4 mb-1">Rekapan target penilaian</h1>
    <p class="text-muted small mb-0">Ringkasan per target penilaian — periode bisa 1 minggu hingga 1 bulan atau lebih.</p>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
    <?php if ($bolehSemua): ?>
    <div class="col-md-4">
        <label class="form-label small mb-0">Pembimbing</label>
        <select name="pembimbing_id" class="form-select form-select-sm">
            <option value="0">Semua</option>
            <?php foreach ($pembimbingList as $p): ?>
                <option value="<?= (int) ($p['id'] ?? 0) ?>" <?= $filterPb === (int) ($p['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($p['nama_pembimbing'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm">Filter</button>
    </div>
    <?php endif; ?>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Target</th>
                    <th>Aspek</th>
                    <th>Periode</th>
                    <th>Pembimbing</th>
                    <th class="text-center">Entri nilai</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada target penilaian.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars((string) ($r['judul'] ?? '')) ?></div>
                        <?php if (!empty($r['deskripsi'])): ?>
                            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) $r['deskripsi']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars(strtoupper((string) ($r['aspek'] ?? ''))) ?></td>
                    <td class="small text-nowrap"><?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($r['tanggal_selesai'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['nama_pembimbing'] ?? '')) ?></td>
                    <td class="text-center"><?= (int) ($r['jumlah_nilai'] ?? 0) ?></td>
                    <td class="text-end">
                        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?target_id=' . (int) ($r['id'] ?? 0))) ?>">Input nilai</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
