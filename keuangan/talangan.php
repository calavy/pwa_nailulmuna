<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_talangan.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_talangan_tables($pdo);

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan_pinjaman') {
        $result = keuangan_talangan_simpan_pinjaman($pdo, $_POST, $userId);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /pwa_nailulmuna/keuangan/talangan.php');
        exit;
    }
    if ($action === 'kembalikan') {
        $id = (int) ($_POST['pinjaman_id'] ?? 0);
        $result = keuangan_talangan_kembalikan($pdo, $id, $userId);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /pwa_nailulmuna/keuangan/talangan.php');
        exit;
    }
}

$posOptions = keuangan_talangan_pos_options($pdo);
$saldoPos = keuangan_talangan_saldo_per_pos($pdo);
$ledger = keuangan_talangan_ledger($pdo, 25);
$totalPiutangAktif = (int) ($ledger['total_aktif'] ?? 0);
$jumlahPinjamanAktif = count($ledger['aktif']);

$pageTitle = 'Dana Talangan';
$bodyClass = keuangan_body_class('keuangan-talangan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="/pwa_nailulmuna/keuangan/index.php">Keuangan</a> · Dana talangan
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-arrows-left-right text-primary me-1"></i> Dana Talangan (Internal Lending)</h1>
    <p class="text-muted mb-0">
        Saling pinjam antar-pos agar arus kas tetap terlacak.
        <strong>Saldo aktual</strong> = total penerimaan fisik per POS;
        <strong>saldo tersedia</strong> = aktual dikurangi piutang keluar ditambah utang masuk internal.
    </p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-primary border-opacity-25 h-100">
            <div class="card-body py-3">
                <div class="small text-muted">Pinjaman aktif</div>
                <div class="fs-4 fw-bold"><?= $jumlahPinjamanAktif ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-warning border-opacity-25 h-100">
            <div class="card-body py-3">
                <div class="small text-muted">Total outstanding</div>
                <div class="fs-5 fw-bold font-monospace"><?= htmlspecialchars($formatRupiah($totalPiutangAktif)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card shadow-sm border-info border-opacity-25 h-100">
            <div class="card-body py-3 small text-muted mb-0">
                <i class="fa-solid fa-circle-info me-1"></i>
                Mengembalikan saldo menandai pinjaman <strong>LUNAS</strong> — saldo tersedia pos pemberi naik kembali tanpa mencatat pembayaran santri baru.
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5" id="form-pinjaman">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                <i class="fa-solid fa-file-pen me-1"></i> Formulir pinjaman
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="simpan_pinjaman">
                    <div class="col-12">
                        <label class="form-label">Pos pemberi <span class="text-danger">*</span></label>
                        <select class="form-select" name="pos_pemberi" id="pos_pemberi" required>
                            <option value="">— pilih —</option>
                            <?php foreach ($saldoPos as $sp): ?>
                                <option value="<?= htmlspecialchars($sp['slug']) ?>"
                                    data-tersedia="<?= (int) $sp['saldo_tersedia'] ?>">
                                    <?= htmlspecialchars($sp['nama']) ?>
                                    (tersedia <?= htmlspecialchars($formatRupiah((int) $sp['saldo_tersedia'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Pos penerima <span class="text-danger">*</span></label>
                        <select class="form-select" name="pos_penerima" required>
                            <option value="">— pilih —</option>
                            <?php foreach ($posOptions as $p): ?>
                                <option value="<?= htmlspecialchars($p['slug']) ?>"><?= htmlspecialchars($p['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                        <input type="text" inputmode="numeric" class="form-control font-monospace" name="nominal" placeholder="500000" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal_pinjam" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alasan</label>
                        <textarea class="form-control" name="alasan" rows="2" maxlength="500" placeholder="Contoh: Talangan operasional dapur sementara kas syahriyah belum cair"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-check me-1"></i> Catat pinjaman
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-header fw-semibold small">Saldo per POS</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>POS</th>
                                <th class="text-end">Aktual</th>
                                <th class="text-end">Tersedia</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($saldoPos as $sp): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?= htmlspecialchars($sp['nama']) ?></span>
                                    <span class="d-block text-muted" style="font-size:.7rem"><?= htmlspecialchars($sp['kategori']) ?></span>
                                </td>
                                <td class="text-end font-monospace small"><?= htmlspecialchars($formatRupiah((int) $sp['saldo_aktual'])) ?></td>
                                <td class="text-end font-monospace small fw-semibold <?= (int) $sp['saldo_tersedia'] < 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= htmlspecialchars($formatRupiah((int) $sp['saldo_tersedia'])) ?>
                                </td>
                            </tr>
                            <?php if ((int) $sp['piutang_keluar'] > 0 || (int) $sp['utang_masuk'] > 0): ?>
                            <tr class="table-light">
                                <td colspan="3" class="small text-muted py-1 ps-3">
                                    <?php if ((int) $sp['piutang_keluar'] > 0): ?>
                                        Pinjam ke pos lain: −<?= htmlspecialchars($formatRupiah((int) $sp['piutang_keluar'])) ?>
                                    <?php endif; ?>
                                    <?php if ((int) $sp['utang_masuk'] > 0): ?>
                                        <?= (int) $sp['piutang_keluar'] > 0 ? ' · ' : '' ?>
                                        Terima pinjaman: +<?= htmlspecialchars($formatRupiah((int) $sp['utang_masuk'])) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7" id="buku-utang">
        <div class="card shadow-sm border-warning border-opacity-50">
            <div class="card-header bg-warning bg-opacity-10 fw-semibold">
                <i class="fa-solid fa-book me-1"></i> Buku utang antar-pos (internal ledger)
            </div>
            <div class="card-body p-0">
                <?php if ($ledger['aktif'] === []): ?>
                    <p class="text-muted small p-3 mb-0">Belum ada pinjaman aktif. Semua pos dalam kondisi netral internal.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Peminjam → Pemberi</th>
                                    <th class="text-end">Nominal</th>
                                    <th>Alasan</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ledger['aktif'] as $row): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= htmlspecialchars((string) ($row['tanggal_pinjam'] ?? '')) ?></td>
                                    <td>
                                        <span class="badge text-bg-warning"><?= htmlspecialchars((string) ($row['pos_penerima_nama'] ?? '')) ?></span>
                                        <i class="fa-solid fa-arrow-right mx-1 text-muted small"></i>
                                        <span class="badge text-bg-primary"><?= htmlspecialchars((string) ($row['pos_pemberi_nama'] ?? '')) ?></span>
                                        <span class="d-block text-muted mt-1" style="font-size:.72rem">
                                            <?= htmlspecialchars((string) ($row['pos_penerima_nama'] ?? '')) ?> pinjam dari <?= htmlspecialchars((string) ($row['pos_pemberi_nama'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td class="text-end font-monospace fw-semibold"><?= htmlspecialchars($formatRupiah((int) round((float) ($row['nominal'] ?? 0)))) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars((string) ($row['alasan'] ?? '—')) ?></td>
                                    <td class="text-end text-nowrap">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Kembalikan saldo pinjaman ini? Status akan menjadi LUNAS.');">
                                            <input type="hidden" name="action" value="kembalikan">
                                            <input type="hidden" name="pinjaman_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="fa-solid fa-rotate-left me-1"></i> Kembalikan
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($ledger['riwayat'] !== []): ?>
        <div class="card shadow-sm mt-3">
            <div class="card-header fw-semibold small text-muted">Riwayat pelunasan</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Lunas</th>
                                <th>Pinjam</th>
                                <th>Peminjam → Pemberi</th>
                                <th class="text-end">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ledger['riwayat'] as $h): ?>
                            <tr>
                                <td class="small text-nowrap"><?= htmlspecialchars((string) ($h['tanggal_lunas'] ?? '')) ?></td>
                                <td class="small text-nowrap text-muted"><?= htmlspecialchars((string) ($h['tanggal_pinjam'] ?? '')) ?></td>
                                <td class="small">
                                    <?= htmlspecialchars((string) ($h['pos_penerima_nama'] ?? '')) ?>
                                    → <?= htmlspecialchars((string) ($h['pos_pemberi_nama'] ?? '')) ?>
                                </td>
                                <td class="text-end font-monospace small"><?= htmlspecialchars($formatRupiah((int) round((float) ($h['nominal'] ?? 0)))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.keuangan-talangan-page .card-header { font-size: 0.95rem; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
