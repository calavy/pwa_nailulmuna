<?php

declare(strict_types=1);

/**
 * Dibuka di komputer admin atau Ketua, bukan lewat browser.
 * Kunci pembuka tidak boleh berada di folder aplikasi.
 *
 * php tools/buka_backup_mingguan.php --kunci=kunci_pembuka.pem --berkas=Backup_Lengkap_YYYY-MM-DD.pnm1 --keluar=backup.zip
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Alat ini hanya dijalankan di komputer Anda, bukan lewat browser.\n");
    exit(1);
}

function buka_backup_arg(string $name): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return '';
}

function buka_backup_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$keyPath = buka_backup_arg('kunci');
$inPath = buka_backup_arg('berkas');
$outPath = buka_backup_arg('keluar');
if ($keyPath === '' || $inPath === '' || $outPath === '') {
    buka_backup_fail('Pakai --kunci=... --berkas=... --keluar=...');
}
if (!is_file($keyPath) || !is_file($inPath)) {
    buka_backup_fail('Berkas kunci atau arsip tidak ditemukan.');
}

$root = realpath(dirname(__DIR__));
$keyReal = realpath($keyPath);
if ($root !== false && $keyReal !== false) {
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $keyNorm = str_replace('\\', '/', $keyReal);
    if (str_starts_with($keyNorm, $rootNorm)) {
        buka_backup_fail('Kunci pembuka tidak boleh berada di folder aplikasi.');
    }
}

$pem = file_get_contents($keyPath);
if ($pem === false || !preg_match('/PRIVATE\s+KEY/', $pem)) {
    buka_backup_fail('Berkas kunci pembuka tidak valid.');
}
$private = openssl_pkey_get_private($pem);
if ($private === false) {
    buka_backup_fail('Kunci pembuka ditolak OpenSSL. Jangan menyalinnya ke server.');
}

$in = fopen($inPath, 'rb');
if ($in === false) {
    buka_backup_fail('Arsip tidak bisa dibaca.');
}
$magic = fread($in, 4);
$lenRaw = fread($in, 2);
if ($magic !== 'PNM1' || $lenRaw === false || strlen($lenRaw) !== 2) {
    fclose($in);
    buka_backup_fail('Ini bukan arsip backup PNM1.');
}
$wrappedLen = unpack('n', $lenRaw);
$len = (int) ($wrappedLen[1] ?? 0);
$size = filesize($inPath);
if ($len < 1 || $size === false || $size < 6 + $len + 16 + 16 + 32) {
    fclose($in);
    buka_backup_fail('Arsip terkunci terlalu pendek atau rusak.');
}
$wrapped = fread($in, $len);
$iv = fread($in, 16);
if ($wrapped === false || $iv === false || strlen($wrapped) !== $len || strlen($iv) !== 16) {
    fclose($in);
    buka_backup_fail('Kepala arsip rusak.');
}
$material = '';
if (!openssl_private_decrypt($wrapped, $material, $private, OPENSSL_PKCS1_OAEP_PADDING) || strlen($material) !== 64) {
    fclose($in);
    buka_backup_fail('Kunci pembuka ini tidak cocok dengan arsip.');
}
$aes = substr($material, 0, 32);
$macKey = substr($material, 32, 32);
$cipherLen = $size - 6 - $len - 16 - 32;
if ($cipherLen < 16 || $cipherLen % 16 !== 0) {
    fclose($in);
    buka_backup_fail('Panjang isi arsip tidak valid.');
}
$hmac = hash_init('sha256', HASH_HMAC, $macKey);
hash_update($hmac, $iv);
$left = $cipherLen;
while ($left > 0) {
    $chunk = fread($in, (int) min(1024 * 1024, $left));
    if ($chunk === false || $chunk === '') {
        fclose($in);
        buka_backup_fail('Arsip terpotong.');
    }
    hash_update($hmac, $chunk);
    $left -= strlen($chunk);
}
$digest = fread($in, 32);
fclose($in);
$expect = hash_final($hmac, true);
if ($digest === false || strlen($digest) !== 32 || !hash_equals($expect, $digest)) {
    buka_backup_fail('Arsip rusak atau tidak utuh.');
}

$in = fopen($inPath, 'rb');
$out = fopen($outPath, 'wb');
if ($in === false || $out === false) {
    buka_backup_fail('Gagal menulis ZIP hasil buka.');
}
fseek($in, 6 + $len + 16);
$left = $cipherLen;
$blockIv = $iv;
try {
    while ($left > 0) {
        $take = (int) min(1024 * 1024, $left);
        $take -= $take % 16;
        if ($take === 0) {
            $take = 16;
        }
        $chunk = fread($in, $take);
        if ($chunk === false || strlen($chunk) !== $take) {
            throw new RuntimeException('Arsip terpotong saat dibuka.');
        }
        $plain = openssl_decrypt($chunk, 'aes-256-cbc', $aes, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $blockIv);
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('Isi arsip tidak bisa dibuka.');
        }
        $blockIv = substr($chunk, -16);
        $left -= $take;
        if ($left === 0) {
            $pad = ord(substr($plain, -1));
            if ($pad < 1 || $pad > 16) {
                throw new RuntimeException('Isi arsip tidak bisa dibuka.');
            }
            $plain = substr($plain, 0, -$pad);
        }
        if ($plain !== '' && fwrite($out, $plain) !== strlen($plain)) {
            throw new RuntimeException('Gagal menulis ZIP hasil buka.');
        }
    }
} catch (Throwable $e) {
    fclose($in);
    fclose($out);
    @unlink($outPath);
    buka_backup_fail($e->getMessage());
}
fclose($in);
fclose($out);
fwrite(STDOUT, "Terbuka: {$outPath}\n");
