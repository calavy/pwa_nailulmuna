<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/perizinan_jenis.php';
require_once __DIR__ . '/../helpers/perizinan_syari_kategori.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);
perizinan_rombongan_ensure_schema($pdo);
perizinan_approval_ensure_schema($pdo);
$hideCetakSurat = user_is_pengasuh_kiai();

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada. Jalankan schema_presensi.sql.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'pembimbing_wa_targets') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../helpers/wa_otomatis.php';
    $santriId = (int) ($_GET['santri_id'] ?? 0);
    $enabled = trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) === '1'
        && wa_otomatis_should_run($pdo, 'izin');
    echo json_encode([
        'ok' => true,
        'enabled' => $enabled,
        'targets' => $santriId > 0 ? perizinan_pembimbing_wa_target_rows($pdo, $santriId) : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_izin';
    if ($action === 'create_rombongan') {
        $santriIds = array_map('intval', (array) ($_POST['santri_ids_rombongan'] ?? []));
        $res = perizinan_rombongan_create($pdo, $_POST, $santriIds, (int) ($_SESSION['user']['id'] ?? 0));
        if ($res['ok']) {
            if (empty($res['auto_approved'])) {
                $jenisRombongan = perizinan_jenis_izin_normalize((string) ($_POST['jenis_izin'] ?? 'KELUAR'));
                perizinan_push_setelah_pengajuan(
                    $pdo,
                    'Izin rombongan (' . count($santriIds) . ' santri)',
                    '',
                    $jenisRombongan,
                    trim((string) ($_POST['tanggal_mulai'] ?? date('Y-m-d'))),
                    trim((string) ($_POST['tanggal_selesai'] ?? date('Y-m-d'))),
                    [
                        'jam_mulai' => trim((string) ($_POST['jam_mulai'] ?? date('H:i'))),
                        'jam_selesai' => trim((string) ($_POST['jam_selesai'] ?? date('H:i'))),
                        'alasan' => trim((string) ($_POST['alasan'] ?? '')),
                        'tujuan' => trim((string) ($_POST['tujuan'] ?? '')),
                    ]
                );
            }
            set_flash('success', $res['message']);
            if (!empty($res['auto_approved']) && !empty($res['rombongan_id'])) {
                header('Location: ' . app_rewrite_internal_url('/perizinan/surat_rombongan.php?id=' . (int) $res['rombongan_id']));
                exit;
            }
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
        set_flash('error', $res['message']);
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'approve_rombongan') {
        $rid = (int) ($_POST['rombongan_id'] ?? 0);
        $metaRombongan = $rid > 0 ? perizinan_rombongan_meta($pdo, $rid) : null;
        if (is_array($metaRombongan) && perizinan_memerlukan_persetujuan_pengasuh((string) ($metaRombongan['jenis_izin'] ?? ''))) {
            set_flash('error', 'Izin syar\'i rombongan disetujui oleh pengasuh. Pengurus tidak perlu menyetujui lagi — gunakan tombol Cetak A4.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
        $bypassRombongan = perizinan_request_bypass_alpa($pdo, $_POST);
        $res = perizinan_rombongan_approve($pdo, $rid, $_POST, (int) ($_SESSION['user']['id'] ?? 0), $bypassRombongan);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        if ($res['ok'] && $rid > 0) {
            header('Location: ' . app_rewrite_internal_url('/perizinan/surat_rombongan.php?id=' . $rid));
            exit;
        }
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'approve_izin') {
        $id = (int) ($_POST['izin_id'] ?? 0);
        $bypassAlpa = perizinan_request_bypass_alpa($pdo, $_POST);
        if ($id > 0) {
            $izinInfoStmt = $pdo->prepare('
                SELECT i.id, i.santri_id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.alasan, i.qr_token, i.approval_status, i.pengasuh_approved_at,
                       s.nama_santri, s.nis, s.tingkatan, s.no_wa_wali
                FROM perizinan i
                INNER JOIN santri s ON s.id = i.santri_id
                WHERE i.id = :id
                LIMIT 1
            ');
            $izinInfoStmt->execute(['id' => $id]);
            $izinInfo = $izinInfoStmt->fetch();
            if (!$izinInfo) {
                set_flash('error', 'Data permohonan tidak ditemukan.');
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }

            $santriId = (int) ($izinInfo['santri_id'] ?? 0);
            $jenisIzinRaw = strtoupper((string) ($izinInfo['jenis_izin'] ?? ''));
            if (perizinan_memerlukan_persetujuan_pengasuh($jenisIzinRaw)) {
                set_flash('error', 'Izin syar\'i disetujui oleh pengasuh. Pengurus tidak perlu menyetujui lagi — gunakan tombol Cetak A5.');
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }
            $alpaErr = perizinan_validasi_setujui_alpa($pdo, $santriId, $jenisIzinRaw, $bypassAlpa);
            if ($alpaErr !== null) {
                set_flash('error', $alpaErr);
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }
            if (perizinan_izin_menunggu_persetujuan_pengasuh($pdo, is_array($izinInfo) ? $izinInfo : [])) {
                set_flash('error', 'Izin syar\'i belum disetujui pengasuh. Minta pengasuh meninjau di menu Persetujuan Izin Pengasuh terlebih dahulu.');
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }

            $tglMulai = trim((string) ($_POST['tanggal_mulai'] ?? ''));
            $tglSelesai = trim((string) ($_POST['tanggal_selesai'] ?? ''));
            $jamMulai = trim((string) ($_POST['jam_mulai'] ?? ''));
            $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));
            $durasiRaw = $_POST['durasi_jam'] ?? '';
            if ($tglMulai === '') { $tglMulai = (string) ($izinInfo['tanggal_mulai'] ?? date('Y-m-d')); }
            if ($tglSelesai === '') { $tglSelesai = (string) ($izinInfo['tanggal_selesai'] ?? date('Y-m-d')); }
            if ($jamMulai === '') { $jamMulai = substr((string) ($izinInfo['jam_mulai'] ?? '00:00'), 0, 5); }
            if ($jamSelesai === '') { $jamSelesai = substr((string) ($izinInfo['jam_selesai'] ?? '00:00'), 0, 5); }
            $durasi = $durasiRaw === '' ? (float) ($izinInfo['durasi_jam'] ?? 0) : (float) $durasiRaw;

            $res = perizinan_setujui_izin_satu(
                $pdo,
                $izinInfo,
                (int) ($_SESSION['user']['id'] ?? 1),
                $bypassAlpa,
                [
                    'tanggal_mulai' => $tglMulai,
                    'tanggal_selesai' => $tglSelesai,
                    'jam_mulai' => $jamMulai,
                    'jam_selesai' => $jamSelesai,
                    'durasi_jam' => $durasi,
                ],
                false,
                perizinan_parse_wa_pembimbing_post($_POST)
            );
            if (!$res['ok']) {
                set_flash('error', $res['message']);
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }
            set_flash('success', $res['message']);
            header('Location: ' . app_rewrite_internal_url('/perizinan/surat.php?id=' . $id));
            exit;
        }
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'reject_izin') {
        $id = (int) ($_POST['izin_id'] ?? 0);
        $reason = trim((string) ($_POST['rejected_reason'] ?? ''));
        if ($id > 0) {
            $rp = $pdo->prepare('UPDATE perizinan SET approval_status = "DITOLAK", rejected_reason = :reason, approved_by = :uid, approved_at = NOW(), status_izin = "KEMBALI", waktu_kembali = NOW() WHERE id = :id');
            $rp->execute([
                'id' => $id,
                'reason' => $reason !== '' ? $reason : 'Ditolak pengurus',
                'uid' => (int) ($_SESSION['user']['id'] ?? 1),
            ]);
            set_flash('success', 'Izin ditolak.');
        }
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'perpanjang_izin') {
        $id = (int) ($_POST['izin_id'] ?? 0);
        $tglBaru = trim((string) ($_POST['tanggal_selesai_baru'] ?? ''));
        $maxHari = max(1, (int) app_setting($pdo, 'izin_perpanjangan_max_hari', '7'));
        $jenisAllowed = array_map('trim', explode(',', strtoupper((string) app_setting($pdo, 'izin_perpanjangan_jenis', 'SAKIT,KELUAR'))));
        if ($id > 0 && $tglBaru !== '') {
            $iz = $pdo->prepare('SELECT id, jenis_izin, tanggal_mulai, tanggal_selesai, approval_status FROM perizinan WHERE id = :id LIMIT 1');
            $iz->execute(['id' => $id]);
            $rowIz = $iz->fetch(PDO::FETCH_ASSOC);
            if ($rowIz && ($rowIz['approval_status'] ?? '') === 'DISETUJUI' && in_array(strtoupper((string) ($rowIz['jenis_izin'] ?? '')), $jenisAllowed, true)) {
                $tglLama = (string) ($rowIz['tanggal_selesai'] ?? '');
                $tsLama = strtotime($tglLama);
                $tsBaru = strtotime($tglBaru);
                if ($tsBaru !== false && $tsLama !== false && $tsBaru >= $tsLama) {
                    $selisih = (int) round(($tsBaru - $tsLama) / 86400);
                    if ($selisih <= $maxHari) {
                        $pdo->prepare('UPDATE perizinan SET tanggal_selesai = :tgl WHERE id = :id')->execute(['tgl' => $tglBaru, 'id' => $id]);
                        set_flash('success', 'Perpanjangan izin disimpan sampai ' . $tglBaru . '.');
                    } else {
                        set_flash('error', 'Perpanjangan melebihi batas ' . $maxHari . ' hari (pengaturan).');
                    }
                } else {
                    set_flash('error', 'Tanggal selesai baru harus sama atau setelah tanggal selesai saat ini.');
                }
            } else {
                set_flash('error', 'Izin tidak dapat diperpanjang (status atau jenis tidak sesuai pengaturan).');
            }
        }
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'create_health') {
        $sidH = (int) ($_POST['santri_id_health'] ?? 0);
        if ($sidH > 0) {
            $chkH = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
            $chkH->execute(['id' => $sidH]);
            if (!$chkH->fetchColumn()) {
                set_flash('error', 'Santri tidak aktif — laporan E-Health hanya untuk santri aktif.');
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }
        }
        $insH = $pdo->prepare('
            INSERT INTO ehealth_records (santri_id, gejala, suhu_tubuh, tindakan, status_kesehatan, notifikasi_wali, created_by)
            VALUES (:santri_id, :gejala, :suhu_tubuh, :tindakan, :status_kesehatan, :notifikasi_wali, :created_by)
        ');
        $insH->execute([
            'santri_id' => (int) ($_POST['santri_id_health'] ?? 0),
            'gejala' => trim((string) ($_POST['gejala'] ?? '')),
            'suhu_tubuh' => ($_POST['suhu_tubuh'] ?? '') !== '' ? (float) $_POST['suhu_tubuh'] : null,
            'tindakan' => trim((string) ($_POST['tindakan'] ?? '')),
            'status_kesehatan' => in_array(($_POST['status_kesehatan'] ?? ''), ['RAWAT_PONDOK', 'DIRUJUK_RS', 'ISOLASI', 'SELESAI'], true) ? $_POST['status_kesehatan'] : 'RAWAT_PONDOK',
            'notifikasi_wali' => isset($_POST['notifikasi_wali']) ? 1 : 0,
            'created_by' => (int) ($_SESSION['user']['id'] ?? 1),
        ]);
        set_flash('success', 'Laporan kesehatan berhasil disimpan.');
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }

    set_flash('info', 'Pengajuan izin perorangan ada di menu Pengajuan.');
    header('Location: ' . app_href('/perizinan/permohonan.php'));
    exit;
}

$sqlAktifS = santri_sql_aktif_only('s');
$rombonganSantriGrouped = perizinan_rombongan_santri_aktif_grouped($pdo);
$namaPengasuh = app_setting($pdo, 'nama_pengasuh', '');

$filterStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['PENDING', 'DISETUJUI', 'DITOLAK'], true)) {
    $filterStatus = '';
}
$filterBulan = trim((string) ($_GET['bulan'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $filterBulan)) {
    $filterBulan = '';
}
$izinPage = max(1, (int) ($_GET['page'] ?? 1));
$izinPerPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));

$izinWhere = ['1=1'];
$izinParams = [];
if ($filterStatus !== '') {
    $izinWhere[] = 'i.approval_status = :filter_status';
    $izinParams['filter_status'] = $filterStatus;
}
if ($filterBulan !== '') {
    $izinWhere[] = 'i.tanggal_mulai >= :bulan_awal AND i.tanggal_mulai < DATE_ADD(:bulan_awal_end, INTERVAL 1 MONTH)';
    $izinParams['bulan_awal'] = $filterBulan . '-01';
    $izinParams['bulan_awal_end'] = $filterBulan . '-01';
}
$izinWhereSql = implode(' AND ', $izinWhere);
$izinJoinSql = '
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifS . '
    WHERE ' . $izinWhereSql;

$statsRow = $pdo->query('
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN i.approval_status = "PENDING" THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN i.approval_status = "DISETUJUI" THEN 1 ELSE 0 END) AS disetujui
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifS . '
')->fetch(PDO::FETCH_ASSOC) ?: [];
$izinTotalAll = (int) ($statsRow['total'] ?? 0);
$izinPending = (int) ($statsRow['pending'] ?? 0);
$izinDisetujui = (int) ($statsRow['disetujui'] ?? 0);

$countStmt = $pdo->prepare('SELECT COUNT(*) ' . $izinJoinSql);
$countStmt->execute($izinParams);
$izinTotalFiltered = (int) ($countStmt->fetchColumn() ?: 0);
$izinTotalPages = max(1, (int) ceil($izinTotalFiltered / $izinPerPage));
if ($izinPage > $izinTotalPages) {
    $izinPage = $izinTotalPages;
}
$izinOffset = ($izinPage - 1) * $izinPerPage;

$listStmt = $pdo->prepare('
    SELECT i.id, i.santri_id, i.rombongan_id, i.jenis_izin, i.syari_kategori, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.status_izin, i.approval_status, i.pengasuh_approved_at, i.alasan, i.tujuan, i.rejected_reason, i.qr_token, i.waktu_keluar, i.waktu_kembali, i.poin_pelanggaran, s.nama_santri, s.nis, s.tingkatan
    ' . $izinJoinSql . '
    ORDER BY
        CASE i.approval_status WHEN "PENDING" THEN 0 WHEN "DISETUJUI" THEN 1 ELSE 2 END ASC,
        COALESCE(i.rombongan_id, i.id) DESC,
        i.rombongan_id DESC,
        i.id DESC
    LIMIT ' . (int) $izinPerPage . ' OFFSET ' . (int) $izinOffset);
$listStmt->execute($izinParams);
$izinList = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$izinAlpaMap = perizinan_alpa_map_for_rows($pdo, $izinList);
$izinAlpaCfg = perizinan_alpa_settings($pdo);
$bolehBypassAlpa = perizinan_user_boleh_bypass_alpa($pdo);
$rombonganPending = [];
if (table_exists($pdo, 'perizinan_rombongan_meta')) {
    $rombonganPending = $pdo->query('
        SELECT m.*, (SELECT COUNT(*) FROM perizinan i WHERE i.rombongan_id = m.id) AS jumlah_santri
        FROM perizinan_rombongan_meta m
        WHERE m.approval_status = "PENDING"
        ORDER BY m.id DESC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$healthList = $pdo->query('
    SELECT h.id, h.gejala, h.suhu_tubuh, h.status_kesehatan, h.notifikasi_wali, h.created_at, s.nama_santri, s.nis
    FROM ehealth_records h
    INNER JOIN santri s ON s.id = h.santri_id AND ' . $sqlAktifS . '
    ORDER BY h.id DESC
    LIMIT 20
')->fetchAll();
$izinPerpanjanganMaxHari = max(1, (int) app_setting($pdo, 'izin_perpanjangan_max_hari', '7'));
$izinPerpanjanganJenisArr = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) app_setting($pdo, 'izin_perpanjangan_jenis', 'SAKIT,KELUAR'))))));
$izinListQuery = array_filter([
    'status' => $filterStatus !== '' ? $filterStatus : null,
    'bulan' => $filterBulan !== '' ? $filterBulan : null,
    'per_page' => $izinPerPage !== 50 ? (string) $izinPerPage : null,
]);

$pageTitle = 'Persetujuan Perizinan';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Perizinan</p>
    <h1 class="h4 mb-1">Persetujuan izin santri</h1>
    <p class="text-muted mb-0">Tinjau permohonan yang masuk, setujui atau tolak, cetak surat. Pengajuan perorangan lewat menu <strong>Pengajuan</strong>; izin rombongan dapat diajukan di panel kiri.
        <?php if (user_can_access_permission_key('pengaturan') || is_super_admin()): ?>
            <a href="<?= htmlspecialchars(app_href('/settings/perizinan.php')) ?>" class="ms-1">Pengaturan syarat ALPA &amp; WA pembimbing</a>
        <?php endif; ?>
    </p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total izin</div>
            <div class="app-mini-stat-value"><?= $izinTotalAll ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Pending</div>
            <div class="app-mini-stat-value text-warning"><?= $izinPending ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Disetujui</div>
            <div class="app-mini-stat-value text-success"><?= $izinDisetujui ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">E-Health terbaru</div>
            <div class="app-mini-stat-value"><?= count($healthList) ?></div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-2">Pengajuan perorangan</h2>
                <p class="small text-muted mb-2">Izin santri tunggal (termasuk sakit &amp; E-Health) lewat menu Pengajuan — bukan di halaman persetujuan ini.</p>
                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/permohonan.php')) ?>">Ke form pengajuan</a>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Pengajuan izin rombongan</h2>
                <form method="post" class="row g-2" id="form-izin-rombongan" data-rombongan-min="2" data-rombongan-target="rombongan-input">
                    <input type="hidden" name="action" value="create_rombongan">
                    <div class="col-12">
                        <label class="form-label">Pilih santri rombongan <span class="text-muted fw-normal">(min. 2)</span></label>
                        <?php
                        $rombonganPickerName = 'santri_ids_rombongan[]';
                        $rombonganPickerId = 'rombongan-input';
                        $rombonganPickerShowToolbar = true;
                        require __DIR__ . '/partials/rombongan_santri_picker.php';
                        ?>
                        <div class="form-text">Satu surat A4. Saat kembali, scan kartu masing-masing santri di Scan Presensi.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jenis Izin</label>
                        <select class="form-select" name="jenis_izin" id="jenis-izin-rombongan" required>
                            <?php $selectedJenis = 'KELUAR'; require __DIR__ . '/partials/jenis_izin_select_options.php'; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alasan</label>
                        <textarea class="form-control" name="alasan" rows="2" required></textarea>
                    </div>
                    <?php
                    $tujuanWrapId = 'wrap-tujuan-rombongan';
                    $tujuanJenisSelectId = 'jenis-izin-rombongan';
                    $tujuanValue = '';
                    require __DIR__ . '/partials/tujuan_izin_field.php';
                    ?>
                    <div class="col-4">
                        <label class="form-label">Jam mulai</label>
                        <input type="text" name="jam_mulai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Jam selesai</label>
                        <input type="text" name="jam_selesai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Durasi (jam)</label>
                        <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pemberi izin</label>
                        <input type="text" class="form-control" name="pemberi_izin" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pengasuh</label>
                        <input type="text" class="form-control" name="penandatangan_pengasuh" value="<?= htmlspecialchars($namaPengasuh) ?>" <?= $namaPengasuh !== '' ? 'readonly' : '' ?> required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Simpan izin rombongan</button>
                    </div>
                </form>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/rekap_aktif.php')) ?>">Rekap izin aktif</a>
                    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/izin_tetap.php')) ?>">Izin tetap hidmah</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if ($rombonganPending !== []): ?>
                <div class="alert alert-warning py-2 mb-3">
                    <strong>Izin rombongan menunggu persetujuan</strong>
                    <span class="d-block small fw-normal text-muted">Satu surat A4 berlaku untuk semua santri dalam rombongan yang sama.</span>
                    <ul class="mb-0 small ps-3">
                        <?php foreach ($rombonganPending as $rm):
                            $rombonganSyari = perizinan_memerlukan_persetujuan_pengasuh((string) ($rm['jenis_izin'] ?? ''));
                            $rombonganTungguPengasuh = false;
                            if ($rombonganSyari && column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
                                $stRp = $pdo->prepare('SELECT COUNT(*) FROM perizinan WHERE rombongan_id = :rid AND approval_status = "PENDING" AND pengasuh_approved_at IS NULL');
                                $stRp->execute(['rid' => (int) $rm['id']]);
                                $rombonganTungguPengasuh = (int) ($stRp->fetchColumn() ?: 0) > 0;
                            }
                        ?>
                            <li class="mt-1">
                                #<?= (int) $rm['id'] ?> — <?= (int) ($rm['jumlah_santri'] ?? 0) ?> santri · <?= htmlspecialchars(jenis_izin_label((string) ($rm['jenis_izin'] ?? ''))) ?>
                                <?php if ($rombonganTungguPengasuh): ?>
                                    <span class="badge text-bg-warning ms-1">Menunggu pengasuh</span>
                                <?php elseif ($rombonganSyari): ?>
                                    <span class="badge text-bg-success ms-1">Disetujui pengasuh — cetak saja</span>
                                <?php endif; ?>
                                <?php if (!$rombonganSyari): ?>
                                <form method="post" class="d-inline ms-1">
                                    <input type="hidden" name="action" value="approve_rombongan">
                                    <input type="hidden" name="rombongan_id" value="<?= (int) $rm['id'] ?>">
                                    <input type="hidden" name="tanggal_mulai" value="<?= htmlspecialchars((string) ($rm['tanggal_mulai'] ?? '')) ?>">
                                    <input type="hidden" name="tanggal_selesai" value="<?= htmlspecialchars((string) ($rm['tanggal_selesai'] ?? '')) ?>">
                                    <input type="hidden" name="jam_mulai" value="<?= htmlspecialchars(substr((string) ($rm['jam_mulai'] ?? ''), 0, 5)) ?>">
                                    <input type="hidden" name="jam_selesai" value="<?= htmlspecialchars(substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)) ?>">
                                    <?php if ($bolehBypassAlpa): ?>
                                        <label class="small ms-1"><input type="checkbox" name="bypass_alpa" value="1"> Lewati ALPA</label>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-success">Setujui</button>
                                </form>
                                <?php endif; ?>
                                    <?php if (!$hideCetakSurat): ?>
                                    <a class="btn btn-sm btn-outline-dark" target="_blank" href="<?= htmlspecialchars(app_href('/perizinan/surat_rombongan.php?id=' . (int) $rm['id'])) ?>">Cetak A4</a>
                                    <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <h2 class="h5">Daftar Izin</h2>
                <form method="get" class="row g-2 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-0">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua</option>
                            <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                            <option value="DISETUJUI" <?= $filterStatus === 'DISETUJUI' ? 'selected' : '' ?>>Disetujui</option>
                            <option value="DITOLAK" <?= $filterStatus === 'DITOLAK' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-0">Bulan mulai</label>
                        <input type="month" name="bulan" class="form-control form-control-sm" value="<?= htmlspecialchars($filterBulan) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-0">Per halaman</label>
                        <select name="per_page" class="form-select form-select-sm">
                            <?php foreach ([20, 50, 100] as $pp): ?>
                                <option value="<?= $pp ?>" <?= $izinPerPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Terapkan</button>
                        <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <?php if ($izinTotalFiltered > 0): ?>
                <p class="small text-muted mb-2">
                    Menampilkan <?= (int) min($izinTotalFiltered, $izinOffset + 1) ?>–<?= (int) min($izinTotalFiltered, $izinOffset + count($izinList)) ?>
                    dari <?= $izinTotalFiltered ?> izin<?= $filterStatus !== '' || $filterBulan !== '' ? ' (difilter)' : '' ?>.
                </p>
                <?php endif; ?>
                <?php if (!empty($izinAlpaCfg['enabled'])): ?>
                <p class="small text-muted mb-2">
                    <strong>ALPA</strong> = tidak hadir ke kegiatan wajib tanpa izin/sakit resmi.
                    Kolom syarat ALPA menampilkan jumlah ALPA dalam periode hitung dan apakah masih boleh disetujui.
                </p>
                <?php endif; ?>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Santri</th><th>Jenis</th><th>Tanggal/Jam</th><th>Persetujuan</th><th>Syarat ALPA</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php
                    $rombonganCetakTampil = [];
                    foreach ($izinList as $i):
                        $ridCetak = (int) ($i['rombongan_id'] ?? 0);
                        $tampilkanCetakRombongan = false;
                        if ($ridCetak > 0) {
                            if (!isset($rombonganCetakTampil[$ridCetak])) {
                                $rombonganCetakTampil[$ridCetak] = true;
                                $tampilkanCetakRombongan = true;
                            }
                        }
                    ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($i['nama_santri']) ?> (<?= htmlspecialchars($i['nis']) ?>)
                                <?php if (!empty($i['rombongan_id'])): ?>
                                    <span class="badge text-bg-info ms-1" style="font-size:.65rem">Rombongan #<?= (int) $i['rombongan_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge = 'secondary';
                                if (($i['jenis_izin'] ?? '') === 'TUGAS' || ($i['jenis_izin'] ?? '') === 'PULANG') { $badge = 'danger'; }
                                if (($i['jenis_izin'] ?? '') === 'KELUAR') { $badge = 'primary'; }
                                if (($i['jenis_izin'] ?? '') === 'SAKIT') { $badge = 'success'; }
                                if (($i['jenis_izin'] ?? '') === 'SYARI') { $badge = 'warning'; }
                                ?>
                                <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars(jenis_izin_label((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?></span>
                                <?php
                                $katKodeIdx = trim((string) ($i['syari_kategori'] ?? ''));
                                if ($katKodeIdx !== ''):
                                    ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars(perizinan_syari_kategori_label($pdo, $katKodeIdx)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small js-time-24">
                                <?= htmlspecialchars(app_format_periode_izin_tabel(
                                    (string) $i['tanggal_mulai'],
                                    (string) $i['tanggal_selesai'],
                                    (string) ($i['jam_mulai'] ?? ''),
                                    (string) ($i['jam_selesai'] ?? '')
                                )) ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= ($i['approval_status'] ?? 'PENDING') === 'DISETUJUI' ? 'success' : (($i['approval_status'] ?? 'PENDING') === 'DITOLAK' ? 'danger' : 'warning') ?>">
                                    <?= htmlspecialchars($i['approval_status'] ?? 'PENDING') ?>
                                </span>
                                <?php if (($i['approval_status'] ?? 'PENDING') === 'PENDING' && perizinan_memerlukan_persetujuan_pengasuh((string) ($i['jenis_izin'] ?? ''))): ?>
                                        <div class="small text-muted mt-1">Menunggu pengasuh (izin)</div>
                                <?php elseif (($i['approval_status'] ?? '') === 'DISETUJUI' && perizinan_memerlukan_persetujuan_pengasuh((string) ($i['jenis_izin'] ?? ''))): ?>
                                        <div class="small text-success mt-1">Disetujui pengasuh<?php if (trim((string) ($i['pengasuh_approved_at'] ?? '')) !== ''): ?> · <?= htmlspecialchars(app_format_datetime_id((string) $i['pengasuh_approved_at'])) ?><?php endif; ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($i['approval_status'] ?? 'PENDING') === 'PENDING'): ?>
                                    <?php
                                    $alpaCek = $izinAlpaMap[(int) $i['id']] ?? ['subject' => false, 'allowed' => true, 'alpa_count' => 0];
                                    $mode = 'table';
                                    require __DIR__ . '/../includes/partials/perizinan_alpa_ringkas.php';
                                    ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($i['status_izin']) ?></td>
                            <td class="text-end">
                                <?php if (($i['approval_status'] ?? 'PENDING') === 'PENDING'): ?>
                                    <?php
                                    $isSyariIzin = perizinan_memerlukan_persetujuan_pengasuh((string) ($i['jenis_izin'] ?? ''));
                                    $alpaCekBtn = $izinAlpaMap[(int) $i['id']] ?? ['subject' => false, 'allowed' => true];
                                    $blokirAlpa = !empty($alpaCekBtn['subject']) && empty($alpaCekBtn['allowed']) && !$bolehBypassAlpa;
                                    $blokirPengasuh = !$isSyariIzin && perizinan_izin_menunggu_persetujuan_pengasuh($pdo, $i);
                                    $blokirTitle = $blokirAlpa
                                        ? 'Tidak memenuhi syarat ALPA'
                                        : ($blokirPengasuh ? 'Menunggu persetujuan pengasuh (izin)' : '');
                                    ?>
                                    <?php if (!$isSyariIzin): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-success js-open-approve"
                                            data-bs-toggle="modal"
                                            data-bs-target="#approveIzinModal"
                                            data-izin-id="<?= (int) $i['id'] ?>"
                                            data-santri-id="<?= (int) ($i['santri_id'] ?? 0) ?>"
                                            data-nama="<?= htmlspecialchars((string) $i['nama_santri']) ?> (<?= htmlspecialchars((string) $i['nis']) ?>)"
                                            data-jenis="<?= htmlspecialchars(jenis_izin_label((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?>"
                                            data-jenis-kode="<?= htmlspecialchars(strtoupper((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?>"
                                            data-alasan="<?= htmlspecialchars((string) ($i['alasan'] ?? '')) ?>"
                                            data-tujuan="<?= htmlspecialchars((string) ($i['tujuan'] ?? '')) ?>"
                                            data-tgl-mulai="<?= htmlspecialchars((string) ($i['tanggal_mulai'] ?? '')) ?>"
                                            data-tgl-selesai="<?= htmlspecialchars((string) ($i['tanggal_selesai'] ?? '')) ?>"
                                            data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($i['jam_mulai'] ?? ''))) ?>"
                                            data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($i['jam_selesai'] ?? ''))) ?>"
                                            data-durasi="<?= htmlspecialchars((string) ($i['durasi_jam'] ?? '')) ?>"
                                            data-alpa-count="<?= (int) ($alpaCekBtn['alpa_count'] ?? 0) ?>"
                                            data-alpa-max="<?= (int) ($alpaCekBtn['max'] ?? 0) ?>"
                                            data-alpa-hari="<?= (int) ($alpaCekBtn['hari'] ?? 0) ?>"
                                            data-alpa-allowed="<?= !empty($alpaCekBtn['allowed']) ? '1' : '0' ?>"
                                            data-alpa-subject="<?= !empty($alpaCekBtn['subject']) ? '1' : '0' ?>"
                                            data-alpa-catatan="<?= htmlspecialchars((string) ($alpaCekBtn['catatan'] ?? '')) ?>"
                                            data-alpa-jumlah="<?= htmlspecialchars((string) ($alpaCekBtn['jumlah_teks'] ?? '')) ?>"
                                            data-alpa-periode="<?= htmlspecialchars((string) ($alpaCekBtn['periode_teks'] ?? '')) ?>"
                                            data-alpa-aturan="<?= htmlspecialchars((string) ($alpaCekBtn['aturan_singkat'] ?? '')) ?>"
                                            data-alpa-blokir="<?= htmlspecialchars((string) ($alpaCekBtn['aturan_blokir'] ?? '')) ?>"
                                            data-alpa-status-label="<?= htmlspecialchars((string) ($alpaCekBtn['status_label'] ?? '')) ?>"
                                            data-alpa-progress="<?= htmlspecialchars((string) ($alpaCekBtn['progress_label'] ?? '')) ?>"
                                            data-alpa-penjelasan="<?= htmlspecialchars(perizinan_alpa_penjelasan_plain($alpaCekBtn)) ?>"
                                            <?= ($blokirAlpa || $blokirPengasuh) ? 'disabled title="' . htmlspecialchars($blokirTitle) . '"' : '' ?>>
                                        Setujui
                                    </button>
                                    <?php else: ?>
                                    <span class="small text-muted me-1" title="Izin syar'i disetujui pengasuh di menu Pengasuh">Tunggu pengasuh</span>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Tolak permohonan izin ini?');">
                                        <input type="hidden" name="action" value="reject_izin">
                                        <input type="hidden" name="izin_id" value="<?= (int) $i['id'] ?>">
                                        <input type="hidden" name="rejected_reason" value="Ditolak pengurus">
                                        <button class="btn btn-sm btn-outline-danger">Tolak</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (($i['approval_status'] ?? '') === 'DISETUJUI' && trim((string) ($i['qr_token'] ?? '')) !== ''): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#qrIzinModal<?= (int) $i['id'] ?>">Kode surat</button>
                                <?php endif; ?>
                                <?php
                                $canPerpanjang = ($i['approval_status'] ?? '') === 'DISETUJUI'
                                    && strtoupper((string) ($i['status_izin'] ?? '')) !== 'KEMBALI'
                                    && in_array(strtoupper((string) ($i['jenis_izin'] ?? '')), $izinPerpanjanganJenisArr, true);
                                ?>
                                <?php if ($canPerpanjang): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-success js-open-perpanjang"
                                            data-bs-toggle="modal"
                                            data-bs-target="#perpanjangIzinModal"
                                            data-izin-id="<?= (int) $i['id'] ?>"
                                            data-nama="<?= htmlspecialchars((string) $i['nama_santri']) ?>"
                                            data-tgl-selesai="<?= htmlspecialchars((string) ($i['tanggal_selesai'] ?? '')) ?>">
                                        Perpanjang
                                    </button>
                                <?php endif; ?>
                                <a href="/perizinan/edit.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                <?php
                                $apStat = strtoupper((string) ($i['approval_status'] ?? ''));
                                $apTok = trim((string) ($i['qr_token'] ?? ''));
                                $canPrint = !$hideCetakSurat && !($apStat === 'DITOLAK' || ($apStat === 'PENDING' && $apTok === ''));
                                ?>
                                <?php if ($canPrint && $ridCetak > 0 && $tampilkanCetakRombongan): ?>
                                    <a target="_blank" href="<?= htmlspecialchars(app_href('/perizinan/surat_rombongan.php?id=' . $ridCetak)) ?>" class="btn btn-sm btn-outline-dark" title="Satu surat untuk seluruh rombongan"><i class="fa-solid fa-print me-1"></i> Surat rombongan (A4)</a>
                                <?php elseif ($canPrint && $ridCetak > 0): ?>
                                    <span class="small text-muted">↳ Satu surat rombongan</span>
                                <?php elseif ($canPrint): ?>
                                    <a target="_blank" href="/perizinan/surat.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-outline-dark">Cetak A5</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Surat dapat dicetak setelah izin disetujui.">Cetak A5</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($izinTotalPages > 1): ?>
                <?php
                $pageBase = app_href('/perizinan/index.php') . '?' . http_build_query(array_filter($izinListQuery));
                $pageSep = $pageBase === app_href('/perizinan/index.php') ? '?' : '&';
                ?>
                <nav class="mt-3" aria-label="Halaman daftar izin">
                    <ul class="pagination pagination-sm mb-0 flex-wrap">
                        <li class="page-item<?= $izinPage <= 1 ? ' disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($pageBase . ($izinPage > 1 ? $pageSep . 'page=' . ($izinPage - 1) : '')) ?>">Sebelumnya</a>
                        </li>
                        <?php
                        $pageStart = max(1, $izinPage - 2);
                        $pageEnd = min($izinTotalPages, $izinPage + 2);
                        for ($p = $pageStart; $p <= $pageEnd; $p++):
                        ?>
                        <li class="page-item<?= $p === $izinPage ? ' active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($pageBase . $pageSep . 'page=' . $p) ?>"><?= $p ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item<?= $izinPage >= $izinTotalPages ? ' disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($pageBase . $pageSep . 'page=' . ($izinPage + 1)) ?>">Berikutnya</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h5">Riwayat Kesehatan Terbaru</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Waktu</th><th>Santri</th><th>Suhu</th><th>Status</th><th>Gejala</th></tr></thead>
                    <tbody>
                    <?php foreach ($healthList as $h): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $h['created_at']) ?></td>
                            <td><?= htmlspecialchars($h['nama_santri']) ?> (<?= htmlspecialchars($h['nis']) ?>)</td>
                            <td><?= htmlspecialchars((string) ($h['suhu_tubuh'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) $h['status_kesehatan']) ?></td>
                            <td><?= htmlspecialchars((string) $h['gejala']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="approveIzinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="approve_izin">
            <input type="hidden" name="izin_id" id="approve-izin-id" value="">
            <div class="modal-header">
                <h5 class="modal-title">Setujui &amp; atur jadwal izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 mb-3 small">
                    Atur ulang <strong>tanggal</strong>, <strong>jam</strong>, dan <strong>durasi</strong> bila perlu sebelum menyetujui. Setelah disetujui, surat izin siap dicetak. Izin selesai saat santri scan kartu di Scan Presensi.
                </div>
                <div class="mb-3 small">
                    <div><strong>Santri:</strong> <span id="approve-santri-info">-</span></div>
                    <div><strong>Jenis izin:</strong> <span id="approve-jenis-info">-</span></div>
                    <div class="text-muted"><strong>Alasan:</strong> <span id="approve-alasan-info">-</span></div>
                    <div class="text-muted d-none" id="approve-tujuan-row"><strong>Tujuan:</strong> <span id="approve-tujuan-info">-</span></div>
                </div>
                <div id="approve-alpa-panel" class="alert py-2 small d-none mb-3 izin-alpa-panel-modal"></div>
                <?php if ($bolehBypassAlpa): ?>
                <div id="approve-bypass-wrap" class="form-check mb-3 d-none">
                    <input class="form-check-input" type="checkbox" name="bypass_alpa" id="approve-bypass-alpa" value="1">
                    <label class="form-check-label" for="approve-bypass-alpa">Lewati syarat ALPA<?= is_super_admin() ? ' (admin super)' : ' (admin ditunjuk)' ?></label>
                </div>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" id="approve-tanggal-mulai" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" id="approve-tanggal-selesai" class="form-control" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Jam mulai</label>
                        <input type="text" name="jam_mulai" id="approve-jam-mulai" <?= app_time_input_attrs() ?> required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Jam selesai</label>
                        <input type="text" name="jam_selesai" id="approve-jam-selesai" <?= app_time_input_attrs() ?> required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Durasi (jam)</label>
                        <input type="number" step="0.25" min="0" name="durasi_jam" id="approve-durasi" class="form-control" placeholder="3.5">
                    </div>
                </div>
                <div id="approve-wa-pembimbing-panel" class="border rounded-3 p-3 mt-3 d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold small"><i class="fa-brands fa-whatsapp text-success me-1"></i> WA ke pembimbing</div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="approve-wa-pb-master" checked>
                            <label class="form-check-label small" for="approve-wa-pb-master">Kirim</label>
                        </div>
                    </div>
                    <p class="small text-muted mb-2">Sesuaikan nama dan nomor WA sebelum menyetujui. Kosongkan centang baris jika tidak ingin mengirim ke penerima tersebut.</p>
                    <div id="approve-wa-pembimbing-loading" class="small text-muted d-none">Memuat daftar pembimbing…</div>
                    <div id="approve-wa-pembimbing-empty" class="small text-muted d-none">Tidak ada pembimbing terkait atau notif izin nonaktif.</div>
                    <div id="approve-wa-pembimbing-rows" class="vstack gap-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success" id="approve-submit-btn">Setujui &amp; cetak surat</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="perpanjangIzinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="perpanjang_izin">
            <input type="hidden" name="izin_id" id="perpanjang-izin-id" value="">
            <div class="modal-header">
                <h5 class="modal-title">Perpanjang izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2" id="perpanjang-santri-info">—</p>
                <p class="small mb-3">Selesai saat ini: <strong id="perpanjang-tgl-lama">—</strong>. Maks. tambahan <?= (int) $izinPerpanjanganMaxHari ?> hari (pengaturan).</p>
                <label class="form-label">Tanggal selesai baru</label>
                <input type="date" name="tanggal_selesai_baru" id="perpanjang-tgl-baru" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success">Simpan perpanjangan</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($izinList as $i): ?>
    <?php if (($i['approval_status'] ?? '') !== 'DISETUJUI' || trim((string) ($i['qr_token'] ?? '')) === '') { continue; } ?>
    <div class="modal fade" id="qrIzinModal<?= (int) $i['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Kode surat izin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="fw-semibold mb-1"><?= htmlspecialchars((string) $i['nama_santri']) ?></div>
                    <div class="small text-muted mb-3">
                        <?= htmlspecialchars(jenis_izin_label((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?>
                        · berlaku sampai <?= htmlspecialchars(app_format_tanggal_id((string) $i['tanggal_selesai'])) ?> <?= htmlspecialchars(app_format_jam((string) ($i['jam_selesai'] ?? ''))) ?>
                    </div>
                    <div class="d-inline-flex p-3 bg-white border rounded-4 shadow-sm mb-3">
                        <div class="izin-qr-box" data-token="<?= htmlspecialchars((string) $i['qr_token']) ?>" id="izin-qr-<?= (int) $i['id'] ?>"></div>
                    </div>
                    <div class="small text-muted">Kode referensi surat (bukan untuk scan gerbang). Saat kembali, scan <strong>QR kartu santri</strong> di Scan Presensi — izin otomatis selesai.</div>
                    <div class="font-monospace small mt-2 text-break"><?= htmlspecialchars((string) $i['qr_token']) ?></div>
                </div>
                <div class="modal-footer">
                    <?php if (!$hideCetakSurat): ?>
                    <a class="btn btn-outline-dark" target="_blank" href="/perizinan/surat.php?id=<?= (int) $i['id'] ?>">Cetak surat</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Selesai</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-rombongan-picker.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-tujuan-field.js')) ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var approveModal = document.getElementById('approveIzinModal');
    if (!approveModal) {
        return;
    }
    function setVal(id, val) {
        var el = document.getElementById(id);
        if (el) { el.value = val || ''; }
    }
    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val || '-'; }
    }
    approveModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) { return; }
        setVal('approve-izin-id', btn.getAttribute('data-izin-id'));
        setVal('approve-tanggal-mulai', btn.getAttribute('data-tgl-mulai'));
        setVal('approve-tanggal-selesai', btn.getAttribute('data-tgl-selesai'));
        setVal('approve-jam-mulai', btn.getAttribute('data-jam-mulai'));
        setVal('approve-jam-selesai', btn.getAttribute('data-jam-selesai'));
        setVal('approve-durasi', btn.getAttribute('data-durasi'));
        setText('approve-santri-info', btn.getAttribute('data-nama'));
        setText('approve-jenis-info', btn.getAttribute('data-jenis'));
        setText('approve-alasan-info', btn.getAttribute('data-alasan'));

        var waPanel = document.getElementById('approve-wa-pembimbing-panel');
        var waRows = document.getElementById('approve-wa-pembimbing-rows');
        var waLoading = document.getElementById('approve-wa-pembimbing-loading');
        var waEmpty = document.getElementById('approve-wa-pembimbing-empty');
        var waMaster = document.getElementById('approve-wa-pb-master');
        var santriId = btn.getAttribute('data-santri-id') || '0';
        if (waPanel && waRows) {
            waRows.innerHTML = '';
            waPanel.classList.remove('d-none');
            if (waLoading) waLoading.classList.remove('d-none');
            if (waEmpty) waEmpty.classList.add('d-none');
            fetch('<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>?ajax=pembimbing_wa_targets&santri_id=' + encodeURIComponent(santriId), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (waLoading) waLoading.classList.add('d-none');
                    var targets = (data && data.targets) ? data.targets : [];
                    var enabled = !!(data && data.enabled);
                    if (!enabled || targets.length === 0) {
                        if (waEmpty) waEmpty.classList.remove('d-none');
                        if (waMaster) waMaster.checked = false;
                        return;
                    }
                    if (waMaster) waMaster.checked = true;
                    targets.forEach(function (t, idx) {
                        var row = document.createElement('div');
                        row.className = 'border rounded p-2 bg-light-subtle';
                        var hasPhone = (t.phone || '').trim() !== '';
                        row.innerHTML =
                            '<div class="form-check mb-2">' +
                            '<input class="form-check-input approve-wa-pb-send" type="checkbox" name="wa_pb[' + idx + '][send]" value="1" id="approve-wa-pb-send-' + idx + '" ' + (hasPhone ? 'checked' : '') + '>' +
                            '<label class="form-check-label small fw-semibold" for="approve-wa-pb-send-' + idx + '">Kirim ke penerima ini</label>' +
                            '</div>' +
                            '<div class="row g-2">' +
                            '<div class="col-md-6"><label class="form-label small mb-0">Nama pembimbing</label>' +
                            '<input type="text" class="form-control form-control-sm" name="wa_pb[' + idx + '][nama]" value="' + (t.nama || '').replace(/"/g, '&quot;') + '"></div>' +
                            '<div class="col-md-6"><label class="form-label small mb-0">No. WA</label>' +
                            '<input type="text" class="form-control form-control-sm" name="wa_pb[' + idx + '][phone]" value="' + (t.phone || '').replace(/"/g, '&quot;') + '" inputmode="tel" placeholder="628xxxxxxxxxx"></div>' +
                            '</div>';
                        waRows.appendChild(row);
                    });
                })
                .catch(function () {
                    if (waLoading) waLoading.classList.add('d-none');
                    if (waEmpty) {
                        waEmpty.textContent = 'Gagal memuat daftar pembimbing.';
                        waEmpty.classList.remove('d-none');
                    }
                });
        }
        if (waMaster) {
            waMaster.onchange = function () {
                waPanel.querySelectorAll('.approve-wa-pb-send').forEach(function (cb) {
                    cb.checked = waMaster.checked && cb.closest('.border').querySelector('input[name*="[phone]"]').value.trim() !== '';
                });
            };
        }
        var tujuanVal = (btn.getAttribute('data-tujuan') || '').trim();
        var tujuanRow = document.getElementById('approve-tujuan-row');
        if (tujuanRow) {
            if (tujuanVal !== '') {
                tujuanRow.classList.remove('d-none');
                setText('approve-tujuan-info', tujuanVal);
            } else {
                tujuanRow.classList.add('d-none');
                setText('approve-tujuan-info', '-');
            }
        }

        var alpaPanel = document.getElementById('approve-alpa-panel');
        var bypassWrap = document.getElementById('approve-bypass-wrap');
        var bypassCb = document.getElementById('approve-bypass-alpa');
        var submitBtn = document.getElementById('approve-submit-btn');
        var subject = btn.getAttribute('data-alpa-subject') === '1';
        var allowed = btn.getAttribute('data-alpa-allowed') === '1';
        if (alpaPanel) {
            if (subject) {
                var statusLabel = btn.getAttribute('data-alpa-status-label') || (allowed ? 'Masih boleh disetujui' : 'Terhalang syarat ALPA');
                var jumlah = btn.getAttribute('data-alpa-jumlah') || (btn.getAttribute('data-alpa-count') || '0') + ' kali ALPA';
                var periode = btn.getAttribute('data-alpa-periode') || (btn.getAttribute('data-alpa-hari') || '0') + ' hari';
                var aturan = btn.getAttribute('data-alpa-aturan') || '';
                var blokir = btn.getAttribute('data-alpa-blokir') || '';
                var progress = btn.getAttribute('data-alpa-progress') || '';
                var catatan = btn.getAttribute('data-alpa-catatan') || '';
                var penjelasan = btn.getAttribute('data-alpa-penjelasan') || btn.getAttribute('data-alpa-message') || '';
                alpaPanel.classList.remove('d-none', 'alert-success', 'alert-warning', 'alert-danger');
                alpaPanel.classList.add(allowed ? 'alert-success' : 'alert-danger');
                alpaPanel.innerHTML =
                    '<div class="izin-alpa-glosarium mb-2"><strong>ALPA</strong> = tidak hadir ke kegiatan wajib tanpa izin/sakit resmi.</div>' +
                    '<div class="fw-semibold mb-1">' + (allowed ? '✓ ' : '✗ ') + statusLabel + '</div>' +
                    '<div class="mb-1"><strong>' + jumlah + '</strong> dalam ' + periode + '</div>' +
                    (aturan ? '<div class="text-muted">' + aturan + '</div>' : '') +
                    (blokir ? '<div class="text-muted">' + blokir + '</div>' : '') +
                    (progress ? '<div class="text-muted mt-1">' + progress + '</div>' : '') +
                    (catatan ? '<div class="mt-2 ' + (allowed ? 'text-warning-emphasis' : '') + '">' + catatan + '</div>' : '') +
                    (penjelasan ? '<div class="mt-2 small text-muted">' + penjelasan + '</div>' : '');
            } else {
                alpaPanel.classList.add('d-none');
                alpaPanel.innerHTML = '';
            }
        }
        if (bypassWrap) {
            bypassWrap.classList.toggle('d-none', !(subject && !allowed));
        }
        if (bypassCb) {
            bypassCb.checked = false;
        }
        if (submitBtn) {
            submitBtn.disabled = subject && !allowed && (!bypassWrap || !bypassCb || !bypassCb.checked);
            if (bypassCb) {
                bypassCb.onchange = function () {
                    submitBtn.disabled = subject && !allowed && !bypassCb.checked;
                };
            }
        }
    });
})();

(function () {
    var perpanjangModal = document.getElementById('perpanjangIzinModal');
    if (!perpanjangModal) return;
    perpanjangModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var idEl = document.getElementById('perpanjang-izin-id');
        var infoEl = document.getElementById('perpanjang-santri-info');
        var lamaEl = document.getElementById('perpanjang-tgl-lama');
        var baruEl = document.getElementById('perpanjang-tgl-baru');
        var tglLama = btn.getAttribute('data-tgl-selesai') || '';
        if (idEl) idEl.value = btn.getAttribute('data-izin-id') || '';
        if (infoEl) infoEl.textContent = btn.getAttribute('data-nama') || '—';
        if (lamaEl) lamaEl.textContent = tglLama || '—';
        if (baruEl) baruEl.value = tglLama;
    });
})();

(function () {
    function renderQr(box) {
        if (!box || box.dataset.rendered === '1' || typeof QRCode === 'undefined') return;
        box.innerHTML = '';
        new QRCode(box, {
            text: box.dataset.token || '',
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M
        });
        box.dataset.rendered = '1';
    }
    document.querySelectorAll('.izin-qr-box').forEach(function (box) {
        renderQr(box);
    });
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('shown.bs.modal', function () {
            renderQr(modal.querySelector('.izin-qr-box'));
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
