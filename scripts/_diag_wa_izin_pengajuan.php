<?php

declare(strict_types=1);

/**
 * Diagnosa WA pengajuan izin (pengasuh syar'i vs permohonan pengurus).
 *
 *   php scripts/_diag_wa_izin_pengajuan.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/perizinan_jenis.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/push_fcm.php';

echo "=== WA Izin Pengajuan ===\n\n";

$master = trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) === '1';
echo 'Master WA otomatis: ' . ($master ? 'ON' : 'OFF') . "\n";

$gw = wa_otomatis_gateway_error($pdo);
echo 'Gateway: ' . ($gw === null ? 'OK' : ('ERROR — ' . $gw)) . "\n\n";

echo "--- Izin syar'i → pengasuh ---\n";
echo 'wa_izin_pengasuh_pending_enabled: ' . (wa_izin_pengasuh_pending_enabled($pdo) ? '1' : '0') . "\n";
echo 'Target pengasuh (kiai + extra): ' . (wa_pengasuh_pending_targets($pdo) !== '' ? wa_pengasuh_pending_targets($pdo) : '(kosong)') . "\n";
echo 'Kiai no_wa saja: ' . (wa_pengasuh_info_targets($pdo) !== '' ? wa_pengasuh_info_targets($pdo) : '(kosong)') . "\n";
echo 'Extra setting: ' . trim((string) app_setting($pdo, 'wa_izin_pengasuh_pending_extra', '')) . "\n\n";

echo "--- Sakit/keluar/tugas → permohonan pengurus ---\n";
echo 'wa_permohonan_izin_enabled: ' . app_setting($pdo, 'wa_permohonan_izin_enabled', '1') . "\n";
echo 'Jenis allowed: ' . implode(', ', wa_permohonan_izin_jenis_allowed_list($pdo)) . "\n";
echo 'should_notify(SYARI): ' . (wa_permohonan_izin_should_notify($pdo, 'SYARI') ? 'yes' : 'no') . " (syar'i pakai jalur pengasuh, bukan ini)\n";
echo 'should_notify(SAKIT): ' . (wa_permohonan_izin_should_notify($pdo, 'SAKIT') ? 'yes' : 'no') . "\n";
echo 'Target permohonan: ' . (wa_permohonan_izin_target($pdo) !== '' ? wa_permohonan_izin_target($pdo) : '(kosong — fallback alpa)') . "\n\n";

echo "fcm_notify_mode: " . push_notify_mode($pdo) . "\n";
echo "Done.\n";
