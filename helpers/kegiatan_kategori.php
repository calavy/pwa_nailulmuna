<?php

declare(strict_types=1);

/** @return list<string> */
function kegiatan_kategori_list(): array
{
    return ['TAALIM', 'JAMAAH', 'EXTRA'];
}

function kegiatan_kategori_normalize(?string $raw): string
{
    $k = strtoupper(trim((string) $raw));
    if ($k === 'TA\'LIM') {
        $k = 'TAALIM';
    }
    if (in_array($k, kegiatan_kategori_list(), true)) {
        return $k;
    }

    return 'TAALIM';
}

function kegiatan_kategori_label(string $kat): string
{
    return match (kegiatan_kategori_normalize($kat)) {
        'JAMAAH' => "Jama'ah",
        'EXTRA' => 'Extra (opsional)',
        default => "Ta'lim & Ta'alum",
    };
}

function kegiatan_kategori_is_extra(string $kat): bool
{
    return kegiatan_kategori_normalize($kat) === 'EXTRA';
}

/** Kegiatan wajib presensi (ALPA otomatis jika tidak hadir). */
function kegiatan_kategori_wajib_presensi(string $kat): bool
{
    return !kegiatan_kategori_is_extra($kat);
}

function kegiatan_kategori_fetch(PDO $pdo, int $kegiatanId): string
{
    if ($kegiatanId <= 0) {
        return 'TAALIM';
    }
    $st = $pdo->prepare('SELECT COALESCE(kategori_kegiatan, "TAALIM") FROM kegiatan WHERE id = ? LIMIT 1');
    $st->execute([$kegiatanId]);

    return kegiatan_kategori_normalize((string) ($st->fetchColumn() ?: 'TAALIM'));
}
