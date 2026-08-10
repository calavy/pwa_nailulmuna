<?php

declare(strict_types=1);

/**
 * Format & batching pesan WA laporan ALPA: dikelompokkan per tingkatan (template editable).
 */

/** Batas karakter absolut WhatsApp (satu pesan). */
function wa_laporan_alpa_message_hard_max(): int
{
    return 4096;
}

/** Batas karakter satu pesan logis laporan ALPA (margin untuk header/footer). */
function wa_laporan_alpa_message_max_len(PDO $pdo): int
{
    $hardMax = wa_laporan_alpa_message_hard_max();
    $configured = 3800;
    if (function_exists('app_setting')) {
        $configured = (int) app_setting($pdo, 'wa_alpa_message_max_len', '3800');
    }

    return max(500, min($hardMax, $configured > 0 ? $configured : 3800));
}

/** Tanggal laporan WA: "Senin, 13 Juli 2026". */
function wa_laporan_alpa_tanggal_label(?string $ymd = null): string
{
    $ts = $ymd !== null && $ymd !== '' ? (strtotime($ymd) ?: time()) : time();
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    return ($hari[(int) date('w', $ts)] ?? '') . ', ' . (int) date('j', $ts) . ' '
        . ($bulan[(int) date('n', $ts)] ?? date('F', $ts)) . ' ' . date('Y', $ts);
}

/**
 * @param array<int, array{nama_kegiatan?: string, nama_santri?: string, tingkatan?: string, nis?: string, total_alpha?: int|string, alpa_count?: int|string}> $rows
 * @return list<array{nama_santri: string, nis: string, tingkatan: string, kegiatan: array<string, int>, total_alpha: int}>
 */
function wa_laporan_alpa_group_by_santri(array $rows): array
{
    $bySantri = [];
    foreach ($rows as $row) {
        $nama = trim((string) ($row['nama_santri'] ?? ''));
        if ($nama === '') {
            $nama = '-';
        }
        $nis = trim((string) ($row['nis'] ?? ''));
        $key = $nis !== '' ? ('nis:' . $nis) : ('nama:' . mb_strtolower($nama));
        if (!isset($bySantri[$key])) {
            $bySantri[$key] = [
                'nama_santri' => $nama,
                'nis' => $nis,
                'tingkatan' => trim((string) ($row['tingkatan'] ?? '')),
                'kegiatan' => [],
                'total_alpha' => 0,
            ];
        }
        $kg = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($kg === '') {
            $kg = 'Tanpa kegiatan';
        }
        $n = (int) ($row['total_alpha'] ?? $row['alpa_count'] ?? 0);
        if ($n <= 0) {
            continue;
        }
        $bySantri[$key]['kegiatan'][$kg] = ($bySantri[$key]['kegiatan'][$kg] ?? 0) + $n;
        $bySantri[$key]['total_alpha'] += $n;
        if (trim((string) ($bySantri[$key]['tingkatan'] ?? '')) === '' && trim((string) ($row['tingkatan'] ?? '')) !== '') {
            $bySantri[$key]['tingkatan'] = trim((string) $row['tingkatan']);
        }
    }

    foreach ($bySantri as &$santri) {
        ksort($santri['kegiatan'], SORT_NATURAL);
    }
    unset($santri);

    $list = array_values($bySantri);
    usort($list, static function (array $a, array $b): int {
        $cmp = ((int) ($b['total_alpha'] ?? 0)) <=> ((int) ($a['total_alpha'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    });

    return $list;
}

/**
 * Blok teks per tingkatan (untuk packing pesan panjang).
 *
 * @param list<array{nama_santri: string, nis?: string, tingkatan?: string, total_alpha: int}> $santriList
 * @return list<string>
 */
function wa_laporan_alpa_tingkatan_blocks(array $santriList): array
{
    $byTingkat = [];
    foreach ($santriList as $s) {
        $tg = trim((string) ($s['tingkatan'] ?? ''));
        if ($tg === '') {
            $tg = 'Tanpa tingkatan';
        }
        $byTingkat[$tg][] = $s;
    }
    ksort($byTingkat, SORT_NATURAL);

    $blocks = [];
    foreach ($byTingkat as $tingkat => $list) {
        usort($list, static function (array $a, array $b): int {
            $cmp = ((int) ($b['total_alpha'] ?? 0)) <=> ((int) ($a['total_alpha'] ?? 0));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
        });
        $lines = ['*Tingkatan: ' . $tingkat . '*'];
        foreach ($list as $s) {
            $lines[] = wa_laporan_alpa_format_santri_line($s);
        }
        $blocks[] = implode("\n", $lines);
    }

    return $blocks;
}

/** Teks daftar lengkap (semua tingkatan). */
function wa_laporan_alpa_format_daftar_santri(array $santriList): string
{
    return implode("\n\n", wa_laporan_alpa_tingkatan_blocks($santriList));
}

/**
 * Satu baris santri: legacy style "Nama (NIS xxx): *N* kali ALPA".
 *
 * @param array{nama_santri?: string, nis?: string, tingkatan?: string, kegiatan?: array<string, int>, total_alpha?: int} $santri
 */
function wa_laporan_alpa_format_santri_line(array $santri): string
{
    $nama = trim((string) ($santri['nama_santri'] ?? '-'));
    if ($nama === '') {
        $nama = '-';
    }
    $nis = trim((string) ($santri['nis'] ?? ''));
    $n = (int) ($santri['total_alpha'] ?? 0);
    if ($n <= 0) {
        foreach ((array) ($santri['kegiatan'] ?? []) as $cnt) {
            $n += (int) $cnt;
        }
    }

    $line = '• ' . $nama;
    if ($nis !== '') {
        $line .= ' (NIS ' . $nis . ')';
    }

    return $line . ': *' . $n . '* kali ALPA';
}

/**
 * @param array{nama_santri?: string, nis?: string, tingkatan?: string, kegiatan?: array<string, int>, total_alpha?: int} $santri
 */
function wa_laporan_alpa_format_santri_block(array $santri): string
{
    return wa_laporan_alpa_format_santri_line($santri);
}

function wa_laporan_alpa_footer_resmi(): string
{
    return "\n\nMohon segera diproses atau tindakan disiplin sesuai aturan. Terima kasih.";
}

/** Token placeholder daftar santri di template rekap_alpa (alias didukung). */
function wa_laporan_alpa_daftar_tokens(): array
{
    return ['{daftar_santri}', '{daftar_santri_alpa}'];
}

/** @return array{token:string,pos:int}|null */
function wa_laporan_alpa_find_daftar_token(string $raw): ?array
{
    foreach (wa_laporan_alpa_daftar_tokens() as $token) {
        $pos = mb_strpos($raw, $token);
        if ($pos !== false) {
            return ['token' => $token, 'pos' => $pos];
        }
    }

    return null;
}

/**
 * Susun pesan WA alpa dari prefix/suffix template + daftar santri (dengan fallback).
 *
 * @param list<array{nama_santri: string, nis: string, tingkatan: string, kegiatan?: array<string, int>, total_alpha: int}> $santriList
 * @return list<string>
 */
function wa_laporan_alpa_compose_messages(
    PDO $pdo,
    int $ambang,
    string $tanggalLabel,
    string $periodeLabel,
    array $santriList
): array {
    if ($santriList === []) {
        return [];
    }

    $blocks = wa_laporan_alpa_tingkatan_blocks($santriList);
    [$prefix, $suffix, $hasDaftar] = wa_laporan_alpa_template_parts($pdo, $ambang, $tanggalLabel, $periodeLabel);
    $daftar = wa_laporan_alpa_format_daftar_santri($santriList);
    $maxLen = wa_laporan_alpa_message_max_len($pdo);

    $continuation = static function (int $part) use ($ambang, $tanggalLabel): string {
        return '*LAPORAN SANTRI ALPA (KELIPATAN ' . $ambang . ' — lanjutan ' . max(1, $part) . ")*\n"
            . 'Tanggal: ' . $tanggalLabel . "\n\n";
    };

    if (!$hasDaftar) {
        $bodyPrefix = rtrim($prefix) . "\n\n";
        $full = rtrim($bodyPrefix . $daftar);
        if (mb_strlen($full) <= $maxLen) {
            return [$full];
        }

        return wa_laporan_alpa_pack_messages($blocks, $bodyPrefix, $continuation, '', $maxLen);
    }

    $full = rtrim($prefix . $daftar . $suffix);
    if (mb_strlen($full) <= $maxLen) {
        return [$full];
    }

    $footer = $suffix !== '' ? $suffix : '';

    return wa_laporan_alpa_pack_messages($blocks, $prefix, $continuation, $footer, $maxLen);
}

/**
 * Render template rekap_alpa (boleh berisi {daftar_santri} atau alias {daftar_santri_alpa}).
 *
 * @return array{0:string,1:string,2:bool} [prefix sebelum daftar, suffix setelah daftar, punya_placeholder_daftar]
 */
function wa_laporan_alpa_template_parts(
    PDO $pdo,
    int $kelipatan,
    string $tanggalLabel,
    string $periodeLabel
): array {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $raw = wa_template_render($pdo, 'rekap_alpa', [
        'kelipatan' => (string) $kelipatan,
        'ambang' => (string) $kelipatan,
        'tanggal' => $tanggalLabel,
        'periode' => $periodeLabel,
        'daftar_santri' => '{daftar_santri}',
        'daftar_santri_alpa' => '{daftar_santri}',
        'nama_ponpes' => function_exists('app_brand_nama_ponpes') ? app_brand_nama_ponpes($pdo) : '',
    ]);
    $raw = trim($raw);
    if ($raw === '') {
        $raw = "*LAPORAN SANTRI ALPA (KELIPATAN {$kelipatan})*\n"
            . "Tanggal: {$tanggalLabel}\n\n"
            . "Berikut adalah daftar santri yang telah mencapai akumulasi {$kelipatan} kali ALPA:\n\n"
            . "{daftar_santri}\n\n"
            . 'Mohon segera diproses atau tindakan disiplin sesuai aturan. Terima kasih.';
    }
    $match = wa_laporan_alpa_find_daftar_token($raw);
    if ($match === null) {
        return [$raw . "\n\n", '', false];
    }
    $token = (string) $match['token'];
    $pos = (int) $match['pos'];
    $prefix = mb_substr($raw, 0, $pos);
    $suffix = mb_substr($raw, $pos + mb_strlen($token));

    return [$prefix, $suffix, true];
}

function wa_laporan_alpa_header_rekap_bulanan(PDO $pdo, string $periodeLabel, int $ambang, bool $lanjutan, int $bagian): string
{
    $tanggalLabel = wa_laporan_alpa_tanggal_label();
    if ($lanjutan) {
        return '*LAPORAN SANTRI ALPA (lanjutan bagian ' . max(1, $bagian) . ")*\n"
            . 'Tanggal: ' . $tanggalLabel . "\n"
            . 'Kelipatan: *' . $ambang . "*\n\n";
    }
    [$prefix] = wa_laporan_alpa_template_parts($pdo, $ambang, $tanggalLabel, $periodeLabel);

    return $prefix;
}

/**
 * Pecah blok tingkatan besar menjadi sub-blok per baris santri.
 *
 * @return list<string>
 */
function wa_laporan_alpa_split_oversized_block(string $block, int $maxLineBudget): array
{
    $block = trim($block);
    if ($block === '' || mb_strlen($block) <= $maxLineBudget) {
        return $block === '' ? [] : [$block];
    }

    $lines = preg_split("/\r\n|\n|\r/", $block) ?: [$block];
    $subBlocks = [];
    $current = '';

    $flush = static function () use (&$current, &$subBlocks): void {
        $buf = trim($current);
        if ($buf !== '') {
            $subBlocks[] = $buf;
        }
        $current = '';
    };

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $candidate = $current === '' ? $line : ($current . "\n" . $line);
        if (mb_strlen($candidate) <= $maxLineBudget) {
            $current = $candidate;
            continue;
        }
        $flush();
        if (mb_strlen($line) > $maxLineBudget) {
            $subBlocks[] = mb_substr($line, 0, $maxLineBudget);
            $current = '';
            continue;
        }
        $current = $line;
    }
    $flush();

    return $subBlocks !== [] ? $subBlocks : [$block];
}

/**
 * @param list<string> $blocks
 * @return list<string>
 */
function wa_laporan_alpa_pack_messages(
    array $blocks,
    string $firstHeader,
    callable $continuationHeader,
    string $footer,
    int $maxLen
): array {
    $blocks = array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''));
    if ($blocks === []) {
        return [];
    }

    $hardMax = wa_laporan_alpa_message_hard_max();
    $maxLen = max(80, min($hardMax, $maxLen));
    $footerLen = mb_strlen($footer);
    $messages = [];
    $part = 1;
    $header = $firstHeader;
    $body = $header;

    $flush = static function (bool $withFooter) use (&$body, &$messages, $footer, $footerLen, $hardMax): void {
        $text = rtrim($body);
        if ($text === '') {
            return;
        }
        if ($withFooter) {
            if (mb_strlen($text) + $footerLen > $hardMax) {
                $messages[] = $text;
                $messages[] = ltrim($footer);
            } else {
                $messages[] = $text . $footer;
            }
        } else {
            $messages[] = $text;
        }
        $body = '';
    };

    $appendBlock = static function (string $blockText, bool $isLast) use (
        &$body,
        &$header,
        &$part,
        $continuationHeader,
        $maxLen,
        $footerLen,
        $flush
    ): void {
        $budget = $maxLen - ($isLast ? $footerLen : 0);

        if (mb_strlen($body . $blockText) <= $budget) {
            $body .= $blockText;
            return;
        }

        if (rtrim($body) !== rtrim($header)) {
            $flush(false);
            $part++;
            $header = (string) $continuationHeader($part);
            $body = $header;
            $budget = $maxLen - ($isLast ? $footerLen : 0);
        }

        if (mb_strlen($body . $blockText) > $budget) {
            $lineBudget = max(80, $budget - mb_strlen($body));
            $subBlocks = wa_laporan_alpa_split_oversized_block(rtrim($blockText), $lineBudget);
            $count = count($subBlocks);
            foreach ($subBlocks as $si => $sub) {
                $subText = $sub . ($si < $count - 1 ? "\n\n" : "\n\n");
                $subLast = $isLast && ($si === $count - 1);
                if (mb_strlen($body . $subText) <= ($maxLen - ($subLast ? $footerLen : 0))) {
                    $body .= $subText;
                    continue;
                }
                if (rtrim($body) !== rtrim($header)) {
                    $flush(false);
                    $part++;
                    $header = (string) $continuationHeader($part);
                    $body = $header;
                }
                $body .= $subText;
            }
            return;
        }

        $body .= $blockText;
    };

    foreach ($blocks as $idx => $block) {
        $blockText = $block . "\n\n";
        $isLast = ($idx === count($blocks) - 1);
        $appendBlock($blockText, $isLast);
    }

    $flush(true);

    return $messages;
}

/**
 * @param array<int, array{nama_kegiatan?: string, nama_santri?: string, tingkatan?: string, nis?: string, total_alpha?: int|string, alpa_count?: int|string}> $rows
 * @return list<string>
 */
function wa_format_rekap_alpa_per_santri_messages(
    PDO $pdo,
    string $periodeLabel,
    int $ambang,
    array $rows,
    ?string $tanggalYmd = null
): array {
    $santriList = wa_laporan_alpa_group_by_santri($rows);
    if ($santriList === []) {
        return [];
    }

    $tanggalLabel = wa_laporan_alpa_tanggal_label($tanggalYmd);

    return wa_laporan_alpa_compose_messages(
        $pdo,
        $ambang,
        $tanggalLabel,
        $periodeLabel,
        $santriList
    );
}

/**
 * @param array<int, array{nama_kegiatan?: string, nama_santri?: string, tingkatan?: string, nis?: string, total_alpha?: int|string, alpa_count?: int|string}> $rows
 */
function wa_format_rekap_alpa_per_kegiatan(PDO $pdo, string $periodeLabel, int $ambang, array $rows): string
{
    $messages = wa_format_rekap_alpa_per_santri_messages($pdo, $periodeLabel, $ambang, $rows);
    if ($messages === []) {
        return '';
    }

    return implode("\n\n---\n\n", $messages);
}

/**
 * @param array<int, array{nama_santri: string, nis: string, total_alpha: int}> $santriList
 * @return list<string>
 */
function wa_format_laporan_alpa_generate_messages(
    PDO $pdo,
    string $tanggalIdn,
    string $tingkatan,
    string $namaKegiatan,
    int $ambang,
    array $santriList
): array {
    if ($santriList === []) {
        return [];
    }
    $rows = [];
    foreach ($santriList as $s) {
        $rows[] = [
            'nama_santri' => (string) ($s['nama_santri'] ?? '-'),
            'nis' => (string) ($s['nis'] ?? ''),
            'tingkatan' => $tingkatan,
            'nama_kegiatan' => $namaKegiatan,
            'total_alpha' => (int) ($s['total_alpha'] ?? 0),
        ];
    }

    $ymd = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalIdn)) {
        $ymd = $tanggalIdn;
    } else {
        $ts = strtotime(str_replace('/', '-', $tanggalIdn));
        if ($ts !== false) {
            $ymd = date('Y-m-d', $ts);
        }
    }

    $periodeLabel = $tanggalIdn . ($namaKegiatan !== '' ? (' · ' . $namaKegiatan) : '');
    $santriGrouped = wa_laporan_alpa_group_by_santri($rows);
    $tanggalLabel = $ymd !== null ? wa_laporan_alpa_tanggal_label($ymd) : $tanggalIdn;

    return wa_laporan_alpa_compose_messages($pdo, $ambang, $tanggalLabel, $periodeLabel, $santriGrouped);
}

/**
 * @param array<int, array{nama_santri:string,nis:string,alpa_count:int}> $entries
 * @return list<string>
 */
function wa_format_alpa_tier_messages(
    PDO $pdo,
    string $tanggalIdn,
    string $tingkatan,
    string $namaKegiatan,
    string $tierLabel,
    int $threshold,
    string $periodeLabel,
    array $entries
): array {
    if ($entries === []) {
        return [];
    }
    $rows = [];
    foreach ($entries as $e) {
        $rows[] = [
            'nama_santri' => (string) ($e['nama_santri'] ?? '-'),
            'nis' => (string) ($e['nis'] ?? ''),
            'tingkatan' => $tingkatan,
            'nama_kegiatan' => $namaKegiatan,
            'total_alpha' => (int) ($e['alpa_count'] ?? 0),
        ];
    }

    $labelPeriode = $periodeLabel;
    if (trim($tierLabel) !== '') {
        $labelPeriode .= ' · ' . trim($tierLabel);
    }

    $santriGrouped = wa_laporan_alpa_group_by_santri($rows);
    $tanggalLabel = wa_laporan_alpa_tanggal_label();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalIdn)) {
        $tanggalLabel = wa_laporan_alpa_tanggal_label($tanggalIdn);
    }

    return wa_laporan_alpa_compose_messages($pdo, $threshold, $tanggalLabel, $labelPeriode, $santriGrouped);
}

/**
 * Kirim beberapa pesan WA ke daftar nomor (berurutan, dengan jeda antar pesan).
 *
 * @param list<string> $messages
 * @param array<string, mixed> $opts
 */
function send_wa_bulk_messages(PDO $pdo, string $phonesRaw, array $messages, array $opts = []): int
{
    if ($messages === []) {
        return 0;
    }
    require_once __DIR__ . '/wa_otomatis.php';

    // Pesan sudah dipecah per blok — jangan pecah lagi di gateway.
    if (!array_key_exists('chunk_max', $opts)) {
        $opts['chunk_max'] = 0;
    }
    if (!array_key_exists('kind', $opts)) {
        $opts['kind'] = 'alpa';
    }
    if (!array_key_exists('message_delay_ms', $opts)) {
        $fonnteDelay = wa_otomatis_fonnte_api_delay($pdo, $opts);
        $minSec = wa_otomatis_delay_min_seconds($fonnteDelay);
        if ($minSec > 0) {
            $opts['message_delay_ms'] = $minSec * 1000;
        }
    }

    $delayMs = max(200, min(300000, (int) ($opts['message_delay_ms'] ?? 650)));
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    $sent = 0;
    $partIdx = 0;
    foreach ($messages as $message) {
        $message = trim((string) $message);
        if ($message === '') {
            continue;
        }
        $parts = mb_strlen($message) > $maxLen
            ? wa_otomatis_chunk_message($message, $maxLen)
            : [$message];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if ($partIdx > 0) {
                usleep($delayMs * 1000);
            }
            $msgOpts = $opts;
            if (!empty($opts['dedup_key'])) {
                $msgOpts['dedup_key'] = (string) $opts['dedup_key'] . ':part:' . $partIdx;
            }
            $bulk = send_wa_bulk_with_result($pdo, $phonesRaw, $part, $msgOpts);
            $sent += (int) ($bulk['sent'] ?? 0);
            $partIdx++;
        }
    }

    return $sent;
}
