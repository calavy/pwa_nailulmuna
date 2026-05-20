<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_izin_tetap.php';

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

ensure_santri_izin_tetap_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['id'] ?? 0);
$hariMap = santri_izin_tetap_hari_map();

$redirect = static function (int $id = 0) use ($q): void {
    $url = '/pwa_nailulmuna/perizinan/izin_tetap.php';
    if ($id > 0) {
        $url .= '?id=' . $id;
    }
    if ($q !== '') {
        $url .= ($id > 0 ? '&' : '?') . 'q=' . urlencode($q);
    }
    header('Location: ' . $url);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan') {
        $slots = santri_izin_tetap_slots_dari_post($_POST);
        $result = santri_izin_tetap_simpan($pdo, $_POST, $slots, $userId);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        $redirect($result['ok'] ? (int) ($result['id'] ?? $_POST['id'] ?? 0) : (int) ($_POST['id'] ?? 0));
    }
    if ($action === 'toggle_aktif') {
        $result = santri_izin_tetap_set_aktif($pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['aktif'] ?? 0) === 1);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        $redirect((int) ($_POST['id'] ?? 0));
    }
    if ($action === 'hapus') {
        $result = santri_izin_tetap_hapus($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /pwa_nailulmuna/perizinan/izin_tetap.php');
        exit;
    }
}

$santriAktif = [];
if (table_exists($pdo, 'santri')) {
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE COALESCE(is_aktif, 1) = 1 ' : '';
    $santriAktif = $pdo->query("SELECT id, nis, {$nameExpr} AS nama FROM santri {$activeExpr} ORDER BY {$nameExpr} ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$editRow = $editId > 0 ? santri_izin_tetap_by_id($pdo, $editId) : null;
$editSlots = $editRow ? santri_izin_tetap_slots($pdo, $editId) : [];
if ($editSlots === []) {
    $editSlots = [['hari_ke' => 1, 'jam_mulai' => '08:00', 'jam_selesai' => '12:00']];
}

$listRows = santri_izin_tetap_list($pdo, $q);
$jumlahAktif = count(array_filter($listRows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1));

$pageTitle = 'Izin Tetap Hidmah';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="/pwa_nailulmuna/perizinan/index.php">Perizinan</a> · Santri
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-calendar-check text-primary me-1"></i> Izin Tetap (Hidmah)</h1>
    <p class="text-muted mb-0">
        Santri hidmah yang keluar pada <strong>hari &amp; jam tertentu</strong> tidak perlu surat izin cetak.
        Status presensi disinkronkan otomatis (IZIN) sesuai jadwal. Dapat diubah atau dihentikan kapan saja.
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                <?= $editRow ? 'Ubah izin tetap' : 'Tambah izin tetap' ?>
            </div>
            <div class="card-body">
                <form method="post" id="form-izin-tetap">
                    <input type="hidden" name="action" value="simpan">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Santri <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="santri_id" required>
                            <option value="">— pilih —</option>
                            <?php foreach ($santriAktif as $s): ?>
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" <?= $editRow && (int) ($editRow['santri_id'] ?? 0) === $sid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nis'] ?? '') . ' — ' . ($s['nama'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label class="form-label">Judul / lokasi hidmah</label>
                            <input type="text" class="form-control form-control-sm" name="judul" required maxlength="120"
                                value="<?= htmlspecialchars($editRow ? (string) ($editRow['judul'] ?? '') : 'Hidmah pondok') ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label">Jenis</label>
                            <select class="form-select form-select-sm" name="jenis">
                                <option value="HIDMAH" <?= !$editRow || ($editRow['jenis'] ?? '') === 'HIDMAH' ? 'selected' : '' ?>>Hidmah</option>
                                <option value="TUGAS" <?= $editRow && ($editRow['jenis'] ?? '') === 'TUGAS' ? 'selected' : '' ?>>Tugas</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Berlaku mulai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_mulai" required
                                value="<?= htmlspecialchars($editRow ? (string) ($editRow['tanggal_mulai'] ?? date('Y-m-d')) : date('Y-m-d')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Berlaku sampai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_selesai"
                                value="<?= htmlspecialchars($editRow && !empty($editRow['tanggal_selesai']) ? (string) $editRow['tanggal_selesai'] : '') ?>">
                            <div class="form-text">Kosongkan = tanpa batas</div>
                        </div>
                    </div>
                    <label class="form-label">Jadwal hari &amp; jam <span class="text-danger">*</span></label>
                    <div id="slot-rows" class="mb-2">
                        <?php foreach ($editSlots as $i => $sl): ?>
                            <div class="row g-1 align-items-end slot-row mb-1">
                                <div class="col-4">
                                    <select class="form-select form-select-sm" name="hari_ke[]" required>
                                        <?php foreach ($hariMap as $hk => $hl): ?>
                                            <option value="<?= $hk ?>" <?= (int) ($sl['hari_ke'] ?? 0) === $hk ? 'selected' : '' ?>><?= htmlspecialchars($hl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <input type="time" class="form-control form-control-sm" name="jam_mulai[]" required
                                        value="<?= htmlspecialchars(substr((string) ($sl['jam_mulai'] ?? '08:00'), 0, 5)) ?>">
                                </div>
                                <div class="col-3">
                                    <input type="time" class="form-control form-control-sm" name="jam_selesai[]" required
                                        value="<?= htmlspecialchars(substr((string) ($sl['jam_selesai'] ?? '12:00'), 0, 5)) ?>">
                                </div>
                                <div class="col-2">
                                    <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-hapus-slot" title="Hapus baris">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="btn-tambah-slot">+ Tambah hari/jam</button>
                    <div class="mb-2">
                        <label class="form-label">Keterangan</label>
                        <textarea class="form-control form-control-sm" name="keterangan" rows="2"><?= htmlspecialchars($editRow ? (string) ($editRow['keterangan'] ?? '') : '') ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_aktif" value="1" id="is_aktif_izin"
                            <?= !$editRow || (int) ($editRow['is_aktif'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_aktif_izin">Izin tetap aktif</label>
                    </div>
                    <div class="alert alert-light border small py-2 mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i>
                        Tanpa cetak surat. Sinkron presensi: status <strong>IZIN</strong> pada hari/jam jadwal (generate alpa &amp; rekap).
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-save me-1"></i> Simpan</button>
                        <?php if ($editRow): ?>
                            <a href="/pwa_nailulmuna/perizinan/izin_tetap.php" class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($editRow): ?>
                    <form method="post" class="mt-3" onsubmit="return confirm('Hapus izin tetap ini?');">
                        <input type="hidden" name="action" value="hapus">
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Hapus permanen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Daftar izin tetap</span>
                <span class="badge text-bg-success"><?= $jumlahAktif ?> aktif</span>
            </div>
            <div class="card-body border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col">
                        <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama, NIS, judul">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>Judul</th>
                                <th>Jadwal</th>
                                <th>Periode</th>
                                <th class="text-center">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($listRows === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($listRows as $r):
                            $iid = (int) ($r['id'] ?? 0);
                            $aktif = (int) ($r['is_aktif'] ?? 0) === 1;
                            ?>
                            <tr class="<?= $aktif ? '' : 'table-secondary' ?>">
                                <td class="small">
                                    <span class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></span><br>
                                    <span class="text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></span>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars((string) ($r['judul'] ?? '')) ?><br>
                                    <span class="badge text-bg-info" style="font-size:.65rem"><?= htmlspecialchars(santri_izin_tetap_jenis_label((string) ($r['jenis'] ?? 'HIDMAH'))) ?></span>
                                </td>
                                <td class="small"><?= htmlspecialchars(santri_izin_tetap_slot_ringkas($pdo, $iid)) ?></td>
                                <td class="small text-nowrap">
                                    <?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?>
                                    <?php if (!empty($r['tanggal_selesai'])): ?>
                                        <br>s/d <?= htmlspecialchars((string) $r['tanggal_selesai']) ?>
                                    <?php else: ?>
                                        <br><span class="text-muted">tanpa batas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($aktif): ?>
                                        <span class="badge text-bg-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="?id=<?= $iid ?>">Ubah</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_aktif">
                                        <input type="hidden" name="id" value="<?= $iid ?>">
                                        <input type="hidden" name="aktif" value="<?= $aktif ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $aktif ? 'warning' : 'success' ?>"><?= $aktif ? 'Stop' : 'Aktif' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="tpl-slot-row">
    <div class="row g-1 align-items-end slot-row mb-1">
        <div class="col-4">
            <select class="form-select form-select-sm" name="hari_ke[]" required>
                <?php foreach ($hariMap as $hk => $hl): ?>
                    <option value="<?= $hk ?>"><?= htmlspecialchars($hl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-3">
            <input type="time" class="form-control form-control-sm" name="jam_mulai[]" value="08:00" required>
        </div>
        <div class="col-3">
            <input type="time" class="form-control form-control-sm" name="jam_selesai[]" value="12:00" required>
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-hapus-slot">×</button>
        </div>
    </div>
</template>

<script>
(function () {
    const container = document.getElementById('slot-rows');
    const tpl = document.getElementById('tpl-slot-row');
    document.getElementById('btn-tambah-slot')?.addEventListener('click', function () {
        if (!container || !tpl) return;
        container.appendChild(tpl.content.cloneNode(true));
    });
    container?.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.btn-hapus-slot');
        if (!btn) return;
        const rows = container.querySelectorAll('.slot-row');
        if (rows.length <= 1) return;
        btn.closest('.slot-row')?.remove();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
