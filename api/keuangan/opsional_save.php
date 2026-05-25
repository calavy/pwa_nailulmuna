<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../../helpers/keuangan_dashboard.php';

require_login();
require_roles(['admin', 'pengurus']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_keuangan_santri_opsional_table($pdo);

$santriId = (int) ($_POST['santri_id'] ?? 0);
$slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
$aktif = !empty($_POST['aktif']) ? 1 : 0;
$nominalRaw = trim((string) ($_POST['nominal'] ?? ''));
$opsionalSlugs = keuangan_tagihan_opsional_bulanan_slugs();

if ($santriId <= 0 || !in_array($slug, $opsionalSlugs, true)) {
    echo json_encode(['ok' => false, 'error' => 'Santri atau pos tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nominal = null;
if ($nominalRaw !== '') {
    $digits = preg_replace('/[^0-9]/', '', $nominalRaw) ?? '';
    if ($digits !== '') {
        $nominal = max(0, (int) $digits);
    }
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO keuangan_santri_opsional (santri_id, slug, aktif, nominal)
         VALUES (:sid, :slug, :aktif, :nominal)
         ON DUPLICATE KEY UPDATE aktif = VALUES(aktif), nominal = VALUES(nominal)'
    );
    $stmt->bindValue(':sid', $santriId, PDO::PARAM_INT);
    $stmt->bindValue(':slug', $slug);
    $stmt->bindValue(':aktif', $aktif, PDO::PARAM_INT);
    if ($nominal === null) {
        $stmt->bindValue(':nominal', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':nominal', $nominal, PDO::PARAM_INT);
    }
    $stmt->execute();

    keuangan_santri_opsional_cache_invalidate();

    echo json_encode([
        'ok' => true,
        'santri_id' => $santriId,
        'slug' => $slug,
        'aktif' => (bool) $aktif,
        'nominal' => $nominal,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Gagal menyimpan pengaturan.'], JSON_UNESCAPED_UNICODE);
}
