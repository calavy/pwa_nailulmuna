<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_keaktifan_nilai.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_keaktifan_nilai_edit();

ensure_santri_nilai_keaktifan_table($pdo);

$tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}
$q = trim((string) ($_GET['q'] ?? ''));
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $postTahun = (int) ($_POST['tahun'] ?? $tahun);
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    if ($action === 'save_one') {
        $sid = (int) ($_POST['santri_id'] ?? 0);
        $nilai = trim((string) ($_POST['nilai'] ?? ''));
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        if ($nilai === '') {
            santri_keaktifan_nilai_hapus($pdo, $sid, $postTahun);
            set_flash('success', 'Penilaian dihapus (kembali ke perhitungan presensi jika ada).');
        } elseif (santri_keaktifan_nilai_save($pdo, $sid, $postTahun, $nilai, $catatan, $userId)) {
            set_flash('success', 'Nilai keaktifan disimpan.');
        } else {
            set_flash('error', 'Data tidak valid.');
        }
    } elseif ($action === 'save_batch') {
        $nilaiBatch = is_array($_POST['nilai'] ?? null) ? $_POST['nilai'] : [];
        $catatanBatch = is_array($_POST['catatan'] ?? null) ? $_POST['catatan'] : [];
        $saved = 0;
        foreach ($nilaiBatch as $sidRaw => $nilaiRaw) {
            $sid = (int) $sidRaw;
            if ($sid <= 0) {
                continue;
            }
            $nilai = trim((string) $nilaiRaw);
            $cat = trim((string) ($catatanBatch[$sidRaw] ?? ''));
            if ($nilai === '') {
                santri_keaktifan_nilai_hapus($pdo, $sid, $postTahun);
                continue;
            }
            if (santri_keaktifan_nilai_save($pdo, $sid, $postTahun, $nilai, $cat, $userId)) {
                $saved++;
            }
        }
        set_flash('success', $saved > 0 ? "Disimpan untuk {$saved} santri." : 'Tidak ada perubahan.');
    }

    $redir = '/pengasuh/nilai_keaktifan.php?tahun=' . $postTahun;
    if ($q !== '') {
        $redir .= '&q=' . urlencode($q);
    }
    if ($tingkatan !== '') {
        $redir .= '&tingkatan=' . urlencode($tingkatan);
    }
    header('Location: ' . app_href($redir));
    exit;
}

santri_list_sort_mode($_GET['santri_sort'] ?? null);
$orderSql = santri_list_order_sql('santri');
$sql = 'SELECT id, nis, nama_santri, tingkatan FROM santri WHERE 1=1';
$params = [];
if ($tingkatan !== '') {
    $sql .= ' AND tingkatan = :ting';
    $params['ting'] = $tingkatan;
}
if ($q !== '') {
    $sql .= ' AND (nama_santri LIKE :q OR nis LIKE :q2)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}
$sql .= ' ORDER BY ' . $orderSql;
$st = $pdo->prepare($sql);
$st->execute($params);
$santriRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nilaiMap = santri_keaktifan_nilai_map_for_tahun($pdo, $tahun);
$tingkatanList = $pdo->query('SELECT DISTINCT tingkatan FROM santri WHERE tingkatan IS NOT NULL AND tingkatan <> "" ORDER BY tingkatan ASC')
    ->fetchAll(PDO::FETCH_COLUMN) ?: [];

$pageTitle = 'Nilai Keaktifan Santri';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Nilai Keaktifan</h1>
            <p class="text-muted small mb-0">Baik / Sedang / Buruk — penilaian pengasuh pondok per tahun kalender.</p>
        </div>
        <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="btn btn-outline-secondary btn-sm">Dashboard</a>
    </div>

    <?php $flashOk = get_flash('success'); $flashErr = get_flash('error'); ?>
    <?php if ($flashOk): ?><div class="alert alert-success py-2"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label small mb-0">Tahun</label>
            <input type="number" name="tahun" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $tahun ?>" style="width:6rem">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0">Tingkatan</label>
            <select name="tingkatan" class="form-select form-select-sm">
                <option value="">Semua</option>
                <?php foreach ($tingkatanList as $tk): ?>
                    <option value="<?= htmlspecialchars((string) $tk) ?>"<?= $tingkatan === (string) $tk ? ' selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Cari nama / NIS</label>
            <input type="search" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik lalu Enter">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
        </div>
        <div class="col-auto ms-md-auto">
            <?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>
        </div>
    </form>

    <form method="post">
        <input type="hidden" name="action" value="save_batch">
        <input type="hidden" name="tahun" value="<?= (int) $tahun ?>">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <strong>Tahun <?= (int) $tahun ?></strong>
                <button type="submit" class="btn btn-success btn-sm">Simpan semua perubahan</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">NIS</th>
                            <th>Nama</th>
                            <th>Tingkatan</th>
                            <th style="min-width:8rem">Nilai</th>
                            <th>Catatan (opsional)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($santriRows as $sr):
                        $sid = (int) $sr['id'];
                        $cur = $nilaiMap[$sid] ?? null;
                    ?>
                        <tr>
                            <td class="ps-3 text-muted small"><?= htmlspecialchars((string) $sr['nis']) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars((string) $sr['nama_santri']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($sr['tingkatan'] ?? '—')) ?></td>
                            <td>
                                <select name="nilai[<?= $sid ?>]" class="form-select form-select-sm">
                                    <option value="">— Otomatis presensi —</option>
                                    <?php foreach (santri_keaktifan_nilai_pilihan_form() as $kode => $lbl): ?>
                                        <option value="<?= htmlspecialchars($kode) ?>"<?= $cur && (string) $cur['nilai'] === $kode ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="catatan[<?= $sid ?>]" class="form-control form-control-sm"
                                       maxlength="500" value="<?= htmlspecialchars($cur['catatan'] ?? '') ?>" placeholder="Catatan singkat">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($santriRows === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada santri.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <p class="small text-muted mt-2 mb-0">
        Kosongkan pilihan nilai untuk menghapus penilaian pengasuh; santri akan melihat nilai dari presensi (jika ada data).
    </p>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
