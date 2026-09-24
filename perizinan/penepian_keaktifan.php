<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_penepian_keaktifan.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_status.php';

require_manage_santri_penepian();

santri_penepian_keaktifan_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$filter = trim((string) ($_GET['filter'] ?? 'aktif'));
if (!in_array($filter, ['aktif', 'selesai', 'batal', 'semua'], true)) {
    $filter = 'aktif';
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);
$presetSantriId = (int) ($_GET['santri_id'] ?? 0);

$redirect = static function (array $qs = []) use ($filter, $tingkatan): void {
    $qs = array_merge(['filter' => $filter], $qs);
    if ($tingkatan !== '') {
        $qs['tingkatan'] = $tingkatan;
    }
    header('Location: ' . app_href('/perizinan/penepian_keaktifan.php?' . http_build_query($qs)));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_can_manage_santri_penepian()) {
        set_flash('error', 'Penepian keaktifan hanya dapat diubah oleh super admin dan pengasuh.');
        auth_redirect_access_denied();
    }
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan') {
        $idPost = (int) ($_POST['id'] ?? 0);
        $sid = (int) ($_POST['santri_id'] ?? 0);
        $t1 = (string) ($_POST['tanggal_mulai'] ?? '');
        $alasan = (string) ($_POST['alasan'] ?? '');
        $cat = (string) ($_POST['catatan_internal'] ?? '');
        if ($idPost > 0) {
            $res = santri_penepian_update($pdo, $idPost, $t1, $alasan, $cat);
        } else {
            $res = santri_penepian_simpan($pdo, $sid, $t1, $alasan, $cat, $userId);
        }
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        $redirect($res['ok'] && $idPost > 0 ? ['edit' => $idPost] : []);
    }
    if ($action === 'batalkan') {
        $res = santri_penepian_batalkan($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        $redirect([]);
    }
    if ($action === 'selesai_hari_ini') {
        $res = santri_penepian_selesai_hari_ini($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        $redirect([]);
    }
}

$listFilter = $filter === 'semua' ? 'aktif' : $filter;
if ($filter === 'semua') {
    $rows = array_merge(
        santri_penepian_list($pdo, 'aktif', $tingkatan !== '' ? $tingkatan : null, 80),
        santri_penepian_list($pdo, 'selesai', $tingkatan !== '' ? $tingkatan : null, 80),
        santri_penepian_list($pdo, 'batal', $tingkatan !== '' ? $tingkatan : null, 40)
    );
} else {
    $rows = santri_penepian_list($pdo, $listFilter, $tingkatan !== '' ? $tingkatan : null, 120);
}

$editRow = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
    if ($editRow === null) {
        $st = $pdo->prepare('SELECT * FROM santri_penepian_keaktifan WHERE id = :id LIMIT 1');
        $st->execute(['id' => $editId]);
        $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

$santriAktif = [];
if (table_exists($pdo, 'santri')) {
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->query('
        SELECT id, nis, ' . $nameExpr . ' AS nama, tingkatan
        FROM santri
        WHERE ' . santri_sql_aktif_only('santri') . '
        ORDER BY ' . $nameExpr . ' ASC
    ');
    $santriAktif = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];

$formSantriId = (int) ($editRow['santri_id'] ?? $presetSantriId);
$formMulai = (string) ($editRow['tanggal_mulai'] ?? date('Y-m-d'));
$formAlasan = (string) ($editRow['alasan'] ?? '');
$formCat = (string) ($editRow['catatan_internal'] ?? '');

$pageTitle = 'Penepian keaktifan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/perizinan/hub.php')) ?>">Perizinan</a>
    </p>
    <h1 class="h4 mb-1">Penepian keaktifan (luar pondok sementara)</h1>
    <p class="text-muted small mb-0">
        Santri tetap <strong>AKTIF</strong> di data. Presensi dihitung netral (PRESNA) sejak <strong>tanggal mulai</strong> sampai Anda menekan
        <strong>Selesai hari ini</strong> atau <strong>Batalkan</strong>. Tidak perlu mengisi tanggal selesai di muka.
        Jangan dipakai menggantikan izin resmi keluar/sakit/syar'i.
    </p>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <?= $editRow ? 'Edit penepian #' . (int) $editRow['id'] : 'Tambah penepian' ?>
            </div>
            <div class="card-body">
                <form method="post" id="form-penepian">
                    <input type="hidden" name="action" value="simpan">
                    <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label">Santri (AKTIF)</label>
                        <select name="santri_id" class="form-select" required <?= $editRow ? 'disabled' : '' ?>>
                            <option value="">— Pilih —</option>
                            <?php foreach ($santriAktif as $s): ?>
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" <?= $formSantriId === $sid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nama'] ?? '')) ?>
                                    · <?= htmlspecialchars((string) ($s['nis'] ?? '')) ?>
                                    · <?= htmlspecialchars((string) ($s['tingkatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editRow): ?>
                            <input type="hidden" name="santri_id" value="<?= (int) ($editRow['santri_id'] ?? 0) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal mulai menepi</label>
                        <input type="date" name="tanggal_mulai" id="penepian-mulai" class="form-control" required value="<?= htmlspecialchars($formMulai) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alasan (min. 10 karakter)</label>
                        <textarea name="alasan" class="form-control" rows="3" required maxlength="500"><?= htmlspecialchars($formAlasan) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan internal (opsional)</label>
                        <textarea name="catatan_internal" class="form-control" rows="2"><?= htmlspecialchars($formCat) ?></textarea>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <?php if ($editRow): ?>
                            <a href="<?= htmlspecialchars(app_href('/perizinan/penepian_keaktifan.php?' . http_build_query(array_filter(['filter' => $filter, 'tingkatan' => $tingkatan ?: null])))) ?>" class="btn btn-outline-secondary">Batal edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Filter</label>
                        <select name="filter" class="form-select form-select-sm">
                            <?php foreach (['aktif' => 'Sedang menepi', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan', 'semua' => 'Semua'] as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Tingkatan</label>
                        <select name="tingkatan" class="form-select form-select-sm">
                            <option value="">Semua</option>
                            <?php foreach ($tingkatanList as $tk): ?>
                                <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Santri</th>
                            <th>Mulai menepi</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="4" class="text-muted text-center py-4">Belum ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $rid = (int) ($r['id'] ?? 0);
                            $stLbl = santri_penepian_status_label($r);
                            $selesaiVal = $r['tanggal_selesai'] ?? null;
                            $selesaiStr = ($selesaiVal === null || $selesaiVal === '') ? '' : (string) $selesaiVal;
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($r['tingkatan'] ?? '')) ?></div>
                                    <div class="small"><?= htmlspecialchars(mb_strimwidth((string) ($r['alasan'] ?? ''), 0, 80, '…')) ?></div>
                                </td>
                                <td class="text-nowrap small">
                                    <?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?>
                                    <?php if ($selesaiStr !== ''): ?>
                                        <br><span class="text-muted">Selesai: <?= htmlspecialchars($selesaiStr) ?></span>
                                    <?php else: ?>
                                        <br><span class="text-muted">Belum diakhiri</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-light border"><?= htmlspecialchars($stLbl) ?></span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ((int) ($r['is_aktif'] ?? 0) === 1 && $stLbl !== 'Selesai' && $stLbl !== 'Dibatalkan'): ?>
                                        <a class="btn btn-outline-primary btn-sm py-0" href="<?= htmlspecialchars(app_href('/perizinan/penepian_keaktifan.php?edit=' . $rid . '&filter=' . urlencode($filter))) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Akhiri penepian hari ini?');">
                                            <input type="hidden" name="action" value="selesai_hari_ini">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button type="submit" class="btn btn-success btn-sm py-0">Selesai hari ini</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Batalkan penepian? Riwayat tetap tersimpan.');">
                                            <input type="hidden" name="action" value="batalkan">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0">Batalkan</button>
                                        </form>
                                    <?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
