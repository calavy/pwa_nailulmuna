<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

keuangan_ensure_schema_deferred($pdo);

$koperasiPortal = defined('CASHLESS_KOPERASI_PORTAL') && CASHLESS_KOPERASI_PORTAL === true;

if ($koperasiPortal) {
    cashless_koperasi_require_session($pdo);
    $koperasiCtx = cashless_koperasi_resolve_context($pdo);
} else {
    require_roles(['admin', 'pengurus', 'petugas_absensi']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['action'] ?? '')) === 'select_koperasi') {
        $pick = (int) ($_POST['koperasi_id'] ?? 0);
        if ($pick >= 1 && $pick <= 3) {
            $_SESSION['cashless_scan_koperasi_id'] = $pick;
            set_flash('success', 'Koperasi aktif: ' . (cashless_koperasi_by_id($pdo, $pick)['nama'] ?? ('Koperasi ' . $pick)));
        }
        header('Location: ' . app_href('/keuangan/cashless_scan.php'));
        exit;
    }
    $koperasiCtx = cashless_koperasi_resolve_context($pdo);
    if ((int) ($koperasiCtx['id'] ?? 0) < 1) {
        $_SESSION['cashless_scan_koperasi_id'] = 1;
        $koperasiCtx = cashless_koperasi_resolve_context($pdo);
    }
}

$koperasiId = (int) ($koperasiCtx['id'] ?? 0);
$koperasiNama = (string) ($koperasiCtx['nama'] ?? 'Umum');
$createdByUserId = $koperasiPortal ? 0 : (int) ($_SESSION['user']['id'] ?? 0);

$resultMessage = null;
$resultType = 'success';
$dailyLimit = (int) app_setting($pdo, 'cashless_daily_limit', '10000');
$scanUangEnabled = app_setting($pdo, 'cashless_scan_uang_enabled', '1') === '1';
$scanUangVoice = app_setting($pdo, 'cashless_scan_uang_voice', '1') === '1';
$scanUangMaxNominal = (int) app_setting($pdo, 'cashless_scan_uang_max_nominal', '200000');
$scanUangMaxNominal = max(1000, $scanUangMaxNominal);
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
                        cashless_koperasi_insert_debit(
                            $pdo,
                            $santriId,
                            $nominal,
                            $keterangan,
                            $createdByUserId,
                            $koperasiId > 0 ? $koperasiId : null
                        );
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

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json() || trim((string) ($_SERVER['HTTP_X_CASHLESS_AJAX'] ?? '')) === '1') {
        $jsonType = $resultType === 'danger' ? 'error' : $resultType;
        $jsonExtra = [
            'action' => $action,
            'verified' => isset($_SESSION['cashless_verified']) && is_array($_SESSION['cashless_verified']),
            'auto_nominal' => ($action === 'verify_cashless_pin' && $resultType === 'success'),
        ];
        if ($action === 'verify_cashless_pin' && $resultType === 'success' && is_array($verifiedSantri)) {
            $jsonExtra['santri'] = [
                'id' => (int) ($verifiedSantri['id'] ?? 0),
                'nis' => (string) ($verifiedSantri['nis'] ?? ''),
                'nama' => (string) ($verifiedSantri['nama_santri'] ?? ''),
                'balance' => (int) ((float) ($verifiedSantri['balance'] ?? 0)),
            ];
        }
        offline_sync_json_response($jsonType, $resultMessage ?? 'OK', $jsonExtra);
    }
}

$autoStartNominalScan = false;
if (!empty($_SESSION['cashless_auto_nominal_scan'])) {
    $autoStartNominalScan = true;
    unset($_SESSION['cashless_auto_nominal_scan']);
}

$todayRows = cashless_koperasi_fetch_debit_hari_ini($pdo, $koperasiId > 0 ? $koperasiId : null, 20);
$koperasiListAdmin = $koperasiPortal ? [] : cashless_koperasi_list($pdo);

$pageTitle = $koperasiPortal ? ('Scan — ' . $koperasiNama) : 'Scan Cashless';
if ($koperasiPortal) {
    require_once __DIR__ . '/../includes/koperasi_portal_layout.php';
    koperasi_portal_layout_begin([
        'title' => $pageTitle,
        'koperasi_nama' => $koperasiNama,
        'active' => 'scan',
    ]);
} else {
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$koperasiPortal): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Scan Cashless<?= $koperasiId > 0 ? ' · ' . htmlspecialchars($koperasiNama) : '' ?></h1>
    <?php if ($koperasiListAdmin !== []): ?>
        <form method="post" class="d-flex align-items-center gap-2">
            <input type="hidden" name="action" value="select_koperasi">
            <select name="koperasi_id" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()" aria-label="Koperasi">
                <?php foreach ($koperasiListAdmin as $kop): ?>
                    <option value="<?= (int) $kop['id'] ?>" <?= (int) $kop['id'] === $koperasiId ? 'selected' : '' ?>><?= htmlspecialchars((string) $kop['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php?koperasi_id=' . $koperasiId . '&dari=' . date('Y-m-d') . '&sampai=' . date('Y-m-d'))) ?>" class="btn btn-outline-secondary btn-sm">Laporan</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($resultMessage !== null && ($resultType !== 'success' || $lastSuccessNominal > 0)): ?>
    <div class="alert alert-<?= $resultType === 'success' ? 'success' : ($resultType === 'danger' ? 'danger' : 'warning') ?> py-2 mb-2"><?= htmlspecialchars($resultMessage) ?></div>
<?php endif; ?>

<style>
.cashless-scan-wrap {
    max-width: 480px;
    margin: 0 auto;
}
.cashless-scan-wrap #reader,
.cashless-scan-wrap #money_reader {
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    background: #0f172a;
}
.cashless-scan-wrap #reader video,
.cashless-scan-wrap #money_reader video {
    border-radius: 12px;
}
.cashless-pin-input {
    font-size: 1.5rem;
    letter-spacing: 0.35em;
    text-align: center;
    padding: 0.65rem 0.75rem;
}
.cashless-phase-uang { display: none; }
.cashless-scan-wrap.is-money-phase .cashless-phase-santri { display: none; }
.cashless-scan-wrap.is-money-phase .cashless-phase-uang { display: block; }
.cashless-santri-chip {
    font-size: 0.95rem;
    font-weight: 600;
    text-align: center;
    margin-bottom: 0.75rem;
}
.cashless-flash-mini {
    font-size: 0.875rem;
    text-align: center;
    min-height: 1.25rem;
    margin-bottom: 0.5rem;
}
</style>

<div class="cashless-scan-wrap<?= ($autoStartNominalScan && $verifiedSantri) ? ' is-money-phase' : '' ?>" id="cashless_scan_wrap">
    <div class="cashless-phase-santri" id="phase_santri">
        <div id="reader"></div>
        <input type="password" id="pin_input" class="form-control cashless-pin-input mt-3" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" placeholder="PIN" aria-label="PIN">
        <form method="post" id="pin_form" class="d-none">
            <input type="hidden" name="action" value="verify_cashless_pin">
            <input type="hidden" name="scan_source" value="camera">
            <input type="hidden" name="kode_qr" id="kode_qr" value="">
            <input type="hidden" name="pin" id="pin_hidden" value="">
        </form>
    </div>

    <div class="cashless-phase-uang" id="phase_uang">
        <div class="cashless-santri-chip" id="santri_chip"><?php if ($verifiedSantri): ?><?= htmlspecialchars((string) ($verifiedSantri['nama_santri'] ?? '')) ?><?php endif; ?></div>
        <div id="money_reader"></div>
        <form method="post" id="scan_uang_form" class="d-none">
            <input type="hidden" name="action" value="process_scan_uang">
            <input type="hidden" name="scan_source" value="camera">
            <input type="hidden" name="nominal_scan" id="nominal_scan" value="">
            <input type="hidden" name="keterangan" value="Belanja">
        </form>
    </div>

    <div class="cashless-flash-mini text-danger" id="cashless_flash" role="status" aria-live="polite"></div>
    <button type="button" class="btn btn-link btn-sm w-100 text-muted" id="retry_camera_btn">Ulangi kamera</button>
</div>

<?php if (!$koperasiPortal): ?>
<details class="mt-3 small">
    <summary class="text-muted">Riwayat debit hari ini</summary>
    <div class="table-responsive mt-2">
        <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Waktu</th><th>NIS</th><th>Nama</th><th class="text-end">Nominal</th></tr></thead>
            <tbody>
            <?php if ($todayRows): foreach ($todayRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                    <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                    <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                    <td class="text-end">Rp <?= number_format((int) ((float) $r['nominal']), 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted">—</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script>
    (function () {
        const CFG = {
            autoNominalAfterPin: <?= ($autoStartNominalScan && $verifiedSantri) ? 'true' : 'false' ?>,
            scanUangEnabled: <?= $scanUangEnabled ? 'true' : 'false' ?>,
            voiceEnabled: <?= $scanUangVoice ? 'true' : 'false' ?>,
            pinMinLen: 4
        };
        const wrap = document.getElementById('cashless_scan_wrap');
        const input = document.getElementById('kode_qr');
        const pinInput = document.getElementById('pin_input');
        const pinHidden = document.getElementById('pin_hidden');
        const pinForm = document.getElementById('pin_form');
        const flashEl = document.getElementById('cashless_flash');
        const retryBtn = document.getElementById('retry_camera_btn');
        const santriChip = document.getElementById('santri_chip');
        const readerId = 'reader';
        if (!input || typeof Html5Qrcode === 'undefined') return;

        let html5QrCode = null;
        let activeCameraId = null;
        let moneyQr = null;
        let moneyPhase = wrap && wrap.classList.contains('is-money-phase');
        let pinVerifyBusy = false;
        let pinDebounce = null;

        const nominalScanInput = document.getElementById('nominal_scan');
        const moneyForm = document.getElementById('scan_uang_form');

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

        function setFlash(msg, isError) {
            if (!flashEl) return;
            flashEl.textContent = msg || '';
            flashEl.className = 'cashless-flash-mini' + (isError ? ' text-danger' : ' text-muted');
        }

        async function stopCurrentScanner() {
            if (!html5QrCode) return;
            try { await html5QrCode.stop(); } catch (e) {}
            try { await html5QrCode.clear(); } catch (e) {}
            html5QrCode = null;
        }

        async function stopMoneyScanner() {
            if (!moneyQr) return;
            try { await moneyQr.stop(); } catch (e) {}
            try { await moneyQr.clear(); } catch (e) {}
            moneyQr = null;
        }

        async function startSantriScanner(preferredCameraId) {
            if (moneyPhase) return;
            await stopCurrentScanner();
            html5QrCode = new Html5Qrcode(readerId);
            const onSuccess = async function (decodedText) {
                input.value = decodedText;
                await stopCurrentScanner();
                setFlash('');
                if (pinInput) {
                    pinInput.value = '';
                    pinInput.focus();
                }
            };
            const scanConfig = { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1.333334 };
            try {
                let useId = preferredCameraId;
                if (useId === 'environment') useId = null;
                if (useId) {
                    const cameras = await Html5Qrcode.getCameras();
                    if (!cameras.find(function (c) { return c.id === useId; })) useId = null;
                }
                activeCameraId = await startScannerDevice(html5QrCode, scanConfig, onSuccess, function () {}, useId || null);
            } catch (e) {
                setFlash('Kamera gagal. Ketuk ulangi kamera.', true);
            }
        }

        function speak(text) {
            if (!CFG.voiceEnabled || !text) return;
            try {
                window.speechSynthesis.cancel();
                var u = new SpeechSynthesisUtterance(text);
                u.lang = 'id-ID';
                u.rate = 1;
                window.speechSynthesis.speak(u);
            } catch (e) {}
        }

        function switchToMoneyPhase(santriName) {
            moneyPhase = true;
            if (wrap) wrap.classList.add('is-money-phase');
            if (santriChip && santriName) santriChip.textContent = santriName;
            setFlash('');
        }

        async function beginMoneyQrScan() {
            if (!CFG.scanUangEnabled) {
                setFlash('Scan uang nonaktif.', true);
                return;
            }
            await stopCurrentScanner();
            await stopMoneyScanner();
            switchToMoneyPhase(santriChip ? santriChip.textContent : '');
            moneyQr = new Html5Qrcode('money_reader');
            try {
                var moneyConfig = { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1.333334 };
                await startScannerDevice(
                    moneyQr,
                    moneyConfig,
                    async function (decodedText) {
                        var raw = (decodedText || '').trim();
                        if (!raw || !nominalScanInput || !moneyForm) return;
                        nominalScanInput.value = raw;
                        await stopMoneyScanner();
                        if (window.PondokOfflineSync && PondokOfflineSync.handleFormSubmit(moneyForm, { label: 'Cashless: ' + raw })) {
                            return;
                        }
                        moneyForm.submit();
                    },
                    function () {},
                    null
                );
            } catch (e) {
                setFlash('Kamera nominal gagal.', true);
            }
        }

        async function verifyPinAjax() {
            if (pinVerifyBusy || moneyPhase) return;
            var qr = (input.value || '').trim();
            var pin = (pinInput && pinInput.value) ? pinInput.value.trim() : '';
            if (!qr || pin.length < CFG.pinMinLen) return;

            pinVerifyBusy = true;
            setFlash('');
            if (pinHidden) pinHidden.value = pin;

            var body = new FormData(pinForm);
            body.set('kode_qr', qr);
            body.set('pin', pin);

            try {
                var res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Cashless-Ajax': '1' },
                    body: body,
                    credentials: 'same-origin'
                });
                var data = await res.json();
                if (data.ok && data.auto_nominal) {
                    if (data.santri && data.santri.nama && santriChip) {
                        santriChip.textContent = data.santri.nama;
                    }
                    speak('PIN benar');
                    await beginMoneyQrScan();
                } else {
                    setFlash(data.message || 'PIN salah.', true);
                    if (pinInput) {
                        pinInput.value = '';
                        pinInput.focus();
                    }
                    await startSantriScanner(activeCameraId);
                }
            } catch (e) {
                setFlash('Gagal verifikasi. Coba lagi.', true);
                await startSantriScanner(activeCameraId);
            } finally {
                pinVerifyBusy = false;
            }
        }

        function schedulePinVerify() {
            if (pinDebounce) clearTimeout(pinDebounce);
            pinDebounce = setTimeout(function () {
                var pin = (pinInput && pinInput.value) ? pinInput.value.trim() : '';
                if ((input.value || '').trim() && pin.length >= CFG.pinMinLen) {
                    verifyPinAjax();
                }
            }, 280);
        }

        if (pinInput) {
            pinInput.addEventListener('input', schedulePinVerify);
            pinInput.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    if (pinDebounce) clearTimeout(pinDebounce);
                    verifyPinAjax();
                }
            });
        }

        if (retryBtn) {
            retryBtn.addEventListener('click', async function () {
                setFlash('');
                if (moneyPhase) {
                    await beginMoneyQrScan();
                } else {
                    await startSantriScanner(activeCameraId);
                }
            });
        }

        async function pageInit() {
            if (CFG.autoNominalAfterPin) {
                speak('PIN benar');
                await beginMoneyQrScan();
            } else {
                await startSantriScanner(null);
            }
        }
        pageInit();
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

<?php if ($koperasiPortal): ?>
<?php koperasi_portal_layout_end(); ?>
<?php else: ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
