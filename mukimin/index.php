<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';

ensure_mukimin_portal_columns($pdo);

if (!isset($_SESSION['mukimin']['alumni_id'])) {
    header('Location: /mukimin/login.php');
    exit;
}

$alumniId = (int) $_SESSION['mukimin']['alumni_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($nama === '') {
            set_flash('error', 'Nama wajib diisi.');
        } else {
            $params = [
                'id' => $alumniId,
                'nama' => mb_substr($nama, 0, 200),
                'dusun' => mb_substr(trim((string) ($_POST['dusun'] ?? '')), 0, 120) ?: null,
                'rt_rw' => mb_substr(trim((string) ($_POST['rt_rw'] ?? '')), 0, 20) ?: null,
                'desa_kelurahan' => mb_substr(trim((string) ($_POST['desa_kelurahan'] ?? '')), 0, 120) ?: null,
                'kecamatan' => mb_substr(trim((string) ($_POST['kecamatan'] ?? '')), 0, 120) ?: null,
                'kabupaten' => mb_substr(trim((string) ($_POST['kabupaten'] ?? '')), 0, 120) ?: null,
                'propinsi' => mb_substr(trim((string) ($_POST['propinsi'] ?? '')), 0, 120) ?: null,
                'th_masuk' => alumni_parse_year_cell(trim((string) ($_POST['th_masuk'] ?? ''))),
                'th_keluar' => alumni_parse_year_cell(trim((string) ($_POST['th_keluar'] ?? ''))),
                'keterangan' => trim((string) ($_POST['keterangan'] ?? '')) ?: null,
                'sektor' => mb_substr(trim((string) ($_POST['sektor'] ?? '')), 0, 120) ?: null,
            ];
            $pdo->prepare('
                UPDATE akademik_alumni SET
                    nama = :nama, dusun = :dusun, rt_rw = :rt_rw,
                    desa_kelurahan = :desa_kelurahan, kecamatan = :kecamatan,
                    kabupaten = :kabupaten, propinsi = :propinsi,
                    th_masuk = :th_masuk, th_keluar = :th_keluar, keterangan = :keterangan,
                    sektor = :sektor
                WHERE id = :id
            ')->execute($params);
            $_SESSION['mukimin']['nama'] = $params['nama'];
            $_SESSION['mukimin']['sektor'] = (string) ($params['sektor'] ?? '');
            set_flash('success', 'Data berhasil disimpan.');
        }
    }
    header('Location: /mukimin/index.php');
    exit;
}

$st = $pdo->prepare('SELECT * FROM akademik_alumni WHERE id = :id LIMIT 1');
$st->execute(['id' => $alumniId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    unset($_SESSION['mukimin']);
    header('Location: /mukimin/login.php');
    exit;
}

$pageTitle = 'Data Mukimin';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-3" style="max-width:720px">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">Portal Mukimin</h1>
            <p class="text-muted small mb-0">
                <?= htmlspecialchars((string) $row['nama']) ?> · NIS <?= htmlspecialchars((string) $row['nis']) ?>
                <?php if (trim((string) ($row['sektor'] ?? '')) !== ''): ?>
                    · <?= htmlspecialchars((string) $row['sektor']) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary btn-sm" href="/mukimin/unduh.php">Unduh Excel</a>
            <a class="btn btn-outline-secondary btn-sm" href="/mukimin/logout.php">Keluar</a>
        </div>
    </div>
    <?php if ($msg = get_flash('success')): ?>
        <div class="alert alert-success py-2 small"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err = get_flash('error')): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="post" class="d-grid gap-2">
                <input type="hidden" name="action" value="simpan">
                <div>
                    <label class="form-label">NIS</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars((string) $row['nis']) ?>" readonly>
                </div>
                <div>
                    <label class="form-label">Nama</label>
                    <input type="text" name="nama" class="form-control" required maxlength="200" value="<?= htmlspecialchars((string) $row['nama']) ?>">
                </div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Th. masuk</label><input type="number" name="th_masuk" class="form-control" value="<?= htmlspecialchars((string) ($row['th_masuk'] ?? '')) ?>"></div>
                    <div class="col-6"><label class="form-label">Th. keluar</label><input type="number" name="th_keluar" class="form-control" value="<?= htmlspecialchars((string) ($row['th_keluar'] ?? '')) ?>"></div>
                </div>
                <div><label class="form-label">Dusun</label><input type="text" name="dusun" class="form-control" value="<?= htmlspecialchars((string) ($row['dusun'] ?? '')) ?>"></div>
                <div><label class="form-label">RT/RW</label><input type="text" name="rt_rw" class="form-control" value="<?= htmlspecialchars((string) ($row['rt_rw'] ?? '')) ?>"></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Desa/Kel.</label><input type="text" name="desa_kelurahan" class="form-control" value="<?= htmlspecialchars((string) ($row['desa_kelurahan'] ?? '')) ?>"></div>
                    <div class="col-6"><label class="form-label">Kecamatan</label><input type="text" name="kecamatan" class="form-control" value="<?= htmlspecialchars((string) ($row['kecamatan'] ?? '')) ?>"></div>
                </div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Kabupaten</label><input type="text" name="kabupaten" class="form-control" value="<?= htmlspecialchars((string) ($row['kabupaten'] ?? '')) ?>"></div>
                    <div class="col-6"><label class="form-label">Propinsi</label><input type="text" name="propinsi" class="form-control" value="<?= htmlspecialchars((string) ($row['propinsi'] ?? '')) ?>"></div>
                </div>
                <div>
                    <label class="form-label">Sektor</label>
                    <input type="text" name="sektor" class="form-control" maxlength="120" list="mukimin-sektor-suggest"
                        value="<?= htmlspecialchars((string) ($row['sektor'] ?? '')) ?>">
                </div>
                <div><label class="form-label">Keterangan</label><textarea name="keterangan" class="form-control" rows="2"><?= htmlspecialchars((string) ($row['keterangan'] ?? '')) ?></textarea></div>
                <button type="submit" class="btn btn-primary">Simpan perubahan</button>
            </form>
        </div>
    </div>
</div>
<datalist id="mukimin-sektor-suggest">
    <?php foreach (mukimin_portal_sektor_suggest() as $ss): ?>
        <option value="<?= htmlspecialchars($ss) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
