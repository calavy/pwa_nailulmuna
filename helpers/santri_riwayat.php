<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/santri_status.php';

/** @return array{mulai:int,selesai:int} */
function santri_tahun_ajaran_for_date(PDO $pdo, ?string $dateYmd = null): array
{
    return pondok_tahun_ajaran_from_date($pdo, $dateYmd);
}

function santri_tahun_ajaran_label(array $ta, ?PDO $pdo = null): string
{
    if ($pdo instanceof PDO) {
        return pondok_tahun_ajaran_label($pdo, $ta);
    }

    return (int) ($ta['mulai'] ?? 0) . '/' . (int) ($ta['selesai'] ?? 0);
}

function ensure_santri_riwayat_tables(PDO $pdo): void
{
    ensure_santri_identity_columns($pdo);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_riwayat_tingkatan (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tahun_ajaran_mulai SMALLINT NOT NULL,
            tahun_ajaran_selesai SMALLINT NOT NULL,
            tingkatan VARCHAR(80) NOT NULL,
            kategori_kelas VARCHAR(80) NULL,
            catatan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_santri_ta (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai),
            INDEX idx_srt_santri (santri_id),
            CONSTRAINT fk_srt_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_riwayat_hidmah (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            jenis_peran ENUM("HIDMAH","PENGURUS_SANTRI","PEMBANTU_USAHA") NOT NULL DEFAULT "HIDMAH",
            nama_hidmah VARCHAR(200) NOT NULL,
            tahun_ajaran_mulai SMALLINT NOT NULL,
            tahun_ajaran_selesai SMALLINT NULL,
            tanggal_mulai DATE NULL,
            tanggal_selesai DATE NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_srh_santri (santri_id, tahun_ajaran_mulai),
            CONSTRAINT fk_srh_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_riwayat_asrama (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            gedung VARCHAR(120) NOT NULL DEFAULT "Asrama",
            nama_kamar VARCHAR(120) NOT NULL,
            no_ranjang VARCHAR(80) NULL,
            tahun_ajaran_mulai SMALLINT NULL,
            tahun_ajaran_selesai SMALLINT NULL,
            catatan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sra_santri (santri_id, tahun_ajaran_mulai),
            CONSTRAINT fk_sra_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_riwayat_domisili (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            jenis_domisili ENUM("MENGAJI","KHIDMAH") NOT NULL DEFAULT "MENGAJI",
            gedung VARCHAR(120) NOT NULL DEFAULT "Asrama",
            nama_kamar VARCHAR(120) NOT NULL,
            no_ranjang VARCHAR(80) NULL,
            tahun_ajaran_mulai SMALLINT NULL,
            tahun_ajaran_selesai SMALLINT NULL,
            tanggal_mulai DATE NULL,
            tanggal_selesai DATE NULL,
            catatan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_srd_santri_jenis (santri_id, jenis_domisili, tahun_ajaran_mulai),
            CONSTRAINT fk_srd_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    santri_riwayat_ensure_tingkatan_columns($pdo);
}

function santri_riwayat_ensure_tingkatan_columns(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri_riwayat_tingkatan')) {
        return;
    }
    foreach ([
        'wali_kelas' => 'VARCHAR(120) NULL',
        'status_akademik' => "VARCHAR(30) NULL DEFAULT 'BERJALAN'",
    ] as $col => $def) {
        if (!column_exists($pdo, 'santri_riwayat_tingkatan', $col)) {
            try {
                $pdo->exec('ALTER TABLE santri_riwayat_tingkatan ADD COLUMN ' . $col . ' ' . $def);
            } catch (Throwable $e) {
                // abaikan duplikat
            }
        }
    }
}

/** @return list<string> */
function santri_riwayat_status_akademik_options(): array
{
    return ['BERJALAN', 'LULUS', 'TINGGAL_KELAS'];
}

function santri_riwayat_status_akademik_label(string $raw): string
{
    return match (strtoupper(trim($raw))) {
        'LULUS' => 'Lulus',
        'TINGGAL_KELAS' => 'Tinggal kelas',
        default => 'Berjalan',
    };
}

/** Label gedung asrama dari jenis kelamin santri. */
function santri_riwayat_gedung_label(array $santri): string
{
    $jk = strtoupper(trim((string) ($santri['jenis_kelamin'] ?? '')));
    if (in_array($jk, ['PUTRA', 'L', 'LAKI-LAKI', 'LAKI LAKI'], true)) {
        return 'Asrama Putra';
    }
    if (in_array($jk, ['PUTRI', 'P', 'PEREMPUAN'], true)) {
        return 'Asrama Putri';
    }

    return 'Asrama';
}

function santri_riwayat_kelas_tampilan(PDO $pdo, ?string $kategoriKelas): string
{
    $k = trim((string) $kategoriKelas);
    if ($k === '') {
        return '—';
    }
    if (function_exists('kelas_keuangan_label_for_kode')) {
        $lbl = kelas_keuangan_label_for_kode($pdo, $k);

        return $lbl !== '' ? $lbl : $k;
    }

    return $k;
}

/** @return list<array<string, mixed>> */
function santri_riwayat_asrama_list(PDO $pdo, int $santriId): array
{
    ensure_santri_riwayat_tables($pdo);
    if (!table_exists($pdo, 'santri_riwayat_asrama')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT * FROM santri_riwayat_asrama
        WHERE santri_id = :id
        ORDER BY COALESCE(tahun_ajaran_mulai, 0) DESC, id DESC
    ');
    $st->execute(['id' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<string> */
function santri_riwayat_domisili_jenis_options(): array
{
    return ['MENGAJI', 'KHIDMAH'];
}

function santri_riwayat_domisili_jenis_label(string $jenis): string
{
    return match (strtoupper(trim($jenis))) {
        'KHIDMAH' => 'Khidmah / Pengabdian',
        default => 'Mengaji (mondok)',
    };
}

/** @return list<array<string, mixed>> */
function santri_riwayat_domisili_list(PDO $pdo, int $santriId, ?string $jenis = null): array
{
    ensure_santri_riwayat_tables($pdo);
    if (!table_exists($pdo, 'santri_riwayat_domisili')) {
        return [];
    }
    $sql = 'SELECT * FROM santri_riwayat_domisili WHERE santri_id = :sid';
    $params = ['sid' => $santriId];
    if ($jenis !== null && $jenis !== '') {
        $sql .= ' AND jenis_domisili = :jenis';
        $params['jenis'] = strtoupper(trim($jenis));
    }
    $sql .= ' ORDER BY COALESCE(tahun_ajaran_mulai, 0) DESC, COALESCE(tanggal_mulai, created_at) DESC, id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function santri_riwayat_domisili_periode_label(array $row): string
{
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if ($tm > 0) {
        return $ts > 0 && $ts !== $tm + 1
            ? $tm . '/' . $ts
            : santri_tahun_ajaran_label(['mulai' => $tm, 'selesai' => $ts > 0 ? $ts : $tm + 1]);
    }
    $d1 = trim((string) ($row['tanggal_mulai'] ?? ''));
    $d2 = trim((string) ($row['tanggal_selesai'] ?? ''));
    if ($d1 !== '' && $d2 !== '') {
        return $d1 . ' — ' . $d2;
    }
    if ($d1 !== '') {
        return 'Sejak ' . $d1;
    }

    return '—';
}

function santri_riwayat_domisili_save(PDO $pdo, array $post, int $santriId, ?int $editId = null): bool
{
    ensure_santri_riwayat_tables($pdo);
    $jenis = strtoupper(trim((string) ($post['jenis_domisili'] ?? 'MENGAJI')));
    if (!in_array($jenis, santri_riwayat_domisili_jenis_options(), true)) {
        $jenis = 'MENGAJI';
    }
    $gedung = trim((string) ($post['gedung'] ?? ''));
    $kamar = trim((string) ($post['nama_kamar'] ?? ''));
    if ($kamar === '') {
        return false;
    }
    $tm = (int) ($post['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($post['tahun_ajaran_selesai'] ?? 0);
    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? ''));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? ''));
    $params = [
        'sid' => $santriId,
        'jenis' => $jenis,
        'gedung' => $gedung !== '' ? mb_substr($gedung, 0, 120) : 'Asrama',
        'kamar' => mb_substr($kamar, 0, 120),
        'ranjang' => trim((string) ($post['no_ranjang'] ?? '')) ?: null,
        'tm' => $tm > 0 ? $tm : null,
        'ts' => $ts > 0 ? $ts : null,
        'tgl1' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai) ? $tglMulai : null,
        'tgl2' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai) ? $tglSelesai : null,
        'cat' => trim((string) ($post['catatan'] ?? '')) ?: null,
    ];
    if ($editId !== null && $editId > 0) {
        $params['id'] = $editId;
        $pdo->prepare('
            UPDATE santri_riwayat_domisili SET
                jenis_domisili = :jenis, gedung = :gedung, nama_kamar = :kamar, no_ranjang = :ranjang,
                tahun_ajaran_mulai = :tm, tahun_ajaran_selesai = :ts,
                tanggal_mulai = :tgl1, tanggal_selesai = :tgl2, catatan = :cat
            WHERE id = :id AND santri_id = :sid
        ')->execute($params);

        return true;
    }
    $pdo->prepare('
        INSERT INTO santri_riwayat_domisili (
            santri_id, jenis_domisili, gedung, nama_kamar, no_ranjang,
            tahun_ajaran_mulai, tahun_ajaran_selesai, tanggal_mulai, tanggal_selesai, catatan
        ) VALUES (
            :sid, :jenis, :gedung, :kamar, :ranjang, :tm, :ts, :tgl1, :tgl2, :cat
        )
    ')->execute($params);

    return true;
}

/** Salin riwayat asrama ke domisili mengaji (sekali). */
function santri_riwayat_domisili_backfill_from_asrama(PDO $pdo, int $santriId): void
{
    ensure_santri_riwayat_tables($pdo);
    if (!table_exists($pdo, 'santri_riwayat_domisili') || !table_exists($pdo, 'santri_riwayat_asrama')) {
        return;
    }
    if (santri_riwayat_domisili_list($pdo, $santriId, 'MENGAJI') !== []) {
        return;
    }
    foreach (santri_riwayat_asrama_list($pdo, $santriId) as $ar) {
        $pdo->prepare('
            INSERT INTO santri_riwayat_domisili (
                santri_id, jenis_domisili, gedung, nama_kamar, no_ranjang,
                tahun_ajaran_mulai, tahun_ajaran_selesai, catatan
            ) VALUES (:sid, \'MENGAJI\', :gedung, :kamar, :ranjang, :tm, :ts, :cat)
        ')->execute([
            'sid' => $santriId,
            'gedung' => (string) ($ar['gedung'] ?? 'Asrama'),
            'kamar' => (string) ($ar['nama_kamar'] ?? ''),
            'ranjang' => trim((string) ($ar['no_ranjang'] ?? '')) ?: null,
            'tm' => !empty($ar['tahun_ajaran_mulai']) ? (int) $ar['tahun_ajaran_mulai'] : null,
            'ts' => !empty($ar['tahun_ajaran_selesai']) ? (int) $ar['tahun_ajaran_selesai'] : null,
            'cat' => trim((string) ($ar['catatan'] ?? '')) ?: 'Disalin dari riwayat asrama',
        ]);
    }
}

function santri_riwayat_domisili_ensure_for_santri(PDO $pdo, int $santriId, array $santriRow): void
{
    santri_riwayat_domisili_backfill_from_asrama($pdo, $santriId);
    if (santri_status_from_row($santriRow) === santri_status_const_khidmah()) {
        $khidmahDom = santri_riwayat_domisili_list($pdo, $santriId, 'KHIDMAH');
        if ($khidmahDom === []) {
            $kamar = trim((string) ($santriRow['nama_kamar'] ?? ''));
            if ($kamar !== '') {
                $ta = santri_tahun_ajaran_for_date($pdo);
                santri_riwayat_domisili_save($pdo, [
                    'jenis_domisili' => 'KHIDMAH',
                    'gedung' => santri_riwayat_gedung_label($santriRow),
                    'nama_kamar' => $kamar,
                    'no_ranjang' => (string) ($santriRow['no_ranjang'] ?? ''),
                    'tahun_ajaran_mulai' => (string) $ta['mulai'],
                    'catatan' => 'Domisili khidmah dari data santri saat ini',
                ], $santriId);
            }
        }
    }
}

function santri_riwayat_asrama_periode_label(array $row): string
{
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if ($tm > 0 && $ts > 0) {
        return $tm . ' — ' . $ts;
    }
    if ($tm > 0) {
        return (string) $tm . ' — sekarang';
    }

    return '—';
}

function santri_riwayat_hidmah_periode_label(array $row): string
{
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if (!empty($row['tanggal_mulai'])) {
        $p = (string) $row['tanggal_mulai'];
        if (!empty($row['tanggal_selesai'])) {
            $p .= ' — ' . $row['tanggal_selesai'];
        }

        return $p;
    }
    if ($tm > 0) {
        return $ts > 0 ? $tm . '/' . $ts : $tm . '/' . ($tm + 1);
    }

    return '—';
}

/** @param list<array<string, mixed>> $rows */
function santri_riwayat_filter_ta_mulai(array $rows, int $taMulai): array
{
    if ($taMulai <= 0) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $r) use ($taMulai): bool {
        $m = (int) ($r['tahun_ajaran_mulai'] ?? 0);
        if ($m === $taMulai) {
            return true;
        }
        $ts = (int) ($r['tahun_ajaran_selesai'] ?? 0);
        if ($m > 0 && $ts > 0 && $taMulai >= $m && $taMulai <= $ts) {
            return true;
        }

        return false;
    }));
}

/**
 * Daftar tahun ajaran mulai yang muncul di riwayat (untuk filter).
 *
 * @return list<int>
 */
function santri_riwayat_tahun_filter_options(PDO $pdo, int $santriId): array
{
    $years = [];
    foreach (santri_riwayat_tingkatan_list($pdo, $santriId) as $r) {
        $y = (int) ($r['tahun_ajaran_mulai'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    foreach (santri_riwayat_hidmah_list($pdo, $santriId) as $r) {
        $y = (int) ($r['tahun_ajaran_mulai'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    foreach (santri_riwayat_asrama_list($pdo, $santriId) as $r) {
        $y = (int) ($r['tahun_ajaran_mulai'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    foreach (santri_riwayat_domisili_list($pdo, $santriId) as $r) {
        $y = (int) ($r['tahun_ajaran_mulai'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    foreach (santri_riwayat_keaktifan_per_tahun($pdo, $santriId) as $ka) {
        $y = (int) ($ka['th'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    foreach (santri_riwayat_pelanggaran_per_tahun($pdo, $santriId) as $pt) {
        $y = (int) ($pt['th'] ?? 0);
        if ($y > 0) {
            $years[$y] = true;
        }
    }
    krsort($years);

    return array_map('intval', array_keys($years));
}

/**
 * Filter baris ber-index tahun kalender agar selaras filter TA (mulai TA = th dan th+1).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function santri_riwayat_filter_tahun_kalender_ta(array $rows, int $taMulai, string $yearKey = 'th'): array
{
    if ($taMulai <= 0) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $r) use ($taMulai, $yearKey): bool {
        $y = (int) ($r[$yearKey] ?? 0);

        return $y === $taMulai || $y === $taMulai + 1;
    }));
}

/** Daftar pelanggaran untuk buku induk (filter TA = tahun kalender TA mulai & tahun berikutnya). */
function santri_riwayat_pelanggaran_list_buku(PDO $pdo, int $santriId, int $filterTa): array
{
    if ($filterTa <= 0) {
        return santri_riwayat_pelanggaran_list($pdo, $santriId, null);
    }
    $merged = [];
    foreach ([$filterTa, $filterTa + 1] as $y) {
        foreach (santri_riwayat_pelanggaran_list($pdo, $santriId, $y) as $row) {
            $merged[(int) ($row['id'] ?? 0)] = $row;
        }
    }
    $out = array_values($merged);
    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['tanggal'] ?? ''), (string) ($a['tanggal'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    return $out;
}

function santri_riwayat_asrama_save(PDO $pdo, array $post, int $santriId, ?int $editId = null): bool
{
    ensure_santri_riwayat_tables($pdo);
    $gedung = trim((string) ($post['gedung'] ?? ''));
    $kamar = trim((string) ($post['nama_kamar'] ?? ''));
    if ($kamar === '') {
        return false;
    }
    $tm = (int) ($post['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($post['tahun_ajaran_selesai'] ?? 0);
    $params = [
        'sid' => $santriId,
        'gedung' => $gedung !== '' ? mb_substr($gedung, 0, 120) : 'Asrama',
        'kamar' => mb_substr($kamar, 0, 120),
        'ranjang' => trim((string) ($post['no_ranjang'] ?? '')) ?: null,
        'tm' => $tm > 0 ? $tm : null,
        'ts' => $ts > 0 ? $ts : null,
        'cat' => trim((string) ($post['catatan'] ?? '')) ?: null,
    ];
    if ($editId !== null && $editId > 0) {
        $params['id'] = $editId;
        $pdo->prepare('
            UPDATE santri_riwayat_asrama SET
                gedung = :gedung, nama_kamar = :kamar, no_ranjang = :ranjang,
                tahun_ajaran_mulai = :tm, tahun_ajaran_selesai = :ts, catatan = :cat
            WHERE id = :id AND santri_id = :sid
        ')->execute($params);

        return true;
    }
    $pdo->prepare('
        INSERT INTO santri_riwayat_asrama (santri_id, gedung, nama_kamar, no_ranjang, tahun_ajaran_mulai, tahun_ajaran_selesai, catatan)
        VALUES (:sid, :gedung, :kamar, :ranjang, :tm, :ts, :cat)
    ')->execute($params);
    santri_riwayat_domisili_save($pdo, array_merge($post, ['jenis_domisili' => 'MENGAJI']), $santriId);

    return true;
}

/** Catat penempatan asrama saat kamar/ranjang santri berubah. */
function santri_riwayat_snapshot_asrama_from_santri(PDO $pdo, int $santriId, array $santriRow): void
{
    if ($santriId <= 0 || !santri_status_is_di_pondok(santri_status_from_row($santriRow))) {
        return;
    }
    ensure_santri_riwayat_tables($pdo);
    $kamar = trim((string) ($santriRow['nama_kamar'] ?? ''));
    $ranjang = trim((string) ($santriRow['no_ranjang'] ?? ''));
    if ($kamar === '') {
        return;
    }
    $gedung = santri_riwayat_gedung_label($santriRow);
    $ta = santri_tahun_ajaran_for_date($pdo);
    $list = santri_riwayat_asrama_list($pdo, $santriId);
    if ($list !== []) {
        $last = $list[0];
        if (
            strcasecmp((string) ($last['gedung'] ?? ''), $gedung) === 0
            && strcasecmp((string) ($last['nama_kamar'] ?? ''), $kamar) === 0
            && strcasecmp(trim((string) ($last['no_ranjang'] ?? '')), $ranjang) === 0
            && (int) ($last['tahun_ajaran_mulai'] ?? 0) === (int) $ta['mulai']
        ) {
            return;
        }
    }
    $pdo->prepare('
        INSERT INTO santri_riwayat_asrama (santri_id, gedung, nama_kamar, no_ranjang, tahun_ajaran_mulai, tahun_ajaran_selesai, catatan)
        VALUES (:sid, :gedung, :kamar, :ranjang, :tm, :ts, :cat)
    ')->execute([
        'sid' => $santriId,
        'gedung' => $gedung,
        'kamar' => mb_substr($kamar, 0, 120),
        'ranjang' => $ranjang !== '' ? mb_substr($ranjang, 0, 80) : null,
        'tm' => $ta['mulai'],
        'ts' => null,
        'cat' => 'Otomatis dari data santri',
    ]);
    santri_riwayat_domisili_save($pdo, [
        'jenis_domisili' => 'MENGAJI',
        'gedung' => $gedung,
        'nama_kamar' => mb_substr($kamar, 0, 120),
        'no_ranjang' => $ranjang,
        'tahun_ajaran_mulai' => (string) $ta['mulai'],
        'catatan' => 'Otomatis dari data santri',
    ], $santriId);
}

/** Isi satu baris penempatan saat ini jika belum ada arsip asrama. */
function santri_riwayat_backfill_asrama_from_santri(PDO $pdo, int $santriId, array $santriRow): void
{
    if (santri_riwayat_asrama_list($pdo, $santriId) !== []) {
        return;
    }
    santri_riwayat_snapshot_asrama_from_santri($pdo, $santriId, $santriRow);
}

/** Label jenis peran hidmah untuk UI. */
function santri_hidmah_jenis_label(string $jenis): string
{
    return match (strtoupper(trim($jenis))) {
        'PENGURUS_SANTRI' => 'Pengurus santri',
        'PEMBANTU_USAHA' => 'Pembantu usaha pondok',
        default => 'Hidmah',
    };
}

/** @return list<string> */
function santri_hidmah_jenis_options(): array
{
    return ['HIDMAH', 'PENGURUS_SANTRI', 'PEMBANTU_USAHA'];
}

/**
 * Simpan / perbarui baris tingkatan untuk tahun ajaran saat ini atau tanggal tertentu.
 */
function santri_riwayat_upsert_tingkatan(
    PDO $pdo,
    int $santriId,
    string $tingkatan,
    ?string $kategoriKelas = null,
    ?string $tanggalRef = null,
    ?string $catatan = null
): void {
    if ($santriId <= 0 || trim($tingkatan) === '') {
        return;
    }
    ensure_santri_riwayat_tables($pdo);
    $ta = santri_tahun_ajaran_for_date($pdo, $tanggalRef);
    $pdo->prepare('
        INSERT INTO santri_riwayat_tingkatan (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, tingkatan, kategori_kelas, catatan)
        VALUES (:sid, :tm, :ts, :ting, :kat, :cat)
        ON DUPLICATE KEY UPDATE
            tingkatan = VALUES(tingkatan),
            kategori_kelas = VALUES(kategori_kelas),
            catatan = COALESCE(VALUES(catatan), catatan)
    ')->execute([
        'sid' => $santriId,
        'tm' => $ta['mulai'],
        'ts' => $ta['selesai'],
        'ting' => mb_substr(trim($tingkatan), 0, 80),
        'kat' => $kategoriKelas !== null && trim($kategoriKelas) !== '' ? mb_substr(trim($kategoriKelas), 0, 80) : null,
        'cat' => $catatan !== null && trim($catatan) !== '' ? mb_substr(trim($catatan), 0, 255) : null,
    ]);
}

/** Isi baris tahun ajaran masuk + tahun berjalan dari data santri saat ini (sekali jalan). */
function santri_riwayat_backfill_from_santri(PDO $pdo, ?int $santriId = null): int
{
    ensure_santri_riwayat_tables($pdo);
    $sql = 'SELECT id, tingkatan, kategori_kelas, tanggal_masuk, status_santri, is_aktif FROM santri';
    $params = [];
    if ($santriId !== null && $santriId > 0) {
        $sql .= ' WHERE id = :id';
        $params['id'] = $santriId;
    }
    $rows = $pdo->prepare($sql);
    $rows->execute($params);
    $count = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $ting = trim((string) ($s['tingkatan'] ?? ''));
        if ($ting === '') {
            continue;
        }
        $tglMasuk = trim((string) ($s['tanggal_masuk'] ?? ''));
        if ($tglMasuk !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMasuk)) {
            santri_riwayat_upsert_tingkatan(
                $pdo,
                (int) $s['id'],
                $ting,
                trim((string) ($s['kategori_kelas'] ?? '')) ?: null,
                $tglMasuk,
                'Tahun masuk pondok'
            );
            $count++;
        }
        santri_riwayat_upsert_tingkatan(
            $pdo,
            (int) $s['id'],
            $ting,
            trim((string) ($s['kategori_kelas'] ?? '')) ?: null,
            null,
            'Data terkini'
        );
        $count++;
    }

    return $count;
}

/** @return list<array<string, mixed>> */
function santri_riwayat_tingkatan_list(PDO $pdo, int $santriId): array
{
    ensure_santri_riwayat_tables($pdo);
    $st = $pdo->prepare('
        SELECT * FROM santri_riwayat_tingkatan
        WHERE santri_id = :id
        ORDER BY tahun_ajaran_mulai ASC, id ASC
    ');
    $st->execute(['id' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string, mixed>> */
function santri_riwayat_hidmah_list(PDO $pdo, int $santriId): array
{
    ensure_santri_riwayat_tables($pdo);
    $st = $pdo->prepare('
        SELECT * FROM santri_riwayat_hidmah
        WHERE santri_id = :id
        ORDER BY tahun_ajaran_mulai DESC, tanggal_mulai DESC, id DESC
    ');
    $st->execute(['id' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function santri_riwayat_hidmah_save(PDO $pdo, array $post, int $santriId, ?int $editId = null): bool
{
    ensure_santri_riwayat_tables($pdo);
    $jenis = strtoupper(trim((string) ($post['jenis_peran'] ?? 'HIDMAH')));
    if (!in_array($jenis, santri_hidmah_jenis_options(), true)) {
        $jenis = 'HIDMAH';
    }
    $nama = trim((string) ($post['nama_hidmah'] ?? ''));
    if ($nama === '') {
        return false;
    }
    $tm = (int) ($post['tahun_ajaran_mulai'] ?? 0);
    if ($tm < 2000 || $tm > 2100) {
        $ta = santri_tahun_ajaran_for_date($pdo);
        $tm = $ta['mulai'];
    }
    $ts = (int) ($post['tahun_ajaran_selesai'] ?? 0);
    $ts = $ts >= 2000 ? $ts : null;
    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? ''));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? ''));
    $params = [
        'sid' => $santriId,
        'jenis' => $jenis,
        'nama' => mb_substr($nama, 0, 200),
        'tm' => $tm,
        'ts' => $ts,
        'tgl1' => $tglMulai !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai) ? $tglMulai : null,
        'tgl2' => $tglSelesai !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai) ? $tglSelesai : null,
        'ket' => trim((string) ($post['keterangan'] ?? '')) ?: null,
    ];
    if ($editId !== null && $editId > 0) {
        $params['id'] = $editId;
        $pdo->prepare('
            UPDATE santri_riwayat_hidmah SET
                jenis_peran = :jenis, nama_hidmah = :nama,
                tahun_ajaran_mulai = :tm, tahun_ajaran_selesai = :ts,
                tanggal_mulai = :tgl1, tanggal_selesai = :tgl2, keterangan = :ket
            WHERE id = :id AND santri_id = :sid
        ')->execute($params);

        return true;
    }
    $pdo->prepare('
        INSERT INTO santri_riwayat_hidmah (santri_id, jenis_peran, nama_hidmah, tahun_ajaran_mulai, tahun_ajaran_selesai, tanggal_mulai, tanggal_selesai, keterangan)
        VALUES (:sid, :jenis, :nama, :tm, :ts, :tgl1, :tgl2, :ket)
    ')->execute($params);

    return true;
}

/** Entri poin dari presensi / keaktifan (bukan pelanggaran kedisiplinan). */
function santri_riwayat_is_poin_presensi(array $row): bool
{
    $sumber = strtoupper(trim((string) ($row['sumber_data'] ?? '')));
    if ($sumber !== '' && str_starts_with($sumber, 'PRESENSI')) {
        return true;
    }
    if (!empty($row['reference_presensi_id'])) {
        return true;
    }
    $ket = trim((string) ($row['keterangan'] ?? ''));
    if ($ket !== '' && preg_match('/^Auto poin dari presensi/i', $ket)) {
        return true;
    }

    return false;
}

/** SQL WHERE untuk mengecualikan poin presensi dari daftar pelanggaran. */
function santri_riwayat_sql_exclude_poin_presensi(string $ledgerAlias = 'l'): string
{
    return ' AND ' . $ledgerAlias . '.sumber_data NOT LIKE \'PRESENSI%\''
        . ' AND ' . $ledgerAlias . '.reference_presensi_id IS NULL';
}

/** Nama pelanggaran spesifik (bukan presensi) — dari keterangan input atau rule. */
function santri_riwayat_pelanggaran_nama(array $row): string
{
    $ket = trim((string) ($row['keterangan'] ?? ''));

    if (preg_match('/^Input rule:\s*(.+)$/iu', $ket, $m)) {
        return trim($m[1]);
    }

    $generic = [
        'Penambahan poin manual',
        'Pengurangan poin manual',
    ];
    if ($ket !== '' && !in_array($ket, $generic, true)) {
        return $ket;
    }

    $namaRule = trim((string) ($row['nama_rule'] ?? ''));
    if ($namaRule !== '') {
        return $namaRule;
    }

    $contoh = trim((string) ($row['contoh_pelanggaran'] ?? ''));
    if ($contoh !== '') {
        $parts = preg_split('/\s*[,;]\s*/', $contoh);
        if (is_array($parts) && isset($parts[0]) && trim($parts[0]) !== '') {
            return trim($parts[0]);
        }

        return mb_substr($contoh, 0, 120);
    }

    return $ket !== '' ? $ket : '—';
}

/** @return list<array<string, mixed>> */
function santri_riwayat_pelanggaran_list(PDO $pdo, int $santriId, ?int $tahunFilter = null): array
{
    if (!table_exists($pdo, 'point_ledger')) {
        return [];
    }
    ensure_point_tables($pdo);
    $sql = '
        SELECT l.id, l.tanggal, l.jenis_perubahan, l.point_delta, l.keterangan, l.sumber_data,
               l.reference_presensi_id,
               r.kode_rule, r.kategori, r.nama_rule, r.contoh_pelanggaran
        FROM point_ledger l
        LEFT JOIN point_rules r ON r.id = l.rule_id
        WHERE l.santri_id = :sid AND l.jenis_perubahan = "PLUS"
        ' . santri_riwayat_sql_exclude_poin_presensi('l') . '
    ';
    $params = ['sid' => $santriId];
    if ($tahunFilter !== null && $tahunFilter > 0) {
        $sql .= ' AND YEAR(l.tanggal) = :th';
        $params['th'] = $tahunFilter;
    }
    $sql .= ' ORDER BY l.tanggal DESC, l.id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Ringkasan poin pelanggaran per tahun kalender. */
function santri_riwayat_pelanggaran_per_tahun(PDO $pdo, int $santriId): array
{
    if (!table_exists($pdo, 'point_ledger')) {
        return [];
    }
    ensure_point_tables($pdo);
    $st = $pdo->prepare('
        SELECT YEAR(tanggal) AS th, SUM(point_delta) AS total_poin, COUNT(*) AS jumlah
        FROM point_ledger l
        WHERE santri_id = :sid AND jenis_perubahan = "PLUS"
        ' . santri_riwayat_sql_exclude_poin_presensi('l') . '
        GROUP BY YEAR(tanggal)
        ORDER BY th DESC
    ');
    $st->execute(['sid' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Label keaktifan ringkas: Baik / Sedang / Buruk. */
function santri_riwayat_keaktifan_label_ringkas(string $kategoriRekap): string
{
    $k = trim($kategoriRekap);
    if ($k === 'Bagus' || $k === 'Baik') {
        return 'Baik';
    }
    if ($k === 'Sedang') {
        return 'Sedang';
    }

    return 'Buruk';
}

/** Badge Bootstrap untuk label keaktifan. */
function santri_riwayat_keaktifan_badge_class(string $label): string
{
    return match ($label) {
        'Baik' => 'text-bg-success',
        'Sedang' => 'text-bg-warning',
        default => 'text-bg-danger',
    };
}

/**
 * @param array{hadir:int,izin:int,sakit:int,alpa:int,total:int} $totals
 * @return array{th:int,hadir:int,izin:int,sakit:int,alpa:int,total:int,persen_hadir:float,kategori:string,label:string,keterangan:string}|null
 */
function santri_riwayat_keaktifan_row_from_totals(PDO $pdo, int $th, array $totals): ?array
{
    $hadir = (int) ($totals['hadir'] ?? 0);
    $izin = (int) ($totals['izin'] ?? 0);
    $sakit = (int) ($totals['sakit'] ?? 0);
    $alpa = (int) ($totals['alpa'] ?? 0);
    $total = (int) ($totals['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $persen = round($hadir / $total * 100, 1);
    $kat = santri_category($alpa, $goodMax, $mediumMax);
    $label = santri_riwayat_keaktifan_label_ringkas($kat);

    return [
        'th' => $th,
        'hadir' => $hadir,
        'izin' => $izin,
        'sakit' => $sakit,
        'alpa' => $alpa,
        'total' => $total,
        'persen_hadir' => $persen,
        'kategori' => $kat,
        'label' => $label,
        'keterangan' => sprintf(
            'Kehadiran %s%% · Hadir %d · Izin %d · Sakit %d · ALPA %d (dari %d jadwal terhitung)',
            number_format($persen, 1, ',', '.'),
            $hadir,
            $izin,
            $sakit,
            $alpa,
            $total
        ),
    ];
}

/**
 * Rekap keaktifan per tahun kalender dari data presensi (bukan poin).
 *
 * @return list<array{th:int,hadir:int,izin:int,sakit:int,alpa:int,total:int,persen_hadir:float,kategori:string,label:string,keterangan:string}>
 */
function santri_riwayat_keaktifan_per_tahun(PDO $pdo, int $santriId): array
{
    if (!table_exists($pdo, 'presensi') || $santriId <= 0) {
        return [];
    }
    require_once __DIR__ . '/rekap_keaktifan.php';

    $st = $pdo->prepare('
        SELECT DISTINCT YEAR(p.tanggal_presensi) AS th
        FROM presensi p
        WHERE p.santri_id = :sid
        ORDER BY th DESC
    ');
    $st->execute(['sid' => $santriId]);
    $years = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $y): bool => $y > 0));
    if ($years === []) {
        return [];
    }

    $today = date('Y-m-d');
    $minYear = min($years);
    $maxYear = max($years);
    $globalStart = sprintf('%04d-01-01', $minYear);
    $globalEnd = sprintf('%04d-12-31', $maxYear);
    if ($globalEnd > $today) {
        $globalEnd = $today;
    }
    if ($globalStart > $globalEnd) {
        return [];
    }

    $allRows = rekap_keaktifan_fetch_eligible_rows($pdo, $globalStart, $globalEnd, [$santriId], 0, false);
    /** @var array<int, list<array<string, mixed>>> $rowsByYear */
    $rowsByYear = [];
    foreach ($allRows as $row) {
        $tanggal = (string) ($row['tanggal_presensi'] ?? '');
        if ($tanggal === '') {
            continue;
        }
        $th = (int) date('Y', strtotime($tanggal) ?: 0);
        if ($th <= 0) {
            continue;
        }
        $rowsByYear[$th][] = $row;
    }

    $out = [];
    foreach ($years as $th) {
        $row = santri_riwayat_keaktifan_row_from_totals($pdo, $th, rekap_keaktifan_totals_from_rows($rowsByYear[$th] ?? []));
        if ($row !== null) {
            $out[] = $row;
        }
    }

    return $out;
}

/** Keaktifan satu tahun; null jika tidak ada presensi. */
function santri_riwayat_keaktifan_tahun(PDO $pdo, int $santriId, int $tahun): ?array
{
    if ($tahun <= 0 || !table_exists($pdo, 'presensi') || $santriId <= 0) {
        return null;
    }
    require_once __DIR__ . '/rekap_keaktifan.php';

    $today = date('Y-m-d');
    $start = sprintf('%04d-01-01', $tahun);
    $end = sprintf('%04d-12-31', $tahun);
    if ($end > $today) {
        $end = $today;
    }
    if ($start > $end) {
        return null;
    }

    $rows = rekap_keaktifan_fetch_eligible_rows($pdo, $start, $end, [$santriId], 0, false);

    return santri_riwayat_keaktifan_row_from_totals($pdo, $tahun, rekap_keaktifan_totals_from_rows($rows));
}

/** @param array<string, mixed> $santri Row santri */
function santri_riwayat_ringkasan(PDO $pdo, array $santri): array
{
    $id = (int) ($santri['id'] ?? 0);
    $tglMasuk = trim((string) ($santri['tanggal_masuk'] ?? ''));
    $taMasuk = $tglMasuk !== '' ? santri_tahun_ajaran_for_date($pdo, $tglMasuk) : null;
    $tingkatanRows = santri_riwayat_tingkatan_list($pdo, $id);
    $hidmahRows = santri_riwayat_hidmah_list($pdo, $id);
    $pelanggaranTahun = santri_riwayat_pelanggaran_per_tahun($pdo, $id);
    $totalPoin = 0;
    foreach ($pelanggaranTahun as $pt) {
        $totalPoin += (int) ($pt['total_poin'] ?? 0);
    }
    if (function_exists('santri_keaktifan_tampilan_tahun')) {
        require_once __DIR__ . '/santri_keaktifan_nilai.php';
        $keaktifanTahunIni = santri_keaktifan_tampilan_tahun($pdo, $id, (int) date('Y'));
    } else {
        $keaktifanTahunIni = santri_riwayat_keaktifan_tahun($pdo, $id, (int) date('Y'));
    }

    return [
        'tahun_masuk' => $tglMasuk !== '' && preg_match('/^(\d{4})/', $tglMasuk, $m) ? (int) $m[1] : null,
        'tahun_ajaran_masuk' => $taMasuk,
        'jumlah_tahun_tingkatan' => count($tingkatanRows),
        'jumlah_hidmah' => count($hidmahRows),
        'total_poin_pelanggaran' => $totalPoin,
        'keaktifan_tahun_ini' => $keaktifanTahunIni,
        'tahun_pertama_tingkatan' => $tingkatanRows[0]['tahun_ajaran_mulai'] ?? null,
        'tingkatan_saat_ini' => trim((string) ($santri['tingkatan'] ?? '')),
        'jumlah_asrama' => count(santri_riwayat_asrama_list($pdo, $id)),
    ];
}
