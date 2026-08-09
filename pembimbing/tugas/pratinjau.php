<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/akademik_pkpps_tugas.php';
require_once __DIR__ . '/../../helpers/ikhtibar_preview.php';
require_once __DIR__ . '/../../helpers/ikhtibar_tugas_draf_pin.php';

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);

$tugasId = (int) ($_GET['tugas_id'] ?? $_GET['id'] ?? 0);
$tugas = $tugasId > 0 ? ikhtibar_tugas_by_id($pdo, $tugasId) : null;
if (!$tugas) {
    exit('Tugas tidak ditemukan.');
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$isOwner = (int) ($tugas['created_by'] ?? 0) === $userId;
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if (!$isOwner && !in_array($role, ['admin', 'pengurus'], true) && !is_super_admin()) {
    exit('Akses ditolak.');
}

if (pkpps_tugas_is_row($tugas)) {
    $backUrl = app_href('/pkpps/tugas/buat.php?id=' . $tugasId);
    $backLabel = 'Edit tugas PKPPS';
} else {
    ikhtibar_tugas_redirect_jika_pkpps($tugas);
    $backUrl = app_href('/pembimbing/tugas/buat.php?id=' . $tugasId);
    $backLabel = 'Edit tugas';
}

$selfUrl = app_href('/pembimbing/tugas/pratinjau.php?tugas_id=' . $tugasId);
ikhtibar_tugas_process_akses_pin_verify_post($pdo, $tugas, $selfUrl);

$tugas = ikhtibar_tugas_by_id($pdo, $tugasId) ?? $tugas;
if (ikhtibar_tugas_akses_pin_terkunci($tugas)) {
    $pageTitle = 'PIN Draf — Pratinjau';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require __DIR__ . '/../../includes/partials/app_vendor_assets.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>">
</head>
<body class="bg-light py-4">
<div class="container" style="max-width:28rem;">
    <?= ikhtibar_tugas_render_akses_pin_gate_html($tugas, $selfUrl, 'Muat ulang pratinjau', 'membuka pratinjau') ?>
</div>
</body>
</html>
    <?php
    exit;
}

$soalList = ikhtibar_soal_by_tugas($pdo, $tugasId);
ikhtibar_render_pratinjau_portal_page($tugas, $soalList, [
    'back_url' => $backUrl,
    'back_label' => $backLabel,
    'show_kunci' => true,
]);
