<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/datetime_display.php';

function kedatangan_libur_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kedatangan_libur_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            akademik_libur_id INT NOT NULL,
            nama VARCHAR(200) NOT NULL,
            tanggal DATE NOT NULL,
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kedatangan_libur_hari (akademik_libur_id, tanggal),
            INDEX idx_kedatangan_libur_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kedatangan_libur_scan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sesi_id INT NOT NULL,
            santri_id INT NOT NULL,
            jam TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_kedatangan_libur_scan (sesi_id, santri_id),
            INDEX idx_kedatangan_libur_scan_sesi (sesi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

function kedatangan_libur_jam_default_mulai(PDO $pdo): string
{
    $raw = app_normalize_jam_hm((string) app_setting($pdo, 'kedatangan_libur_jam_mulai', '07:00'));

    return $raw !== '' ? $raw : '07:00';
}

function kedatangan_libur_jam_default_selesai(PDO $pdo): string
{
    $raw = app_normalize_jam_hm((string) app_setting($pdo, 'kedatangan_libur_jam_selesai', '16:00'));

    return $raw !== '' ? $raw : '16:00';
}

function kedatangan_libur_wali_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_kedatangan_libur_wali_enabled', '1')) === '1';
}

function kedatangan_libur_santri_nama_sql(PDO $pdo, string $alias = 's'): string
{
    $col = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    return $alias . '.' . $col;
}

function kedatangan_libur_menit(string $hm): int
{
    $n = app_normalize_jam_hm($hm);
    if ($n === '' || !preg_match('/^(\d{2}):(\d{2})/', $n, $m)) {
        return -1;
    }

    return ((int) $m[1] * 60) + (int) $m[2];
}

function kedatangan_libur_jam_dalam_jendela(string $jam, string $mulai, string $selesai): bool
{
    $j = kedatangan_libur_menit($jam);
    $a = kedatangan_libur_menit($mulai);
    $b = kedatangan_libur_menit($selesai);
    if ($j < 0 || $a < 0 || $b < 0) {
        return true;
    }
    if ($a <= $b) {
        return $j >= $a && $j <= $b;
    }

    return $j >= $a || $j <= $b;
}

/** Menit setelah jam_selesai sesi; 0 jika tidak telat. */
function kedatangan_libur_menit_telat(string $jam, string $jamMulai, string $jamSelesai): int
{
    $j = kedatangan_libur_menit($jam);
    $a = kedatangan_libur_menit($jamMulai);
    $b = kedatangan_libur_menit($jamSelesai);
    if ($j < 0 || $b < 0) {
        return 0;
    }
    if ($a >= 0 && $a > $b) {
        return ($j > $b && $j < $a) ? ($j - $b) : 0;
    }

    return $j > $b ? ($j - $b) : 0;
}

function kedatangan_libur_format_durasi_menit(int $menit): string
{
    $menit = max(0, $menit);
    $jam = intdiv($menit, 60);
    $sisa = $menit % 60;
    if ($jam > 0 && $sisa > 0) {
        return $jam . ' jam ' . $sisa . ' menit';
    }
    if ($jam > 0) {
        return $jam . ' jam';
    }

    return $sisa . ' menit';
}

/** Hari + tanggal Indonesia untuk WA wali, mis. Selasa, 1 September 2026. */
function kedatangan_libur_format_tanggal_hari(?string $date): string
{
    $raw = trim((string) $date);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $namaHari = $hari[(int) date('w', $ts)] ?? '';
    $namaBulan = $bulan[(int) date('n', $ts)] ?? date('m', $ts);

    return $namaHari . ', ' . (int) date('j', $ts) . ' ' . $namaBulan . ' ' . date('Y', $ts);
}

function kedatangan_libur_is_putri(string $jenisKelamin): bool
{
    $jk = strtolower(trim($jenisKelamin));

    return $jk === 'perempuan' || $jk === 'p' || $jk === 'putri' || str_contains($jk, 'perempuan');
}

/**
 * Rentang akademik_libur yang baru selesai: hari terakhir libur s.d. 3 hari setelahnya.
 *
 * @return list<array<string, mixed>>
 */
function kedatangan_libur_libur_siap(PDO $pdo, ?string $today = null): array
{
    ensure_akademik_libur_table($pdo);
    $today = $today ?? date('Y-m-d');
    $st = $pdo->prepare('
        SELECT *
        FROM akademik_libur
        WHERE tanggal_selesai <= :today
          AND DATE_ADD(tanggal_selesai, INTERVAL 3 DAY) >= :today2
        ORDER BY tanggal_selesai DESC, id DESC
    ');
    $st->execute(['today' => $today, 'today2' => $today]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function kedatangan_libur_sesi_by_id(PDO $pdo, int $sesiId): ?array
{
    kedatangan_libur_ensure_schema($pdo);
    if ($sesiId <= 0) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT se.*, al.nama AS nama_libur, al.tanggal_mulai AS libur_mulai, al.tanggal_selesai AS libur_selesai
        FROM kedatangan_libur_sesi se
        LEFT JOIN akademik_libur al ON al.id = se.akademik_libur_id
        WHERE se.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $sesiId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function kedatangan_libur_sesi_terbaru(PDO $pdo, int $limit = 20): array
{
    kedatangan_libur_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));
    $sql = '
        SELECT se.*, al.nama AS nama_libur, al.tanggal_mulai AS libur_mulai, al.tanggal_selesai AS libur_selesai,
               (SELECT COUNT(*) FROM kedatangan_libur_scan sc WHERE sc.sesi_id = se.id) AS jumlah_datang
        FROM kedatangan_libur_sesi se
        LEFT JOIN akademik_libur al ON al.id = se.akademik_libur_id
        ORDER BY se.tanggal DESC, se.id DESC
        LIMIT ' . $limit;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool, message:string, sesi:?array}
 */
function kedatangan_libur_buka_sesi(PDO $pdo, int $liburId, string $tanggal, string $jamMulai, string $jamSelesai, int $createdBy): array
{
    kedatangan_libur_ensure_schema($pdo);
    ensure_akademik_libur_table($pdo);
    if ($liburId <= 0) {
        return ['ok' => false, 'message' => 'Pilih rentang libur akademik.', 'sesi' => null];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal sesi tidak valid.', 'sesi' => null];
    }
    $jamMulai = app_normalize_jam_hm($jamMulai);
    $jamSelesai = app_normalize_jam_hm($jamSelesai);
    if ($jamMulai === '' || $jamSelesai === '') {
        return ['ok' => false, 'message' => 'Jam mulai dan jam selesai wajib diisi.', 'sesi' => null];
    }

    $stLibur = $pdo->prepare('SELECT * FROM akademik_libur WHERE id = :id LIMIT 1');
    $stLibur->execute(['id' => $liburId]);
    $libur = $stLibur->fetch(PDO::FETCH_ASSOC);
    if (!is_array($libur)) {
        return ['ok' => false, 'message' => 'Rentang libur tidak ditemukan.', 'sesi' => null];
    }

    $nama = 'Kedatangan ' . trim((string) ($libur['nama'] ?? 'libur'));
    $find = $pdo->prepare('SELECT id FROM kedatangan_libur_sesi WHERE akademik_libur_id = :lid AND tanggal = :tgl LIMIT 1');
    $find->execute(['lid' => $liburId, 'tgl' => $tanggal]);
    $existingId = (int) ($find->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $upd = $pdo->prepare('UPDATE kedatangan_libur_sesi SET jam_mulai = :jm, jam_selesai = :js, nama = :nama WHERE id = :id LIMIT 1');
        $upd->execute(['jm' => $jamMulai . ':00', 'js' => $jamSelesai . ':00', 'nama' => $nama, 'id' => $existingId]);
        $sesi = kedatangan_libur_sesi_by_id($pdo, $existingId);

        return ['ok' => true, 'message' => 'Sesi kedatangan sudah ada — jam diperbarui.', 'sesi' => $sesi];
    }

    $ins = $pdo->prepare('
        INSERT INTO kedatangan_libur_sesi (akademik_libur_id, nama, tanggal, jam_mulai, jam_selesai, created_by)
        VALUES (:lid, :nama, :tgl, :jm, :js, :uid)
    ');
    $ins->execute([
        'lid' => $liburId,
        'nama' => $nama,
        'tgl' => $tanggal,
        'jm' => $jamMulai . ':00',
        'js' => $jamSelesai . ':00',
        'uid' => $createdBy > 0 ? $createdBy : null,
    ]);
    $sesi = kedatangan_libur_sesi_by_id($pdo, (int) $pdo->lastInsertId());

    return ['ok' => true, 'message' => 'Sesi kedatangan dibuka.', 'sesi' => $sesi];
}

/**
 * @return array{ok:bool, message:string, sesi:?array}
 */
function kedatangan_libur_ubah_jam(PDO $pdo, int $sesiId, string $jamMulai, string $jamSelesai): array
{
    kedatangan_libur_ensure_schema($pdo);
    $jamMulai = app_normalize_jam_hm($jamMulai);
    $jamSelesai = app_normalize_jam_hm($jamSelesai);
    if ($sesiId <= 0 || $jamMulai === '' || $jamSelesai === '') {
        return ['ok' => false, 'message' => 'Jam mulai dan jam selesai wajib diisi.', 'sesi' => null];
    }
    $upd = $pdo->prepare('UPDATE kedatangan_libur_sesi SET jam_mulai = :jm, jam_selesai = :js WHERE id = :id LIMIT 1');
    $upd->execute(['jm' => $jamMulai . ':00', 'js' => $jamSelesai . ':00', 'id' => $sesiId]);
    $sesi = kedatangan_libur_sesi_by_id($pdo, $sesiId);
    if ($sesi === null) {
        return ['ok' => false, 'message' => 'Sesi tidak ditemukan.', 'sesi' => null];
    }

    return ['ok' => true, 'message' => 'Jam sesi diperbarui.', 'sesi' => $sesi];
}

/**
 * @return list<array<string, mixed>>
 */
function kedatangan_libur_daftar_datang(PDO $pdo, int $sesiId): array
{
    kedatangan_libur_ensure_schema($pdo);
    $namaSql = kedatangan_libur_santri_nama_sql($pdo, 's');
    $st = $pdo->prepare('
        SELECT sc.id, sc.santri_id, sc.jam, sc.created_at,
               ' . $namaSql . ' AS nama_santri, s.nis, s.tingkatan, s.jenis_kelamin
        FROM kedatangan_libur_scan sc
        INNER JOIN santri s ON s.id = sc.santri_id
        WHERE sc.sesi_id = :sid
        ORDER BY sc.jam ASC, ' . $namaSql . ' ASC
    ');
    $st->execute(['sid' => $sesiId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Santri aktif yang belum ada baris scan di sesi ini.
 *
 * @return list<array<string, mixed>>
 */
function kedatangan_libur_daftar_belum(PDO $pdo, int $sesiId): array
{
    kedatangan_libur_ensure_schema($pdo);
    $namaSql = kedatangan_libur_santri_nama_sql($pdo, 's');
    $st = $pdo->prepare('
        SELECT s.id AS santri_id, ' . $namaSql . ' AS nama_santri, s.nis, s.tingkatan, s.jenis_kelamin
        FROM santri s
        WHERE ' . santri_sql_aktif_only('s') . '
          AND s.id NOT IN (SELECT sc.santri_id FROM kedatangan_libur_scan sc WHERE sc.sesi_id = :sid)
        ORDER BY s.jenis_kelamin ASC, ' . $namaSql . ' ASC
    ');
    $st->execute(['sid' => $sesiId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function kedatangan_libur_filter_jk(array $rows, string $gender): array
{
    $wantPutri = $gender === 'putri';
    $out = [];
    foreach ($rows as $row) {
        $isPutri = kedatangan_libur_is_putri((string) ($row['jenis_kelamin'] ?? ''));
        if ($wantPutri === $isPutri) {
            $out[] = $row;
        }
    }

    return $out;
}

function kedatangan_libur_format_baris_datang(array $row, array $sesi = []): string
{
    $nama = trim((string) ($row['nama_santri'] ?? '-'));
    $nis = trim((string) ($row['nis'] ?? ''));
    $jam = app_format_jam((string) ($row['jam'] ?? ''));
    $label = $nis !== '' ? $nama . ' (' . $nis . ')' : $nama;
    $line = $label . ' — ' . $jam;
    $telat = kedatangan_libur_menit_telat(
        (string) ($row['jam'] ?? ''),
        (string) ($sesi['jam_mulai'] ?? ''),
        (string) ($sesi['jam_selesai'] ?? '')
    );
    if ($telat > 0) {
        $line .= ' · telat ' . kedatangan_libur_format_durasi_menit($telat);
    }

    return $line;
}

function kedatangan_libur_format_baris_belum(array $row): string
{
    $nama = trim((string) ($row['nama_santri'] ?? '-'));
    $nis = trim((string) ($row['nis'] ?? ''));
    $tingkat = trim((string) ($row['tingkatan'] ?? ''));
    $label = $nis !== '' ? $nama . ' (' . $nis . ')' : $nama;
    if ($tingkat !== '') {
        $label .= ' · ' . $tingkat;
    }

    return $label;
}

function kedatangan_libur_keterangan_scan(array $row, array $sesi): string
{
    $jamScan = (string) ($row['jam'] ?? '');
    $telatMenit = kedatangan_libur_menit_telat(
        $jamScan,
        (string) ($sesi['jam_mulai'] ?? ''),
        (string) ($sesi['jam_selesai'] ?? '')
    );
    if ($telatMenit > 0) {
        return 'Telat ' . kedatangan_libur_format_durasi_menit($telatMenit);
    }
    if (!kedatangan_libur_jam_dalam_jendela($jamScan, (string) ($sesi['jam_mulai'] ?? ''), (string) ($sesi['jam_selesai'] ?? ''))) {
        return 'Luar jam';
    }

    return '';
}

/** CSV rekap satu sesi (UTF-8 BOM): sudah datang + belum datang. */
function kedatangan_libur_export_csv(PDO $pdo, int $sesiId): string
{
    $sesi = kedatangan_libur_sesi_by_id($pdo, $sesiId);
    if ($sesi === null) {
        return '';
    }
    $datang = kedatangan_libur_daftar_datang($pdo, $sesiId);
    $belum = kedatangan_libur_daftar_belum($pdo, $sesiId);
    $out = fopen('php://temp', 'r+');
    if ($out === false) {
        return '';
    }
    $namaLibur = trim((string) ($sesi['nama_libur'] ?? $sesi['nama'] ?? 'Sesi'));
    fputcsv($out, ['Libur', $namaLibur]);
    fputcsv($out, ['Tanggal', app_format_tanggal_id((string) ($sesi['tanggal'] ?? ''))]);
    fputcsv($out, ['Jam', app_format_jam_rentang((string) ($sesi['jam_mulai'] ?? ''), (string) ($sesi['jam_selesai'] ?? ''))]);
    fputcsv($out, []);
    fputcsv($out, ['Status', 'NIS', 'Nama', 'Tingkatan', 'Kelompok', 'Jam', 'Keterangan']);
    foreach ($datang as $row) {
        fputcsv($out, [
            'Datang',
            (string) ($row['nis'] ?? ''),
            (string) ($row['nama_santri'] ?? ''),
            (string) ($row['tingkatan'] ?? ''),
            kedatangan_libur_is_putri((string) ($row['jenis_kelamin'] ?? '')) ? 'Putri' : 'Putra',
            app_format_jam((string) ($row['jam'] ?? '')),
            kedatangan_libur_keterangan_scan($row, $sesi),
        ]);
    }
    foreach ($belum as $row) {
        fputcsv($out, [
            'Belum',
            (string) ($row['nis'] ?? ''),
            (string) ($row['nama_santri'] ?? ''),
            (string) ($row['tingkatan'] ?? ''),
            kedatangan_libur_is_putri((string) ($row['jenis_kelamin'] ?? '')) ? 'Putri' : 'Putra',
            '',
            '',
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    return "\xEF\xBB\xBF" . ($csv !== false ? $csv : '');
}

/** URL foto profil santri untuk overlay scan (kosong jika tidak ada). */
function kedatangan_libur_santri_foto_url(?array $santri): string
{
    if (!is_array($santri)) {
        return '';
    }
    require_once __DIR__ . '/santri_foto.php';
    $rel = trim((string) ($santri['foto_profil'] ?? ''));

    return $rel !== '' ? santri_foto_url($rel) : '';
}

function kedatangan_libur_santri_nama(?array $santri): string
{
    if (!is_array($santri)) {
        return '';
    }

    return trim((string) ($santri['nama_santri'] ?? $santri['nama'] ?? ''));
}

/**
 * @return array{ok:bool, type:string, message:string, santri:?array, luar_jam:bool, duplicate:bool}
 */
function kedatangan_libur_catat_scan(PDO $pdo, int $sesiId, string $code, int $createdBy = 0): array
{
    kedatangan_libur_ensure_schema($pdo);
    $empty = ['ok' => false, 'type' => 'warning', 'message' => '', 'santri' => null, 'luar_jam' => false, 'duplicate' => false];
    $code = trim($code);
    if ($sesiId <= 0) {
        $empty['message'] = 'Sesi kedatangan belum dipilih. Buka Absen kedatangan dulu.';

        return $empty;
    }
    if ($code === '') {
        $empty['message'] = 'Kode QR kosong.';

        return $empty;
    }

    $sesi = kedatangan_libur_sesi_by_id($pdo, $sesiId);
    if ($sesi === null) {
        $empty['message'] = 'Sesi kedatangan tidak ditemukan.';

        return $empty;
    }

    require_once __DIR__ . '/santri_kartu_sementara.php';
    $santri = santri_resolve_by_scan_code($pdo, $code);
    if (!is_array($santri)) {
        $empty['message'] = 'Peringatan: kode QR tidak terdaftar sebagai santri.';

        return $empty;
    }

    $santriId = (int) ($santri['id'] ?? 0);
    $chkAktif = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
    $chkAktif->execute(['id' => $santriId]);
    if (!$chkAktif->fetchColumn()) {
        $empty['message'] = 'Santri tidak aktif — kedatangan tidak dicatat.';
        $empty['santri'] = $santri;

        return $empty;
    }

    $jam = date('H:i:s');
    $luarJam = !kedatangan_libur_jam_dalam_jendela($jam, (string) $sesi['jam_mulai'], (string) $sesi['jam_selesai']);

    $cek = $pdo->prepare('SELECT jam FROM kedatangan_libur_scan WHERE sesi_id = :sid AND santri_id = :nid LIMIT 1');
    $cek->execute(['sid' => $sesiId, 'nid' => $santriId]);
    $sudah = $cek->fetchColumn();
    if ($sudah !== false && $sudah !== null) {
        $nama = trim((string) ($santri['nama_santri'] ?? $santri['nama'] ?? 'Santri'));

        return [
            'ok' => false,
            'type' => 'duplicate',
            'message' => $nama . ' sudah dicatat datang pukul ' . app_format_jam((string) $sudah) . '.',
            'santri' => $santri,
            'luar_jam' => $luarJam,
            'duplicate' => true,
        ];
    }

    $ins = $pdo->prepare('INSERT INTO kedatangan_libur_scan (sesi_id, santri_id, jam) VALUES (:sid, :nid, :jam)');
    try {
        $ins->execute(['sid' => $sesiId, 'nid' => $santriId, 'jam' => $jam]);
    } catch (PDOException $e) {
        $nama = trim((string) ($santri['nama_santri'] ?? $santri['nama'] ?? 'Santri'));

        return [
            'ok' => false,
            'type' => 'duplicate',
            'message' => $nama . ' sudah dicatat datang.',
            'santri' => $santri,
            'luar_jam' => $luarJam,
            'duplicate' => true,
        ];
    }

    $nama = trim((string) ($santri['nama_santri'] ?? $santri['nama'] ?? 'Santri'));
    $msg = $nama . ' dicatat datang pukul ' . app_format_jam($jam) . '.';
    if ($luarJam) {
        $msg .= ' Di luar jam ' . app_format_jam_rentang((string) $sesi['jam_mulai'], (string) $sesi['jam_selesai']) . '.';
    }

    $waNote = kedatangan_libur_kirim_wa_wali($pdo, $sesi, $santri, $jam);
    if ($waNote !== '') {
        $msg .= ' ' . $waNote;
    }

    return [
        'ok' => true,
        'type' => 'success',
        'message' => $msg,
        'santri' => $santri,
        'luar_jam' => $luarJam,
        'duplicate' => false,
    ];
}

/**
 * @param array<string, mixed> $sesi
 * @param array<string, mixed> $santri
 */
function kedatangan_libur_kirim_wa_wali(PDO $pdo, array $sesi, array $santri, string $jam): string
{
    if (!kedatangan_libur_wali_enabled($pdo)) {
        return '';
    }
    require_once __DIR__ . '/wa_otomatis.php';
    require_once __DIR__ . '/wa_templates.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return '';
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 'WA wali dilewati (gateway).';
    }

    $phone = wa_otomatis_santri_wali_phone($pdo, $santri);
    if ($phone === '') {
        return 'Nomor wali kosong.';
    }

    $santriId = (int) ($santri['id'] ?? 0);
    $sesiId = (int) ($sesi['id'] ?? 0);
    $msg = wa_template_render($pdo, 'kedatangan_libur_wali', [
        'nama_santri' => trim((string) ($santri['nama_santri'] ?? $santri['nama'] ?? '-')),
        'nis' => trim((string) ($santri['nis'] ?? '-')),
        'tingkatan' => trim((string) ($santri['tingkatan'] ?? '-')),
        'nama_libur' => trim((string) ($sesi['nama_libur'] ?? $sesi['nama'] ?? '-')),
        'tanggal' => kedatangan_libur_format_tanggal_hari((string) ($sesi['tanggal'] ?? '')),
        'jam' => app_format_jam($jam),
        'nama_ponpes' => app_brand_nama_ponpes($pdo),
    ]);
    $res = wa_otomatis_send($pdo, $phone, $msg, [
        'kind' => 'general',
        'dedup_key' => 'kedatangan_wali:' . $sesiId . ':' . $santriId,
    ]);
    if (!empty($res['skipped'])) {
        return '';
    }
    if (!empty($res['success'])) {
        return 'WA wali terkirim.';
    }

    $err = trim((string) ($res['error'] ?? ''));

    return $err !== '' ? ('WA wali gagal: ' . $err) : 'WA wali gagal.';
}

function kedatangan_libur_pengurus_targets(PDO $pdo, string $gender): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $peran = $gender === 'putri' ? 'kedatangan_putri' : 'kedatangan_putra';
    $raw = trim(wa_nomor_targets($pdo, $peran));
    if ($raw !== '') {
        return $raw;
    }
    $setting = $gender === 'putri' ? 'wa_kedatangan_pengurus_putri' : 'wa_kedatangan_pengurus_putra';
    $raw = trim((string) app_setting($pdo, $setting, ''));
    if ($raw !== '') {
        return $raw;
    }
    $fallbackPeran = $gender === 'putri' ? 'izin_putri' : 'izin_putra';
    $raw = trim(wa_nomor_targets($pdo, $fallbackPeran));
    if ($raw !== '') {
        return $raw;
    }
    $fallbackSetting = $gender === 'putri' ? 'wa_izin_pengurus_putri' : 'wa_izin_pengurus_putra';

    return trim((string) app_setting($pdo, $fallbackSetting, ''));
}

/**
 * @return array{ok:bool, message:string}
 */
function kedatangan_libur_kirim_laporan_pengurus(PDO $pdo, int $sesiId, string $jenis): array
{
    kedatangan_libur_ensure_schema($pdo);
    require_once __DIR__ . '/wa_otomatis.php';
    require_once __DIR__ . '/wa_templates.php';

    $jenis = $jenis === 'belum' ? 'belum' : 'datang';
    $sesi = kedatangan_libur_sesi_by_id($pdo, $sesiId);
    if ($sesi === null) {
        return ['ok' => false, 'message' => 'Sesi tidak ditemukan.'];
    }
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['ok' => false, 'message' => 'WA otomatis sedang nonaktif.'];
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return ['ok' => false, 'message' => 'Gateway WA belum siap.'];
    }

    $datangAll = kedatangan_libur_daftar_datang($pdo, $sesiId);
    $belumAll = kedatangan_libur_daftar_belum($pdo, $sesiId);
    $slug = $jenis === 'belum' ? 'kedatangan_libur_belum_pengurus' : 'kedatangan_libur_pengurus';
    $baseVars = [
        'nama_libur' => trim((string) ($sesi['nama_libur'] ?? $sesi['nama'] ?? '-')),
        'tanggal' => app_format_tanggal_id((string) ($sesi['tanggal'] ?? '')),
        'jam_mulai' => app_format_jam((string) ($sesi['jam_mulai'] ?? '')),
        'jam_selesai' => app_format_jam((string) ($sesi['jam_selesai'] ?? '')),
        'nama_ponpes' => app_brand_nama_ponpes($pdo),
    ];

    $parts = [];
    $sent = 0;
    $failed = 0;
    foreach (['putra', 'putri'] as $gender) {
        $targets = kedatangan_libur_pengurus_targets($pdo, $gender);
        if ($targets === '') {
            $parts[] = ucfirst($gender) . ': nomor pengurus kosong.';
            continue;
        }
        $rows = $jenis === 'belum'
            ? kedatangan_libur_filter_jk($belumAll, $gender)
            : kedatangan_libur_filter_jk($datangAll, $gender);
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $jenis === 'belum'
                ? kedatangan_libur_format_baris_belum($row)
                : kedatangan_libur_format_baris_datang($row, $sesi);
        }
        $daftar = $lines !== [] ? implode("\n", $lines) : '(tidak ada)';
        $vars = $baseVars;
        if ($jenis === 'belum') {
            $vars['jumlah_belum'] = (string) count($rows);
            $vars['daftar_belum'] = $daftar;
        } else {
            $vars['jumlah_datang'] = (string) count($rows);
            $vars['daftar_datang'] = $daftar;
        }
        $msg = wa_template_render($pdo, $slug, $vars);
        $res = wa_otomatis_send_bulk($pdo, $targets, $msg, [
            'kind' => 'general',
            'skip_dedup' => true,
        ]);
        $okN = (int) ($res['sent'] ?? 0);
        $failN = (int) ($res['failed'] ?? 0);
        $sent += $okN;
        $failed += $failN;
        $parts[] = ucfirst($gender) . ': ' . count($rows) . ' nama, terkirim ' . $okN
            . ($failN > 0 ? (', gagal ' . $failN) : '');
    }

    if ($sent === 0 && $failed === 0) {
        return ['ok' => false, 'message' => implode(' ', $parts)];
    }

    $ok = $sent > 0;
    $label = $jenis === 'belum' ? 'belum datang' : 'sudah datang';

    return [
        'ok' => $ok,
        'message' => 'Laporan ' . $label . ' — ' . implode(' · ', $parts),
    ];
}
