<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$pdo->exec("
CREATE TABLE IF NOT EXISTS cashless_accounts (
    santri_id INT PRIMARY KEY,
    pin_hash VARCHAR(255) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS cashless_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    jenis ENUM('TOPUP','DEBIT') NOT NULL,
    nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan VARCHAR(255) NULL,
    ref_pembayaran_id INT NULL,
    created_by INT NULL
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

$resultMessage = null;
$resultType = 'success';
$dailyLimit = (int) app_setting($pdo, 'cashless_daily_limit', '10000');
$scanUangEnabled = app_setting($pdo, 'cashless_scan_uang_enabled', '1') === '1';
$scanUangVoice = app_setting($pdo, 'cashless_scan_uang_voice', '1') === '1';
$scanUangMaxNominal = (int) app_setting($pdo, 'cashless_scan_uang_max_nominal', '200000');
$scanUangMaxNominal = max(1000, $scanUangMaxNominal);
$qrNominalMapRows = $pdo->query('
    SELECT kode_qr, nominal, keterangan, is_aktif
    FROM cashless_nominal_qr_map
    ORDER BY is_aktif DESC, nominal ASC, kode_qr ASC
')->fetchAll();
$lastSantriSummary = null;
$verifiedSantri = null;
$lastSuccessNominal = 0;
$cashlessVoiceText = null;

if (isset($_SESSION['cashless_verified']) && is_array($_SESSION['cashless_verified'])) {
    $verifiedId = (int) ($_SESSION['cashless_verified']['santri_id'] ?? 0);
    if ($verifiedId > 0) {
        $vsStmt = $pdo->prepare('
            SELECT s.id, s.nis, COALESCE(NULLIF(s.nama_santri, ""), s.nama) AS nama_santri, ca.balance
            FROM santri s
            LEFT JOIN cashless_accounts ca ON ca.santri_id = s.id
            WHERE s.id = :id
            LIMIT 1
        ');
        $vsStmt->execute(['id' => $verifiedId]);
        $verifiedSantri = $vsStmt->fetch();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'verify_cashless_pin'));
    if (($action === 'verify_cashless_pin' || $action === 'process_scan_uang') && ($_POST['scan_source'] ?? '') !== 'camera') {
        $resultType = 'warning';
        $resultMessage = 'Gunakan scan kamera untuk transaksi cashless.';
    } elseif ($action === 'verify_cashless_pin') {
        $kodeQr = trim((string) ($_POST['kode_qr'] ?? ''));
        $pin = trim((string) ($_POST['pin'] ?? ''));

        $findSantri = $pdo->prepare('SELECT id, nis, nama_santri, nama FROM santri WHERE qr = :code OR nis = :code LIMIT 1');
        $findSantri->execute(['code' => $kodeQr]);
        $santri = $findSantri->fetch();
        if (!$santri) {
            $resultType = 'warning';
            $resultMessage = 'QR/NIS tidak terdaftar.';
        } else {
            $santriId = (int) $santri['id'];
            $accStmt = $pdo->prepare('SELECT pin_hash, balance FROM cashless_accounts WHERE santri_id = :santri_id LIMIT 1');
            $accStmt->execute(['santri_id' => $santriId]);
            $account = $accStmt->fetch();
            if (!$account || !password_verify($pin, (string) ($account['pin_hash'] ?? ''))) {
                $resultType = 'danger';
                $resultMessage = 'PIN salah atau belum diatur.';
            } else {
                $nama = (string) (($santri['nama_santri'] ?? '') !== '' ? $santri['nama_santri'] : ($santri['nama'] ?? 'Santri'));
                $saldoTotal = (int) ((float) ($account['balance'] ?? 0));
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM cashless_transactions WHERE santri_id = :santri_id AND jenis='DEBIT' AND DATE(tanggal)=CURDATE()");
                $sumStmt->execute(['santri_id' => $santriId]);
                $todayDebit = (int) ($sumStmt->fetchColumn() ?: 0);
                $sisaLimit = max(0, $dailyLimit - $todayDebit);
                $lastSantriSummary = [
                    'nis' => (string) ($santri['nis'] ?? ''),
                    'nama' => $nama,
                    'saldo_total' => $saldoTotal,
                    'limit_harian' => $dailyLimit,
                    'terpakai_hari_ini' => $todayDebit,
                    'sisa_limit' => $sisaLimit,
                ];
                $_SESSION['cashless_verified'] = [
                    'santri_id' => $santriId,
                    'verified_at' => time(),
                ];
                $_SESSION['cashless_auto_nominal_scan'] = true;
                $verifiedSantri = [
                    'id' => $santriId,
                    'nis' => (string) ($santri['nis'] ?? ''),
                    'nama_santri' => $nama,
                    'balance' => $saldoTotal,
                ];
                $resultType = 'success';
                $resultMessage = 'PIN benar. Scan nominal dibuka otomatis.';
            }
        }
    } elseif ($action === 'process_scan_uang') {
        $verified = $_SESSION['cashless_verified'] ?? null;
        $santriId = (int) (($verified['santri_id'] ?? 0));
        if (!$verified || $santriId <= 0) {
            $resultType = 'warning';
            $resultMessage = 'Sesi verifikasi habis. Silakan scan QR dan PIN kembali.';
        } else {
            $tokenScanned = cashless_normalize_money_qr_payload((string) ($_POST['nominal_scan'] ?? ''));
            $nominal = 0;
            $keterangan = trim((string) ($_POST['keterangan'] ?? 'Belanja'));
            if (!$scanUangEnabled) {
                $resultType = 'warning';
                $resultMessage = 'Mode scan uang sedang nonaktif di pengaturan.';
            } elseif ($tokenScanned === '') {
                $resultType = 'warning';
                $resultMessage = 'QR nominal belum terbaca.';
            } else {
                $mapStmt = $pdo->prepare('
                    SELECT nominal FROM cashless_nominal_qr_map
                    WHERE kode_qr = :kode AND is_aktif = 1
                    LIMIT 1
                ');
                $mapStmt->execute(['kode' => $tokenScanned]);
                $mapRow = $mapStmt->fetch();
                if ($mapRow) {
                    $nominal = (int) ($mapRow['nominal'] ?? 0);
                } else {
                    $resultType = 'warning';
                    $resultMessage = 'QR tidak dikenali. Daftarkan kode di Setting PIN Cashless → Peta QR nominal.';
                }
            }
            if ($nominal > 0) {
                if ($nominal > $scanUangMaxNominal) {
                    $resultType = 'warning';
                    $resultMessage = 'Nominal melebihi batas scan uang: Rp ' . number_format($scanUangMaxNominal, 0, ',', '.') . '.';
                    if ($scanUangVoice) {
                        $cashlessVoiceText = 'Nominal melebihi batas scan. Transaksi ditolak.';
                    }
                }
            }
            if ($nominal > 0 && $resultType !== 'warning') {
                $santriStmt = $pdo->prepare('SELECT id, nis, COALESCE(NULLIF(nama_santri, ""), nama) AS nama_santri FROM santri WHERE id = :id LIMIT 1');
                $santriStmt->execute(['id' => $santriId]);
                $santri = $santriStmt->fetch();
                $accStmt = $pdo->prepare('SELECT pin_hash, balance FROM cashless_accounts WHERE santri_id = :santri_id LIMIT 1');
                $accStmt->execute(['santri_id' => $santriId]);
                $account = $accStmt->fetch();
                if (!$santri || !$account) {
                    $resultType = 'danger';
                    $resultMessage = 'Data santri/cashless tidak ditemukan.';
                    unset($_SESSION['cashless_verified']);
                } else {
                    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM cashless_transactions WHERE santri_id = :santri_id AND jenis='DEBIT' AND DATE(tanggal)=CURDATE()");
                    $sumStmt->execute(['santri_id' => $santriId]);
                    $todayDebit = (int) ($sumStmt->fetchColumn() ?: 0);
                    $sisaLimit = max(0, $dailyLimit - $todayDebit);
                    if (($todayDebit + $nominal) > $dailyLimit) {
                        $resultType = 'warning';
                        $resultMessage = 'Transaksi ditolak: batas harian terlampaui. Sisa limit hari ini Rp ' . number_format($sisaLimit, 0, ',', '.') . '.';
                        if ($scanUangVoice) {
                            $cashlessVoiceText = 'Batas belanja harian terlampaui. Transaksi ditolak.';
                        }
                    } elseif ((float) ($account['balance'] ?? 0) < $nominal) {
                        $resultType = 'warning';
                        $resultMessage = 'Transaksi ditolak: saldo tidak cukup.';
                        if ($scanUangVoice) {
                            $saldoSaatIni = (float) ($account['balance'] ?? 0);
                            $cashlessVoiceText = $saldoSaatIni <= 0
                                ? 'Saldo kosong. Transaksi ditolak.'
                                : 'Saldo tidak mencukupi. Transaksi ditolak.';
                        }
                    } else {
                        $pdo->prepare('UPDATE cashless_accounts SET balance = balance - :nominal WHERE santri_id = :santri_id')->execute([
                            'nominal' => $nominal,
                            'santri_id' => $santriId,
                        ]);
                        $pdo->prepare("INSERT INTO cashless_transactions (santri_id, jenis, nominal, keterangan, created_by) VALUES (:santri_id,'DEBIT',:nominal,:keterangan,:created_by)")
                            ->execute([
                                'santri_id' => $santriId,
                                'nominal' => $nominal,
                                'keterangan' => $keterangan,
                                'created_by' => (int) ($_SESSION['user']['id'] ?? 0),
                            ]);
                        $lastSuccessNominal = $nominal;
                        $resultType = 'success';
                        $resultMessage = 'Transaksi berhasil untuk ' . (string) $santri['nama_santri'] . '. Nominal Rp ' . number_format($nominal, 0, ',', '.') . '.';
                        if ($scanUangVoice) {
                            $cashlessVoiceText = 'Transaksi sebesar ' . number_format($nominal, 0, ',', '.') . ' rupiah berhasil';
                        }
                        unset($_SESSION['cashless_verified']);
                    }
                }
            }
        }
    }
}

$autoStartNominalScan = false;
if (!empty($_SESSION['cashless_auto_nominal_scan'])) {
    $autoStartNominalScan = true;
    unset($_SESSION['cashless_auto_nominal_scan']);
}

$todayRows = $pdo->query("
    SELECT ct.tanggal, ct.nominal, ct.keterangan, s.nis, COALESCE(NULLIF(s.nama_santri,''), s.nama) AS nama_santri
    FROM cashless_transactions ct
    INNER JOIN santri s ON s.id = ct.santri_id
    WHERE ct.jenis = 'DEBIT' AND DATE(ct.tanggal) = CURDATE()
    ORDER BY ct.id DESC
    LIMIT 20
")->fetchAll();

$pageTitle = 'Scan Cashless';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Scan Cashless</h1>
</div>

<?php if ($resultMessage !== null): ?>
    <div class="alert alert-<?= $resultType === 'success' ? 'success' : ($resultType === 'danger' ? 'danger' : 'warning') ?>"><?= htmlspecialchars($resultMessage) ?></div>
<?php endif; ?>
<?php if (is_array($lastSantriSummary)): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($lastSantriSummary['nama']) ?></div>
                    <div class="text-muted small">NIS: <?= htmlspecialchars($lastSantriSummary['nis'] !== '' ? $lastSantriSummary['nis'] : '-') ?></div>
                </div>
                <div class="text-end">
                    <div class="small text-muted">Saldo Total</div>
                    <div class="fw-semibold">Rp <?= number_format((int) $lastSantriSummary['saldo_total'], 0, ',', '.') ?></div>
                </div>
            </div>
            <hr class="my-2">
            <div class="row g-2">
                <div class="col-md-4">
                    <div class="small text-muted">Limit Harian</div>
                    <div class="fw-semibold">Rp <?= number_format((int) $lastSantriSummary['limit_harian'], 0, ',', '.') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Terpakai Hari Ini</div>
                    <div class="fw-semibold">Rp <?= number_format((int) $lastSantriSummary['terpakai_hari_ini'], 0, ',', '.') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Sisa Limit Hari Ini</div>
                    <div class="fw-semibold">Rp <?= number_format((int) $lastSantriSummary['sisa_limit'], 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <div id="camera_status" class="alert alert-secondary py-2 small mb-2">Menyiapkan kamera...</div>
                <div id="reader" style="width:100%; max-width:420px;"></div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="retry_camera_btn">Ulangi Kamera</button>
                </div>
                <form method="post" class="mt-3">
                    <input type="hidden" name="action" value="verify_cashless_pin">
                    <input type="hidden" name="scan_source" id="scan_source" value="camera">
                    <input type="hidden" name="kode_qr" id="kode_qr" value="" required>
                    <div class="mb-2">
                        <label class="form-label">PIN Santri</label>
                        <input type="password" name="pin" class="form-control" required>
                    </div>
                    <button class="btn btn-primary w-100">Verifikasi PIN</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-2">Alur Pembayaran Cashless</h2>
                <ol class="small text-muted mb-3">
                    <li>Scan QR santri</li>
                    <li>Masukkan PIN — setelah benar, kamera beralih otomatis ke scan nominal</li>
                    <li>Scan QR nominal; transaksi diproses dan suara konfirmasi</li>
                </ol>
                <?php if ($verifiedSantri): ?>
                    <div class="border rounded p-3 mb-3 bg-light-subtle" id="cashless-panel-bayar">
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($verifiedSantri['nama_santri'] ?? '-')) ?></div>
                        <div class="small text-muted mb-2">NIS: <?= htmlspecialchars((string) ($verifiedSantri['nis'] ?? '-')) ?></div>
                        <div class="small">Saldo saat ini: <strong>Rp <?= number_format((int) ((float) ($verifiedSantri['balance'] ?? 0)), 0, ',', '.') ?></strong></div>
                        <form method="post" id="scan_uang_form" class="mt-2">
                            <input type="hidden" name="action" value="process_scan_uang">
                            <input type="hidden" name="scan_source" value="camera">
                            <input type="hidden" name="nominal_scan" id="nominal_scan" value="">
                            <div class="mb-2">
                                <label class="form-label">Keterangan</label>
                                <input type="text" name="keterangan" class="form-control" value="Belanja">
                            </div>
                            <button type="button" class="btn btn-danger" id="start_money_scan_btn">Bayar (Scan Uang)</button>
                        </form>
                        <div id="money_scan_status" class="small text-muted mt-2"></div>
                        <div class="small text-muted mt-1">
                            <?php
                            $mapAktif = array_values(array_filter($qrNominalMapRows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1));
                            if ($mapAktif !== []): ?>
                                <span class="text-dark">Kode QR yang dikenali:</span>
                                <?php foreach ($mapAktif as $mi): ?>
                                    <span class="badge text-bg-light border me-1 mb-1"><code><?= htmlspecialchars((string) $mi['kode_qr']) ?></code> = Rp <?= number_format((int) ($mi['nominal'] ?? 0), 0, ',', '.') ?></span>
                                <?php endforeach; ?>
                                <br><span class="text-muted">Maks. per scan: Rp <?= number_format($scanUangMaxNominal, 0, ',', '.') ?>.</span>
                            <?php else: ?>
                                Belum ada peta QR. Atur di <a href="/keuangan/cashless_pin.php">Setting PIN Cashless</a> → Peta QR nominal.
                            <?php endif; ?>
                        </div>
                        <div id="money_reader" style="width:100%; max-width:420px; display:none;" class="mt-2"></div>
                    </div>
                <?php endif; ?>
                <h2 class="h6">Transaksi Debit Hari Ini</h2>
                <div class="small text-muted mb-2">Batas harian aktif: <strong>Rp <?= number_format($dailyLimit, 0, ',', '.') ?></strong> (atur di menu Setting PIN Cashless)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Waktu</th><th>NIS</th><th>Nama</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead>
                        <tbody>
                        <?php if ($todayRows): foreach ($todayRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                                <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                                <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                                <td><?= htmlspecialchars((string) ($r['keterangan'] ?: '-')) ?></td>
                                <td class="text-end">Rp <?= number_format((int) ((float) $r['nominal']), 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted">Belum ada transaksi debit hari ini.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    (function () {
        const CFG = {
            autoNominalAfterPin: <?= ($autoStartNominalScan && $verifiedSantri) ? 'true' : 'false' ?>,
            scanUangEnabled: <?= $scanUangEnabled ? 'true' : 'false' ?>,
            voiceEnabled: <?= $scanUangVoice ? 'true' : 'false' ?>
        };
        const input = document.getElementById('kode_qr');
        const statusEl = document.getElementById('camera_status');
        const retryBtn = document.getElementById('retry_camera_btn');
        const readerId = 'reader';
        if (!input || typeof Html5Qrcode === 'undefined') return;
        let html5QrCode = null;
        let activeCameraId = null;

        /** Prioritas kamera belakang (environment); fallback label / hindari depan. */
        function pickPreferredCamera(cameras) {
            if (!cameras || cameras.length === 0) return null;
            let c = cameras.find(function (cam) {
                return /back|rear|environment|world|wide/i.test(cam.label || '');
            });
            if (c) return c;
            c = cameras.find(function (cam) {
                return !/front|user|face|selfie|depan/i.test(cam.label || '');
            });
            return c || cameras[0];
        }

        async function startScannerDevice(html5QrCodeInstance, config, onSuccess, onError, preferredCameraId) {
            if (preferredCameraId) {
                await html5QrCodeInstance.start(preferredCameraId, config, onSuccess, onError);
                return preferredCameraId;
            }
            try {
                await html5QrCodeInstance.start({ facingMode: 'environment' }, config, onSuccess, onError);
                return 'environment';
            } catch (envErr) {
                const cameras = await Html5Qrcode.getCameras();
                const cam = pickPreferredCamera(cameras);
                if (!cam) throw envErr;
                await html5QrCodeInstance.start(cam.id, config, onSuccess, onError);
                return cam.id;
            }
        }

        function setStatus(type, message) {
            if (!statusEl) return;
            statusEl.className = 'alert py-2 small mb-2 alert-' + type;
            statusEl.textContent = message;
        }

        async function stopCurrentScanner() {
            if (!html5QrCode) return;
            try { await html5QrCode.stop(); } catch (e) {}
            try { await html5QrCode.clear(); } catch (e) {}
            html5QrCode = null;
        }

        async function startScanner(preferredCameraId) {
            await stopCurrentScanner();
            html5QrCode = new Html5Qrcode(readerId);
            const onSuccess = function (decodedText) {
                input.value = decodedText;
                setStatus('success', 'Santri terbaca. Silakan masukkan PIN.');
            };
            const onError = function () {};
            const scanConfig = { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1.333334 };
            try {
                let useId = preferredCameraId;
                if (useId === 'environment') {
                    useId = null;
                }
                if (useId) {
                    const cameras = await Html5Qrcode.getCameras();
                    const byId = cameras.find((c) => c.id === useId);
                    if (!byId) useId = null;
                }
                activeCameraId = await startScannerDevice(html5QrCode, scanConfig, onSuccess, onError, useId || null);
                setStatus('info', 'Kamera belakang aktif (jika tersedia). Arahkan QR ke kotak scanner.');
            } catch (e) {
                setStatus('danger', 'Gagal membuka kamera. Klik "Ulangi Kamera" atau cek izin kamera browser.');
            }
        }

        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                setStatus('secondary', 'Mencoba mengaktifkan ulang kamera...');
                startScanner(activeCameraId);
            });
        }

        if (!window.isSecureContext) {
            setStatus('warning', 'Akses kamera butuh koneksi aman. Gunakan localhost/https agar kamera berfungsi normal.');
        }

        const startMoneyBtn = document.getElementById('start_money_scan_btn');
        const moneyReader = document.getElementById('money_reader');
        const moneyStatus = document.getElementById('money_scan_status');
        const nominalScanInput = document.getElementById('nominal_scan');
        const moneyForm = document.getElementById('scan_uang_form');
        let moneyQr = null;

        async function stopMoneyScanner() {
            if (!moneyQr) return;
            try { await moneyQr.stop(); } catch (e) {}
            try { await moneyQr.clear(); } catch (e) {}
            moneyQr = null;
        }

        function speakPinOkThenNominal() {
            if (!CFG.voiceEnabled) return;
            try {
                window.speechSynthesis.cancel();
                var u = new SpeechSynthesisUtterance('PIN benar. Silakan scan kode nominal.');
                u.lang = 'id-ID';
                u.rate = 1;
                window.speechSynthesis.speak(u);
            } catch (e) {}
        }

        async function beginMoneyQrScan() {
            if (!moneyReader || !nominalScanInput || !moneyForm) return;
            if (!CFG.scanUangEnabled) {
                if (moneyStatus) moneyStatus.textContent = 'Mode scan uang sedang nonaktif di pengaturan.';
                return;
            }
            await stopCurrentScanner();
            moneyReader.style.display = '';
            if (moneyStatus) moneyStatus.textContent = 'Arahkan QR nominal ke kamera belakang...';
            await stopMoneyScanner();
            moneyQr = new Html5Qrcode('money_reader');
            try {
                var moneyConfig = { fps: 10, qrbox: { width: 250, height: 250 } };
                await startScannerDevice(
                    moneyQr,
                    moneyConfig,
                    async function (decodedText) {
                        var raw = (decodedText || '').trim();
                        if (!raw) {
                            if (moneyStatus) moneyStatus.textContent = 'Kode nominal belum valid.';
                            return;
                        }
                        nominalScanInput.value = raw;
                        if (moneyStatus) moneyStatus.textContent = 'Memproses transaksi...';
                        await stopMoneyScanner();
                        moneyForm.submit();
                    },
                    function () {},
                    null
                );
            } catch (e) {
                if (moneyStatus) moneyStatus.textContent = 'Gagal membuka kamera scan nominal. Cek izin kamera.';
            }
        }

        async function pageInit() {
            if (CFG.autoNominalAfterPin) {
                setStatus('success', 'PIN benar. Kamera dialihkan ke scan nominal.');
                await stopCurrentScanner();
                var bayarPanel = document.getElementById('cashless-panel-bayar');
                if (bayarPanel) {
                    bayarPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                speakPinOkThenNominal();
                await new Promise(function (r) { setTimeout(r, 450); });
                if (CFG.scanUangEnabled) {
                    await beginMoneyQrScan();
                } else if (moneyStatus) {
                    moneyStatus.textContent = 'Mode scan uang nonaktif. Aktifkan di Setting PIN Cashless.';
                }
            } else {
                await startScanner(null);
            }
        }
        pageInit();

        if (startMoneyBtn) {
            startMoneyBtn.addEventListener('click', function () {
                beginMoneyQrScan();
            });
        }
    })();
</script>

<?php if ($scanUangVoice && $cashlessVoiceText !== null && $cashlessVoiceText !== ''): ?>
<script>
(function () {
    var msg = <?= json_encode($cashlessVoiceText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function speakCashlessAlert(text) {
        if (!window.speechSynthesis || !text) return;
        function utterIt() {
            try {
                window.speechSynthesis.cancel();
                var u = new SpeechSynthesisUtterance(text);
                u.lang = 'id-ID';
                u.rate = 0.92;
                window.speechSynthesis.speak(u);
            } catch (e) {}
        }
        setTimeout(utterIt, 500);
    }
    function boot() {
        speakCashlessAlert(msg);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
