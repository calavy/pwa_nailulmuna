<?php

declare(strict_types=1);

require_once __DIR__ . '/excel.php';
require_once __DIR__ . '/ikhtibar_docx.php';

/** Huruf opsi PG aktif (2–5). @return list<string> */
function ikhtibar_pg_opsi_huruf_list(int $jumlahOpsi): array
{
    $jumlahOpsi = max(2, min(5, $jumlahOpsi));

    return array_slice(['A', 'B', 'C', 'D', 'E'], 0, $jumlahOpsi);
}

/** @return list<string> */
function ikhtibar_pg_opsi_kolom_lower(int $jumlahOpsi): array
{
    return array_map(static fn (string $h): string => strtolower($h), ikhtibar_pg_opsi_huruf_list($jumlahOpsi));
}

/**
 * @param array<string,mixed> $row
 */
function ikhtibar_pg_normalisasi_jumlah_opsi(array $row): int
{
    $explicit = (int) ($row['jumlah_opsi'] ?? $row['pg_jumlah_opsi'] ?? 0);
    if ($explicit >= 2 && $explicit <= 5) {
        return $explicit;
    }
    $eVal = trim((string) ($row['e'] ?? $row['opsi_e'] ?? ''));
    $kunci = strtoupper(trim((string) ($row['kunci'] ?? $row['kunci_jawaban'] ?? '')));
    if ($eVal !== '' || $kunci === 'E') {
        return 5;
    }

    return 4;
}

/** @param array<string,mixed> $row baris struct PG */
function ikhtibar_pg_bersihkan_opsi(array $row): array
{
    $jumlah = ikhtibar_pg_normalisasi_jumlah_opsi($row);
    $row['jumlah_opsi'] = $jumlah;
    $cols = ['a', 'b', 'c', 'd', 'e'];
    for ($i = $jumlah; $i < 5; $i++) {
        $row[$cols[$i]] = null;
    }

    return $row;
}

/** @param array<string,mixed> $row baris DB ikhtibar_soal */
function ikhtibar_pg_jumlah_opsi_dari_row(array $row): int
{
    if (isset($row['pg_jumlah_opsi']) && $row['pg_jumlah_opsi'] !== null && $row['pg_jumlah_opsi'] !== '') {
        $n = (int) $row['pg_jumlah_opsi'];
        if ($n >= 2 && $n <= 5) {
            return $n;
        }
    }

    return ikhtibar_pg_normalisasi_jumlah_opsi([
        'e' => $row['opsi_e'] ?? null,
        'kunci' => $row['kunci_jawaban'] ?? null,
    ]);
}

/**
 * @param array<string,mixed> $row
 */
function ikhtibar_pg_validasi_baris(array $row, int $nom): ?string
{
    $jumlah = ikhtibar_pg_normalisasi_jumlah_opsi($row);
    $huruf = ikhtibar_pg_opsi_huruf_list($jumlah);
    $kunci = strtoupper(trim((string) ($row['kunci'] ?? '')));
    if ($kunci === '' || !in_array($kunci, $huruf, true)) {
        return 'Soal PG nomor ' . $nom . ': pilih kunci jawaban (' . $huruf[0] . '–' . $huruf[count($huruf) - 1] . ').';
    }
    foreach (ikhtibar_pg_opsi_kolom_lower($jumlah) as $opt) {
        if (trim((string) ($row[$opt] ?? '')) === '') {
            return 'Soal PG nomor ' . $nom . ': opsi ' . strtoupper($opt) . ' wajib diisi.';
        }
    }

    return null;
}

/** @return list<array<string,mixed>> */
function ikhtibar_import_template_xlsx_rows(): array
{
    return [
        ['jenis', 'nomor', 'teks', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'kunci', 'bobot'],
        ['PG', 1, 'Contoh teks soal pilihan ganda', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', '', 'A', ''],
        ['PG', 2, 'Soal PG kedua', 'Jawaban 1', 'Jawaban 2', 'Jawaban 3', 'Jawaban 4', '', 'B', ''],
        ['ESAI', 1, 'Contoh pertanyaan esai', '', '', '', '', '', '[KELENGKAPAN] poin1, poin2', '100'],
    ];
}

/**
 * @param list<list<mixed>> $rows
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_rows(array $rows, int $maxPg, int $maxEsai): array
{
    $out = ['pg' => [], 'esai' => [], 'errors' => []];
    if ($rows === []) {
        $out['errors'][] = 'Data baris kosong.';

        return $out;
    }

    $header = array_map(static fn ($v): string => strtolower(trim((string) $v)), $rows[0] ?? []);
    $colMap = [];
    foreach ($header as $idx => $name) {
        if ($name !== '') {
            $colMap[$name] = $idx;
        }
    }

    $hasHeader = isset($colMap['jenis']) || isset($colMap['teks']);
    $startRow = $hasHeader ? 1 : 0;

    for ($r = $startRow, $n = count($rows); $r < $n; $r++) {
        $row = $rows[$r];
        if ($hasHeader) {
            $jenis = strtoupper(trim((string) ($row[$colMap['jenis'] ?? -1] ?? '')));
            $nomor = (int) ($row[$colMap['nomor'] ?? -1] ?? 0);
            $teks = trim((string) ($row[$colMap['teks'] ?? -1] ?? ''));
            $kunci = trim((string) ($row[$colMap['kunci'] ?? -1] ?? ''));
            $bobot = trim((string) ($row[$colMap['bobot'] ?? -1] ?? ''));
            $opsi = [
                'a' => trim((string) ($row[$colMap['opsi_a'] ?? -1] ?? '')),
                'b' => trim((string) ($row[$colMap['opsi_b'] ?? -1] ?? '')),
                'c' => trim((string) ($row[$colMap['opsi_c'] ?? -1] ?? '')),
                'd' => trim((string) ($row[$colMap['opsi_d'] ?? -1] ?? '')),
                'e' => trim((string) ($row[$colMap['opsi_e'] ?? -1] ?? '')),
            ];
        } else {
            $jenis = strtoupper(trim((string) ($row[0] ?? '')));
            $nomor = (int) ($row[1] ?? 0);
            $teks = trim((string) ($row[2] ?? ''));
            $opsi = [
                'a' => trim((string) ($row[3] ?? '')),
                'b' => trim((string) ($row[4] ?? '')),
                'c' => trim((string) ($row[5] ?? '')),
                'd' => trim((string) ($row[6] ?? '')),
                'e' => trim((string) ($row[7] ?? '')),
            ];
            $kunci = trim((string) ($row[8] ?? ''));
            $bobot = trim((string) ($row[9] ?? ''));
        }

        if ($teks === '') {
            continue;
        }
        if ($nomor <= 0) {
            $nomor = ($jenis === 'ESAI' || $jenis === 'ESSAY' ? count($out['esai']) : count($out['pg'])) + 1;
        }

        if ($jenis === 'ESAI' || $jenis === 'ESSAY') {
            if ($nomor > $maxEsai) {
                continue;
            }
            $out['esai'][$nomor] = [
                'teks' => $teks,
                'kunci' => $kunci !== '' ? $kunci : null,
                'bobot' => $bobot !== '' ? max(1, min(100, (float) $bobot)) : 100.0,
            ];
        } else {
            if ($nomor > $maxPg) {
                continue;
            }
            $out['pg'][$nomor] = ikhtibar_pg_bersihkan_opsi([
                'teks' => $teks,
                'a' => $opsi['a'] !== '' ? $opsi['a'] : null,
                'b' => $opsi['b'] !== '' ? $opsi['b'] : null,
                'c' => $opsi['c'] !== '' ? $opsi['c'] : null,
                'd' => $opsi['d'] !== '' ? $opsi['d'] : null,
                'e' => $opsi['e'] !== '' ? $opsi['e'] : null,
                'kunci' => strtoupper($kunci) !== '' ? strtoupper($kunci) : null,
                'bobot' => 100.0,
            ]);
        }
    }

    $out['errors'] = array_merge($out['errors'], ikhtibar_import_errors_dari_soal($out, $maxPg, $maxEsai));

    return $out;
}

/** @return list<list<string>> */
function ikhtibar_parse_csv_to_rows(string $csv): array
{
    $csv = ltrim($csv, "\xEF\xBB\xBF");
    $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = str_getcsv($line);
    }

    return $rows;
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_xlsx(string $filePath, int $maxPg, int $maxEsai): array
{
    try {
        $rows = parse_xlsx_rows($filePath);
    } catch (Throwable $e) {
        return ['pg' => [], 'esai' => [], 'errors' => [$e->getMessage()]];
    }

    return ikhtibar_import_soal_dari_rows($rows, $maxPg, $maxEsai);
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_teks(string $text, int $maxPg, int $maxEsai): array
{
    $out = ['pg' => [], 'esai' => [], 'errors' => []];
    $text = trim($text);
    if ($text === '') {
        $out['errors'][] = 'Teks soal kosong.';

        return $out;
    }

    if ($maxPg > 0) {
        $parsedPg = ikhtibar_parse_teks_soal_pg($text, $maxPg);
        foreach ($parsedPg as $idx => $p) {
            $nom = $idx + 1;
            $out['pg'][$nom] = [
                'teks' => (string) ($p['teks'] ?? ''),
                'a' => $p['opsi']['A'] ?? null,
                'b' => $p['opsi']['B'] ?? null,
                'c' => $p['opsi']['C'] ?? null,
                'd' => $p['opsi']['D'] ?? null,
                'e' => $p['opsi']['E'] ?? null,
                'kunci' => ($p['kunci'] ?? '') !== '' ? strtoupper((string) $p['kunci']) : null,
                'bobot' => 100.0,
            ];
        }
    }

    if ($maxEsai > 0) {
        $parsedEsai = ikhtibar_parse_teks_soal_esai($text, $maxEsai);
        foreach ($parsedEsai as $idx => $p) {
            $nom = $idx + 1;
            $out['esai'][$nom] = [
                'teks' => (string) ($p['teks'] ?? ''),
                'kunci' => ($p['kunci'] ?? '') !== '' ? (string) $p['kunci'] : null,
                'bobot' => 100.0,
            ];
        }
    }

    $out['errors'] = ikhtibar_import_errors_dari_soal($out, $maxPg, $maxEsai);

    return $out;
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_docx(string $filePath, int $maxPg, int $maxEsai): array
{
    $text = ikhtibar_docx_extract_text($filePath);
    if ($text === '') {
        return ['pg' => [], 'esai' => [], 'errors' => ['Teks Word tidak terbaca. Pastikan file .docx valid.']];
    }

    return ikhtibar_import_soal_dari_teks($text, $maxPg, $maxEsai);
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>}
 */
function ikhtibar_kumpulkan_soal_dari_post(array $post, int $jumlahPg, int $jumlahEsai): array
{
    $pg = [];
    $esai = [];
    for ($i = 1; $i <= $jumlahPg; $i++) {
        $teks = trim((string) ($post['pg_teks'][$i] ?? ''));
        if ($teks === '') {
            continue;
        }
        $pg[$i] = ikhtibar_pg_bersihkan_opsi([
            'teks' => $teks,
            'jumlah_opsi' => max(2, min(5, (int) ($post['pg_jumlah_opsi'][$i] ?? 4))),
            'a' => trim((string) ($post['pg_a'][$i] ?? '')) ?: null,
            'b' => trim((string) ($post['pg_b'][$i] ?? '')) ?: null,
            'c' => trim((string) ($post['pg_c'][$i] ?? '')) ?: null,
            'd' => trim((string) ($post['pg_d'][$i] ?? '')) ?: null,
            'e' => trim((string) ($post['pg_e'][$i] ?? '')) ?: null,
            'kunci' => strtoupper(trim((string) ($post['pg_kunci'][$i] ?? ''))) ?: null,
            'bobot' => 100.0,
        ]);
    }
    for ($i = 1; $i <= $jumlahEsai; $i++) {
        $teks = trim((string) ($post['esai_teks'][$i] ?? ''));
        if ($teks === '') {
            continue;
        }
        $esai[$i] = [
            'teks' => $teks,
            'kunci' => trim((string) ($post['esai_kunci'][$i] ?? '')) ?: null,
            'bobot' => max(1, min(100, (float) ($post['esai_bobot'][$i] ?? 100))),
        ];
    }

    return ['pg' => $pg, 'esai' => $esai];
}

/**
 * Import menimpa slot nomor yang sama; manual form di-merge dulu lalu import.
 *
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $manual
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $imported
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>}
 */
function ikhtibar_merge_soal_import(array $manual, array $imported): array
{
    $pg = $manual['pg'];
    $esai = $manual['esai'];
    foreach ($imported['pg'] ?? [] as $nom => $row) {
        if (trim((string) ($row['teks'] ?? '')) !== '') {
            $pg[(int) $nom] = $row;
        }
    }
    foreach ($imported['esai'] ?? [] as $nom => $row) {
        if (trim((string) ($row['teks'] ?? '')) !== '') {
            $esai[(int) $nom] = $row;
        }
    }

    return ['pg' => $pg, 'esai' => $esai];
}

/** @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal */
function ikhtibar_persist_soal_rows(PDO $pdo, int $tugasId, array $soal): void
{
    ensure_akademik_ikhtibar_tables($pdo);
    ikhtibar_apply_pending_schema_columns($pdo);

    $pdo->prepare('DELETE FROM ikhtibar_soal WHERE tugas_id = :id')->execute(['id' => $tugasId]);
    $ins = $pdo->prepare('
        INSERT INTO ikhtibar_soal (tugas_id, jenis, nomor, teks_soal, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_nilai, pg_jumlah_opsi)
        VALUES (:tid,:jenis,:nom,:teks,:a,:b,:c,:d,:e,:kunci,:bobot,:jopsi)
    ');
    foreach ($soal['pg'] as $nom => $row) {
        $teks = trim((string) ($row['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $row = ikhtibar_pg_bersihkan_opsi($row);
        $ins->execute([
            'tid' => $tugasId, 'jenis' => 'PG', 'nom' => (int) $nom, 'teks' => $teks,
            'a' => $row['a'] ?? null, 'b' => $row['b'] ?? null, 'c' => $row['c'] ?? null,
            'd' => $row['d'] ?? null, 'e' => $row['e'] ?? null,
            'kunci' => $row['kunci'] ?? null,
            'bobot' => (float) ($row['bobot'] ?? 100),
            'jopsi' => ikhtibar_pg_normalisasi_jumlah_opsi($row),
        ]);
    }
    foreach ($soal['esai'] as $nom => $row) {
        $teks = trim((string) ($row['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $ins->execute([
            'tid' => $tugasId, 'jenis' => 'ESAI', 'nom' => (int) $nom, 'teks' => $teks,
            'a' => null, 'b' => null, 'c' => null, 'd' => null, 'e' => null,
            'kunci' => $row['kunci'] ?? null,
            'bobot' => max(1, min(100, (float) ($row['bobot'] ?? 100))),
            'jopsi' => null,
        ]);
    }
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>}
 */
function ikhtibar_soal_struct_dari_db_rows(array $rows): array
{
    $pg = [];
    $esai = [];
    foreach ($rows as $r) {
        $nom = (int) ($r['nomor'] ?? 0);
        if ($nom <= 0) {
            continue;
        }
        if ((string) ($r['jenis'] ?? '') === 'PG') {
            $pg[$nom] = [
                'teks' => (string) ($r['teks_soal'] ?? ''),
                'a' => $r['opsi_a'] ?? null,
                'b' => $r['opsi_b'] ?? null,
                'c' => $r['opsi_c'] ?? null,
                'd' => $r['opsi_d'] ?? null,
                'e' => $r['opsi_e'] ?? null,
                'jumlah_opsi' => ikhtibar_pg_jumlah_opsi_dari_row($r),
                'kunci' => ($r['kunci_jawaban'] ?? '') !== '' ? strtoupper((string) $r['kunci_jawaban']) : null,
                'bobot' => (float) ($r['bobot_nilai'] ?? 100),
            ];
        } else {
            $esai[$nom] = [
                'teks' => (string) ($r['teks_soal'] ?? ''),
                'kunci' => ($r['kunci_jawaban'] ?? '') !== '' ? (string) $r['kunci_jawaban'] : null,
                'bobot' => max(1, min(100, (float) ($r['bobot_nilai'] ?? 100))),
            ];
        }
    }

    return ['pg' => $pg, 'esai' => $esai];
}

/**
 * Isi slot kosong dari soal yang sudah tersimpan di DB (edit tugas).
 *
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal
 * @param list<array<string,mixed>> $dbRows
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>}
 */
function ikhtibar_merge_soal_existing(array $soal, array $dbRows, int $maxPg, int $maxEsai): array
{
    $existing = ikhtibar_soal_struct_dari_db_rows($dbRows);
    foreach ($existing['pg'] as $nom => $row) {
        if ($nom > $maxPg || trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        $cur = $soal['pg'][$nom] ?? null;
        if ($cur === null || trim((string) ($cur['teks'] ?? '')) === '') {
            $soal['pg'][$nom] = $row;
        }
    }
    foreach ($existing['esai'] as $nom => $row) {
        if ($nom > $maxEsai || trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        $cur = $soal['esai'][$nom] ?? null;
        if ($cur === null || trim((string) ($cur['teks'] ?? '')) === '') {
            $soal['esai'][$nom] = $row;
        }
    }

    return $soal;
}

/**
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal
 * @return array{ok:bool,message:string}
 */
function ikhtibar_validasi_soal_publish(array $soal): array
{
    $total = count($soal['pg']) + count($soal['esai']);
    if ($total < 1) {
        return ['ok' => false, 'message' => 'Publikasikan membutuhkan minimal satu soal. Isi manual, import Excel/Word, atau OCR.'];
    }
    foreach ($soal['pg'] as $nom => $row) {
        $err = ikhtibar_pg_validasi_baris($row, (int) $nom);
        if ($err !== null) {
            return ['ok' => false, 'message' => $err];
        }
    }

    return ['ok' => true, 'message' => ''];
}

function ikhtibar_tugas_has_active_sesi(PDO $pdo, int $tugasId): bool
{
    if ($tugasId <= 0) {
        return false;
    }
    ensure_akademik_ikhtibar_tables($pdo);
    $st = $pdo->prepare('
        SELECT COUNT(*) FROM ikhtibar_sesi
        WHERE tugas_id = :id AND status IN ("berjalan", "selesai", "habis_waktu")
    ');
    $st->execute(['id' => $tugasId]);

    return (int) $st->fetchColumn() > 0;
}

/**
 * Pesan peringatan parse import (slot kosong, kunci hilang, dll.).
 *
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal
 * @return list<string>
 */
function ikhtibar_import_errors_dari_soal(array $soal, int $maxPg, int $maxEsai): array
{
    $errors = [];
    for ($i = 1; $i <= $maxPg; $i++) {
        $row = $soal['pg'][$i] ?? null;
        if ($row === null || trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        $err = ikhtibar_pg_validasi_baris($row, $i);
        if ($err !== null) {
            $errors[] = $err;
        }
    }
    for ($i = 1; $i <= $maxEsai; $i++) {
        $row = $soal['esai'][$i] ?? null;
        if ($row === null || trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        if (trim((string) ($row['kunci'] ?? '')) === '') {
            $errors[] = 'Esai nomor ' . $i . ': kunci/kriteria esai kosong.';
        }
    }

    return $errors;
}

/**
 * Kumpulkan hasil import dari file upload, Google URL, dan OCR (merge ke satu struct).
 *
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_kumpulkan_import_dari_request(PDO $pdo, array $post, array $files, int $maxPg, int $maxEsai): array
{
    require_once __DIR__ . '/ikhtibar_google_import.php';

    $merged = ['pg' => [], 'esai' => [], 'errors' => []];

    $apply = static function (array $import) use (&$merged): void {
        foreach ($import['pg'] ?? [] as $nom => $row) {
            if (trim((string) ($row['teks'] ?? '')) !== '') {
                $merged['pg'][(int) $nom] = $row;
            }
        }
        foreach ($import['esai'] ?? [] as $nom => $row) {
            if (trim((string) ($row['teks'] ?? '')) !== '') {
                $merged['esai'][(int) $nom] = $row;
            }
        }
        $merged['errors'] = array_merge($merged['errors'], $import['errors'] ?? []);
    };

    if (isset($files['import_docx']) && (int) ($files['import_docx']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $apply(ikhtibar_import_soal_dari_docx((string) $files['import_docx']['tmp_name'], $maxPg, $maxEsai));
    }
    if (isset($files['import_xlsx']) && (int) ($files['import_xlsx']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $apply(ikhtibar_import_soal_dari_xlsx((string) $files['import_xlsx']['tmp_name'], $maxPg, $maxEsai));
    }

    $sheetUrl = trim((string) ($post['import_google_sheet'] ?? ''));
    if ($sheetUrl !== '') {
        $apply(ikhtibar_import_soal_dari_google_sheet($pdo, $sheetUrl, $maxPg, $maxEsai));
    }

    $docUrl = trim((string) ($post['import_google_doc'] ?? ''));
    if ($docUrl !== '') {
        $apply(ikhtibar_import_soal_dari_google_doc($pdo, $docUrl, $maxPg, $maxEsai));
    }

    $ocrText = trim((string) ($post['ocr_teks_import'] ?? ''));
    if ($ocrText !== '' && ($maxPg > 0 || $maxEsai > 0)) {
        require_once __DIR__ . '/ikhtibar_ai_parse.php';
        if (ikhtibar_ai_ocr_enabled($pdo)) {
            $aiResult = ikhtibar_ai_parse_soal_dari_teks($pdo, $ocrText, $maxPg, $maxEsai);
            if (($aiResult['pg'] ?? []) !== [] || ($aiResult['esai'] ?? []) !== []) {
                $apply($aiResult);
            } else {
                $apply(ikhtibar_import_soal_dari_teks($ocrText, $maxPg, $maxEsai));
                if (($aiResult['errors'] ?? []) !== []) {
                    $merged['errors'] = array_merge($merged['errors'], $aiResult['errors']);
                }
            }
        } else {
            $apply(ikhtibar_import_soal_dari_teks($ocrText, $maxPg, $maxEsai));
        }
    }

    return $merged;
}
