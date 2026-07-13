<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_impor_ekspor.php';

require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$bolehDestruktif = keuangan_impor_ekspor_boleh_destruktif($pdo);
$preview = null;

$dl = trim((string) ($_GET['download'] ?? ''));
if ($dl !== '') {
    if ($dl === 'masuk') {
        send_xlsx_download('riwayat_masuk.xlsx', keuangan_impor_ekspor_build_masuk_rows($pdo), 'Masuk');
        exit;
    }
    if ($dl === 'keluar') {
        send_xlsx_download('riwayat_keluar.xlsx', keuangan_impor_ekspor_build_keluar_rows($pdo), 'Keluar');
        exit;
    }
    if ($dl === 'template_masuk') {
        send_xlsx_download('template_riwayat_masuk.xlsx', keuangan_impor_ekspor_template_masuk(), 'Template Masuk');
        exit;
    }
    if ($dl === 'template_keluar') {
        send_xlsx_download('template_riwayat_keluar.xlsx', keuangan_impor_ekspor_template_keluar(), 'Template Keluar');
        exit;
    }
    if ($dl === 'keduanya') {
        // Unduh masuk dulu; keluar lewat query terpisah di UI (browser satu file per request).
        send_xlsx_download('riwayat_masuk.xlsx', keuangan_impor_ekspor_build_masuk_rows($pdo), 'Masuk');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'wipe_all') {
        if (!$bolehDestruktif) {
            set_flash('error', 'Akses ditolak.');
        } else {
            $res = keuangan_impor_ekspor_wipe_all(
                $pdo,
                $currentUserId,
                (string) ($_POST['alasan'] ?? ''),
                (string) ($_POST['konfirmasi'] ?? '')
            );
            set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        }
        header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
        exit;
    }

    if (in_array($action, ['preview_masuk', 'commit_masuk', 'preview_keluar', 'commit_keluar'], true)) {
        if (!$bolehDestruktif && in_array($action, ['commit_masuk', 'commit_keluar'], true)) {
            set_flash('error', 'Akses ditolak untuk mengunggah isi ulang.');
            header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
            exit;
        }

        $fileKey = str_contains($action, 'masuk') ? 'file_masuk' : 'file_keluar';
        if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey]) || (int) $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'File Excel tidak valid.');
            header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
            exit;
        }

        $name = (string) $_FILES[$fileKey]['name'];
        $tmp = (string) $_FILES[$fileKey]['tmp_name'];
        $allowAppend = !empty($_POST['allow_append']);

        try {
            $rows = keuangan_impor_ekspor_parse_upload_rows($tmp, $name);
            if (str_contains($action, 'masuk')) {
                $validated = keuangan_impor_ekspor_validate_masuk($pdo, $rows);
                if ($action === 'preview_masuk') {
                    $preview = [
                        'jenis' => 'masuk',
                        'validated' => $validated,
                    ];
                    $_SESSION['keuangan_impor_preview_masuk'] = [
                        'validated' => $validated,
                        'allow_append' => $allowAppend,
                        'at' => time(),
                    ];
                } else {
                    // Commit memakai file yang baru diunggah ulang (lebih aman daripada temp session).
                    $res = keuangan_impor_ekspor_commit_masuk($pdo, $validated, $currentUserId, $allowAppend);
                    unset($_SESSION['keuangan_impor_preview_masuk']);
                    $msg = (string) ($res['message'] ?? '');
                    if (!empty($res['errors'])) {
                        $msg .= ' ' . implode(' | ', array_slice($res['errors'], 0, 5));
                    }
                    set_flash($res['ok'] ? 'success' : 'error', $msg);
                    header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
                    exit;
                }
            } else {
                $validated = keuangan_impor_ekspor_validate_keluar($pdo, $rows);
                if ($action === 'preview_keluar') {
                    $preview = [
                        'jenis' => 'keluar',
                        'validated' => $validated,
                    ];
                    $_SESSION['keuangan_impor_preview_keluar'] = [
                        'validated' => $validated,
                        'allow_append' => $allowAppend,
                        'at' => time(),
                    ];
                } else {
                    $res = keuangan_impor_ekspor_commit_keluar($pdo, $validated, $currentUserId, $allowAppend);
                    unset($_SESSION['keuangan_impor_preview_keluar']);
                    $msg = (string) ($res['message'] ?? '');
                    if (!empty($res['errors'])) {
                        $msg .= ' ' . implode(' | ', array_slice($res['errors'], 0, 5));
                    }
                    set_flash($res['ok'] ? 'success' : 'error', $msg);
                    header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            set_flash('error', 'Gagal memproses Excel: ' . $e->getMessage());
            header('Location: ' . app_href('/keuangan/impor-ekspor.php'));
            exit;
        }
    }
}

$cntPembayaran = table_exists($pdo, 'keuangan_pembayaran')
    ? (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pembayaran')->fetchColumn()
    : 0;
$cntKeluar = table_exists($pdo, 'keuangan_pengeluaran')
    ? (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pengeluaran')->fetchColumn()
    : 0;

$pageTitle = 'Impor / Ekspor Excel';
$bodyClass = keuangan_body_class('keuangan-impor-ekspor-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>
        · <a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pembayaran.php')) ?>">Riwayat</a>
    </p>
    <h1 class="h4 mb-1">Impor / Ekspor Excel Masuk &amp; Keluar</h1>
    <p class="text-muted mb-0">
        Alur ganti total: unduh Excel → hapus seluruh di sistem → unggah Excel yang sudah diperbarui.
        Masuk = pembayaran santri; keluar = pengeluaran. Pemasukan lain tidak ikut.
    </p>
</div>

<div class="alert alert-warning small">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <strong>Unduh dulu</strong> sebelum menghapus. Hapus bersifat permanen.
    Import membuat ID baru (nomor kuitansi lama tidak berlaku lagi).
    Saat ini: <strong><?= $cntPembayaran ?></strong> pembayaran, <strong><?= $cntKeluar ?></strong> pengeluaran.
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">1. Unduh Excel</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/impor-ekspor.php?download=masuk')) ?>">
            <i class="fa-solid fa-file-excel me-1"></i> Unduh masuk
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/impor-ekspor.php?download=keluar')) ?>">
            <i class="fa-solid fa-file-excel me-1"></i> Unduh keluar
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/impor-ekspor.php?download=masuk')) ?>"
           onclick="setTimeout(function(){ window.location.href=<?= json_encode(app_href('/keuangan/impor-ekspor.php?download=keluar')) ?>; }, 800); return true;">
            Unduh keduanya
        </a>
        <a class="btn btn-link btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/impor-ekspor.php?download=template_masuk')) ?>">Template masuk</a>
        <a class="btn btn-link btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/impor-ekspor.php?download=template_keluar')) ?>">Template keluar</a>
    </div>
</div>

<?php if ($bolehDestruktif): ?>
<div class="card shadow-sm mb-3 border-danger">
    <div class="card-header fw-semibold text-danger">2. Hapus seluruh masuk &amp; keluar</div>
    <div class="card-body">
        <p class="small text-muted">
            Menghapus semua pembayaran + detail, semua pengeluaran, jurnal terkait, dan top-up cashless yang terhubung ke pembayaran.
            Tidak menghapus pemasukan lain, akun, atau master alokasi.
        </p>
        <form method="post" class="row g-2" onsubmit="return confirm('Yakin hapus SELURUH masuk & keluar? Pastikan sudah unduh Excel.');">
            <input type="hidden" name="action" value="wipe_all">
            <div class="col-md-5">
                <label class="form-label small">Ketik <code>HAPUS SEMUA</code></label>
                <input type="text" name="konfirmasi" class="form-control form-control-sm" required autocomplete="off" placeholder="HAPUS SEMUA">
            </div>
            <div class="col-md-5">
                <label class="form-label small">Alasan (wajib)</label>
                <input type="text" name="alasan" class="form-control form-control-sm" required maxlength="500" placeholder="Mis. koreksi massal Juli 2026">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-danger btn-sm w-100">Hapus seluruh</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">3. Unggah Excel</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Lakukan <strong>preview</strong> dulu (dry-run), lalu unggah ulang file yang sama dengan tombol terapkan.
            Centang “izinkan tambah” hanya jika ingin menambahkan ke data yang sudah ada (bukan ganti total).
        </p>

        <div class="row g-3">
            <div class="col-md-6">
                <h2 class="h6">Masuk (pembayaran)</h2>
                <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                    <input type="file" name="file_masuk" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm" required>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="allow_append" value="1" id="appendMasuk">
                        <label class="form-check-label small" for="appendMasuk">Izinkan tambah ke data yang ada</label>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action" value="preview_masuk" class="btn btn-outline-primary btn-sm">Preview masuk</button>
                        <button type="submit" name="action" value="commit_masuk" class="btn btn-primary btn-sm"
                                onclick="return confirm('Terapkan import masuk sekarang?');">Terapkan masuk</button>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <h2 class="h6">Keluar (pengeluaran)</h2>
                <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                    <input type="file" name="file_keluar" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm" required>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="allow_append" value="1" id="appendKeluar">
                        <label class="form-check-label small" for="appendKeluar">Izinkan tambah ke data yang ada</label>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action" value="preview_keluar" class="btn btn-outline-primary btn-sm">Preview keluar</button>
                        <button type="submit" name="action" value="commit_keluar" class="btn btn-primary btn-sm"
                                onclick="return confirm('Terapkan import keluar sekarang?');">Terapkan keluar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-secondary small">
    Hapus seluruh dan unggah isi ulang hanya untuk <strong>super admin</strong> (atau pemegang token koreksi pembayaran).
    Anda tetap bisa mengunduh Excel.
</div>
<?php endif; ?>

<?php if (is_array($preview)):
    $v = $preview['validated'] ?? [];
    $errs = $v['errors'] ?? [];
    ?>
<div class="card shadow-sm mb-3 border-info">
    <div class="card-header fw-semibold">Hasil preview <?= htmlspecialchars((string) ($preview['jenis'] ?? '')) ?></div>
    <div class="card-body">
        <p class="mb-2"><?= htmlspecialchars((string) ($v['message'] ?? '')) ?></p>
        <?php if (($preview['jenis'] ?? '') === 'masuk'): ?>
            <p class="small text-muted mb-2">Grup pembayaran: <?= count($v['groups'] ?? []) ?></p>
        <?php else: ?>
            <p class="small text-muted mb-2">Baris siap: <?= (int) ($v['row_ok'] ?? 0) ?></p>
        <?php endif; ?>
        <?php if ($errs !== []): ?>
            <div class="alert alert-danger small mb-0" style="max-height:240px;overflow:auto">
                <ul class="mb-0 ps-3">
                    <?php foreach (array_slice($errs, 0, 50) as $e): ?>
                        <li><?= htmlspecialchars((string) $e) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($errs) > 50): ?>
                        <li>… dan <?= count($errs) - 50 ?> error lain</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php else: ?>
            <p class="small text-success mb-0">Tidak ada error. Unggah ulang file yang sama lalu klik Terapkan.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Format kolom</div>
    <div class="card-body small">
        <p class="mb-1"><strong>Masuk:</strong> grup_key, tanggal_bayar, nis, jenis_periode, bulan_tagihan, tahun_ajaran_mulai, tahun_ajaran_selesai, metode_bayar, pos_slug, pos_nama, nominal, keterangan</p>
        <p class="mb-0"><strong>Keluar:</strong> tanggal, penanggung_jawab, pos, alokasi_nama, nominal, metode_keluar, keterangan, no_bukti</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
