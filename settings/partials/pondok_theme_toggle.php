<div class="card shadow-sm mb-3" id="theme-settings-card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="flex-grow-1" style="min-width: 12rem;">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge rounded-pill text-bg-success-subtle text-success border border-success-subtle">
                        <i class="fa-solid fa-palette me-1" aria-hidden="true"></i> Super Admin
                    </span>
                </div>
                <h2 class="h5 mb-1">Mode tampilan sistem</h2>
                <p class="small text-muted mb-0">Beralih antara mode terang (hijau toska) dan mode gelap (slate + emerald). Pilihan disimpan otomatis di perangkat ini via <code class="small">localStorage</code>.</p>
            </div>
            <div class="btn-group app-theme-switch" role="group" aria-label="Pilih mode tampilan">
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-light" value="light" autocomplete="off">
                <label class="btn btn-outline-primary" for="theme-mode-light">
                    <i class="fa-solid fa-sun me-1" aria-hidden="true"></i>
                    Terang
                </label>
                <input type="radio" class="btn-check" name="theme-mode" id="theme-mode-dark" value="dark" autocomplete="off">
                <label class="btn btn-outline-primary" for="theme-mode-dark">
                    <i class="fa-solid fa-moon me-1" aria-hidden="true"></i>
                    Gelap
                </label>
            </div>
        </div>
    </div>
</div>
