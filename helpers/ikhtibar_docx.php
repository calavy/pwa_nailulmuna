<?php

declare(strict_types=1);

/** Ekstrak teks dari .docx (Word) tanpa library eksternal. */
function ikhtibar_docx_extract_text(string $filePath): string
{
    if (!is_readable($filePath) || !class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false || $xml === '') {
        return '';
    }
    $xml = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml) ?? $xml;
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return trim($text);
}

/**
 * Parse teks mentah menjadi baris soal sederhana (PG: baris dengan A. B. C. atau kunci di akhir).
 *
 * @return list<array{teks:string,opsi:array<string,string>,kunci:string}>
 */
function ikhtibar_parse_teks_soal_pg(string $raw, int $maxSoal = 30): array
{
    $lines = preg_split('/\n+/', $raw) ?: [];
    $out = [];
    $current = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(?:soal|pertanyaan)?\s*(\d+)[.):\-]\s*(.*)$/iu', $line, $m)) {
            if ($current !== null) {
                $out[] = $current;
            }
            $current = ['teks' => trim($m[2]), 'opsi' => [], 'kunci' => ''];
            if (count($out) >= $maxSoal) {
                break;
            }
            continue;
        }
        if ($current === null) {
            $current = ['teks' => $line, 'opsi' => [], 'kunci' => ''];
            continue;
        }
        if (preg_match('/^([A-Ea-e])[.):\-]\s*(.+)$/u', $line, $om)) {
            $current['opsi'][strtoupper($om[1])] = trim($om[2]);
            continue;
        }
        if (preg_match('/^(?:kunci|jawaban|key)\s*[:=]\s*([A-Ea-e])/iu', $line, $km)) {
            $current['kunci'] = strtoupper($km[1]);
            continue;
        }
        $current['teks'] .= ' ' . $line;
    }
    if ($current !== null && count($out) < $maxSoal) {
        $out[] = $current;
    }

    return $out;
}

/**
 * @return list<array{teks:string,kunci:string}>
 */
function ikhtibar_parse_teks_soal_esai(string $raw, int $maxSoal = 15): array
{
    $lines = preg_split('/\n+/', $raw) ?: [];
    $out = [];
    $current = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(?:soal|pertanyaan)?\s*(\d+)[.):\-]\s*(.*)$/iu', $line, $m)) {
            if ($current !== null) {
                $out[] = $current;
            }
            $current = ['teks' => trim($m[2]), 'kunci' => ''];
            if (count($out) >= $maxSoal) {
                break;
            }
            continue;
        }
        if (preg_match('/^(?:kunci|jawaban|key)\s*[:=]\s*(.+)$/iu', $line, $km)) {
            if ($current !== null) {
                $current['kunci'] = trim($km[1]);
            }
            continue;
        }
        if ($current === null) {
            $current = ['teks' => $line, 'kunci' => ''];
        } else {
            $current['teks'] .= "\n" . $line;
        }
    }
    if ($current !== null && count($out) < $maxSoal) {
        $out[] = $current;
    }

    return $out;
}
