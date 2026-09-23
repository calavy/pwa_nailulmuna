<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';
require_once __DIR__ . '/../helpers/munawib.php';

pembimbing_portal_require_access(['petugas_absensi']);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
if ($pembimbingId <= 0 && !$bolehSemua && $role === 'pembimbing' && table_exists($pdo, 'pembimbing')) {
    $nipLogin = trim((string) ($_SESSION['user']['username'] ?? ''));
    if ($nipLogin !== '') {
        $stPbId = $pdo->prepare('SELECT id, nama_pembimbing, nip FROM pembimbing WHERE TRIM(nip) = :nip LIMIT 1');
        $stPbId->execute(['nip' => $nipLogin]);
        $pbRowLogin = $stPbId->fetch(PDO::FETCH_ASSOC);
        if (is_array($pbRowLogin)) {
            $pembimbingId = (int) ($pbRowLogin['id'] ?? 0);
            $pembimbingInfo = [
                'id' => $pembimbingId,
                'nama' => (string) ($pbRowLogin['nama_pembimbing'] ?? ''),
                'nip' => (string) ($pbRowLogin['nip'] ?? $nipLogin),
            ];
        }
    }
}
$isSelfService = ($role === 'pembimbing' || (int) ($_SESSION['munawib_id'] ?? 0) > 0) && !$bolehSemua;
$today = date('Y-m-d');
$tanggalMax = date('Y-m-d', strtotime('+14 days'));
$tanggalPilih = trim((string) ($_REQUEST['tanggal'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalPilih)) {
    $tanggalPilih = $today;
}
if ($tanggalPilih < $today) {
    $tanggalPilih = $today;
}
if ($tanggalPilih > $tanggalMax) {
    $tanggalPilih = $tanggalMax;
}
$pbPerizinanUrl = static function (array $q = []) use ($tanggalPilih): string {
    $q['tanggal'] = $tanggalPilih;
    return app_href('/pembimbing/perizinan.php?' . http_build_query($q));
};
$act = strtolower(trim((string) ($_GET['act'] ?? '')));

pb_jadwal_override_ensure_schema($pdo);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'cek_bentrok' && $isSelfService && $pembimbingId > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $jadwalId = (int) ($_GET['jadwal_id'] ?? 0);
    $jamBaru = trim((string) ($_GET['jam_mulai'] ?? ''));
    $slotRes = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $tanggalPilih);
    if (!$slotRes['ok']) {
        echo json_encode(['bentrok' => false, 'items' => [], 'error' => $slotRes['pesan']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    /** @var array<string,mixed> $slotAjax */
    $slotAjax = $slotRes['slot'];
    $durasi = (int) ($slotAjax['durasi_menit'] ?? 60);
    $jamSelesai = pb_jadwal_jam_selesai_dari_mulai($jamBaru, $durasi);
    $res = pb_jadwal_cek_bentrok_pindah_waktu($pdo, $pembimbingId, $slotAjax, $tanggalPilih, $jamBaru, $jamSelesai);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSelfService) {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($pembimbingId <= 0) {
        set_flash('error', 'Akun login belum terhubung ke data pembimbing.');
        header('Location: ' . $pbPerizinanUrl());
        exit;
    }

    if ($postAction === 'batal_override') {
        $res = pb_jadwal_hapus_override($pdo, $pembimbingId, (int) ($_POST['override_id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . $pbPerizinanUrl());
        exit;
    }

    if ($postAction === 'batal_pengajuan_munawib') {
        $res = pb_munawib_pengajuan_batal($pdo, $pembimbingId, (int) ($_POST['pengajuan_id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?' . http_build_query(['act' => 'munawib'])));
        exit;
    }

    $postTanggal = trim((string) ($_POST['tanggal'] ?? $tanggalPilih));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $postTanggal)) {
        if ($postTanggal < $today) {
            $postTanggal = $today;
        }
        if ($postTanggal > $tanggalMax) {
            $postTanggal = $tanggalMax;
        }
        $tanggalPilih = $postTanggal;
    }

    $tanggalSlot = $tanggalPilih;
    if ($postAction === 'simpan_munawib' || $postAction === 'ajukan_munawib') {
        $tMulPost = trim((string) ($_POST['tanggal_mulai'] ?? $tanggalPilih));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tMulPost)) {
            if ($tMulPost < $today) {
                $tMulPost = $today;
            }
            if ($tMulPost > $tanggalMax) {
                $tMulPost = $tanggalMax;
            }
            $tanggalSlot = $tMulPost;
        }
    }

    $jadwalId = (int) ($_POST['jadwal_id'] ?? 0);
    $slotRes = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $tanggalSlot);
    if (!$slotRes['ok']) {
        $actBack = match ($postAction) {
            'simpan_pindah' => 'pindah',
            'simpan_munawib', 'ajukan_munawib' => 'munawib',
            default => '',
        };
        set_flash('error', $slotRes['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?' . http_build_query(array_filter([
            'act' => $actBack !== '' ? $actBack : null,
            'tanggal' => $tanggalPilih,
        ]))));
        exit;
    }
    /** @var array<string,mixed> $slot */
    $slot = $slotRes['slot'];
    $alasan = trim((string) ($_POST['alasan'] ?? ''));

    if ($postAction === 'simpan_pindah') {
        $res = pb_jadwal_simpan_pindah_waktu($pdo, $pembimbingId, $slot, $tanggalPilih, (string) ($_POST['jam_mulai_baru'] ?? ''), $alasan, $userId);
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?' . http_build_query(['act' => 'pindah', 'tanggal' => $tanggalPilih])));
        exit;
    }
    if ($postAction === 'simpan_munawib' || $postAction === 'ajukan_munawib') {
        $materiParsed = pb_jadwal_parse_materi_halaman(
            is_array($_POST['materi_hal'] ?? null) ? $_POST['materi_hal'] : [],
            is_array($_POST['materi_isi'] ?? null) ? $_POST['materi_isi'] : []
        );
        if (!$materiParsed['ok']) {
            set_flash('error', $materiParsed['pesan']);
            header('Location: ' . app_href('/pembimbing/perizinan.php?' . http_build_query(['act' => 'munawib', 'tanggal' => $tanggalSlot])));
            exit;
        }
        $tMul = $tanggalSlot;
        $tSel = trim((string) ($_POST['tanggal_selesai'] ?? $tMul));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tSel)) {
            $tSel = $tMul;
        }
        if ($tSel < $tMul) {
            $tSel = $tMul;
        }
        if ($tSel > $tanggalMax) {
            $tSel = $tanggalMax;
        }
        $res = pb_munawib_pengajuan_simpan(
            $pdo,
            $pembimbingId,
            $jadwalId,
            $tMul,
            $tSel,
            (int) ($_POST['munawib_id'] ?? 0),
            $alasan,
            $userId,
            $materiParsed['rows'] ?? []
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?' . http_build_query(['act' => 'munawib', 'tanggal' => $tMul])));
        exit;
    }
}

$slotsHariIni = $isSelfService && $pembimbingId > 0 ? pb_jadwal_slots_hari_ini($pdo, $pembimbingId, $tanggalPilih) : [];
$munawibList = $isSelfService ? munawib_list_aktif($pdo) : [];
$munawibTugasMap = [];
if ($isSelfService && $munawibList !== []) {
    foreach ($munawibList as $mwRow) {
        $mwId = (int) ($mwRow['id'] ?? 0);
        if ($mwId <= 0) {
            continue;
        }
        $penugasan = munawib_penugasan_aktif($pdo, $mwId, $tanggalPilih);
        $munawibTugasMap[$mwId] = $penugasan;
    }
}
$riwayatOverride = $isSelfService && $pembimbingId > 0 ? pb_jadwal_riwayat_override($pdo, $pembimbingId) : [];
$pengajuanMunawib = $isSelfService && $pembimbingId > 0 ? pb_munawib_pengajuan_list_pembimbing($pdo, $pembimbingId) : [];

$bulanId = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$tanggalTs = strtotime($tanggalPilih);
$tanggalLabel = $tanggalTs !== false
    ? (int) date('j', $tanggalTs) . ' ' . ($bulanId[(int) date('n', $tanggalTs)] ?? '') . ' ' . date('Y', $tanggalTs)
    : $tanggalPilih;
$isHariIni = $tanggalPilih === $today;

$izinSql = '
    SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.status_izin, b.nama_pembimbing, b.nip, k.nama_kegiatan
    FROM perizinan_pembimbing i
    INNER JOIN pembimbing b ON b.id = i.pembimbing_id
    LEFT JOIN kegiatan k ON k.id = i.kegiatan_id
';
if ($isSelfService) {
    $izinSql .= ' WHERE i.pembimbing_id = :pid';
}
$izinSql .= ' ORDER BY i.id DESC LIMIT 50';
$stIzin = $pdo->prepare($izinSql);
if ($isSelfService) {
    $stIzin->execute(['pid' => $pembimbingId]);
} else {
    $stIzin->execute();
}
$izinList = $stIzin->fetchAll();

$pageTitle = 'Perizinan Pembimbing';
if ($isSelfService) {
    if (!isset($pageStylesheets) || !is_array($pageStylesheets)) {
        $pageStylesheets = [];
    }
    $pbPerizinanCss = app_asset_href('/assets/css/pb-perizinan-portal.css');
    if (!in_array($pbPerizinanCss, $pageStylesheets, true)) {
        $pageStylesheets[] = $pbPerizinanCss;
    }
}
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<?php if (empty($pbPortalShell)): ?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Perizinan</p>
    <h1 class="h4 mb-1">Pengaturan kegiatan<?= $isHariIni ? ' hari ini' : '' ?></h1>
    <p class="text-muted mb-0">
        <?php if ($isSelfService): ?>
            Ajukan pindah waktu atau ganti munawib (tugas per halaman). Pengajuan min. <?= (int) PB_JADWAL_BATAS_HARI_PENGAJUAN ?> hari sebelum jadwal; setelah disetujui, ubah/batal override max. <?= PB_JADWAL_BATAS_JAM_SEBELUM ?> jam sebelum jadwal asli.
        <?php else: ?>
            Pantau perubahan jadwal dan izin pembimbing.
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($isSelfService): ?>

<?php if ($act === ''): ?>
<div class="pb-perizinan-choice-list mb-4">
    <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php?act=munawib')) ?>" class="pb-perizinan-choice-card pb-perizinan-choice-card--munawib">
        <span class="pb-perizinan-choice-card__icon" aria-hidden="true"><i class="fa-solid fa-user-clock"></i></span>
        <span class="pb-perizinan-choice-card__text">
            <span class="pb-perizinan-choice-card__title">Cari / ganti munawib</span>
            <span class="pb-perizinan-choice-card__desc">1 atau beberapa hari · persetujuan pengasuh</span>
        </span>
        <span class="pb-perizinan-choice-card__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php?act=pindah')) ?>" class="pb-perizinan-choice-card pb-perizinan-choice-card--pindah">
        <span class="pb-perizinan-choice-card__icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
        <span class="pb-perizinan-choice-card__text">
            <span class="pb-perizinan-choice-card__title">Pindah waktu</span>
            <span class="pb-perizinan-choice-card__desc">Ta&apos;lim / ta&apos;alum · maks. <?= (int) PB_JADWAL_MAX_PINDAH_BULAN ?>× per bulan</span>
        </span>
        <span class="pb-perizinan-choice-card__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
    </a>
</div>
<?php else: ?>
<p class="mb-3"><a href="<?= htmlspecialchars($pbPerizinanUrl()) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke pilihan</a></p>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="act" value="<?= htmlspecialchars($act) ?>">
            <div class="col-sm-auto">
                <label class="form-label small mb-1" for="pb-tanggal-pilih">Pilih tanggal</label>
                <input type="date" class="form-control form-control-sm" name="tanggal" id="pb-tanggal-pilih"
                    min="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($tanggalMax) ?>"
                    value="<?= htmlspecialchars($tanggalPilih) ?>" required>
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-calendar-day me-1"></i> Tampilkan</button>
            </div>
            <div class="col-sm">
                <p class="small text-muted mb-0">Jadwal: <strong><?= htmlspecialchars($tanggalLabel) ?></strong><?= $isHariIni ? ' (hari ini)' : '' ?> · maks. 14 hari ke depan</p>
            </div>
        </form>
    </div>
</div>

<?php if ($slotsHariIni === []): ?>
    <div class="alert alert-warning small">
        Tidak ada kegiatan jadwal Anda pada <?= htmlspecialchars($tanggalLabel) ?>.
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Kelola jadwal</a>
    </div>
<?php elseif ($act === 'pindah'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2">Pindah waktu kegiatan<?= $isHariIni ? ' hari ini' : '' ?></h2>
            <p class="small text-muted">Pilih jam mulai baru — jam selesai mengikuti durasi jadwal asli. Hanya ta'lim & ta'alum. Ajukan min. <?= (int) PB_JADWAL_BATAS_HARI_PENGAJUAN ?> hari sebelum jadwal.</p>
            <form method="post" id="form-pindah-waktu" class="row g-3">
                <input type="hidden" name="action" value="simpan_pindah">
                <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggalPilih) ?>">
                <div class="col-md-6">
                    <label class="form-label">Kegiatan <?= $isHariIni ? 'hari ini' : 'tanggal ' . htmlspecialchars($tanggalLabel) ?></label>
                    <select class="form-select" name="jadwal_id" id="pb-slot-pindah" required>
                        <option value="">— Pilih —</option>
                        <?php foreach ($slotsHariIni as $sl):
                            $taalim = strtoupper((string) ($sl['kategori_kegiatan'] ?? '')) === 'TAALIM';
                        ?>
                            <option value="<?= (int) $sl['jadwal_id'] ?>"
                                data-mulai="<?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>"
                                data-selesai="<?= htmlspecialchars(substr((string) $sl['jam_selesai'], 0, 5)) ?>"
                                data-durasi="<?= (int) $sl['durasi_menit'] ?>"
                                data-bisa="<?= !empty($sl['batas_pengajuan']['ok']) && $taalim && (int) $sl['sisa_pindah_bulan'] > 0 ? '1' : '0' ?>"
                                data-sisa="<?= (int) $sl['sisa_pindah_bulan'] ?>"
                                <?= !$taalim ? 'disabled' : '' ?>>
                                <?= htmlspecialchars((string) $sl['nama_kegiatan']) ?> · <?= htmlspecialchars((string) $sl['tingkatan']) ?>
                                · <?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $sl['jam_selesai'], 0, 5)) ?>
                                <?= !$taalim ? '(bukan ta\'lim)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jam mulai baru</label>
                    <input type="time" class="form-control" name="jam_mulai_baru" id="pb-jam-baru" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jam selesai (otomatis)</label>
                    <input type="text" class="form-control" id="pb-jam-selesai-preview" readonly value="—">
                </div>
                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <textarea class="form-control" name="alasan" rows="2" required placeholder="Catatan pergeseran waktu"></textarea>
                </div>
                <div class="col-12">
                    <div class="alert alert-warning d-none py-2 small mb-2" id="pb-pindah-bentrok" role="alert"></div>
                    <p class="small text-muted mb-2" id="pb-pindah-info"></p>
                    <button type="submit" class="btn btn-success" id="pb-btn-pindah"><i class="fa-solid fa-check me-1"></i> Simpan pergeseran</button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($act === 'munawib'): ?>
    <div class="pb-perizinan-munawib mb-4">
        <div class="pb-perizinan-munawib__head">
            <h2 class="h6 mb-1">Ganti / cari munawib</h2>
            <p class="small text-muted mb-0">Ajukan min. <?= (int) PB_JADWAL_BATAS_HARI_PENGAJUAN ?> hari sebelum jadwal. Semua pengajuan menunggu persetujuan pengasuh; WA otomatis ke pengasuh jika lebih dari satu hari.</p>
        </div>
        <form method="post" class="pb-perizinan-munawib__form" id="form-munawib">
            <input type="hidden" name="action" value="ajukan_munawib">
            <section class="pb-perizinan-section">
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="form-label" for="pb-tanggal-mulai">Tanggal mulai</label>
                        <input type="date" class="form-control" name="tanggal_mulai" id="pb-tanggal-mulai"
                            min="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($tanggalMax) ?>"
                            value="<?= htmlspecialchars($tanggalPilih) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="pb-tanggal-selesai">Tanggal selesai</label>
                        <input type="date" class="form-control" name="tanggal_selesai" id="pb-tanggal-selesai"
                            min="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($tanggalMax) ?>"
                            value="<?= htmlspecialchars($tanggalPilih) ?>" required>
                    </div>
                </div>
                <p class="small text-muted mb-0 mt-1">Kegiatan di bawah mengacu pada tanggal mulai (<?= htmlspecialchars($tanggalLabel) ?>).</p>
            </section>
            <section class="pb-perizinan-section">
                <label class="form-label">Kegiatan <?= $isHariIni ? 'hari ini' : 'tanggal ' . htmlspecialchars($tanggalLabel) ?></label>
                <select class="form-select form-select-lg pb-perizinan-select" name="jadwal_id" required>
                    <option value="">— Pilih —</option>
                    <?php foreach ($slotsHariIni as $sl): ?>
                        <?php if (empty($sl['batas_pengajuan']['ok'])) { continue; } ?>
                        <option value="<?= (int) $sl['jadwal_id'] ?>">
                            <?= htmlspecialchars((string) $sl['nama_kegiatan']) ?> · <?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>
            <section class="pb-perizinan-section">
                <label class="form-label">Munawib</label>
                <select class="form-select form-select-lg pb-perizinan-select" name="munawib_id" id="pb-munawib-select" required>
                    <option value="">— Pilih munawib —</option>
                    <?php foreach ($munawibList as $mw):
                        $mwId = (int) ($mw['id'] ?? 0);
                        $pen = $munawibTugasMap[$mwId] ?? null;
                        $suffix = '';
                        if (is_array($pen)) {
                            $suffix = ' · sudah ada tugas';
                        }
                    ?>
                        <option value="<?= $mwId ?>" data-sudah-tugas="<?= is_array($pen) ? '1' : '0' ?>"
                            data-info="<?= htmlspecialchars(is_array($pen) ? 'Sudah ditugaskan oleh ' . (string) ($pen['pembimbing_nama'] ?? 'pembimbing lain') : 'Belum ada penugasan hari ini') ?>">
                            <?= htmlspecialchars((string) $mw['nama']) ?><?= $suffix ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="alert alert-info py-2 px-3 small mb-0 mt-2 d-none" id="pb-munawib-info" role="status"></div>
            </section>
            <section class="pb-perizinan-section">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <label class="form-label mb-0">Tugas / materi per halaman</label>
                    <button type="button" class="btn btn-sm btn-outline-primary w-100 w-sm-auto" id="pb-tambah-hal"><i class="fa-solid fa-plus me-1"></i> Tambah halaman</button>
                </div>
                <div id="pb-materi-rows" class="pb-perizinan-materi-list">
                    <div class="pb-materi-row pb-perizinan-materi-card">
                        <div class="pb-materi-row__hal">
                            <label class="form-label small mb-1">Hal</label>
                            <input type="text" class="form-control form-control-sm" name="materi_hal[]" placeholder="1" value="1" required>
                        </div>
                        <div class="pb-materi-row__isi">
                            <label class="form-label small mb-1">Materi</label>
                            <input type="text" class="form-control" name="materi_isi[]" placeholder="Isi tugas / materi halaman ini" required>
                        </div>
                    </div>
                </div>
            </section>
            <section class="pb-perizinan-section">
                <label class="form-label">Alasan <span class="text-danger">*</span></label>
                <textarea class="form-control" name="alasan" rows="3" required placeholder="Tulis alasan sendiri (wajib), mis. sakit, tugas di luar, keperluan keluarga, …"></textarea>
            </section>
            <div class="pb-perizinan-munawib__submit">
                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fa-solid fa-paper-plane me-1"></i> Kirim pengajuan ke pengasuh</button>
            </div>
        </form>
    </div>
    <?php if ($pengajuanMunawib !== []): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2">Pengajuan munawib</h2>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Rentang</th><th>Kegiatan</th><th>Munawib</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($pengajuanMunawib as $pg):
                        $stPg = (string) ($pg['status'] ?? '');
                        $stLabel = match ($stPg) {
                            'MENUNGGU' => 'Menunggu pengasuh',
                            'DISETUJUI' => 'Disetujui',
                            'DITOLAK' => 'Ditolak',
                            'DIBATALKAN' => 'Dibatalkan',
                            default => $stPg,
                        };
                        $rentang = (string) ($pg['tanggal_mulai'] ?? '');
                        if (($pg['tanggal_selesai'] ?? '') !== '' && ($pg['tanggal_selesai'] ?? '') !== ($pg['tanggal_mulai'] ?? '')) {
                            $rentang .= ' – ' . (string) $pg['tanggal_selesai'];
                        }
                    ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($rentang) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($pg['nama_kegiatan'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($pg['munawib_nama'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars($stLabel) ?></td>
                            <td class="text-end">
                                <?php if ($stPg === 'MENUNGGU'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Batalkan pengajuan ini?')">
                                    <input type="hidden" name="action" value="batal_pengajuan_munawib">
                                    <input type="hidden" name="pengajuan_id" value="<?= (int) ($pg['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Batalkan</button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <script>
    (function () {
        var wrap = document.getElementById('pb-materi-rows');
        var btn = document.getElementById('pb-tambah-hal');
        if (!wrap || !btn) return;
        btn.addEventListener('click', function () {
            var n = wrap.querySelectorAll('.pb-materi-row').length + 1;
            var row = document.createElement('div');
            row.className = 'pb-materi-row pb-perizinan-materi-card';
            row.innerHTML = '<div class="pb-materi-row__hal"><label class="form-label small mb-1">Hal</label><input type="text" class="form-control form-control-sm" name="materi_hal[]" placeholder="' + n + '" value="' + n + '" required></div>'
                + '<div class="pb-materi-row__isi"><label class="form-label small mb-1">Materi</label><input type="text" class="form-control" name="materi_isi[]" placeholder="Isi tugas / materi halaman ini" required></div>';
            wrap.appendChild(row);
        });
    })();
    </script>
<?php endif; ?>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h6 mb-2">Riwayat perubahan hari ini & terakhir</h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Tanggal</th><th>Kegiatan</th><th>Jenis</th><th>Detail</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php if ($riwayatOverride === []): ?>
                    <tr><td colspan="5" class="text-muted text-center small py-3">Belum ada perubahan.</td></tr>
                <?php endif; ?>
                <?php foreach ($riwayatOverride as $rv):
                    $jenis = (string) ($rv['jenis'] ?? '');
                    $jenisLabel = match ($jenis) {
                        'PINDAH_WAKTU' => 'Pindah waktu',
                        'GANTI_MATERI' => 'Ganti materi',
                        'CARI_MUNAWIB' => 'Munawib',
                        default => $jenis,
                    };
                    $detail = '';
                    if ($jenis === 'PINDAH_WAKTU') {
                        $detail = substr((string) ($rv['jam_mulai_asli'] ?? ''), 0, 5) . '→' . substr((string) ($rv['jam_mulai_baru'] ?? ''), 0, 5);
                    } elseif ($jenis === 'CARI_MUNAWIB') {
                        $detail = (string) ($rv['munawib_nama'] ?? '');
                        $mat = pb_jadwal_materi_ringkas((string) ($rv['materi_pengganti'] ?? ''));
                        if ($mat !== '') {
                            $detail .= ' · ' . $mat;
                        }
                    } else {
                        $detail = pb_jadwal_materi_ringkas((string) ($rv['materi_pengganti'] ?? ''));
                    }
                    $bisaBatal = pb_jadwal_cek_batas_waktu((string) ($rv['tanggal'] ?? ''), (string) ($rv['jam_mulai_asli'] ?? ''))['ok'];
                ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars((string) ($rv['tanggal'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($rv['nama_kegiatan'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars($jenisLabel) ?></td>
                        <td class="small"><?= htmlspecialchars($detail) ?></td>
                        <td class="text-end">
                            <?php if ($bisaBatal): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Batalkan perubahan ini?')">
                                <input type="hidden" name="action" value="batal_override">
                                <input type="hidden" name="override_id" value="<?= (int) ($rv['id'] ?? 0) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Batal</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted small">Terkunci</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('pb-slot-pindah');
    var jamBaru = document.getElementById('pb-jam-baru');
    var preview = document.getElementById('pb-jam-selesai-preview');
    var info = document.getElementById('pb-pindah-info');
    var bentrokEl = document.getElementById('pb-pindah-bentrok');
    var btn = document.getElementById('pb-btn-pindah');
    if (!sel || !jamBaru) return;

    var cekUrl = <?= json_encode(app_href('/pembimbing/perizinan.php?ajax=cek_bentrok&tanggal=' . rawurlencode($tanggalPilih)), JSON_UNESCAPED_UNICODE) ?>;
    var cekTimer = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function selesaiFromMulai(mulai, durasi) {
        if (!mulai || !durasi) return '—';
        var p = mulai.split(':');
        var d = new Date(1970, 0, 1, parseInt(p[0], 10), parseInt(p[1], 10));
        d.setMinutes(d.getMinutes() + durasi);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function refreshInfo() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            preview.value = '—';
            info.textContent = '';
            if (btn) btn.disabled = true;
            if (bentrokEl) { bentrokEl.classList.add('d-none'); bentrokEl.textContent = ''; }
            return;
        }
        var durasi = parseInt(opt.getAttribute('data-durasi') || '60', 10);
        preview.value = selesaiFromMulai(jamBaru.value || opt.getAttribute('data-mulai'), durasi);
        var bisa = opt.getAttribute('data-bisa') === '1';
        var sisa = opt.getAttribute('data-sisa') || '0';
        info.textContent = bisa
            ? 'Sisa kuota pindah bulan ini: ' + sisa + 'x · Asli ' + opt.getAttribute('data-mulai') + '–' + opt.getAttribute('data-selesai')
            : 'Kegiatan ini tidak bisa dipindah (bukan ta\'lim, kuota habis, atau sudah lewat batas waktu).';
        if (btn) btn.disabled = !bisa;
    }
    function cekBentrok() {
        if (!bentrokEl) return;
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value || !jamBaru.value) {
            bentrokEl.classList.add('d-none');
            bentrokEl.textContent = '';
            return;
        }
        var url = cekUrl + '&jadwal_id=' + encodeURIComponent(opt.value) + '&jam_mulai=' + encodeURIComponent(jamBaru.value);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.bentrok || !data.items || !data.items.length) {
                    bentrokEl.classList.add('d-none');
                    bentrokEl.textContent = '';
                    return;
                }
                var lines = data.items.map(function (it) {
                    return (it.nama_kegiatan || '—') + ' · ' + (it.tingkatan || '') + ' · ' + (it.jam || '') + ' (' + (it.pembimbing || '—') + ')';
                });
                bentrokEl.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation me-1"></i> Peringatan bentrok:</strong> waktu baru bentrok dengan kegiatan lain:<ul class="mb-0 mt-1 ps-3"><li>' + lines.join('</li><li>') + '</li></ul>';
                bentrokEl.classList.remove('d-none');
            })
            .catch(function () {
                bentrokEl.classList.add('d-none');
            });
    }
    function refresh() {
        refreshInfo();
        clearTimeout(cekTimer);
        cekTimer = setTimeout(cekBentrok, 350);
    }
    sel.addEventListener('change', refresh);
    jamBaru.addEventListener('input', refresh);
    refresh();
})();

(function () {
    var mwSel = document.getElementById('pb-munawib-select');
    var mwInfo = document.getElementById('pb-munawib-info');
    if (!mwSel || !mwInfo) return;
    function refreshMw() {
        var opt = mwSel.options[mwSel.selectedIndex];
        if (!opt || !opt.value) {
            mwInfo.textContent = '';
            mwInfo.className = 'alert py-2 px-3 small mb-0 mt-2 d-none';
            return;
        }
        var sudah = opt.getAttribute('data-sudah-tugas') === '1';
        mwInfo.textContent = opt.getAttribute('data-info') || '';
        mwInfo.className = 'alert py-2 px-3 small mb-0 mt-2 ' + (sudah ? 'alert-warning' : 'alert-success');
    }
    mwSel.addEventListener('change', refreshMw);
    refreshMw();
})();
</script>

<?php else: ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Daftar izin pembimbing (legacy)</h2>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover">
                <thead><tr><th>Pembimbing</th><th>Kegiatan</th><th>Jenis</th><th>Tanggal</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($izinList === []): ?><tr><td colspan="5" class="text-muted text-center small">Belum ada data.</td></tr><?php endif; ?>
                <?php foreach ($izinList as $i): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $i['nama_pembimbing']) ?></td>
                        <td><?= htmlspecialchars((string) ($i['nama_kegiatan'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) $i['jenis_izin']) ?></td>
                        <td><?= htmlspecialchars((string) $i['tanggal_mulai']) ?> s/d <?= htmlspecialchars((string) $i['tanggal_selesai']) ?></td>
                        <td><?= htmlspecialchars((string) $i['status_izin']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
