<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik.php';

ensure_akademik_hafalan_setoran_table($pdo);

$rows = [];
if (table_exists($pdo, 'akademik_hafalan_setoran')) {
    $st = $pdo->prepare('
        SELECT *
        FROM akademik_hafalan_setoran
        WHERE santri_id = :sid
        ORDER BY tanggal_setoran DESC, id DESC
        LIMIT 50
    ');
    $st->execute(['sid' => $waliSantriId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Hafalan — Portal Wali', true, 'hafalan');
?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h5 mb-0 wali-brand fw-bold">Setoran hafalan</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/pwa_nailulmuna/wali/logout.php">Keluar</a>
        </div>
        <p class="small text-muted">Catatan dari ustadz/mudaris. Jika kosong, belum ada input.</p>

        <?php if (!$rows): ?>
            <div class="card shadow-sm wali-card"><div class="card-body text-muted small text-center py-4">Belum ada data setoran.</div></div>
        <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $kat = strtoupper(trim((string) ($r['kategori_setoran'] ?? 'ALQURAN')));
                    $hij = trim((string) ($r['kalender_hijriyah'] ?? ''));
                    $brs = isset($r['baris_setor']) && $r['baris_setor'] !== null && $r['baris_setor'] !== '' ? (int) $r['baris_setor'] : null;
                    ?>
                    <div class="card shadow-sm wali-card">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between gap-2 mb-1 flex-wrap">
                                <span class="fw-semibold small"><?= htmlspecialchars((string) ($r['target_hafalan'] ?? '')) ?></span>
                                <span class="small text-muted text-nowrap"><?= htmlspecialchars((string) ($r['tanggal_setoran'] ?? '')) ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mb-1">
                                <span class="badge text-bg-light text-dark border" style="font-size:0.65rem;"><?= htmlspecialchars($kat === 'BAIT' ? 'Bait' : "Al-Qur'an") ?></span>
                                <?php if ($hij !== ''): ?>
                                    <span class="badge text-bg-secondary" style="font-size:0.65rem;">Hijri <?= htmlspecialchars($hij) ?></span>
                                <?php endif; ?>
                                <?php if ($brs !== null && $brs > 0): ?>
                                    <span class="badge text-bg-info text-dark" style="font-size:0.65rem;"><?= $brs ?> baris</span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string) ($r['juz_halaman'] ?? '')) !== ''): ?>
                                <div class="small text-muted">Juz / hal: <?= htmlspecialchars((string) $r['juz_halaman']) ?></div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-1 small">
                                <?php if ($r['nilai_skor'] !== null && $r['nilai_skor'] !== ''): ?>
                                    <span class="badge text-bg-light text-dark border">Nilai <?= (int) $r['nilai_skor'] ?></span>
                                <?php endif; ?>
                                <?php if (trim((string) ($r['predikat'] ?? '')) !== ''): ?>
                                    <span class="badge text-bg-info text-dark"><?= htmlspecialchars((string) $r['predikat']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string) ($r['catatan'] ?? '')) !== ''): ?>
                                <p class="small mb-0 mt-2 text-body-secondary"><?= nl2br(htmlspecialchars((string) $r['catatan'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
<?php
wali_layout_foot(true, 'hafalan');
