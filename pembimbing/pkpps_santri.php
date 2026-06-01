<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';
require_once __DIR__ . '/../helpers/pkpps.php';

require_roles(['admin', 'pengurus', 'pembimbing']);

pkpps_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$pbInfo = pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pbInfo !== null ? (int) ($pbInfo['id'] ?? 0) : 0;

if (!$bolehSemua && $pembimbingId <= 0) {
    set_flash('error', 'Akun pembimbing tidak dikenali.');
    header('Location: ' . app_href('/pembimbing/dashboard.php'));
    exit;
}
if (!$bolehSemua && !pembimbing_pkpps_has_jadwal($pdo, $pembimbingId)) {
    set_flash('error', 'Anda belum ditetapkan sebagai pembimbing jadwal PKPPS.');
    header('Location: ' . app_href('/pembimbing/dashboard.php'));
    exit;
}

$tingkatanMap = $bolehSemua
    ? array_column(pkpps_tingkatan_list($pdo, true), 'nama_tingkatan', 'id')
    : pembimbing_pkpps_tingkatan_map($pdo, $pembimbingId);
$tingkatanIds = array_map('intval', array_keys($tingkatanMap));

$editId = (int) ($_GET['edit'] ?? 0);
$filterTingkatan = (int) ($_GET['tingkatan'] ?? 0);
if ($filterTingkatan > 0 && !$bolehSemua && !pembimbing_pkpps_can_access_tingkatan($pdo, $pembimbingId, $filterTingkatan)) {
    $filterTingkatan = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'ubah') {
        $rowId = (int) ($_POST['pkpps_santri_id'] ?? 0);
        $tingkatId = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $tahun = (int) ($_POST['tahun_masehi'] ?? 0);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $isAktif = (int) ($_POST['is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($bolehSemua) {
            $stOld = $pdo->prepare('SELECT id FROM pkpps_santri WHERE id = :id LIMIT 1');
            $stOld->execute(['id' => $rowId]);
            if (!$stOld->fetchColumn()) {
                set_flash('error', 'Data tidak ditemukan.');
            } else {
                $pdo->prepare('
                    UPDATE pkpps_santri SET pkpps_tingkatan_id = :tid, tahun_masehi = :th, catatan = :cat, is_aktif = :a WHERE id = :id
                ')->execute([
                    'tid' => $tingkatId,
                    'th' => $tahun > 0 ? $tahun : null,
                    'cat' => mb_substr($catatan, 0, 255),
                    'a' => $isAktif,
                    'id' => $rowId,
                ]);
                set_flash('success', 'Data santri PKPPS diperbarui.');
            }
        } else {
            $res = pembimbing_pkpps_santri_simpan($pdo, $pembimbingId, $rowId, $tingkatId, $tahun > 0 ? $tahun : null, $catatan, $isAktif);
            set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
    }
    $qs = $filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : '';
    header('Location: ' . app_href('/pembimbing/pkpps_santri.php' . $qs));
    exit;
}

$filterIds = $filterTingkatan > 0 ? [$filterTingkatan] : $tingkatanIds;
$rows = $bolehSemua
    ? []
    : pembimbing_pkpps_santri_list($pdo, $pembimbingId, $filterIds, 500);

if ($bolehSemua) {
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = '
        SELECT ps.id AS pkpps_santri_id, ps.santri_id, ps.tahun_masehi, ps.is_aktif, ps.catatan,
               s.' . $nameCol . ' AS nama_santri, s.nis, s.tingkatan AS tingkatan_kajian,
               t.id AS tingkatan_id, t.nama_tingkatan
        FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        WHERE 1=1
    ';
    $params = [];
    if ($filterTingkatan > 0) {
        $sql .= ' AND ps.pkpps_tingkatan_id = :tid';
        $params['tid'] = $filterTingkatan;
    }
    $sql .= ' ORDER BY t.urutan ASC, s.' . $nameCol . ' ASC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['tingkatan'] = pembimbing_pkpps_label((string) ($r['nama_tingkatan'] ?? ''));
    }
    unset($r);
}

$editRow = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int) ($r['pkpps_santri_id'] ?? $r['id'] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
}

$pageTitle = 'Santri PKPPS';
$bodyClass = 'dash-page';
$pageStylesheets = [app_asset_href('/assets/css/pembimbing-dashboard.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php')) ?>">Dashboard</a></p>
    <h1 class="h4 mb-1">Santri PKPPS saya</h1>
    <p class="text-muted small mb-0">Daftar santri pada tingkatan PKPPS yang Anda bimbing. Presensi lewat scan QR seperti biasa.</p>
</div>

<?php if ($editRow !== null): ?>
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header py-2 bg-primary-subtle"><strong>Edit santri PKPPS</strong></div>
    <div class="card-body">
        <p class="small mb-2 fw-semibold"><?= htmlspecialchars((string) ($editRow['nama_santri'] ?? '-')) ?> · NIS <?= htmlspecialchars((string) ($editRow['nis'] ?? '-')) ?></p>
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="ubah">
            <input type="hidden" name="pkpps_santri_id" value="<?= (int) ($editRow['pkpps_santri_id'] ?? $editRow['id'] ?? 0) ?>">
            <div class="col-md-4">
                <label class="form-label small mb-0">Tingkatan PKPPS</label>
                <select name="pkpps_tingkatan_id" class="form-select form-select-sm" required>
                    <?php foreach ($tingkatanMap as $tid => $nama): ?>
                        <option value="<?= (int) $tid ?>" <?= (int) ($editRow['tingkatan_id'] ?? 0) === (int) $tid ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $nama) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Tahun</label>
                <input type="number" name="tahun_masehi" class="form-control form-control-sm" min="2000" max="2100"
                       value="<?= (int) ($editRow['tahun_masehi'] ?? date('Y')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Status</label>
                <select name="is_aktif" class="form-select form-select-sm">
                    <option value="1" <?= (int) ($editRow['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= (int) ($editRow['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label small mb-0">Catatan</label>
                <input type="text" name="catatan" class="form-control form-control-sm" maxlength="255"
                       value="<?= htmlspecialchars((string) ($editRow['catatan'] ?? '')) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/pkpps_santri.php' . ($filterTingkatan > 0 ? '?tingkatan=' . $filterTingkatan : ''))) ?>" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><?= count($rows) ?> santri PKPPS</strong>
        <form method="get" class="m-0">
            <select name="tingkatan" class="form-select form-select-sm" style="max-width:14rem" onchange="this.form.submit()">
                <option value="0">Semua tingkatan saya</option>
                <?php foreach ($tingkatanMap as $tid => $nama): ?>
                    <option value="<?= (int) $tid ?>" <?= $filterTingkatan === (int) $tid ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $nama) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th>Nama</th>
                <th>Tingkatan PKPPS</th>
                <th>Kajian</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada santri PKPPS pada tingkatan Anda.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $rid = (int) ($r['pkpps_santri_id'] ?? $r['id'] ?? 0);
                ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '-')) ?></div>
                            <div class="small text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                        </td>
                        <td class="small"><?= htmlspecialchars((string) ($r['nama_tingkatan'] ?? '')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($r['tingkatan_kajian'] ?? '-')) ?></td>
                        <td><?= (int) ($r['is_aktif'] ?? 0) === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(app_href('/pembimbing/pkpps_santri.php?edit=' . $rid . ($filterTingkatan > 0 ? '&tingkatan=' . $filterTingkatan : ''))) ?>" class="btn btn-outline-primary btn-sm py-0 px-2">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
