<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_nilai_manual.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';
require_once __DIR__ . '/../helpers/munawib_portal.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';

require_roles(['admin', 'pengurus', 'pembimbing']);
munawib_portal_guard_halaman();

pembimbing_nilai_manual_ensure_schema($pdo);
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
if (!column_exists($pdo, 'pembimbing_nilai_manual', 'aspek')) {
    try {
        $pdo->exec("ALTER TABLE pembimbing_nilai_manual ADD COLUMN aspek VARCHAR(20) NOT NULL DEFAULT 'murod' AFTER kegiatan_id");
    } catch (Throwable $e) {
        // abaikan jika kolom sudah ada
    }
}
try {
    $pdo->exec('ALTER TABLE pembimbing_nilai_manual DROP INDEX uk_nilai_manual');
} catch (Throwable $e) {
}
try {
    $pdo->exec('ALTER TABLE pembimbing_nilai_manual ADD UNIQUE KEY uk_nilai_manual (pembimbing_id, santri_id, aspek, tanggal)');
} catch (Throwable $e) {
}

$aspekOptions = [
    'murod' => 'Murod (kedudukan kata / maksud)',
    'makna' => 'Makna (penjelasan arti)',
    'hafalan' => 'Hafalan (kelancaran dan ketepatan)',
];

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
$filterAspek = strtolower(trim((string) ($_GET['aspek'] ?? '')));
$targetId = (int) ($_GET['target_id'] ?? 0);
$preselectSantriId = (int) ($_GET['santri_id'] ?? 0);
$showBuatTarget = isset($_GET['buat_target']);
$showInputNilai = isset($_GET['input_nilai']) || $preselectSantriId > 0;
if ($filterAspek !== '' && !isset($aspekOptions[$filterAspek])) {
    $filterAspek = '';
}

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
    if ($aksi === 'buat_target') {
        $judul = mb_substr(trim((string) ($_POST['judul'] ?? '')), 0, 120);
        $deskripsi = mb_substr(trim((string) ($_POST['deskripsi'] ?? '')), 0, 500);
        $aspekTarget = strtolower(trim((string) ($_POST['aspek'] ?? 'murod')));
        if (!isset($aspekOptions[$aspekTarget])) {
            $aspekTarget = 'murod';
        }
        $tglMulai = trim((string) ($_POST['tanggal_mulai'] ?? date('Y-m-d')));
        $tglSelesai = trim((string) ($_POST['tanggal_selesai'] ?? date('Y-m-d', strtotime('+7 days'))));
        $pbIdInput = (int) ($_POST['pembimbing_id'] ?? 0);
        $pbIdSimpan = $bolehSemua ? max(0, $pbIdInput) : $pembimbingIdAktif;
        if ($judul === '' || $pbIdSimpan <= 0) {
            set_flash('error', 'Judul target dan pembimbing wajib diisi.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai)) {
            set_flash('error', 'Periode target tidak valid.');
        } else {
            if ($tglSelesai < $tglMulai) {
                [$tglMulai, $tglSelesai] = [$tglSelesai, $tglMulai];
            }
            $st = $pdo->prepare('
                INSERT INTO pembimbing_penilaian_target
                    (pembimbing_id, judul, deskripsi, aspek, tanggal_mulai, tanggal_selesai, is_aktif, created_by)
                VALUES (:pid, :j, :d, :a, :m, :s, 1, :uid)
            ');
            $st->execute([
                'pid' => $pbIdSimpan,
                'j' => $judul,
                'd' => $deskripsi !== '' ? $deskripsi : null,
                'a' => $aspekTarget,
                'm' => $tglMulai,
                's' => $tglSelesai,
                'uid' => $userId > 0 ? $userId : null,
            ]);
            $targetId = (int) $pdo->lastInsertId();
            set_flash('success', 'Target penilaian dibuat. Lengkapi nilai seluruh santri.');
        }
    } elseif ($aksi === 'simpan_nilai') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $kegiatanId = 0;
        $targetIdPost = (int) ($_POST['target_id'] ?? 0);
        $aspek = strtolower(trim((string) ($_POST['aspek'] ?? 'murod')));
        if (!isset($aspekOptions[$aspek])) {
            $aspek = 'murod';
        }
        $nilaiRaw = (float) ($_POST['nilai'] ?? 0);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $tglPost = trim((string) ($_POST['tanggal_penilaian'] ?? $_POST['tanggal'] ?? $tanggal));
        $pbIdInput = (int) ($_POST['pembimbing_id'] ?? 0);

        if ($targetIdPost <= 0) {
            set_flash('error', 'Pilih target penilaian terlebih dahulu.');
        } elseif ($santriId <= 0) {
            set_flash('error', 'Santri belum dipilih.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglPost)) {
            set_flash('error', 'Tanggal tidak valid.');
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
                $targetRow = pembimbing_nilai_manual_target_by_id($pdo, $targetIdPost);
                if ($targetRow === null) {
                    set_flash('error', 'Target penilaian tidak ditemukan.');
                } else {
                    $tglMulaiTarget = (string) ($targetRow['tanggal_mulai'] ?? '');
                    $tglSelesaiTarget = (string) ($targetRow['tanggal_selesai'] ?? '');
                    if ($tglMulaiTarget !== '' && $tglPost < $tglMulaiTarget) {
                        $tglPost = $tglMulaiTarget;
                    }
                    if ($tglSelesaiTarget !== '' && $tglPost > $tglSelesaiTarget) {
                        $tglPost = $tglSelesaiTarget;
                    }
                    $aspek = (string) ($targetRow['aspek'] ?? $aspek);
                    $st = $pdo->prepare('
                        INSERT INTO pembimbing_nilai_manual
                            (pembimbing_id, target_id, santri_id, kegiatan_id, aspek, tanggal, nilai, catatan, created_by, updated_by)
                        VALUES
                            (:pid, :tid, :sid, 0, :aspek, :tgl, :nilai, :catatan, :uid, :uid)
                        ON DUPLICATE KEY UPDATE
                            nilai = VALUES(nilai),
                            catatan = VALUES(catatan),
                            target_id = VALUES(target_id),
                            updated_by = VALUES(updated_by)
                    ');
                    $st->execute([
                        'pid' => $pbIdSimpan,
                        'tid' => $targetIdPost,
                        'sid' => $santriId,
                        'aspek' => $aspek,
                        'tgl' => $tglPost,
                        'nilai' => $nilai,
                        'catatan' => $catatan !== '' ? $catatan : null,
                        'uid' => $userId > 0 ? $userId : null,
                    ]);
                    set_flash('success', 'Nilai manual berhasil disimpan.');
                    $targetId = $targetIdPost;
                }
            }
        }
    }

    $qs = ['tanggal' => $tanggal];
    if ($targetId > 0) {
        $qs['target_id'] = $targetId;
    }
    if ($filterTingkatan !== '') {
        $qs['tingkatan'] = $filterTingkatan;
    }
    if ($filterKelas !== '') {
        $qs['kelas'] = $filterKelas;
    }
    if ($filterAspek !== '') {
        $qs['aspek'] = $filterAspek;
    }
    if ($preselectSantriId > 0) {
        $qs['santri_id'] = $preselectSantriId;
    }
    if ($aksi === 'buat_target' && $targetId > 0) {
        $qs['target_id'] = $targetId;
    }
    if ($aksi === 'simpan_nilai') {
        $qs['input_nilai'] = '1';
    }

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        $flash = offline_sync_take_flash();
        offline_sync_json_response($flash['type'], $flash['message'], [
            'target_id' => $targetId,
            'action' => $aksi,
        ]);
    }

    header('Location: ' . app_href('/pembimbing/nilai_manual.php?' . http_build_query($qs)));
    exit;
}

$pembimbingList = [];
if ($bolehSemua && table_exists($pdo, 'pembimbing')) {
    $pembimbingList = $pdo->query('SELECT id, nama_pembimbing, nip FROM pembimbing WHERE COALESCE(is_aktif,1)=1 ORDER BY ' . pembimbing_list_order_sql(''))->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$santriRows = [];
$filterIsPkpps = $filterTingkatan !== '' && pembimbing_pkpps_is_label($filterTingkatan);
if (table_exists($pdo, 'santri') && !$filterIsPkpps) {
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

if (!$bolehSemua && $pembimbingIdAktif > 0 && pembimbing_pkpps_has_jadwal($pdo, $pembimbingIdAktif)) {
    $pkppsFilterIds = [];
    if ($filterIsPkpps) {
        $tid = pembimbing_pkpps_id_from_label($filterTingkatan, $pdo, $pembimbingIdAktif);
        if ($tid > 0) {
            $pkppsFilterIds = [$tid];
        }
    } elseif ($filterTingkatan === '') {
        $pkppsFilterIds = pembimbing_pkpps_tingkatan_ids($pdo, $pembimbingIdAktif);
    }
    if ($pkppsFilterIds !== [] || $filterIsPkpps) {
        $pkRows = pembimbing_pkpps_santri_list($pdo, $pembimbingIdAktif, $pkppsFilterIds, 600);
        $existingIds = array_flip(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $santriRows));
        foreach ($pkRows as $pr) {
            $sid = (int) ($pr['santri_id'] ?? 0);
            if ($sid > 0 && !isset($existingIds[$sid])) {
                $santriRows[] = [
                    'id' => $sid,
                    'nis' => (string) ($pr['nis'] ?? ''),
                    'nama_santri' => (string) ($pr['nama_santri'] ?? ''),
                    'tingkatan' => (string) ($pr['tingkatan'] ?? ''),
                ];
            }
        }
        usort($santriRows, static function (array $a, array $b): int {
            $c = strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
            return $c !== 0 ? $c : strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
        });
    }
}

$whereNilai = 'WHERE 1=1';
$paramsNilai = [];
if ($targetId > 0) {
    $whereNilai .= ' AND n.target_id = :tid';
    $paramsNilai['tid'] = $targetId;
} else {
    $whereNilai .= ' AND n.tanggal = :tgl';
    $paramsNilai['tgl'] = $tanggal;
}
if ($filterAspek !== '') {
    $whereNilai .= ' AND n.aspek = :aspek';
    $paramsNilai['aspek'] = $filterAspek;
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
               CASE WHEN n.aspek <> \'\' THEN UPPER(n.aspek) ELSE "Umum" END AS label_aspek
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

$pbIdUntukTarget = $bolehSemua && $pembimbingIdAktif <= 0 ? 0 : ($bolehSemua ? $pembimbingIdAktif : $pembimbingIdAktif);
if ($bolehSemua && isset($_GET['pembimbing_id'])) {
    $pbIdUntukTarget = (int) $_GET['pembimbing_id'];
}
if ($pbIdUntukTarget <= 0 && !$bolehSemua) {
    $pbIdUntukTarget = $pembimbingIdAktif;
}
$targetList = $pbIdUntukTarget > 0 ? pembimbing_nilai_manual_targets($pdo, $pbIdUntukTarget, true) : [];
$activeTarget = $targetId > 0 ? pembimbing_nilai_manual_target_by_id($pdo, $targetId) : null;
if ($activeTarget === null && $targetList !== []) {
    $activeTarget = $targetList[0];
    $targetId = (int) ($activeTarget['id'] ?? 0);
}
$nilaiMapTarget = [];
if ($activeTarget !== null && $santriRows !== []) {
    $santriIds = array_map(static fn (array $s): int => (int) ($s['id'] ?? 0), $santriRows);
    $nilaiMapTarget = pembimbing_nilai_manual_map_for_target($pdo, $targetId, $santriIds);
}
$belumNilaiCount = 0;
foreach ($santriRows as $sr) {
    if (!isset($nilaiMapTarget[(int) ($sr['id'] ?? 0)])) {
        $belumNilaiCount++;
    }
}

$pageTitle = 'Nilai Manual';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pembimbing · Penilaian</p>
    <h1 class="h4 mb-1">Nilai manual</h1>
    <p class="text-muted mb-0 small">
        Penilaian lisan (murod, makna, hafalan) memakai <strong>periode tanggal</strong> — tidak perlu jam/durasi.
        Buat target, lalu input nilai santri dalam rentang tersebut.
        <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual_rekap.php')) ?>">Rekapan nilai</a>
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

<div class="d-flex flex-wrap gap-2 mb-2">
    <?php
    $qsBuat = $_GET;
    unset($qsBuat['buat_target'], $qsBuat['input_nilai']);
    $qsBuat['buat_target'] = '1';
    ?>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?' . http_build_query($qsBuat))) ?>"
       class="btn btn-outline-primary btn-sm<?= $showBuatTarget ? ' active' : '' ?>">
        <i class="fa-solid fa-bullseye me-1"></i>Buat target
    </a>
    <?php if ($activeTarget !== null && $santriRows !== []): ?>
        <?php
        $qsIn = $_GET;
        unset($qsIn['buat_target']);
        $qsIn['input_nilai'] = '1';
        ?>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?' . http_build_query($qsIn))) ?>#form-nilai"
           class="btn btn-success btn-sm<?= $showInputNilai ? ' active' : '' ?>">
            <i class="fa-solid fa-pen me-1"></i>Input nilai santri
        </a>
    <?php endif; ?>
</div>

<div class="row g-2 mb-2">
    <?php if ($showBuatTarget): ?>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold small">Buat target penilaian</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="buat_target">
                    <?php if ($bolehSemua): ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Pembimbing</label>
                        <select name="pembimbing_id" class="form-select form-select-sm" required>
                            <option value="">Pilih pembimbing</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) ($p['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($p['nama_pembimbing'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="pembimbing_id" value="<?= $pembimbingIdAktif ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Target / materi dinilai</label>
                        <input type="text" name="judul" class="form-control form-control-sm" maxlength="120" required placeholder="Contoh: Murod Juz 30, Makna QS An-Naba">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Aspek</label>
                        <select name="aspek" class="form-select form-select-sm">
                            <?php foreach ($aspekOptions as $k => $label): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d', strtotime('+7 days'))) ?>">
                    </div>
                    <div class="col-12">
                        <textarea name="deskripsi" class="form-control form-control-sm" rows="2" placeholder="Catatan target (opsional)"></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-bullseye me-1"></i>Buat target</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?= $showBuatTarget ? 'col-lg-7' : 'col-12' ?>">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold small d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Target aktif</span>
                <?php if ($activeTarget !== null && $santriRows !== []): ?>
                    <span class="badge text-bg-<?= $belumNilaiCount > 0 ? 'warning' : 'success' ?>">
                        <?= $belumNilaiCount > 0 ? $belumNilaiCount . ' santri belum dinilai' : 'Semua santri sudah dinilai' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if ($targetList === []): ?>
                    <p class="text-muted small p-3 mb-0">Belum ada target. Buat target penilaian terlebih dahulu.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($targetList as $tg):
                            $tid = (int) ($tg['id'] ?? 0);
                            $qsT = ['target_id' => $tid, 'tanggal' => $tanggal];
                            if ($filterTingkatan !== '') { $qsT['tingkatan'] = $filterTingkatan; }
                        ?>
                            <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?' . http_build_query($qsT))) ?>"
                               class="list-group-item list-group-item-action py-2<?= $targetId === $tid ? ' active' : '' ?>">
                                <div class="fw-semibold small"><?= htmlspecialchars((string) ($tg['judul'] ?? '')) ?></div>
                                <div class="small opacity-75">
                                    <?= htmlspecialchars(strtoupper((string) ($tg['aspek'] ?? ''))) ?>
                                    · <?= htmlspecialchars((string) ($tg['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($tg['tanggal_selesai'] ?? '')) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<form class="row g-2 align-items-end mb-2" method="get">
    <?php if ($targetId > 0): ?><input type="hidden" name="target_id" value="<?= (int) $targetId ?>"><?php endif; ?>
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
        <label class="form-label small mb-0">Aspek</label>
        <select name="aspek" class="form-select form-select-sm">
            <option value="">Semua aspek</option>
            <?php foreach ($aspekOptions as $k => $label): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $filterAspek === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
    </div>
</form>

<?php if ($santriRows !== []): ?>
<div class="d-lg-none mb-3">
    <div class="card shadow-sm">
        <div class="card-header py-2 small fw-semibold">Pilih santri (ponsel)</div>
        <div class="list-group list-group-flush" style="max-height:16rem;overflow-y:auto">
            <?php
            $qsBase = [];
            if ($targetId > 0) { $qsBase['target_id'] = $targetId; }
            if ($filterTingkatan !== '') { $qsBase['tingkatan'] = $filterTingkatan; }
            if ($filterKelas !== '') { $qsBase['kelas'] = $filterKelas; }
            if ($filterAspek !== '') { $qsBase['aspek'] = $filterAspek; }
            foreach ($santriRows as $s):
                $sid = (int) ($s['id'] ?? 0);
                $qsBase['santri_id'] = $sid;
                $qsBase['input_nilai'] = '1';
            ?>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/nilai_manual.php?' . http_build_query($qsBase))) ?>#form-nilai"
                   class="list-group-item list-group-item-action py-2<?= $preselectSantriId === $sid ? ' active' : '' ?>">
                    <div class="fw-semibold small"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?></div>
                    <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($s['tingkatan'] ?? '')) ?> · <?= htmlspecialchars((string) ($s['nis'] ?? '')) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTarget !== null && $santriRows !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold small">Checklist santri — <?= htmlspecialchars((string) ($activeTarget['judul'] ?? '')) ?></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Santri</th><th>Status</th><th>Nilai</th></tr></thead>
            <tbody>
            <?php foreach ($santriRows as $s):
                $sid = (int) ($s['id'] ?? 0);
                $nm = $nilaiMapTarget[$sid] ?? null;
                $qsRow = [];
                if ($targetId > 0) { $qsRow['target_id'] = $targetId; }
                if ($filterTingkatan !== '') { $qsRow['tingkatan'] = $filterTingkatan; }
                if ($filterKelas !== '') { $qsRow['kelas'] = $filterKelas; }
                if ($filterAspek !== '') { $qsRow['aspek'] = $filterAspek; }
                $qsRow['santri_id'] = $sid;
                $qsRow['input_nilai'] = '1';
                $formHref = app_href('/pembimbing/nilai_manual.php?' . http_build_query($qsRow)) . '#form-nilai';
            ?>
                <tr class="<?= $nm === null ? 'table-warning' : '' ?>">
                    <td class="small">
                        <?php if ($nm === null): ?>
                            <a href="<?= htmlspecialchars($formHref) ?>" class="text-decoration-none fw-semibold link-primary"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?>
                        <?php endif; ?>
                        <span class="text-muted">(<?= htmlspecialchars((string) ($s['tingkatan'] ?? '')) ?>)</span>
                    </td>
                    <td class="small"><?= $nm === null ? '<span class="text-danger fw-semibold">Belum dinilai</span>' : '<span class="text-success">Sudah</span>' ?></td>
                    <td><?= $nm !== null ? number_format((float) $nm['nilai'], 1, ',', '.') : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($showInputNilai): ?>
<div class="row g-2" id="form-nilai">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">Input nilai manual</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="simpan_nilai">
                    <?php
                    $tglPenilaianDefault = $tanggal;
                    if ($activeTarget !== null) {
                        $tglPenilaianDefault = (string) ($activeTarget['tanggal_selesai'] ?? $tanggal);
                        $today = date('Y-m-d');
                        $mulai = (string) ($activeTarget['tanggal_mulai'] ?? $today);
                        $selesai = (string) ($activeTarget['tanggal_selesai'] ?? $today);
                        if ($today >= $mulai && $today <= $selesai) {
                            $tglPenilaianDefault = $today;
                        }
                    }
                    ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Tanggal penilaian</label>
                        <input type="date" name="tanggal_penilaian" class="form-control form-control-sm" required
                               value="<?= htmlspecialchars($tglPenilaianDefault) ?>"
                               <?php if ($activeTarget !== null): ?>
                               min="<?= htmlspecialchars((string) ($activeTarget['tanggal_mulai'] ?? '')) ?>"
                               max="<?= htmlspecialchars((string) ($activeTarget['tanggal_selesai'] ?? '')) ?>"
                               <?php endif; ?>>
                        <?php if ($activeTarget !== null): ?>
                        <div class="form-text">Periode target: <?= htmlspecialchars((string) ($activeTarget['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($activeTarget['tanggal_selesai'] ?? '')) ?></div>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="target_id" value="<?= (int) $targetId ?>">
                    <?php if ($activeTarget === null): ?>
                        <div class="col-12"><div class="alert alert-warning py-2 small mb-0">Buat atau pilih target penilaian sebelum input nilai.</div></div>
                    <?php endif; ?>
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
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" <?= $preselectSantriId === $sid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?>
                                    (<?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?> · <?= htmlspecialchars((string) ($s['nis'] ?? '-')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Aspek penilaian</label>
                        <input type="text" class="form-control form-control-sm" readonly value="<?= htmlspecialchars($aspekOptions[(string) ($activeTarget['aspek'] ?? 'murod')] ?? '—') ?>">
                        <input type="hidden" name="aspek" value="<?= htmlspecialchars((string) ($activeTarget['aspek'] ?? 'murod')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Nilai (0–100)</label>
                        <input type="number" class="form-control" name="nilai" min="0" max="100" step="0.5" required placeholder="Contoh: 85">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan (opsional)</label>
                        <textarea class="form-control" name="catatan" rows="2" placeholder="Contoh: Bacaan lancar, adab baik, perlu perbaikan makhraj."></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success"<?= $activeTarget === null ? ' disabled' : '' ?>><i class="fa-solid fa-floppy-disk me-1"></i>Simpan nilai</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">
                <?php if ($activeTarget !== null): ?>
                    Nilai — <?= htmlspecialchars((string) ($activeTarget['judul'] ?? '')) ?>
                <?php else: ?>
                    Nilai manual (pilih target)
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>Aspek</th>
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
                                <td class="small"><?= htmlspecialchars((string) ($n['label_aspek'] ?? '-')) ?></td>
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
<?php elseif ($activeTarget !== null && $santriRows !== []): ?>
    <p class="text-muted small mb-0">Pilih <strong>Input nilai santri</strong> untuk membuka formulir penilaian.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

