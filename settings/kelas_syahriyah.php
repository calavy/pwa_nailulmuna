<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_kelas_syahriyah.php';

require_roles(['admin', 'pengurus']);
ensure_kelas_syahriyah_table($pdo);
ensure_kelas_keuangan_table($pdo);

$kkRows = kelas_keuangan_list_active($pdo);
$kkOptions = [];
foreach ($kkRows as $kr) {
    $k = strtoupper(trim((string) ($kr['kode'] ?? '')));
    if ($k !== '') {
        $kkOptions[$k] = trim((string) ($kr['nama_tampilan'] ?? $k));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_tambahan') {
        $res = keuangan_kelas_syahriyah_save_tambahan_settings($pdo, $_POST);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-kelas'));
        exit;
    } elseif ($action === 'create') {
        $kodeRaw = strtoupper(trim((string) ($_POST['kode'] ?? '')));
        $kode = preg_replace('/[^A-Z0-9_-]/', '', $kodeRaw) ?? '';
        $nama = trim((string) ($_POST['nama_tampilan'] ?? ''));
        $kk = strtoupper(trim((string) ($_POST['kelas_keuangan_kode'] ?? '')));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        if ($kode === '' || $nama === '' || $kk === '' || !isset($kkOptions[$kk])) {
            set_flash('error', 'Kode, nama, dan kelas keuangan wajib diisi.');
        } else {
            try {
                $pdo->prepare('
                    INSERT INTO kelas_syahriyah (kode, nama_tampilan, kelas_keuangan_kode, urutan, is_aktif)
                    VALUES (:k, :n, :kk, :u, 1)
                ')->execute(['k' => $kode, 'n' => $nama, 'kk' => $kk, 'u' => $urutan]);
                set_flash('success', 'Kelas syahriyah pembayaran ditambahkan.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menambah: kode atau kelas keuangan mungkin sudah dipakai.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $kodeBaru = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', trim((string) ($_POST['kode'] ?? ''))) ?? '');
        $nama = trim((string) ($_POST['nama_tampilan'] ?? ''));
        $kk = strtoupper(trim((string) ($_POST['kelas_keuangan_kode'] ?? '')));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $aktif = (int) ($_POST['is_aktif'] ?? 0) === 1 ? 1 : 0;
        if ($id <= 0 || $kodeBaru === '' || $nama === '' || $kk === '' || !isset($kkOptions[$kk])) {
            set_flash('error', 'Data tidak valid.');
        } else {
            $pdo->prepare('
                UPDATE kelas_syahriyah
                SET kode = :k, nama_tampilan = :n, kelas_keuangan_kode = :kk, urutan = :u, is_aktif = :a
                WHERE id = :id
            ')->execute(['k' => $kodeBaru, 'n' => $nama, 'kk' => $kk, 'u' => $urutan, 'a' => $aktif, 'id' => $id]);
            set_flash('success', 'Kelas syahriyah diperbarui.');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM kelas_syahriyah WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Kelas syahriyah dihapus.');
        }
    }
    header('Location: ' . app_href('/settings/kelas_syahriyah.php'));
    exit;
}

$rows = kelas_syahriyah_all_rows($pdo);
$pageTitle = 'Kelas Syahriyah Pembayaran';
$settingsNavActive = '/settings/kelas_syahriyah.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Kelas / jenis syahriyah pembayaran</h1>
    <p class="text-muted small mb-0">
        Setiap jenis dihubungkan ke satu <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">kelas keuangan</a>
        (Wustho 1/2/3 → Wustho). Nominal tambahan diatur di
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-syahriyah')) ?>">Keuangan → Pengaturan syahriyah</a>.
    </p>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Tambah jenis</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <label class="form-label small mb-0">Kode jenis syahriyah</label>
                        <input type="text" name="kode" class="form-control form-control-sm" maxlength="40" required placeholder="SY-MUAD">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nama tampilan</label>
                        <input type="text" name="nama_tampilan" class="form-control form-control-sm" maxlength="120" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Kelas keuangan santri</label>
                        <select name="kelas_keuangan_kode" class="form-select form-select-sm" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($kkOptions as $k => $label): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?> — <?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Urutan</label>
                        <input type="number" name="urutan" class="form-control form-control-sm" value="0">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm w-100">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Daftar jenis syahriyah</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Kode / nama</th>
                        <th>Kelas keuangan</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="3" class="text-muted text-center py-3">Belum ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td colspan="3" class="p-2">
                                    <form method="post" class="d-flex flex-wrap align-items-end gap-2">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                        <div>
                                            <label class="form-label small mb-0">Kode</label>
                                            <input type="text" name="kode" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($row['kode'] ?? '')) ?>" maxlength="40" required>
                                        </div>
                                        <div class="flex-grow-1" style="min-width:8rem">
                                            <label class="form-label small mb-0">Nama</label>
                                            <input type="text" name="nama_tampilan" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($row['nama_tampilan'] ?? '')) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label small mb-0">Kelas keuangan</label>
                                            <select name="kelas_keuangan_kode" class="form-select form-select-sm" required>
                                                <?php foreach ($kkOptions as $k => $label): ?>
                                                    <option value="<?= htmlspecialchars($k) ?>" <?= strtoupper((string) ($row['kelas_keuangan_kode'] ?? '')) === $k ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($k) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label small mb-0">Urut</label>
                                            <input type="number" name="urutan" class="form-control form-control-sm" value="<?= (int) ($row['urutan'] ?? 0) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small mb-0">Aktif</label>
                                            <select name="is_aktif" class="form-select form-select-sm">
                                                <option value="1" <?= (int) ($row['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Ya</option>
                                                <option value="0" <?= (int) ($row['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Tidak</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                                    </form>
                                    <form method="post" class="d-inline mt-1" onsubmit="return confirm('Hapus jenis ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm" id="tambahan-syahriyah">
    <div class="card-header fw-semibold">Nominal tambahan syahriyah per kelas</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Atur nominal di halaman syahriyah terpusat (termasuk PKPPS) — otomatis ke tagihan &amp; input pembayaran.
        </p>
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-kelas')) ?>">
            Pengaturan syahriyah &amp; tambahan
        </a>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
