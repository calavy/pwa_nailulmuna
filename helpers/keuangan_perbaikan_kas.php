<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_jurnal.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';
require_once __DIR__ . '/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/keuangan_pemasukan_riwayat.php';
require_once __DIR__ . '/keuangan_pengeluaran_riwayat.php';
require_once __DIR__ . '/pembayaran_edit_token.php';

/**
 * @return array{
 *   jumlah:int,
 *   nominal:int,
 *   pembayaran:list<array<string,mixed>>,
 *   pemasukan:list<array<string,mixed>>,
 *   pengeluaran:list<array<string,mixed>>,
 *   duplikat:list<array<string,mixed>>
 * }
 */
function keuangan_perbaikan_kas_ringkas(PDO $pdo, ?string $asOf = null): array
{
    $asOf = $asOf !== null && $asOf !== '' ? date('Y-m-d', strtotime($asOf) ?: time()) : date('Y-m-d');
    $pembayaran = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pembayaran', 'tanggal_bayar', 'total_nominal', $asOf);
    $pemasukan = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pemasukan', 'tanggal', 'nominal', $asOf);
    $pengeluaran = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pengeluaran', 'tanggal', 'nominal', $asOf);
    $duplikat = keuangan_perbaikan_kas_list_duplikat_mungkin($pdo);

    $nominal = 0;
    foreach ([$pembayaran, $pemasukan, $pengeluaran] as $rows) {
        foreach ($rows as $row) {
            $nominal += (int) ($row['nominal'] ?? 0);
        }
    }

    return [
        'jumlah' => count($pembayaran) + count($pemasukan) + count($pengeluaran),
        'nominal' => $nominal,
        'pembayaran' => $pembayaran,
        'pemasukan' => $pemasukan,
        'pengeluaran' => $pengeluaran,
        'duplikat' => $duplikat,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function keuangan_perbaikan_kas_list_tanpa_akun(
    PDO $pdo,
    string $table,
    string $dateCol,
    string $nomCol,
    string $asOf,
    int $limit = 100
): array {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, 'akun_id')) {
        return [];
    }

    $extraCols = '';
    $join = '';
    if ($table === 'keuangan_pembayaran' && table_exists($pdo, 'santri')) {
        $extraCols = ', s.nis, s.nama_santri';
        $join = ' LEFT JOIN santri s ON s.id = p.santri_id';
    } elseif ($table === 'keuangan_pengeluaran') {
        $extraCols = ', p.pos, p.penanggung_jawab';
    } elseif ($table === 'keuangan_pemasukan') {
        $extraCols = ', p.sumber, p.dari_pihak';
    }

    $alias = 'p';
    $st = $pdo->prepare("
        SELECT p.id, p.{$dateCol} AS tanggal, p.{$nomCol} AS nominal{$extraCols}
        FROM {$table} p{$join}
        WHERE p.{$dateCol} <= :as_of
          AND (p.akun_id IS NULL OR p.akun_id <= 0)
        ORDER BY p.{$dateCol} DESC, p.id DESC
        LIMIT " . max(1, min(200, $limit)) . '
    ');
    $st->execute(['as_of' => $asOf]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Pembayaran kemungkinan dobel: santri + tanggal + nominal sama (>1 baris).
 *
 * @return list<array<string,mixed>>
 */
function keuangan_perbaikan_kas_list_duplikat_mungkin(PDO $pdo, int $limit = 30): array
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $st = $pdo->query("
        SELECT p.santri_id, p.tanggal_bayar, p.total_nominal AS nominal,
               COUNT(*) AS jumlah, MIN(p.id) AS id_pertama, MAX(p.id) AS id_terakhir,
               s.nis, s.nama_santri
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        GROUP BY p.santri_id, p.tanggal_bayar, p.total_nominal
        HAVING COUNT(*) > 1
        ORDER BY p.tanggal_bayar DESC, p.santri_id ASC
        LIMIT " . max(1, min(50, $limit)));

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function keuangan_perbaikan_kas_default_akun_id(PDO $pdo): int
{
    foreach (keuangan_fetch_akun_aktif($pdo) as $ak) {
        if (!empty($ak['is_default'])) {
            return (int) ($ak['id'] ?? 0);
        }
    }
    $first = keuangan_fetch_akun_aktif($pdo)[0] ?? null;

    return is_array($first) ? (int) ($first['id'] ?? 0) : 0;
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_patch_akun(PDO $pdo, string $tipe, int $id, int $akunId, int $userId): array
{
    $tipe = strtolower(trim($tipe));
    $akunErr = keuangan_validasi_akun_kas_id($pdo, $akunId);
    if ($akunErr !== null) {
        return ['ok' => false, 'message' => $akunErr];
    }
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID transaksi tidak valid.'];
    }

    keuangan_transaksi_bootstrap_jurnal();

    if ($tipe === 'pembayaran') {
        return keuangan_perbaikan_kas_patch_pembayaran($pdo, $id, $akunId, $userId);
    }
    if ($tipe === 'pemasukan') {
        return keuangan_perbaikan_kas_patch_pemasukan($pdo, $id, $akunId, $userId);
    }
    if ($tipe === 'pengeluaran') {
        return keuangan_perbaikan_kas_patch_pengeluaran($pdo, $id, $akunId, $userId);
    }

    return ['ok' => false, 'message' => 'Jenis transaksi tidak dikenali.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_patch_pembayaran(PDO $pdo, int $id, int $akunId, int $userId): array
{
    $row = keuangan_pembayaran_fetch($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Pembayaran #' . $id . ' tidak ditemukan.'];
    }
    if ((int) ($row['akun_id'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Pembayaran #' . $id . ' sudah punya akun kas.'];
    }

    $tanggal = (string) ($row['tanggal_bayar'] ?? date('Y-m-d'));
    $total = (int) round((float) ($row['total_nominal'] ?? 0));
    $detailRows = [];
    foreach ($row['details'] ?? [] as $det) {
        $detailRows[] = [
            'slug' => (string) ($det['pos_slug'] ?? ''),
            'nama' => (string) ($det['pos_nama'] ?? ''),
            'nominal' => (int) round((float) ($det['nominal'] ?? 0)),
        ];
    }
    $jenisPeriode = strtoupper((string) ($row['jenis_periode'] ?? 'BULANAN'));
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE keuangan_pembayaran SET akun_id = :akun_id WHERE id = :id')
            ->execute(['akun_id' => $akunId, 'id' => $id]);
        keuangan_jurnal_delete_by_ref($pdo, 'pembayaran', $id);
        keuangan_jurnal_pembayaran($pdo, $id, $tanggal, $akunId, $total, $detailRows, $kategoriFilter, $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memperbaiki pembayaran: ' . $e->getMessage()];
    }

    keuangan_perbaikan_kas_invalidate_cache();

    return ['ok' => true, 'message' => 'Pembayaran #' . $id . ' — akun kas diperbarui.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_patch_pemasukan(PDO $pdo, int $id, int $akunId, int $userId): array
{
    $row = keuangan_pemasukan_get($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Pemasukan #' . $id . ' tidak ditemukan.'];
    }
    if ((int) ($row['akun_id'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Pemasukan #' . $id . ' sudah punya akun kas.'];
    }

    $tanggal = (string) ($row['tanggal'] ?? date('Y-m-d'));
    $nominal = (int) round((float) ($row['nominal'] ?? 0));
    $sumber = (string) ($row['sumber'] ?? '');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE keuangan_pemasukan SET akun_id = :akun_id WHERE id = :id')
            ->execute(['akun_id' => $akunId, 'id' => $id]);
        keuangan_jurnal_delete_by_ref($pdo, 'pemasukan', $id);
        keuangan_jurnal_pemasukan($pdo, $id, $tanggal, $akunId, $nominal, $sumber, $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memperbaiki pemasukan: ' . $e->getMessage()];
    }

    keuangan_perbaikan_kas_invalidate_cache();

    return ['ok' => true, 'message' => 'Pemasukan #' . $id . ' — akun kas diperbarui.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_patch_pengeluaran(PDO $pdo, int $id, int $akunId, int $userId): array
{
    $row = keuangan_pengeluaran_get($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Pengeluaran #' . $id . ' tidak ditemukan.'];
    }
    if ((int) ($row['akun_id'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Pengeluaran #' . $id . ' sudah punya akun kas.'];
    }

    $tanggal = (string) ($row['tanggal'] ?? date('Y-m-d'));
    $nominal = (int) round((float) ($row['nominal'] ?? 0));
    $pos = (string) ($row['pos'] ?? '');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE keuangan_pengeluaran SET akun_id = :akun_id WHERE id = :id')
            ->execute(['akun_id' => $akunId, 'id' => $id]);
        keuangan_jurnal_delete_by_ref($pdo, 'pengeluaran', $id);
        keuangan_jurnal_pengeluaran($pdo, $id, $tanggal, $akunId, $nominal, $pos, $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memperbaiki pengeluaran: ' . $e->getMessage()];
    }

    keuangan_perbaikan_kas_invalidate_cache();

    return ['ok' => true, 'message' => 'Pengeluaran #' . $id . ' — akun kas diperbarui.'];
}

/**
 * @return array{ok:bool,message:string,perbaikan:int}
 */
function keuangan_perbaikan_kas_patch_semua_tanpa_akun(PDO $pdo, int $akunId, int $userId): array
{
    $ringkas = keuangan_perbaikan_kas_ringkas($pdo);
    $perbaikan = 0;
    $errors = [];

    foreach ($ringkas['pembayaran'] as $row) {
        $res = keuangan_perbaikan_kas_patch_akun($pdo, 'pembayaran', (int) ($row['id'] ?? 0), $akunId, $userId);
        if ($res['ok']) {
            ++$perbaikan;
        } else {
            $errors[] = $res['message'];
        }
    }
    foreach ($ringkas['pemasukan'] as $row) {
        $res = keuangan_perbaikan_kas_patch_akun($pdo, 'pemasukan', (int) ($row['id'] ?? 0), $akunId, $userId);
        if ($res['ok']) {
            ++$perbaikan;
        } else {
            $errors[] = $res['message'];
        }
    }
    foreach ($ringkas['pengeluaran'] as $row) {
        $res = keuangan_perbaikan_kas_patch_akun($pdo, 'pengeluaran', (int) ($row['id'] ?? 0), $akunId, $userId);
        if ($res['ok']) {
            ++$perbaikan;
        } else {
            $errors[] = $res['message'];
        }
    }

    if ($perbaikan === 0 && $errors !== []) {
        return ['ok' => false, 'message' => $errors[0], 'perbaikan' => 0];
    }

    $msg = $perbaikan . ' transaksi diperbaiki.';
    if ($errors !== []) {
        $msg .= ' ' . count($errors) . ' gagal.';
    }

    return ['ok' => true, 'message' => $msg, 'perbaikan' => $perbaikan];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_hapus(PDO $pdo, string $tipe, int $id, int $userId, string $alasan): array
{
    $alasan = trim($alasan);
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan penghapusan wajib diisi.'];
    }

    $tipe = strtolower(trim($tipe));
    if ($tipe === 'pembayaran') {
        return keuangan_delete_pembayaran($pdo, $id, $userId, $alasan);
    }
    if ($tipe === 'pemasukan') {
        if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
            return ['ok' => false, 'message' => 'Hapus pemasukan memerlukan token super admin.'];
        }

        return keuangan_pemasukan_delete($pdo, $id, $userId);
    }
    if ($tipe === 'pengeluaran') {
        if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
            return ['ok' => false, 'message' => 'Hapus pengeluaran memerlukan token super admin.'];
        }

        return keuangan_pengeluaran_delete($pdo, $id, $userId);
    }

    return ['ok' => false, 'message' => 'Jenis transaksi tidak dikenali.'];
}

function keuangan_perbaikan_kas_invalidate_cache(): void
{
    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();
}

function keuangan_perbaikan_kas_edit_url(string $tipe, int $id): string
{
    return match (strtolower($tipe)) {
        'pembayaran' => app_href('/pembayaran/riwayat_edit.php?id=' . $id),
        'pemasukan' => app_href('/keuangan/riwayat_pemasukan.php?edit=' . $id),
        'pengeluaran' => app_href('/keuangan/riwayat_pengeluaran.php?edit=' . $id),
        default => app_href('/keuangan/perbaikan-kas.php'),
    };
}
