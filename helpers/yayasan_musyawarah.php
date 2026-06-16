<?php

declare(strict_types=1);

require_once __DIR__ . '/yayasan.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';

/** @return list<string> */
function yayasan_sdm_jabatan_opsi(string $kategori = 'YAYASAN'): array
{
    if (strtoupper($kategori) === 'LEMBAGA') {
        return [
            'Ketua Lembaga',
            'Wakil Ketua',
            'Sekretaris',
            'Bendahara',
            'Koordinator',
            'Anggota',
            'Lainnya',
        ];
    }

    return yayasan_jabatan_opsi();
}

function yayasan_sdm_normalize_jabatan(string $raw, string $fallback = 'Anggota'): string
{
    $jabatan = trim($raw);
    if ($jabatan === '') {
        return $fallback;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($jabatan, 0, 80);
    }

    return substr($jabatan, 0, 80);
}

/**
 * Gabungan jabatan bawaan + yang sudah dipakai di data SDM aktif.
 *
 * @return list<string>
 */
function yayasan_sdm_jabatan_saran(PDO $pdo, string $kategori = 'YAYASAN'): array
{
    yayasan_musyawarah_ensure_schema($pdo);
    $kat = strtoupper($kategori) === 'LEMBAGA' ? 'LEMBAGA' : 'YAYASAN';
    $st = $pdo->prepare('
        SELECT DISTINCT jabatan
        FROM yayasan_pengurus
        WHERE kategori = :kat AND jabatan IS NOT NULL AND TRIM(jabatan) <> ""
        ORDER BY jabatan ASC
    ');
    $st->execute(['kat' => $kat]);
    $db = array_map(static fn($v): string => trim((string) $v), $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $merged = [];
    foreach (array_merge(yayasan_sdm_jabatan_opsi($kat), $db) as $jab) {
        $jab = trim($jab);
        if ($jab === '') {
            continue;
        }
        if (!in_array($jab, $merged, true)) {
            $merged[] = $jab;
        }
    }

    return $merged;
}

function yayasan_musyawarah_ensure_schema(PDO $pdo): void
{
    yayasan_ensure_tables($pdo);

    $cols = [
        'qr' => 'VARCHAR(60) NULL',
        'kategori' => 'ENUM("YAYASAN","LEMBAGA") NOT NULL DEFAULT "YAYASAN"',
        'lembaga_nama' => 'VARCHAR(120) NULL',
        'periode_mulai' => 'DATE NULL',
        'periode_selesai' => 'DATE NULL',
    ];
    foreach ($cols as $col => $def) {
        if (!column_exists($pdo, 'yayasan_pengurus', $col)) {
            try {
                $pdo->exec('ALTER TABLE yayasan_pengurus ADD COLUMN ' . $col . ' ' . $def);
            } catch (PDOException $e) {
            }
        }
    }
    try {
        $pdo->exec('CREATE UNIQUE INDEX idx_yayasan_pengurus_qr ON yayasan_pengurus (qr)');
    } catch (PDOException $e) {
    }

    if (!column_exists($pdo, 'yayasan_rapat', 'presensi_scan')) {
        try {
            $pdo->exec('ALTER TABLE yayasan_rapat ADD COLUMN presensi_scan TINYINT(1) NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
        }
    }
    try {
        $pdo->exec('ALTER TABLE yayasan_rapat MODIFY COLUMN jenis ENUM("RUTIN","INSIDENTAL","MUSYAWARAH","LAIN") NOT NULL DEFAULT "RUTIN"');
    } catch (PDOException $e) {
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_rapat_undangan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rapat_id INT NOT NULL,
            jabatan VARCHAR(80) NOT NULL,
            kategori ENUM("YAYASAN","LEMBAGA","SEMUA") NOT NULL DEFAULT "YAYASAN",
            wajib_scan TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_rapat_jabatan (rapat_id, jabatan, kategori),
            INDEX idx_rapat_undangan_rapat (rapat_id),
            CONSTRAINT fk_rapat_undangan_rapat FOREIGN KEY (rapat_id) REFERENCES yayasan_rapat(id) ON DELETE CASCADE
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_musyawarah (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rapat_id INT NOT NULL,
            pengurus_id INT NOT NULL,
            status ENUM("HADIR","IZIN","ALPA") NOT NULL DEFAULT "HADIR",
            tanggal DATE NOT NULL,
            jam TIME NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_presensi_musyawarah (rapat_id, pengurus_id),
            INDEX idx_presensi_musyawarah_tanggal (tanggal),
            CONSTRAINT fk_presensi_musyawarah_rapat FOREIGN KEY (rapat_id) REFERENCES yayasan_rapat(id) ON DELETE CASCADE,
            CONSTRAINT fk_presensi_musyawarah_pengurus FOREIGN KEY (pengurus_id) REFERENCES yayasan_pengurus(id) ON DELETE CASCADE
        )
    ');
}

function yayasan_sdm_resolve_qr(array $row): string
{
    $qr = trim((string) ($row['qr'] ?? ''));
    if ($qr !== '') {
        return $qr;
    }

    return 'YY-' . (int) ($row['id'] ?? 0);
}

function yayasan_sdm_qr_image_url(string $code): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=10&data=' . rawurlencode($code);
}

/** @return array<string, mixed>|null */
function yayasan_sdm_find_by_code(PDO $pdo, string $code): ?array
{
    yayasan_musyawarah_ensure_schema($pdo);
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $st = $pdo->prepare('
        SELECT *
        FROM yayasan_pengurus
        WHERE is_aktif = 1
          AND (qr = :code OR CONCAT("YY-", id) = :code2)
        LIMIT 1
    ');
    $st->execute(['code' => $code, 'code2' => $code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function yayasan_sdm_periode_aktif(array $row, string $tanggal): bool
{
    $mulai = trim((string) ($row['periode_mulai'] ?? ''));
    $selesai = trim((string) ($row['periode_selesai'] ?? ''));
    if ($mulai !== '' && $tanggal < $mulai) {
        return false;
    }
    if ($selesai !== '' && $tanggal > $selesai) {
        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_musyawarah_rapat_aktif(PDO $pdo, string $tanggal, string $jam, ?int $rapatIdFilter = null): array
{
    yayasan_musyawarah_ensure_schema($pdo);
    $sql = '
        SELECT *
        FROM yayasan_rapat
        WHERE tanggal_rapat = :tgl
          AND presensi_scan = 1
          AND status = "DRAFT"
    ';
    $params = ['tgl' => $tanggal];
    if ($rapatIdFilter !== null && $rapatIdFilter > 0) {
        $sql .= ' AND id = :rid';
        $params['rid'] = $rapatIdFilter;
    }
    $sql .= ' ORDER BY COALESCE(waktu_mulai, "00:00:00") ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $mulai = trim((string) ($row['waktu_mulai'] ?? ''));
        $selesai = trim((string) ($row['waktu_selesai'] ?? ''));
        if ($mulai !== '' && $jam < substr($mulai, 0, 8)) {
            continue;
        }
        if ($selesai !== '' && $selesai !== '00:00:00' && $jam > substr($selesai, 0, 8)) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/** @return list<array{jabatan:string,kategori:string,wajib_scan:int}> */
function yayasan_rapat_undangan_list(PDO $pdo, int $rapatId): array
{
    yayasan_musyawarah_ensure_schema($pdo);
    if ($rapatId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT jabatan, kategori, wajib_scan
        FROM yayasan_rapat_undangan
        WHERE rapat_id = :id
        ORDER BY kategori ASC, jabatan ASC
    ');
    $st->execute(['id' => $rapatId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function yayasan_rapat_jabatan_diundang(PDO $pdo, int $rapatId, array $pengurus): bool
{
    $undangan = yayasan_rapat_undangan_list($pdo, $rapatId);
    if ($undangan === []) {
        return true;
    }
    $jabatan = trim((string) ($pengurus['jabatan'] ?? ''));
    $kat = strtoupper(trim((string) ($pengurus['kategori'] ?? 'YAYASAN')));
    foreach ($undangan as $u) {
        $uKat = strtoupper((string) ($u['kategori'] ?? 'YAYASAN'));
        $uJab = trim((string) ($u['jabatan'] ?? ''));
        if ($uJab !== $jabatan) {
            continue;
        }
        if ($uKat === 'SEMUA' || $uKat === $kat) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{ok:bool,message:string}
 */
function yayasan_musyawarah_catat_scan(
    PDO $pdo,
    int $rapatId,
    int $pengurusId,
    string $tanggal,
    string $jam,
    int $createdBy
): array {
    yayasan_musyawarah_ensure_schema($pdo);
    if ($rapatId <= 0 || $pengurusId <= 0) {
        return ['ok' => false, 'message' => 'Data rapat atau pengurus tidak valid.'];
    }

    $chk = $pdo->prepare('SELECT id FROM presensi_musyawarah WHERE rapat_id = :rid AND pengurus_id = :pid LIMIT 1');
    $chk->execute(['rid' => $rapatId, 'pid' => $pengurusId]);
    if ($chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Presensi musyawarah sudah tercatat untuk rapat ini.'];
    }

    $ins = $pdo->prepare('
        INSERT INTO presensi_musyawarah (rapat_id, pengurus_id, status, tanggal, jam, created_by)
        VALUES (:rid, :pid, "HADIR", :tgl, :jam, :by)
    ');
    $ins->execute([
        'rid' => $rapatId,
        'pid' => $pengurusId,
        'tgl' => $tanggal,
        'jam' => $jam,
        'by' => $createdBy > 0 ? $createdBy : null,
    ]);

    return ['ok' => true, 'message' => 'Presensi musyawarah tercatat.'];
}

/**
 * @param list<array{jabatan:string,kategori:string,wajib_scan?:int}> $items
 */
function yayasan_rapat_simpan_undangan(PDO $pdo, int $rapatId, array $items): void
{
    yayasan_musyawarah_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM yayasan_rapat_undangan WHERE rapat_id = :id')->execute(['id' => $rapatId]);
    if ($items === []) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO yayasan_rapat_undangan (rapat_id, jabatan, kategori, wajib_scan)
        VALUES (:rid, :jabatan, :kategori, :wajib)
    ');
    foreach ($items as $item) {
        $jabatan = trim((string) ($item['jabatan'] ?? ''));
        if ($jabatan === '') {
            continue;
        }
        $kat = strtoupper(trim((string) ($item['kategori'] ?? 'YAYASAN')));
        if (!in_array($kat, ['YAYASAN', 'LEMBAGA', 'SEMUA'], true)) {
            $kat = 'YAYASAN';
        }
        $ins->execute([
            'rid' => $rapatId,
            'jabatan' => $jabatan,
            'kategori' => $kat,
            'wajib' => !empty($item['wajib_scan']) ? 1 : 0,
        ]);
    }
}

/**
 * @return array{hadir:list<array>,izin:list<array>,alpa:list<array>,wajib:list<array>}
 */
function yayasan_musyawarah_rekap_rapat(PDO $pdo, int $rapatId): array
{
    yayasan_musyawarah_ensure_schema($pdo);
    $rapatSt = $pdo->prepare('SELECT * FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $rapatSt->execute(['id' => $rapatId]);
    $rapat = $rapatSt->fetch(PDO::FETCH_ASSOC);
    if (!$rapat) {
        return ['hadir' => [], 'izin' => [], 'alpa' => [], 'wajib' => [], 'rapat' => null];
    }

    $undangan = yayasan_rapat_undangan_list($pdo, $rapatId);
    $wajibJabatan = [];
    foreach ($undangan as $u) {
        if ((int) ($u['wajib_scan'] ?? 0) === 1) {
            $wajibJabatan[] = $u;
        }
    }

    $sqlWajib = '
        SELECT p.*
        FROM yayasan_pengurus p
        WHERE p.is_aktif = 1
    ';
    $params = [];
    if ($wajibJabatan !== []) {
        $or = [];
        foreach ($wajibJabatan as $i => $u) {
            $or[] = '(p.jabatan = :j' . $i . ' AND (p.kategori = :k' . $i . ' OR :kcat' . $i . ' = "SEMUA"))';
            $params['j' . $i] = (string) $u['jabatan'];
            $kat = strtoupper((string) ($u['kategori'] ?? 'YAYASAN'));
            $params['k' . $i] = $kat === 'SEMUA' ? 'YAYASAN' : $kat;
            $params['kcat' . $i] = $kat;
        }
        $sqlWajib .= ' AND (' . implode(' OR ', $or) . ')';
    }
    $sqlWajib .= ' ORDER BY p.kategori ASC, p.urutan ASC, p.nama ASC';
    $wajibSt = $pdo->prepare($sqlWajib);
    $wajibSt->execute($params);
    $wajibRows = $wajibSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $tanggal = (string) ($rapat['tanggal_rapat'] ?? date('Y-m-d'));
    $wajibRows = array_values(array_filter($wajibRows, static fn(array $r): bool => yayasan_sdm_periode_aktif($r, $tanggal)));

    $presSt = $pdo->prepare('
        SELECT pm.*, p.nama, p.jabatan, p.kategori, p.lembaga_nama
        FROM presensi_musyawarah pm
        INNER JOIN yayasan_pengurus p ON p.id = pm.pengurus_id
        WHERE pm.rapat_id = :id
    ');
    $presSt->execute(['id' => $rapatId]);
    $presensi = $presSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byPid = [];
    foreach ($presensi as $pr) {
        $byPid[(int) $pr['pengurus_id']] = $pr;
    }

    $hadir = [];
    $izin = [];
    foreach ($presensi as $pr) {
        $st = strtoupper((string) ($pr['status'] ?? 'HADIR'));
        if ($st === 'IZIN') {
            $izin[] = $pr;
        } elseif ($st === 'HADIR') {
            $hadir[] = $pr;
        }
    }

    $alpa = [];
    foreach ($wajibRows as $wr) {
        $pid = (int) ($wr['id'] ?? 0);
        if (!isset($byPid[$pid])) {
            $alpa[] = $wr;
            continue;
        }
        $st = strtoupper((string) ($byPid[$pid]['status'] ?? ''));
        if ($st === 'ALPA') {
            $alpa[] = $wr;
        }
    }

    return [
        'rapat' => $rapat,
        'hadir' => $hadir,
        'izin' => $izin,
        'alpa' => $alpa,
        'wajib' => $wajibRows,
        'undangan' => $undangan,
    ];
}

function yayasan_musyawarah_format_laporan_wa(PDO $pdo, int $rapatId): string
{
    $rekap = yayasan_musyawarah_rekap_rapat($pdo, $rapatId);
    $rapat = $rekap['rapat'] ?? null;
    if (!is_array($rapat)) {
        return '';
    }
    $namaPonpes = app_brand_nama_ponpes($pdo);
    $judul = trim((string) ($rapat['judul'] ?? 'Musyawarah'));
    $tgl = yayasan_format_tanggal_rapat(
        (string) ($rapat['tanggal_rapat'] ?? ''),
        $rapat['waktu_mulai'] !== null ? (string) $rapat['waktu_mulai'] : null,
        $rapat['waktu_selesai'] !== null ? (string) $rapat['waktu_selesai'] : null
    );

    $fmtNama = static function (array $row): string {
        $nama = trim((string) ($row['nama'] ?? $row['nama_santri'] ?? '-'));
        $jab = trim((string) ($row['jabatan'] ?? ''));
        if ($jab !== '') {
            return $nama . ' (' . $jab . ')';
        }

        return $nama;
    };

    $lines = [
        '*Laporan Presensi Musyawarah*',
        $namaPonpes,
        '',
        'Rapat: ' . $judul,
        'Tanggal: ' . $tgl,
        '',
    ];

    $hadir = $rekap['hadir'] ?? [];
    $izin = $rekap['izin'] ?? [];
    $alpa = $rekap['alpa'] ?? [];

    $lines[] = '*Hadir (' . count($hadir) . '):*';
    if ($hadir === []) {
        $lines[] = '- (belum ada)';
    } else {
        foreach ($hadir as $h) {
            $lines[] = '- ' . $fmtNama($h);
        }
    }
    $lines[] = '';
    $lines[] = '*Izin (' . count($izin) . '):*';
    if ($izin === []) {
        $lines[] = '- (tidak ada)';
    } else {
        foreach ($izin as $h) {
            $lines[] = '- ' . $fmtNama($h);
        }
    }
    $lines[] = '';
    $lines[] = '*Tidak hadir / belum scan (' . count($alpa) . '):*';
    if ($alpa === []) {
        $lines[] = '- (semua wajib hadir)';
    } else {
        foreach ($alpa as $h) {
            $lines[] = '- ' . $fmtNama($h);
        }
    }

    return implode("\n", $lines);
}

/**
 * @return array{ok:bool,message:string,sent:int}
 */
function yayasan_musyawarah_kirim_wa_laporan(PDO $pdo, int $rapatId): array
{
    if (trim((string) app_setting($pdo, 'wa_musyawarah_enabled', '0')) !== '1') {
        return ['ok' => false, 'message' => 'WA laporan musyawarah belum diaktifkan di Pengaturan WA.', 'sent' => 0];
    }
    $targets = wa_otomatis_parse_targets(trim((string) app_setting($pdo, 'wa_musyawarah_target', '')));
    if ($targets === []) {
        return ['ok' => false, 'message' => 'Nomor tujuan WA pengasuh musyawarah belum diisi.', 'sent' => 0];
    }
    $pesan = yayasan_musyawarah_format_laporan_wa($pdo, $rapatId);
    if ($pesan === '') {
        return ['ok' => false, 'message' => 'Data rapat tidak ditemukan.', 'sent' => 0];
    }

    $sent = 0;
    $errors = [];
    foreach ($targets as $target) {
        $res = send_wa_message_with_result($pdo, $target, $pesan);
        if (!empty($res['ok'])) {
            $sent++;
        } else {
            $errors[] = (string) ($res['error'] ?? 'gagal');
        }
    }
    if ($sent > 0) {
        save_setting($pdo, 'wa_musyawarah_last_rapat_id', (string) $rapatId);
        save_setting($pdo, 'wa_musyawarah_last_sent_at', date('Y-m-d H:i:s'));
    }

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Laporan musyawarah terkirim ke ' . $sent . ' nomor.'
            : ('Gagal kirim WA: ' . implode('; ', $errors)),
        'sent' => $sent,
    ];
}

function yayasan_label_jenis_rapat_extended(string $jenis): string
{
    return match (strtoupper($jenis)) {
        'MUSYAWARAH' => 'Musyawarah',
        default => yayasan_label_jenis_rapat($jenis),
    };
}

/**
 * Konteks timer scan musyawarah (format kompatibel presensi-scan-timer.js).
 *
 * @return array<string, mixed>
 */
function yayasan_musyawarah_scan_jadwal_context(PDO $pdo, ?int $rapatFilter = null, ?string $tanggal = null, ?string $jam = null): array
{
    $empty = [
        'state' => 'none',
        'nama_kegiatan' => '',
        'tingkatan' => '',
        'jam_mulai' => '',
        'jam_selesai' => '',
        'tempat' => '',
        'ends_at' => '',
        'starts_at' => '',
        'seconds_remaining' => 0,
        'seconds_until_start' => 0,
        'libur_nama' => '',
        'slots' => [],
    ];
    $tanggal = $tanggal ?? date('Y-m-d');
    $jam = $jam ?? date('H:i:s');
    $rapats = yayasan_musyawarah_rapat_aktif($pdo, $tanggal, $jam, $rapatFilter);
    if ($rapats === []) {
        return $empty;
    }

    $slots = [];
    $active = null;
    $upcoming = null;
    $nowTs = strtotime($tanggal . ' ' . substr($jam, 0, 8)) ?: time();

    foreach ($rapats as $rapat) {
        $judul = (string) ($rapat['judul'] ?? 'Musyawarah');
        $mulai = trim((string) ($rapat['waktu_mulai'] ?? ''));
        $selesai = trim((string) ($rapat['waktu_selesai'] ?? ''));
        $lokasi = trim((string) ($rapat['lokasi'] ?? ''));
        $label = $judul;
        if ($mulai !== '') {
            $label .= ' [' . substr($mulai, 0, 5);
            if ($selesai !== '' && $selesai !== '00:00:00') {
                $label .= '-' . substr($selesai, 0, 5);
            }
            $label .= ']';
        }
        $slots[] = [
            'kegiatan_id' => (int) ($rapat['id'] ?? 0),
            'nama_kegiatan' => $judul,
            'label' => $label,
            'tingkatan' => $lokasi,
            'jam_mulai' => $mulai,
            'jam_selesai' => $selesai,
        ];

        $startTs = $mulai !== '' ? (strtotime($tanggal . ' ' . substr($mulai, 0, 8)) ?: $nowTs) : $nowTs;
        $endTs = ($selesai !== '' && $selesai !== '00:00:00')
            ? (strtotime($tanggal . ' ' . substr($selesai, 0, 8)) ?: $startTs)
            : ($startTs + 7200);

        if ($nowTs >= $startTs && $nowTs <= $endTs) {
            $active = $rapat;
            $active['_start_ts'] = $startTs;
            $active['_end_ts'] = $endTs;
        } elseif ($nowTs < $startTs && $upcoming === null) {
            $upcoming = $rapat;
            $upcoming['_start_ts'] = $startTs;
            $upcoming['_end_ts'] = $endTs;
        }
    }

    if ($active !== null) {
        $endTs = (int) ($active['_end_ts'] ?? $nowTs);
        return array_merge($empty, [
            'state' => 'active',
            'nama_kegiatan' => (string) ($active['judul'] ?? 'Musyawarah'),
            'tingkatan' => trim((string) ($active['lokasi'] ?? '')),
            'jam_mulai' => substr((string) ($active['waktu_mulai'] ?? ''), 0, 8),
            'jam_selesai' => substr((string) ($active['waktu_selesai'] ?? ''), 0, 8),
            'tempat' => trim((string) ($active['lokasi'] ?? '')),
            'seconds_remaining' => max(0, $endTs - $nowTs),
            'slots' => $slots,
        ]);
    }
    if ($upcoming !== null) {
        $startTs = (int) ($upcoming['_start_ts'] ?? $nowTs);
        return array_merge($empty, [
            'state' => 'upcoming',
            'nama_kegiatan' => (string) ($upcoming['judul'] ?? 'Musyawarah'),
            'tingkatan' => trim((string) ($upcoming['lokasi'] ?? '')),
            'jam_mulai' => substr((string) ($upcoming['waktu_mulai'] ?? ''), 0, 8),
            'jam_selesai' => substr((string) ($upcoming['waktu_selesai'] ?? ''), 0, 8),
            'tempat' => trim((string) ($upcoming['lokasi'] ?? '')),
            'seconds_until_start' => max(0, $startTs - $nowTs),
            'slots' => $slots,
        ]);
    }

    $last = $rapats[count($rapats) - 1];

    return array_merge($empty, [
        'state' => 'ended',
        'nama_kegiatan' => (string) ($last['judul'] ?? 'Musyawarah'),
        'tingkatan' => trim((string) ($last['lokasi'] ?? '')),
        'jam_mulai' => substr((string) ($last['waktu_mulai'] ?? ''), 0, 8),
        'jam_selesai' => substr((string) ($last['waktu_selesai'] ?? ''), 0, 8),
        'slots' => $slots,
    ]);
}

/**
 * @return array{resultType:string,resultMessage:?string,scanRedirect:?string}
 */
function yayasan_musyawarah_proses_scan_post(PDO $pdo, array $post, int $createdBy, ?int $rapatFilter = null): array
{
    require_once __DIR__ . '/presensi_scan_client.php';
    yayasan_musyawarah_ensure_schema($pdo);

    $resultType = 'success';
    $resultMessage = null;
    $scanRedirect = null;
    $scanClock = presensi_scan_resolve_clock($post);
    $action = trim((string) ($post['action'] ?? ''));

    if ($action === 'musyawarah_pick_rapat') {
        $pending = $_SESSION['yayasan_musyawarah_scan_pending'] ?? null;
        $pickRid = (int) ($post['rapat_id'] ?? 0);
        $pickPid = (int) ($post['pengurus_id'] ?? 0);
        if ($rapatFilter !== null && $rapatFilter > 0 && $pickRid !== $rapatFilter) {
            $pickRid = 0;
        }
        if (is_array($pending) && $pickRid > 0 && $pickPid > 0 && (int) ($pending['pengurus_id'] ?? 0) === $pickPid) {
            $allowed = is_array($pending['rapats'] ?? null) ? $pending['rapats'] : [];
            $okRapat = null;
            foreach ($allowed as $rapatRow) {
                if ((int) ($rapatRow['id'] ?? 0) === $pickRid) {
                    $okRapat = $rapatRow;
                    break;
                }
            }
            if ($okRapat !== null) {
                $resPick = yayasan_musyawarah_catat_scan(
                    $pdo,
                    $pickRid,
                    $pickPid,
                    $scanClock['tanggal'],
                    $scanClock['jam'],
                    $createdBy
                );
                $resultType = $resPick['ok'] ? 'success' : 'warning';
                $resultMessage = ($resPick['ok'] ? 'Musyawarah: ' : '') . $resPick['message'];
                if ($resPick['ok']) {
                    $resultMessage .= ' · ' . (string) ($okRapat['judul'] ?? ('Rapat #' . $pickRid));
                }
            } else {
                $resultType = 'warning';
                $resultMessage = 'Rapat yang dipilih tidak valid. Silakan scan ulang.';
            }
        } else {
            $resultType = 'warning';
            $resultMessage = 'Pilihan rapat tidak ditemukan. Silakan scan ulang.';
        }
        unset($_SESSION['yayasan_musyawarah_scan_pending']);

        return compact('resultType', 'resultMessage', 'scanRedirect');
    }

    if (($post['scan_source'] ?? '') !== 'camera') {
        return [
            'resultType' => 'warning',
            'resultMessage' => 'Input manual dinonaktifkan. Silakan gunakan scan kamera.',
            'scanRedirect' => null,
        ];
    }

    $code = trim((string) ($post['kode_qr'] ?? ''));
    if ($code === '') {
        return ['resultType' => 'success', 'resultMessage' => null, 'scanRedirect' => null];
    }

    $yayasanSdm = yayasan_sdm_find_by_code($pdo, $code);
    if (!$yayasanSdm) {
        return [
            'resultType' => 'warning',
            'resultMessage' => 'QR tidak terdaftar sebagai SDM yayasan/lembaga. Gunakan kartu musyawarah.',
            'scanRedirect' => null,
        ];
    }

    $tanggal = $scanClock['tanggal'];
    $jam = $scanClock['jam'];
    if (!yayasan_sdm_periode_aktif($yayasanSdm, $tanggal)) {
        return [
            'resultType' => 'warning',
            'resultMessage' => 'Masa jabatan SDM "' . (string) ($yayasanSdm['nama'] ?? '-') . '" belum/sudah berakhir untuk tanggal ini.',
            'scanRedirect' => null,
        ];
    }

    $rapatAktif = yayasan_musyawarah_rapat_aktif($pdo, $tanggal, $jam, $rapatFilter);
    $eligible = [];
    foreach ($rapatAktif as $rapatRow) {
        if (yayasan_rapat_jabatan_diundang($pdo, (int) $rapatRow['id'], $yayasanSdm)) {
            $eligible[] = $rapatRow;
        }
    }
    if ($eligible === []) {
        return [
            'resultType' => 'warning',
            'resultMessage' => 'Tidak ada rapat musyawarah aktif untuk "' . (string) ($yayasanSdm['nama'] ?? '-') . '" pada jam ini, atau jabatan tidak diundang.',
            'scanRedirect' => null,
        ];
    }
    if (count($eligible) === 1) {
        $only = $eligible[0];
        $resMs = yayasan_musyawarah_catat_scan($pdo, (int) $only['id'], (int) $yayasanSdm['id'], $tanggal, $jam, $createdBy);
        $resultType = $resMs['ok'] ? 'success' : 'warning';
        $resultMessage = ($resMs['ok'] ? 'Musyawarah hadir: ' : '') . (string) ($yayasanSdm['nama'] ?? '-')
            . ($resMs['ok'] ? ' · ' . (string) ($only['judul'] ?? 'Rapat') : ' — ' . $resMs['message']);

        return compact('resultType', 'resultMessage', 'scanRedirect');
    }

    $_SESSION['yayasan_musyawarah_scan_pending'] = [
        'pengurus_id' => (int) $yayasanSdm['id'],
        'pengurus_nama' => (string) ($yayasanSdm['nama'] ?? ''),
        'rapats' => array_map(static function (array $r): array {
            $label = (string) ($r['judul'] ?? 'Rapat');
            $mulai = substr((string) ($r['waktu_mulai'] ?? ''), 0, 5);
            $selesai = substr((string) ($r['waktu_selesai'] ?? ''), 0, 5);
            if ($mulai !== '' && $selesai !== '') {
                $label .= ' [' . $mulai . '-' . $selesai . ']';
            }

            return ['id' => (int) ($r['id'] ?? 0), 'judul' => (string) ($r['judul'] ?? ''), 'label' => $label];
        }, $eligible),
        'created_at' => time(),
    ];

    return [
        'resultType' => 'warning',
        'resultMessage' => 'SDM musyawarah: ' . (string) ($yayasanSdm['nama'] ?? '-') . '. Pilih rapat yang dihadiri.',
        'scanRedirect' => null,
    ];
}
