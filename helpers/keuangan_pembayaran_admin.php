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

/**
 * @param list<array{slug:string,nama:string,nominal:int}> $detailRows
 */
function keuangan_pembayaran_apply_cashless_saku(PDO $pdo, int $pembayaranId, int $santriId, array $detailRows, int $userId): void
{
    if ($pembayaranId <= 0 || $santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    $hasSaku = array_filter($detailRows, static fn(array $r): bool => ($r['slug'] ?? '') === 'saku' && (int) ($r['nominal'] ?? 0) > 0);
    if ($hasSaku === []) {
        return;
    }
    $topupNominal = (int) array_sum(array_map(static fn(array $r): int => (int) $r['nominal'], $hasSaku));
    if (table_exists($pdo, 'cashless_transactions') && column_exists($pdo, 'cashless_transactions', 'ref_pembayaran_id')) {
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
    }
    require_once __DIR__ . '/cashless_koperasi.php';
    require_once __DIR__ . '/cashless_wa.php';
    cashless_sync_account_balance($pdo, $santriId);
    cashless_wa_maybe_notify_saldo_rendah($pdo, $santriId, (float) cashless_santri_saldo_tampil($pdo, $santriId));
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
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank penerimaan.'];
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
            'slug' => $slug,
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
            if ($dr['slug'] === 'saku') {
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
