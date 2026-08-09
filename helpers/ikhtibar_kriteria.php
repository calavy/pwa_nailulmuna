<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ikhtibar_kriteria_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ikhtibar_kriteria_penilaian (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(40) NOT NULL,
            label VARCHAR(120) NOT NULL,
            bobot_persen DECIMAL(5,2) NOT NULL DEFAULT 25.00,
            urutan SMALLINT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_ikhtibar_kriteria_kode (kode),
            KEY idx_ikhtibar_kriteria_aktif (is_aktif, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    if (function_exists('akademik_add_column')) {
        require_once __DIR__ . '/akademik.php';
        akademik_add_column($pdo, 'ikhtibar_soal', 'bobot_nilai', 'DECIMAL(6,2) NOT NULL DEFAULT 100.00');
        akademik_add_column($pdo, 'ikhtibar_soal', 'pg_jumlah_opsi', 'TINYINT NULL DEFAULT 4');
        akademik_add_column($pdo, 'ikhtibar_jawaban', 'nilai_otomatis', 'DECIMAL(6,2) NULL');
        akademik_add_column($pdo, 'ikhtibar_jawaban', 'detail_kriteria_json', 'TEXT NULL');
    }

    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM ikhtibar_kriteria_penilaian')->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['KELENGKAPAN', 'Kelengkapan jawaban', 25, 1],
            ['KETEPATAN', 'Ketepatan isi', 25, 2],
            ['STRUKTUR', 'Struktur & sistematika', 25, 3],
            ['BAHASA', 'Bahasa & redaksi', 25, 4],
        ];
        $ins = $pdo->prepare('
            INSERT INTO ikhtibar_kriteria_penilaian (kode, label, bobot_persen, urutan, is_aktif)
            VALUES (:k, :l, :b, :u, 1)
        ');
        foreach ($seed as [$kode, $label, $bobot, $urutan]) {
            $ins->execute(['k' => $kode, 'l' => $label, 'b' => $bobot, 'u' => $urutan]);
        }
    }
}

/** @return list<array<string,mixed>> */
function ikhtibar_kriteria_list(PDO $pdo, bool $aktifOnly = true): array
{
    ikhtibar_kriteria_ensure_schema($pdo);
    $sql = 'SELECT * FROM ikhtibar_kriteria_penilaian';
    if ($aktifOnly) {
        $sql .= ' WHERE is_aktif = 1';
    }
    $sql .= ' ORDER BY urutan ASC, id ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array{kode:string,label:string,bobot_persen:float,urutan:int,is_aktif:int}> $rows
 */
function ikhtibar_kriteria_simpan_batch(PDO $pdo, array $rows): array
{
    ikhtibar_kriteria_ensure_schema($pdo);
    if ($rows === []) {
        return ['ok' => false, 'message' => 'Tidak ada data kriteria.'];
    }
    $totalBobot = 0.0;
    foreach ($rows as $row) {
        $totalBobot += (float) ($row['bobot_persen'] ?? 0);
    }
    if (abs($totalBobot - 100.0) > 0.01) {
        return ['ok' => false, 'message' => 'Total bobot kriteria harus 100% (saat ini ' . round($totalBobot, 1) . '%).'];
    }

    $upd = $pdo->prepare('
        UPDATE ikhtibar_kriteria_penilaian
        SET label = :l, bobot_persen = :b, urutan = :u, is_aktif = :a
        WHERE kode = :k
    ');
    $ins = $pdo->prepare('
        INSERT INTO ikhtibar_kriteria_penilaian (kode, label, bobot_persen, urutan, is_aktif)
        VALUES (:k, :l, :b, :u, :a)
    ');

    foreach ($rows as $row) {
        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
        if ($kode === '' || !preg_match('/^[A-Z0-9_]{2,40}$/', $kode)) {
            return ['ok' => false, 'message' => 'Kode kriteria tidak valid: ' . $kode];
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'message' => 'Label kriteria wajib diisi.'];
        }
        $params = [
            'k' => $kode,
            'l' => $label,
            'b' => max(0, min(100, (float) ($row['bobot_persen'] ?? 0))),
            'u' => (int) ($row['urutan'] ?? 0),
            'a' => !empty($row['is_aktif']) ? 1 : 0,
        ];
        $chk = $pdo->prepare('SELECT id FROM ikhtibar_kriteria_penilaian WHERE kode = :k LIMIT 1');
        $chk->execute(['k' => $kode]);
        if ($chk->fetchColumn()) {
            $upd->execute($params);
        } else {
            $ins->execute($params);
        }
    }

    return ['ok' => true, 'message' => 'Kriteria penilaian disimpan.'];
}

/**
 * Parse kunci esai: baris [KODE] kata1, kata2 atau teks biasa (keyword dipisah koma).
 *
 * @return array<string, list<string>>
 */
function ikhtibar_parse_kunci_esai_kriteria(string $kunci): array
{
    $kunci = trim($kunci);
    if ($kunci === '') {
        return [];
    }
    if ($kunci[0] === '{') {
        $json = json_decode($kunci, true);
        if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
            $out = [];
            foreach ($json['items'] as $kode => $words) {
                $kode = strtoupper(trim((string) $kode));
                if ($kode === '') {
                    continue;
                }
                $out[$kode] = ikhtibar_kriteria_parse_keywords((string) $words);
            }

            return $out;
        }
    }

    $out = [];
    $lines = preg_split('/\R/u', $kunci) ?: [];
    $hasTag = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\[([A-Z0-9_]+)\]\s*(.+)$/u', $line, $m)) {
            $hasTag = true;
            $out[strtoupper($m[1])] = ikhtibar_kriteria_parse_keywords($m[2]);
        }
    }
    if ($hasTag) {
        return $out;
    }

    return ['UMUM' => ikhtibar_kriteria_parse_keywords($kunci)];
}

/** @return list<string> */
function ikhtibar_kriteria_parse_keywords(string $raw): array
{
    $parts = preg_split('/[,|;]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = mb_strtolower($p);
        }
    }

    return array_values(array_unique($out));
}

function ikhtibar_kriteria_normalize_text(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return $text;
}

/**
 * Hitung nilai otomatis esai berdasarkan kriteria aktif & kunci jawaban.
 *
 * @return array{nilai:float,detail:array<string,array{label:string,bobot:float,skor:float,found:int,total:int,keywords:list<string>}>}
 */
function ikhtibar_hitung_nilai_esai_otomatis(PDO $pdo, string $jawabanSantri, string $kunciJawaban, float $bobotSoal = 100.0): array
{
    ikhtibar_kriteria_ensure_schema($pdo);
    $kriteriaMap = ikhtibar_parse_kunci_esai_kriteria($kunciJawaban);
    $kriteriaRows = ikhtibar_kriteria_list($pdo, true);
    $jawabanNorm = ikhtibar_kriteria_normalize_text($jawabanSantri);
    $detail = [];
    $skorKriteria = 0.0;

    if ($kriteriaRows === []) {
        return ['nilai' => 0.0, 'detail' => []];
    }

    foreach ($kriteriaRows as $kr) {
        $kode = strtoupper((string) ($kr['kode'] ?? ''));
        $label = (string) ($kr['label'] ?? $kode);
        $bobotKr = (float) ($kr['bobot_persen'] ?? 0);
        $keywords = $kriteriaMap[$kode] ?? $kriteriaMap['UMUM'] ?? [];
        if ($keywords === [] && isset($kriteriaMap['UMUM'])) {
            $keywords = $kriteriaMap['UMUM'];
        }
        $found = 0;
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($jawabanNorm, $kw)) {
                $found++;
            }
        }
        $totalKw = max(1, count($keywords));
        $ratio = $found / $totalKw;
        $skorKr = round($bobotKr * $ratio, 2);
        $skorKriteria += $skorKr;
        $detail[$kode] = [
            'label' => $label,
            'bobot' => $bobotKr,
            'skor' => $skorKr,
            'found' => $found,
            'total' => count($keywords),
            'keywords' => $keywords,
        ];
    }

    $nilai = round($skorKriteria * ($bobotSoal / 100.0), 2);

    return ['nilai' => min(100.0, max(0.0, $nilai)), 'detail' => $detail];
}

function ikhtibar_terapkan_nilai_esai_otomatis(PDO $pdo, int $sesiId, int $soalId, string $jawabanSantri): void
{
    ikhtibar_kriteria_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT so.kunci_jawaban, so.bobot_nilai
        FROM ikhtibar_soal so
        WHERE so.id = :id AND so.jenis = "ESAI"
        LIMIT 1
    ');
    $st->execute(['id' => $soalId]);
    $soal = $st->fetch(PDO::FETCH_ASSOC);
    if (!$soal) {
        return;
    }
    $kunci = trim((string) ($soal['kunci_jawaban'] ?? ''));
    if ($kunci === '') {
        return;
    }
    $bobotSoal = (float) ($soal['bobot_nilai'] ?? 100);
    $hasil = ikhtibar_hitung_nilai_esai_otomatis($pdo, $jawabanSantri, $kunci, $bobotSoal);
    $detailJson = $hasil['detail'] !== [] ? json_encode($hasil['detail'], JSON_UNESCAPED_UNICODE) : null;

    $pdo->prepare('
        INSERT INTO ikhtibar_jawaban (sesi_id, soal_id, jawaban_santri, nilai_otomatis, nilai_esai, detail_kriteria_json, benar)
        VALUES (:s, :q, :j, :no, :ne, :d, NULL)
        ON DUPLICATE KEY UPDATE
            jawaban_santri = VALUES(jawaban_santri),
            nilai_otomatis = VALUES(nilai_otomatis),
            nilai_esai = COALESCE(nilai_esai, VALUES(nilai_esai)),
            detail_kriteria_json = VALUES(detail_kriteria_json)
    ')->execute([
        's' => $sesiId,
        'q' => $soalId,
        'j' => $jawabanSantri,
        'no' => $hasil['nilai'],
        'ne' => $hasil['nilai'],
        'd' => $detailJson,
    ]);
}
