<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Kode QR efektif untuk scan presensi (qr → nis → ST-{id}).
 */
function santri_kartu_resolve_qr(array $row): string
{
    $qr = trim((string) ($row['qr'] ?? ''));
    if ($qr !== '') {
        return $qr;
    }
    $nis = trim((string) ($row['nis'] ?? ''));
    if ($nis !== '') {
        return $nis;
    }

    return 'ST-' . (int) ($row['id'] ?? 0);
}

function santri_kartu_qr_image_url(string $kodeQr, int $size = 700): string
{
    $size = max(200, min(1000, $size));

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&margin=8&data=' . rawurlencode($kodeQr);
}

/** Motto/tagline bawah kartu santri. */
function santri_kartu_motto(PDO $pdo): string
{
    $raw = trim((string) app_setting($pdo, 'kartu_santri_motto', 'DZIKIR , PIKIR , EKSYEN'));

    return $raw !== '' ? $raw : 'DZIKIR , PIKIR , EKSYEN';
}

/**
 * @return array{nama_ponpes:string,alamat:string,telp:string,motto:string}
 */
function santri_kartu_brand(PDO $pdo): array
{
    require_once __DIR__ . '/pondok_cetak.php';
    $kop = pondok_kop_data($pdo);
    $alamat = strtoupper(trim((string) ($kop['alamat_ponpes'] ?? '')));
    $telp = trim((string) ($kop['telp_ponpes'] ?? ''));

    return [
        'nama_ponpes' => strtoupper(trim((string) ($kop['nama_ponpes'] ?? app_brand_nama_ponpes($pdo)))),
        'alamat' => $alamat,
        'telp' => $telp,
        'motto' => strtoupper(santri_kartu_motto($pdo)),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function santri_kartu_fetch(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !table_exists($pdo, 'santri')) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT id, qr, nis, nama_santri, nama_ayah, foto_profil, tingkatan, jenis_kelamin, is_aktif, status_santri
        FROM santri
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function santri_kartu_fetch_many(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
    if ($ids === [] || !table_exists($pdo, 'santri')) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    require_once __DIR__ . '/santri_list_sort.php';
    $st = $pdo->prepare('
        SELECT id, qr, nis, nama_santri, nama_ayah, foto_profil, tingkatan, jenis_kelamin, is_aktif, status_santri
        FROM santri
        WHERE id IN (' . $ph . ')
        ORDER BY ' . santri_list_order_sql('santri')
    );
    $st->execute($ids);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Label hubungan filiasi: Bin (laki-laki) atau Binti (perempuan). */
function santri_bin_binti_hubungan(array $row): string
{
    $jk = strtoupper(trim((string) ($row['jenis_kelamin'] ?? '')));
    if (in_array($jk, ['PEREMPUAN', 'P', 'PUTRI', 'WANITA'], true)) {
        return 'Binti';
    }

    return 'Bin';
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function santri_kartu_prepare_row(array $row): array
{
    require_once __DIR__ . '/santri_foto.php';
    $kodeQr = santri_kartu_resolve_qr($row);
    $row['kode_qr_final'] = $kodeQr;
    $row['qr_url'] = santri_kartu_qr_image_url($kodeQr, 480);
    $fotoRel = trim((string) ($row['foto_profil'] ?? ''));
    $row['foto_url'] = $fotoRel !== '' ? santri_foto_url($fotoRel) : '';
    $ayah = trim((string) ($row['nama_ayah'] ?? ''));
    $row['bin_label'] = $ayah !== '' ? santri_bin_binti_hubungan($row) . ' ' . $ayah : '';

    return $row;
}

/**
 * Siapkan baris kartu dengan QR tertentu (mis. kartu sementara).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function santri_kartu_prepare_with_qr(array $row, string $kodeQr): array
{
    $row = santri_kartu_prepare_row($row);
    $kodeQr = trim($kodeQr);
    if ($kodeQr !== '') {
        $row['kode_qr_final'] = $kodeQr;
        $row['qr_url'] = santri_kartu_qr_image_url($kodeQr, 480);
    }

    return $row;
}

/** Kelas ukuran teks kartu berdasarkan panjang string (mm-friendly). */
function santri_kartu_text_size_class(string $text, string $prefix, int $lgMax = 18, int $mdMax = 26, int $smMax = 34): string
{
    $len = mb_strlen(trim($text));

    return match (true) {
        $len <= $lgMax => $prefix . '--lg',
        $len <= $mdMax => $prefix . '--md',
        $len <= $smMax => $prefix . '--sm',
        $len <= 42 => $prefix . '--xs',
        $len <= 52 => $prefix . '--xxs',
        default => $prefix . '--xxxs',
    };
}
