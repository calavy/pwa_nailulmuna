<?php

declare(strict_types=1);

/**
 * Panel ringkasan hak akses user (read-only).
 *
 * @var array<string,mixed> $aksesSummary dari user_permission_access_summary()
 * @var bool $aksesPanelCompact tampilan ringkas di profil
 */
$aksesSummary = is_array($aksesSummary ?? null) ? $aksesSummary : [];
$aksesPanelCompact = !empty($aksesPanelCompact);

$isSuper = !empty($aksesSummary['is_super_admin']);
$fullAccess = !empty($aksesSummary['full_access']);
$role = (string) ($aksesSummary['role'] ?? '');
$note = trim((string) ($aksesSummary['full_access_note'] ?? ''));
$groups = (array) ($aksesSummary['groups'] ?? []);
$menuPreview = (array) ($aksesSummary['menu_preview'] ?? []);
$allowedCount = count((array) ($aksesSummary['allowed_keys'] ?? []));
$menuCount = count($menuPreview);

if (!function_exists('menu_tile_icon_for_path')) {
    require_once __DIR__ . '/../../helpers/app.php';
}
?>
<div class="card shadow-sm border-0<?= $aksesPanelCompact ? '' : ' mb-4' ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    </span>
                    <h2 class="h6 mb-0">Hak akses &amp; tampilan menu</h2>
                </div>
                <p class="small text-muted mb-0">
                    Daftar fitur yang <strong>diizinkan</strong> untuk akun Anda sesuai pengaturan super admin.
                </p>
            </div>
            <?php if ($aksesPanelCompact): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/settings/akses_saya.php')) ?>">Lihat lengkap</a>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($isSuper): ?>
                <span class="badge text-bg-danger">Super Admin</span>
            <?php endif; ?>
            <?php if ($role !== ''): ?>
                <span class="badge text-bg-light border text-dark text-capitalize"><?= htmlspecialchars($role) ?></span>
            <?php endif; ?>
            <?php if ($fullAccess): ?>
                <span class="badge text-bg-success">Akses penuh</span>
            <?php else: ?>
                <span class="badge text-bg-info"><?= (int) $allowedCount ?> fitur</span>
                <span class="badge text-bg-secondary"><?= (int) $menuCount ?> menu</span>
            <?php endif; ?>
        </div>

        <?php if ($note !== ''): ?>
            <div class="alert alert-light border small py-2 mb-3"><?= htmlspecialchars($note) ?></div>
        <?php endif; ?>

        <?php if ($aksesPanelCompact): ?>
            <?php if ($groups !== []): ?>
                <p class="small text-muted mb-2">Contoh fitur yang diizinkan:</p>
                <ul class="small mb-0 ps-3">
                    <?php
                    $shown = 0;
                    foreach ($groups as $group):
                        foreach ((array) ($group['items'] ?? []) as $item):
                            if ($shown >= 5) {
                                break 2;
                            }
                            $shown++;
                            ?>
                            <li><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></li>
                        <?php endforeach;
                    endforeach;
                    if ($allowedCount > $shown): ?>
                        <li class="text-muted">… dan <?= (int) ($allowedCount - $shown) ?> fitur lainnya</li>
                    <?php endif; ?>
                </ul>
            <?php elseif ($fullAccess): ?>
                <p class="small text-muted mb-0">Semua modul sesuai peran Anda tersedia di menu navigasi.</p>
            <?php else: ?>
                <p class="small text-muted mb-0">Belum ada fitur khusus yang dicatat. Hubungi super admin jika menu terasa kurang.</p>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($groups !== []): ?>
                <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.68rem;">Fitur diizinkan</div>
                <div class="row g-3 mb-4">
                    <?php foreach ($groups as $group): ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="border rounded-3 p-3 h-100 bg-light bg-opacity-50">
                                <div class="fw-semibold small mb-2"><?= htmlspecialchars((string) ($group['label'] ?? '')) ?></div>
                                <ul class="list-unstyled small mb-0 d-flex flex-column gap-1">
                                    <?php foreach ((array) ($group['items'] ?? []) as $item): ?>
                                        <li class="d-flex align-items-start gap-2">
                                            <i class="fa-solid fa-circle-check text-success mt-1" aria-hidden="true"></i>
                                            <span><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($menuPreview !== []): ?>
                <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.68rem;">Menu yang tampil di navigasi</div>
                <div class="row g-2">
                    <?php foreach ($menuPreview as $menuRow): ?>
                        <?php
                        $mPath = (string) ($menuRow['path'] ?? '');
                        $mLabel = (string) ($menuRow['label'] ?? '');
                        $mIcon = menu_tile_icon_for_path($mPath);
                        ?>
                        <div class="col-sm-6 col-lg-4">
                            <a class="d-flex align-items-center gap-2 text-decoration-none border rounded-3 px-3 py-2 h-100 user-akses-menu-link"
                               href="<?= htmlspecialchars(app_href($mPath)) ?>">
                                <i class="<?= htmlspecialchars($mIcon) ?> text-primary opacity-75" aria-hidden="true"></i>
                                <span class="small fw-semibold text-body"><?= htmlspecialchars($mLabel) ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($fullAccess): ?>
                <p class="small text-muted mb-0">Buka menu samping atau dashboard untuk melihat semua modul yang tersedia.</p>
            <?php else: ?>
                <p class="small text-muted mb-0">Tidak ada menu tambahan di luar dashboard. Minta super admin menambah hak akses jika diperlukan.</p>
            <?php endif; ?>

            <p class="small text-muted mt-3 mb-0">
                Perubahan hak akses hanya dapat dilakukan oleh <strong>super admin</strong>
                <?php if ($isSuper || (function_exists('user_can_access_permission_key') && user_can_access_permission_key('settings_admin'))): ?>
                    di <a href="<?= htmlspecialchars(app_href('/settings/admin.php')) ?>">Pengaturan → Kelola Akses User</a>.
                <?php else: ?>
                    melalui menu Pengaturan → Kelola Akses User.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
