<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/santri_izin_tetap.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';

require_roles(['admin', 'pengurus']);
ensure_alpa_tier_tables($pdo);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada. Jalankan schema_presensi.sql.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$kegiatanList = table_exists($pdo, 'kegiatan')
    ? $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE is_active = 1 ORDER BY nama_kegiatan ASC')->fetchAll()
    : [];

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $tingkatan = trim($_POST['tingkatan'] ?? '');
    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);

    if ($kegiatanId <= 0) {
        set_flash('error', 'Pilih kegiatan yang terikat jadwal. ALPA hanya untuk santri yang tingkatannya masuk jadwal kegiatan tersebut.');
        header('Location: ' . app_href('/presensi/alpha.php'));
        exit;
    }
    if (!presensi_tingkatan_terjadwal($pdo, $tingkatan, $kegiatanId, $tanggal)) {
        set_flash('error', 'Tingkatan "' . $tingkatan . '" tidak terdaftar di jadwal kegiatan ini pada tanggal ' . $tanggal . '. Tidak ada ALPA yang dibuat.');
        header('Location: ' . app_href('/presensi/alpha.php'));
        exit;
    }

    $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);

    $santriStmt = $pdo->prepare('SELECT id, nama_santri, nis, no_wa_wali FROM santri WHERE tingkatan = :tingkatan AND ' . santri_sql_aktif_only('santri'));
    $santriStmt->execute(['tingkatan' => $tingkatan]);
    $santriList = $santriStmt->fetchAll();

    $namaKegiatanLabel = 'Umum / tidak terikat kegiatan';
    if ($kegiatanId > 0) {
        foreach ($kegiatanList as $kRow) {
            if ((int) $kRow['id'] === $kegiatanId) {
                $namaKegiatanLabel = (string) $kRow['nama_kegiatan'];
                break;
            }
        }
    }
    $tsTgl = strtotime($tanggal) ?: time();
    $namaBulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tanggalIdn = (int) date('j', $tsTgl) . ' ' . ($namaBulanId[(int) date('n', $tsTgl)] ?? date('F', $tsTgl)) . ' ' . date('Y', $tsTgl);
    if (class_exists('IntlDateFormatter')) {
        $fmtTgl = new IntlDateFormatter('id_ID', IntlDateFormatter::LONG, IntlDateFormatter::NONE, date_default_timezone_get());
        $tanggalIdn = $fmtTgl->format($tsTgl) ?: $tanggalIdn;
    }

    $waLaporanSantri = [];

    $hadirStmt = $pdo->prepare('
        SELECT santri_id
        FROM presensi
        WHERE tanggal_presensi = :tanggal_presensi
          AND status_presensi IN ("HADIR","IZIN","SAKIT")
          AND (:kegiatan_id = 0 OR kegiatan_id = :kegiatan_id)
    ');
    $hadirStmt->execute([
        'tanggal_presensi' => $tanggal,
        'kegiatan_id' => $kegiatanId,
    ]);
    $hadirIds = array_map('intval', $hadirStmt->fetchAll(PDO::FETCH_COLUMN));

    $izinStmt = $pdo->prepare('
        SELECT santri_id
        FROM perizinan
        WHERE status_izin = "IZIN"
          AND :tanggal_now BETWEEN tanggal_mulai AND tanggal_selesai
    ');
    $izinStmt->execute(['tanggal_now' => $tanggal]);
    $izinIds = array_map('intval', $izinStmt->fetchAll(PDO::FETCH_COLUMN));

    $jamMulaiKeg = null;
    $jamSelesaiKeg = null;
    $jadwalKegiatanId = null;
    if ($kegiatanId > 0 && table_exists($pdo, 'jadwal_kegiatan')) {
        $hariKe = (int) date('N', strtotime($tanggal));
        $jadwalStmt = $pdo->prepare('
            SELECT id, jam_mulai, jam_selesai FROM jadwal_kegiatan
            WHERE kegiatan_id = :kid
              AND (hari_ke = 0 OR hari_ke = :hari)
              AND (tingkatan = :tingkatan OR tingkatan = "Semua Tingkatan")
            ORDER BY jam_mulai ASC LIMIT 1
        ');
        $jadwalStmt->execute(['kid' => $kegiatanId, 'hari' => $hariKe, 'tingkatan' => $tingkatan]);
        $jadwalRow = $jadwalStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($jadwalRow)) {
            $jadwalKegiatanId = (int) ($jadwalRow['id'] ?? 0) ?: null;
            $jamMulaiKeg = (string) ($jadwalRow['jam_mulai'] ?? null);
            $jamSelesaiKeg = (string) ($jadwalRow['jam_selesai'] ?? null);
        }
    }
    if ($jadwalKegiatanId === null) {
        set_flash('error', 'Tidak ada slot jadwal untuk tingkatan "' . $tingkatan . '" pada kegiatan dan tanggal tersebut.');
        header('Location: ' . app_href('/presensi/alpha.php'));
        exit;
    }
    ensure_presensi_jadwal_column($pdo);
    $santriIdsTingkatan = array_map(static fn(array $s): int => (int) ($s['id'] ?? 0), $santriList);
    $izinTetapMap = santri_izin_tetap_map_for_santri_ids($pdo, $santriIdsTingkatan, $tanggal, $jamMulaiKeg, $jamSelesaiKeg, $kegiatanId);
    $izinTetapIds = array_keys($izinTetapMap);

    $threshold = (int) app_setting($pdo, 'batas_alpa_notif', '3');
    $pengurusWa = wa_alpa_notif_target($pdo);
    $jamAutoWa = trim((string) app_setting($pdo, 'jam_kirim_wa_auto', ''));
    $canSendNow = $jamAutoWa === '' || date('H:i') >= $jamAutoWa;
    $created = 0;
    $newlyAlpaSantri = [];

    foreach ($santriList as $santri) {
        $santriId = (int) $santri['id'];
        if (in_array($santriId, $hadirIds, true) || in_array($santriId, $izinIds, true) || in_array($santriId, $izinTetapIds, true)) {
            continue;
        }

        $insert = $pdo->prepare('
            INSERT INTO presensi (santri_id, kegiatan_id, jadwal_kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by)
            VALUES (:santri_id, :kegiatan_id, :jadwal_kegiatan_id, :tanggal_presensi, :jam_presensi, "ALPA", :kalender_hijriyah, :created_by)
        ');
        $insert->execute([
            'santri_id' => $santriId,
            'kegiatan_id' => $kegiatanId > 0 ? $kegiatanId : null,
            'jadwal_kegiatan_id' => $jadwalKegiatanId,
            'tanggal_presensi' => $tanggal,
            'jam_presensi' => date('H:i:s'),
            'kalender_hijriyah' => $hijri,
            'created_by' => (int) ($_SESSION['user']['id'] ?? 1),
        ]);
        $created++;

        $newlyAlpaSantri[] = [
            'id' => $santriId,
            'nama_santri' => (string) ($santri['nama_santri'] ?? '-'),
            'nis' => (string) ($santri['nis'] ?? ''),
        ];

        $countStmt = $pdo->prepare('
            SELECT COUNT(*) FROM presensi
            WHERE santri_id = :santri_id
              AND status_presensi = "ALPA"
              AND DATE_FORMAT(tanggal_presensi, "%Y-%m") = DATE_FORMAT(:tanggal_now, "%Y-%m")
        ');
        $countStmt->execute([
            'santri_id' => $santriId,
            'tanggal_now' => $tanggal,
        ]);
        $alphaCount = (int) $countStmt->fetchColumn();

        if ($alphaCount >= $threshold) {
            $waLaporanSantri[] = [
                'nama_santri' => (string) ($santri['nama_santri'] ?? '-'),
                'nis' => (string) ($santri['nis'] ?? ''),
                'total_alpha' => $alphaCount,
            ];
        }
    }

    $tierSummary = ['tiers' => [], 'sent_total' => 0];
    $activeTiers = alpa_tier_list($pdo, true);
    if ($canSendNow && $newlyAlpaSantri !== [] && $activeTiers !== []) {
        $tierSummary = alpa_tier_dispatch_batch(
            $pdo,
            $newlyAlpaSantri,
            $tanggal,
            $tanggalIdn,
            $tingkatan,
            $namaKegiatanLabel
        );
    }

    if ($activeTiers === [] && $waLaporanSantri !== [] && $pengurusWa !== '' && $canSendNow) {
        require_once __DIR__ . '/../helpers/wa_laporan_alpa.php';
        $pesanLaporan = wa_format_laporan_alpa_generate_messages(
            $pdo,
            $tanggalIdn,
            $tingkatan,
            $namaKegiatanLabel,
            $threshold,
            $waLaporanSantri
        );
        send_wa_bulk_messages($pdo, $pengurusWa, $pesanLaporan);
    }

    $msg = 'Generate alpa selesai. Total tersimpan: ' . $created . '.';
    if (!empty($tierSummary['tiers'])) {
        $parts = [];
        foreach ($tierSummary['tiers'] as $row) {
            $parts[] = 'ambang ' . $row['threshold'] . ' (' . $row['santri_count'] . ' santri → ' . ((trim($row['label']) !== '') ? $row['label'] : 'tier') . ')';
        }
        $msg .= ' Notifikasi tier terkirim: ' . implode(', ', $parts) . '.';
    } elseif (!$canSendNow) {
        $msg .= ' (WA otomatis ditunda hingga jam ' . $jamAutoWa . ')';
    }
    set_flash('success', $msg);
    header('Location: ' . app_href('/presensi/alpha.php'));
    exit;
}

$pageTitle = 'Generate Alpa Otomatis';
$tierAktif = alpa_tier_list($pdo, true);
$tierMode = alpa_tier_periode_mode($pdo);
$tierTanggalMulai = alpa_tier_tanggal_mulai($pdo);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4">Generate Alpa Otomatis</h1>
        <p class="text-muted">Hanya santri <strong>aktif</strong> yang tingkatannya <strong>terdaftar di jadwal</strong> kegiatan terpilih. Santri di luar jadwal tidak dihitung ALPA meskipun tidak scan.
            Santri yang tidak hadir dan tidak izin pada tanggal/kegiatan ini akan dicatat sebagai ALPA.
            <?php if (user_can_hapus_presensi_admin()): ?>
                <a href="<?= htmlspecialchars(app_href('/presensi/bersihkan.php')) ?>">Bersihkan presensi tanpa kegiatan</a>
            <?php endif; ?>
        </p>

        <div class="alert alert-light border small mb-3">
            <?php if ($tierAktif): ?>
                <strong>Notifikasi alpa bertahap aktif</strong> (periode: <?= htmlspecialchars(alpa_tier_periode_label($tierMode)) ?><?= $tierTanggalMulai !== '' ? ', sejak ' . htmlspecialchars($tierTanggalMulai) : '' ?>). Tier:
                <?php
                $bits = [];
                foreach ($tierAktif as $t) {
                    $lbl = trim((string) $t['label']);
                    $bits[] = '≥ ' . (int) $t['threshold'] . ' → ' . ($lbl !== '' ? htmlspecialchars($lbl) : '<em>tanpa label</em>');
                }
                echo implode(' · ', $bits);
                ?>.
                <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=alpa')) ?>">Ubah pengaturan</a>.
            <?php else: ?>
                <strong>Notifikasi tier belum diatur.</strong> Sistem memakai mode lama (≥ <?= (int) app_setting($pdo, 'batas_alpa_notif', '3') ?> kali alpa → nomor di tab <strong>Alpa</strong> / Gateway).
                <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=alpa')) ?>">Aktifkan notifikasi alpa bertahap</a>.
            <?php endif; ?>
        </div>
        <form method="post" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Tanggal</label>
                <input type="date" class="form-control" name="tanggal" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tingkatan</label>
                <?php if ($tingkatanList): ?>
                    <select class="form-select" name="tingkatan" required>
                        <option value="">Pilih tingkatan</option>
                        <?php foreach ($tingkatanList as $tg): ?>
                            <option value="<?= htmlspecialchars($tg) ?>"><?= htmlspecialchars($tg) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control" name="tingkatan" placeholder="Contoh: SMP" required>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Kegiatan</label>
                <select name="kegiatan_id" class="form-select" required>
                    <option value="">— Pilih kegiatan —</option>
                    <?php foreach ($kegiatanList as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-danger w-100">Proses Alpa</button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
