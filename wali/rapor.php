<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik.php';

ensure_akademik_rapor_table($pdo);

$rows = [];
if (table_exists($pdo, 'akademik_rapor')) {
    $st = $pdo->prepare('
        SELECT id, judul_periode, tanggal_terbit, narasi, predikat_akhlak, catatan_pondok
        FROM akademik_rapor
        WHERE santri_id = :sid AND is_published = 1
        ORDER BY tanggal_terbit DESC, id DESC
        LIMIT 30
    ');
    $st->execute(['sid' => $waliSantriId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$waPengurus = trim((string) app_setting($pdo, 'wa_pengurus', ''));
$namaAnak = (string) ($waliSantriRow['nama_tampil'] ?? '');
$pesanTanya = 'Assalamu\'alaikum, saya wali dari *' . $namaAnak . '* (NIS ' . ($waliSantriRow['nis'] ?? '') . '). Mohon penjelasan terkait rapor akademik di portal wali. Terima kasih.';
$waAdminUrl = $waPengurus !== '' ? wa_me_chat_url($waPengurus, $pesanTanya) : null;

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Rapor — Portal Wali', true, 'rapor');
?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h5 mb-0 wali-brand fw-bold">Rapor akademik</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>
        <p class="small text-muted">Hanya rapor yang sudah <strong>diterbitkan</strong> pengurus.</p>

        <?php if ($waAdminUrl): ?>
            <a class="btn btn-success w-100 mb-3" target="_blank" rel="noopener" href="<?= htmlspecialchars($waAdminUrl) ?>">Chat WhatsApp pengurus</a>
        <?php else: ?>
            <div class="alert alert-light border small mb-3">Nomor WhatsApp pengurus belum diatur di Settings (wa_pengurus).</div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="card shadow-sm wali-card"><div class="card-body text-muted small text-center py-4">Belum ada rapor yang diterbitkan.</div></div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($rows as $r): ?>
                    <div class="card shadow-sm wali-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <span class="fw-semibold"><?= htmlspecialchars((string) ($r['judul_periode'] ?? '')) ?></span>
                                <span class="small text-muted text-nowrap"><?= htmlspecialchars((string) ($r['tanggal_terbit'] ?? '')) ?></span>
                            </div>
                            <?php if (trim((string) ($r['predikat_akhlak'] ?? '')) !== ''): ?>
                                <div class="mb-2"><span class="badge text-bg-info text-dark"><?= htmlspecialchars((string) $r['predikat_akhlak']) ?></span></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($r['narasi'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary mb-2" style="white-space:pre-wrap;"><?= htmlspecialchars((string) $r['narasi']) ?></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($r['catatan_pondok'] ?? '')) !== ''): ?>
                                <div class="small border-start border-3 border-success ps-2 mt-2"><?= nl2br(htmlspecialchars((string) $r['catatan_pondok'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
<?php
wali_layout_foot(true, 'rapor');
