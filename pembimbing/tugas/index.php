<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM ikhtibar_jawaban WHERE sesi_id IN (SELECT id FROM ikhtibar_sesi WHERE tugas_id = :id)')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_sesi WHERE tugas_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_soal WHERE tugas_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_tugas WHERE id = :id')->execute(['id' => $id]);
        set_flash('success', 'Tugas dihapus.');
    }
    header('Location: ' . app_href('/pembimbing/tugas/index.php'));
    exit;
}

$rows = ikhtibar_tugas_list_pembimbing($pdo, $userId);
$pageTitle = 'Tugas Santri (Ikhtibar)';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php')) ?>">Dashboard Pembimbing</a>
        · Kajian · Tugas Ikhtibar
    </p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-list-check text-primary me-1"></i> Tugas Santri (Ikhtibar)</h1>
            <p class="text-muted mb-0">Buat tugas, atur soal PG &amp; esai, token keamanan, dan pantau nilai santri.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a>
            <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/rekap.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chart-pie me-1"></i> Rekap nilai</a>
            <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php')) ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i> Buat tugas baru</a>
        </div>
    </div>
</div>

<div class="alert alert-light border small mb-3">
    <strong class="d-block mb-1">Alur ke santri</strong>
    <ol class="mb-0 ps-3">
        <li>Buat tugas → isi soal PG/esai → tekan <strong>Publikasikan tugas</strong> (status <em>published</em>).</li>
        <li>Santri login di <a href="<?= htmlspecialchars(app_href('/santri_portal/login.php')) ?>" target="_blank" rel="noopener">portal santri</a> (NIS + PIN portal atau PIN cashless).</li>
        <li>Tugas muncul di <strong>Tugas Ikhtibar</strong> pada hari tugas (tanggal ≤ hari ini) dan tingkatan yang sesuai.</li>
        <li>Santri memasukkan token (jika diaktifkan), mengerjakan, lalu menyelesaikan — nilai bisa dilihat pembimbing di menu Nilai.</li>
    </ol>
</div>

<?php
$flashOk = get_flash('success');
$flashErr = get_flash('error');
if ($flashOk): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Judul</th>
                        <th>Kelas / mapel</th>
                        <th>Tanggal</th>
                        <th>Durasi</th>
                        <th>Soal</th>
                        <th>Token</th>
                        <th>Status</th>
                        <th>Selesai</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada tugas. Klik <strong>Buat tugas baru</strong>.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $id = (int) $r['id'];
                    $st = (string) ($r['status'] ?? 'draft');
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['judul']) ?></td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($r['mapel_label'] ?? '')) !== '' ? (string) $r['mapel_label'] : '—') ?></td>
                        <td class="small text-nowrap"><?= htmlspecialchars(ikhtibar_hari_label((int) ($r['hari_ke'] ?? 0))) ?><br><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                        <td><?= (int) ($r['durasi_menit'] ?? 0) ?> mnt</td>
                        <td class="small">PG <?= (int) ($r['jumlah_pg'] ?? 0) ?><br>Esai <?= (int) ($r['jumlah_esai'] ?? 0) ?></td>
                        <td class="small">
                            <?php if ((int) ($r['pakai_token'] ?? 0) === 1): ?>
                                <span class="badge text-bg-warning">Aktif</span>
                                <?php if ($st === 'published' && !empty($r['token_plain'])): ?>
                                    <br><code class="user-select-all"><?= htmlspecialchars((string) $r['token_plain']) ?></code>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $badge = match ($st) {
                                'published' => 'success',
                                'closed' => 'secondary',
                                default => 'warning',
                            };
                            ?>
                            <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                        </td>
                        <td><?= (int) ($r['jumlah_selesai'] ?? 0) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php?id=' . $id)) ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href('/pembimbing/tugas/nilai.php?tugas_id=' . $id)) ?>">Nilai</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus tugas ini?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
