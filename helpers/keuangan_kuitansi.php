<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/pondok_stampel.php';

function keuangan_kuitansi_jenis_periode_label(string $jenis): string
{
    return match (strtoupper(trim($jenis))) {
        'AWAL_TAHUN' => 'Pembayaran awal tahun ajaran',
        default => 'Tagihan bulanan',
    };
}

function keuangan_kuitansi_metode_label(string $metode): string
{
    $m = strtoupper(trim($metode));
    if ($m === '') {
        return 'Tunai / kas pondok';
    }

    return match ($m) {
        'TUNAI', 'KAS' => 'Tunai',
        'TRANSFER', 'BANK' => 'Transfer bank',
        'QRIS' => 'QRIS',
        default => ucwords(strtolower(str_replace('_', ' ', $metode))),
    };
}

/** Terbilang rupiah (untuk kuitansi orang tua). */
function keuangan_nominal_terbilang(int $nominal): string
{
    $nominal = max(0, $nominal);
    if ($nominal === 0) {
        return 'nol rupiah';
    }

    $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    $toWords = static function (int $n) use (&$toWords, $satuan): string {
        if ($n < 12) {
            return $satuan[$n];
        }
        if ($n < 20) {
            return $toWords($n - 10) . ' belas';
        }
        if ($n < 100) {
            $puluh = (int) floor($n / 10);
            $sisa = $n % 10;

            return $toWords($puluh) . ' puluh' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        if ($n < 200) {
            return 'seratus' . ($n > 100 ? ' ' . $toWords($n - 100) : '');
        }
        if ($n < 1000) {
            $ratus = (int) floor($n / 100);
            $sisa = $n % 100;

            return $toWords($ratus) . ' ratus' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        if ($n < 2000) {
            return 'seribu' . ($n > 1000 ? ' ' . $toWords($n - 1000) : '');
        }
        if ($n < 1_000_000) {
            $ribu = (int) floor($n / 1000);
            $sisa = $n % 1000;

            return $toWords($ribu) . ' ribu' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        if ($n < 1_000_000_000) {
            $juta = (int) floor($n / 1_000_000);
            $sisa = $n % 1_000_000;

            return $toWords($juta) . ' juta' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        $milyar = (int) floor($n / 1_000_000_000);
        $sisa = $n % 1_000_000_000;

        return $toWords($milyar) . ' milyar' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
    };

    return trim($toWords($nominal)) . ' rupiah';
}

/**
 * @return array<string,mixed>|null
 */
function keuangan_kuitansi_context(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return null;
    }

    $santriNameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $santriLevelExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
    $joinKelas = '';
    if (!column_exists($pdo, 'santri', 'tingkatan') && column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
        $joinKelas = ' LEFT JOIN kelas k ON k.id = s.kelas_id ';
        $santriLevelExpr = 'k.nama_kelas';
    }

    $stmt = $pdo->prepare("
        SELECT p.*, s.nis, {$santriNameExpr} AS nama_santri, {$santriLevelExpr} AS tingkatan, u.nama AS nama_petugas
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN users u ON u.id = p.created_by
        {$joinKelas}
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $details = [];
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $detStmt = $pdo->prepare('SELECT pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC');
        $detStmt->execute(['id' => $id]);
        $details = $detStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return keuangan_kuitansi_context_build($pdo, $id, $row, $details);
}

/**
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $details
 * @return array<string,mixed>
 */
function keuangan_kuitansi_context_build(PDO $pdo, int $id, array $row, array $details): array
{
    $bulanTagihan = (int) ($row['bulan_tagihan'] ?? 0);
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    $khTersimpan = trim((string) ($row['kalender_hijriyah'] ?? ''));
    if ($bulanTagihan > 0 && $khTersimpan !== '') {
        $slotKh = pondok_slot_dari_kalender_hijriyah(pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts), $khTersimpan);
        $periodeLabel = (string) ($slotKh['label'] ?? $khTersimpan);
    } elseif ($bulanTagihan > 0 && $tm > 0) {
        $periodeLabel = pondok_bulan_label($pdo, $bulanTagihan, $tm, $ts);
    } else {
        $periodeLabel = 'Awal tahun ajaran';
    }

    $nominalTotal = (int) round((float) ($row['total_nominal'] ?? 0));
    $detailRows = [];
    foreach ($details as $d) {
        $nom = (int) round((float) ($d['nominal'] ?? 0));
        $detailRows[] = [
            'nama' => (string) ($d['pos_nama'] ?? ''),
            'nominal' => $nom,
            'nominal_fmt' => keuangan_format_rupiah($nom),
        ];
    }

    $verifySecret = (string) app_setting($pdo, 'kuitansi_verify_secret', 'pwa_nailulmuna_secret');
    $tanggalBayar = (string) ($row['tanggal_bayar'] ?? '');
    $verifySig = substr(hash('sha256', $id . '|' . $tanggalBayar . '|' . (string) ($row['total_nominal'] ?? '') . '|' . $verifySecret), 0, 16);

    return [
        'id' => $id,
        'no_kuitansi' => 'KW-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        'tanggal_bayar' => $tanggalBayar,
        'tanggal_bayar_fmt' => app_format_tanggal_id($tanggalBayar),
        'nis' => (string) ($row['nis'] ?? ''),
        'nama_santri' => (string) ($row['nama_santri'] ?? ''),
        'tingkatan' => trim((string) ($row['tingkatan'] ?? '')),
        'jenis_periode' => (string) ($row['jenis_periode'] ?? ''),
        'jenis_periode_label' => keuangan_kuitansi_jenis_periode_label((string) ($row['jenis_periode'] ?? '')),
        'periode_label' => $periodeLabel,
        'metode_bayar' => trim((string) ($row['metode_bayar'] ?? '')),
        'metode_bayar_label' => keuangan_kuitansi_metode_label((string) ($row['metode_bayar'] ?? '')),
        'keterangan' => trim((string) ($row['keterangan'] ?? '')),
        'nama_petugas' => trim((string) ($row['nama_petugas'] ?? '')),
        'details' => $detailRows,
        'nominal_total' => $nominalTotal,
        'nominal_total_fmt' => keuangan_format_rupiah($nominalTotal),
        'nominal_terbilang' => keuangan_nominal_terbilang($nominalTotal),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'alamat_ponpes' => trim((string) app_setting($pdo, 'alamat_ponpes', '')),
        'jenis_pendidikan' => trim((string) app_setting($pdo, 'jenis_pendidikan', '')),
        'logo' => app_pondok_logo_href($pdo, false),
        'stampel' => pondok_stampel_href($pdo, 'kuitansi'),
        'verify_url' => app_public_url() . app_href('/keuangan/verifikasi_kuitansi.php?id=' . $id . '&sig=' . $verifySig),
        'footer_note' => 'Simpan bukti ini sebagai arsip pembayaran. Terima kasih.',
    ];
}
