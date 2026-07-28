<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/jadwal_ui.php';
require_once __DIR__ . '/munawib.php';

const PB_JADWAL_BATAS_JAM_SEBELUM = 3;
const PB_JADWAL_MAX_PINDAH_BULAN = 3;

/**
 * @param array<int|string,mixed> $halInput
 * @param array<int|string,mixed> $isiInput
 * @return array{ok:bool,pesan:string,rows?:list<array{hal:string,isi:string}>}
 */
function pb_jadwal_parse_materi_halaman(array $halInput, array $isiInput): array
{
    $rows = [];
    $count = max(count($halInput), count($isiInput));
    for ($i = 0; $i < $count; $i++) {
        $hal = trim((string) ($halInput[$i] ?? ''));
        $isi = trim((string) ($isiInput[$i] ?? ''));
        if ($hal === '' && $isi === '') {
            continue;
        }
        if ($hal === '' || $isi === '') {
            return ['ok' => false, 'pesan' => 'Setiap baris tugas wajib diisi nomor halaman dan isinya.'];
        }
        $rows[] = ['hal' => $hal, 'isi' => $isi];
    }
    if ($rows === []) {
        return ['ok' => false, 'pesan' => 'Isi minimal satu tugas/materi per halaman untuk munawib.'];
    }

    return ['ok' => true, 'pesan' => '', 'rows' => $rows];
}

function pb_jadwal_materi_to_json(array $rows): string
{
    return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

/**
 * @return list<array{hal:string,isi:string}>
 */
function pb_jadwal_materi_from_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [['hal' => '-', 'isi' => $json]];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hal = trim((string) ($row['hal'] ?? ''));
        $isi = trim((string) ($row['isi'] ?? ''));
        if ($hal === '' && $isi === '') {
            continue;
        }
        $out[] = ['hal' => $hal !== '' ? $hal : '-', 'isi' => $isi];
    }

    return $out;
}

function pb_jadwal_materi_ringkas(?string $json): string
{
    $rows = pb_jadwal_materi_from_json($json);
    if ($rows === []) {
        return '';
    }
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = 'Hal ' . ($row['hal'] ?? '-') . ': ' . ($row['isi'] ?? '');
    }

    return implode(' · ', $parts);
}

function pb_jadwal_override_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pembimbing_jadwal_override (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            jadwal_id INT NOT NULL,
            kegiatan_id INT NOT NULL,
            tanggal DATE NOT NULL,
            jenis ENUM("PINDAH_WAKTU","GANTI_MATERI","CARI_MUNAWIB") NOT NULL,
            jam_mulai_asli TIME NOT NULL,
            jam_selesai_asli TIME NOT NULL,
            jam_mulai_baru TIME NULL,
            jam_selesai_baru TIME NULL,
            materi_pengganti TEXT NULL,
            munawib_id INT NULL,
            alasan TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_pjo_pb_tgl (pembimbing_id, tanggal),
            KEY idx_pjo_kegiatan_bulan (pembimbing_id, kegiatan_id, tanggal),
            FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
        )
    ');
    try {
        $pdo->exec('ALTER TABLE pembimbing_jadwal_override ADD COLUMN IF NOT EXISTS materi_pengganti TEXT NULL');
    } catch (PDOException $e) {
        // ignore
    }
}

function pb_jadwal_durasi_menit(string $jamMulai, string $jamSelesai): int
{
    $m1 = strtotime('1970-01-01 ' . jadwal_norm_jam($jamMulai));
    $m2 = strtotime('1970-01-01 ' . jadwal_norm_jam($jamSelesai));
    if ($m1 === false || $m2 === false || $m2 <= $m1) {
        return 60;
    }

    return (int) round(($m2 - $m1) / 60);
}

function pb_jadwal_jam_selesai_dari_mulai(string $jamMulaiBaru, int $durasiMenit): string
{
    $ts = strtotime('1970-01-01 ' . jadwal_norm_jam($jamMulaiBaru) . ' +' . max(1, $durasiMenit) . ' minutes');

    return $ts !== false ? date('H:i:s', $ts) : jadwal_norm_jam($jamMulaiBaru);
}

/**
 * @return array{ok:bool,pesan:string,batas?:string}
 */
function pb_jadwal_cek_batas_waktu(string $tanggal, string $jamMulaiAsli): array
{
    $startTs = strtotime($tanggal . ' ' . jadwal_norm_jam($jamMulaiAsli));
    if ($startTs === false) {
        return ['ok' => false, 'pesan' => 'Waktu kegiatan tidak valid.'];
    }
    $batasTs = $startTs - (PB_JADWAL_BATAS_JAM_SEBELUM * 3600);
    $now = time();
    if ($now >= $batasTs) {
        return [
            'ok' => false,
            'pesan' => 'Perubahan hanya bisa dilakukan minimal ' . PB_JADWAL_BATAS_JAM_SEBELUM . ' jam sebelum jadwal asli.',
            'batas' => date('Y-m-d H:i', $batasTs),
        ];
    }

    return ['ok' => true, 'pesan' => '', 'batas' => date('Y-m-d H:i', $batasTs)];
}

function pb_jadwal_hitung_pindah_bulan(PDO $pdo, int $pembimbingId, int $kegiatanId, string $tanggal): int
{
    pb_jadwal_override_ensure_schema($pdo);
    $bulan = date('Y-m', strtotime($tanggal));
    $st = $pdo->prepare('
        SELECT COUNT(*) FROM pembimbing_jadwal_override
        WHERE pembimbing_id = :pb AND kegiatan_id = :kid AND jenis = "PINDAH_WAKTU"
          AND DATE_FORMAT(tanggal, "%Y-%m") = :bulan
    ');
    $st->execute(['pb' => $pembimbingId, 'kid' => $kegiatanId, 'bulan' => $bulan]);

    return (int) $st->fetchColumn();
}

/**
 * Slot jadwal pembimbing untuk hari tertentu (hari_ke cocok).
 *
 * @return list<array<string,mixed>>
 */
function pb_jadwal_slots_hari_ini(PDO $pdo, int $pembimbingId, ?string $tanggal = null): array
{
    if ($pembimbingId <= 0) {
        return [];
    }
    $tanggal = $tanggal ?: date('Y-m-d');
    $hariKe = (int) date('N', strtotime($tanggal));
    ensure_kegiatan_kategori_column($pdo);
    $st = $pdo->prepare('
        SELECT j.id AS jadwal_id, j.kegiatan_id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat,
               k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.pembimbing_id = :pb
          AND (j.hari_ke = :hk OR j.hari_ke = 0)
          AND COALESCE(k.is_active, 1) = 1
        ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC
    ');
    $st->execute(['pb' => $pembimbingId, 'hk' => $hariKe]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $row['durasi_menit'] = pb_jadwal_durasi_menit((string) $row['jam_mulai'], (string) $row['jam_selesai']);
        $row['batas_ubah'] = pb_jadwal_cek_batas_waktu($tanggal, (string) $row['jam_mulai']);
        $row['sisa_pindah_bulan'] = max(0, PB_JADWAL_MAX_PINDAH_BULAN - pb_jadwal_hitung_pindah_bulan($pdo, $pembimbingId, (int) $row['kegiatan_id'], $tanggal));
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return array{ok:bool,pesan:string,slot?:array<string,mixed>}
 */
function pb_jadwal_ambil_slot_pembimbing(PDO $pdo, int $pembimbingId, int $jadwalId, string $tanggal): array
{
    $slots = pb_jadwal_slots_hari_ini($pdo, $pembimbingId, $tanggal);
    foreach ($slots as $slot) {
        if ((int) ($slot['jadwal_id'] ?? 0) === $jadwalId) {
            return ['ok' => true, 'pesan' => '', 'slot' => $slot];
        }
    }

    return ['ok' => false, 'pesan' => 'Jadwal tidak ditemukan atau bukan milik Anda hari ini.'];
}

function pb_jadwal_jam_overlap(string $mulaiA, string $selesaiA, string $mulaiB, string $selesaiB): bool
{
    $a0 = substr(jadwal_norm_jam($mulaiA), 0, 8);
    $a1 = substr(jadwal_norm_jam($selesaiA), 0, 8);
    $b0 = substr(jadwal_norm_jam($mulaiB), 0, 8);
    $b1 = substr(jadwal_norm_jam($selesaiB), 0, 8);

    return $a0 < $b1 && $b0 < $a1;
}

/**
 * Jam efektif jadwal pada tanggal (termasuk override pindah waktu).
 *
 * @return array{mulai:string,selesai:string}
 */
function pb_jadwal_jam_efektif_hari(PDO $pdo, int $jadwalId, string $tanggal, string $jamMulai, string $jamSelesai): array
{
    $st = $pdo->prepare('
        SELECT jam_mulai_baru, jam_selesai_baru
        FROM pembimbing_jadwal_override
        WHERE jadwal_id = :jid AND tanggal = :tgl AND jenis = "PINDAH_WAKTU"
        LIMIT 1
    ');
    $st->execute(['jid' => $jadwalId, 'tgl' => $tanggal]);
    $ov = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($ov) && !empty($ov['jam_mulai_baru'])) {
        return [
            'mulai' => (string) $ov['jam_mulai_baru'],
            'selesai' => (string) ($ov['jam_selesai_baru'] ?? $jamSelesai),
        ];
    }

    return ['mulai' => $jamMulai, 'selesai' => $jamSelesai];
}

/**
 * Cek bentrok waktu baru dengan jadwal pembimbing lain atau kegiatan tingkatan sama.
 *
 * @param array<string,mixed> $slot
 * @return array{bentrok:bool,items:list<array<string,string>>}
 */
function pb_jadwal_cek_bentrok_pindah_waktu(PDO $pdo, int $pembimbingId, array $slot, string $tanggal, string $jamMulaiBaru, string $jamSelesaiBaru): array
{
    $tingkatan = trim((string) ($slot['tingkatan'] ?? ''));
    $excludeJid = (int) ($slot['jadwal_id'] ?? 0);
    if ($tingkatan === '' || $excludeJid <= 0) {
        return ['bentrok' => false, 'items' => []];
    }
    $hariKe = (int) date('N', strtotime($tanggal));
    ensure_kegiatan_kategori_column($pdo);

    $st = $pdo->prepare('
        SELECT j.id AS jadwal_id, j.jam_mulai, j.jam_selesai, j.tingkatan, j.pembimbing_id,
               k.nama_kegiatan, p.nama_pembimbing
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
        WHERE (j.hari_ke = :hk OR j.hari_ke = 0)
          AND j.id != :excl
          AND (j.pembimbing_id = :pb OR j.tingkatan = :tk)
          AND COALESCE(k.is_active, 1) = 1
    ');
    $st->execute(['hk' => $hariKe, 'excl' => $excludeJid, 'pb' => $pembimbingId, 'tk' => $tingkatan]);

    $items = [];
    $seen = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $jid = (int) ($row['jadwal_id'] ?? 0);
        if ($jid <= 0 || isset($seen[$jid])) {
            continue;
        }
        $seen[$jid] = true;
        $efektif = pb_jadwal_jam_efektif_hari(
            $pdo,
            $jid,
            $tanggal,
            (string) ($row['jam_mulai'] ?? ''),
            (string) ($row['jam_selesai'] ?? '')
        );
        if (!pb_jadwal_jam_overlap($jamMulaiBaru, $jamSelesaiBaru, $efektif['mulai'], $efektif['selesai'])) {
            continue;
        }
        $items[] = [
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '—'),
            'tingkatan' => (string) ($row['tingkatan'] ?? '—'),
            'jam' => substr($efektif['mulai'], 0, 5) . '–' . substr($efektif['selesai'], 0, 5),
            'pembimbing' => (string) ($row['nama_pembimbing'] ?? '—'),
        ];
    }

    return ['bentrok' => $items !== [], 'items' => $items];
}

function pb_jadwal_kirim_notifikasi(PDO $pdo, string $judul, string $isi, string $dedupKey = ''): void
{
    $waTujuan = trim((string) app_setting($pdo, 'wa_pembimbing_izin', ''));
    if ($waTujuan === '') {
        $waTujuan = trim((string) app_setting($pdo, 'wa_petugas_pendidikan', ''));
    }
    if ($waTujuan === '') {
        return;
    }
    $msg = $judul . "\n" . $isi;
    if ($waTujuan !== '' && function_exists('send_wa_bulk')) {
        $opts = ['kind' => 'presensi'];
        if ($dedupKey !== '') {
            $opts['dedup_key'] = $dedupKey;
            $opts['dedup_key_once'] = true;
        }
        send_wa_bulk($pdo, $waTujuan, $msg, $opts);
    }
}

/**
 * @param array<string,mixed> $slot
 * @return array{ok:bool,pesan:string}
 */
function pb_jadwal_simpan_pindah_waktu(PDO $pdo, int $pembimbingId, array $slot, string $tanggal, string $jamMulaiBaru, string $alasan, int $userId): array
{
    pb_jadwal_override_ensure_schema($pdo);
    $kategori = strtoupper((string) ($slot['kategori_kegiatan'] ?? 'TAALIM'));
    if ($kategori !== 'TAALIM') {
        return ['ok' => false, 'pesan' => 'Pergeseran waktu hanya untuk kegiatan ta\'lim & ta\'alum.'];
    }
    $cek = pb_jadwal_cek_batas_waktu($tanggal, (string) $slot['jam_mulai']);
    if (!$cek['ok']) {
        return ['ok' => false, 'pesan' => $cek['pesan']];
    }
    if (pb_jadwal_hitung_pindah_bulan($pdo, $pembimbingId, (int) $slot['kegiatan_id'], $tanggal) >= PB_JADWAL_MAX_PINDAH_BULAN) {
        return ['ok' => false, 'pesan' => 'Batas pergeseran waktu (' . PB_JADWAL_MAX_PINDAH_BULAN . 'x/bulan per kegiatan) sudah tercapai.'];
    }
    if (trim($alasan) === '') {
        return ['ok' => false, 'pesan' => 'Catatan wajib diisi.'];
    }

    $durasi = (int) ($slot['durasi_menit'] ?? 60);
    $jamSelesaiBaru = pb_jadwal_jam_selesai_dari_mulai($jamMulaiBaru, $durasi);
    $jadwalId = (int) ($slot['jadwal_id'] ?? 0);

    $stExist = $pdo->prepare('SELECT id FROM pembimbing_jadwal_override WHERE pembimbing_id = :pb AND jadwal_id = :jid AND tanggal = :tgl AND jenis = "PINDAH_WAKTU" LIMIT 1');
    $stExist->execute(['pb' => $pembimbingId, 'jid' => $jadwalId, 'tgl' => $tanggal]);
    $existId = (int) ($stExist->fetchColumn() ?: 0);

    if ($existId > 0) {
        $pdo->prepare('
            UPDATE pembimbing_jadwal_override
            SET jam_mulai_baru = :jm, jam_selesai_baru = :js, alasan = :alasan, updated_at = NOW()
            WHERE id = :id AND pembimbing_id = :pb
        ')->execute([
            'jm' => jadwal_norm_jam($jamMulaiBaru),
            'js' => $jamSelesaiBaru,
            'alasan' => $alasan,
            'id' => $existId,
            'pb' => $pembimbingId,
        ]);
    } else {
        $pdo->prepare('
            INSERT INTO pembimbing_jadwal_override
            (pembimbing_id, jadwal_id, kegiatan_id, tanggal, jenis, jam_mulai_asli, jam_selesai_asli, jam_mulai_baru, jam_selesai_baru, alasan)
            VALUES (:pb, :jid, :kid, :tgl, "PINDAH_WAKTU", :jma, :jsa, :jm, :js, :alasan)
        ')->execute([
            'pb' => $pembimbingId,
            'jid' => $jadwalId,
            'kid' => (int) $slot['kegiatan_id'],
            'tgl' => $tanggal,
            'jma' => $slot['jam_mulai'],
            'jsa' => $slot['jam_selesai'],
            'jm' => jadwal_norm_jam($jamMulaiBaru),
            'js' => $jamSelesaiBaru,
            'alasan' => $alasan,
        ]);
    }

    pb_jadwal_kirim_notifikasi(
        $pdo,
        '🕐 Pindah waktu kegiatan',
        (string) ($slot['nama_kegiatan'] ?? '') . ' · ' . $tanggal . "\n"
        . 'Asli: ' . substr((string) $slot['jam_mulai'], 0, 5) . ' → Baru: ' . substr(jadwal_norm_jam($jamMulaiBaru), 0, 5) . "\n"
        . 'Alasan: ' . $alasan,
        'pb_jadwal:' . $pembimbingId . ':' . $jadwalId . ':' . $tanggal . ':pindah_waktu'
    );

    return ['ok' => true, 'pesan' => 'Pergeseran waktu disimpan. Bisa diubah lagi sebelum ' . PB_JADWAL_BATAS_JAM_SEBELUM . ' jam sebelum jadwal asli.'];
}

/**
 * @param array<string,mixed> $slot
 * @return array{ok:bool,pesan:string}
 */
function pb_jadwal_simpan_ganti_materi(PDO $pdo, int $pembimbingId, array $slot, string $tanggal, string $materi, string $alasan): array
{
    pb_jadwal_override_ensure_schema($pdo);
    $cek = pb_jadwal_cek_batas_waktu($tanggal, (string) $slot['jam_mulai']);
    if (!$cek['ok']) {
        return ['ok' => false, 'pesan' => $cek['pesan']];
    }
    $materi = trim($materi);
    $alasan = trim($alasan);
    if ($materi === '') {
        return ['ok' => false, 'pesan' => 'Tugas/materi pengganti wajib diisi.'];
    }
    if ($alasan === '') {
        return ['ok' => false, 'pesan' => 'Catatan wajib diisi.'];
    }

    $jadwalId = (int) ($slot['jadwal_id'] ?? 0);
    $stExist = $pdo->prepare('SELECT id FROM pembimbing_jadwal_override WHERE pembimbing_id = :pb AND jadwal_id = :jid AND tanggal = :tgl AND jenis = "GANTI_MATERI" LIMIT 1');
    $stExist->execute(['pb' => $pembimbingId, 'jid' => $jadwalId, 'tgl' => $tanggal]);
    $existId = (int) ($stExist->fetchColumn() ?: 0);

    if ($existId > 0) {
        $pdo->prepare('UPDATE pembimbing_jadwal_override SET materi_pengganti = :mat, alasan = :alasan, updated_at = NOW() WHERE id = :id')
            ->execute(['mat' => $materi, 'alasan' => $alasan, 'id' => $existId]);
    } else {
        $pdo->prepare('
            INSERT INTO pembimbing_jadwal_override
            (pembimbing_id, jadwal_id, kegiatan_id, tanggal, jenis, jam_mulai_asli, jam_selesai_asli, materi_pengganti, alasan)
            VALUES (:pb, :jid, :kid, :tgl, "GANTI_MATERI", :jma, :jsa, :mat, :alasan)
        ')->execute([
            'pb' => $pembimbingId,
            'jid' => $jadwalId,
            'kid' => (int) $slot['kegiatan_id'],
            'tgl' => $tanggal,
            'jma' => $slot['jam_mulai'],
            'jsa' => $slot['jam_selesai'],
            'mat' => $materi,
            'alasan' => $alasan,
        ]);
    }

    pb_jadwal_kirim_notifikasi(
        $pdo,
        '📚 Ganti tugas/materi',
        (string) ($slot['nama_kegiatan'] ?? '') . ' · ' . $tanggal . "\n"
        . 'Materi: ' . $materi . "\nAlasan: " . $alasan,
        'pb_jadwal:' . $pembimbingId . ':' . $jadwalId . ':' . $tanggal . ':ganti_materi'
    );

    return ['ok' => true, 'pesan' => 'Perubahan materi disimpan.'];
}

/**
 * @param array<string,mixed> $slot
 * @return array{ok:bool,pesan:string}
 */
function pb_jadwal_simpan_cari_munawib(PDO $pdo, int $pembimbingId, array $slot, string $tanggal, int $munawibId, string $alasan, int $userId, array $materiRows = []): array
{
    pb_jadwal_override_ensure_schema($pdo);
    munawib_ensure_schema($pdo);
    $cek = pb_jadwal_cek_batas_waktu($tanggal, (string) $slot['jam_mulai']);
    if (!$cek['ok']) {
        return ['ok' => false, 'pesan' => $cek['pesan']];
    }
    if ($munawibId <= 0) {
        return ['ok' => false, 'pesan' => 'Pilih munawib pengganti.'];
    }
    if (trim($alasan) === '') {
        return ['ok' => false, 'pesan' => 'Catatan wajib diisi.'];
    }
    if ($materiRows === []) {
        return ['ok' => false, 'pesan' => 'Isi tugas/materi per halaman untuk munawib.'];
    }
    $materiJson = pb_jadwal_materi_to_json($materiRows);

    $stMw = $pdo->prepare('SELECT id, nama FROM munawib WHERE id = :id AND COALESCE(is_aktif,1)=1 LIMIT 1');
    $stMw->execute(['id' => $munawibId]);
    $mw = $stMw->fetch(PDO::FETCH_ASSOC);
    if (!$mw) {
        return ['ok' => false, 'pesan' => 'Munawib tidak ditemukan.'];
    }

    $jadwalId = (int) ($slot['jadwal_id'] ?? 0);
    $kegiatanId = (int) ($slot['kegiatan_id'] ?? 0);

    $pdo->prepare('
        INSERT INTO munawib_penugasan (pembimbing_id, munawib_id, jadwal_kegiatan_id, kegiatan_id, tanggal_mulai, tanggal_selesai, alasan, status, created_by)
        VALUES (:pb, :mid, :jid, :kid, :tgl, :tgl, :alasan, "AKTIF", :uid)
    ')->execute([
        'pb' => $pembimbingId,
        'mid' => $munawibId,
        'jid' => $jadwalId,
        'kid' => $kegiatanId,
        'tgl' => $tanggal,
        'alasan' => $alasan,
        'uid' => $userId > 0 ? $userId : null,
    ]);

    $stExist = $pdo->prepare('SELECT id FROM pembimbing_jadwal_override WHERE pembimbing_id = :pb AND jadwal_id = :jid AND tanggal = :tgl AND jenis = "CARI_MUNAWIB" LIMIT 1');
    $stExist->execute(['pb' => $pembimbingId, 'jid' => $jadwalId, 'tgl' => $tanggal]);
    $existId = (int) ($stExist->fetchColumn() ?: 0);
    if ($existId > 0) {
        $pdo->prepare('UPDATE pembimbing_jadwal_override SET munawib_id = :mid, materi_pengganti = :mat, alasan = :alasan, updated_at = NOW() WHERE id = :id')
            ->execute(['mid' => $munawibId, 'mat' => $materiJson, 'alasan' => $alasan, 'id' => $existId]);
    } else {
        $pdo->prepare('
            INSERT INTO pembimbing_jadwal_override
            (pembimbing_id, jadwal_id, kegiatan_id, tanggal, jenis, jam_mulai_asli, jam_selesai_asli, munawib_id, materi_pengganti, alasan)
            VALUES (:pb, :jid, :kid, :tgl, "CARI_MUNAWIB", :jma, :jsa, :mid, :mat, :alasan)
        ')->execute([
            'pb' => $pembimbingId,
            'jid' => $jadwalId,
            'kid' => $kegiatanId,
            'tgl' => $tanggal,
            'jma' => $slot['jam_mulai'],
            'jsa' => $slot['jam_selesai'],
            'mid' => $munawibId,
            'mat' => $materiJson,
            'alasan' => $alasan,
        ]);
    }

    pb_jadwal_kirim_notifikasi(
        $pdo,
        '👤 Permintaan munawib',
        (string) ($slot['nama_kegiatan'] ?? '') . ' · ' . $tanggal . "\n"
        . 'Munawib: ' . (string) ($mw['nama'] ?? '') . "\n"
        . 'Tugas: ' . pb_jadwal_materi_ringkas($materiJson) . "\nAlasan: " . $alasan,
        'pb_jadwal:' . $pembimbingId . ':' . $jadwalId . ':' . $tanggal . ':cari_munawib'
    );

    return ['ok' => true, 'pesan' => 'Penugasan munawib dicatat untuk hari ini.'];
}

/**
 * @return list<array<string,mixed>>
 */
function pb_jadwal_riwayat_override(PDO $pdo, int $pembimbingId, int $limit = 40): array
{
    pb_jadwal_override_ensure_schema($pdo);
    if ($pembimbingId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT o.*, k.nama_kegiatan, m.nama AS munawib_nama
        FROM pembimbing_jadwal_override o
        LEFT JOIN kegiatan k ON k.id = o.kegiatan_id
        LEFT JOIN munawib m ON m.id = o.munawib_id
        WHERE o.pembimbing_id = :pb
        ORDER BY o.tanggal DESC, o.id DESC
        LIMIT ' . max(1, min($limit, 100))
    );
    $st->execute(['pb' => $pembimbingId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pb_jadwal_hapus_override(PDO $pdo, int $pembimbingId, int $overrideId): array
{
    pb_jadwal_override_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM pembimbing_jadwal_override WHERE id = :id AND pembimbing_id = :pb LIMIT 1');
    $st->execute(['id' => $overrideId, 'pb' => $pembimbingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'pesan' => 'Data tidak ditemukan.'];
    }
    $cek = pb_jadwal_cek_batas_waktu((string) $row['tanggal'], (string) $row['jam_mulai_asli']);
    if (!$cek['ok']) {
        return ['ok' => false, 'pesan' => $cek['pesan']];
    }
    $pdo->prepare('DELETE FROM pembimbing_jadwal_override WHERE id = :id AND pembimbing_id = :pb')->execute(['id' => $overrideId, 'pb' => $pembimbingId]);

    return ['ok' => true, 'pesan' => 'Perubahan dibatalkan.'];
}
