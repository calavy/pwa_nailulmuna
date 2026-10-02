<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_tagihan_santri_html.php';

require_roles(['admin', 'pengurus']);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$inline = isset($_GET['inline']) && (string) $_GET['inline'] !== '0';
$download = !$inline && ((string) ($_GET['download'] ?? '1')) !== '0';

if ($santriId <= 0) {
    set_flash('error', 'Pilih santri untuk unduh rekap tagihan.');
    header('Location: ' . app_href('/pembayaran/kartu_syahriyah_santri.php'));
    exit;
}

$pack = keuangan_rekap_tagihan_santri_build($pdo, $santriId);
if ($pack === null) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/pembayaran/kartu_syahriyah_santri.php'));
    exit;
}

$filename = (string) ($pack['filename'] ?? 'tagihan-santri.html');
$html = keuangan_rekap_tagihan_santri_render_html($pack, $inline);

header('Content-Type: text/html; charset=utf-8');
if ($download) {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
}

echo $html;
exit;
