<?php

declare(strict_types=1);

/** Modul log audit terpadu (keuangan, jadwal, …). */
const OPERASIONAL_AUDIT_MODUL_KEUANGAN = 'keuangan_pembayaran';
const OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN = 'keuangan_pemasukan';
const OPERASIONAL_AUDIT_MODUL_KEUANGAN_PENGELUARAN = 'keuangan_pengeluaran';
const OPERASIONAL_AUDIT_MODUL_JADWAL = 'jadwal_kegiatan';

/** @return list<string> */
function operasional_audit_kas_moduls(): array
{
    return [
        OPERASIONAL_AUDIT_MODUL_KEUANGAN,
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN,
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PENGELUARAN,
    ];
}

function operasional_audit_user_nama(): string
{
    return trim((string) ($_SESSION['user']['nama'] ?? $_SESSION['user']['username'] ?? 'Admin'));
}

function ensure_operasional_audit_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operasional_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            modul VARCHAR(40) NOT NULL,
            entity_id INT NULL,
            aksi ENUM('CREATE','UPDATE','DELETE') NOT NULL,
            data_sebelum JSON NOT NULL,
            data_sesudah JSON NULL,
            alasan TEXT NOT NULL,
            user_id INT NULL,
            user_nama VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_oal_modul (modul),
            INDEX idx_oal_entity (modul, entity_id),
            INDEX idx_oal_created (created_at),
            INDEX idx_oal_aksi (aksi)
        )
    ");

    if (table_exists($pdo, 'keuangan_pembayaran_audit') && table_exists($pdo, 'operasional_audit_log')) {
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM operasional_audit_log WHERE modul = '" . OPERASIONAL_AUDIT_MODUL_KEUANGAN . "'")->fetchColumn();
        if ($cnt === 0) {
            $pdo->exec("
                INSERT INTO operasional_audit_log (modul, entity_id, aksi, data_sebelum, data_sesudah, alasan, user_id, user_nama, created_at)
                SELECT '" . OPERASIONAL_AUDIT_MODUL_KEUANGAN . "', pembayaran_id, aksi, data_sebelum, data_sesudah, alasan, user_id, user_nama, created_at
                FROM keuangan_pembayaran_audit
            ");
        }
    }
}

/** @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
function operasional_audit_log(
    PDO $pdo,
    string $modul,
    string $aksi,
    int $entityId,
    ?array $before,
    ?array $after,
    int $userId,
    string $alasan
): void {
    if (!$pdo->inTransaction()) {
        ensure_operasional_audit_table($pdo);
    }
    $aksi = strtoupper(trim($aksi));
    if (!in_array($aksi, ['CREATE', 'UPDATE', 'DELETE'], true)) {
        $aksi = 'UPDATE';
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        $alasan = '(tanpa keterangan)';
    }
    $pdo->prepare('
        INSERT INTO operasional_audit_log
            (modul, entity_id, aksi, data_sebelum, data_sesudah, alasan, user_id, user_nama)
        VALUES
            (:modul, :eid, :aksi, :sebelum, :sesudah, :alasan, :uid, :nama)
    ')->execute([
        'modul' => mb_substr($modul, 0, 40),
        'eid' => $entityId > 0 ? $entityId : null,
        'aksi' => $aksi,
        'sebelum' => json_encode($before ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sesudah' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'alasan' => $alasan,
        'uid' => $userId > 0 ? $userId : null,
        'nama' => operasional_audit_user_nama(),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function operasional_audit_list(
    PDO $pdo,
    int $limit = 300,
    string $modulFilter = '',
    int $entityId = 0
): array {
    ensure_operasional_audit_table($pdo);
    $limit = max(10, min(1000, $limit));
    $sql = 'SELECT * FROM operasional_audit_log WHERE 1=1';
    $params = [];
    if ($modulFilter !== '') {
        $sql .= ' AND modul = :modul';
        $params['modul'] = $modulFilter;
    }
    if ($entityId > 0) {
        $sql .= ' AND entity_id = :eid';
        $params['eid'] = $entityId;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function operasional_audit_modul_label(string $modul): string
{
    return match ($modul) {
        OPERASIONAL_AUDIT_MODUL_KEUANGAN => 'Pembayaran santri',
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN => 'Pemasukan kas',
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PENGELUARAN => 'Pengeluaran kas',
        OPERASIONAL_AUDIT_MODUL_JADWAL => 'Jadwal kegiatan',
        default => $modul,
    };
}

function operasional_audit_is_restored(array $log): bool
{
    $sesudah = json_decode((string) ($log['data_sesudah'] ?? 'null'), true);

    return is_array($sesudah) && !empty($sesudah['_restored']);
}

function operasional_audit_mark_restored(PDO $pdo, int $auditLogId, int $userId): void
{
    if ($auditLogId <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT data_sesudah FROM operasional_audit_log WHERE id = :id LIMIT 1');
    $st->execute(['id' => $auditLogId]);
    $raw = $st->fetchColumn();
    $meta = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['_restored'] = true;
    $meta['_restored_at'] = date('Y-m-d H:i:s');
    $meta['_restored_by'] = $userId > 0 ? $userId : null;
    $pdo->prepare('UPDATE operasional_audit_log SET data_sesudah = :sesudah WHERE id = :id')
        ->execute([
            'id' => $auditLogId,
            'sesudah' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
}

/**
 * @return list<array<string, mixed>>
 */
function operasional_audit_list_deleted_kas(PDO $pdo, int $limit = 50): array
{
    ensure_operasional_audit_table($pdo);
    $limit = max(5, min(200, $limit));
    $moduls = operasional_audit_kas_moduls();
    $in = implode(',', array_fill(0, count($moduls), '?'));
    $st = $pdo->prepare("
        SELECT *
        FROM operasional_audit_log
        WHERE modul IN ({$in}) AND aksi = 'DELETE'
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    $st->execute($moduls);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, mixed>|null */
function jadwal_kegiatan_audit_fetch(PDO $pdo, int $jadwalId): ?array
{
    if ($jadwalId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return null;
    }
    $sql = '
        SELECT j.*, k.nama_kegiatan, COALESCE(p.nama_pembimbing, NULL) AS nama_pembimbing
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
        WHERE j.id = :id
        LIMIT 1
    ';
    $st = $pdo->prepare($sql);
    $st->execute(['id' => $jadwalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Ringkasan satu baris jadwal untuk tabel audit. */
function operasional_audit_ringkas_jadwal(?array $row): string
{
    if (!is_array($row) || $row === []) {
        return '—';
    }
    $hari = [0 => 'Setiap hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    $hk = (int) ($row['hari_ke'] ?? 0);
    $hariLabel = $hari[$hk] ?? (string) $hk;
    $keg = (string) ($row['nama_kegiatan'] ?? '');
    $tg = (string) ($row['tingkatan'] ?? '');
    $jam = substr((string) ($row['jam_mulai'] ?? ''), 0, 5) . '–' . substr((string) ($row['jam_selesai'] ?? ''), 0, 5);
    $tempat = trim((string) ($row['tempat'] ?? ''));

    return trim($keg . ' · ' . $tg . ' · ' . $hariLabel . ' · ' . $jam . ($tempat !== '' ? ' · ' . $tempat : ''));
}

/** Ringkasan pembayaran untuk tabel audit. */
function operasional_audit_ringkas_pembayaran(?array $row): string
{
    if (!is_array($row) || $row === []) {
        return '—';
    }
    $nama = (string) ($row['nama_santri'] ?? '');
    $total = (int) round((float) ($row['total_nominal'] ?? 0));
    $parts = [];
    if ($nama !== '') {
        $parts[] = $nama;
    }
    if ($total > 0) {
        $parts[] = 'Rp ' . number_format($total, 0, ',', '.');
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

/** Ringkasan pemasukan untuk tabel audit / riwayat hapus. */
function operasional_audit_ringkas_pemasukan(?array $row): string
{
    if (!is_array($row) || $row === []) {
        return '—';
    }
    $parts = array_filter([
        trim((string) ($row['sumber'] ?? '')),
        trim((string) ($row['dari_pihak'] ?? '')),
    ]);
    $nominal = (int) round((float) ($row['nominal'] ?? 0));
    if ($nominal > 0) {
        $parts[] = 'Rp ' . number_format($nominal, 0, ',', '.');
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

/** Ringkasan pengeluaran untuk tabel audit / riwayat hapus. */
function operasional_audit_ringkas_pengeluaran(?array $row): string
{
    if (!is_array($row) || $row === []) {
        return '—';
    }
    $parts = array_filter([
        trim((string) ($row['pos'] ?? '')),
        trim((string) ($row['penanggung_jawab'] ?? '')),
    ]);
    $nominal = (int) round((float) ($row['nominal'] ?? 0));
    if ($nominal > 0) {
        $parts[] = 'Rp ' . number_format($nominal, 0, ',', '.');
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

function operasional_audit_ringkas_kas(string $modul, ?array $row): string
{
    return match ($modul) {
        OPERASIONAL_AUDIT_MODUL_KEUANGAN => operasional_audit_ringkas_pembayaran($row),
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PEMASUKAN => operasional_audit_ringkas_pemasukan($row),
        OPERASIONAL_AUDIT_MODUL_KEUANGAN_PENGELUARAN => operasional_audit_ringkas_pengeluaran($row),
        default => '—',
    };
}
