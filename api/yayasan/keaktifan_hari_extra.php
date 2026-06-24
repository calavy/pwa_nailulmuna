<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan_hari.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);
$tkFilter = $tingkatan !== '' ? $tingkatan : null;

$extra = rekap_keaktifan_hari_extra_pack($pdo, $tanggal, $tkFilter, $kategori);

ob_start();
$kegiatanKosong = (array) ($extra['kegiatan_kosong'] ?? []);
?>
<?php if ($kegiatanKosong === []): ?>
    <div class="yp-empty-inline">Tidak ada kegiatan kosong. Semua kegiatan memiliki progres kehadiran.</div>
<?php else: ?>
    <div class="yp-kosong-grid">
        <?php foreach ($kegiatanKosong as $kk): ?>
            <article class="yp-kosong-card">
                <div class="yp-kosong-card__title"><?= htmlspecialchars((string) ($kk['nama_kegiatan'] ?? 'Kegiatan')) ?></div>
                <div class="yp-kosong-card__meta">
                    <?= htmlspecialchars((string) ($kk['jam_mulai'] ?? '--:--')) ?>-<?= htmlspecialchars((string) ($kk['jam_selesai'] ?? '--:--')) ?>
                    · <?= htmlspecialchars((string) (($kk['tingkatan'] ?? '') !== '' ? $kk['tingkatan'] : '-')) ?>
                    <?php if (!empty($kk['tempat'])): ?> · <?= htmlspecialchars((string) $kk['tempat']) ?><?php endif; ?>
                </div>
                <div class="yp-kosong-card__pb">Pembimbing: <?= htmlspecialchars((string) (($kk['nama_pembimbing'] ?? '') !== '' ? $kk['nama_pembimbing'] : '-')) ?></div>
                <div class="yp-kosong-card__stats">
                    Santri hadir <?= (int) ($kk['santri_hadir'] ?? 0) ?>/<?= (int) ($kk['santri_total'] ?? 0) ?>
                    · Pembimbing <?= !empty($kk['pembimbing_hadir']) ? 'Hadir' : 'Belum' ?>
                    · Munawib <?= !empty($kk['munawib_hadir']) ? 'Hadir' : 'Belum' ?>
                </div>
                <ul class="yp-kosong-card__reason">
                    <?php foreach ((array) ($kk['reasons'] ?? []) as $reason): ?>
                        <li><?= htmlspecialchars((string) $reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$kosongHtml = (string) ob_get_clean();

ob_start();
$kegiatanTanpaScan = (array) ($extra['kegiatan_tanpa_scan'] ?? []);
?>
<?php if ($kegiatanTanpaScan === []): ?>
    <div class="yp-empty-inline">Semua jadwal kegiatan hari ini yang sudah lewat waktunya sudah memiliki scan hadir.</div>
<?php else: ?>
    <?php
    $ktsSlotRows = $kegiatanTanpaScan;
    $ktsListPrefix = 'ypkh';
    $ktsShowHint = false;
    require __DIR__ . '/../../includes/partials/kegiatan_tanpa_scan_grouped.php';
    ?>
<?php endif; ?>
<?php
$tanpaScanHtml = (string) ob_get_clean();

echo json_encode([
    'ok' => true,
    'jadwal_tanpa_scan_count' => (int) ($extra['jadwal_tanpa_scan_count'] ?? 0),
    'kosong_count' => count($kegiatanKosong),
    'kosong_html' => $kosongHtml,
    'tanpa_scan_html' => $tanpaScanHtml,
], JSON_UNESCAPED_UNICODE);
