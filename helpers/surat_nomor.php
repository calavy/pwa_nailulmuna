<?php

declare(strict_types=1);

/**
 * Penomoran surat resmi berjenis (berurut per tahun).
 * Format: {seq4}/{KODE}/{ROM}/{TAHUN} — mis. 0007/SIZN.S/VI/2026
 */

function surat_nomor_bulan_romawi(int $bulan): string
{
    $map = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    return $map[max(1, min(12, $bulan)) - 1] ?? 'I';
}

/** Kode tengah pada nomor surat (sesuai permintaan administrasi). */
function surat_nomor_prefix_for_jenis(string $jenis): string
{
    return match ($jenis) {
        'izin_keluar' => 'SIZN.S',
        'izin_tugas' => 'IZN.T',
        'izin_sakit' => 'IZN.S',
        'izin_tetap' => 'SIZN.T',
        'sp1' => 'S.SP1.',
        'sp2' => 'S.SP2.',
        'surat_keluar' => 'SKLR.S',
        'surat_tanggungan' => 'STNG.S',
        default => 'DOC',
    };
}

/** Map jenis_izin perizinan → kunci penomoran. */
function surat_nomor_jenis_from_izin(string $jenisIzin): string
{
    $u = strtoupper(trim($jenisIzin));

    return match ($u) {
        'TUGAS', 'PULANG' => 'izin_tugas',
        'SAKIT' => 'izin_sakit',
        default => 'izin_keluar',
    };
}

function ensure_surat_nomor_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS surat_nomor_seq (
            jenis_kode VARCHAR(40) NOT NULL,
            tahun INT NOT NULL,
            seq_terakhir INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (jenis_kode, tahun)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS surat_nomor_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jenis_kode VARCHAR(40) NOT NULL,
            ref_key VARCHAR(160) NOT NULL,
            nomor_surat VARCHAR(180) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_surat_nomor_cache (jenis_kode, ref_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    if (table_exists($pdo, 'perizinan') && !column_exists($pdo, 'perizinan', 'nomor_surat')) {
        try {
            $pdo->exec('ALTER TABLE perizinan ADD COLUMN nomor_surat VARCHAR(180) NULL');
        } catch (PDOException $e) {
            $m = strtolower($e->getMessage());
            if (!str_contains($m, 'duplicate') && !str_contains($m, '1060')) {
                throw $e;
            }
        }
    }
}

/**
 * Ambil nomor dari cache (cetak ulang) atau alokasi baru.
 */
function surat_nomor_ambil_atau_buat(PDO $pdo, string $jenisKode, string $refKey, ?int $tahun = null, ?int $bulan = null): string
{
    ensure_surat_nomor_schema($pdo);
    $tahun = $tahun ?? (int) date('Y');
    $bulan = $bulan ?? (int) date('n');
    $st = $pdo->prepare('SELECT nomor_surat FROM surat_nomor_cache WHERE jenis_kode = :j AND ref_key = :r LIMIT 1');
    $st->execute(['j' => $jenisKode, 'r' => $refKey]);
    $ex = $st->fetchColumn();
    if (is_string($ex) && trim($ex) !== '') {
        return trim($ex);
    }

    $prefix = surat_nomor_prefix_for_jenis($jenisKode);
    $roman = surat_nomor_bulan_romawi($bulan);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO surat_nomor_seq (jenis_kode, tahun, seq_terakhir) VALUES (:j, :t, 0)
            ON DUPLICATE KEY UPDATE seq_terakhir = seq_terakhir')->execute(['j' => $jenisKode, 't' => $tahun]);
        $lk = $pdo->prepare('SELECT seq_terakhir FROM surat_nomor_seq WHERE jenis_kode = :j AND tahun = :t FOR UPDATE');
        $lk->execute(['j' => $jenisKode, 't' => $tahun]);
        $cur = (int) ($lk->fetchColumn() ?: 0);
        $next = $cur + 1;
        $pdo->prepare('UPDATE surat_nomor_seq SET seq_terakhir = :n WHERE jenis_kode = :j AND tahun = :t')->execute([
            'n' => $next, 'j' => $jenisKode, 't' => $tahun,
        ]);
        $seqP = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $nomor = $seqP . '/' . $prefix . '/' . $roman . '/' . $tahun;
        $pdo->prepare('INSERT INTO surat_nomor_cache (jenis_kode, ref_key, nomor_surat) VALUES (:j, :r, :n)')->execute([
            'j' => $jenisKode, 'r' => $refKey, 'n' => $nomor,
        ]);
        $pdo->commit();

        return $nomor;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Set seq_terakhir manual (admin). */
function surat_nomor_set_seq(PDO $pdo, string $jenisKode, int $tahun, int $seq): void
{
    ensure_surat_nomor_schema($pdo);
    $seq = max(0, $seq);
    $pdo->prepare('INSERT INTO surat_nomor_seq (jenis_kode, tahun, seq_terakhir) VALUES (:j, :t, :s)
        ON DUPLICATE KEY UPDATE seq_terakhir = :s2')->execute([
        'j' => $jenisKode, 't' => $tahun, 's' => $seq, 's2' => $seq,
    ]);
}

/** @return list<array{jenis_kode:string,tahun:int,seq_terakhir:int}> */
function surat_nomor_seq_semua(PDO $pdo): array
{
    ensure_surat_nomor_schema($pdo);

    return $pdo->query('SELECT jenis_kode, tahun, seq_terakhir FROM surat_nomor_seq ORDER BY tahun DESC, jenis_kode ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
