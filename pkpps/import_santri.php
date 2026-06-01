<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/excel.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);
ensure_santri_identity_columns($pdo);

/**
 * @return array{ok:bool,santri_id:int,message:string}
 */
function pkpps_import_resolve_santri_id(PDO $pdo, string $nis, string $qr, string $nama): array
{
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');

    if ($nis !== '') {
        $st = $pdo->prepare('SELECT id FROM santri WHERE TRIM(nis) = :n AND ' . $aktifSql . ' LIMIT 1');
        $st->execute(['n' => $nis]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return ['ok' => true, 'santri_id' => $id, 'message' => ''];
        }
    }
    if ($qr !== '') {
        $st = $pdo->prepare('SELECT id FROM santri WHERE TRIM(qr) = :q AND ' . $aktifSql . ' LIMIT 1');
        $st->execute(['q' => $qr]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return ['ok' => true, 'santri_id' => $id, 'message' => ''];
        }
    }
    if ($nama !== '') {
        $st = $pdo->prepare('SELECT id FROM santri WHERE TRIM(' . $namaCol . ') = :n AND ' . $aktifSql . ' LIMIT 1');
        $st->execute(['n' => $nama]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return ['ok' => true, 'santri_id' => $id, 'message' => ''];
        }
    }

    $hint = $nis !== '' ? 'NIS "' . $nis . '"' : ($qr !== '' ? 'QR "' . $qr . '"' : 'nama "' . $nama . '"');

    return ['ok' => false, 'santri_id' => 0, 'message' => 'Santri tidak ditemukan (' . $hint . ').'];
}

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_import_santri_pkpps.xlsx', [
        ['nis', 'qr', 'tingkatan_pkpps', 'tahun_masehi', 'catatan', 'is_aktif'],
        ['20240001', '', 'Muadalah A', (int) date('Y'), '', 1],
        ['20240002', 'QR-SANTRI-02', 'Muadalah B', (int) date('Y'), 'Baris contoh', 1],
    ], 'Template Santri PKPPS');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file_import']) || (int) ($_FILES['file_import']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        set_flash('error', 'File tidak valid.');
        header('Location: ' . app_href('/pkpps/import_santri.php'));
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
        header('Location: ' . app_href('/pkpps/import_santri.php'));
        exit;
    }

    $tingkatanMap = [];
    foreach (pkpps_tingkatan_list($pdo, false) as $t) {
        $nama = mb_strtolower(trim((string) ($t['nama_tingkatan'] ?? '')));
        if ($nama !== '') {
            $tingkatanMap[$nama] = (int) ($t['id'] ?? 0);
        }
    }

    $ins = $pdo->prepare('
        INSERT INTO pkpps_santri (santri_id, pkpps_tingkatan_id, tahun_masehi, is_aktif, catatan)
        VALUES (:sid, :tid, :th, :aktif, :cat)
        ON DUPLICATE KEY UPDATE
            pkpps_tingkatan_id = VALUES(pkpps_tingkatan_id),
            tahun_masehi = VALUES(tahun_masehi),
            is_aktif = VALUES(is_aktif),
            catatan = VALUES(catatan)
    ');

    $ok = 0;
    $skip = 0;
    $errors = [];
    foreach ($rows as $i => $raw) {
        $nis = trim((string) ($raw['nis'] ?? ''));
        $qr = trim((string) ($raw['qr'] ?? ''));
        $nama = trim((string) ($raw['nama_santri'] ?? $raw['nama'] ?? ''));
        $namaTingkat = trim((string) ($raw['tingkatan_pkpps'] ?? $raw['nama_tingkatan'] ?? $raw['tingkatan'] ?? ''));
        $tahun = (int) ($raw['tahun_masehi'] ?? $raw['tahun'] ?? 0);
        $catatan = mb_substr(trim((string) ($raw['catatan'] ?? '')), 0, 255);
        $isAktifRaw = strtolower(trim((string) ($raw['is_aktif'] ?? '1')));
        $isAktif = in_array($isAktifRaw, ['0', 'no', 'tidak', 'nonaktif', 'non'], true) ? 0 : 1;

        if ($nis === '' && $qr === '' && $nama === '') {
            $skip++;
            continue;
        }
        if ($namaTingkat === '') {
            $errors[] = 'Baris ' . ($i + 2) . ': tingkatan_pkpps kosong.';
            $skip++;
            continue;
        }
        $tingkatKey = mb_strtolower($namaTingkat);
        $tingkatId = $tingkatanMap[$tingkatKey] ?? 0;
        if ($tingkatId <= 0) {
            $errors[] = 'Baris ' . ($i + 2) . ': tingkatan "' . $namaTingkat . '" tidak dikenali.';
            $skip++;
            continue;
        }

        $resolved = pkpps_import_resolve_santri_id($pdo, $nis, $qr, $nama);
        if (!$resolved['ok']) {
            $errors[] = 'Baris ' . ($i + 2) . ': ' . $resolved['message'];
            $skip++;
            continue;
        }

        $ins->execute([
            'sid' => (int) $resolved['santri_id'],
            'tid' => $tingkatId,
            'th' => $tahun > 0 ? $tahun : null,
            'aktif' => $isAktif,
            'cat' => $catatan !== '' ? $catatan : null,
        ]);
        $ok++;
    }

    $msg = "Import santri PKPPS selesai: {$ok} baris tersimpan, {$skip} dilewati.";
    if ($errors !== []) {
        $msg .= ' ' . implode(' ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $msg .= ' …+' . (count($errors) - 5) . ' lainnya.';
        }
    }
    set_flash($ok > 0 ? 'success' : 'error', $msg);
    header('Location: ' . app_href('/pkpps/santri.php'));
    exit;
}

$pageTitle = 'Import Santri PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>">Santri PKPPS</a></p>
    <h1 class="h4 mb-1">Import santri PKPPS (Excel / CSV)</h1>
    <p class="text-muted mb-0 small">
        Santri harus sudah ada di data induk. Cocokkan lewat <strong>NIS</strong> (utama), <strong>QR</strong>, atau <strong>nama</strong> persis.
    </p>
</div>

<div class="alert alert-secondary py-2 small mb-3">
    <strong>Kolom file:</strong> nis, qr (opsional), tingkatan_pkpps, tahun_masehi (opsional), catatan (opsional), is_aktif (1/0).
    Jika santri sudah terdaftar PKPPS, baris akan <em>diperbarui</em> (tingkatan/tahun/status).
</div>

<div class="card shadow-sm" style="max-width:36rem">
    <div class="card-body">
        <p class="small mb-2"><a href="?template=xlsx"><i class="fa-solid fa-download me-1"></i> Unduh template Excel (.xlsx)</a></p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="file_import" class="form-control mb-2" accept=".xlsx,.csv" required>
            <button class="btn btn-primary"><i class="fa-solid fa-file-import me-1"></i> Import</button>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pkpps/santri.php')) ?>">Batal</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
