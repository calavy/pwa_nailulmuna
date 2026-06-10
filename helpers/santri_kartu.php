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

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&margin=10&data=' . rawurlencode($kodeQr);
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
        SELECT id, qr, nis, nama_santri, tingkatan, jenis_kelamin, is_aktif, status_santri
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
        SELECT id, qr, nis, nama_santri, tingkatan, jenis_kelamin, is_aktif, status_santri
        FROM santri
        WHERE id IN (' . $ph . ')
        ORDER BY ' . santri_list_order_sql('santri')
    );
    $st->execute($ids);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function santri_kartu_prepare_row(array $row): array
{
    $kodeQr = santri_kartu_resolve_qr($row);
    $row['kode_qr_final'] = $kodeQr;
    $row['qr_url'] = santri_kartu_qr_image_url($kodeQr);

    return $row;
}
