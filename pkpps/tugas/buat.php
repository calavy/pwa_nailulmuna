<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';

require_once __DIR__ . '/../../helpers/pkpps.php';
require_once __DIR__ . '/../../helpers/ikhtibar_import.php';
require_once __DIR__ . '/../../helpers/ikhtibar_google_import.php';
require_once __DIR__ . '/../../helpers/ikhtibar_ai_parse.php';
require_once __DIR__ . '/../../helpers/ikhtibar_preview.php';
require_once __DIR__ . '/../../helpers/ikhtibar_tugas_draf_pin.php';
require_once __DIR__ . '/../../helpers/excel.php';

pkpps_tugas_require_access();
ensure_akademik_ikhtibar_tables($pdo);
pkpps_ensure_schema($pdo);
ensure_santri_identity_columns($pdo);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_soal_ikhtibar.xlsx', ikhtibar_import_template_xlsx_rows(), 'Template Soal Ikhtibar');
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$pkppsJadwalOptions = ikhtibar_pkpps_jadwal_options($pdo, $userId);
$pkppsKelasOptions = ikhtibar_pkpps_kelas_options($pdo, $userId);
require_once __DIR__ . '/../../helpers/ikhtibar_kriteria.php';
$kriteriaList = ikhtibar_kriteria_list($pdo, true);
$roleUser = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$wajibPilihJadwal = !is_super_admin() && !in_array($roleUser, ['admin', 'pengurus'], true);
$id = (int) ($_GET['id'] ?? 0);
$tugas = $id > 0 ? ikhtibar_tugas_by_id($pdo, $id) : null;
if (is_array($tugas)) {
    pkpps_tugas_redirect_jika_bukan($tugas);
}
$soalExisting = $tugas ? ikhtibar_soal_by_tugas($pdo, $id) : [];
$base = pkpps_tugas_base_path();
$googleTemplateUrl = ikhtibar_google_sheets_template_url($pdo);
$aiOcrEnabled = ikhtibar_ai_ocr_enabled($pdo);

if ($tugas) {
    ikhtibar_tugas_process_akses_pin_verify_post($pdo, $tugas, app_href($base . '/buat.php?id=' . $id));
    $tugas = ikhtibar_tugas_by_id($pdo, $id) ?? $tugas;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'simpan'));
    if ($action === 'token_baru' && $id > 0) {
        $plain = ikhtibar_set_token($pdo, $id);
        set_flash('success', 'Token baru: ' . $plain);
        header('Location: ' . app_href($base . '/buat.php?id=' . $id));
        exit;
    }
    $result = pkpps_tugas_simpan_dari_post($pdo, $_POST, $_FILES, $userId);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href($base . '/buat.php?id=' . (int) ($result['id'] ?? $id)));
    exit;
}

$kuotaPg = ikhtibar_kuota_pg();
$kuotaEsai = ikhtibar_kuota_esai();
$jumlahPg = (int) ($tugas['jumlah_pg'] ?? (int) ($_GET['pg'] ?? 10));
$jumlahEsai = (int) ($tugas['jumlah_esai'] ?? (int) ($_GET['esai'] ?? 0));
if (!in_array($jumlahPg, $kuotaPg, true)) {
    $jumlahPg = 10;
}
if (!in_array($jumlahEsai, $kuotaEsai, true)) {
    $jumlahEsai = 0;
}

$pgSoal = [];
$esaiSoal = [];
foreach ($soalExisting as $s) {
    if ((string) ($s['jenis'] ?? '') === 'PG') {
        $pgSoal[(int) $s['nomor']] = $s;
    } else {
        $esaiSoal[(int) $s['nomor']] = $s;
    }
}

$tingkatanList = [];
if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
    $tingkatanList = $pdo->query('SELECT DISTINCT tingkatan FROM santri WHERE tingkatan IS NOT NULL AND tingkatan <> "" ORDER BY tingkatan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$pageTitle = $tugas ? 'Edit Tugas PKPPS' : 'Buat Tugas PKPPS';
$statusTugas = (string) ($tugas['status'] ?? 'draft');
$hasActiveSesi = $tugas ? ikhtibar_tugas_has_active_sesi($pdo, $id) : false;
$perluBuatPinTugas = ikhtibar_tugas_perlu_buat_akses_pin($tugas);
$drafPinTerkunci = $tugas && ikhtibar_tugas_akses_pin_terkunci($tugas);
$bolehPratinjau = $tugas && (!ikhtibar_tugas_status_draf($tugas) || !$drafPinTerkunci);
$wizardInitialStep = ($tugas && ($pgSoal !== [] || $esaiSoal !== [])) ? 3 : 1;
$wizardInitialMetode = ($pgSoal !== [] || $esaiSoal !== []) ? 'manual' : '';

if ($drafPinTerkunci) {
    $pageTitle = 'PIN Draf — Edit Tugas PKPPS';
    require_once __DIR__ . '/../../includes/header.php';
    echo ikhtibar_tugas_render_akses_pin_gate_html(
        $tugas,
        app_href($base . '/index.php'),
        'Daftar tugas PKPPS',
        'mengedit tugas draf'
    );
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>">Tugas PKPPS</a> · <a href="<?= htmlspecialchars(app_href('/pkpps/index.php')) ?>">PKPPS</a></p>
    <h1 class="h4 mb-0"><?= $tugas ? 'Edit' : 'Buat' ?> Tugas PKPPS</h1>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($tugas && ikhtibar_tugas_akses_pin_plain($tugas) !== ''): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <strong>Super admin — PIN draf tugas:</strong>
        <code class="user-select-all"><?= htmlspecialchars(ikhtibar_tugas_akses_pin_plain($tugas)) ?></code>
    </div>
<?php endif; ?>

<?php
$mapelMode = 'pkpps';
$selKelasKey = is_array($tugas) ? ikhtibar_tugas_pkpps_kelas_key($tugas) : '';
$initialMetode = $wizardInitialMetode;
$templateXlsxUrl = app_href($base . '/buat.php?template=xlsx');
$cancelUrl = app_href($base . '/index.php');
$pratinjauUrl = ($bolehPratinjau && $tugas) ? app_href('/pembimbing/tugas/pratinjau.php?tugas_id=' . (int) $tugas['id']) : '';
require __DIR__ . '/../../includes/partials/ikhtibar_tugas_wizard_shell.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/wali-portal.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/ikhtibar-tugas-buat.css')) ?>">
<?php ikhtibar_soal_typography_head(); ?>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
window.IKHTIBAR_BUAT = <?= json_encode([
    'pgData' => $pgSoal,
    'esaiData' => $esaiSoal,
    'previewUrl' => app_href('/pkpps/tugas/preview_import.php'),
    'aiOcrEnabled' => $aiOcrEnabled,
    'initialStep' => $wizardInitialStep,
    'initialMetode' => $wizardInitialMetode,
    'perluPin' => $perluBuatPinTugas && $statusTugas === 'draft',
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/ikhtibar-tugas-wizard.js')) ?>"></script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
