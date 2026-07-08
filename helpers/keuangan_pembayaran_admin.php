<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_jurnal.php';
require_once __DIR__ . '/operasional_audit.php';

function ensure_keuangan_pembayaran_audit_table(PDO $pdo): void
{
    ensure_operasional_audit_table($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_pembayaran_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembayaran_id INT NULL,
            aksi ENUM('UPDATE','DELETE') NOT NULL,
            data_sebelum JSON NOT NULL,
            data_sesudah JSON NULL,
            alasan TEXT NOT NULL,
            user_id INT NULL,
            user_nama VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kpa_pembayaran (pembayaran_id),
            INDEX idx_kpa_created (created_at),
            INDEX idx_kpa_aksi (aksi)
        )
    ");
}

function keuangan_pembayaran_audit_user_nama(): string
{
    return operasional_audit_user_nama();
}

/** @return array<string, mixed>|null */
function keuangan_pembayaran_fetch(PDO $pdo, int $pembayaranId): ?array
{
    if ($pembayaranId <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return null;
    }
    $kkCol = column_exists($pdo, 'santri', 'kategori_kelas') ? 's.kategori_kelas' : "'' AS kategori_kelas";
    $st = $pdo->prepare("
        SELECT p.*, s.nis, s.nama_santri, {$kkCol}
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $pembayaranId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['details'] = [];
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $det = $pdo->prepare('SELECT id, pos_slug, pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC');
        $det->execute(['id' => $pembayaranId]);
        $row['details'] = $det->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (table_exists($pdo, 'cashless_transactions') && column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
        $ct = $pdo->prepare('SELECT id, jenis, nominal, keterangan FROM cashless_transactions WHERE ref_pembayaran_id = :id');
        $ct->execute(['id' => $pembayaranId]);
        $row['cashless_tx'] = $ct->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $row['cashless_tx'] = [];
    }

    return $row;
}

/** @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
function keuangan_pembayaran_audit_log(
    PDO $pdo,
    string $aksi,
    int $pembayaranId,
    ?array $before,
    ?array $after,
    int $userId,
    string $alasan
): void {
    if (!$pdo->inTransaction()) {
        ensure_keuangan_pembayaran_audit_table($pdo);
    }
    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_KEUANGAN,
        $aksi === 'DELETE' ? 'DELETE' : 'UPDATE',
        $pembayaranId,
        $before,
        $after,
        $userId,
        $alasan
    );
}

function keuangan_pembayaran_reverse_cashless(PDO $pdo, int $pembayaranId): void
{
    if ($pembayaranId <= 0 || !table_exists($pdo, 'cashless_transactions') || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    if (!column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
        return;
    }
    $st = $pdo->prepare('SELECT id, santri_id, jenis, nominal FROM cashless_transactions WHERE ref_pembayaran_id = :id');
    $st->execute(['id' => $pembayaranId]);
    $affectedSantri = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        $sid = (int) ($tx['santri_id'] ?? 0);
        if ($sid > 0) {
            $affectedSantri[$sid] = true;
        }
        $pdo->prepare('DELETE FROM cashless_transactions WHERE id = :id')->execute(['id' => (int) $tx['id']]);
    }
    if ($affectedSantri !== []) {
        require_once __DIR__ . '/cashless_koperasi.php';
        foreach (array_keys($affectedSantri) as $sid) {
            cashless_sync_account_balance($pdo, $sid);
        }
    }
}

function keuangan_pembayaran_pos_slug_normalize(string $slug): string
{
    return strtolower(trim($slug));
}

/** @param array{slug?:string,pos_slug?:string,nominal?:int|float|string} $row */
function keuangan_pembayaran_detail_is_saku(array $row): bool
{
    $slug = (string) ($row['slug'] ?? $row['pos_slug'] ?? '');

    return keuangan_pembayaran_pos_slug_normalize($slug) === 'saku' && (int) round((float) ($row['nominal'] ?? 0)) > 0;
}

/**
 * @param list<array{slug?:string,pos_slug?:string,nama?:string,pos_nama?:string,nominal?:int|float}> $detailRows
 * @return list<array{slug:string,nama:string,nominal:int}>
 */
function keuangan_pembayaran_detail_rows_normalize(array $detailRows): array
{
    $out = [];
    foreach ($detailRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = keuangan_pembayaran_pos_slug_normalize((string) ($row['slug'] ?? $row['pos_slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $out[] = [
            'slug' => $slug,
            'nama' => (string) ($row['nama'] ?? $row['pos_nama'] ?? $slug),
            'nominal' => (int) round((float) ($row['nominal'] ?? 0)),
        ];
    }

    return $out;
}

/**
 * Pembayaran pos Saku yang belum punya baris TOPUP cashless.
 *
 * @return list<array{pembayaran_id:int,santri_id:int,nama_santri:string,nominal_saku:int,tanggal_bayar:string}>
 */
function keuangan_pembayaran_list_saku_tanpa_topup(PDO $pdo, int $limit = 500): array
{
    if (!table_exists($pdo, 'keuangan_pembayaran_detail') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return [];
    }
    if (!table_exists($pdo, 'cashless_transactions') || !column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
        return [];
    }
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $sql = "
        SELECT p.id AS pembayaran_id, p.santri_id, {$nameExpr} AS nama_santri, p.tanggal_bayar,
               COALESCE(SUM(d.nominal), 0) AS nominal_saku
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN cashless_transactions ct ON ct.ref_pembayaran_id = p.id AND UPPER(ct.jenis) = 'TOPUP'
        WHERE LOWER(TRIM(d.pos_slug)) = 'saku' AND d.nominal > 0 AND ct.id IS NULL
        GROUP BY p.id, p.santri_id, {$nameExpr}, p.tanggal_bayar
        ORDER BY p.id ASC
        LIMIT " . max(1, min(5000, $limit));
    $st = $pdo->query($sql);

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @return array{ok:bool,processed:int,success:int,failed:int,message:string,rows:list<array<string,mixed>>}
 */
function keuangan_pembayaran_backfill_saku_topup(PDO $pdo, int $userId, bool $dryRun = true, int $limit = 500): array
{
    $orphans = keuangan_pembayaran_list_saku_tanpa_topup($pdo, $limit);
    $rows = [];
    $success = 0;
    $failed = 0;

    foreach ($orphans as $orphan) {
        $pembayaranId = (int) ($orphan['pembayaran_id'] ?? 0);
        $santriId = (int) ($orphan['santri_id'] ?? 0);
        $nominalSaku = (int) round((float) ($orphan['nominal_saku'] ?? 0));
        $nama = (string) ($orphan['nama_santri'] ?? '');
        if ($pembayaranId <= 0 || $santriId <= 0) {
            continue;
        }

        if ($dryRun) {
            $rows[] = [
                'pembayaran_id' => $pembayaranId,
                'santri_id' => $santriId,
                'nama_santri' => $nama,
                'nominal_saku' => $nominalSaku,
                'status' => 'DRY_RUN',
            ];
            $success++;
            continue;
        }

        $fetch = keuangan_pembayaran_fetch($pdo, $pembayaranId);
        $detailRows = keuangan_pembayaran_detail_rows_normalize(is_array($fetch['details'] ?? null) ? $fetch['details'] : []);
        $applied = keuangan_pembayaran_apply_cashless_saku($pdo, $pembayaranId, $santriId, $detailRows, $userId);
        $ok = $applied || keuangan_pembayaran_cashless_topup_exists($pdo, $pembayaranId);
        if ($ok) {
            $success++;
            $status = 'OK';
        } else {
            $failed++;
            $status = 'GAGAL';
        }
        $rows[] = [
            'pembayaran_id' => $pembayaranId,
            'santri_id' => $santriId,
            'nama_santri' => $nama,
            'nominal_saku' => $nominalSaku,
            'status' => $status,
        ];
    }

    $processed = count($rows);
    $msg = $dryRun
        ? 'Dry-run: ' . $processed . ' pembayaran saku tanpa top-up ditemukan.'
        : 'Backfill selesai: ' . $success . ' berhasil, ' . $failed . ' gagal dari ' . $processed . ' pembayaran.';

    return [
        'ok' => $dryRun ? $processed >= 0 : $failed === 0,
        'processed' => $processed,
        'success' => $success,
        'failed' => $failed,
        'message' => $msg,
        'rows' => $rows,
    ];
}

function keuangan_pembayaran_cashless_topup_exists(PDO $pdo, int $pembayaranId): bool
{
    if ($pembayaranId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return false;
    }
    if (!column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
        return false;
    }
    $st = $pdo->prepare("
        SELECT 1 FROM cashless_transactions
        WHERE ref_pembayaran_id = :id AND UPPER(jenis) = 'TOPUP'
        LIMIT 1
    ");
    $st->execute(['id' => $pembayaranId]);

    return (bool) $st->fetchColumn();
}

/**
 * @param list<array{slug?:string,pos_slug?:string,nama?:string,nominal?:int}> $detailRows
 */
function keuangan_pembayaran_apply_cashless_saku(PDO $pdo, int $pembayaranId, int $santriId, array $detailRows, int $userId): bool
{
    if ($pembayaranId <= 0 || $santriId <= 0) {
        return false;
    }
    $detailRows = keuangan_pembayaran_detail_rows_normalize($detailRows);
    $hasSaku = array_filter($detailRows, static fn(array $r): bool => keuangan_pembayaran_detail_is_saku($r));
    if ($hasSaku === []) {
        return true;
    }
    if (keuangan_pembayaran_cashless_topup_exists($pdo, $pembayaranId)) {
        return true;
    }
    if (!table_exists($pdo, 'cashless_accounts')) {
        return false;
    }
    if (!table_exists($pdo, 'cashless_transactions') || !column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
        return false;
    }
    $topupNominal = (int) array_sum(array_map(static fn(array $r): int => (int) $r['nominal'], $hasSaku));
    $pdo->prepare('INSERT IGNORE INTO cashless_accounts (santri_id, balance) VALUES (:santri_id, 0)')
        ->execute(['santri_id' => $santriId]);
    $pdo->prepare("
        INSERT INTO cashless_transactions (santri_id, jenis, nominal, keterangan, ref_pembayaran_id, created_by)
        VALUES (:santri_id, 'TOPUP', :nominal, :keterangan, :ref_pembayaran_id, :created_by)
    ")->execute([
        'santri_id' => $santriId,
        'nominal' => $topupNominal,
        'keterangan' => 'Topup otomatis dari pembayaran pos Saku',
        'ref_pembayaran_id' => $pembayaranId,
        'created_by' => $userId > 0 ? $userId : null,
    ]);
    if ((int) $pdo->lastInsertId() <= 0 && !keuangan_pembayaran_cashless_topup_exists($pdo, $pembayaranId)) {
        return false;
    }
    require_once __DIR__ . '/cashless_koperasi.php';
    require_once __DIR__ . '/cashless_wa.php';
    cashless_sync_account_balance($pdo, $santriId);
    cashless_wa_maybe_notify_saldo_rendah($pdo, $santriId, (float) cashless_santri_saldo_tampil($pdo, $santriId));

    return keuangan_pembayaran_cashless_topup_exists($pdo, $pembayaranId);
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_update_pembayaran(PDO $pdo, int $pembayaranId, array $post, int $userId, string $alasan): array
{
    ensure_keuangan_transaksi_tables($pdo);
    ensure_keuangan_jurnal_tables($pdo);
    ensure_keuangan_pembayaran_audit_table($pdo);

    $before = keuangan_pembayaran_fetch($pdo, $pembayaranId);
    if ($before === null) {
        return ['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'];
    }

    $alasan = trim($alasan);
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan koreksi wajib diisi.'];
    }

    $santriId = (int) ($before['santri_id'] ?? 0);
    $biayaDefinitions = keuangan_biaya_definitions();
    $jenisPeriode = strtoupper(trim((string) ($post['jenis_periode'] ?? $before['jenis_periode'] ?? 'BULANAN')));
    $bulanTagihan = (int) ($post['bulan_tagihan'] ?? $before['bulan_tagihan'] ?? 0);
    require_once __DIR__ . '/pondok_kalender.php';
    $taInput = pondok_normalisasi_tahun_ajaran_input(
        $pdo,
        (int) ($post['tahun_ajaran_mulai'] ?? $before['tahun_ajaran_mulai'] ?? 0),
        (int) ($post['tahun_ajaran_selesai'] ?? $before['tahun_ajaran_selesai'] ?? 0)
    );
    $tahunMulai = $taInput['mulai'];
    $tahunSelesai = $taInput['selesai'];
    $tanggalBayar = trim((string) ($post['tanggal_bayar'] ?? $before['tanggal_bayar'] ?? date('Y-m-d')));
    $keterangan = trim((string) ($post['keterangan'] ?? $before['keterangan'] ?? ''));
    $metodeBayar = strtoupper(trim((string) ($post['metode_bayar'] ?? $before['metode_bayar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? $before['akun_id'] ?? 0);
    $noReferensi = trim((string) ($post['no_referensi'] ?? $before['no_referensi'] ?? ''));

    if (!in_array($jenisPeriode, ['BULANAN', 'AWAL_TAHUN'], true)) {
        $jenisPeriode = 'BULANAN';
    }
    if ($jenisPeriode !== 'BULANAN') {
        $bulanTagihan = 0;
    } elseif ($bulanTagihan < 1 || $bulanTagihan > 12) {
        $bulanTagihan = (int) ($before['bulan_tagihan'] ?? keuangan_bulan_berjalan(null, $pdo));
    }
    $kalenderHijriyahBayar = $jenisPeriode === 'BULANAN'
        ? pondok_kalender_hijriyah_untuk_simpan_pembayaran($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan)
        : null;
    if (!in_array($metodeBayar, ['KAS', 'TRANSFER'], true)) {
        $metodeBayar = 'KAS';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalBayar)) {
        $tanggalBayar = (string) ($before['tanggal_bayar'] ?? date('Y-m-d'));
    }
    $akunErr = keuangan_validasi_akun_kas_id($pdo, $akunId);
    if ($akunErr !== null) {
        return ['ok' => false, 'message' => $akunErr];
    }
    if ($metodeBayar === 'TRANSFER' && $noReferensi === '') {
        return ['ok' => false, 'message' => 'Nomor referensi transfer wajib diisi.'];
    }

    $pickedPos = $post['bayar_pos'] ?? [];
    if (!is_array($pickedPos)) {
        $pickedPos = [];
    }
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';

    $totalNominal = 0;
    $detailRows = [];
    foreach ($biayaDefinitions as $def) {
        if (($def['kategori'] ?? '') !== $kategoriFilter) {
            continue;
        }
        $slug = (string) ($def['slug'] ?? '');
        if (!in_array($slug, $pickedPos, true)) {
            continue;
        }
        $nominal = keuangan_money_input_to_int((string) ($post['nominal_' . $slug] ?? '0'));
        if ($nominal <= 0) {
            continue;
        }
        if (!function_exists('keuangan_pos_display_nama')) {
            require_once __DIR__ . '/keuangan_kelas_makan.php';
        }
        $detailRows[] = [
            'slug' => keuangan_pembayaran_pos_slug_normalize($slug),
            'nama' => keuangan_pos_display_nama($pdo, $slug, (string) ($def['nama'] ?? $slug)),
            'nominal' => $nominal,
        ];
        $totalNominal += $nominal;
    }
    if ($detailRows === []) {
        return ['ok' => false, 'message' => 'Minimal satu komponen pembayaran dengan nominal valid.'];
    }

    $paidAdjustBySlug = [];
    foreach ($before['details'] as $oldDet) {
        $oldSlug = strtolower(trim((string) ($oldDet['pos_slug'] ?? '')));
        if ($oldSlug === '') {
            continue;
        }
        $paidAdjustBySlug[$oldSlug] = ($paidAdjustBySlug[$oldSlug] ?? 0)
            + (int) round((float) ($oldDet['nominal'] ?? 0));
    }
    keuangan_transaksi_bootstrap_rekap();
    $antiDobel = keuangan_pembayaran_validasi_anti_dobel(
        $pdo,
        $santriId,
        $jenisPeriode,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $detailRows,
        $biayaDefinitions,
        $paidAdjustBySlug
    );
    if (!$antiDobel['ok']) {
        return $antiDobel;
    }

    if (
        $jenisPeriode === 'BULANAN'
        && $bulanTagihan > 0
        && (int) ($before['bulan_tagihan'] ?? 0) !== $bulanTagihan
    ) {
        $urutan = keuangan_pembayaran_validasi_urutan_bulan($pdo, $santriId, $bulanTagihan, $tahunMulai, $tahunSelesai);
        if (!$urutan['ok']) {
            return $urutan;
        }
    }

    $statusLunas = 'LUNAS';
    if (column_exists($pdo, 'keuangan_pembayaran', 'status_lunas')) {
        keuangan_transaksi_bootstrap_rekap();
        $tagihanBreakdown = keuangan_tagihan_breakdown_for_santri(
            $pdo,
            $santriId,
            $jenisPeriode,
            $bulanTagihan,
            $tahunMulai,
            $tahunSelesai,
            $biayaDefinitions
        );
        $stillHasSisaWajib = false;
        foreach ($detailRows as $dr) {
            if (keuangan_pembayaran_detail_is_saku($dr)) {
                continue;
            }
            $info = $tagihanBreakdown[$dr['slug']] ?? null;
            if (!is_array($info)) {
                continue;
            }
            $paidBefore = (int) ($info['paid'] ?? 0);
            $expected = (int) ($info['expected'] ?? 0);
            foreach ($before['details'] as $oldDet) {
                if ((string) ($oldDet['pos_slug'] ?? '') === $dr['slug']) {
                    $paidBefore = max(0, $paidBefore - (int) round((float) ($oldDet['nominal'] ?? 0)));
                }
            }
            if ($expected > 0 && ($paidBefore + $dr['nominal']) < $expected) {
                $stillHasSisaWajib = true;
                break;
            }
        }
        $statusLunas = $stillHasSisaWajib ? 'CICILAN' : 'LUNAS';
    }

    ensure_operasional_audit_table($pdo);

    try {
        $pdo->beginTransaction();

        keuangan_pembayaran_reverse_cashless($pdo, $pembayaranId);
        keuangan_jurnal_delete_by_ref($pdo, 'pembayaran', $pembayaranId);

        $sets = [
            'jenis_periode = :jenis_periode',
            'tahun_ajaran_mulai = :mulai',
            'tahun_ajaran_selesai = :selesai',
            'bulan_tagihan = :bulan_tagihan',
            'tanggal_bayar = :tanggal_bayar',
            'total_nominal = :total_nominal',
            'keterangan = :keterangan',
        ];
        $params = [
            'id' => $pembayaranId,
            'jenis_periode' => $jenisPeriode,
            'mulai' => $tahunMulai,
            'selesai' => $tahunSelesai,
            'bulan_tagihan' => $bulanTagihan > 0 ? $bulanTagihan : null,
            'tanggal_bayar' => $tanggalBayar,
            'total_nominal' => $totalNominal,
            'keterangan' => $keterangan !== '' ? $keterangan : null,
        ];
        if (column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')) {
            $sets[] = 'metode_bayar = :metode_bayar';
            $params['metode_bayar'] = $metodeBayar;
        }
        if (column_exists($pdo, 'keuangan_pembayaran', 'akun_id')) {
            $sets[] = 'akun_id = :akun_id';
            $params['akun_id'] = $akunId;
        }
        if (column_exists($pdo, 'keuangan_pembayaran', 'no_referensi')) {
            $sets[] = 'no_referensi = :no_referensi';
            $params['no_referensi'] = $noReferensi !== '' ? $noReferensi : null;
        }
        if (column_exists($pdo, 'keuangan_pembayaran', 'kalender_hijriyah')) {
            $sets[] = 'kalender_hijriyah = :kalender_hijriyah';
            $params['kalender_hijriyah'] = $kalenderHijriyahBayar;
        }
        if (column_exists($pdo, 'keuangan_pembayaran', 'status_lunas')) {
            $sets[] = 'status_lunas = :status_lunas';
            $params['status_lunas'] = $statusLunas;
        }

        $pdo->prepare('UPDATE keuangan_pembayaran SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

        $pdo->prepare('DELETE FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id')->execute(['id' => $pembayaranId]);
        $insDet = $pdo->prepare('
            INSERT INTO keuangan_pembayaran_detail (pembayaran_id, pos_slug, pos_nama, nominal)
            VALUES (:pembayaran_id, :pos_slug, :pos_nama, :nominal)
        ');
        foreach ($detailRows as $dr) {
            $insDet->execute([
                'pembayaran_id' => $pembayaranId,
                'pos_slug' => $dr['slug'],
                'pos_nama' => $dr['nama'],
                'nominal' => $dr['nominal'],
            ]);
        }

        keuangan_pembayaran_apply_cashless_saku($pdo, $pembayaranId, $santriId, $detailRows, $userId);
        keuangan_jurnal_pembayaran($pdo, $pembayaranId, $tanggalBayar, $akunId, $totalNominal, $detailRows, $kategoriFilter, $userId);

        $after = keuangan_pembayaran_fetch($pdo, $pembayaranId);
        keuangan_pembayaran_audit_log($pdo, 'UPDATE', $pembayaranId, $before, $after, $userId, $alasan);

        $pdo->commit();

        if (function_exists('keuangan_dashboard_cache_invalidate')) {
            require_once __DIR__ . '/keuangan_dashboard.php';
            keuangan_dashboard_cache_invalidate();
        }

        return ['ok' => true, 'message' => 'Pembayaran #' . $pembayaranId . ' berhasil diperbarui.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan koreksi: ' . $e->getMessage()];
    }
}

/** @return array{ok:bool,message:string} */
function keuangan_delete_pembayaran(PDO $pdo, int $pembayaranId, int $userId, string $alasan): array
{
    ensure_keuangan_pembayaran_audit_table($pdo);

    $before = keuangan_pembayaran_fetch($pdo, $pembayaranId);
    if ($before === null) {
        return ['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'];
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan penghapusan wajib diisi.'];
    }

    ensure_operasional_audit_table($pdo);

    try {
        $pdo->beginTransaction();

        keuangan_pembayaran_audit_log($pdo, 'DELETE', $pembayaranId, $before, null, $userId, $alasan);
        keuangan_pembayaran_reverse_cashless($pdo, $pembayaranId);
        keuangan_jurnal_delete_by_ref($pdo, 'pembayaran', $pembayaranId);
        $pdo->prepare('DELETE FROM keuangan_pembayaran WHERE id = :id')->execute(['id' => $pembayaranId]);

        $pdo->commit();

        if (function_exists('keuangan_dashboard_cache_invalidate')) {
            require_once __DIR__ . '/keuangan_dashboard.php';
            keuangan_dashboard_cache_invalidate();
        }

        return ['ok' => true, 'message' => 'Pembayaran #' . $pembayaranId . ' telah dihapus dan dicatat di log audit.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_pembayaran_audit_list(PDO $pdo, int $limit = 300, int $pembayaranId = 0): array
{
    return operasional_audit_list($pdo, $limit, OPERASIONAL_AUDIT_MODUL_KEUANGAN, $pembayaranId);
}
