<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_neraca.php';

function ensure_keuangan_talangan_tables(PDO $pdo): void
{
    ensure_keuangan_transaksi_tables($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_talangan_internal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pos_pemberi_slug VARCHAR(80) NOT NULL,
            pos_pemberi_nama VARCHAR(150) NOT NULL,
            pos_penerima_slug VARCHAR(80) NOT NULL,
            pos_penerima_nama VARCHAR(150) NOT NULL,
            nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            alasan TEXT NULL,
            status ENUM('AKTIF','LUNAS') NOT NULL DEFAULT 'AKTIF',
            tanggal_pinjam DATE NOT NULL,
            tanggal_lunas DATE NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_talangan_status (status),
            INDEX idx_talangan_pemberi (pos_pemberi_slug, status),
            INDEX idx_talangan_penerima (pos_penerima_slug, status)
        )
    ");
}

/**
 * @return list<array{slug:string,nama:string,kategori:string}>
 */
function keuangan_talangan_pos_options(PDO $pdo): array
{
    $out = [];
    foreach (keuangan_biaya_definitions() as $def) {
        $slug = strtolower(trim((string) ($def['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $out[] = [
            'slug' => $slug,
            'nama' => (string) ($def['nama'] ?? $slug),
            'kategori' => (string) ($def['kategori'] ?? ''),
        ];
    }

    return $out;
}

function keuangan_talangan_pos_label_map(PDO $pdo): array
{
    $map = [];
    foreach (keuangan_talangan_pos_options($pdo) as $p) {
        $map[$p['slug']] = $p['nama'];
    }

    return $map;
}

/** Total terbayar per pos (uang fisik masuk) — kumulatif semua periode. */
function keuangan_talangan_saldo_aktual_map(PDO $pdo): array
{
    $map = [];
    if (!table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return $map;
    }
    $stmt = $pdo->query('
        SELECT LOWER(TRIM(pos_slug)) AS slug, COALESCE(SUM(nominal), 0) AS total
        FROM keuangan_pembayaran_detail
        GROUP BY LOWER(TRIM(pos_slug))
    ');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
        $slug = (string) ($r['slug'] ?? '');
        if ($slug !== '') {
            $map[$slug] = (int) round((float) ($r['total'] ?? 0));
        }
    }

    return $map;
}

/**
 * @return array{piutang_keluar: array<string,int>, utang_masuk: array<string,int>}
 */
function keuangan_talangan_internal_saldo_maps(PDO $pdo): array
{
    $piutang = [];
    $utang = [];
    if (!table_exists($pdo, 'keuangan_talangan_internal')) {
        return ['piutang_keluar' => $piutang, 'utang_masuk' => $utang];
    }
    $stmt = $pdo->query("
        SELECT pos_pemberi_slug, pos_penerima_slug, COALESCE(SUM(nominal), 0) AS total
        FROM keuangan_talangan_internal
        WHERE status = 'AKTIF'
        GROUP BY pos_pemberi_slug, pos_penerima_slug
    ");
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
        $from = strtolower(trim((string) ($r['pos_pemberi_slug'] ?? '')));
        $to = strtolower(trim((string) ($r['pos_penerima_slug'] ?? '')));
        $amt = (int) round((float) ($r['total'] ?? 0));
        if ($from !== '') {
            $piutang[$from] = ($piutang[$from] ?? 0) + $amt;
        }
        if ($to !== '') {
            $utang[$to] = ($utang[$to] ?? 0) + $amt;
        }
    }

    return ['piutang_keluar' => $piutang, 'utang_masuk' => $utang];
}

/**
 * Ringkasan saldo per POS untuk dashboard talangan.
 *
 * @return list<array{
 *   slug:string,nama:string,kategori:string,
 *   saldo_aktual:int,piutang_keluar:int,utang_masuk:int,saldo_tersedia:int
 * }>
 */
function keuangan_talangan_saldo_per_pos(PDO $pdo): array
{
    $aktualMap = keuangan_talangan_saldo_aktual_map($pdo);
    $internal = keuangan_talangan_internal_saldo_maps($pdo);
    $piutangMap = $internal['piutang_keluar'];
    $utangMap = $internal['utang_masuk'];
    $rows = [];

    foreach (keuangan_talangan_pos_options($pdo) as $p) {
        $slug = $p['slug'];
        $aktual = (int) ($aktualMap[$slug] ?? 0);
        $piutang = (int) ($piutangMap[$slug] ?? 0);
        $utang = (int) ($utangMap[$slug] ?? 0);
        $tersedia = $aktual - $piutang + $utang;
        $rows[] = [
            'slug' => $slug,
            'nama' => $p['nama'],
            'kategori' => $p['kategori'],
            'saldo_aktual' => $aktual,
            'piutang_keluar' => $piutang,
            'utang_masuk' => $utang,
            'saldo_tersedia' => $tersedia,
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($a['nama'], $b['nama']));

    return $rows;
}

/**
 * @return array{ok:bool,message:string,id?:int}
 */
function keuangan_talangan_simpan_pinjaman(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_talangan_tables($pdo);

    $posMap = keuangan_talangan_pos_label_map($pdo);
    $pemberi = strtolower(trim((string) ($post['pos_pemberi'] ?? '')));
    $penerima = strtolower(trim((string) ($post['pos_penerima'] ?? '')));
    $nominal = keuangan_money_input_to_int((string) ($post['nominal'] ?? '0'));
    $alasan = trim((string) ($post['alasan'] ?? ''));
    $tanggal = trim((string) ($post['tanggal_pinjam'] ?? date('Y-m-d')));

    if ($pemberi === '' || $penerima === '') {
        return ['ok' => false, 'message' => 'Pos pemberi dan penerima wajib dipilih.'];
    }
    if (!isset($posMap[$pemberi]) || !isset($posMap[$penerima])) {
        return ['ok' => false, 'message' => 'Pos tidak valid.'];
    }
    if ($pemberi === $penerima) {
        return ['ok' => false, 'message' => 'Pos pemberi dan penerima tidak boleh sama.'];
    }
    if ($nominal <= 0) {
        return ['ok' => false, 'message' => 'Nominal pinjaman harus lebih dari nol.'];
    }
    if ($tanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $saldoRows = keuangan_talangan_saldo_per_pos($pdo);
    $saldoPemberi = 0;
    foreach ($saldoRows as $sr) {
        if (($sr['slug'] ?? '') === $pemberi) {
            $saldoPemberi = (int) ($sr['saldo_tersedia'] ?? 0);
            break;
        }
    }
    if ($nominal > $saldoPemberi) {
        return [
            'ok' => false,
            'message' => 'Saldo tersedia pos pemberi (' . keuangan_format_rupiah($saldoPemberi)
                . ') tidak mencukupi untuk pinjaman ' . keuangan_format_rupiah($nominal) . '.',
        ];
    }

    $stmt = $pdo->prepare('
        INSERT INTO keuangan_talangan_internal (
            pos_pemberi_slug, pos_pemberi_nama, pos_penerima_slug, pos_penerima_nama,
            nominal, alasan, status, tanggal_pinjam, created_by
        ) VALUES (
            :pemberi_slug, :pemberi_nama, :penerima_slug, :penerima_nama,
            :nominal, :alasan, \'AKTIF\', :tanggal, :uid
        )
    ');
    $stmt->execute([
        'pemberi_slug' => $pemberi,
        'pemberi_nama' => $posMap[$pemberi],
        'penerima_slug' => $penerima,
        'penerima_nama' => $posMap[$penerima],
        'nominal' => $nominal,
        'alasan' => $alasan !== '' ? $alasan : null,
        'tanggal' => $tanggal,
        'uid' => $userId > 0 ? $userId : null,
    ]);

    return [
        'ok' => true,
        'message' => 'Pinjaman internal tercatat: ' . $posMap[$pemberi] . ' → ' . $posMap[$penerima]
            . ' ' . keuangan_format_rupiah($nominal) . '.',
        'id' => (int) $pdo->lastInsertId(),
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_talangan_kembalikan(PDO $pdo, int $id, int $userId): array
{
    ensure_keuangan_talangan_tables($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Data pinjaman tidak valid.'];
    }

    $stmt = $pdo->prepare('
        SELECT id, pos_pemberi_nama, pos_penerima_nama, nominal, status
        FROM keuangan_talangan_internal
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Pinjaman tidak ditemukan.'];
    }
    if ((string) ($row['status'] ?? '') === 'LUNAS') {
        return ['ok' => false, 'message' => 'Pinjaman ini sudah dilunasi sebelumnya.'];
    }

    $upd = $pdo->prepare("
        UPDATE keuangan_talangan_internal
        SET status = 'LUNAS', tanggal_lunas = :tgl
        WHERE id = :id AND status = 'AKTIF'
    ");
    $upd->execute(['tgl' => date('Y-m-d'), 'id' => $id]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Gagal memperbarui status pinjaman.'];
    }

    $nom = keuangan_format_rupiah((int) round((float) ($row['nominal'] ?? 0)));

    return [
        'ok' => true,
        'message' => 'Saldo dikembalikan: ' . ($row['pos_penerima_nama'] ?? '')
            . ' → ' . ($row['pos_pemberi_nama'] ?? '') . ' (' . $nom . ').',
    ];
}

/**
 * Buku utang antar-pos: pinjaman aktif + riwayat lunas terbaru.
 *
 * @return array{aktif:list<array>,riwayat:list<array>,total_aktif:int}
 */
function keuangan_talangan_ledger(PDO $pdo, int $riwayatLimit = 30): array
{
    ensure_keuangan_talangan_tables($pdo);
    if (!table_exists($pdo, 'keuangan_talangan_internal')) {
        return ['aktif' => [], 'riwayat' => [], 'total_aktif' => 0];
    }

    $aktifStmt = $pdo->query("
        SELECT id, pos_pemberi_slug, pos_pemberi_nama, pos_penerima_slug, pos_penerima_nama,
               nominal, alasan, tanggal_pinjam, created_at
        FROM keuangan_talangan_internal
        WHERE status = 'AKTIF'
        ORDER BY tanggal_pinjam DESC, id DESC
    ");
    $aktif = $aktifStmt ? $aktifStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $totalAktif = 0;
    foreach ($aktif as $a) {
        $totalAktif += (int) round((float) ($a['nominal'] ?? 0));
    }

    $lim = max(5, min(100, $riwayatLimit));
    $histStmt = $pdo->query("
        SELECT id, pos_pemberi_nama, pos_penerima_nama, nominal, alasan,
               tanggal_pinjam, tanggal_lunas
        FROM keuangan_talangan_internal
        WHERE status = 'LUNAS'
        ORDER BY tanggal_lunas DESC, id DESC
        LIMIT {$lim}
    ");
    $riwayat = $histStmt ? $histStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return [
        'aktif' => $aktif,
        'riwayat' => $riwayat,
        'total_aktif' => $totalAktif,
    ];
}
