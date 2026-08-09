<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik_ikhtibar.php';

function ikhtibar_tugas_status_draf(array $tugas): bool
{
    return (string) ($tugas['status'] ?? '') === 'draft';
}

function ikhtibar_tugas_punya_akses_pin(array $tugas): bool
{
    return trim((string) ($tugas['draf_akses_pin_hash'] ?? '')) !== '';
}

/** PIN plain — hanya untuk super admin. */
function ikhtibar_tugas_akses_pin_plain(array $tugas): string
{
    if (!function_exists('is_super_admin') || !is_super_admin()) {
        return '';
    }

    return trim((string) ($tugas['draf_akses_pin_plain'] ?? ''));
}

function ikhtibar_tugas_akses_pin_terbuka(int $tugasId): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }

    return !empty($_SESSION['ikhtibar_tugas_draf_unlock'][$tugasId]);
}

function ikhtibar_tugas_buka_akses_pin(int $tugasId): void
{
    if (!isset($_SESSION['ikhtibar_tugas_draf_unlock']) || !is_array($_SESSION['ikhtibar_tugas_draf_unlock'])) {
        $_SESSION['ikhtibar_tugas_draf_unlock'] = [];
    }
    $_SESSION['ikhtibar_tugas_draf_unlock'][$tugasId] = time();
}

function ikhtibar_tugas_akses_pin_terkunci(array $tugas): bool
{
    if (!ikhtibar_tugas_status_draf($tugas) || !ikhtibar_tugas_punya_akses_pin($tugas)) {
        return false;
    }

    return !ikhtibar_tugas_akses_pin_terbuka((int) ($tugas['id'] ?? 0));
}

function ikhtibar_tugas_perlu_buat_akses_pin(?array $tugas): bool
{
    if ($tugas === null) {
        return true;
    }

    return ikhtibar_tugas_status_draf($tugas) && !ikhtibar_tugas_punya_akses_pin($tugas);
}

function ikhtibar_tugas_set_akses_pin(PDO $pdo, int $tugasId, string $pin): array
{
    $err = ikhtibar_draf_pin_validasi($pin);
    if ($err !== null) {
        return ['ok' => false, 'message' => $err];
    }
    $plain = ikhtibar_draf_pin_normalize($pin);
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $pdo->prepare('
        UPDATE ikhtibar_tugas SET draf_akses_pin_hash = :h, draf_akses_pin_plain = :p WHERE id = :id
    ')->execute(['h' => $hash, 'p' => $plain, 'id' => $tugasId]);

    return ['ok' => true, 'message' => 'PIN draf tugas disimpan.'];
}

function ikhtibar_tugas_verifikasi_akses_pin(array $tugas, string $pin): bool
{
    $hash = trim((string) ($tugas['draf_akses_pin_hash'] ?? ''));
    if ($hash === '') {
        return true;
    }

    return password_verify(ikhtibar_draf_pin_normalize($pin), $hash);
}

/** @return never|void */
function ikhtibar_tugas_process_akses_pin_verify_post(PDO $pdo, array $tugas, string $redirectUrl): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (trim((string) ($_POST['action'] ?? '')) !== 'verifikasi_pin_draf_tugas') {
        return;
    }

    $fresh = ikhtibar_tugas_by_id($pdo, (int) ($tugas['id'] ?? 0));
    if (!$fresh || !ikhtibar_tugas_status_draf($fresh)) {
        set_flash('error', 'Tugas tidak valid.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $pin = (string) ($_POST['pin_draf_tugas'] ?? '');
    if (!ikhtibar_tugas_verifikasi_akses_pin($fresh, $pin)) {
        set_flash('error', 'PIN draf salah.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    ikhtibar_tugas_buka_akses_pin((int) $fresh['id']);
    set_flash('success', 'PIN benar. Anda dapat mengedit / melihat pratinjau.');
    header('Location: ' . $redirectUrl);
    exit;
}

function ikhtibar_tugas_render_akses_pin_gate_html(array $tugas, string $backUrl, string $backLabel, string $contextLabel): string
{
    $judul = htmlspecialchars((string) ($tugas['judul'] ?? 'Tugas'));
    $plain = ikhtibar_tugas_akses_pin_plain($tugas);
    ob_start();
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h1 class="h5 fw-bold mb-2"><i class="fa-solid fa-lock text-warning me-1"></i> PIN draf tugas</h1>
                    <p class="small text-muted mb-3">
                        Tugas <strong><?= $judul ?></strong> masih draf.
                        Masukkan PIN untuk <?= htmlspecialchars($contextLabel) ?>.
                    </p>
                    <?php if ($plain !== ''): ?>
                        <div class="alert alert-warning py-2 small mb-3">
                            <strong>Super admin:</strong> PIN draf = <code><?= htmlspecialchars($plain) ?></code>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="d-grid gap-2">
                        <input type="hidden" name="action" value="verifikasi_pin_draf_tugas">
                        <input type="password" name="pin_draf_tugas" class="form-control form-control-lg text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" required autocomplete="off" placeholder="PIN draf (4–6 digit)">
                        <button type="submit" class="btn btn-primary">Lanjutkan</button>
                        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-link btn-sm"><?= htmlspecialchars($backLabel) ?></a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function ikhtibar_tugas_render_akses_pin_buat_html(): string
{
    ob_start();
    ?>
    <div class="alert alert-info py-2 small mb-3">
        <strong>PIN draf wajib</strong> — buat PIN 4–6 digit sebelum menyimpan draf.
        PIN dipakai saat mengedit atau membuka pratinjau, dan dapat dilihat super admin.
    </div>
    <div class="card mb-3 border-info border-opacity-25">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6">
                    <label class="form-label small mb-1" for="pin_draf_tugas_baru">PIN draf baru</label>
                    <input type="password" name="pin_draf_tugas_baru" id="pin_draf_tugas_baru" class="form-control text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" autocomplete="new-password" placeholder="4–6 digit">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small mb-1" for="pin_draf_tugas_konfirmasi">Ulangi PIN</label>
                    <input type="password" name="pin_draf_tugas_konfirmasi" id="pin_draf_tugas_konfirmasi" class="form-control text-center" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="6" autocomplete="new-password" placeholder="Ulangi PIN">
                </div>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * Halaman pratinjau ala portal santri (fullscreen).
 *
 * @param array{back_url:string,back_label:string,show_kunci?:bool} $opts
 */
function ikhtibar_render_pratinjau_portal_page(array $tugas, array $soalList, array $opts): void
{
    require_once __DIR__ . '/app.php';
    require_once __DIR__ . '/ikhtibar_preview.php';
    require_once __DIR__ . '/ikhtibar_kerjakan_portal.php';

    $backUrl = (string) ($opts['back_url'] ?? '#');
    $backLabel = (string) ($opts['back_label'] ?? 'Kembali');
    $showKunci = (bool) ($opts['show_kunci'] ?? false);
    $pageTitle = 'Pratinjau — ' . (string) ($tugas['judul'] ?? 'Tugas');
    $cardsHtml = ikhtibar_render_soal_cards_html($soalList, [
        'readonly' => false,
        'show_kunci' => false,
        'preview_badge' => false,
        'portal_style' => true,
        'interactive' => true,
    ]);
    $skorPayload = ikhtibar_preview_skor_client_payload($soalList);
    $headAssets = ikhtibar_kerjakan_portal_head_html();
    $previewJs = app_asset_href('/assets/js/ikhtibar-pratinjau-nilai.js');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require dirname(__DIR__) . '/includes/partials/app_vendor_assets.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/wali-portal.css')) ?>">
    <?= $headAssets ?>
    <script defer src="<?= htmlspecialchars($previewJs) ?>"></script>
</head>
<body class="santri-portal santri-portal--kerjakan py-3 py-md-4">
<div class="container wali-shell px-3">
<div class="ikhtibar-kerjakan-page" id="ikhtibar-pratinjau-root">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-sm btn-outline-secondary">&larr; <?= htmlspecialchars($backLabel) ?></a>
        <span class="badge text-bg-info">Tampilan portal santri</span>
        <?php if (ikhtibar_tugas_status_draf($tugas)): ?>
            <span class="badge text-bg-secondary">Draf</span>
        <?php else: ?>
            <span class="badge text-bg-success">Published</span>
        <?php endif; ?>
    </div>
    <?= ikhtibar_render_kerjakan_header_html($tugas, null, [
        'preview' => true,
        'durasi_menit' => (int) ($tugas['durasi_menit'] ?? 0),
        'status' => 'menunggu',
    ]) ?>
    <p class="small text-muted mb-2">
        PG: <?= (int) ($tugas['jumlah_pg'] ?? 0) ?>
        · Esai: <?= (int) ($tugas['jumlah_esai'] ?? 0) ?>
        · Pilih jawaban lalu tekan <strong>Hitung nilai</strong> (simulasi, kunci tidak ditampilkan ke santri)
    </p>
    <form id="form-pratinjau-nilai">
    <?= $cardsHtml ?>
    <div id="ikhtibar-pratinjau-hasil" class="alert alert-success d-none mb-2" role="status"></div>
    <?= ikhtibar_kerjakan_render_text_toolbar_html(true) ?>
    <div class="d-grid gap-2 mt-2 ikhtibar-kerjakan-actions">
        <button type="button" class="btn btn-outline-primary" id="btn-hitung-pratinjau">Hitung nilai (simulasi)</button>
        <button type="button" class="btn btn-outline-secondary" disabled>Simpan sementara</button>
        <button type="button" class="btn btn-auth-primary" disabled>Selesai &amp; kirim</button>
    </div>
    </form>
    <script type="application/json" id="ikhtibar-pratinjau-skor-data"><?= json_encode($skorPayload, JSON_UNESCAPED_UNICODE) ?></script>
    <p class="small text-center text-muted mt-2 mb-0">Pratinjau interaktif — nilai PG otomatis; esai perlu koreksi pembimbing.</p>
</div>
</div>
</body>
</html>
    <?php
}
