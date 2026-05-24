<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_riwayat.php';

function ensure_santri_nilai_keaktifan_table(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri')) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_nilai_keaktifan (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tahun SMALLINT NOT NULL,
            nilai ENUM("BAIK","SEDANG","BURUK") NOT NULL,
            catatan VARCHAR(500) NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_santri_nilai_keaktifan_th (santri_id, tahun),
            INDEX idx_snk_tahun (tahun),
            CONSTRAINT fk_snk_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/** @return list<string> */
function santri_keaktifan_nilai_kode_options(): array
{
    return ['BAIK', 'SEDANG', 'BURUK'];
}

function santri_keaktifan_nilai_normalize_kode(string $raw): ?string
{
    $k = strtoupper(trim($raw));
    if ($k === 'BAGUS') {
        $k = 'BAIK';
    }

    return in_array($k, santri_keaktifan_nilai_kode_options(), true) ? $k : null;
}

function santri_keaktifan_nilai_label_dari_kode(string $kode): string
{
    return match (santri_keaktifan_nilai_normalize_kode($kode) ?? '') {
        'BAIK' => 'Baik',
        'SEDANG' => 'Sedang',
        default => 'Buruk',
    };
}

/** @return array<string, string> kode => label tampilan */
function santri_keaktifan_nilai_pilihan_form(): array
{
    return [
        'BAIK' => 'Baik',
        'SEDANG' => 'Sedang',
        'BURUK' => 'Buruk',
    ];
}

/**
 * @return array<int, array{nilai:string,label:string,catatan:string,updated_at:string|null}>
 */
function santri_keaktifan_nilai_map_for_santri(PDO $pdo, int $santriId): array
{
    ensure_santri_nilai_keaktifan_table($pdo);
    if ($santriId <= 0 || !table_exists($pdo, 'santri_nilai_keaktifan')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT tahun, nilai, catatan, updated_at
        FROM santri_nilai_keaktifan
        WHERE santri_id = :sid
        ORDER BY tahun DESC
    ');
    $st->execute(['sid' => $santriId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $th = (int) ($row['tahun'] ?? 0);
        if ($th <= 0) {
            continue;
        }
        $kode = (string) ($row['nilai'] ?? '');
        $out[$th] = [
            'nilai' => $kode,
            'label' => santri_keaktifan_nilai_label_dari_kode($kode),
            'catatan' => trim((string) ($row['catatan'] ?? '')),
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    return $out;
}

/**
 * @return array<int, array{nilai:string,label:string,catatan:string}>
 */
function santri_keaktifan_nilai_map_for_tahun(PDO $pdo, int $tahun): array
{
    ensure_santri_nilai_keaktifan_table($pdo);
    if ($tahun <= 0 || !table_exists($pdo, 'santri_nilai_keaktifan')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT santri_id, nilai, catatan
        FROM santri_nilai_keaktifan
        WHERE tahun = :th
    ');
    $st->execute(['th' => $tahun]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kode = (string) ($row['nilai'] ?? '');
        $out[$sid] = [
            'nilai' => $kode,
            'label' => santri_keaktifan_nilai_label_dari_kode($kode),
            'catatan' => trim((string) ($row['catatan'] ?? '')),
        ];
    }

    return $out;
}

function santri_keaktifan_nilai_save(
    PDO $pdo,
    int $santriId,
    int $tahun,
    string $nilaiKode,
    ?string $catatan,
    ?int $updatedBy
): bool {
    ensure_santri_nilai_keaktifan_table($pdo);
    $kode = santri_keaktifan_nilai_normalize_kode($nilaiKode);
    if ($santriId <= 0 || $tahun < 2000 || $tahun > 2100 || $kode === null) {
        return false;
    }
    $cat = $catatan !== null && trim($catatan) !== '' ? mb_substr(trim($catatan), 0, 500) : null;
    $pdo->prepare('
        INSERT INTO santri_nilai_keaktifan (santri_id, tahun, nilai, catatan, updated_by)
        VALUES (:sid, :th, :nilai, :cat, :uid)
        ON DUPLICATE KEY UPDATE
            nilai = VALUES(nilai),
            catatan = VALUES(catatan),
            updated_by = VALUES(updated_by)
    ')->execute([
        'sid' => $santriId,
        'th' => $tahun,
        'nilai' => $kode,
        'cat' => $cat,
        'uid' => $updatedBy > 0 ? $updatedBy : null,
    ]);

    return true;
}

function santri_keaktifan_nilai_hapus(PDO $pdo, int $santriId, int $tahun): void
{
    ensure_santri_nilai_keaktifan_table($pdo);
    if ($santriId <= 0 || $tahun <= 0 || !table_exists($pdo, 'santri_nilai_keaktifan')) {
        return;
    }
    $pdo->prepare('DELETE FROM santri_nilai_keaktifan WHERE santri_id = :sid AND tahun = :th')
        ->execute(['sid' => $santriId, 'th' => $tahun]);
}

/**
 * Gabungan presensi otomatis + penilaian pengasuh (pengasuh menimpa label).
 *
 * @return list<array<string, mixed>>
 */
function santri_keaktifan_tampilan_per_tahun(PDO $pdo, int $santriId): array
{
    $presensiRows = santri_riwayat_keaktifan_per_tahun($pdo, $santriId);
    $manual = santri_keaktifan_nilai_map_for_santri($pdo, $santriId);
    $byTh = [];

    foreach ($presensiRows as $row) {
        $th = (int) ($row['th'] ?? 0);
        if ($th <= 0) {
            continue;
        }
        if (isset($manual[$th])) {
            $row['label'] = $manual[$th]['label'];
            $row['kategori'] = $manual[$th]['nilai'];
            $row['sumber'] = 'pengasuh';
            $row['catatan_pengasuh'] = $manual[$th]['catatan'];
            $row['keterangan'] = santri_keaktifan_nilai_keterangan_tampilan($row, true);
        } else {
            $row['sumber'] = 'presensi';
            $row['catatan_pengasuh'] = '';
        }
        $byTh[$th] = $row;
    }

    foreach ($manual as $th => $m) {
        if (isset($byTh[$th])) {
            continue;
        }
        $byTh[$th] = [
            'th' => $th,
            'hadir' => 0,
            'izin' => 0,
            'sakit' => 0,
            'alpa' => 0,
            'total' => 0,
            'persen_hadir' => 0.0,
            'kategori' => $m['nilai'],
            'label' => $m['label'],
            'sumber' => 'pengasuh',
            'catatan_pengasuh' => $m['catatan'],
            'keterangan' => santri_keaktifan_nilai_keterangan_tampilan([
                'label' => $m['label'],
                'catatan_pengasuh' => $m['catatan'],
            ], true),
        ];
    }

    krsort($byTh, SORT_NUMERIC);

    return array_values($byTh);
}

/** Keaktifan satu tahun (gabungan); null jika tidak ada data. */
function santri_keaktifan_tampilan_tahun(PDO $pdo, int $santriId, int $tahun): ?array
{
    foreach (santri_keaktifan_tampilan_per_tahun($pdo, $santriId) as $row) {
        if ((int) ($row['th'] ?? 0) === $tahun) {
            return $row;
        }
    }

    return null;
}

/** @param array<string, mixed> $row */
function santri_keaktifan_nilai_keterangan_tampilan(array $row, bool $dariPengasuh): string
{
    if ($dariPengasuh) {
        $cat = trim((string) ($row['catatan_pengasuh'] ?? ''));
        $base = 'Penilaian pengasuh pondok: ' . (string) ($row['label'] ?? '');
        if ($cat !== '') {
            return $base . ' — ' . $cat;
        }

        return $base;
    }

    return (string) ($row['keterangan'] ?? '');
}
