<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';

pkpps_tugas_require_access();
ensure_akademik_ikhtibar_tables($pdo);
pkpps_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $chk = ikhtibar_tugas_by_id($pdo, $id);
        if (is_array($chk) && ikhtibar_tugas_is_row($chk)) {
            set_flash('error', 'Tugas kajian dihapus dari menu Tugas Ikhtibar.');
            header('Location: ' . app_href(pkpps_tugas_base_path() . '/index.php'));
            exit;
        }
        $pdo->prepare('DELETE FROM ikhtibar_jawaban WHERE sesi_id IN (SELECT id FROM ikhtibar_sesi WHERE tugas_id = :id)')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_sesi WHERE tugas_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_soal WHERE tugas_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM ikhtibar_tugas WHERE id = :id')->execute(['id' => $id]);
        set_flash('success', 'Tugas PKPPS dihapus.');
    }
    header('Location: ' . app_href(pkpps_tugas_base_path() . '/index.php'));
    exit;
}

$rows = pkpps_tugas_list($pdo, $userId);
$pageTitle = 'Tugas PKPPS';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a> · Tugas &amp; soal</p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-list-check text-primary me-1"></i> Tugas PKPPS</h1>
            <p class="text-muted mb-0">Soal untuk santri terdaftar PKPPS, berdasarkan jadwal PKPPS &amp; pembimbing Anda.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Dashboard PKPPS</a>
            <a href="<?= htmlspecialchars(app_href(pkpps_tugas_base_path() . '/rekap.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chart-pie me-1"></i> Rekap nilai</a>
            <a href="<?= htmlspecialchars(app_href(pkpps_tugas_base_path() . '/buat.php')) ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i> Buat tugas PKPPS</a>
        </div>
    </div>
</div>

<div class="alert alert-light border small mb-3">
    <strong class="d-block mb-1">Alur ke santri PKPPS</strong>
    <ol class="mb-0 ps-3">
        <li>Pilih <strong>jadwal PKPPS</strong> yang Anda ampu → isi soal → publikasikan.</li>
        <li>Santri PKPPS login portal → menu <strong>Tugas PKPPS</strong>.</li>
        <li>Hanya santri aktif di tingkatan PKPPS jadwal tersebut yang melihat tugas.</li>
        <li>Nilai otomatis (PG + esai) dapat dilihat di menu Penilaian; kriteria di <a href="<?= htmlspecialchars(app_href('/settings/ikhtibar_kriteria.php')) ?>">Pengaturan kriteria</a>.</li>
    </ol>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Judul</th>
                        <th>Jadwal PKPPS</th>
                        <th>Tanggal</th>
                        <th>Durasi</th>
                        <th>Soal</th>
                        <th>Status</th>
                        <th>Selesai</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada tugas PKPPS.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $id = (int) $r['id'];
                    $st = (string) ($r['status'] ?? 'draft');
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['judul']) ?></td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($r['mapel_label'] ?? '')) !== '' ? (string) $r['mapel_label'] : '—') ?></td>
                        <td class="small text-nowrap"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                        <td><?= (int) ($r['durasi_menit'] ?? 0) > 0 ? (int) $r['durasi_menit'] . ' mnt' : '—' ?></td>
                        <td class="small">PG <?= (int) ($r['jumlah_pg'] ?? 0) ?><br>Esai <?= (int) ($r['jumlah_esai'] ?? 0) ?></td>
                        <td><span class="badge text-bg-<?= $st === 'published' ? 'success' : ($st === 'closed' ? 'secondary' : 'warning') ?>"><?= htmlspecialchars($st) ?></span></td>
                        <td><?= (int) ($r['jumlah_selesai'] ?? 0) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href(pkpps_tugas_base_path() . '/buat.php?id=' . $id)) ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href(pkpps_tugas_base_path() . '/nilai.php?tugas_id=' . $id)) ?>">Nilai</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus tugas PKPPS ini?');">
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
