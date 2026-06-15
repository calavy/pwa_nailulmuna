<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';

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
                if ($saldoTotal <= 0) {
                    $resultType = 'warning';
                    $resultMessage = 'Transaksi ditolak: saldo cashless habis.';
                    if ($scanUangVoice) {
                        $cashlessVoiceText = 'Saldo kosong. Transaksi ditolak.';
                    }
                } else {
                    $jatah = cashless_santri_jatah_harian($pdo, $santriId, (float) $saldoTotal);
                    $lastSantriSummary = [
                        'nis' => (string) ($santri['nis'] ?? ''),
                        'nama' => $nama,
                        'saldo_total' => $saldoTotal,
                        'limit_harian' => (int) $jatah['limit'],
                        'terpakai_hari_ini' => (int) $jatah['terpakai'],
                        'sisa_limit' => (int) $jatah['sisa'],
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
                    $saldoErr = cashless_santri_saldo_cukup_debit($pdo, $santriId, $nominal);
                    if ($saldoErr !== null) {
                        $resultType = 'warning';
                        $resultMessage = $saldoErr;
                        if ($scanUangVoice) {
                            if (str_contains($saldoErr, 'habis') || str_contains($saldoErr, 'kosong')) {
                                $cashlessVoiceText = 'Saldo kosong. Transaksi ditolak.';
                            } elseif (str_contains($saldoErr, 'tidak cukup')) {
                                $cashlessVoiceText = 'Saldo tidak mencukupi. Transaksi ditolak.';
                            } elseif (str_contains($saldoErr, 'batas')) {
                                $cashlessVoiceText = 'Batas belanja harian terlampaui. Transaksi ditolak.';
                            }
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
                        $saldoSetelah = (float) ($account['balance'] ?? 0) - $nominal;
                        cashless_wa_maybe_notify_saldo_rendah($pdo, $santriId, $saldoSetelah);
                        cashless_wa_notify_transaksi_sukses($pdo, $santriId, $nominal, $koperasiId, $saldoSetelah);
                        $jatahSetelah = cashless_santri_jatah_harian($pdo, $santriId, $saldoSetelah);
                        $lastSantriSummary = [
                            'saldo_keseluruhan' => (int) $jatahSetelah['balance'],
                            'sisa_jatah_hari' => (int) $jatahSetelah['sisa'],
                            'limit_harian' => (int) $jatahSetelah['limit'],
                            'terpakai_hari' => (int) $jatahSetelah['terpakai'],
                        ];
                        $lastSuccessNominal = $nominal;
                        $resultType = 'success';
                        $resultMessage = 'Transaksi berhasil untuk ' . (string) $santri['nama_santri'] . '. Nominal Rp '
                            . number_format($nominal, 0, ',', '.')
                            . '. Saldo Rp ' . number_format((int) $jatahSetelah['balance'], 0, ',', '.')
                            . ', sisa jatah hari ini Rp ' . number_format((int) $jatahSetelah['sisa'], 0, ',', '.') . '.';
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
            'auto_nominal' => ($action === 'verify_cashless_pin' && $resultType === 'success' && $scanUangEnabled),
        ];
        if ($action === 'verify_cashless_pin' && $resultType === 'success' && is_array($verifiedSantri)) {
            $jatahPin = cashless_santri_jatah_harian($pdo, (int) ($verifiedSantri['id'] ?? 0), (float) ($verifiedSantri['balance'] ?? 0));
            $jsonExtra['santri'] = [
                'id' => (int) ($verifiedSantri['id'] ?? 0),
                'nis' => (string) ($verifiedSantri['nis'] ?? ''),
                'nama' => (string) ($verifiedSantri['nama_santri'] ?? ''),
                'balance' => (int) ((float) ($verifiedSantri['balance'] ?? 0)),
                'sisa_jatah_hari' => (int) $jatahPin['sisa'],
                'limit_harian' => (int) $jatahPin['limit'],
                'terpakai_hari' => (int) $jatahPin['terpakai'],
            ];
        }
        if ($action === 'process_scan_uang') {
            $jsonExtra['debit_success'] = $lastSuccessNominal > 0;
            $jsonExtra['nominal'] = $lastSuccessNominal;
            if ($lastSuccessNominal > 0 && is_array($lastSantriSummary)) {
                $jsonExtra['saldo_keseluruhan'] = (int) ($lastSantriSummary['saldo_keseluruhan'] ?? 0);
                $jsonExtra['sisa_jatah_hari'] = (int) ($lastSantriSummary['sisa_jatah_hari'] ?? 0);
                $jsonExtra['limit_harian'] = (int) ($lastSantriSummary['limit_harian'] ?? 0);
                $jsonExtra['terpakai_hari'] = (int) ($lastSantriSummary['terpakai_hari'] ?? 0);
            }
            if ($cashlessVoiceText !== null && $cashlessVoiceText !== '') {
                $jsonExtra['voice'] = $cashlessVoiceText;
            }
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
$cashlessScanCss = app_asset_href('/assets/css/cashless-scan.css');
if ($koperasiPortal) {
    require_once __DIR__ . '/../includes/koperasi_portal_layout.php';
    koperasi_portal_layout_begin([
        'title' => $pageTitle,
        'koperasi_nama' => $koperasiNama,
        'active' => 'scan',
    ]);
    echo '<link href="' . htmlspecialchars($cashlessScanCss) . '" rel="stylesheet">';
} else {
    $bodyClass = 'cashless-scan-page';
    $pageStylesheets = [$cashlessScanCss];
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$koperasiPortal): ?>
<div class="cashless-admin-bar d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="small fw-semibold text-dark">Koperasi: <?= htmlspecialchars($koperasiNama) ?></span>
    <div class="d-flex align-items-center gap-2">
        <?php if ($koperasiListAdmin !== []): ?>
            <form method="post" class="d-flex align-items-center gap-2 mb-0">
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
</div>
<?php endif; ?>

<?php if ($resultMessage !== null && ($resultType !== 'success' || $lastSuccessNominal > 0)): ?>
    <div class="alert alert-<?= $resultType === 'success' ? 'success' : ($resultType === 'danger' ? 'danger' : 'warning') ?> cashless-alert-page py-2 mb-0"><?= htmlspecialchars($resultMessage) ?></div>
<?php endif; ?>

<div class="cashless-scan-app<?= $koperasiPortal ? ' cashless-koperasi-page' : '' ?>">
    <header class="cashless-scan-top">
        <h1><i class="fa-solid fa-wallet me-1"></i> Scan Cashless</h1>
        <span class="cashless-kop-badge"><?= htmlspecialchars($koperasiNama) ?></span>
    </header>

    <div class="cashless-steps" aria-hidden="true">
        <div class="cashless-step cashless-step--1">1 · QR &amp; PIN</div>
        <div class="cashless-step cashless-step--2">2 · Nominal</div>
    </div>

    <div class="cashless-scan-body">
        <div class="cashless-scan-wrap<?= ($autoStartNominalScan && $verifiedSantri) ? ' is-money-phase' : '' ?>" id="cashless_scan_wrap">
            <div class="cashless-phase-santri" id="phase_santri">
                <div class="cashless-viewport">
                    <div id="reader"></div>
                </div>
                <div class="cashless-pin-card">
                    <label for="pin_input">Masukkan PIN santri</label>
                    <input type="password" id="pin_input" class="cashless-pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" placeholder="••••" aria-label="PIN">
                </div>
                <form method="post" id="pin_form" class="d-none">
                    <input type="hidden" name="action" value="verify_cashless_pin">
                    <input type="hidden" name="scan_source" value="camera">
                    <input type="hidden" name="kode_qr" id="kode_qr" value="">
                    <input type="hidden" name="pin" id="pin_hidden" value="">
                </form>
            </div>

            <div class="cashless-phase-uang" id="phase_uang">
                <div class="cashless-santri-chip" id="santri_chip">
                    <i class="fa-solid fa-user-check"></i>
                    <span id="santri_chip_name"><?php if ($verifiedSantri): ?><?= htmlspecialchars((string) ($verifiedSantri['nama_santri'] ?? '')) ?><?php else: ?>Santri<?php endif; ?></span>
                </div>
                <div class="cashless-viewport">
                    <div id="money_reader"></div>
                </div>
                <form method="post" id="scan_uang_form" class="d-none">
                    <input type="hidden" name="action" value="process_scan_uang">
                    <input type="hidden" name="scan_source" value="camera">
                    <input type="hidden" name="nominal_scan" id="nominal_scan" value="">
                    <input type="hidden" name="keterangan" value="Belanja">
                </form>
            </div>

            <div class="cashless-flash is-empty" id="cashless_flash" role="status" aria-live="polite"></div>
            <div class="cashless-actions">
                <button type="button" class="cashless-btn-retry" id="retry_camera_btn"><i class="fa-solid fa-rotate-right me-1"></i> Ulangi kamera</button>
            </div>
        </div>
    </div>
</div>

<?php if (!$koperasiPortal): ?>
<details class="cashless-history small">
    <summary>Riwayat debit hari ini</summary>
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
        const santriChipName = document.getElementById('santri_chip_name');
        const readerId = 'reader';
        const moneyReaderId = 'money_reader';
        if (!input || typeof Html5Qrcode === 'undefined') return;

        let html5QrCode = null;
        let activeCameraId = null;
        let moneyQr = null;
        let moneyPhase = wrap && wrap.classList.contains('is-money-phase');
        let pinVerifyBusy = false;
        let moneyScanBusy = false;
        let pinDebounce = null;

        const nominalScanInput = document.getElementById('nominal_scan');
        const moneyForm = document.getElementById('scan_uang_form');

        function qrScanBoxSize() {
            var w = Math.min(window.innerWidth || 360, 480) - 48;
            var side = Math.max(180, Math.min(280, Math.floor(w * 0.82)));
            return { width: side, height: side };
        }

        function scanConfig() {
            var box = qrScanBoxSize();
            return { fps: 12, qrbox: box, aspectRatio: 1.333334 };
        }

        function nextPaint() {
            return new Promise(function (resolve) {
                requestAnimationFrame(function () {
                    requestAnimationFrame(resolve);
                });
            });
        }

        async function waitReaderVisible(elementId) {
            for (var i = 0; i < 12; i++) {
                var el = document.getElementById(elementId);
                if (el && el.offsetParent !== null && el.offsetHeight > 40) {
                    return;
                }
                await nextPaint();
            }
            await new Promise(function (r) { setTimeout(r, 120); });
        }

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
            if (preferredCameraId && preferredCameraId !== 'environment') {
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

        function formatRp(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function saldoSuccessLine(data) {
            if (!data || !data.debit_success) return '';
            var parts = [];
            if (data.saldo_keseluruhan !== undefined) {
                parts.push('Saldo ' + formatRp(data.saldo_keseluruhan));
            }
            if (data.sisa_jatah_hari !== undefined) {
                parts.push('Sisa jatah hari ini ' + formatRp(data.sisa_jatah_hari));
            }
            return parts.length ? parts.join(' · ') : '';
        }

        function setFlash(msg, type) {
            if (!flashEl) return;
            flashEl.textContent = msg || '';
            flashEl.className = 'cashless-flash';
            if (!msg) {
                flashEl.classList.add('is-empty');
                return;
            }
            flashEl.classList.remove('is-empty');
            if (type === 'error') {
                flashEl.classList.add('is-error');
            } else if (type === 'success') {
                flashEl.classList.add('is-success');
            } else {
                flashEl.classList.add('is-info');
            }
        }

        function setSantriName(nama) {
            if (santriChipName && nama) {
                santriChipName.textContent = nama;
            }
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
            await stopMoneyScanner();
            await stopCurrentScanner();
            html5QrCode = new Html5Qrcode(readerId);
            const onSuccess = async function (decodedText) {
                input.value = decodedText;
                await stopCurrentScanner();
                setFlash('QR terbaca. Masukkan PIN.', 'info');
                if (pinInput) {
                    pinInput.value = '';
                    pinInput.focus();
                }
            };
            const scanConfigLocal = scanConfig();
            try {
                let useId = preferredCameraId;
                if (useId === 'environment') useId = null;
                if (useId) {
                    const cameras = await Html5Qrcode.getCameras();
                    if (!cameras.find(function (c) { return c.id === useId; })) useId = null;
                }
                activeCameraId = await startScannerDevice(html5QrCode, scanConfigLocal, onSuccess, function () {}, useId || null);
            } catch (e) {
                setFlash('Kamera gagal. Ketuk ulangi kamera.', 'error');
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
            setSantriName(santriName || (santriChipName ? santriChipName.textContent : ''));
            if (pinInput) {
                pinInput.blur();
            }
            setFlash('', '');
        }

        function resetToSantriPhase() {
            moneyPhase = false;
            if (wrap) wrap.classList.remove('is-money-phase');
            if (input) input.value = '';
            if (pinInput) pinInput.value = '';
            if (nominalScanInput) nominalScanInput.value = '';
            setFlash('', '');
        }

        async function beginMoneyQrScan(santriName) {
            if (!CFG.scanUangEnabled) {
                setFlash('Scan uang nonaktif di pengaturan.', 'error');
                return;
            }
            await stopCurrentScanner();
            await stopMoneyScanner();
            switchToMoneyPhase(santriName || (santriChipName ? santriChipName.textContent : ''));
            await waitReaderVisible(moneyReaderId);
            moneyQr = new Html5Qrcode(moneyReaderId);
            try {
                await startScannerDevice(
                    moneyQr,
                    scanConfig(),
                    function (decodedText) {
                        submitMoneyScan((decodedText || '').trim());
                    },
                    function () {},
                    activeCameraId || null
                );
            } catch (e) {
                setFlash('Kamera nominal gagal. Ketuk ulangi kamera.', 'error');
            }
        }

        async function postCashlessAjax(form) {
            var body = new FormData(form);
            var res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Cashless-Ajax': '1' },
                body: body,
                credentials: 'same-origin'
            });
            return res.json();
        }

        async function submitMoneyScan(raw) {
            if (moneyScanBusy || !raw || !nominalScanInput || !moneyForm) return;
            moneyScanBusy = true;
            await stopMoneyScanner();
            nominalScanInput.value = raw;
            setFlash('Memproses nominal…', 'info');

            if (window.PondokOfflineSync && PondokOfflineSync.handleFormSubmit(moneyForm, { label: 'Cashless: ' + raw })) {
                moneyScanBusy = false;
                resetToSantriPhase();
                await startSantriScanner(activeCameraId);
                return;
            }

            try {
                var data = await postCashlessAjax(moneyForm);
                if (data.voice) speak(data.voice);
                else if (data.ok && data.debit_success) speak('Transaksi berhasil');

                if (data.ok && data.debit_success) {
                    var extra = saldoSuccessLine(data);
                    setFlash((data.message || 'Transaksi berhasil.') + (extra ? '\n' + extra : ''), 'success');
                    resetToSantriPhase();
                    await startSantriScanner(activeCameraId);
                } else {
                    setFlash(data.message || 'Transaksi gagal.', 'error');
                    if (data.verified) {
                        await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
                    } else {
                        resetToSantriPhase();
                        await startSantriScanner(activeCameraId);
                    }
                }
            } catch (e) {
                setFlash('Gagal kirim transaksi. Coba lagi.', 'error');
                if (moneyPhase) {
                    await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
                }
            } finally {
                moneyScanBusy = false;
            }
        }

        async function verifyPinAjax() {
            if (pinVerifyBusy || moneyPhase) return;
            var qr = (input.value || '').trim();
            var pin = (pinInput && pinInput.value) ? pinInput.value.trim() : '';
            if (!qr || pin.length < CFG.pinMinLen) return;

            pinVerifyBusy = true;
            setFlash('Memverifikasi PIN…', 'info');
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
                    var nama = (data.santri && data.santri.nama) ? data.santri.nama : '';
                    setSantriName(nama);
                    speak('PIN benar');
                    setFlash('PIN benar. Arahkan ke QR nominal.', 'success');
                    await beginMoneyQrScan(nama);
                } else if (data.ok && !CFG.scanUangEnabled) {
                    setFlash(data.message || 'PIN benar. Scan uang nonaktif.', 'success');
                    if (pinInput) pinInput.value = '';
                    await startSantriScanner(activeCameraId);
                } else {
                    setFlash(data.message || 'PIN salah.', 'error');
                    if (pinInput) {
                        pinInput.value = '';
                        pinInput.focus();
                    }
                    await startSantriScanner(activeCameraId);
                }
            } catch (e) {
                setFlash('Gagal verifikasi. Coba lagi.', 'error');
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
                setFlash('', '');
                if (moneyPhase) {
                    await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
                } else {
                    await startSantriScanner(activeCameraId);
                }
            });
        }

        window.addEventListener('orientationchange', function () {
            setTimeout(function () {
                if (moneyPhase) {
                    beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
                } else {
                    startSantriScanner(activeCameraId);
                }
            }, 400);
        });

        async function pageInit() {
            if (CFG.autoNominalAfterPin && CFG.scanUangEnabled) {
                speak('PIN benar');
                setFlash('PIN benar. Arahkan ke QR nominal.', 'success');
                await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
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
