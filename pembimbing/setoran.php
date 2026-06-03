<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';

if (!isset($_SESSION['user'])) {
    app_redirect('login.php?peran=pembimbing&act=qr&dest=setoran');
}

require_once __DIR__ . '/../helpers/akademik_setoran.php';
require_once __DIR__ . '/partials/setoran_portal_bootstrap.php';

$setoranNavActive = 'scan';
$scanSantriId = (int) ($_GET['santri_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan_setoran') {
    $result = akademik_setoran_simpan($pdo, $_POST, $ctx);
    if ($result['ok']) {
        set_flash('success', $result['message']);
        header('Location: ' . app_href('/pembimbing/setoran.php'));
        exit;
    }
    set_flash('error', $result['message']);
    header('Location: ' . app_href('/pembimbing/setoran.php?santri_id=' . (int) ($_POST['santri_id'] ?? 0)));
    exit;
}

$santriRow = null;
$kitabRows = [];
$perolehanMap = [];
$lastBarisMap = [];
$sudahHariIni = false;
$izinHariIni = false;
$liburNama = null;

if ($scanSantriId > 0) {
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $scanSantriId]);
    $santriRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($santriRow && !akademik_setoran_can_terima_santri($pdo, $santriRow, $ctx)) {
        set_flash('error', 'Santri di luar tingkatan yang Anda terima setoran.');
        header('Location: ' . app_href('/pembimbing/setoran.php'));
        exit;
    }
    if ($santriRow) {
        $tk = (string) ($santriRow['tingkatan'] ?? '');
        $kitabRows = akademik_setoran_kitab_rows_for_tingkatan($pdo, $tk);
        foreach ($kitabRows as $kr) {
            $kid = (int) ($kr['id'] ?? 0);
            $perolehanMap[$kid] = akademik_setoran_perolehan_bait($pdo, $scanSantriId, $kid);
            $lastBarisMap[$kid] = akademik_setoran_last_baris($pdo, $scanSantriId, $kid);
        }
        $sudahHariIni = akademik_setoran_sudah_hari_ini($pdo, $scanSantriId, $today);
        $izinHariIni = akademik_setoran_izin_atau_sakit($pdo, $scanSantriId, $today);
        $liburInfo = akademik_libur_info($pdo, $today, 'setoran');
        $liburNama = $liburInfo !== null ? (string) ($liburInfo['nama'] ?? 'Libur') : null;
    }
}

$pageTitle = 'Portal Setoran · Scan';
$bodyClass = 'setoran-portal-page st-portal-page pb-dash-bg-putih dash-page';
$pageStylesheets = [
    app_asset_href('/assets/css/pembimbing-dashboard.css'),
    app_asset_href('/assets/css/setoran-portal.css'),
    app_asset_href('/assets/css/presensi-scan.css'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page">
<div class="container py-3" style="max-width:640px">
    <?php require __DIR__ . '/partials/setoran_portal_head.php'; ?>
    <?php require __DIR__ . '/partials/setoran_portal_subnav.php'; ?>

    <?php if ($santriRow === null): ?>
    <div class="st-portal-scan-app presensi-scan-app">
        <div id="setoran-scan-status" class="presensi-scan-status is-waiting mb-2">Menyiapkan kamera…</div>
        <div class="presensi-scan-viewport setoran-scan-viewport shadow-sm mb-2">
            <div id="setoran-qr-reader" aria-label="Scan QR santri"></div>
            <div class="presensi-scan-frame" aria-hidden="true"><div class="presensi-scan-frame-box"></div></div>
        </div>
        <p class="text-center text-muted small mb-3">Arahkan kamera ke QR kartu santri — form muncul setelah scan berhasil.</p>
    </div>

    <?php require __DIR__ . '/partials/setoran_santri_list.php'; ?>

    <?php else: ?>
    <div class="card shadow-sm border-success mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-success"><i class="fa-solid fa-circle-check me-1"></i> Scan berhasil</span>
                <div>
                    <div class="fw-bold"><?= htmlspecialchars((string) $santriRow['nama_santri']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars((string) $santriRow['nis']) ?> · <?= htmlspecialchars((string) $santriRow['tingkatan']) ?></div>
                </div>
                <a class="btn btn-outline-secondary btn-sm ms-auto" href="<?= htmlspecialchars(app_href('/pembimbing/setoran.php')) ?>">
                    <i class="fa-solid fa-qrcode me-1"></i> Scan lain
                </a>
            </div>
        </div>
    </div>

    <?php if ($sudahHariIni): ?>
        <div class="alert alert-warning">Setoran hari ini sudah tercatat untuk santri ini.</div>
    <?php elseif ($izinHariIni): ?>
        <div class="alert alert-info">Santri tercatat izin/sakit hari ini — setoran tidak wajib.</div>
    <?php elseif ($liburNama !== null && akademik_blokir_setoran_libur($pdo)): ?>
        <div class="alert alert-secondary">Hari libur setoran: <?= htmlspecialchars($liburNama) ?></div>
    <?php elseif ($kitabRows === []): ?>
        <div class="alert alert-warning">Belum ada kitab bait untuk tingkatan <strong><?= htmlspecialchars((string) $santriRow['tingkatan']) ?></strong>.</div>
    <?php else: ?>
    <div class="card shadow-sm st-portal-form-card">
        <div class="card-header fw-semibold">Form setoran bait — <?= htmlspecialchars($today) ?></div>
        <div class="card-body">
            <form method="post" id="form-setoran-bait" class="d-grid gap-3">
                <input type="hidden" name="action" value="simpan_setoran">
                <input type="hidden" name="santri_id" value="<?= (int) $scanSantriId ?>">
                <input type="hidden" name="tanggal_setoran" value="<?= htmlspecialchars($today) ?>">
                <input type="hidden" name="jenis_entri" id="jenis_entri" value="HARIAN">

                <div>
                    <label class="form-label fw-semibold">Kitab bait <span class="text-danger">*</span></label>
                    <select name="bait_kitab_id" id="sel_kitab" class="form-select" required>
                        <option value="">— Pilih kitab —</option>
                        <?php foreach ($kitabRows as $kr): ?>
                            <?php $kid = (int) ($kr['id'] ?? 0); ?>
                            <option value="<?= $kid ?>"
                                    data-target="<?= (int) ($kr['target_baris_per_hari'] ?? 1) ?>"
                                    data-perolehan="<?= (int) ($perolehanMap[$kid] ?? 0) ?>"
                                    data-last="<?= (int) ($lastBarisMap[$kid] ?? 0) ?>"
                                    data-total="<?= (int) ($kr['jumlah_baris'] ?? 0) ?>">
                                <?= htmlspecialchars((string) $kr['nama_kitab']) ?>
                                (perolehan: <?= (int) ($perolehanMap[$kid] ?? 0) ?> / <?= (int) ($kr['jumlah_baris'] ?? 0) ?> baris)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Baris setor hari ini</label>
                        <input type="number" name="baris_setor" id="inp_baris" class="form-control form-control-lg text-center fw-bold" min="1" step="1" required>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary flex-fill" id="btn-tikror">
                                <i class="fa-solid fa-rotate-left me-1"></i> Tikror
                            </button>
                            <button type="button" class="btn btn-outline-success flex-fill" id="btn-harian">
                                <i class="fa-solid fa-plus me-1"></i> Tambah harian
                            </button>
                        </div>
                    </div>
                </div>

                <div id="setoran-hint" class="alert alert-light border small mb-0 py-2"></div>

                <div>
                    <label class="form-label">Catatan (opsional)</label>
                    <textarea name="catatan" class="form-control" rows="2"></textarea>
                </div>

                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Simpan setoran
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
(function () {
    function tick() {
        var now = new Date();
        var clock = document.getElementById('st-portal-live-clock');
        var date = document.getElementById('st-portal-live-date');
        if (clock) {
            clock.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        if (date) {
            date.textContent = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        }
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php if ($santriRow === null): ?>
<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var statusEl = document.getElementById('setoran-scan-status');
    var scanner = new PresensiScanCamera({
        readerId: 'setoran-qr-reader',
        statusEl: statusEl,
        onSubmit: function (code) {
            if (statusEl) {
                statusEl.textContent = 'Memverifikasi QR…';
                statusEl.className = 'presensi-scan-status is-waiting';
            }
            fetch(<?= json_encode(app_href('/api/setoran/santri_scan.php')) ?> + '?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    if (statusEl) {
                        statusEl.textContent = (data && data.message) || 'Scan gagal.';
                        statusEl.className = 'presensi-scan-status is-error';
                    }
                })
                .catch(function () {
                    if (statusEl) {
                        statusEl.textContent = 'Gagal memverifikasi QR.';
                        statusEl.className = 'presensi-scan-status is-error';
                    }
                });
        }
    });
    scanner.init();
})();
</script>
<?php else: ?>
<script>
(function () {
    var sel = document.getElementById('sel_kitab');
    var inp = document.getElementById('inp_baris');
    var hint = document.getElementById('setoran-hint');
    var jenis = document.getElementById('jenis_entri');
    var btnT = document.getElementById('btn-tikror');
    var btnH = document.getElementById('btn-harian');
    function opt() { return sel ? sel.options[sel.selectedIndex] : null; }
    function syncHint() {
        var o = opt();
        if (!hint || !o || !o.value) { if (hint) hint.textContent = 'Pilih kitab bait.'; return; }
        hint.innerHTML = 'Perolehan: <strong>' + (o.getAttribute('data-perolehan') || '0') + '</strong> / '
            + (o.getAttribute('data-total') || '0') + ' baris · Setoran terakhir: '
            + (o.getAttribute('data-last') || '0') + ' baris · Target harian: '
            + (o.getAttribute('data-target') || '1') + ' baris';
    }
    if (btnT) btnT.addEventListener('click', function () {
        var o = opt();
        if (!o || !inp) return;
        var last = parseInt(o.getAttribute('data-last') || '0', 10);
        inp.value = last > 0 ? last : parseInt(o.getAttribute('data-target') || '1', 10);
        if (jenis) jenis.value = 'TIKROR';
    });
    if (btnH) btnH.addEventListener('click', function () {
        var o = opt();
        if (!o || !inp) return;
        inp.value = parseInt(o.getAttribute('data-target') || '1', 10);
        if (jenis) jenis.value = 'HARIAN';
    });
    if (sel) sel.addEventListener('change', syncHint);
    syncHint();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
