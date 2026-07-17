<?php

declare(strict_types=1);

/**
 * Migrasi: pindahkan jurnal saku dari kas pondok (1101/1102) ke Kas Titipan Saku (1103).
 *
 * - Jurnal pembayaran yang memuat kredit 2101 (titipan saku): porsi debit saku → 1103
 * - Jurnal cashless_setor yang kredit 1101/1102 → 1103
 * - Backfill jurnal cashless_pengeluaran (Dr 2101 / Cr 1103) bila belum ada
 * - Sync saldo cashless_accounts
 *
 * Jalankan:
 *   php scripts/migrate_saku_kas_titipan_1103.php           # dry-run
 *   php scripts/migrate_saku_kas_titipan_1103.php --apply   # eksekusi
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_jurnal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

keuangan_ensure_schema_deferred($pdo);
ensure_keuangan_jurnal_tables($pdo);
cashless_koperasi_ensure_schema($pdo);

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;
$kasSaku = keuangan_coa_kas_titipan_saku();

echo "=== Migrasi Kas Titipan Saku ({$kasSaku}) ===\n";
echo 'Mode: ' . ($dryRun ? 'DRY-RUN (tanpa perubahan)' : 'APPLY') . "\n\n";

$stats = [
    'pembayaran_repost' => 0,
    'setor_update' => 0,
    'pengeluaran_backfill' => 0,
    'sync_accounts' => 0,
];

// --- 1) Repost jurnal pembayaran yang punya saku (Cr 2101) ---
if (table_exists($pdo, 'akuntansi_jurnal_umum') && table_exists($pdo, 'keuangan_pembayaran')) {
    $ids = $pdo->query("
        SELECT DISTINCT j.ref_id
        FROM akuntansi_jurnal_umum j
        WHERE j.ref_type = 'pembayaran'
          AND j.kode_akun = '2101'
          AND j.kredit > 0
          AND j.ref_id IS NOT NULL
        ORDER BY j.ref_id ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    echo 'Pembayaran dengan jurnal saku (2101): ' . count($ids) . "\n";

    foreach ($ids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        // Sudah ada debit 1103? skip
        $st = $pdo->prepare("
            SELECT 1 FROM akuntansi_jurnal_umum
            WHERE ref_type = 'pembayaran' AND ref_id = :id AND kode_akun = :kas AND debit > 0
            LIMIT 1
        ");
        $st->execute(['id' => $pid, 'kas' => $kasSaku]);
        if ($st->fetchColumn()) {
            continue;
        }

        $pay = $pdo->prepare('SELECT id, tanggal_bayar, akun_id, total_nominal, kategori_filter FROM keuangan_pembayaran WHERE id = :id LIMIT 1');
        $pay->execute(['id' => $pid]);
        $row = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $details = [];
        if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
            $dst = $pdo->prepare('SELECT pos_slug AS slug, pos_nama AS nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id');
            $dst->execute(['id' => $pid]);
            foreach ($dst->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
                $details[] = [
                    'slug' => (string) ($d['slug'] ?? ''),
                    'nama' => (string) ($d['nama'] ?? ''),
                    'nominal' => (int) round((float) ($d['nominal'] ?? 0)),
                ];
            }
        }
        $hasSaku = false;
        foreach ($details as $d) {
            if (strtolower(trim($d['slug'])) === 'saku' && $d['nominal'] > 0) {
                $hasSaku = true;
                break;
            }
        }
        if (!$hasSaku) {
            continue;
        }

        echo "  pembayaran #{$pid} → repost jurnal (saku → {$kasSaku})\n";
        $stats['pembayaran_repost']++;
        if ($dryRun) {
            continue;
        }
        keuangan_jurnal_delete_by_ref($pdo, 'pembayaran', $pid);
        keuangan_jurnal_pembayaran(
            $pdo,
            $pid,
            (string) ($row['tanggal_bayar'] ?? date('Y-m-d')),
            (int) ($row['akun_id'] ?? 0),
            (int) round((float) ($row['total_nominal'] ?? 0)),
            $details,
            (string) ($row['kategori_filter'] ?? ''),
            0
        );
    }
}

// --- 2) Setor: kredit 1101/1102 → 1103 ---
if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
    $st = $pdo->query("
        SELECT id, kode_akun, kredit, ref_id
        FROM akuntansi_jurnal_umum
        WHERE ref_type = 'cashless_setor'
          AND kode_akun IN ('1101','1102')
          AND kredit > 0
    ");
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    echo 'Baris jurnal setor ke kas pondok: ' . count($rows) . "\n";
    foreach ($rows as $r) {
        $jid = (int) ($r['id'] ?? 0);
        echo "  setor jurnal #{$jid} {$r['kode_akun']} → {$kasSaku} (Rp " . number_format((int) $r['kredit'], 0, ',', '.') . ")\n";
        $stats['setor_update']++;
        if ($dryRun || $jid <= 0) {
            continue;
        }
        $upd = $pdo->prepare('UPDATE akuntansi_jurnal_umum SET kode_akun = :k, nama_akun = :n WHERE id = :id');
        $upd->execute([
            'k' => $kasSaku,
            'n' => keuangan_coa_nama($pdo, $kasSaku),
            'id' => $jid,
        ]);
    }
}

// --- 3) Backfill jurnal pengeluaran manual ---
if (table_exists($pdo, 'cashless_transactions')) {
    $st = $pdo->query("
        SELECT ct.id, ct.nominal, DATE(ct.tanggal) AS tgl, ct.keterangan, ct.created_by
        FROM cashless_transactions ct
        WHERE UPPER(ct.jenis) = 'PENGELUARAN'
          AND NOT EXISTS (
              SELECT 1 FROM akuntansi_jurnal_umum j
              WHERE j.ref_type = 'cashless_pengeluaran' AND j.ref_id = ct.id
          )
        ORDER BY ct.id ASC
    ");
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    echo 'Pengeluaran manual tanpa jurnal: ' . count($rows) . "\n";
    foreach ($rows as $r) {
        $txId = (int) ($r['id'] ?? 0);
        $nom = (int) round((float) ($r['nominal'] ?? 0));
        if ($txId <= 0 || $nom <= 0) {
            continue;
        }
        echo "  PENGELUARAN #{$txId} → Dr2101/Cr{$kasSaku} Rp " . number_format($nom, 0, ',', '.') . "\n";
        $stats['pengeluaran_backfill']++;
        if ($dryRun) {
            continue;
        }
        cashless_jurnal_pengeluaran_manual(
            $pdo,
            $txId,
            (string) ($r['tgl'] ?? date('Y-m-d')),
            $nom,
            (int) ($r['created_by'] ?? 0),
            (string) ($r['keterangan'] ?? '')
        );
    }
}

// --- 4) Sync saldo ---
if (!$dryRun) {
    $stats['sync_accounts'] = cashless_sync_all_account_balances($pdo);
    echo "Sync cashless_accounts: {$stats['sync_accounts']} baris\n";
} else {
    echo "Sync cashless_accounts: (dilewati di dry-run)\n";
}

echo "\n=== Ringkasan ===\n";
foreach ($stats as $k => $v) {
    echo "{$k}: {$v}\n";
}

if ($dryRun) {
    echo "\nJalankan ulang dengan --apply untuk menulis perubahan.\n";
} else {
    echo "\nSelesai. Verifikasi dengan:\n";
    echo "  php scripts/verify_saku_cashless_audit.php\n";
}
