<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

$d1 = date('Y-m-d', strtotime('-45 days'));
$d2 = date('Y-m-d');
$rows = [];
$counts = ['HADIR' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];

if (table_exists($pdo, 'presensi')) {
    $agg = $pdo->prepare('
        SELECT status_presensi, COUNT(*) AS c
        FROM presensi
        WHERE santri_id = :sid AND tanggal_presensi BETWEEN :d1 AND :d2
        GROUP BY status_presensi
    ');
    $agg->execute(['sid' => $waliSantriId, 'd1' => $d1, 'd2' => $d2]);
    foreach ($agg->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $k = strtoupper((string) ($ar['status_presensi'] ?? ''));
        if (isset($counts[$k])) {
            $counts[$k] = (int) $ar['c'];
        }
    }
    $ps = $pdo->prepare('
        SELECT tanggal_presensi, status_presensi, jam_presensi, catatan
        FROM presensi
        WHERE santri_id = :sid AND tanggal_presensi BETWEEN :d1 AND :d2
        ORDER BY tanggal_presensi DESC, id DESC
        LIMIT 60
    ');
    $ps->execute(['sid' => $waliSantriId, 'd1' => $d1, 'd2' => $d2]);
    $rows = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Presensi — Portal Wali', true, 'keaktifan');
?>
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Presensi</h1>
                <p class="small text-muted mb-0">Hanya data <strong>anak Anda</strong> (<?= htmlspecialchars((string) ($waliSantriRow['nama_tampil'] ?? '')) ?>).</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>

        <div class="wali-hero mb-3">
            <div class="small text-muted mb-2">Ringkasan ±45 hari terakhir</div>
            <div class="row g-2 text-center">
                <div class="col-3">
                    <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                        <div class="small text-muted">Hadir</div>
                        <div class="fs-5 fw-bold text-success"><?= (int) $counts['HADIR'] ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                        <div class="small text-muted">Izin</div>
                        <div class="fs-5 fw-bold text-warning"><?= (int) $counts['IZIN'] ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                        <div class="small text-muted">Sakit</div>
                        <div class="fs-5 fw-bold text-primary"><?= (int) $counts['SAKIT'] ?></div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="rounded-3 bg-white bg-opacity-80 py-2 shadow-sm">
                        <div class="small text-muted">Alpa</div>
                        <div class="fs-5 fw-bold text-danger"><?= (int) $counts['ALPA'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!table_exists($pdo, 'presensi')): ?>
            <div class="card wali-card"><div class="card-body small text-muted text-center py-4">Modul presensi belum diaktifkan.</div></div>
        <?php elseif ($rows === []): ?>
            <div class="card wali-card"><div class="card-body small text-muted text-center py-4">Belum ada catatan presensi pada periode ini.</div></div>
        <?php else: ?>
            <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.7rem;">Riwayat presensi</div>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $st = strtoupper((string) ($r['status_presensi'] ?? ''));
                    if ($st === 'HADIR') {
                        $badge = 'success';
                    } elseif ($st === 'IZIN') {
                        $badge = 'warning';
                    } elseif ($st === 'SAKIT') {
                        $badge = 'primary';
                    } elseif ($st === 'ALPA') {
                        $badge = 'danger';
                    } else {
                        $badge = 'secondary';
                    }
                    ?>
                    <div class="card wali-card border-0">
                        <div class="card-body py-3 d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($r['tanggal_presensi'] ?? '')) ?></div>
                                <?php if (trim((string) ($r['jam_presensi'] ?? '')) !== ''): ?>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars(substr((string) $r['jam_presensi'], 0, 8)) ?></div>
                                <?php endif; ?>
                                <?php if (trim((string) ($r['catatan'] ?? '')) !== ''): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string) $r['catatan']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="badge text-bg-<?= htmlspecialchars($badge) ?> align-self-center"><?= htmlspecialchars($st ?: '—') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="small text-muted text-center mt-4 mb-0">Data di atas hanya untuk santri yang Anda akses melalui portal ini.</p>
<?php
wali_layout_foot(true, 'keaktifan');
