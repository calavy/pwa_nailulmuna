<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';
require_once __DIR__ . '/../../helpers/ikhtibar_import.php';
require_once __DIR__ . '/../../helpers/excel.php';

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);
ensure_santri_identity_columns($pdo);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_soal_ikhtibar.xlsx', ikhtibar_import_template_xlsx_rows(), 'Template Soal Ikhtibar');
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$jadwalOptions = ikhtibar_jadwal_options($pdo, $userId);
require_once __DIR__ . '/../../helpers/ikhtibar_kriteria.php';
$kriteriaList = ikhtibar_kriteria_list($pdo, true);
$roleUser = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$wajibPilihMapel = !is_super_admin() && !in_array($roleUser, ['admin', 'pengurus'], true);
$id = (int) ($_GET['id'] ?? 0);
$tugas = $id > 0 ? ikhtibar_tugas_by_id($pdo, $id) : null;
if (is_array($tugas)) {
    ikhtibar_tugas_redirect_jika_pkpps($tugas);
}
$soalExisting = $tugas ? ikhtibar_soal_by_tugas($pdo, $id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'simpan'));
    if ($action === 'token_baru' && $id > 0) {
        $plain = ikhtibar_set_token($pdo, $id);
        set_flash('success', 'Token baru: ' . $plain);
        header('Location: ' . app_href('/pembimbing/tugas/buat.php?id=' . $id));
        exit;
    }
    $_POST['sumber'] = IKHTIBAR_TUGAS_SUMBER;
    unset($_POST['pkpps_jadwal_id']);
    $result = ikhtibar_simpan_tugas_dari_post($pdo, $_POST, $_FILES, $userId);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/pembimbing/tugas/buat.php?id=' . (int) ($result['id'] ?? $id)));
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

$selSumber = IKHTIBAR_TUGAS_SUMBER;
$statusTugas = (string) ($tugas['status'] ?? 'draft');
$hasActiveSesi = $tugas ? ikhtibar_tugas_has_active_sesi($pdo, $id) : false;
$pageTitle = $tugas ? 'Edit Tugas Ikhtibar' : 'Buat Tugas Ikhtibar';
require_once __DIR__ . '/../../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>">Tugas Ikhtibar</a></p>
    <h1 class="h4 mb-0"><?= $tugas ? 'Edit' : 'Buat' ?> Tugas (Ikhtibar)</h1>
    <?php if ($tugas): ?>
        <?php if ($statusTugas === 'published'): ?>
            <span class="badge text-bg-success">Published</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">Draf</span>
        <?php endif; ?>
        <?php if ($statusTugas === 'draft'): ?>
            <p class="small text-muted mb-0 mt-1">Soal dapat disimpan sebelum dipublikasikan. Santri belum bisa mengakses tugas ini.</p>
        <?php endif; ?>
        <?php if ($hasActiveSesi): ?>
            <p class="small text-warning mb-0 mt-1">Santri sudah mulai mengerjakan — tugas tidak bisa dikembalikan ke draf.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="form-ikhtibar">
    <?php if ($tugas): ?><input type="hidden" name="id" value="<?= (int) $tugas['id'] ?>"><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold small">1. Jadwal &amp; periode</div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Kelas / mapel (jadwal Anda)</label>
                    <select name="jadwal_kegiatan_id" class="form-select"<?= $wajibPilihMapel ? ' required' : '' ?>>
                        <option value="">— Pilih jadwal kajian —</option>
                        <?php
                        $selJadwal = (int) ($tugas['jadwal_kegiatan_id'] ?? 0);
                        foreach ($jadwalOptions as $jo):
                            ?>
                            <option value="<?= (int) $jo['id'] ?>" <?= $selJadwal === (int) $jo['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $jo['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($jadwalOptions === []): ?>
                        <div class="form-text text-warning">Belum ada jadwal dengan NIP Anda. Pastikan akun login username = NIP pembimbing di menu Jadwal.</div>
                    <?php else: ?>
                        <div class="form-text">Sesuai kegiatan &amp; tingkatan yang Anda ampu di jadwal kajian pondok.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Judul tugas</label>
                    <input type="text" name="judul" class="form-control" required maxlength="200" value="<?= htmlspecialchars((string) ($tugas['judul'] ?? '')) ?>">
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
                <p class="small text-muted mb-2">Import soal dari Word (.docx) atau Excel (.xlsx). Format Word: nomor soal, opsi A–D, baris <code>kunci: A</code>. Excel: unduh template.</p>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small">Kamera / foto soal (OCR Arab)</label>
                        <input type="file" id="ocr_file" accept="image/*" capture="environment" class="form-control form-control-sm">
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btn-ocr-run"><i class="fa-solid fa-camera me-1"></i> Scan teks Arab</button>
                        <div id="ocr_status" class="small text-muted mt-1"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Upload Word (.docx)</label>
                        <input type="file" name="import_docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Upload Excel (.xlsx)</label>
                        <input type="file" name="import_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm">
                        <a class="small d-inline-block mt-1" href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php?template=xlsx')) ?>">Unduh template Excel</a>
                    </div>
                </div>
                <label class="form-label small mt-2">Hasil OCR / tempel teks (opsional)</label>
                <textarea name="ocr_teks_import" id="ocr_teks_import" class="form-control font-monospace small" rows="4" placeholder="Teks hasil scan atau tempel manual…"></textarea>
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
        <button type="submit" name="action" value="simpan" class="btn btn-outline-secondary"<?= $hasActiveSesi && $statusTugas === 'published' ? ' disabled title="Tugas sudah aktif"' : '' ?>>Simpan draf</button>
        <button type="submit" name="publish" value="1" class="btn btn-primary">Publikasikan tugas</button>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>" class="btn btn-link">Batal</a>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
(function () {
    var pgData = <?= json_encode($pgSoal, JSON_UNESCAPED_UNICODE) ?>;
    var esaiData = <?= json_encode($esaiSoal, JSON_UNESCAPED_UNICODE) ?>;
    var wrapPg = document.getElementById('wrap-pg');
    var wrapEsai = document.getElementById('wrap-esai');
    var selPg = document.getElementById('jumlah_pg');
    var selEsai = document.getElementById('jumlah_esai');

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function renderPg(n) {
        if (!wrapPg) return;
        var html = '<h3 class="h6 border-bottom pb-2">Soal Pilihan Ganda (' + n + ')</h3>';
        for (var i = 1; i <= n; i++) {
            var row = pgData[i] || {};
            html += '<div class="border rounded p-2 mb-2"><div class="fw-semibold small text-muted mb-1">Soal ' + i + '</div>';
            html += '<textarea name="pg_teks[' + i + ']" class="form-control form-control-sm mb-1" rows="2">' + esc(row.teks_soal || '') + '</textarea>';
            html += '<div class="row g-1 small">';
            ['a','b','c','d','e'].forEach(function (L) {
                var U = L.toUpperCase();
                html += '<div class="col"><input name="pg_' + L + '[' + i + ']" class="form-control form-control-sm" placeholder="' + U + '" value="' + esc(row['opsi_' + L] || '') + '"></div>';
            });
            html += '</div><div class="mt-1"><select name="pg_kunci[' + i + ']" class="form-select form-select-sm" style="max-width:100px">';
            html += '<option value="">Kunci</option>';
            ['A','B','C','D','E'].forEach(function (K) {
                html += '<option value="' + K + '"' + ((row.kunci_jawaban || '') === K ? ' selected' : '') + '>' + K + '</option>';
            });
            html += '</select></div></div>';
        }
        wrapPg.innerHTML = html;
    }

    function renderEsai(n) {
        if (!wrapEsai) return;
        if (n < 1) { wrapEsai.innerHTML = ''; return; }
        var html = '<h3 class="h6 border-bottom pb-2">Soal Esai (' + n + ')</h3>';
        for (var i = 1; i <= n; i++) {
            var row = esaiData[i] || {};
            html += '<div class="border rounded p-2 mb-2"><div class="fw-semibold small text-muted mb-1">Esai ' + i + '</div>';
            html += '<textarea name="esai_teks[' + i + ']" class="form-control form-control-sm mb-1" rows="2">' + esc(row.teks_soal || '') + '</textarea>';
            html += '<textarea name="esai_kunci[' + i + ']" class="form-control form-control-sm mb-1" rows="3" placeholder="Kunci per kriteria, contoh: [KELENGKAPAN] poin1, poin2">' + esc(row.kunci_jawaban || '') + '</textarea>';
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
                if (ocrStatus) ocrStatus.textContent = 'OCR selesai. Teks dimasukkan ke kotak hasil.';
                btnOcr.disabled = false;
            }).catch(function () {
                if (ocrStatus) ocrStatus.textContent = 'OCR gagal. Coba foto lebih jelas atau input manual.';
                btnOcr.disabled = false;
            });
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
