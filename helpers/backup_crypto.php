<?php

declare(strict_types=1);

/**
 * Mengunci berkas dengan kunci publik saja.
 * Format PNM1: magic, panjang kunci AES yang terbungkus, RSA-OAEP(aes||hmac), IV, ciphertext AES-256-CBC, HMAC-SHA256.
 * Aplikasi ini tidak membuka arsip dan tidak menyimpan kunci pembuka.
 */

function backup_crypto_assert_public_only(string $pem): string
{
    $pem = trim($pem);
    if ($pem === '') {
        throw new RuntimeException('Kunci publik kosong.');
    }
    if (preg_match('/PRIVATE\s+KEY/i', $pem)) {
        throw new RuntimeException('Kunci pembuka tidak boleh disimpan di server. Tempel hanya kunci publik.');
    }
    if (!preg_match('/BEGIN (RSA )?PUBLIC KEY/', $pem)) {
        throw new RuntimeException('Tempel kunci publik PEM (BEGIN PUBLIC KEY).');
    }
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new RuntimeException('Kunci publik tidak valid.');
    }
    $details = openssl_pkey_get_details($key);
    $bits = (int) ($details['bits'] ?? 0);
    if ($bits < 2048) {
        throw new RuntimeException('Kunci publik minimal 2048 bit.');
    }

    return $pem;
}

function backup_crypto_fingerprint(string $pem): string
{
    $pem = backup_crypto_assert_public_only($pem);

    return strtoupper(substr(hash('sha256', $pem), 0, 16));
}

function backup_crypto_cbc_blocks(string $plainBlocks, string $key, string &$iv): string
{
    if ($plainBlocks === '' || strlen($plainBlocks) % 16 !== 0) {
        throw new RuntimeException('Blok enkripsi tidak lengkap.');
    }
    $cipher = openssl_encrypt($plainBlocks, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if (!is_string($cipher) || $cipher === '' || strlen($cipher) !== strlen($plainBlocks)) {
        throw new RuntimeException('Gagal mengunci berkas.');
    }
    $iv = substr($cipher, -16);

    return $cipher;
}

function backup_crypto_seal_file(string $plainPath, string $destPath, string $publicPem): void
{
    if (!function_exists('openssl_public_encrypt')) {
        throw new RuntimeException('OpenSSL tidak tersedia di PHP.');
    }
    $publicPem = backup_crypto_assert_public_only($publicPem);
    $publicKey = openssl_pkey_get_public($publicPem);
    if ($publicKey === false) {
        throw new RuntimeException('Kunci publik tidak bisa dipakai.');
    }
    if (!is_file($plainPath)) {
        throw new RuntimeException('Berkas yang akan dikunci tidak ada.');
    }

    $aes = random_bytes(32);
    $mac = random_bytes(32);
    $wrapped = '';
    $sealed = openssl_public_encrypt($aes . $mac, $wrapped, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
    if (!$sealed || $wrapped === '') {
        throw new RuntimeException('Gagal mengunci kunci AES dengan kunci publik.');
    }
    if (strlen($wrapped) > 65535) {
        throw new RuntimeException('Kunci publik terlalu besar untuk format arsip.');
    }

    $in = fopen($plainPath, 'rb');
    $out = fopen($destPath, 'wb');
    if ($in === false || $out === false) {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        throw new RuntimeException('Gagal membuka berkas untuk dikunci.');
    }

    $iv = random_bytes(16);
    $header = 'PNM1' . pack('n', strlen($wrapped)) . $wrapped . $iv;
    if (fwrite($out, $header) !== strlen($header)) {
        fclose($in);
        fclose($out);
        @unlink($destPath);
        throw new RuntimeException('Gagal menulis arsip terkunci.');
    }

    $hmac = hash_init('sha256', HASH_HMAC, $mac);
    if ($hmac === false) {
        fclose($in);
        fclose($out);
        @unlink($destPath);
        throw new RuntimeException('Gagal menyiapkan tanda arsip.');
    }
    hash_update($hmac, $iv);
    $buf = '';
    $chunk = 1024 * 1024;
    try {
        while (true) {
            $read = fread($in, $chunk);
            if ($read === false) {
                throw new RuntimeException('Gagal membaca berkas yang akan dikunci.');
            }
            $eof = $read === '' || feof($in);
            if ($read !== '') {
                $buf .= $read;
            }
            if (!$eof) {
                $n = intdiv(strlen($buf), 16) * 16;
                if ($n > 0) {
                    $piece = substr($buf, 0, $n);
                    $buf = substr($buf, $n);
                    $enc = backup_crypto_cbc_blocks($piece, $aes, $iv);
                    hash_update($hmac, $enc);
                    if (fwrite($out, $enc) !== strlen($enc)) {
                        throw new RuntimeException('Gagal menulis arsip terkunci.');
                    }
                }
                continue;
            }
            $pad = 16 - (strlen($buf) % 16);
            if ($pad === 0) {
                $pad = 16;
            }
            $buf .= str_repeat(chr($pad), $pad);
            $enc = backup_crypto_cbc_blocks($buf, $aes, $iv);
            hash_update($hmac, $enc);
            if (fwrite($out, $enc) !== strlen($enc)) {
                throw new RuntimeException('Gagal menulis arsip terkunci.');
            }
            $digest = hash_final($hmac, true);
            if (fwrite($out, $digest) !== 32) {
                throw new RuntimeException('Gagal menulis tanda arsip terkunci.');
            }
            break;
        }
    } catch (Throwable $e) {
        fclose($in);
        fclose($out);
        @unlink($destPath);
        throw $e;
    }
    fclose($in);
    fclose($out);
}
