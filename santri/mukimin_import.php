<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/excel.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_alumni_table($pdo);

if (($_GET['template'] ?? '') === 'xlsx') {
    send_xlsx_download('template_import_alumni.xlsx', alumni_xlsx_template_rows(), 'Template');
    exit;
}

$redirectTarget = '/santri/mukimin_import.php';
$templateLabels = alumni_xlsx_header_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file_import']) || !is_array($_FILES['file_import']) || (int) $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'File import tidak valid.');
        header('Location: ' . $redirectTarget);
        exit;
    }

    $name = strtolower((string) $_FILES['file_import']['name']);
    $tmp = (string) $_FILES['file_import']['tmp_name'];

    try {
        if (!str_ends_with($name, '.xlsx')) {
            throw new RuntimeException('Format harus file Excel (.xlsx).');
        }

        $rows = normalize_alumni_import_rows(parse_xlsx_rows($tmp));

        $insert = $pdo->prepare('
            INSERT INTO akademik_alumni (urutan, nis, nama, dusun, rt_rw, desa_kelurahan, kecamatan, kabupaten, propinsi, th_masuk, th_keluar, keterangan)
            VALUES (:urutan, :nis, :nama, :dusun, :rt_rw, :desa_kelurahan, :kecamatan, :kabupaten, :propinsi, :th_masuk, :th_keluar, :keterangan)
            ON DUPLICATE KEY UPDATE
                urutan = VALUES(urutan),
                nama = VALUES(nama),
                dusun = VALUES(dusun),
                rt_rw = VALUES(rt_rw),
                desa_kelurahan = VALUES(desa_kelurahan),
                kecamatan = VALUES(kecamatan),
                kabupaten = VALUES(kabupaten),
                propinsi = VALUES(propinsi),
                th_masuk = VALUES(th_masuk),
                th_keluar = VALUES(th_keluar),
                keterangan = VALUES(keterangan)
        ');

        $total = 0;
        $urutan = 0;
        foreach ($rows as $row) {
            if (($row['nis'] ?? '') === '' || ($row['nama'] ?? '') === '') {
                continue;
            }
            $urutan++;
            $thMasukVal = alumni_parse_year_cell((string) ($row['th_masuk'] ?? ''));
            $thKeluarVal = alumni_parse_year_cell((string) ($row['th_keluar'] ?? ''));
            $insert->execute([
                'urutan' => $urutan,
                'nis' => mb_substr((string) $row['nis'], 0, 32),
                'nama' => mb_substr((string) $row['nama'], 0, 200),
                'dusun' => mb_substr(trim((string) ($row['dusun'] ?? '')), 0, 120) ?: null,
                'rt_rw' => mb_substr(trim((string) ($row['rt_rw'] ?? '')), 0, 20) ?: null,
                'desa_kelurahan' => mb_substr(trim((string) ($row['desa_kelurahan'] ?? '')), 0, 120) ?: null,
                'kecamatan' => mb_substr(trim((string) ($row['kecamatan'] ?? '')), 0, 120) ?: null,
                'kabupaten' => mb_substr(trim((string) ($row['kabupaten'] ?? '')), 0, 120) ?: null,
                'propinsi' => mb_substr(trim((string) ($row['propinsi'] ?? '')), 0, 120) ?: null,
                'th_masuk' => $thMasukVal,
                'th_keluar' => $thKeluarVal,
                'keterangan' => trim((string) ($row['keterangan'] ?? '')) ?: null,
            ]);
            $total++;
        }

        set_flash('success', 'Import data mukimin selesai. Total baris diproses: ' . $total . '. Urutan tampilan mengikuti baris di file Excel (import ulang file lengkap untuk menyelaraskan urutan).');
        header('Location: /santri/mukimin.php');
        exit;
    } catch (Throwable $e) {
        set_flash('error', 'Import gagal: ' . $e->getMessage());
    }

    header('Location: ' . $redirectTarget);
    exit;
}

$pageTitle = 'Import Data Mukimin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-2">Import Data Mukimin</h1>
        <p class="text-muted">
            Upload file Excel <code>.xlsx</code> saja.
            Baris dengan <strong>NIS</strong> yang sudah ada akan diperbarui.
        </p>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-8">
                <input type="file" class="form-control" name="file_import" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
            <div class="col-md-4">
                <button class="btn btn-success w-100">Import Sekarang</button>
            </div>
        </form>
        <hr>
        <h2 class="h6">Kolom template (baris pertama)</h2>
        <p class="small text-muted mb-2"><?= htmlspecialchars(implode(' | ', $templateLabels)) ?></p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="?template=xlsx">Unduh template Excel</a>
            <a class="btn btn-outline-secondary btn-sm" href="/santri/mukimin.php">Kembali ke Data Mukimin</a>
            <a class="btn btn-outline-secondary btn-sm" href="/santri/mukimin_export.php">Unduh data saat ini</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
