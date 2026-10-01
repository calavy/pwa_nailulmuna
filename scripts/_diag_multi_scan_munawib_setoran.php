<?php

declare(strict_types=1);

/**
 * Cek routing Multi Scan: munawib penerima setoran tanpa jadwal → dest setoran.
 *
 *   php scripts/_diag_multi_scan_munawib_setoran.php
 */

$root = dirname(__DIR__);
require_once $root . '/helpers/scan_smart_route.php';
require_once $root . '/helpers/login_pembimbing.php';

$fail = 0;

if (!function_exists('scan_smart_resolve_login_dest')) {
    echo "FAIL: scan_smart_resolve_login_dest tidak ada\n";
    exit(1);
}

$smartPhp = (string) file_get_contents($root . '/api/scan/smart.php');
if (!str_contains($smartPhp, 'scan_smart_resolve_login_dest')) {
    echo "FAIL: api/scan/smart.php belum memanggil scan_smart_resolve_login_dest\n";
    $fail++;
} else {
    echo "OK: smart.php memakai scan_smart_resolve_login_dest\n";
}

require_once $root . '/config/database.php';

if (!($pdo instanceof PDO)) {
    echo "SKIP: DB tidak tersedia — hanya cek statis di atas.\n";
    exit($fail > 0 ? 1 : 0);
}

munawib_ensure_schema($pdo);
require_once $root . '/helpers/akademik_setoran.php';
ensure_akademik_setoran_penerima_schema($pdo);

$st = $pdo->query('
    SELECT m.id, m.qr, m.nip
    FROM munawib m
    INNER JOIN akademik_penerima_setoran ps ON ps.peran = "munawib" AND ps.ref_id = m.id AND ps.is_aktif = 1
    WHERE COALESCE(m.is_aktif, 1) = 1
      AND (TRIM(COALESCE(m.qr, "")) != "" OR TRIM(COALESCE(m.nip, "")) != "")
    LIMIT 1
');
$row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;

if (!is_array($row)) {
    echo "SKIP: tidak ada munawib penerima setoran aktif dengan QR/NIP untuk uji DB.\n";
    exit($fail > 0 ? 1 : 0);
}

$mwId = (int) ($row['id'] ?? 0);
$code = trim((string) ($row['qr'] ?? ''));
if ($code === '') {
    $code = trim((string) ($row['nip'] ?? ''));
}

$tanggal = date('Y-m-d');
$jam = date('H:i:s');
$hasSlots = scan_smart_munawib_has_slots($pdo, $mwId, $tanggal, $jam);
$dest = scan_smart_resolve_login_dest($pdo, $code, '', $tanggal, $jam);

echo "Munawib #{$mwId}, jadwal berlangsung: " . ($hasSlots ? 'ya' : 'tidak') . "\n";
echo "scan_smart_resolve_login_dest (login_dest kosong): " . ($dest === '' ? '(kosong)' : $dest) . "\n";

if (!$hasSlots && $dest !== 'setoran') {
    echo "FAIL: diharapkan dest=setoran bila tidak ada jadwal dan penerima aktif\n";
    $fail++;
} elseif ($hasSlots && $dest === 'setoran') {
    echo "FAIL: tidak boleh auto-setoran bila munawib punya jadwal berlangsung\n";
    $fail++;
} else {
    echo "OK: resolusi dest sesuai aturan\n";
}

exit($fail > 0 ? 1 : 0);
