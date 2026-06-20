<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/kegiatan_khusus.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';

require_roles(['admin', 'pengurus']);
kegiatan_khusus_ensure_schema($pdo);
ensure_santri_identity_columns($pdo);

$tingkatanList = kegiatan_khusus_tingkatan_list($pdo);
$detailId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'tambah_kegiatan_khusus') {
        $result = kegiatan_khusus_tambah($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/presensi/kegiatan_khusus.php'));
        exit;
    }
    if ($action === 'catat_presensi') {
        $res = kegiatan_khusus_catat_presensi(
            $pdo,
            (int) ($_POST['kegiatan_id'] ?? 0),
            (int) ($_POST['santri_id'] ?? 0),
            (int) ($_SESSION['user']['id'] ?? 0)
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/presensi/kegiatan_khusus.php?id=' . (int) ($_POST['kegiatan_id'] ?? 0)));
        exit;
    }
}

$rows = $pdo->query('
    SELECT k.*,
           (SELECT COUNT(*) FROM presensi_kegiatan_khusus p WHERE p.kegiatan_khusus_id = k.id) AS total_scan,
           (SELECT COUNT(*) FROM kegiatan_khusus_santri ks WHERE ks.kegiatan_khusus_id = k.id) AS jumlah_peserta
    FROM kegiatan_khusus k
    ORDER BY k.tanggal DESC, k.jam_mulai DESC, k.id DESC
    LIMIT 120
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$detailKegiatan = null;
$detailPeserta = [];
$detailHadir = [];
if ($detailId > 0) {
    $st = $pdo->prepare('SELECT * FROM kegiatan_khusus WHERE id = :id LIMIT 1');
    $st->execute(['id' => $detailId]);
    $detailKegiatan = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailKegiatan) {
        $detailPeserta = kegiatan_khusus_peserta_santri($pdo, $detailId);
        $hadirSt = $pdo->prepare('SELECT santri_id FROM presensi_kegiatan_khusus WHERE kegiatan_khusus_id = :id');
        $hadirSt->execute(['id' => $detailId]);
        foreach ($hadirSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $detailHadir[(int) $sid] = true;
        }
    }
}

$pageTitle = 'Kegiatan Khusus';
require_once __DIR__ . '/../includes/header.php';
$santriSearchUrl = app_href('/api/keuangan/santri_search.php');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">Presensi</a></p>
    <h1 class="h4 mb-1">Absensi kegiatan khusus (sekali pakai)</h1>
    <p class="text-muted mb-0 small">Peserta bisa per tingkatan (scan QR) atau daftar santri tertentu (input nama). Presensi manual tersedia di detail kegiatan.</p>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Tambah kegiatan khusus</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2" id="form-kegiatan-khusus">
            <input type="hidden" name="action" value="tambah_kegiatan_khusus">
            <div class="col-md-5">
                <label class="form-label">Nama kegiatan</label>
                <input type="text" name="nama_kegiatan" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Kategori</label>
                <select class="form-select" name="kategori_kegiatan">
                    <option value="TAALIM">Ta'lim/Ta'alum</option>
                    <option value="JAMAAH">Jama'ah</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tanggal</label>
                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label d-block">Mode peserta</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="mode_peserta" id="mode-tingkatan" value="TINGKATAN" checked>
                    <label class="form-check-label" for="mode-tingkatan">Per tingkatan (scan QR)</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="mode_peserta" id="mode-santri" value="SANTRI">
                    <label class="form-check-label" for="mode-santri">Santri tertentu (nama/NIS)</label>
                </div>
            </div>
            <div class="col-12" id="wrap-tingkatan">
                <label class="form-label">Tingkatan <span class="text-danger">*</span></label>
                <?php
                $tingkatanPickerList = $tingkatanList;
                $tingkatanPickerSelected = [];
                $tingkatanPickerName = 'tingkatan[]';
                $tingkatanPickerId = 'kegiatan-khusus-tingkatan';
                require __DIR__ . '/../includes/partials/tingkatan_multi_picker.php';
                ?>
            </div>
            <div class="col-12 d-none" id="wrap-santri-pick">
                <label class="form-label">Cari &amp; pilih santri</label>
                <select id="santri-picker" class="form-select santri-select-searchable" data-santri-ajax="1" data-santri-search-url="<?= htmlspecialchars($santriSearchUrl) ?>" data-search-placeholder="Ketik nama atau NIS…">
                    <option value="">— Cari santri —</option>
                </select>
                <div class="form-text">Pilih santri lalu klik Tambah. Bisa lebih dari satu.</div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btn-add-santri"><i class="fa-solid fa-user-plus me-1"></i>Tambah ke daftar</button>
                <ul class="list-group list-group-flush mt-2" id="santri-selected-list"></ul>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam mulai</label>
                <input type="time" name="jam_mulai" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam selesai</label>
                <input type="time" name="jam_selesai" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tempat</label>
                <input type="text" name="tempat" class="form-control" placeholder="Opsional">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus me-1"></i>Simpan</button>
                <a class="btn btn-outline-secondary ms-2" href="<?= htmlspecialchars(app_href('/rekap/kegiatan_khusus.php')) ?>">Rekap</a>
            </div>
        </form>
        <script src="<?= htmlspecialchars(app_asset_href('/assets/js/tingkatan-multi-picker.js')) ?>" defer></script>
        <script src="<?= htmlspecialchars(app_asset_href('/assets/js/santri-select.js')) ?>" defer></script>
        <script>
        (function () {
            var modeTingkatan = document.getElementById('mode-tingkatan');
            var modeSantri = document.getElementById('mode-santri');
            var wrapTingkatan = document.getElementById('wrap-tingkatan');
            var wrapSantri = document.getElementById('wrap-santri-pick');
            var picker = document.getElementById('santri-picker');
            var list = document.getElementById('santri-selected-list');
            var btnAdd = document.getElementById('btn-add-santri');
            var selected = {};

            function syncMode() {
                var santriMode = modeSantri && modeSantri.checked;
                if (wrapTingkatan) wrapTingkatan.classList.toggle('d-none', santriMode);
                if (wrapSantri) wrapSantri.classList.toggle('d-none', !santriMode);
            }
            if (modeTingkatan) modeTingkatan.addEventListener('change', syncMode);
            if (modeSantri) modeSantri.addEventListener('change', syncMode);
            syncMode();

            function renderList() {
                if (!list) return;
                list.innerHTML = '';
                Object.keys(selected).forEach(function (id) {
                    var item = selected[id];
                    var li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center py-1 px-0';
                    li.innerHTML = '<span class="small">' + item.label + '</span>'
                        + '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove="' + id + '">Hapus</button>'
                        + '<input type="hidden" name="santri_ids[]" value="' + id + '">';
                    list.appendChild(li);
                });
            }

            if (btnAdd && picker) {
                btnAdd.addEventListener('click', function () {
                    var id = picker.value;
                    if (!id) return;
                    var label = picker.options[picker.selectedIndex] ? picker.options[picker.selectedIndex].text : id;
                    selected[id] = { label: label };
                    renderList();
                });
            }
            if (list) {
                list.addEventListener('click', function (e) {
                    var btn = e.target.closest('[data-remove]');
                    if (!btn) return;
                    delete selected[btn.getAttribute('data-remove')];
                    renderList();
                });
            }
        })();
        </script>
    </div>
</div>

<?php if ($detailKegiatan): ?>
<div class="card shadow-sm mb-3 border-primary">
    <div class="card-header py-2 bg-primary-subtle">
        <strong>Detail: <?= htmlspecialchars((string) ($detailKegiatan['nama_kegiatan'] ?? '')) ?></strong>
        <span class="small text-muted ms-2"><?= htmlspecialchars((string) ($detailKegiatan['tanggal'] ?? '')) ?></span>
    </div>
    <div class="card-body">
        <?php if (strtoupper((string) ($detailKegiatan['mode_peserta'] ?? 'TINGKATAN')) === 'SANTRI'): ?>
            <p class="small text-muted">Peserta terdaftar: <?= count($detailPeserta) ?> santri. Tandai hadir manual di bawah.</p>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Santri</th><th>Tingkatan</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($detailPeserta as $p):
                        $sid = (int) ($p['id'] ?? 0);
                        $hadir = !empty($detailHadir[$sid]);
                        ?>
                        <tr>
                            <td><span class="fw-semibold"><?= htmlspecialchars((string) ($p['nama_santri'] ?? '')) ?></span><br><span class="small text-muted"><?= htmlspecialchars((string) ($p['nis'] ?? '')) ?></span></td>
                            <td class="small"><?= htmlspecialchars((string) ($p['tingkatan'] ?? '-')) ?></td>
                            <td><?= $hadir ? '<span class="badge text-bg-success">Hadir</span>' : '<span class="badge text-bg-secondary">Belum</span>' ?></td>
                            <td class="text-end">
                                <?php if (!$hadir): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="catat_presensi">
                                    <input type="hidden" name="kegiatan_id" value="<?= $detailId ?>">
                                    <input type="hidden" name="santri_id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Tandai hadir</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="catat_presensi">
                <input type="hidden" name="kegiatan_id" value="<?= $detailId ?>">
                <div class="col-md-8">
                    <label class="form-label">Input presensi manual (nama/NIS)</label>
                    <select name="santri_id" class="form-select santri-select-searchable" required data-santri-ajax="1" data-santri-search-url="<?= htmlspecialchars($santriSearchUrl) ?>">
                        <option value="">— Cari santri —</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-check me-1"></i>Catat hadir</button>
                </div>
            </form>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(app_href('/presensi/kegiatan_khusus.php')) ?>" class="btn btn-sm btn-link mt-2">Tutup detail</a>
    </div>
</div>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/santri-select.js')) ?>" defer></script>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header py-2"><strong>Daftar kegiatan khusus</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tanggal</th><th>Kegiatan</th><th>Mode</th><th>Peserta</th><th>Waktu</th><th>Hadir</th><th></th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada kegiatan khusus.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r):
                $mode = strtoupper((string) ($r['mode_peserta'] ?? 'TINGKATAN'));
                ?>
                <tr>
                    <td class="small"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                    <td class="small fw-semibold"><?= htmlspecialchars((string) ($r['nama_kegiatan'] ?? '')) ?></td>
                    <td class="small"><?= $mode === 'SANTRI' ? 'Nama santri' : 'Tingkatan' ?></td>
                    <td class="small"><?= $mode === 'SANTRI' ? (int) ($r['jumlah_peserta'] ?? 0) . ' orang' : htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                    <td class="small"><?= htmlspecialchars(substr((string) ($r['jam_mulai'] ?? ''), 0, 5)) ?> - <?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?></td>
                    <td class="small"><?= (int) ($r['total_scan'] ?? 0) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?id=<?= (int) ($r['id'] ?? 0) ?>">Kelola</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
