<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/pembayaran_edit_token.php';

/** @return list<array<string, mixed>> */
function keuangan_pengeluaran_list(PDO $pdo, int $limit = 100, int $offset = 0): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }
    $limit = max(10, min(500, $limit));
    $offset = max(0, $offset);
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama, p.akun_id' : '';

    return $pdo->query("
        SELECT p.id, p.tanggal, p.penanggung_jawab, p.pos, p.alokasi_nama,
               p.metode_keluar, p.nominal, p.keterangan, p.no_bukti, p.created_at{$akunCol}
        FROM keuangan_pengeluaran p
        {$join}
        ORDER BY p.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function keuangan_pengeluaran_count(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pengeluaran')->fetchColumn();
}

function keuangan_pengeluaran_sum_nominal(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return 0;
    }

    return (int) round((float) ($pdo->query('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran')->fetchColumn() ?: 0));
}

/** @return array<string, mixed>|null */
function keuangan_pengeluaran_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !table_exists($pdo, 'keuangan_pengeluaran')) {
        return null;
    }
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama, p.akun_id' : '';
    $st = $pdo->prepare("
        SELECT p.id, p.tanggal, p.penanggung_jawab, p.pos, p.alokasi_nama,
               p.metode_keluar, p.nominal, p.keterangan, p.no_bukti{$akunCol}
        FROM keuangan_pengeluaran p
        {$join}
        WHERE p.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Ubah riwayat pengeluaran — wajib token super admin (sistem pembayaran_edit_token).
 *
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_pengeluaran_update(PDO $pdo, array $post, int $userId): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
        return [
            'ok' => false,
            'message' => 'Masukkan token super admin terlebih dahulu (menu Token edit pembayaran / redeem di halaman ini).',
        ];
    }

    $id = (int) ($post['id'] ?? 0);
    $row = keuangan_pengeluaran_get($pdo, $id);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Data pengeluaran tidak ditemukan.'];
    }

    $tanggal = trim((string) ($post['tanggal'] ?? ''));
    $penanggungJawab = trim((string) ($post['penanggung_jawab'] ?? ''));
    $pos = trim((string) ($post['pos'] ?? ''));
    $alokasiNama = trim((string) ($post['alokasi_nama'] ?? ''));
    $metodeKeluar = strtoupper(trim((string) ($post['metode_keluar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $noBukti = trim((string) ($post['no_bukti'] ?? ''));
    $nominal = keuangan_money_input_to_int((string) ($post['nominal'] ?? '0'));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal tidak valid.'];
    }
    if ($penanggungJawab === '' || $pos === '' || $nominal <= 0) {
        return ['ok' => false, 'message' => 'Penanggung jawab, pos, dan nominal wajib diisi.'];
    }
    $alokasiErr = keuangan_validasi_alokasi_pengeluaran($pdo, $alokasiNama);
    if ($alokasiErr !== null) {
        return ['ok' => false, 'message' => $alokasiErr];
    }
    if (!in_array($metodeKeluar, ['KAS', 'TRANSFER'], true)) {
        $metodeKeluar = 'KAS';
    }
    $akunErr = keuangan_validasi_akun_kas_id($pdo, $akunId);
    if ($akunErr !== null) {
        return ['ok' => false, 'message' => $akunErr];
    }

    $sets = [
        'tanggal = :tanggal',
        'penanggung_jawab = :penanggung_jawab',
        'pos = :pos',
        'alokasi_nama = :alokasi_nama',
        'nominal = :nominal',
        'keterangan = :keterangan',
    ];
    $params = [
        'id' => $id,
        'tanggal' => $tanggal,
        'penanggung_jawab' => $penanggungJawab,
        'pos' => $pos,
        'alokasi_nama' => $alokasiNama,
        'nominal' => $nominal,
        'keterangan' => $keterangan,
    ];
    if (column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar')) {
        $sets[] = 'metode_keluar = :metode_keluar';
        $params['metode_keluar'] = $metodeKeluar;
    }
    if (column_exists($pdo, 'keuangan_pengeluaran', 'akun_id')) {
        $sets[] = 'akun_id = :akun_id';
        $params['akun_id'] = $akunId;
    }
    if (column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti')) {
        $sets[] = 'no_bukti = :no_bukti';
        $params['no_bukti'] = $noBukti !== '' ? $noBukti : null;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE keuangan_pengeluaran SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

        keuangan_transaksi_bootstrap_jurnal();
        keuangan_jurnal_delete_by_ref($pdo, 'pengeluaran', $id);
        $akunJurnal = $akunId > 0 ? $akunId : (int) ($row['akun_id'] ?? 0);
        if ($akunJurnal > 0) {
            keuangan_jurnal_pengeluaran($pdo, $id, $tanggal, $akunJurnal, $nominal, $pos, $userId);
        }

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

    return [
        'ok' => true,
        'message' => 'Pengeluaran #' . $id . ' diperbarui.',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_pengeluaran_delete(PDO $pdo, int $id, int $userId): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    if (!pembayaran_edit_token_user_boleh_edit($pdo)) {
        return ['ok' => false, 'message' => 'Masukkan token super admin terlebih dahulu.'];
    }
    if ($id <= 0 || keuangan_pengeluaran_get($pdo, $id) === null) {
        return ['ok' => false, 'message' => 'Data pengeluaran tidak ditemukan.'];
    }

    $pdo->beginTransaction();
    try {
        keuangan_transaksi_bootstrap_jurnal();
        keuangan_jurnal_delete_by_ref($pdo, 'pengeluaran', $id);
        $pdo->prepare('DELETE FROM keuangan_pengeluaran WHERE id = :id')->execute(['id' => $id]);
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

    return ['ok' => true, 'message' => 'Pengeluaran #' . $id . ' telah dihapus.'];
}
