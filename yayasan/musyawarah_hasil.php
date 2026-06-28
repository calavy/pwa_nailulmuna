<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';
require_once __DIR__ . '/../helpers/yayasan_notulen.php';
require_once __DIR__ . '/../helpers/surat_cetak_templates.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);
yayasan_notulen_ensure_schema($pdo);

$rapatId = (int) ($_GET['rapat_id'] ?? $_POST['rapat_id'] ?? 0);
if ($rapatId <= 0) {
    set_flash('error', 'Rapat tidak ditemukan.');
    header('Location: ' . app_href('/yayasan/rapat.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['action'] ?? '')) === 'simpan_hasil_musyawarah') {
    $res = yayasan_notulen_save_hasil_agenda($pdo, $rapatId, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    header('Location: ' . app_href('/yayasan/musyawarah_hasil.php?rapat_id=' . $rapatId . '#pratinjau-cetak'));
    exit;
}

$rekap = yayasan_musyawarah_rekap_rapat($pdo, $rapatId);
$rapat = $rekap['rapat'] ?? null;
if (!is_array($rapat)) {
    set_flash('error', 'Rapat tidak ditemukan.');
    header('Location: ' . app_href('/yayasan/rapat.php'));
    exit;
}

$docRow = yayasan_musyawarah_hasil_dokumen_row($pdo, $rapatId);
$notulen = yayasan_notulen_fetch_by_rapat($pdo, $rapatId);
$hasilAgendaRows = yayasan_notulen_agenda_uraian_rows($pdo, $rapatId, $rapat);

$formRingkasan = (string) ($notulen['ringkasan'] ?? '');
$formKeputusan = (string) ($notulen['keputusan'] ?? '');
$formHadir = (string) ($notulen['hadir'] ?? '');
if ($formHadir === '' && ($rekap['hadir'] ?? []) !== []) {
    $formHadir = yayasan_musyawarah_hadir_dari_rekap($rekap['hadir']);
}

$ponpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));
$kop = pondok_kop_data($pdo);
$logoHref = (string) ($kop['logo_href'] ?? '');
$headerColor = surat_cetak_kop_accent_color($pdo);
$dokumenJudul = surat_cetak_template_render($pdo, 'notulen_judul', ['nama_ponpes' => $ponpes]);

$pageTitle = 'Hasil Musyawarah';
$bodyClass = 'yn-musyawarah-hasil-page';
$pageStylesheets = [
    app_asset_href('/assets/css/yayasan-notulen.css'),
    app_asset_href('/assets/css/yayasan-portal.css'),
    app_asset_href('/assets/css/musyawarah-hasil-cetak.css'),
];
$pageStyleBlocks = [pondok_kop_surat_embed_styles($headerColor, $logoHref)];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 yn-no-print">
    <?php $yayasanCrumbTail = 'Hasil musyawarah'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
    <p class="small mb-2">
        <a href="<?= htmlspecialchars(app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatId)) ?>">← Presensi musyawarah</a>
        <span class="text-muted"> · </span>
        <a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">Daftar rapat</a>
    </p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><?= htmlspecialchars((string) ($rapat['judul'] ?? 'Musyawarah')) ?></h1>
            <p class="text-muted mb-0 small">Edit hasil musyawarah di kiri, pratinjau cetak di kanan. Simpan dulu sebelum mencetak.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" form="form-hasil-musyawarah" class="btn btn-sm btn-success">
                <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
            </button>
            <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i>Cetak
            </button>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5 yn-no-print">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Edit hasil musyawarah</div>
            <div class="card-body">
                <?php if ($hasilAgendaRows === []): ?>
                    <p class="text-muted small mb-3">Belum ada <strong>agenda ringkas</strong> di rapat ini. Tambahkan di <a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php?edit=' . $rapatId)) ?>">edit rapat</a>.</p>
                <?php endif; ?>
                <form method="post" id="form-hasil-musyawarah" class="d-grid gap-3">
                    <input type="hidden" name="action" value="simpan_hasil_musyawarah">
                    <input type="hidden" name="rapat_id" value="<?= $rapatId ?>">
                    <?php if ($hasilAgendaRows !== []): ?>
                        <div>
                            <label class="form-label small fw-semibold mb-2">Uraian per agenda</label>
                            <?php
                            $hasilAgendaEmbedded = true;
                            require __DIR__ . '/partials/hasil_agenda_form.php';
                            ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label small mb-1">Yang hadir</label>
                        <textarea class="form-control form-control-sm" name="hadir" rows="3" placeholder="Satu nama per baris"><?= htmlspecialchars($formHadir) ?></textarea>
                        <div class="form-text">Otomatis terisi dari presensi scan jika masih kosong.</div>
                    </div>
                    <div>
                        <label class="form-label small mb-1">Ringkasan</label>
                        <textarea class="form-control form-control-sm" name="ringkasan" rows="2"><?= htmlspecialchars($formRingkasan) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label small mb-1">Keputusan</label>
                        <textarea class="form-control form-control-sm" name="keputusan" rows="3" placeholder="Keputusan rapat (opsional)"><?= htmlspecialchars($formKeputusan) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Simpan hasil
                    </button>
                </form>
                <p class="small text-muted mb-0 mt-3">
                    Notulen lengkap (timeline, foto): <a href="<?= htmlspecialchars(app_href('/yayasan/notulen.php?rapat_id=' . $rapatId)) ?>">menu Notulen</a>
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7" id="pratinjau-cetak">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold yn-no-print d-flex justify-content-between align-items-center">
                <span>Pratinjau cetak</span>
                <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">Cetak</button>
            </div>
            <div class="card-body" id="musyawarah-hasil-dokumen">
                <?php if (is_array($docRow)): ?>
                    <?php require __DIR__ . '/partials/musyawarah_hasil_dokumen.php'; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
