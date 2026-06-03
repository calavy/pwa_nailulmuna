<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';

require_roles(['admin', 'pengurus']);
if (!user_can_hapus_presensi_admin()) {
    set_flash('error', 'Hanya admin / super admin yang dapat menghapus presensi bermasalah.');
    header('Location: ' . app_href('/presensi/scan.php'));
    exit;
}

ensure_presensi_jadwal_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus_presensi') {
        $ids = array_map('intval', (array) ($_POST['presensi_ids'] ?? []));
        $n = presensi_hapus_by_ids($pdo, $ids);
        set_flash('success', $n . ' baris presensi dihapus (termasuk poin otomatis terkait).');
    }
    header('Location: ' . app_href('/presensi/bersihkan.php'));
    exit;
}

$rows = presensi_list_tanpa_kegiatan($pdo, 300);

$pageTitle = 'Bersihkan presensi tanpa kegiatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">Presensi</a></p>
    <h1 class="h4 mb-1">Presensi tanpa nama kegiatan</h1>
    <p class="text-muted mb-0 small">
        Daftar presensi <strong>Hadir</strong> atau <strong>Alpa</strong> yang tidak punya kegiatan valid (kosong / kegiatan sudah tidak ada).
        Hapus baris yang salah input. Saat <strong>jadwal dihapus</strong>, presensi terikat jadwal ikut terhapus otomatis.
        <?php if (is_super_admin()): ?>
            · <a href="<?= htmlspecialchars(app_href('/settings/presensi_data.php')) ?>">Kelola / unduh data presensi (rentang tanggal)</a>
        <?php endif; ?>
    </p>
</div>

<?php if ($rows === []): ?>
    <div class="alert alert-success">Tidak ada presensi bermasalah yang perlu dibersihkan.</div>
<?php else: ?>
    <form method="post" onsubmit="return confirm('Hapus presensi yang dicentang?');">
        <input type="hidden" name="action" value="hapus_presensi">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="submit" class="btn btn-danger btn-sm">Hapus yang dicentang</button>
            <span class="small text-muted align-self-center"><?= count($rows) ?> baris</span>
        </div>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:2.5rem"><input type="checkbox" id="chk-all-presensi"></th>
                            <th>Tanggal</th>
                            <th>Jam</th>
                            <th>Status</th>
                            <th>Santri</th>
                            <th>Tingkatan</th>
                            <th>Kegiatan (ID)</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><input type="checkbox" name="presensi_ids[]" value="<?= (int) $r['id'] ?>" class="chk-presensi-row"></td>
                            <td class="text-nowrap small"><?= htmlspecialchars((string) ($r['tanggal_presensi'] ?? '')) ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars(substr((string) ($r['jam_presensi'] ?? ''), 0, 8)) ?></td>
                            <td><span class="badge text-bg-<?= strtoupper((string) ($r['status_presensi'] ?? '')) === 'ALPA' ? 'danger' : 'success' ?>"><?= htmlspecialchars((string) ($r['status_presensi'] ?? '')) ?></span></td>
                            <td class="small"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?><br><span class="text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></span></td>
                            <td class="small"><?= htmlspecialchars((string) ($r['tingkatan'] ?? '—')) ?></td>
                            <td class="small font-monospace"><?= $r['kegiatan_id'] !== null ? '#' . (int) $r['kegiatan_id'] : '—' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string) ($r['catatan'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <script>
    (function () {
        const all = document.getElementById('chk-all-presensi');
        const rows = document.querySelectorAll('.chk-presensi-row');
        if (!all) return;
        all.addEventListener('change', function () {
            rows.forEach(function (c) { c.checked = all.checked; });
        });
    })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
