<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_timeline.php';

require_roles(['admin', 'pengurus']);

yayasan_timeline_ensure_schema($pdo);
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'tambah') {
        $id = yayasan_tugas_insert_manual($pdo, [
            'judul' => $_POST['judul'] ?? '',
            'deskripsi' => $_POST['deskripsi'] ?? '',
            'penanggung_jawab' => $_POST['penanggung_jawab'] ?? '',
            'tanggal_mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
            'tanggal_target' => $_POST['tanggal_target'] ?? '',
            'progress' => (int) ($_POST['progress'] ?? 0),
            'sync_kalender' => isset($_POST['sync_kalender']),
        ], $userId);
        set_flash($id > 0 ? 'success' : 'error', $id > 0 ? 'Tugas timeline ditambahkan.' : 'Judul tugas wajib diisi.');
    } elseif ($action === 'progress') {
        $tid = (int) ($_POST['id'] ?? 0);
        $prog = (int) ($_POST['progress'] ?? 0);
        $ok = $tid > 0 && yayasan_tugas_update_progress($pdo, $tid, $prog, $userId);
        set_flash($ok ? 'success' : 'error', $ok ? 'Progres diperbarui.' : 'Tugas tidak ditemukan.');
    } elseif ($action === 'hapus') {
        $tid = (int) ($_POST['id'] ?? 0);
        $ok = $tid > 0 && yayasan_tugas_delete($pdo, $tid);
        set_flash($ok ? 'success' : 'error', $ok ? 'Tugas dihapus.' : 'Gagal menghapus.');
    }
    $qs = http_build_query(array_filter([
        'filter' => trim((string) ($_POST['filter'] ?? '')),
    ]));
    header('Location: ' . app_href('/yayasan/timeline.php' . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$filter = trim((string) ($_GET['filter'] ?? 'aktif'));
if (!in_array($filter, ['aktif', 'semua', 'terlambat', 'rapat', 'manual'], true)) {
    $filter = 'aktif';
}
$listFilter = $filter === 'semua' ? null : ($filter === 'aktif' ? 'aktif' : $filter);
$rows = yayasan_tugas_list($pdo, $listFilter);
$stats = yayasan_tugas_stats(yayasan_tugas_list($pdo, null));
$groups = yayasan_tugas_group_by_month($rows);

$bulanId = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';

$pageTitle = 'Timeline Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css'), app_asset_href('/assets/css/yayasan-timeline.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap yt-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a></p>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1 class="h3 mb-1">Timeline & Tugas Yayasan</h1>
                <p class="text-muted mb-0">Dari hasil rapat/notulen atau input manual · tersinkron ke kalender akademik</p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/akademik/kalender.php')) ?>">
                <i class="fa-solid fa-calendar-days me-1"></i>Kalender
            </a>
        </div>
    </header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="yt-stat"><div class="yt-stat__n"><?= (int) $stats['total'] ?></div><div class="yt-stat__l">Total</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--primary"><div class="yt-stat__n"><?= (int) $stats['berjalan'] ?></div><div class="yt-stat__l">Berjalan</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--danger"><div class="yt-stat__n"><?= (int) $stats['terlambat'] ?></div><div class="yt-stat__l">Terlambat</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--success"><div class="yt-stat__n"><?= (int) $stats['selesai'] ?></div><div class="yt-stat__l">Selesai</div></div></div>
    </div>

    <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <?php foreach (['aktif' => 'Aktif', 'semua' => 'Semua', 'terlambat' => 'Terlambat', 'rapat' => 'Dari rapat', 'manual' => 'Manual'] as $k => $lbl): ?>
            <li class="nav-item"><a class="nav-link <?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= urlencode($k) ?>"><?= htmlspecialchars($lbl) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <div class="row g-4">
        <div class="col-lg-4 order-lg-2">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-plus me-1"></i>Tugas manual</div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="tambah">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <div class="col-12">
                            <label class="form-label small mb-0">Judul</label>
                            <input class="form-control form-control-sm" name="judul" required maxlength="200">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Mulai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_mulai" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Target selesai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_target" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Penanggung jawab</label>
                            <input class="form-control form-control-sm" name="penanggung_jawab" placeholder="Opsional">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Catatan</label>
                            <textarea class="form-control form-control-sm" name="deskripsi" rows="2"></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Progres awal (%)</label>
                            <input type="number" class="form-control form-control-sm" name="progress" min="0" max="100" value="0">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="sync_kalender" id="sync_kalender" checked>
                                <label class="form-check-label small" for="sync_kalender">Sinkron kalender</label>
                            </div>
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-success btn-sm w-100">Simpan tugas</button></div>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm bg-light">
                <div class="card-body small">
                    <div class="fw-semibold mb-2"><i class="fa-solid fa-circle-info me-1"></i>Format tindak lanjut rapat</div>
                    <p class="mb-1 text-muted">Satu tugas per baris di notulen:</p>
                    <code class="d-block mb-1">Siapkan proposal | 2025-06-30 | PJ: Sekretaris</code>
                    <code class="d-block mb-1">[50%] Audit keuangan | sampai 15/07/2025</code>
                    <code class="d-block mb-0">Koordinasi PKPPS — deadline 2025-08-01</code>
                    <p class="mt-2 mb-0"><a href="<?= htmlspecialchars(app_href('/yayasan/notulen.php')) ?>">Kelola notulen rapat</a></p>
                </div>
            </div>
        </div>

        <div class="col-lg-8 order-lg-1">
            <?php if ($rows === []): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="fa-solid fa-route fs-1 mb-2 opacity-50"></i>
                        <p class="mb-0">Belum ada tugas timeline untuk filter ini.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="yt-timeline">
                    <?php foreach ($groups as $ym => $items): ?>
                        <?php
                        $parts = explode('-', (string) $ym);
                        $monthLabel = count($parts) === 2
                            ? ($bulanId[(int) $parts[1]] ?? $parts[1]) . ' ' . $parts[0]
                            : (string) $ym;
                        ?>
                        <div class="yt-month">
                            <div class="yt-month__label"><?= htmlspecialchars($monthLabel) ?></div>
                            <?php foreach ($items as $t): ?>
                                <?php
                                $tid = (int) ($t['id'] ?? 0);
                                $prog = (int) ($t['progress'] ?? 0);
                                $badge = (string) ($t['status_badge'] ?? 'secondary');
                                $tlPct = (int) ($t['timeline_pct'] ?? 0);
                                ?>
                                <article class="yt-item">
                                    <div class="yt-item__dot yt-item__dot--<?= htmlspecialchars($badge) ?>"></div>
                                    <div class="yt-item__card card border-0 shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                                <div>
                                                    <h2 class="h6 mb-1"><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></h2>
                                                    <div class="small text-muted">
                                                        <?= htmlspecialchars((string) ($t['tanggal_mulai'] ?? '')) ?> → <strong><?= htmlspecialchars((string) ($t['tanggal_target'] ?? '')) ?></strong>
                                                        <?php if (!empty($t['penanggung_jawab'])): ?>
                                                            · PJ: <?= htmlspecialchars((string) $t['penanggung_jawab']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="badge text-bg-<?= htmlspecialchars($badge) ?> align-self-start"><?= htmlspecialchars((string) ($t['status_label'] ?? '')) ?></span>
                                            </div>

                                            <?php if (!empty($t['deskripsi'])): ?>
                                                <p class="small text-muted mb-2"><?= nl2br(htmlspecialchars((string) $t['deskripsi'])) ?></p>
                                            <?php endif; ?>

                                            <div class="mb-1 small d-flex justify-content-between">
                                                <span>Progres tugas</span>
                                                <strong><?= $prog ?>%</strong>
                                            </div>
                                            <div class="progress mb-2" style="height:8px">
                                                <div class="progress-bar bg-<?= $prog >= 100 ? 'success' : 'primary' ?>" style="width:<?= $prog ?>%"></div>
                                            </div>

                                            <div class="mb-2 small d-flex justify-content-between text-muted">
                                                <span>Linimasa waktu</span>
                                                <span><?= $tlPct ?>% durasi</span>
                                            </div>
                                            <div class="progress mb-3" style="height:4px">
                                                <div class="progress-bar bg-secondary opacity-50" style="width:<?= $tlPct ?>%"></div>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                                <div class="small">
                                                    <?php if (($t['sumber'] ?? '') === 'RAPAT'): ?>
                                                        <span class="badge text-bg-light border"><i class="fa-solid fa-handshake me-1"></i>Rapat</span>
                                                        <?php if (!empty($t['rapat_judul'])): ?>
                                                            <a href="<?= htmlspecialchars(app_href('/yayasan/notulen.php?rapat_id=' . (int) ($t['rapat_id'] ?? 0))) ?>" class="text-decoration-none"><?= htmlspecialchars((string) $t['rapat_judul']) ?></a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-light border"><i class="fa-solid fa-pen me-1"></i>Manual</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($t['agenda_id'])): ?>
                                                        <span class="text-success"><i class="fa-solid fa-link me-1"></i>Kalender</span>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="post" class="d-flex flex-wrap gap-1 align-items-center yt-progress-form">
                                                    <input type="hidden" name="action" value="progress">
                                                    <input type="hidden" name="id" value="<?= $tid ?>">
                                                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                                    <input type="range" class="form-range yt-range" name="progress" min="0" max="100" value="<?= $prog ?>" oninput="this.nextElementSibling.textContent=this.value+'%'">
                                                    <span class="small fw-semibold yt-range-label" style="min-width:2.5rem"><?= $prog ?>%</span>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Simpan</button>
                                                    <button type="button" class="btn btn-sm btn-outline-success yt-btn-done">Selesai</button>
                                                </form>
                                            </div>
                                            <form method="post" class="mt-2 text-end" onsubmit="return confirm('Hapus tugas ini?');">
                                                <input type="hidden" name="action" value="hapus">
                                                <input type="hidden" name="id" value="<?= $tid ?>">
                                                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                                <button type="submit" class="btn btn-link btn-sm text-danger p-0">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.yt-btn-done').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = btn.closest('form');
        if (!form) return;
        var range = form.querySelector('[type=range]');
        var label = form.querySelector('.yt-range-label');
        if (range) range.value = '100';
        if (label) label.textContent = '100%';
        form.submit();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
