<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$userNama = trim((string) ($_SESSION['user']['nama'] ?? 'Petugas'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pengeluaran') {
    $result = keuangan_save_pengeluaran($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/keuangan/pengeluaran.php'));
    exit;
}

$akunRows = keuangan_fetch_akun_aktif($pdo);
$alokasiPengeluaranOpts = keuangan_pengeluaran_alokasi_options($pdo);
$defaultAkunId = 0;
foreach ($akunRows as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunId = (int) $ar['id'];
        break;
    }
}
if ($defaultAkunId <= 0 && $akunRows !== []) {
    $defaultAkunId = (int) ($akunRows[0]['id'] ?? 0);
}

$recentRows = keuangan_recent_pengeluaran($pdo, 20);

$pageTitle = 'Input Pengeluaran';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pengeluaran</p>
    <h1 class="h4 mb-1">Formulir Pengeluaran Operasional</h1>
    <p class="text-muted mb-0">
        Catat beban keluar dari kas/bank. Untuk gaji pembimbing gunakan modul terpisah.
        <a href="/rekap/pembimbing.php">Gaji pembimbing</a>
        · <a href="/keuangan/index.php">Dashboard keuangan</a>
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-6" id="formulir-pengeluaran">
        <div class="card shadow-sm">
            <div class="card-header bg-danger bg-opacity-10 fw-semibold text-danger">Transaksi keluar</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_pengeluaran">
                    <div class="col-md-4">
                        <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tanggal_pengeluaran" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Penanggung jawab <span class="text-danger">*</span></label>
                        <input class="form-control" name="penanggung_jawab" value="<?= htmlspecialchars($userNama) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pos / jenis beban <span class="text-danger">*</span></label>
                        <input class="form-control" name="pos_pengeluaran" list="pos-suggest" placeholder="Contoh: ATK, Transport, Konsumsi" required>
                        <datalist id="pos-suggest">
                            <option value="ATK"></option>
                            <option value="Transport"></option>
                            <option value="Konsumsi"></option>
                            <option value="Pemeliharaan"></option>
                            <option value="Utilitas"></option>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alokasi dana (opsional)</label>
                        <select class="form-select" name="alokasi_nama">
                            <option value="">— Tidak terkait alokasi —</option>
                            <?php
                            $lastGroup = '';
                            foreach ($alokasiPengeluaranOpts as $opt):
                                $grp = (string) ($opt['group'] ?? '');
                                if ($grp !== $lastGroup):
                                    if ($lastGroup !== '') {
                                        echo '</optgroup>';
                                    }
                                    echo '<optgroup label="' . htmlspecialchars($grp) . '">';
                                    $lastGroup = $grp;
                                endif;
                                ?>
                                <option value="<?= htmlspecialchars((string) ($opt['value'] ?? '')) ?>">
                                    <?= htmlspecialchars((string) ($opt['label'] ?? '')) ?>
                                </option>
                            <?php endforeach;
                            if ($lastGroup !== '') {
                                echo '</optgroup>';
                            }
                            ?>
                        </select>
                        <div class="form-text">Termasuk dana umum syahriyah PKPPS/kelas — selaras dengan laporan alokasi &amp; pengaturan syahriyah.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Metode</label>
                        <select class="form-select" name="metode_keluar">
                            <option value="KAS">Kas</option>
                            <option value="TRANSFER">Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Akun sumber dana <span class="text-danger">*</span></label>
                        <select class="form-select" name="akun_id" required>
                            <?php if ($akunRows === []): ?>
                                <option value="">Belum ada akun</option>
                            <?php else: ?>
                                <?php foreach ($akunRows as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $defaultAkunId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $ak['jenis_akun']) ?> — <?= htmlspecialchars((string) $ak['nama_akun']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nominal <span class="text-danger">*</span></label>
                        <input class="form-control" name="nominal_pengeluaran" inputmode="numeric" placeholder="0" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">No. bukti</label>
                        <input class="form-control" name="no_bukti" placeholder="Opsional / wajib transfer">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Keterangan</label>
                        <input class="form-control" name="keterangan_pengeluaran" placeholder="Catatan">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-minus-circle me-1"></i> Simpan pengeluaran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Pengeluaran terakhir</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Pos</th>
                                <th>Alokasi</th>
                                <th class="text-end">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentRows === []): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada pengeluaran tercatat.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRows as $pr): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars((string) $pr['tanggal']) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars((string) $pr['pos']) ?>
                                        <div class="text-muted"><?= htmlspecialchars((string) ($pr['penanggung_jawab'] ?? '')) ?></div>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string) ($pr['alokasi_nama'] ?: '—')) ?></td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah((int) ((float) $pr['nominal']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
