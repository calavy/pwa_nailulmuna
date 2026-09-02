<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/kedatangan_libur.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);
kedatangan_libur_ensure_schema($pdo);

$createdBy = (int) ($_SESSION['user']['id'] ?? 0);
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'buka_sesi') {
        $res = kedatangan_libur_buka_sesi(
            $pdo,
            (int) ($_POST['akademik_libur_id'] ?? 0),
            trim((string) ($_POST['tanggal'] ?? $today)),
            (string) ($_POST['jam_mulai'] ?? ''),
            (string) ($_POST['jam_selesai'] ?? ''),
            $createdBy
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        $sid = (int) (($res['sesi']['id'] ?? 0));
        if ($sid > 0) {
            $_SESSION['kedatangan_libur_sesi_id'] = $sid;
            header('Location: ' . app_href('/presensi/kedatangan.php?sesi=' . $sid));
            exit;
        }
        header('Location: ' . app_href('/presensi/kedatangan.php'));
        exit;
    }
    if ($action === 'ubah_jam') {
        $sid = (int) ($_POST['sesi_id'] ?? 0);
        $res = kedatangan_libur_ubah_jam(
            $pdo,
            $sid,
            (string) ($_POST['jam_mulai'] ?? ''),
            (string) ($_POST['jam_selesai'] ?? '')
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/presensi/kedatangan.php?sesi=' . $sid));
        exit;
    }
    if ($action === 'kirim_datang' || $action === 'kirim_belum') {
        $sid = (int) ($_POST['sesi_id'] ?? 0);
        $res = kedatangan_libur_kirim_laporan_pengurus($pdo, $sid, $action === 'kirim_belum' ? 'belum' : 'datang');
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/presensi/kedatangan.php?sesi=' . $sid));
        exit;
    }
}

$sesiId = (int) ($_GET['sesi'] ?? ($_SESSION['kedatangan_libur_sesi_id'] ?? 0));
$sesi = $sesiId > 0 ? kedatangan_libur_sesi_by_id($pdo, $sesiId) : null;
if ($sesi !== null) {
    $_SESSION['kedatangan_libur_sesi_id'] = (int) $sesi['id'];
    $sesiId = (int) $sesi['id'];
} else {
    $sesiId = 0;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($sesi === null) {
        set_flash('error', 'Pilih sesi kedatangan dulu sebelum mengunduh CSV.');
        header('Location: ' . app_href('/presensi/kedatangan.php'));
        exit;
    }
    $tglFile = preg_replace('/[^0-9\-]/', '', (string) ($sesi['tanggal'] ?? '')) ?: date('Y-m-d');
    $fn = 'kedatangan-libur-' . $sesiId . '-' . $tglFile . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    echo kedatangan_libur_export_csv($pdo, $sesiId);
    exit;
}

$liburSiap = kedatangan_libur_libur_siap($pdo, $today);
$sesiTerbaru = kedatangan_libur_sesi_terbaru($pdo, 20);
$datang = $sesiId > 0 ? kedatangan_libur_daftar_datang($pdo, $sesiId) : [];
$belum = $sesiId > 0 ? kedatangan_libur_daftar_belum($pdo, $sesiId) : [];
$jumlahAktif = $sesiId > 0 ? (count($datang) + count($belum)) : 0;

$pageTitle = 'Absen kedatangan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Ketertiban</p>
    <h1 class="h4 mb-1">Absen kedatangan setelah liburan</h1>
    <p class="text-muted mb-0 small">Catatan gerbang/asrama — tidak masuk presensi Jama’ah/Ta’lim dan tidak dihitung PRESNA. Hanya rentang libur akademik (bukan libur mingguan).</p>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Buka sesi</h2>
        <?php if ($liburSiap === []): ?>
            <p class="small text-muted mb-2">Tidak ada rentang libur yang baru selesai (hari terakhir libur sampai 3 hari kemudian). Sesi yang sudah dibuka tetap bisa dipakai di daftar bawah.</p>
        <?php endif; ?>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="buka_sesi">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="akademik_libur_id">Rentang libur</label>
                <select class="form-select form-select-sm" name="akademik_libur_id" id="akademik_libur_id" required <?= $liburSiap === [] ? 'disabled' : '' ?>>
                    <option value="">Pilih libur</option>
                    <?php foreach ($liburSiap as $libur): ?>
                        <option value="<?= (int) $libur['id'] ?>">
                            <?= htmlspecialchars((string) $libur['nama']) ?>
                            (<?= htmlspecialchars(app_format_tanggal_id((string) $libur['tanggal_mulai'])) ?>
                            – <?= htmlspecialchars(app_format_tanggal_id((string) $libur['tanggal_selesai'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="tanggal">Tanggal</label>
                <input class="form-control form-control-sm" type="date" name="tanggal" id="tanggal" value="<?= htmlspecialchars($today) ?>" required <?= $liburSiap === [] ? 'disabled' : '' ?>>
            </div>
            <div class="col-3 col-md-2">
                <label class="form-label small mb-1" for="jam_mulai">Jam mulai</label>
                <input class="form-control form-control-sm" type="time" name="jam_mulai" id="jam_mulai" value="<?= htmlspecialchars(kedatangan_libur_jam_default_mulai($pdo)) ?>" required <?= $liburSiap === [] ? 'disabled' : '' ?>>
            </div>
            <div class="col-3 col-md-2">
                <label class="form-label small mb-1" for="jam_selesai">Jam selesai</label>
                <input class="form-control form-control-sm" type="time" name="jam_selesai" id="jam_selesai" value="<?= htmlspecialchars(kedatangan_libur_jam_default_selesai($pdo)) ?>" required <?= $liburSiap === [] ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100" type="submit" <?= $liburSiap === [] ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-door-open me-1"></i>Buka sesi
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($sesiTerbaru !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <h2 class="h6 mb-2">Sesi terbaru</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Libur</th>
                        <th>Jam</th>
                        <th class="text-end">Datang</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sesiTerbaru as $row): ?>
                        <tr class="<?= (int) $row['id'] === $sesiId ? 'table-success' : '' ?>">
                            <td><?= htmlspecialchars(app_format_tanggal_id((string) $row['tanggal'])) ?></td>
                            <td><?= htmlspecialchars((string) ($row['nama_libur'] ?? $row['nama'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars(app_format_jam_rentang((string) $row['jam_mulai'], (string) $row['jam_selesai'])) ?></td>
                            <td class="text-end"><?= (int) ($row['jumlah_datang'] ?? 0) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/presensi/kedatangan.php?sesi=' . (int) $row['id'])) ?>">Buka</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($sesi !== null): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div>
                <h2 class="h6 mb-1"><?= htmlspecialchars((string) ($sesi['nama_libur'] ?? $sesi['nama'] ?? 'Sesi')) ?></h2>
                <p class="small text-muted mb-0">
                    <?= htmlspecialchars(app_format_tanggal_id((string) $sesi['tanggal'])) ?>
                    · jam <?= htmlspecialchars(app_format_jam_rentang((string) $sesi['jam_mulai'], (string) $sesi['jam_selesai'])) ?>
                    · datang <?= count($datang) ?><?= $jumlahAktif > 0 ? (' / ' . $jumlahAktif . ' santri aktif') : '' ?>
                </p>
            </div>
            <div>
                <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/presensi/kedatangan_scan.php?sesi=' . $sesiId)) ?>">
                    <i class="fa-solid fa-qrcode me-1"></i>Scan kedatangan
                </a>
            </div>
        </div>
        <form method="post" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="action" value="ubah_jam">
            <input type="hidden" name="sesi_id" value="<?= $sesiId ?>">
            <div class="col-auto">
                <label class="form-label small mb-1" for="jam_mulai_edit">Jam mulai</label>
                <input class="form-control form-control-sm" type="time" name="jam_mulai" id="jam_mulai_edit" value="<?= htmlspecialchars(substr((string) $sesi['jam_mulai'], 0, 5)) ?>" required>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1" for="jam_selesai_edit">Jam selesai</label>
                <input class="form-control form-control-sm" type="time" name="jam_selesai" id="jam_selesai_edit" value="<?= htmlspecialchars(substr((string) $sesi['jam_selesai'], 0, 5)) ?>" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Simpan jam</button>
            </div>
        </form>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" onsubmit="return confirm('Kirim daftar yang sudah datang ke pengurus putra dan putri?');">
                <input type="hidden" name="action" value="kirim_datang">
                <input type="hidden" name="sesi_id" value="<?= $sesiId ?>">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="fa-brands fa-whatsapp me-1"></i>Kirim yang sudah datang
                </button>
            </form>
            <form method="post" onsubmit="return confirm('Kirim daftar yang belum datang ke pengurus putra dan putri?');">
                <input type="hidden" name="action" value="kirim_belum">
                <input type="hidden" name="sesi_id" value="<?= $sesiId ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">
                    <i class="fa-brands fa-whatsapp me-1"></i>Kirim yang belum datang
                </button>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/presensi/kedatangan.php?sesi=' . $sesiId . '&export=csv')) ?>">
                <i class="fa-solid fa-file-csv me-1"></i>Unduh CSV
            </a>
        </div>
        <p class="small text-muted mt-2 mb-0">Nomor pengurus: pengaturan WA → Akun/nomor (peran kedatangan), atau fallback nomor izin putra/putri. Template bisa diedit di tab Template.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body py-2">
                <h2 class="h6 mb-2">Sudah datang (<?= count($datang) ?>)</h2>
                <?php if ($datang === []): ?>
                    <p class="small text-muted mb-0">Belum ada scan.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 28rem;">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Nama</th><th>Tingkatan</th><th>Jam</th></tr></thead>
                            <tbody>
                            <?php foreach ($datang as $row):
                                $jamScan = (string) $row['jam'];
                                $telatMenit = kedatangan_libur_menit_telat($jamScan, (string) $sesi['jam_mulai'], (string) $sesi['jam_selesai']);
                                $luar = $telatMenit <= 0 && !kedatangan_libur_jam_dalam_jendela($jamScan, (string) $sesi['jam_mulai'], (string) $sesi['jam_selesai']);
                                ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars((string) $row['nama_santri']) ?>
                                        <?php if (trim((string) ($row['nis'] ?? '')) !== ''): ?>
                                            <span class="text-muted small">(<?= htmlspecialchars((string) $row['nis']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string) ($row['tingkatan'] ?? '-')) ?></td>
                                    <td>
                                        <?= htmlspecialchars(app_format_jam($jamScan)) ?>
                                        <?php if ($telatMenit > 0): ?>
                                            <span class="badge text-bg-warning">Telat <?= htmlspecialchars(kedatangan_libur_format_durasi_menit($telatMenit)) ?></span>
                                        <?php elseif ($luar): ?>
                                            <span class="badge text-bg-warning">Luar jam</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body py-2">
                <h2 class="h6 mb-2">Belum datang (<?= count($belum) ?>)</h2>
                <?php if ($belum === []): ?>
                    <p class="small text-muted mb-0">Semua santri aktif sudah dicatat, atau belum ada data santri aktif.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 28rem;">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Nama</th><th>Tingkatan</th></tr></thead>
                            <tbody>
                            <?php foreach ($belum as $row): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars((string) $row['nama_santri']) ?>
                                        <?php if (trim((string) ($row['nis'] ?? '')) !== ''): ?>
                                            <span class="text-muted small">(<?= htmlspecialchars((string) $row['nis']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string) ($row['tingkatan'] ?? '-')) ?></td>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
