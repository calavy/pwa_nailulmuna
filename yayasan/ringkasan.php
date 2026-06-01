<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_portal.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$todos = yayasan_todo_mendesak($pdo);
$agenda = yayasan_kegiatan_mendatang($pdo);
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';

$pageTitle = 'Yayasan — To-Do & Agenda';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a></p>
        <h1 class="h3 mb-1">Yayasan</h1>
        <p class="text-muted mb-0">To-do mendesak pengurus & kegiatan terdekat ke depan.</p>
    </header>

    <div class="row g-4">
        <div class="col-lg-6">
            <section>
                <h2 class="h5 mb-3"><i class="fa-solid fa-fire text-danger me-2"></i>To-Do List Mendesak</h2>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <?php if ($todos === []): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fa-solid fa-circle-check text-success fs-3 mb-2"></i>
                                <p class="mb-0">Tidak ada tugas mendesak saat ini.</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($todos as $todo): ?>
                                    <?php
                                    $lvl = (string) ($todo['level'] ?? 'info');
                                    $badge = match ($lvl) {
                                        'danger' => 'danger',
                                        'warning' => 'warning',
                                        default => 'secondary',
                                    };
                                    $icon = trim((string) ($todo['icon'] ?? 'fa-circle'));
                                    if (!str_contains($icon, 'fa-')) {
                                        $icon = 'fa-' . $icon;
                                    }
                                    ?>
                                    <li class="list-group-item">
                                        <div class="d-flex gap-3">
                                            <div class="yp-todo-icon text-<?= htmlspecialchars($badge) ?>">
                                                <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                                            </div>
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($todo['judul'] ?? '')) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars((string) ($todo['deskripsi'] ?? '')) ?></div>
                                                <?php if (!empty($todo['href'])): ?>
                                                    <a class="small" href="<?= htmlspecialchars(app_href((string) $todo['href'])) ?>">Kerjakan <i class="fa-solid fa-arrow-right ms-1"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section>
                <h2 class="h5 mb-3"><i class="fa-solid fa-calendar-days text-primary me-2"></i>Kegiatan Terdekat</h2>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <?php if ($agenda === []): ?>
                            <div class="p-4 text-center text-muted">
                                <p class="mb-2">Belum ada rapat atau agenda dalam 3 minggu ke depan.</p>
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">Jadwalkan rapat</a>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($agenda as $ev): ?>
                                    <?php
                                    $ts = strtotime((string) ($ev['tanggal'] ?? ''));
                                    $tglLabel = $ts ? date('D, d M Y', $ts) : (string) ($ev['tanggal'] ?? '');
                                    ?>
                                    <li class="list-group-item">
                                        <div class="d-flex justify-content-between gap-2">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($ev['judul'] ?? '')) ?></div>
                                                <div class="small text-muted">
                                                    <?= htmlspecialchars($tglLabel) ?>
                                                    <?php if (!empty($ev['waktu'])): ?> · <?= htmlspecialchars((string) $ev['waktu']) ?><?php endif; ?>
                                                    · <?= htmlspecialchars((string) ($ev['jenis'] ?? '')) ?>
                                                </div>
                                                <?php if (!empty($ev['tempat'])): ?>
                                                    <div class="small"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars((string) $ev['tempat']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= htmlspecialchars(app_href((string) ($ev['href'] ?? '/yayasan/rapat.php'))) ?>">Detail</a>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
