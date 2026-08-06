<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_bos_pengaturan') {
        $result = bos_save_pengaturan($pdo, $_POST);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/keuangan-bos/pengaturan.php'));
        exit;
    }
    if ($action === 'save_saldo_awal_bos') {
        $result = bos_save_saldo_awal($pdo, $_POST);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/keuangan-bos/pengaturan.php?tahun_saldo=' . (int) ($_POST['saldo_tahun'] ?? date('Y')) . '#saldo-awal'));
        exit;
    }
}

$nomWustho = bos_nominal_per_santri($pdo, BOS_JENJANG_WUSTHO);
$nomUlya = bos_nominal_per_santri($pdo, BOS_JENJANG_ULYA);
$akunRows = bos_fetch_akun_aktif($pdo);
$akunWustho = max(0, (int) app_setting($pdo, 'bos_akun_id_wustho', '0'));
$akunUlya = max(0, (int) app_setting($pdo, 'bos_akun_id_ulya', '0'));
$akunSpp = max(0, (int) app_setting($pdo, 'bos_akun_id_spp', '0'));

$tahunSaldo = max(2000, min(2105, (int) ($_GET['tahun_saldo'] ?? date('Y'))));
$saldoRows = bos_fetch_saldo_awal_tahun($pdo, $tahunSaldo);

$pageTitle = 'Pengaturan Keuangan BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Pengaturan Keuangan BOS</h1>
    <p class="text-muted mb-0">Nominal BOS per santri, akun kas/bank, dan saldo awal tahun.</p>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><span class="nav-link active">Nominal &amp; Akun</span></li>
    <li class="nav-item">
        <a class="nav-link" href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan-pos.php')) ?>">Pos Pengeluaran Lain</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#saldo-awal">Saldo Awal Tahun</a>
    </li>
</ul>

<form method="post" class="card shadow-sm mb-4">
    <input type="hidden" name="action" value="save_bos_pengaturan">
    <div class="card-header fw-semibold">Nominal BOS per santri</div>
    <div class="card-body">
        <p class="small text-muted">Nominal BOS per santri per bulan kalender Masehi (Januari–Desember).</p>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100 bg-light">
                    <div class="fw-semibold mb-2"><i class="fa-solid fa-book-quran me-1 text-primary"></i> Wustho (1/2/3)</div>
                    <div class="input-group">
                        <span class="input-group-text">Rp / santri / bulan</span>
                        <input type="number" name="bos_nominal_wustho" class="form-control" min="0" step="1000" value="<?= (int) $nomWustho ?>" required>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100 bg-light">
                    <div class="fw-semibold mb-2"><i class="fa-solid fa-graduation-cap me-1 text-success"></i> Ulya (1/2/3)</div>
                    <div class="input-group">
                        <span class="input-group-text">Rp / santri / bulan</span>
                        <input type="number" name="bos_nominal_ulya" class="form-control" min="0" step="1000" value="<?= (int) $nomUlya ?>" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header fw-semibold border-top">Akun kas / bank BOS</div>
    <div class="card-body">
        <?php if ($akunRows === []): ?>
            <p class="text-danger small mb-0">Belum ada akun BOS. Schema akan membuat akun default otomatis — refresh halaman.</p>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Bank BOS Wustho</label>
                    <select name="bos_akun_wustho" class="form-select" required>
                        <option value="">— pilih —</option>
                        <?php foreach ($akunRows as $ar): ?>
                            <option value="<?= (int) $ar['id'] ?>" <?= (int) $ar['id'] === $akunWustho ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $ar['nama_akun']) ?> (COA <?= htmlspecialchars((string) $ar['kode_coa']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bank BOS Ulya</label>
                    <select name="bos_akun_ulya" class="form-select" required>
                        <option value="">— pilih —</option>
                        <?php foreach ($akunRows as $ar): ?>
                            <option value="<?= (int) $ar['id'] ?>" <?= (int) $ar['id'] === $akunUlya ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $ar['nama_akun']) ?> (COA <?= htmlspecialchars((string) $ar['kode_coa']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bank SPP / Syahriyah</label>
                    <select name="bos_akun_spp" class="form-select">
                        <option value="0">— opsional —</option>
                        <?php foreach ($akunRows as $ar): ?>
                            <option value="<?= (int) $ar['id'] ?>" <?= (int) $ar['id'] === $akunSpp ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $ar['nama_akun']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan pengaturan</button>
        <a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>" class="btn btn-outline-secondary">Kembali ke dashboard</a>
    </div>
</form>

<div id="saldo-awal" class="card shadow-sm">
    <div class="card-header fw-semibold">Saldo Awal Tahun</div>
    <div class="card-body">
        <p class="small text-muted">Input saldo per <strong>1 Januari</strong> untuk setiap akun bank/kas BOS.</p>
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-auto">
                <label class="form-label small mb-0">Tahun Masehi</label>
                <select name="tahun_saldo" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $tahunSaldo === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
        <?php if ($akunRows === []): ?>
            <p class="text-danger small mb-0">Belum ada akun BOS.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="save_saldo_awal_bos">
                <input type="hidden" name="saldo_tahun" value="<?= $tahunSaldo ?>">
                <p class="fw-semibold mb-2">Saldo per 1 Januari <?= $tahunSaldo ?></p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Akun</th>
                                <th style="width:14rem">Saldo (Rp)</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($akunRows as $ar): ?>
                                <?php
                                $aid = (int) ($ar['id'] ?? 0);
                                $saved = $saldoRows[$aid] ?? null;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?></td>
                                    <td>
                                        <input type="number" name="saldo_akun_<?= $aid ?>" class="form-control form-control-sm" min="0" step="1000"
                                               value="<?= (int) ($saved['nominal'] ?? 0) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="saldo_ket_<?= $aid ?>" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars((string) ($saved['keterangan'] ?? '')) ?>" placeholder="Opsional">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan saldo awal</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
