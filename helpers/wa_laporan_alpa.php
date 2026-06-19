<?php

declare(strict_types=1);

/**
 * Format & batching pesan WA laporan ALPA: per nama santri, kegiatan di bawahnya.
 */

/** Batas karakter satu pesan logis (sebelum pecahan gateway). */
function wa_laporan_alpa_message_max_len(PDO $pdo): int
{
    require_once __DIR__ . '/wa_otomatis.php';
    $chunk = wa_otomatis_gateway_chunk_max($pdo);
    if ($chunk >= 500) {
        return min(4096, $chunk);
    }

    return max(80, min(4096, $chunk));
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
 * @param array{nama_santri?: string, nis?: string, tingkatan?: string, kegiatan?: array<string, int>} $santri
 */
function wa_laporan_alpa_format_santri_block(array $santri): string
{
    $nama = (string) ($santri['nama_santri'] ?? '-');
    $nis = trim((string) ($santri['nis'] ?? ''));
    $tg = trim((string) ($santri['tingkatan'] ?? ''));
    $head = '*' . $nama . '*';
    if ($nis !== '') {
        $head .= ' (NIS ' . $nis . ')';
    }
    if ($tg !== '') {
        $head .= ' · ' . $tg;
    }
    $lines = [$head];
    foreach ((array) ($santri['kegiatan'] ?? []) as $kg => $n) {
        $lines[] = '  • ' . $kg . ': *' . (int) $n . '* ALPA';
    }
    if (count($lines) === 1) {
        $lines[] = '  • (tidak ada rincian kegiatan)';
    }

    return implode("\n", $lines);
}

function wa_laporan_alpa_footer_resmi(): string
{
    return "\n\nMohon arahan dan tindak lanjut sesuai peraturan pesantren.\n"
        . "Demikian disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';
}

function wa_laporan_alpa_header_rekap_bulanan(PDO $pdo, string $periodeLabel, int $ambang, bool $lanjutan, int $bagian): string
{
    $intro = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n";
    if ($lanjutan) {
        return $intro
            . '*LAPORAN ALPA (lanjutan bagian ' . max(1, $bagian) . ")*\n"
            . 'Periode: *' . $periodeLabel . "*\n\n";
    }

    $templateBody = '';
    if (function_exists('wa_template_render')) {
        $templateBody = trim(wa_template_render($pdo, 'rekap_alpa', [
            'periode' => $periodeLabel,
            'ambang' => (string) $ambang,
        ]));
    }
    if ($templateBody !== '') {
        return $intro . $templateBody . "\n\n*Daftar santri (nama → kegiatan):*\n\n";
    }

    return $intro
        . "*PEMBERITAHUAN RESMI*\n"
        . "Perihal: Rekapitulasi ketidakhadiran (*ALPA*)\n"
        . 'Periode data: *' . $periodeLabel . "*\n"
        . 'Kriteria: jumlah ALPA ≥ *' . $ambang . "* per kegiatan\n\n"
        . "Berikut daftar santri (*nama* → *kegiatan* di bawahnya):\n\n";
}

/**
 * Pecah daftar blok santri menjadi beberapa pesan WA (tanpa memotong di tengah satu santri).
 *
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

    $maxLen = max(80, min(4096, $maxLen));
    $footerLen = mb_strlen($footer);
    $messages = [];
    $part = 1;
    $header = $firstHeader;
    $body = $header;

    $flush = static function (bool $withFooter) use (&$body, &$messages, $footer, $footerLen): void {
        $text = rtrim($body);
        if ($text === '') {
            return;
        }
        if ($withFooter) {
            if (mb_strlen($text) + $footerLen > 4096) {
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

    $blocks = array_map('wa_laporan_alpa_format_santri_block', $santriList);
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    $footer = wa_laporan_alpa_footer_resmi();
    $firstHeader = wa_laporan_alpa_header_rekap_bulanan($pdo, $periodeLabel, $ambang, false, 1);
    $continuation = static fn (int $part): string => wa_laporan_alpa_header_rekap_bulanan($pdo, $periodeLabel, $ambang, true, $part);

    return wa_laporan_alpa_pack_messages($blocks, $firstHeader, $continuation, $footer, $maxLen);
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
 * Laporan setelah generate ALPA massal (beberapa santri, satu konteks kegiatan).
 *
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
            'nama_kegiatan' => $namaKegiatan . ' (kumulatif bulan ini)',
            'total_alpha' => (int) ($s['total_alpha'] ?? 0),
        ];
    }

    $blocks = array_map('wa_laporan_alpa_format_santri_block', wa_laporan_alpa_group_by_santri($rows));
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    $footer = "\n\nMohon ditindaklanjuti sesuai ketentuan.\n"
        . "Demikian laporan ini disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';
    $firstHeader = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*LAPORAN RESMI — PENCATATAN ALPA*\n\n"
        . 'Tanggal kegiatan: *' . $tanggalIdn . "*\n"
        . 'Tingkatan: *' . $tingkatan . "*\n"
        . 'Kegiatan: *' . $namaKegiatan . "*\n"
        . 'Ambang pemberitahuan bulan berjalan: *≥ ' . $ambang . "* kali ALPA\n\n"
        . "*Daftar santri (nama → kegiatan):*\n\n";
    $continuation = static fn (int $part): string => wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . '*LAPORAN ALPA (lanjutan bagian ' . max(1, $part) . ")*\n"
        . 'Tanggal: *' . $tanggalIdn . "* · Kegiatan: *" . $namaKegiatan . "*\n\n";

    return wa_laporan_alpa_pack_messages($blocks, $firstHeader, $continuation, $footer, $maxLen);
}

/**
 * Notifikasi tier ALPA.
 *
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
    $blocks = array_map('wa_laporan_alpa_format_santri_block', wa_laporan_alpa_group_by_santri($rows));
    $maxLen = wa_laporan_alpa_message_max_len($pdo);
    $footer = "\n\nMohon ditindaklanjuti sesuai kewenangan.\n"
        . "Demikian disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';
    $tierLine = trim($tierLabel) !== '' ? 'Penanggung jawab: *' . trim($tierLabel) . "*\n" : '';
    $firstHeader = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . '*LAPORAN ALPA — AMBANG ' . $threshold . "*\n"
        . $tierLine
        . 'Periode: *' . $periodeLabel . "*\n"
        . 'Tanggal pencatatan: *' . $tanggalIdn . "*\n"
        . 'Tingkatan: *' . $tingkatan . "*\n\n"
        . "*Daftar santri (nama → kegiatan):*\n\n";
    $continuation = static fn (int $part): string => wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . '*LAPORAN ALPA (lanjutan bagian ' . max(1, $part) . ")*\n"
        . 'Periode: *' . $periodeLabel . "*\n\n";

    return wa_laporan_alpa_pack_messages($blocks, $firstHeader, $continuation, $footer, $maxLen);
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

    if (!array_key_exists('chunk_max', $opts)) {
        $opts['chunk_max'] = wa_otomatis_gateway_chunk_max($pdo);
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
