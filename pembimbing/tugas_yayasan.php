<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/yayasan_timeline.php';

pembimbing_portal_require_access(['pembimbing', 'pengurus', 'admin', 'petugas_absensi', 'kiai']);

yayasan_timeline_ensure_schema($pdo);
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$taskId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'status') {
        $tid = (int) ($_POST['id'] ?? 0);
        $toggle = trim((string) ($_POST['toggle_status'] ?? ''));
        $file = isset($_FILES['attachment']) && is_array($_FILES['attachment']) ? $_FILES['attachment'] : null;
        $res = yayasan_tugas_update_status_toggle($pdo, $tid, $toggle, $userId, $file);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
        header('Location: ' . app_href('/pembimbing/tugas_yayasan.php?id=' . $tid));
        exit;
    }
}

$calendarUrl = yayasan_task_calendar_sync_url($pdo, $userId);
$googleCalSub = 'https://www.google.com/calendar/render?cid=' . rawurlencode(str_replace('https://', 'http://', $calendarUrl));
$webcalUrl = preg_replace('#^https:#', 'webcal:', $calendarUrl) ?? $calendarUrl;

if ($taskId > 0) {
    $task = yayasan_tugas_get($pdo, $taskId);
    if ($task === null || (!yayasan_tugas_user_is_assignee($task, $userId) && !$bolehSemua)) {
        set_flash('error', 'Tugas tidak ditemukan atau bukan penugasan Anda.');
        header('Location: ' . app_href('/pembimbing/tugas_yayasan.php'));
        exit;
    }
    $pageTitle = 'Detail Tugas';
    $pageStylesheets = [app_asset_href('/assets/css/yayasan-timeline.css')];
    require_once __DIR__ . '/../includes/header.php';
    $toggle = (string) ($task['toggle_status'] ?? 'belum');
    ?>
    <div class="container py-3 yt-pb-wrap">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas_yayasan.php')) ?>" class="btn btn-link btn-sm ps-0 mb-2">&larr; Daftar tugas</a>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <h1 class="h5 mb-0"><?= htmlspecialchars((string) ($task['judul'] ?? '')) ?></h1>
                    <span class="badge text-bg-<?= htmlspecialchars((string) ($task['category_badge'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($task['category'] ?? '')) ?></span>
                </div>
                <?php if (!empty($task['deskripsi'])): ?>
                    <p class="text-muted mb-3"><?= nl2br(htmlspecialchars((string) $task['deskripsi'])) ?></p>
                <?php endif; ?>
                <div class="small text-muted mb-3">
                    <div><i class="fa-regular fa-clock me-1"></i><?= htmlspecialchars((string) ($task['start_at'] ?? '')) ?> &rarr; <strong><?= htmlspecialchars((string) ($task['due_at'] ?? '')) ?></strong></div>
                    <?php if (!empty($task['pj_nama'])): ?><div class="mt-1">PJ: <?= htmlspecialchars((string) $task['pj_nama']) ?></div><?php endif; ?>
                    <?php if (!empty($task['pembantu_nama'])): ?><div>Pembantu: <?= htmlspecialchars((string) $task['pembantu_nama']) ?></div><?php endif; ?>
                    <div class="mt-1"><span class="badge text-bg-<?= htmlspecialchars((string) ($task['status_badge'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($task['status_label'] ?? '')) ?></span></div>
                </div>
                <?php if (!empty($task['attachment'])): ?>
                    <a class="btn btn-sm btn-outline-secondary mb-3" href="<?= htmlspecialchars(app_href('/' . ltrim((string) $task['attachment'], '/'))) ?>" target="_blank" rel="noopener">
                        <i class="fa-solid fa-paperclip me-1"></i>Lihat bukti
                    </a>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="yt-pb-form">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $taskId ?>">
                    <label class="form-label fw-semibold">Status pelaksanaan</label>
                    <div class="btn-group w-100 mb-3" role="group">
                        <?php foreach (['belum' => 'Belum', 'proses' => 'Proses', 'selesai' => 'Selesai'] as $val => $lbl): ?>
                            <input type="radio" class="btn-check" name="toggle_status" id="st_<?= $val ?>" value="<?= $val ?>" <?= $toggle === $val ? 'checked' : '' ?> autocomplete="off">
                            <label class="btn btn-outline-primary" for="st_<?= $val ?>"><?= $lbl ?></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label fw-semibold">Dokumentasi (maks. 2 MB)</label>
                    <input type="file" class="form-control mb-3" name="attachment" accept="image/*,application/pdf" capture="environment">
                    <button type="submit" class="btn btn-success w-100 btn-lg">Simpan laporan</button>
                </form>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$tasks = yayasan_tugas_list_for_pic($pdo, $userId, true);
$pageTitle = 'Tugas Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-timeline.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-3 yt-pb-wrap">
    <header class="mb-3">
        <h1 class="h4 mb-1">Tugas & Timeline</h1>
        <p class="text-muted small mb-0">Penugasan dari yayasan · sinkron ke kalender HP</p>
    </header>

    <?php if ($calendarUrl !== ''): ?>
        <div class="card border-0 shadow-sm mb-3 yt-pb-sync">
            <div class="card-body text-center py-4">
                <p class="small text-muted mb-3">Subscribe sekali — jadwal otomatis terupdate di kalender perangkat Anda.</p>
                <a class="btn btn-primary btn-lg w-100 mb-2" href="<?= htmlspecialchars($webcalUrl) ?>">
                    <i class="fa-solid fa-calendar-plus me-2"></i>Hubungkan ke Kalender HP Saya
                </a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($googleCalSub) ?>" target="_blank" rel="noopener">Buka di Google Calendar</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tasks === []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="fa-solid fa-clipboard-check fs-1 mb-2 opacity-50"></i>
                <p class="mb-0">Belum ada tugas aktif untuk Anda.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="vstack gap-2">
            <?php foreach ($tasks as $t): ?>
                <?php
                $isPj = in_array($userId, (array) ($t['pj_ids'] ?? []), true);
                $isPembantu = in_array($userId, (array) ($t['pembantu_ids'] ?? []), true);
                $peranLbl = $isPj ? 'PJ' : ($isPembantu ? 'Pembantu' : 'Tim');
                ?>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas_yayasan.php?id=' . (int) ($t['id'] ?? 0))) ?>" class="card border-0 shadow-sm text-decoration-none text-body yt-pb-card">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <strong class="small"><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></strong>
                            <div class="text-end">
                                <span class="badge text-bg-light border me-1"><?= htmlspecialchars($peranLbl) ?></span>
                                <span class="badge text-bg-<?= htmlspecialchars((string) ($t['category_badge'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($t['category'] ?? '')) ?></span>
                            </div>
                        </div>
                        <div class="small text-muted">Tenggat: <?= htmlspecialchars((string) ($t['due_at'] ?? '')) ?></div>
                        <span class="badge text-bg-<?= htmlspecialchars((string) ($t['status_badge'] ?? 'secondary')) ?> mt-2"><?= htmlspecialchars((string) ($t['status_label'] ?? '')) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
