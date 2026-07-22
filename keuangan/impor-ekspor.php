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
        send_xlsx_download(
            'template_riwayat_masuk.xlsx',
            keuangan_impor_ekspor_template_masuk($pdo),
            'Template Masuk',
            ['column_formats' => keuangan_impor_ekspor_template_column_formats_masuk()]
        );
        exit;
    }
    if ($dl === 'template_keluar') {
        send_xlsx_download(
            'template_riwayat_keluar.xlsx',
            keuangan_impor_ekspor_template_keluar(),
            'Template Keluar',
            ['column_formats' => keuangan_impor_ekspor_template_column_formats_keluar()]
        );
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
        Alur ganti total yang disarankan: unduh Excel → hapus seluruh → impor <strong>masuk</strong> → impor <strong>keluar</strong>.
        Jangan centang “izinkan tambah” kecuali Anda paham risikonya (data ganda).
    </p>
</div>

<div class="alert alert-warning small">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <strong>Unduh dulu</strong> sebelum menghapus. Hapus bersifat permanen.
    Import membuat ID baru (nomor kuitansi lama tidak berlaku lagi).
    Saat ini: <strong><?= $cntPembayaran ?></strong> pembayaran, <strong><?= $cntKeluar ?></strong> pengeluaran.
</div>

<div class="alert alert-info small">
    <strong>Setelah hapus + impor Excel, yang tersinkron:</strong>
    pembayaran pondok &amp; pengeluaran dari file, jurnal otomatis pondok, serta pembayaran pos Saku (top-up cashless tetap dari data saku yang ada).
    <br>
    <strong>Tidak dihapus saat wipe:</strong>
    saldo cashless santri, riwayat scan/setor, pembayaran pos Saku, dan jurnal titipan saku (1103/2101/2103).
    <br>
    <strong>Tidak ikut Excel:</strong> pemasukan manual, transaksi jajan (DEBIT), log setor — isi ulang di modul saku jika perlu.
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
        <p class="small text-muted mb-0 w-100">
            Template sudah memakai format sel Text/Angka. Baris 2 berisi petunjuk (<code>#</code>) — biarkan saat upload.
            Isi data nyata mulai baris 3 (10 contoh) atau baris 13 ke bawah. Hapus baris contoh jika tidak dipakai.
        </p>
    </div>
</div>

<?php if ($bolehDestruktif): ?>
<div class="card shadow-sm mb-3 border-danger">
    <div class="card-header fw-semibold text-danger">2. Hapus keuangan pondok (saku tetap)</div>
    <div class="card-body">
        <p class="small text-muted">
            Menghapus pembayaran pondok (syahriyah, makan, dll.), pengeluaran, pemasukan, dan jurnal operasional pondok.
            <strong>Data saku &amp; cashless santri tidak dihapus</strong> — saldo jajan, riwayat scan/setor, pembayaran pos Saku, dan jurnal titipan saku tetap utuh.
            Setelah itu impor Excel mengembalikan data pondok; saku tidak perlu di-import ulang kecuali ada baris Saku baru di Excel.
        </p>
        <form method="post" class="row g-2" onsubmit="return confirm('Yakin hapus data keuangan PONDOK? Saku & cashless TIDAK dihapus. Pastikan sudah unduh Excel.');">
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
                <button type="submit" class="btn btn-danger btn-sm w-100">Hapus keuangan pondok</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">3. Unggah Excel</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Lakukan <strong>preview</strong> dulu (dry-run), lalu unggah ulang file yang sama dengan tombol terapkan.
            Urutan ganti total: terapkan masuk dulu, lalu keluar.
            Centang “izinkan tambah” hanya jika ingin menambahkan ke data yang sudah ada (bukan ganti total) — berisiko dobel.
            Jika top-up saku gagal saat impor masuk, seluruh batch dibatalkan (bukan sukses parsial).
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
        <h2 class="h6">Masuk (pembayaran)</h2>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kolom</th>
                        <th>Jenis</th>
                        <th>Format</th>
                        <th>Wajib</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>grup_key</td><td>Text</td><td>huruf/angka</td><td>Ya</td><td>Baris sama = 1 kuitansi</td></tr>
                    <tr><td>tanggal_bayar</td><td>Text</td><td>YYYY-MM-DD</td><td>Ya</td><td>Jangan format Date di Excel</td></tr>
                    <tr><td>nis</td><td>Text</td><td>NIS atau kode QR</td><td>Ya</td><td>Harus ada di menu Santri</td></tr>
                    <tr><td>jenis_periode</td><td>Text</td><td>BULANAN / AWAL_TAHUN</td><td>Ya</td><td></td></tr>
                    <tr><td>bulan_tagihan</td><td>Text</td><td>7 atau 2026-07</td><td>Jika BULANAN</td><td>Kosong jika AWAL_TAHUN</td></tr>
                    <tr><td>tahun_ajaran_mulai</td><td>Angka</td><td>2025</td><td>Ya</td><td></td></tr>
                    <tr><td>tahun_ajaran_selesai</td><td>Angka</td><td>2026</td><td>Ya</td><td></td></tr>
                    <tr><td>metode_bayar</td><td>Text</td><td>KAS / TRANSFER</td><td>Ya</td><td></td></tr>
                    <tr><td>pos_slug</td><td>Text</td><td>syahriyah, makan, saku</td><td>Ya</td><td>huruf kecil</td></tr>
                    <tr><td>pos_nama</td><td>Text</td><td>label pos</td><td>Tidak</td><td>Kosong = pakai slug</td></tr>
                    <tr><td>nominal</td><td>Angka</td><td>350000</td><td>Ya</td><td>Rupiah bulat</td></tr>
                    <tr><td>keterangan</td><td>Text</td><td>teks bebas</td><td>Tidak</td><td></td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="h6">Keluar (pengeluaran)</h2>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kolom</th>
                        <th>Jenis</th>
                        <th>Format</th>
                        <th>Wajib</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>tanggal</td><td>Text</td><td>YYYY-MM-DD</td><td>Ya</td><td></td></tr>
                    <tr><td>penanggung_jawab</td><td>Text</td><td>nama PJ</td><td>Ya</td><td></td></tr>
                    <tr><td>pos</td><td>Text</td><td>Operasional, dll</td><td>Ya</td><td></td></tr>
                    <tr><td>alokasi_nama</td><td>Text</td><td>Dana Umum</td><td>Ya</td><td>Harus ada di pengaturan</td></tr>
                    <tr><td>nominal</td><td>Angka</td><td>100000</td><td>Ya</td><td>&gt; 0</td></tr>
                    <tr><td>metode_keluar</td><td>Text</td><td>KAS / TRANSFER</td><td>Ya</td><td></td></tr>
                    <tr><td>keterangan</td><td>Text</td><td>teks bebas</td><td>Tidak</td><td></td></tr>
                    <tr><td>no_bukti</td><td>Text</td><td>nomor bukti</td><td>Tidak</td><td></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
