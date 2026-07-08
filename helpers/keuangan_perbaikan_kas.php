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
require_once __DIR__ . '/operasional_audit.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_rekap.php';

/**
 * @return array{
 *   jumlah:int,
 *   nominal:int,
 *   pembayaran:list<array<string,mixed>>,
 *   pemasukan:list<array<string,mixed>>,
 *   pengeluaran:list<array<string,mixed>>,
 *   duplikat:list<array<string,mixed>>,
 *   nominal_berlebihan:list<array<string,mixed>>,
 *   total_detail_selisih:list<array<string,mixed>>
 * }
 */
function keuangan_perbaikan_kas_ringkas(PDO $pdo, ?string $asOf = null): array
{
    $asOf = $asOf !== null && $asOf !== '' ? date('Y-m-d', strtotime($asOf) ?: time()) : date('Y-m-d');
    $pembayaran = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pembayaran', 'tanggal_bayar', 'total_nominal', $asOf);
    $pemasukan = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pemasukan', 'tanggal', 'nominal', $asOf);
    $pengeluaran = keuangan_perbaikan_kas_list_tanpa_akun($pdo, 'keuangan_pengeluaran', 'tanggal', 'nominal', $asOf);
    $duplikat = keuangan_perbaikan_kas_list_duplikat_mungkin($pdo);
    $nominalBerlebihan = keuangan_perbaikan_kas_list_nominal_berlebihan($pdo);
    $totalDetailSelisih = keuangan_perbaikan_kas_list_total_detail_selisih($pdo);

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
        'nominal_berlebihan' => $nominalBerlebihan,
        'total_detail_selisih' => $totalDetailSelisih,
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

/**
 * Pembayaran pos wajib/awal tahun yang total terbayar melebihi tagihan periode (data lama sebelum validasi ketat).
 *
 * @return list<array<string,mixed>>
 */
function keuangan_perbaikan_kas_list_nominal_berlebihan(PDO $pdo, int $limit = 80): array
{
    if (
        !table_exists($pdo, 'keuangan_pembayaran')
        || !table_exists($pdo, 'keuangan_pembayaran_detail')
        || !table_exists($pdo, 'santri')
    ) {
        return [];
    }

    $st = $pdo->query('
        SELECT p.santri_id, s.nis, s.nama_santri,
               UPPER(TRIM(p.jenis_periode)) AS jenis_periode,
               p.bulan_tagihan,
               p.tahun_ajaran_mulai, p.tahun_ajaran_selesai,
               LOWER(TRIM(d.pos_slug)) AS pos_slug,
               MAX(d.pos_nama) AS pos_nama,
               SUM(d.nominal) AS total_paid,
               MAX(p.tanggal_bayar) AS tanggal_terakhir
        FROM keuangan_pembayaran p
        INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE LOWER(TRIM(d.pos_slug)) <> \'saku\'
          AND LOWER(TRIM(d.pos_slug)) <> \'\'
        GROUP BY p.santri_id, s.nis, s.nama_santri,
                 UPPER(TRIM(p.jenis_periode)), p.bulan_tagihan,
                 p.tahun_ajaran_mulai, p.tahun_ajaran_selesai,
                 LOWER(TRIM(d.pos_slug))
        HAVING SUM(d.nominal) > 0
        ORDER BY MAX(p.tanggal_bayar) DESC, p.santri_id ASC
        LIMIT 400
    ');
    if (!$st) {
        return [];
    }

    $biayaDefinitions = keuangan_biaya_definitions();
    $wajibSlugs = keuangan_tagihan_wajib_slugs();
    $out = [];
    $seen = [];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (count($out) >= max(1, min(150, $limit))) {
            break;
        }

        $santriId = (int) ($row['santri_id'] ?? 0);
        $jenisPeriode = strtoupper(trim((string) ($row['jenis_periode'] ?? 'BULANAN')));
        $bulanTagihan = (int) ($row['bulan_tagihan'] ?? 0);
        $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
        $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
        $slug = strtolower(trim((string) ($row['pos_slug'] ?? '')));
        $totalPaid = (int) round((float) ($row['total_paid'] ?? 0));
        if ($santriId <= 0 || $slug === '' || $totalPaid <= 0 || $tm <= 0) {
            continue;
        }

        $isWajibBulanan = $jenisPeriode === 'BULANAN' && in_array($slug, $wajibSlugs, true);
        if (!$isWajibBulanan && $jenisPeriode !== 'AWAL_TAHUN') {
            continue;
        }

        $breakdown = keuangan_tagihan_breakdown_for_santri(
            $pdo,
            $santriId,
            $jenisPeriode,
            $bulanTagihan,
            $tm,
            $ts,
            $biayaDefinitions
        );
        $info = $breakdown[$slug] ?? null;
        if (!is_array($info)) {
            continue;
        }
        $expected = (int) ($info['expected'] ?? 0);
        if ($expected <= 0 || $totalPaid <= $expected) {
            continue;
        }

        $excess = $totalPaid - $expected;
        $pembayaranIds = keuangan_perbaikan_kas_pembayaran_ids_for_pos(
            $pdo,
            $santriId,
            $jenisPeriode,
            $bulanTagihan,
            $tm,
            $ts,
            $slug
        );
        $pembayaranId = $pembayaranIds !== [] ? (int) end($pembayaranIds) : 0;
        $dedupeKey = $santriId . '|' . $jenisPeriode . '|' . $bulanTagihan . '|' . $tm . '|' . $ts . '|' . $slug;
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $out[] = [
            'pembayaran_id' => $pembayaranId,
            'pembayaran_ids' => $pembayaranIds,
            'santri_id' => $santriId,
            'nis' => (string) ($row['nis'] ?? ''),
            'nama_santri' => (string) ($row['nama_santri'] ?? ''),
            'jenis_periode' => $jenisPeriode,
            'bulan_tagihan' => $bulanTagihan,
            'tahun_ajaran_mulai' => $tm,
            'tahun_ajaran_selesai' => $ts,
            'pos_slug' => $slug,
            'pos_nama' => (string) ($row['pos_nama'] ?? $slug),
            'expected' => $expected,
            'total_paid' => $totalPaid,
            'kelebihan' => $excess,
            'tanggal_terakhir' => (string) ($row['tanggal_terakhir'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return list<int>
 */
function keuangan_perbaikan_kas_pembayaran_ids_for_pos(
    PDO $pdo,
    int $santriId,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    string $posSlug
): array {
    $st = $pdo->prepare('
        SELECT DISTINCT p.id
        FROM keuangan_pembayaran p
        INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
        WHERE p.santri_id = :sid
          AND UPPER(TRIM(p.jenis_periode)) = :jp
          AND p.bulan_tagihan = :bln
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND LOWER(TRIM(d.pos_slug)) = :slug
        ORDER BY p.tanggal_bayar ASC, p.id ASC
    ');
    $st->execute([
        'sid' => $santriId,
        'jp' => strtoupper(trim($jenisPeriode)),
        'bln' => $bulanTagihan,
        'tm' => $tahunMulai,
        'ts' => $tahunSelesai,
        'slug' => strtolower(trim($posSlug)),
    ]);

    return array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Pembayaran yang total_nominal tidak sama dengan jumlah baris detail.
 *
 * @return list<array<string,mixed>>
 */
function keuangan_perbaikan_kas_list_total_detail_selisih(PDO $pdo, int $limit = 50): array
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return [];
    }

    $joinSantri = table_exists($pdo, 'santri')
        ? ' LEFT JOIN santri s ON s.id = p.santri_id'
        : '';
    $colsSantri = table_exists($pdo, 'santri') ? ', s.nis, s.nama_santri' : ", '' AS nis, '' AS nama_santri";

    $st = $pdo->query('
        SELECT p.id, p.tanggal_bayar, p.total_nominal,
               COALESCE(SUM(d.nominal), 0) AS sum_detail' . $colsSantri . '
        FROM keuangan_pembayaran p
        LEFT JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id' . $joinSantri . '
        GROUP BY p.id, p.tanggal_bayar, p.total_nominal' . (table_exists($pdo, 'santri') ? ', s.nis, s.nama_santri' : '') . '
        HAVING ABS(p.total_nominal - COALESCE(SUM(d.nominal), 0)) >= 1
        ORDER BY p.tanggal_bayar DESC, p.id DESC
        LIMIT ' . max(1, min(100, $limit))
    );

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
    if (!function_exists('keuangan_pembayaran_pos_slug_normalize')) {
        require_once __DIR__ . '/keuangan_pembayaran_admin.php';
    }
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
            'slug' => keuangan_pembayaran_pos_slug_normalize((string) ($det['pos_slug'] ?? '')),
            'nama' => (string) ($det['pos_nama'] ?? ''),
            'nominal' => (int) round((float) ($det['nominal'] ?? 0)),
        ];
    }
    $jenisPeriode = strtoupper((string) ($row['jenis_periode'] ?? 'BULANAN'));
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    $santriId = (int) ($row['santri_id'] ?? 0);

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

    if (!function_exists('keuangan_pembayaran_apply_cashless_saku')) {
        require_once __DIR__ . '/keuangan_pembayaran_admin.php';
    }
    keuangan_pembayaran_apply_cashless_saku($pdo, $id, $santriId, $detailRows, $userId);

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

        return keuangan_pemasukan_delete($pdo, $id, $userId, $alasan);
    }
    if ($tipe === 'pengeluaran') {
        if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
            return ['ok' => false, 'message' => 'Hapus pengeluaran memerlukan token super admin.'];
        }

        return keuangan_pengeluaran_delete($pdo, $id, $userId, $alasan);
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

function keuangan_perbaikan_kas_tipe_from_modul(string $modul): ?string
{
    return match ($modul) {
        OPERASIONAL_AUDIT_MODUL_KEUANGAN => 'pembayaran',
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN => 'pemasukan',
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PENGELUARAN => 'pengeluaran',
        default => null,
    };
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_perbaikan_kas_list_riwayat_hapus(PDO $pdo, int $limit = 50): array
{
    $rows = operasional_audit_list_deleted_kas($pdo, $limit);
    $out = [];
    foreach ($rows as $log) {
        if (operasional_audit_is_restored($log)) {
            continue;
        }
        $modul = (string) ($log['modul'] ?? '');
        $tipe = keuangan_perbaikan_kas_tipe_from_modul($modul);
        if ($tipe === null) {
            continue;
        }
        $sebelum = json_decode((string) ($log['data_sebelum'] ?? '{}'), true);
        if (!is_array($sebelum)) {
            $sebelum = [];
        }
        $entityId = (int) ($log['entity_id'] ?? ($sebelum['id'] ?? 0));
        $out[] = [
            'audit_id' => (int) ($log['id'] ?? 0),
            'tipe' => $tipe,
            'entity_id' => $entityId,
            'modul' => $modul,
            'ringkas' => operasional_audit_ringkas_kas($modul, $sebelum),
            'alasan' => (string) ($log['alasan'] ?? ''),
            'user_nama' => (string) ($log['user_nama'] ?? ''),
            'created_at' => (string) ($log['created_at'] ?? ''),
            'data_sebelum' => $sebelum,
        ];
    }

    return $out;
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_perbaikan_kas_restore(PDO $pdo, int $auditLogId, int $userId): array
{
    if ($auditLogId <= 0) {
        return ['ok' => false, 'message' => 'Log riwayat tidak valid.'];
    }
    ensure_operasional_audit_table($pdo);
    $st = $pdo->prepare('SELECT * FROM operasional_audit_log WHERE id = :id LIMIT 1');
    $st->execute(['id' => $auditLogId]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        return ['ok' => false, 'message' => 'Riwayat hapus tidak ditemukan.'];
    }
    if ((string) ($log['aksi'] ?? '') !== 'DELETE') {
        return ['ok' => false, 'message' => 'Hanya transaksi yang dihapus yang dapat dipulihkan.'];
    }
    if (operasional_audit_is_restored($log)) {
        return ['ok' => false, 'message' => 'Transaksi ini sudah pernah dipulihkan.'];
    }

    $modul = (string) ($log['modul'] ?? '');
    $tipe = keuangan_perbaikan_kas_tipe_from_modul($modul);
    if ($tipe === null) {
        return ['ok' => false, 'message' => 'Jenis transaksi tidak didukung untuk pemulihan.'];
    }

    $data = json_decode((string) ($log['data_sebelum'] ?? '{}'), true);
    if (!is_array($data) || $data === []) {
        return ['ok' => false, 'message' => 'Data cadangan transaksi kosong — tidak dapat dipulihkan.'];
    }

    ensure_keuangan_transaksi_tables($pdo);
    keuangan_transaksi_bootstrap_jurnal();

    if ($tipe === 'pembayaran') {
        $res = keuangan_perbaikan_kas_restore_pembayaran($pdo, $data, $userId);
    } elseif ($tipe === 'pemasukan') {
        $res = keuangan_perbaikan_kas_restore_pemasukan($pdo, $data, $userId);
    } else {
        $res = keuangan_perbaikan_kas_restore_pengeluaran($pdo, $data, $userId);
    }

    if (!$res['ok']) {
        return $res;
    }

    $entityId = (int) ($res['entity_id'] ?? ($log['entity_id'] ?? 0));
    operasional_audit_mark_restored($pdo, $auditLogId, $userId);
    operasional_audit_log(
        $pdo,
        $modul,
        'CREATE',
        $entityId,
        null,
        $data,
        $userId,
        'Pulihkan dari riwayat hapus (log #' . $auditLogId . ')'
    );
    keuangan_perbaikan_kas_invalidate_cache();

    return [
        'ok' => true,
        'message' => 'Transaksi #' . $entityId . ' (' . $tipe . ') berhasil dipulihkan.',
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,message:string,entity_id?:int}
 */
function keuangan_perbaikan_kas_restore_pembayaran(PDO $pdo, array $data, int $userId): array
{
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID pembayaran pada cadangan tidak valid.'];
    }
    if (keuangan_pembayaran_fetch($pdo, $id) !== null) {
        return ['ok' => false, 'message' => 'Pembayaran #' . $id . ' sudah ada — tidak dapat dipulihkan ganda.'];
    }

    $details = is_array($data['details'] ?? null) ? $data['details'] : [];
    $skip = ['details', 'cashless_tx', 'nis', 'nama_santri', 'kategori_kelas', 'akun_nama'];
    $cols = [];
    $vals = [];
    $params = ['id' => $id];
    foreach ($data as $key => $value) {
        if (!is_string($key) || in_array($key, $skip, true) || !column_exists($pdo, 'keuangan_pembayaran', $key)) {
            continue;
        }
        $cols[] = $key;
        $vals[] = ':' . $key;
        $params[$key] = $value;
    }
    if (!in_array('id', $cols, true)) {
        array_unshift($cols, 'id');
        array_unshift($vals, ':id');
    }

    $santriId = (int) ($data['santri_id'] ?? 0);
    $akunId = (int) ($data['akun_id'] ?? 0);
    $tanggal = (string) ($data['tanggal_bayar'] ?? date('Y-m-d'));
    $total = (int) round((float) ($data['total_nominal'] ?? 0));
    $jenisPeriode = strtoupper((string) ($data['jenis_periode'] ?? 'BULANAN'));
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    $detailRows = [];
    foreach ($details as $det) {
        if (!is_array($det)) {
            continue;
        }
        $detailRows[] = [
            'slug' => keuangan_pembayaran_pos_slug_normalize((string) ($det['pos_slug'] ?? '')),
            'nama' => (string) ($det['pos_nama'] ?? ''),
            'nominal' => (int) round((float) ($det['nominal'] ?? 0)),
        ];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO keuangan_pembayaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')')
            ->execute($params);

        $insDet = $pdo->prepare('
            INSERT INTO keuangan_pembayaran_detail (pembayaran_id, pos_slug, pos_nama, nominal)
            VALUES (:pembayaran_id, :pos_slug, :pos_nama, :nominal)
        ');
        foreach ($detailRows as $dr) {
            $insDet->execute([
                'pembayaran_id' => $id,
                'pos_slug' => $dr['slug'],
                'pos_nama' => $dr['nama'],
                'nominal' => $dr['nominal'],
            ]);
        }

        keuangan_pembayaran_apply_cashless_saku($pdo, $id, $santriId, $detailRows, $userId);

        if ($akunId > 0 && $detailRows !== []) {
            keuangan_jurnal_pembayaran($pdo, $id, $tanggal, $akunId, $total, $detailRows, $kategoriFilter, $userId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memulihkan pembayaran: ' . $e->getMessage()];
    }

    return ['ok' => true, 'message' => 'OK', 'entity_id' => $id];
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,message:string,entity_id?:int}
 */
function keuangan_perbaikan_kas_restore_pemasukan(PDO $pdo, array $data, int $userId): array
{
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID pemasukan pada cadangan tidak valid.'];
    }
    if (keuangan_pemasukan_get($pdo, $id) !== null) {
        return ['ok' => false, 'message' => 'Pemasukan #' . $id . ' sudah ada — tidak dapat dipulihkan ganda.'];
    }

    $skip = ['akun_nama'];
    $cols = [];
    $vals = [];
    $params = ['id' => $id];
    foreach ($data as $key => $value) {
        if (!is_string($key) || in_array($key, $skip, true) || !column_exists($pdo, 'keuangan_pemasukan', $key)) {
            continue;
        }
        $cols[] = $key;
        $vals[] = ':' . $key;
        $params[$key] = $value;
    }
    if (!in_array('id', $cols, true)) {
        array_unshift($cols, 'id');
        array_unshift($vals, ':id');
    }

    $akunId = (int) ($data['akun_id'] ?? 0);
    $tanggal = (string) ($data['tanggal'] ?? date('Y-m-d'));
    $nominal = (int) round((float) ($data['nominal'] ?? 0));
    $sumber = (string) ($data['sumber'] ?? '');

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO keuangan_pemasukan (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')')
            ->execute($params);
        if ($akunId > 0 && $nominal > 0) {
            keuangan_jurnal_pemasukan($pdo, $id, $tanggal, $akunId, $nominal, $sumber, $userId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memulihkan pemasukan: ' . $e->getMessage()];
    }

    return ['ok' => true, 'message' => 'OK', 'entity_id' => $id];
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,message:string,entity_id?:int}
 */
function keuangan_perbaikan_kas_restore_pengeluaran(PDO $pdo, array $data, int $userId): array
{
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID pengeluaran pada cadangan tidak valid.'];
    }
    if (keuangan_pengeluaran_get($pdo, $id) !== null) {
        return ['ok' => false, 'message' => 'Pengeluaran #' . $id . ' sudah ada — tidak dapat dipulihkan ganda.'];
    }

    $skip = ['akun_nama'];
    $cols = [];
    $vals = [];
    $params = ['id' => $id];
    foreach ($data as $key => $value) {
        if (!is_string($key) || in_array($key, $skip, true) || !column_exists($pdo, 'keuangan_pengeluaran', $key)) {
            continue;
        }
        $cols[] = $key;
        $vals[] = ':' . $key;
        $params[$key] = $value;
    }
    if (!in_array('id', $cols, true)) {
        array_unshift($cols, 'id');
        array_unshift($vals, ':id');
    }

    $akunId = (int) ($data['akun_id'] ?? 0);
    $tanggal = (string) ($data['tanggal'] ?? date('Y-m-d'));
    $nominal = (int) round((float) ($data['nominal'] ?? 0));
    $pos = (string) ($data['pos'] ?? '');

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO keuangan_pengeluaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')')
            ->execute($params);
        if ($akunId > 0 && $nominal > 0) {
            keuangan_jurnal_pengeluaran($pdo, $id, $tanggal, $akunId, $nominal, $pos, $userId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memulihkan pengeluaran: ' . $e->getMessage()];
    }

    return ['ok' => true, 'message' => 'OK', 'entity_id' => $id];
}
