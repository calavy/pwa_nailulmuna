<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ensure_keuangan_jurnal_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS akuntansi_chart_of_accounts (
            kode_akun VARCHAR(30) PRIMARY KEY,
            nama_akun VARCHAR(150) NOT NULL,
            kelompok_laporan VARCHAR(40) NOT NULL DEFAULT 'ASET',
            sifat_akun ENUM('DEBIT','KREDIT') NOT NULL DEFAULT 'DEBIT',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS akuntansi_jurnal_umum (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            kode_akun VARCHAR(30) NOT NULL,
            nama_akun VARCHAR(150) NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0,
            kredit DECIMAL(14,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            ref_type VARCHAR(40) NULL,
            ref_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_jurnal_tanggal (tanggal),
            INDEX idx_jurnal_ref (ref_type, ref_id),
            INDEX idx_jurnal_akun (kode_akun)
        )
    ");
    if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
        $pdo->exec('ALTER TABLE akuntansi_jurnal_umum ADD COLUMN IF NOT EXISTS ref_type VARCHAR(40) NULL');
        $pdo->exec('ALTER TABLE akuntansi_jurnal_umum ADD COLUMN IF NOT EXISTS ref_id INT NULL');
    }
    if (table_exists($pdo, 'akuntansi_aset_tetap')) {
        $pdo->exec('ALTER TABLE akuntansi_aset_tetap ADD COLUMN IF NOT EXISTS ref_pengeluaran_id INT NULL');
    }

    keuangan_seed_chart_of_accounts($pdo);
}

function keuangan_seed_chart_of_accounts(PDO $pdo): void
{
    if (!table_exists($pdo, 'akuntansi_chart_of_accounts')) {
        return;
    }
    $defaults = [
        ['1101', 'Kas', 'ASET', 'DEBIT'],
        ['1102', 'Bank', 'ASET', 'DEBIT'],
        ['1201', 'Aset Tetap', 'ASET', 'DEBIT'],
        ['1209', 'Akumulasi Penyusutan Aset Tetap', 'ASET', 'KREDIT'],
        ['2101', 'Titipan Saku Santri (Cashless)', 'LIABILITAS', 'KREDIT'],
        ['2102', 'Utang/Pinjaman Internal', 'LIABILITAS', 'KREDIT'],
        ['3101', 'Aset Neto Tanpa Pembatasan', 'ASET_NETO', 'KREDIT'],
        ['4101', 'Pendapatan Syahriyah', 'ASET_NETO', 'KREDIT'],
        ['4102', 'Pendapatan Makan', 'ASET_NETO', 'KREDIT'],
        ['4103', 'Pendapatan Awal Tahun Santri', 'ASET_NETO', 'KREDIT'],
        ['4199', 'Pendapatan Santri Lainnya', 'ASET_NETO', 'KREDIT'],
        ['4201', 'Pendapatan Donasi/Hibah', 'ASET_NETO', 'KREDIT'],
        ['5101', 'Beban Operasional', 'BEBAN', 'DEBIT'],
        ['5102', 'Beban Belanja Modal (CapEx)', 'BEBAN', 'DEBIT'],
        ['6101', 'Beban Penyusutan', 'BEBAN', 'DEBIT'],
    ];
    $ins = $pdo->prepare('
        INSERT IGNORE INTO akuntansi_chart_of_accounts (kode_akun, nama_akun, kelompok_laporan, sifat_akun, is_active)
        VALUES (:kode, :nama, :kelompok, :sifat, 1)
    ');
    foreach ($defaults as [$kode, $nama, $kelompok, $sifat]) {
        $ins->execute(['kode' => $kode, 'nama' => $nama, 'kelompok' => $kelompok, 'sifat' => $sifat]);
    }
}

function keuangan_coa_nama(PDO $pdo, string $kodeAkun): string
{
    if (!table_exists($pdo, 'akuntansi_chart_of_accounts')) {
        return $kodeAkun;
    }
    $stmt = $pdo->prepare('SELECT nama_akun FROM akuntansi_chart_of_accounts WHERE kode_akun = :k LIMIT 1');
    $stmt->execute(['k' => $kodeAkun]);

    return (string) ($stmt->fetchColumn() ?: $kodeAkun);
}

/** Kode COA kas/bank dari akun operasional keuangan_akun. */
function keuangan_akun_coa_kode(PDO $pdo, int $akunId): string
{
    if ($akunId <= 0 || !table_exists($pdo, 'keuangan_akun')) {
        return '1101';
    }
    $stmt = $pdo->prepare('SELECT jenis_akun FROM keuangan_akun WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $akunId]);
    $jenis = strtoupper((string) ($stmt->fetchColumn() ?: 'KAS'));

    return $jenis === 'BANK' ? '1102' : '1101';
}

function keuangan_jurnal_delete_by_ref(PDO $pdo, string $refType, int $refId): void
{
    if ($refId <= 0 || !table_exists($pdo, 'akuntansi_jurnal_umum')) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM akuntansi_jurnal_umum WHERE ref_type = :t AND ref_id = :id');
    $stmt->execute(['t' => $refType, 'id' => $refId]);
}

function keuangan_jurnal_ref_exists(PDO $pdo, string $refType, int $refId): bool
{
    if ($refId <= 0 || !table_exists($pdo, 'akuntansi_jurnal_umum')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM akuntansi_jurnal_umum WHERE ref_type = :t AND ref_id = :id LIMIT 1');
    $stmt->execute(['t' => $refType, 'id' => $refId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @param list<array{kode_akun:string,debit:int,kredit:int}> $lines
 */
function keuangan_jurnal_post(PDO $pdo, string $tanggal, array $lines, string $refType, int $refId, int $userId, string $keterangan = ''): bool
{
    ensure_keuangan_jurnal_tables($pdo);
    if ($refId > 0 && keuangan_jurnal_ref_exists($pdo, $refType, $refId)) {
        return false;
    }
    if ($lines === []) {
        return false;
    }

    $totalDebit = 0;
    $totalKredit = 0;
    foreach ($lines as $line) {
        $totalDebit += (int) ($line['debit'] ?? 0);
        $totalKredit += (int) ($line['kredit'] ?? 0);
    }
    if ($totalDebit !== $totalKredit || $totalDebit <= 0) {
        return false;
    }

    $ins = $pdo->prepare('
        INSERT INTO akuntansi_jurnal_umum (tanggal, kode_akun, nama_akun, debit, kredit, keterangan, ref_type, ref_id, created_by)
        VALUES (:tanggal, :kode_akun, :nama_akun, :debit, :kredit, :keterangan, :ref_type, :ref_id, :created_by)
    ');
    foreach ($lines as $line) {
        $kode = (string) ($line['kode_akun'] ?? '');
        $debit = (int) ($line['debit'] ?? 0);
        $kredit = (int) ($line['kredit'] ?? 0);
        if ($kode === '' || ($debit <= 0 && $kredit <= 0)) {
            continue;
        }
        $ins->execute([
            'tanggal' => $tanggal,
            'kode_akun' => $kode,
            'nama_akun' => keuangan_coa_nama($pdo, $kode),
            'debit' => $debit,
            'kredit' => $kredit,
            'keterangan' => $keterangan !== '' ? $keterangan : null,
            'ref_type' => $refType !== '' ? $refType : null,
            'ref_id' => $refId > 0 ? $refId : null,
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    }

    return true;
}

function keuangan_pendapatan_coa_for_pos(string $posSlug, string $kategoriFee): string
{
    if ($posSlug === 'syahriyah') {
        return '4101';
    }
    if ($posSlug === 'makan') {
        return '4102';
    }
    if ($kategoriFee === 'Awal Tahun') {
        return '4103';
    }

    return '4199';
}

/**
 * Jurnal pembayaran santri: Debit kas, Kredit pendapatan / titipan saku.
 *
 * @param list<array{slug:string,nama:string,nominal:int}> $detailRows
 */
function keuangan_jurnal_pembayaran(PDO $pdo, int $pembayaranId, string $tanggal, int $akunId, int $totalNominal, array $detailRows, string $kategoriFilter, int $userId): void
{
    if ($pembayaranId <= 0 || $totalNominal <= 0) {
        return;
    }
    $kasKode = keuangan_akun_coa_kode($pdo, $akunId);
    $lines = [['kode_akun' => $kasKode, 'debit' => $totalNominal, 'kredit' => 0]];

    foreach ($detailRows as $dr) {
        $nom = (int) ($dr['nominal'] ?? 0);
        if ($nom <= 0) {
            continue;
        }
        $slug = (string) ($dr['slug'] ?? '');
        if ($slug === 'saku') {
            $lines[] = ['kode_akun' => '2101', 'debit' => 0, 'kredit' => $nom];
        } else {
            $coa = keuangan_pendapatan_coa_for_pos($slug, $kategoriFilter);
            $lines[] = ['kode_akun' => $coa, 'debit' => 0, 'kredit' => $nom];
        }
    }

    $ket = 'Jurnal otomatis pembayaran santri #' . $pembayaranId;
    keuangan_jurnal_post($pdo, $tanggal, $lines, 'pembayaran', $pembayaranId, $userId, $ket);
}

function keuangan_jurnal_pengeluaran(PDO $pdo, int $pengeluaranId, string $tanggal, int $akunId, int $nominal, string $pos, int $userId): void
{
    if ($pengeluaranId <= 0 || $nominal <= 0) {
        return;
    }
    if (stripos($pos, 'Belanja Modal') === 0) {
        return;
    }
    $kasKode = keuangan_akun_coa_kode($pdo, $akunId);
    $bebanKode = '5101';
    $lines = [
        ['kode_akun' => $bebanKode, 'debit' => $nominal, 'kredit' => 0],
        ['kode_akun' => $kasKode, 'debit' => 0, 'kredit' => $nominal],
    ];
    keuangan_jurnal_post($pdo, $tanggal, $lines, 'pengeluaran', $pengeluaranId, $userId, 'Jurnal otomatis pengeluaran #' . $pengeluaranId);
}

function keuangan_jurnal_pemasukan(PDO $pdo, int $pemasukanId, string $tanggal, int $akunId, int $nominal, string $sumber, int $userId): void
{
    if ($pemasukanId <= 0 || $nominal <= 0) {
        return;
    }
    $kasKode = keuangan_akun_coa_kode($pdo, $akunId);
    $lines = [
        ['kode_akun' => $kasKode, 'debit' => $nominal, 'kredit' => 0],
        ['kode_akun' => '4201', 'debit' => 0, 'kredit' => $nominal],
    ];
    $ket = 'Jurnal otomatis pemasukan: ' . $sumber;
    keuangan_jurnal_post($pdo, $tanggal, $lines, 'pemasukan', $pemasukanId, $userId, $ket);
}

/**
 * CapEx: Debit aset tetap, Kredit kas — ref aset tetap.
 */
function keuangan_jurnal_capex_aset(PDO $pdo, int $asetId, string $tanggal, int $akunId, int $nominal, string $namaAset, int $userId): void
{
    if ($asetId <= 0 || $nominal <= 0) {
        return;
    }
    $kasKode = keuangan_akun_coa_kode($pdo, $akunId);
    $lines = [
        ['kode_akun' => '1201', 'debit' => $nominal, 'kredit' => 0],
        ['kode_akun' => $kasKode, 'debit' => 0, 'kredit' => $nominal],
    ];
    $ket = 'Perolehan aset tetap: ' . $namaAset;
    keuangan_jurnal_post($pdo, $tanggal, $lines, 'aset_tetap', $asetId, $userId, $ket);
}
