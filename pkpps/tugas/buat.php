<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';

require_once __DIR__ . '/../../helpers/pkpps.php';
require_once __DIR__ . '/../../helpers/ikhtibar_import.php';
require_once __DIR__ . '/../../helpers/ikhtibar_google_import.php';
require_once __DIR__ . '/../../helpers/ikhtibar_ai_parse.php';
require_once __DIR__ . '/../../helpers/ikhtibar_preview.php';
require_once __DIR__ . '/../../helpers/ikhtibar_tugas_draf_pin.php';
require_once __DIR__ . '/../../helpers/excel.php';

pkpps_tugas_require_access();
ensure_akademik_ikhtibar_tables($pdo);
pkpps_ensure_schema($pdo);
ensure_santri_identity_columns($pdo);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_soal_ikhtibar.xlsx', ikhtibar_import_template_xlsx_rows(), 'Template Soal Ikhtibar');
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$pkppsJadwalOptions = ikhtibar_pkpps_jadwal_options($pdo, $userId);
require_once __DIR__ . '/../../helpers/ikhtibar_kriteria.php';
$kriteriaList = ikhtibar_kriteria_list($pdo, true);
$roleUser = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$wajibPilihJadwal = !is_super_admin() && !in_array($roleUser, ['admin', 'pengurus'], true);
$id = (int) ($_GET['id'] ?? 0);
$tugas = $id > 0 ? ikhtibar_tugas_by_id($pdo, $id) : null;
if (is_array($tugas)) {
    pkpps_tugas_redirect_jika_bukan($tugas);
}
$soalExisting = $tugas ? ikhtibar_soal_by_tugas($pdo, $id) : [];
$base = pkpps_tugas_base_path();
$googleTemplateUrl = ikhtibar_google_sheets_template_url($pdo);
$aiOcrEnabled = ikhtibar_ai_ocr_enabled($pdo);

if ($tugas) {
    ikhtibar_tugas_process_akses_pin_verify_post($pdo, $tugas, app_href($base . '/buat.php?id=' . $id));
    $tugas = ikhtibar_tugas_by_id($pdo, $id) ?? $tugas;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'simpan'));
    if ($action === 'token_baru' && $id > 0) {
        $plain = ikhtibar_set_token($pdo, $id);
        set_flash('success', 'Token baru: ' . $plain);
        header('Location: ' . app_href($base . '/buat.php?id=' . $id));
        exit;
    }
    $result = pkpps_tugas_simpan_dari_post($pdo, $_POST, $_FILES, $userId);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href($base . '/buat.php?id=' . (int) ($result['id'] ?? $id)));
    exit;
}

$kuotaPg = ikhtibar_kuota_pg();
$kuotaEsai = ikhtibar_kuota_esai();
$jumlahPg = (int) ($tugas['jumlah_pg'] ?? (int) ($_GET['pg'] ?? 10));
$jumlahEsai = (int) ($tugas['jumlah_esai'] ?? (int) ($_GET['esai'] ?? 0));
if (!in_array($jumlahPg, $kuotaPg, true)) {
    $jumlahPg = 10;
}
if (!in_array($jumlahEsai, $kuotaEsai, true)) {
    $jumlahEsai = 0;
}

$pgSoal = [];
$esaiSoal = [];
foreach ($soalExisting as $s) {
    if ((string) ($s['jenis'] ?? '') === 'PG') {
        $pgSoal[(int) $s['nomor']] = $s;
    } else {
        $esaiSoal[(int) $s['nomor']] = $s;
    }
}

$tingkatanList = [];
if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
    $tingkatanList = $pdo->query('SELECT DISTINCT tingkatan FROM santri WHERE tingkatan IS NOT NULL AND tingkatan <> "" ORDER BY tingkatan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$pageTitle = $tugas ? 'Edit Tugas PKPPS' : 'Buat Tugas PKPPS';
$statusTugas = (string) ($tugas['status'] ?? 'draft');
$perluBuatPinTugas = ikhtibar_tugas_perlu_buat_akses_pin($tugas);
$drafPinTerkunci = $tugas && ikhtibar_tugas_akses_pin_terkunci($tugas);
$bolehPratinjau = $tugas && (!ikhtibar_tugas_status_draf($tugas) || !$drafPinTerkunci);

if ($drafPinTerkunci) {
    $pageTitle = 'PIN Draf — Edit Tugas PKPPS';
    require_once __DIR__ . '/../../includes/header.php';
    echo ikhtibar_tugas_render_akses_pin_gate_html(
        $tugas,
        app_href($base . '/index.php'),
        'Daftar tugas PKPPS',
        'mengedit tugas draf'
    );
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>">Tugas PKPPS</a> · <a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a></p>
    <h1 class="h4 mb-0"><?= $tugas ? 'Edit' : 'Buat' ?> Tugas PKPPS</h1>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($tugas && ikhtibar_tugas_akses_pin_plain($tugas) !== ''): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <strong>Super admin — PIN draf tugas:</strong>
        <code class="user-select-all"><?= htmlspecialchars(ikhtibar_tugas_akses_pin_plain($tugas)) ?></code>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-ikhtibar">
    <?php if ($tugas): ?><input type="hidden" name="id" value="<?= (int) $tugas['id'] ?>"><?php endif; ?>

    <?php if ($perluBuatPinTugas && $statusTugas === 'draft'): ?>
        <?= ikhtibar_tugas_render_akses_pin_buat_html() ?>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold small">1. Jadwal PKPPS &amp; periode</div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Jadwal PKPPS (pembimbing Anda)</label>
                    <select name="pkpps_jadwal_id" class="form-select"<?= $wajibPilihJadwal ? ' required' : '' ?>>
                        <option value="">— Pilih jadwal PKPPS —</option>
                        <?php
                        $selPkpps = (int) ($tugas['pkpps_jadwal_id'] ?? 0);
                        foreach ($pkppsJadwalOptions as $pj):
                            ?>
                            <option value="<?= (int) $pj['id'] ?>" <?= $selPkpps === (int) $pj['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $pj['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($pkppsJadwalOptions === []): ?>
                        <div class="form-text text-warning">Belum ada jadwal PKPPS dengan NIP Anda. Atur di menu PKPPS → Jadwal.</div>
                    <?php else: ?>
                        <div class="form-text">Tugas hanya untuk santri aktif di tingkatan PKPPS jadwal ini.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Judul tugas</label>
                    <input type="text" name="judul" class="form-control" required maxlength="200" value="<?= htmlspecialchars((string) ($tugas['judul'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kelompok / bab</label>
                    <input type="text" name="kelompok_label" class="form-control" maxlength="200" placeholder="Contoh: Bab 1" value="<?= htmlspecialchars((string) ($tugas['kelompok_label'] ?? '')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Urutan</label>
                    <input type="number" name="urutan_kelompok" class="form-control" min="0" max="999" value="<?= (int) ($tugas['urutan_kelompok'] ?? 0) ?>">
                </div>
                <div class="col-md-3" id="wrap-tgl-mulai">
                    <label class="form-label">Tanggal mulai</label>
                    <input type="date" name="tanggal" id="tanggal_mulai" class="form-control" required value="<?= htmlspecialchars((string) ($tugas['tanggal'] ?? date('Y-m-d'))) ?>">
                </div>
                <div class="col-md-3 d-none" id="wrap-tgl-selesai">
                    <label class="form-label">Tanggal selesai</label>
                    <input type="date" name="tanggal_selesai" id="tanggal_selesai" class="form-control"
                           value="<?= htmlspecialchars((string) ($tugas['tanggal_selesai'] ?? ($tugas['tanggal'] ?? date('Y-m-d', strtotime('+3 days'))))) ?>">
                    <div class="form-text">Tugas tanpa esai dapat dikerjakan beberapa hari.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Hari</label>
                    <select name="hari_ke" class="form-select">
                        <?php for ($h = 1; $h <= 7; $h++): ?>
                            <option value="<?= $h ?>" <?= (int) ($tugas['hari_ke'] ?? (int) date('N')) === $h ? 'selected' : '' ?>><?= ikhtibar_hari_label($h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3" id="wrap-durasi">
                    <label class="form-label">Durasi (menit)</label>
                    <input type="number" name="durasi_menit" id="durasi_menit" class="form-control" min="5" max="300" value="<?= (int) ($tugas['durasi_menit'] ?? 60) ?>">
                    <div class="form-text">Hitung mundur saat santri mulai (hanya tugas ber-esai).</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter tingkatan</label>
                    <select name="filter_tingkatan" class="form-select">
                        <option value="">Semua tingkatan</option>
                        <?php foreach ($tingkatanList as $tk): ?>
                            <option value="<?= htmlspecialchars((string) $tk) ?>" <?= ($tugas['filter_tingkatan'] ?? '') === $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Catatan (opsional)</label>
                    <input type="text" name="catatan" class="form-control" value="<?= htmlspecialchars((string) ($tugas['catatan'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="pakai_token" id="pakai_token" value="1" <?= !$tugas || (int) ($tugas['pakai_token'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pakai_token">Aktifkan <strong>Token Kunci</strong> (santri wajib memasukkan token sebelum membuka soal)</label>
                    </div>
                    <?php if ($tugas && (int) ($tugas['pakai_token'] ?? 0) === 1 && !empty($tugas['token_plain'])): ?>
                        <p class="small mt-2 mb-0">Token saat ini: <code class="user-select-all fs-6"><?= htmlspecialchars((string) $tugas['token_plain']) ?></code>
                            <button type="submit" name="action" value="token_baru" class="btn btn-sm btn-outline-warning ms-2">Buat token baru</button>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold small">2. Kuota &amp; input soal</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Pilihan ganda (PG)</label>
                    <select name="jumlah_pg" id="jumlah_pg" class="form-select">
                        <?php foreach ($kuotaPg as $k): ?>
                            <option value="<?= $k ?>" <?= $jumlahPg === $k ? 'selected' : '' ?>><?= $k ?> soal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Isian singkat / Esai</label>
                    <select name="jumlah_esai" id="jumlah_esai" class="form-select">
                        <option value="0" <?= $jumlahEsai === 0 ? 'selected' : '' ?>>Tidak ada</option>
                        <?php foreach ($kuotaEsai as $k): ?>
                            <option value="<?= $k ?>" <?= $jumlahEsai === $k ? 'selected' : '' ?>><?= $k ?> soal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="border rounded p-3 mb-3 bg-light-subtle">
                <h3 class="h6">Metode input cerdas</h3>
                <p class="small text-muted mb-2">Word, Excel, Google Sheets/Docs, atau foto OCR.<?php if ($aiOcrEnabled): ?> <span class="badge text-bg-info">AI OCR aktif</span><?php endif; ?></p>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Kamera / foto soal (OCR Arab)</label>
                        <input type="file" id="ocr_file" accept="image/*" capture="environment" class="form-control form-control-sm">
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btn-ocr-run"><i class="fa-solid fa-camera me-1"></i> Scan teks Arab</button>
                        <div id="ocr_status" class="small text-muted mt-1"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Upload Word (.docx)</label>
                        <input type="file" name="import_docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Upload Excel (.xlsx)</label>
                        <input type="file" name="import_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm">
                        <a class="small d-inline-block mt-1" href="<?= htmlspecialchars(app_href($base . '/buat.php?template=xlsx')) ?>">Unduh template Excel</a>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Link Google Sheets</label>
                        <input type="url" name="import_google_sheet" class="form-control form-control-sm" placeholder="https://docs.google.com/spreadsheets/d/...">
                        <?php if ($googleTemplateUrl !== ''): ?>
                            <a class="small d-inline-block mt-1" href="<?= htmlspecialchars($googleTemplateUrl) ?>" target="_blank" rel="noopener">Template Google Sheets</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small">Link Google Docs</label>
                        <input type="url" name="import_google_doc" class="form-control form-control-sm" placeholder="https://docs.google.com/document/d/...">
                    </div>
                </div>
                <label class="form-label small mt-2">Hasil OCR / tempel teks (opsional)</label>
                <textarea name="ocr_teks_import" id="ocr_teks_import" class="form-control font-monospace small" rows="4" placeholder="Teks hasil scan atau tempel manual…"></textarea>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-preview-import"><i class="fa-solid fa-eye me-1"></i> Pratinjau import</button>
                </div>
            </div>

            <div id="wrap-pg"></div>
            <div id="wrap-esai" class="mt-3"></div>
            <?php if ($kriteriaList !== []): ?>
            <div class="alert alert-light border small mt-3 mb-0">
                <strong>Kunci esai + nilai otomatis:</strong> gunakan format <code>[KODE] kata1, kata2</code> per baris.
                Kriteria aktif:
                <?php foreach ($kriteriaList as $kr): ?>
                    <span class="badge text-bg-secondary me-1"><?= htmlspecialchars((string) ($kr['kode'] ?? '')) ?> (<?= htmlspecialchars((string) ($kr['bobot_persen'] ?? '0')) ?>%)</span>
                <?php endforeach; ?>
                · <a href="<?= htmlspecialchars(app_href('/settings/ikhtibar_kriteria.php')) ?>">Atur kriteria</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if ($tugas): ?>
        <?php if ($bolehPratinjau): ?>
            <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/pratinjau.php?tugas_id=' . (int) $tugas['id'])) ?>" class="btn btn-outline-info" target="_blank" rel="noopener"><i class="fa-solid fa-eye me-1"></i> Pratinjau</a>
        <?php elseif ($tugas && ikhtibar_tugas_status_draf($tugas)): ?>
            <span class="small text-muted align-self-center">Pratinjau membutuhkan PIN draf.</span>
        <?php endif; ?>
        <?php endif; ?>
        <button type="submit" name="action" value="simpan" class="btn btn-outline-secondary">Simpan draf</button>
        <button type="submit" name="publish" value="1" class="btn btn-primary">Publikasikan tugas</button>
        <a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>" class="btn btn-link">Batal</a>
    </div>
</form>

<div class="modal fade" id="modal-preview-import" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2"><h2 class="modal-title h6">Pratinjau soal</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-2" id="preview-import-body" style="background:#f1f5f9;max-height:70vh;overflow-y:auto;"></div>
            <div class="modal-footer py-2 flex-wrap">
                <div id="preview-import-errors" class="small text-danger me-auto"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-apply-preview" disabled>Terapkan ke form</button>
            </div>
        </div>
    </div>
</div>
<link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/wali-portal.css')) ?>">
<?php ikhtibar_soal_typography_head(); ?>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
(function () {
    var pgData = <?= json_encode($pgSoal, JSON_UNESCAPED_UNICODE) ?>;
    var esaiData = <?= json_encode($esaiSoal, JSON_UNESCAPED_UNICODE) ?>;
    var wrapPg = document.getElementById('wrap-pg');
    var wrapEsai = document.getElementById('wrap-esai');
    var selPg = document.getElementById('jumlah_pg');
    var selEsai = document.getElementById('jumlah_esai');
    var OPSI_LABELS = {2: 'A–B', 3: 'A–C', 4: 'A–D', 5: 'A–E'};
    var OPSI_COLS = ['a', 'b', 'c', 'd', 'e'];

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function getPgJumlahOpsi(row) {
        var j = parseInt(row.pg_jumlah_opsi || row.jumlah_opsi || 0, 10);
        if (j >= 2 && j <= 5) return j;
        if ((row.opsi_e || '').trim() !== '' || (row.kunci_jawaban || '') === 'E') return 5;
        return 4;
    }

    function bindPgOpsiToggle() {
        if (!wrapPg) return;
        wrapPg.querySelectorAll('.pg-jumlah-opsi').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var soalIdx = sel.getAttribute('data-soal');
                var jOpsi = parseInt(sel.value, 10) || 4;
                OPSI_COLS.forEach(function (L, idx) {
                    var wrap = wrapPg.querySelector('.pg-opsi-' + L + '-' + soalIdx);
                    if (wrap) wrap.classList.toggle('d-none', idx >= jOpsi);
                });
                var kunciSel = wrapPg.querySelector('.pg-kunci-select[data-soal="' + soalIdx + '"]');
                if (!kunciSel) return;
                var cur = kunciSel.value;
                var html = '<option value="">Kunci</option>';
                for (var k = 0; k < jOpsi; k++) {
                    var K = ['A', 'B', 'C', 'D', 'E'][k];
                    html += '<option value="' + K + '"' + (cur === K ? ' selected' : '') + '>' + K + '</option>';
                }
                kunciSel.innerHTML = html;
            });
        });
    }

    function renderPg(n) {
        if (!wrapPg) return;
        var html = '<h3 class="h6 border-bottom pb-2">Soal Pilihan Ganda (' + n + ')</h3>';
        html += '<p class="small text-muted">Pilih jumlah opsi per soal (A–D atau A–E). Teks Arab/Latin didukung.</p>';
        for (var i = 1; i <= n; i++) {
            var row = pgData[i] || {};
            var jOpsi = getPgJumlahOpsi(row);
            html += '<div class="border rounded p-2 mb-2"><div class="fw-semibold small text-muted mb-1">Soal ' + i + '</div>';
            html += '<div class="d-flex flex-wrap align-items-center gap-2 mb-1"><label class="small text-muted mb-0">Pilihan sampai</label>';
            html += '<select name="pg_jumlah_opsi[' + i + ']" class="form-select form-select-sm pg-jumlah-opsi" data-soal="' + i + '" style="max-width:130px">';
            [2, 3, 4, 5].forEach(function (v) {
                html += '<option value="' + v + '"' + (jOpsi === v ? ' selected' : '') + '>' + OPSI_LABELS[v] + '</option>';
            });
            html += '</select></div>';
            html += '<textarea name="pg_teks[' + i + ']" class="form-control form-control-sm mb-1 ikhtibar-soal-input" dir="auto" rows="2" required>' + esc(row.teks_soal || '') + '</textarea>';
            html += '<div class="row g-1 small">';
            OPSI_COLS.forEach(function (L, idx) {
                var hidden = idx >= jOpsi ? ' d-none' : '';
                html += '<div class="col pg-opsi-wrap pg-opsi-' + L + '-' + i + hidden + '">';
                html += '<input name="pg_' + L + '[' + i + ']" class="form-control form-control-sm ikhtibar-soal-input" dir="auto" placeholder="' + L.toUpperCase() + '" value="' + esc(row['opsi_' + L] || '') + '">';
                html += '</div>';
            });
            html += '</div><div class="mt-1"><select name="pg_kunci[' + i + ']" class="form-select form-select-sm pg-kunci-select" data-soal="' + i + '" style="max-width:100px" required>';
            html += '<option value="">Kunci</option>';
            for (var k = 0; k < jOpsi; k++) {
                var K = ['A', 'B', 'C', 'D', 'E'][k];
                html += '<option value="' + K + '"' + ((row.kunci_jawaban || '') === K ? ' selected' : '') + '>' + K + '</option>';
            }
            html += '</select></div></div>';
        }
        wrapPg.innerHTML = html;
        bindPgOpsiToggle();
    }

    function renderEsai(n) {
        if (!wrapEsai) return;
        if (n < 1) { wrapEsai.innerHTML = ''; return; }
        var html = '<h3 class="h6 border-bottom pb-2">Soal Esai (' + n + ')</h3>';
        for (var i = 1; i <= n; i++) {
            var row = esaiData[i] || {};
            html += '<div class="border rounded p-2 mb-2"><div class="fw-semibold small text-muted mb-1">Esai ' + i + '</div>';
            html += '<textarea name="esai_teks[' + i + ']" class="form-control form-control-sm mb-1 ikhtibar-soal-input" dir="auto" rows="2">' + esc(row.teks_soal || '') + '</textarea>';
            html += '<textarea name="esai_kunci[' + i + ']" class="form-control form-control-sm mb-1 ikhtibar-soal-input" dir="auto" rows="3" placeholder="Kunci per kriteria">' + esc(row.kunci_jawaban || '') + '</textarea>';
            html += '<div class="input-group input-group-sm"><span class="input-group-text">Bobot</span><input name="esai_bobot[' + i + ']" type="number" min="1" max="100" class="form-control" value="' + esc(String(row.bobot_nilai || '100')) + '"></div>';
            html += '</div>';
        }
        wrapEsai.innerHTML = html;
    }

    var wrapDurasi = document.getElementById('wrap-durasi');
    var wrapTglSelesai = document.getElementById('wrap-tgl-selesai');
    var inpDurasi = document.getElementById('durasi_menit');

    function syncTanpaEsai() {
        var tanpaEsai = parseInt(selEsai.value, 10) === 0;
        if (wrapDurasi) wrapDurasi.classList.toggle('d-none', tanpaEsai);
        if (wrapTglSelesai) wrapTglSelesai.classList.toggle('d-none', !tanpaEsai);
        if (inpDurasi) {
            inpDurasi.required = !tanpaEsai;
            if (tanpaEsai) inpDurasi.value = '0';
        }
    }

    function refresh() {
        renderPg(parseInt(selPg.value, 10) || 0);
        renderEsai(parseInt(selEsai.value, 10) || 0);
        syncTanpaEsai();
    }
    selPg.addEventListener('change', refresh);
    selEsai.addEventListener('change', refresh);
    refresh();

    var btnOcr = document.getElementById('btn-ocr-run');
    var ocrFile = document.getElementById('ocr_file');
    var ocrStatus = document.getElementById('ocr_status');
    var ocrTa = document.getElementById('ocr_teks_import');
    var formIkhtibar = document.getElementById('form-ikhtibar');
    var btnPreview = document.getElementById('btn-preview-import');
    var previewModalEl = document.getElementById('modal-preview-import');
    var previewBody = document.getElementById('preview-import-body');
    var previewErrors = document.getElementById('preview-import-errors');
    var btnApplyPreview = document.getElementById('btn-apply-preview');
    var previewModal = previewModalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(previewModalEl) : null;
    var lastPreviewSoal = null;
    var previewUrl = <?= json_encode(app_href('/pkpps/tugas/preview_import.php'), JSON_UNESCAPED_UNICODE) ?>;

    function structToFormData(soal) {
        if (!soal) return;
        pgData = {}; esaiData = {};
        Object.keys(soal.pg || {}).forEach(function (nom) {
            var r = soal.pg[nom];
            pgData[nom] = { teks_soal: r.teks || '', opsi_a: r.a || '', opsi_b: r.b || '', opsi_c: r.c || '', opsi_d: r.d || '', opsi_e: r.e || '', pg_jumlah_opsi: r.jumlah_opsi || (r.e ? 5 : 4), kunci_jawaban: r.kunci || '' };
        });
        Object.keys(soal.esai || {}).forEach(function (nom) {
            var r = soal.esai[nom];
            esaiData[nom] = { teks_soal: r.teks || '', kunci_jawaban: r.kunci || '', bobot_nilai: r.bobot || 100 };
        });
        refresh();
    }

    function runPreviewImport() {
        if (!formIkhtibar || !previewBody) return;
        previewBody.innerHTML = '<p class="text-muted small mb-0">Memuat pratinjau…</p>';
        if (previewErrors) previewErrors.textContent = '';
        if (btnApplyPreview) btnApplyPreview.disabled = true;
        lastPreviewSoal = null;
        if (previewModal) previewModal.show();
        var fd = new FormData(formIkhtibar);
        fetch(previewUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                previewBody.innerHTML = data.html || '<p class="text-muted small">Tidak ada soal.</p>';
                if (previewErrors && data.errors && data.errors.length) previewErrors.textContent = data.errors.join(' · ');
                if (data.soal && ((data.count_pg || 0) + (data.count_esai || 0)) > 0) {
                    lastPreviewSoal = data.soal;
                    if (btnApplyPreview) btnApplyPreview.disabled = false;
                }
            })
            .catch(function () { previewBody.innerHTML = '<p class="text-danger small">Gagal memuat pratinjau.</p>'; });
    }

    if (btnPreview) btnPreview.addEventListener('click', runPreviewImport);
    if (btnApplyPreview) btnApplyPreview.addEventListener('click', function () { structToFormData(lastPreviewSoal); if (previewModal) previewModal.hide(); });

    if (btnOcr && ocrFile) {
        btnOcr.addEventListener('click', function () {
            if (!ocrFile.files || !ocrFile.files[0]) {
                if (ocrStatus) ocrStatus.textContent = 'Pilih atau ambil foto terlebih dahulu.';
                return;
            }
            if (typeof Tesseract === 'undefined') {
                if (ocrStatus) ocrStatus.textContent = 'OCR tidak tersedia di browser ini.';
                return;
            }
            if (ocrStatus) ocrStatus.textContent = 'Memproses OCR (Bahasa Arab)…';
            btnOcr.disabled = true;
            Tesseract.recognize(ocrFile.files[0], 'ara', { logger: function (m) {
                if (m.status === 'recognizing text' && ocrStatus) ocrStatus.textContent = 'OCR: ' + Math.round((m.progress || 0) * 100) + '%';
            }}).then(function (res) {
                if (ocrTa) ocrTa.value = (res.data && res.data.text) ? res.data.text.trim() : '';
                if (ocrStatus) ocrStatus.textContent = 'OCR selesai. Membuka pratinjau…';
                btnOcr.disabled = false;
                runPreviewImport();
            }).catch(function () {
                if (ocrStatus) ocrStatus.textContent = 'OCR gagal. Coba foto lebih jelas atau input manual.';
                btnOcr.disabled = false;
            });
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
