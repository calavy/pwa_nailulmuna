<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pkpps.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);

if (!table_exists($pdo, 'kegiatan')) {
    set_flash('error', 'Tabel kegiatan belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$tingkatanList = pkpps_tingkatan_list($pdo, true);
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE COALESCE(is_active, 1) = 1 ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pembimbingList = table_exists($pdo, 'pembimbing')
    ? $pdo->query('SELECT id, nama_pembimbing FROM pembimbing WHERE COALESCE(is_aktif, 1) = 1 ORDER BY nama_pembimbing ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    : [];

$filterTingkatan = (int) ($_GET['tingkatan'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'tambah') {
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
        $hariKe = (int) ($_POST['hari_ke'] ?? 0);
        $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
        $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
        if ($tingkatId <= 0 || $kegiatanId <= 0 || $jamMulai === '' || $jamSelesai === '') {
            set_flash('error', 'Lengkapi tingkatan PKPPS, kegiatan, dan jam.');
        } else {
            $pdo->prepare('
                INSERT INTO pkpps_jadwal (pkpps_tingkatan_id, kegiatan_id, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat, is_aktif)
                VALUES (:tid, :kid, :hk, :jm, :js, :pid, :tp, 1)
            ')->execute([
                'tid' => $tingkatId,
                'kid' => $kegiatanId,
                'hk' => max(0, min(7, $hariKe)),
                'jm' => $jamMulai,
                'js' => $jamSelesai,
                'pid' => (int) ($_POST['pembimbing_id'] ?? 0) ?: null,
                'tp' => trim((string) ($_POST['tempat'] ?? '')) ?: null,
            ]);
            set_flash('success', 'Jadwal PKPPS ditambahkan.');
        }
    } elseif ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM pkpps_jadwal WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Jadwal PKPPS dihapus.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $aktif = (int) ($_POST['is_aktif'] ?? 0) === 1 ? 1 : 0;
        if ($id > 0) {
            $pdo->prepare('UPDATE pkpps_jadwal SET is_aktif = :a WHERE id = :id')->execute(['a' => $aktif, 'id' => $id]);
            set_flash('success', 'Status jadwal diperbarui.');
        }
    }
    $qs = $filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : '';
    header('Location: ' . app_href('/pkpps/jadwal.php' . $qs));
    exit;
}

$sql = '
    SELECT j.id, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, j.is_aktif,
           t.nama_tingkatan, t.urutan,
           k.nama_kegiatan,
           COALESCE(p.nama_pembimbing, \'-\') AS nama_pembimbing
    FROM pkpps_jadwal j
    INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
    INNER JOIN kegiatan k ON k.id = j.kegiatan_id
    LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
    WHERE 1=1
';
$params = [];
if ($filterTingkatan > 0) {
    $sql .= ' AND j.pkpps_tingkatan_id = :tid';
    $params['tid'] = $filterTingkatan;
}
$sql .= ' ORDER BY t.urutan ASC, j.hari_ke ASC, j.jam_mulai ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$jadwalRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Jadwal PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Jadwal Khusus PKPPS</h1>
    <p class="text-muted small mb-0">
        Jadwal per tingkatan PKPPS. Presensi tetap lewat
        <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">scan utama</a>.
    </p>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Tambah jadwal</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="tambah">
                    <div class="mb-2">
                        <label class="form-label small mb-0">Tingkatan PKPPS</label>
                        <select name="pkpps_tingkatan_id" class="form-select form-select-sm" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($tingkatanList as $t): ?>
                                <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= $filterTingkatan === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Kegiatan</label>
                        <select name="kegiatan_id" class="form-select form-select-sm" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($kegiatanList as $k): ?>
                                <option value="<?= (int) ($k['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($k['nama_kegiatan'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Hari</label>
                        <select name="hari_ke" class="form-select form-select-sm">
                            <?php foreach ($hari as $hk => $label): ?>
                                <option value="<?= (int) $hk ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam mulai</label>
                            <input type="time" name="jam_mulai" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam selesai</label>
                            <input type="time" name="jam_selesai" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Pembimbing (opsional)</label>
                        <select name="pembimbing_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) ($p['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($p['nama_pembimbing'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-0">Tempat</label>
                        <input type="text" name="tempat" class="form-control form-control-sm" maxlength="255">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Simpan jadwal</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Daftar jadwal</strong>
                <form method="get" class="d-flex gap-2">
                    <select name="tingkatan" class="form-select form-select-sm" style="max-width:12rem" onchange="this.form.submit()">
                        <option value="0">Semua tingkatan</option>
                        <?php foreach ($tingkatanList as $t): ?>
                            <option value="<?= (int) ($t['id'] ?? 0) ?>" <?= $filterTingkatan === (int) ($t['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Tingkatan</th>
                        <th>Kegiatan</th>
                        <th>Hari / Jam</th>
                        <th>Pembimbing</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($jadwalRows === []): ?>
                        <tr><td colspan="5" class="text-muted text-center py-4">Belum ada jadwal PKPPS.</td></tr>
                    <?php else: ?>
                        <?php foreach ($jadwalRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($r['nama_tingkatan'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['nama_kegiatan'] ?? '')) ?></td>
                                <td class="small">
                                    <?= htmlspecialchars($hari[(int) ($r['hari_ke'] ?? 0)] ?? '-') ?><br>
                                    <?= htmlspecialchars(substr((string) ($r['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?>
                                    <?php if (trim((string) ($r['tempat'] ?? '')) !== ''): ?>
                                        <br><span class="text-muted"><?= htmlspecialchars((string) $r['tempat']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars((string) ($r['nama_pembimbing'] ?? '-')) ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <input type="hidden" name="is_aktif" value="<?= (int) ($r['is_aktif'] ?? 0) === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Aktif/nonaktif">
                                            <?= (int) ($r['is_aktif'] ?? 0) === 1 ? 'Aktif' : 'Off' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus jadwal ini?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
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

<p class="small text-muted mt-3 mb-0">
    <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>">Santri PKPPS</a>
    ·
    <a href="<?= htmlspecialchars(app_href('/settings/pkpps_tingkatan.php')) ?>">Pengaturan tingkatan</a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
