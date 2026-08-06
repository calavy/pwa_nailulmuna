<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/santri_wa.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';

$out = [];

// Santri dengan nomor WA wali
$st = $pdo->query("
    SELECT s.id, COALESCE(NULLIF(s.nama_santri,''), s.nama) AS nama
    FROM santri s
    ORDER BY s.id ASC
    LIMIT 200
");
$withWa = 0;
$withoutWa = 0;
$samples = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $phone = wa_otomatis_santri_wali_phone($pdo, (int) $r['id']);
    if ($phone !== '') {
        $withWa++;
        if (count($samples) < 3) {
            $samples[] = ['id' => (int) $r['id'], 'nama' => $r['nama'], 'phone' => $phone];
        }
    } else {
        $withoutWa++;
    }
}
$out['santri_wa_wali'] = ['with_phone' => $withWa, 'without_phone' => $withoutWa, 'samples' => $samples];

// Transaksi cashless terakhir
if (table_exists($pdo, 'cashless_transactions')) {
    $st2 = $pdo->query("
        SELECT ct.id, ct.santri_id, ct.nominal, ct.tanggal, ct.jenis
        FROM cashless_transactions ct
        ORDER BY ct.id DESC LIMIT 5
    ");
    $out['recent_cashless_trx'] = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $out['recent_cashless_trx'] = [];
}

// Alpa kandidat (>= min tier)
ensure_alpa_tier_tables($pdo);
$tiers = alpa_tier_list($pdo, true);
$minTh = $tiers !== [] ? min(array_map(static fn($t) => (int) $t['threshold'], $tiers)) : 999;
if (table_exists($pdo, 'presensi')) {
    $st3 = $pdo->prepare('
        SELECT s.id, s.nis, COALESCE(NULLIF(s.nama_santri,""), s.nama) AS nama, COUNT(p.id) AS alpa_count
        FROM presensi p INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.status_presensi = "ALPA"
        GROUP BY s.id, s.nis, nama HAVING alpa_count >= :min
        ORDER BY alpa_count DESC LIMIT 10
    ');
    $st3->execute(['min' => $minTh]);
    $out['alpa_candidates'] = $st3->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $out['alpa_candidates'] = [];
}

$out['pengurus_wa_empty'] = trim((string) app_setting($pdo, 'wa_pengurus', '')) === '';
$out['all_tier_wa_empty'] = array_reduce($tiers, static fn(bool $c, array $t): bool => $c && trim((string) ($t['wa'] ?? '')) === '', true);

file_put_contents(__DIR__ . '/_diag_wa_otomatis_extra.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "OK\n";
