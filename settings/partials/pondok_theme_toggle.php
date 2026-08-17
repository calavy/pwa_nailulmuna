<?php

declare(strict_types=1);

$uiThemeMode = pondok_ui_theme_mode($pdo ?? null);
$themeSaveUrl = app_href('/settings/admin.php');
?>
<div class="card shadow-sm mb-3" id="theme-settings-card" data-theme-save-url="<?= htmlspecialchars($themeSaveUrl) ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="flex-grow-1" style="min-width: 12rem;">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge rounded-pill text-bg-success-subtle text-success border border-success-subtle">
                        <i class="fa-solid fa-palette me-1" aria-hidden="true"></i> Super Admin
                    </span>
                </div>
                <h2 class="h5 mb-1">Mode tampilan sistem</h2>
                <p class="small text-muted mb-0">Berlaku untuk <strong>seluruh pengguna</strong> (pengurus, wali, santri, halaman login). Hanya super admin yang dapat mengubah. Pilih terang (hijau toska) atau gelap (slate + emerald).</p>
            </div>
            <div class="btn-group app-theme-switch" role="group" aria-label="Pilih mode tampilan pondok">
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-light" value="light" autocomplete="off" <?= $uiThemeMode === 'light' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="theme-mode-light">
                    <i class="fa-solid fa-sun me-1" aria-hidden="true"></i>
                    Terang
                </label>
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-dark" value="dark" autocomplete="off" <?= $uiThemeMode === 'dark' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="theme-mode-dark">
                    <i class="fa-solid fa-moon me-1" aria-hidden="true"></i>
                    Gelap
                </label>
            </div>
        </div>
    </div>
</div>
