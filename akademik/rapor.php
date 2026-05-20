<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_akademik_rapor_table($pdo);

$filterSantri = (int) ($_GET['santri_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $eSt = $pdo->prepare('SELECT * FROM akademik_rapor WHERE id = :id LIMIT 1');
    $eSt->execute(['id' => $editId]);
    $editRow = $eSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Rapor untuk diedit tidak ditemukan.');
        header('Location: /pwa_nailulmuna/akademik/rapor.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        if ($rid > 0) {
            $pdo->prepare('DELETE FROM akademik_rapor WHERE id = :id')->execute(['id' => $rid]);
            set_flash('success', 'Rapor dihapus.');
        }
        header('Location: /pwa_nailulmuna/akademik/rapor.php');
        exit;
    }
    if ($action === 'simpan_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        $sid = (int) ($_POST['santri_id'] ?? 0);
        $judul = trim((string) ($_POST['judul_periode'] ?? ''));
        $tgl = trim((string) ($_POST['tanggal_terbit'] ?? ''));
        $narasi = trim((string) ($_POST['narasi'] ?? ''));
        $pred = trim((string) ($_POST['predikat_akhlak'] ?? ''));
        $cat = trim((string) ($_POST['catatan_pondok'] ?? ''));
        $published = isset($_POST['is_published']) ? 1 : 0;
        if ($sid <= 0 || $judul === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            set_flash('error', 'Santri, judul periode, dan tanggal terbit wajib valid.');
            header('Location: /pwa_nailulmuna/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : ''));
            exit;
        }
        ensure_akademik_libur_table($pdo);
        $liburN = akademik_libur_info($pdo, $tgl, 'penilaian');
        if ($liburN !== null && akademik_blokir_penilaian_libur($pdo)) {
            set_flash('error', 'Tanggal terbit pada hari libur: ' . $liburN['nama'] . ' — tidak disimpan (aktifkan opsional di Kalender akademik atau pilih tanggal lain).');
            header('Location: /pwa_nailulmuna/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : ''));
            exit;
        }
        $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
        $chk->execute(['id' => $sid]);
        if (!$chk->fetch()) {
            set_flash('error', 'Santri tidak ditemukan.');
            header('Location: /pwa_nailulmuna/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : ''));
            exit;
        }
        $uid = (int) ($_SESSION['user']['id'] ?? 0) ?: null;
        if ($rid > 0) {
            $pdo->prepare('
                UPDATE akademik_rapor SET
                    santri_id = :sid,
                    judul_periode = :judul,
                    tanggal_terbit = :tgl,
                    narasi = :nar,
                    predikat_akhlak = :pred,
                    catatan_pondok = :cat,
                    is_published = :pub
                WHERE id = :id
            ')->execute([
                'sid' => $sid,
                'judul' => mb_substr($judul, 0, 160),
                'tgl' => $tgl,
                'nar' => $narasi !== '' ? $narasi : null,
                'pred' => $pred !== '' ? mb_substr($pred, 0, 100) : null,
                'cat' => $cat !== '' ? $cat : null,
                'pub' => $published,
                'id' => $rid,
            ]);
            set_flash('success', 'Rapor diperbarui.');
            header('Location: /pwa_nailulmuna/akademik/rapor.php?santri_id=' . $sid);
            exit;
        }
        $pdo->prepare('
            INSERT INTO akademik_rapor (santri_id, judul_periode, tanggal_terbit, narasi, predikat_akhlak, catatan_pondok, is_published, created_by)
            VALUES (:sid, :judul, :tgl, :nar, :pred, :cat, :pub, :uid)
        ')->execute([
            'sid' => $sid,
            'judul' => mb_substr($judul, 0, 160),
            'tgl' => $tgl,
            'nar' => $narasi !== '' ? $narasi : null,
            'pred' => $pred !== '' ? mb_substr($pred, 0, 100) : null,
            'cat' => $cat !== '' ? $cat : null,
            'pub' => $published,
            'uid' => $uid,
        ]);
        set_flash('success', 'Rapor ditambahkan.');
        header('Location: /pwa_nailulmuna/akademik/rapor.php?santri_id=' . $sid);
        exit;
    }
}

$sqlSantri = 'SELECT id, nis, nama_santri';
if (column_exists($pdo, 'santri', 'tingkatan')) {
    $sqlSantri .= ', tingkatan';
}
$sqlSantri .= ' FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$sqlSantri .= ' ORDER BY nama_santri ASC LIMIT 600';
$santriList = $pdo->query($sqlSantri)->fetchAll(PDO::FETCH_ASSOC);

$listSql = '
    SELECT r.*, s.nis, s.nama_santri, s.no_wa_wali
    FROM akademik_rapor r
    INNER JOIN santri s ON s.id = r.santri_id
';
$listParams = [];
if ($filterSantri > 0) {
    $listSql .= ' WHERE r.santri_id = :fid';
    $listParams['fid'] = $filterSantri;
}
$listSql .= ' ORDER BY r.tanggal_terbit DESC, r.id DESC LIMIT 100';
$lst = $pdo->prepare($listSql);
$lst->execute($listParams);
$daftar = $lst->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Rapor Akademik';
require_once __DIR__ . '/../includes/header.php';

$formSid = $editRow ? (int) $editRow['santri_id'] : ($filterSantri > 0 ? $filterSantri : 0);
$formJudul = (string) ($editRow['judul_periode'] ?? '');
$formTgl = (string) ($editRow['tanggal_terbit'] ?? date('Y-m-d'));
$formNarasi = (string) ($editRow['narasi'] ?? '');
$formPred = (string) ($editRow['predikat_akhlak'] ?? '');
$formCat = (string) ($editRow['catatan_pondok'] ?? '');
$formPub = $editRow ? (int) ($editRow['is_published'] ?? 0) : 0;
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Akademik</p>
    <h1 class="h3 mb-1">Rapor &amp; WA wali</h1>
    <p class="text-muted mb-0">Buat rapor per santri, centang <strong>Terbit</strong> agar tampil di portal wali. Tombol WA membuka chat ke nomor wali (tanpa gateway).</p>
    <p class="small text-muted mb-0 mt-2">
        <a href="/pwa_nailulmuna/settings/tingkatan.php" class="link-secondary">Pengaturan master tingkatan</a>
        — ubah nama tingkatan; data santri &amp; jadwal yang memakai nama lama ikut diselaraskan.
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-5" id="rapor-form">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Edit rapor' : 'Tambah rapor' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted"><a href="/pwa_nailulmuna/akademik/rapor.php">Batal edit — form baru</a></p>
                <?php endif; ?>
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="action" value="simpan_rapor">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="rapor_id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label">Santri</label>
                        <select name="santri_id" class="form-select" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($santriList as $s): ?>
                                <?php
                                $tg = isset($s['tingkatan']) ? trim((string) $s['tingkatan']) : '';
                                $optLabel = htmlspecialchars((string) $s['nama_santri']) . ' (' . htmlspecialchars((string) $s['nis']) . ')';
                                if ($tg !== '') {
                                    $optLabel .= ' — ' . htmlspecialchars($tg);
                                }
                                ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $formSid === (int) $s['id'] ? 'selected' : '' ?>><?= $optLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Judul / periode rapor</label>
                        <input type="text" name="judul_periode" class="form-control" required maxlength="160" placeholder="Mis. Semester 1 TA 2026/2027" value="<?= htmlspecialchars($formJudul) ?>">
                    </div>
                    <div>
                        <label class="form-label">Tanggal terbit</label>
                        <input type="date" name="tanggal_terbit" class="form-control" required value="<?= htmlspecialchars($formTgl) ?>">
                    </div>
                    <div>
                        <label class="form-label">Ringkasan nilai / narasi</label>
                        <textarea name="narasi" class="form-control" rows="5" placeholder="Isi capaian per mapel atau narasi rapor."><?= htmlspecialchars($formNarasi) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label">Predikat akhlak (opsional)</label>
                        <input type="text" name="predikat_akhlak" class="form-control" maxlength="100" value="<?= htmlspecialchars($formPred) ?>">
                    </div>
                    <div>
                        <label class="form-label">Catatan pondok (opsional)</label>
                        <textarea name="catatan_pondok" class="form-control" rows="2"><?= htmlspecialchars($formCat) ?></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_published" value="1" id="pubrap" <?= $formPub === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubrap">Terbitkan ke portal wali</label>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editRow ? 'Simpan perubahan' : 'Simpan rapor' ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Daftar rapor</h2>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <select name="santri_id" class="form-select form-select-sm" style="min-width:12rem;" onchange="this.form.submit()">
                            <option value="0">Semua santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $filterSantri === (int) $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $s['nama_santri']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Tgl</th>
                                <th>Santri</th>
                                <th>Judul</th>
                                <th>Terbit</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$daftar): ?>
                            <tr><td colspan="5" class="text-muted small">Belum ada rapor.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($daftar as $d): ?>
                            <?php
                            $waWali = trim((string) ($d['no_wa_wali'] ?? ''));
                            $namaS = (string) ($d['nama_santri'] ?? '');
                            $pesanWa = 'Assalamu\'alaikum, kami informasikan rapor akademik untuk *' . $namaS . '* (' . ($d['judul_periode'] ?? '') . '). Silakan cek di portal wali pondok. Terima kasih.';
                            $waUrl = $waWali !== '' ? wa_me_chat_url($waWali, $pesanWa) : null;
                            ?>
                            <tr>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) $d['tanggal_terbit']) ?></td>
                                <td class="small"><?= htmlspecialchars($namaS) ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($d['judul_periode'] ?? '')) ?></td>
                                <td><?= (int) ($d['is_published'] ?? 0) === 1 ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Draft</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($waUrl): ?>
                                        <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= htmlspecialchars($waUrl) ?>">WA wali</a>
                                    <?php else: ?>
                                        <span class="small text-muted">—</span>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="/pwa_nailulmuna/akademik/rapor.php?edit=<?= (int) $d['id'] ?>#rapor-form">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus rapor ini?');">
                                        <input type="hidden" name="action" value="hapus_rapor">
                                        <input type="hidden" name="rapor_id" value="<?= (int) $d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0 mt-2">Nomor WA diambil dari data santri (No WA Wali). Atur juga <strong>wa_pengurus</strong> di Settings untuk tombol di portal wali.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($editRow): ?>
<script>
(function () {
    var el = document.getElementById('rapor-form');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
