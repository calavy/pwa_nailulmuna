<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/yayasan.php';
require_once __DIR__ . '/yayasan_musyawarah.php';
require_once __DIR__ . '/yayasan_timeline.php';
require_once __DIR__ . '/wa_otomatis.php';

function yayasan_notulen_ensure_schema(PDO $pdo): void
{
    yayasan_ensure_tables($pdo);
    yayasan_musyawarah_ensure_schema($pdo);

    $notulenCols = [
        'timeline_json' => 'LONGTEXT NULL',
        'foto_path' => 'VARCHAR(500) NULL',
    ];
    foreach ($notulenCols as $col => $def) {
        if (!column_exists($pdo, 'yayasan_notulen', $col)) {
            try {
                $pdo->exec('ALTER TABLE yayasan_notulen ADD COLUMN ' . $col . ' ' . $def);
            } catch (PDOException $e) {
            }
        }
    }

    if (!column_exists($pdo, 'yayasan_rapat', 'undangan_wa_kirim_at')) {
        try {
            $pdo->exec('ALTER TABLE yayasan_rapat ADD COLUMN undangan_wa_kirim_at TIMESTAMP NULL DEFAULT NULL');
        } catch (PDOException $e) {
        }
    }
}

function yayasan_notulen_foto_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/yayasan_rapat';
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int} $file
 * @return array{ok:bool,path?:string,error?:string}
 */
function yayasan_notulen_handle_foto_upload(array $file, ?string $oldRelativePath = null): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload foto gagal. Coba lagi.'];
    }
    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ok' => false, 'error' => 'Format foto: JPG, PNG, atau WEBP.'];
    }
    if (!is_uploaded_file($tmpFile)) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    if (@filesize($tmpFile) > 3 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Ukuran foto maksimal 3 MB.'];
    }
    $targetDir = yayasan_notulen_foto_upload_dir();
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }
    $safeName = 'rapat-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan foto ke server.'];
    }
    if (function_exists('user_profil_optimize_uploaded_image')) {
        require_once __DIR__ . '/user_profil.php';
        user_profil_optimize_uploaded_image($targetPath);
    }
    if ($oldRelativePath !== null && $oldRelativePath !== '') {
        $oldFull = dirname(__DIR__) . '/' . ltrim($oldRelativePath, '/');
        if (is_file($oldFull) && str_starts_with($oldRelativePath, 'uploads/yayasan_rapat/')) {
            @unlink($oldFull);
        }
    }

    return ['ok' => true, 'path' => 'uploads/yayasan_rapat/' . $safeName];
}

function yayasan_rapat_format_jam_24(?string $time): string
{
    $time = trim((string) $time);
    if ($time === '' || $time === '00:00:00') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    return substr($time, 0, 5);
}

/**
 * @return list<array{bagian:string,keputusan:string,penanggung_jawab:string,waktu_mulai:string,batas_waktu:string,keterangan:string}>
 */
function yayasan_notulen_timeline_rows_from_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [
            'bagian' => trim((string) ($row['bagian'] ?? '')),
            'keputusan' => trim((string) ($row['keputusan'] ?? '')),
            'penanggung_jawab' => trim((string) ($row['penanggung_jawab'] ?? '')),
            'waktu_mulai' => trim((string) ($row['waktu_mulai'] ?? '')),
            'batas_waktu' => trim((string) ($row['batas_waktu'] ?? '')),
            'keterangan' => trim((string) ($row['keterangan'] ?? '')),
        ];
        if ($item['keputusan'] === '' && $item['bagian'] === '' && $item['penanggung_jawab'] === '') {
            continue;
        }
        $out[] = $item;
    }

    return $out;
}

/**
 * @param list<array{bagian:string,keputusan:string,penanggung_jawab:string,waktu_mulai:string,batas_waktu:string,keterangan:string}> $rows
 */
function yayasan_notulen_timeline_rows_to_legacy_text(array $rows): string
{
    $lines = [];
    foreach ($rows as $row) {
        $judul = trim((string) ($row['keputusan'] ?? ''));
        if ($judul === '') {
            continue;
        }
        $bagian = trim((string) ($row['bagian'] ?? ''));
        if ($bagian !== '') {
            $judul = '[' . $bagian . '] ' . $judul;
        }
        $parts = [$judul];
        $batas = trim((string) ($row['batas_waktu'] ?? ''));
        if ($batas !== '') {
            $parts[] = 'batas ' . $batas;
        }
        $pj = trim((string) ($row['penanggung_jawab'] ?? ''));
        if ($pj !== '') {
            $parts[] = 'PJ: ' . $pj;
        }
        $ket = trim((string) ($row['keterangan'] ?? ''));
        $line = implode(' | ', $parts);
        if ($ket !== '') {
            $line .= ' — ' . $ket;
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

function yayasan_notulen_bagian_to_category(string $bagian): string
{
    $b = strtolower(trim($bagian));
    if ($b === '') {
        return 'Yayasan';
    }
    if (str_contains($b, 'akademik') || str_contains($b, 'madrasah') || str_contains($b, 'pendidikan')) {
        return 'Akademik';
    }
    if (str_contains($b, 'asrama') || str_contains($b, 'kesantrian') || str_contains($b, 'pengasuhan')) {
        return 'Asrama';
    }

    return 'Yayasan';
}

/**
 * @return array{date:string,datetime:string}
 */
function yayasan_notulen_parse_waktu_field(string $value, string $rapatDate, string $defaultTime = '08:00:00'): array
{
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') {
        return [
            'date' => $rapatDate,
            'datetime' => $rapatDate . ' ' . $defaultTime,
        ];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
        $ts = strtotime(str_replace('T', ' ', $value));

        return [
            'date' => $ts !== false ? date('Y-m-d', $ts) : $rapatDate,
            'datetime' => $ts !== false ? date('Y-m-d H:i:s', $ts) : ($rapatDate . ' ' . $defaultTime),
        ];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return [
            'date' => $value,
            'datetime' => $value . ' ' . $defaultTime,
        ];
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
        $jam = yayasan_rapat_format_jam_24($value) . ':00';

        return [
            'date' => $rapatDate,
            'datetime' => $rapatDate . ' ' . $jam,
        ];
    }
    $parsed = yayasan_tugas_parse_date($value);
    if ($parsed !== null) {
        return [
            'date' => $parsed,
            'datetime' => $parsed . ' ' . $defaultTime,
        ];
    }

    return [
        'date' => $rapatDate,
        'datetime' => $rapatDate . ' ' . $defaultTime,
    ];
}

/**
 * @param list<array{bagian:string,keputusan:string,penanggung_jawab:string,waktu_mulai:string,batas_waktu:string,keterangan:string}> $rows
 * @return list<array<string, mixed>>
 */
function yayasan_notulen_timeline_to_task_items(array $rows, string $rapatDate): array
{
    $out = [];
    foreach ($rows as $row) {
        $keputusan = trim((string) ($row['keputusan'] ?? ''));
        if ($keputusan === '') {
            continue;
        }
        $bagian = trim((string) ($row['bagian'] ?? ''));
        $start = yayasan_notulen_parse_waktu_field((string) ($row['waktu_mulai'] ?? ''), $rapatDate, '08:00:00');
        $due = yayasan_notulen_parse_waktu_field((string) ($row['batas_waktu'] ?? ''), $start['date'], '17:00:00');
        if (strtotime($due['datetime']) < strtotime($start['datetime'])) {
            $due = $start;
        }
        $desk = trim((string) ($row['keterangan'] ?? ''));
        if ($bagian !== '' && $desk !== '') {
            $desk = 'Bagian: ' . $bagian . '. ' . $desk;
        } elseif ($bagian !== '') {
            $desk = 'Bagian: ' . $bagian;
        }
        $out[] = [
            'judul' => mb_substr($keputusan, 0, 200),
            'penanggung_jawab' => trim((string) ($row['penanggung_jawab'] ?? '')) ?: null,
            'tanggal_mulai' => $start['date'],
            'tanggal_target' => $due['date'],
            'start_at' => $start['datetime'],
            'due_at' => $due['datetime'],
            'category' => yayasan_notulen_bagian_to_category($bagian),
            'deskripsi' => $desk !== '' ? $desk : null,
            'progress' => 0,
        ];
    }

    return $out;
}

function yayasan_notulen_format_hasil_rapat(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $html = '';
    $inOl = false;
    $inUl = false;

    $closeLists = static function () use (&$html, &$inOl, &$inUl): void {
        if ($inOl) {
            $html .= '</ol>';
            $inOl = false;
        }
        if ($inUl) {
            $html .= '</ul>';
            $inUl = false;
        }
    };

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            $closeLists();
            continue;
        }
        if (preg_match('/^(\d+)[.)]\s+(.+)$/', $trim, $m)) {
            if (!$inOl) {
                $closeLists();
                $html .= '<ol class="yn-list yn-list-ol">';
                $inOl = true;
            }
            $html .= '<li>' . htmlspecialchars($m[2]) . '</li>';
            continue;
        }
        if (preg_match('/^[-•*]\s+(.+)$/', $trim, $m)) {
            if (!$inUl) {
                $closeLists();
                $html .= '<ul class="yn-list yn-list-ul">';
                $inUl = true;
            }
            $html .= '<li>' . htmlspecialchars($m[1]) . '</li>';
            continue;
        }
        $closeLists();
        $html .= '<p class="yn-para">' . htmlspecialchars($trim) . '</p>';
    }
    $closeLists();

    return $html !== '' ? $html : '<p class="text-muted mb-0">—</p>';
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_rapat_undangan_penerima(PDO $pdo, int $rapatId): array
{
    yayasan_notulen_ensure_schema($pdo);
    $rapatSt = $pdo->prepare('SELECT * FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $rapatSt->execute(['id' => $rapatId]);
    $rapat = $rapatSt->fetch(PDO::FETCH_ASSOC);
    if (!$rapat) {
        return [];
    }

    $tanggal = (string) ($rapat['tanggal_rapat'] ?? date('Y-m-d'));
    $undangan = yayasan_rapat_undangan_list($pdo, $rapatId);
    $sql = '
        SELECT p.*
        FROM yayasan_pengurus p
        WHERE p.is_aktif = 1
          AND p.no_wa IS NOT NULL
          AND TRIM(p.no_wa) <> ""
    ';
    $params = [];
    if ($undangan !== []) {
        $or = [];
        foreach ($undangan as $i => $u) {
            $or[] = '(p.jabatan = :j' . $i . ' AND (p.kategori = :k' . $i . ' OR :kcat' . $i . ' = "SEMUA"))';
            $kat = strtoupper((string) ($u['kategori'] ?? 'YAYASAN'));
            $params['j' . $i] = (string) $u['jabatan'];
            $params['k' . $i] = $kat === 'SEMUA' ? 'YAYASAN' : $kat;
            $params['kcat' . $i] = $kat;
        }
        $sql .= ' AND (' . implode(' OR ', $or) . ')';
    } else {
        $sql .= ' AND p.kategori = "YAYASAN"';
    }
    $sql .= ' ORDER BY p.urutan ASC, p.nama ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!yayasan_sdm_periode_aktif($row, $tanggal)) {
            continue;
        }
        $phone = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
        if ($phone === '' || isset($seen[$phone])) {
            continue;
        }
        $seen[$phone] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * @return array{ok:bool,sent:int,total:int,message:string}
 */
function yayasan_rapat_kirim_undangan_wa(PDO $pdo, int $rapatId): array
{
    yayasan_notulen_ensure_schema($pdo);
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['ok' => false, 'sent' => 0, 'total' => 0, 'message' => 'Pengiriman WA otomatis tidak aktif atau gateway belum dikonfigurasi.'];
    }

    $rapatSt = $pdo->prepare('SELECT * FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $rapatSt->execute(['id' => $rapatId]);
    $rapat = $rapatSt->fetch(PDO::FETCH_ASSOC);
    if (!$rapat) {
        return ['ok' => false, 'sent' => 0, 'total' => 0, 'message' => 'Rapat tidak ditemukan.'];
    }

    $penerima = yayasan_rapat_undangan_penerima($pdo, $rapatId);
    if ($penerima === []) {
        return ['ok' => false, 'sent' => 0, 'total' => 0, 'message' => 'Tidak ada pengurus dengan nomor WA. Isi no_wa di SDM Yayasan atau centang jabatan diundang.'];
    }

    $ponpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));
    $judul = trim((string) ($rapat['judul'] ?? 'Musyawarah Rutin'));
    $tanggal = yayasan_format_tanggal_rapat((string) ($rapat['tanggal_rapat'] ?? ''));
    $jamMulai = yayasan_rapat_format_jam_24($rapat['waktu_mulai'] ?? null);
    $jamSelesai = yayasan_rapat_format_jam_24($rapat['waktu_selesai'] ?? null);
    $jamLine = '';
    if ($jamMulai !== '' && $jamSelesai !== '') {
        $jamLine = $jamMulai . ' – ' . $jamSelesai . ' WIB (24 jam)';
    } elseif ($jamMulai !== '') {
        $jamLine = $jamMulai . ' WIB (24 jam)';
    }
    $lokasi = trim((string) ($rapat['lokasi'] ?? ''));
    $agenda = trim((string) ($rapat['agenda_ringkas'] ?? ''));
    $jenisLabel = yayasan_label_jenis_rapat((string) ($rapat['jenis'] ?? 'RUTIN'));

    $sent = 0;
    foreach ($penerima as $row) {
        $phone = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
        if ($phone === '') {
            continue;
        }
        $nama = trim((string) ($row['nama'] ?? 'Bapak/Ibu'));
        $jabatan = trim((string) ($row['jabatan'] ?? ''));
        $msg = '*' . $ponpes . "*\n";
        $msg .= "*Undangan " . $jenisLabel . "*\n\n";
        $msg .= 'Yth. ' . $nama;
        if ($jabatan !== '') {
            $msg .= ' (' . $jabatan . ')';
        }
        $msg .= "\n\n";
        $msg .= "Mohon kehadiran Bapak/Ibu pada:\n\n";
        $msg .= '*Judul:* ' . $judul . "\n";
        $msg .= '*Tanggal:* ' . $tanggal . "\n";
        if ($jamLine !== '') {
            $msg .= '*Waktu:* ' . $jamLine . "\n";
        }
        if ($lokasi !== '') {
            $msg .= '*Tempat:* ' . $lokasi . "\n";
        }
        if ($agenda !== '') {
            $msg .= "\n*Agenda:*\n" . $agenda . "\n";
        }
        $msg .= "\nTerima kasih. Wassalamu'alaikum wr. wb.";
        if (send_wa_message($pdo, $phone, $msg)) {
            $sent++;
        }
    }

    if ($sent > 0) {
        $pdo->prepare('UPDATE yayasan_rapat SET undangan_wa_kirim_at = NOW() WHERE id = :id')->execute(['id' => $rapatId]);
    }

    $total = count($penerima);
    if ($sent === 0) {
        return ['ok' => false, 'sent' => 0, 'total' => $total, 'message' => 'Undangan gagal terkirim ke semua penerima. Periksa gateway WA.'];
    }

    return [
        'ok' => true,
        'sent' => $sent,
        'total' => $total,
        'message' => 'Undangan WA terkirim ke ' . $sent . ' dari ' . $total . ' pengurus.',
    ];
}
