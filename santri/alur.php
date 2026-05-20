<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

$pageTitle = 'Alur santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-4">
    <p class="page-intro-kicker mb-1">Santri &amp; SDM</p>
    <h1 class="h3 mb-2">Alur data santri</h1>
    <p class="text-muted mb-0">Jati diri = biodata <strong>seluruh</strong> santri (aktif &amp; sudah muqim/keluar), lewat menu <a href="/pwa_nailulmuna/santri/semua_jati.php">Semua jati diri</a>. Non aktif dari daftar aktif bisa lewat <strong>Non aktifkan</strong> (muqim=tamat vs keluar=belum tamat), lalu <strong>Santri keluar</strong> untuk keuangan &amp; surat (satu formulir untuk keduanya).</p>
</div>

<div class="row g-3">
    <div class="col-md-6 col-lg-4">
        <a href="/pwa_nailulmuna/santri/semua_jati.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 border-primary-subtle">
                <div class="card-body">
                    <div class="text-primary fw-bold mb-1">1 · Jati diri</div>
                    <h2 class="h6 text-body">Biodata seluruh santri</h2>
                    <p class="small text-muted mb-0">Santri aktif &amp; muqim/keluar. Dari sini juga ke <strong>Tambah santri</strong>.</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="/pwa_nailulmuna/santri/index.php" class="text-decoration-none">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-bold text-success mb-1">2 · Santri aktif</div>
                    <h2 class="h6 text-body">Data santri yang mondok</h2>
                    <p class="small text-muted mb-0">Yang sedang mondok. Gunakan <strong>Non aktifkan</strong> di tabel untuk muqim (tamat) atau keluar (belum tamat).</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="/pwa_nailulmuna/santri/mukimin.php" class="text-decoration-none">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-bold text-secondary mb-1">3 · Muqim &amp; keluar</div>
                    <h2 class="h6 text-body">Riwayat non aktif</h2>
                    <p class="small text-muted mb-0">Muqim (tamat) dan keluar (belum tamat) dalam satu daftar.</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="/pwa_nailulmuna/santri/keluar.php" class="text-decoration-none">
            <div class="card shadow-sm h-100 border-danger-subtle">
                <div class="card-body">
                    <div class="fw-bold text-danger mb-1">4 · Santri keluar</div>
                    <h2 class="h6 text-body">Keuangan &amp; surat resmi</h2>
                    <p class="small text-muted mb-0">Setelah non aktif (dari tabel aktif atau jati diri): keuangan, surat. Sama untuk muqim maupun keluar.</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="/pwa_nailulmuna/data/wali.php" class="text-decoration-none">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-bold text-body mb-1">Profil wali</div>
                    <h2 class="h6 text-body">Master wali santri</h2>
                    <p class="small text-muted mb-0">Membantu kelengkapan data di surat perizinan &amp; surat keluar.</p>
                </div>
            </div>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
