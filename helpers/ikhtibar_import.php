<?php

declare(strict_types=1);

require_once __DIR__ . '/excel.php';
require_once __DIR__ . '/ikhtibar_docx.php';

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
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_xlsx(string $filePath, int $maxPg, int $maxEsai): array
{
    $out = ['pg' => [], 'esai' => [], 'errors' => []];
    try {
        $rows = parse_xlsx_rows($filePath);
    } catch (Throwable $e) {
        $out['errors'][] = $e->getMessage();

        return $out;
    }
    if ($rows === []) {
        $out['errors'][] = 'File Excel kosong.';

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
            $nomor = ($jenis === 'ESAI' ? count($out['esai']) : count($out['pg'])) + 1;
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
            $out['pg'][$nomor] = [
                'teks' => $teks,
                'a' => $opsi['a'] !== '' ? $opsi['a'] : null,
                'b' => $opsi['b'] !== '' ? $opsi['b'] : null,
                'c' => $opsi['c'] !== '' ? $opsi['c'] : null,
                'd' => $opsi['d'] !== '' ? $opsi['d'] : null,
                'e' => $opsi['e'] !== '' ? $opsi['e'] : null,
                'kunci' => strtoupper($kunci) !== '' ? strtoupper($kunci) : null,
                'bobot' => 100.0,
            ];
        }
    }

    return $out;
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_docx(string $filePath, int $maxPg, int $maxEsai): array
{
    $out = ['pg' => [], 'esai' => [], 'errors' => []];
    $text = ikhtibar_docx_extract_text($filePath);
    if ($text === '') {
        $out['errors'][] = 'Teks Word tidak terbaca. Pastikan file .docx valid.';

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

    return $out;
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
        $pg[$i] = [
            'teks' => $teks,
            'a' => trim((string) ($post['pg_a'][$i] ?? '')) ?: null,
            'b' => trim((string) ($post['pg_b'][$i] ?? '')) ?: null,
            'c' => trim((string) ($post['pg_c'][$i] ?? '')) ?: null,
            'd' => trim((string) ($post['pg_d'][$i] ?? '')) ?: null,
            'e' => trim((string) ($post['pg_e'][$i] ?? '')) ?: null,
            'kunci' => strtoupper(trim((string) ($post['pg_kunci'][$i] ?? ''))) ?: null,
            'bobot' => 100.0,
        ];
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
    $pdo->prepare('DELETE FROM ikhtibar_soal WHERE tugas_id = :id')->execute(['id' => $tugasId]);
    $ins = $pdo->prepare('
        INSERT INTO ikhtibar_soal (tugas_id, jenis, nomor, teks_soal, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_nilai)
        VALUES (:tid,:jenis,:nom,:teks,:a,:b,:c,:d,:e,:kunci,:bobot)
    ');
    foreach ($soal['pg'] as $nom => $row) {
        $teks = trim((string) ($row['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $ins->execute([
            'tid' => $tugasId, 'jenis' => 'PG', 'nom' => (int) $nom, 'teks' => $teks,
            'a' => $row['a'] ?? null, 'b' => $row['b'] ?? null, 'c' => $row['c'] ?? null,
            'd' => $row['d'] ?? null, 'e' => $row['e'] ?? null,
            'kunci' => $row['kunci'] ?? null,
            'bobot' => (float) ($row['bobot'] ?? 100),
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
        ]);
    }
}

/**
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal
 * @return array{ok:bool,message:string}
 */
function ikhtibar_validasi_soal_publish(array $soal): array
{
    $total = count($soal['pg']) + count($soal['esai']);
    if ($total < 1) {
        return ['ok' => false, 'message' => 'Publikasikan membutuhkan minimal satu soal.'];
    }
    foreach ($soal['pg'] as $nom => $row) {
        $kunci = strtoupper(trim((string) ($row['kunci'] ?? '')));
        if ($kunci === '' || !in_array($kunci, ['A', 'B', 'C', 'D', 'E'], true)) {
            return ['ok' => false, 'message' => 'Soal PG nomor ' . $nom . ' wajib memiliki kunci A–E.'];
        }
        foreach (['a', 'b', 'c', 'd'] as $opt) {
            if (trim((string) ($row[$opt] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Soal PG nomor ' . $nom . ' wajib memiliki opsi A–D.'];
            }
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
