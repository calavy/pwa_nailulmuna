<?php

declare(strict_types=1);

/**
 * Diagnosa fan-out WA izin → pembimbing (read-only).
 *
 *   php scripts/_diag_wa_izin_pembimbing_fanout.php
 *   php scripts/_diag_wa_izin_pembimbing_fanout.php --santri=123
 *   php scripts/_diag_wa_izin_pembimbing_fanout.php --izin=456
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';

$santriId = 0;
$izinId = 0;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--santri=(\d+)$/', (string) $arg, $m)) {
        $santriId = (int) $m[1];
    }
    if (preg_match('/^--izin=(\d+)$/', (string) $arg, $m)) {
        $izinId = (int) $m[1];
    }
}

if ($izinId > 0 && table_exists($pdo, 'perizinan')) {
    $st = $pdo->prepare('SELECT id, santri_id, jenis_izin, approval_status, approved_at FROM perizinan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $santriId = (int) ($row['santri_id'] ?? 0);
        echo "Izin #{$izinId} santri_id={$santriId} jenis=" . ($row['jenis_izin'] ?? '-') . ' status=' . ($row['approval_status'] ?? '-') . "\n";
    }
}

if ($santriId <= 0 && table_exists($pdo, 'perizinan')) {
    $st = $pdo->query('
        SELECT i.id, i.santri_id, i.approved_at
        FROM perizinan i
        WHERE i.approval_status = "DISETUJUI"
        ORDER BY i.approved_at DESC, i.id DESC
        LIMIT 1
    ');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (is_array($row)) {
        $izinId = (int) ($row['id'] ?? 0);
        $santriId = (int) ($row['santri_id'] ?? 0);
        echo "Sampel izin terakhir disetujui: #{$izinId} santri_id={$santriId}\n";
    }
}

echo "\n=== WA Izin Pembimbing Fan-out ===\n\n";
echo 'Mode sumber: ' . perizinan_pembimbing_wa_sumber_mode($pdo) . "\n";
echo 'Max recipients: ' . perizinan_pembimbing_wa_max_recipients($pdo) . "\n";
echo 'Fallback scope: ' . (perizinan_pembimbing_wa_fallback_scope_enabled($pdo) ? 'ON' : 'OFF') . "\n";
echo 'wa_izin_pembimbing_enabled: ' . app_setting($pdo, 'wa_izin_pembimbing_enabled', '1') . "\n\n";

if ($santriId <= 0) {
    echo "Tidak ada santri_id. Gunakan --santri=ID atau --izin=ID.\n";
    exit(0);
}

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$st = $pdo->prepare('SELECT id, ' . $nameCol . ' AS nama_santri, nis, tingkatan FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $santriId]);
$santri = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($santri)) {
    echo "Santri #{$santriId} tidak ditemukan.\n";
    exit(1);
}

echo 'Santri: ' . ($santri['nama_santri'] ?? '-') . ' (#' . $santriId . ') tingkatan=' . ($santri['tingkatan'] ?? '-') . "\n\n";

$tingkatan = trim((string) ($santri['tingkatan'] ?? ''));
$pkpps = perizinan_pembimbing_ids_dari_pkpps($pdo, $santriId);
$setoran = perizinan_pembimbing_ids_dari_setoran($pdo, $tingkatan);
$setoranScoped = perizinan_pembimbing_ids_dari_setoran_scoped($pdo, $santriId, $tingkatan);
$jadwal = perizinan_pembimbing_ids_dari_jadwal_legacy($pdo, $tingkatan);
$scope = perizinan_pembimbing_ids_dari_scope($pdo, $santriId);
$final = perizinan_pembimbing_ids_untuk_santri($pdo, $santriId);
$targets = perizinan_pembimbing_wa_targets($pdo, $santriId);

echo 'Blok PKPPS: ' . count($pkpps) . ' id → ' . implode(',', $pkpps) . "\n";
echo 'Blok setoran (tingkatan): ' . count($setoran) . ' id → ' . implode(',', $setoran) . "\n";
echo 'Blok setoran (scoped santri): ' . count($setoranScoped) . ' id → ' . implode(',', $setoranScoped) . "\n";
echo 'Blok jadwal (legacy): ' . count($jadwal) . ' id → ' . implode(',', $jadwal) . "\n";
echo 'Blok scope dashboard: ' . count($scope) . ' id → ' . implode(',', $scope) . "\n";
echo 'Resolver final (setelah mode + cap): ' . count($final) . ' id → ' . implode(',', $final) . "\n";
echo 'Nomor WA unik siap kirim: ' . count($targets) . ' → ' . implode(', ', $targets) . "\n\n";

if ($izinId > 0 && table_exists($pdo, 'wa_dispatch_log')) {
    $prefix = 'izin:' . $izinId . ':pembimbing:approved';
    $stL = $pdo->prepare('
        SELECT dedup_key, target_phone, http_ok, sent_at
        FROM wa_dispatch_log
        WHERE dedup_key LIKE :p
        ORDER BY sent_at DESC
        LIMIT 50
    ');
    $stL->execute(['p' => $prefix . '%']);
    $logs = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo "wa_dispatch_log (prefix {$prefix}): " . count($logs) . " baris\n";
    foreach ($logs as $log) {
        echo '  - ' . ($log['dedup_key'] ?? '') . ' → ' . ($log['target_phone'] ?? '')
            . ' ok=' . ($log['http_ok'] ?? '') . ' @' . ($log['sent_at'] ?? '') . "\n";
    }
    $phones = [];
    foreach ($logs as $log) {
        $ph = (string) ($log['target_phone'] ?? '');
        if ($ph !== '') {
            $phones[$ph] = ($phones[$ph] ?? 0) + 1;
        }
    }
    $dup = array_filter($phones, static fn(int $c): bool => $c > 1);
    if ($dup !== []) {
        echo "\nCatatan: target_phone sama muncul >1 kali (kemungkin duplikat kirim, bukan fan-out):\n";
        foreach ($dup as $ph => $c) {
            echo "  {$ph}: {$c}x\n";
        }
    } elseif (count($logs) > count($final)) {
        echo "\nCatatan: banyak baris log dengan target berbeda = fan-out lebar (bukan duplikat ke nomor sama).\n";
    }
}

echo "\nDone.\n";
