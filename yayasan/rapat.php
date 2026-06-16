<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);

$jenisOpsi = yayasan_jenis_rapat_opsi();
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $st->execute(['id' => $editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM yayasan_rapat WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Rapat dan notulen terkait dihapus.');
        }
        header('Location: ' . app_href('/yayasan/rapat.php'));
        exit;
    }

    $judul = trim((string) ($_POST['judul'] ?? ''));
    $tanggal = trim((string) ($_POST['tanggal_rapat'] ?? ''));
    $jenis = strtoupper(trim((string) ($_POST['jenis'] ?? 'RUTIN')));
    if (!in_array($jenis, $jenisOpsi, true)) {
        $jenis = 'RUTIN';
    }
    $status = strtoupper(trim((string) ($_POST['status'] ?? 'DRAFT')));
    if (!in_array($status, ['DRAFT', 'SELESAI'], true)) {
        $status = 'DRAFT';
    }
    if ($judul === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        set_flash('error', 'Judul dan tanggal rapat wajib diisi dengan benar.');
        header('Location: ' . app_href('/yayasan/rapat.php' . ($editId > 0 ? '?edit=' . $editId : '')));
        exit;
    }

    $data = [
        'nomor_rapat' => trim((string) ($_POST['nomor_rapat'] ?? '')) ?: null,
        'judul' => $judul,
        'tanggal_rapat' => $tanggal,
        'waktu_mulai' => trim((string) ($_POST['waktu_mulai'] ?? '')) ?: null,
        'waktu_selesai' => trim((string) ($_POST['waktu_selesai'] ?? '')) ?: null,
        'lokasi' => trim((string) ($_POST['lokasi'] ?? '')) ?: null,
        'jenis' => $jenis,
        'status' => $status,
        'agenda_ringkas' => trim((string) ($_POST['agenda_ringkas'] ?? '')) ?: null,
        'presensi_scan' => ($jenis === 'MUSYAWARAH' || isset($_POST['presensi_scan'])) ? 1 : 0,
    ];

    $undanganItems = [];
    foreach ((array) ($_POST['undangan_yayasan'] ?? []) as $jab) {
        $jab = trim((string) $jab);
        if ($jab !== '') {
            $undanganItems[] = ['jabatan' => $jab, 'kategori' => 'YAYASAN', 'wajib_scan' => 1];
        }
    }
    foreach ((array) ($_POST['undangan_lembaga'] ?? []) as $jab) {
        $jab = trim((string) $jab);
        if ($jab !== '') {
            $undanganItems[] = ['jabatan' => $jab, 'kategori' => 'LEMBAGA', 'wajib_scan' => 1];
        }
    }

    $idPost = (int) ($_POST['id'] ?? 0);
    if ($idPost > 0) {
        $pdo->prepare('
            UPDATE yayasan_rapat
            SET nomor_rapat = :nomor_rapat, judul = :judul, tanggal_rapat = :tanggal_rapat,
                waktu_mulai = :waktu_mulai, waktu_selesai = :waktu_selesai, lokasi = :lokasi,
                jenis = :jenis, status = :status, agenda_ringkas = :agenda_ringkas, presensi_scan = :presensi_scan
            WHERE id = :id
        ')->execute($data + ['id' => $idPost]);
        yayasan_rapat_simpan_undangan($pdo, $idPost, $undanganItems);
        set_flash('success', 'Data rapat diperbarui.');
        header('Location: ' . app_href('/yayasan/rapat.php'));
        exit;
    }

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    $pdo->prepare('
        INSERT INTO yayasan_rapat (nomor_rapat, judul, tanggal_rapat, waktu_mulai, waktu_selesai, lokasi, jenis, status, agenda_ringkas, presensi_scan, created_by)
        VALUES (:nomor_rapat, :judul, :tanggal_rapat, :waktu_mulai, :waktu_selesai, :lokasi, :jenis, :status, :agenda_ringkas, :presensi_scan, :created_by)
    ')->execute($data + ['created_by' => $uid > 0 ? $uid : null]);
    $newId = (int) $pdo->lastInsertId();
    yayasan_rapat_simpan_undangan($pdo, $newId, $undanganItems);
    set_flash('success', 'Rapat ditambahkan. Anda dapat mengisi notulen atau presensi musyawarah.');
    if ($data['presensi_scan'] === 1) {
        header('Location: ' . app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $newId));
    } else {
        header('Location: ' . app_href('/yayasan/notulen.php?rapat_id=' . $newId));
    }
    exit;
}

$rows = $pdo->query('
    SELECT r.*,
           (SELECT COUNT(*) FROM yayasan_notulen n WHERE n.rapat_id = r.id) AS jumlah_notulen,
           (SELECT COUNT(*) FROM presensi_musyawarah pm WHERE pm.rapat_id = r.id AND pm.status = "HADIR") AS jumlah_hadir
    FROM yayasan_rapat r
    ORDER BY r.tanggal_rapat DESC, r.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$editUndanganYayasan = [];
$editUndanganLembaga = [];
if ($editRow) {
    foreach (yayasan_rapat_undangan_list($pdo, (int) $editRow['id']) as $u) {
        $kat = strtoupper((string) ($u['kategori'] ?? 'YAYASAN'));
        $jab = (string) ($u['jabatan'] ?? '');
        if ($kat === 'LEMBAGA') {
            $editUndanganLembaga[] = $jab;
        } else {
            $editUndanganYayasan[] = $jab;
        }
    }
}
$jabatanYayasanOpsi = yayasan_sdm_jabatan_saran($pdo, 'YAYASAN');
$jabatanLembagaOpsi = yayasan_sdm_jabatan_saran($pdo, 'LEMBAGA');
foreach ($editUndanganYayasan as $j) {
    if ($j !== '' && !in_array($j, $jabatanYayasanOpsi, true)) {
        $jabatanYayasanOpsi[] = $j;
    }
}
foreach ($editUndanganLembaga as $j) {
    if ($j !== '' && !in_array($j, $jabatanLembagaOpsi, true)) {
        $jabatanLembagaOpsi[] = $j;
    }
}

$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$pageTitle = 'Rapat Yayasan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · Rapat</p>
    <h1 class="h4 mb-1">Rapat yayasan</h1>
    <p class="text-muted mb-0">Jadwalkan rapat &amp; musyawarah — centang jabatan wajib scan presensi.</p>
    <p class="small mb-0 mt-1"><a href="<?= htmlspecialchars(app_href('/yayasan/sdm.php')) ?>">Kelola data SDM yayasan &amp; lembaga</a></p>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Ubah rapat' : 'Tambah rapat' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted"><a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">← Batal edit</a></p>
                <?php endif; ?>
                <form method="post" class="row g-2">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nomor rapat</label>
                        <input class="form-control" name="nomor_rapat" placeholder="Opsional" value="<?= htmlspecialchars((string) ($editRow['nomor_rapat'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Judul</label>
                        <input class="form-control" name="judul" required value="<?= htmlspecialchars((string) ($editRow['judul'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal_rapat" required value="<?= htmlspecialchars((string) ($editRow['tanggal_rapat'] ?? date('Y-m-d'))) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Mulai</label>
                        <input type="time" class="form-control" name="waktu_mulai" value="<?= htmlspecialchars(substr((string) ($editRow['waktu_mulai'] ?? ''), 0, 5)) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Selesai</label>
                        <input type="time" class="form-control" name="waktu_selesai" value="<?= htmlspecialchars(substr((string) ($editRow['waktu_selesai'] ?? ''), 0, 5)) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Lokasi</label>
                        <input class="form-control" name="lokasi" value="<?= htmlspecialchars((string) ($editRow['lokasi'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Jenis</label>
                        <select class="form-select" name="jenis">
                            <?php foreach ($jenisOpsi as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>" <?= strtoupper((string) ($editRow['jenis'] ?? 'RUTIN')) === $j ? 'selected' : '' ?>><?= htmlspecialchars(yayasan_label_jenis_rapat($j)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Status</label>
                        <select class="form-select" name="status">
                            <option value="DRAFT" <?= ($editRow['status'] ?? 'DRAFT') === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                            <option value="SELESAI" <?= ($editRow['status'] ?? '') === 'SELESAI' ? 'selected' : '' ?>>Selesai</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Agenda ringkas</label>
                        <textarea class="form-control" name="agenda_ringkas" rows="3"><?= htmlspecialchars((string) ($editRow['agenda_ringkas'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="presensi_scan" id="presensiScan" value="1"
                                <?= ($editRow && (int) ($editRow['presensi_scan'] ?? 0) === 1) || strtoupper((string) ($editRow['jenis'] ?? '')) === 'MUSYAWARAH' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="presensiScan">Aktifkan presensi scan (musyawarah)</label>
                        </div>
                    </div>
                    <div class="col-12" id="undanganJabatanBlock">
                        <label class="form-label small mb-1 fw-semibold">Jabatan diundang — wajib scan</label>
                        <div class="border rounded p-2 mb-2 bg-light">
                            <div class="small fw-semibold text-primary mb-1">Kepengurusan Yayasan</div>
                            <div class="row g-1">
                                <?php foreach ($jabatanYayasanOpsi as $j): ?>
                                    <div class="col-6">
                                        <div class="form-check form-check-sm">
                                            <input class="form-check-input" type="checkbox" name="undangan_yayasan[]" value="<?= htmlspecialchars($j) ?>" id="uy_<?= md5($j) ?>"
                                                <?= in_array($j, $editUndanganYayasan, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="uy_<?= md5($j) ?>"><?= htmlspecialchars($j) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="border rounded p-2 bg-light">
                            <div class="small fw-semibold text-info mb-1">Lembaga</div>
                            <div class="row g-1">
                                <?php foreach ($jabatanLembagaOpsi as $j): ?>
                                    <div class="col-6">
                                        <div class="form-check form-check-sm">
                                            <input class="form-check-input" type="checkbox" name="undangan_lembaga[]" value="<?= htmlspecialchars($j) ?>" id="ul_<?= md5($j) ?>"
                                                <?= in_array($j, $editUndanganLembaga, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="ul_<?= md5($j) ?>"><?= htmlspecialchars($j) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success w-100"><?= $editRow ? 'Simpan' : 'Tambah rapat' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar rapat</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Judul</th>
                                <th>Jenis</th>
                                <th>Presensi</th>
                                <th>Notulen</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="text-muted text-center py-4">Belum ada rapat.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="text-nowrap small">
                                        <?= htmlspecialchars(yayasan_format_tanggal_rapat(
                                            (string) $row['tanggal_rapat'],
                                            $row['waktu_mulai'] !== null ? (string) $row['waktu_mulai'] : null,
                                            $row['waktu_selesai'] !== null ? (string) $row['waktu_selesai'] : null
                                        )) ?>
                                        <?php if (!empty($row['nomor_rapat'])): ?>
                                            <br><span class="text-muted"><?= htmlspecialchars((string) $row['nomor_rapat']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['judul']) ?></strong>
                                        <?php if (!empty($row['lokasi'])): ?>
                                            <br><span class="small text-muted"><?= htmlspecialchars((string) $row['lokasi']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= htmlspecialchars(yayasan_label_jenis_rapat((string) $row['jenis'])) ?>
                                        <br>
                                        <span class="badge text-bg-<?= ($row['status'] ?? '') === 'SELESAI' ? 'success' : 'secondary' ?>"><?= ($row['status'] ?? 'DRAFT') === 'SELESAI' ? 'Selesai' : 'Draft' ?></span>
                                    </td>
                                    <td>
                                        <?php if ((int) ($row['presensi_scan'] ?? 0) === 1): ?>
                                            <span class="badge text-bg-success"><?= (int) ($row['jumlah_hadir'] ?? 0) ?> hadir</span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $jn = (int) ($row['jumlah_notulen'] ?? 0); ?>
                                        <?php if ($jn > 0): ?>
                                            <span class="badge text-bg-primary"><?= $jn ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ((int) ($row['presensi_scan'] ?? 0) === 1): ?>
                                            <a class="btn btn-sm btn-outline-info" href="<?= htmlspecialchars(app_href('/yayasan/scan_musyawarah.php?rapat_id=' . (int) $row['id'])) ?>">Scan</a>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . (int) $row['id'])) ?>">Presensi</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href('/yayasan/notulen.php?rapat_id=' . (int) $row['id'])) ?>">Notulen</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/yayasan/rapat.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus rapat dan notulen terkait?');">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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
</div>

<script>
(function () {
    var jenis = document.querySelector('select[name="jenis"]');
    var presensi = document.getElementById('presensiScan');
    function sync() {
        if (!jenis || !presensi) return;
        if (jenis.value === 'MUSYAWARAH') {
            presensi.checked = true;
        }
    }
    if (jenis) jenis.addEventListener('change', sync);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
