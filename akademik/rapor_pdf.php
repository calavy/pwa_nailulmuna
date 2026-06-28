<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/akademik_rapor_pdf.php';

require_roles(['admin', 'pengurus']);

$pdo = pondok_pdo();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Rapor tidak ditemukan.');
}

$st = $pdo->prepare('
    SELECT r.pdf_path, r.pdf_original_name, r.judul_periode, s.nis, s.nama_santri
    FROM akademik_rapor r
    INNER JOIN santri s ON s.id = r.santri_id
    WHERE r.id = :id
    LIMIT 1
');
$st->execute(['id' => $id]);
$rapor = $st->fetch(PDO::FETCH_ASSOC);
if (!$rapor || trim((string) ($rapor['pdf_path'] ?? '')) === '') {
    http_response_code(404);
    exit('PDF rapor belum diunggah.');
}

$download = isset($_GET['dl']) && (string) $_GET['dl'] !== '0';
akademik_rapor_pdf_stream($rapor, $download);
