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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'nilai_esai') {
    $sesiId = (int) ($_POST['sesi_id'] ?? 0);
    $soalId = (int) ($_POST['soal_id'] ?? 0);
    $nilai = (float) ($_POST['nilai_esai'] ?? 0);
    $catatan = trim((string) ($_POST['catatan'] ?? ''));
    if ($sesiId > 0 && $soalId > 0) {
        ikhtibar_nilai_esai_manual($pdo, $sesiId, $soalId, max(0, min(100, $nilai)), $catatan);
        set_flash('success', 'Nilai esai disimpan.');
    }
    header('Location: ' . app_href('/pembimbing/tugas/nilai.php?tugas_id=' . $tugasId . '&sesi_id=' . $sesiId));
    exit;
}

$laporan = ikhtibar_laporan_nilai($pdo, $tugasId);
$detailSesiId = (int) ($_GET['sesi_id'] ?? 0);
$detailJawaban = [];
if ($detailSesiId > 0) {
    $stmt = $pdo->prepare('
        SELECT j.*, so.jenis, so.nomor, so.teks_soal, so.kunci_jawaban
        FROM ikhtibar_jawaban j
        INNER JOIN ikhtibar_soal so ON so.id = j.soal_id
        WHERE j.sesi_id = :s
        ORDER BY so.jenis, so.nomor
    ');
    $stmt->execute(['s' => $detailSesiId]);
    $detailJawaban = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Penilaian — ' . (string) $tugas['judul'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>">Tugas Ikhtibar</a></p>
    <h1 class="h4 mb-1">Laporan &amp; Penilaian</h1>
    <p class="text-muted mb-0"><?= htmlspecialchars((string) $tugas['judul']) ?> · <?= htmlspecialchars((string) $tugas['tanggal']) ?></p>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">Rekap nilai santri</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Santri</th><th>NIS</th><th>Status</th><th>PG (otomatis)</th><th>Esai</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($laporan === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Belum ada santri yang mengerjakan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($laporan as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                                <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string) $r['status']) ?></span></td>
                                <td><?= $r['skor_pg'] !== null ? htmlspecialchars((string) $r['skor_pg']) . '%' : '—' ?></td>
                                <td><?= $r['skor_esai'] !== null ? htmlspecialchars((string) $r['skor_esai']) : '—' ?></td>
                                <td><a href="?tugas_id=<?= $tugasId ?>&sesi_id=<?= (int) $r['sesi_id'] ?>" class="btn btn-sm btn-outline-primary">Detail</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <?php if ($detailSesiId > 0): ?>
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">Koreksi manual (Esai)</div>
            <div class="card-body">
                <?php foreach ($detailJawaban as $j):
                    if ((string) ($j['jenis'] ?? '') !== 'ESAI') {
                        continue;
                    }
                    ?>
                    <div class="border rounded p-2 mb-2 small">
                        <div class="fw-semibold">Esai <?= (int) $j['nomor'] ?></div>
                        <p class="mb-1"><?= nl2br(htmlspecialchars((string) ($j['teks_soal'] ?? ''))) ?></p>
                        <p class="mb-1"><strong>Jawaban:</strong> <?= nl2br(htmlspecialchars((string) ($j['jawaban_santri'] ?? '-'))) ?></p>
                        <p class="text-muted mb-2">Pedoman: <?= htmlspecialchars((string) ($j['kunci_jawaban'] ?? '-')) ?></p>
                        <form method="post" class="row g-1 align-items-end">
                            <input type="hidden" name="action" value="nilai_esai">
                            <input type="hidden" name="sesi_id" value="<?= $detailSesiId ?>">
                            <input type="hidden" name="soal_id" value="<?= (int) $j['soal_id'] ?>">
                            <div class="col-4">
                                <label class="form-label small">Nilai 0–100</label>
                                <input type="number" name="nilai_esai" class="form-control form-control-sm" min="0" max="100" step="0.5" value="<?= htmlspecialchars((string) ($j['nilai_esai'] ?? '')) ?>">
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
                <p class="small fw-semibold mb-1">PG (otomatis)</p>
                <?php foreach ($detailJawaban as $j):
                    if ((string) ($j['jenis'] ?? '') !== 'PG') {
                        continue;
                    }
                    $benar = (int) ($j['benar'] ?? 0) === 1;
                    ?>
                    <div class="small mb-1">
                        #<?= (int) $j['nomor'] ?> — Jawaban: <strong><?= htmlspecialchars((string) ($j['jawaban_santri'] ?? '-')) ?></strong>
                        (Kunci <?= htmlspecialchars((string) ($j['kunci_jawaban'] ?? '-')) ?>)
                        <span class="badge text-bg-<?= $benar ? 'success' : 'danger' ?>"><?= $benar ? 'Benar' : 'Salah' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card shadow-sm"><div class="card-body text-muted small">Pilih santri di tabel untuk koreksi esai.</div></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
