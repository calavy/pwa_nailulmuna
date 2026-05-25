<?php

declare(strict_types=1);

require_once __DIR__ . '/pondok_ta.php';

/**
 * Tahun ajaran operasional — selalu sama dengan pengaturan terpusat (Keuangan → Umum & periode).
 *
 * @param array<string, mixed>|null $input Diabaikan (kompatibilitas lama).
 * @return array{mulai:int,selesai:int,is_aktif:bool,label:string}
 */
function keuangan_ta_resolve(PDO $pdo, ?array $input = null): array
{
    return pondok_ta_resolve($pdo, $input);
}

/** @param array{mulai:int,selesai:int} $ta */
function keuangan_ta_persist_session(array $ta): void
{
    pondok_ta_persist_session($ta);
}

/**
 * @return list<array{mulai:int,selesai:int,label:string,is_aktif:bool,is_berjalan:bool}>
 */
function keuangan_ta_pilihan_options(PDO $pdo): array
{
    return pondok_ta_pilihan_options($pdo);
}

function keuangan_ta_query(array $ta, array $extra = []): string
{
    return pondok_ta_query($ta, $extra);
}
