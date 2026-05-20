<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

$pdo->exec("
CREATE TABLE IF NOT EXISTS cashless_accounts (
    santri_id INT PRIMARY KEY,
    pin_hash VARCHAR(255) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS cashless_nominal_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_code VARCHAR(80) NOT NULL UNIQUE,
    nominal INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
ensure_cashless_nominal_qr_map_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'save_cashless_limit') {
        $limitRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['batas_harian'] ?? '10000')) ?? '10000';
        $limit = max(0, (int) $limitRaw);
        save_setting($pdo, 'cashless_daily_limit', (string) $limit);
        set_flash('success', 'Batas harian cashless berhasil disimpan.');
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_scan_uang_setting') {
        $isEnabled = (int) ($_POST['cashless_scan_uang_enabled'] ?? 0) === 1 ? '1' : '0';
        $isVoice = (int) ($_POST['cashless_scan_uang_voice'] ?? 0) === 1 ? '1' : '0';
        $maxNominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['cashless_scan_uang_max_nominal'] ?? '200000')) ?? '200000';
        $maxNominal = max(1000, (int) $maxNominalRaw);
        save_setting($pdo, 'cashless_scan_uang_enabled', $isEnabled);
        save_setting($pdo, 'cashless_scan_uang_voice', $isVoice);
        save_setting($pdo, 'cashless_scan_uang_max_nominal', (string) $maxNominal);
        set_flash('success', 'Pengaturan scan uang cashless berhasil disimpan.');
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'create_qr_nominal_map') {
        $kode = cashless_normalize_money_qr_payload((string) ($_POST['map_kode_qr'] ?? ''));
        $nominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['map_nominal'] ?? '0')) ?? '0';
        $nominal = (int) $nominalRaw;
        $ket = trim((string) ($_POST['map_keterangan'] ?? ''));
        if ($kode === '' || strlen($kode) > 120) {
            set_flash('error', 'Kode QR wajib diisi (alfanumerik, maks. 120 karakter setelah normalisasi).');
            header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#peta-qr-nominal');
            exit;
        }
        if ($nominal <= 0) {
            set_flash('error', 'Nominal harus lebih dari 0.');
            header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#peta-qr-nominal');
            exit;
        }
        try {
            $pdo->prepare('
                INSERT INTO cashless_nominal_qr_map (kode_qr, nominal, keterangan, is_aktif)
                VALUES (:kode, :nominal, :ket, 1)
            ')->execute(['kode' => $kode, 'nominal' => $nominal, 'ket' => $ket !== '' ? $ket : null]);
            set_flash('success', 'Peta QR nominal ditambahkan. Isi QR dengan teks: ' . $kode . ' (atau huruf kecil; sistem menyesuaikan).');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menyimpan: kode mungkin sudah dipakai.');
        }
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#peta-qr-nominal');
        exit;
    }
    if (($_POST['action'] ?? '') === 'update_qr_nominal_map') {
        $id = (int) ($_POST['map_id'] ?? 0);
        $nominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['map_nominal'] ?? '0')) ?? '0';
        $nominal = (int) $nominalRaw;
        $ket = trim((string) ($_POST['map_keterangan'] ?? ''));
        $aktif = (int) ($_POST['map_is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($id <= 0 || $nominal <= 0) {
            set_flash('error', 'Data peta QR tidak valid.');
        } else {
            $pdo->prepare('
                UPDATE cashless_nominal_qr_map
                SET nominal = :nominal, keterangan = :ket, is_aktif = :aktif
                WHERE id = :id
            ')->execute([
                'nominal' => $nominal,
                'ket' => $ket !== '' ? $ket : null,
                'aktif' => $aktif,
                'id' => $id,
            ]);
            set_flash('success', 'Peta QR nominal diperbarui.');
        }
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#peta-qr-nominal');
        exit;
    }
    if (($_POST['action'] ?? '') === 'delete_qr_nominal_map') {
        $id = (int) ($_POST['map_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM cashless_nominal_qr_map WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Peta QR nominal dihapus.');
        }
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#peta-qr-nominal');
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_cashless_pin') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $pinBaru = trim((string) ($_POST['pin_baru'] ?? ''));
        $pinKonf = trim((string) ($_POST['pin_konfirmasi'] ?? ''));
        if ($santriId <= 0) {
            set_flash('error', 'Pilih santri.');
            header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#form-pin-cashless');
            exit;
        }
        if (strlen($pinBaru) < 4) {
            set_flash('error', 'PIN minimal 4 digit.');
            header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php?ubah_pin=' . $santriId . '#form-pin-cashless');
            exit;
        }
        if ($pinBaru !== $pinKonf) {
            set_flash('error', 'PIN dan konfirmasi PIN tidak sama.');
            header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php?ubah_pin=' . $santriId . '#form-pin-cashless');
            exit;
        }
        $pdo->prepare('INSERT IGNORE INTO cashless_accounts (santri_id, balance) VALUES (:santri_id, 0)')->execute(['santri_id' => $santriId]);
        $pdo->prepare('UPDATE cashless_accounts SET pin_hash = :pin_hash WHERE santri_id = :santri_id')->execute([
            'pin_hash' => password_hash($pinBaru, PASSWORD_DEFAULT),
            'santri_id' => $santriId,
        ]);
        set_flash('success', 'PIN cashless berhasil disimpan.');
        header('Location: /pwa_nailulmuna/keuangan/cashless_pin.php#form-pin-cashless');
        exit;
    }
}

$dailyLimit = (int) app_setting($pdo, 'cashless_daily_limit', '10000');
$scanUangEnabled = app_setting($pdo, 'cashless_scan_uang_enabled', '1') === '1';
$scanUangVoice = app_setting($pdo, 'cashless_scan_uang_voice', '1') === '1';
$scanUangMaxNominal = (int) app_setting($pdo, 'cashless_scan_uang_max_nominal', '200000');
$scanUangMaxNominal = max(1000, $scanUangMaxNominal);
$qrNominalMapRows = $pdo->query('
    SELECT id, kode_qr, nominal, keterangan, is_aktif
    FROM cashless_nominal_qr_map
    ORDER BY is_aktif DESC, nominal ASC, kode_qr ASC
')->fetchAll();

$santriNameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
$santriLevelExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
$joinKelas = '';
if (!column_exists($pdo, 'santri', 'tingkatan') && column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
    $joinKelas = ' LEFT JOIN kelas k ON k.id = s.kelas_id ';
    $santriLevelExpr = 'k.nama_kelas';
}
$whereAktif = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE s.is_aktif = 1 ' : '';
$santriRows = $pdo->query("
    SELECT s.id, s.nis, {$santriNameExpr} AS nama_santri, {$santriLevelExpr} AS tingkatan
    FROM santri s
    {$joinKelas}
    {$whereAktif}
    ORDER BY nama_santri ASC
")->fetchAll();

$pinRows = $pdo->query("
    SELECT ca.santri_id, ca.balance, {$santriNameExpr} AS nama_santri, s.nis
    FROM cashless_accounts ca
    INNER JOIN santri s ON s.id = ca.santri_id
    ORDER BY nama_santri ASC
")->fetchAll();

$santriPinStatusRows = $pdo->query("
    SELECT s.id, s.nis, {$santriNameExpr} AS nama_santri,
           ca.balance,
           (ca.pin_hash IS NOT NULL AND ca.pin_hash <> '') AS pin_terpasang
    FROM santri s
    LEFT JOIN cashless_accounts ca ON ca.santri_id = s.id
    {$whereAktif}
    ORDER BY nama_santri ASC
")->fetchAll();

$ubahPinSantriId = (int) ($_GET['ubah_pin'] ?? 0);

$pageTitle = 'Pengaturan Cashless & Uang Saku';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <p class="page-intro-kicker mb-1 small"><a href="/pwa_nailulmuna/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
        <h1 class="h4 mb-0">Pengaturan Cashless &amp; Uang Saku</h1>
        <p class="small text-muted mb-0">PIN santri, batas belanja harian, scan uang, dan alur top-up dari pembayaran Saku.</p>
    </div>
    <a href="/pwa_nailulmuna/keuangan/cashless_scan.php" class="btn btn-outline-danger btn-sm">Ke Scan Cashless</a>
</div>
<div class="alert alert-info small mb-3">
    <strong>Uang saku (opsional):</strong> jika wali membayar pos <em>Saku</em> (mis. Rp 100.000), nominal itu masuk saldo <strong>cashless</strong> santri.
    Santri memakai saldo untuk belanja di pondok; pengeluaran per hari dibatasi di bawah (standar Rp <?= number_format($dailyLimit, 0, ',', '.') ?>).
    Tagihan wajib bulanan hanya <strong>Syahriyah</strong> dan <strong>Makan</strong>.
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">Uang saku &amp; batas harian</div>
            <div class="card-body">
                <form method="post" class="mb-3 border rounded p-3 bg-light-subtle">
                    <input type="hidden" name="action" value="save_cashless_limit">
                    <label class="form-label">Batas belanja harian cashless</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" name="batas_harian" class="form-control" value="<?= number_format($dailyLimit, 0, ',', '.') ?>" required>
                        <button class="btn btn-outline-primary">Simpan</button>
                    </div>
                    <div class="form-text">Maksimal total belanja cashless per santri per hari (contoh: Rp 10.000). Saldo dari top-up Saku bisa lebih besar, tetapi pemakaian harian tetap dibatasi.</div>
                </form>
                <form method="post" class="mb-3 border rounded p-3">
                    <input type="hidden" name="action" value="save_scan_uang_setting">
                    <label class="form-label fw-semibold">Pengaturan Scan Uang (Nominal via Kamera)</label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Mode Scan Uang</label>
                            <select class="form-select" name="cashless_scan_uang_enabled">
                                <option value="1" <?= $scanUangEnabled ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= !$scanUangEnabled ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Suara Transaksi</label>
                            <select class="form-select" name="cashless_scan_uang_voice">
                                <option value="1" <?= $scanUangVoice ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= !$scanUangVoice ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Batas Maks Nominal per Scan</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="text" class="form-control" name="cashless_scan_uang_max_nominal" value="<?= number_format($scanUangMaxNominal, 0, ',', '.') ?>">
                            </div>
                            <div class="form-text">Nominal setiap QR mengikuti <strong>Peta QR nominal</strong> di bawah; nilai tidak boleh melebihi batas ini.</div>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary mt-2">Simpan Pengaturan Scan Uang</button>
                </form>
                <div class="mb-3 border rounded p-3 bg-light-subtle" id="peta-qr-nominal">
                    <h2 class="h6">Peta QR nominal tetap (scan uang)</h2>
                    <p class="small text-muted mb-2">
                        Buat QR dengan <strong>teks biasa</strong> sesuai kode di sini. Contoh kode <code>1hhey45ljsj</code> → nominal Rp 5.000.
                        Huruf besar/kecil diabaikan; karakter selain huruf/angka dihapus saat scan. Kode bisa dipakai berulang.
                    </p>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="create_qr_nominal_map">
                        <div class="col-md-4">
                            <label class="form-label small mb-0">Kode isi QR</label>
                            <input type="text" class="form-control form-control-sm" name="map_kode_qr" maxlength="120" placeholder="1hhey45ljsj" required autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Nominal (Rp)</label>
                            <input type="text" class="form-control form-control-sm" name="map_nominal" placeholder="5000" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Keterangan (opsional)</label>
                            <input type="text" class="form-control form-control-sm" name="map_keterangan" placeholder="Snack / ATK">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-success w-100">Tambah</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 align-middle">
                            <thead class="table-light"><tr><th>Kode tersimpan</th><th>Nominal</th><th>Keterangan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                            <tbody>
                            <?php if ($qrNominalMapRows): foreach ($qrNominalMapRows as $mr): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string) $mr['kode_qr']) ?></code></td>
                                    <td colspan="4">
                                        <div class="d-flex flex-wrap align-items-end gap-2">
                                            <form method="post" class="d-flex flex-wrap align-items-end gap-2 flex-grow-1">
                                                <input type="hidden" name="action" value="update_qr_nominal_map">
                                                <input type="hidden" name="map_id" value="<?= (int) $mr['id'] ?>">
                                                <div style="min-width:6rem">
                                                    <label class="form-label small mb-0 text-muted">Rp</label>
                                                    <input type="text" class="form-control form-control-sm" name="map_nominal" value="<?= number_format((int) ($mr['nominal'] ?? 0), 0, ',', '.') ?>" required>
                                                </div>
                                                <div style="min-width:10rem">
                                                    <label class="form-label small mb-0 text-muted">Ket.</label>
                                                    <input type="text" class="form-control form-control-sm" name="map_keterangan" value="<?= htmlspecialchars((string) ($mr['keterangan'] ?? '')) ?>" placeholder="Opsional">
                                                </div>
                                                <div style="min-width:6rem">
                                                    <label class="form-label small mb-0 text-muted">Status</label>
                                                    <select class="form-select form-select-sm" name="map_is_aktif">
                                                        <option value="1" <?= (int) ($mr['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Aktif</option>
                                                        <option value="0" <?= (int) ($mr['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                                                    </select>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                            </form>
                                            <form method="post" class="mb-1" onsubmit="return confirm('Hapus peta kode ini?')">
                                                <input type="hidden" name="action" value="delete_qr_nominal_map">
                                                <input type="hidden" name="map_id" value="<?= (int) $mr['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="text-center text-muted small">Belum ada peta. Tambahkan kode di atas lalu cetak QR dengan generator online (isi teks = kode).</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3" id="form-pin-cashless">
            <div class="card-header fw-semibold small">PIN cashless santri</div>
            <div class="card-body">
                <p class="small text-muted">PIN dipakai saat scan belanja. Saldo diisi dari pembayaran pos Saku (opsional).</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_cashless_pin">
                    <div class="mb-2">
                        <label class="form-label">Santri</label>
                        <select class="form-select" name="santri_id" id="select-santri-pin" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriRows as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $ubahPinSantriId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['nis']) ?> — <?= htmlspecialchars((string) $s['nama_santri']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Pilih santri lalu isi PIN baru untuk membuat atau mengganti PIN.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">PIN baru</label>
                        <input type="password" name="pin_baru" class="form-control" minlength="4" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konfirmasi PIN</label>
                        <input type="password" name="pin_konfirmasi" class="form-control" minlength="4" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary">Simpan PIN</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6">Santri &amp; status PIN</h2>
                <p class="small text-muted mb-2">Klik <strong>Ubah PIN</strong> untuk mengisi form di atas dengan santri terpilih.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead><tr><th>NIS</th><th>Nama</th><th>Status PIN</th><th class="text-end">Saldo</th><th class="text-end">Aksi</th></tr></thead>
                        <tbody>
                        <?php if ($santriPinStatusRows): foreach ($santriPinStatusRows as $sr): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $sr['nis']) ?></td>
                                <td><?= htmlspecialchars((string) $sr['nama_santri']) ?></td>
                                <td>
                                    <?php if ((int) ($sr['pin_terpasang'] ?? 0) === 1): ?>
                                        <span class="badge text-bg-success">Sudah diatur</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">Belum ada</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= $sr['balance'] !== null ? 'Rp ' . number_format((int) ((float) $sr['balance']), 0, ',', '.') : '—' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="/pwa_nailulmuna/keuangan/cashless_pin.php?ubah_pin=<?= (int) $sr['id'] ?>#form-pin-cashless">Ubah PIN</a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted">Tidak ada data santri aktif.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pinRows !== []): ?>
                <hr>
                <h2 class="h6">Ringkasan akun (ada saldo / transaksi)</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th>NIS</th><th>Nama</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                        <?php foreach ($pinRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                                <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                                <td class="text-end">Rp <?= number_format((int) ((float) $r['balance']), 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
