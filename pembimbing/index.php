<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';
require_once __DIR__ . '/../helpers/pembimbing_kelas.php';

require_roles(['admin', 'pengurus']);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS pembimbing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qr VARCHAR(120) NULL,
        nip VARCHAR(40) NOT NULL UNIQUE,
        nama_pembimbing VARCHAR(120) NOT NULL,
        no_wa VARCHAR(30) NULL,
        is_aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

payroll_pembimbing_ensure_schema($pdo);
login_pembimbing_ensure_password_plain_column($pdo);
pembimbing_kelas_ensure_schema($pdo);

// Pastikan ENUM role di tabel users sudah memuat 'pembimbing' supaya akun
// login pembimbing bisa dibuat lewat halaman ini.
if (table_exists($pdo, 'users')) {
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
    } catch (PDOException $e) { /* abaikan MySQL lama */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'qr' => trim((string) ($_POST['qr'] ?? '')),
        'nip' => trim((string) ($_POST['nip'] ?? '')),
        'nama' => trim((string) ($_POST['nama_pembimbing'] ?? '')),
        'wa' => trim((string) ($_POST['no_wa'] ?? '')),
    ];
    $passwordRaw = (string) ($_POST['password'] ?? '');
    // Default: selalu buatkan akun login agar kolom USER/PASS terisi.
    $createAccount = !isset($_POST['create_account']) || $_POST['create_account'] === '1';

    if ($data['nip'] === '' || $data['nama'] === '') {
        set_flash('error', 'NIP dan nama pengurus wajib diisi.');
        header('Location: ' . app_href('/pembimbing/index.php'));
        exit;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO pembimbing (qr, nip, nama_pembimbing, no_wa) VALUES (:qr, :nip, :nama, :wa)');
        $stmt->execute($data);

        $flashMsg = 'Data pengurus ditambahkan. Kelas yang dikaji akan otomatis terisi setelah pembimbing dimasukkan ke jadwal.';

        if ($createAccount && table_exists($pdo, 'users')) {
            $checkUser = $pdo->prepare('SELECT id FROM users WHERE TRIM(username) = :u LIMIT 1');
            $checkUser->execute(['u' => $data['nip']]);
            if (!$checkUser->fetch()) {
                // Default: password acak (BUKAN NIP — NIP sudah dipakai sebagai USER).
                $pwd = $passwordRaw !== '' ? $passwordRaw : login_pembimbing_buat_password_acak();
                $insertU = $pdo->prepare(
                    "INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :pwd, 'pembimbing')"
                );
                $insertU->execute([
                    'nama' => $data['nama'],
                    'username' => $data['nip'],
                    'pwd' => password_hash($pwd, PASSWORD_DEFAULT),
                ]);
                $newUid = (int) $pdo->lastInsertId();
                if ($newUid > 0) {
                    login_pembimbing_set_password_by_admin($pdo, $newUid, $pwd);
                    login_pembimbing_ensure_acl($pdo, $newUid);
                }
                $flashMsg .= ' Akun login dibuat — USER: ' . $data['nip'] . ' · PASS: ' . $pwd;
            } else {
                $flashMsg .= ' (Akun login dengan username "' . $data['nip'] . '" sudah ada — tidak ditimpa.)';
            }
        }
        set_flash('success', $flashMsg);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false) {
            set_flash('error', 'NIP "' . $data['nip'] . '" sudah terdaftar.');
        } else {
            set_flash('error', 'Gagal menyimpan: ' . $msg);
        }
    }

    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$hasUsersTable = table_exists($pdo, 'users');
$usersHasPwdPlain = $hasUsersTable && function_exists('column_exists') && column_exists($pdo, 'users', 'password_plain');
$selectUserCols = $usersHasPwdPlain
    ? 'u.id AS user_id, u.username AS user_username, u.password_plain AS user_password_plain, u.role AS user_role'
    : 'u.id AS user_id, u.username AS user_username, NULL AS user_password_plain, u.role AS user_role';

if ($hasUsersTable) {
    $rows = $pdo->query("
        SELECT p.id, p.qr, p.nip, p.nama_pembimbing, p.no_wa, p.is_aktif,
               p.gaji_pokok, p.tarif_kriteria,
               {$selectUserCols}
        FROM pembimbing p
        LEFT JOIN users u ON TRIM(u.username) = TRIM(p.nip)
        ORDER BY p.nama_pembimbing ASC
    ")->fetchAll();
} else {
    $rows = $pdo->query('SELECT id, qr, nip, nama_pembimbing, no_wa, is_aktif, gaji_pokok, tarif_kriteria, NULL AS user_id, NULL AS user_username, NULL AS user_password_plain, NULL AS user_role FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll();
}

$payrollTarifMap = payroll_pembimbing_tarif_map($pdo);
$payrollLabels = payroll_pembimbing_kriteria_labels();

$kelasMap = pembimbing_kelas_map_all($pdo);
$jadwalCountMap = pembimbing_kelas_jadwal_count_map($pdo);

$totalPembimbing = count($rows);
$pembimbingAktif = count(array_filter($rows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 1) === 1));
$pembimbingNonAktif = $totalPembimbing - $pembimbingAktif;
$pembimbingPunyaAkun = count(array_filter($rows, static fn(array $r): bool => (int) ($r['user_id'] ?? 0) > 0));
$pembimbingBelumPunyaAkun = $totalPembimbing - $pembimbingPunyaAkun;
$pembimbingSudahDapatJadwal = count(array_filter($rows, static fn(array $r): bool => ($jadwalCountMap[(int) $r['id']] ?? 0) > 0));
$pembimbingBelumDapatJadwal = $totalPembimbing - $pembimbingSudahDapatJadwal;

$pageTitle = 'Data Pengurus';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
    <div class="flex-grow-1">
        <p class="page-intro-kicker mb-1">Santri &amp; SDM · Pengurus / Pembimbing</p>
        <h1 class="h4 mb-1">Data pengurus &amp; akun login</h1>
        <p class="text-muted mb-0 small">
            Kelola identitas, akun login, dan kelas yang diampu. <strong>Kelas yang dikaji</strong>
            otomatis turun dari <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="text-decoration-none">slot jadwal</a>.
        </p>
    </div>
    <button class="btn btn-sm btn-primary flex-shrink-0" type="button" data-bs-toggle="collapse" data-bs-target="#pengurusFormCard" aria-expanded="false" aria-controls="pengurusFormCard">
        <i class="fa-solid fa-plus me-1"></i> Formulir
    </button>
</div>

<?php
$flashOk = get_flash('success');
$flashErr = get_flash('error');
?>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small mb-3"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-users me-1 text-secondary"></i>Total pengurus</div>
            <div class="app-mini-stat-value"><?= $totalPembimbing ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-circle-check me-1 text-success"></i>Aktif</div>
            <div class="app-mini-stat-value text-success"><?= $pembimbingAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-calendar-check me-1 text-primary"></i>Sudah ada jadwal</div>
            <div class="app-mini-stat-value text-primary"><?= $pembimbingSudahDapatJadwal ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Belum dapat jadwal</div>
            <div class="app-mini-stat-value <?= $pembimbingBelumDapatJadwal > 0 ? 'text-warning' : 'text-success' ?>"><?= $pembimbingBelumDapatJadwal ?></div>
        </div>
    </div>
</div>

<?php
$showFormOnLoad = $flashErr !== null && stripos((string) $flashErr, 'NIP') !== false;
?>
<div class="collapse <?= $showFormOnLoad ? 'show' : '' ?> mb-4" id="pengurusFormCard">
    <div class="card shadow-sm border-primary-subtle">
        <div class="card-header bg-primary-subtle border-0 d-flex justify-content-between align-items-center py-2">
            <h2 class="h6 mb-0 text-primary-emphasis">
                <i class="fa-solid fa-user-plus me-1"></i> Formulir tambah pengurus
            </h2>
            <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#pengurusFormCard" aria-label="Tutup formulir"></button>
        </div>
        <div class="card-body">
            <form method="post" autocomplete="off">
                <p class="page-intro-kicker mb-2 small text-muted">
                    <i class="fa-solid fa-id-card me-1"></i> Identitas pengurus
                </p>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1">QR <span class="text-muted">(opsional)</span></label>
                        <input class="form-control" name="qr" placeholder="Boleh sama dengan NIP">
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1">NIP <span class="text-danger">*</span></label>
                        <input class="form-control" name="nip" placeholder="Jadi USER login" required>
                        <div class="form-text small">NIP = USER login otomatis.</div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1">NAMA <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_pembimbing" placeholder="Nama lengkap" required>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label small text-muted mb-1">No WA <span class="text-muted">(opsional)</span></label>
                        <input class="form-control" name="no_wa" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="col-12 col-sm-6 col-md-8">
                        <label class="form-label small text-muted mb-1">
                            PASS awal <span class="text-muted">(opsional — kosongkan = acak)</span>
                        </label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="inpPassword" name="password" placeholder="Ketik / klik Acak" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" id="btnPassToggle" title="Tampilkan/sembunyikan">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnPassRandom" title="Buatkan password acak">
                                <i class="fa-solid fa-dice"></i><span class="d-none d-sm-inline ms-1">Acak</span>
                            </button>
                        </div>
                    </div>
                </div>

                <p class="page-intro-kicker mb-2 small text-muted">
                    <i class="fa-solid fa-shield-halved me-1"></i> Pratinjau kredensial &amp; jadwal
                </p>
                <div class="border rounded p-2 px-3 bg-light mb-3">
                    <div class="d-flex flex-wrap align-items-center column-gap-3 row-gap-1">
                        <div class="small">USER: <code id="prevUserCode" class="user-select-all">—</code></div>
                        <div class="small">PASS: <code id="prevPassCode" class="user-select-all">—</code></div>
                    </div>
                    <div class="small text-muted mt-1">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Akun login dibuat otomatis (role <em>pembimbing</em>). Password tersimpan terbaca admin sampai pembimbing menggantinya sendiri.
                    </div>
                    <hr class="my-2">
                    <div class="small text-muted">
                        <i class="fa-solid fa-chalkboard-user me-1 text-primary"></i>
                        <strong>Kelas yang dikaji</strong> akan terisi otomatis setelah pengurus ini dimasukkan ke
                        <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>">slot jadwal</a>.
                    </div>
                </div>

                <input type="hidden" name="create_account" value="1">

                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-sm-end">
                    <button type="button" class="btn btn-outline-secondary order-sm-1" data-bs-toggle="collapse" data-bs-target="#pengurusFormCard">
                        Batal
                    </button>
                    <button class="btn btn-success order-sm-2">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Simpan pengurus
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-transparent border-bottom-0 pt-3 pb-0">
        <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center gap-2">
            <div>
                <h2 class="h5 mb-0">Daftar pengurus</h2>
                <p class="small text-muted mb-0"><?= $totalPembimbing ?> akun terdaftar · cari cepat di kanan.</p>
            </div>
            <div class="position-relative w-100 w-sm-auto" style="max-width:300px">
                <i class="fa-solid fa-magnifying-glass position-absolute top-50 start-0 translate-middle-y ms-2 text-muted small" aria-hidden="true"></i>
                <input type="search" id="pengurus-search" class="form-control form-control-sm ps-4" placeholder="Cari NIP, nama, atau kelas…">
            </div>
        </div>
    </div>
    <div class="card-body pt-2">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="pengurus-table">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="text-nowrap">QR</th>
                        <th class="text-nowrap">NIP</th>
                        <th>Nama</th>
                        <th class="text-nowrap">User</th>
                        <th class="text-nowrap">Password</th>
                        <th>Kelas yg dikaji</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $pid = (int) $row['id'];
                    $userId = (int) ($row['user_id'] ?? 0);
                    $userRole = strtolower((string) ($row['user_role'] ?? ''));
                    $username = (string) ($row['user_username'] ?? '');
                    $pwdPlain = (string) ($row['user_password_plain'] ?? '');
                    $kelasList = $kelasMap[$pid] ?? [];
                    $jadwalCount = $jadwalCountMap[$pid] ?? 0;
                    $qrVal = trim((string) ($row['qr'] ?? ''));
                ?>
                    <tr>
                        <td class="font-monospace small text-muted">
                            <?= $qrVal !== '' ? htmlspecialchars($qrVal) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="font-monospace small fw-semibold"><?= htmlspecialchars($row['nip']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($row['nama_pembimbing']) ?>
                            <?php if (!empty($row['no_wa'])): ?>
                                <div class="small text-muted font-monospace"><i class="fa-brands fa-whatsapp me-1 text-success"></i><?= htmlspecialchars($row['no_wa']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="font-monospace small">
                            <?php if ($userId > 0): ?>
                                <span title="USER login"><?= htmlspecialchars($username) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-monospace small" style="min-width:160px">
                            <?php if ($userId > 0 && $pwdPlain !== ''): ?>
                                <div class="input-group input-group-sm flex-nowrap">
                                    <input type="password" class="form-control form-control-sm pengurus-pass" value="<?= htmlspecialchars($pwdPlain) ?>" readonly>
                                    <button class="btn btn-outline-secondary btn-sm pengurus-pass-toggle" type="button" title="Tampilkan/sembunyikan">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                            <?php elseif ($userId > 0): ?>
                                <span class="text-muted small" title="Password sudah diganti user — admin tak bisa melihat. Reset di Edit.">
                                    <i class="fa-solid fa-lock me-1"></i>tersembunyi
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($kelasList !== []): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($kelasList as $kelas): ?>
                                        <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($kelas) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="small text-success mt-1">
                                    <i class="fa-solid fa-calendar-check me-1"></i><?= $jadwalCount ?> slot jadwal
                                </div>
                            <?php else: ?>
                                <div class="small text-warning-emphasis">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Belum mendapatkan jadwal
                                </div>
                                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="small text-decoration-none">
                                    <i class="fa-solid fa-plus me-1"></i>Tambahkan ke jadwal
                                </a>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int) $row['is_aktif'] === 1): ?>
                                <span class="badge text-bg-success-subtle text-success border border-success-subtle">
                                    <i class="fa-solid fa-circle-check me-1"></i>Aktif
                                </span>
                            <?php else: ?>
                                <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    <i class="fa-solid fa-pause me-1"></i>Izin/Pulang
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Aksi pengurus">
                                <a href="<?= htmlspecialchars(app_href('/pembimbing/edit.php?id=' . $pid)) ?>" class="btn btn-outline-primary" title="Edit pengurus & akun login">
                                    <i class="fa-solid fa-pen-to-square"></i><span class="d-none d-md-inline ms-1">Edit</span>
                                </a>
                                <form method="post"
                                      action="<?= htmlspecialchars(app_href('/pembimbing/edit.php?id=' . $pid)) ?>"
                                      class="m-0"
                                      onsubmit="return confirm('Hapus pengurus &quot;<?= htmlspecialchars(addslashes($row['nama_pembimbing'])) ?>&quot;? Data presensi/izin/jadwal terkait akan dibersihkan. Tindakan ini tidak bisa dibatalkan.');">
                                    <input type="hidden" name="_action" value="delete_pembimbing">
                                    <?php if ($userId > 0 && $userRole === 'pembimbing'): ?>
                                        <input type="hidden" name="hapus_akun" value="1">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-outline-danger" title="Hapus pengurus">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center py-5">
                        <i class="fa-regular fa-folder-open fa-2x text-muted mb-2 d-block"></i>
                        <div class="text-muted">Belum ada pengurus terdaftar.</div>
                        <button type="button" class="btn btn-sm btn-primary mt-2" data-bs-toggle="collapse" data-bs-target="#pengurusFormCard">
                            <i class="fa-solid fa-plus me-1"></i> Buka formulir tambah
                        </button>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    // ---- Helper form tambah: live preview USER & PASS ----
    var inpNip = document.querySelector('#pengurusFormCard input[name="nip"]');
    var inpPass = document.getElementById('inpPassword');
    var prevUserCode = document.getElementById('prevUserCode');
    var prevPassCode = document.getElementById('prevPassCode');
    var btnPassToggle = document.getElementById('btnPassToggle');
    var btnPassRandom = document.getElementById('btnPassRandom');

    function refreshPreview() {
        var nip = inpNip ? (inpNip.value || '').trim() : '';
        var pass = inpPass ? (inpPass.value || '').trim() : '';
        if (prevUserCode) {
            prevUserCode.textContent = nip !== '' ? nip : '—';
        }
        if (prevPassCode) {
            if (pass !== '') {
                prevPassCode.textContent = pass;
            } else {
                prevPassCode.textContent = '(otomatis dibuat acak saat simpan)';
            }
        }
    }
    if (inpNip) {
        inpNip.addEventListener('input', refreshPreview);
    }
    if (inpPass) {
        inpPass.addEventListener('input', refreshPreview);
    }

    if (btnPassToggle && inpPass) {
        btnPassToggle.addEventListener('click', function () {
            var t = inpPass.getAttribute('type') === 'password' ? 'text' : 'password';
            inpPass.setAttribute('type', t);
            var icon = btnPassToggle.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    }
    if (btnPassRandom && inpPass) {
        btnPassRandom.addEventListener('click', function () {
            // Password acak ramah-ketik: 6 karakter, huruf+angka, hindari ambigu.
            var alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
            var out = '';
            for (var i = 0; i < 6; i++) {
                out += alpha.charAt(Math.floor(Math.random() * alpha.length));
            }
            inpPass.value = out;
            inpPass.setAttribute('type', 'text');
            var icon = btnPassToggle ? btnPassToggle.querySelector('i') : null;
            if (icon && icon.classList.contains('fa-eye')) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
            refreshPreview();
        });
    }
    refreshPreview();

    // Toggle visibility password tiap baris.
    document.querySelectorAll('.pengurus-pass-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var grp = btn.closest('.input-group');
            if (!grp) { return; }
            var input = grp.querySelector('.pengurus-pass');
            if (!input) { return; }
            var isPwd = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPwd ? 'text' : 'password');
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    });

    // Pencarian sederhana di tabel.
    var search = document.getElementById('pengurus-search');
    var table = document.getElementById('pengurus-table');
    if (search && table) {
        search.addEventListener('input', function () {
            var q = (search.value || '').toLowerCase().trim();
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.children.length < 6) { return; }
                var hay = (tr.innerText || '').toLowerCase();
                tr.style.display = q === '' || hay.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
