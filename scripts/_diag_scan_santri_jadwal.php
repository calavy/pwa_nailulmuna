<?php

declare(strict_types=1);

/**
 * Simulasi resolve jadwal absensi santri (Multi Scan / portal) untuk satu kode QR.
 *
 *   php scripts/_diag_scan_santri_jadwal.php KODE_QR
 *   php scripts/_diag_scan_santri_jadwal.php KODE_QR 2026-09-22 10:30:00
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/presensi_scan_client.php';
require_once __DIR__ . '/../helpers/presensi_scan_jadwal.php';
require_once __DIR__ . '/../helpers/presensi_scan_portal_json.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/santri_kartu_sementara.php';

$qr = trim((string) ($argv[1] ?? ''));
if ($qr === '') {
    fwrite(STDERR, "Usage: php scripts/_diag_scan_santri_jadwal.php KODE_QR [YYYY-MM-DD HH:MM:SS]\n");
    exit(1);
}

$tanggal = trim((string) ($argv[2] ?? ''));
$jam = trim((string) ($argv[3] ?? ''));
$post = ['kode_qr' => $qr, 'scan_source' => 'camera'];
if ($tanggal !== '' && $jam !== '') {
    $post['scan_client_date'] = $tanggal;
    $post['scan_client_jam'] = $jam;
} else {
    $post['scan_client_at'] = date('c');
}

echo "=== Diag scan santri + jadwal ===\n\n";
echo "QR: {$qr}\n";

$santri = santri_resolve_by_scan_code($pdo, $qr);
if (!is_array($santri)) {
    echo "Santri: TIDAK DITEMUKAN\n";
    exit(2);
}
$loadFull = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$loadFull->execute(['id' => (int) ($santri['id'] ?? 0)]);
$row = $loadFull->fetch(PDO::FETCH_ASSOC) ?: $santri;
echo 'Santri: ' . (string) ($row['nama_santri'] ?? '') . ' · tingkatan ' . (string) ($row['tingkatan'] ?? '-') . "\n";

$clock = presensi_scan_resolve_clock($post);
echo 'Clock: ' . $clock['tanggal'] . ' ' . $clock['jam']
    . ' from_client=' . ($clock['from_client'] ? 'yes' : 'no')
    . ' skew=' . (!empty($clock['from_client_skew']) ? 'yes' : 'no') . "\n";

$active = presensi_scan_active_slots_at($pdo, $clock['tanggal'], $clock['jam']);
echo 'Slot aktif global (' . count($active) . "):\n";
foreach ($active as $slot) {
    echo '  - ' . presensi_scan_marquee_slot_label(is_array($slot) ? $slot : []) . "\n";
}

pkpps_ensure_schema($pdo);
$kegiatan = activity_for_pkpps_santri($pdo, (int) $row['id'], $clock['tanggal'], $clock['jam']);
if (!$kegiatan) {
    $kegiatan = activity_for_tingkatan($pdo, (string) ($row['tingkatan'] ?? ''), $clock['tanggal'], $clock['jam']);
}
if ($kegiatan) {
    echo "\nResolve kegiatan: " . (string) ($kegiatan['nama_kegiatan'] ?? '') . ' (' . substr((string) ($kegiatan['jam_mulai'] ?? ''), 0, 5) . ")\n";
} else {
    echo "\nResolve kegiatan: (kosong) — Multi Scan akan warning jika tidak ada kegiatan khusus\n";
    echo 'Pesan contoh: ' . presensi_scan_format_active_slots_list($active) . "\n";
}

$result = presensi_scan_portal_json($pdo, $post);
echo "\nAPI presensi_scan_portal_json:\n";
echo '  type: ' . (string) ($result['type'] ?? '') . "\n";
echo '  message: ' . (string) ($result['message'] ?? '') . "\n";
if (!empty($result['scan_clock'])) {
    echo '  scan_clock: ' . json_encode($result['scan_clock'], JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($result['active_slots'])) {
    echo '  active_slots: ' . json_encode($result['active_slots'], JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\nTip: tidak bip = masalah kamera/decode; ada pesan \"Kartu terbaca\" = QR OK, cek tingkatan/jadwal.\n";
