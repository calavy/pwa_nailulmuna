<?php

declare(strict_types=1);

/**
 * CLI: sesuaikan pembayaran & presensi lama ke kalender Hijriyah pondok.
 * Usage: php scripts/pondok_backfill_kalender_hijriyah.php [--force]
 */

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/helpers/pondok_kalender.php';

$force = in_array('--force', $argv ?? [], true);

if (!pondok_kalender_hijriyah($pdo)) {
    fwrite(STDERR, "Kalender pondok bukan HIJRIYAH (wa_tagihan_calendar). Ubah di pengaturan pondok dulu.\n");
    exit(1);
}

$bf = pondok_backfill_kalender_hijriyah($pdo, $force);

echo "Backfill kalender Hijriyah selesai.\n";
echo "  Pembayaran diperbarui: {$bf['pembayaran']}\n";
echo "  Presensi diperbarui:    {$bf['presensi']}\n";
echo "  Jeda potongan:         {$bf['jeda']}\n";
echo "  Dilewati:              {$bf['skipped']}\n";

exit(0);
