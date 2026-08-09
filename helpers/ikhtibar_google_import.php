<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/ikhtibar_import.php';

/** URL template Google Sheets (opsional, dari pengaturan). */
function ikhtibar_google_sheets_template_url(PDO $pdo): string
{
    return trim((string) app_setting($pdo, 'ikhtibar_google_sheets_template_url', ''));
}

function ikhtibar_google_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    return mb_substr($url, 0, 500);
}

/** @return array{id:string,gid:string}|null */
function ikhtibar_google_sheet_parts(string $url): ?array
{
    $url = ikhtibar_google_sanitize_url($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
        return null;
    }
    $gid = '0';
    if (preg_match('#[?&]gid=(\d+)#', $url, $gm)) {
        $gid = $gm[1];
    }

    return ['id' => $m[1], 'gid' => $gid];
}

/** @return array{id:string}|null */
function ikhtibar_google_doc_parts(string $url): ?array
{
    $url = ikhtibar_google_sanitize_url($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#docs\.google\.com/document/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
        return null;
    }

    return ['id' => $m[1]];
}

function ikhtibar_google_fetch_url(string $url, int $timeoutSec = 25): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi cURL tidak tersedia di server.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PWA-NailulMuna/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException($err !== '' ? $err : 'Gagal mengunduh (HTTP ' . $code . '). Pastikan link dibagikan: Anyone with the link can view.');
    }

    return (string) $body;
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_google_sheet(PDO $pdo, string $url, int $maxPg, int $maxEsai): array
{
    $parts = ikhtibar_google_sheet_parts($url);
    if ($parts === null) {
        return ['pg' => [], 'esai' => [], 'errors' => ['Link Google Sheets tidak valid.']];
    }
    $exportUrl = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($parts['id'])
        . '/export?format=csv&gid=' . rawurlencode($parts['gid']);

    try {
        $csv = ikhtibar_google_fetch_url($exportUrl);
    } catch (Throwable $e) {
        return ['pg' => [], 'esai' => [], 'errors' => ['Google Sheets: ' . $e->getMessage()]];
    }

    $rows = ikhtibar_parse_csv_to_rows($csv);
    $result = ikhtibar_import_soal_dari_rows($rows, $maxPg, $maxEsai);
    if (($result['pg'] ?? []) === [] && ($result['esai'] ?? []) === []) {
        $result['errors'][] = 'Google Sheets: tidak ada soal terbaca. Pastikan kolom mengikuti template (jenis, nomor, teks, opsi_a…).';
    }

    return $result;
}

/**
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_import_soal_dari_google_doc(PDO $pdo, string $url, int $maxPg, int $maxEsai): array
{
    $parts = ikhtibar_google_doc_parts($url);
    if ($parts === null) {
        return ['pg' => [], 'esai' => [], 'errors' => ['Link Google Docs tidak valid.']];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ikhtibar_gdoc_');
    if ($tmp === false) {
        return ['pg' => [], 'esai' => [], 'errors' => ['Gagal menyiapkan file sementara.']];
    }

    try {
        $docxUrl = 'https://docs.google.com/document/d/' . rawurlencode($parts['id']) . '/export?format=docx';
        $bin = ikhtibar_google_fetch_url($docxUrl);
        file_put_contents($tmp, $bin);
        $result = ikhtibar_import_soal_dari_docx($tmp, $maxPg, $maxEsai);
    } catch (Throwable $e) {
        try {
            $txtUrl = 'https://docs.google.com/document/d/' . rawurlencode($parts['id']) . '/export?format=txt';
            $text = ikhtibar_google_fetch_url($txtUrl);
            $result = ikhtibar_import_soal_dari_teks($text, $maxPg, $maxEsai);
        } catch (Throwable $e2) {
            $result = ['pg' => [], 'esai' => [], 'errors' => ['Google Docs: ' . $e2->getMessage()]];
        }
    } finally {
        @unlink($tmp);
    }

    if (($result['pg'] ?? []) === [] && ($result['esai'] ?? []) === [] && ($result['errors'] ?? []) === []) {
        $result['errors'][] = 'Google Docs: tidak ada soal terbaca. Gunakan format nomor soal, opsi A–D, baris kunci: A.';
    }

    return $result;
}
