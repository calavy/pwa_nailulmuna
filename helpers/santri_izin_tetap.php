<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ensure_santri_izin_tetap_tables(PDO $pdo): void
{
    ensure_santri_identity_columns($pdo);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_izin_tetap (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            judul VARCHAR(120) NOT NULL DEFAULT "Hidmah",
            jenis ENUM("HIDMAH","TUGAS") NOT NULL DEFAULT "HIDMAH",
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            tanpa_cetak TINYINT(1) NOT NULL DEFAULT 1,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_izin_tetap_santri (santri_id),
            INDEX idx_izin_tetap_aktif (is_aktif)
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_izin_tetap_slot (
            id INT AUTO_INCREMENT PRIMARY KEY,
            izin_tetap_id INT NOT NULL,
            hari_ke TINYINT NOT NULL COMMENT "1=Senin ... 7=Minggu",
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            INDEX idx_slot_izin (izin_tetap_id, hari_ke)
        )
    ');
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'nomor_surat')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN nomor_surat VARCHAR(180) NULL AFTER keterangan');
        } catch (PDOException $e) {
        }
    }
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'penanggung_jawab')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN penanggung_jawab VARCHAR(120) NULL AFTER nomor_surat');
        } catch (PDOException $e) {
        }
    }
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'kegiatan_ditinggalkan')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN kegiatan_ditinggalkan VARCHAR(500) NULL AFTER judul');
        } catch (PDOException $e) {
        }
    }
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'kategori_hidmah')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN kategori_hidmah VARCHAR(64) NULL AFTER jenis');
        } catch (PDOException $e) {
        }
    }
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'kelompok_id')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN kelompok_id INT UNSIGNED NULL AFTER santri_id');
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD INDEX idx_izin_tetap_kelompok (kelompok_id)');
        } catch (PDOException $e) {
        }
    }
    try {
        $pdo->exec('
            ALTER TABLE santri_izin_tetap
            ADD CONSTRAINT fk_izin_tetap_santri
            FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ');
    } catch (PDOException $e) {
        /* abaikan jika sudah ada */
    }
    try {
        $pdo->exec('
            ALTER TABLE santri_izin_tetap_slot
            ADD CONSTRAINT fk_izin_tetap_slot_header
            FOREIGN KEY (izin_tetap_id) REFERENCES santri_izin_tetap(id) ON DELETE CASCADE
        ');
    } catch (PDOException $e) {
        /* abaikan jika sudah ada */
    }
}

/** @return array<int, string> */
function santri_izin_tetap_hari_map(): array
{
    return [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];
}

function santri_izin_tetap_jenis_label(string $jenis): string
{
    return match (strtoupper($jenis)) {
        'TUGAS' => 'Tugas',
        default => 'Hidmah',
    };
}

/**
 * Izin tetap hidmah hanya mengecualikan ALPA pada kegiatan berkategori Jama'ah.
 * Jenis TUGAS tetap berlaku untuk semua kategori kegiatan.
 */
function santri_izin_tetap_applies_to_kegiatan(PDO $pdo, int $kegiatanId, string $jenisIzinTetap): bool
{
    if ($kegiatanId <= 0) {
        return true;
    }
    if (strtoupper(trim($jenisIzinTetap)) !== 'HIDMAH') {
        return true;
    }
    if (!table_exists($pdo, 'kegiatan')) {
        return false;
    }
    if (!function_exists('ensure_kegiatan_kategori_column')) {
        require_once __DIR__ . '/app.php';
    }
    ensure_kegiatan_kategori_column($pdo);
    $st = $pdo->prepare('SELECT COALESCE(kategori_kegiatan, "TAALIM") FROM kegiatan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $kegiatanId]);

    return strtoupper((string) ($st->fetchColumn() ?: 'TAALIM')) === 'JAMAAH';
}

/** @return list<string> */
function santri_izin_tetap_kegiatan_jamaah_list(PDO $pdo): array
{
    if (!table_exists($pdo, 'kegiatan')) {
        return [];
    }
    if (!function_exists('ensure_kegiatan_kategori_column')) {
        require_once __DIR__ . '/app.php';
    }
    ensure_kegiatan_kategori_column($pdo);
    $rows = $pdo->query('
        SELECT nama_kegiatan
        FROM kegiatan
        WHERE COALESCE(is_active, 1) = 1
          AND COALESCE(kategori_kegiatan, "TAALIM") = "JAMAAH"
        ORDER BY nama_kegiatan ASC
    ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $out = [];
    foreach ($rows as $nama) {
        $t = trim((string) $nama);
        if ($t !== '') {
            $out[$t] = $t;
        }
    }

    return array_values($out);
}

function santri_izin_tetap_kegiatan_picker_submitted(array $post): bool
{
    return trim((string) ($post['kegiatan_ditinggalkan_picker'] ?? '')) === '1';
}

/**
 * @return list<string>
 */
function santri_izin_tetap_kegiatan_checkbox_dari_post(array $post): array
{
    $names = [];
    $picked = $post['kegiatan_ditinggalkan_items'] ?? [];
    if (!is_array($picked)) {
        return [];
    }
    foreach ($picked as $nama) {
        $t = trim((string) $nama);
        if ($t !== '') {
            $names[$t] = $t;
        }
    }

    return array_values($names);
}

/**
 * @return list<string>
 */
function santri_izin_tetap_kegiatan_manual_dari_post(array $post): array
{
    $manual = trim((string) ($post['kegiatan_ditinggalkan'] ?? ''));
    if ($manual === '') {
        return [];
    }
    $names = [];
    foreach (preg_split('/[\n,;]+/', $manual) ?: [] as $part) {
        $t = trim((string) $part);
        if ($t !== '') {
            $names[$t] = $t;
        }
    }

    return array_values($names);
}

function santri_izin_tetap_kegiatan_ditinggalkan_dari_post(array $post): ?string
{
    $names = santri_izin_tetap_kegiatan_checkbox_dari_post($post);
    foreach (santri_izin_tetap_kegiatan_manual_dari_post($post) as $nama) {
        $names[$nama] = $nama;
    }
    $names = array_values($names);
    if (santri_izin_tetap_kegiatan_picker_submitted($post)) {
        return $names === [] ? '' : implode(', ', $names);
    }

    return $names === [] ? null : implode(', ', $names);
}

/**
 * Kegiatan ditinggalkan per santri (penting saat simpan massal / tingkatan berbeda).
 *
 * @param array<int, array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $normalizedSlots
 * @param list<string> $checkboxNames
 * @param list<string> $manualNames
 * @param bool $checkboxFieldPresent Apakah field checkbox ikut terkirim (beda "belum dirender" vs "dicentang nol")
 */
function santri_izin_tetap_kegiatan_ditinggalkan_for_santri(
    PDO $pdo,
    int $santriId,
    array $normalizedSlots,
    string $jenis,
    array $checkboxNames,
    array $manualNames,
    bool $pickerSubmitted,
    bool $checkboxFieldPresent = true
): string {
    if ($santriId <= 0) {
        return '';
    }

    $jenis = strtoupper(trim($jenis));
    $hanyaJamaah = $jenis !== 'TUGAS';
    $tingList = santri_izin_tetap_tingkatan_for_santri_ids($pdo, [$santriId]);
    $auto = $normalizedSlots === []
        ? []
        : santri_izin_tetap_kegiatan_overlap_dari_jadwal($pdo, $normalizedSlots, $tingList, $hanyaJamaah);
    $autoNames = [];
    foreach ($auto as $row) {
        $n = trim((string) ($row['nama'] ?? ''));
        if ($n !== '') {
            $autoNames[$n] = $n;
        }
    }

    if (!$pickerSubmitted) {
        if ($autoNames !== []) {
            return implode(', ', array_values($autoNames));
        }

        return '';
    }

    if (!$checkboxFieldPresent && $manualNames === []) {
        if ($autoNames !== []) {
            return implode(', ', array_values($autoNames));
        }

        return '';
    }

    $out = [];
    foreach ($checkboxNames as $nama) {
        if (isset($autoNames[$nama])) {
            $out[$nama] = $nama;
        }
    }
    foreach ($manualNames as $nama) {
        $out[$nama] = $nama;
    }

    return implode(', ', array_values($out));
}

/** @return list<string> */
function santri_izin_tetap_kegiatan_ditinggalkan_terpilih(string $stored, array $daftarKegiatan): array
{
    $stored = trim($stored);
    if ($stored === '') {
        return [];
    }
    $parts = [];
    foreach (preg_split('/[\n,;]+/', $stored) ?: [] as $part) {
        $t = trim((string) $part);
        if ($t !== '') {
            $parts[$t] = $t;
        }
    }
    $out = [];
    foreach ($daftarKegiatan as $nama) {
        $nama = trim((string) $nama);
        if ($nama !== '' && isset($parts[$nama])) {
            $out[] = $nama;
        }
    }

    return $out;
}

function santri_izin_tetap_kegiatan_ditinggalkan_manual(string $stored, array $daftarKegiatan): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '';
    }
    $known = [];
    foreach ($daftarKegiatan as $nama) {
        $known[trim((string) $nama)] = true;
    }
    $manual = [];
    foreach (preg_split('/[\n,;]+/', $stored) ?: [] as $part) {
        $t = trim((string) $part);
        if ($t !== '' && !isset($known[$t])) {
            $manual[$t] = $t;
        }
    }

    return implode(', ', array_values($manual));
}

/** @return list<string> */
function santri_izin_tetap_tingkatan_for_santri_ids(PDO $pdo, array $santriIds): array
{
    $santriIds = array_values(array_filter(array_map('intval', $santriIds)));
    if ($santriIds === [] || !table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'tingkatan')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($santriIds), '?'));
    $st = $pdo->prepare('SELECT DISTINCT tingkatan FROM santri WHERE id IN (' . $placeholders . ')');
    $st->execute($santriIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $t) {
        $t = trim((string) $t);
        if ($t !== '') {
            $out[$t] = $t;
        }
    }

    return array_values($out);
}

/**
 * Kegiatan jadwal yang bertabrakan dengan slot izin tetap (durasi jam hidmah).
 *
 * @param array<int, array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $slots
 * @param list<string> $tingkatanList
 * @return list<array{nama:string,label:string,jam:string,hari:string}>
 */
function santri_izin_tetap_kegiatan_overlap_dari_jadwal(PDO $pdo, array $slots, array $tingkatanList, bool $hanyaJamaah = true): array
{
    if ($slots === [] || !table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    if (!function_exists('ensure_kegiatan_kategori_column')) {
        require_once __DIR__ . '/app.php';
    }
    ensure_kegiatan_kategori_column($pdo);

    $tingkatanList = array_values(array_filter(array_map(static fn ($t): string => trim((string) $t), $tingkatanList)));
    if ($tingkatanList === []) {
        $tingkatanList = ['Semua Tingkatan'];
    }

    $sql = '
        SELECT j.hari_ke, j.jam_mulai, j.jam_selesai, j.tingkatan, k.nama_kegiatan,
               COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE COALESCE(k.is_active, 1) = 1
    ';
    if ($hanyaJamaah) {
        $sql .= ' AND COALESCE(k.kategori_kegiatan, "TAALIM") = "JAMAAH"';
    }
    $sql .= ' ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC';

    $jadwalRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $hariMap = santri_izin_tetap_hari_map();
    $grouped = [];

    foreach ($jadwalRows as $row) {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $jadwalHari = (int) ($row['hari_ke'] ?? 0);
        $jadwalTing = trim((string) ($row['tingkatan'] ?? ''));
        $jmJadwal = substr((string) ($row['jam_mulai'] ?? ''), 0, 8);
        $jsJadwal = substr((string) ($row['jam_selesai'] ?? ''), 0, 8);

        $tingkatanCocok = false;
        foreach ($tingkatanList as $tingkatan) {
            if ($jadwalTing === 'Semua Tingkatan'
                || strcasecmp($tingkatan, 'Semua Tingkatan') === 0
                || strcasecmp($jadwalTing, $tingkatan) === 0) {
                $tingkatanCocok = true;
                break;
            }
        }
        if (!$tingkatanCocok) {
            continue;
        }

        foreach ($slots as $slot) {
            $slotHari = (int) ($slot['hari_ke'] ?? 0);
            if ($slotHari < 1 || $slotHari > 7) {
                continue;
            }
            if ($jadwalHari !== 0 && $jadwalHari !== $slotHari) {
                continue;
            }
            $jmSlot = (string) ($slot['jam_mulai'] ?? '');
            $jsSlot = (string) ($slot['jam_selesai'] ?? '');
            if (!santri_izin_tetap_waktu_overlap($jmSlot, $jsSlot, $jmJadwal, $jsJadwal)) {
                continue;
            }

            $hariLabel = $hariMap[$slotHari] ?? '?';
            $jamLabel = substr($jmJadwal, 0, 5) . '–' . substr($jsJadwal, 0, 5);
            $detailKey = $hariLabel . '|' . $jamLabel;
            if (!isset($grouped[$nama])) {
                $grouped[$nama] = [
                    'nama' => $nama,
                    'details' => [],
                ];
            }
            $grouped[$nama]['details'][$detailKey] = [
                'hari' => $hariLabel,
                'jam' => $jamLabel,
            ];
        }
    }

    $out = [];
    foreach ($grouped as $item) {
        $details = array_values($item['details']);
        $hariParts = [];
        $jamParts = [];
        foreach ($details as $d) {
            $hariParts[$d['hari']] = $d['hari'];
            $jamParts[$d['jam']] = $d['jam'];
        }
        $hariText = implode(', ', array_values($hariParts));
        $jamText = implode(', ', array_values($jamParts));
        $out[] = [
            'nama' => (string) $item['nama'],
            'label' => $hariText . ' · ' . $jamText,
            'hari' => $hariText,
            'jam' => $jamText,
        ];
    }

    usort($out, static fn (array $a, array $b): int => strcasecmp($a['nama'], $b['nama']));

    return $out;
}

/** Nama kegiatan ditinggalkan efektif (tersimpan atau dihitung ulang dari jadwal). */
function santri_izin_tetap_kegiatan_ditinggalkan_efektif(PDO $pdo, array $izinRow, ?array $slots = null): string
{
    if (array_key_exists('kegiatan_ditinggalkan', $izinRow) && $izinRow['kegiatan_ditinggalkan'] !== null) {
        return trim((string) $izinRow['kegiatan_ditinggalkan']);
    }

    $jenis = strtoupper((string) ($izinRow['jenis'] ?? 'HIDMAH'));
    $izinId = (int) ($izinRow['id'] ?? 0);
    if ($slots === null && $izinId > 0) {
        $slots = santri_izin_tetap_slots($pdo, $izinId);
    }
    if ($slots === [] || $slots === null) {
        return '';
    }

    $tingkatan = trim((string) ($izinRow['tingkatan'] ?? ''));
    $tingList = $tingkatan !== '' ? [$tingkatan] : [];
    if ($tingList === [] && $izinId > 0) {
        $tingList = santri_izin_tetap_tingkatan_for_santri_ids($pdo, [(int) ($izinRow['santri_id'] ?? 0)]);
    }

    $hanyaJamaah = $jenis !== 'TUGAS';
    $auto = santri_izin_tetap_kegiatan_overlap_dari_jadwal($pdo, $slots, $tingList, $hanyaJamaah);
    if ($auto === []) {
        return '';
    }

    return implode(', ', array_map(static fn (array $r): string => (string) $r['nama'], $auto));
}

/**
 * Daftar nama kegiatan untuk surat cetak (dari data tersimpan atau jadwal).
 *
 * @return list<string>
 */
function santri_izin_tetap_kegiatan_items_for_print(PDO $pdo, array $izinRow): array
{
    $raw = santri_izin_tetap_kegiatan_ditinggalkan_efektif($pdo, $izinRow);

    return santri_izin_tetap_kegiatan_items_dari_raw($raw);
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_list(PDO $pdo, string $q = '', bool $hanyaAktif = false): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri')) {
        return [];
    }

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = "
        SELECT i.*, s.nis, {$nameExpr} AS nama_santri
        FROM santri_izin_tetap i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE 1=1
    ";
    if ($hanyaAktif) {
        $sql .= ' AND i.is_aktif = 1 ';
    }
    $sql .= ' ORDER BY i.is_aktif DESC, i.tanggal_mulai DESC, s.' . (column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama') . ' ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($q === '') {
        return $rows;
    }
    $needle = strtolower($q);
    $out = [];
    foreach ($rows as $r) {
        $hay = strtolower((string) ($r['nama_santri'] ?? '') . ' ' . (string) ($r['nis'] ?? '') . ' ' . (string) ($r['judul'] ?? '') . ' ' . (string) ($r['kegiatan_ditinggalkan'] ?? ''));
        if (str_contains($hay, $needle)) {
            $out[] = $r;
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_slots(PDO $pdo, int $izinTetapId): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($izinTetapId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT id, izin_tetap_id, hari_ke, jam_mulai, jam_selesai
        FROM santri_izin_tetap_slot
        WHERE izin_tetap_id = :id
        ORDER BY hari_ke ASC, jam_mulai ASC
    ');
    $stmt->execute(['id' => $izinTetapId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function santri_izin_tetap_by_id(PDO $pdo, int $id): ?array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM santri_izin_tetap WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Cek overlap dua rentang waktu (HH:MM:SS). */
function santri_izin_tetap_waktu_overlap(string $mulaiA, string $selesaiA, string $mulaiB, string $selesaiB): bool
{
    $a1 = strtotime('1970-01-01 ' . substr($mulaiA, 0, 8));
    $a2 = strtotime('1970-01-01 ' . substr($selesaiA, 0, 8));
    $b1 = strtotime('1970-01-01 ' . substr($mulaiB, 0, 8));
    $b2 = strtotime('1970-01-01 ' . substr($selesaiB, 0, 8));
    if ($a1 === false || $a2 === false || $b1 === false || $b2 === false) {
        return false;
    }

    return $a1 < $b2 && $b1 < $a2;
}

/**
 * Apakah santri punya izin tetap yang berlaku pada tanggal (dan opsional rentang jam kegiatan)?
 */
function santri_izin_tetap_berlaku(
    PDO $pdo,
    int $santriId,
    string $tanggal,
    ?string $jamMulaiKegiatan = null,
    ?string $jamSelesaiKegiatan = null,
    ?int $kegiatanId = null
): bool {
    if ($santriId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return false;
    }
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri_izin_tetap')) {
        return false;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $stmt = $pdo->prepare('
        SELECT i.id, i.jenis, sl.jam_mulai, sl.jam_selesai
        FROM santri_izin_tetap i
        INNER JOIN santri_izin_tetap_slot sl ON sl.izin_tetap_id = i.id
        WHERE i.santri_id = :sid
          AND i.is_aktif = 1
          AND i.tanggal_mulai <= :tgl
          AND (i.tanggal_selesai IS NULL OR i.tanggal_selesai >= :tgl)
          AND sl.hari_ke = :hari
    ');
    $stmt->execute([
        'sid' => $santriId,
        'tgl' => $tanggal,
        'hari' => $hariKe,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return false;
    }

    if ($jamMulaiKegiatan === null && $jamSelesaiKegiatan === null) {
        foreach ($rows as $row) {
            if ($kegiatanId === null || $kegiatanId <= 0
                || santri_izin_tetap_applies_to_kegiatan($pdo, $kegiatanId, (string) ($row['jenis'] ?? 'HIDMAH'))) {
                return true;
            }
        }

        return false;
    }

    $jamMulaiKegiatan = $jamMulaiKegiatan ?? '00:00:00';
    $jamSelesaiKegiatan = $jamSelesaiKegiatan ?? '23:59:59';

    foreach ($rows as $row) {
        if ($kegiatanId !== null && $kegiatanId > 0
            && !santri_izin_tetap_applies_to_kegiatan($pdo, $kegiatanId, (string) ($row['jenis'] ?? 'HIDMAH'))) {
            continue;
        }
        if (santri_izin_tetap_waktu_overlap(
            (string) ($row['jam_mulai'] ?? ''),
            (string) ($row['jam_selesai'] ?? ''),
            $jamMulaiKegiatan,
            $jamSelesaiKegiatan
        )) {
            return true;
        }
    }

    return false;
}

/**
 * Dari daftar santri: yang izin tetapnya overlap jam kegiatan pada tanggal.
 *
 * @param list<int> $santriIds
 * @return array<int, true>
 */
function santri_izin_tetap_map_for_santri_ids(
    PDO $pdo,
    array $santriIds,
    string $tanggal,
    ?string $jamMulaiKegiatan = null,
    ?string $jamSelesaiKegiatan = null,
    ?int $kegiatanId = null
): array {
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn (int $id): bool => $id > 0)));
    if ($santriIds === [] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return [];
    }
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri_izin_tetap')) {
        return [];
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $placeholders = implode(',', array_fill(0, count($santriIds), '?'));
    $stmt = $pdo->prepare('
        SELECT i.santri_id, i.jenis, sl.jam_mulai, sl.jam_selesai
        FROM santri_izin_tetap i
        INNER JOIN santri_izin_tetap_slot sl ON sl.izin_tetap_id = i.id
        WHERE i.santri_id IN (' . $placeholders . ')
          AND i.is_aktif = 1
          AND i.tanggal_mulai <= ?
          AND (i.tanggal_selesai IS NULL OR i.tanggal_selesai >= ?)
          AND sl.hari_ke = ?
    ');
    $params = array_merge($santriIds, [$tanggal, $tanggal, $hariKe]);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    if ($jamMulaiKegiatan === null && $jamSelesaiKegiatan === null) {
        foreach ($rows as $row) {
            $sid = (int) ($row['santri_id'] ?? 0);
            if ($sid <= 0 || isset($out[$sid])) {
                continue;
            }
            if ($kegiatanId !== null && $kegiatanId > 0
                && !santri_izin_tetap_applies_to_kegiatan($pdo, $kegiatanId, (string) ($row['jenis'] ?? 'HIDMAH'))) {
                continue;
            }
            $out[$sid] = true;
        }

        return $out;
    }

    $jamMulaiKegiatan = $jamMulaiKegiatan ?? '00:00:00';
    $jamSelesaiKegiatan = $jamSelesaiKegiatan ?? '23:59:59';
    foreach ($rows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0 || isset($out[$sid])) {
            continue;
        }
        if ($kegiatanId !== null && $kegiatanId > 0
            && !santri_izin_tetap_applies_to_kegiatan($pdo, $kegiatanId, (string) ($row['jenis'] ?? 'HIDMAH'))) {
            continue;
        }
        if (santri_izin_tetap_waktu_overlap(
            (string) ($row['jam_mulai'] ?? ''),
            (string) ($row['jam_selesai'] ?? ''),
            $jamMulaiKegiatan,
            $jamSelesaiKegiatan
        )) {
            $out[$sid] = true;
        }
    }

    return $out;
}

/**
 * ID santri yang izin tetapnya berlaku pada tanggal (untuk generate alpa / sinkron presensi).
 *
 * @return list<int>
 */
function santri_izin_tetap_santri_ids_pada_tanggal(
    PDO $pdo,
    string $tanggal,
    ?string $jamMulaiKegiatan = null,
    ?string $jamSelesaiKegiatan = null,
    ?int $kegiatanId = null
): array {
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'santri_izin_tetap')) {
        return [];
    }

    $aktifSql = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(s.is_aktif, 1) = 1 ' : '';
    $rows = $pdo->query('SELECT s.id FROM santri s WHERE 1=1 ' . $aktifSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $sid = (int) ($r['id'] ?? 0);
        if ($sid > 0 && santri_izin_tetap_berlaku($pdo, $sid, $tanggal, $jamMulaiKegiatan, $jamSelesaiKegiatan, $kegiatanId)) {
            $out[] = $sid;
        }
    }

    return $out;
}

/** Parse ID santri dari POST (satu atau banyak). */
function santri_izin_tetap_santri_ids_dari_post(array $post): array
{
    $editId = (int) ($post['id'] ?? 0);
    if ($editId > 0) {
        $sid = (int) ($post['santri_id'] ?? 0);

        return $sid > 0 ? [$sid] : [];
    }

    $raw = $post['santri_ids'] ?? [];
    if (!is_array($raw)) {
        $raw = [(string) $raw];
    }
    $ids = [];
    foreach ($raw as $v) {
        $sid = (int) $v;
        if ($sid > 0) {
            $ids[$sid] = $sid;
        }
    }

    return array_values($ids);
}

/**
 * @param array<int, array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $normalizedSlots
 * @return array{ok:bool,message:string,id?:int}
 */
function santri_izin_tetap_persist_one(
    PDO $pdo,
    int $id,
    int $santriId,
    string $judul,
    ?string $kegiatanDitinggalkan,
    string $jenis,
    ?string $kategoriHidmah,
    string $tglMulai,
    ?string $tglSelesai,
    int $isAktif,
    ?string $keterangan,
    ?string $penanggungJawab,
    array $normalizedSlots,
    int $userId
): array {
    if ($id > 0) {
        $existing = santri_izin_tetap_by_id($pdo, $id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Data izin tetap tidak ditemukan.'];
        }
        $upd = $pdo->prepare('
            UPDATE santri_izin_tetap
            SET santri_id = :sid, judul = :judul, kegiatan_ditinggalkan = :keg, jenis = :jenis,
                kategori_hidmah = :khid, tanggal_mulai = :mulai, tanggal_selesai = :selesai,
                is_aktif = :aktif, keterangan = :ket, penanggung_jawab = :pj, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'sid' => $santriId,
            'judul' => $judul,
            'keg' => $kegiatanDitinggalkan,
            'jenis' => $jenis,
            'khid' => $kategoriHidmah,
            'mulai' => $tglMulai,
            'selesai' => $tglSelesai,
            'aktif' => $isAktif,
            'ket' => $keterangan,
            'pj' => $penanggungJawab,
            'id' => $id,
        ]);
        $pdo->prepare('DELETE FROM santri_izin_tetap_slot WHERE izin_tetap_id = :id')->execute(['id' => $id]);
    } else {
        $ins = $pdo->prepare('
            INSERT INTO santri_izin_tetap
                (santri_id, judul, kegiatan_ditinggalkan, jenis, kategori_hidmah, tanggal_mulai, tanggal_selesai, is_aktif, tanpa_cetak, keterangan, penanggung_jawab, created_by)
            VALUES
                (:sid, :judul, :keg, :jenis, :khid, :mulai, :selesai, :aktif, 0, :ket, :pj, :uid)
        ');
        $ins->execute([
            'sid' => $santriId,
            'judul' => $judul,
            'keg' => $kegiatanDitinggalkan,
            'jenis' => $jenis,
            'khid' => $kategoriHidmah,
            'mulai' => $tglMulai,
            'selesai' => $tglSelesai,
            'aktif' => $isAktif,
            'ket' => $keterangan,
            'pj' => $penanggungJawab,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Gagal menyimpan data izin tetap.'];
        }
    }

    $slotIns = $pdo->prepare('
        INSERT INTO santri_izin_tetap_slot (izin_tetap_id, hari_ke, jam_mulai, jam_selesai)
        VALUES (:iid, :hari, :jm, :js)
    ');
    foreach ($normalizedSlots as $slot) {
        $slotIns->execute([
            'iid' => $id,
            'hari' => $slot['hari_ke'],
            'jm' => $slot['jam_mulai'],
            'js' => $slot['jam_selesai'],
        ]);
    }

    return ['ok' => true, 'message' => '', 'id' => $id];
}

/**
 * @param array<int, array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $slots
 * @return array{ok:bool,message:string,id?:int,count?:int,ids?:list<int>}
 */
function santri_izin_tetap_simpan(PDO $pdo, array $post, array $slots, int $userId): array
{
    ensure_santri_izin_tetap_tables($pdo);

    $id = (int) ($post['id'] ?? 0);
    $santriIds = santri_izin_tetap_santri_ids_dari_post($post);
    $judul = trim((string) ($post['judul'] ?? 'Hidmah'));
    $pickerSubmitted = santri_izin_tetap_kegiatan_picker_submitted($post);
    $kegiatanCheckboxFieldPresent = array_key_exists('kegiatan_ditinggalkan_items', $post);
    $kegiatanCheckbox = santri_izin_tetap_kegiatan_checkbox_dari_post($post);
    $kegiatanManual = santri_izin_tetap_kegiatan_manual_dari_post($post);
    $kegiatanDitinggalkan = santri_izin_tetap_kegiatan_ditinggalkan_dari_post($post);
    $jenis = strtoupper(trim((string) ($post['jenis'] ?? 'HIDMAH')));
    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? ''));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? ''));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $penanggungJawab = trim((string) ($post['penanggung_jawab'] ?? ''));
    $isAktif = isset($post['is_aktif']) ? 1 : 0;

    if ($santriIds === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu santri.'];
    }
    if ($id > 0 && count($santriIds) > 1) {
        return ['ok' => false, 'message' => 'Ubah izin tetap hanya untuk satu santri.'];
    }
    if ($judul === '') {
        return ['ok' => false, 'message' => 'Judul/keterangan singkat wajib diisi.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai)) {
        return ['ok' => false, 'message' => 'Tanggal mulai tidak valid.'];
    }
    if ($tglSelesai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai)) {
        return ['ok' => false, 'message' => 'Tanggal selesai tidak valid.'];
    }
    if ($tglSelesai !== '' && $tglSelesai < $tglMulai) {
        return ['ok' => false, 'message' => 'Tanggal selesai harus setelah tanggal mulai.'];
    }
    if (!in_array($jenis, ['HIDMAH', 'TUGAS'], true)) {
        $jenis = 'HIDMAH';
    }
    require_once __DIR__ . '/izin_tetap_hidmah_kategori.php';
    $kategoriHidmah = null;
    if ($jenis === 'HIDMAH') {
        $kategoriRaw = trim((string) ($post['kategori_hidmah'] ?? ''));
        $kategoriHidmah = izin_tetap_hidmah_kategori_normalize_kode($pdo, $kategoriRaw);
        if ($kategoriHidmah === '') {
            $aktifList = izin_tetap_hidmah_kategori_list_aktif($pdo);
            $kategoriHidmah = $aktifList !== [] ? (string) ($aktifList[0]['kode'] ?? '') : 'pondok';
        }
        if ($kategoriHidmah === '') {
            return ['ok' => false, 'message' => 'Pilih kategori hidmah. Atur kategori di Pengaturan → Perizinan.'];
        }
    }
    if ($slots === []) {
        return ['ok' => false, 'message' => 'Centang minimal satu hari hidmah dan isi jam mulai–selesai.'];
    }

    $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    foreach ($santriIds as $santriId) {
        $chk->execute(['id' => $santriId]);
        if (!$chk->fetch()) {
            return ['ok' => false, 'message' => 'Santri tidak ditemukan (ID ' . $santriId . ').'];
        }
    }

    $tglSelesaiDb = $tglSelesai !== '' ? $tglSelesai : null;
    $ketDb = $keterangan !== '' ? $keterangan : null;
    $pjDb = $penanggungJawab !== '' ? $penanggungJawab : null;

    $normalizedSlots = [];
    foreach ($slots as $slot) {
        $hari = (int) ($slot['hari_ke'] ?? 0);
        $jm = trim((string) ($slot['jam_mulai'] ?? ''));
        $js = trim((string) ($slot['jam_selesai'] ?? ''));
        if ($hari < 1 || $hari > 7 || $jm === '' || $js === '') {
            continue;
        }
        if (strlen($jm) === 5) {
            $jm .= ':00';
        }
        if (strlen($js) === 5) {
            $js .= ':00';
        }
        if ($jm >= $js) {
            return ['ok' => false, 'message' => 'Jam selesai harus setelah jam mulai pada setiap blok waktu.'];
        }
        $normalizedSlots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
    }
    if ($normalizedSlots === []) {
        return ['ok' => false, 'message' => 'Centang minimal satu hari dan isi jam yang valid pada blok waktu.'];
    }

    $resolveKegiatanDb = static function (int $santriId) use (
        $pdo,
        $normalizedSlots,
        $jenis,
        $kegiatanCheckbox,
        $kegiatanManual,
        $pickerSubmitted,
        $kegiatanCheckboxFieldPresent,
        $kegiatanDitinggalkan,
        $santriIds
    ): ?string {
        $pickerActive = $pickerSubmitted || $kegiatanCheckbox !== [] || $kegiatanManual !== [];
        if (count($santriIds) === 1 && !$pickerActive && $kegiatanDitinggalkan !== null) {
            return $kegiatanDitinggalkan;
        }

        return santri_izin_tetap_kegiatan_ditinggalkan_for_santri(
            $pdo,
            $santriId,
            $normalizedSlots,
            $jenis,
            $kegiatanCheckbox,
            $kegiatanManual,
            $pickerActive,
            $kegiatanCheckboxFieldPresent
        );
    };

    $inTransaction = false;
    $savedIds = [];
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $inTransaction = true;
        }

        foreach ($santriIds as $santriId) {
            $rowId = $id > 0 ? $id : 0;
            $kegDb = $resolveKegiatanDb($santriId);
            $result = santri_izin_tetap_persist_one(
                $pdo,
                $rowId,
                $santriId,
                $judul,
                $kegDb,
                $jenis,
                $kategoriHidmah,
                $tglMulai,
                $tglSelesaiDb,
                $isAktif,
                $ketDb,
                $pjDb,
                $normalizedSlots,
                $userId
            );
            if (!$result['ok']) {
                if ($inTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return $result;
            }
            $savedIds[] = (int) ($result['id'] ?? 0);
            if ($id > 0) {
                break;
            }
        }

        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        if ($id <= 0 && count($savedIds) > 1) {
            $kelompokId = santri_izin_tetap_assign_kelompok($pdo, $savedIds);
        } else {
            $kelompokId = 0;
        }
    } catch (Throwable $e) {
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan izin tetap: ' . $e->getMessage()];
    }

    $presensiNote = $jenis === 'HIDMAH'
        ? ' Presensi kegiatan Jama\'ah pada jadwal ini dicatat IZIN (bukan ALPA); kegiatan Ta\'lim tetap mengikuti aturan biasa.'
        : ' Presensi pada jadwal ini dicatat IZIN (bukan ALPA).';

    $count = count($savedIds);
    if ($count > 1) {
        return [
            'ok' => true,
            'message' => 'Izin tetap disimpan untuk ' . $count . ' santri.' . $presensiNote . ' Surat gabungan siap dicetak.',
            'count' => $count,
            'ids' => $savedIds,
            'id' => $savedIds[0] ?? 0,
            'kelompok_id' => $kelompokId ?? santri_izin_tetap_assign_kelompok($pdo, $savedIds),
        ];
    }

    return [
        'ok' => true,
        'message' => 'Izin tetap disimpan. Surat dapat dicetak.' . $presensiNote,
        'id' => $savedIds[0] ?? 0,
        'count' => $count,
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_izin_tetap_set_aktif(PDO $pdo, int $id, bool $aktif): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $pdo->prepare('UPDATE santri_izin_tetap SET is_aktif = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['a' => $aktif ? 1 : 0, 'id' => $id]);

    return [
        'ok' => true,
        'message' => $aktif ? 'Izin tetap diaktifkan kembali.' : 'Izin tetap dihentikan sementara.',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_izin_tetap_hapus(PDO $pdo, int $id): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $pdo->prepare('DELETE FROM santri_izin_tetap_slot WHERE izin_tetap_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM santri_izin_tetap WHERE id = :id')->execute(['id' => $id]);

    return ['ok' => true, 'message' => 'Izin tetap dihapus.'];
}

/** Parse slot dari POST (blok hari centang + jam, atau format lama hari_ke[] per baris). */
function santri_izin_tetap_slots_dari_post(array $post): array
{
    $blokHari = $post['slot_hari'] ?? null;
    $blokMulai = $post['slot_jam_mulai'] ?? null;
    $blokSelesai = $post['slot_jam_selesai'] ?? null;
    if (is_array($blokHari) && is_array($blokMulai) && is_array($blokSelesai)) {
        $slots = [];
        foreach ($blokHari as $idx => $hariArr) {
            if (!is_array($hariArr)) {
                continue;
            }
            $jm = trim((string) ($blokMulai[$idx] ?? ''));
            $js = trim((string) ($blokSelesai[$idx] ?? ''));
            if ($jm === '' || $js === '') {
                continue;
            }
            foreach ($hariArr as $hariRaw) {
                $hari = (int) $hariRaw;
                if ($hari < 1 || $hari > 7) {
                    continue;
                }
                $slots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
            }
        }
        if ($slots !== []) {
            return $slots;
        }
    }

    $hariList = $post['hari_ke'] ?? [];
    $mulaiList = $post['jam_mulai'] ?? [];
    $selesaiList = $post['jam_selesai'] ?? [];
    if (!is_array($hariList)) {
        return [];
    }
    $slots = [];
    foreach ($hariList as $i => $hariRaw) {
        $hari = (int) $hariRaw;
        $jm = trim((string) ($mulaiList[$i] ?? ''));
        $js = trim((string) ($selesaiList[$i] ?? ''));
        if ($hari < 1 || $hari > 7 || $jm === '' || $js === '') {
            continue;
        }
        $slots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
    }

    return $slots;
}

/**
 * Kelompokkan slot DB menjadi blok form (hari centang + jam bersama).
 *
 * @param list<array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $slots
 * @return list<array{hari:list<int>,jam_mulai:string,jam_selesai:string}>
 */
function santri_izin_tetap_slots_ke_blok_form(array $slots): array
{
    /** @var array<string, array{jam_mulai:string,jam_selesai:string,hari:array<int,int>}> $groups */
    $groups = [];
    foreach ($slots as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        $jm = substr((string) ($sl['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($sl['jam_selesai'] ?? ''), 0, 5);
        if ($h < 1 || $h > 7 || $jm === '' || $js === '') {
            continue;
        }
        $key = $jm . '|' . $js;
        if (!isset($groups[$key])) {
            $groups[$key] = ['jam_mulai' => $jm, 'jam_selesai' => $js, 'hari' => []];
        }
        $groups[$key]['hari'][$h] = $h;
    }
    $out = [];
    foreach ($groups as $g) {
        $hari = array_values($g['hari']);
        sort($hari, SORT_NUMERIC);
        $out[] = [
            'hari' => $hari,
            'jam_mulai' => $g['jam_mulai'],
            'jam_selesai' => $g['jam_selesai'],
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $minA = $a['hari'] !== [] ? min($a['hari']) : 99;
        $minB = $b['hari'] !== [] ? min($b['hari']) : 99;
        if ($minA !== $minB) {
            return $minA <=> $minB;
        }

        return strcmp((string) $a['jam_mulai'], (string) $b['jam_mulai']);
    });

    return $out;
}

/** Expand blok form menjadi slot per hari (untuk overlap kegiatan). */
function santri_izin_tetap_blok_form_ke_slots(array $bloks): array
{
    $slots = [];
    foreach ($bloks as $blok) {
        if (!is_array($blok)) {
            continue;
        }
        $jm = trim((string) ($blok['jam_mulai'] ?? ''));
        $js = trim((string) ($blok['jam_selesai'] ?? ''));
        if ($jm === '' || $js === '') {
            continue;
        }
        foreach ((array) ($blok['hari'] ?? []) as $hariRaw) {
            $hari = (int) $hariRaw;
            if ($hari < 1 || $hari > 7) {
                continue;
            }
            $slots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
        }
    }

    return $slots;
}

/** Ringkas jadwal slot untuk tampilan tabel. */
function santri_izin_tetap_slot_ringkas(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $parts = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        $jm = substr((string) ($sl['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($sl['jam_selesai'] ?? ''), 0, 5);
        $parts[] = ($hariMap[$h] ?? '?') . ' ' . $jm . '–' . $js;
    }

    return $parts !== [] ? implode('; ', $parts) : '—';
}

/** Format hari hidmah untuk surat cetak (tanpa jam — santri tetap di lingkungan pondok). */
function santri_izin_tetap_slot_hari_html(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $days = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        if ($h >= 1 && $h <= 7) {
            $days[$h] = $hariMap[$h] ?? '?';
        }
    }
    ksort($days);

    return $days !== [] ? implode(', ', array_map('htmlspecialchars', array_values($days))) : '—';
}

/** Hari hidmah surat cetak — tampil berdampingan kiri-kanan agar rapi. */
function santri_izin_tetap_slot_hari_surat_html(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $days = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        if ($h >= 1 && $h <= 7) {
            $days[$h] = $hariMap[$h] ?? '?';
        }
    }
    ksort($days);
    if ($days === []) {
        return '—';
    }

    $items = [];
    foreach (array_values($days) as $namaHari) {
        $items[] = '<span class="hari-surat-item">' . htmlspecialchars((string) $namaHari) . '</span>';
    }

    return '<span class="hari-surat-row">' . implode('', $items) . '</span>';
}

/** Format jadwal slot untuk surat cetak (satu baris per hari). */
function santri_izin_tetap_slot_html(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $lines = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        $jm = substr((string) ($sl['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($sl['jam_selesai'] ?? ''), 0, 5);
        $lines[] = ($hariMap[$h] ?? '?') . ', pukul ' . $jm . ' s.d. ' . $js . ' WIB';
    }

    return $lines !== [] ? implode('<br>', array_map('htmlspecialchars', $lines)) : '—';
}

/** Teks tampilan surat: bersihkan garis miring & spasi berlebih. */
function santri_izin_tetap_surat_teks_bersih(string $raw): string
{
    $t = trim($raw);
    if ($t === '') {
        return '';
    }
    $t = rtrim($t, '/\\');
    $t = preg_replace('#\s*/\s*#', ', ', $t) ?? $t;
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;

    return trim($t);
}

/** Detail uraian tanpa awalan hidmah/tugas (untuk tabel surat). */
function santri_izin_tetap_surat_detail_teks(string $judul, bool $isTugas): string
{
    $judul = santri_izin_tetap_surat_teks_bersih($judul);
    if ($judul === '') {
        return '';
    }
    $j = strtolower($judul);
    if ($isTugas) {
        if (str_starts_with($j, 'tugas ke ')) {
            return trim(substr($judul, 8));
        }
        if (str_starts_with($j, 'tugas ')) {
            return trim(substr($judul, 5));
        }

        return $judul;
    }
    if (str_starts_with($j, 'hidmah sebagai ')) {
        return trim(substr($judul, 14));
    }
    if (str_starts_with($j, 'sebagai ')) {
        return trim(substr($judul, 7));
    }
    if (str_starts_with($j, 'hidmah ')) {
        return trim(substr($judul, 6));
    }

    return $judul;
}

/** Kalimat uraian resmi di paragraf surat: hidmah sebagai … / tugas ke … */
function santri_izin_tetap_surat_kalimat_uraian(string $jenis, string $judul): string
{
    $isTugas = strtoupper(trim($jenis)) === 'TUGAS';
    $detail = santri_izin_tetap_surat_detail_teks($judul, $isTugas);
    if ($detail === '') {
        return $isTugas ? 'tugas santri' : 'hidmah santri';
    }

    return $isTugas ? ('tugas ke ' . $detail) : ('hidmah sebagai ' . $detail);
}

/**
 * @return array{
 *   is_tugas:bool,
 *   jenis_label:string,
 *   uraian_kalimat:string,
 *   detail_teks:string,
 *   label_uraian:string,
 *   label_jadwal:string,
 *   label_kegiatan_box:string
 * }
 */
function santri_izin_tetap_surat_konteks(string $jenis, string $judul): array
{
    $isTugas = strtoupper(trim($jenis)) === 'TUGAS';

    $detail = santri_izin_tetap_surat_detail_teks($judul, $isTugas);

    return [
        'is_tugas' => $isTugas,
        'jenis_label' => santri_izin_tetap_jenis_label($jenis),
        'uraian_kalimat' => santri_izin_tetap_surat_kalimat_uraian($jenis, $judul),
        'detail_teks' => $detail,
        'label_uraian' => $isTugas ? 'Tujuan Tugas' : 'Uraian Hidmah',
        'label_jadwal' => $isTugas ? 'Hari & Waktu Tugas' : 'Hari & Waktu Hidmah',
        'label_kegiatan_box' => $isTugas ? 'Kegiatan tidak diikuti' : 'Kegiatan Jama\'ah tidak diikuti',
    ];
}

/** Daftar nama kegiatan ringkas untuk surat (nama saja, pisah koma). */
function santri_izin_tetap_kegiatan_nama_tampil(string $raw): string
{
    return implode(', ', santri_izin_tetap_kegiatan_items_dari_raw($raw));
}

/** @return list<string> */
function santri_izin_tetap_kegiatan_items_dari_raw(string $raw): array
{
    $parts = [];
    foreach (preg_split('/[\n,;]+/', trim($raw)) ?: [] as $p) {
        $t = santri_izin_tetap_surat_teks_bersih((string) $p);
        if ($t !== '') {
            $parts[$t] = $t;
        }
    }

    return array_values($parts);
}

/**
 * @return array<string, mixed>|null
 */
function santri_izin_tetap_for_print(PDO $pdo, int $id): ?array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0 || !table_exists($pdo, 'santri')) {
        return null;
    }
    $tingExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $stmt = $pdo->prepare("
        SELECT i.*, s.nis, {$nameExpr} AS nama_santri, {$tingExpr} AS tingkatan
        FROM santri_izin_tetap i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Gabungkan beberapa izin tetap dalam satu kelompok cetak (ID terkecil = kelompok_id). */
function santri_izin_tetap_assign_kelompok(PDO $pdo, array $ids): int
{
    ensure_santri_izin_tetap_tables($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    if ($ids === []) {
        return 0;
    }
    if (count($ids) === 1) {
        return $ids[0];
    }
    sort($ids, SORT_NUMERIC);
    $leader = $ids[0];
    $st = $pdo->prepare('UPDATE santri_izin_tetap SET kelompok_id = :kid WHERE id = :id');
    foreach ($ids as $id) {
        $st->execute(['kid' => $leader, 'id' => $id]);
    }

    return $leader;
}

/** @return list<int> */
function santri_izin_tetap_ids_dari_get(array $get): array
{
    $raw = trim((string) ($get['ids'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $ids = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function santri_izin_tetap_surat_gabungan_href(int $kelompokId): string
{
    return app_href('/perizinan/surat_izin_tetap.php?kelompok_id=' . $kelompokId);
}

/**
 * Pengaturan spasi cetak A4 — semakin banyak santri, semakin rapat (tetap 1 lembar).
 *
 * @return array{
 *   class:string,
 *   page_margin:string,
 *   sheet_padding:string,
 *   logo_px:int,
 *   sign_mm:int,
 *   ringkas:bool,
 *   tabel_cols:int
 * }
 */
function santri_izin_tetap_surat_density_config(int $totalSantri, bool $isGabungan): array
{
    if (!$isGabungan || $totalSantri <= 2) {
        return [
            'class' => '',
            'page_margin' => '12mm',
            'sheet_padding' => '12mm 14mm',
            'logo_px' => 72,
            'sign_mm' => 18,
            'ringkas' => false,
            'tabel_cols' => 1,
        ];
    }
    if ($totalSantri <= 5) {
        return [
            'class' => 'sheet--compact',
            'page_margin' => '10mm',
            'sheet_padding' => '8mm 10mm',
            'logo_px' => 58,
            'sign_mm' => 14,
            'ringkas' => true,
            'tabel_cols' => 1,
        ];
    }
    if ($totalSantri <= 10) {
        return [
            'class' => 'sheet--dense',
            'page_margin' => '8mm',
            'sheet_padding' => '6mm 8mm',
            'logo_px' => 50,
            'sign_mm' => 12,
            'ringkas' => true,
            'tabel_cols' => 1,
        ];
    }

    return [
        'class' => 'sheet--extra-dense',
        'page_margin' => '7mm',
        'sheet_padding' => '5mm 7mm',
        'logo_px' => 44,
        'sign_mm' => 10,
        'ringkas' => true,
        'tabel_cols' => 2,
    ];
}

/** Alihkan cetak perorangan ke surat gabungan jika santri bagian kelompok (>1). */
function santri_izin_tetap_redirect_gabungan_jika_perlu(PDO $pdo, array $anggotaRows, int $kelompokIdParam, array $idsParam): void
{
    if ($kelompokIdParam > 0 || $idsParam !== [] || count($anggotaRows) !== 1) {
        return;
    }
    $row = $anggotaRows[0];
    $kid = (int) ($row['kelompok_id'] ?? 0);
    if ($kid <= 0) {
        return;
    }
    $kelompokRows = santri_izin_tetap_anggota_by_kelompok($pdo, $kid);
    if (count($kelompokRows) > 1) {
        header('Location: ' . santri_izin_tetap_surat_gabungan_href($kid));
        exit;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_anggota_rows(PDO $pdo, string $whereSql, array $params): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    if (!function_exists('perizinan_rombongan_order_sql')) {
        require_once __DIR__ . '/perizinan_rombongan.php';
    }
    $tingExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $st = $pdo->prepare("
        SELECT i.*, s.nis, {$nameExpr} AS nama_santri, {$tingExpr} AS tingkatan
        FROM santri_izin_tetap i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE {$whereSql}
        ORDER BY " . perizinan_rombongan_order_sql('s', $pdo) . '
    ');
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return is_array($rows) ? $rows : [];
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_anggota_by_kelompok(PDO $pdo, int $kelompokId): array
{
    if ($kelompokId <= 0) {
        return [];
    }

    return santri_izin_tetap_anggota_rows(
        $pdo,
        'i.kelompok_id = :kid OR i.id = :kid2',
        ['kid' => $kelompokId, 'kid2' => $kelompokId]
    );
}

/**
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_anggota_by_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    return santri_izin_tetap_anggota_rows($pdo, 'i.id IN (' . $placeholders . ')', $ids);
}

/**
 * @param list<array<string, mixed>> $anggotaRows
 * @return list<string>
 */
function santri_izin_tetap_kegiatan_items_for_print_gabungan(PDO $pdo, array $anggotaRows): array
{
    $merged = [];
    foreach ($anggotaRows as $row) {
        foreach (santri_izin_tetap_kegiatan_items_for_print($pdo, $row) as $nama) {
            $t = trim((string) $nama);
            if ($t !== '') {
                $merged[$t] = $t;
            }
        }
    }

    return array_values($merged);
}

/** @return array{ok:bool,message:string,anggota?:list<array<string, mixed>>} */
function santri_izin_tetap_validasi_cetak_gabungan(array $anggotaRows): array
{
    if ($anggotaRows === []) {
        return ['ok' => false, 'message' => 'Data izin tetap tidak ditemukan.'];
    }
    $nonaktif = [];
    foreach ($anggotaRows as $row) {
        if ((int) ($row['is_aktif'] ?? 0) !== 1) {
            $nonaktif[] = (string) ($row['nama_santri'] ?? $row['nis'] ?? 'Santri');
        }
    }
    if ($nonaktif !== []) {
        return [
            'ok' => false,
            'message' => 'Surat gabungan belum dapat dicetak. Izin nonaktif: ' . implode(', ', $nonaktif) . '.',
        ];
    }

    return ['ok' => true, 'message' => '', 'anggota' => $anggotaRows];
}
