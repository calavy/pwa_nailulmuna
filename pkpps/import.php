<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/excel.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);
ensure_kegiatan_kategori_column($pdo);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_import_jadwal_pkpps.xlsx', [
        ['tingkatan_pkpps', 'nama_kegiatan', 'kategori_kegiatan', 'hari_ke', 'jam_mulai', 'jam_selesai', 'tempat', 'nip_pembimbing'],
        ['PKPPS Tingkat 1', 'Ngaji PKPPS', 'PKPPS', 1, '07:00', '08:00', 'Ruang PKPPS', ''],
        ['PKPPS Tingkat 2', 'Sholat Dhuha', 'PKPPS', 0, '06:30', '07:00', 'Masjid', '12345'],
    ], 'Template Jadwal PKPPS');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file_import']) || (int) ($_FILES['file_import']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        set_flash('error', 'File tidak valid.');
        header('Location: ' . app_href('/pkpps/import.php'));
        exit;
    }
    $name = strtolower((string) $_FILES['file_import']['name']);
    $tmp = (string) $_FILES['file_import']['tmp_name'];
    $rows = [];
    try {
        if (str_ends_with($name, '.xlsx')) {
            $rows = normalize_import_rows(parse_xlsx_rows($tmp));
        } elseif (str_ends_with($name, '.csv')) {
            if (($h = fopen($tmp, 'r')) !== false) {
                $header = fgetcsv($h);
                while (($data = fgetcsv($h)) !== false) {
                    if (!$header) {
                        continue;
                    }
                    $item = array_combine($header, $data);
                    if (is_array($item)) {
                        $rows[] = $item;
                    }
                }
                fclose($h);
            }
            $rows = normalize_import_rows($rows);
        } else {
            throw new RuntimeException('Format harus .xlsx atau .csv');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        header('Location: ' . app_href('/pkpps/import.php'));
        exit;
    }

    $tingkatanMap = [];
    foreach (pkpps_tingkatan_list($pdo, false) as $t) {
        $nama = mb_strtolower(trim((string) ($t['nama_tingkatan'] ?? '')));
        if ($nama !== '') {
            $tingkatanMap[$nama] = (int) ($t['id'] ?? 0);
        }
    }

    $ok = 0;
    $skip = 0;
    foreach ($rows as $raw) {
        $namaTingkat = trim((string) ($raw['tingkatan_pkpps'] ?? $raw['nama_tingkatan'] ?? ''));
        $namaKeg = trim((string) ($raw['nama_kegiatan'] ?? ''));
        $kategoriKeg = pkpps_normalize_kegiatan_kategori((string) ($raw['kategori_kegiatan'] ?? ''), true);
        $hariKe = (int) ($raw['hari_ke'] ?? 0);
        $jamMulai = trim((string) ($raw['jam_mulai'] ?? ''));
        $jamSelesai = trim((string) ($raw['jam_selesai'] ?? ''));
        $tempat = trim((string) ($raw['tempat'] ?? ''));
        $nipPb = trim((string) ($raw['nip_pembimbing'] ?? ''));
        if ($namaTingkat === '' || $namaKeg === '' || $jamMulai === '' || $jamSelesai === '') {
            $skip++;
            continue;
        }
        $tingkatId = $tingkatanMap[mb_strtolower($namaTingkat)] ?? 0;
        if ($tingkatId <= 0) {
            $skip++;
            continue;
        }
        if (strlen($jamMulai) === 5) {
            $jamMulai .= ':00';
        }
        if (strlen($jamSelesai) === 5) {
            $jamSelesai .= ':00';
        }
        $stK = $pdo->prepare('SELECT id FROM kegiatan WHERE nama_kegiatan = :n LIMIT 1');
        $stK->execute(['n' => $namaKeg]);
        $kegId = (int) ($stK->fetchColumn() ?: 0);
        if ($kegId <= 0) {
            $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:n, :kat, 1)')
                ->execute(['n' => $namaKeg, 'kat' => $kategoriKeg]);
            $kegId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE kegiatan SET kategori_kegiatan = :kat WHERE id = :id')->execute(['kat' => $kategoriKeg, 'id' => $kegId]);
        }
        $pbId = null;
        if ($nipPb !== '' && table_exists($pdo, 'pembimbing')) {
            $stP = $pdo->prepare('SELECT id FROM pembimbing WHERE nip = :nip LIMIT 1');
            $stP->execute(['nip' => $nipPb]);
            $pid = (int) ($stP->fetchColumn() ?: 0);
            if ($pid > 0) {
                $pbId = $pid;
            }
        }
        $pdo->prepare('
            INSERT INTO pkpps_jadwal (pkpps_tingkatan_id, kegiatan_id, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat, is_aktif)
            VALUES (:tid, :kid, :hk, :jm, :js, :pid, :tp, 1)
        ')->execute([
            'tid' => $tingkatId,
            'kid' => $kegId,
            'hk' => max(0, min(7, $hariKe)),
            'jm' => $jamMulai,
            'js' => $jamSelesai,
            'pid' => $pbId,
            'tp' => $tempat !== '' ? $tempat : null,
        ]);
        $ok++;
    }
    pkpps_sync_kegiatan_kategori($pdo);
    set_flash('success', "Import PKPPS selesai: {$ok} baris jadwal, {$skip} dilewati.");
    header('Location: ' . app_href('/pkpps/jadwal.php'));
    exit;
}

$pageTitle = 'Import Jadwal PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/jadwal.php')) ?>">Jadwal PKPPS</a></p>
    <h1 class="h4 mb-1">Import jadwal PKPPS Excel / CSV</h1>
    <p class="text-muted mb-0 small">
        Kolom: tingkatan_pkpps, nama_kegiatan, kategori_kegiatan (PKPPS/TAALIM/JAMAAH — default PKPPS), hari_ke (0=setiap hari, 1–7),
        jam_mulai, jam_selesai, tempat, nip_pembimbing
    </p>
</div>

<div class="card shadow-sm" style="max-width:32rem">
    <div class="card-body">
        <p class="small"><a href="?template=xlsx">Unduh template Excel (.xlsx)</a></p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="file_import" class="form-control mb-2" accept=".xlsx,.csv" required>
            <button class="btn btn-primary"><i class="fa-solid fa-file-import me-1"></i> Import</button>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pkpps/jadwal.php')) ?>">Batal</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
