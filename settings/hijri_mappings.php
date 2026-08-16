<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_kalender_ui.php';

require_roles(['admin', 'pengurus']);

ensure_hijri_mappings_table($pdo);
hijri_sync_from_akademik_awal_bulan($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan') {
        $tahun = (int) ($_POST['tahun_hijriah'] ?? 0);
        $nama = trim((string) ($_POST['nama_bulan'] ?? ''));
        $awal = hijri_masehi_hbt_dari_post($_POST, 'awal', '');
        $total = (int) ($_POST['total_hari'] ?? 30);
        try {
            if ($awal === '') {
                throw new InvalidArgumentException('Isi tanggal awal bulan (H/B/T).');
            }
            hijri_simpan_mapping($pdo, $tahun, $nama, $awal, $total);
            set_flash('success', 'Pemetaan bulan Hijriyah disimpan.');
        } catch (InvalidArgumentException $e) {
            set_flash('error', $e->getMessage());
        }
    } elseif ($action === 'ubah_baris') {
        $id = (int) ($_POST['id'] ?? 0);
        $awal = hijri_masehi_hbt_dari_post($_POST, 'baris', $id);
        $total = (int) ($_POST['total_hari'] ?? 30);
        $total = $total === 29 ? 29 : 30;
        if ($id <= 0) {
            set_flash('error', 'Baris tidak valid.');
        } else {
            $st = $pdo->prepare('SELECT tahun_hijriah, nama_bulan FROM hijri_mappings WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                set_flash('error', 'Pemetaan tidak ditemukan.');
            } elseif ($awal === '') {
                $pdo->prepare('DELETE FROM hijri_mappings WHERE id = :id')->execute(['id' => $id]);
                hijri_mappings_rows($pdo, true);
                set_flash('success', 'Pemetaan dihapus (tanggal dikosongkan).');
            } else {
                try {
                    hijri_simpan_mapping(
                        $pdo,
                        (int) $row['tahun_hijriah'],
                        (string) $row['nama_bulan'],
                        $awal,
                        $total
                    );
                    set_flash('success', 'Pemetaan diperbarui.');
                } catch (InvalidArgumentException $e) {
                    set_flash('error', $e->getMessage());
                }
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM hijri_mappings WHERE id = :id')->execute(['id' => $id]);
            hijri_mappings_rows($pdo, true);
            set_flash('success', 'Pemetaan dihapus.');
        }
    } elseif ($action === 'tes') {
        $tgl = hijri_masehi_hbt_dari_post($_POST, 'tes', '');
        if ($tgl === '') {
            $tgl = trim((string) ($_POST['tanggal_tes'] ?? date('Y-m-d')));
        }
        $_SESSION['hijri_tes_tanggal'] = $tgl;
        set_flash('success', 'Tes konversi dijalankan.');
    }
    header('Location: ' . app_href('/settings/hijri_mappings.php'));
    exit;
}

$rows = hijri_mappings_rows($pdo);
$tesTanggal = (string) ($_SESSION['hijri_tes_tanggal'] ?? date('Y-m-d'));
unset($_SESSION['hijri_tes_tanggal']);
$hasilTes = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tesTanggal) ? konversiKeHijriah($pdo, $tesTanggal) : null;

$pageTitle = 'Pemetaan Hijriyah';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/kalender.php';
require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_asset_href('/assets/css/kalender-akademik.css')) ?>" rel="stylesheet">

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/settings/kalender.php">Pengaturan Kalender</a> · <a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Pemetaan bulan Hijriyah</h1>
    <p class="text-muted mb-0 small">Awal tiap bulan H. = tanggal <strong>1 Masehi</strong> dalam format <strong>H / B / T</strong> (Hari / Bulan / Tahun). Edit di tabel lalu klik <strong>Simpan</strong>.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">Tambah pemetaan</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="simpan">
                    <div class="col-6">
                        <label class="form-label small">Tahun H.</label>
                        <input type="number" class="form-control form-control-sm" name="tahun_hijriah" value="<?= (int) (akademik_hijri_anchor_hari_ini($pdo)['y']) ?>" min="1300" max="1500" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Bulan H.</label>
                        <select class="form-select form-select-sm" name="nama_bulan" required>
                            <?php foreach (hijri_nama_bulan_list() as $nama): ?>
                                <option value="<?= htmlspecialchars($nama) ?>"><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Tanggal 1 Masehi (H / B / T)</label>
                        <?php hijri_render_input_hbt('awal', '', ''); ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Jumlah hari</label>
                        <select class="form-select form-select-sm" name="total_hari">
                            <option value="30">30</option>
                            <option value="29">29</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 border-primary">
            <div class="card-body">
                <h2 class="h6 mb-3">Tes konversi</h2>
                <form method="post" class="mb-2">
                    <input type="hidden" name="action" value="tes">
                    <label class="form-label small">Tanggal Masehi (H/B/T)</label>
                    <div class="d-flex flex-wrap align-items-end gap-2">
                        <?php hijri_render_input_hbt('tes', '', $tesTanggal); ?>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Konversi</button>
                    </div>
                </form>
                <?php if ($hasilTes !== null): ?>
                    <div class="alert alert-success small mb-0 py-2">
                        <strong><?= htmlspecialchars($tesTanggal) ?></strong> Masehi →
                        <strong><?= (int) $hasilTes['tanggal'] ?> <?= htmlspecialchars((string) $hasilTes['nama_bulan']) ?> <?= (int) $hasilTes['tahun_hijriah'] ?> H.</strong>
                        <span class="font-monospace">(<?= htmlspecialchars((string) $hasilTes['tanggal_hijriah']) ?>)</span>
                    </div>
                <?php elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tesTanggal)): ?>
                    <div class="alert alert-warning small mb-0 py-2">Tidak ada pemetaan untuk tanggal ini.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-light py-2">
        <h2 class="h6 mb-0">Daftar pemetaan — edit H/B/T</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tahun H.</th>
                        <th>Bulan H.</th>
                        <th>Tanggal 1 Masehi (H/B/T)</th>
                        <th class="text-center" style="width:5rem">Hari</th>
                        <th class="text-end" style="width:9rem">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $rid = (int) ($r['id'] ?? 0);
                        $ymd = (string) ($r['tanggal_masehi_awal_bulan'] ?? '');
                        $th = (int) ($r['total_hari'] ?? 30);
                        ?>
                        <tr>
                            <td><?= (int) ($r['tahun_hijriah'] ?? 0) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($r['nama_bulan'] ?? '')) ?></td>
                            <td>
                                <form method="post" id="form-baris-<?= $rid ?>">
                                    <input type="hidden" name="action" value="ubah_baris">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <?php hijri_render_input_hbt('baris', $rid, $ymd); ?>
                                    <?php if ($ymd !== ''): ?>
                                        <div class="small text-muted font-monospace mt-1"><?= htmlspecialchars($ymd) ?></div>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td class="text-center">
                                <select class="form-select form-select-sm" name="total_hari" form="form-baris-<?= $rid ?>">
                                    <option value="30" <?= $th !== 29 ? 'selected' : '' ?>>30</option>
                                    <option value="29" <?= $th === 29 ? 'selected' : '' ?>>29</option>
                                </select>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="submit" class="btn btn-primary btn-sm" form="form-baris-<?= $rid ?>">Simpan</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus pemetaan ini?');">
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
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

<p class="small text-muted">
    <a href="/akademik/kalender.php?view=atur">Atur 12 bulan per tahun H. (kalender akademik)</a>
</p>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
