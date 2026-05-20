<?php

declare(strict_types=1);

/**
 * Status santri terpusat — satu sumber untuk seluruh aplikasi.
 *
 * Nilai di database (kolom status_santri):
 * - AKTIF     : masih mondok sebagai santri
 * - NONAKTIF  : sudah tidak mondok (boyong, alumni, keluar — detail di keluar_kategori)
 * - KHIDMAH   : alumni / tamat yang tetap di pondok membantu (pengabdian)
 */

function santri_status_const_aktif(): string
{
    return 'AKTIF';
}

function santri_status_const_nonaktif(): string
{
    return 'NONAKTIF';
}

function santri_status_const_khidmah(): string
{
    return 'KHIDMAH';
}

/** @return list<string> */
function santri_status_options(): array
{
    return [
        santri_status_const_aktif(),
        santri_status_const_nonaktif(),
        santri_status_const_khidmah(),
    ];
}

/** Normalisasi nilai lama / alias ke status resmi. */
function santri_status_normalize(?string $raw, ?string $keluarKategori = null): string
{
    $s = strtoupper(trim((string) $raw));
    $s = str_replace([' ', '-'], '_', $s);

    if (in_array($s, santri_status_options(), true)) {
        return $s;
    }

    // Alias & data migrasi
    if (in_array($s, ['NON_AKTIF', 'NONAKTIF', 'BOYONG', 'ALUMNI', 'MUQIM', 'KELUAR'], true)) {
        return santri_status_const_nonaktif();
    }
    if (in_array($s, ['PENGABDIAN', 'PENGABDIAN_KHIDMAH', 'ABDI'], true)) {
        return santri_status_const_khidmah();
    }

    // Baris lama: status kosong / tidak dikenal — tebak dari keluar_kategori
    $kat = strtoupper(trim((string) $keluarKategori));
    if (in_array($kat, ['TAMAT', 'MUQIM'], true)) {
        return santri_status_const_nonaktif();
    }
    if (in_array($kat, ['KELUAR_PINDAH', 'BOYONG', 'KELUAR'], true)) {
        return santri_status_const_nonaktif();
    }

    return santri_status_const_aktif();
}

function santri_status_from_row(array $row): string
{
    return santri_status_normalize(
        (string) ($row['status_santri'] ?? 'AKTIF'),
        (string) ($row['keluar_kategori'] ?? '')
    );
}

function santri_status_label(string $status): string
{
    return match (santri_status_normalize($status)) {
        santri_status_const_nonaktif() => 'Nonaktif',
        santri_status_const_khidmah() => 'Khidmah',
        default => 'Aktif',
    };
}

function santri_status_badge_class(string $status): string
{
    return match (santri_status_normalize($status)) {
        santri_status_const_nonaktif() => 'text-bg-secondary',
        santri_status_const_khidmah() => 'text-bg-info',
        default => 'text-bg-success',
    };
}

/** Daftar Santri Aktif — hanya status Aktif. */
function santri_status_is_aktif_list(string $status): bool
{
    return santri_status_normalize($status) === santri_status_const_aktif();
}

/** Masih berada di pondok (Aktif atau Khidmah). */
function santri_status_is_di_pondok(string $status): bool
{
    return in_array(santri_status_normalize($status), [santri_status_const_aktif(), santri_status_const_khidmah()], true);
}

/** Sudah nonaktif / tidak mondok. */
function santri_status_is_nonaktif(string $status): bool
{
    return santri_status_normalize($status) === santri_status_const_nonaktif();
}

/** @deprecated Gunakan santri_status_is_nonaktif() */
function santri_status_is_keluar(string $status): bool
{
    return santri_status_is_nonaktif($status);
}

function santri_status_requires_keluar_info(string $status): bool
{
    return santri_status_is_nonaktif($status);
}

function santri_status_requires_tanggal_selesai(string $status): bool
{
    return santri_status_is_nonaktif($status);
}

function santri_status_sync_is_aktif(string $status): int
{
    return santri_status_is_di_pondok($status) ? 1 : 0;
}

/** Untuk administrasi keluar & surat (kolom keluar_kategori tetap dipakai). */
function santri_status_to_keluar_kategori(string $status, ?string $jenisMutasi = null): ?string
{
    if (santri_status_normalize($status) === santri_status_const_khidmah()) {
        return 'TAMAT';
    }
    if (!santri_status_is_nonaktif($status)) {
        return null;
    }
    $jenis = strtoupper(trim((string) $jenisMutasi));
    return match ($jenis) {
        'MUQIM', 'TAMAT', 'ALUMNI' => 'TAMAT',
        'KELUAR', 'BOYONG', 'KELUAR_PINDAH' => 'KELUAR_PINDAH',
        default => null,
    };
}

function santri_status_sync_mukimin(string $status): bool
{
    return santri_status_is_nonaktif($status);
}

function santri_status_hapus_operasional(string $status): bool
{
    return santri_status_is_nonaktif($status);
}

/**
 * @return array{ok:bool,error:?string,status:string,alasan_keluar:string,tanggal_keluar:string,keluar_kategori:?string,is_aktif:int}
 */
function santri_status_validate_save(
    string $statusRaw,
    string $alasanKeluar,
    string $tanggalKeluar,
    ?string $jenisMutasi = null,
    ?string $existingKeluarKategori = null
): array {
    $status = santri_status_normalize($statusRaw);
    $alasanKeluar = trim($alasanKeluar);
    $tanggalKeluar = trim($tanggalKeluar);

    if (santri_status_is_nonaktif($status)) {
        if ($alasanKeluar === '' || $tanggalKeluar === '') {
            return [
                'ok' => false,
                'error' => 'Status Nonaktif wajib diisi alasan dan tanggal keluar.',
                'status' => $status,
                'alasan_keluar' => $alasanKeluar,
                'tanggal_keluar' => $tanggalKeluar,
                'keluar_kategori' => null,
                'is_aktif' => 0,
            ];
        }
    }

    if ($status === santri_status_const_aktif()) {
        $alasanKeluar = '';
        $tanggalKeluar = '';
    }

    $keluarKat = santri_status_to_keluar_kategori($status, $jenisMutasi);
    if ($keluarKat === null && santri_status_is_nonaktif($status)) {
        $keluarKat = trim((string) $existingKeluarKategori) ?: 'KELUAR_PINDAH';
    }

    return [
        'ok' => true,
        'error' => null,
        'status' => $status,
        'alasan_keluar' => $alasanKeluar,
        'tanggal_keluar' => $tanggalKeluar,
        'keluar_kategori' => $keluarKat,
        'is_aktif' => santri_status_sync_is_aktif($status),
    ];
}

/** SQL IN (...) untuk filter status nonaktif termasuk data lama. */
function santri_status_sql_nonaktif_values(): string
{
    return "'NONAKTIF','NON_AKTIF','BOYONG','ALUMNI'";
}

function santri_status_migrate_legacy(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'status_santri')) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE santri MODIFY COLUMN status_santri VARCHAR(20) NOT NULL DEFAULT 'AKTIF'");
    } catch (Throwable $e) {
        // abaikan
    }

    $pdo->exec("
        UPDATE santri SET status_santri = 'NONAKTIF', is_aktif = 0
        WHERE UPPER(REPLACE(TRIM(COALESCE(status_santri, '')), ' ', '_')) IN ('NON_AKTIF','NONAKTIF','BOYONG','ALUMNI','MUQIM','KELUAR')
    ");
    $pdo->exec("
        UPDATE santri SET status_santri = 'KHIDMAH', is_aktif = 1
        WHERE UPPER(REPLACE(TRIM(COALESCE(status_santri, '')), ' ', '_')) IN ('PENGABDIAN','KHIDMAH')
    ");
    $pdo->exec("
        UPDATE santri SET status_santri = 'AKTIF', is_aktif = 1
        WHERE UPPER(TRIM(COALESCE(status_santri, ''))) = 'AKTIF'
           OR TRIM(COALESCE(status_santri, '')) = ''
    ");
}
