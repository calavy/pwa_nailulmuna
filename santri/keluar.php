<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/wali.php';
require_once __DIR__ . '/../helpers/sdm_embed.php';
require_once __DIR__ . '/../helpers/santri_status.php';

require_roles(['admin', 'pengurus']);
$embed = sdm_is_embed();
ensure_santri_identity_columns($pdo);
ensure_santri_keluar_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['santri_id'] ?? 0);
} else {
    $id = (int) ($_GET['id'] ?? 0);
}

if ($id <= 0) {
    $pageTitle = 'Administrasi keluar — pilih santri';
    if ($embed) {
        sdm_embed_layout_start($pageTitle);
    } else {
        require_once __DIR__ . '/../includes/header.php';
    }
    $need = $pdo->query("
        SELECT id, nis, nama_santri, tanggal_keluar, alasan_keluar
        FROM santri
        WHERE UPPER(TRIM(COALESCE(status_santri, 'AKTIF'))) IN ('NONAKTIF', 'NON_AKTIF')
          AND (keluar_settled_at IS NULL)
        ORDER BY tanggal_keluar DESC, nama_santri ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ?>
    <div class="mb-3">
        <h1 class="h3">Santri keluar</h1>
        <p class="text-muted small mb-0">Pilih santri yang statusnya non aktif dan belum diselesaikan administrasi keuangannya.</p>
    </div>
    <?php if ($need === []): ?>
        <div class="alert alert-info mb-0">Tidak ada santri yang menunggu penyelesaian keluar. Non aktifkan santri lewat <a href="/santri/index.php">Data santri aktif</a> (tombol non aktif). Arsip ada di <a href="/santri/mukimin.php">Data Mukimin</a>.</div>
    <?php else: ?>
        <div class="list-group shadow-sm">
            <?php foreach ($need as $n): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/santri/keluar.php?id=<?= (int) $n['id'] ?><?= $embed ? '&embed=1' : '' ?>">
                    <span><strong><?= htmlspecialchars((string) $n['nama_santri']) ?></strong> <span class="text-muted small"><?= htmlspecialchars((string) $n['nis']) ?></span></span>
                    <span class="small text-muted"><?= htmlspecialchars((string) ($n['tanggal_keluar'] ?? '')) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: /santri/index.php');
    exit;
}

$isNon = santri_status_is_keluar(santri_status_from_row($row));
$settled = trim((string) ($row['keluar_settled_at'] ?? '')) !== '';

if (!$isNon) {
    set_flash('error', 'Formulir ini hanya untuk santri yang sudah ditandai non aktif. Ubah status di halaman Jati diri (edit santri) terlebih dahulu.');
    header('Location: ' . sdm_embed_url('/santri/edit.php?id=' . $id));
    exit;
}

$periodeMulai = (int) app_setting($pdo, 'keuangan_periode_mulai', (string) (int) date('Y'));
$periodeSelesai = (int) app_setting($pdo, 'keuangan_periode_selesai', (string) ($periodeMulai + 1));
if ($periodeSelesai < $periodeMulai) {
    $periodeSelesai = $periodeMulai + 1;
}

$kelasKategori = trim((string) ($row['kategori_kelas'] ?? ''));
if ($kelasKategori === '' && trim((string) ($row['tingkatan'] ?? '')) !== '') {
    $kelasKategori = (string) $row['tingkatan'];
}

$outstanding = santri_outstanding_bulanan_rows($pdo, $id, $kelasKategori, $periodeMulai, $periodeSelesai);
$cashlessSaldo = santri_cashless_balance($pdo, $id);
$totalKekurangan = 0;
foreach ($outstanding as $o) {
    $totalKekurangan += (int) ($o['sisa'] ?? 0);
}

$bulanNama = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nop', 12 => 'Des',
];

$prefKeluarKat = strtoupper(trim((string) ($row['keluar_kategori'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$settled) {
    $token = (string) ($_POST['confirm_token'] ?? '');
    if ($token !== '1') {
        set_flash('error', 'Centang konfirmasi penyelesaian administrasi keluar.');
        header('Location: ' . sdm_embed_url('/santri/keluar.php?id=' . $id));
        exit;
    }
    $kat = strtoupper(trim((string) ($_POST['keluar_kategori'] ?? '')));
    if (!in_array($kat, ['TAMAT', 'KELUAR_PINDAH'], true)) {
        set_flash('error', 'Pilih kategori keluar: Muqim (tamat) atau Keluar (belum tamat).');
        header('Location: ' . sdm_embed_url('/santri/keluar.php?id=' . $id));
        exit;
    }
    $tanggalKeluar = trim((string) ($row['tanggal_keluar'] ?? ''));
    if ($tanggalKeluar === '') {
        $tanggalKeluar = date('Y-m-d');
    }

    $nomorKeluar = surat_nomor_ambil_atau_buat($pdo, 'surat_keluar', 'santri-keluar:' . $id);
    $nomorTang = surat_nomor_ambil_atau_buat($pdo, 'surat_tanggungan', 'santri-tanggungan:' . $id);

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    $waliSebelumSelesai = (int) ($row['wali_santri_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $sum = santri_settle_keuangan_on_exit($pdo, $id, $kelasKategori, $periodeMulai, $periodeSelesai, $tanggalKeluar, $uid, $nomorKeluar);
        $ringkas = implode("\n", $sum['lines']);
        $pdo->prepare('UPDATE santri SET keluar_kategori = :k, keluar_settled_at = NOW(), nomor_surat_keluar = :nk, nomor_surat_tanggungan = :nt, keluar_ringkasan_keuangan = :r, wali_santri_id = NULL WHERE id = :id')
            ->execute([
                'k' => $kat,
                'nk' => $nomorKeluar,
                'nt' => $nomorTang,
                'r' => $ringkas,
                'id' => $id,
            ]);
        $pdo->commit();
        if ($waliSebelumSelesai > 0) {
            ensure_wali_santri_table($pdo);
            wali_santri_prune_if_orphan($pdo, $waliSebelumSelesai);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal menyelesaikan administrasi keluar. ' . ($e->getMessage() ?: ''));
        sdm_embed_done_redirect('/santri/keluar.php?id=' . $id);
    }

    mukimin_sync_from_santri($pdo, $id);
    set_flash('success', 'Administrasi keluar selesai. Cetak surat resmi dan surat tanggungan.');
    sdm_embed_done_redirect('/santri/mukimin.php');
}

$pageTitle = 'Administrasi keluar';
if ($embed) {
    sdm_embed_layout_start($pageTitle);
} else {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1">Santri keluar</h1>
        <p class="text-muted mb-0 small">Penyelesaian tagihan bulanan, saldo cashless, dan surat resmi.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/santri/index.php" class="btn btn-outline-secondary btn-sm">Santri aktif</a>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <p class="mb-0"><strong><?= htmlspecialchars((string) $row['nama_santri']) ?></strong>
            <span class="text-muted small">· NIS <?= htmlspecialchars((string) $row['nis']) ?> · <?= htmlspecialchars((string) ($row['tingkatan'] ?? '-')) ?></span></p>
        <p class="small text-muted mb-0 mt-1">Alasan: <?= htmlspecialchars((string) ($row['alasan_keluar'] ?? '-')) ?> · Tanggal keluar: <?= htmlspecialchars((string) ($row['tanggal_keluar'] ?? '-')) ?></p>
    </div>
</div>

<?php if ($settled): ?>
    <div class="alert alert-success">
        <strong>Selesai.</strong> Penyelesaian keuangan tercatat pada <?= htmlspecialchars((string) ($row['keluar_settled_at'] ?? '')) ?>.
        Kategori: <?= htmlspecialchars(keluar_kategori_label((string) ($row['keluar_kategori'] ?? ''))) ?>.
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-primary" target="_blank" rel="noopener" href="/santri/surat_keluar.php?id=<?= (int) $id ?>">Cetak surat keluar</a>
        <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="/santri/surat_tanggungan.php?id=<?= (int) $id ?>">Cetak surat tanggungan</a>
        <a class="btn btn-outline-secondary" href="/santri/mukimin.php">Data Mukimin</a>
    </div>
    <?php if (trim((string) ($row['keluar_ringkasan_keuangan'] ?? '')) !== ''): ?>
        <div class="card border-0 bg-light mb-4">
            <div class="card-body small">
                <strong>Ringkasan keuangan</strong>
                <pre class="mb-0 mt-2 small" style="white-space:pre-wrap;font-family:inherit;"><?= htmlspecialchars((string) $row['keluar_ringkasan_keuangan']) ?></pre>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm mb-3 border-0 bg-light">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h2 class="h6 mb-1">Kekurangan sebelum administrasi selesai</h2>
                    <p class="small text-muted mb-0">TA <?= (int) $periodeMulai ?>/<?= (int) $periodeSelesai ?> · Saldo cashless dipakai otomatis saat Anda menyelesaikan administrasi.</p>
                </div>
                <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="/santri/keluar_kekurangan_print.php?id=<?= (int) $id ?>">Cetak ringkasan</a>
            </div>
            <div class="row g-2 small">
                <div class="col-sm-4">
                    <div class="p-2 rounded border bg-white h-100">
                        <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Total sisa tagihan</div>
                        <div class="fs-5 fw-bold font-monospace">Rp <?= number_format($totalKekurangan, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-2 rounded border bg-white h-100">
                        <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Saldo cashless</div>
                        <div class="fs-5 fw-bold font-monospace">Rp <?= number_format($cashlessSaldo, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-2 rounded border bg-white h-100">
                        <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Baris tagihan</div>
                        <div class="fs-5 fw-bold"><?= count($outstanding) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($outstanding === []): ?>
                <p class="small text-muted mb-0 mt-3">Tidak ada sisa tagihan bulanan menurut data pembayaran.</p>
            <?php else: ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered mb-0 align-middle bg-white">
                        <thead class="table-light"><tr><th>Bln</th><th>Pos</th><th class="text-end">Sisa</th></tr></thead>
                        <tbody>
                            <?php foreach ($outstanding as $o): ?>
                                <tr>
                                    <td class="text-nowrap"><?= (int) $o['bulan'] ?> <?= htmlspecialchars($bulanNama[(int) $o['bulan']] ?? '') ?></td>
                                    <td><?= htmlspecialchars((string) $o['nama']) ?></td>
                                    <td class="text-end font-monospace small">Rp <?= number_format((int) $o['sisa'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" class="card shadow-sm">
        <div class="card-body">
            <input type="hidden" name="santri_id" value="<?= (int) $id ?>">
            <div class="mb-3">
                <label class="form-label">Kategori (muqim = tamat, keluar = belum tamat)</label>
                <select name="keluar_kategori" class="form-select" required>
                    <option value="">— pilih —</option>
                    <option value="TAMAT" <?= $prefKeluarKat === 'TAMAT' ? 'selected' : '' ?>>Muqim (tamat / selesai)</option>
                    <option value="KELUAR_PINDAH" <?= in_array($prefKeluarKat, ['KELUAR_PINDAH', 'BOYONG'], true) ? 'selected' : '' ?>>Keluar (belum tamat / pindah)</option>
                </select>
                <div class="form-text">Bisa diubah di sini sebelum menyelesaikan administrasi.</div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" name="confirm_token" id="cf-keluar">
                <label class="form-check-label" for="cf-keluar">Saya mengerti tindakan ini akan mencatat pembayaran/penyesuaian di modul keuangan dan tidak dapat dibatalkan otomatis.</label>
            </div>
            <button type="submit" class="btn btn-danger">Selesaikan administrasi keluar</button>
        </div>
    </form>
<?php endif; ?>

<?php
if ($embed) {
    sdm_embed_layout_end();
}
require_once __DIR__ . '/../includes/footer.php';
?>
