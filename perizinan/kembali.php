<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_roles(['admin', 'pengurus']);
perizinan_rombongan_ensure_schema($pdo);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$message = null;
$type = 'success';
$redirectAfterPost = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['scan_source'] ?? '') !== 'camera') {
        $type = 'warning';
        $message = 'Input manual dinonaktifkan. Gunakan scan kamera.';
    } else {
        $code = trim((string) ($_POST['kode_qr'] ?? ''));
        $rombonganMeta = perizinan_rombongan_by_qr($pdo, $code);
        if ($rombonganMeta) {
            $rid = (int) ($rombonganMeta['id'] ?? 0);
            if (empty($rombonganMeta['waktu_keluar'])) {
                perizinan_rombongan_scan_checkout($pdo, $rid);
                $type = 'success';
                $message = 'Check-out rombongan tercatat. Saat kembali, scan lagi lalu centang santri yang sudah tiba.';
            } else {
                header('Location: ' . app_href('/perizinan/kembali_rombongan.php?id=' . $rid));
                exit;
            }
        } else {
        $izin = $pdo->prepare('
            SELECT i.id, i.tanggal_selesai, i.jam_selesai, i.waktu_keluar, i.grace_menit, i.santri_id, s.nama_santri
            FROM perizinan i
            INNER JOIN santri s ON s.id = i.santri_id
            WHERE i.qr_token = :qr_token
              AND i.status_izin = "IZIN"
              AND (i.approval_status = "DISETUJUI" OR i.approval_status IS NULL)
              AND (i.rombongan_id IS NULL OR i.rombongan_id = 0)
            ORDER BY i.id DESC
            LIMIT 1
        ');
        $izin->execute(['qr_token' => $code]);
        $activeIzin = $izin->fetch();
        if (!$activeIzin) {
            $type = 'warning';
            $message = 'QR verifikasi izin tidak valid atau izin sudah selesai.';
        } else {
            if (empty($activeIzin['waktu_keluar'])) {
                $out = $pdo->prepare('UPDATE perizinan SET waktu_keluar = NOW() WHERE id = :id');
                $out->execute(['id' => $activeIzin['id']]);
                $message = 'Check-out tercatat: ' . $activeIzin['nama_santri'];
            } else {
                $grace = isset($activeIzin['grace_menit']) ? (int) $activeIzin['grace_menit'] : (int) app_setting($pdo, 'grace_period_menit', '15');
                $latePoint = 0;
                $lateMinutes = 0;
                $batasTs = strtotime((string) $activeIzin['tanggal_selesai'] . ' ' . (string) $activeIzin['jam_selesai']);
                $nowTs = time();
                if ($batasTs !== false && $nowTs > $batasTs) {
                    $lateMinutes = (int) floor(($nowTs - $batasTs) / 60);
                    if ($lateMinutes > $grace) {
                        $latePoint = max(1, (int) app_setting($pdo, 'point_auto_telat', '1'));
                    }
                }
                $up = $pdo->prepare('UPDATE perizinan SET status_izin = "KEMBALI", waktu_kembali = NOW(), poin_pelanggaran = :poin WHERE id = :id');
                $up->execute(['id' => $activeIzin['id'], 'poin' => $latePoint]);

                $santriUp = $pdo->prepare('UPDATE santri SET is_aktif = 1 WHERE id = :id');
                $santriUp->execute(['id' => $activeIzin['santri_id']]);

                if ($latePoint > 0) {
                    ensure_point_tables($pdo);
                    $ledger = $pdo->prepare('
                        INSERT IGNORE INTO point_ledger
                        (santri_id, tanggal, jenis_perubahan, point_delta, sumber_data, reference_presensi_id, keterangan, created_by)
                        VALUES
                        (:santri_id, CURDATE(), "PLUS", :point_delta, "PERIZINAN_TELAT_AUTO", :reference_id, :keterangan, :created_by)
                    ');
                    $ledger->execute([
                        'santri_id' => (int) $activeIzin['santri_id'],
                        'point_delta' => $latePoint,
                        'reference_id' => (int) $activeIzin['id'],
                        'keterangan' => 'Auto poin dari keterlambatan kembali izin. Telat ' . $lateMinutes . ' menit (toleransi ' . $grace . ' menit).',
                        'created_by' => (int) ($_SESSION['user']['id'] ?? 1),
                    ]);
                }

                $message = 'Check-in selesai: ' . $activeIzin['nama_santri'] . ($latePoint > 0 ? ' (terlambat, poin pelanggaran +' . $latePoint . ')' : '');

                require_once __DIR__ . '/../helpers/perizinan_approval.php';
                perizinan_kirim_wa_pengurus_izin_selesai($pdo, (int) $activeIzin['id'], $lateMinutes, $latePoint);
            }
        }
        }
    }

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        offline_sync_json_response($type, $message ?: 'OK', [
            'redirect' => $redirectAfterPost,
        ]);
    }
}

$pageTitle = 'Scan Izin Keluar/Kembali';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Gerbang Pesantren</p>
    <h1 class="h4 mb-1">Scan izin keluar/kembali</h1>
    <p class="text-muted mb-0">Scan QR izin digital saat santri keluar. Scan QR yang sama saat santri kembali; jika terlambat, poin kedisiplinan otomatis tercatat.</p>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Kamera scan QR izin</h2>
        <p class="text-muted">Scan pertama mencatat <strong>check-out</strong>. Scan berikutnya mencatat <strong>check-in</strong>.</p>
        <?php if ($message): ?>
            <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="post" class="row g-2" id="form-kembali">
            <div class="col-md-8">
                <input type="text" class="form-control" id="kode_qr" name="kode_qr" placeholder="Menunggu hasil scan..." required readonly>
                <input type="hidden" name="scan_source" id="scan_source" value="">
            </div>
            <div class="col-md-4">
                <button class="btn btn-success w-100" disabled>Aktifkan via Scan</button>
            </div>
        </form>
        <hr>
        <h2 class="h6">Scan Kamera Realtime</h2>
        <label class="form-label small mb-1">Pilih Kamera</label>
        <select id="camera-select" class="form-select form-select-sm mb-2"></select>
        <div id="qr-reader-kembali" class="border rounded p-2 mb-2 bg-white"></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-start-camera">Mulai Kamera</button>
            <button type="button" class="btn btn-outline-info btn-sm" id="btn-switch-camera">Ganti Kamera</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-stop-camera">Stop Kamera</button>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script>
    (function () {
        const input = document.getElementById('kode_qr');
        const form = document.getElementById('form-kembali');
        const startBtn = document.getElementById('btn-start-camera');
        const switchBtn = document.getElementById('btn-switch-camera');
        const stopBtn = document.getElementById('btn-stop-camera');
        const cameraSelect = document.getElementById('camera-select');
        const qrReader = new Html5Qrcode('qr-reader-kembali');
        let scanning = false;
        let lastCode = '';
        let lastTime = 0;
        let selectedCameraId = null;
        let hitCount = 0;
        let cameraIds = [];
        let currentCamIndex = 0;

        function beepSuccess() {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, ctx.currentTime);
            gainNode.gain.setValueAtTime(0.001, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            oscillator.start();
            oscillator.stop(ctx.currentTime + 0.2);
        }

        async function startScan() {
            if (scanning) {
                return;
            }
            try {
                await qrReader.start(
                    selectedCameraId || { facingMode: 'environment' },
                    {
                        fps: 14,
                        qrbox: { width: 240, height: 240 },
                        aspectRatio: 1.0,
                        disableFlip: false,
                    },
                    onScanSuccess
                );
                scanning = true;
            } catch (err) {
                alert('Tidak bisa mengakses kamera: ' + err);
            }
        }

        async function stopScan() {
            if (!scanning) {
                return;
            }
            try {
                await qrReader.stop();
            } catch (err) {
                console.log(err);
            }
            scanning = false;
        }

        Html5Qrcode.getCameras().then(function (devices) {
            cameraSelect.innerHTML = '';
            if (!devices || devices.length === 0) {
                cameraSelect.innerHTML = '<option>Tidak ada kamera</option>';
                return;
            }
            cameraIds = devices.map(function (d) { return d.id; });
            devices.forEach(function (device, idx) {
                const option = document.createElement('option');
                option.value = device.id;
                option.textContent = device.label || ('Kamera ' + (idx + 1));
                cameraSelect.appendChild(option);
            });
            selectedCameraId = devices[0].id;
            const backCam = devices.find(function (d) {
                return /back|rear|environment/i.test(d.label || '');
            });
            if (backCam) {
                selectedCameraId = backCam.id;
                cameraSelect.value = backCam.id;
            }
            currentCamIndex = Math.max(cameraIds.indexOf(selectedCameraId), 0);
            startScan();
        }).catch(function () {
            cameraSelect.innerHTML = '<option>Gagal memuat kamera</option>';
        });

        cameraSelect.addEventListener('change', function () {
            selectedCameraId = cameraSelect.value;
            currentCamIndex = Math.max(cameraIds.indexOf(selectedCameraId), 0);
            if (scanning) {
                stopScan().then(startScan);
            }
        });

        function onScanSuccess(decodedText) {
            const now = Date.now();
            if (decodedText === lastCode) {
                hitCount += 1;
            } else {
                hitCount = 1;
                lastCode = decodedText;
            }
            if (hitCount < 2) {
                return;
            }
            if (decodedText === lastCode && now - lastTime < 3000) {
                return;
            }
            lastCode = decodedText;
            lastTime = now;
            beepSuccess();
            input.value = decodedText;
            document.getElementById('scan_source').value = 'camera';
            if (window.PondokOfflineSync && PondokOfflineSync.handleFormSubmit(form, { label: 'Izin: ' + decodedText })) {
                return;
            }
            form.submit();
        }

        startBtn.addEventListener('click', function () {
            startScan();
        });

        switchBtn.addEventListener('click', function () {
            if (!cameraIds.length) {
                return;
            }
            currentCamIndex = (currentCamIndex + 1) % cameraIds.length;
            selectedCameraId = cameraIds[currentCamIndex];
            cameraSelect.value = selectedCameraId;
            if (scanning) {
                stopScan().then(startScan);
            }
        });

        stopBtn.addEventListener('click', function () {
            stopScan();
        });

        window.addEventListener('beforeunload', function () {
            if (scanning) {
                qrReader.stop().catch(function () {});
            }
        });
    })();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
