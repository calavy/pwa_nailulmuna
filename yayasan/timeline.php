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
$access = yayasan_task_user_access($pdo, $userId);
$canManageAll = yayasan_task_user_can_manage_all($pdo, $userId);
$picUsers = yayasan_task_pic_users($pdo);

/**
 * @return array<string, mixed>
 */
function yt_timeline_post_payload(): array
{
    return [
        'judul' => $_POST['judul'] ?? '',
        'deskripsi' => $_POST['deskripsi'] ?? '',
        'penanggung_jawab' => $_POST['penanggung_jawab'] ?? '',
        'category' => $_POST['category'] ?? 'Yayasan',
        'pj_ids' => $_POST['pj_ids'] ?? [],
        'pembantu_ids' => $_POST['pembantu_ids'] ?? [],
        'pic_id' => (int) ($_POST['pic_id'] ?? 0),
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
        'tanggal_target' => $_POST['tanggal_target'] ?? '',
        'start_at' => trim((string) ($_POST['tanggal_mulai'] ?? '')) . ' ' . trim((string) ($_POST['start_time'] ?? '08:00')),
        'due_at' => trim((string) ($_POST['tanggal_target'] ?? '')) . ' ' . trim((string) ($_POST['due_time'] ?? '17:00')),
        'progress' => (int) ($_POST['progress'] ?? 0),
        'sync_kalender' => isset($_POST['sync_kalender']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    /** @var array<string, mixed>|null $res */
    $res = null;
    if ($action === 'tambah') {
        $force = isset($_POST['force_conflict']);
        $res = yayasan_tugas_insert_manual($pdo, yt_timeline_post_payload(), $userId, $force);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        if (!empty($res['conflict'])) {
            $_SESSION['yt_last_conflict'] = $res['conflicts'] ?? [];
        }
    } elseif ($action === 'edit') {
        $tid = (int) ($_POST['id'] ?? 0);
        $force = isset($_POST['force_conflict']);
        $res = $tid > 0
            ? yayasan_tugas_update_manual($pdo, $tid, yt_timeline_post_payload(), $userId, $force)
            : ['ok' => false, 'message' => 'Tugas tidak valid.'];
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        if (!empty($res['conflict'])) {
            $_SESSION['yt_last_conflict'] = $res['conflicts'] ?? [];
        }
    } elseif ($action === 'progress') {
        $tid = (int) ($_POST['id'] ?? 0);
        $prog = (int) ($_POST['progress'] ?? 0);
        $ok = $tid > 0 && yayasan_tugas_update_progress($pdo, $tid, $prog, $userId);
        set_flash($ok ? 'success' : 'error', $ok ? 'Progres diperbarui.' : 'Tugas tidak ditemukan.');
    } elseif ($action === 'hapus') {
        $tid = (int) ($_POST['id'] ?? 0);
        $task = $tid > 0 ? yayasan_tugas_get($pdo, $tid) : null;
        $ok = $task !== null && yayasan_task_user_can_manage_category($pdo, $userId, (string) ($task['category'] ?? 'Yayasan'))
            && yayasan_tugas_delete($pdo, $tid);
        set_flash($ok ? 'success' : 'error', $ok ? 'Tugas dihapus.' : 'Gagal menghapus.');
    } elseif ($action === 'role_tags' && $canManageAll) {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $tags = isset($_POST['tags']) && is_array($_POST['tags']) ? $_POST['tags'] : [];
        $acc = trim((string) ($_POST['timeline_access'] ?? ''));
        if ($uid > 0) {
            yayasan_task_set_user_tags($pdo, $uid, $tags);
            yayasan_task_set_user_access($pdo, $uid, $acc !== '' ? $acc : null);
            set_flash('success', 'Peran & tag pembimbing diperbarui.');
        }
    }
    $redirect = ['filter' => trim((string) ($_POST['filter'] ?? ''))];
    if ($action === 'edit' && is_array($res) && !empty($res['conflict'])) {
        $redirect['edit'] = (int) ($_POST['id'] ?? 0);
    }
    $qs = http_build_query(array_filter($redirect, static fn($v) => $v !== '' && $v !== null));
    header('Location: ' . app_href('/yayasan/timeline.php' . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$filter = trim((string) ($_GET['filter'] ?? 'aktif'));
if (!in_array($filter, ['aktif', 'semua', 'terlambat', 'rapat', 'manual'], true)) {
    $filter = 'aktif';
}
$listFilter = $filter === 'semua' ? null : ($filter === 'aktif' ? 'aktif' : $filter);
$allRows = yayasan_tugas_list($pdo, null);
$rows = yayasan_tugas_list($pdo, $listFilter);
$stats = yayasan_tugas_stats($allRows);
$groups = yayasan_tugas_group_by_month($rows);
$conflicts = yayasan_tugas_all_conflicts($pdo);
$lastConflict = $_SESSION['yt_last_conflict'] ?? null;
unset($_SESSION['yt_last_conflict']);

$bulanId = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

$allowedCategories = $canManageAll ? yayasan_task_categories() : array_values(array_filter(
    yayasan_task_categories(),
    static fn(string $c): bool => yayasan_task_user_can_manage_category($pdo, $userId, $c)
));

$editId = (int) ($_GET['edit'] ?? 0);
$editTask = $editId > 0 ? yayasan_tugas_get($pdo, $editId) : null;
if ($editTask !== null && !yayasan_task_user_can_manage_category($pdo, $userId, (string) ($editTask['category'] ?? 'Yayasan'))) {
    $editTask = null;
    $editId = 0;
}
$isEditForm = $editTask !== null;
$formJudul = $isEditForm ? (string) ($editTask['judul'] ?? '') : '';
$formCategory = $isEditForm ? (string) ($editTask['category'] ?? 'Yayasan') : ($allowedCategories[0] ?? 'Yayasan');
$formPjIds = $isEditForm ? (array) ($editTask['pj_ids'] ?? []) : [];
$formPembantuIds = $isEditForm ? (array) ($editTask['pembantu_ids'] ?? []) : [];
$formMulai = $isEditForm ? (string) ($editTask['tanggal_mulai'] ?? date('Y-m-d')) : date('Y-m-d');
$formTarget = $isEditForm ? (string) ($editTask['tanggal_target'] ?? '') : '';
$formDeskripsi = $isEditForm ? (string) ($editTask['deskripsi'] ?? '') : '';
$formProgress = $isEditForm ? (int) ($editTask['progress'] ?? 0) : 0;
$formSyncKalender = !$isEditForm || (int) ($editTask['sync_kalender'] ?? 1) === 1;
$formStartTime = '08:00';
$formDueTime = '17:00';
if ($isEditForm) {
    if (preg_match('/(\d{2}:\d{2})/', (string) ($editTask['start_at'] ?? ''), $m)) {
        $formStartTime = $m[1];
    }
    if (preg_match('/(\d{2}:\d{2})/', (string) ($editTask['due_at'] ?? ''), $m)) {
        $formDueTime = $m[1];
    }
}

$formPanelOpen = $isEditForm || (is_array($lastConflict) && $lastConflict !== []);

$pageTitle = 'Timeline Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css'), app_asset_href('/assets/css/yayasan-timeline.css')];
$pageScripts = [app_asset_href('/assets/js/yayasan-timeline.js')];
$timelineApi = app_href('/api/yayasan/timeline_dashboard.php');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap yt-wrap">
    <header class="mb-4">
        <?php $yayasanCrumbTail = 'Timeline & Tugas'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1 class="h3 mb-1">Timeline & Tugas Yayasan</h1>
                <p class="text-muted mb-0">Gantt program kerja · beban pembimbing · deteksi bentrok jadwal · sinkron iCal</p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/akademik/kalender.php')) ?>">
                <i class="fa-solid fa-calendar-days me-1"></i>Kalender akademik
            </a>
        </div>
    </header>

    <div id="yt-conflict-panel" class="yt-conflict-panel mb-3 <?= $conflicts !== [] ? 'yt-conflict-panel--alert' : '' ?>">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>Conflict Monitor</strong>
            <span class="badge rounded-pill text-bg-danger yt-conflict-panel__count"><?= count($conflicts) ?></span>
            <span class="small ms-auto">Jadwal bentrok pada pembimbing merangkap</span>
        </div>
        <div id="yt-conflict-list" class="mt-2">
            <?php if ($conflicts === []): ?>
                <p class="text-muted small mb-0">Tidak ada jadwal bentrok.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach (array_slice($conflicts, 0, 5) as $c): ?>
                        <li class="mb-1">
                            <strong><?= htmlspecialchars((string) ($c['pic_nama'] ?? '')) ?></strong>:
                            <?= htmlspecialchars((string) ($c['task_a']['judul'] ?? '')) ?>
                            ↔ <?= htmlspecialchars((string) ($c['task_b']['judul'] ?? '')) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div id="yt-dashboard-mount" data-filter="<?= htmlspecialchars($filter) ?>" data-api-url="<?= htmlspecialchars($timelineApi) ?>" class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100 yt-gantt-card overflow-hidden">
                <div class="card-header bg-white border-0 pt-3 pb-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 mb-1 fw-bold"><i class="fa-solid fa-chart-gantt me-2 text-primary"></i>Linimasa Program Kerja</h2>
                            <p class="small text-muted mb-0">Klik batang tugas untuk lompat ke detail · garis merah = hari ini</p>
                        </div>
                        <div class="yt-gantt-legend" aria-hidden="true">
                            <span class="yt-gantt-legend__item yt-gantt-legend__item--akademik">Akademik</span>
                            <span class="yt-gantt-legend__item yt-gantt-legend__item--asrama">Asrama</span>
                            <span class="yt-gantt-legend__item yt-gantt-legend__item--yayasan">Yayasan</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="yt-gantt" class="yt-gantt-mount">
                        <div class="yt-gantt-skeleton">
                            <div class="yt-gantt-skeleton__bar"></div>
                            <div class="yt-gantt-skeleton__bar"></div>
                            <div class="yt-gantt-skeleton__bar"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-weight-hanging me-1"></i>Beban Kerja Pembimbing</div>
                <div class="card-body" id="yt-workload"></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="yt-stat"><div class="yt-stat__n"><?= (int) $stats['total'] ?></div><div class="yt-stat__l">Total</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--primary"><div class="yt-stat__n"><?= (int) $stats['berjalan'] ?></div><div class="yt-stat__l">Proses</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--danger"><div class="yt-stat__n"><?= (int) $stats['terlambat'] ?></div><div class="yt-stat__l">Terlambat</div></div></div>
        <div class="col-6 col-md-3"><div class="yt-stat yt-stat--success"><div class="yt-stat__n"><?= (int) $stats['selesai'] ?></div><div class="yt-stat__l">Selesai</div></div></div>
    </div>

    <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <?php foreach (['aktif' => 'Aktif', 'semua' => 'Semua', 'terlambat' => 'Terlambat', 'rapat' => 'Dari rapat', 'manual' => 'Manual'] as $k => $lbl): ?>
            <li class="nav-item"><a class="nav-link <?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= urlencode($k) ?>"><?= htmlspecialchars($lbl) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if (is_array($lastConflict) && $lastConflict !== []): ?>
        <div class="alert alert-warning">
            <strong>Peringatan bentrok jadwal.</strong> Centang konfirmasi di form tugas lalu simpan ulang jika tetap ingin melanjutkan.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4 order-lg-2">
            <?php if ($allowedCategories !== []): ?>
            <button type="button" class="btn btn-success w-100 mb-3 yt-form-toggle <?= $formPanelOpen ? 'd-none' : '' ?>" id="yt-form-toggle">
                <i class="fa-solid fa-plus me-2"></i>Buat tugas baru
            </button>
            <div class="card border-0 shadow-sm mb-3 yt-form-panel <?= $formPanelOpen ? 'is-open' : '' ?>" id="yt-form-card" data-form-open="<?= $formPanelOpen ? '1' : '0' ?>">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2">
                    <span class="yt-form-panel__title">
                        <?php if ($isEditForm): ?>
                            <i class="fa-solid fa-pen me-1"></i>Edit tugas #<?= $editId ?>
                        <?php else: ?>
                            <i class="fa-solid fa-clipboard-list me-1"></i>Form tugas
                        <?php endif; ?>
                    </span>
                    <div class="d-flex align-items-center gap-1">
                        <?php if ($isEditForm): ?>
                            <a href="?filter=<?= urlencode($filter) ?>" class="btn btn-link btn-sm p-0">Batal</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary yt-form-close" aria-label="Tutup form">&times;</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body yt-form-panel__body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="<?= $isEditForm ? 'edit' : 'tambah' ?>">
                        <?php if ($isEditForm): ?>
                            <input type="hidden" name="id" value="<?= $editId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <div class="col-12">
                            <label class="form-label small mb-0">Judul kegiatan</label>
                            <input class="form-control form-control-sm" name="judul" required maxlength="200" value="<?= htmlspecialchars($formJudul) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Kategori</label>
                            <select class="form-select form-select-sm" name="category">
                                <?php foreach ($allowedCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= $formCategory === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Penanggung jawab (PJ) <span class="text-muted">— boleh lebih dari satu</span></label>
                            <select class="form-select form-select-sm yt-multi-select" name="pj_ids[]" multiple size="5">
                                <?php foreach ($picUsers as $pu): ?>
                                    <?php $uid = (int) ($pu['id'] ?? 0); ?>
                                    <option value="<?= $uid ?>" <?= in_array($uid, $formPjIds, true) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($pu['nama'] ?? $pu['username'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Tahan Ctrl / tap panjang untuk pilih beberapa PJ.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Pembantu <span class="text-muted">— boleh lebih dari satu</span></label>
                            <select class="form-select form-select-sm yt-multi-select" name="pembantu_ids[]" multiple size="5">
                                <?php foreach ($picUsers as $pu): ?>
                                    <?php $uid = (int) ($pu['id'] ?? 0); ?>
                                    <option value="<?= $uid ?>" <?= in_array($uid, $formPembantuIds, true) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($pu['nama'] ?? $pu['username'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Mulai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_mulai" value="<?= htmlspecialchars($formMulai) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam mulai</label>
                            <input type="time" class="form-control form-control-sm" name="start_time" value="<?= htmlspecialchars($formStartTime) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Tenggat</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_target" required value="<?= htmlspecialchars($formTarget) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Jam tenggat</label>
                            <input type="time" class="form-control form-control-sm" name="due_time" value="<?= htmlspecialchars($formDueTime) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Catatan</label>
                            <textarea class="form-control form-control-sm" name="deskripsi" rows="2"><?= htmlspecialchars($formDeskripsi) ?></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Progres (%)</label>
                            <input type="number" class="form-control form-control-sm" name="progress" min="0" max="100" value="<?= (int) $formProgress ?>">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="sync_kalender" id="sync_kalender" <?= $formSyncKalender ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="sync_kalender">Sinkron kalender</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="force_conflict" id="force_conflict" value="1" <?= is_array($lastConflict) && $lastConflict !== [] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="force_conflict">Lanjutkan meski jadwal bentrok</label>
                            </div>
                        </div>
                            <div class="col-12"><div class="small text-muted"><i class="fa-brands fa-whatsapp text-success me-1"></i>Semua PJ & pembantu akan menerima notifikasi WA.</div></div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-<?= $isEditForm ? 'primary' : 'success' ?> btn-sm w-100">
                                <?= $isEditForm ? 'Simpan perubahan' : 'Simpan tugas' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canManageAll): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-tags me-1"></i>Tag peran pembimbing</div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="role_tags">
                        <div class="col-12">
                            <label class="form-label small mb-0">Akun</label>
                            <select class="form-select form-select-sm" name="user_id" required>
                                <option value="">Pilih pembimbing…</option>
                                <?php foreach ($picUsers as $pu): ?>
                                    <?php if (strtolower((string) ($pu['role'] ?? '')) === 'pembimbing' || !empty($pu['pembimbing_id'])): ?>
                                        <option value="<?= (int) ($pu['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($pu['nama'] ?? '')) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Level akses</label>
                            <select class="form-select form-select-sm" name="timeline_access">
                                <option value="">Default per role</option>
                                <?php foreach (yayasan_task_access_levels() as $lvl): ?>
                                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars(yayasan_task_access_label($lvl)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Tag kategori (boleh lebih dari satu)</label>
                            <?php foreach (yayasan_task_role_tags() as $tag): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="tags[]" value="<?= htmlspecialchars($tag) ?>" id="tag_<?= htmlspecialchars($tag) ?>">
                                    <label class="form-check-label small" for="tag_<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-outline-primary btn-sm w-100">Simpan peran</button></div>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Akademik = madrasah & nilai · Asrama = kesantrian & halaqah · Yayasan = prioritas merah di kalender.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm bg-light">
                <div class="card-body small">
                    <div class="fw-semibold mb-2"><i class="fa-solid fa-circle-info me-1"></i>Integrasi kalender</div>
                    <p class="mb-1 text-muted">Setiap pembimbing mendapat URL unik <code>.ics</code> (one-way sync) di menu <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas_yayasan.php')) ?>">Tugas Yayasan</a>.</p>
                    <p class="mb-1">Warna otomatis: <span class="text-primary">Akademik</span> · <span class="text-success">Asrama</span> · <span class="text-danger">Yayasan</span></p>
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
                                $catBadge = (string) ($t['category_badge'] ?? 'secondary');
                                ?>
                                <article class="yt-item" id="yt-task-<?= $tid ?>">
                                    <div class="yt-item__dot yt-item__dot--<?= htmlspecialchars($badge) ?>"></div>
                                    <div class="yt-item__card card border-0 shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                                <div>
                                                    <h2 class="h6 mb-1"><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></h2>
                                                    <div class="small text-muted">
                                                        <?= htmlspecialchars((string) ($t['start_at'] ?? $t['tanggal_mulai'] ?? '')) ?> → <strong><?= htmlspecialchars((string) ($t['due_at'] ?? $t['tanggal_target'] ?? '')) ?></strong>
                                                        <?php if (!empty($t['pj_nama']) || !empty($t['pembantu_nama']) || !empty($t['penanggung_jawab'])): ?>
                                                            <?php if (!empty($t['pj_nama'])): ?>
                                                                · PJ: <?= htmlspecialchars((string) $t['pj_nama']) ?>
                                                            <?php endif; ?>
                                                            <?php if (!empty($t['pembantu_nama'])): ?>
                                                                · Pembantu: <?= htmlspecialchars((string) $t['pembantu_nama']) ?>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge text-bg-<?= htmlspecialchars($catBadge) ?> me-1"><?= htmlspecialchars((string) ($t['category'] ?? 'Yayasan')) ?></span>
                                                    <span class="badge text-bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars((string) ($t['status_label'] ?? '')) ?></span>
                                                </div>
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

                                            <div class="mb-1 small d-flex justify-content-between text-muted">
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
                                                    <?php if (!empty($t['attachment'])): ?>
                                                        <a href="<?= htmlspecialchars(app_href('/' . ltrim((string) $t['attachment'], '/'))) ?>" class="text-decoration-none" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip me-1"></i>Bukti</a>
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
                                            <?php if (yayasan_task_user_can_manage_category($pdo, $userId, (string) ($t['category'] ?? 'Yayasan'))): ?>
                                            <div class="mt-2 d-flex justify-content-end gap-2">
                                                <a href="?filter=<?= urlencode($filter) ?>&amp;edit=<?= $tid ?>#yt-form-card" class="btn btn-link btn-sm p-0">Edit</a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus tugas ini?');">
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id" value="<?= $tid ?>">
                                                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0">Hapus</button>
                                                </form>
                                            </div>
                                            <?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
