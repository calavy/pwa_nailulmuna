<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/excel.php';

require_roles(['admin', 'pengurus']);

if (($_GET['template'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_import_santri.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['qr', 'nis', 'nama_santri', 'tingkatan', 'no_wa_wali']);
    fputcsv($out, ['QR-001', '2024001', 'Contoh Santri', 'SMP', '6281234567890']);
    fclose($out);
    exit;
}

$allowedRedirects = [
    '/santri/index.php',
    '/santri/import.php',
];
$redirectTarget = '/santri/import.php';
$requestedRedirect = trim((string) ($_POST['redirect_to'] ?? ''));
if ($requestedRedirect !== '' && in_array($requestedRedirect, $allowedRedirects, true)) {
    $redirectTarget = $requestedRedirect;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file_import']) || !is_array($_FILES['file_import']) || (int) $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'File import tidak valid.');
        header('Location: ' . app_href($redirectTarget));
        exit;
    }

    $name = strtolower((string) $_FILES['file_import']['name']);
    $tmp = (string) $_FILES['file_import']['tmp_name'];
    $rows = [];

    try {
        if (str_ends_with($name, '.xlsx')) {
            $rows = parse_xlsx_rows($tmp);
            $rows = normalize_santri_import_rows($rows);
        } elseif (str_ends_with($name, '.csv')) {
            if (($handle = fopen($tmp, 'r')) !== false) {
                $header = fgetcsv($handle);
                while (($data = fgetcsv($handle)) !== false) {
                    if (!$header) {
                        continue;
                    }
                    $item = array_combine($header, $data);
                    if (!is_array($item)) {
                        continue;
                    }
                    $rows[] = [
                        'qr' => trim((string) ($item['qr'] ?? '')),
                        'nis' => trim((string) ($item['nis'] ?? '')),
                        'nama_santri' => trim((string) ($item['nama_santri'] ?? '')),
                        'tingkatan' => trim((string) ($item['tingkatan'] ?? '')),
                        'no_wa_wali' => trim((string) ($item['no_wa_wali'] ?? '')),
                    ];
                }
                fclose($handle);
            }
        } else {
            throw new RuntimeException('Format harus .xlsx atau .csv');
        }

        $insert = $pdo->prepare('
            INSERT INTO santri (qr, nis, nama_santri, tingkatan, no_wa_wali, is_aktif)
            VALUES (:qr, :nis, :nama_santri, :tingkatan, :no_wa_wali, 1)
            ON DUPLICATE KEY UPDATE
                qr = VALUES(qr),
                nama_santri = VALUES(nama_santri),
                tingkatan = VALUES(tingkatan),
                no_wa_wali = VALUES(no_wa_wali)
        ');

        $total = 0;
        foreach ($rows as $row) {
            if (($row['nis'] ?? '') === '' || ($row['nama_santri'] ?? '') === '') {
                continue;
            }
            $insert->execute([
                'qr' => $row['qr'] ?? '',
                'nis' => $row['nis'] ?? '',
                'nama_santri' => $row['nama_santri'] ?? '',
                'tingkatan' => $row['tingkatan'] ?? '',
                'no_wa_wali' => $row['no_wa_wali'] ?? '',
            ]);
            $total++;
        }

        set_flash('success', 'Import selesai. Total data diproses: ' . $total);
    } catch (Throwable $e) {
        set_flash('error', 'Import gagal: ' . $e->getMessage());
    }

    header('Location: ' . app_href($redirectTarget));
    exit;
}

$pageTitle = 'Import Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-2">Import Data Santri Massal</h1>
        <p class="text-muted">
            Upload file <code>.xlsx</code> atau <code>.csv</code>. Kolom yang dipakai:
            <code>qr</code>, <code>nis</code>, <code>nama_santri</code>, <code>tingkatan</code>, <code>no_wa_wali</code>.
        </p>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-8">
                <input type="file" class="form-control" name="file_import" accept=".xlsx,.csv" required>
            </div>
            <div class="col-md-4">
                <button class="btn btn-success w-100">Import Sekarang</button>
            </div>
        </form>
        <hr>
        <h2 class="h6">Template Header Excel/CSV</h2>
        <code>qr,nis,nama_santri,tingkatan,no_wa_wali</code>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
