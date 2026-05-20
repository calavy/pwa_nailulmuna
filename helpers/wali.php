<?php

declare(strict_types=1);

/**
 * Profil wali (terhubung opsional ke users untuk akun aplikasi).
 * Kolom inti: id, nama, no_wa, alamat, nomor_id, user_id, created_at.
 */

function wali_santri_normalize_wa_digits(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);

    return $digits ?? '';
}

function wali_santri_wa_digits_compatible(string $a, string $b): bool
{
    if ($a === '' && $b === '') {
        return true;
    }
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $minLen = 9;
    if (strlen($a) >= $minLen && strlen($b) >= $minLen) {
        return str_ends_with($a, $b) || str_ends_with($b, $a);
    }

    return false;
}

function wali_santri_merge_address_from_santri_row(array $row): ?string
{
    $parts = array_filter([
        trim((string) ($row['dusun'] ?? '')),
        trim((string) ($row['rt_rw'] ?? '')),
        trim((string) ($row['desa_kelurahan'] ?? '')),
        trim((string) ($row['kecamatan'] ?? '')),
        trim((string) ($row['kabupaten'] ?? '')),
        trim((string) ($row['propinsi'] ?? '')),
    ], static fn(string $s): bool => $s !== '');
    if ($parts === []) {
        return null;
    }

    return implode(', ', $parts);
}

/** @return null|int ID wali yang cocok dengan nama kafil & pola nomor WA */
function wali_santri_find_id_by_nama_and_wa(PDO $pdo, string $namaKafil, string $waDigits): ?int
{
    $st = $pdo->prepare('SELECT id, no_wa FROM wali_santri WHERE LOWER(TRIM(nama)) = LOWER(TRIM(:nama))');
    $st->execute(['nama' => mb_substr($namaKafil, 0, 120)]);
    $matchBothEmpty = null;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rw = wali_santri_normalize_wa_digits((string) ($r['no_wa'] ?? ''));
        if ($waDigits !== '' && wali_santri_wa_digits_compatible($waDigits, $rw)) {
            return (int) $r['id'];
        }
        if ($waDigits === '' && $rw === '') {
            if ($matchBothEmpty === null) {
                $matchBothEmpty = (int) $r['id'];
            }
        }
    }

    return $waDigits === '' ? $matchBothEmpty : null;
}

function wali_santri_ensure_automatic_nomor(PDO $pdo, int $waliId): void
{
    if (!column_exists($pdo, 'wali_santri', 'nomor_id')) {
        return;
    }
    $chk = $pdo->prepare('SELECT id, nomor_id FROM wali_santri WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $waliId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $current = trim((string) ($row['nomor_id'] ?? ''));
    if ($current !== '') {
        return;
    }
    $slug = 'WS-' . str_pad((string) $waliId, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE wali_santri SET nomor_id = :n WHERE id = :id')->execute(['n' => $slug, 'id' => $waliId]);
}

function ensure_wali_santri_extra_columns(PDO $pdo): void
{
    if (!table_exists($pdo, 'wali_santri')) {
        return;
    }
    if (!column_exists($pdo, 'wali_santri', 'nomor_id')) {
        try {
            $pdo->exec('ALTER TABLE wali_santri ADD COLUMN nomor_id VARCHAR(40) NULL');
        } catch (PDOException $e) {
            $m = strtolower($e->getMessage());
            if (!str_contains($m, 'duplicate') && !str_contains($m, '1060')) {
                throw $e;
            }
        }
    }
    try {
        $pdo->exec('CREATE UNIQUE INDEX uk_wali_santri_nomor_id ON wali_santri (nomor_id)');
    } catch (PDOException $e) {
        $m = strtolower($e->getMessage());
        if (!str_contains($m, 'duplicate') && !str_contains($m, '1061') && !str_contains($m, 'exists')) {
            throw $e;
        }
    }
}

function wali_santri_backfill_nomor_id(PDO $pdo): void
{
    if (!table_exists($pdo, 'wali_santri') || !column_exists($pdo, 'wali_santri', 'nomor_id')) {
        return;
    }
    $pdo->exec("UPDATE wali_santri SET nomor_id = CONCAT('WS-', LPAD(id, 6, '0')) WHERE nomor_id IS NULL OR TRIM(COALESCE(nomor_id, '')) = ''");
}

function ensure_wali_santri_table(PDO $pdo): void
{
    if (!table_exists($pdo, 'users')) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS wali_santri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(120) NOT NULL,
            no_wa VARCHAR(40) NULL,
            alamat TEXT NULL,
            user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wali_santri_user (user_id),
            CONSTRAINT fk_wali_santri_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )
    ');
    ensure_wali_santri_extra_columns($pdo);
    wali_santri_backfill_nomor_id($pdo);
}

/**
 * Hapus baris wali_santri bila tidak ada santri yang menaut dan tidak terhubung ke akun users
 * (profil wali manual yang dipakai login pengurus tetap dipertahankan walau belum ada santri).
 */
function wali_santri_prune_if_orphan(PDO $pdo, int $waliId): void
{
    if ($waliId <= 0 || !table_exists($pdo, 'wali_santri')) {
        return;
    }
    $st = $pdo->prepare('SELECT id, user_id FROM wali_santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $waliId]);
    $w = $st->fetch(PDO::FETCH_ASSOC);
    if (!$w) {
        return;
    }
    if (!empty($w['user_id'])) {
        return;
    }
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM santri WHERE wali_santri_id = :id');
        $c->execute(['id' => $waliId]);
        if ((int) $c->fetchColumn() > 0) {
            return;
        }
    }
    $pdo->prepare('DELETE FROM wali_santri WHERE id = :id')->execute(['id' => $waliId]);
}

/**
 * @param null|int $newWaliId null = lepas tautan
 */
function santri_set_wali_santri_id_and_prune_previous(PDO $pdo, int $santriId, int $prevWaliId, ?int $newWaliId): void
{
    if (!column_exists($pdo, 'santri', 'wali_santri_id')) {
        return;
    }
    $pdo->prepare('UPDATE santri SET wali_santri_id = :w WHERE id = :id')->execute([
        'w' => $newWaliId,
        'id' => $santriId,
    ]);
    $next = $newWaliId ?? 0;
    if ($prevWaliId > 0 && $prevWaliId !== $next) {
        wali_santri_prune_if_orphan($pdo, $prevWaliId);
    }
}

/** Buat baris wali_santri baru; kembalikan id. */
function wali_santri_insert_profile(PDO $pdo, string $nama, string $waDigits, ?string $alamat): int
{
    $noWaStore = $waDigits !== '' ? mb_substr($waDigits, 0, 40) : null;
    $ins = $pdo->prepare('INSERT INTO wali_santri (nama, no_wa, alamat, user_id) VALUES (:nama, :no_wa, :alamat, NULL)');
    $ins->execute([
        'nama' => mb_substr($nama, 0, 120),
        'no_wa' => $noWaStore,
        'alamat' => $alamat,
    ]);
    $newWaliId = (int) $pdo->lastInsertId();
    if ($newWaliId > 0) {
        wali_santri_ensure_automatic_nomor($pdo, $newWaliId);
    }

    return $newWaliId;
}

/**
 * Setelah simpan data santri aktif: isi / perbarui tautan ke wali_santri.
 * Prioritas: (1) kafil valid, (2) no WA wali + nama orang tua / fallback, (3) kontak ayah, (4) kontak ibu.
 * Santri non aktif: tautan dilepas; baris wali otomatis dihapus jika tidak dipakai lagi (kecuali terhubung user).
 */
function sync_santri_wali_from_kafil(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return;
    }
    ensure_wali_santri_table($pdo);
    if (function_exists('ensure_santri_identity_columns')) {
        ensure_santri_identity_columns($pdo);
    }
    if (!column_exists($pdo, 'santri', 'wali_santri_id')) {
        return;
    }

    $cols = [
        'id', 'wali_santri_id', 'status_santri', 'is_aktif', 'nama_santri', 'nis',
        'nama_kafil', 'status_kafil', 'no_kontak_kafil',
        'no_wa_wali', 'nama_ayah', 'no_kontak_ayah', 'nama_ibu', 'no_kontak_ibu',
        'dusun', 'rt_rw', 'desa_kelurahan', 'kecamatan', 'kabupaten', 'propinsi',
    ];
    $q = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
    $q->execute(['id' => $santriId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $prevWaliId = (int) ($row['wali_santri_id'] ?? 0);
    $statusSantri = strtoupper(trim((string) ($row['status_santri'] ?? 'AKTIF')));
    $aktif = $statusSantri === 'AKTIF' && (int) ($row['is_aktif'] ?? 1) === 1;
    if (!$aktif) {
        santri_set_wali_santri_id_and_prune_previous($pdo, $santriId, $prevWaliId, null);

        return;
    }

    $newWaliId = null;

    $namaKafilCol = column_exists($pdo, 'santri', 'nama_kafil');
    $namaKafil = $namaKafilCol ? trim((string) ($row['nama_kafil'] ?? '')) : '';
    $statusKafil = $namaKafilCol ? trim((string) ($row['status_kafil'] ?? '')) : '';
    $kafilUsable = $namaKafil !== '' && strcasecmp($statusKafil, 'Tidak Ada') !== 0;

    if ($kafilUsable) {
        $waDigits = wali_santri_normalize_wa_digits((string) ($row['no_kontak_kafil'] ?? ''));
        $existingId = wali_santri_find_id_by_nama_and_wa($pdo, $namaKafil, $waDigits);
        if ($existingId !== null) {
            $newWaliId = $existingId;
        } else {
            $alamat = wali_santri_merge_address_from_santri_row($row);
            $newWaliId = wali_santri_insert_profile($pdo, $namaKafil, $waDigits, $alamat);
            if ($newWaliId <= 0) {
                $newWaliId = null;
            }
        }
    }

    if ($newWaliId === null || $newWaliId <= 0) {
        $namaSantri = trim((string) ($row['nama_santri'] ?? ''));
        if ($namaSantri === '') {
            $namaSantri = 'Santri';
        }
        $waMain = column_exists($pdo, 'santri', 'no_wa_wali')
            ? wali_santri_normalize_wa_digits((string) ($row['no_wa_wali'] ?? ''))
            : '';
        $namaAyah = trim((string) ($row['nama_ayah'] ?? ''));
        $waAyah = wali_santri_normalize_wa_digits((string) ($row['no_kontak_ayah'] ?? ''));
        $namaIbu = trim((string) ($row['nama_ibu'] ?? ''));
        $waIbu = wali_santri_normalize_wa_digits((string) ($row['no_kontak_ibu'] ?? ''));

        $pairs = [];
        if ($waMain !== '') {
            if ($namaAyah !== '') {
                $pairs[] = [$namaAyah, $waMain];
            }
            if ($namaIbu !== '') {
                $pairs[] = [$namaIbu, $waMain];
            }
            $pairs[] = ['Wali — ' . mb_substr($namaSantri, 0, 100), $waMain];
        }
        if ($namaAyah !== '' && $waAyah !== '') {
            $pairs[] = [$namaAyah, $waAyah];
        }
        if ($namaIbu !== '' && $waIbu !== '') {
            $pairs[] = [$namaIbu, $waIbu];
        }

        foreach ($pairs as [$namaW, $waD]) {
            $namaW = trim((string) $namaW);
            $waD = (string) $waD;
            if ($namaW === '') {
                continue;
            }
            $found = wali_santri_find_id_by_nama_and_wa($pdo, $namaW, $waD);
            if ($found !== null) {
                $newWaliId = $found;
                break;
            }
        }

        if ($newWaliId === null || $newWaliId <= 0) {
            foreach ($pairs as [$namaW, $waD]) {
                $namaW = trim((string) $namaW);
                $waD = (string) $waD;
                if ($namaW === '') {
                    continue;
                }
                $alamat = wali_santri_merge_address_from_santri_row($row);
                $newWaliId = wali_santri_insert_profile($pdo, $namaW, $waD, $alamat);
                if ($newWaliId > 0) {
                    break;
                }
            }
        }
    }

    if ($newWaliId === null || $newWaliId <= 0) {
        santri_set_wali_santri_id_and_prune_previous($pdo, $santriId, $prevWaliId, null);

        return;
    }

    santri_set_wali_santri_id_and_prune_previous($pdo, $santriId, $prevWaliId, $newWaliId);
}
