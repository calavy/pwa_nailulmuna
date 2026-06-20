<?php

declare(strict_types=1);

require_once __DIR__ . '/../../inc_portal.php';
require_once __DIR__ . '/../../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../../helpers/akademik_pkpps_tugas.php';
require_once __DIR__ . '/../../../helpers/app_path.php';

santri_portal_pkpps_tugas_guard($pdo);
ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$base = pkpps_tugas_santri_base_path();
$sesiId = (int) ($_GET['sesi_id'] ?? 0);
$detail = $sesiId > 0 ? ikhtibar_hasil_detail_santri($pdo, $sesiId, $santriId) : null;

if ($detail === null) {
    set_flash('error', 'Data hasil tidak ditemukan.');
    header('Location: ' . app_href($base . '/hasil.php'));
    exit;
}

$sesi = $detail['sesi'];
$jawaban = $detail['jawaban'];
$pred = $detail['predikat'];
$pending = (int) ($detail['esai_pending'] ?? 0) > 0;
$nilai = $sesi['nilai_total'] !== null ? (float) $sesi['nilai_total'] : null;
$bobot = $detail['bobot'];

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../../includes/layout.php';
santri_portal_layout_head('Detail Hasil PKPPS — ' . (string) ($sesi['judul'] ?? ''), 'tugas_pkpps');
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<h1 class="h5 fw-bold mb-1"><?= htmlspecialchars((string) ($sesi['judul'] ?? 'Tugas')) ?></h1>
<p class="small text-muted mb-3"><?= htmlspecialchars(ikhtibar_hari_label((int) ($sesi['hari_ke'] ?? 0))) ?> · <?= htmlspecialchars((string) ($sesi['tanggal'] ?? '')) ?></p>

<nav class="ikhtibar-portal-tabs mb-3">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/pkpps/tugas/index.php')) ?>">Kerjakan</a>
    <a href="<?= htmlspecialchars(app_href('/santri_portal/pkpps/tugas/hasil.php')) ?>" class="active">Hasil saya</a>
</nav>

<div class="ikhtibar-hero-score <?= ($pending || $nilai === null) ? 'ikhtibar-hero-score--pending' : '' ?> mb-3">
    <?php if ($pending): ?>
        <div class="ikhtibar-hero-score__value"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="ikhtibar-hero-score__label">Menunggu koreksi esai pembimbing</div>
        <p class="small mb-0 mt-2 opacity-90">Nilai PG sudah dihitung. Nilai akhir akan muncul setelah esai dinilai.</p>
    <?php elseif ($nilai !== null): ?>
        <div class="ikhtibar-hero-score__value"><?= htmlspecialchars(number_format($nilai, 1)) ?></div>
        <div class="ikhtibar-hero-score__label">Nilai akhir</div>
        <span class="badge bg-light text-dark mt-2"><i class="fa-solid <?= htmlspecialchars($pred['icon']) ?> me-1"></i><?= htmlspecialchars($pred['label']) ?></span>
    <?php else: ?>
        <div class="ikhtibar-hero-score__value">—</div>
        <div class="ikhtibar-hero-score__label">Belum ada nilai</div>
    <?php endif; ?>
</div>

<div class="ikhtibar-score-grid mb-3">
    <div class="ikhtibar-score-tile">
        <div class="ikhtibar-score-tile__val"><?= $sesi['skor_pg'] !== null ? htmlspecialchars((string) $sesi['skor_pg']) . '%' : '—' ?></div>
        <div class="ikhtibar-score-tile__lbl">PG <?= (int) ($detail['pg_benar'] ?? 0) ?>/<?= (int) ($detail['pg_total'] ?? 0) ?> benar</div>
        <?php if ((int) ($sesi['jumlah_pg'] ?? 0) > 0): ?>
            <div class="small text-muted">Bobot <?= round($bobot['pg'] * 100) ?>%</div>
        <?php endif; ?>
    </div>
    <div class="ikhtibar-score-tile">
        <div class="ikhtibar-score-tile__val"><?= $sesi['skor_esai'] !== null ? htmlspecialchars((string) $sesi['skor_esai']) : ($pending ? '…' : '—') ?></div>
        <div class="ikhtibar-score-tile__lbl">Rata-rata esai</div>
        <?php if ((int) ($sesi['jumlah_esai'] ?? 0) > 0): ?>
            <div class="small text-muted">Bobot <?= round($bobot['esai'] * 100) ?>%</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($sesi['waktu_selesai'])): ?>
    <p class="small text-muted text-center mb-3">Selesai: <?= htmlspecialchars((string) $sesi['waktu_selesai']) ?></p>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white fw-semibold small"><i class="fa-solid fa-list-check me-1 text-primary"></i> Jawaban pilihan ganda</div>
    <div class="card-body">
        <?php
        $adaPg = false;
        foreach ($jawaban as $j):
            if ((string) ($j['jenis'] ?? '') !== 'PG') {
                continue;
            }
            $adaPg = true;
            $benar = (int) ($j['benar'] ?? 0) === 1;
            ?>
            <div class="ikhtibar-jawaban-pg <?= $benar ? 'ikhtibar-jawaban-pg--benar' : 'ikhtibar-jawaban-pg--salah' ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold small">Soal <?= (int) ($j['nomor'] ?? 0) ?></span>
                    <span class="badge text-bg-<?= $benar ? 'success' : 'danger' ?>"><?= $benar ? 'Benar' : 'Salah' ?></span>
                </div>
                <p class="small mb-1 text-muted"><?= nl2br(htmlspecialchars(mb_strimwidth((string) ($j['teks_soal'] ?? ''), 0, 200, '…'))) ?></p>
                <p class="small mb-0">Jawaban Anda: <strong><?= htmlspecialchars((string) ($j['jawaban_santri'] ?? '-')) ?></strong>
                    <?php if (!$benar): ?> · Kunci: <strong><?= htmlspecialchars((string) ($j['kunci_jawaban'] ?? '-')) ?></strong><?php endif; ?>
                </p>
            </div>
        <?php endforeach;
        if (!$adaPg): ?>
            <p class="text-muted small mb-0">Tidak ada soal pilihan ganda.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white fw-semibold small"><i class="fa-solid fa-pen-fancy me-1 text-warning"></i> Jawaban esai</div>
    <div class="card-body">
        <?php
        $adaEsai = false;
        foreach ($jawaban as $j):
            if ((string) ($j['jenis'] ?? '') !== 'ESAI') {
                continue;
            }
            $adaEsai = true;
            $dinilai = $j['nilai_esai'] !== null;
            ?>
            <div class="ikhtibar-jawaban-esai">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold small">Esai <?= (int) ($j['nomor'] ?? 0) ?></span>
                    <?php if ($dinilai): ?>
                        <span class="badge text-bg-success"><?= htmlspecialchars((string) $j['nilai_esai']) ?> poin</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning text-dark">Belum dinilai</span>
                    <?php endif; ?>
                </div>
                <p class="small text-muted mb-1"><?= nl2br(htmlspecialchars(mb_strimwidth((string) ($j['teks_soal'] ?? ''), 0, 180, '…'))) ?></p>
                <p class="small mb-0"><?= nl2br(htmlspecialchars((string) ($j['jawaban_santri'] ?? '-'))) ?></p>
                <?php if ($dinilai && trim((string) ($j['catatan_pembimbing'] ?? '')) !== ''): ?>
                    <p class="small text-info mb-0 mt-2"><i class="fa-solid fa-comment me-1"></i><?= htmlspecialchars((string) $j['catatan_pembimbing']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach;
        if (!$adaEsai): ?>
            <p class="text-muted small mb-0">Tidak ada soal esai.</p>
        <?php endif; ?>
    </div>
</div>

<a href="<?= htmlspecialchars(app_href('/santri_portal/pkpps/tugas/hasil.php')) ?>" class="btn btn-outline-secondary w-100">← Kembali ke daftar hasil</a>

<?php
santri_portal_layout_foot('tugas_pkpps');
