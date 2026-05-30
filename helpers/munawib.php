<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function munawib_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS munawib (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(120) NOT NULL,
            nip VARCHAR(40) NULL,
            qr VARCHAR(120) NULL,
            no_wa VARCHAR(30) NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_munawib_nip (nip),
            UNIQUE KEY uk_munawib_qr (qr)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS munawib_penugasan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NULL,
            munawib_id INT NOT NULL,
            jadwal_kegiatan_id INT NULL,
            kegiatan_id INT NULL,
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            alasan TEXT NULL,
            status ENUM("AKTIF","SELESAI","BATAL") NOT NULL DEFAULT "AKTIF",
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mp_pembimbing (pembimbing_id),
            KEY idx_mp_munawib (munawib_id),
            KEY idx_mp_tanggal (tanggal_mulai, tanggal_selesai),
            FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE,
            FOREIGN KEY (munawib_id) REFERENCES munawib(id) ON DELETE CASCADE
        )
    ');
    try {
        $pdo->exec('ALTER TABLE munawib_penugasan MODIFY COLUMN pembimbing_id INT NULL');
    } catch (PDOException $e) {
        // abaikan database lama yang menolak alter
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_munawib (
            id INT AUTO_INCREMENT PRIMARY KEY,
            munawib_id INT NOT NULL,
            penugasan_id INT NULL,
            kegiatan_id INT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pm_munawib_tgl (munawib_id, tanggal),
            FOREIGN KEY (munawib_id) REFERENCES munawib(id) ON DELETE CASCADE
        )
    ');
}

/**
 * @return list<array<string, mixed>>
 */
function munawib_list_aktif(PDO $pdo): array
{
    munawib_ensure_schema($pdo);
    try {
        return $pdo->query('SELECT id, nama, nip, qr, no_wa FROM munawib WHERE COALESCE(is_aktif,1)=1 ORDER BY nama ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @return array<string, mixed>|null
 */
/**
 * Tingkatan kelas yang diwakili munawib (dari penugasan aktif + jadwal).
 *
 * @return list<string>
 */
function munawib_tingkatan_aktif_list(PDO $pdo, int $munawibId, ?string $tanggal = null): array
{
    munawib_ensure_schema($pdo);
    if ($munawibId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    $tgl = $tanggal ?: date('Y-m-d');
    $sql = '
        SELECT DISTINCT TRIM(j.tingkatan) AS tingkatan
        FROM munawib_penugasan mp
        INNER JOIN jadwal_kegiatan j ON j.kegiatan_id = mp.kegiatan_id
            AND (mp.pembimbing_id IS NULL OR j.pembimbing_id = mp.pembimbing_id)
        WHERE mp.munawib_id = :mid
          AND mp.status = "AKTIF"
          AND :tgl BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
          AND j.tingkatan IS NOT NULL
          AND TRIM(j.tingkatan) <> ""
          AND TRIM(j.tingkatan) <> "Semua Tingkatan"
        ORDER BY tingkatan ASC
    ';
    if (column_exists($pdo, 'munawib_penugasan', 'jadwal_kegiatan_id')) {
        $sql = '
            SELECT DISTINCT TRIM(j.tingkatan) AS tingkatan
            FROM munawib_penugasan mp
            LEFT JOIN jadwal_kegiatan j ON (
                (mp.jadwal_kegiatan_id IS NOT NULL AND mp.jadwal_kegiatan_id > 0 AND j.id = mp.jadwal_kegiatan_id)
                OR (
                    (mp.jadwal_kegiatan_id IS NULL OR mp.jadwal_kegiatan_id = 0)
                    AND j.kegiatan_id = mp.kegiatan_id
                    AND (mp.pembimbing_id IS NULL OR j.pembimbing_id = mp.pembimbing_id)
                )
            )
            WHERE mp.munawib_id = :mid
              AND mp.status = "AKTIF"
              AND :tgl BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
              AND j.tingkatan IS NOT NULL
              AND TRIM(j.tingkatan) <> ""
              AND TRIM(j.tingkatan) <> "Semua Tingkatan"
            ORDER BY tingkatan ASC
        ';
    }
    $st = $pdo->prepare($sql);
    $st->execute(['mid' => $munawibId, 'tgl' => $tgl]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tk) {
        $t = trim((string) $tk);
        if ($t !== '' && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }

    return $out;
}

/** Pembimbing pengganti utama untuk portal (dari penugasan aktif). */
function munawib_pembimbing_id_portal(PDO $pdo, int $munawibId, ?string $tanggal = null): int
{
    munawib_ensure_schema($pdo);
    if ($munawibId <= 0) {
        return 0;
    }
    $tgl = $tanggal ?: date('Y-m-d');
    $st = $pdo->prepare('
        SELECT pembimbing_id
        FROM munawib_penugasan
        WHERE munawib_id = :mid
          AND status = "AKTIF"
          AND :tgl BETWEEN tanggal_mulai AND tanggal_selesai
          AND pembimbing_id IS NOT NULL
          AND pembimbing_id > 0
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['mid' => $munawibId, 'tgl' => $tgl]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * @return array{ok:bool,message:string,session?:array<string,mixed>}
 */
function munawib_buat_sesi_portal(PDO $pdo, string $qrCode): array
{
    $row = munawib_find_by_code($pdo, $qrCode);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Kartu QR munawib tidak dikenali atau tidak aktif.'];
    }
    $mid = (int) ($row['id'] ?? 0);
    $tingkatan = munawib_tingkatan_aktif_list($pdo, $mid);
    if ($tingkatan === []) {
        return [
            'ok' => false,
            'message' => 'Munawib belum punya penugasan aktif dengan kelas/tingkatan. Hubungi pengurus.',
        ];
    }
    $pbId = munawib_pembimbing_id_portal($pdo, $mid);
    $nama = trim((string) ($row['nama'] ?? 'Munawib'));
    $nip = trim((string) ($row['nip'] ?? ''));

    return [
        'ok' => true,
        'message' => 'Login munawib berhasil.',
        'session' => [
            'user' => [
                'id' => 0,
                'nama' => $nama . ' (Munawib)',
                'username' => $nip !== '' ? $nip : ('munawib:' . $mid),
                'role' => 'pembimbing',
                'is_super_admin' => 0,
                'foto_profil' => '',
            ],
            'munawib_id' => $mid,
            'munawib_tingkatan' => $tingkatan,
            'munawib_pembimbing_id' => $pbId,
        ],
    ];
}

function munawib_find_by_code(PDO $pdo, string $code): ?array
{
    munawib_ensure_schema($pdo);
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM munawib WHERE (qr = :c OR nip = :c) AND COALESCE(is_aktif,1)=1 LIMIT 1');
    $st->execute(['c' => $code]);

    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Penugasan aktif munawib pada tanggal & kegiatan.
 *
 * @return array<string, mixed>|null
 */
function munawib_penugasan_aktif(PDO $pdo, int $munawibId, string $tanggal, int $kegiatanId = 0): ?array
{
    munawib_ensure_schema($pdo);
    $sql = '
        SELECT mp.*, b.nama_pembimbing AS pembimbing_nama
        FROM munawib_penugasan mp
        LEFT JOIN pembimbing b ON b.id = mp.pembimbing_id
        WHERE mp.munawib_id = :mid
          AND mp.status = "AKTIF"
          AND :tgl BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
    ';
    $params = ['mid' => $munawibId, 'tgl' => $tanggal];
    if ($kegiatanId > 0) {
        $sql .= ' AND (mp.kegiatan_id IS NULL OR mp.kegiatan_id = :kid)';
        $params['kid'] = $kegiatanId;
    }
    $sql .= ' ORDER BY mp.id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Catat presensi munawib; pembimbing asli tetap tidak di-mark hadir.
 *
 * @return array{ok:bool, message:string}
 */
function munawib_catat_presensi(PDO $pdo, int $munawibId, int $kegiatanId, string $tanggal, string $jam, int $createdBy): array
{
    munawib_ensure_schema($pdo);
    if ($munawibId <= 0 || $kegiatanId <= 0) {
        return ['ok' => false, 'message' => 'Data scan munawib tidak valid.'];
    }

    $cekHarian = $pdo->prepare('SELECT id FROM presensi_munawib WHERE munawib_id = :m AND tanggal = :t LIMIT 1');
    $cekHarian->execute(['m' => $munawibId, 't' => $tanggal]);
    if ($cekHarian->fetch()) {
        return ['ok' => false, 'message' => 'Munawib sudah scan pada jadwal lain di waktu ini. Hanya boleh mewakili 1 kegiatan.'];
    }

    $cekKegiatanMunawib = $pdo->prepare('SELECT id FROM presensi_munawib WHERE kegiatan_id = :k AND tanggal = :t LIMIT 1');
    $cekKegiatanMunawib->execute(['k' => $kegiatanId, 't' => $tanggal]);
    if ($cekKegiatanMunawib->fetch()) {
        return ['ok' => false, 'message' => 'Kegiatan ini sudah diwakili munawib lain.'];
    }

    if (table_exists($pdo, 'presensi_pembimbing')) {
        $cekPb = $pdo->prepare('SELECT id FROM presensi_pembimbing WHERE kegiatan_id = :k AND tanggal = :t LIMIT 1');
        $cekPb->execute(['k' => $kegiatanId, 't' => $tanggal]);
        if ($cekPb->fetch()) {
            return ['ok' => false, 'message' => 'Pembimbing asli sudah scan hadir pada kegiatan ini.'];
        }
    }

    $penugasan = munawib_penugasan_aktif($pdo, $munawibId, $tanggal, $kegiatanId);
    $pid = (int) (($penugasan['id'] ?? 0));

    $ins = $pdo->prepare('INSERT INTO presensi_munawib (munawib_id, penugasan_id, kegiatan_id, tanggal, jam, created_by) VALUES (:m, :p, :k, :t, :j, :by)');
    $ins->execute([
        'm' => $munawibId,
        'p' => $pid > 0 ? $pid : null,
        'k' => $kegiatanId > 0 ? $kegiatanId : null,
        't' => $tanggal,
        'j' => $jam,
        'by' => $createdBy > 0 ? $createdBy : null,
    ]);

    $kegiatanNama = '';
    if (table_exists($pdo, 'kegiatan')) {
        $stK = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id LIMIT 1');
        $stK->execute(['id' => $kegiatanId]);
        $kegiatanNama = trim((string) ($stK->fetchColumn() ?: ''));
    }
    munawib_kirim_notif_kelas_terisi($pdo, $munawibId, $kegiatanId, $tanggal, $jam, $kegiatanNama);

    if ($penugasan !== null && trim((string) ($penugasan['pembimbing_nama'] ?? '')) !== '') {
        return ['ok' => true, 'message' => 'Munawib hadir (pengganti ' . (string) ($penugasan['pembimbing_nama'] ?? 'pembimbing') . ').'];
    }
    return ['ok' => true, 'message' => 'Munawib hadir (mode fleksibel, tanpa pembimbing khusus).'];
}

function munawib_kirim_notif_kelas_terisi(PDO $pdo, int $munawibId, int $kegiatanId, string $tanggal, string $jam, string $kegiatanNama = ''): void
{
    if (!function_exists('wa_petugas_pendidikan_target') || !function_exists('send_wa_bulk')) {
        return;
    }
    if (!table_exists($pdo, 'perizinan_pembimbing') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return;
    }
    $targetWa = trim((string) wa_petugas_pendidikan_target($pdo));
    if ($targetWa === '') {
        return;
    }
    $stM = $pdo->prepare('SELECT nama FROM munawib WHERE id = :id LIMIT 1');
    $stM->execute(['id' => $munawibId]);
    $munawibNama = trim((string) ($stM->fetchColumn() ?: 'Munawib'));

    $stI = $pdo->prepare('
        SELECT b.nama_pembimbing, j.tingkatan
        FROM perizinan_pembimbing i
        INNER JOIN pembimbing b ON b.id = i.pembimbing_id
        INNER JOIN jadwal_kegiatan j ON j.pembimbing_id = i.pembimbing_id
        WHERE i.status_izin = "IZIN"
          AND :tgl BETWEEN i.tanggal_mulai AND i.tanggal_selesai
          AND j.kegiatan_id = :kid
          AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
        ORDER BY i.id DESC
        LIMIT 1
    ');
    $stI->execute(['tgl' => $tanggal, 'kid' => $kegiatanId, 'jam' => $jam]);
    $izinRow = $stI->fetch(PDO::FETCH_ASSOC);
    if (!is_array($izinRow)) {
        return;
    }

    $kegiatanText = $kegiatanNama !== '' ? $kegiatanNama : ('Kegiatan #' . $kegiatanId);
    $msg = "✅ Update kelas terisi\n"
        . "Tanggal: " . date('d/m/Y', strtotime($tanggal)) . "\n"
        . "Kegiatan: " . $kegiatanText . "\n"
        . "Tingkatan: " . (string) ($izinRow['tingkatan'] ?? '-') . "\n"
        . "Pembimbing izin: " . (string) ($izinRow['nama_pembimbing'] ?? '-') . "\n"
        . "Pengganti masuk: " . $munawibNama . " (" . substr($jam, 0, 5) . ")";
    send_wa_bulk($pdo, $targetWa, $msg);
}

/**
 * @return list<array<string, mixed>>
 */
function munawib_laporan_kehadiran(PDO $pdo, ?string $dari, ?string $sampai, int $munawibId = 0): array
{
    munawib_ensure_schema($pdo);
    $dari = $dari ?: date('Y-m-01');
    $sampai = $sampai ?: date('Y-m-d');
    $where = 'pm.tanggal BETWEEN :dari AND :sampai';
    $params = ['dari' => $dari, 'sampai' => $sampai];
    if ($munawibId > 0) {
        $where .= ' AND pm.munawib_id = :mid';
        $params['mid'] = $munawibId;
    }
    $joinKeg = table_exists($pdo, 'kegiatan')
        ? 'LEFT JOIN kegiatan k ON k.id = pm.kegiatan_id'
        : '';
    $namaKeg = table_exists($pdo, 'kegiatan') ? 'k.nama_kegiatan' : "''";
    $sql = "
        SELECT pm.*, m.nama AS munawib_nama, m.nip AS munawib_nip,
               {$namaKeg} AS nama_kegiatan,
               b.nama_pembimbing AS pembimbing_diganti
        FROM presensi_munawib pm
        INNER JOIN munawib m ON m.id = pm.munawib_id
        LEFT JOIN munawib_penugasan mp ON mp.id = pm.penugasan_id
        LEFT JOIN pembimbing b ON b.id = mp.pembimbing_id
        {$joinKeg}
        WHERE {$where}
        ORDER BY pm.tanggal DESC, pm.jam DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
