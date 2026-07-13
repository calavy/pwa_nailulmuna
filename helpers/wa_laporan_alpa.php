<?php

declare(strict_types=1);

/**
 * Format & batching pesan WA laporan ALPA: dikelompokkan per tingkatan (template editable).
 */

/** Batas karakter satu pesan WA laporan ALPA (pecah ke pesan berikutnya). */
function wa_laporan_alpa_message_hard_max(): int
{
    return 40960;
}

/** Batas karakter satu pesan logis laporan ALPA. */
function wa_laporan_alpa_message_max_len(PDO $pdo): int
{
    unset($pdo);

    return wa_laporan_alpa_message_hard_max();
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
            $nama = trim((string) ($s['nama_santri'] ?? '-'));
            $n = (int) ($s['total_alpha'] ?? 0);
            $lines[] = '• ' . $nama . ' (Total: ' . $n . ' hari)';
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
 * @param array{nama_santri?: string, nis?: string, tingkatan?: string, kegiatan?: array<string, int>, total_alpha?: int} $santri
 */
function wa_laporan_alpa_format_santri_block(array $santri): string
{
    $nama = (string) ($santri['nama_santri'] ?? '-');
    $n = (int) ($santri['total_alpha'] ?? 0);
    if ($n <= 0) {
        foreach ((array) ($santri['kegiatan'] ?? []) as $cnt) {
            $n += (int) $cnt;
        }
    }

    return '• ' . $nama . ' (Total: ' . $n . ' hari)';
}

function wa_laporan_alpa_footer_resmi(): string
{
    return "\n\nMohon segera diproses atau tindakan disiplin sesuai aturan. Terima kasih.";
}

/**
 * Render template rekap_alpa (boleh berisi {daftar_santri}).
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
        'nama_ponpes' => function_exists('app_brand_nama_ponpes') ? app_brand_nama_ponpes($pdo) : '',
    ]);
    $raw = trim($raw);
    if ($raw === '') {
        $raw = "*LAPORAN SANTRI ALPA (KELIPATAN {$kelipatan})*\n"
            . "Tanggal: {$tanggalLabel}\n\n"
            . "Berikut adalah daftar santri yang telah mencapai akumulasi {$kelipatan} hari alpa:\n\n"
            . "{daftar_santri}\n\n"
            . 'Mohon segera diproses atau tindakan disiplin sesuai aturan. Terima kasih.';
    }
    $pos = mb_strpos($raw, '{daftar_santri}');
    if ($pos === false) {
        return [$raw . "\n\n", '', false];
    }
    $prefix = mb_substr($raw, 0, $pos);
    $suffix = mb_substr($raw, $pos + mb_strlen('{daftar_santri}'));

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

    foreach ($blocks as $idx => $block) {
        $blockText = $block . "\n\n";
        $isLast = ($idx === count($blocks) - 1);
        $budget = $maxLen - ($isLast ? $footerLen : 0);

        if (mb_strlen($body . $blockText) <= $budget) {
            $body .= $blockText;
            continue;
        }

        if (rtrim($body) !== rtrim($header)) {
            $flush(false);
            $part++;
            $header = (string) $continuationHeader($part);
            $body = $header;
            $budget = $maxLen - ($isLast ? $footerLen : 0);
        }

        if (mb_strlen($body . $blockText) > $budget && rtrim($body) !== rtrim($header)) {
            $flush(false);
            $part++;
            $header = (string) $continuationHeader($part);
            $body = $header;
        }

        $body .= $blockText;
    }

    $flush(true);

    return $messages;
}

/**
 * @param array<int, array{nama_kegiatan?: string, nama_santri?: string, tingkatan?: string, nis?: string, total_alpha?: int|string, alpa_count?: int|string}> $rows
 * @return list<string>
 */
function wa_format_rekap_alpa_per_santri_messages(PDO $pdo, string $periodeLabel, int $ambang, array $rows): array
{
    $santriList = wa_laporan_alpa_group_by_santri($rows);
    if ($santriList === []) {
        return [];
    }

    $tanggalLabel = wa_laporan_alpa_tanggal_label();
    $blocks = wa_laporan_alpa_tingkatan_blocks($santriList);
    [$prefix, $suffix, $hasDaftar] = wa_laporan_alpa_template_parts($pdo, $ambang, $tanggalLabel, $periodeLabel);
    $maxLen = wa_laporan_alpa_message_max_len($pdo);

    if (!$hasDaftar) {
        return [rtrim($prefix)];
    }

    $full = rtrim($prefix . wa_laporan_alpa_format_daftar_santri($santriList) . $suffix);
    if (mb_strlen($full) <= $maxLen) {
        return [$full];
    }

    $footer = $suffix !== '' ? $suffix : '';
    $continuation = static function (int $part) use ($ambang, $tanggalLabel): string {
        return '*LAPORAN SANTRI ALPA (KELIPATAN ' . $ambang . ' — lanjutan ' . max(1, $part) . ")*\n"
            . 'Tanggal: ' . $tanggalLabel . "\n\n";
    };

    return wa_laporan_alpa_pack_messages($blocks, $prefix, $continuation, $footer, $maxLen);
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
    $blocks = wa_laporan_alpa_tingkatan_blocks($santriGrouped);
    [$prefix, $suffix, $hasDaftar] = wa_laporan_alpa_template_parts($pdo, $ambang, $tanggalLabel, $periodeLabel);
    if (!$hasDaftar) {
        return [rtrim($prefix)];
    }
    $full = rtrim($prefix . wa_laporan_alpa_format_daftar_santri($santriGrouped) . $suffix);
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    if (mb_strlen($full) <= $maxLen) {
        return [$full];
    }
    $continuation = static function (int $part) use ($ambang, $tanggalLabel): string {
        return '*LAPORAN SANTRI ALPA (KELIPATAN ' . $ambang . ' — lanjutan ' . max(1, $part) . ")*\n"
            . 'Tanggal: ' . $tanggalLabel . "\n\n";
    };

    return wa_laporan_alpa_pack_messages($blocks, $prefix, $continuation, $suffix, $maxLen);
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
    $blocks = wa_laporan_alpa_tingkatan_blocks($santriGrouped);
    [$prefix, $suffix, $hasDaftar] = wa_laporan_alpa_template_parts($pdo, $threshold, $tanggalLabel, $labelPeriode);
    if (!$hasDaftar) {
        return [rtrim($prefix)];
    }
    $full = rtrim($prefix . wa_laporan_alpa_format_daftar_santri($santriGrouped) . $suffix);
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    if (mb_strlen($full) <= $maxLen) {
        return [$full];
    }
    $continuation = static function (int $part) use ($threshold, $tanggalLabel): string {
        return '*LAPORAN SANTRI ALPA (KELIPATAN ' . $threshold . ' — lanjutan ' . max(1, $part) . ")*\n"
            . 'Tanggal: ' . $tanggalLabel . "\n\n";
    };

    return wa_laporan_alpa_pack_messages($blocks, $prefix, $continuation, $suffix, $maxLen);
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

    $delayMs = max(200, min(5000, (int) ($opts['message_delay_ms'] ?? 650)));
    $sent = 0;
    foreach ($messages as $idx => $message) {
        if ($idx > 0) {
            usleep($delayMs * 1000);
        }
        $message = trim((string) $message);
        if ($message === '') {
            continue;
        }
        $bulk = send_wa_bulk_with_result($pdo, $phonesRaw, $message, $opts);
        $sent += (int) ($bulk['sent'] ?? 0);
    }

    return $sent;
}
