<?php

declare(strict_types=1);

/** Modul log audit terpadu (keuangan, jadwal, …). */
const OPERASIONAL_AUDIT_MODUL_KEUANGAN = 'keuangan_pembayaran';
const OPERASIONAL_AUDIT_MODUL_JADWAL = 'jadwal_kegiatan';

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
    ensure_operasional_audit_table($pdo);
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
        OPERASIONAL_AUDIT_MODUL_KEUANGAN => 'Koreksi pembayaran',
        OPERASIONAL_AUDIT_MODUL_JADWAL => 'Jadwal kegiatan',
        default => $modul,
    };
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
