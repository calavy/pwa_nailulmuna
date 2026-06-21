<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';

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
    ? $pdo->query('SELECT id, nip, nama_pembimbing, no_wa FROM pembimbing WHERE COALESCE(is_aktif, 1) = 1 ORDER BY ' . pembimbing_list_order_sql(''))->fetchAll(PDO::FETCH_ASSOC) ?: []
    : [];

$filterTingkatan = (int) ($_GET['tingkatan'] ?? 0);
$preselectKegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stEdit = $pdo->prepare('
        SELECT j.*, t.nama_tingkatan, k.nama_kegiatan, p.nama_pembimbing, p.nip, p.no_wa
        FROM pkpps_jadwal j
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
        WHERE j.id = :id
        LIMIT 1
    ');
    $stEdit->execute(['id' => $editId]);
    $editRow = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Jadwal PKPPS tidak ditemukan.');
        header('Location: ' . app_href('/pkpps/jadwal.php' . ($filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : '')));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $kartuPembimbingRedirect = 0;
    if ($action === 'tambah_kegiatan') {
        ensure_kegiatan_kategori_column($pdo);
        $namaKegiatan = mb_substr(trim((string) ($_POST['nama_kegiatan'] ?? '')), 0, 120);
        $kategoriKegiatan = pkpps_normalize_kegiatan_kategori((string) ($_POST['kategori_kegiatan'] ?? ''), true);
        if ($namaKegiatan === '') {
            set_flash('error', 'Nama kegiatan wajib diisi.');
        } else {
            $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:nama, :kat, 1)')
                ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan]);
            $preselectKegiatanId = (int) $pdo->lastInsertId();
            set_flash('success', 'Kegiatan "' . $namaKegiatan . '" ditambahkan. Silakan lengkapi jadwal PKPPS.');
        }
        $qs = [];
        if ($filterTingkatan > 0) {
            $qs['tingkatan'] = (string) $filterTingkatan;
        }
        if ($preselectKegiatanId > 0) {
            $qs['kegiatan_id'] = (string) $preselectKegiatanId;
        }
        header('Location: ' . app_href('/pkpps/jadwal.php' . ($qs !== [] ? '?' . http_build_query($qs) : '')));
        exit;
    }
    if ($action === 'tambah') {
        $tingkatIdsRaw = $_POST['pkpps_tingkatan_ids'] ?? [];
        if (!is_array($tingkatIdsRaw)) {
            $single = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
            $tingkatIdsRaw = $single > 0 ? [$single] : [];
        }
        $tingkatIds = [];
        foreach ($tingkatIdsRaw as $raw) {
            $tid = (int) $raw;
            if ($tid > 0) {
                $tingkatIds[] = $tid;
            }
        }
        $tingkatIds = array_values(array_unique($tingkatIds));
        $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
        $hariKe = (int) ($_POST['hari_ke'] ?? 0);
        $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
        $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
        if ($tingkatIds === [] || $kegiatanId <= 0 || $jamMulai === '' || $jamSelesai === '') {
            set_flash('error', 'Pilih minimal satu tingkatan PKPPS, kegiatan, dan jam.');
        } else {
            $pembimbingId = (int) ($_POST['pembimbing_id'] ?? 0) ?: null;
            $ins = $pdo->prepare('
                INSERT INTO pkpps_jadwal (pkpps_tingkatan_id, kegiatan_id, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat, is_aktif)
                VALUES (:tid, :kid, :hk, :jm, :js, :pid, :tp, 1)
            ');
            $tempat = trim((string) ($_POST['tempat'] ?? '')) ?: null;
            $hk = max(0, min(7, $hariKe));
            foreach ($tingkatIds as $tingkatId) {
                $ins->execute([
                    'tid' => $tingkatId,
                    'kid' => $kegiatanId,
                    'hk' => $hk,
                    'jm' => $jamMulai,
                    'js' => $jamSelesai,
                    'pid' => $pembimbingId,
                    'tp' => $tempat,
                ]);
            }
            $n = count($tingkatIds);
            if ($pembimbingId !== null && $pembimbingId > 0) {
                set_flash('success', "Jadwal PKPPS ditambahkan untuk {$n} tingkatan. Pembimbing sudah bisa dicetak kartunya.");
                $kartuPembimbingRedirect = $pembimbingId;
            } else {
                set_flash('success', "Jadwal PKPPS ditambahkan untuk {$n} tingkatan.");
            }
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
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
        $hariKe = max(0, min(7, (int) ($_POST['hari_ke'] ?? 0)));
        $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
        $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
        $pembimbingId = (int) ($_POST['pembimbing_id'] ?? 0) ?: null;
        $tempat = trim((string) ($_POST['tempat'] ?? '')) ?: null;
        $noWa = trim((string) ($_POST['no_wa'] ?? ''));
        if ($id <= 0 || $tingkatId <= 0 || $kegiatanId <= 0 || $jamMulai === '' || $jamSelesai === '') {
            set_flash('error', 'Lengkapi tingkatan, kegiatan, dan jam.');
        } else {
            $pdo->prepare('
                UPDATE pkpps_jadwal
                SET pkpps_tingkatan_id = :tid, kegiatan_id = :kid, hari_ke = :hk,
                    jam_mulai = :jm, jam_selesai = :js, pembimbing_id = :pid, tempat = :tp
                WHERE id = :id
            ')->execute([
                'tid' => $tingkatId,
                'kid' => $kegiatanId,
                'hk' => $hariKe,
                'jm' => $jamMulai,
                'js' => $jamSelesai,
                'pid' => $pembimbingId,
                'tp' => $tempat,
                'id' => $id,
            ]);
            if ($pembimbingId !== null && $pembimbingId > 0 && table_exists($pdo, 'pembimbing')) {
                $pdo->prepare('UPDATE pembimbing SET no_wa = :wa WHERE id = :id')
                    ->execute(['wa' => $noWa, 'id' => $pembimbingId]);
            }
            set_flash('success', 'Jadwal PKPPS diperbarui.');
            if ($pembimbingId !== null && $pembimbingId > 0) {
                $kartuPembimbingRedirect = $pembimbingId;
            }
        }
    }
    if (in_array($action, ['tambah', 'hapus', 'toggle', 'update', 'tambah_kegiatan'], true)) {
        pkpps_sync_kegiatan_kategori($pdo);
    }
    $qs = $filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : '';
    if ($kartuPembimbingRedirect > 0) {
        $qs .= ($qs === '' ? '?' : '&') . 'kartu=' . $kartuPembimbingRedirect;
    }
    header('Location: ' . app_href('/pkpps/jadwal.php' . $qs));
    exit;
}

$sql = '
    SELECT j.id, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, j.is_aktif, j.pembimbing_id,
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

$flashKartuPembimbingId = (int) ($_GET['kartu'] ?? 0);

$pageTitle = 'Jadwal PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Jadwal Khusus PKPPS</h1>
    <p class="text-muted small mb-0">
        Jadwal per tingkatan PKPPS. Presensi lewat
        <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">scan utama</a>
        (santri PKPPS & pembimbing PKPPS).
    </p>
</div>

<?php if ($flashKartuPembimbingId > 0): ?>
    <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small mb-0">Pembimbing sudah tertaut ke jadwal PKPPS — siap cetak kartu QR.</span>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/kartu.php?id=' . $flashKartuPembimbingId)) ?>" class="btn btn-success btn-sm">
            <i class="fa-solid fa-id-card me-1"></i> Cetak kartu pembimbing
        </a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong><?= $editRow ? 'Edit jadwal #' . (int) $editId : 'Tambah jadwal' ?></strong>
                <?php if ($editRow): ?>
                    <a href="<?= htmlspecialchars(app_href('/pkpps/jadwal.php' . ($filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : ''))) ?>" class="btn btn-outline-secondary btn-sm py-0">Batal</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <details class="mb-3">
                    <summary class="small text-primary fw-semibold" style="cursor:pointer">+ Tambah kegiatan baru</summary>
                    <form method="post" class="border rounded p-2 mt-2 bg-light">
                        <input type="hidden" name="action" value="tambah_kegiatan">
                        <div class="mb-2">
                            <label class="form-label small mb-0">Nama kegiatan</label>
                            <input type="text" name="nama_kegiatan" class="form-control form-control-sm" maxlength="120" required placeholder="Contoh: Muadalah A">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-0">Kategori</label>
                            <select name="kategori_kegiatan" class="form-select form-select-sm">
                                <option value="PKPPS" selected>PKPPS</option>
                                <option value="TAALIM">Ta'lim</option>
                                <option value="JAMAAH">Jama'ah</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">Simpan kegiatan</button>
                    </form>
                </details>
                <?php if ($editRow): ?>
                <form method="post">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $editId ?>">
                    <div class="mb-2">
                        <label class="form-label small mb-0">Tingkatan PKPPS</label>
                        <select name="pkpps_tingkatan_id" class="form-select form-select-sm" required>
                            <?php foreach ($tingkatanList as $t):
                                $tid = (int) ($t['id'] ?? 0);
                            ?>
                                <option value="<?= $tid ?>" <?= $tid === (int) ($editRow['pkpps_tingkatan_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Kegiatan</label>
                        <select name="kegiatan_id" class="form-select form-select-sm" required>
                            <?php foreach ($kegiatanList as $k):
                                $kid = (int) ($k['id'] ?? 0);
                            ?>
                                <option value="<?= $kid ?>" <?= $kid === (int) ($editRow['kegiatan_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($k['nama_kegiatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Hari</label>
                        <select name="hari_ke" class="form-select form-select-sm">
                            <?php foreach ($hari as $hk => $label): ?>
                                <option value="<?= (int) $hk ?>" <?= (int) ($editRow['hari_ke'] ?? 0) === (int) $hk ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam mulai</label>
                            <input type="time" name="jam_mulai" class="form-control form-control-sm" required
                                   value="<?= htmlspecialchars(substr((string) ($editRow['jam_mulai'] ?? ''), 0, 5)) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam selesai</label>
                            <input type="time" name="jam_selesai" class="form-control form-control-sm" required
                                   value="<?= htmlspecialchars(substr((string) ($editRow['jam_selesai'] ?? ''), 0, 5)) ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Pembimbing PKPPS</label>
                        <select name="pembimbing_id" class="form-select form-select-sm" id="pkpps-edit-pembimbing">
                            <option value="">— Tanpa pembimbing —</option>
                            <?php foreach ($pembimbingList as $p):
                                $pid = (int) ($p['id'] ?? 0);
                            ?>
                                <option value="<?= $pid ?>" data-wa="<?= htmlspecialchars((string) ($p['no_wa'] ?? '')) ?>"
                                    <?= $pid === (int) ($editRow['pembimbing_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($p['nama_pembimbing'] ?? '')) ?>
                                    <?php if (trim((string) ($p['nip'] ?? '')) !== ''): ?> (<?= htmlspecialchars((string) $p['nip']) ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">No. WhatsApp pembimbing</label>
                        <input type="text" name="no_wa" id="pkpps-edit-wa" class="form-control form-control-sm"
                               maxlength="30" placeholder="08xxxxxxxxxx"
                               value="<?= htmlspecialchars((string) ($editRow['no_wa'] ?? '')) ?>">
                        <div class="form-text">
                            Disimpan ke profil pembimbing.
                            <?php if ((int) ($editRow['pembimbing_id'] ?? 0) > 0): ?>
                                <a href="<?= htmlspecialchars(app_href('/pembimbing/edit.php?id=' . (int) $editRow['pembimbing_id'])) ?>">Profil lengkap</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-0">Tempat</label>
                        <input type="text" name="tempat" class="form-control form-control-sm" maxlength="255"
                               value="<?= htmlspecialchars((string) ($editRow['tempat'] ?? '')) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Simpan perubahan</button>
                </form>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="tambah">
                    <div class="mb-2">
                        <label class="form-label small mb-1">Tingkatan PKPPS <span class="text-muted">(bisa lebih dari satu)</span></label>
                        <div class="border rounded p-2 bg-light" style="max-height:10rem;overflow-y:auto">
                            <?php foreach ($tingkatanList as $t): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="pkpps_tingkatan_ids[]"
                                           value="<?= (int) ($t['id'] ?? 0) ?>" id="tk_<?= (int) ($t['id'] ?? 0) ?>">
                                    <label class="form-check-label small" for="tk_<?= (int) ($t['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($t['nama_tingkatan'] ?? '')) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Kegiatan</label>
                        <select name="kegiatan_id" class="form-select form-select-sm" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($kegiatanList as $k):
                                $kid = (int) ($k['id'] ?? 0);
                            ?>
                                <option value="<?= $kid ?>"<?= $preselectKegiatanId === $kid ? ' selected' : '' ?>><?= htmlspecialchars((string) ($k['nama_kegiatan'] ?? '')) ?></option>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Daftar jadwal</strong>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="<?= htmlspecialchars(app_href('/pkpps/import.php')) ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fa-solid fa-file-import me-1"></i> Import Excel
                    </a>
                    <form method="get" class="d-flex gap-2 m-0">
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
                                <td class="small">
                                    <?= htmlspecialchars((string) ($r['nama_pembimbing'] ?? '-')) ?>
                                    <?php if (trim((string) ($r['pembimbing_wa'] ?? '')) !== ''): ?>
                                        <div class="text-muted font-monospace"><i class="fa-brands fa-whatsapp text-success me-1"></i><?= htmlspecialchars((string) $r['pembimbing_wa']) ?></div>
                                    <?php endif; ?>
                                    <?php if ((int) ($r['pembimbing_id'] ?? 0) > 0): ?>
                                        <div class="mt-1 d-flex flex-wrap gap-1">
                                            <a href="<?= htmlspecialchars(app_href('/pkpps/jadwal.php?edit=' . (int) ($r['id'] ?? 0) . ($filterTingkatan > 0 ? '&tingkatan=' . $filterTingkatan : ''))) ?>" class="btn btn-outline-primary btn-sm py-0 px-2">Edit</a>
                                            <a href="<?= htmlspecialchars(app_href('/pembimbing/edit.php?id=' . (int) $r['pembimbing_id'])) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2">Profil</a>
                                            <a href="<?= htmlspecialchars(app_href('/pembimbing/kartu.php?id=' . (int) $r['pembimbing_id'])) ?>" class="btn btn-outline-success btn-sm py-0 px-2" title="Cetak kartu">
                                                <i class="fa-solid fa-id-card"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-1">
                                            <a href="<?= htmlspecialchars(app_href('/pkpps/jadwal.php?edit=' . (int) ($r['id'] ?? 0) . ($filterTingkatan > 0 ? '&tingkatan=' . $filterTingkatan : ''))) ?>" class="btn btn-outline-primary btn-sm py-0 px-2">Edit</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
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
    <a href="<?= htmlspecialchars(app_href('/pkpps/import.php')) ?>">Import jadwal Excel</a>
    ·
    <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>">Santri PKPPS</a>
    ·
    <a href="<?= htmlspecialchars(app_href('/settings/pkpps_tingkatan.php')) ?>">Pengaturan tingkatan</a>
</p>

<script>
(function () {
    const sel = document.getElementById('pkpps-edit-pembimbing');
    const wa = document.getElementById('pkpps-edit-wa');
    if (!sel || !wa) {
        return;
    }
    sel.addEventListener('change', function () {
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.wa !== undefined) {
            wa.value = opt.dataset.wa || '';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
