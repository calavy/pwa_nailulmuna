<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

ensure_santri_identity_columns($pdo);

if (isset($_SESSION['wali']['santri_id'])) {
    app_redirect('wali/index.php');
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
    $redirectPin = app_href('/wali/login.php') . ($nis !== '' ? '?nis=' . urlencode($nis) : '');

    if ($nis === '' || $pin === '') {
        set_flash('error', 'Isi NIS dan PIN portal wali.');
        header('Location: ' . app_href($redirectPin));
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
        set_flash('error', 'NIS atau PIN salah, atau PIN wali belum diatur pengurus. Hubungi administrasi pondok.');
        header('Location: ' . app_href($redirectPin));
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['wali'] = [
        'santri_id' => (int) $row['id'],
        'nis' => (string) $row['nis'],
        'nama_santri' => (string) ($row['nama_santri'] ?? ''),
        'wali_santri_id' => (int) ($row['wali_santri_id'] ?? 0),
    ];
    app_redirect('wali/index.php');
}

$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$welcome = auth_portal_welcome_copy($pdo);
$hasSelected = $selectedSantri !== null;

auth_portal_layout_begin([
    'title' => 'Portal Wali Santri',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => $welcome['tagline_portal'],
    'subtitle_mobile' => 'Masuk dengan NIS anak dan PIN portal wali. Bukan username pengurus.',
    'subtitle_desktop' => 'Masuk dengan NIS anak dan PIN portal wali. Bukan username pengurus.',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => '',
    'layout' => 'stack',
    'shell_mod' => 'wali',
    'card_title' => 'Portal Wali Santri',
    'card_meta' => 'Tagihan · presensi · perkembangan anak',
    'accent' => 'teal',
]);

$err = get_flash('error');
$ok = get_flash('success');
?>
                <?php if ($err): ?>
                    <div class="alert alert-danger py-2 small mb-3" role="alert"><?= htmlspecialchars($err) ?></div>
                <?php endif; ?>
                <?php if ($ok): ?>
                    <div class="alert alert-success py-2 small mb-3" role="status"><?= htmlspecialchars($ok) ?></div>
                <?php endif; ?>

                <div class="auth-portal-inner-panel mb-3" id="wali-login-card">
                    <h2 class="auth-portal-inner-title"><i class="fa-solid fa-id-card me-1" aria-hidden="true"></i> Masuk dengan NIS</h2>
                    <p class="small text-muted mb-3">Identitas masuk adalah <strong>NIS santri</strong> yang sudah terdaftar, bukan username pengurus. PIN wali diatur admin di menu Edit Santri (berbeda dari PIN santri/cashless).</p>
                    <form method="post" class="auth-portal-form d-grid gap-3" autocomplete="on" action="<?= htmlspecialchars(app_href('/wali/login.php')) ?>" id="wali-login-form">
                        <div>
                            <label class="form-label" for="wali-nis">NIS</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-hashtag" aria-hidden="true"></i></span>
                                <input id="wali-nis" type="text" name="nis" class="form-control" required value="<?= htmlspecialchars($prefillNis) ?>" autocomplete="username" inputmode="numeric" placeholder="Nomor induk santri">
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="wali-pin">PIN portal wali</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                <input id="wali-pin" type="password" name="pin" class="form-control" required autocomplete="current-password" minlength="6" inputmode="numeric" placeholder="6 digit atau lebih">
                                <button class="btn btn-outline-secondary" type="button" id="wali-pin-toggle" aria-label="Tampilkan atau sembunyikan PIN" title="Lihat PIN">
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-auth-primary btn-lg w-100">Masuk ke portal</button>
                    </form>
                </div>

                <details class="auth-portal-inner-panel mb-3" id="wali-search-card" <?= ($cari !== '' || $hasSelected) ? 'open' : '' ?>>
                    <summary class="auth-portal-wali-forgot">Lupa NIS? Cari nama santri</summary>
                    <h2 class="auth-portal-inner-title mt-3"><i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i> Cari nama atau NIS</h2>
                    <form method="get" class="d-flex gap-2 align-items-stretch auth-portal-form" action="<?= htmlspecialchars(app_href('/wali/login.php')) ?>" role="search">
                        <label class="visually-hidden" for="wali-cari-q">Cari nama atau NIS</label>
                        <input id="wali-cari-q" type="search" name="q" class="form-control" value="<?= htmlspecialchars($cari) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off" enterkeyhint="search">
                        <button type="submit" class="btn btn-auth-primary px-3 flex-shrink-0">Cari</button>
                    </form>
                    <?php if ($cari !== ''): ?>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="small text-muted">Hasil pencarian</span>
                            <a href="<?= htmlspecialchars(app_href('/wali/login.php')) ?>" class="btn btn-link btn-sm py-0">Bersihkan</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($cari !== '' && $cariHasil === []): ?>
                        <p class="small text-danger mb-0 mt-2">Tidak ada santri aktif yang cocok. Periksa ejaan nama atau NIS.</p>
                    <?php elseif ($cariHasil !== []): ?>
                        <p class="small text-muted mb-2 mt-3">Ketuk nama untuk mengisi NIS di form masuk:</p>
                        <div class="auth-portal-wali-pick-list" id="wali-pick-list" role="listbox" aria-label="Daftar santri">
                            <?php foreach ($cariHasil as $ch): ?>
                                <?php
                                $nisRow = (string) ($ch['nis'] ?? '');
                                $isPicked = $hasSelected && $prefillNis === $nisRow;
                                ?>
                                <button type="button"
                                        class="auth-portal-wali-pick<?= $isPicked ? ' is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= $isPicked ? 'true' : 'false' ?>"
                                        data-nis="<?= htmlspecialchars($nisRow) ?>"
                                        data-nama="<?= htmlspecialchars((string) ($ch['nama_tampil'] ?? '')) ?>">
                                    <span class="fw-semibold"><?= htmlspecialchars((string) ($ch['nama_tampil'] ?? '')) ?></span>
                                    <span class="font-monospace text-muted"><?= htmlspecialchars($nisRow) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($cari === '' && !$hasSelected): ?>
                        <p class="small text-muted mb-0 mt-2">Ketik nama anak, lalu pilih dari hasil agar NIS terisi otomatis.</p>
                    <?php endif; ?>
                    <?php if ($hasSelected): ?>
                        <div class="auth-portal-wali-selected mt-3" id="wali-selected-display">
                            <div class="small text-muted mb-1">Santri terpilih</div>
                            <div class="fw-bold" id="wali-selected-name"><?= htmlspecialchars((string) ($selectedSantri['nama_tampil'] ?? '')) ?></div>
                            <div class="font-monospace small text-muted" id="wali-selected-nis">NIS <?= htmlspecialchars((string) ($selectedSantri['nis'] ?? '')) ?></div>
                        </div>
                    <?php else: ?>
                        <div class="auth-portal-wali-selected mt-3 d-none" id="wali-selected-display">
                            <div class="small text-muted mb-1">Santri terpilih</div>
                            <div class="fw-bold" id="wali-selected-name"></div>
                            <div class="font-monospace small text-muted" id="wali-selected-nis"></div>
                        </div>
                    <?php endif; ?>
                </details>

                <p class="auth-portal-footnote mb-0">Portal khusus wali santri. Untuk bantuan PIN, hubungi administrasi pondok.</p>
<script>
(function () {
    var pickList = document.getElementById('wali-pick-list');
    var nisInput = document.getElementById('wali-nis');
    var nameEl = document.getElementById('wali-selected-name');
    var nisEl = document.getElementById('wali-selected-nis');
    var pinInput = document.getElementById('wali-pin');
    var selectedBox = document.getElementById('wali-selected-display');

    function setSelected(nis, nama) {
        if (nisInput) {
            nisInput.value = nis || '';
        }
        if (nameEl) nameEl.textContent = nama || '';
        if (nisEl) nisEl.textContent = nis ? 'NIS ' + nis : '';
        if (selectedBox) selectedBox.classList.remove('d-none');
        if (pickList) {
            pickList.querySelectorAll('.auth-portal-wali-pick').forEach(function (btn) {
                var on = btn.getAttribute('data-nis') === nis;
                btn.classList.toggle('is-selected', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        if (pinInput) pinInput.focus();
        if (nisInput) {
            nisInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    if (pickList) {
        pickList.addEventListener('click', function (e) {
            var btn = e.target.closest('.auth-portal-wali-pick');
            if (!btn) return;
            setSelected(btn.getAttribute('data-nis'), btn.getAttribute('data-nama'));
        });
    }

    <?php if ($hasSelected): ?>
    if (pinInput) pinInput.focus();
    <?php else: ?>
    if (nisInput) nisInput.focus();
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
auth_portal_layout_end([
    ['href' => app_href('/login.php'), 'label' => 'Portal pengurus'],
    ['href' => app_href('/santri_portal/login.php'), 'label' => 'Portal santri'],
]);
