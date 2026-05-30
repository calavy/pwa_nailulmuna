<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);
perizinan_rombongan_ensure_schema($pdo);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada. Jalankan schema_presensi.sql.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$pdo->exec("ALTER TABLE perizinan
    ADD COLUMN IF NOT EXISTS jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG') NOT NULL DEFAULT 'KELUAR',
    ADD COLUMN IF NOT EXISTS jam_mulai TIME NULL,
    ADD COLUMN IF NOT EXISTS jam_selesai TIME NULL,
    ADD COLUMN IF NOT EXISTS durasi_jam DECIMAL(5,2) NULL,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
    ADD COLUMN IF NOT EXISTS approved_by INT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS qr_token VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS waktu_keluar DATETIME NULL,
    ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15,
    ADD COLUMN IF NOT EXISTS poin_pelanggaran INT NOT NULL DEFAULT 0");

$pdo->exec('
    CREATE TABLE IF NOT EXISTS ehealth_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        santri_id INT NOT NULL,
        gejala TEXT NOT NULL,
        suhu_tubuh DECIMAL(4,1) NULL,
        tindakan TEXT NULL,
        status_kesehatan ENUM("RAWAT_PONDOK","DIRUJUK_RS","ISOLASI","SELESAI") NOT NULL DEFAULT "RAWAT_PONDOK",
        notifikasi_wali TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
    )
');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_izin';
    if ($action === 'create_rombongan') {
        $santriIds = array_map('intval', (array) ($_POST['santri_ids_rombongan'] ?? []));
        $res = perizinan_rombongan_create($pdo, $_POST, $santriIds, (int) ($_SESSION['user']['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }
    if ($action === 'approve_rombongan') {
        $rid = (int) ($_POST['rombongan_id'] ?? 0);
        $res = perizinan_rombongan_approve($pdo, $rid, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
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
        if ($id > 0) {
            $izinInfoStmt = $pdo->prepare('
                SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.alasan, i.qr_token, i.approval_status, s.nama_santri, s.no_wa_wali
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

            $tsMulai = strtotime($tglMulai . ' ' . $jamMulai);
            $tsSelesai = strtotime($tglSelesai . ' ' . $jamSelesai);
            if ($tsMulai !== false && $tsSelesai !== false && $tsSelesai < $tsMulai) {
                set_flash('error', 'Waktu selesai harus sesudah waktu mulai. Periksa kembali tanggal/jam.');
                header('Location: ' . app_href('/perizinan/index.php'));
                exit;
            }

            $qrToken = trim((string) ($izinInfo['qr_token'] ?? ''));
            if ($qrToken === '') {
                $qrToken = bin2hex(random_bytes(16));
            }

            $ap = $pdo->prepare('
                UPDATE perizinan
                   SET approval_status = "DISETUJUI",
                       approved_by = :uid,
                       approved_at = NOW(),
                       rejected_reason = NULL,
                       qr_token = :qr_token,
                       status_izin = "IZIN",
                       tanggal_mulai = :tanggal_mulai,
                       tanggal_selesai = :tanggal_selesai,
                       jam_mulai = :jam_mulai,
                       jam_selesai = :jam_selesai,
                       durasi_jam = :durasi_jam
                 WHERE id = :id
            ');
            $ap->execute([
                'uid' => (int) ($_SESSION['user']['id'] ?? 1),
                'qr_token' => $qrToken,
                'tanggal_mulai' => $tglMulai,
                'tanggal_selesai' => $tglSelesai,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'durasi_jam' => $durasi,
                'id' => $id,
            ]);
            $s = $pdo->prepare('UPDATE santri s INNER JOIN perizinan i ON i.santri_id = s.id SET s.is_aktif = 0 WHERE i.id = :id');
            $s->execute(['id' => $id]);

            $jenisIzinRaw = strtoupper((string) ($izinInfo['jenis_izin'] ?? ''));
            $jenisLabel = jenis_izin_label($jenisIzinRaw);
            push_event_izin_disetujui_wali(
                $pdo,
                (int) ($izinInfo['santri_id'] ?? 0),
                (string) ($izinInfo['nama_santri'] ?? '-'),
                $jenisLabel,
                $tglSelesai,
                $jamSelesai
            );
            if (in_array($jenisIzinRaw, ['PULANG', 'TUGAS'], true)) {
                $waliPhone = trim((string) ($izinInfo['no_wa_wali'] ?? ''));
                if ($waliPhone !== '' && push_should_send_wa($pdo)) {
                    $msg = wa_format_izin_disetujui_untuk_wali(
                        $pdo,
                        (string) ($izinInfo['nama_santri'] ?? '-'),
                        $jenisLabel,
                        $tglSelesai,
                        $jamSelesai,
                        (string) ($izinInfo['alasan'] ?? '-')
                    );
                    send_wa_message($pdo, $waliPhone, $msg);
                }
            }
            set_flash('success', 'Izin disetujui. QR digital aktif dan surat siap dicetak.');
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

    $defaultPengasuh = app_setting($pdo, 'nama_pengasuh', '');
    $graceMenit = (int) app_setting($pdo, 'grace_period_menit', '15');
    $jenisIzinPost = in_array(($_POST['jenis_izin'] ?? ''), ['SAKIT', 'KELUAR', 'TUGAS', 'PULANG'], true) ? $_POST['jenis_izin'] : 'KELUAR';
    $santriIdPost = (int) ($_POST['santri_id'] ?? 0);

    if ($santriIdPost > 0) {
        $chkAktif = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
        $chkAktif->execute(['id' => $santriIdPost]);
        if (!$chkAktif->fetchColumn()) {
            set_flash('error', 'Santri tidak aktif atau sudah keluar — tidak dapat diberi izin baru.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
    }

    if ($jenisIzinPost === 'SAKIT') {
        $gejalaCheck = trim((string) ($_POST['gejala'] ?? ''));
        $suhuRaw = $_POST['suhu_tubuh'] ?? '';
        if ($santriIdPost <= 0) {
            set_flash('error', 'Pilih santri untuk izin kesehatan.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
        if ($gejalaCheck === '') {
            set_flash('error', 'Izin kesehatan wajib melengkapi E-Health: isi gejala.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
        if ($suhuRaw === '' || !is_numeric($suhuRaw)) {
            set_flash('error', 'Izin kesehatan wajib melengkapi E-Health: isi suhu tubuh.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
    }

    $data = [
        'santri_id' => $santriIdPost,
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
        'tanggal_selesai' => $_POST['tanggal_selesai'] ?? date('Y-m-d'),
        'jam_mulai' => $_POST['jam_mulai'] ?? date('H:i'),
        'jam_selesai' => $_POST['jam_selesai'] ?? date('H:i'),
        'durasi_jam' => (float) ($_POST['durasi_jam'] ?? 0),
        'jenis_izin' => $jenisIzinPost,
        'alasan' => trim($_POST['alasan'] ?? ''),
        'pemberi_izin' => trim($_POST['pemberi_izin'] ?? ''),
        'penandatangan_pengasuh' => $defaultPengasuh !== '' ? $defaultPengasuh : trim($_POST['penandatangan_pengasuh'] ?? ''),
        'grace_menit' => $graceMenit,
    ];

    $insert = $pdo->prepare('
        INSERT INTO perizinan (santri_id, jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam, alasan, pemberi_izin, penandatangan_pengasuh, status_izin, approval_status, grace_menit)
        VALUES (:santri_id, :jenis_izin, :tanggal_mulai, :tanggal_selesai, :jam_mulai, :jam_selesai, :durasi_jam, :alasan, :pemberi_izin, :penandatangan_pengasuh, "IZIN", "PENDING", :grace_menit)
    ');
    $insHealth = $pdo->prepare('
        INSERT INTO ehealth_records (santri_id, gejala, suhu_tubuh, tindakan, status_kesehatan, notifikasi_wali, created_by)
        VALUES (:santri_id, :gejala, :suhu_tubuh, :tindakan, :status_kesehatan, :notifikasi_wali, :created_by)
    ');

    $pdo->beginTransaction();
    try {
        $insert->execute($data);
        if ($data['jenis_izin'] === 'SAKIT') {
            $insHealth->execute([
                'santri_id' => $data['santri_id'],
                'gejala' => trim((string) ($_POST['gejala'] ?? '')),
                'suhu_tubuh' => (float) $_POST['suhu_tubuh'],
                'tindakan' => trim((string) ($_POST['tindakan'] ?? '')),
                'status_kesehatan' => in_array(($_POST['status_kesehatan'] ?? ''), ['RAWAT_PONDOK', 'DIRUJUK_RS', 'ISOLASI', 'SELESAI'], true) ? $_POST['status_kesehatan'] : 'RAWAT_PONDOK',
                'notifikasi_wali' => isset($_POST['notifikasi_wali']) ? 1 : 0,
                'created_by' => (int) ($_SESSION['user']['id'] ?? 1),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', 'Gagal menyimpan: data tidak konsisten. Coba lagi.');
        header('Location: ' . app_href('/perizinan/index.php'));
        exit;
    }

    $sInfoStmt = $pdo->prepare('SELECT nama_santri, nis, tingkatan FROM santri WHERE id = :id LIMIT 1');
    $sInfoStmt->execute(['id' => $data['santri_id']]);
    $sInfoRow = $sInfoStmt->fetch() ?: ['nama_santri' => '-', 'nis' => '', 'tingkatan' => ''];
    $notifMsg = wa_format_pengajuan_izin_baru(
        $pdo,
        (string) ($sInfoRow['nama_santri'] ?? '-'),
        (string) ($sInfoRow['nis'] ?? ''),
        (string) ($sInfoRow['tingkatan'] ?? ''),
        (string) $data['jenis_izin'],
        (string) $data['tanggal_mulai'],
        (string) $data['tanggal_selesai'],
        substr((string) $data['jam_mulai'], 0, 5),
        substr((string) $data['jam_selesai'], 0, 5),
        (string) $data['alasan']
    );
    push_event_izin_pengajuan_baru(
        $pdo,
        (string) ($sInfoRow['nama_santri'] ?? '-'),
        (string) ($sInfoRow['nis'] ?? ''),
        (string) $data['jenis_izin'],
        (string) $data['tanggal_mulai'],
        (string) $data['tanggal_selesai']
    );
    if ($data['jenis_izin'] === 'SAKIT' && isset($_POST['notifikasi_wali'])) {
        push_event_laporan_sakit_wali(
            $pdo,
            (int) $data['santri_id'],
            (string) ($sInfoRow['nama_santri'] ?? '-'),
            trim((string) ($_POST['gejala'] ?? '')),
            (string) ($_POST['status_kesehatan'] ?? 'RAWAT_PONDOK')
        );
    }
    if (push_should_send_wa($pdo)) {
        send_wa_bulk($pdo, app_setting($pdo, 'wa_pengurus', ''), $notifMsg);
    }
    $okMsg = $data['jenis_izin'] === 'SAKIT'
        ? 'Pengajuan izin kesehatan dan laporan E-Health tersimpan (status: PENDING). Menunggu persetujuan.'
        : 'Pengajuan izin tersimpan (status: PENDING). Menunggu persetujuan.';
    set_flash('success', $okMsg);
    header('Location: ' . app_href('/perizinan/index.php'));
    exit;
}

$sqlAktifS = santri_sql_aktif_only('s');
require_once __DIR__ . '/../helpers/santri_list_sort.php';
santri_list_sort_mode($_GET['santri_sort'] ?? null);
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri s WHERE ' . $sqlAktifS . ' ORDER BY ' . santri_list_order_sql('s'))->fetchAll();
$rombonganSantriGrouped = perizinan_rombongan_santri_aktif_grouped($pdo);
$namaPengasuh = app_setting($pdo, 'nama_pengasuh', '');
$izinList = $pdo->query('
    SELECT i.id, i.rombongan_id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.status_izin, i.approval_status, i.alasan, i.rejected_reason, i.qr_token, i.waktu_keluar, i.waktu_kembali, i.poin_pelanggaran, s.nama_santri, s.nis
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifS . '
    ORDER BY COALESCE(i.rombongan_id, i.id) DESC, i.rombongan_id DESC, i.id DESC
')->fetchAll();
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
$izinPending = count(array_filter($izinList, static fn(array $r): bool => (string) ($r['approval_status'] ?? 'PENDING') === 'PENDING'));
$izinDisetujui = count(array_filter($izinList, static fn(array $r): bool => (string) ($r['approval_status'] ?? '') === 'DISETUJUI'));
$izinPerpanjanganMaxHari = max(1, (int) app_setting($pdo, 'izin_perpanjangan_max_hari', '7'));
$izinPerpanjanganJenisArr = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) app_setting($pdo, 'izin_perpanjangan_jenis', 'SAKIT,KELUAR'))))));

$pageTitle = 'Perizinan Santri';
$loadSantriSelectJs = true;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Perizinan</p>
    <h1 class="h4 mb-1">Perizinan &amp; E-Health santri</h1>
    <p class="text-muted mb-0">Tinjau permohonan izin yang masuk, setujui/tolak, kelola data izin dan E-Health di satu tempat.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total izin</div>
            <div class="app-mini-stat-value"><?= count($izinList) ?></div>
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
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Input Izin Santri</h1>
                <div class="mb-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-toggle-rombongan">
                        <i class="fa-solid fa-users me-1"></i> Izin rombongan
                    </button>
                </div>
                <form method="post" class="row g-2 d-none" id="form-izin-rombongan" data-rombongan-min="2" data-rombongan-target="rombongan-input">
                    <input type="hidden" name="action" value="create_rombongan">
                    <div class="col-12">
                        <label class="form-label">Pilih santri rombongan <span class="text-muted fw-normal">(min. 2)</span></label>
                        <?php
                        $rombonganPickerName = 'santri_ids_rombongan[]';
                        $rombonganPickerId = 'rombongan-input';
                        $rombonganPickerShowToolbar = true;
                        require __DIR__ . '/partials/rombongan_santri_picker.php';
                        ?>
                        <div class="form-text">Urutan tingkatan → NIS. Satu surat A4 &amp; satu QR saat kembali.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jenis Izin</label>
                        <select class="form-select" name="jenis_izin" required>
                            <option value="KELUAR">Keluar</option>
                            <option value="TUGAS">Tugas</option>
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
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan izin rombongan</button>
                        <button type="button" class="btn btn-outline-secondary" id="btn-batal-rombongan">Batal</button>
                    </div>
                </form>
                <form method="post" class="row g-2" id="form-izin-santri">
                    <input type="hidden" name="action" value="create_izin">
                    <div class="col-12">
                        <label class="form-label">Santri</label>
                        <select class="form-select santri-select-searchable" name="santri_id" id="santri-izin-select" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama_santri']) ?> (<?= htmlspecialchars($s['tingkatan']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jenis Izin</label>
                        <select class="form-select" name="jenis_izin" id="jenis-izin-input" required>
                            <option value="KELUAR">Keluar</option>
                            <option value="SAKIT">Sakit</option>
                            <option value="TUGAS">Tugas</option>
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
                    <div class="col-4">
                        <label class="form-label">Jam Mulai</label>
                        <input type="text" name="jam_mulai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Jam Selesai</label>
                        <input type="text" name="jam_selesai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Durasi (jam)</label>
                        <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control" placeholder="contoh 3.5">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pemberi Izin</label>
                        <input type="text" class="form-control" name="pemberi_izin" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pengasuh</label>
                        <input type="text" class="form-control" name="penandatangan_pengasuh" value="<?= htmlspecialchars($namaPengasuh) ?>" <?= $namaPengasuh !== '' ? 'readonly' : '' ?> required>
                    </div>

                    <div id="ehealth-input-card" class="col-12 mt-2 pt-3 border-top ehealth-block">
                        <h2 class="h6">Data kesehatan (E-Health)</h2>
                        <p class="small text-muted mb-2">
                            <strong>Wajib</strong> bila jenis izin <strong>Izin Kesehatan</strong>. Santri mengikuti pilihan di atas (satu santri yang sama).
                        </p>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Gejala <span class="text-danger ehealth-req-mark d-none">*</span></label>
                                <textarea class="form-control ehealth-field" name="gejala" id="ehealth-gejala" rows="2" placeholder="Gejala" autocomplete="off"></textarea>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Suhu tubuh (°C) <span class="text-danger ehealth-req-mark d-none">*</span></label>
                                <input type="number" step="0.1" class="form-control ehealth-field" name="suhu_tubuh" id="ehealth-suhu" placeholder="contoh 36.5" autocomplete="off">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Status kesehatan</label>
                                <select class="form-select ehealth-field" name="status_kesehatan" id="ehealth-status">
                                    <option value="RAWAT_PONDOK">Rawat Pondok</option>
                                    <option value="DIRUJUK_RS">Dirujuk RS</option>
                                    <option value="ISOLASI">Isolasi</option>
                                    <option value="SELESAI">Selesai</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Obat / tindakan</label>
                                <textarea class="form-control ehealth-field" name="tindakan" rows="2" placeholder="Obat/Tindakan (opsional)"></textarea>
                            </div>
                            <div class="col-12 form-check ms-1">
                                <input class="form-check-input ehealth-field" type="checkbox" name="notifikasi_wali" id="notifikasi_wali" value="1">
                                <label class="form-check-label" for="notifikasi_wali">Kirim notifikasi ke wali (flag)</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-success">Simpan Izin</button>
                    </div>
                </form>
                <?php
                $currentRole = (string) ($_SESSION['user']['role'] ?? '');
                $canScanIzin = is_super_admin()
                    || in_array($currentRole, ['admin', 'pengurus'], true)
                    || user_can_access_permission_key('perizinan_scan');
                ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <?php if ($canScanIzin): ?>
                        <a class="btn btn-outline-primary btn-sm" href="/perizinan/kembali.php">Scan Izin Keluar/Kembali</a>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary btn-sm" href="/perizinan/permohonan.php">Form Permohonan (Wali/Petugas)</a>
                    <a class="btn btn-outline-primary btn-sm" href="/perizinan/izin_tetap.php">Izin Tetap Hidmah</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if ($rombonganPending !== []): ?>
                <div class="alert alert-warning py-2 mb-3">
                    <strong>Izin rombongan menunggu persetujuan</strong>
                    <span class="d-block small fw-normal text-muted">Satu surat A4 berlaku untuk semua santri dalam rombongan yang sama.</span>
                    <ul class="mb-0 small ps-3">
                        <?php foreach ($rombonganPending as $rm): ?>
                            <li class="mt-1">
                                #<?= (int) $rm['id'] ?> — <?= (int) ($rm['jumlah_santri'] ?? 0) ?> santri · <?= htmlspecialchars(jenis_izin_label((string) ($rm['jenis_izin'] ?? ''))) ?>
                                <form method="post" class="d-inline ms-1">
                                    <input type="hidden" name="action" value="approve_rombongan">
                                    <input type="hidden" name="rombongan_id" value="<?= (int) $rm['id'] ?>">
                                    <input type="hidden" name="tanggal_mulai" value="<?= htmlspecialchars((string) ($rm['tanggal_mulai'] ?? '')) ?>">
                                    <input type="hidden" name="tanggal_selesai" value="<?= htmlspecialchars((string) ($rm['tanggal_selesai'] ?? '')) ?>">
                                    <input type="hidden" name="jam_mulai" value="<?= htmlspecialchars(substr((string) ($rm['jam_mulai'] ?? ''), 0, 5)) ?>">
                                    <input type="hidden" name="jam_selesai" value="<?= htmlspecialchars(substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)) ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Setujui</button>
                                    <a class="btn btn-sm btn-outline-dark" target="_blank" href="<?= htmlspecialchars(app_href('/perizinan/surat_rombongan.php?id=' . (int) $rm['id'])) ?>">Cetak A4</a>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <h2 class="h5">Daftar Izin</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Santri</th><th>Jenis</th><th>Tanggal/Jam</th><th>Persetujuan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
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
                                ?>
                                <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars(jenis_izin_label((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?></span>
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
                            </td>
                            <td><?= htmlspecialchars($i['status_izin']) ?></td>
                            <td class="text-end">
                                <?php if (($i['approval_status'] ?? 'PENDING') === 'PENDING'): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-success js-open-approve"
                                            data-bs-toggle="modal"
                                            data-bs-target="#approveIzinModal"
                                            data-izin-id="<?= (int) $i['id'] ?>"
                                            data-nama="<?= htmlspecialchars((string) $i['nama_santri']) ?> (<?= htmlspecialchars((string) $i['nis']) ?>)"
                                            data-jenis="<?= htmlspecialchars(jenis_izin_label((string) ($i['jenis_izin'] ?? 'KELUAR'))) ?>"
                                            data-alasan="<?= htmlspecialchars((string) ($i['alasan'] ?? '')) ?>"
                                            data-tgl-mulai="<?= htmlspecialchars((string) ($i['tanggal_mulai'] ?? '')) ?>"
                                            data-tgl-selesai="<?= htmlspecialchars((string) ($i['tanggal_selesai'] ?? '')) ?>"
                                            data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($i['jam_mulai'] ?? ''))) ?>"
                                            data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($i['jam_selesai'] ?? ''))) ?>"
                                            data-durasi="<?= htmlspecialchars((string) ($i['durasi_jam'] ?? '')) ?>">
                                        Setujui
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Tolak permohonan izin ini?');">
                                        <input type="hidden" name="action" value="reject_izin">
                                        <input type="hidden" name="izin_id" value="<?= (int) $i['id'] ?>">
                                        <input type="hidden" name="rejected_reason" value="Ditolak pengurus">
                                        <button class="btn btn-sm btn-outline-danger">Tolak</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (($i['approval_status'] ?? '') === 'DISETUJUI' && trim((string) ($i['qr_token'] ?? '')) !== ''): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#qrIzinModal<?= (int) $i['id'] ?>">QR Digital</button>
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
                                $canPrint = !($apStat === 'DITOLAK' || ($apStat === 'PENDING' && $apTok === ''));
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
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="approve_izin">
            <input type="hidden" name="izin_id" id="approve-izin-id" value="">
            <div class="modal-header">
                <h5 class="modal-title">Setujui &amp; atur jadwal izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 mb-3 small">
                    Atur ulang <strong>tanggal</strong>, <strong>jam</strong>, dan <strong>durasi</strong> bila perlu sebelum menyetujui. Setelah disetujui, QR digital aktif dan surat izin siap dicetak.
                </div>
                <div class="mb-3 small">
                    <div><strong>Santri:</strong> <span id="approve-santri-info">-</span></div>
                    <div><strong>Jenis izin:</strong> <span id="approve-jenis-info">-</span></div>
                    <div class="text-muted"><strong>Alasan:</strong> <span id="approve-alasan-info">-</span></div>
                </div>
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success">Setujui &amp; terbitkan QR</button>
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
                    <h5 class="modal-title">QR izin digital</h5>
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
                    <div class="small text-muted">Petugas gerbang scan QR ini saat santri keluar dan saat kembali.</div>
                    <div class="font-monospace small mt-2 text-break"><?= htmlspecialchars((string) $i['qr_token']) ?></div>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-outline-dark" target="_blank" href="/perizinan/surat.php?id=<?= (int) $i['id'] ?>">Cetak surat</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Selesai</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-rombongan-picker.js')) ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var btnR = document.getElementById('btn-toggle-rombongan');
    var btnBatal = document.getElementById('btn-batal-rombongan');
    var formR = document.getElementById('form-izin-rombongan');
    var formS = document.getElementById('form-izin-santri');
    function showRombongan(show) {
        if (!formR || !formS) return;
        formR.classList.toggle('d-none', !show);
        formS.classList.toggle('d-none', show);
    }
    if (btnR) btnR.addEventListener('click', function () { showRombongan(true); });
    if (btnBatal) btnBatal.addEventListener('click', function () { showRombongan(false); });
})();
(function () {
    var jenis = document.getElementById('jenis-izin-input');
    var panelInput = document.getElementById('ehealth-input-card');
    if (!jenis || !panelInput) {
        return;
    }
    function syncEhealthVisibility() {
        var sakit = jenis.value === 'SAKIT';
        panelInput.classList.toggle('d-none', !sakit);
        panelInput.querySelectorAll('.ehealth-req-mark').forEach(function (m) {
            m.classList.toggle('d-none', !sakit);
        });
        panelInput.querySelectorAll('.ehealth-field').forEach(function (el) {
            el.disabled = !sakit;
            if (el.name === 'gejala' || el.name === 'suhu_tubuh') {
                el.required = sakit;
            }
        });
        if (!sakit) {
            var g = document.getElementById('ehealth-gejala');
            var su = document.getElementById('ehealth-suhu');
            var ti = panelInput.querySelector('textarea[name="tindakan"]');
            if (g) {
                g.value = '';
            }
            if (su) {
                su.value = '';
            }
            if (ti) {
                ti.value = '';
            }
            var cb = document.getElementById('notifikasi_wali');
            if (cb) {
                cb.checked = false;
            }
        }
    }
    jenis.addEventListener('change', syncEhealthVisibility);
    syncEhealthVisibility();
})();

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
