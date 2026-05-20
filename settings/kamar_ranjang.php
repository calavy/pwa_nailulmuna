<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/asrama.php';

require_roles(['admin', 'pengurus']);
ensure_asrama_kamar_ranjang_tables($pdo);
ensure_santri_identity_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_kamar') {
        $nama = mb_substr(trim((string) ($_POST['nama_kamar'] ?? '')), 0, 120);
        $urutan = (int) ($_POST['urutan_kamar'] ?? 0);
        if ($nama === '') {
            set_flash('error', 'Nama kamar wajib diisi.');
        } else {
            $ins = $pdo->prepare('INSERT INTO asrama_kamar (nama_kamar, urutan) VALUES (:n, :u)');
            try {
                $ins->execute(['n' => $nama, 'u' => $urutan]);
                set_flash('success', 'Kamar berhasil ditambahkan.');
            } catch (Throwable $e) {
                set_flash('error', 'Nama kamar sudah ada atau gagal menyimpan.');
            }
        }
    } elseif ($action === 'update_kamar') {
        $id = (int) ($_POST['id'] ?? 0);
        $namaBaru = mb_substr(trim((string) ($_POST['nama_kamar'] ?? '')), 0, 120);
        $urutan = (int) ($_POST['urutan_kamar'] ?? 0);
        if ($id <= 0 || $namaBaru === '') {
            set_flash('error', 'Data kamar tidak valid.');
        } else {
            $cur = $pdo->prepare('SELECT nama_kamar FROM asrama_kamar WHERE id = :id LIMIT 1');
            $cur->execute(['id' => $id]);
            $lama = $cur->fetchColumn();
            if ($lama === false) {
                set_flash('error', 'Kamar tidak ditemukan.');
            } else {
                $namaLama = (string) $lama;
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE asrama_kamar SET nama_kamar = :n, urutan = :u WHERE id = :id')->execute(['n' => $namaBaru, 'u' => $urutan, 'id' => $id]);
                    if ($namaBaru !== $namaLama && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'nama_kamar')) {
                        $pdo->prepare('UPDATE santri SET nama_kamar = :baru WHERE nama_kamar = :lama')->execute(['baru' => $namaBaru, 'lama' => $namaLama]);
                    }
                    $pdo->commit();
                    set_flash('success', 'Kamar diperbarui. Nama kamar di data santri yang memakai nama lama ikut diselaraskan.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    set_flash('error', 'Gagal menyimpan (mungkin nama bentrok dengan kamar lain).');
                }
            }
        }
    } elseif ($action === 'delete_kamar') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM asrama_kamar WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Kamar dan seluruh ranjang di dalamnya telah dihapus.');
        }
    } elseif ($action === 'create_ranjang') {
        $kamarId = (int) ($_POST['kamar_id'] ?? 0);
        $label = mb_substr(trim((string) ($_POST['label_ranjang'] ?? '')), 0, 80);
        $urutan = (int) ($_POST['urutan_ranjang'] ?? 0);
        $posisi = strtoupper(trim((string) ($_POST['posisi_ranjang'] ?? 'ATAS')));
        if (!in_array($posisi, ['ATAS', 'BAWAH'], true)) {
            $posisi = 'ATAS';
        }
        if ($kamarId <= 0 || $label === '') {
            set_flash('error', 'Kamar dan label ranjang wajib diisi.');
        } else {
            $ins = $pdo->prepare('INSERT INTO asrama_ranjang (kamar_id, label, posisi, urutan) VALUES (:k, :l, :p, :u)');
            try {
                $ins->execute(['k' => $kamarId, 'l' => $label, 'p' => $posisi, 'u' => $urutan]);
                set_flash('success', 'Ranjang berhasil ditambahkan.');
            } catch (Throwable $e) {
                set_flash('error', 'Kombinasi label + atas/bawah di kamar ini sudah ada atau gagal menyimpan.');
            }
        }
    } elseif ($action === 'update_ranjang') {
        $id = (int) ($_POST['id'] ?? 0);
        $labelBaru = mb_substr(trim((string) ($_POST['label_ranjang'] ?? '')), 0, 80);
        $urutan = (int) ($_POST['urutan_ranjang'] ?? 0);
        $posisiBaru = strtoupper(trim((string) ($_POST['posisi_ranjang'] ?? 'ATAS')));
        if (!in_array($posisiBaru, ['ATAS', 'BAWAH'], true)) {
            $posisiBaru = 'ATAS';
        }
        if ($id <= 0 || $labelBaru === '') {
            set_flash('error', 'Data ranjang tidak valid.');
        } else {
            $cur = $pdo->prepare('
                SELECT r.label AS label_lama, r.posisi AS posisi_lama, k.nama_kamar
                FROM asrama_ranjang r
                INNER JOIN asrama_kamar k ON k.id = r.kamar_id
                WHERE r.id = :id
                LIMIT 1
            ');
            $cur->execute(['id' => $id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                set_flash('error', 'Ranjang tidak ditemukan.');
            } else {
                $labelLama = (string) $row['label_lama'];
                $posisiLama = (string) ($row['posisi_lama'] ?? 'ATAS');
                $namaKamar = (string) $row['nama_kamar'];
                $oldDisp = asrama_format_no_ranjang_display($labelLama, $posisiLama);
                $newDisp = asrama_format_no_ranjang_display($labelBaru, $posisiBaru);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE asrama_ranjang SET label = :l, posisi = :p, urutan = :u WHERE id = :id')->execute(['l' => $labelBaru, 'p' => $posisiBaru, 'u' => $urutan, 'id' => $id]);
                    if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'no_ranjang') && column_exists($pdo, 'santri', 'nama_kamar')
                        && ($oldDisp !== $newDisp || $labelBaru !== $labelLama || $posisiBaru !== $posisiLama)) {
                        $pdo->prepare('UPDATE santri SET no_ranjang = :baru WHERE nama_kamar = :nk AND no_ranjang = :lama')->execute([
                            'baru' => $newDisp,
                            'nk' => $namaKamar,
                            'lama' => $oldDisp,
                        ]);
                    }
                    $pdo->commit();
                    set_flash('success', 'Ranjang diperbarui. Tampilan ranjang di data santri yang cocok ikut diselaraskan.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    set_flash('error', 'Gagal menyimpan (mungkin bentrok dengan ranjang lain).');
                }
            }
        }
    } elseif ($action === 'delete_ranjang') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM asrama_ranjang WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Ranjang dihapus.');
        }
    }

    header('Location: /pwa_nailulmuna/settings/kamar_ranjang.php');
    exit;
}

$kamars = $pdo->query('SELECT id, nama_kamar, urutan FROM asrama_kamar ORDER BY urutan ASC, nama_kamar ASC')->fetchAll(PDO::FETCH_ASSOC);
$ranjangByKamar = [];
if ($kamars) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $kamars);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, kamar_id, label, posisi, urutan FROM asrama_ranjang WHERE kamar_id IN ($in) ORDER BY urutan ASC, label ASC, posisi ASC");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rw) {
        $kid = (int) $rw['kamar_id'];
        if (!isset($ranjangByKamar[$kid])) {
            $ranjangByKamar[$kid] = [];
        }
        $ranjangByKamar[$kid][] = $rw;
    }
}
$totalKamar = count($kamars);
$totalRanjang = (int) $pdo->query('SELECT COUNT(*) FROM asrama_ranjang')->fetchColumn();
$nextLabelByKamar = [];
foreach ($kamars as $kRow) {
    $kidN = (int) ($kRow['id'] ?? 0);
    $maxNum = 0;
    foreach ($ranjangByKamar[$kidN] ?? [] as $rjN) {
        if (preg_match('/(\d+)/', (string) ($rjN['label'] ?? ''), $m)) {
            $maxNum = max($maxNum, (int) $m[1]);
        }
    }
    $nextLabelByKamar[$kidN] = str_pad((string) ($maxNum + 1), 2, '0', STR_PAD_LEFT);
}

$pageTitle = 'Master Kamar & Ranjang';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/pwa_nailulmuna/settings/kamar_ranjang.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/pwa_nailulmuna/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a> · Asrama</p>
    <h1 class="h4 mb-1">Kamar &amp; ranjang</h1>
    <p class="text-muted mb-0">Setiap ranjang punya posisi <strong>Atas</strong> atau <strong>Bawah</strong> (satu slot = satu santri aktif). Dipakai saat tambah/sunting santri dari master; isi manual tetap boleh.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total kamar</div>
            <div class="app-mini-stat-value"><?= $totalKamar ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total ranjang</div>
            <div class="app-mini-stat-value"><?= $totalRanjang ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5">Tambah kamar</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_kamar">
                    <div class="col-12">
                        <label class="form-label small">Nama kamar</label>
                        <input type="text" name="nama_kamar" class="form-control" maxlength="120" placeholder="Contoh: Gedung A — Lantai 2" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Urutan tampil</label>
                        <input type="number" name="urutan_kamar" class="form-control" value="0">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm">Simpan kamar</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Tambah ranjang</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create_ranjang">
                    <div class="col-12">
                        <label class="form-label small">Kamar</label>
                        <select name="kamar_id" id="ranjang-kamar-select" class="form-select" required>
                            <option value="">— Pilih kamar —</option>
                            <?php foreach ($kamars as $k): ?>
                                <option value="<?= (int) $k['id'] ?>" data-next-label="<?= htmlspecialchars((string) ($nextLabelByKamar[(int) $k['id']] ?? '01')) ?>"><?= htmlspecialchars((string) $k['nama_kamar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Label ranjang (nomor otomatis)</label>
                        <input type="text" name="label_ranjang" id="label-ranjang-input" class="form-control" maxlength="80" placeholder="01" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Posisi</label>
                        <select name="posisi_ranjang" class="form-select">
                            <option value="ATAS">Atas</option>
                            <option value="BAWAH">Bawah</option>
                        </select>
                        <div class="form-text">Nomor sama boleh dipakai untuk atas dan bawah sebagai dua ranjang terpisah.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Urutan tampil</label>
                        <input type="number" name="urutan_ranjang" class="form-control" value="0">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm">Simpan ranjang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Daftar kamar &amp; ranjang</h2>
                <?php if (!$kamars): ?>
                    <p class="text-muted mb-0">Belum ada kamar. Tambahkan minimal satu kamar, lalu ranjang di dalamnya.</p>
                <?php else: ?>
                    <?php foreach ($kamars as $k): ?>
                        <?php
                        $kid = (int) $k['id'];
                        $rjList = $ranjangByKamar[$kid] ?? [];
                        ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between mb-2">
                                <form method="post" class="d-flex flex-wrap gap-2 align-items-end flex-grow-1">
                                    <input type="hidden" name="action" value="update_kamar">
                                    <input type="hidden" name="id" value="<?= $kid ?>">
                                    <div>
                                        <label class="form-label small mb-0">Nama kamar</label>
                                        <input type="text" name="nama_kamar" class="form-control form-control-sm" style="min-width:14rem;" maxlength="120" required value="<?= htmlspecialchars((string) $k['nama_kamar']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label small mb-0">Urutan</label>
                                        <input type="number" name="urutan_kamar" class="form-control form-control-sm" style="width:5rem;" value="<?= (int) $k['urutan'] ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus kamar ini beserta semua ranjang di dalamnya?');">
                                    <input type="hidden" name="action" value="delete_kamar">
                                    <input type="hidden" name="id" value="<?= $kid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus kamar</button>
                                </form>
                            </div>
                            <?php if (!$rjList): ?>
                                <p class="small text-muted mb-0">Belum ada ranjang untuk kamar ini.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th>Label, posisi &amp; urutan</th>
                                                <th class="text-end" style="width:10rem;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rjList as $rj): ?>
                                                <?php $posRj = strtoupper((string) ($rj['posisi'] ?? 'ATAS')) === 'BAWAH' ? 'BAWAH' : 'ATAS'; ?>
                                                <tr>
                                                    <td>
                                                        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                                            <input type="hidden" name="action" value="update_ranjang">
                                                            <input type="hidden" name="id" value="<?= (int) $rj['id'] ?>">
                                                            <input type="text" name="label_ranjang" class="form-control form-control-sm" style="max-width:10rem;" maxlength="80" required value="<?= htmlspecialchars((string) $rj['label']) ?>">
                                                            <select name="posisi_ranjang" class="form-select form-select-sm" style="width:7rem;">
                                                                <option value="ATAS" <?= $posRj === 'ATAS' ? 'selected' : '' ?>>Atas</option>
                                                                <option value="BAWAH" <?= $posRj === 'BAWAH' ? 'selected' : '' ?>>Bawah</option>
                                                            </select>
                                                            <input type="number" name="urutan_ranjang" class="form-control form-control-sm" style="width:5rem;" value="<?= (int) $rj['urutan'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                                        </form>
                                                    </td>
                                                    <td class="text-end">
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus ranjang ini?');">
                                                            <input type="hidden" name="action" value="delete_ranjang">
                                                            <input type="hidden" name="id" value="<?= (int) $rj['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('ranjang-kamar-select');
    var inp = document.getElementById('label-ranjang-input');
    if (!sel || !inp) return;
    function applyNext() {
        var opt = sel.options[sel.selectedIndex];
        var next = opt && opt.getAttribute('data-next-label');
        if (next) inp.value = next;
    }
    sel.addEventListener('change', applyNext);
    if (sel.value) applyNext();
})();
</script>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
