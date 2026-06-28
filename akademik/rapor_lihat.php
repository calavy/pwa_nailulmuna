<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_rapor_columns($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Rapor tidak ditemukan.');
    header('Location: ' . app_href('/akademik/rapor.php'));
    exit;
}

$st = $pdo->prepare('
    SELECT r.*, s.nis, s.nama_santri, s.tingkatan
    FROM akademik_rapor r
    INNER JOIN santri s ON s.id = r.santri_id
    WHERE r.id = :id LIMIT 1
');
$st->execute(['id' => $id]);
$rapor = $st->fetch(PDO::FETCH_ASSOC);
if (!$rapor) {
    set_flash('error', 'Rapor tidak ditemukan.');
    header('Location: ' . app_href('/akademik/rapor.php'));
    exit;
}

$periode = rapor_periode_dari_row($pdo, $rapor);
$konten = akademik_rapor_konten_from_row($pdo, $rapor);
$santriId = (int) ($rapor['santri_id'] ?? 0);
$raporJenis = $konten['jenis'];
$raporPeriodeLabel = $konten['periode_label'];
$raporPresensi = $konten['presensi'];
$raporSetoran = $konten['setoran'];
$raporTugas = $konten['tugas'];
$raporSectionLabels = $konten['section_labels'];
$editRaporPath = akademik_rapor_admin_base_path($raporJenis);

$pageTitle = 'Rapor — ' . ($rapor['nama_santri'] ?? '');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3 d-print-none">
    <p class="page-intro-kicker mb-1"><a href="/akademik/rapor.php">Rapor Akademik</a></p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><?= htmlspecialchars((string) ($rapor['judul_periode'] ?? '')) ?>
                <span class="badge text-bg-<?= $raporJenis === 'pkpps' ? 'info' : 'success' ?> fs-6"><?= htmlspecialchars(akademik_rapor_jenis_label($raporJenis)) ?></span>
            </h1>
            <p class="text-muted mb-0 small">
                <?= htmlspecialchars((string) ($rapor['nama_santri'] ?? '')) ?>
                (<?= htmlspecialchars((string) ($rapor['nis'] ?? '')) ?>)
                <?php if (trim((string) ($rapor['tingkatan'] ?? '')) !== ''): ?>
                    · <?= htmlspecialchars((string) $rapor['tingkatan']) ?>
                <?php endif; ?>
                · Terbit <?= htmlspecialchars((string) ($rapor['tanggal_terbit'] ?? '')) ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= htmlspecialchars(app_href($editRaporPath . '?edit=' . (int) $rapor['id'])) ?>#rapor-form" class="btn btn-outline-primary btn-sm">Edit</a>
            <?php if (trim((string) ($rapor['pdf_path'] ?? '')) !== ''): ?>
                <a href="<?= htmlspecialchars(app_href('/akademik/rapor_pdf.php?id=' . (int) $rapor['id'])) ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">Lihat PDF</a>
                <a href="<?= htmlspecialchars(app_href('/akademik/rapor_pdf.php?id=' . (int) $rapor['id'] . '&dl=1')) ?>" class="btn btn-outline-primary btn-sm">Unduh PDF</a>
            <?php endif; ?>
            <a href="/akademik/rapor_cetak.php?id=<?= (int) $rapor['id'] ?>&preview=1" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Pratinjau cetak</a>
            <a href="/akademik/rapor_cetak.php?id=<?= (int) $rapor['id'] ?>" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">Cetak (kop &amp; TTD)</a>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <?php if (trim((string) ($rapor['predikat_akhlak'] ?? '')) !== ''): ?>
            <p class="mb-2">Predikat akhlak: <span class="badge text-bg-info text-dark"><?= htmlspecialchars((string) $rapor['predikat_akhlak']) ?></span></p>
        <?php endif; ?>
        <?php if (trim((string) ($rapor['narasi'] ?? '')) !== ''): ?>
            <div class="mb-3" style="white-space:pre-wrap;"><?= htmlspecialchars((string) $rapor['narasi']) ?></div>
        <?php endif; ?>
        <?php if (trim((string) ($rapor['catatan_pondok'] ?? '')) !== ''): ?>
            <div class="small border-start border-3 border-success ps-2 mb-3"><?= nl2br(htmlspecialchars((string) $rapor['catatan_pondok'])) ?></div>
        <?php endif; ?>

        <?php require __DIR__ . '/../includes/partials/rapor_isi.php'; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
