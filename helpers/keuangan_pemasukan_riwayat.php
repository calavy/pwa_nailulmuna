<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/pembayaran_edit_token.php';
require_once __DIR__ . '/operasional_audit.php';

/** @return list<array<string, mixed>> */
function keuangan_pemasukan_list(PDO $pdo, int $limit = 100, int $offset = 0): array
{
    if (!table_exists($pdo, 'keuangan_pemasukan')) {
        return [];
    }
    $limit = max(10, min(500, $limit));
    $offset = max(0, $offset);
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama, p.akun_id' : '';

    return $pdo->query("
        SELECT p.id, p.tanggal, p.sumber, p.dari_pihak, p.metode_bayar,
               p.nominal, p.keterangan, p.no_bukti, p.created_at{$akunCol}
        FROM keuangan_pemasukan p
        {$join}
        ORDER BY p.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function keuangan_pemasukan_count(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_pemasukan')) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pemasukan')->fetchColumn();
}

function keuangan_pemasukan_sum_nominal(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_pemasukan')) {
        return 0;
    }

    return (int) round((float) ($pdo->query('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan')->fetchColumn() ?: 0));
}

/** @return array<string, mixed>|null */
function keuangan_pemasukan_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !table_exists($pdo, 'keuangan_pemasukan')) {
        return null;
    }
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama, p.akun_id' : '';
    $st = $pdo->prepare("
        SELECT p.*
        FROM keuangan_pemasukan p
        {$join}
        WHERE p.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_pemasukan_update(PDO $pdo, array $post, int $userId): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
        return [
            'ok' => false,
            'message' => 'Masukkan token super admin terlebih dahulu.',
        ];
    }

    $id = (int) ($post['id'] ?? 0);
    $row = keuangan_pemasukan_get($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Data pemasukan tidak ditemukan.'];
    }

    $tanggal = trim((string) ($post['tanggal'] ?? ''));
    $sumber = trim((string) ($post['sumber'] ?? ''));
    $dariPihak = trim((string) ($post['dari_pihak'] ?? ''));
    $metodeBayar = strtoupper(trim((string) ($post['metode_bayar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $noBukti = trim((string) ($post['no_bukti'] ?? ''));
    $nominal = keuangan_money_input_to_int((string) ($post['nominal'] ?? '0'));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal tidak valid.'];
    }
    if ($sumber === '' || $nominal <= 0) {
        return ['ok' => false, 'message' => 'Sumber dan nominal wajib diisi.'];
    }
    $akunErr = keuangan_validasi_akun_kas_id($pdo, $akunId);
    if ($akunErr !== null) {
        return ['ok' => false, 'message' => $akunErr];
    }
    if (!in_array($metodeBayar, ['KAS', 'TRANSFER'], true)) {
        $metodeBayar = 'KAS';
    }

    $alasan = trim((string) ($post['alasan'] ?? ''));
    if ($alasan === '') {
        return ['ok' => false, 'message' => 'Alasan koreksi wajib diisi.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE keuangan_pemasukan
            SET tanggal = :tanggal, sumber = :sumber, dari_pihak = :dari_pihak,
                metode_bayar = :metode_bayar, akun_id = :akun_id, no_bukti = :no_bukti,
                nominal = :nominal, keterangan = :keterangan
            WHERE id = :id
        ')->execute([
            'id' => $id,
            'tanggal' => $tanggal,
            'sumber' => $sumber,
            'dari_pihak' => $dariPihak !== '' ? $dariPihak : null,
            'metode_bayar' => $metodeBayar,
            'akun_id' => $akunId,
            'no_bukti' => $noBukti !== '' ? $noBukti : null,
            'nominal' => $nominal,
            'keterangan' => $keterangan !== '' ? $keterangan : null,
        ]);

        keuangan_transaksi_bootstrap_jurnal();
        keuangan_jurnal_delete_by_ref($pdo, 'pemasukan', $id);
        keuangan_jurnal_pemasukan($pdo, $id, $tanggal, $akunId, $nominal, $sumber, $userId);

        $after = keuangan_pemasukan_get($pdo, $id);
        ensure_operasional_audit_table($pdo);
        operasional_audit_log(
            $pdo,
            OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN,
            'UPDATE',
            $id,
            $row,
            $after,
            $userId,
            $alasan
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memperbarui: ' . $e->getMessage()];
    }

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return ['ok' => true, 'message' => 'Pemasukan #' . $id . ' diperbarui (jurnal diganti, audit dicatat).'];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_pemasukan_delete(PDO $pdo, int $id, int $userId, string $alasan = ''): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
        return ['ok' => false, 'message' => 'Masukkan token super admin terlebih dahulu.'];
    }
    $before = keuangan_pemasukan_get($pdo, $id);
    if ($id <= 0 || $before === null) {
        return ['ok' => false, 'message' => 'Data pemasukan tidak ditemukan.'];
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        $alasan = 'Dihapus dari riwayat pemasukan';
    }

    ensure_operasional_audit_table($pdo);

    $pdo->beginTransaction();
    try {
        operasional_audit_log(
            $pdo,
            OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN,
            'DELETE',
            $id,
            $before,
            null,
            $userId,
            $alasan
        );
        keuangan_transaksi_bootstrap_jurnal();
        keuangan_jurnal_delete_by_ref($pdo, 'pemasukan', $id);
        $pdo->prepare('DELETE FROM keuangan_pemasukan WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return ['ok' => true, 'message' => 'Pemasukan #' . $id . ' telah dihapus dan dicatat di riwayat.'];
}
