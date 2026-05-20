<?php

function parse_xlsx_rows(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension ZipArchive belum aktif di PHP.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('File Excel tidak bisa dibuka.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
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

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Sheet1 pada Excel tidak ditemukan.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $ref);
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

function normalize_santri_import_rows(array $rows): array
{
    if (!$rows) {
        return [];
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
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension ZipArchive belum aktif di PHP.');
    }

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

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('Tidak bisa membuat file sementara.');
    }
    $zipPath = $tmp . '.xlsx';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Tidak bisa membuat file Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    return $bytes;
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
