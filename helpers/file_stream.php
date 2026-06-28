<?php

declare(strict_types=1);

/**
 * Stream file lokal dengan dukungan HTTP Range (penting untuk PDF di browser).
 *
 * @param array{mime?:string,filename?:string,download?:bool,cache_seconds?:int,not_found?:string} $opts
 */
function app_http_stream_file(string $absolutePath, array $opts = []): never
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404);
        exit($opts['not_found'] ?? 'File tidak ditemukan.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');

    $size = (int) filesize($absolutePath);
    if ($size <= 0) {
        http_response_code(404);
        exit($opts['not_found'] ?? 'File tidak ditemukan.');
    }

    $mime = (string) ($opts['mime'] ?? 'application/octet-stream');
    $filename = (string) ($opts['filename'] ?? basename($absolutePath));
    $filename = str_replace(['"', "\r", "\n"], '', $filename);
    $download = !empty($opts['download']);
    $cacheSeconds = max(0, (int) ($opts['cache_seconds'] ?? 86400));
    $mtime = (int) filemtime($absolutePath);
    $etag = '"' . sha1($absolutePath . '|' . $size . '|' . $mtime) . '"';

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    if ($cacheSeconds > 0) {
        header('Cache-Control: private, max-age=' . $cacheSeconds);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
    } else {
        header('Cache-Control: private, no-cache');
    }

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }

    $disposition = ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"';
    $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');

    if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
        $start = $m[1] !== '' ? (int) $m[1] : 0;
        $end = $m[2] !== '' ? (int) $m[2] : ($size - 1);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $end = min($end, $size - 1);
        $length = $end - $start + 1;

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . (string) $length);
        header('Content-Disposition: ' . $disposition);

        $fp = fopen($absolutePath, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit('Gagal membaca file.');
        }
        fseek($fp, $start);
        $remaining = $length;
        $chunkSize = 1024 * 256;
        while ($remaining > 0 && !feof($fp)) {
            $read = (int) min($chunkSize, $remaining);
            $buf = fread($fp, $read);
            if ($buf === false || $buf === '') {
                break;
            }
            echo $buf;
            $remaining -= strlen($buf);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
        exit;
    }

    header('Content-Length: ' . (string) $size);
    header('Content-Disposition: ' . $disposition);

    $fp = fopen($absolutePath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Gagal membaca file.');
    }
    $chunkSize = 1024 * 512;
    while (!feof($fp)) {
        $buf = fread($fp, $chunkSize);
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        if (connection_aborted()) {
            break;
        }
    }
    fclose($fp);
    exit;
}
