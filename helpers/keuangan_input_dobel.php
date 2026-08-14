<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_validasi_pesan.php';

function keuangan_input_dobel_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!table_exists($pdo, 'keuangan_input_fingerprint')) {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS keuangan_input_fingerprint (
                fingerprint CHAR(64) NOT NULL,
                ref_type VARCHAR(20) NOT NULL,
                ref_id INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (fingerprint),
                KEY idx_keu_fp_ref (ref_type, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}

function keuangan_input_dobel_hash(string $refType, array $parts): string
{
    $normalized = array_map(static fn($v): string => trim((string) $v), $parts);

    return hash('sha256', strtolower($refType) . '|' . implode('|', $normalized));
}

/**
 * @param list<array{slug:string,nominal:int}> $detailRows
 */
function keuangan_input_dobel_fingerprint_pembayaran(
    int $santriId,
    string $tanggalBayar,
    int $totalNominal,
    string $jenisPeriode,
    int $bulanTagihan,
    array $detailRows,
    int $userId
): string {
    $posParts = [];
    foreach ($detailRows as $dr) {
        $posParts[] = strtolower(trim((string) ($dr['slug'] ?? ''))) . ':' . (int) ($dr['nominal'] ?? 0);
    }
    sort($posParts);

    return keuangan_input_dobel_hash('pembayaran', [
        $santriId,
        $tanggalBayar,
        $totalNominal,
        strtoupper(trim($jenisPeriode)),
        $bulanTagihan,
        implode(',', $posParts),
        $userId,
    ]);
}

function keuangan_input_dobel_fingerprint_pemasukan(
    string $tanggal,
    int $akunId,
    int $nominal,
    string $sumber,
    string $metodeBayar,
    string $noBukti,
    int $userId
): string {
    return keuangan_input_dobel_hash('pemasukan', [
        $tanggal,
        $akunId,
        $nominal,
        trim($sumber),
        strtoupper(trim($metodeBayar)),
        trim($noBukti),
        $userId,
    ]);
}

function keuangan_input_dobel_fingerprint_pengeluaran(
    string $tanggal,
    int $akunId,
    int $nominal,
    string $pos,
    string $alokasiNama,
    int $userId
): string {
    return keuangan_input_dobel_hash('pengeluaran', [
        $tanggal,
        $akunId,
        $nominal,
        trim($pos),
        trim($alokasiNama),
        $userId,
    ]);
}

function keuangan_input_dobel_claim(PDO $pdo, string $fingerprint, string $refType, int $refId): bool
{
    keuangan_input_dobel_ensure_schema($pdo);
    if ($fingerprint === '') {
        return false;
    }
    try {
        $pdo->prepare('
            INSERT INTO keuangan_input_fingerprint (fingerprint, ref_type, ref_id, created_at)
            VALUES (:fp, :t, :id, NOW())
        ')->execute([
            'fp' => $fingerprint,
            't' => strtolower(trim($refType)),
            'id' => max(0, $refId),
        ]);

        return true;
    } catch (PDOException $e) {
        $code = (string) ($e->getCode() ?? '');
        $msg = strtolower($e->getMessage());
        if ($code === '23000' || str_contains($msg, 'duplicate') || str_contains($msg, '1062')) {
            return false;
        }
        throw $e;
    }
}

/** @return string|null pesan error jika idempotency key sudah dipakai */
function keuangan_input_dobel_idempotency_cek(array $post, int $userId): ?string
{
    $key = trim((string) ($post['idempotency_key'] ?? ''));
    if ($key === '' || $userId <= 0) {
        return null;
    }
    if (!isset($_SESSION['keuangan_idempotency']) || !is_array($_SESSION['keuangan_idempotency'])) {
        $_SESSION['keuangan_idempotency'] = [];
    }
    $now = time();
    foreach ($_SESSION['keuangan_idempotency'] as $k => $ts) {
        if (!is_int($ts) || $ts < $now - 900) {
            unset($_SESSION['keuangan_idempotency'][$k]);
        }
    }
    $sessionKey = $userId . ':' . $key;
    if (isset($_SESSION['keuangan_idempotency'][$sessionKey])) {
        return keuangan_validasi_pesan('INPUT_DOBEL');
    }

    return null;
}

function keuangan_input_dobel_idempotency_mark(array $post, int $userId): void
{
    $key = trim((string) ($post['idempotency_key'] ?? ''));
    if ($key === '' || $userId <= 0) {
        return;
    }
    if (!isset($_SESSION['keuangan_idempotency']) || !is_array($_SESSION['keuangan_idempotency'])) {
        $_SESSION['keuangan_idempotency'] = [];
    }
    $_SESSION['keuangan_idempotency'][$userId . ':' . $key] = time();
}

function keuangan_input_dobel_cek_no_referensi_pembayaran(PDO $pdo, string $noReferensi, string $metodeBayar): ?string
{
    $noReferensi = trim($noReferensi);
    $metodeBayar = strtoupper(trim($metodeBayar));
    if ($noReferensi === '' || !in_array($metodeBayar, ['TRANSFER', 'MIDTRANS'], true)) {
        return null;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !column_exists($pdo, 'keuangan_pembayaran', 'no_referensi')) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM keuangan_pembayaran WHERE no_referensi = :ref LIMIT 1');
    $st->execute(['ref' => $noReferensi]);
    if ($st->fetchColumn()) {
        return 'Nomor referensi transfer sudah dipakai pada pembayaran lain.';
    }

    return null;
}

function keuangan_input_dobel_cek_no_bukti_pemasukan(PDO $pdo, string $noBukti, string $metodeBayar): ?string
{
    $noBukti = trim($noBukti);
    if ($noBukti === '' || strtoupper(trim($metodeBayar)) !== 'TRANSFER') {
        return null;
    }
    if (!table_exists($pdo, 'keuangan_pemasukan') || !column_exists($pdo, 'keuangan_pemasukan', 'no_bukti')) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM keuangan_pemasukan WHERE no_bukti = :nb LIMIT 1');
    $st->execute(['nb' => $noBukti]);
    if ($st->fetchColumn()) {
        return 'Nomor bukti transfer sudah dipakai pada pemasukan lain.';
    }

    return null;
}

function keuangan_input_dobel_cek_no_bukti_pengeluaran(PDO $pdo, string $noBukti, string $metodeKeluar): ?string
{
    $noBukti = trim($noBukti);
    if ($noBukti === '' || strtoupper(trim($metodeKeluar)) !== 'TRANSFER') {
        return null;
    }
    if (!table_exists($pdo, 'keuangan_pengeluaran') || !column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti')) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM keuangan_pengeluaran WHERE no_bukti = :nb LIMIT 1');
    $st->execute(['nb' => $noBukti]);
    if ($st->fetchColumn()) {
        return 'Nomor bukti transfer sudah dipakai pada pengeluaran lain.';
    }

    return null;
}

/**
 * @return list<array<string,mixed>>
 */
function keuangan_input_dobel_list_duplikat_pemasukan(PDO $pdo, int $limit = 50): array
{
    if (!table_exists($pdo, 'keuangan_pemasukan')) {
        return [];
    }
    $st = $pdo->query('
        SELECT tanggal, akun_id, nominal, sumber, COALESCE(created_by, 0) AS created_by,
               COUNT(*) AS jumlah, MIN(id) AS id_pertama, MAX(id) AS id_terakhir,
               GROUP_CONCAT(id ORDER BY id) AS ids
        FROM keuangan_pemasukan
        GROUP BY tanggal, akun_id, nominal, sumber, COALESCE(created_by, 0)
        HAVING COUNT(*) > 1
        ORDER BY tanggal DESC, id_terakhir DESC
        LIMIT ' . max(1, min(100, $limit)));

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @return list<array<string,mixed>>
 */
function keuangan_input_dobel_list_duplikat_pengeluaran(PDO $pdo, int $limit = 50): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }
    $st = $pdo->query('
        SELECT tanggal, akun_id, nominal, pos, alokasi_nama, COALESCE(created_by, 0) AS created_by,
               COUNT(*) AS jumlah, MIN(id) AS id_pertama, MAX(id) AS id_terakhir,
               GROUP_CONCAT(id ORDER BY id) AS ids
        FROM keuangan_pengeluaran
        GROUP BY tanggal, akun_id, nominal, pos, alokasi_nama, COALESCE(created_by, 0)
        HAVING COUNT(*) > 1
        ORDER BY tanggal DESC, id_terakhir DESC
        LIMIT ' . max(1, min(100, $limit)));

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @param array<string,mixed> $grupRow
 * @return list<int>
 */
function keuangan_input_dobel_parse_ids(array $grupRow): array
{
    $raw = trim((string) ($grupRow['ids'] ?? ''));
    if ($raw === '') {
        $ids = [];
        foreach (['id_pertama', 'id_terakhir'] as $k) {
            $v = (int) ($grupRow[$k] ?? 0);
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }

        return array_values($ids);
    }
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return $out;
}

/**
 * @return array{pembayaran:int,pemasukan:int,pengeluaran:int,total:int}
 */
function keuangan_input_dobel_ringkas(PDO $pdo): array
{
    require_once __DIR__ . '/keuangan_perbaikan_kas.php';

    $pembayaran = count(keuangan_perbaikan_kas_list_duplikat_mungkin($pdo, 500));
    $pemasukan = count(keuangan_input_dobel_list_duplikat_pemasukan($pdo, 500));
    $pengeluaran = count(keuangan_input_dobel_list_duplikat_pengeluaran($pdo, 500));

    return [
        'pembayaran' => $pembayaran,
        'pemasukan' => $pemasukan,
        'pengeluaran' => $pengeluaran,
        'total' => $pembayaran + $pemasukan + $pengeluaran,
    ];
}
