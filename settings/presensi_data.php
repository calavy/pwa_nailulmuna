<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/presensi_data_admin.php';

require_roles(['admin']);
require_super_admin();

$jenisMap = presensi_data_jenis_map();
$jenisHapusKeys = presensi_data_jenis_hapus_keys();
$defaultMulai = date('Y-m-01');
$defaultSelesai = date('Y-m-d');

$mulai = trim((string) ($_REQUEST['tanggal_mulai'] ?? $defaultMulai));
$selesai = trim((string) ($_REQUEST['tanggal_selesai'] ?? $defaultSelesai));
$jenisSelected = presensi_data_normalize_jenis(
    is_array($_REQUEST['jenis'] ?? null) ? (array) $_REQUEST['jenis'] : array_keys($jenisMap)
);
if ($jenisSelected === []) {
    $jenisSelected = array_keys($jenisMap);
}

$hapusMulai = trim((string) ($_REQUEST['hapus_mulai'] ?? $_POST['hapus_mulai'] ?? $mulai));
$hapusSelesai = trim((string) ($_REQUEST['hapus_selesai'] ?? $_POST['hapus_selesai'] ?? $selesai));
$hapusJenisRaw = $_POST['hapus_jenis'] ?? $_REQUEST['hapus_jenis'] ?? $jenisHapusKeys;
$hapusJenisSelected = presensi_data_normalize_jenis(
    is_array($hapusJenisRaw) ? (array) $hapusJenisRaw : [],
    $jenisHapusKeys
);

if (($_GET['export'] ?? '') === 'csv') {
    $parsed = presensi_data_parse_rentang($mulai, $selesai);
    if (!$parsed['ok']) {
        set_flash('error', $parsed['message']);
        header('Location: ' . app_href('/settings/presensi_data.php'));
        exit;
    }
    presensi_data_stream_csv($pdo, $parsed['mulai'], $parsed['selesai'], $jenisSelected);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_presensi_rentang') {
    $parsed = presensi_data_parse_rentang(
        trim((string) ($_POST['hapus_mulai'] ?? '')),
        trim((string) ($_POST['hapus_selesai'] ?? ''))
    );
    $confirm = strtoupper(trim((string) ($_POST['confirm_text'] ?? '')));
    $jenisPost = presensi_data_normalize_jenis(
        is_array($_POST['hapus_jenis'] ?? null) ? (array) $_POST['hapus_jenis'] : [],
        $jenisHapusKeys
    );

    if (!$parsed['ok']) {
        set_flash('error', $parsed['message']);
    } elseif ($confirm !== 'HAPUS') {
        set_flash('error', 'Ketik HAPUS (huruf besar) untuk mengonfirmasi penghapusan.');
    } elseif ($jenisPost === []) {
        set_flash('error', 'Centang minimal satu jenis presensi (santri, pembimbing, atau munawib).');
    } else {
        $result = presensi_data_delete_by_range(
            $pdo,
            $parsed['mulai'],
            $parsed['selesai'],
            $jenisPost,
            (int) ($_SESSION['user']['id'] ?? 0)
        );
        if ($result['ok']) {
            $parts = [];
            foreach ($result['deleted'] as $k => $n) {
                if ($n > 0) {
                    $parts[] = ($jenisMap[$k]['label'] ?? $k) . ': ' . $n;
                }
            }
            set_flash('success', $result['message'] . ($parts !== [] ? ' (' . implode(' · ', $parts) . ')' : ''));
        } else {
            set_flash('error', $result['message']);
        }
    }
    $qs = http_build_query([
        'tanggal_mulai' => $mulai,
        'tanggal_selesai' => $selesai,
        'hapus_mulai' => $parsed['mulai'] ?: $hapusMulai,
        'hapus_selesai' => $parsed['selesai'] ?: $hapusSelesai,
        'hapus_jenis' => $jenisPost !== [] ? $jenisPost : $hapusJenisSelected,
    ]);
    header('Location: ' . app_href('/settings/presensi_data.php?' . $qs . '#hapus-presensi'));
    exit;
}

$parsedPreview = presensi_data_parse_rentang($mulai, $selesai);
$counts = $parsedPreview['ok']
    ? presensi_data_count_by_range($pdo, $parsedPreview['mulai'], $parsedPreview['selesai'], $jenisSelected)
    : [];
$totalPreview = array_sum($counts);

$parsedHapus = presensi_data_parse_rentang($hapusMulai, $hapusSelesai);
$hapusCounts = ($parsedHapus['ok'] && $hapusJenisSelected !== [])
    ? presensi_data_count_by_range($pdo, $parsedHapus['mulai'], $parsedHapus['selesai'], $hapusJenisSelected)
    : [];
$hapusTotal = array_sum($hapusCounts);

$pageTitle = 'Kelola Data Presensi';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/presensi_data.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-database me-1 text-danger"></i> Kelola Data Presensi</h1>
    <p class="text-muted mb-0 small">
        Hanya <strong>super admin</strong>. Unduh atau hapus data presensi per rentang tanggal.
        Penghapusan permanen — termasuk poin otomatis terkait presensi santri.
    </p>
</div>

<form method="get" class="card shadow-sm mb-3" id="form-presensi-data-filter">
    <div class="card-header fw-semibold"><i class="fa-solid fa-file-csv me-1 text-success"></i> Unduh data (CSV)</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Dari tanggal</label>
                <input type="date" class="form-control" name="tanggal_mulai" value="<?= htmlspecialchars($parsedPreview['ok'] ? $parsedPreview['mulai'] : $mulai) ?>" required>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Sampai tanggal</label>
                <input type="date" class="form-control" name="tanggal_selesai" value="<?= htmlspecialchars($parsedPreview['ok'] ? $parsedPreview['selesai'] : $selesai) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1">Jenis presensi</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($jenisMap as $key => $def): ?>
                        <?php $avail = table_exists($pdo, $def['table']); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="jenis[]" value="<?= htmlspecialchars($key) ?>"
                                   id="jenis-<?= htmlspecialchars($key) ?>"
                                   <?= in_array($key, $jenisSelected, true) ? 'checked' : '' ?>
                                   <?= $avail ? '' : 'disabled' ?>>
                            <label class="form-check-label small" for="jenis-<?= htmlspecialchars($key) ?>">
                                <?= htmlspecialchars($def['label']) ?>
                                <?php if (!$avail): ?><span class="text-muted">(belum ada tabel)</span><?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass me-1"></i> Tampilkan jumlah</button>
                <?php if ($parsedPreview['ok'] && $totalPreview > 0): ?>
                    <a class="btn btn-outline-success btn-sm"
                       href="<?= htmlspecialchars(app_href('/settings/presensi_data.php?export=csv&tanggal_mulai=' . urlencode($parsedPreview['mulai']) . '&tanggal_selesai=' . urlencode($parsedPreview['selesai']) . '&' . http_build_query(['jenis' => $jenisSelected]))) ?>">
                        <i class="fa-solid fa-file-csv me-1"></i> Unduh CSV (<?= number_format($totalPreview, 0, ',', '.') ?> baris)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (!$parsedPreview['ok']): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($parsedPreview['message']) ?></div>
<?php elseif ($counts !== []): ?>
    <div class="row g-2 mb-3">
        <?php foreach ($counts as $key => $cnt): ?>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm h-100 border-0">
                    <div class="card-body py-2">
                        <div class="small text-muted"><?= htmlspecialchars($jenisMap[$key]['label'] ?? $key) ?></div>
                        <div class="h5 mb-0 fw-bold"><?= number_format($cnt, 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm h-100 border-primary-subtle bg-primary-subtle">
                <div class="card-body py-2">
                    <div class="small text-muted">Total unduh</div>
                    <div class="h5 mb-0 fw-bold text-primary"><?= number_format($totalPreview, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-danger-subtle" id="hapus-presensi">
    <div class="card-header bg-danger-subtle text-danger fw-semibold">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Hapus data presensi
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Pilih <strong>rentang tanggal</strong> dan <strong>jenis data</strong> yang akan dihapus.
            Tindakan permanen — presensi santri juga menghapus poin otomatis terkait.
        </p>
        <form method="post" id="form-hapus-presensi" onsubmit="return confirm('Yakin hapus data presensi sesuai pilihan? Tindakan permanen.');">
            <input type="hidden" name="action" value="hapus_presensi_rentang">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Dari tanggal <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="hapus_mulai"
                           value="<?= htmlspecialchars($parsedHapus['ok'] ? $parsedHapus['mulai'] : $hapusMulai) ?>" required>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Sampai tanggal <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="hapus_selesai"
                           value="<?= htmlspecialchars($parsedHapus['ok'] ? $parsedHapus['selesai'] : $hapusSelesai) ?>" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold mb-1">Jenis yang dihapus <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-3 p-2 rounded border bg-light">
                        <?php foreach ($jenisHapusKeys as $key): ?>
                            <?php
                            $def = $jenisMap[$key] ?? null;
                            if ($def === null) {
                                continue;
                            }
                            $avail = table_exists($pdo, $def['table']);
                            $checked = in_array($key, $hapusJenisSelected, true);
                            ?>
                            <div class="form-check">
                                <input class="form-check-input hapus-jenis-check" type="checkbox" name="hapus_jenis[]"
                                       value="<?= htmlspecialchars($key) ?>"
                                       id="hapus-jenis-<?= htmlspecialchars($key) ?>"
                                       <?= $checked ? 'checked' : '' ?>
                                       <?= $avail ? '' : 'disabled' ?>>
                                <label class="form-check-label" for="hapus-jenis-<?= htmlspecialchars($key) ?>">
                                    <?= htmlspecialchars($def['label']) ?>
                                    <?php if ($parsedHapus['ok'] && $avail && isset($hapusCounts[$key])): ?>
                                        <span class="badge text-bg-secondary ms-1"><?= number_format((int) $hapusCounts[$key], 0, ',', '.') ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Centang satu atau lebih: santri, pembimbing, munawib.</div>
                </div>
            </div>

            <?php if (!$parsedHapus['ok']): ?>
                <div class="alert alert-warning py-2 mt-3 mb-0 small"><?= htmlspecialchars($parsedHapus['message']) ?></div>
            <?php elseif ($hapusJenisSelected === []): ?>
                <div class="alert alert-info py-2 mt-3 mb-0 small">Centang minimal satu jenis presensi untuk melihat jumlah dan menghapus.</div>
            <?php elseif ($hapusTotal === 0): ?>
                <div class="alert alert-secondary py-2 mt-3 mb-0 small">Tidak ada data pada rentang dan jenis yang dipilih.</div>
            <?php else: ?>
                <div class="alert alert-danger py-2 mt-3 mb-0 small">
                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                    Akan menghapus <strong><?= number_format($hapusTotal, 0, ',', '.') ?> baris</strong>
                    (<?= htmlspecialchars($parsedHapus['mulai']) ?> s/d <?= htmlspecialchars($parsedHapus['selesai']) ?>)
                    <?php
                    $hapusParts = [];
                    foreach ($hapusCounts as $hk => $hc) {
                        if ($hc > 0) {
                            $hapusParts[] = ($jenisMap[$hk]['label'] ?? $hk) . ': ' . number_format($hc, 0, ',', '.');
                        }
                    }
                    if ($hapusParts !== []) {
                        echo ' — ' . htmlspecialchars(implode(' · ', $hapusParts));
                    }
                    ?>
                </div>
            <?php endif; ?>

            <div class="row g-3 align-items-end mt-2">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Ketik <strong>HAPUS</strong> untuk konfirmasi</label>
                    <input type="text" class="form-control" name="confirm_text" autocomplete="off" placeholder="HAPUS" required>
                </div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-danger"
                        <?= ($parsedHapus['ok'] && $hapusJenisSelected !== [] && $hapusTotal > 0) ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-trash-can me-1"></i> Hapus data terpilih
                    </button>
                    <a class="btn btn-outline-secondary btn-sm align-self-center"
                       href="<?= htmlspecialchars(app_href('/settings/presensi_data.php?' . http_build_query([
                           'tanggal_mulai' => $mulai,
                           'tanggal_selesai' => $selesai,
                           'hapus_mulai' => $parsedHapus['ok'] ? $parsedHapus['mulai'] : $hapusMulai,
                           'hapus_selesai' => $parsedHapus['ok'] ? $parsedHapus['selesai'] : $hapusSelesai,
                           'hapus_jenis' => $hapusJenisSelected,
                       ]) . '#hapus-presensi')) ?>">
                        Segarkan jumlah
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('form-hapus-presensi');
    if (!form) return;

    function refreshHapusPreview() {
        var mulai = form.querySelector('[name="hapus_mulai"]');
        var selesai = form.querySelector('[name="hapus_selesai"]');
        if (!mulai || !selesai || !mulai.value || !selesai.value) return;
        var params = new URLSearchParams(window.location.search);
        params.set('hapus_mulai', mulai.value);
        params.set('hapus_selesai', selesai.value);
        params.delete('hapus_jenis[]');
        form.querySelectorAll('.hapus-jenis-check:checked').forEach(function (cb) {
            params.append('hapus_jenis[]', cb.value);
        });
        window.location.href = '<?= htmlspecialchars(app_href('/settings/presensi_data.php')) ?>?' + params.toString() + '#hapus-presensi';
    }

    form.querySelectorAll('[name="hapus_mulai"], [name="hapus_selesai"], .hapus-jenis-check').forEach(function (el) {
        el.addEventListener('change', refreshHapusPreview);
    });
})();
</script>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
