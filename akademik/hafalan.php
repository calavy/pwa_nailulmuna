<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_akademik_hafalan_setoran_table($pdo);
ensure_akademik_bait_kitab_table($pdo);

$filterSantri = (int) ($_GET['santri_id'] ?? 0);

$baitList = $pdo->query('SELECT id, nama_kitab, jumlah_baris, target_baris_per_hari FROM akademik_bait_kitab WHERE is_aktif = 1 ORDER BY urutan ASC, nama_kitab ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan_setoran') {
    $sid = (int) ($_POST['santri_id'] ?? 0);
    $tanggal = trim((string) ($_POST['tanggal_setoran'] ?? ''));
    $kategori = strtoupper(trim((string) ($_POST['kategori_setoran'] ?? 'ALQURAN')));
    if (!in_array($kategori, ['BAIT', 'ALQURAN'], true)) {
        $kategori = 'ALQURAN';
    }
    $baitId = (int) ($_POST['bait_kitab_id'] ?? 0);
    $barisSetor = max(0, (int) ($_POST['baris_setor'] ?? 0));
    $target = trim((string) ($_POST['target_hafalan'] ?? ''));
    $juz = trim((string) ($_POST['juz_halaman'] ?? ''));
    $nilaiRaw = trim((string) ($_POST['nilai_skor'] ?? ''));
    $predikat = trim((string) ($_POST['predikat'] ?? ''));
    $catatan = trim((string) ($_POST['catatan'] ?? ''));
    $lewatiLibur = isset($_POST['lewati_blokir_libur']);

    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        set_flash('error', 'Santri dan tanggal wajib valid.');
        header('Location: ' . app_href('/akademik/hafalan.php'));
        exit;
    }
    if ($kategori === 'BAIT') {
        if ($baitId <= 0) {
            set_flash('error', 'Pilih kitab bait.');
            header('Location: ' . app_href('/akademik/hafalan.php'));
            exit;
        }
        $bk = $pdo->prepare('SELECT nama_kitab FROM akademik_bait_kitab WHERE id = :id AND is_aktif = 1 LIMIT 1');
        $bk->execute(['id' => $baitId]);
        $brow = $bk->fetch(PDO::FETCH_ASSOC);
        if (!$brow) {
            set_flash('error', 'Kitab bait tidak valid.');
            header('Location: ' . app_href('/akademik/hafalan.php'));
            exit;
        }
        if ($barisSetor <= 0) {
            set_flash('error', 'Isi jumlah baris setoran (lebih dari 0).');
            header('Location: ' . app_href('/akademik/hafalan.php'));
            exit;
        }
        $target = 'Bait: ' . (string) $brow['nama_kitab'] . ' (' . $barisSetor . ' baris)';
    } else {
        if ($target === '') {
            set_flash('error', 'Target / materi Al-Qur\'an wajib diisi.');
            header('Location: ' . app_href('/akademik/hafalan.php'));
            exit;
        }
        $baitId = 0;
        $barisSetor = 0;
    }

    $liburInfo = akademik_libur_info($pdo, $tanggal, 'setoran');
    if ($liburInfo !== null && akademik_blokir_setoran_libur($pdo) && !$lewatiLibur) {
        set_flash('error', 'Tanggal ini libur: ' . $liburInfo['nama'] . '. Centang "simpan tetap di hari libur" bila perlu.');
        header('Location: ' . app_href('/akademik/hafalan.php'));
        exit;
    }

    $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $sid]);
    if (!$chk->fetch()) {
        set_flash('error', 'Santri tidak ditemukan.');
        header('Location: ' . app_href('/akademik/hafalan.php'));
        exit;
    }
    $nilai = null;
    if ($nilaiRaw !== '' && ctype_digit($nilaiRaw)) {
        $nilai = max(0, min(100, (int) $nilaiRaw));
    }
    $predikat = $predikat !== '' ? mb_substr($predikat, 0, 40) : null;
    $juz = $juz !== '' ? mb_substr($juz, 0, 120) : null;
    $hijri = akademik_hijri_tanggal_penuh($pdo, $tanggal);

    $baitBind = $kategori === 'BAIT' && $baitId > 0 ? $baitId : null;
    $barisBind = $kategori === 'BAIT' && $barisSetor > 0 ? $barisSetor : null;

    $ins = $pdo->prepare('
        INSERT INTO akademik_hafalan_setoran (santri_id, tanggal_setoran, kategori_setoran, bait_kitab_id, baris_setor, kalender_hijriyah, target_hafalan, juz_halaman, nilai_skor, predikat, catatan, created_by)
        VALUES (:sid, :tgl, :kat, :bid, :baris, :hij, :tgt, :juz, :nil, :pre, :cat, :uid)
    ');
    $ins->execute([
        'sid' => $sid,
        'tgl' => $tanggal,
        'kat' => $kategori,
        'bid' => $baitBind,
        'baris' => $barisBind,
        'hij' => $hijri,
        'tgt' => mb_substr($target, 0, 255),
        'juz' => $juz,
        'nil' => $nilai,
        'pre' => $predikat,
        'cat' => $catatan !== '' ? $catatan : null,
        'uid' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
    ]);
    set_flash('success', 'Setoran hafalan tersimpan.');
    header('Location: ' . app_rewrite_internal_url('/akademik/hafalan.php?santri_id=' . $sid));
    exit;
}

$sqlSantri = 'SELECT id, nis, nama_santri FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$sqlSantri .= ' ORDER BY nama_santri ASC LIMIT 600';
$santriList = $pdo->query($sqlSantri)->fetchAll(PDO::FETCH_ASSOC);

$hasKategori = column_exists($pdo, 'akademik_hafalan_setoran', 'kategori_setoran');
$cols = 'h.id, h.tanggal_setoran, h.target_hafalan, h.juz_halaman, h.nilai_skor, h.predikat, h.catatan, s.nis, s.nama_santri';
if ($hasKategori) {
    $cols .= ', h.kategori_setoran, h.baris_setor, h.kalender_hijriyah, k.nama_kitab AS bait_nama';
} else {
    $cols .= ", 'ALQURAN' AS kategori_setoran, NULL AS baris_setor, NULL AS kalender_hijriyah, NULL AS bait_nama";
}

$listQuery = "
    SELECT {$cols}
    FROM akademik_hafalan_setoran h
    INNER JOIN santri s ON s.id = h.santri_id
";
if ($hasKategori && column_exists($pdo, 'akademik_hafalan_setoran', 'bait_kitab_id') && table_exists($pdo, 'akademik_bait_kitab')) {
    $listQuery .= ' LEFT JOIN akademik_bait_kitab k ON k.id = h.bait_kitab_id';
}
$params = [];
if ($filterSantri > 0) {
    $listQuery .= ' WHERE h.santri_id = :fid';
    $params['fid'] = $filterSantri;
}
$listQuery .= ' ORDER BY h.tanggal_setoran DESC, h.id DESC LIMIT 100';
$lst = $pdo->prepare($listQuery);
$lst->execute($params);
$daftar = $lst->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Setoran Hafalan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Akademik</p>
    <h1 class="h3 mb-1">Input setoran hafalan</h1>
    <p class="text-muted mb-0">Kategori <strong>Bait</strong> (kitab + baris) atau <strong>Al-Qur&apos;an</strong>. Tanggal tercatat juga dalam <strong>hijriyah</strong>. Libur akademik: atur di <a href="/akademik/kalender.php">Kalender &amp; libur</a> · master bait di <a href="/akademik/bait_kitab.php">Pengaturan bait</a>. Data tampil di portal wali.</p>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Tambah setoran</h2>
                <form method="post" class="d-grid gap-2" id="formSetoran">
                    <input type="hidden" name="action" value="simpan_setoran">
                    <div>
                        <label class="form-label">Santri</label>
                        <select name="santri_id" class="form-select" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $filterSantri === (int) $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $s['nama_santri']) ?> (<?= htmlspecialchars((string) $s['nis']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Tanggal setoran (masehi)</label>
                        <input type="date" name="tanggal_setoran" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Kategori</label>
                        <select name="kategori_setoran" class="form-select" id="selKategori" required>
                            <option value="ALQURAN">Al-Qur&apos;an</option>
                            <option value="BAIT">Bait (kitab)</option>
                        </select>
                    </div>
                    <div id="blockBait" class="border rounded p-2 bg-light d-none">
                        <div class="mb-2">
                            <label class="form-label small mb-0">Kitab bait</label>
                            <select name="bait_kitab_id" class="form-select form-select-sm" id="selBait">
                                <option value="0">— Pilih kitab —</option>
                                <?php foreach ($baitList as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>" data-target="<?= (int) $b['target_baris_per_hari'] ?>" data-baris="<?= (int) $b['jumlah_baris'] ?>">
                                        <?= htmlspecialchars((string) $b['nama_kitab']) ?> (<?= (int) $b['jumlah_baris'] ?> brs · tgt <?= (int) $b['target_baris_per_hari'] ?>/hari)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($baitList === []): ?>
                                <p class="small text-warning mb-0 mt-1">Belum ada kitab aktif. <a href="/akademik/bait_kitab.php">Tambah di pengaturan bait</a>.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label small mb-0">Baris setor hari ini</label>
                            <input type="number" name="baris_setor" class="form-control form-control-sm" min="1" step="1" id="inpBarisSetor" placeholder="Jumlah baris">
                        </div>
                        <p class="small text-muted mb-0 mt-2" id="hintBait"></p>
                    </div>
                    <div id="blockQuran">
                        <label class="form-label">Target / materi</label>
                        <input type="text" name="target_hafalan" class="form-control" placeholder="Mis. Juz 30, surat Yasin" maxlength="255" id="inpTargetQuran">
                        <div class="mt-2">
                            <label class="form-label">Juz / halaman (opsional)</label>
                            <input type="text" name="juz_halaman" class="form-control" maxlength="120">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Nilai 0–100 (opsional)</label>
                        <input type="number" name="nilai_skor" class="form-control" min="0" max="100" step="1">
                    </div>
                    <div>
                        <label class="form-label">Predikat (opsional)</label>
                        <input type="text" name="predikat" class="form-control" maxlength="40" placeholder="Baik, Cukup, …">
                    </div>
                    <div>
                        <label class="form-label">Catatan</label>
                        <textarea name="catatan" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="lewati_blokir_libur" value="1" id="lewLibur">
                        <label class="form-check-label small" for="lewLibur">Simpan meski tanggal libur (override)</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Riwayat</h2>
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
                        <thead><tr><th>Tgl</th><th>Hijri</th><th>Santri</th><th>Kat</th><th>Target / bait</th><th>Baris</th><th>Nilai</th></tr></thead>
                        <tbody>
                        <?php if (!$daftar): ?>
                            <tr><td colspan="7" class="text-muted small">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($daftar as $d): ?>
                            <tr>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) $d['tanggal_setoran']) ?></td>
                                <td class="small font-monospace"><?= htmlspecialchars((string) ($d['kalender_hijriyah'] ?? '—')) ?></td>
                                <td class="small"><?= htmlspecialchars((string) $d['nama_santri']) ?></td>
                                <td class="small"><span class="badge text-bg-light border"><?= htmlspecialchars((string) ($d['kategori_setoran'] ?? 'ALQURAN')) ?></span></td>
                                <td class="small"><?= htmlspecialchars((string) $d['target_hafalan']) ?></td>
                                <td class="small text-end"><?= isset($d['baris_setor']) && $d['baris_setor'] !== null && $d['baris_setor'] !== '' ? (int) $d['baris_setor'] : '—' ?></td>
                                <td class="small"><?= $d['nilai_skor'] !== null && $d['nilai_skor'] !== '' ? (int) $d['nilai_skor'] : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('selKategori');
    var blockBait = document.getElementById('blockBait');
    var blockQuran = document.getElementById('blockQuran');
    var selBait = document.getElementById('selBait');
    var inpT = document.getElementById('inpTargetQuran');
    var hint = document.getElementById('hintBait');
    function sync() {
        var bait = sel && sel.value === 'BAIT';
        if (blockBait) blockBait.classList.toggle('d-none', !bait);
        if (blockQuran) blockQuran.classList.toggle('d-none', bait);
        if (inpT) inpT.required = !bait;
    }
    function baitHint() {
        if (!selBait || !hint) return;
        var opt = selBait.options[selBait.selectedIndex];
        var tgt = opt ? parseInt(opt.getAttribute('data-target') || '0', 10) : 0;
        var brs = opt ? parseInt(opt.getAttribute('data-baris') || '0', 10) : 0;
        if (tgt > 0 && brs > 0) {
            hint.textContent = 'Acuan target pondok: ' + tgt + ' baris/hari (dari ' + brs + ' baris total kitab).';
        } else {
            hint.textContent = '';
        }
    }
    if (sel) sel.addEventListener('change', sync);
    if (selBait) selBait.addEventListener('change', baitHint);
    sync();
    baitHint();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
