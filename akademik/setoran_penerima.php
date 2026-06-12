<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';
require_once __DIR__ . '/../helpers/akademik_setoran.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_setoran_penerima_schema($pdo);

$tingkatanList = akademik_setoran_semua_tingkatan($pdo);
$tab = trim((string) ($_GET['tab'] ?? 'data'));
if (!in_array($tab, ['data', 'tambah', 'tingkatan'], true)) {
    $tab = 'data';
}

$penerimaUrl = static function (array $extra = []) use ($tab): string {
    $q = array_merge(['tab' => $tab], $extra);

    return app_href('/akademik/setoran_penerima.php?' . http_build_query($q));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'tugaskan_penerima') {
        $peran = trim((string) ($_POST['peran'] ?? ''));
        $refId = (int) ($_POST['ref_id'] ?? 0);
        $tkList = is_array($_POST['tingkatan'] ?? null) ? (array) $_POST['tingkatan'] : [];
        if (!in_array($peran, ['pembimbing', 'munawib'], true) || $refId <= 0) {
            set_flash('error', 'Pilih pembimbing atau munawib yang valid.');
            header('Location: ' . $penerimaUrl(['tab' => 'tambah']));
            exit;
        }
        akademik_setoran_penerima_upsert($pdo, $peran, $refId, true);
        if ($peran === 'pembimbing') {
            akademik_setoran_sync_pembimbing_tingkatan($pdo, $refId, $tkList);
        } else {
            akademik_setoran_sync_munawib_tingkatan($pdo, $refId, $tkList);
        }
        set_flash('success', 'Penerima setoran ditugaskan. Petugas dapat login lewat Input setoran hafalan (scan kartu).');
        header('Location: ' . app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=' . rawurlencode($peran) . '&ref_id=' . $refId));
        exit;
    }
    if ($action === 'simpan_munawib_tingkatan') {
        $mid = (int) ($_POST['munawib_id'] ?? 0);
        $tkList = is_array($_POST['tingkatan'] ?? null) ? (array) $_POST['tingkatan'] : [];
        if ($mid <= 0) {
            set_flash('error', 'Pilih munawib.');
        } else {
            akademik_setoran_sync_munawib_tingkatan($pdo, $mid, $tkList);
            set_flash('success', 'Tingkatan setoran munawib disimpan.');
        }
        header('Location: ' . app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=munawib&ref_id=' . $mid));
        exit;
    }
    if ($action === 'simpan_pembimbing_tingkatan') {
        $pid = (int) ($_POST['pembimbing_id'] ?? 0);
        $tkList = is_array($_POST['tingkatan'] ?? null) ? (array) $_POST['tingkatan'] : [];
        if ($pid <= 0) {
            set_flash('error', 'Pilih pembimbing.');
        } else {
            akademik_setoran_sync_pembimbing_tingkatan($pdo, $pid, $tkList);
            set_flash('success', 'Tingkatan setoran pembimbing disimpan.');
        }
        header('Location: ' . app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=pembimbing&ref_id=' . $pid));
        exit;
    }
    if ($action === 'toggle_aktif') {
        $peran = trim((string) ($_POST['peran'] ?? ''));
        $refId = (int) ($_POST['ref_id'] ?? 0);
        $aktif = (int) ($_POST['aktif'] ?? 0) === 1;
        if (in_array($peran, ['pembimbing', 'munawib'], true) && $refId > 0) {
            akademik_setoran_penerima_set_aktif($pdo, $peran, $refId, $aktif);
            set_flash('success', $aktif ? 'Penerima setoran diaktifkan.' : 'Penerima setoran dinonaktifkan.');
        }
        header('Location: ' . app_href('/akademik/setoran_penerima.php'));
        exit;
    }
    if ($action === 'hapus_penerima') {
        $peran = trim((string) ($_POST['peran'] ?? ''));
        $refId = (int) ($_POST['ref_id'] ?? 0);
        if (in_array($peran, ['pembimbing', 'munawib'], true) && $refId > 0) {
            akademik_setoran_penerima_hapus($pdo, $peran, $refId);
            set_flash('success', 'Penugasan penerima setoran dihapus.');
        }
        header('Location: ' . app_href('/akademik/setoran_penerima.php'));
        exit;
    }
}

$filterPeran = trim((string) ($_GET['peran'] ?? ''));
$tkPeran = in_array($filterPeran, ['pembimbing', 'munawib'], true) ? $filterPeran : 'pembimbing';
$tkSelectedId = (int) ($_GET['ref_id'] ?? $_GET['pembimbing_id'] ?? $_GET['munawib_id'] ?? 0);

if ($tab === 'tambah') {
    $penerimaList = [];
    $kandidat = akademik_setoran_penerima_kandidat($pdo);
    $tkPenerimaRows = [];
    $tkSelectedList = [];
} elseif ($tab === 'tingkatan') {
    $penerimaList = [];
    $kandidat = ['pembimbing' => [], 'munawib' => []];
    if ($tkPeran === 'munawib') {
        $tkPenerimaRows = $pdo->query('
            SELECT m.id, m.nama, m.nip
            FROM munawib m
            INNER JOIN akademik_penerima_setoran ps ON ps.peran = "munawib" AND ps.ref_id = m.id AND ps.is_aktif = 1
            WHERE COALESCE(m.is_aktif, 1) = 1
            ORDER BY ' . munawib_list_order_by_induk_sql('m') . '
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($tkSelectedId <= 0 && $tkPenerimaRows !== []) {
            $tkSelectedId = (int) ($tkPenerimaRows[0]['id'] ?? 0);
        }
        $tkSelectedList = $tkSelectedId > 0 ? akademik_setoran_munawib_tingkatan_list($pdo, $tkSelectedId) : [];
    } else {
        $tkPenerimaRows = $pdo->query('
            SELECT p.id, p.nama_pembimbing AS nama, p.nip
            FROM pembimbing p
            INNER JOIN akademik_penerima_setoran ps ON ps.peran = "pembimbing" AND ps.ref_id = p.id AND ps.is_aktif = 1
            WHERE COALESCE(p.is_aktif, 1) = 1
            ORDER BY ' . pembimbing_list_order_sql('p') . '
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($tkSelectedId <= 0 && $tkPenerimaRows !== []) {
            $tkSelectedId = (int) ($tkPenerimaRows[0]['id'] ?? 0);
        }
        $tkSelectedList = $tkSelectedId > 0 ? akademik_setoran_pembimbing_tingkatan_list($pdo, $tkSelectedId) : [];
    }
} else {
    $penerimaList = akademik_setoran_penerima_list($pdo, $filterPeran !== '' ? $filterPeran : null, true);
    $kandidat = ['pembimbing' => [], 'munawib' => []];
    $tkPenerimaRows = [];
    $tkSelectedList = [];
}

$ringkas = ['total' => 0, 'aktif' => 0, 'siap' => 0, 'belum_tingkatan' => 0];
if ($tab === 'data') {
    $ringkas['total'] = count($penerimaList);
    foreach ($penerimaList as $p) {
        if (!empty($p['is_aktif'])) {
            $ringkas['aktif']++;
        }
        if (!empty($p['siap_terima'])) {
            $ringkas['siap']++;
        }
        if (!empty($p['is_aktif']) && ($p['tingkatan'] ?? []) === []) {
            $ringkas['belum_tingkatan']++;
        }
    }
}

$pageTitle = 'Penerima Setoran';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/akademik/setoran_dashboard.php')) ?>">Setoran</a></p>
    <h1 class="h4 mb-1">Penerima setoran (terpusat)</h1>
    <p class="text-muted small mb-0">
        Satu tempat untuk menugaskan pembimbing/munawib penerima setoran harian dan tingkatan yang boleh diterima.
        Setelah ditugaskan dan <strong>aktif</strong>, petugas otomatis bisa masuk portal via <strong>Input setoran hafalan</strong> (scan kartu).
    </p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_dashboard.php')) ?>">Dashboard setoran</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_rekap.php')) ?>">Rekap setoran</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/bait_kitab.php')) ?>">Pengaturan bait</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>">Preview portal scan</a>
</div>

<?php if ($tab === 'data'): ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-2 text-center">
            <div class="small text-muted">Terdaftar</div>
            <div class="h5 mb-0"><?= (int) $ringkas['total'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-success"><div class="card-body py-2 text-center">
            <div class="small text-muted">Aktif (bisa login)</div>
            <div class="h5 mb-0 text-success"><?= (int) $ringkas['aktif'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-primary"><div class="card-body py-2 text-center">
            <div class="small text-muted">Siap scan santri</div>
            <div class="h5 mb-0 text-primary"><?= (int) $ringkas['siap'] ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 border-warning"><div class="card-body py-2 text-center">
            <div class="small text-muted">Belum tingkatan</div>
            <div class="h5 mb-0 text-warning"><?= (int) $ringkas['belum_tingkatan'] ?></div>
        </div></div>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'data' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php')) ?>">Daftar penerima</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'tambah' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tambah')) ?>">Tugaskan baru</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'tingkatan' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tingkatan')) ?>">Tingkatan penerima</a>
    </li>
</ul>

<?php if ($tab === 'tingkatan'): ?>
    <?php
    $tkPeran = $tkPeran;
    require __DIR__ . '/partials/setoran_penerima_tingkatan_panel.php';
    ?>

<?php elseif ($tab === 'tambah'): ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tugaskan pembimbing</div>
            <div class="card-body">
                <?php if (($kandidat['pembimbing'] ?? []) === []): ?>
                    <p class="text-muted small mb-0">Semua pembimbing aktif sudah ditugaskan.</p>
                <?php else: ?>
                    <input type="search" class="form-control form-control-sm mb-2 st-penerima-cari" placeholder="Cari nama / NIP…" data-target="sel-pb-penerima" autocomplete="off">
                    <form method="post">
                        <input type="hidden" name="action" value="tugaskan_penerima">
                        <input type="hidden" name="peran" value="pembimbing">
                        <div class="mb-2">
                            <label class="form-label small mb-0">Pembimbing</label>
                            <select name="ref_id" id="sel-pb-penerima" class="form-select form-select-sm" required>
                                <option value="">— Pilih —</option>
                                <?php foreach ($kandidat['pembimbing'] as $pb): ?>
                                    <option value="<?= (int) ($pb['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($pb['nama'] ?? '')) ?> (<?= htmlspecialchars((string) ($pb['nip'] ?? '')) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($tingkatanList !== []): ?>
                        <p class="small text-muted mb-1">Tingkatan (opsional, bisa diatur nanti):</p>
                        <div class="row g-1 mb-2" style="max-height:160px;overflow:auto">
                            <?php foreach ($tingkatanList as $tk): ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tingkatan[]" value="<?= htmlspecialchars($tk) ?>" id="tambah-pb-<?= htmlspecialchars(md5($tk)) ?>">
                                        <label class="form-check-label small" for="tambah-pb-<?= htmlspecialchars(md5($tk)) ?>"><?= htmlspecialchars($tk) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">Tugaskan &amp; aktifkan</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tugaskan munawib</div>
            <div class="card-body">
                <?php if (($kandidat['munawib'] ?? []) === []): ?>
                    <p class="text-muted small mb-0">Semua munawib aktif sudah ditugaskan.</p>
                <?php else: ?>
                    <input type="search" class="form-control form-control-sm mb-2 st-penerima-cari" placeholder="Cari nama / NIP…" data-target="sel-mw-penerima" autocomplete="off">
                    <form method="post">
                        <input type="hidden" name="action" value="tugaskan_penerima">
                        <input type="hidden" name="peran" value="munawib">
                        <div class="mb-2">
                            <label class="form-label small mb-0">Munawib</label>
                            <select name="ref_id" id="sel-mw-penerima" class="form-select form-select-sm" required>
                                <option value="">— Pilih —</option>
                                <?php foreach ($kandidat['munawib'] as $mw): ?>
                                    <option value="<?= (int) ($mw['id'] ?? 0) ?>">
                                        <?= htmlspecialchars((string) ($mw['nama'] ?? '')) ?> (<?= htmlspecialchars((string) ($mw['nip'] ?? '')) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($tingkatanList !== []): ?>
                        <p class="small text-muted mb-1">Tingkatan (opsional):</p>
                        <div class="row g-1 mb-2" style="max-height:160px;overflow:auto">
                            <?php foreach ($tingkatanList as $tk): ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tingkatan[]" value="<?= htmlspecialchars($tk) ?>" id="tambah-mw-<?= htmlspecialchars(md5($tk)) ?>">
                                        <label class="form-check-label small" for="tambah-mw-<?= htmlspecialchars(md5($tk)) ?>"><?= htmlspecialchars($tk) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">Tugaskan &amp; aktifkan</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="hidden" name="tab" value="data">
    <label class="small text-muted mb-0">Filter</label>
    <select name="peran" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="">Semua peran</option>
        <option value="pembimbing"<?= $filterPeran === 'pembimbing' ? ' selected' : '' ?>>Pembimbing</option>
        <option value="munawib"<?= $filterPeran === 'munawib' ? ' selected' : '' ?>>Munawib</option>
    </select>
    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tambah')) ?>"><i class="fa-solid fa-plus me-1"></i> Tugaskan baru</a>
</form>

<?php if ($penerimaList === []): ?>
    <div class="alert alert-warning mb-0">
        Belum ada penerima setoran. Gunakan tab <a href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tambah')) ?>">Tugaskan baru</a>.
    </div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Peran</th>
                    <th>Nama</th>
                    <th>NIP</th>
                    <th>Tingkatan</th>
                    <th class="text-end">Santri</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($penerimaList as $p): ?>
                    <?php
                    $peran = (string) ($p['peran'] ?? '');
                    $refId = (int) ($p['ref_id'] ?? 0);
                    $tkList = $p['tingkatan'] ?? [];
                    $aktif = !empty($p['is_aktif']);
                    $siap = !empty($p['siap_terima']);
                    ?>
                    <tr>
                        <td><span class="badge text-bg-<?= $peran === 'pembimbing' ? 'primary' : 'warning' ?>"><?= $peran === 'pembimbing' ? 'Pembimbing' : 'Munawib' ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) ($p['nama'] ?? '')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($p['nip'] ?? '')) ?></td>
                        <td>
                            <?php if ($tkList === []): ?>
                                <span class="text-warning small">—</span>
                            <?php else: ?>
                                <?php foreach ($tkList as $tk): ?>
                                    <span class="badge text-bg-light border me-1"><?= htmlspecialchars((string) $tk) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) ($p['jumlah_santri'] ?? 0) ?></td>
                        <td>
                            <?php if ($aktif): ?>
                                <span class="badge text-bg-success">Bisa login portal</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Nonaktif</span>
                            <?php endif; ?>
                            <?php if ($siap): ?><span class="badge text-bg-primary ms-1">Siap scan</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php?tab=tingkatan&peran=' . $peran . '&ref_id=' . $refId)) ?>">Tingkatan</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Ubah status?');">
                                <input type="hidden" name="action" value="toggle_aktif">
                                <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                                <input type="hidden" name="ref_id" value="<?= $refId ?>">
                                <input type="hidden" name="aktif" value="<?= $aktif ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $aktif ? 'Nonaktif' : 'Aktifkan' ?></button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus penugasan ini?');">
                                <input type="hidden" name="action" value="hapus_penerima">
                                <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                                <input type="hidden" name="ref_id" value="<?= $refId ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'tambah'): ?>
<script>
(function () {
    document.querySelectorAll('.st-penerima-cari').forEach(function (inp) {
        var sel = document.getElementById(inp.getAttribute('data-target') || '');
        if (!sel) return;
        var opts = Array.prototype.slice.call(sel.options);
        inp.addEventListener('input', function () {
            var q = (inp.value || '').toLowerCase().trim();
            opts.forEach(function (opt, i) {
                if (i === 0) return;
                var show = q === '' || (opt.textContent || '').toLowerCase().indexOf(q) >= 0;
                opt.hidden = !show;
                opt.disabled = !show;
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
