<?php

declare(strict_types=1);

/**
 * Modul admin rapor (pesantren / PKPPS).
 * Wajib: $raporJenis, $raporBasePath, $raporPageKicker, $raporPageTitle, $raporPageDesc
 * Opsional: $raporSettingsPath, $raporSettingsLabel, $raporExtraIntroHtml
 */

/** @var PDO $pdo */
$raporJenis = akademik_rapor_jenis_normalize((string) ($raporJenis ?? 'pesantren'));
$raporBasePath = trim((string) ($raporBasePath ?? akademik_rapor_admin_base_path($raporJenis)));
$raporPageKicker = (string) ($raporPageKicker ?? 'Modul Akademik');
$raporPageTitle = (string) ($raporPageTitle ?? 'Rapor Akademik');
$raporPageDesc = (string) ($raporPageDesc ?? '');
$raporSettingsPath = (string) ($raporSettingsPath ?? akademik_rapor_pengaturan_path($raporJenis));
$raporSettingsLabel = (string) ($raporSettingsLabel ?? 'Pengaturan template');
$raporExtraIntroHtml = (string) ($raporExtraIntroHtml ?? '');
$isPkpps = $raporJenis === 'pkpps';

if ($isPkpps) {
    require_once __DIR__ . '/../../helpers/pkpps_rapor.php';
    require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';
    pkpps_ensure_schema($pdo);
}

akademik_rapor_process_admin_post($pdo, $raporJenis, $raporBasePath);

$filterSantri = (int) ($_GET['santri_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = akademik_rapor_fetch_edit_row($pdo, $editId, $raporJenis);
if ($editId > 0 && $editRow === null) {
    set_flash('error', 'Rapor untuk diedit tidak ditemukan.');
    header('Location: ' . app_href($raporBasePath));
    exit;
}

$santriList = akademik_rapor_santri_list_for_jenis($pdo, $raporJenis);
$daftar = akademik_rapor_list_for_jenis($pdo, $raporJenis, $filterSantri);

$pageTitle = $raporPageTitle;
require_once __DIR__ . '/../../includes/header.php';

$formSid = $editRow ? (int) $editRow['santri_id'] : ($filterSantri > 0 ? $filterSantri : 0);
$formJudul = (string) ($editRow['judul_periode'] ?? '');
$formTgl = (string) ($editRow['tanggal_terbit'] ?? date('Y-m-d'));
$formNarasi = (string) ($editRow['narasi'] ?? '');
$formPred = (string) ($editRow['predikat_akhlak'] ?? '');
$formCat = (string) ($editRow['catatan_pondok'] ?? '');
$formPub = $editRow ? (int) ($editRow['is_published'] ?? 0) : 0;
$defPeriode = $editRow
    ? [
        'mode' => strtolower((string) ($editRow['periode_mode'] ?? '')),
        'month' => (int) ($editRow['periode_bulan'] ?? 0),
        'year' => (int) ($editRow['periode_tahun'] ?? 0),
    ]
    : rapor_periode_default_dari_tanggal($pdo, $formTgl);
if (!in_array($defPeriode['mode'], ['masehi', 'hijriyah'], true)) {
    $defPeriode = rapor_periode_default_dari_tanggal($pdo, $formTgl);
}
if ($defPeriode['month'] < 1 || $defPeriode['year'] < 1) {
    $defPeriode = rapor_periode_default_dari_tanggal($pdo, $formTgl);
}
$hijriMonths = hijri_nama_bulan_list();
$masehiMonths = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$calDefault = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
$judulPlaceholder = $isPkpps
    ? pkpps_rapor_setting($pdo, 'pkpps_rapor_judul_placeholder', 'Rapor PKPPS Semester …')
    : 'Mis. Semester 1 TA 2026/2027';

$tugasPreview = [];
if ($filterSantri > 0) {
    if ($isPkpps) {
        $tugasPreview = pkpps_tugas_riwayat_santri($pdo, $filterSantri);
    } else {
        $tugasPreview = ikhtibar_riwayat_hasil_santri($pdo, $filterSantri);
    }
}
$pdfPathEdit = $editRow ? trim((string) ($editRow['pdf_path'] ?? '')) : '';
$pdfAda = $pdfPathEdit !== '';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><?= $raporPageKicker ?></p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars($raporPageTitle) ?> <span class="badge text-bg-<?= $isPkpps ? 'info' : 'success' ?> fs-6 align-middle"><?= htmlspecialchars(akademik_rapor_jenis_label($raporJenis)) ?></span></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($raporPageDesc) ?></p>
            <?php if ($raporExtraIntroHtml !== ''): ?>
                <div class="small text-muted mt-2"><?= $raporExtraIntroHtml ?></div>
            <?php endif; ?>
        </div>
        <a href="<?= htmlspecialchars(app_href($raporSettingsPath)) ?>" class="btn btn-outline-secondary btn-sm"><?= htmlspecialchars($raporSettingsLabel) ?></a>
    </div>
</div>

<?php if ($filterSantri > 0): ?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold"><i class="fa-solid fa-list-check text-primary me-1"></i> Nilai tugas <?= $isPkpps ? 'PKPPS' : 'Ikhtibar' ?></span>
        <?php if ($isPkpps): ?>
            <a href="<?= htmlspecialchars(app_href('/pkpps/tugas/rekap.php')) ?>" class="btn btn-sm btn-outline-primary">Rekap PKPPS</a>
        <?php else: ?>
            <a href="<?= htmlspecialchars(app_href('/akademik/ikhtibar_rekap.php?santri_id=' . $filterSantri)) ?>" class="btn btn-sm btn-outline-primary">Rekap lengkap</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($tugasPreview === []): ?>
            <p class="text-muted small mb-0">Belum ada riwayat tugas untuk santri ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead><tr><th>Tugas</th><th>Tanggal</th><th>PG</th><th>Esai</th><th>Total</th><th>Predikat</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($tugasPreview, 0, 8) as $ir): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($ir['judul'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($ir['tanggal'] ?? '')) ?></td>
                            <td><?= $ir['skor_pg'] !== null ? (string) $ir['skor_pg'] . '%' : '—' ?></td>
                            <td><?= (int) ($ir['esai_pending'] ?? 0) > 0 ? 'Pending' : ($ir['skor_esai'] !== null ? (string) $ir['skor_esai'] : '—') ?></td>
                            <td class="fw-semibold"><?= $ir['nilai_total'] !== null ? (string) $ir['nilai_total'] : '—' ?></td>
                            <td><span class="badge text-bg-<?= htmlspecialchars((string) ($ir['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($ir['predikat'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5" id="rapor-form">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Edit rapor' : 'Tambah rapor' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted mb-2">
                        <a href="<?= htmlspecialchars(app_href($raporBasePath)) ?>">Batal edit</a>
                        · <a href="/akademik/rapor_lihat.php?id=<?= (int) $editRow['id'] ?>">Pratinjau</a>
                        · <a href="/akademik/rapor_cetak.php?id=<?= (int) $editRow['id'] ?>" target="_blank" rel="noopener">Cetak</a>
                    </p>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="d-grid gap-2">
                    <input type="hidden" name="action" value="simpan_rapor">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="rapor_id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label">Santri <?= $isPkpps ? '(program PKPPS)' : '' ?></label>
                        <select name="santri_id" class="form-select" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($santriList as $s): ?>
                                <?php
                                $tg = trim((string) ($s['tingkatan'] ?? ''));
                                $optLabel = htmlspecialchars((string) $s['nama_santri']) . ' (' . htmlspecialchars((string) $s['nis']) . ')';
                                if ($tg !== '') {
                                    $optLabel .= ' — ' . htmlspecialchars($tg);
                                }
                                ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $formSid === (int) $s['id'] ? 'selected' : '' ?>><?= $optLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isPkpps && $santriList === []): ?>
                            <div class="form-text text-warning">Belum ada santri aktif di PKPPS. <a href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>">Kelola santri PKPPS</a></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label">Judul / periode rapor</label>
                        <input type="text" name="judul_periode" class="form-control" required maxlength="160" placeholder="<?= htmlspecialchars($judulPlaceholder) ?>" value="<?= htmlspecialchars($formJudul) ?>">
                    </div>
                    <div>
                        <label class="form-label">Tanggal terbit</label>
                        <input type="date" name="tanggal_terbit" class="form-control" required value="<?= htmlspecialchars($formTgl) ?>">
                    </div>
                    <div class="border rounded p-2 bg-light">
                        <label class="form-label fw-semibold small mb-2">Periode data</label>
                        <div class="row g-2">
                            <div class="col-12">
                                <select name="periode_mode" class="form-select form-select-sm" id="rapor-periode-mode">
                                    <option value="hijriyah" <?= $defPeriode['mode'] === 'hijriyah' ? 'selected' : '' ?>>Bulan Hijriyah</option>
                                    <option value="masehi" <?= $defPeriode['mode'] === 'masehi' ? 'selected' : '' ?>>Bulan Masehi</option>
                                </select>
                            </div>
                            <div class="col-7">
                                <select name="periode_bulan" class="form-select form-select-sm" id="rapor-periode-bulan">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" data-masehi="<?= htmlspecialchars($masehiMonths[$m] ?? (string) $m) ?>" data-hijri="<?= htmlspecialchars($hijriMonths[$m] ?? (string) $m) ?>" <?= $defPeriode['month'] === $m ? 'selected' : '' ?>><?= htmlspecialchars($hijriMonths[$m] ?? (string) $m) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <input type="number" name="periode_tahun" class="form-control form-control-sm" min="1300" max="2100" required value="<?= (int) $defPeriode['year'] ?>">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Ringkasan / narasi</label>
                        <textarea name="narasi" class="form-control" rows="5"><?= htmlspecialchars($formNarasi) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label">Predikat akhlak (opsional)</label>
                        <input type="text" name="predikat_akhlak" class="form-control" maxlength="100" value="<?= htmlspecialchars($formPred) ?>">
                    </div>
                    <div>
                        <label class="form-label">Catatan (opsional)</label>
                        <textarea name="catatan_pondok" class="form-control" rows="2"><?= htmlspecialchars($formCat) ?></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_published" value="1" id="pubrap" <?= $formPub === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubrap"><strong>Terbitkan ke portal wali</strong></label>
                    </div>
                    <p class="small text-muted mb-2">Wajib dicentang agar rapor &amp; PDF tampil di portal wali. Tanpa ini rapor tetap <em>Draft</em> (hanya admin yang melihat).</p>
                    <div class="border rounded p-2 bg-light">
                        <label class="form-label small fw-semibold mb-1">File PDF rapor (opsional)</label>
                        <?php if ($pdfAda): ?>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/akademik/rapor_pdf.php?id=' . (int) ($editRow['id'] ?? 0))) ?>" target="_blank" rel="noopener">Lihat PDF</a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/akademik/rapor_pdf.php?id=' . (int) ($editRow['id'] ?? 0) . '&dl=1')) ?>">Unduh</a>
                                <span class="badge text-bg-primary align-self-center">PDF terunggah</span>
                            </div>
                        <?php elseif (!$editRow): ?>
                            <p class="small text-muted mb-2">Bisa diunggah bersamaan saat simpan (setelah data rapor tersimpan).</p>
                        <?php endif; ?>
                        <input type="file" name="pdf_file" class="form-control form-control-sm" accept=".pdf,application/pdf">
                        <div class="form-text">PDF per santri, maks. 15 MB. <?= $pdfAda ? 'Pilih file baru untuk mengganti.' : '' ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editRow ? 'Simpan perubahan' : 'Simpan rapor' ?></button>
                </form>
                <?php if ($editRow && $pdfAda): ?>
                    <form method="post" class="mt-2" onsubmit="return confirm('Hapus PDF?');">
                        <input type="hidden" name="action" value="hapus_pdf_rapor">
                        <input type="hidden" name="rapor_id" value="<?= (int) $editRow['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus PDF</button>
                    </form>
                <?php endif; ?>
                <?php if ($editRow):
                    $waSentAt = trim((string) ($editRow['wa_terbit_sent_at'] ?? ''));
                    if ($waSentAt !== ''): ?>
                    <p class="small text-success mt-2 mb-0"><i class="fa-brands fa-whatsapp me-1"></i> WA terbit terkirim <?= htmlspecialchars($waSentAt) ?></p>
                <?php endif; endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Daftar rapor</h2>
                    <form method="get" class="d-flex gap-2">
                        <select name="santri_id" class="form-select form-select-sm" style="min-width:10rem;" onchange="this.form.submit()">
                            <option value="0">Semua santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $filterSantri === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['nama_santri']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Tgl</th><th>Santri</th><th>Judul</th><th>Periode</th><th>Terbit</th><th>PDF</th><th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$daftar): ?>
                            <tr><td colspan="7" class="text-muted small">Belum ada rapor.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($daftar as $d):
                            if (!function_exists('santri_resolve_no_wa_wali')) {
                                require_once __DIR__ . '/../../helpers/santri_wa.php';
                            }
                            $waWali = santri_resolve_no_wa_wali($pdo, (int) ($d['santri_id'] ?? 0));
                            $namaS = (string) ($d['nama_santri'] ?? '');
                            $periodeRow = rapor_periode_dari_row($pdo, $d);
                            $pesanWa = akademik_rapor_wa_render_pesan($pdo, $d, $raporJenis);
                            $waUrl = $waWali !== '' ? wa_me_chat_url($waWali, $pesanWa) : null;
                            $isPub = (int) ($d['is_published'] ?? 0) === 1;
                            $hasPdf = trim((string) ($d['pdf_path'] ?? '')) !== '';
                            ?>
                            <tr class="<?= !$isPub && $hasPdf ? 'table-warning' : '' ?>">
                                <td class="text-nowrap small"><?= htmlspecialchars((string) $d['tanggal_terbit']) ?></td>
                                <td class="small"><?= htmlspecialchars($namaS) ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($d['judul_periode'] ?? '')) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars((string) $periodeRow['label']) ?></td>
                                <td><?= $isPub ? '<span class="badge text-bg-success">Terbit</span>' : '<span class="badge text-bg-secondary" title="Belum tampil di portal wali">Draft</span>' ?></td>
                                <td><?= $hasPdf ? '<span class="badge text-bg-primary">Ada</span>' : '—' ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if (!$isPub): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Terbitkan rapor ini ke portal wali?');">
                                            <input type="hidden" name="action" value="terbitkan_rapor">
                                            <input type="hidden" name="rapor_id" value="<?= (int) $d['id'] ?>">
                                            <input type="hidden" name="santri_id" value="<?= (int) ($d['santri_id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Terbitkan</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="/akademik/rapor_lihat.php?id=<?= (int) $d['id'] ?>">Lihat</a>
                                    <?php if ($waUrl): ?>
                                        <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= htmlspecialchars($waUrl) ?>">WA</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href($raporBasePath . '?edit=' . (int) $d['id'])) ?>#rapor-form">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus?');">
                                        <input type="hidden" name="action" value="hapus_rapor">
                                        <input type="hidden" name="rapor_id" value="<?= (int) $d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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

<script>
(function () {
    var modeEl = document.getElementById('rapor-periode-mode');
    var bulanEl = document.getElementById('rapor-periode-bulan');
    function syncBulanLabels() {
        if (!modeEl || !bulanEl) return;
        var hijri = modeEl.value === 'hijriyah';
        for (var i = 0; i < bulanEl.options.length; i++) {
            var opt = bulanEl.options[i];
            opt.textContent = hijri ? (opt.getAttribute('data-hijri') || opt.value) : (opt.getAttribute('data-masehi') || opt.value);
        }
    }
    if (modeEl) { modeEl.addEventListener('change', syncBulanLabels); syncBulanLabels(); }
    var pdfInput = document.querySelector('input[name="pdf_file"]');
    var pubChk = document.getElementById('pubrap');
    if (pdfInput && pubChk) {
        pdfInput.addEventListener('change', function () {
            if (pdfInput.files && pdfInput.files.length > 0 && !pubChk.checked) {
                pubChk.checked = true;
            }
        });
    }
    var el = document.getElementById('rapor-form');
    if (el && window.location.hash === '#rapor-form') el.scrollIntoView({ behavior: 'smooth', block: 'start' });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
