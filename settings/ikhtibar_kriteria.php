<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/ikhtibar_kriteria.php';

require_roles(['admin', 'pengurus']);
ikhtibar_kriteria_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'simpan_kriteria') {
        $kodes = (array) ($_POST['kode'] ?? []);
        $rows = [];
        $urutan = 1;
        foreach ($kodes as $idx => $kode) {
            $kode = strtoupper(trim((string) $kode));
            if ($kode === '') {
                continue;
            }
            $rows[] = [
                'kode' => $kode,
                'label' => trim((string) ($_POST['label'][$idx] ?? '')),
                'bobot_persen' => (float) ($_POST['bobot'][$idx] ?? 0),
                'urutan' => $urutan++,
                'is_aktif' => isset($_POST['aktif'][$idx]) ? 1 : 0,
            ];
        }
        $res = ikhtibar_kriteria_simpan_batch($pdo, $rows);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    header('Location: ' . app_href('/settings/ikhtibar_kriteria.php'));
    exit;
}

$rows = ikhtibar_kriteria_list($pdo, false);
$pageTitle = 'Kriteria Penilaian Ikhtibar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/akademik/ikhtibar.php')) ?>">Tugas Ikhtibar</a></p>
    <h1 class="h4 mb-1">Pengaturan kriteria penilaian</h1>
    <p class="text-muted mb-0 small">Bobot kriteria dipakai untuk nilai otomatis soal esai. Total bobot harus 100%. Pembimbing melihat rincian nilai otomatis di menu penilaian.</p>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Format kunci esai (pedoman otomatis)</strong></div>
    <div class="card-body small text-muted">
        <p class="mb-1">Satu baris per kriteria, contoh:</p>
        <pre class="bg-light p-2 rounded mb-2">[KELENGKAPAN] poin utama, dalil, penjelasan
[KETEPATAN] istilah, definisi
[STRUKTUR] pembuka, isi, penutup
[BAHASA] ejaan, tata bahasa</pre>
        <p class="mb-0">Sistem menghitung kecocokan kata kunci di jawaban santri × bobot kriteria → nilai otomatis (0–100). Pembimbing dapat menyesuaikan manual jika perlu.</p>
    </div>
</div>

<form method="post" class="card shadow-sm">
    <input type="hidden" name="action" value="simpan_kriteria">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>Daftar kriteria</strong>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-tambah-kriteria"><i class="fa-solid fa-plus me-1"></i>Tambah baris</button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle" id="tbl-kriteria">
            <thead class="table-light">
                <tr>
                    <th style="width:140px">Kode</th>
                    <th>Label</th>
                    <th style="width:120px">Bobot %</th>
                    <th style="width:80px">Aktif</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada kriteria.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><input type="text" name="kode[]" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars((string) ($r['kode'] ?? '')) ?>" required pattern="[A-Z0-9_]{2,40}"></td>
                    <td><input type="text" name="label[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($r['label'] ?? '')) ?>" required></td>
                    <td><input type="number" name="bobot[]" class="form-control form-control-sm" min="0" max="100" step="0.5" value="<?= htmlspecialchars((string) ($r['bobot_persen'] ?? '0')) ?>" required></td>
                    <td class="text-center"><input type="checkbox" name="aktif[<?= (int) $i ?>]" value="1" <?= !empty($r['is_aktif']) ? 'checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan kriteria</button>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>" class="btn btn-outline-secondary ms-2">Kembali ke tugas</a>
    </div>
</form>

<script>
(function () {
    var btn = document.getElementById('btn-tambah-kriteria');
    var tbody = document.querySelector('#tbl-kriteria tbody');
    if (!btn || !tbody) return;
    btn.addEventListener('click', function () {
        var idx = tbody.querySelectorAll('tr').length;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="kode[]" class="form-control form-control-sm font-monospace" placeholder="KODE" required></td>'
            + '<td><input type="text" name="label[]" class="form-control form-control-sm" placeholder="Label tampilan" required></td>'
            + '<td><input type="number" name="bobot[]" class="form-control form-control-sm" min="0" max="100" step="0.5" value="0" required></td>'
            + '<td class="text-center"><input type="checkbox" name="aktif[' + idx + ']" value="1" checked></td>';
        tbody.appendChild(tr);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
