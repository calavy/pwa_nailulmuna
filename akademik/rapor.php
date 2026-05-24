<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

ensure_akademik_ikhtibar_tables($pdo);
santri_list_sort_mode($_GET['santri_sort'] ?? null);

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_akademik_rapor_columns($pdo);

$filterSantri = (int) ($_GET['santri_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $eSt = $pdo->prepare('SELECT * FROM akademik_rapor WHERE id = :id LIMIT 1');
    $eSt->execute(['id' => $editId]);
    $editRow = $eSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Rapor untuk diedit tidak ditemukan.');
        header('Location: ' . app_href('/akademik/rapor.php'));
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
        header('Location: ' . app_href('/akademik/rapor.php'));
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
        $periodeMode = strtolower(trim((string) ($_POST['periode_mode'] ?? 'hijriyah')));
        if (!in_array($periodeMode, ['masehi', 'hijriyah'], true)) {
            $periodeMode = 'hijriyah';
        }
        $periodeBulan = max(1, min(12, (int) ($_POST['periode_bulan'] ?? 0)));
        $periodeTahun = (int) ($_POST['periode_tahun'] ?? 0);
        if ($periodeBulan < 1 || $periodeTahun < 1) {
            $defP = rapor_periode_default_dari_tanggal($pdo, $tgl);
            $periodeMode = $defP['mode'];
            $periodeBulan = $defP['month'];
            $periodeTahun = $defP['year'];
        }
        if ($sid <= 0 || $judul === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            set_flash('error', 'Santri, judul periode, dan tanggal terbit wajib valid.');
            header('Location: ' . app_rewrite_internal_url('/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : '')));
            exit;
        }
        ensure_akademik_libur_table($pdo);
        $liburN = akademik_libur_info($pdo, $tgl, 'penilaian');
        if ($liburN !== null && akademik_blokir_penilaian_libur($pdo)) {
            set_flash('error', 'Tanggal terbit pada hari libur: ' . $liburN['nama'] . ' — tidak disimpan (aktifkan opsional di Kalender akademik atau pilih tanggal lain).');
            header('Location: ' . app_rewrite_internal_url('/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : '')));
            exit;
        }
        $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
        $chk->execute(['id' => $sid]);
        if (!$chk->fetch()) {
            set_flash('error', 'Santri tidak ditemukan.');
            header('Location: ' . app_rewrite_internal_url('/akademik/rapor.php' . ($rid > 0 ? '?edit=' . $rid : '')));
            exit;
        }
        $uid = (int) ($_SESSION['user']['id'] ?? 0) ?: null;
        if ($rid > 0) {
            $pdo->prepare('
                UPDATE akademik_rapor SET
                    santri_id = :sid,
                    judul_periode = :judul,
                    tanggal_terbit = :tgl,
                    periode_mode = :pm,
                    periode_bulan = :pb,
                    periode_tahun = :pt,
                    narasi = :nar,
                    predikat_akhlak = :pred,
                    catatan_pondok = :cat,
                    is_published = :pub
                WHERE id = :id
            ')->execute([
                'sid' => $sid,
                'judul' => mb_substr($judul, 0, 160),
                'tgl' => $tgl,
                'pm' => $periodeMode,
                'pb' => $periodeBulan,
                'pt' => $periodeTahun,
                'nar' => $narasi !== '' ? $narasi : null,
                'pred' => $pred !== '' ? mb_substr($pred, 0, 100) : null,
                'cat' => $cat !== '' ? $cat : null,
                'pub' => $published,
                'id' => $rid,
            ]);
            set_flash('success', 'Rapor diperbarui.');
            header('Location: ' . app_rewrite_internal_url('/akademik/rapor.php?santri_id=' . $sid));
            exit;
        }
        $pdo->prepare('
            INSERT INTO akademik_rapor (santri_id, judul_periode, tanggal_terbit, periode_mode, periode_bulan, periode_tahun, narasi, predikat_akhlak, catatan_pondok, is_published, created_by)
            VALUES (:sid, :judul, :tgl, :pm, :pb, :pt, :nar, :pred, :cat, :pub, :uid)
        ')->execute([
            'sid' => $sid,
            'judul' => mb_substr($judul, 0, 160),
            'tgl' => $tgl,
            'pm' => $periodeMode,
            'pb' => $periodeBulan,
            'pt' => $periodeTahun,
            'nar' => $narasi !== '' ? $narasi : null,
            'pred' => $pred !== '' ? mb_substr($pred, 0, 100) : null,
            'cat' => $cat !== '' ? $cat : null,
            'pub' => $published,
            'uid' => $uid,
        ]);
        set_flash('success', 'Rapor ditambahkan.');
        header('Location: ' . app_rewrite_internal_url('/akademik/rapor.php?santri_id=' . $sid));
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
$sqlSantri .= ' ORDER BY ' . santri_list_order_sql('santri') . ' LIMIT 600';
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
$defPeriode = $editRow
    ? [
        'mode' => strtolower((string) ($editRow['periode_mode'] ?? '')),
        'month' => (int) ($editRow['periode_bulan'] ?? 0),
        'year' => (int) ($editRow['periode_tahun'] ?? 0),
    ]
    : rapor_periode_default_dari_tanggal($pdo, $formTgl);
if (!in_array($defPeriode['mode'], ['masehi', 'hijriyah'], true)) {
    $defPeriode = rapor_periode_default_dari_tanggal($pdo, $formTgl);
}
if ($defPeriode['month'] < 1 || $defPeriode['year'] < 1) {
    $defPeriode = rapor_periode_default_dari_tanggal($pdo, $formTgl);
}
$hijriMonths = hijri_nama_bulan_list();
$masehiMonths = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$calDefault = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
$ikhtibarSantri = $filterSantri > 0 ? ikhtibar_riwayat_hasil_santri($pdo, $filterSantri) : [];
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Akademik</p>
    <h1 class="h3 mb-1">Rapor &amp; WA wali</h1>
    <p class="text-muted mb-0">Buat rapor per santri: presensi bulanan, setoran hafalan, dan tugas Ikhtibar per pembimbing/mapel. Centang <strong>Terbit</strong> agar tampil di portal wali.</p>
    <p class="small text-muted mb-0 mt-2">
        <a href="/settings/tingkatan.php" class="link-secondary">Pengaturan master tingkatan</a>
        — ubah nama tingkatan; data santri &amp; jadwal yang memakai nama lama ikut diselaraskan.
    </p>
</div>

<?php if ($filterSantri > 0): ?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold"><i class="fa-solid fa-list-check text-primary me-1"></i> Nilai Tugas Ikhtibar (pembimbing)</span>
        <a href="<?= htmlspecialchars(app_href('/akademik/ikhtibar_rekap.php?santri_id=' . $filterSantri)) ?>" class="btn btn-sm btn-outline-primary">Rekap lengkap</a>
    </div>
    <div class="card-body">
        <?php if ($ikhtibarSantri === []): ?>
            <p class="text-muted small mb-0">Belum ada riwayat tugas ikhtibar untuk santri ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead><tr><th>Tugas</th><th>Tanggal</th><th>PG</th><th>Esai</th><th>Total</th><th>Predikat</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($ikhtibarSantri, 0, 8) as $ir): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($ir['judul'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($ir['tanggal'] ?? '')) ?></td>
                            <td><?= $ir['skor_pg'] !== null ? (string) $ir['skor_pg'] . '%' : '—' ?></td>
                            <td><?= (int) ($ir['esai_pending'] ?? 0) > 0 ? 'Pending' : ($ir['skor_esai'] !== null ? (string) $ir['skor_esai'] : '—') ?></td>
                            <td class="fw-semibold"><?= $ir['nilai_total'] !== null ? (string) $ir['nilai_total'] : '—' ?></td>
                            <td><span class="badge text-bg-<?= htmlspecialchars((string) ($ir['predikat_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($ir['predikat'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($ikhtibarSantri) > 8): ?>
                <p class="small text-muted mb-0 mt-2">Menampilkan 8 terbaru dari <?= count($ikhtibarSantri) ?> tugas.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5" id="rapor-form">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Edit rapor' : 'Tambah rapor' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small text-muted mb-2">
                        <a href="/akademik/rapor.php">Batal edit — form baru</a>
                        · <a href="/akademik/rapor_lihat.php?id=<?= (int) $editRow['id'] ?>">Pratinjau</a>
                        · <a href="/akademik/rapor_cetak.php?id=<?= (int) $editRow['id'] ?>" target="_blank" rel="noopener">Cetak kop &amp; TTD</a>
                    </p>
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
                    <div class="border rounded p-2 bg-light">
                        <label class="form-label fw-semibold small mb-2">Periode presensi &amp; tugas</label>
                        <div class="row g-2">
                            <div class="col-12">
                                <select name="periode_mode" class="form-select form-select-sm" id="rapor-periode-mode">
                                    <option value="hijriyah" <?= $defPeriode['mode'] === 'hijriyah' ? 'selected' : '' ?>>Bulan Hijriyah</option>
                                    <option value="masehi" <?= $defPeriode['mode'] === 'masehi' ? 'selected' : '' ?>>Bulan Masehi</option>
                                </select>
                                <div class="form-text">Default kalender pondok: <?= $calDefault === 'MASEHI' ? 'Masehi' : 'Hijriyah' ?>.</div>
                            </div>
                            <div class="col-7">
                                <select name="periode_bulan" class="form-select form-select-sm" id="rapor-periode-bulan">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" data-masehi="<?= htmlspecialchars($masehiMonths[$m] ?? (string) $m) ?>" data-hijri="<?= htmlspecialchars($hijriMonths[$m] ?? (string) $m) ?>" <?= $defPeriode['month'] === $m ? 'selected' : '' ?>><?= htmlspecialchars($hijriMonths[$m] ?? (string) $m) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <input type="number" name="periode_tahun" class="form-control form-control-sm" min="1300" max="2100" required value="<?= (int) $defPeriode['year'] ?>">
                            </div>
                        </div>
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
                                <th>Periode data</th>
                                <th>Terbit</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$daftar): ?>
                            <tr><td colspan="6" class="text-muted small">Belum ada rapor.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($daftar as $d): ?>
                            <?php
                            $waWali = trim((string) ($d['no_wa_wali'] ?? ''));
                            $namaS = (string) ($d['nama_santri'] ?? '');
                            $periodeRow = rapor_periode_dari_row($pdo, $d);
                            $pesanWa = 'Assalamu\'alaikum, kami informasikan rapor akademik untuk *' . $namaS . '* (' . ($d['judul_periode'] ?? '') . '). Silakan cek di portal wali pondok. Terima kasih.';
                            $waUrl = $waWali !== '' ? wa_me_chat_url($waWali, $pesanWa) : null;
                            ?>
                            <tr>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) $d['tanggal_terbit']) ?></td>
                                <td class="small"><?= htmlspecialchars($namaS) ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($d['judul_periode'] ?? '')) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars((string) $periodeRow['label']) ?></td>
                                <td><?= (int) ($d['is_published'] ?? 0) === 1 ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Draft</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="/akademik/rapor_lihat.php?id=<?= (int) $d['id'] ?>">Lihat</a>
                                    <a class="btn btn-sm btn-outline-dark" href="/akademik/rapor_cetak.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener" title="Cetak dengan kop surat">Cetak</a>
                                    <?php if ($waUrl): ?>
                                        <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= htmlspecialchars($waUrl) ?>">WA wali</a>
                                    <?php else: ?>
                                        <span class="small text-muted">—</span>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="/akademik/rapor.php?edit=<?= (int) $d['id'] ?>#rapor-form">Edit</a>
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

<script>
(function () {
    var modeEl = document.getElementById('rapor-periode-mode');
    var bulanEl = document.getElementById('rapor-periode-bulan');
    function syncBulanLabels() {
        if (!modeEl || !bulanEl) return;
        var hijri = modeEl.value === 'hijriyah';
        for (var i = 0; i < bulanEl.options.length; i++) {
            var opt = bulanEl.options[i];
            opt.textContent = hijri ? (opt.getAttribute('data-hijri') || opt.value) : (opt.getAttribute('data-masehi') || opt.value);
        }
    }
    if (modeEl) {
        modeEl.addEventListener('change', syncBulanLabels);
        syncBulanLabels();
    }
    var el = document.getElementById('rapor-form');
    if (el && window.location.hash === '#rapor-form') {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
