<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function cashless_wa_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'cashless_accounts')) {
        $done = true;

        return;
    }
    $done = true;
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        try {
            $pdo->exec('ALTER TABLE cashless_accounts ADD COLUMN saldo_rendah_wa_flag TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // kolom mungkin sudah ada tanpa terdeteksi
        }
    }
}

function cashless_wa_saldo_rendah_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'cashless_saldo_rendah_wa_enabled', '1')) === '1';
}

function cashless_wa_saldo_rendah_threshold(PDO $pdo): int
{
    return max(0, (int) app_setting($pdo, 'cashless_saldo_rendah_wa_ambang', '30000'));
}

function cashless_wa_reset_low_flag(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return;
    }
    $pdo->prepare('UPDATE cashless_accounts SET saldo_rendah_wa_flag = 0 WHERE santri_id = :id')->execute(['id' => $santriId]);
}

function cashless_wa_low_flag_sent(PDO $pdo, int $santriId): bool
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return false;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return false;
    }
    $st = $pdo->prepare('SELECT saldo_rendah_wa_flag FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (int) ($st->fetchColumn() ?: 0) === 1;
}

function cashless_wa_set_low_flag(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return;
    }
    $pdo->prepare('UPDATE cashless_accounts SET saldo_rendah_wa_flag = 1 WHERE santri_id = :id')->execute(['id' => $santriId]);
}

function wa_format_cashless_saldo_rendah_wali(
    PDO $pdo,
    string $namaSantri,
    int $saldoTersisa,
    int $ambang
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $saldoFmt = 'Rp ' . number_format($saldoTersisa, 0, ',', '.');
    $ambangFmt = 'Rp ' . number_format($ambang, 0, ',', '.');

    return wa_template_render($pdo, 'cashless_saldo_rendah_wali', [
        'nama_santri' => $namaSantri,
        'saldo_tersisa' => $saldoFmt,
        'ambang' => $ambangFmt,
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * Kirim WA ke wali jika saldo cashless turun ke ambang atau di bawahnya (sekali per periode rendah).
 */
function cashless_wa_maybe_notify_saldo_rendah(PDO $pdo, int $santriId, float|int $balanceAfter): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }

    cashless_wa_ensure_schema($pdo);
    $threshold = cashless_wa_saldo_rendah_threshold($pdo);
    $balanceInt = (int) round((float) $balanceAfter);

    if ($balanceInt > $threshold) {
        cashless_wa_reset_low_flag($pdo, $santriId);

        return;
    }

    if (!cashless_wa_saldo_rendah_enabled($pdo)) {
        return;
    }

    if (cashless_wa_low_flag_sent($pdo, $santriId)) {
        return;
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }

    $waliPhone = wa_otomatis_santri_wali_phone($pdo, $santriId);
    if ($waliPhone === '') {
        return;
    }

    $st = $pdo->prepare('SELECT COALESCE(NULLIF(nama_santri, ""), nama) AS nama_santri FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $namaSantri = trim((string) ($st->fetchColumn() ?: 'Santri'));

    $msg = wa_format_cashless_saldo_rendah_wali($pdo, $namaSantri, $balanceInt, $threshold);
    if (!send_wa_message($pdo, $waliPhone, $msg)) {
        return;
    }

    cashless_wa_set_low_flag($pdo, $santriId);
}
