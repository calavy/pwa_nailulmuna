<?php

declare(strict_types=1);

/**
 * Pengirim SMTP kecil, tanpa library.
 * Password di sini adalah sandi kotak surat, bukan kunci pembuka backup.
 */

/**
 * @param resource $fp
 */
function backup_mail_read($fp): string
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    if ($data === '') {
        throw new RuntimeException('SMTP tidak menjawab.');
    }

    return $data;
}

/**
 * @param resource $fp
 * @param list<int> $okCodes
 */
function backup_mail_cmd($fp, string $cmd, array $okCodes): string
{
    if (fwrite($fp, $cmd . "\r\n") === false) {
        throw new RuntimeException('Gagal menulis ke SMTP.');
    }
    $reply = backup_mail_read($fp);
    $code = (int) substr($reply, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        $brief = trim(preg_replace('/\s+/', ' ', $reply) ?? $reply);
        if (strlen($brief) > 180) {
            $brief = substr($brief, 0, 180);
        }
        throw new RuntimeException('SMTP menolak perintah (' . $code . '): ' . $brief);
    }

    return $reply;
}

function backup_mail_header_encode(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    return $value;
}

function backup_mail_assert_address(string $email): string
{
    $email = trim($email);
    if (preg_match('/[\r\n]/', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Alamat email tidak valid.');
    }

    return $email;
}

/**
 * @param list<string> $toList
 * @param list<array{path:string, filename:string, mime:string}> $attachments
 */
function backup_mail_send(
    string $host,
    int $port,
    string $user,
    string $password,
    string $from,
    array $toList,
    string $subject,
    string $bodyText,
    array $attachments = []
): void {
    $host = trim($host);
    if ($host === '' || preg_match('/[\r\n\s]/', $host)) {
        throw new RuntimeException('Host SMTP kosong atau tidak valid.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Port SMTP tidak valid.');
    }
    $from = backup_mail_assert_address($from);
    $recipients = [];
    foreach ($toList as $to) {
        $recipients[] = backup_mail_assert_address((string) $to);
    }
    $recipients = array_values(array_unique($recipients));
    if ($recipients === []) {
        throw new RuntimeException('Penerima email kosong.');
    }
    if (!function_exists('stream_socket_client')) {
        throw new RuntimeException('stream_socket_client tidak tersedia.');
    }

    $remote = ($port === 465 ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ],
    ]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if ($fp === false) {
        throw new RuntimeException('Tidak terhubung ke SMTP: ' . ($errstr !== '' ? $errstr : 'kode ' . $errno));
    }
    stream_set_timeout($fp, 30);

    try {
        $greet = backup_mail_read($fp);
        if ((int) substr($greet, 0, 3) !== 220) {
            throw new RuntimeException('SMTP tidak siap.');
        }
        $ehloHost = 'localhost';
        backup_mail_cmd($fp, 'EHLO ' . $ehloHost, [250]);
        if ($port !== 465) {
            backup_mail_cmd($fp, 'STARTTLS', [220]);
            $cryptoOk = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoOk !== true) {
                throw new RuntimeException('STARTTLS gagal.');
            }
            backup_mail_cmd($fp, 'EHLO ' . $ehloHost, [250]);
        }
        if ($user !== '' || $password !== '') {
            backup_mail_cmd($fp, 'AUTH LOGIN', [334]);
            backup_mail_cmd($fp, base64_encode($user), [334]);
            backup_mail_cmd($fp, base64_encode($password), [235]);
        }
        backup_mail_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($recipients as $rcpt) {
            backup_mail_cmd($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
        }
        backup_mail_cmd($fp, 'DATA', [354]);

        $boundary = 'pnm_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $from,
            'To: ' . implode(', ', $recipients),
            'Subject: ' . backup_mail_header_encode($subject),
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
        ];
        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $payload = backup_mail_dotstuff($bodyText);
        } else {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $payload = "--{$boundary}\r\n";
            $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $payload .= backup_mail_dotstuff($bodyText) . "\r\n";
            foreach ($attachments as $file) {
                $path = (string) ($file['path'] ?? '');
                $filename = (string) ($file['filename'] ?? 'lampiran.bin');
                $mime = (string) ($file['mime'] ?? 'application/octet-stream');
                if ($filename === '' || preg_match('/[\r\n"]/', $filename)) {
                    throw new RuntimeException('Nama lampiran tidak valid.');
                }
                if (!is_file($path)) {
                    throw new RuntimeException('Lampiran email tidak ditemukan.');
                }
                $payload .= "--{$boundary}\r\n";
                $payload .= 'Content-Type: ' . $mime . '; name="' . $filename . "\"\r\n";
                $payload .= "Content-Transfer-Encoding: base64\r\n";
                $payload .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
                $raw = file_get_contents($path);
                if ($raw === false) {
                    throw new RuntimeException('Lampiran email tidak bisa dibaca.');
                }
                $payload .= chunk_split(base64_encode($raw), 76, "\r\n");
            }
            $payload .= "--{$boundary}--\r\n";
        }
        if (fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $payload . "\r\n.\r\n") === false) {
            throw new RuntimeException('Gagal mengirim isi email.');
        }
        $dataReply = backup_mail_read($fp);
        if ((int) substr($dataReply, 0, 3) !== 250) {
            throw new RuntimeException('SMTP menolak isi email.');
        }
        backup_mail_cmd($fp, 'QUIT', [221, 250]);
    } finally {
        fclose($fp);
    }
}

function backup_mail_dotstuff(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if (isset($line[0]) && $line[0] === '.') {
            $lines[$i] = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}
