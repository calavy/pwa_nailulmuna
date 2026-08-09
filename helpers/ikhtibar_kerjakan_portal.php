<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik_ikhtibar.php';
require_once __DIR__ . '/ikhtibar_preview.php';

function ikhtibar_kerjakan_ui_head(): void
{
    require_once __DIR__ . '/app.php';
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_asset_href('/assets/css/ikhtibar-kerjakan.css')) . '">' . "\n";
    echo '<script defer src="' . htmlspecialchars(app_asset_href('/assets/js/ikhtibar-kerjakan-ui.js')) . '"></script>' . "\n";
}

/** CSS/JS/font tugas kerjakan — untuk dimasukkan ke &lt;head&gt; portal santri. */
function ikhtibar_kerjakan_portal_head_html(): string
{
    require_once __DIR__ . '/app.php';
    ob_start();
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_asset_href('/assets/css/ikhtibar-hasil.css')) . '">' . "\n";
    ikhtibar_soal_typography_head();
    ikhtibar_kerjakan_ui_head();

    return (string) ob_get_clean();
}

/**
 * Proses POST halaman kerjakan portal santri. Mengembalikan true jika sudah redirect.
 *
 * @param array{redirect:string,hasil_detail:string,hasil_index:string} $urls
 */
function ikhtibar_kerjakan_handle_post(PDO $pdo, int $tugasId, int $santriId, bool $tokenOk, array $urls): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    $redirectUrl = (string) ($urls['redirect'] ?? '');
    $hasilDetailUrl = (string) ($urls['hasil_detail'] ?? '');
    $hasilIndexUrl = (string) ($urls['hasil_index'] ?? $redirectUrl);

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'verifikasi_token') {
        $inp = trim((string) ($_POST['token'] ?? ''));
        if (ikhtibar_verify_token($pdo, $tugasId, $inp)) {
            $_SESSION['ikhtibar_token_ok'][$tugasId] = true;
            set_flash('success', 'Token benar. Silakan mulai tugas.');
        } else {
            set_flash('error', 'Token tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'verifikasi_pin_draf') {
        $sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
        if (!$sesi || (string) ($sesi['status'] ?? '') !== 'berjalan') {
            set_flash('error', 'Sesi tidak aktif.');
            header('Location: ' . $redirectUrl);
            exit;
        }
        $pin = (string) ($_POST['pin_draf'] ?? '');
        if (!ikhtibar_sesi_verifikasi_pin_draf($sesi, $pin)) {
            set_flash('error', 'PIN draf salah.');
            header('Location: ' . $redirectUrl);
            exit;
        }
        ikhtibar_sesi_buka_draf((int) $sesi['id']);
        set_flash('success', 'Draf dibuka. Lanjutkan mengerjakan.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'mulai' && $tokenOk) {
        $res = ikhtibar_mulai_sesi($pdo, $tugasId, $santriId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action !== 'simpan' && $action !== 'selesai') {
        return false;
    }

    $sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
    if (!$sesi || (string) ($sesi['status'] ?? '') !== 'berjalan') {
        set_flash('error', 'Sesi tidak aktif.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $sesiId = (int) $sesi['id'];
    if (ikhtibar_sesi_draf_terkunci($sesi)) {
        set_flash('error', 'Masukkan PIN draf untuk melanjutkan.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'simpan' && ikhtibar_simpan_sementara_diblokir(ikhtibar_sesi_sisa_detik($sesi))) {
        set_flash('error', 'Sisa waktu 5 menit atau kurang — simpan sementara tidak diizinkan. Gunakan «Selesai & kirim» jika sudah yakin.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'simpan' && !ikhtibar_sesi_punya_pin_draf($sesi)) {
        $pinBaru = (string) ($_POST['pin_draf_baru'] ?? '');
        $pinKonfirm = (string) ($_POST['pin_draf_konfirmasi'] ?? '');
        $err = ikhtibar_draf_pin_validasi($pinBaru, $pinKonfirm);
        if ($err !== null) {
            set_flash('error', $err);
            header('Location: ' . $redirectUrl);
            exit;
        }
        $setPin = ikhtibar_sesi_set_pin_draf($pdo, $sesiId, $pinBaru);
        if (!$setPin['ok']) {
            set_flash('error', (string) ($setPin['message'] ?? 'Gagal menyimpan PIN.'));
            header('Location: ' . $redirectUrl);
            exit;
        }
        $sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId) ?? $sesi;
    }

    $soalList = ikhtibar_soal_urut_sesi($pdo, $sesi);
    foreach ($soalList as $soal) {
        $sid = (int) $soal['id'];
        $key = 'jawaban_' . $sid;
        if (isset($_POST[$key])) {
            ikhtibar_simpan_jawaban($pdo, $sesiId, $sid, trim((string) $_POST[$key]));
        }
    }

    if ($action === 'selesai') {
        ikhtibar_sesi_tutup_draf($sesiId);
        $fin = ikhtibar_selesai_sesi($pdo, $sesiId);
        if ($fin['ok'] && !empty($fin['sesi_id'])) {
            set_flash('success', 'Tugas selesai. Lihat ringkasan nilai di bawah.');
            header('Location: ' . $hasilDetailUrl . (int) $fin['sesi_id']);
        } else {
            set_flash($fin['ok'] ? 'success' : 'error', (string) ($fin['message'] ?? ''));
            header('Location: ' . $hasilIndexUrl);
        }
        exit;
    }

    ikhtibar_sesi_tandai_draf_disimpan($pdo, $sesiId);
    ikhtibar_sesi_buka_draf($sesiId);
    set_flash('success', 'Jawaban tersimpan. PIN draf diperlukan saat membuka kembali.');
    header('Location: ' . $redirectUrl);
    exit;
}

function ikhtibar_kerjakan_render_pin_buka_html(): string
{
    ob_start();
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 fw-bold mb-2"><i class="fa-solid fa-lock me-1 text-warning"></i> Buka draf jawaban</h2>
            <p class="small text-muted mb-3">Anda pernah menyimpan sementara. Masukkan PIN draf untuk melanjutkan mengerjakan.</p>
            <form method="post" class="d-grid gap-2">
                <input type="hidden" name="action" value="verifikasi_pin_draf">
                <input type="password" name="pin_draf" class="form-control form-control-lg text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" required autocomplete="off" placeholder="PIN draf (4–6 digit)">
                <button type="submit" class="btn btn-auth-primary">Buka draf</button>
            </form>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function ikhtibar_kerjakan_render_pin_buat_html(): string
{
    ob_start();
    ?>
    <div class="alert alert-info py-2 small mb-2" id="alert-pin-draf">
        <strong>PIN draf wajib</strong> — buat PIN 4–6 digit sebelum menyimpan sementara. PIN ini dipakai saat membuka draf lagi.
    </div>
    <div class="card border-0 shadow-sm mb-2 ikhtibar-pin-setup">
        <div class="card-body py-2">
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small mb-1" for="pin_draf_baru">PIN baru</label>
                    <input type="password" name="pin_draf_baru" id="pin_draf_baru" class="form-control form-control-sm text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" autocomplete="new-password" placeholder="4–6 digit">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1" for="pin_draf_konfirmasi">Ulangi PIN</label>
                    <input type="password" name="pin_draf_konfirmasi" id="pin_draf_konfirmasi" class="form-control form-control-sm text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" autocomplete="new-password" placeholder="Ulangi PIN">
                </div>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function ikhtibar_kerjakan_render_text_toolbar_html(bool $dock = false): string
{
    $cls = 'ikhtibar-text-toolbar' . ($dock ? ' ikhtibar-text-toolbar--dock' : '');

    return '<div class="' . $cls . '" role="group" aria-label="Ukuran teks soal">'
        . '<span class="ikhtibar-text-toolbar__label"><i class="fa-solid fa-text-height me-1" aria-hidden="true"></i>Ukuran teks</span>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary ikhtibar-text-btn" data-ikhtibar-text-action="decrease" aria-label="Perkecil teks" title="Perkecil">A−</button>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary ikhtibar-text-btn" data-ikhtibar-text-action="reset" aria-label="Ukuran normal" title="Normal">A</button>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary ikhtibar-text-btn" data-ikhtibar-text-action="increase" aria-label="Perbesar teks" title="Perbesar">A+</button>'
        . '</div>';
}
