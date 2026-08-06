<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_nomor.php';

wa_nomor_ensure_schema($pdo);
wa_nomor_migrate_legacy($pdo);

$out = [
    'wa_pengurus_setting' => app_setting($pdo, 'wa_pengurus', ''),
    'wa_nomor_pengurus' => wa_nomor_targets($pdo, 'pengurus'),
    'wa_alpa_notif_target' => wa_alpa_notif_target($pdo),
    'wa_nomor_counts' => wa_nomor_count_by_peran($pdo),
];

file_put_contents(__DIR__ . '/_diag_wa_nomor.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "OK\n";
