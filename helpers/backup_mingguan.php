<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db_backup_csv.php';
require_once __DIR__ . '/backup_crypto.php';
require_once __DIR__ . '/backup_mail.php';

function backup_mingguan_attachment_limit(): int
{
    return 20 * 1024 * 1024;
}

function backup_mingguan_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backup';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat folder backup.');
    }

    return $dir;
}

function backup_mingguan_prune(string $dir): void
{
    $files = glob($dir . DIRECTORY_SEPARATOR . 'Backup_Lengkap_*.pnm1');
    if ($files === false || count($files) <= 4) {
        return;
    }
    sort($files, SORT_STRING);
    $drop = array_slice($files, 0, count($files) - 4);
    foreach ($drop as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @return array{host:string, port:int, user:string, password:string, from:string, admin:string, ketua:string}
 */
function backup_mingguan_mail_config(PDO $pdo): array
{
    $host = trim((string) app_setting($pdo, 'backup_mingguan_smtp_host', ''));
    $port = (int) app_setting($pdo, 'backup_mingguan_smtp_port', '587');
    $user = trim((string) app_setting($pdo, 'backup_mingguan_smtp_user', ''));
    $password = (string) app_setting($pdo, 'backup_mingguan_smtp_password', '');
    $from = trim((string) app_setting($pdo, 'backup_mingguan_smtp_from', ''));
    $admin = trim((string) app_setting($pdo, 'backup_mingguan_email_admin', ''));
    $ketua = trim((string) app_setting($pdo, 'backup_mingguan_email_ketua', ''));
    if ($host === '' || $from === '' || $admin === '' || $ketua === '') {
        throw new RuntimeException('SMTP, email admin, atau email Ketua belum diisi.');
    }
    if ($user === '' || $password === '') {
        throw new RuntimeException('User atau sandi SMTP belum diisi.');
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'password' => $password,
        'from' => $from,
        'admin' => $admin,
        'ketua' => $ketua,
    ];
}

function backup_mingguan_cron_url(PDO $pdo): string
{
    require_once __DIR__ . '/app_path.php';
    $key = trim((string) app_setting($pdo, 'backup_mingguan_cron_key', ''));
    $path = app_href('/cron/backup_mingguan.php') . ($key !== '' ? ('?key=' . rawurlencode($key)) : '');

    return rtrim(app_public_url(), '/') . $path;
}

function backup_mingguan_notify_failure(PDO $pdo, string $reason): void
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
    if (strlen($reason) > 300) {
        $reason = substr($reason, 0, 300);
    }
    $channels = [];
    $wa = trim((string) app_setting($pdo, 'backup_mingguan_wa_gagal', ''));
    if ($wa !== '') {
        try {
            require_once __DIR__ . '/wa_otomatis.php';
            $sent = wa_otomatis_send($pdo, $wa, "Backup mingguan gagal.\n" . $reason, [
                'dispatch_immediate' => true,
            ]);
            if (!empty($sent['success'])) {
                $channels[] = 'WhatsApp';
            }
        } catch (Throwable $e) {
        }
    }
    try {
        $cfg = backup_mingguan_mail_config($pdo);
        backup_mail_send(
            $cfg['host'],
            $cfg['port'],
            $cfg['user'],
            $cfg['password'],
            $cfg['from'],
            [$cfg['admin']],
            'Backup mingguan gagal',
            "Backup mingguan gagal.\n" . $reason . "\n\nArsip tidak dikirim ke Google."
        );
        $channels[] = 'email';
    } catch (Throwable $e) {
    }
    $note = $reason;
    if ($channels === []) {
        $note .= ' Kabar WhatsApp dan email tidak terkirim.';
    }
    save_setting($pdo, 'backup_mingguan_last_error', $note);
    save_setting($pdo, 'backup_mingguan_last_ok', '');
}

/**
 * @param list<array{path:string, filename:string, mime:string}> $attachments
 */
function backup_mingguan_mail_people(PDO $pdo, array $cfg, array $to, string $subject, string $body, array $attachments = []): void
{
    backup_mail_send(
        $cfg['host'],
        $cfg['port'],
        $cfg['user'],
        $cfg['password'],
        $cfg['from'],
        $to,
        $subject,
        $body,
        $attachments
    );
}

/**
 * @return array{ok:bool, mode:string, note:string}
 */
function backup_mingguan_run(PDO $pdo, bool $force = false): array
{
    @set_time_limit(0);
    $hari = (int) app_setting($pdo, 'backup_mingguan_hari', '0');
    if ($hari < 0 || $hari > 6) {
        $hari = 0;
    }
    $today = date('Y-m-d');
    if (!$force) {
        if ((int) date('w') !== $hari) {
            return ['ok' => true, 'mode' => 'idle', 'note' => 'bukan hari jadwal'];
        }
        if ((string) app_setting($pdo, 'backup_mingguan_last_date', '') === $today) {
            return ['ok' => true, 'mode' => 'idle', 'note' => 'sudah jalan hari ini'];
        }
        $attempt = trim((string) app_setting($pdo, 'backup_mingguan_last_attempt_at', ''));
        if ($attempt !== '') {
            $attemptTs = strtotime($attempt);
            if ($attemptTs !== false && (time() - $attemptTs) < 6 * 3600) {
                return ['ok' => true, 'mode' => 'idle', 'note' => 'ditunda setelah percobaan terakhir'];
            }
        }
    }

    if (!$force) {
        save_setting($pdo, 'backup_mingguan_last_attempt_at', date('Y-m-d H:i:s'));
    }
    $plainDir = '';
    $inspectDir = '';
    $tmpSealed = '';
    try {
        $pem = backup_crypto_assert_public_only((string) app_setting($pdo, 'backup_mingguan_public_key', ''));
        $cfg = backup_mingguan_mail_config($pdo);
        $built = db_backup_csv_build($pdo, false);
        $plainDir = $built['dir'];
        $dir = backup_mingguan_dir();
        $sealedName = 'Backup_Lengkap_' . $today . '.pnm1';
        $sealedPath = $dir . DIRECTORY_SEPARATOR . $sealedName;
        $tmpSealed = $sealedPath . '.tmp';
        if (is_file($tmpSealed)) {
            @unlink($tmpSealed);
        }
        backup_crypto_seal_file($built['zip'], $tmpSealed, $pem);
        db_backup_csv_cleanup($plainDir);
        $plainDir = '';
        if (is_file($sealedPath)) {
            @unlink($sealedPath);
        }
        if (!rename($tmpSealed, $sealedPath)) {
            throw new RuntimeException('Gagal menyimpan arsip terkunci.');
        }
        $tmpSealed = '';
        backup_mingguan_prune($dir);

        $inspect = db_backup_csv_build($pdo, true);
        $inspectDir = $inspect['dir'];
        $inspectName = 'Backup_Periksa_' . $today . '.zip';
        $limit = backup_mingguan_attachment_limit();
        $inspectSize = (int) filesize($inspect['zip']);

        if ($inspectSize > $limit) {
            backup_mingguan_mail_people(
                $pdo,
                $cfg,
                [$cfg['admin']],
                'ZIP periksa backup terlalu besar',
                "ZIP periksa tanggal {$today} berukuran " . (string) $inspectSize . " byte, di atas batas lampiran 20 MB. Berkas tidak dilampirkan.\nArsip lengkap terkunci ({$sealedName}) tetap di folder backup hosting. Salin berkas itu ke luar server. Kunci pembuka dipegang Pengasuh dan Ketua. Arsip lengkap tidak dikirim lewat email."
            );
        } else {
            backup_mingguan_mail_people(
                $pdo,
                $cfg,
                [$cfg['admin']],
                'ZIP periksa backup mingguan',
                "Lampiran ini untuk diperiksa. Kolom identitas, kontak, pesan, kesehatan, foto, rekening, serta sandi dan kunci dikosongkan.\nBukan dump SQL dan bukan arsip lengkap.\nPemisah kolom titik koma (;). Lihat README.txt di dalam ZIP.",
                [[
                    'path' => $inspect['zip'],
                    'filename' => $inspectName,
                    'mime' => 'application/zip',
                ]]
            );
        }

        db_backup_csv_cleanup($inspectDir);
        $inspectDir = '';
        if (!$force) {
            save_setting($pdo, 'backup_mingguan_last_date', $today);
        }
        save_setting($pdo, 'backup_mingguan_last_run_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'backup_mingguan_last_error', '');
        save_setting($pdo, 'backup_mingguan_last_ok', $today . ' arsip ' . $sealedName);

        return ['ok' => true, 'mode' => 'sent', 'note' => $sealedName];
    } catch (Throwable $e) {
        if ($plainDir !== '') {
            db_backup_csv_cleanup($plainDir);
        }
        if ($inspectDir !== '') {
            db_backup_csv_cleanup($inspectDir);
        }
        if ($tmpSealed !== '' && is_file($tmpSealed)) {
            @unlink($tmpSealed);
        }
        backup_mingguan_notify_failure($pdo, $e->getMessage());

        return ['ok' => false, 'mode' => 'error', 'note' => $e->getMessage()];
    }
}
