<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

ensure_kelas_keuangan_table($pdo);

$validTiers = ['muadalah' => 'Muadalah', 'wustho' => 'Wustho', 'ulya' => 'Ulya'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $kodeRaw = strtoupper(trim((string) ($_POST['kode'] ?? '')));
        $kode = preg_replace('/[^A-Z0-9_-]/', '', $kodeRaw) ?? '';
        $nama = trim((string) ($_POST['nama_tampilan'] ?? ''));
        $tier = strtolower(trim((string) ($_POST['tarif_keuangan_tier'] ?? 'wustho')));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        if ($kode === '' || strlen($kode) > 40) {
            set_flash('error', 'Kode wajib diisi (huruf, angka, garis bawah/tengah, maks. 40 karakter).');
        } elseif ($nama === '') {
            set_flash('error', 'Nama tampilan wajib diisi.');
        } elseif (!isset($validTiers[$tier])) {
            set_flash('error', 'Tarif keuangan tidak valid.');
        } else {
            try {
                $ins = $pdo->prepare('INSERT INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
                $ins->execute(['k' => $kode, 'n' => $nama, 't' => $tier, 'u' => $urutan]);
                set_flash('success', 'Kelas keuangan berhasil ditambahkan.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menambah: kode mungkin sudah dipakai.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $kodeBaruRaw = strtoupper(trim((string) ($_POST['kode'] ?? '')));
        $kodeBaru = preg_replace('/[^A-Z0-9_-]/', '', $kodeBaruRaw) ?? '';
        $nama = trim((string) ($_POST['nama_tampilan'] ?? ''));
        $tier = strtolower(trim((string) ($_POST['tarif_keuangan_tier'] ?? 'wustho')));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $isAktif = (int) ($_POST['is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($id <= 0 || $nama === '' || $kodeBaru === '' || strlen($kodeBaru) > 40 || !isset($validTiers[$tier])) {
            set_flash('error', 'Data tidak valid: kode, nama, dan tarif wajib benar.');
        } else {
            $cur = $pdo->prepare('SELECT id, kode FROM kelas_keuangan WHERE id = :id LIMIT 1');
            $cur->execute(['id' => $id]);
            $curRow = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) {
                set_flash('error', 'Entri tidak ditemukan.');
            } else {
                $kodeLama = strtoupper(trim((string) ($curRow['kode'] ?? '')));
                if ($kodeBaru !== $kodeLama) {
                    $dup = $pdo->prepare('SELECT id FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k AND id <> :id LIMIT 1');
                    $dup->execute(['k' => $kodeBaru, 'id' => $id]);
                    if ($dup->fetch()) {
                        set_flash('error', 'Kode baru bentrok dengan entri lain.');
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $up = $pdo->prepare('UPDATE kelas_keuangan SET kode = :kode, nama_tampilan = :n, tarif_keuangan_tier = :t, urutan = :u, is_aktif = :a WHERE id = :id');
                            $up->execute(['kode' => $kodeBaru, 'n' => $nama, 't' => $tier, 'u' => $urutan, 'a' => $isAktif, 'id' => $id]);
                            if (column_exists($pdo, 'santri', 'kategori_kelas')) {
                                $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')->execute([
                                    'baru' => $kodeBaru,
                                    'lama' => $kodeLama,
                                ]);
                            }
                            $pdo->commit();
                            set_flash('success', 'Kelas keuangan diperbarui; kode di data santri yang memakai kode lama diselaraskan.');
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            set_flash('error', 'Gagal menyimpan perubahan kode.');
                        }
                    }
                } else {
                    $up = $pdo->prepare('UPDATE kelas_keuangan SET nama_tampilan = :n, tarif_keuangan_tier = :t, urutan = :u, is_aktif = :a WHERE id = :id');
                    $up->execute(['n' => $nama, 't' => $tier, 'u' => $urutan, 'a' => $isAktif, 'id' => $id]);
                    set_flash('success', 'Kelas keuangan berhasil diperbarui.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE id = :id');
            $st->execute(['id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['kode'])) {
                $kode = (string) $row['kode'];
                if (column_exists($pdo, 'santri', 'kategori_kelas')) {
                    $c = $pdo->prepare('SELECT COUNT(*) FROM santri WHERE UPPER(TRIM(kategori_kelas)) = :k');
                    $c->execute(['k' => strtoupper(trim($kode))]);
                    $used = (int) $c->fetchColumn();
                    if ($used > 0) {
                        set_flash('error', 'Tidak dapat dihapus: masih dipakai oleh ' . $used . ' santri. Nonaktifkan saja.');
                        header('Location: ' . app_href('/settings/kelas_keuangan.php'));
                        exit;
                    }
                }
                $del = $pdo->prepare('DELETE FROM kelas_keuangan WHERE id = :id');
                $del->execute(['id' => $id]);
                set_flash('success', 'Kelas keuangan dihapus.');
            }
        }
    }
    header('Location: ' . app_href('/settings/kelas_keuangan.php'));
    exit;
}

$rows = kelas_keuangan_all_rows($pdo);
$total = count($rows);

$pageTitle = 'Kelas Keuangan';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/kelas_keuangan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Kelas / kategori keuangan</h1>
    <p class="text-muted mb-0">Atur kode (disimpan di data santri), nama tampilan, tarif Muadalah/Wustho/Ulya, dan urutan. Mengubah <strong>kode</strong> menyamakan nilai <code>kategori_kelas</code> santri yang memakai kode lama. Input santri/import bisa memakai <strong>nama tampilan persis</strong> atau kode — sistem menormalisasi ke kode master.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total entri</div>
            <div class="app-mini-stat-value"><?= $total ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Tambah kelas keuangan</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <label class="form-label small mb-0">Kode (disimpan di data santri)</label>
                        <input type="text" class="form-control" name="kode" maxlength="40" placeholder="Contoh: PRA-ULYA" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nama tampilan</label>
                        <input type="text" class="form-control" name="nama_tampilan" maxlength="120" placeholder="Contoh: Pra-Ulya" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Tarif mengikuti kolom</label>
                        <select class="form-select" name="tarif_keuangan_tier">
                            <?php foreach ($validTiers as $tk => $tl): ?>
                                <option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($tl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Urutan di dropdown</label>
                        <input type="number" class="form-control" name="urutan" value="0">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar kelas keuangan</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Kode, nama, tarif &amp; status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <div class="d-flex flex-wrap align-items-end gap-2">
                                        <form method="post" class="d-flex flex-wrap align-items-end gap-2 flex-grow-1">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <div style="min-width:6rem">
                                                <label class="form-label small mb-0 text-muted">Kode</label>
                                                <input type="text" class="form-control form-control-sm" name="kode" maxlength="40" required value="<?= htmlspecialchars((string) $row['kode']) ?>" title="Huruf, angka, garis bawah/tengah">
                                            </div>
                                            <div style="min-width:12rem; flex:1">
                                                <label class="form-label small mb-0 text-muted">Nama</label>
                                                <input type="text" class="form-control form-control-sm" name="nama_tampilan" value="<?= htmlspecialchars((string) $row['nama_tampilan']) ?>" required>
                                            </div>
                                            <div style="min-width:7rem">
                                                <label class="form-label small mb-0 text-muted">Tarif</label>
                                                <select class="form-select form-select-sm" name="tarif_keuangan_tier">
                                                    <?php foreach ($validTiers as $tk => $tl): ?>
                                                        <option value="<?= htmlspecialchars($tk) ?>" <?= ((string) $row['tarif_keuangan_tier'] === $tk) ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div style="width:5rem">
                                                <label class="form-label small mb-0 text-muted">Urut</label>
                                                <input type="number" class="form-control form-control-sm" name="urutan" value="<?= (int) $row['urutan'] ?>">
                                            </div>
                                            <div style="min-width:6rem">
                                                <label class="form-label small mb-0 text-muted">Status</label>
                                                <select class="form-select form-select-sm" name="is_aktif">
                                                    <option value="1" <?= (int) $row['is_aktif'] === 1 ? 'selected' : '' ?>>Aktif</option>
                                                    <option value="0" <?= (int) $row['is_aktif'] !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                        </form>
                                        <form method="post" class="mb-1" onsubmit="return confirm('Hapus entri ini? Hanya jika tidak dipakai santri.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">Santri hanya dapat memilih entri berstatus Aktif. Data lama dengan kode nonaktif tetap dikenali untuk perhitungan tarif.</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
