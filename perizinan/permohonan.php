<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/perizinan_jenis.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);
perizinan_redirect_kiai_dari_permohonan();
perizinan_approval_ensure_schema($pdo);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada. Jalankan schema_presensi.sql terlebih dahulu.');
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
    $defaultPengasuh = (string) app_setting($pdo, 'nama_pengasuh', '');
    $graceMenit = (int) app_setting($pdo, 'grace_period_menit', '15');
    $jenisIzinPost = perizinan_jenis_izin_normalize((string) ($_POST['jenis_izin'] ?? 'KELUAR'));
    $santriIdPost = (int) ($_POST['santri_id'] ?? 0);
    $alasanPost = trim((string) ($_POST['alasan'] ?? ''));
    $pemberiIzinPost = trim((string) ($_POST['pemberi_izin'] ?? ''));

    if ($santriIdPost <= 0) {
        set_flash('error', 'Pilih santri yang akan diajukan izinnya.');
        header('Location: ' . app_href('/perizinan/permohonan.php'));
        exit;
    }
    if ($santriIdPost > 0) {
        $chkAktif = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
        $chkAktif->execute(['id' => $santriIdPost]);
        if (!$chkAktif->fetchColumn()) {
            set_flash('error', 'Santri tidak aktif atau sudah keluar — tidak dapat mengajukan izin.');
            header('Location: ' . app_href('/perizinan/permohonan.php'));
            exit;
        }
        $blokirPending = perizinan_cek_blokir_pengajuan_baru($pdo, $santriIdPost);
        if ($blokirPending !== null) {
            set_flash('error', $blokirPending);
            header('Location: ' . app_href('/perizinan/permohonan.php'));
            exit;
        }
    }

    if ($alasanPost === '') {
        set_flash('error', 'Alasan permohonan izin wajib diisi.');
        header('Location: ' . app_href('/perizinan/permohonan.php'));
        exit;
    }
    if ($pemberiIzinPost === '') {
        set_flash('error', 'Nama pemohon (wali / petugas yang mengajukan) wajib diisi.');
        header('Location: ' . app_href('/perizinan/permohonan.php'));
        exit;
    }

    if ($jenisIzinPost === 'SAKIT') {
        $gejalaCheck = trim((string) ($_POST['gejala'] ?? ''));
        $suhuRaw = $_POST['suhu_tubuh'] ?? '';
        if ($gejalaCheck === '') {
            set_flash('error', 'Untuk Izin Kesehatan, gejala wajib diisi.');
            header('Location: ' . app_href('/perizinan/permohonan.php'));
            exit;
        }
        if ($suhuRaw === '' || !is_numeric($suhuRaw)) {
            set_flash('error', 'Untuk Izin Kesehatan, suhu tubuh wajib diisi.');
            header('Location: ' . app_href('/perizinan/permohonan.php'));
            exit;
        }
    }

    $tujuanPost = perizinan_tujuan_normalize((string) ($_POST['tujuan'] ?? ''));
    $tujuanErr = perizinan_validasi_tujuan($jenisIzinPost, $tujuanPost);
    if ($tujuanErr !== null) {
        set_flash('error', $tujuanErr);
        header('Location: ' . app_href('/perizinan/permohonan.php'));
        exit;
    }

    $data = [
        'santri_id' => $santriIdPost,
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
        'tanggal_selesai' => $_POST['tanggal_selesai'] ?? date('Y-m-d'),
        'jam_mulai' => $_POST['jam_mulai'] ?? date('H:i'),
        'jam_selesai' => $_POST['jam_selesai'] ?? date('H:i'),
        'durasi_jam' => (float) ($_POST['durasi_jam'] ?? 0),
        'jenis_izin' => $jenisIzinPost,
        'alasan' => $alasanPost,
        'tujuan' => $tujuanPost !== '' ? $tujuanPost : null,
        'pemberi_izin' => $pemberiIzinPost,
        'penandatangan_pengasuh' => $defaultPengasuh !== '' ? $defaultPengasuh : trim((string) ($_POST['penandatangan_pengasuh'] ?? '')),
        'grace_menit' => $graceMenit,
        'pengajuan_sumber' => 'admin',
    ];

    perizinan_approval_ensure_schema($pdo);
    $insert = $pdo->prepare('
        INSERT INTO perizinan (santri_id, jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam, alasan, tujuan, pemberi_izin, penandatangan_pengasuh, status_izin, approval_status, grace_menit, pengajuan_sumber)
        VALUES (:santri_id, :jenis_izin, :tanggal_mulai, :tanggal_selesai, :jam_mulai, :jam_selesai, :durasi_jam, :alasan, :tujuan, :pemberi_izin, :penandatangan_pengasuh, "IZIN", "PENDING", :grace_menit, :pengajuan_sumber)
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
        $izinId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', 'Gagal menyimpan permohonan: data tidak konsisten. Coba lagi.');
        header('Location: ' . app_href('/perizinan/permohonan.php'));
        exit;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 1);
    $sInfoStmt = $pdo->prepare('SELECT nama_santri, nis, tingkatan FROM santri WHERE id = :id LIMIT 1');
    $sInfoStmt->execute(['id' => $data['santri_id']]);
    $sInfoRow = $sInfoStmt->fetch() ?: ['nama_santri' => '-', 'nis' => '', 'tingkatan' => ''];

    if ($data['jenis_izin'] === 'SAKIT' && isset($_POST['notifikasi_wali'])) {
        push_event_laporan_sakit_wali(
            $pdo,
            (int) $data['santri_id'],
            (string) ($sInfoRow['nama_santri'] ?? '-'),
            trim((string) ($_POST['gejala'] ?? '')),
            (string) ($_POST['status_kesehatan'] ?? 'RAWAT_PONDOK')
        );
    }

    $fin = perizinan_finalisasi_setelah_input($pdo, $izinId, $userId);
    if ($fin !== null) {
        set_flash($fin['ok'] ? 'success' : 'error', $fin['ok']
            ? ($fin['message'] ?? 'Izin aktif. Notifikasi terkirim.')
            : ($fin['message'] ?? 'Gagal mengaktifkan izin.'));
        header('Location: ' . app_rewrite_internal_url($fin['ok'] ? '/perizinan/surat.php?id=' . $izinId : '/perizinan/permohonan.php'));
        exit;
    }

    $waDetail = [
        'izin_id' => $izinId,
        'tingkatan' => (string) ($sInfoRow['tingkatan'] ?? ''),
        'jam_mulai' => substr((string) $data['jam_mulai'], 0, 5),
        'jam_selesai' => substr((string) $data['jam_selesai'], 0, 5),
        'alasan' => (string) $data['alasan'],
        'tujuan' => (string) ($data['tujuan'] ?? ''),
    ];
    perizinan_push_setelah_pengajuan(
        $pdo,
        (string) ($sInfoRow['nama_santri'] ?? '-'),
        (string) ($sInfoRow['nis'] ?? ''),
        (string) $data['jenis_izin'],
        (string) $data['tanggal_mulai'],
        (string) $data['tanggal_selesai'],
        $waDetail,
        'admin'
    );

    if (perizinan_memerlukan_persetujuan_pengasuh((string) $data['jenis_izin'])) {
        $flashOk = 'Permohonan izin syar\'i terkirim. Menunggu persetujuan pengasuh — setelah disetujui, pengurus tinggal cetak surat.';
    } else {
        $flashOk = 'Permohonan izin tersimpan.';
    }
    set_flash('success', $flashOk);
    header('Location: ' . app_href('/perizinan/permohonan.php'));
    exit;
}

$sqlAktifS = santri_sql_aktif_only('s');
santri_list_sort_mode($_GET['santri_sort'] ?? null);
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri s WHERE ' . $sqlAktifS . ' ORDER BY ' . santri_list_order_sql('s'))->fetchAll();
$santriIdsList = array_map(static fn(array $s): int => (int) ($s['id'] ?? 0), $santriList ?: []);
$pendingBySantri = perizinan_santri_pending_map($pdo, $santriIdsList);
$namaPengasuh = (string) app_setting($pdo, 'nama_pengasuh', '');

$myIzinList = $pdo->query('
    SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.alasan, i.approval_status, i.status_izin, i.rejected_reason, i.created_at, i.pemberi_izin, s.nama_santri, s.nis
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifS . '
    ORDER BY i.id DESC
    LIMIT 25
')->fetchAll();

$totalPermohonan = (int) $pdo->query('SELECT COUNT(*) FROM perizinan i INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifS)->fetchColumn();
$totalPending = (int) $pdo->query("SELECT COUNT(*) FROM perizinan i INNER JOIN santri s ON s.id = i.santri_id AND $sqlAktifS WHERE i.approval_status = 'PENDING'")->fetchColumn();
$totalDisetujui = (int) $pdo->query("SELECT COUNT(*) FROM perizinan i INNER JOIN santri s ON s.id = i.santri_id AND $sqlAktifS WHERE i.approval_status = 'DISETUJUI'")->fetchColumn();
$totalDitolak = (int) $pdo->query("SELECT COUNT(*) FROM perizinan i INNER JOIN santri s ON s.id = i.santri_id AND $sqlAktifS WHERE i.approval_status = 'DITOLAK'")->fetchColumn();

$pageTitle = 'Permohonan Izin';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pengajuan Izin Santri</p>
    <h1 class="h4 mb-1">Permohonan izin</h1>
    <p class="text-muted mb-0">Halaman ini dipakai wali santri atau pengurus jaga untuk mengajukan izin. Setelah diajukan, pengurus berwenang akan meninjaunya di menu <strong>Perizinan</strong>.</p>
</div>
<div class="poin-howto px-3 py-3 mb-4">
    <p class="small mb-0 text-muted">Silakan ajukan perizinan santri untuk persetujuan, menunggu konfirmasi dari pengurus.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total permohonan</div>
            <div class="app-mini-stat-value"><?= $totalPermohonan ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Menunggu</div>
            <div class="app-mini-stat-value text-warning"><?= $totalPending ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Disetujui</div>
            <div class="app-mini-stat-value text-success"><?= $totalDisetujui ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Ditolak</div>
            <div class="app-mini-stat-value text-danger"><?= $totalDitolak ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-2">Form permohonan izin</h2>
                <p class="small text-muted mb-3">Isi data santri dan keperluannya. Permohonan akan masuk antrian persetujuan pengurus.</p>
                <form method="post" class="row g-2" id="form-permohonan">
                    <div class="col-12 d-none" id="wrap-pending-blokir">
                        <div class="alert alert-warning small py-2 mb-0" id="pending-blokir-teks"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Santri</label>
                        <select class="form-select" name="santri_id" id="santri-permohonan-select" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['nama_santri']) ?> (<?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jenis izin</label>
                        <select class="form-select" name="jenis_izin" id="jenis-izin-permohonan" required>
                            <?php $selectedJenis = 'KELUAR'; $includeSakit = true; require __DIR__ . '/partials/jenis_izin_select_options.php'; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pemohon</label>
                        <input type="text" class="form-control" name="pemberi_izin" placeholder="Nama wali / petugas" required value="<?= htmlspecialchars((string) ($_SESSION['user']['nama'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control" value="<?= date('Y-m-d') ?>" required>
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
                        <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control" placeholder="3.5">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alasan</label>
                        <textarea class="form-control" name="alasan" rows="2" required placeholder="Contoh: menjenguk orang tua sakit"></textarea>
                    </div>
                    <?php
                    $tujuanWrapId = 'wrap-tujuan-permohonan';
                    $tujuanJenisSelectId = 'jenis-izin-permohonan';
                    $tujuanValue = '';
                    require __DIR__ . '/partials/tujuan_izin_field.php';
                    ?>
                    <input type="hidden" name="penandatangan_pengasuh" value="<?= htmlspecialchars($namaPengasuh) ?>">

                    <div id="ehealth-permohonan" class="col-12 mt-2 pt-3 border-top ehealth-block d-none">
                        <h2 class="h6 mb-1">Data kesehatan (E-Health)</h2>
                        <p class="small text-muted mb-2">Wajib diisi bila jenis izin <strong>Izin Kesehatan</strong>.</p>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Gejala <span class="text-danger ehealth-req-mark d-none">*</span></label>
                                <textarea class="form-control ehealth-field" name="gejala" rows="2" placeholder="Gejala" autocomplete="off"></textarea>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Suhu tubuh (°C) <span class="text-danger ehealth-req-mark d-none">*</span></label>
                                <input type="number" step="0.1" class="form-control ehealth-field" name="suhu_tubuh" placeholder="36.5" autocomplete="off">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Status kesehatan</label>
                                <select class="form-select ehealth-field" name="status_kesehatan">
                                    <option value="RAWAT_PONDOK">Rawat Pondok</option>
                                    <option value="DIRUJUK_RS">Dirujuk RS</option>
                                    <option value="ISOLASI">Isolasi</option>
                                    <option value="SELESAI">Selesai</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Obat / tindakan</label>
                                <textarea class="form-control ehealth-field" name="tindakan" rows="2" placeholder="Obat/tindakan (opsional)"></textarea>
                            </div>
                            <div class="col-12 form-check ms-1">
                                <input class="form-check-input ehealth-field" type="checkbox" name="notifikasi_wali" id="notifikasi_wali_p" value="1">
                                <label class="form-check-label" for="notifikasi_wali_p">Kirim notifikasi ke wali (flag)</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 mt-2">
                        <button type="submit" class="btn btn-success" id="btn-kirim-permohonan">Kirim permohonan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h5 mb-0">Riwayat permohonan terbaru</h2>
                    <a class="small text-muted" href="/perizinan/index.php">Buka panel persetujuan &rarr;</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal Ajukan</th>
                                <th>Santri</th>
                                <th>Jenis</th>
                                <th>Periode</th>
                                <th>Pemohon</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($myIzinList): ?>
                                <?php foreach ($myIzinList as $r): ?>
                                    <?php
                                    $jenis = (string) ($r['jenis_izin'] ?? 'KELUAR');
                                    $jb = 'secondary';
                                    if ($jenis === 'TUGAS' || $jenis === 'PULANG') { $jb = 'danger'; }
                                    elseif ($jenis === 'KELUAR') { $jb = 'primary'; }
                                    elseif ($jenis === 'SAKIT') { $jb = 'success'; }
                                    $status = (string) ($r['approval_status'] ?? 'PENDING');
                                    $sb = 'warning';
                                    if ($status === 'DISETUJUI') { $sb = 'success'; }
                                    elseif ($status === 'DITOLAK') { $sb = 'danger'; }
                                    ?>
                                    <tr>
                                        <td class="small text-muted text-nowrap"><?= htmlspecialchars((string) ($r['created_at'] ?? '-')) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?> <span class="text-muted small">(<?= htmlspecialchars((string) $r['nis']) ?>)</span></td>
                                        <td><span class="badge text-bg-<?= $jb ?>"><?= htmlspecialchars(jenis_izin_label($jenis)) ?></span></td>
                                        <td class="small">
                                            <?= htmlspecialchars(app_format_periode_izin_tabel(
                                                (string) $r['tanggal_mulai'],
                                                (string) $r['tanggal_selesai'],
                                                (string) ($r['jam_mulai'] ?? ''),
                                                (string) ($r['jam_selesai'] ?? '')
                                            )) ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars((string) ($r['pemberi_izin'] ?? '-')) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= $sb ?>"><?= htmlspecialchars($status) ?></span>
                                            <?php if ($status === 'DITOLAK' && trim((string) ($r['rejected_reason'] ?? '')) !== ''): ?>
                                                <div class="small text-muted mt-1"><?= htmlspecialchars((string) $r['rejected_reason']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">Belum ada permohonan izin tercatat.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var pendingMap = <?= json_encode(array_map(
        static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'message' => perizinan_pesan_blokir_pending($row) ?? '',
        ],
        $pendingBySantri
    ), JSON_UNESCAPED_UNICODE) ?>;
    var santriSelect = document.getElementById('santri-permohonan-select');
    var wrapPending = document.getElementById('wrap-pending-blokir');
    var txtPending = document.getElementById('pending-blokir-teks');
    var btnKirim = document.getElementById('btn-kirim-permohonan');

    function syncPendingBlokir() {
        if (!santriSelect || !wrapPending || !txtPending || !btnKirim) {
            return;
        }
        var sid = parseInt(santriSelect.value || '0', 10);
        var info = pendingMap[sid] || pendingMap[String(sid)] || null;
        if (info && info.message) {
            wrapPending.classList.remove('d-none');
            txtPending.textContent = info.message;
            btnKirim.disabled = true;
            return;
        }
        wrapPending.classList.add('d-none');
        txtPending.textContent = '';
        btnKirim.disabled = false;
    }

    if (santriSelect) {
        santriSelect.addEventListener('change', syncPendingBlokir);
        syncPendingBlokir();
    }
})();
</script>
<script>
(function () {
    var jenis = document.getElementById('jenis-izin-permohonan');
    var panel = document.getElementById('ehealth-permohonan');
    if (!jenis || !panel) {
        return;
    }
    function syncPermohonanEhealth() {
        var sakit = jenis.value === 'SAKIT';
        panel.classList.toggle('d-none', !sakit);
        panel.querySelectorAll('.ehealth-req-mark').forEach(function (m) {
            m.classList.toggle('d-none', !sakit);
        });
        panel.querySelectorAll('.ehealth-field').forEach(function (el) {
            el.disabled = !sakit;
            if (el.name === 'gejala' || el.name === 'suhu_tubuh') {
                el.required = sakit;
            }
        });
        if (!sakit) {
            panel.querySelectorAll('textarea, input[type="number"]').forEach(function (el) { el.value = ''; });
            var cb = document.getElementById('notifikasi_wali_p');
            if (cb) { cb.checked = false; }
        }
    }
    jenis.addEventListener('change', syncPermohonanEhealth);
    syncPermohonanEhealth();
})();
</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-tujuan-field.js')) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
