<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'lookup_qr') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim((string) ($_GET['code'] ?? ''));
    $santri = cashless_lookup_santri_by_code($pdo, $code);
    if (!$santri) {
        echo json_encode(['ok' => true, 'registered' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'registered' => true,
        'nama' => (string) ($santri['nama_santri'] ?? ''),
        'nis' => (string) ($santri['nis'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
$lastSaldoSaku = null;
$lastSisaJatah = null;
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
        if (is_array($verifiedSantri)) {
            require_once __DIR__ . '/../helpers/cashless_koperasi.php';
            $balInit = (float) cashless_santri_saldo_tampil($pdo, $verifiedId);
            $jatahInit = cashless_santri_jatah_harian($pdo, $verifiedId, $balInit);
            $verifiedSantri['saldo_saku'] = (int) round($balInit);
            $verifiedSantri['sisa_jatah_hari'] = (int) ($jatahInit['sisa'] ?? 0);
            $verifiedSantri['limit_harian'] = (int) ($jatahInit['limit'] ?? 0);
        }
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

        require_once __DIR__ . '/../helpers/santri_kartu_sementara.php';
        $santri = santri_resolve_by_scan_code($pdo, $kodeQr);
        if ($santri) {
            $loadFull = $pdo->prepare('SELECT id, nis, nama_santri, nama FROM santri WHERE id = :id LIMIT 1');
            $loadFull->execute(['id' => (int) ($santri['id'] ?? 0)]);
            $santri = $loadFull->fetch() ?: $santri;
        }
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
                require_once __DIR__ . '/../helpers/cashless_koperasi.php';
                $saldoTampil = cashless_santri_saldo_tampil($pdo, $santriId);
                if ($saldoTampil <= 0) {
                    $resultType = 'warning';
                    $resultMessage = 'Transaksi ditolak: Saldo Saku habis.';
                    if ($scanUangVoice) {
                        $cashlessVoiceText = 'Saldo kosong. Transaksi ditolak.';
                    }
                } else {
                    $jatah = cashless_santri_jatah_harian($pdo, $santriId, (float) $saldoTampil);
                    if ((int) ($jatah['sisa'] ?? 0) <= 0) {
                        $resultType = 'warning';
                        $resultMessage = 'Transaksi ditolak: batas belanja harian terlampaui.';
                        if ($scanUangVoice) {
                            $cashlessVoiceText = 'Batas belanja harian terlampaui. Transaksi ditolak.';
                        }
                    } else {
                        $lastSantriSummary = [
                            'nis' => (string) ($santri['nis'] ?? ''),
                            'nama' => $nama,
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
                            'saldo_saku' => $saldoTampil,
                            'sisa_jatah_hari' => (int) ($jatah['sisa'] ?? 0),
                            'limit_harian' => (int) ($jatah['limit'] ?? 0),
                        ];
                        $resultType = 'success';
                        $resultMessage = 'PIN benar. Scan nominal dibuka otomatis.';
                    }
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
                        if (str_contains($saldoErr, 'habis') || str_contains($saldoErr, 'kosong')) {
                            $resultMessage = 'Transaksi ditolak: Saldo Saku habis.';
                        } elseif (str_contains($saldoErr, 'tidak cukup')) {
                            $resultMessage = 'Transaksi ditolak: Saldo Saku tidak cukup.';
                        } elseif (str_contains($saldoErr, 'batas')) {
                            $resultMessage = 'Transaksi ditolak: batas belanja harian terlampaui.';
                        } else {
                            $resultMessage = $saldoErr;
                        }
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
                        require_once __DIR__ . '/../helpers/keuangan_jurnal.php';
                        ensure_keuangan_jurnal_tables($pdo);
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare('UPDATE cashless_accounts SET balance = balance - :nominal WHERE santri_id = :santri_id')->execute([
                                'nominal' => $nominal,
                                'santri_id' => $santriId,
                            ]);
                            $txId = cashless_koperasi_insert_debit(
                                $pdo,
                                $santriId,
                                $nominal,
                                $keterangan,
                                $createdByUserId,
                                $koperasiId > 0 ? $koperasiId : null
                            );
                            cashless_jurnal_belanja_scan(
                                $pdo,
                                $txId,
                                date('Y-m-d'),
                                $nominal,
                                $createdByUserId,
                                $keterangan
                            );
                            if ($pdo->inTransaction()) {
                                $pdo->commit();
                            }
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $resultType = 'danger';
                            $errMsg = $e->getMessage();
                            if (stripos($errMsg, 'no active transaction') !== false) {
                                $resultMessage = 'Transaksi gagal: kesalahan database. Muat ulang halaman lalu coba lagi.';
                            } else {
                                $resultMessage = 'Transaksi gagal: ' . $errMsg;
                            }
                            if ($scanUangVoice) {
                                $cashlessVoiceText = 'Transaksi gagal.';
                            }
                        }
                        if ($resultType !== 'danger') {
                            require_once __DIR__ . '/../helpers/cashless_koperasi.php';
                            $saldoSetelah = cashless_santri_saldo_tampil($pdo, $santriId);
                            cashless_wa_maybe_notify_saldo_rendah($pdo, $santriId, $saldoSetelah);
                            cashless_wa_notify_transaksi_sukses($pdo, $santriId, $nominal, $koperasiId, $saldoSetelah);
                            $lastSuccessNominal = $nominal;
                            $lastSaldoSaku = $saldoSetelah;
                            $jatahSetelah = cashless_santri_jatah_harian($pdo, $santriId, (float) $saldoSetelah);
                            $lastSisaJatah = (int) ($jatahSetelah['sisa'] ?? 0);
                            $resultType = 'success';
                            $resultMessage = 'Transaksi berhasil untuk ' . (string) $santri['nama_santri'] . '. Nominal Rp '
                                . number_format($nominal, 0, ',', '.')
                                . '. Saldo Saku Rp ' . number_format($saldoSetelah, 0, ',', '.') . '.';
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

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json() || trim((string) ($_SERVER['HTTP_X_CASHLESS_AJAX'] ?? '')) === '1') {
        $jsonType = $resultType === 'danger' ? 'error' : $resultType;
        $jsonExtra = [
            'action' => $action,
            'verified' => isset($_SESSION['cashless_verified']) && is_array($_SESSION['cashless_verified']),
            'auto_nominal' => ($action === 'verify_cashless_pin' && $resultType === 'success' && $scanUangEnabled),
        ];
        if ($action === 'verify_cashless_pin' && $resultType === 'success' && is_array($verifiedSantri)) {
            $jsonExtra['santri'] = [
                'id' => (int) ($verifiedSantri['id'] ?? 0),
                'nis' => (string) ($verifiedSantri['nis'] ?? ''),
                'nama' => (string) ($verifiedSantri['nama_santri'] ?? ''),
                'saldo_saku' => (int) ($verifiedSantri['saldo_saku'] ?? 0),
                'sisa_jatah_hari' => (int) ($verifiedSantri['sisa_jatah_hari'] ?? 0),
                'limit_harian' => (int) ($verifiedSantri['limit_harian'] ?? 0),
            ];
        }
        if ($action === 'process_scan_uang') {
            $jsonExtra['debit_success'] = $lastSuccessNominal > 0;
            $jsonExtra['nominal'] = $lastSuccessNominal;
            if ($lastSaldoSaku !== null) {
                $jsonExtra['saldo_saku'] = $lastSaldoSaku;
            }
            if ($lastSisaJatah !== null) {
                $jsonExtra['sisa_jatah_hari'] = $lastSisaJatah;
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
$presensiScanCss = app_asset_href('/assets/css/presensi-scan.css');
if ($koperasiPortal) {
    require_once __DIR__ . '/../includes/koperasi_portal_layout.php';
    koperasi_portal_layout_begin([
        'title' => $pageTitle,
        'koperasi_nama' => $koperasiNama,
        'active' => 'scan',
    ]);
    echo '<link href="' . htmlspecialchars($cashlessScanCss) . '" rel="stylesheet">';
    echo '<link href="' . htmlspecialchars($presensiScanCss) . '" rel="stylesheet">';
} else {
    $bodyClass = 'cashless-scan-page';
    $pageStylesheets = [$cashlessScanCss, $presensiScanCss];
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
            <div id="cashless_scan_start_wrap" class="presensi-scan-start-wrap is-hidden">
                <button type="button" class="btn btn-success btn-lg px-4" id="cashless_btn_start_scan">
                    <i class="fa-solid fa-camera me-2" aria-hidden="true"></i>Mulai scan kamera
                </button>
                <p class="small text-muted mt-2 mb-0">Ketuk untuk mengizinkan kamera. Scan QR santri, lalu masukkan PIN.</p>
            </div>
            <div class="cashless-phase-santri" id="phase_santri">
                <div class="cashless-viewport presensi-scan-viewport" id="cashless_viewport_santri">
                    <div id="reader"></div>
                    <div id="cashless_santri_error" class="presensi-scan-error d-none" role="alert">
                        <div>
                            <p class="fw-semibold mb-2" id="cashless_santri_error_text">Gagal membuka kamera</p>
                            <p class="small opacity-75 mb-2">Izinkan kamera di browser, lalu ketuk <strong>Ulangi</strong>.</p>
                            <button type="button" class="btn btn-light btn-sm" id="cashless_santri_retry">Coba lagi</button>
                        </div>
                    </div>
                </div>
                <div class="cashless-pin-card">
                    <label for="pin_input">Masukkan PIN santri</label>
                    <input type="password" id="pin_input" class="cashless-pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" placeholder="••••" aria-label="PIN">
                    <p class="cashless-pin-hint small text-muted mb-0 mt-1" id="pin_hint">Scan QR santri dulu, lalu masukkan PIN sampai selesai (Enter atau jeda ±1,5 detik).</p>
                </div>
                <form method="post" id="pin_form" class="d-none">
                    <input type="hidden" name="action" value="verify_cashless_pin">
                    <input type="hidden" name="scan_source" value="camera">
                    <input type="hidden" name="kode_qr" id="kode_qr" value="">
                    <input type="hidden" name="pin" id="pin_hidden" value="">
                </form>
            </div>

            <div class="cashless-phase-uang" id="phase_uang">
                <?php
                $initSaldoSaku = is_array($verifiedSantri)
                    ? (int) ($verifiedSantri['saldo_saku'] ?? round((float) ($verifiedSantri['balance'] ?? 0)))
                    : null;
                $initSisaJatah = is_array($verifiedSantri) ? (int) ($verifiedSantri['sisa_jatah_hari'] ?? 0) : null;
                $showSantriStats = $initSaldoSaku !== null;
                ?>
                <div class="cashless-santri-chip" id="santri_chip">
                    <i class="fa-solid fa-user-check"></i>
                    <span id="santri_chip_name"><?php if ($verifiedSantri): ?><?= htmlspecialchars((string) ($verifiedSantri['nama_santri'] ?? '')) ?><?php else: ?>Santri<?php endif; ?></span>
                </div>
                <div class="cashless-viewport cashless-viewport--uang presensi-scan-viewport" id="cashless_viewport_uang">
                    <div id="money_reader"></div>
                    <div id="cashless_money_error" class="presensi-scan-error d-none" role="alert">
                        <div>
                            <p class="fw-semibold mb-2" id="cashless_money_error_text">Gagal membuka kamera</p>
                            <p class="small opacity-75 mb-2">Izinkan kamera di browser, lalu ketuk <strong>Ulangi</strong>.</p>
                            <button type="button" class="btn btn-light btn-sm" id="cashless_money_retry">Coba lagi</button>
                        </div>
                    </div>
                </div>
                <div class="cashless-santri-stats<?= $showSantriStats ? '' : ' is-hidden' ?>" id="santri_stats" aria-live="polite">
                    <div class="cashless-santri-stats__item">
                        <span class="cashless-santri-stats__label"><i class="fa-solid fa-wallet me-1"></i>Saldo Saku</span>
                        <span class="cashless-santri-stats__value" id="santri_saldo_saku_amount"><?php if ($initSaldoSaku !== null): ?>Rp <?= number_format($initSaldoSaku, 0, ',', '.') ?><?php endif; ?></span>
                    </div>
                    <div class="cashless-santri-stats__item">
                        <span class="cashless-santri-stats__label"><i class="fa-solid fa-chart-pie me-1"></i>Sisa jatah hari ini</span>
                        <span class="cashless-santri-stats__value cashless-santri-stats__value--jatah" id="santri_sisa_jatah_amount"><?php if ($initSisaJatah !== null): ?>Rp <?= number_format($initSisaJatah, 0, ',', '.') ?><?php endif; ?></span>
                    </div>
                </div>
                <form method="post" id="scan_uang_form" class="d-none">
                    <input type="hidden" name="action" value="process_scan_uang">
                    <input type="hidden" name="scan_source" value="camera">
                    <input type="hidden" name="nominal_scan" id="nominal_scan" value="">
                    <input type="hidden" name="keterangan" value="Belanja">
                </form>
            </div>

            <div class="cashless-flash is-empty" id="cashless_flash" role="status" aria-live="polite"></div>
            <div class="cashless-scan-controls" id="cashless_scan_controls">
                <button type="button" class="cashless-btn-ctl" id="cashless_btn_flip" title="Ganti kamera depan/belakang">
                    <i class="fa-solid fa-camera-rotate" aria-hidden="true"></i>
                    <span>Ganti kamera</span>
                </button>
                <button type="button" class="cashless-btn-ctl" id="cashless_btn_torch" title="Nyalakan/matikan flash" style="display:none">
                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                    <span>Flash</span>
                </button>
                <button type="button" class="cashless-btn-ctl" id="cashless_btn_restart" title="Nyalakan ulang kamera">
                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                    <span>Ulangi</span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if (!$koperasiPortal): ?>
<details class="cashless-history small">
    <summary>Riwayat debit hari ini</summary>
    <p class="small text-muted mt-2 mb-2">
        Saldo Saku santri berkurang saat scan. Uang fisik masih bendahara sampai
        <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_setor.php')) ?>">setor harian</a> (kas keluar ke koperasi).
    </p>
    <div class="table-responsive mt-2">
        <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Waktu</th><th>NIS</th><th>Nama</th><th class="text-end">Nominal</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($todayRows): foreach ($todayRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                    <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                    <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                    <td class="text-end">Rp <?= number_format((int) ((float) $r['nominal']), 0, ',', '.') ?></td>
                    <td><?php if (!empty($r['setor_at'])): ?><span class="badge text-bg-success">Setor</span><?php else: ?><span class="badge text-bg-warning text-dark">Menunggu</span><?php endif; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center text-muted">—</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
    (function () {
        const ScanCam = window.PresensiScanCamera;
        const CFG = {
            autoNominalAfterPin: <?= ($autoStartNominalScan && $verifiedSantri) ? 'true' : 'false' ?>,
            scanUangEnabled: <?= $scanUangEnabled ? 'true' : 'false' ?>,
            voiceEnabled: <?= $scanUangVoice ? 'true' : 'false' ?>,
            pinMinLen: 4,
            pinIdleMs: 1500
        };
        const wrap = document.getElementById('cashless_scan_wrap');
        const input = document.getElementById('kode_qr');
        const pinInput = document.getElementById('pin_input');
        const pinHidden = document.getElementById('pin_hidden');
        const pinForm = document.getElementById('pin_form');
        const flashEl = document.getElementById('cashless_flash');
        const btnFlip = document.getElementById('cashless_btn_flip');
        const btnTorch = document.getElementById('cashless_btn_torch');
        const btnRestart = document.getElementById('cashless_btn_restart');
        const startWrap = document.getElementById('cashless_scan_start_wrap');
        const startBtn = document.getElementById('cashless_btn_start_scan');
        const santriErrorPanel = document.getElementById('cashless_santri_error');
        const santriErrorText = document.getElementById('cashless_santri_error_text');
        const santriErrorRetry = document.getElementById('cashless_santri_retry');
        const moneyErrorPanel = document.getElementById('cashless_money_error');
        const moneyErrorText = document.getElementById('cashless_money_error_text');
        const moneyErrorRetry = document.getElementById('cashless_money_retry');
        const santriChipName = document.getElementById('santri_chip_name');
        const santriStatsEl = document.getElementById('santri_stats');
        const santriSaldoAmountEl = document.getElementById('santri_saldo_saku_amount');
        const santriJatahAmountEl = document.getElementById('santri_sisa_jatah_amount');
        const readerId = 'reader';
        const moneyReaderId = 'money_reader';
        if (!input || !ScanCam) {
            return;
        }

        const STORAGE_KEY = 'cashless_scan_camera_id';
        let html5QrCode = null;
        let activeCameraId = null;
        let cameras = [];
        let currentCameraIndex = 0;
        let torchOn = false;
        let moneyQr = null;
        let moneyPhase = wrap && wrap.classList.contains('is-money-phase');
        let pinVerifyBusy = false;
        let moneyScanBusy = false;
        let pinDebounce = null;
        let pinEntryActive = false;
        let flashClearTimer = null;

        const nominalScanInput = document.getElementById('nominal_scan');
        const moneyForm = document.getElementById('scan_uang_form');

        function hideCameraError(phase) {
            var panel = phase === 'money' ? moneyErrorPanel : santriErrorPanel;
            if (panel) {
                panel.classList.add('d-none');
            }
        }

        function showCameraError(phase, msg) {
            var panel = phase === 'money' ? moneyErrorPanel : santriErrorPanel;
            var textEl = phase === 'money' ? moneyErrorText : santriErrorText;
            if (textEl) {
                textEl.textContent = msg || 'Gagal membuka kamera';
            }
            if (panel) {
                panel.classList.remove('d-none');
            }
            notifyResult('danger', msg || 'Gagal membuka kamera');
        }

        function scanConfig() {
            var w = Math.min(window.innerWidth || 360, 480) - 48;
            var side = Math.max(180, Math.min(280, Math.floor(w * 0.82)));
            return ScanCam.buildScanConfig({ qrbox: { width: side, height: side } });
        }

        function nextPaint() {
            return new Promise(function (resolve) {
                requestAnimationFrame(function () {
                    requestAnimationFrame(resolve);
                });
            });
        }

        async function waitReaderVisible(elementId) {
            return ScanCam.waitReaderVisible(elementId);
        }

        function pickPreferredCamera(list) {
            return ScanCam.pickPreferredCamera(list, null);
        }

        async function startScannerDevice(html5QrCodeInstance, config, onSuccess, onError, preferredCameraId) {
            return ScanCam.startDevice(
                html5QrCodeInstance,
                config,
                onSuccess,
                onError || function () {},
                cameras,
                preferredCameraId || null
            );
        }

        function formatRp(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function setSantriStats(saldo, sisaJatah) {
            if (!santriStatsEl) return;
            if (saldo === null || saldo === undefined) {
                santriStatsEl.classList.add('is-hidden');
                if (santriSaldoAmountEl) santriSaldoAmountEl.textContent = '';
                if (santriJatahAmountEl) santriJatahAmountEl.textContent = '';
                return;
            }
            santriStatsEl.classList.remove('is-hidden');
            if (santriSaldoAmountEl) santriSaldoAmountEl.textContent = formatRp(saldo);
            if (santriJatahAmountEl) {
                santriJatahAmountEl.textContent = sisaJatah !== null && sisaJatah !== undefined
                    ? formatRp(sisaJatah)
                    : '—';
            }
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
            } else if (type === 'warning') {
                flashEl.classList.add('is-warning');
            } else if (type === 'success') {
                flashEl.classList.add('is-success');
            } else {
                flashEl.classList.add('is-info');
            }
        }

        function scheduleFlashClear(ms) {
            if (flashClearTimer) {
                clearTimeout(flashClearTimer);
            }
            flashClearTimer = setTimeout(function () {
                setFlash('', '');
                flashClearTimer = null;
            }, ms || 9000);
        }

        function formatNotifyLabel(type, message) {
            var msg = (message || '').trim();
            if (!msg) {
                return '';
            }
            if (type === 'success') {
                return 'Berhasil\n' + msg;
            }
            if (type === 'warning') {
                return 'Ditolak\n' + msg;
            }
            if (type === 'danger' || type === 'error') {
                return 'Gagal\n' + msg;
            }
            return msg;
        }

        function mapAjaxResultType(data) {
            if (data && data.ok && data.debit_success) {
                return 'success';
            }
            var t = (data && data.type) ? String(data.type) : 'danger';
            if (t === 'error') {
                t = 'danger';
            }
            if (t === 'success' && !(data && data.debit_success)) {
                t = 'warning';
            }
            return t;
        }

        function notifyResult(type, message, options) {
            options = options || {};
            var labeled = formatNotifyLabel(type, message);
            var flashType = (type === 'danger' || type === 'error') ? 'error' : type;
            if (type === 'info') {
                flashType = 'info';
            }
            setFlash(labeled, flashType);
            if (options.autoClear !== false && labeled) {
                scheduleFlashClear(type === 'success' ? 9000 : 11000);
            }
            if (!labeled || !window.PresensiScanFeedback) {
                return;
            }
            var fbType = type;
            if (type === 'error') {
                fbType = 'danger';
            }
            if (typeof PresensiScanFeedback.show === 'function') {
                PresensiScanFeedback.show(fbType, labeled);
            }
        }

        function notifyScanTick() {
            if (window.PresensiScanFeedback && typeof PresensiScanFeedback.scanTick === 'function') {
                PresensiScanFeedback.scanTick();
            }
        }

        async function ensureCamerasList() {
            if (cameras.length > 0) {
                return;
            }
            cameras = await ScanCam.loadCameraList();
            var savedId = null;
            try {
                savedId = localStorage.getItem(STORAGE_KEY);
            } catch (e) { /* abaikan */ }
            if (savedId && !cameras.some(function (c) { return c.id === savedId; })) {
                savedId = null;
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) { /* abaikan */ }
            }
            if (savedId && cameras.some(function (c) { return c.id === savedId; })) {
                currentCameraIndex = Math.max(0, cameras.findIndex(function (c) { return c.id === savedId; }));
                activeCameraId = savedId;
            } else {
                var pref = pickPreferredCamera(cameras);
                if (pref) {
                    activeCameraId = pref.id;
                    currentCameraIndex = Math.max(0, cameras.findIndex(function (c) { return c.id === pref.id; }));
                }
            }
            updateCameraControls();
        }

        function updateCameraControls() {
            if (btnFlip) {
                var multi = cameras.length >= 2;
                btnFlip.disabled = !multi;
                btnFlip.style.opacity = multi ? '1' : '0.45';
            }
        }

        function getActiveReaderElementId() {
            return moneyPhase ? moneyReaderId : readerId;
        }

        function rememberCameraId(cameraId) {
            if (!cameraId || cameraId === 'environment' || cameraId === 'user') {
                return;
            }
            activeCameraId = cameraId;
            var idx = cameras.findIndex(function (c) { return c.id === cameraId; });
            if (idx >= 0) {
                currentCameraIndex = idx;
            }
            try {
                localStorage.setItem(STORAGE_KEY, cameraId);
            } catch (e) { /* abaikan */ }
        }

        async function applyTorchState() {
            if (!btnTorch) {
                return;
            }
            try {
                var elId = getActiveReaderElementId();
                var video = document.querySelector('#' + elId + ' video');
                if (!video || !video.srcObject) {
                    btnTorch.style.display = 'none';
                    torchOn = false;
                    return;
                }
                var track = video.srcObject.getVideoTracks()[0];
                if (!track) {
                    btnTorch.style.display = 'none';
                    return;
                }
                var caps = track.getCapabilities ? track.getCapabilities() : {};
                if (!caps.torch) {
                    btnTorch.style.display = 'none';
                    torchOn = false;
                    btnTorch.classList.remove('is-active');
                    return;
                }
                btnTorch.style.display = '';
                btnTorch.classList.toggle('is-active', torchOn);
                await track.applyConstraints({ advanced: [{ torch: torchOn }] });
            } catch (e) {
                btnTorch.style.display = 'none';
                torchOn = false;
                btnTorch.classList.remove('is-active');
            }
        }

        function toggleTorch() {
            torchOn = !torchOn;
            applyTorchState();
        }

        async function flipCamera() {
            await ensureCamerasList();
            if (cameras.length < 2) {
                notifyResult('info', 'Hanya satu kamera tersedia di perangkat ini.');
                return;
            }
            currentCameraIndex = (currentCameraIndex + 1) % cameras.length;
            activeCameraId = cameras[currentCameraIndex].id;
            rememberCameraId(activeCameraId);
            var label = /front|user|face|selfie|depan/i.test(cameras[currentCameraIndex].label || '')
                ? 'Kamera depan'
                : (/back|rear|environment|belakang|world|wide/i.test(cameras[currentCameraIndex].label || '')
                    ? 'Kamera belakang'
                    : 'Kamera ' + (currentCameraIndex + 1));
            setFlash('Mengganti ke ' + label + '…', 'info');
            if (moneyPhase) {
                await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '', true);
            } else if (!pinEntryActive) {
                await startSantriScanner(activeCameraId);
            }
        }

        async function restartCamera() {
            setFlash('', '');
            if (moneyPhase) {
                await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '', true);
            } else if (pinEntryActive && (input.value || '').trim()) {
                if (pinInput) {
                    pinInput.disabled = false;
                    pinInput.focus();
                }
            } else {
                pinEntryActive = false;
                await startSantriScanner(activeCameraId);
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
            if (moneyPhase || pinEntryActive) return;
            await ensureCamerasList();
            await stopMoneyScanner();
            await stopCurrentScanner();
            await waitReaderVisible(readerId);
            hideCameraError('santri');
            html5QrCode = new Html5Qrcode(readerId);
            const onSuccess = async function (decodedText) {
                notifyScanTick();
                input.value = decodedText;
                pinEntryActive = true;
                await stopCurrentScanner();
                try {
                    var lookupUrl = window.location.pathname + '?action=lookup_qr&code=' + encodeURIComponent(decodedText || '');
                    var res = await fetch(lookupUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    var data = await res.json();
                    if (data && data.ok && data.registered) {
                        notifyResult('success', '\u2713 ' + (data.nama || 'Santri') + ' (' + (data.nis || '-') + ') terdaftar. Masukkan PIN.');
                        if (pinInput) {
                            pinInput.value = '';
                            pinInput.disabled = false;
                            pinInput.focus();
                        }
                        return;
                    }
                    notifyResult('danger', 'QR/NIS tidak terdaftar di sistem.');
                    input.value = '';
                    pinEntryActive = false;
                    await startSantriScanner(activeCameraId);
                } catch (e) {
                    notifyResult('info', 'QR terbaca. Lanjutkan masukkan PIN sampai selesai.');
                    if (pinInput) {
                        pinInput.value = '';
                        pinInput.disabled = false;
                        pinInput.focus();
                    }
                }
            };
            const scanConfigLocal = scanConfig();
            try {
                let useId = preferredCameraId || activeCameraId;
                if (useId === 'environment' || useId === 'user') useId = null;
                if (useId && !cameras.find(function (c) { return c.id === useId; })) useId = null;
                activeCameraId = await startScannerDevice(html5QrCode, scanConfigLocal, onSuccess, function () {}, useId || null);
                rememberCameraId(activeCameraId);
                await applyTorchState();
            } catch (e) {
                try {
                    await stopCurrentScanner();
                    html5QrCode = new Html5Qrcode(readerId);
                    await waitReaderVisible(readerId);
                    activeCameraId = await startScannerDevice(html5QrCode, scanConfigLocal, onSuccess, function () {}, null);
                    rememberCameraId(activeCameraId);
                    await applyTorchState();
                } catch (e2) {
                    showCameraError('santri', ScanCam.formatError(e2 || e));
                }
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

        function resetToSantriPhase(opts) {
            opts = opts || {};
            moneyPhase = false;
            pinEntryActive = false;
            if (wrap) wrap.classList.remove('is-money-phase');
            if (input) input.value = '';
            if (pinInput) {
                pinInput.value = '';
                pinInput.disabled = false;
            }
            if (nominalScanInput) nominalScanInput.value = '';
            if (opts.clearStats !== false) {
                setSantriStats(null, null);
            }
            if (opts.clearSantriChip !== false && santriChipName) {
                santriChipName.textContent = 'Santri';
            }
            if (opts.clearFlash !== false) {
                if (flashClearTimer) {
                    clearTimeout(flashClearTimer);
                    flashClearTimer = null;
                }
                setFlash('', '');
            }
        }

        async function returnToSantriQrScan(noticeType, noticeMessage) {
            await stopMoneyScanner();
            resetToSantriPhase({ clearFlash: true, clearSantriChip: true, clearStats: true });
            await nextPaint();
            await waitReaderVisible(readerId);
            await startSantriScanner(activeCameraId);
            if (noticeMessage) {
                notifyResult(noticeType || 'info', noticeMessage);
            } else {
                notifyResult('info', 'Kamera siap scan QR santri.');
            }
        }

        async function beginMoneyQrScan(santriName, skipSwitch) {
            if (!CFG.scanUangEnabled) {
                notifyResult('danger', 'Scan uang nonaktif di pengaturan.');
                return;
            }
            await ensureCamerasList();
            await stopCurrentScanner();
            await stopMoneyScanner();
            if (!skipSwitch) {
                switchToMoneyPhase(santriName || (santriChipName ? santriChipName.textContent : ''));
            }
            await waitReaderVisible(moneyReaderId);
            hideCameraError('money');
            moneyQr = new Html5Qrcode(moneyReaderId);
            const moneyOnSuccess = function (decodedText) {
                notifyScanTick();
                submitMoneyScan((decodedText || '').trim());
            };
            try {
                var useId = activeCameraId;
                if (useId === 'environment' || useId === 'user') useId = null;
                if (useId && !cameras.find(function (c) { return c.id === useId; })) useId = null;
                activeCameraId = await startScannerDevice(
                    moneyQr,
                    scanConfig(),
                    moneyOnSuccess,
                    function () {},
                    useId || null
                );
                rememberCameraId(activeCameraId);
                await applyTorchState();
            } catch (e) {
                try {
                    await stopMoneyScanner();
                    moneyQr = new Html5Qrcode(moneyReaderId);
                    await waitReaderVisible(moneyReaderId);
                    activeCameraId = await startScannerDevice(
                        moneyQr,
                        scanConfig(),
                        moneyOnSuccess,
                        function () {},
                        null
                    );
                    rememberCameraId(activeCameraId);
                    await applyTorchState();
                } catch (e2) {
                    showCameraError('money', ScanCam.formatError(e2 || e));
                }
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
                await returnToSantriQrScan('info', 'Transaksi masuk antrian offline.\nScan QR santri untuk transaksi berikutnya.');
                return;
            }

            try {
                var data = await postCashlessAjax(moneyForm);
                if (data.voice) speak(data.voice);
                else if (data.ok && data.debit_success) speak('Transaksi berhasil');

                if (data.ok && data.debit_success) {
                    var msg = data.message || 'Transaksi berhasil.';
                    if (data.saldo_saku !== undefined && msg.indexOf('Saldo Saku') < 0) {
                        msg += ' Saldo Saku ' + formatRp(data.saldo_saku) + '.';
                    }
                    msg += '\nScan QR santri untuk transaksi berikutnya.';
                    await returnToSantriQrScan('success', msg);
                } else {
                    var failType = mapAjaxResultType(data);
                    var failMsg = data.message || 'Transaksi gagal.';
                    if (data.verified) {
                        notifyResult(failType, failMsg);
                        await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '', true);
                    } else {
                        await returnToSantriQrScan(failType, failMsg + '\nScan QR santri untuk mulai lagi.');
                    }
                }
            } catch (e) {
                if (moneyPhase) {
                    notifyResult('danger', 'Gagal kirim transaksi. Periksa koneksi lalu coba lagi.');
                    await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '', true);
                } else {
                    await returnToSantriQrScan('danger', 'Gagal kirim transaksi.\nScan QR santri untuk mulai lagi.');
                }
            } finally {
                moneyScanBusy = false;
            }
        }

        async function verifyPinAjax() {
            if (pinVerifyBusy || moneyPhase) return;
            var qr = (input.value || '').trim();
            var pin = (pinInput && pinInput.value) ? pinInput.value.replace(/\D/g, '') : '';
            if (pinInput) pinInput.value = pin;
            if (!qr || pin.length < CFG.pinMinLen) return;

            if (pinDebounce) {
                clearTimeout(pinDebounce);
                pinDebounce = null;
            }

            pinVerifyBusy = true;
            if (pinInput) pinInput.disabled = true;
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
                    pinEntryActive = false;
                    var nama = (data.santri && data.santri.nama) ? data.santri.nama : '';
                    setSantriName(nama);
                    if (data.santri && data.santri.saldo_saku !== undefined) {
                        setSantriStats(
                            data.santri.saldo_saku,
                            data.santri.sisa_jatah_hari
                        );
                    }
                    speak('PIN benar');
                    notifyResult('success', 'PIN benar. Arahkan ke QR nominal.');
                    await beginMoneyQrScan(nama);
                } else if (data.ok && !CFG.scanUangEnabled) {
                    pinEntryActive = false;
                    notifyResult('success', data.message || 'PIN benar. Scan uang nonaktif.');
                    if (pinInput) pinInput.value = '';
                    await returnToSantriQrScan('success', (data.message || 'PIN benar.') + '\nScan QR santri untuk transaksi berikutnya.');
                } else {
                    notifyResult(mapAjaxResultType(data), data.message || 'PIN salah atau belum diatur.');
                    if (pinInput) {
                        pinInput.value = '';
                        pinInput.disabled = false;
                        pinInput.focus();
                    }
                }
            } catch (e) {
                notifyResult('danger', 'Gagal verifikasi PIN. Coba lagi.');
                if (pinInput) {
                    pinInput.disabled = false;
                    pinInput.focus();
                }
            } finally {
                pinVerifyBusy = false;
                if (pinInput && !moneyPhase && pinEntryActive) {
                    pinInput.disabled = false;
                }
            }
        }

        function schedulePinVerify() {
            if (pinVerifyBusy || moneyPhase || !pinEntryActive) return;
            if (pinDebounce) clearTimeout(pinDebounce);
            pinDebounce = setTimeout(function () {
                pinDebounce = null;
                var qr = (input.value || '').trim();
                var pin = (pinInput && pinInput.value) ? pinInput.value.replace(/\D/g, '') : '';
                if (qr && pin.length >= CFG.pinMinLen) {
                    verifyPinAjax();
                }
            }, CFG.pinIdleMs);
        }

        if (pinInput) {
            pinInput.addEventListener('input', function () {
                var digits = pinInput.value.replace(/\D/g, '');
                if (pinInput.value !== digits) {
                    pinInput.value = digits;
                }
                schedulePinVerify();
            });
            pinInput.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    if (pinDebounce) {
                        clearTimeout(pinDebounce);
                        pinDebounce = null;
                    }
                    verifyPinAjax();
                }
            });
        }

        if (btnFlip) {
            btnFlip.addEventListener('click', function () { flipCamera(); });
        }
        if (btnTorch) {
            btnTorch.addEventListener('click', function () { toggleTorch(); });
        }
        if (btnRestart) {
            btnRestart.addEventListener('click', function () { restartCamera(); });
        }
        if (santriErrorRetry) {
            santriErrorRetry.addEventListener('click', function () {
                hideCameraError('santri');
                restartCamera();
            });
        }
        if (moneyErrorRetry) {
            moneyErrorRetry.addEventListener('click', function () {
                hideCameraError('money');
                restartCamera();
            });
        }

        window.addEventListener('orientationchange', function () {
            setTimeout(function () {
                if (moneyPhase) {
                    beginMoneyQrScan(santriChipName ? santriChipName.textContent : '', true);
                } else if (!pinEntryActive) {
                    startSantriScanner(activeCameraId);
                }
            }, 400);
        });

        async function bootScanPage() {
            var libReady = await ScanCam.waitForLibrary();
            if (!libReady || typeof Html5Qrcode === 'undefined') {
                notifyResult('danger', 'Pustaka scanner gagal dimuat. Muat ulang halaman.');
                return;
            }
            if (!window.isSecureContext) {
                notifyResult('danger', ScanCam.secureContextMsg());
                return;
            }
            await ScanCam.primePermission();
            await ensureCamerasList();
            if (cameras.length === 0) {
                notifyResult('danger', 'Tidak ada kamera terdeteksi. Izinkan kamera di browser lalu ketuk Ulangi.');
                return;
            }
            if (CFG.autoNominalAfterPin && CFG.scanUangEnabled) {
                speak('PIN benar');
                notifyResult('success', 'PIN benar. Arahkan ke QR nominal.');
                await beginMoneyQrScan(santriChipName ? santriChipName.textContent : '');
            } else {
                await startSantriScanner(null);
            }
        }

        ScanCam.runWithMobileStartGate({
            startWrap: startWrap,
            startBtn: startBtn,
            run: bootScanPage,
        }).catch(function () {});
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
