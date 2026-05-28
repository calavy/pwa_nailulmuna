<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/munawib.php';
require_once __DIR__ . '/../helpers/excel.php';

require_roles(['admin', 'pengurus']);

munawib_ensure_schema($pdo);
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_sdm_munawib.xlsx', [
        ['nama', 'nip', 'no_wa', 'qr'],
        ['Munawib A', 'MW001', '081234567890', 'MW001'],
    ], 'Template Munawib');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'import_munawib') {
        if (!isset($_FILES['file_import_munawib']) || (int) ($_FILES['file_import_munawib']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            set_flash('error', 'File import munawib tidak valid.');
            header('Location: ' . app_href('/pembimbing/munawib.php'));
            exit;
        }
        $name = strtolower((string) $_FILES['file_import_munawib']['name']);
        $tmp = (string) $_FILES['file_import_munawib']['tmp_name'];
        $rows = [];
        try {
            if (str_ends_with($name, '.xlsx')) {
                $rows = normalize_import_rows(parse_xlsx_rows($tmp));
            } elseif (str_ends_with($name, '.csv')) {
                if (($h = fopen($tmp, 'r')) !== false) {
                    $header = fgetcsv($h);
                    while (($data = fgetcsv($h)) !== false) {
                        if (!$header) { continue; }
                        $item = array_combine($header, $data);
                        if (is_array($item)) { $rows[] = $item; }
                    }
                    fclose($h);
                }
                $rows = normalize_import_rows($rows);
            } else {
                throw new RuntimeException('Format file harus .xlsx atau .csv');
            }
        } catch (Throwable $e) {
            set_flash('error', 'Import gagal: ' . $e->getMessage());
            header('Location: ' . app_href('/pembimbing/munawib.php'));
            exit;
        }

        $ok = 0;
        $skip = 0;
        foreach ($rows as $raw) {
            $nama = trim((string) ($raw['nama'] ?? ''));
            $nip = trim((string) ($raw['nip'] ?? ''));
            $wa = trim((string) ($raw['no_wa'] ?? ''));
            $qr = trim((string) ($raw['qr'] ?? ''));
            if ($nama === '') {
                $skip++;
                continue;
            }
            if ($qr === '' && $nip !== '') {
                $qr = $nip;
            }
            try {
                if ($nip !== '') {
                    $stC = $pdo->prepare('SELECT id FROM munawib WHERE nip = :nip LIMIT 1');
                    $stC->execute(['nip' => $nip]);
                    $existingId = (int) ($stC->fetchColumn() ?: 0);
                    if ($existingId > 0) {
                        $stU = $pdo->prepare('UPDATE munawib SET nama = :n, no_wa = :wa, qr = :qr WHERE id = :id');
                        $stU->execute(['n' => $nama, 'wa' => $wa !== '' ? $wa : null, 'qr' => $qr !== '' ? $qr : null, 'id' => $existingId]);
                        $ok++;
                        continue;
                    }
                }
                $st = $pdo->prepare('INSERT INTO munawib (nama, nip, qr, no_wa) VALUES (:n, :nip, :qr, :wa)');
                $st->execute(['n' => $nama, 'nip' => $nip !== '' ? $nip : null, 'qr' => $qr !== '' ? $qr : null, 'wa' => $wa !== '' ? $wa : null]);
                $ok++;
            } catch (Throwable $e) {
                $skip++;
            }
        }
        set_flash('success', "Import data munawib selesai: {$ok} diproses, {$skip} dilewati.");
        header('Location: ' . app_href('/pembimbing/munawib.php'));
        exit;
    }
    if ($action === 'tambah_munawib') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $nip = trim((string) ($_POST['nip'] ?? ''));
        $qr = trim((string) ($_POST['qr'] ?? ''));
        $wa = trim((string) ($_POST['no_wa'] ?? ''));
        if ($nama !== '') {
            $st = $pdo->prepare('INSERT INTO munawib (nama, nip, qr, no_wa) VALUES (:n, :nip, :qr, :wa)');
            $st->execute(['n' => $nama, 'nip' => $nip !== '' ? $nip : null, 'qr' => $qr !== '' ? $qr : null, 'wa' => $wa !== '' ? $wa : null]);
            set_flash('success', 'Munawib "' . $nama . '" ditambahkan.');
        }
    }
    if ($action === 'tambah_penugasan') {
        $pbId = (int) ($_POST['pembimbing_id'] ?? 0);
        $mId = (int) ($_POST['munawib_id'] ?? 0);
        $mulai = (string) ($_POST['tanggal_mulai'] ?? date('Y-m-d'));
        $selesai = (string) ($_POST['tanggal_selesai'] ?? $mulai);
        $alasan = trim((string) ($_POST['alasan'] ?? ''));
        $kegId = (int) ($_POST['kegiatan_id'] ?? 0);
        if ($mId > 0) {
            $st = $pdo->prepare('INSERT INTO munawib_penugasan (pembimbing_id, munawib_id, kegiatan_id, tanggal_mulai, tanggal_selesai, alasan, created_by) VALUES (:p,:m,:k,:mul,:sel,:a,:by)');
            $st->execute([
                'p' => $pbId > 0 ? $pbId : null, 'm' => $mId, 'k' => $kegId > 0 ? $kegId : null,
                'mul' => $mulai, 'sel' => $selesai, 'a' => $alasan !== '' ? $alasan : null, 'by' => $currentUserId,
            ]);
            if ($pbId > 0) {
                set_flash('success', 'Penugasan munawib dibuat. Pembimbing asli tetap tercatat izin/alpa di payroll.');
            } else {
                set_flash('success', 'Munawib disiapkan tanpa ikatan pembimbing khusus (fleksibel saat scan).');
            }
        }
    }
    header('Location: ' . app_href('/pembimbing/munawib.php'));
    exit;
}

$munawibList = munawib_list_aktif($pdo);
$pembimbingList = $pdo->query('SELECT id, nama_pembimbing, nip FROM pembimbing ORDER BY nama_pembimbing')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$kegiatanList = table_exists($pdo, 'kegiatan') ? $pdo->query('SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan')->fetchAll(PDO::FETCH_ASSOC) : [];
$penugasan = $pdo->query('
    SELECT mp.*, m.nama AS munawib_nama, b.nama_pembimbing, k.nama_kegiatan
    FROM munawib_penugasan mp
    INNER JOIN munawib m ON m.id = mp.munawib_id
    LEFT JOIN pembimbing b ON b.id = mp.pembimbing_id
    LEFT JOIN kegiatan k ON k.id = mp.kegiatan_id
    WHERE mp.status = "AKTIF"
    ORDER BY mp.tanggal_mulai DESC
    LIMIT 100
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Data Munawib';
require_once __DIR__ . '/../includes/header.php';
$flashOk = get_flash('success');
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Munawib (pengganti pembimbing)</h1>
    <p class="text-muted mb-0 small">Munawib dapat scan hadir untuk kegiatan aktif, termasuk saat belum ada pembimbing pengganti yang ditentukan atau belum ada data izin pembimbing.</p>
</div>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/munawib.php?template=xlsx')) ?>" class="btn btn-outline-success btn-sm">
            <i class="fa-solid fa-file-arrow-down me-1"></i> Template Excel Munawib
        </a>
        <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2">
            <input type="hidden" name="action" value="import_munawib">
            <input type="file" name="file_import_munawib" class="form-control form-control-sm" accept=".xlsx,.csv" style="max-width:260px" required>
            <button class="btn btn-success btn-sm" type="submit"><i class="fa-solid fa-file-import me-1"></i> Import Munawib</button>
        </form>
        <span class="small text-muted">Kolom: nama, nip, no_wa, qr</span>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Tambah munawib</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="tambah_munawib">
                    <div class="col-12"><input class="form-control form-control-sm" name="nama" placeholder="Nama *" required></div>
                    <div class="col-6"><input class="form-control form-control-sm" name="nip" placeholder="NIP"></div>
                    <div class="col-6"><input class="form-control form-control-sm" name="qr" placeholder="Kode QR"></div>
                    <div class="col-12"><input class="form-control form-control-sm" name="no_wa" placeholder="No WA"></div>
                    <div class="col-12"><button class="btn btn-primary btn-sm w-100">Simpan</button></div>
                </form>
            </div>
        </div>
        <div class="card shadow-sm mt-3">
            <div class="card-header py-2"><strong>Penugasan</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="tambah_penugasan">
                    <div class="col-12">
                        <select class="form-select form-select-sm" name="pembimbing_id" required>
                            <option value="0">Tanpa pembimbing khusus (fleksibel)</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['nama_pembimbing']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <select class="form-select form-select-sm" name="munawib_id" required>
                            <option value="">Munawib *</option>
                            <?php foreach ($munawibList as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars((string) $m['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><input type="date" class="form-control form-control-sm" name="tanggal_mulai" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><input type="date" class="form-control form-control-sm" name="tanggal_selesai" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-12">
                        <select class="form-select form-select-sm" name="kegiatan_id">
                            <option value="0">Semua kegiatan</option>
                            <?php foreach ($kegiatanList as $k): ?>
                                <option value="<?= (int) $k['id'] ?>"><?= htmlspecialchars((string) $k['nama_kegiatan']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><textarea class="form-control form-control-sm" name="alasan" rows="2" placeholder="Alasan"></textarea></div>
                    <div class="col-12"><button class="btn btn-warning btn-sm w-100">Buat penugasan</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2"><strong>Daftar munawib</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Nama</th><th>NIP</th><th>QR</th><th>No WA</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php if ($munawibList === []): ?><tr><td colspan="5" class="text-muted text-center py-3">Belum ada data munawib.</td></tr><?php endif; ?>
                    <?php foreach ($munawibList as $mw): ?>
                        <tr>
                            <td class="small fw-semibold"><?= htmlspecialchars((string) ($mw['nama'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) (($mw['nip'] ?? '') !== '' ? $mw['nip'] : '-')) ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars((string) (($mw['qr'] ?? '') !== '' ? $mw['qr'] : '-')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) (($mw['no_wa'] ?? '') !== '' ? $mw['no_wa'] : '-')) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href('/pembimbing/munawib_kartu.php?id=' . (int) ($mw['id'] ?? 0))) ?>">
                                    <i class="fa-solid fa-id-card me-1"></i>Kartu
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between">
                <strong>Penugasan aktif</strong>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/rekap/munawib.php')) ?>">Laporan kehadiran</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Periode</th><th>Pembimbing</th><th>Munawib</th><th>Kegiatan</th></tr></thead>
                    <tbody>
                    <?php if ($penugasan === []): ?><tr><td colspan="4" class="text-muted text-center py-3">Belum ada penugasan.</td></tr><?php endif; ?>
                    <?php foreach ($penugasan as $pg): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars((string) $pg['tanggal_mulai']) ?> – <?= htmlspecialchars((string) $pg['tanggal_selesai']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) (($pg['nama_pembimbing'] ?? '') !== '' ? $pg['nama_pembimbing'] : 'Fleksibel / belum ditentukan')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) $pg['munawib_nama']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($pg['nama_kegiatan'] ?? 'Semua')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
