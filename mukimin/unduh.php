<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/excel.php';
require_once __DIR__ . '/../helpers/mukimin_portal.php';

ensure_mukimin_portal_columns($pdo);

if (!isset($_SESSION['mukimin']['alumni_id'])) {
    header('Location: /pwa_nailulmuna/mukimin/login.php');
    exit;
}

$alumniId = (int) $_SESSION['mukimin']['alumni_id'];
$st = $pdo->prepare('SELECT * FROM akademik_alumni WHERE id = :id LIMIT 1');
$st->execute(['id' => $alumniId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    header('Location: /pwa_nailulmuna/mukimin/login.php');
    exit;
}

$fn = 'Data_Mukimin_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $row['nis']) . '.xlsx';
send_xlsx_download($fn, alumni_db_rows_to_xlsx([$row]), 'Mukimin');
