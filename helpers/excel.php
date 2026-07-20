<?php

function import_upload_is_xlsx(string $filename, string $tmpPath): bool
{
    $name = strtolower(trim($filename));
    if (str_ends_with($name, '.xlsx')) {
        return true;
    }
    if ($tmpPath === '' || !is_readable($tmpPath)) {
        return false;
    }
    if (function_exists('mime_content_type')) {
        $mime = strtolower((string) mime_content_type($tmpPath));
        if ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return true;
        }
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($tmpPath));
        if ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return true;
        }
    }

    return false;
}

/**
 * @return ?string isi entry dalam arsip ZIP
 */
function zip_archive_read_entry(string $zipPath, string $entryName): ?string
{
    $entryName = str_replace('\\', '/', ltrim($entryName, '/'));
    if ($entryName === '' || !is_readable($zipPath)) {
        return null;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $data = $zip->getFromName($entryName);
            $zip->close();
            if ($data !== false) {
                return $data;
            }
        }
    }

    if (class_exists('PharData')) {
        $tmpBase = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmpBase !== false) {
            $tmpZip = $tmpBase . '.zip';
            try {
                if (@copy($zipPath, $tmpZip)) {
                    $phar = new PharData($tmpZip);
                    $data = $phar->getFromName($entryName);
                    if ($data !== false) {
                        return (string) $data;
                    }
                }
            } catch (Throwable $e) {
                // lanjut ke parser native
            } finally {
                @unlink($tmpZip);
                @unlink($tmpBase);
            }
        }
    }

    return zip_archive_read_entry_native($zipPath, $entryName);
}

function zip_archive_inflate_entry(string $data, int $method, int $uncompSize): ?string
{
    if ($method === 0) {
        return $data;
    }
    if ($method !== 8) {
        return null;
    }
    if (function_exists('zlib_decode')) {
        $out = @zlib_decode($data);
        if ($out !== false) {
            return $out;
        }
    }
    $out = @gzinflate($data);
    if ($out !== false) {
        return $out;
    }
    if ($uncompSize > 0) {
        $out = @gzinflate($data, $uncompSize);
        if ($out !== false) {
            return $out;
        }
    }

    return null;
}

function zip_archive_read_entry_native(string $zipPath, string $entryName): ?string
{
    $entryName = str_replace('\\', '/', ltrim($entryName, '/'));
    $handle = @fopen($zipPath, 'rb');
    if ($handle === false) {
        return null;
    }

    $result = null;
    while (!feof($handle)) {
        $header = @fread($handle, 4);
        if ($header === false || strlen($header) !== 4) {
            break;
        }
        $sig = unpack('V', $header)[1];
        if ($sig !== 0x04034b50) {
            break;
        }
        $rest = @fread($handle, 26);
        if ($rest === false || strlen($rest) !== 26) {
            break;
        }
        $meta = unpack('vversion/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', $rest);
        if ($meta === false) {
            break;
        }
        $nameLen = (int) ($meta['nameLen'] ?? 0);
        $extraLen = (int) ($meta['extraLen'] ?? 0);
        $compSize = (int) ($meta['compSize'] ?? 0);
        $flags = (int) ($meta['flags'] ?? 0);

        // Bit 3 = data descriptor: ukuran bisa 0 di local header (tidak didukung penuh)
        if (($flags & 0x08) !== 0 && $compSize === 0) {
            break;
        }

        $name = $nameLen > 0 ? @fread($handle, $nameLen) : '';
        if ($name === false) {
            break;
        }
        if ($extraLen > 0) {
            @fread($handle, $extraLen);
        }
        $name = str_replace('\\', '/', (string) $name);
        $data = $compSize > 0 ? @fread($handle, $compSize) : '';
        if ($data === false) {
            break;
        }
        if ($name === $entryName) {
            $result = zip_archive_inflate_entry((string) $data, (int) $meta['method'], (int) $meta['uncompSize']);
            break;
        }
    }
    fclose($handle);

    return $result;
}

/**
 * @param array<string, string> $files path dalam arsip => isi file
 */
function zip_archive_build(array $files): string
{
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Tidak bisa membuat file sementara.');
        }
        $zipPath = $tmp . '.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $contents) {
                $zip->addFromString(str_replace('\\', '/', $name), (string) $contents);
            }
            $zip->close();
            $bytes = (string) file_get_contents($zipPath);
            @unlink($zipPath);
            if ($bytes !== '') {
                return $bytes;
            }
        }
        @unlink($zipPath);
    }

    if (class_exists('PharData')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp !== false) {
            $zipPath = $tmp . '.zip';
            @unlink($tmp);
            try {
                @unlink($zipPath);
                $phar = new PharData($zipPath, Phar::ZIP | Phar::CREATE);
                foreach ($files as $name => $contents) {
                    $phar->addFromString(str_replace('\\', '/', $name), (string) $contents);
                }
                unset($phar);
                $bytes = (string) file_get_contents($zipPath);
                @unlink($zipPath);
                if ($bytes !== '') {
                    return $bytes;
                }
            } catch (Throwable $e) {
                @unlink($zipPath);
            }
        }
    }

    return zip_archive_build_native($files);
}

/**
 * @param array<string, string> $files
 */
function zip_archive_build_native(array $files): string
{
    if (!function_exists('gzdeflate')) {
        throw new RuntimeException(
            'Import Excel membutuhkan ekstensi PHP zip atau zlib. Aktifkan extension=zip di php.ini, lalu restart Apache.'
        );
    }

    $localParts = [];
    $centralParts = [];
    $offset = 0;

    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', (string) $name);
        $contents = (string) $contents;
        $crc = crc32($contents);
        if ($crc < 0) {
            $crc += 0x100000000;
        }
        $uncompressedLen = strlen($contents);
        $compressed = gzdeflate($contents, 6);
        if ($compressed === false) {
            throw new RuntimeException('Gagal mengompres file Excel.');
        }
        $compressedLen = strlen($compressed);

        $localHeader = pack(
            'Vv5V3v2',
            0x04034b50,
            20,
            0,
            8,
            0,
            0,
            $crc,
            $compressedLen,
            $uncompressedLen,
            strlen($name),
            0
        );
        $localRecord = $localHeader . $name . $compressed;
        $localParts[] = $localRecord;

        $centralHeader = pack(
            'Vv6V3v5VV',
            0x02014b50,
            20,
            20,
            0,
            8,
            0,
            0,
            $crc,
            $compressedLen,
            $uncompressedLen,
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            $offset
        );
        $centralParts[] = $centralHeader . $name;

        $offset += strlen($localRecord);
    }

    $centralDir = implode('', $centralParts);
    $centralDirOffset = $offset;
    $centralDirSize = strlen($centralDir);
    $entryCount = count($files);

    $eocd = pack(
        'Vv4V2v',
        0x06054b50,
        0,
        0,
        $entryCount,
        $entryCount,
        $centralDirSize,
        $centralDirOffset,
        0
    );

    return implode('', $localParts) . $centralDir . $eocd;
}

function parse_xlsx_rows(string $filePath): array
{
    if (!is_readable($filePath)) {
        throw new RuntimeException('File Excel tidak bisa dibaca.');
    }

    $sharedStrings = [];
    $sharedXml = zip_archive_read_entry($filePath, 'xl/sharedStrings.xml');
    if ($sharedXml !== null && $sharedXml !== '') {
        $shared = @simplexml_load_string(excel_strip_xml_namespaces($sharedXml));
        if ($shared && isset($shared->si)) {
            foreach ($shared->si as $item) {
                $value = '';
                if (isset($item->t)) {
                    $value = (string) $item->t;
                } elseif (isset($item->r)) {
                    foreach ($item->r as $run) {
                        $value .= (string) $run->t;
                    }
                }
                $sharedStrings[] = $value;
            }
        }
    }

    $sheetXml = zip_archive_read_entry($filePath, 'xl/worksheets/sheet1.xml');
    if ($sheetXml === null || $sheetXml === '') {
        $relsXml = zip_archive_read_entry($filePath, 'xl/_rels/workbook.xml.rels');
        if ($relsXml !== null && $relsXml !== '') {
            $rels = @simplexml_load_string(excel_strip_xml_namespaces($relsXml));
            if ($rels) {
                foreach ($rels->Relationship as $rel) {
                    $type = strtolower((string) ($rel['Type'] ?? ''));
                    if (!str_contains($type, '/worksheet')) {
                        continue;
                    }
                    $target = str_replace('\\', '/', (string) ($rel['Target'] ?? ''));
                    if ($target === '') {
                        continue;
                    }
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . ltrim($target, '/');
                    }
                    $sheetXml = zip_archive_read_entry($filePath, $target);
                    if ($sheetXml !== null && $sheetXml !== '') {
                        break;
                    }
                }
            }
        }
    }
    if ($sheetXml === null || $sheetXml === '') {
        $hint = class_exists('ZipArchive')
            ? 'Pastikan file .xlsx valid dan berisi minimal 1 sheet.'
            : 'Aktifkan extension=zip di php.ini lalu restart Apache.';
        throw new RuntimeException('Sheet Excel tidak ditemukan. ' . $hint);
    }

    $sheet = @simplexml_load_string(excel_strip_xml_namespaces($sheetXml));
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $ref) ?? '';
            if ($column === '') {
                continue;
            }
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string) $cell->is->t;
            } elseif (isset($cell->v)) {
                $raw = (string) $cell->v;
                if ($type === 's') {
                    $idx = (int) $raw;
                    $value = $sharedStrings[$idx] ?? '';
                } else {
                    $value = $raw;
                }
            }
            $rowData[$column] = trim($value);
        }
        $rows[] = $rowData;
    }

    return $rows;
}

/** Buang xmlns agar SimpleXML mudah dibaca (OOXML sering memakai default namespace). */
function excel_strip_xml_namespaces(string $xml): string
{
    $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/i', '', $xml) ?? $xml;
    $xml = preg_replace('/\sxmlns(:\w+)?=\'[^\']*\'/i', '', $xml) ?? $xml;

    return $xml;
}

function normalize_santri_import_rows(array $rows): array
{
    if (!$rows) {
        return [];
    }

    $normalized = normalize_import_rows($rows);
    if ($normalized !== []) {
        $result = [];
        foreach ($normalized as $raw) {
            $entry = [
                'qr' => trim((string) ($raw['qr'] ?? '')),
                'nis' => trim((string) ($raw['nis'] ?? '')),
                'nama_santri' => trim((string) ($raw['nama_santri'] ?? $raw['nama'] ?? '')),
                'tingkatan' => trim((string) ($raw['tingkatan'] ?? '')),
                'no_wa_wali' => trim((string) ($raw['no_wa_wali'] ?? $raw['wa_wali'] ?? $raw['no_wa'] ?? '')),
                'jenis_kelamin' => trim((string) ($raw['jenis_kelamin'] ?? $raw['jk'] ?? '')),
            ];
            if ($entry['nis'] === '' || $entry['nama_santri'] === '') {
                continue;
            }
            $result[] = $entry;
        }

        return $result;
    }

    $result = [];
    $first = $rows[0];
    $headerMap = [];
    $expected = ['qr', 'nis', 'nama_santri', 'tingkatan', 'no_wa_wali'];

    foreach ($first as $col => $value) {
        $name = strtolower(trim($value));
        if (in_array($name, $expected, true)) {
            $headerMap[$name] = $col;
        }
    }

    $startIndex = count($headerMap) >= 2 ? 1 : 0;

    for ($i = $startIndex; $i < count($rows); $i++) {
        $r = $rows[$i];
        if ($headerMap) {
            $entry = [
                'qr' => trim((string) ($r[$headerMap['qr'] ?? ''] ?? '')),
                'nis' => trim((string) ($r[$headerMap['nis'] ?? ''] ?? '')),
                'nama_santri' => trim((string) ($r[$headerMap['nama_santri'] ?? ''] ?? '')),
                'tingkatan' => trim((string) ($r[$headerMap['tingkatan'] ?? ''] ?? '')),
                'no_wa_wali' => trim((string) ($r[$headerMap['no_wa_wali'] ?? ''] ?? '')),
            ];
        } else {
            $entry = [
                'qr' => trim((string) ($r['A'] ?? '')),
                'nis' => trim((string) ($r['B'] ?? '')),
                'nama_santri' => trim((string) ($r['C'] ?? '')),
                'tingkatan' => trim((string) ($r['D'] ?? '')),
                'no_wa_wali' => trim((string) ($r['E'] ?? '')),
            ];
        }

        if ($entry['nis'] === '' || $entry['nama_santri'] === '') {
            continue;
        }
        $result[] = $entry;
    }

    return $result;
}

function normalize_import_header_key(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = str_replace(['.', '/', '-'], ' ', $key);
    $key = preg_replace('/\s+/', '_', $key) ?? $key;
    $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? $key;
    return trim($key, '_');
}

/**
 * Normalisasi baris import agar bisa dipakai dengan key kolom konsisten.
 * Mendukung:
 * - hasil parse_xlsx_rows() (kolom A/B/C, header di baris pertama)
 * - baris asosiatif biasa (mis. hasil array_combine dari CSV)
 *
 * @return list<array<string, string>>
 */
function normalize_import_rows(array $rows): array
{
    if ($rows === []) {
        return [];
    }
    $first = $rows[0] ?? null;
    if (!is_array($first) || $first === []) {
        return [];
    }

    $firstKeys = array_keys($first);
    $looksLikeSheetColumns = true;
    foreach ($firstKeys as $k) {
        $ks = (string) $k;
        if (!preg_match('/^[A-Z]+$/i', $ks)) {
            $looksLikeSheetColumns = false;
            break;
        }
    }

    $result = [];
    if ($looksLikeSheetColumns) {
        $headerMap = [];
        foreach ($first as $col => $labelRaw) {
            $label = normalize_import_header_key((string) $labelRaw);
            if ($label !== '') {
                $headerMap[$col] = $label;
            }
        }
        if ($headerMap === []) {
            return [];
        }
        for ($i = 1; $i < count($rows); $i++) {
            $r = is_array($rows[$i]) ? $rows[$i] : [];
            $entry = [];
            $hasValue = false;
            foreach ($headerMap as $col => $field) {
                $value = trim((string) ($r[$col] ?? ''));
                $entry[$field] = $value;
                if ($value !== '') {
                    $hasValue = true;
                }
            }
            if ($hasValue) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $entry = [];
        $hasValue = false;
        foreach ($r as $k => $v) {
            $field = normalize_import_header_key((string) $k);
            if ($field === '') {
                continue;
            }
            $value = trim((string) $v);
            $entry[$field] = $value;
            if ($value !== '') {
                $hasValue = true;
            }
        }
        if ($hasValue) {
            $result[] = $entry;
        }
    }

    return $result;
}

/** @return array<string, list<string>> */
function alumni_import_header_aliases(): array
{
    return [
        'nis' => ['nis'],
        'nama' => ['nama', 'nama_santri', 'nama alumni'],
        'dusun' => ['dusun'],
        'rt_rw' => ['rt_rw', 'rt/rw', 'rt rw', 'rt-rw'],
        'desa_kelurahan' => ['desa_kelurahan', 'desa/kelurahan', 'desa kelurahan', 'desa', 'kelurahan'],
        'kecamatan' => ['kecamatan'],
        'kabupaten' => ['kabupaten', 'kab/kota', 'kabupaten/kota'],
        'propinsi' => ['propinsi', 'provinsi'],
        'th_masuk' => ['th_masuk', 'th.masuk', 'th masuk', 'thmasuk', 'tahun_masuk', 'tahun masuk', 'tahun masuk pondok', 'th masuk pondok'],
        'th_keluar' => ['th_keluar', 'th.keluar', 'th keluar', 'thkeluar', 'tahun_keluar', 'tahun keluar', 'tahun keluar pondok', 'th keluar pondok'],
        'keterangan' => ['keterangan', 'ket', 'catatan'],
    ];
}

function alumni_normalize_header_key(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = str_replace(['.', '/', '-'], ' ', $key);
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;

    foreach (alumni_import_header_aliases() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $a = str_replace(['.', '/', '-'], ' ', strtolower($alias));
            $a = preg_replace('/\s+/', ' ', $a) ?? $a;
            if ($key === $a || $key === str_replace(' ', '_', $a)) {
                return $field;
            }
        }
    }

    return str_replace(' ', '_', $key);
}

function normalize_alumni_import_rows(array $rows): array
{
    if (!$rows) {
        return [];
    }

    $aliases = alumni_import_header_aliases();
    $expectedKeys = array_keys($aliases);
    $headerMap = [];
    $first = $rows[0];

    foreach ($first as $col => $value) {
        $field = alumni_normalize_header_key((string) $value);
        if (in_array($field, $expectedKeys, true)) {
            $headerMap[$field] = $col;
        }
    }

    $startIndex = count($headerMap) >= 2 ? 1 : 0;
    $result = [];

    for ($i = $startIndex; $i < count($rows); $i++) {
        $r = $rows[$i];
        if ($headerMap) {
            $entry = [];
            foreach ($expectedKeys as $field) {
                $entry[$field] = trim((string) ($r[$headerMap[$field] ?? ''] ?? ''));
            }
        } else {
            $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
            $entry = [];
            foreach ($expectedKeys as $idx => $field) {
                $entry[$field] = trim((string) ($r[$cols[$idx] ?? ''] ?? ''));
            }
        }

        if (($entry['nis'] ?? '') === '' || ($entry['nama'] ?? '') === '') {
            continue;
        }
        foreach (['th_masuk', 'th_keluar'] as $thField) {
            $y = alumni_parse_year_cell((string) ($entry[$thField] ?? ''));
            $entry[$thField] = $y !== null ? (string) $y : '';
        }
        $result[] = $entry;
    }

    return $result;
}

/** Ubah isi sel Excel menjadi tahun (1900–2100), termasuk angka & tanggal Excel. */
function alumni_parse_year_cell(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(19|20)\d{2}$/', $raw)) {
        return (int) $raw;
    }
    if (is_numeric($raw)) {
        $n = (float) $raw;
        if ($n >= 1900 && $n <= 2100 && abs($n - round($n)) < 0.0001) {
            return (int) round($n);
        }
        if ($n > 20000 && $n < 80000) {
            $unix = (int) round(($n - 25569) * 86400);
            if ($unix > 0) {
                $y = (int) date('Y', $unix);
                if ($y >= 1900 && $y <= 2100) {
                    return $y;
                }
            }
        }
    }
    if (preg_match('/\b(19|20)\d{2}\b/', $raw, $m)) {
        return (int) $m[0];
    }

    return null;
}

function xlsx_column_letter(int $index): string
{
    $letter = '';
    $n = $index + 1;
    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }

    return $letter;
}

function xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @param list<list<string|int|float|null>> $rows
 */
function build_xlsx_bytes(array $rows, string $sheetName = 'Sheet1'): string
{
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $r = $rowIndex + 1;
        $cells = '';
        foreach (array_values($row) as $colIndex => $value) {
            $col = xlsx_column_letter($colIndex);
            $ref = $col . $r;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $cells .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                continue;
            }
            $text = xlsx_xml_escape((string) $value);
            $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
        }
        $sheetRows .= '<row r="' . $r . '">' . $cells . '</row>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_xml_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    return zip_archive_build([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * @param list<list<string|int|float|null>> $rows
 */
function send_xlsx_download(string $filename, array $rows, string $sheetName = 'Sheet1'): void
{
    if (!str_ends_with(strtolower($filename), '.xlsx')) {
        $filename .= '.xlsx';
    }
    $bytes = build_xlsx_bytes($rows, $sheetName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: max-age=0');
    echo $bytes;
}

/** @return list<string> */
function alumni_xlsx_header_labels(): array
{
    return [
        'NIS', 'NAMA', 'DUSUN', 'RT/RW', 'DESA/KELURAHAN', 'KECAMATAN', 'KABUPATEN', 'PROPINSI',
        'TH.MASUK', 'TH.KELUAR', 'keterangan',
    ];
}

/** @return list<list<string|int|null>> */
function alumni_xlsx_template_rows(): array
{
    return [
        alumni_xlsx_header_labels(),
        [
            '2020001', 'Ahmad Alumni', 'Sukamaju', '001/002', 'Sukamaju', 'Contoh', 'Contoh', 'Jawa Timur',
            2018, 2024, 'Lulus SMP',
        ],
    ];
}

/**
 * @param list<array<string, mixed>> $dbRows
 * @return list<list<string|int|null>>
 */
function alumni_db_rows_to_xlsx(array $dbRows): array
{
    $out = [alumni_xlsx_header_labels()];
    foreach ($dbRows as $r) {
        $out[] = [
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama'] ?? ''),
            (string) ($r['dusun'] ?? ''),
            (string) ($r['rt_rw'] ?? ''),
            (string) ($r['desa_kelurahan'] ?? ''),
            (string) ($r['kecamatan'] ?? ''),
            (string) ($r['kabupaten'] ?? ''),
            (string) ($r['propinsi'] ?? ''),
            ($r['th_masuk'] ?? '') !== '' && $r['th_masuk'] !== null ? (int) $r['th_masuk'] : null,
            ($r['th_keluar'] ?? '') !== '' && $r['th_keluar'] !== null ? (int) $r['th_keluar'] : null,
            (string) ($r['keterangan'] ?? ''),
        ];
    }

    return $out;
}
