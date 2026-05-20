<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/presensi_scan_jadwal.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada. Jalankan schema_presensi.sql di phpMyAdmin.');
    header('Location: /pwa_nailulmuna/dashboard.php');
    exit;
}

$resultMessage = null;
$resultType = 'success';
$today = date('Y-m-d');
$nowTime = date('H:i:s');
$createdBy = (int) ($_SESSION['user']['id'] ?? 1);

if (table_exists($pdo, 'pembimbing')) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_pembimbing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            kegiatan_id INT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            jenis_scan ENUM("DATANG","PULANG") NOT NULL DEFAULT "DATANG",
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
        )
    ');
    try { $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL AFTER pembimbing_id'); } catch (PDOException $e) {}
    ensure_jadwal_kegiatan_tempat($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['scan_source'] ?? '') !== 'camera') {
        $resultType = 'warning';
        $resultMessage = 'Input manual dinonaktifkan. Silakan gunakan scan kamera.';
    } else {
    $code = trim($_POST['kode_qr'] ?? '');
    if ($code !== '') {
        $find = $pdo->prepare('SELECT * FROM santri WHERE qr = :code OR nis = :code LIMIT 1');
        $find->execute(['code' => $code]);
        $santri = $find->fetch();

        $pembimbing = null;
        if (!$santri && table_exists($pdo, 'pembimbing')) {
            $findP = $pdo->prepare('SELECT id, nama_pembimbing FROM pembimbing WHERE qr = :code OR nip = :code LIMIT 1');
            $findP->execute(['code' => $code]);
            $pembimbing = $findP->fetch() ?: null;
        }

        if (!$santri && !$pembimbing) {
            $resultType = 'warning';
            $resultMessage = 'Peringatan: kode QR tidak terdaftar (santri maupun pembimbing).';
        } elseif ($santri) {
            $chkAktif = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
            $chkAktif->execute(['id' => (int) $santri['id']]);
            if (!$chkAktif->fetchColumn()) {
                $resultType = 'warning';
                $resultMessage = 'Santri tidak aktif atau sudah keluar — presensi tidak dicatat.';
                goto end_scan_process;
            }
            $tanggal = date('Y-m-d');
            ensure_akademik_libur_table($pdo);
            $liburP = akademik_libur_info($pdo, $tanggal, 'presensi');
            if ($liburP !== null && akademik_blokir_presensi_libur($pdo)) {
                $resultType = 'warning';
                $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — presensi tidak dicatat.';
                goto end_scan_process;
            }
            $jam = date('H:i:s');
            $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);
            $kegiatan = activity_for_tingkatan($pdo, (string) ($santri['tingkatan'] ?? ''), $tanggal, $jam);
            if (!$kegiatan) {
                $resultType = 'warning';
                $resultMessage = 'Peringatan: scan di luar jadwal aktif untuk tingkatan ' . ($santri['tingkatan'] ?: '-') . '.';
                goto end_scan_process;
            }
            $kegiatanId = isset($kegiatan['id']) ? (int) $kegiatan['id'] : null;
            $lateThreshold = (int) app_setting($pdo, 'batas_telat_menit', '15');
            $catatan = null;
            if ($kegiatan && $lateThreshold > 0 && isset($kegiatan['jam_mulai']) && $kegiatan['jam_mulai'] !== null) {
                $start = DateTime::createFromFormat('H:i:s', $kegiatan['jam_mulai']);
                $current = DateTime::createFromFormat('H:i:s', $jam);
                if ($start && $current) {
                    $thresholdTime = (clone $start)->modify('+' . $lateThreshold . ' minutes');
                    if ($current > $thresholdTime) {
                        $diff = $current->getTimestamp() - $thresholdTime->getTimestamp();
                        $catatan = 'Terlambat ' . ceil($diff / 60) . ' menit';
                    }
                }
            }

            $existingStmt = $pdo->prepare('
                SELECT id, status_presensi
                FROM presensi
                WHERE santri_id = :santri_id
                  AND tanggal_presensi = :tanggal_presensi
                  AND (
                        (:kegiatan_id IS NULL AND kegiatan_id IS NULL)
                        OR kegiatan_id = :kegiatan_id
                  )
                ORDER BY id DESC
                LIMIT 1
            ');
            $existingStmt->execute([
                'santri_id' => (int) $santri['id'],
                'tanggal_presensi' => $tanggal,
                'kegiatan_id' => $kegiatanId,
            ]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                $existingStatus = strtoupper((string) ($existing['status_presensi'] ?? ''));
                if ($existingStatus === 'HADIR') {
                    $resultType = 'warning';
                    $resultMessage = 'Presensi sudah tercatat untuk kegiatan aktif ini: ' . $santri['nama_santri'] . '.';
                    goto end_scan_process;
                }
                $update = $pdo->prepare('
                    UPDATE presensi
                    SET jam_presensi = :jam_presensi, status_presensi = "HADIR", kalender_hijriyah = :kalender_hijriyah, created_by = :created_by, catatan = :catatan
                    WHERE id = :id
                ');
                $update->execute([
                    'id' => (int) $existing['id'],
                    'jam_presensi' => $jam,
                    'kalender_hijriyah' => $hijri,
                    'created_by' => $createdBy,
                    'catatan' => $catatan,
                ]);
            } else {
                $insert = $pdo->prepare('
                    INSERT INTO presensi (santri_id, kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by, catatan)
                    VALUES (:santri_id, :kegiatan_id, :tanggal_presensi, :jam_presensi, :status_presensi, :kalender_hijriyah, :created_by, :catatan)
                ');
                $insert->execute([
                    'santri_id' => (int) $santri['id'],
                    'kegiatan_id' => $kegiatanId,
                    'tanggal_presensi' => $tanggal,
                    'jam_presensi' => $jam,
                    'status_presensi' => 'HADIR',
                    'kalender_hijriyah' => $hijri,
                    'created_by' => $createdBy,
                    'catatan' => $catatan,
                ]);
            }

            $resultType = 'success';
            $resultMessage = 'Santri hadir: ' . $santri['nama_santri'] . ' (' . ($santri['tingkatan'] ?: '-') . ').';
            $namaKeg = (string) ($kegiatan['nama_kegiatan'] ?? '');
            $tempatKeg = trim((string) ($kegiatan['tempat'] ?? ''));
            if ($namaKeg !== '') {
                $resultMessage .= ' Kegiatan: ' . $namaKeg;
            }
            if ($tempatKeg !== '') {
                $resultMessage .= ' — Tempat: ' . $tempatKeg;
            }
        } else {
            $tanggal = date('Y-m-d');
            $jam = date('H:i:s');
            $hariKe = (int) date('N');
            $jadwalAktifStmt = $pdo->prepare('
                SELECT j.kegiatan_id, k.nama_kegiatan, j.tempat
                FROM jadwal_kegiatan j
                INNER JOIN kegiatan k ON k.id = j.kegiatan_id
                WHERE j.pembimbing_id = :pembimbing_id
                  AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
                  AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
                  AND k.is_active = 1
                ORDER BY j.jam_mulai ASC
                LIMIT 1
            ');
            $jadwalAktifStmt->execute([
                'pembimbing_id' => (int) $pembimbing['id'],
                'hari_ke' => $hariKe,
                'jam_now' => $jam,
            ]);
            $jadwalAktif = $jadwalAktifStmt->fetch();
            if (!$jadwalAktif) {
                $resultType = 'warning';
                $resultMessage = 'Tidak ada kegiatan aktif untuk pembimbing "' . $pembimbing['nama_pembimbing'] . '" pada jam sekarang.';
                goto end_scan_process;
            }
            $check = $pdo->prepare('
                SELECT id
                FROM presensi_pembimbing
                WHERE pembimbing_id = :id
                  AND kegiatan_id = :kegiatan_id
                  AND tanggal = :tgl
                LIMIT 1
            ');
            $check->execute([
                'id' => (int) $pembimbing['id'],
                'kegiatan_id' => (int) $jadwalAktif['kegiatan_id'],
                'tgl' => $tanggal,
            ]);
            $existsThisKegiatan = $check->fetch();
            if ($existsThisKegiatan) {
                $resultType = 'warning';
                $resultMessage = 'Presensi pembimbing "' . $pembimbing['nama_pembimbing'] . '" sudah tercatat untuk kegiatan "' . (string) $jadwalAktif['nama_kegiatan'] . '".';
                goto end_scan_process;
            }
            $ins = $pdo->prepare('
                INSERT INTO presensi_pembimbing (pembimbing_id, kegiatan_id, tanggal, jam, jenis_scan, created_by)
                VALUES (:id, :kegiatan_id, :tgl, :jam, "DATANG", :by)
            ');
            $ins->execute([
                'id' => (int) $pembimbing['id'],
                'kegiatan_id' => (int) $jadwalAktif['kegiatan_id'],
                'tgl' => $tanggal,
                'jam' => $jam,
                'by' => $createdBy,
            ]);
            $resultType = 'success';
            $resultMessage = 'Pembimbing hadir: ' . $pembimbing['nama_pembimbing'] . ' — Kegiatan ' . (string) $jadwalAktif['nama_kegiatan'];
            $tempat = trim((string) ($jadwalAktif['tempat'] ?? ''));
            if ($tempat !== '') {
                $resultMessage .= ' (Tempat: ' . $tempat . ')';
            }
        }
    }
    }
}
end_scan_process:

$todayDate = date('Y-m-d');
$todayRowsStmt = $pdo->prepare('
    SELECT p.jam_presensi AS jam, p.status_presensi AS status, s.nama_santri AS nama, s.tingkatan AS info, "Santri" AS jenis
    FROM presensi p
    INNER JOIN santri s ON s.id = p.santri_id AND ' . santri_sql_aktif_only('s') . '
    WHERE p.tanggal_presensi = :tanggal
    ORDER BY p.id DESC
    LIMIT 20
');
$todayRowsStmt->execute(['tanggal' => $todayDate]);
$todaySantri = $todayRowsStmt->fetchAll();

$todayPembimbing = [];
if (table_exists($pdo, 'presensi_pembimbing')) {
    $todayRowsP = $pdo->prepare('
        SELECT pp.jam AS jam, "HADIR" AS status, b.nama_pembimbing AS nama, COALESCE(k.nama_kegiatan, "-") AS info, "Pembimbing" AS jenis
        FROM presensi_pembimbing pp
        INNER JOIN pembimbing b ON b.id = pp.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = pp.kegiatan_id
        WHERE pp.tanggal = :tanggal
        ORDER BY pp.id DESC
        LIMIT 20
    ');
    $todayRowsP->execute(['tanggal' => $todayDate]);
    $todayPembimbing = $todayRowsP->fetchAll();
}

$todayRows = array_merge($todaySantri, $todayPembimbing);
usort($todayRows, static function ($a, $b): int {
    return strcmp((string) ($b['jam'] ?? ''), (string) ($a['jam'] ?? ''));
});
$todayRows = array_slice($todayRows, 0, 30);

$pageTitle = 'Scan Presensi';
$bodyClass = 'scan-simple-page';
$pageStylesheets = ['/pwa_nailulmuna/assets/css/presensi-scan.css'];
$isPetugasAbsensi = (string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi';
$todayScanCount = count($todayRows);
$scanJadwalCtx = presensi_scan_jadwal_context($pdo);
$timerState = (string) ($scanJadwalCtx['state'] ?? 'none');
$timerClass = in_array($timerState, ['active', 'upcoming', 'ended', 'libur', 'none'], true) ? $timerState : 'none';
$timerSec = $timerState === 'active'
    ? (int) ($scanJadwalCtx['seconds_remaining'] ?? 0)
    : ($timerState === 'upcoming' ? (int) ($scanJadwalCtx['seconds_until_start'] ?? 0) : 0);
$timerClockInit = sprintf('%02d:%02d', (int) floor($timerSec / 60), $timerSec % 60);
require_once __DIR__ . '/../includes/header.php';
?>


<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <?php if ($isPetugasAbsensi): ?>
                <a href="/pwa_nailulmuna/logout.php"><i class="fa-solid fa-right-from-bracket me-1"></i> Keluar</a>
            <?php else: ?>
                <a href="/pwa_nailulmuna/dashboard.php"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a>
            <?php endif; ?>
        </div>
        <strong class="small">Scan Presensi</strong>
        <span id="scan-status-badge" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </header>

    <p class="presensi-scan-hint mb-0">
        Arahkan QR ke kotak hijau · otomatis tercatat
        <?php if ($todayScanCount > 0): ?>
            <span class="text-white-50"> · hari ini <?= (int) $todayScanCount ?>+</span>
        <?php endif; ?>
    </p>

    <div id="presensi-scan-timer" class="presensi-scan-timer is-<?= htmlspecialchars($timerClass) ?>" aria-live="polite">
        <div class="presensi-scan-timer-inner">
            <span id="presensi-scan-timer-title" class="presensi-scan-timer-title"><?php
                if ($timerState === 'active') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: 'Kegiatan aktif'));
                } elseif ($timerState === 'upcoming') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: 'Menunggu jadwal'));
                } elseif ($timerState === 'libur') {
                    echo 'Hari libur';
                } elseif ($timerState === 'ended') {
                    echo 'Di luar jadwal';
                } else {
                    echo 'Belum ada jadwal';
                }
            ?></span>
            <span id="presensi-scan-timer-range" class="presensi-scan-timer-range"><?php
                if (!empty($scanJadwalCtx['jam_mulai']) && !empty($scanJadwalCtx['jam_selesai'])) {
                    echo htmlspecialchars(substr((string) $scanJadwalCtx['jam_mulai'], 0, 5) . ' – ' . substr((string) $scanJadwalCtx['jam_selesai'], 0, 5));
                    if (!empty($scanJadwalCtx['tingkatan'])) {
                        echo ' · ' . htmlspecialchars((string) $scanJadwalCtx['tingkatan']);
                    }
                }
            ?></span>
            <span id="presensi-scan-timer-clock" class="presensi-scan-timer-clock"><?= htmlspecialchars($timerClockInit) ?></span>
            <span id="presensi-scan-timer-hint" class="presensi-scan-timer-hint"><?php
                if ($timerState === 'active') {
                    echo 'Sisa waktu scan';
                } elseif ($timerState === 'upcoming') {
                    echo 'Scan dibuka dalam';
                } elseif ($timerState === 'libur') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['libur_nama'] ?: 'Presensi tidak dicatat'));
                } elseif ($timerState === 'ended') {
                    echo 'Tidak ada sesi scan aktif';
                } else {
                    echo 'Atur jadwal di menu Jadwal';
                }
            ?></span>
        </div>
    </div>
    <script type="application/json" id="presensi-scan-timer-data"><?= json_encode($scanJadwalCtx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <form method="post" id="form-scan-presensi" class="visually-hidden">
        <input type="text" id="kode_qr" name="kode_qr" required readonly>
        <input type="hidden" name="scan_source" id="scan_source" value="camera">
    </form>

    <div class="presensi-scan-viewport">
        <div id="qr-reader" aria-label="Kamera scan QR"></div>
        <div class="presensi-scan-frame" aria-hidden="true">
            <div class="presensi-scan-frame-box"></div>
        </div>
        <div id="camera-error-panel" class="presensi-scan-error d-none" role="alert">
            <div>
                <p class="fw-semibold mb-2" id="camera-error-text">Gagal membuka kamera</p>
                <p class="small opacity-75 mb-3">Izinkan akses kamera saat browser meminta, atau buka pengaturan situs → Kamera → Izinkan.</p>
                <button type="button" class="btn btn-light btn-sm" id="btn-retry-camera">Coba lagi</button>
            </div>
        </div>
    </div>

    <div id="presensi-scan-settings" class="presensi-scan-settings">
        <label class="form-label mb-1" for="camera-select">Pilih kamera</label>
        <select id="camera-select" class="form-select form-select-sm" aria-label="Pilih kamera"></select>
    </div>

    <div class="presensi-scan-controls">
        <button type="button" class="btn-scan-ctl" id="btn-flip-camera" title="Ganti kamera depan/belakang">
            <i class="fa-solid fa-camera-rotate"></i>
            <span>Ganti kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-scan-settings" title="Pilih kamera">
            <i class="fa-solid fa-sliders"></i>
            <span>Kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-torch" title="Lampu (jika didukung)" style="display:none">
            <i class="fa-solid fa-lightbulb"></i>
            <span>Lampu</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-restart-camera" title="Nyalakan ulang kamera">
            <i class="fa-solid fa-rotate-right"></i>
            <span>Ulangi</span>
        </button>
    </div>
</div>

<?php if ($resultMessage): ?>
<div id="presensi-scan-result" class="visually-hidden" data-type="<?= htmlspecialchars($resultType) ?>" aria-hidden="true">
    <span class="presensi-scan-result-text"><?= htmlspecialchars($resultMessage) ?></span>
</div>
<?php endif; ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-timer.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var form = document.getElementById('form-scan-presensi');
    var input = document.getElementById('kode_qr');
    var scanner = new PresensiScanCamera({
        readerId: 'qr-reader',
        statusEl: document.getElementById('scan-status-badge'),
        errorPanel: document.getElementById('camera-error-panel'),
        errorText: document.getElementById('camera-error-text'),
        cameraSelect: document.getElementById('camera-select'),
        settingsPanel: document.getElementById('presensi-scan-settings'),
        btnFlip: document.getElementById('btn-flip-camera'),
        btnRestart: document.getElementById('btn-restart-camera'),
        btnSettings: document.getElementById('btn-scan-settings'),
        btnRetry: document.getElementById('btn-retry-camera'),
        btnTorch: document.getElementById('btn-torch'),
        onSubmit: function (code) {
            input.value = code;
            document.getElementById('scan_source').value = 'camera';
            form.submit();
        },
    });

    scanner.init().then(function () {
        var torchBtn = document.getElementById('btn-torch');
        if (!torchBtn) return;
        setTimeout(function () {
            try {
                var video = document.querySelector('#qr-reader video');
                if (!video || !video.srcObject) return;
                var track = video.srcObject.getVideoTracks()[0];
                var caps = track && track.getCapabilities ? track.getCapabilities() : {};
                if (caps.torch) {
                    torchBtn.style.display = '';
                }
            } catch (e) { /* abaikan */ }
        }, 800);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
