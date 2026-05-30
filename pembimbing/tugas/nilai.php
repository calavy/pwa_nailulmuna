<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);

$tugasId = (int) ($_GET['tugas_id'] ?? 0);
$tugas = ikhtibar_tugas_by_id($pdo, $tugasId);
if (!$tugas) {
    set_flash('error', 'Tugas tidak ditemukan.');
    header('Location: ' . app_href('/pembimbing/tugas/index.php'));
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nilai-ikhtibar-' . $tugasId . '.csv"');
    echo ikhtibar_export_nilai_csv($pdo, $tugasId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'nilai_esai') {
    $sesiId = (int) ($_POST['sesi_id'] ?? 0);
    $soalId = (int) ($_POST['soal_id'] ?? 0);
    $nilai = (float) ($_POST['nilai_esai'] ?? 0);
    $catatan = trim((string) ($_POST['catatan'] ?? ''));
    if ($sesiId > 0 && $soalId > 0) {
        ikhtibar_nilai_esai_manual($pdo, $sesiId, $soalId, max(0, min(100, $nilai)), $catatan);
        set_flash('success', 'Nilai esai disimpan.');
    }

    require_once __DIR__ . '/../../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        $flash = offline_sync_take_flash();
        offline_sync_json_response($flash['type'], $flash['message'], [
            'tugas_id' => $tugasId,
            'sesi_id' => $sesiId,
        ]);
    }

    header('Location: ' . app_href('/pembimbing/tugas/nilai.php?tugas_id=' . $tugasId . '&sesi_id=' . $sesiId));
    exit;
}

$laporan = ikhtibar_laporan_nilai_enriched($pdo, $tugasId);
$detailSesiId = (int) ($_GET['sesi_id'] ?? 0);
$detailPaket = null;
if ($detailSesiId > 0) {
    $st = $pdo->prepare('SELECT santri_id FROM ikhtibar_sesi WHERE id = :id AND tugas_id = :t LIMIT 1');
    $st->execute(['id' => $detailSesiId, 't' => $tugasId]);
    $sid = (int) ($st->fetchColumn() ?: 0);
    if ($sid > 0) {
        $detailPaket = ikhtibar_hasil_detail_santri($pdo, $detailSesiId, $sid);
    }
}

$rataNilai = null;
$cntNilai = 0;
$sumNilai = 0.0;
foreach ($laporan as $row) {
    if ($row['nilai_total'] !== null) {
        $sumNilai += (float) $row['nilai_total'];
        $cntNilai++;
    }
}
if ($cntNilai > 0) {
    $rataNilai = round($sumNilai / $cntNilai, 1);
}

$pageTitle = 'Penilaian — ' . (string) $tugas['judul'];
$bodyClass = 'ikhtibar-nilai-page';
require_once __DIR__ . '/../../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>">Tugas Ikhtibar</a>
        · <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/rekap.php')) ?>">Rekap</a>
    </p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-clipboard-check text-success me-1"></i> Laporan &amp; Penilaian</h1>
            <p class="text-muted mb-0">
                <strong><?= htmlspecialchars((string) $tugas['judul']) ?></strong>
                · <?= htmlspecialchars((string) $tugas['tanggal']) ?>
                <?php if (!empty($tugas['mapel_label'])): ?> · <?= htmlspecialchars((string) $tugas['mapel_label']) ?><?php endif; ?>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="?tugas_id=<?= $tugasId ?>&export=csv" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-file-csv me-1"></i> Export CSV</a>
            <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php?id=' . $tugasId)) ?>" class="btn btn-sm btn-outline-primary">Edit tugas</a>
        </div>
    </div>
</div>

<?php $flashOk = get_flash('success'); if ($flashOk): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-md-3 col-6">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--info">
            <div class="ikhtibar-rekap-stat__num"><?= count($laporan) ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Santri mengerjakan</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--primary">
            <div class="ikhtibar-rekap-stat__num"><?= $rataNilai !== null ? htmlspecialchars((string) $rataNilai) : '—' ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Rata nilai akhir</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--warn">
            <div class="ikhtibar-rekap-stat__num"><?= count(array_filter($laporan, static fn ($r) => (int) ($r['esai_pending'] ?? 0) > 0)) ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Butuh koreksi esai</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="ikhtibar-rekap-stat" style="background:#f8fafc;color:#334155">
            <div class="ikhtibar-rekap-stat__num">PG <?= (int) ($tugas['jumlah_pg'] ?? 0) ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Esai <?= (int) ($tugas['jumlah_esai'] ?? 0) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Rekap nilai santri</span>
                <span class="small text-muted">Klik baris untuk koreksi</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>PG</th>
                                <th>Esai</th>
                                <th>Total</th>
                                <th>Predikat</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($laporan === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada santri yang mengerjakan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($laporan as $r):
                            $sesiRowId = (int) ($r['sesi_id'] ?? 0);
                            $active = $sesiRowId === $detailSesiId;
                            $pending = (int) ($r['esai_pending'] ?? 0) > 0;
                            ?>
                            <tr class="<?= $active ? 'table-primary' : '' ?>">
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></div>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars((string) $r['nis']) ?></div>
                                </td>
                                <td><?= $r['skor_pg'] !== null ? htmlspecialchars((string) $r['skor_pg']) . '%' : '—' ?></td>
                                <td>
                                    <?php if ($pending): ?>
                                        <span class="badge text-bg-warning text-dark">Pending</span>
                                    <?php elseif ($r['skor_esai'] !== null): ?>
                                        <?= htmlspecialchars((string) $r['skor_esai']) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?= $r['nilai_total'] !== null ? htmlspecialchars((string) $r['nilai_total']) : '—' ?></td>
                                <td><span class="badge text-bg-<?= htmlspecialchars((string) ($r['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($r['predikat'] ?? '')) ?></span></td>
                                <td class="text-end">
                                    <a href="?tugas_id=<?= $tugasId ?>&sesi_id=<?= $sesiRowId ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <?php if ($detailPaket !== null):
            $jawaban = $detailPaket['jawaban'];
            ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold small">
                Koreksi &amp; detail — <?= htmlspecialchars((string) ($detailPaket['sesi']['nama_santri'] ?? '')) ?>
            </div>
            <div class="card-body" style="max-height:70vh;overflow-y:auto">
                <?php foreach ($jawaban as $j):
                    if ((string) ($j['jenis'] ?? '') !== 'ESAI') {
                        continue;
                    }
                    ?>
                    <div class="ikhtibar-jawaban-esai">
                        <div class="fw-semibold small mb-1">Esai <?= (int) ($j['nomor'] ?? 0) ?></div>
                        <p class="small text-muted mb-1"><?= nl2br(htmlspecialchars(mb_strimwidth((string) ($j['teks_soal'] ?? ''), 0, 160, '…'))) ?></p>
                        <p class="small mb-2"><strong>Jawaban:</strong> <?= nl2br(htmlspecialchars((string) ($j['jawaban_santri'] ?? '-'))) ?></p>
                        <form method="post" class="row g-1 align-items-end">
                            <input type="hidden" name="action" value="nilai_esai">
                            <input type="hidden" name="sesi_id" value="<?= $detailSesiId ?>">
                            <input type="hidden" name="soal_id" value="<?= (int) $j['soal_id'] ?>">
                            <div class="col-4">
                                <label class="form-label small">Nilai</label>
                                <input type="number" name="nilai_esai" class="form-control form-control-sm" min="0" max="100" step="0.5" value="<?= htmlspecialchars((string) ($j['nilai_esai'] ?? '')) ?>" required>
                            </div>
                            <div class="col-5">
                                <input type="text" name="catatan" class="form-control form-control-sm" placeholder="Catatan" value="<?= htmlspecialchars((string) ($j['catatan_pembimbing'] ?? '')) ?>">
                            </div>
                            <div class="col-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Simpan</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                <hr>
                <p class="small fw-semibold mb-2">Pilihan ganda (otomatis)</p>
                <?php foreach ($jawaban as $j):
                    if ((string) ($j['jenis'] ?? '') !== 'PG') {
                        continue;
                    }
                    $benar = (int) ($j['benar'] ?? 0) === 1;
                    ?>
                    <div class="ikhtibar-jawaban-pg <?= $benar ? 'ikhtibar-jawaban-pg--benar' : 'ikhtibar-jawaban-pg--salah' ?> mb-2">
                        <span class="small">#<?= (int) $j['nomor'] ?> — <strong><?= htmlspecialchars((string) ($j['jawaban_santri'] ?? '-')) ?></strong></span>
                        <span class="badge text-bg-<?= $benar ? 'success' : 'danger' ?> float-end"><?= $benar ? 'Benar' : 'Salah' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                <i class="fa-solid fa-hand-pointer fa-2x mb-2 opacity-50"></i>
                <p class="mb-0 small">Pilih santri di tabel untuk melihat jawaban dan menilai esai.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
