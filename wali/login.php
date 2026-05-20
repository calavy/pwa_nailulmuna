<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';

ensure_santri_identity_columns($pdo);

if (isset($_SESSION['wali']['santri_id'])) {
    header('Location: /pwa_nailulmuna/wali/index.php');
    exit;
}

$aktifSql = santri_sql_aktif_only('s');
$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$cari = trim((string) ($_GET['q'] ?? ''));
$prefillNis = trim((string) ($_GET['nis'] ?? $_POST['nis'] ?? ''));
$cariHasil = [];
$selectedSantri = null;

if ($cari !== '') {
    $sql = 'SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_tampil FROM santri s WHERE ' . $aktifSql
        . ' AND (s.nis LIKE :q OR s.' . $nameCol . ' LIKE :q2) ORDER BY s.' . $nameCol . ' ASC LIMIT 25';
    $st = $pdo->prepare($sql);
    $like = '%' . $cari . '%';
    $st->execute(['q' => $like, 'q2' => $like]);
    $cariHasil = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($prefillNis !== '') {
    $stSel = $pdo->prepare('SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_tampil FROM santri s WHERE s.nis = :nis AND ' . $aktifSql . ' LIMIT 1');
    $stSel->execute(['nis' => $prefillNis]);
    $selectedSantri = $stSel->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedSantri) {
        $prefillNis = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nis = trim((string) ($_POST['nis'] ?? ''));
    $pin = (string) ($_POST['pin'] ?? '');
    $redirectPin = '/pwa_nailulmuna/wali/login.php' . ($nis !== '' ? '?nis=' . urlencode($nis) : '');

    if ($nis === '' || $pin === '') {
        set_flash('error', 'Pilih santri terlebih dahulu, lalu masukkan PIN.');
        header('Location: ' . $redirectPin);
        exit;
    }

    $sql = 'SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_santri, s.wali_portal_pin_hash';
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $sql .= ', s.wali_santri_id';
    }
    $sql .= ' FROM santri s WHERE s.nis = :nis AND ' . $aktifSql . ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute(['nis' => $nis]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $hash = (string) ($row['wali_portal_pin_hash'] ?? '');
    if (!$row || $hash === '' || !password_verify($pin, $hash)) {
        set_flash('error', 'PIN salah atau belum diatur pengurus. Hubungi bagian administrasi pondok.');
        header('Location: ' . $redirectPin);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['wali'] = [
        'santri_id' => (int) $row['id'],
        'nis' => (string) $row['nis'],
        'nama_santri' => (string) ($row['nama_santri'] ?? ''),
        'wali_santri_id' => (int) ($row['wali_santri_id'] ?? 0),
    ];
    header('Location: /pwa_nailulmuna/wali/index.php');
    exit;
}

$namaInstitusi = trim((string) app_setting($pdo, 'nama_ponpes', 'A.P.I Nailul Muna'));
if ($namaInstitusi === '') {
    $namaInstitusi = 'A.P.I Nailul Muna';
}
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrlSetting = trim((string) app_setting($pdo, 'logo_url', ''));
$waliLogo = $logoPath !== '' ? '/pwa_nailulmuna/' . ltrim($logoPath, '/') : $logoUrlSetting;

$loginBrand = [
    'logo_url' => $waliLogo,
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $namaInstitusi,
    'headline' => 'Portal Wali Santri',
    'subheadline' => 'Cek tagihan bulanan, presensi, dan perkembangan anak — mudah dibaca di HP.',
];

$hasSelected = $selectedSantri !== null;

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Portal Wali', true, null, $loginBrand);

$err = get_flash('error');
$ok = get_flash('success');
?>
        <div class="wali-login-steps" aria-label="Langkah masuk">
            <div class="step <?= $hasSelected ? '' : 'active' ?>" id="wali-step-label-1">1. Cari &amp; pilih</div>
            <div class="step <?= $hasSelected ? 'active' : '' ?>" id="wali-step-label-2">2. Masukkan PIN</div>
        </div>

        <?php if ($err): ?>
            <div class="alert alert-danger py-2 small mb-3" role="alert"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <?php if ($ok): ?>
            <div class="alert alert-success py-2 small mb-3" role="status"><?= htmlspecialchars($ok) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm wali-card mb-3" id="wali-search-card">
            <div class="card-body p-3">
                <h2 class="h6 mb-2 text-teal"><i class="fa-solid fa-magnifying-glass me-1"></i> Cari nama atau NIS santri</h2>
                <form method="get" class="d-flex gap-2 align-items-stretch" action="/pwa_nailulmuna/wali/login.php" role="search">
                    <label class="visually-hidden" for="wali-cari-q">Cari nama atau NIS</label>
                    <input id="wali-cari-q" type="search" name="q" class="form-control" value="<?= htmlspecialchars($cari) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off" enterkeyhint="search" autofocus>
                    <button type="submit" class="btn btn-teal px-3">Cari</button>
                </form>
                <?php if ($cari !== ''): ?>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span class="small text-muted">Hasil pencarian</span>
                        <a href="/pwa_nailulmuna/wali/login.php" class="btn btn-link btn-sm py-0">Bersihkan</a>
                    </div>
                <?php endif; ?>
                <?php if ($cari !== '' && $cariHasil === []): ?>
                    <p class="small text-danger mb-0 mt-2">Tidak ada santri aktif yang cocok. Periksa ejaan nama atau NIS.</p>
                <?php elseif ($cariHasil !== []): ?>
                    <p class="small text-muted mb-2 mt-3">Ketuk nama santri untuk melanjutkan:</p>
                    <div class="list-group list-group-flush small rounded-3 border" id="wali-pick-list" role="listbox" aria-label="Daftar santri">
                        <?php foreach ($cariHasil as $ch): ?>
                            <?php
                            $nisRow = (string) ($ch['nis'] ?? '');
                            $isPicked = $hasSelected && $prefillNis === $nisRow;
                            ?>
                            <button type="button"
                                    class="list-group-item list-group-item-action wali-pick-item d-flex justify-content-between align-items-center py-2 text-start<?= $isPicked ? ' selected' : '' ?>"
                                    role="option"
                                    aria-selected="<?= $isPicked ? 'true' : 'false' ?>"
                                    data-nis="<?= htmlspecialchars($nisRow) ?>"
                                    data-nama="<?= htmlspecialchars((string) ($ch['nama_tampil'] ?? '')) ?>">
                                <span class="fw-semibold"><?= htmlspecialchars((string) ($ch['nama_tampil'] ?? '')) ?></span>
                                <span class="font-monospace text-muted ms-2"><?= htmlspecialchars($nisRow) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($cari === '' && !$hasSelected): ?>
                    <p class="small text-muted mb-0 mt-2">Mulai dengan mengetik nama atau nomor induk (NIS) anak Anda.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm wali-card <?= $hasSelected ? '' : 'd-none' ?>" id="wali-pin-panel">
            <div class="card-body p-4">
                <h2 class="h6 mb-3 text-teal"><i class="fa-solid fa-lock me-1"></i> Masukkan PIN portal wali</h2>
                <div class="wali-selected-card mb-3" id="wali-selected-display">
                    <div class="small text-muted mb-1">Santri terpilih</div>
                    <div class="wali-selected-name" id="wali-selected-name"><?= $hasSelected ? htmlspecialchars((string) ($selectedSantri['nama_tampil'] ?? '')) : '' ?></div>
                    <div class="font-monospace small text-muted" id="wali-selected-nis"><?= $hasSelected ? 'NIS ' . htmlspecialchars((string) ($selectedSantri['nis'] ?? '')) : '' ?></div>
                </div>
                <form method="post" class="d-grid gap-3" autocomplete="on" action="/pwa_nailulmuna/wali/login.php" id="wali-login-form">
                    <input type="hidden" name="nis" id="wali-nis-hidden" value="<?= htmlspecialchars($prefillNis) ?>">
                    <div>
                        <label class="form-label small text-muted" for="wali-pin">PIN</label>
                        <div class="input-group input-group-lg">
                            <input id="wali-pin" type="password" name="pin" class="form-control" required autocomplete="current-password" minlength="6" inputmode="numeric" placeholder="6 digit atau lebih">
                            <button class="btn btn-outline-secondary" type="button" id="wali-pin-toggle" aria-label="Tampilkan atau sembunyikan PIN" title="Lihat PIN">
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="form-text">PIN diberikan pengurus pondok. Bukan password admin.</div>
                    </div>
                    <button type="submit" class="btn btn-lg btn-teal">Masuk ke portal</button>
                    <button type="button" class="btn btn-link btn-sm" id="wali-ganti-santri">Ganti santri</button>
                </form>
            </div>
        </div>

        <p class="text-center small text-muted mt-4 mb-0 px-2">Portal khusus wali santri. Untuk bantuan PIN, hubungi administrasi pondok.</p>
<script>
(function () {
    var pickList = document.getElementById('wali-pick-list');
    var pinPanel = document.getElementById('wali-pin-panel');
    var nisHidden = document.getElementById('wali-nis-hidden');
    var nameEl = document.getElementById('wali-selected-name');
    var nisEl = document.getElementById('wali-selected-nis');
    var pinInput = document.getElementById('wali-pin');
    var step1 = document.getElementById('wali-step-label-1');
    var step2 = document.getElementById('wali-step-label-2');
    var gantiBtn = document.getElementById('wali-ganti-santri');

    function setSelected(nis, nama) {
        if (!nisHidden || !pinPanel) return;
        nisHidden.value = nis || '';
        if (nameEl) nameEl.textContent = nama || '';
        if (nisEl) nisEl.textContent = nis ? 'NIS ' + nis : '';
        pinPanel.classList.remove('d-none');
        if (step1) step1.classList.remove('active');
        if (step2) step2.classList.add('active');
        if (pickList) {
            pickList.querySelectorAll('.wali-pick-item').forEach(function (btn) {
                var on = btn.getAttribute('data-nis') === nis;
                btn.classList.toggle('selected', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        if (pinInput) {
            pinInput.focus();
        }
        pinPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearSelected() {
        if (nisHidden) nisHidden.value = '';
        if (pinPanel) pinPanel.classList.add('d-none');
        if (step1) step1.classList.add('active');
        if (step2) step2.classList.remove('active');
        if (pickList) {
            pickList.querySelectorAll('.wali-pick-item').forEach(function (btn) {
                btn.classList.remove('selected');
                btn.setAttribute('aria-selected', 'false');
            });
        }
        var q = document.getElementById('wali-cari-q');
        if (q) q.focus();
    }

    if (pickList) {
        pickList.addEventListener('click', function (e) {
            var btn = e.target.closest('.wali-pick-item');
            if (!btn) return;
            setSelected(btn.getAttribute('data-nis'), btn.getAttribute('data-nama'));
        });
    }

    if (gantiBtn) {
        gantiBtn.addEventListener('click', function () {
            clearSelected();
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', '/pwa_nailulmuna/wali/login.php' + (document.getElementById('wali-cari-q')?.value ? '?q=' + encodeURIComponent(document.getElementById('wali-cari-q').value) : ''));
            }
        });
    }

    <?php if ($hasSelected): ?>
    if (pinInput) pinInput.focus();
    <?php endif; ?>

    var toggle = document.getElementById('wali-pin-toggle');
    if (toggle && pinInput) {
        toggle.addEventListener('click', function () {
            var show = pinInput.getAttribute('type') === 'password';
            pinInput.setAttribute('type', show ? 'text' : 'password');
            toggle.innerHTML = show ? '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>' : '<i class="fa-regular fa-eye" aria-hidden="true"></i>';
            toggle.setAttribute('aria-label', show ? 'Sembunyikan PIN' : 'Tampilkan PIN');
        });
    }
})();
</script>
<?php
wali_layout_foot(true);
