<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';

require_roles(['admin', 'pengurus', 'pembimbing']);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS pembimbing_nilai_manual (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pembimbing_id INT NOT NULL,
        santri_id INT NOT NULL,
        kegiatan_id INT NOT NULL DEFAULT 0,
        tanggal DATE NOT NULL,
        nilai DECIMAL(6,2) NOT NULL,
        catatan VARCHAR(500) NULL,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_nilai_manual (pembimbing_id, santri_id, kegiatan_id, tanggal),
        KEY idx_nilai_manual_tanggal (tanggal),
        KEY idx_nilai_manual_santri (santri_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$pbAktif = pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingIdAktif = (int) ($pbAktif['id'] ?? 0);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$filterTingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$filterKelas = trim((string) ($_GET['kelas'] ?? ''));
$filterKegiatan = (int) ($_GET['kegiatan_id'] ?? 0);

$semuaTingkatan = $bolehSemua
    ? pembimbing_dashboard_semua_tingkatan($pdo)
    : pembimbing_dashboard_tingkatan_list($pdo, $pembimbingIdAktif > 0 ? $pembimbingIdAktif : null, false);
if ($filterTingkatan !== '' && !in_array($filterTingkatan, $semuaTingkatan, true)) {
    $filterTingkatan = '';
}
$tingkatanUntukKelas = $filterTingkatan !== '' ? [$filterTingkatan] : $semuaTingkatan;
$kelasList = pembimbing_dashboard_kelas_list($pdo, $tingkatanUntukKelas);
if ($filterKelas !== '' && !in_array($filterKelas, $kelasList, true)) {
    $filterKelas = '';
}

$kegiatanList = pembimbing_dashboard_kegiatan_dari_jadwal(
    $pdo,
    $pembimbingIdAktif > 0 ? $pembimbingIdAktif : null,
    $bolehSemua
);
$kegiatanIdsAllowed = array_map(static fn(array $k): int => (int) ($k['id'] ?? 0), $kegiatanList);
if ($kegiatanList === [] && $bolehSemua && table_exists($pdo, 'kegiatan')) {
    $kegiatanList = $pdo->query(
        'SELECT id, nama_kegiatan FROM kegiatan WHERE COALESCE(is_active,1)=1 ORDER BY nama_kegiatan ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $kegiatanIdsAllowed = array_map(static fn(array $k): int => (int) ($k['id'] ?? 0), $kegiatanList);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = trim((string) ($_POST['action'] ?? ''));
    if ($aksi === 'simpan_nilai') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $kegiatanId = max(0, (int) ($_POST['kegiatan_id'] ?? 0));
        $nilaiRaw = (float) ($_POST['nilai'] ?? 0);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $tglPost = trim((string) ($_POST['tanggal'] ?? $tanggal));
        $pbIdInput = (int) ($_POST['pembimbing_id'] ?? 0);

        if ($santriId <= 0) {
            set_flash('error', 'Santri belum dipilih.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglPost)) {
            set_flash('error', 'Tanggal tidak valid.');
        } elseif (
            !$bolehSemua
            && $kegiatanId > 0
            && $kegiatanIdsAllowed !== []
            && !in_array($kegiatanId, $kegiatanIdsAllowed, true)
        ) {
            set_flash('error', 'Kegiatan di luar jadwal pembimbing Anda.');
        } elseif (
            !$bolehSemua
            && !pembimbing_dashboard_santri_dalam_scope($pdo, $santriId, $pembimbingIdAktif, false)
        ) {
            set_flash('error', 'Santri di luar tingkatan/kelas yang Anda ampu.');
        } else {
            $nilai = max(0.0, min(100.0, $nilaiRaw));
            $pbIdSimpan = $bolehSemua ? max(0, $pbIdInput) : $pembimbingIdAktif;
            if ($pbIdSimpan <= 0) {
                set_flash('error', 'Pembimbing tidak valid.');
            } else {
                $st = $pdo->prepare('
                    INSERT INTO pembimbing_nilai_manual
                        (pembimbing_id, santri_id, kegiatan_id, tanggal, nilai, catatan, created_by, updated_by)
                    VALUES
                        (:pid, :sid, :kid, :tgl, :nilai, :catatan, :uid, :uid)
                    ON DUPLICATE KEY UPDATE
                        nilai = VALUES(nilai),
                        catatan = VALUES(catatan),
                        updated_by = VALUES(updated_by)
                ');
                $st->execute([
                    'pid' => $pbIdSimpan,
                    'sid' => $santriId,
                    'kid' => $kegiatanId,
                    'tgl' => $tglPost,
                    'nilai' => $nilai,
                    'catatan' => $catatan !== '' ? $catatan : null,
                    'uid' => $userId > 0 ? $userId : null,
                ]);
                set_flash('success', 'Nilai manual berhasil disimpan.');
            }
        }
    }

    $qs = ['tanggal' => $tanggal];
    if ($filterTingkatan !== '') {
        $qs['tingkatan'] = $filterTingkatan;
    }
    if ($filterKelas !== '') {
        $qs['kelas'] = $filterKelas;
    }
    if ($filterKegiatan > 0) {
        $qs['kegiatan_id'] = $filterKegiatan;
    }
    header('Location: ' . app_href('/pembimbing/nilai_manual.php?' . http_build_query($qs)));
    exit;
}

$pembimbingList = [];
if ($bolehSemua && table_exists($pdo, 'pembimbing')) {
    $pembimbingList = $pdo->query('SELECT id, nama_pembimbing FROM pembimbing WHERE COALESCE(is_aktif,1)=1 ORDER BY nama_pembimbing')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$santriRows = [];
if (table_exists($pdo, 'santri')) {
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');
    $where = 'WHERE ' . $aktifSql;
    $params = [];
    if ($filterTingkatan !== '') {
        $where .= ' AND s.tingkatan = :tk';
        $params['tk'] = $filterTingkatan;
    }
    if ($filterKelas !== '' && column_exists($pdo, 'santri', 'kategori_kelas')) {
        $where .= ' AND TRIM(s.kategori_kelas) = :kelas';
        $params['kelas'] = $filterKelas;
    }
    if (!$bolehSemua && $semuaTingkatan !== [] && $filterTingkatan === '') {
        $in = [];
        foreach ($semuaTingkatan as $i => $tk) {
            $k = 'tk' . $i;
            $in[] = ':' . $k;
            $params[$k] = $tk;
        }
        if ($in !== []) {
            $where .= ' AND s.tingkatan IN (' . implode(',', $in) . ')';
        }
    }
    $sqlSantri = 'SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan FROM santri s ' . $where . ' ORDER BY s.tingkatan ASC, s.' . $nameCol . ' ASC LIMIT 600';
    $stSantri = $pdo->prepare($sqlSantri);
    $stSantri->execute($params);
    $santriRows = $stSantri->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$whereNilai = 'WHERE n.tanggal = :tgl';
$paramsNilai = ['tgl' => $tanggal];
if ($filterKegiatan > 0) {
    $whereNilai .= ' AND n.kegiatan_id = :kid';
    $paramsNilai['kid'] = $filterKegiatan;
}
if (!$bolehSemua && $pembimbingIdAktif > 0) {
    $whereNilai .= ' AND n.pembimbing_id = :pid';
    $paramsNilai['pid'] = $pembimbingIdAktif;
}
if ($filterTingkatan !== '') {
    $whereNilai .= ' AND s.tingkatan = :tk';
    $paramsNilai['tk'] = $filterTingkatan;
}
$nilaiList = [];
if (table_exists($pdo, 'pembimbing_nilai_manual') && table_exists($pdo, 'santri') && table_exists($pdo, 'pembimbing')) {
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sqlNilai = '
        SELECT n.*, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan,
               p.nama_pembimbing,
               CASE WHEN n.kegiatan_id > 0 THEN k.nama_kegiatan ELSE "Tanpa soal tertulis" END AS nama_kegiatan
        FROM pembimbing_nilai_manual n
        INNER JOIN santri s ON s.id = n.santri_id
        INNER JOIN pembimbing p ON p.id = n.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = n.kegiatan_id
        ' . $whereNilai . '
        ORDER BY s.tingkatan ASC, s.' . $nameCol . ' ASC
        LIMIT 500
    ';
    $stNilai = $pdo->prepare($sqlNilai);
    $stNilai->execute($paramsNilai);
    $nilaiList = $stNilai->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Nilai Manual Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pembimbing · Penilaian</p>
    <h1 class="h4 mb-1">Nilai manual tanpa soal tertulis</h1>
    <p class="text-muted mb-0 small">
        Input nilai langsung untuk observasi, praktik, atau penilaian lisan.
        Santri dan kegiatan dibatasi <strong>tingkatan/kelas dari jadwal pembimbing</strong> Anda.
    </p>
</div>

<?php if (!$bolehSemua && $semuaTingkatan === []): ?>
    <div class="alert alert-warning">Belum ada jadwal/tingkatan yang ditetapkan untuk akun pembimbing ini.</div>
<?php elseif ($semuaTingkatan !== []): ?>
    <div class="alert alert-light border py-2 small mb-3">
        <strong>Tingkatan asuhan:</strong> <?= htmlspecialchars(implode(', ', $semuaTingkatan)) ?>
        <?php if ($kelasList !== []): ?>
            · <strong>Kelas:</strong> <?= htmlspecialchars(implode(', ', $kelasList)) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($msg = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($msg = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Tanggal</label>
        <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggal) ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Tingkatan</label>
        <select name="tingkatan" class="form-select form-select-sm">
            <option value="">Semua</option>
            <?php foreach ($semuaTingkatan as $tk): ?>
                <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $filterTingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($kelasList !== []): ?>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Kelas</label>
        <select name="kelas" class="form-select form-select-sm">
            <option value="">Semua kelas</option>
            <?php foreach ($kelasList as $kl): ?>
                <option value="<?= htmlspecialchars((string) $kl) ?>" <?= $filterKelas === (string) $kl ? 'selected' : '' ?>><?= htmlspecialchars((string) $kl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Kegiatan (jadwal)</label>
        <select name="kegiatan_id" class="form-select form-select-sm">
            <option value="0">Semua</option>
            <?php foreach ($kegiatanList as $k): ?>
                <option value="<?= (int) ($k['id'] ?? 0) ?>" <?= $filterKegiatan === (int) ($k['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($k['nama_kegiatan'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
    </div>
</form>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Input nilai manual</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="simpan_nilai">
                    <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
                    <?php if ($bolehSemua): ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Pembimbing</label>
                        <select name="pembimbing_id" class="form-select" required>
                            <option value="">Pilih pembimbing</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) ($p['id'] ?? 0) ?>" <?= (int) ($p['id'] ?? 0) === $pembimbingIdAktif ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($p['nama_pembimbing'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="pembimbing_id" value="<?= $pembimbingIdAktif ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Santri</label>
                        <select name="santri_id" class="form-select" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriRows as $s): ?>
                                <option value="<?= (int) ($s['id'] ?? 0) ?>">
                                    <?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?>
                                    (<?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?> · <?= htmlspecialchars((string) ($s['nis'] ?? '-')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Kegiatan</label>
                        <select name="kegiatan_id" class="form-select">
                            <option value="0">Tanpa soal tertulis</option>
                            <?php if ($kegiatanList === []): ?>
                                <option value="" disabled>Belum ada kegiatan di jadwal</option>
                            <?php endif; ?>
                            <?php foreach ($kegiatanList as $k): ?>
                                <option value="<?= (int) ($k['id'] ?? 0) ?>" <?= $filterKegiatan === (int) ($k['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($k['nama_kegiatan'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Nilai (0-100)</label>
                        <input type="number" class="form-control" name="nilai" min="0" max="100" step="0.5" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan (opsional)</label>
                        <textarea class="form-control" name="catatan" rows="2" placeholder="Contoh: Bacaan lancar, adab baik, perlu perbaikan makhraj."></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan nilai manual</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Data nilai tanggal <?= htmlspecialchars($tanggal) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>Kegiatan</th>
                                <th>Nilai</th>
                                <th>Pembimbing</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($nilaiList === []): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Belum ada nilai manual.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($nilaiList as $n): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string) ($n['nama_santri'] ?? '')) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($n['tingkatan'] ?? '')) ?> · <?= htmlspecialchars((string) ($n['nis'] ?? '')) ?></div>
                                    <?php if (!empty($n['catatan'])): ?>
                                        <div class="small text-muted mt-1"><?= htmlspecialchars((string) $n['catatan']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars((string) ($n['nama_kegiatan'] ?? '-')) ?></td>
                                <td><span class="badge text-bg-primary"><?= number_format((float) ($n['nilai'] ?? 0), 1, ',', '.') ?></span></td>
                                <td class="small"><?= htmlspecialchars((string) ($n['nama_pembimbing'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

