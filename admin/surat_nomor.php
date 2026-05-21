<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';

require_roles(['admin', 'pengurus']);

ensure_surat_nomor_schema($pdo);

$jenisList = [
    'izin_keluar' => 'Izin keluar — SIZN.S',
    'izin_tugas' => 'Izin tugas — IZN.T',
    'izin_pulang' => 'Izin tugas (lama) — IZN.P',
    'izin_sakit' => 'Izin sakit — IZN.S',
    'sp1' => 'Surat peringatan 1 — S.SP1.',
    'sp2' => 'Surat peringatan 2 — S.SP2.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jenis = trim((string) ($_POST['jenis_kode'] ?? ''));
    $tahun = (int) ($_POST['tahun'] ?? date('Y'));
    $seq = (int) ($_POST['seq_terakhir'] ?? 0);
    if ($jenis !== '' && isset($jenisList[$jenis]) && $tahun >= 2000 && $tahun <= 2100) {
        surat_nomor_set_seq($pdo, $jenis, $tahun, $seq);
        set_flash('success', 'Penomoran diperbarui.');
    } else {
        set_flash('error', 'Data tidak valid.');
    }
    header('Location: ' . app_href('/admin/surat_nomor.php'));
    exit;
}

$rows = surat_nomor_seq_semua($pdo);
$pageTitle = 'Administrasi — Nomor surat';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <p class="text-muted small mb-1">Administrasi</p>
    <h1 class="h3 mb-1">Penomoran surat berkesinambungan</h1>
    <p class="text-muted mb-0 small">Nomor otomatis naik per jenis surat dan tahun. Format contoh: <code>0007/SIZN.S/VI/2026</code> (izin keluar), <code>0008/IZN.T/VI/2026</code> (izin tugas), <code>0012/S.SP1./VI/2026</code> (SP1).</p>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light"><strong>Set manual urutan terakhir</strong> <span class="text-muted small">(nomor berikutnya = nilai + 1)</span></div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Jenis surat</label>
                <select name="jenis_kode" class="form-select" required>
                    <?php foreach ($jenisList as $k => $lab): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Tahun</label>
                <input type="number" name="tahun" class="form-control" value="<?= (int) date('Y') ?>" min="2000" max="2100" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Seq terakhir</label>
                <input type="number" name="seq_terakhir" class="form-control" value="0" min="0" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-light"><strong>Riwayat counter</strong></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Jenis</th>
                    <th>Tahun</th>
                    <th class="text-end">Seq terakhir</th>
                    <th>Contoh nomor berikutnya</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" class="text-muted text-center py-4">Belum ada data — akan terisi saat surat pertama dicetak.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php
                $jk = (string) ($r['jenis_kode'] ?? '');
                $th = (int) ($r['tahun'] ?? 0);
                $sq = (int) ($r['seq_terakhir'] ?? 0);
                $next = $sq + 1;
                $pref = surat_nomor_prefix_for_jenis($jk);
                $rom = surat_nomor_bulan_romawi((int) date('n'));
                $contoh = str_pad((string) $next, 4, '0', STR_PAD_LEFT) . '/' . $pref . '/' . $rom . '/' . $th;
                ?>
                <tr>
                    <td><?= htmlspecialchars($jenisList[$jk] ?? $jk) ?></td>
                    <td><?= (int) $th ?></td>
                    <td class="text-end font-monospace"><?= (int) $sq ?></td>
                    <td class="small font-monospace text-muted"><?= htmlspecialchars($contoh) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3 mb-0">
    <a href="/admin/rekap_surat_izin.php">Rekapan surat izin</a> ·
    <a href="/admin/rekap_surat_sp.php">Rekap surat SP</a> ·
    <a href="/perizinan/index.php">Perizinan</a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
