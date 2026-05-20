<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_neraca.php';
require_once __DIR__ . '/keuangan_jurnal.php';

/** @return list<string> */
function keuangan_inventaris_kategori_options(): array
{
    return ['Tanah', 'Bangunan', 'Kendaraan', 'Peralatan', 'Inventaris', 'Lainnya'];
}

function ensure_keuangan_inventaris_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS akuntansi_aset_tetap (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_inventaris VARCHAR(50) NULL,
            nama_aset VARCHAR(180) NOT NULL,
            kategori_aset VARCHAR(120) NOT NULL,
            lokasi VARCHAR(150) NULL,
            tanggal_perolehan DATE NOT NULL,
            harga_perolehan DECIMAL(14,2) NOT NULL DEFAULT 0,
            nilai_residu DECIMAL(14,2) NOT NULL DEFAULT 0,
            umur_manfaat_bulan INT NOT NULL DEFAULT 12,
            akumulasi_penyusutan DECIMAL(14,2) NOT NULL DEFAULT 0,
            last_penyusutan_periode VARCHAR(7) NULL,
            keterangan TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aset_kategori (kategori_aset),
            INDEX idx_aset_perolehan (tanggal_perolehan)
        )
    ");
    if (table_exists($pdo, 'akuntansi_aset_tetap')) {
        $pdo->exec('ALTER TABLE akuntansi_aset_tetap ADD COLUMN IF NOT EXISTS kode_inventaris VARCHAR(50) NULL');
        $pdo->exec('ALTER TABLE akuntansi_aset_tetap ADD COLUMN IF NOT EXISTS lokasi VARCHAR(150) NULL');
        $pdo->exec('ALTER TABLE akuntansi_aset_tetap ADD COLUMN IF NOT EXISTS keterangan TEXT NULL');
        $pdo->exec('ALTER TABLE akuntansi_aset_tetap ADD COLUMN IF NOT EXISTS created_by INT NULL');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS akuntansi_jurnal_penyesuaian (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            kode_akun VARCHAR(30) NOT NULL,
            nama_akun VARCHAR(150) NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0,
            kredit DECIMAL(14,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_aset_rows(PDO $pdo, bool $activeOnly = true): array
{
    if (!table_exists($pdo, 'akuntansi_aset_tetap')) {
        return [];
    }
    $sql = '
        SELECT id, kode_inventaris, nama_aset, kategori_aset, lokasi, tanggal_perolehan,
               harga_perolehan, nilai_residu, umur_manfaat_bulan, akumulasi_penyusutan,
               last_penyusutan_periode, keterangan, is_active
        FROM akuntansi_aset_tetap
    ';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY kategori_aset ASC, nama_aset ASC, id DESC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{labels:list<string>,values:list<int>}
 */
function keuangan_aset_chart_data(array $rows): array
{
    $labels = [];
    $values = [];
    foreach ($rows as $as) {
        if ((int) ($as['is_active'] ?? 1) !== 1) {
            continue;
        }
        $harga = (int) round((float) ($as['harga_perolehan'] ?? 0));
        $akum = (int) round((float) ($as['akumulasi_penyusutan'] ?? 0));
        $buku = max(0, $harga - $akum);
        if ($buku <= 0 && $harga <= 0) {
            continue;
        }
        $nama = (string) ($as['nama_aset'] ?? 'Aset');
        if (strlen($nama) > 28) {
            $nama = substr($nama, 0, 25) . '…';
        }
        $labels[] = $nama;
        $values[] = $buku;
    }

    return ['labels' => $labels, 'values' => $values];
}

function keuangan_generate_kode_inventaris(PDO $pdo): string
{
    $prefix = 'INV-' . date('Y') . '-';
    $stmt = $pdo->prepare("
        SELECT kode_inventaris FROM akuntansi_aset_tetap
        WHERE kode_inventaris LIKE :pfx
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['pfx' => $prefix . '%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $seq = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $seq = (int) $m[1] + 1;
    }

    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_save_aset_tetap(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_inventaris_tables($pdo);
    ensure_keuangan_transaksi_tables($pdo);
    ensure_keuangan_jurnal_tables($pdo);

    $namaAset = trim((string) ($post['nama_aset'] ?? ''));
    $kategoriAset = trim((string) ($post['kategori_aset'] ?? 'Lainnya'));
    $tanggal = trim((string) ($post['tanggal_perolehan'] ?? date('Y-m-d')));
    $harga = keuangan_money_input_to_int((string) ($post['harga_perolehan'] ?? '0'));
    $residu = keuangan_money_input_to_int((string) ($post['nilai_residu'] ?? '0'));
    $umur = max(1, (int) ($post['umur_manfaat_bulan'] ?? 12));
    $lokasi = trim((string) ($post['lokasi'] ?? ''));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $kode = trim((string) ($post['kode_inventaris'] ?? ''));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $metodeKeluar = strtoupper(trim((string) ($post['metode_keluar'] ?? 'KAS')));
    if ($kode === '') {
        $kode = keuangan_generate_kode_inventaris($pdo);
    }

    if ($namaAset === '') {
        return ['ok' => false, 'message' => 'Nama aset wajib diisi.'];
    }
    if ($harga <= 0) {
        return ['ok' => false, 'message' => 'Harga perolehan harus lebih dari nol.'];
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank sumber pembayaran aset.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    if ($residu >= $harga) {
        return ['ok' => false, 'message' => 'Nilai residu tidak boleh melebihi harga perolehan.'];
    }
    if (!in_array($metodeKeluar, ['KAS', 'TRANSFER'], true)) {
        $metodeKeluar = 'KAS';
    }

    $posPengeluaran = 'Belanja Modal — Inventaris ' . $kode;
    $ketPengeluaran = 'Perolehan aset: ' . $namaAset;
    if ($keterangan !== '') {
        $ketPengeluaran .= ' — ' . $keterangan;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('
            INSERT INTO akuntansi_aset_tetap (
                kode_inventaris, nama_aset, kategori_aset, lokasi, tanggal_perolehan,
                harga_perolehan, nilai_residu, umur_manfaat_bulan, keterangan, created_by
            ) VALUES (
                :kode, :nama, :kat, :lokasi, :tgl, :harga, :residu, :umur, :ket, :uid
            )
        ')->execute([
            'kode' => $kode,
            'nama' => $namaAset,
            'kat' => $kategoriAset !== '' ? $kategoriAset : 'Lainnya',
            'lokasi' => $lokasi !== '' ? $lokasi : null,
            'tgl' => $tanggal,
            'harga' => $harga,
            'residu' => $residu,
            'umur' => $umur,
            'ket' => $keterangan !== '' ? $keterangan : null,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $asetId = (int) $pdo->lastInsertId();

        $pengCols = ['tanggal', 'penanggung_jawab', 'pos', 'nominal', 'keterangan', 'created_by'];
        $pengVals = [':tanggal', ':penanggung_jawab', ':pos', ':nominal', ':keterangan', ':created_by'];
        $pengParams = [
            'tanggal' => $tanggal,
            'penanggung_jawab' => 'Inventaris',
            'pos' => $posPengeluaran,
            'nominal' => $harga,
            'keterangan' => $ketPengeluaran,
            'created_by' => $userId > 0 ? $userId : null,
        ];
        if (column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar')) {
            $pengCols[] = 'metode_keluar';
            $pengVals[] = ':metode_keluar';
            $pengParams['metode_keluar'] = $metodeKeluar;
        }
        if (column_exists($pdo, 'keuangan_pengeluaran', 'akun_id')) {
            $pengCols[] = 'akun_id';
            $pengVals[] = ':akun_id';
            $pengParams['akun_id'] = $akunId;
        }
        $pdo->prepare('INSERT INTO keuangan_pengeluaran (' . implode(', ', $pengCols) . ') VALUES (' . implode(', ', $pengVals) . ')')
            ->execute($pengParams);
        $pengeluaranId = (int) $pdo->lastInsertId();

        if (column_exists($pdo, 'akuntansi_aset_tetap', 'ref_pengeluaran_id')) {
            $pdo->prepare('UPDATE akuntansi_aset_tetap SET ref_pengeluaran_id = :pid WHERE id = :id')
                ->execute(['pid' => $pengeluaranId, 'id' => $asetId]);
        }

        keuangan_jurnal_capex_aset($pdo, $asetId, $tanggal, $akunId, $harga, $namaAset, $userId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal mencatat aset: ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'message' => 'Aset ' . $kode . ' dicatat. Kas berkurang ' . keuangan_format_rupiah($harga) . ' dan jurnal CapEx tersimpan.',
    ];
}

/**
 * @return array{ok:bool,message:string,jumlah:int}
 */
function keuangan_run_penyusutan_aset(PDO $pdo, string $periode, int $userId): array
{
    ensure_keuangan_inventaris_tables($pdo);
    $periode = trim($periode);
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        return ['ok' => false, 'message' => 'Format periode harus YYYY-MM (contoh: 2026-05).', 'jumlah' => 0];
    }

    $rows = $pdo->query('
        SELECT id, nama_aset, harga_perolehan, nilai_residu, umur_manfaat_bulan,
               akumulasi_penyusutan, last_penyusutan_periode
        FROM akuntansi_aset_tetap
        WHERE is_active = 1
    ')->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare('
        UPDATE akuntansi_aset_tetap
        SET akumulasi_penyusutan = :akumulasi, last_penyusutan_periode = :periode
        WHERE id = :id
    ');
    $insAdj = null;
    if (table_exists($pdo, 'akuntansi_jurnal_penyesuaian')) {
        $insAdj = $pdo->prepare('
            INSERT INTO akuntansi_jurnal_penyesuaian (tanggal, kode_akun, nama_akun, debit, kredit, keterangan, created_by)
            VALUES (:tanggal, :kode_akun, :nama_akun, :debit, :kredit, :keterangan, :created_by)
        ');
    }

    $jumlah = 0;
    $tanggalJurnal = $periode . '-01';

    foreach ($rows as $row) {
        if ((string) ($row['last_penyusutan_periode'] ?? '') === $periode) {
            continue;
        }
        $harga = (int) round((float) ($row['harga_perolehan'] ?? 0));
        $residu = (int) round((float) ($row['nilai_residu'] ?? 0));
        $umur = max(1, (int) ($row['umur_manfaat_bulan'] ?? 12));
        $dasar = max(0, $harga - $residu);
        $beban = (int) floor($dasar / $umur);
        if ($beban <= 0) {
            continue;
        }
        $akumulasiLama = (int) round((float) ($row['akumulasi_penyusutan'] ?? 0));
        $akumulasiBaru = min($dasar, $akumulasiLama + $beban);
        $bebanAktual = $akumulasiBaru - $akumulasiLama;
        if ($bebanAktual <= 0) {
            continue;
        }

        $upd->execute([
            'akumulasi' => $akumulasiBaru,
            'periode' => $periode,
            'id' => (int) $row['id'],
        ]);

        if ($insAdj !== null) {
            $ket = 'Penyusutan aset: ' . (string) ($row['nama_aset'] ?? '');
            $insAdj->execute([
                'tanggal' => $tanggalJurnal,
                'kode_akun' => '6101',
                'nama_akun' => 'Beban Penyusutan',
                'debit' => $bebanAktual,
                'kredit' => 0,
                'keterangan' => $ket,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
            $insAdj->execute([
                'tanggal' => $tanggalJurnal,
                'kode_akun' => '1209',
                'nama_akun' => 'Akumulasi Penyusutan',
                'debit' => 0,
                'kredit' => $bebanAktual,
                'keterangan' => $ket,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
        }
        $jumlah++;
    }

    return [
        'ok' => true,
        'message' => 'Penyusutan periode ' . $periode . ' selesai. Aset diproses: ' . $jumlah . '.',
        'jumlah' => $jumlah,
    ];
}

function keuangan_nonaktifkan_aset(PDO $pdo, int $id): bool
{
    if ($id <= 0 || !table_exists($pdo, 'akuntansi_aset_tetap')) {
        return false;
    }
    $pdo->prepare('UPDATE akuntansi_aset_tetap SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);

    return true;
}

/** Ringkasan inventaris untuk kartu dashboard. */
function keuangan_inventaris_ringkas(PDO $pdo): array
{
    $rows = keuangan_fetch_aset_rows($pdo, true);
    $totalHarga = 0;
    $totalAkum = 0;
    foreach ($rows as $r) {
        $totalHarga += (int) round((float) ($r['harga_perolehan'] ?? 0));
        $totalAkum += (int) round((float) ($r['akumulasi_penyusutan'] ?? 0));
    }

    return [
        'jumlah' => count($rows),
        'total_harga' => $totalHarga,
        'total_akumulasi' => $totalAkum,
        'total_nilai_buku' => max(0, $totalHarga - $totalAkum),
    ];
}
