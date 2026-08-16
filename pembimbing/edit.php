<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';
require_once __DIR__ . '/../helpers/pembimbing_kelas.php';
require_once __DIR__ . '/../helpers/wa_pembimbing_scan.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';

require_roles(['admin', 'pengurus']);

payroll_pembimbing_ensure_schema($pdo);
login_pembimbing_ensure_password_plain_column($pdo);
pembimbing_kelas_ensure_schema($pdo);
pembimbing_ensure_wa_scan_reminder_column($pdo);
perizinan_approval_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT * FROM pembimbing WHERE id = :id');
$statement->execute(['id' => $id]);
$pembimbing = $statement->fetch();

if (!$pembimbing) {
    set_flash('error', 'Data pembimbing tidak ditemukan.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

// Pastikan kolom & ENUM users mendukung 'pembimbing'.
if (table_exists($pdo, 'users')) {
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai','petugas_koperasi') NOT NULL DEFAULT 'pengurus'");
    } catch (PDOException $e) { /* abaikan */ }
}

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

/**
 * Ambil akun users yang username-nya = NIP pembimbing.
 */
function pembimbing_edit_find_user_account(PDO $pdo, string $nip): ?array
{
    if (!table_exists($pdo, 'users') || $nip === '') {
        return null;
    }
    $cols = 'id, nama, username, role, is_super_admin';
    if (function_exists('column_exists') && column_exists($pdo, 'users', 'password_plain')) {
        $cols .= ', password_plain';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM users WHERE TRIM(username) = :u LIMIT 1");
    $st->execute(['u' => trim($nip)]);
    $row = $st->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? 'update_pembimbing');

    if ($action === 'update_pembimbing') {
        $gajiPokokRaw = (string) ($_POST['gaji_pokok'] ?? '0');
        $gajiPokokNum = (float) preg_replace('/[^0-9.]/', '', $gajiPokokRaw);
        if ($gajiPokokNum < 0) {
            $gajiPokokNum = 0;
        }
        $data = [
            'id' => $id,
            'qr' => trim($_POST['qr'] ?? ''),
            'nip' => trim($_POST['nip'] ?? ''),
            'nama' => trim($_POST['nama_pembimbing'] ?? ''),
            'wa' => trim($_POST['no_wa'] ?? ''),
            'is_aktif' => isset($_POST['is_aktif']) && $_POST['is_aktif'] === '1' ? 1 : 0,
            'gaji_pokok' => $gajiPokokNum,
            'wa_scan_reminder' => isset($_POST['wa_scan_reminder']) && $_POST['wa_scan_reminder'] === '1' ? 1 : 0,
            'wa_izin_notif' => isset($_POST['wa_izin_notif']) && $_POST['wa_izin_notif'] === '1' ? 1 : 0,
        ];
        if ($data['nip'] === '' || $data['nama'] === '') {
            set_flash('error', 'NIP & nama pembimbing wajib diisi.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }

        try {
            $update = $pdo->prepare('UPDATE pembimbing SET qr = :qr, nip = :nip, nama_pembimbing = :nama, no_wa = :wa, is_aktif = :is_aktif, gaji_pokok = :gaji_pokok, wa_scan_reminder = :wa_scan_reminder, wa_izin_notif = :wa_izin_notif WHERE id = :id');
            $update->execute($data);

            $renameNote = '';
            $nipLama = trim((string) ($pembimbing['nip'] ?? ''));
            if ($data['nip'] !== $nipLama && $nipLama !== '' && table_exists($pdo, 'users')) {
                // NIP berubah → ikutkan rename username pada akun users yang
                // sebelumnya terhubung (username == NIP lama). Hanya berlaku
                // untuk akun ber-role pembimbing.
                $lookupOld = $pdo->prepare('SELECT id, role, is_super_admin FROM users WHERE TRIM(username) = :u LIMIT 1');
                $lookupOld->execute(['u' => $nipLama]);
                $oldUser = $lookupOld->fetch();
                if ($oldUser && strtolower((string) $oldUser['role']) === 'pembimbing' && (int) $oldUser['is_super_admin'] !== 1) {
                    $clash = $pdo->prepare('SELECT id FROM users WHERE TRIM(username) = :u AND id <> :self LIMIT 1');
                    $clash->execute(['u' => $data['nip'], 'self' => (int) $oldUser['id']]);
                    if (!$clash->fetch()) {
                        $pdo->prepare('UPDATE users SET username = :nu, nama = :nm WHERE id = :id')
                            ->execute(['nu' => $data['nip'], 'nm' => $data['nama'], 'id' => (int) $oldUser['id']]);
                        $renameNote = ' Akun login juga di-rename ke username baru.';
                    } else {
                        $renameNote = ' Catatan: akun login TIDAK di-rename karena username "' . $data['nip'] . '" sudah dipakai user lain.';
                    }
                }
            } else {
                // NIP tidak berubah → samakan saja nama pada akun users.
                $acc = pembimbing_edit_find_user_account($pdo, $data['nip']);
                if ($acc && strtolower((string) $acc['role']) === 'pembimbing' && (int) $acc['is_super_admin'] !== 1) {
                    $pdo->prepare('UPDATE users SET nama = :nm WHERE id = :id')
                        ->execute(['nm' => $data['nama'], 'id' => (int) $acc['id']]);
                }
            }

            set_flash('success', 'Data pembimbing diperbarui.' . $renameNote);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false) {
                set_flash('error', 'NIP "' . $data['nip'] . '" sudah dipakai pembimbing lain.');
            } else {
                set_flash('error', 'Gagal update: ' . $msg);
            }
        }

        header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
        exit;
    }

    if ($action === 'account_create') {
        $nip = trim((string) ($pembimbing['nip'] ?? ''));
        $nama = trim((string) ($pembimbing['nama_pembimbing'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($nip === '') {
            set_flash('error', 'NIP pembimbing belum diisi.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        $existing = pembimbing_edit_find_user_account($pdo, $nip);
        if ($existing) {
            set_flash('error', 'Akun dengan username "' . $nip . '" sudah ada.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        // Default: password acak (BUKAN NIP — NIP sudah jadi USER).
        $pwd = $password !== '' ? $password : login_pembimbing_buat_password_acak();
        try {
            $insertU = $pdo->prepare("INSERT INTO users (nama, username, password, role) VALUES (:nama, :u, :p, 'pembimbing')");
            $insertU->execute([
                'nama' => $nama,
                'u' => $nip,
                'p' => password_hash($pwd, PASSWORD_DEFAULT),
            ]);
            $newUid = (int) $pdo->lastInsertId();
            if ($newUid > 0) {
                login_pembimbing_set_password_by_admin($pdo, $newUid, $pwd);
                login_pembimbing_ensure_acl($pdo, $newUid);
            }
            set_flash('success', 'Akun login dibuat. USER: ' . $nip . ' · PASS: ' . $pwd);
        } catch (PDOException $e) {
            set_flash('error', 'Gagal membuat akun: ' . $e->getMessage());
        }
        header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
        exit;
    }

    if ($action === 'account_reset_password') {
        $nip = trim((string) ($pembimbing['nip'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $acc = pembimbing_edit_find_user_account($pdo, $nip);
        if (!$acc) {
            set_flash('error', 'Akun login belum dibuat.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        if ((int) $acc['is_super_admin'] === 1) {
            set_flash('error', 'Tidak bisa reset password super admin dari halaman ini.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        // Default: password acak (BUKAN NIP — NIP sudah jadi USER).
        $pwd = $password !== '' ? $password : login_pembimbing_buat_password_acak();
        try {
            login_pembimbing_set_password_by_admin($pdo, (int) $acc['id'], $pwd);
            // Pastikan ACL pembimbing rapi setelah reset.
            login_pembimbing_ensure_acl($pdo, (int) $acc['id']);
            set_flash('success', 'Password direset. Password baru: ' . $pwd);
        } catch (PDOException $e) {
            set_flash('error', 'Gagal reset password: ' . $e->getMessage());
        }
        header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
        exit;
    }

    if ($action === 'delete_pembimbing') {
        $hapusAkun = isset($_POST['hapus_akun']) && $_POST['hapus_akun'] === '1';
        $nip = trim((string) ($pembimbing['nip'] ?? ''));
        $namaForLog = (string) ($pembimbing['nama_pembimbing'] ?? 'pembimbing');

        try {
            $pdo->beginTransaction();

            // Lepas relasi ke jadwal kegiatan (kolom tanpa FK).
            if (table_exists($pdo, 'jadwal_kegiatan')) {
                try {
                    $pdo->prepare('UPDATE jadwal_kegiatan SET pembimbing_id = NULL WHERE pembimbing_id = :id')
                        ->execute(['id' => $id]);
                } catch (PDOException $e) { /* abaikan kalau kolom belum ada */ }
            }

            // Bersihkan tabel relasi yang TIDAK punya FK CASCADE.
            foreach (['perizinan_pembimbing', 'keuangan_gaji_pembimbing'] as $tbl) {
                if (table_exists($pdo, $tbl)) {
                    try {
                        $pdo->prepare('DELETE FROM ' . $tbl . ' WHERE pembimbing_id = :id')->execute(['id' => $id]);
                    } catch (PDOException $e) { /* abaikan jika skema beda */ }
                }
            }

            // Hapus akun login jika diminta (dan akun memang berrole pembimbing).
            if ($hapusAkun && $nip !== '' && table_exists($pdo, 'users')) {
                $accChk = $pdo->prepare('SELECT id, role, is_super_admin FROM users WHERE TRIM(username) = :u LIMIT 1');
                $accChk->execute(['u' => $nip]);
                $accRow = $accChk->fetch();
                if (
                    $accRow
                    && strtolower((string) $accRow['role']) === 'pembimbing'
                    && (int) $accRow['is_super_admin'] !== 1
                    && (int) $accRow['id'] !== $currentUserId
                ) {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $accRow['id']]);
                }
            }

            // Terakhir: hapus baris pembimbing. presensi_pembimbing terhapus
            // otomatis via FK ON DELETE CASCADE.
            $pdo->prepare('DELETE FROM pembimbing WHERE id = :id')->execute(['id' => $id]);

            $pdo->commit();
            set_flash('success', 'Pembimbing "' . $namaForLog . '" dihapus' . ($hapusAkun ? ' beserta akun login.' : '. (Akun login tidak ikut dihapus.)'));
            header('Location: ' . app_href('/pembimbing/index.php'));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Gagal menghapus pembimbing: ' . $e->getMessage());
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
    }

    if ($action === 'account_delete') {
        $nip = trim((string) ($pembimbing['nip'] ?? ''));
        $acc = pembimbing_edit_find_user_account($pdo, $nip);
        if (!$acc) {
            set_flash('error', 'Akun login tidak ditemukan.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        if ((int) $acc['is_super_admin'] === 1) {
            set_flash('error', 'Tidak bisa menghapus akun super admin.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        if ((int) $acc['id'] === $currentUserId) {
            set_flash('error', 'Tidak bisa menghapus akun yang sedang Anda pakai login.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        if (strtolower((string) $acc['role']) !== 'pembimbing') {
            set_flash('error', 'Akun ini bukan pembimbing — hapus lewat menu pengaturan user.');
            header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
            exit;
        }
        try {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $acc['id']]);
            set_flash('success', 'Akun login pembimbing dihapus.');
        } catch (PDOException $e) {
            set_flash('error', 'Gagal hapus akun: ' . $e->getMessage());
        }
        header('Location: ' . app_href('/pembimbing/edit.php?id=' . $id));
        exit;
    }
}

// Refresh data after possible POST.
$statement->execute(['id' => $id]);
$pembimbing = $statement->fetch();
$userAccount = pembimbing_edit_find_user_account($pdo, (string) ($pembimbing['nip'] ?? ''));

$pembimbingKelas = pembimbing_kelas_list($pdo, $id);

$pageTitle = 'Edit Pengurus';
require_once __DIR__ . '/../includes/header.php';
?>

<?php
$payrollTarifMap = payroll_pembimbing_tarif_map($pdo);
$pembimbingGajiPokok = (float) ($pembimbing['gaji_pokok'] ?? 0);
?>
<div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-stretch align-items-sm-center mb-3 gap-2">
    <div>
        <p class="page-intro-kicker mb-1 small text-muted">Santri &amp; SDM · Edit Pengurus</p>
        <h1 class="h4 mb-0">Edit pengurus &amp; akun login</h1>
    </div>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>" class="btn btn-outline-secondary w-100 w-sm-auto flex-shrink-0">
        <i class="fa-solid fa-arrow-left me-1"></i> Kembali
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Data pembimbing</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="_action" value="update_pembimbing">
                    <div class="col-md-6">
                        <label class="form-label">QR</label>
                        <input type="text" name="qr" class="form-control" value="<?= htmlspecialchars($pembimbing['qr'] ?? '') ?>" placeholder="Boleh sama dengan NIP">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">NIP / ID <span class="text-danger">*</span></label>
                        <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($pembimbing['nip'] ?? '') ?>" required>
                        <div class="form-text">Username login otomatis mengikuti NIP.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama pembimbing <span class="text-danger">*</span></label>
                        <input type="text" name="nama_pembimbing" class="form-control" value="<?= htmlspecialchars($pembimbing['nama_pembimbing'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">No WA</label>
                        <input type="text" name="no_wa" class="form-control" value="<?= htmlspecialchars($pembimbing['no_wa'] ?? '') ?>" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pengingat WA scan kehadiran</label>
                        <select name="wa_scan_reminder" class="form-select">
                            <option value="1" <?= (int) ($pembimbing['wa_scan_reminder'] ?? 1) === 1 ? 'selected' : '' ?>>Aktif — kirim WA jika belum scan</option>
                            <option value="0" <?= (int) ($pembimbing['wa_scan_reminder'] ?? 1) === 0 ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                        <div class="form-text">Pesan otomatis ~10 menit sebelum kegiatan selesai (butuh No WA terisi).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Notifikasi WA izin santri disetujui</label>
                        <select name="wa_izin_notif" class="form-select">
                            <option value="1" <?= (int) ($pembimbing['wa_izin_notif'] ?? 1) === 1 ? 'selected' : '' ?>>Aktif — kirim saat izin binaan disetujui</option>
                            <option value="0" <?= (int) ($pembimbing['wa_izin_notif'] ?? 1) === 0 ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                        <div class="form-text">Pembimbing yang mengampu tingkatan/PKPPS santri terkait.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status pembimbing</label>
                        <select name="is_aktif" class="form-select">
                            <option value="1" <?= (int) $pembimbing['is_aktif'] === 1 ? 'selected' : '' ?>>Aktif</option>
                            <option value="0" <?= (int) $pembimbing['is_aktif'] === 0 ? 'selected' : '' ?>>Izin / Tidak Aktif</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <hr class="my-2">
                        <label class="form-label mb-1">
                            <i class="fa-solid fa-chalkboard-user me-1 text-primary"></i>
                            Kelas yang dikaji
                            <span class="text-muted small">(turunan dari slot jadwal)</span>
                        </label>
                        <?php if ($pembimbingKelas !== []): ?>
                            <div class="d-flex flex-wrap gap-1 mb-1">
                                <?php foreach ($pembimbingKelas as $kelas): ?>
                                    <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars($kelas) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text small">
                                Daftar tingkatan di atas diturunkan langsung dari slot
                                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">jadwal kegiatan</a>
                                yang sudah di-assign ke pembimbing ini. Untuk menambah/kurangi kelas, edit slot jadwalnya.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning py-2 px-3 small mb-1 d-flex align-items-start gap-2">
                                <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong>Pembimbing ini belum mendapatkan jadwal.</strong>
                                    Tambahkan ke
                                    <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="alert-link">slot jadwal</a>
                                    terlebih dahulu — daftar kelas akan otomatis muncul di sini sesuai tingkatan slotnya.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <hr class="my-2">
                        <p class="page-intro-kicker mb-1 small text-muted"><i class="fa-solid fa-coins me-1"></i> Payroll</p>
                        <p class="small text-muted mb-2">
                            Gaji pokok adalah tunjangan tetap per bulan. Tarif per jam dihitung otomatis dari
                            presensi mengikuti <strong>beban payroll per kegiatan Ta'lim</strong> (bukan per pembimbing).
                            Atur tarif Rp/jam di
                            <a href="<?= htmlspecialchars(app_href('/settings/tarif_payroll.php')) ?>">Tarif Payroll</a>
                            dan beban per mapel di
                            <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>">Beban Payroll Ta'lim</a>.
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gaji pokok (Rp / bulan)</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number" name="gaji_pokok" class="form-control text-end" min="0" step="1000" inputmode="numeric"
                                   value="<?= (int) round($pembimbingGajiPokok) ?>" placeholder="0">
                        </div>
                        <div class="form-text">Isi 0 jika pembimbing ini hanya dibayar per jam.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label d-block">Tarif per jam</label>
                        <div class="small text-muted border rounded-3 p-3 h-100">
                            Mengikuti kegiatan/jadwal Ta'lim yang discan saat presensi.
                            <ul class="mb-0 ps-3 mt-2">
                                <?php foreach (PAYROLL_PEMBIMBING_KRITERIA as $k): ?>
                                    <li><?= htmlspecialchars(payroll_pembimbing_kriteria_labels()[$k] ?? $k) ?> — Rp <?= number_format((int) round((float) ($payrollTarifMap[$k] ?? 0)), 0, ',', '.') ?>/jam</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Update data pembimbing
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-3 border-danger-subtle">
            <div class="card-body">
                <h2 class="h6 text-danger mb-2">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Zona berbahaya
                </h2>
                <p class="small text-muted mb-3">
                    Menghapus pembimbing akan menghilangkan datanya dari sistem secara permanen, termasuk riwayat presensi, izin, dan link jadwal yang memakai pembimbing ini akan dilepas. Tindakan ini <strong>tidak bisa dibatalkan</strong>.
                </p>
                <form method="post" id="delete-pembimbing-form" onsubmit="return pembimbingDeleteConfirm();" class="d-flex flex-wrap gap-2 align-items-center">
                    <input type="hidden" name="_action" value="delete_pembimbing">
                    <div class="form-check form-switch me-2">
                        <input class="form-check-input" type="checkbox" id="hapusAkun" name="hapus_akun" value="1" <?= $userAccount ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="hapusAkun">
                            Sekalian hapus akun login pembimbing
                        </label>
                    </div>
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="fa-solid fa-trash me-1"></i> Hapus pembimbing
                    </button>
                </form>
                <script>
                    function pembimbingDeleteConfirm() {
                        var nama = <?= json_encode((string) ($pembimbing['nama_pembimbing'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        var hapusAkun = document.getElementById('hapusAkun');
                        var extra = hapusAkun && hapusAkun.checked ? ' beserta akun loginnya' : '';
                        return confirm('Hapus pembimbing "' + nama + '"' + extra + '?\n\nSeluruh presensi, izin, dan jadwal yang memakainya akan terpengaruh. Tindakan ini tidak bisa dibatalkan.');
                    }
                </script>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h2 class="h5 mb-0">Akun login pembimbing</h2>
                    <?php if ($userAccount): ?>
                        <span class="badge text-bg-success"><i class="fa-solid fa-circle-check me-1"></i> Aktif</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><i class="fa-solid fa-circle-xmark me-1"></i> Belum dibuat</span>
                    <?php endif; ?>
                </div>

                <?php if ($userAccount): ?>
                    <ul class="list-unstyled small text-muted mb-3">
                        <li><strong>Username:</strong> <span class="font-monospace"><?= htmlspecialchars($userAccount['username']) ?></span></li>
                        <li><strong>Role:</strong> <?= htmlspecialchars(ucfirst((string) $userAccount['role'])) ?></li>
                        <li><strong>Nama akun:</strong> <?= htmlspecialchars($userAccount['nama']) ?></li>
                    </ul>

                    <?php $isAccountForeign = strtolower((string) $userAccount['role']) !== 'pembimbing'; ?>
                    <?php if ($isAccountForeign): ?>
                        <div class="alert alert-warning small mb-3">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Username ini terdaftar sebagai role <strong><?= htmlspecialchars(ucfirst((string) $userAccount['role'])) ?></strong>, bukan pembimbing.
                            Reset / hapus akun ini sebaiknya lewat menu pengaturan user.
                        </div>
                    <?php endif; ?>

                    <?php $passwordPlain = (string) ($userAccount['password_plain'] ?? ''); ?>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Password saat ini (admin view)</label>
                        <?php if ($passwordPlain !== ''): ?>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace" id="pb-pwd-plain" value="<?= htmlspecialchars($passwordPlain) ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="pb-pwd-toggle" title="Tampilkan/sembunyikan">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="pb-pwd-copy" title="Salin ke clipboard">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                            <div class="form-text small">Tersimpan saat admin men-set/reset password. Akan otomatis dihapus jika pembimbing mengubah password sendiri lewat halaman profilnya.</div>
                        <?php else: ?>
                            <div class="alert alert-light border small mb-0 py-2 px-3">
                                <i class="fa-solid fa-circle-info me-1 text-muted"></i>
                                Password tidak tersimpan dalam bentuk yang bisa dibaca
                                <?php if ($userAccount && (int) ($userAccount['is_super_admin'] ?? 0) === 1): ?>
                                    (akun super admin selalu disembunyikan).
                                <?php else: ?>
                                    — kemungkinan user telah mengubah password sendiri. Lakukan <strong>Reset</strong> di bawah untuk menetapkan password baru yang Anda ketahui.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" class="mb-2" id="formResetPass">
                        <input type="hidden" name="_action" value="account_reset_password">
                        <label class="form-label small">Reset / ubah password</label>
                        <div class="input-group input-group-sm mb-1">
                            <input type="text" name="password" id="inpResetPass" class="form-control" placeholder="Ketik password atau klik acak" autocomplete="off">
                            <button type="button" class="btn btn-outline-primary" id="btnResetRandom" title="Acak">
                                <i class="fa-solid fa-dice"></i> Acak
                            </button>
                            <button type="submit" class="btn btn-warning" <?= $isAccountForeign ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-key me-1"></i> Simpan
                            </button>
                        </div>
                        <div class="form-text small">Kosongkan = otomatis dibuatkan password acak (tidak memakai NIP). Tersimpan terbaca admin sampai pembimbing menggantinya sendiri.</div>
                    </form>
                    <script>
                        (function () {
                            var inp = document.getElementById('inpResetPass');
                            var rnd = document.getElementById('btnResetRandom');
                            if (rnd && inp) {
                                rnd.addEventListener('click', function () {
                                    var a = 'abcdefghjkmnpqrstuvwxyz23456789';
                                    var s = '';
                                    for (var i = 0; i < 6; i++) { s += a.charAt(Math.floor(Math.random() * a.length)); }
                                    inp.value = s;
                                    inp.focus();
                                });
                            }
                        })();
                    </script>

                    <form method="post" onsubmit="return confirm('Hapus akun login pembimbing ini? Data pembimbing tetap tersimpan.');">
                        <input type="hidden" name="_action" value="account_delete">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100" <?= $isAccountForeign || (int) $userAccount['id'] === $currentUserId ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-user-slash me-1"></i> Hapus akun login
                        </button>
                    </form>
                <?php else: ?>
                    <p class="small text-muted">Pembimbing ini belum punya akun login. Buat akun supaya bisa masuk portal pembimbing &amp; scan presensi.</p>
                    <form method="post">
                        <input type="hidden" name="_action" value="account_create">
                        <label class="form-label small">Password awal</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" name="password" id="inpCreatePass" class="form-control" placeholder="Ketik password atau klik acak">
                            <button type="button" class="btn btn-outline-primary" id="btnCreateRandom" title="Acak">
                                <i class="fa-solid fa-dice"></i> Acak
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-user-plus me-1"></i> Buat akun
                            </button>
                        </div>
                        <div class="form-text small">USER = NIP (<span class="font-monospace"><?= htmlspecialchars((string) ($pembimbing['nip'] ?? '-')) ?></span>). Kosongkan password = otomatis dibuatkan acak.</div>
                    </form>
                    <script>
                        (function () {
                            var inp = document.getElementById('inpCreatePass');
                            var rnd = document.getElementById('btnCreateRandom');
                            if (rnd && inp) {
                                rnd.addEventListener('click', function () {
                                    var a = 'abcdefghjkmnpqrstuvwxyz23456789';
                                    var s = '';
                                    for (var i = 0; i < 6; i++) { s += a.charAt(Math.floor(Math.random() * a.length)); }
                                    inp.value = s;
                                    inp.focus();
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('pb-pwd-plain');
    var toggle = document.getElementById('pb-pwd-toggle');
    var copy = document.getElementById('pb-pwd-copy');
    if (toggle && input) {
        toggle.addEventListener('click', function () {
            var isPwd = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPwd ? 'text' : 'password');
            var icon = toggle.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    }
    if (copy && input) {
        copy.addEventListener('click', function () {
            var val = input.value || '';
            if (!val) { return; }
            var done = function () {
                var icon = copy.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-copy');
                    icon.classList.add('fa-check');
                    setTimeout(function () {
                        icon.classList.remove('fa-check');
                        icon.classList.add('fa-copy');
                    }, 1200);
                }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(done).catch(function () {
                    input.setAttribute('type', 'text');
                    input.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                });
            } else {
                input.setAttribute('type', 'text');
                input.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
