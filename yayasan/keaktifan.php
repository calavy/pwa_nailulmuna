<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);

$tingkatanList = [];
if (table_exists($pdo, 'santri')) {
    $tingkatanList = $pdo->query(
        'SELECT DISTINCT TRIM(tingkatan) AS t FROM santri WHERE tingkatan IS NOT NULL AND TRIM(tingkatan)<>"" ORDER BY t'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$pageTitle = 'Rekap Keaktifan Hari Ini';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css'), app_asset_href('/assets/css/keaktifan-hari.css')];
$pageScripts = [app_asset_href('/assets/js/keaktifan-hari.js')];
$bodyClass = 'page-keaktifan-hari';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap kh-wrap">
    <div class="page-intro mb-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <?php $yayasanCrumbTail = 'Keaktifan hari ini'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
            <h1 class="h4 mb-1 d-flex align-items-center flex-wrap gap-2">
                Rekap Keaktifan Hari Ini
                <button type="button" class="btn btn-link btn-sm p-0 kh-panduan-btn d-md-none" data-bs-toggle="modal" data-bs-target="#khPanduanModal" aria-label="Cara membaca halaman ini">
                    <i class="fa-solid fa-circle-info fa-lg"></i>
                </button>
            </h1>
            <p class="text-muted mb-0 small">Scan gerbang · Santri, Pembimbing, Munawib, Jama'ah & Ta'lim</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">
                <i class="fa-solid fa-qrcode me-1"></i>Scan
            </a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3 yp-filter-bar kh-filter-form kh-section" method="get">
        <div class="col-12 col-md-2">
            <label class="form-label small mb-0">Tanggal</label>
            <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggal) ?>">
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label small mb-0">Kategori</label>
            <select name="kategori" class="form-select form-select-sm">
                <option value="" <?= $kategori === null ? 'selected' : '' ?>>Semua</option>
                <option value="JAMAAH" <?= $kategori === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah saja</option>
                <option value="TAALIM" <?= $kategori === 'TAALIM' ? 'selected' : '' ?>>Ta'lim saja</option>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label small mb-0">Tingkatan</label>
            <select name="tingkatan" class="form-select form-select-sm">
                <option value="">Semua kelas</option>
                <?php foreach ($tingkatanList as $tk): ?>
                    <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-primary btn-sm kh-filter-submit"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
        </div>
    </form>

    <div id="yp-keaktifan-mount">
        <div class="text-center text-muted py-5">
            <i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat rekap keaktifan…
        </div>
    </div>
</div>

<script>
window.__ypKeaktifanBoot = <?= json_encode([
    'api' => app_href('/api/yayasan/keaktifan_hari_content.php'),
], JSON_UNESCAPED_UNICODE) ?>;
(function () {
    var boot = window.__ypKeaktifanBoot || {};
    function runScriptsIn(root) {
        var scripts = Array.prototype.slice.call(root.querySelectorAll('script'));
        function runNext(i) {
            if (i >= scripts.length) return;
            var oldScript = scripts[i];
            var s = document.createElement('script');
            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                s.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                s.onload = function () { runNext(i + 1); };
                s.onerror = function () { runNext(i + 1); };
                oldScript.parentNode.replaceChild(s, oldScript);
            } else {
                s.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(s, oldScript);
                runNext(i + 1);
            }
        }
        runNext(0);
    }
    function loadKeaktifanContent() {
        var mount = document.getElementById('yp-keaktifan-mount');
        if (!mount || !boot.api) return;
        mount.innerHTML = '<div class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat rekap keaktifan…</div>';
        fetch(boot.api + (window.location.search || ''), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    mount.innerHTML = '<div class="alert alert-warning mb-0">Gagal memuat data keaktifan.</div>';
                    return;
                }
                mount.innerHTML = data.html || '';
                runScriptsIn(mount);
            })
            .catch(function () {
                mount.innerHTML = '<div class="alert alert-warning mb-0">Gagal memuat data keaktifan.</div>';
            });
    }
    document.addEventListener('yp:navigated', loadKeaktifanContent);
    loadKeaktifanContent();
})();
</script>

<div class="modal fade" id="khPanduanModal" tabindex="-1" aria-labelledby="khPanduanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="khPanduanModalLabel"><i class="fa-solid fa-circle-info me-1 text-primary"></i> Cara membaca</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body small">
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--hadir">Hadir</span> — santri sudah scan.</p>
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--izin">Izin</span> / <span class="kh-panduan__item kh-panduan__item--sakit">Sakit</span> — ada keterangan resmi.</p>
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> — tidak scan sampai jam kegiatan selesai (tanpa izin resmi).</p>
                <p class="mb-0">Ringkasan per kelas &amp; SDM ada di halaman ini. Detail per kegiatan/shalat buka lewat tautan <strong>Rekap per kegiatan</strong>.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
