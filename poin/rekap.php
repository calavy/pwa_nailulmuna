<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);
ensure_point_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_followup') {
        $month = max(1, min(12, (int) ($_POST['reroute_month'] ?? $_GET['month'] ?? date('m'))));
        $year = max(2020, min(2100, (int) ($_POST['reroute_year'] ?? $_GET['year'] ?? app_tahun_masehi_default($pdo))));
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $totalPoin = (int) ($_POST['total_poin'] ?? 0);
        $tindakan = trim((string) ($_POST['tindakan'] ?? ''));
        $durasi = trim((string) ($_POST['durasi_keterangan'] ?? ''));
        $keterangan = trim((string) ($_POST['keterangan'] ?? ''));
        $statusTindak = strtoupper(trim((string) ($_POST['status_tindak'] ?? 'BELUM')));
        if (!in_array($statusTindak, ['BELUM', 'PROSES', 'SELESAI'], true)) {
            $statusTindak = 'BELUM';
        }
        $buktiTindak = trim((string) ($_POST['bukti_tindak'] ?? ''));
        $handledByName = trim((string) ($_POST['handled_by_nama'] ?? (string) ($_SESSION['user']['nama'] ?? 'Pengurus')));
        $tanggalTindak = (string) ($_POST['tanggal_tindak'] ?? date('Y-m-d'));

        if ($santriId > 0 && $tindakan !== '' && $handledByName !== '') {
            $insert = $pdo->prepare('
                INSERT INTO point_followups
                (santri_id, periode_bulan, periode_tahun, total_poin, tindakan, durasi_keterangan, keterangan, status_tindak, bukti_tindak, handled_by_user_id, handled_by_nama, tanggal_tindak)
                VALUES
                (:santri_id, :periode_bulan, :periode_tahun, :total_poin, :tindakan, :durasi_keterangan, :keterangan, :status_tindak, :bukti_tindak, :handled_by_user_id, :handled_by_nama, :tanggal_tindak)
            ');
            $insert->execute([
                'santri_id' => $santriId,
                'periode_bulan' => $month,
                'periode_tahun' => $year,
                'total_poin' => $totalPoin,
                'tindakan' => $tindakan,
                'durasi_keterangan' => $durasi,
                'keterangan' => $keterangan,
                'status_tindak' => $statusTindak,
                'bukti_tindak' => $buktiTindak,
                'handled_by_user_id' => (int) ($_SESSION['user']['id'] ?? 0),
                'handled_by_nama' => $handledByName,
                'tanggal_tindak' => $tanggalTindak,
            ]);
            set_flash('success', 'Tindak lanjut sanksi berhasil dicatat.');
        } else {
            set_flash('error', 'Isi data tindak lanjut belum lengkap.');
        }

        $rerouteMode = strtolower(trim((string) ($_POST['reroute_mode'] ?? 'tingkatan')));
        if (!in_array($rerouteMode, ['tingkatan', 'santri'], true)) {
            $rerouteMode = 'tingkatan';
        }
        $rerouteTingkat = trim((string) ($_POST['reroute_tingkatan'] ?? ''));
        $rerouteSantriId = max(0, (int) ($_POST['reroute_santri_id'] ?? 0));

        $redirect = '/poin/rekap.php?month=' . $month . '&year=' . $year . '&mode=' . rawurlencode($rerouteMode);
        if ($rerouteTingkat !== '') {
            $redirect .= '&tingkatan=' . rawurlencode($rerouteTingkat);
        }
        if ($rerouteMode === 'santri' && $rerouteSantriId > 0) {
            $redirect .= '&santri_id=' . $rerouteSantriId;
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$month = max(1, min(12, (int) ($_GET['month'] ?? date('m'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? app_tahun_masehi_default($pdo))));
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'tingkatan')));
if (!in_array($mode, ['tingkatan', 'santri'], true)) {
    $mode = 'tingkatan';
}
$santriIdPick = max(0, (int) ($_GET['santri_id'] ?? 0));
if ($mode === 'tingkatan') {
    $santriIdPick = 0;
}
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

if ($mode === 'santri') {
    $tingkatan = '';
}

$santriPickerSource = $pdo->query('SELECT id, nis, nama_santri, tingkatan FROM santri ORDER BY nama_santri ASC')->fetchAll(PDO::FETCH_ASSOC);

$sanctionRows = $pdo->query('SELECT ambang_poin, tindakan FROM point_sanctions WHERE is_active = 1 ORDER BY ambang_poin ASC')->fetchAll();
$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];

$stmt = $pdo->prepare('
    SELECT
        s.id AS santri_id,
        s.nis,
        s.nama_santri,
        s.tingkatan,
        COALESCE(SUM(pl.point_delta), 0) AS total_poin
    FROM santri s
    LEFT JOIN point_ledger pl
        ON pl.santri_id = s.id
       AND pl.tanggal BETWEEN :start_date AND :end_date
    GROUP BY s.id, s.nis, s.nama_santri, s.tingkatan
    ORDER BY total_poin DESC, s.nama_santri ASC
');
$stmt->execute([
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$rows = $stmt->fetchAll();
if ($mode === 'tingkatan') {
    if ($tingkatan !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($tingkatan): bool {
            return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
        }));
    }
} elseif ($santriIdPick > 0) {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['santri_id'] ?? 0) === $santriIdPick));
} else {
    $rows = [];
}

$pickedSingle = ($mode === 'santri' && $santriIdPick > 0 && $rows !== []) ? $rows[0] : null;
$pickedLabel = '';
if ($mode === 'santri' && $santriIdPick > 0) {
    foreach ($santriPickerSource as $ps) {
        if ((int) $ps['id'] === $santriIdPick) {
            $pickedLabel = trim((string) ($ps['nama_santri'] ?? ''))
                . ' · NIS ' . trim((string) ($ps['nis'] ?? ''))
                . (trim((string) ($ps['tingkatan'] ?? '')) !== '' ? ' · ' . trim((string) ($ps['tingkatan'] ?? '')) : '');
            break;
        }
    }
}

$expandFollowSection = ($mode === 'santri' && $pickedSingle !== null) || ($mode === 'tingkatan');
$suppressDetailBlocks = ($mode === 'santri' && $pickedSingle === null);

$detailStmt = $pdo->prepare('
    SELECT
        pl.santri_id,
        COUNT(pl.id) AS jumlah_entri,
        SUM(CASE WHEN pl.jenis_perubahan = "PLUS" THEN pl.point_delta ELSE 0 END) AS subtotal_plus,
        SUM(CASE WHEN pl.jenis_perubahan = "MINUS" THEN pl.point_delta ELSE 0 END) AS subtotal_minus,
        SUBSTRING(GROUP_CONCAT(
            CONCAT(
                DATE_FORMAT(pl.tanggal, "%d/%m"),
                ": ",
                COALESCE(NULLIF(TRIM(pl.keterangan), ""), pl.sumber_data),
                " (",
                IF(pl.point_delta >= 0, CONCAT("+", pl.point_delta), pl.point_delta),
                ")"
            )
            ORDER BY pl.tanggal DESC, pl.id DESC
            SEPARATOR " • "
        ), 1, 520) AS ringkasan_keterangan
    FROM point_ledger pl
    WHERE pl.tanggal BETWEEN :start_detail AND :end_detail
    GROUP BY pl.santri_id
');
$detailStmt->execute([
    'start_detail' => $startDate,
    'end_detail' => $endDate,
]);
$ledgerDetailBySantri = [];
while ($det = $detailStmt->fetch(PDO::FETCH_ASSOC)) {
    $ledgerDetailBySantri[(int) $det['santri_id']] = [
        'jumlah_entri' => (int) $det['jumlah_entri'],
        'subtotal_plus' => (int) $det['subtotal_plus'],
        'subtotal_minus' => (int) $det['subtotal_minus'],
        'ringkasan_keterangan' => (string) ($det['ringkasan_keterangan'] ?? ''),
    ];
}
$ambangMin = poin_ambang_sanksi_minimum($pdo);
$latestFollowupStatus = poin_latest_followup_status_map($pdo, $month, $year);
$eligibleAll = array_values(array_filter($rows, static function (array $row) use ($ambangMin): bool {
    return (int) ($row['total_poin'] ?? 0) >= $ambangMin;
}));
$eligibleRows = array_values(array_filter($eligibleAll, static function (array $row) use ($latestFollowupStatus): bool {
    $sid = (int) ($row['santri_id'] ?? 0);

    return ($latestFollowupStatus[$sid] ?? '') !== 'SELESAI';
}));

$followupFollowParams = [
    'periode_bulan' => $month,
    'periode_tahun' => $year,
];
$followSqlWhere = '
    WHERE pf.periode_bulan = :periode_bulan
      AND pf.periode_tahun = :periode_tahun
';
if ($mode === 'santri' && $santriIdPick > 0) {
    $followSqlWhere .= ' AND pf.santri_id = :f_sid';
    $followupFollowParams['f_sid'] = $santriIdPick;
} elseif ($mode === 'tingkatan' && $tingkatan !== '') {
    $followSqlWhere .= ' AND LOWER(TRIM(COALESCE(s.tingkatan, \'\'))) = LOWER(TRIM(:f_tkat))';
    $followupFollowParams['f_tkat'] = $tingkatan;
}

$followupStmt = $pdo->prepare(
    '
    SELECT pf.*, s.nama_santri, s.nis, s.tingkatan
    FROM point_followups pf
    INNER JOIN santri s ON s.id = pf.santri_id
    ' . $followSqlWhere . '
    ORDER BY pf.id DESC
'
);
$followupStmt->execute($followupFollowParams);
$followups = $followupStmt->fetchAll();
$totalSantriRekap = count($rows);
$totalPoinAkumulasi = array_sum(array_map(static fn(array $r): int => (int) ($r['total_poin'] ?? 0), $rows));
$santriMencapaiAmbang = count($eligibleAll);
$santriPerluTindakan = count($eligibleRows);
$statusCounts = ['BELUM' => 0, 'PROSES' => 0, 'SELESAI' => 0];
foreach ($followups as $f) {
    $key = strtoupper((string) ($f['status_tindak'] ?? 'BELUM'));
    if (isset($statusCounts[$key])) {
        $statusCounts[$key]++;
    }
}

$logLimit = 160;
if ($mode === 'santri' && $santriIdPick > 0) {
    $logLimit = 800;
} elseif ($mode === 'tingkatan' && $tingkatan !== '') {
    $logLimit = 500;
}

$logSql = '
    SELECT pl.tanggal, pl.point_delta, pl.jenis_perubahan, pl.sumber_data, pl.keterangan, s.nama_santri, s.nis
    FROM point_ledger pl
    INNER JOIN santri s ON s.id = pl.santri_id
    WHERE pl.tanggal BETWEEN :start_date AND :end_date
';
$logExec = ['start_date' => $startDate, 'end_date' => $endDate];
if ($mode === 'santri' && $santriIdPick > 0) {
    $logSql .= ' AND pl.santri_id = :log_sid';
    $logExec['log_sid'] = $santriIdPick;
} elseif ($mode === 'tingkatan' && $tingkatan !== '') {
    $logSql .= ' AND LOWER(TRIM(COALESCE(s.tingkatan, \'\'))) = LOWER(TRIM(:log_tkat))';
    $logExec['log_tkat'] = $tingkatan;
}
$logSql .= ' ORDER BY pl.id DESC LIMIT ' . (int) $logLimit;
$logsStmt = $pdo->prepare($logSql);
$logsStmt->execute($logExec);
$logs = $logsStmt->fetchAll();

function resolve_sanction(array $sanctions, int $point): string
{
    $result = '-';
    foreach ($sanctions as $s) {
        if ($point >= (int) $s['ambang_poin']) {
            $result = (string) $s['tindakan'];
        }
    }
    return $result;
}

$namaBulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$periodeLabelIndo = ($namaBulanId[$month] ?? ('Bulan ' . $month)) . ' ' . $year;
$perluTindakanIdSet = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['santri_id'] ?? 0), $eligibleRows), true);

function poin_level_badge_class(int $total): string
{
    if ($total >= 75) {
        return 'danger';
    }
    if ($total >= 50) {
        return 'warning';
    }
    if ($total >= 25) {
        return 'warning';
    }
    return 'secondary';
}

/** @return array{0:string,1:string} badge class without text-bg-, label */
function poin_status_tindak_badge(?string $raw): array
{
    $s = strtoupper((string) ($raw ?? ''));
    if ($s === 'PROSES') {
        return ['warning', 'Sedang diproses'];
    }
    if ($s === 'SELESAI') {
        return ['success', 'Selesai'];
    }
    if ($s === 'BELUM') {
        return ['danger', 'Belum ditangani'];
    }

    return ['secondary', 'Belum ada penanganan'];
}

$pageTitle = 'Rekap Poin Kedisiplinan';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Rekap Poin</p>
    <h1 class="h4 mb-1">Rekap poin kedisiplinan</h1>
    <p class="text-muted mb-0">Periode: <span class="badge text-bg-light border fw-semibold"><?= htmlspecialchars($periodeLabelIndo) ?></span>
        <span class="badge border text-secondary fw-semibold ms-1"><?= $mode === 'tingkatan' ? 'Per tingkatan' : 'Per santri' ?></span>
        <?php if ($mode === 'tingkatan' && $tingkatan !== ''): ?>
            <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars($tingkatan) ?></span>
        <?php elseif ($mode === 'tingkatan' && $tingkatan === ''): ?>
            <span class="badge text-bg-secondary ms-1">Semua tingkatan</span>
        <?php elseif ($pickedLabel !== ''): ?>
            <span class="badge text-bg-primary ms-1"><?= htmlspecialchars($pickedLabel) ?></span>
        <?php elseif ($mode === 'santri'): ?>
            <span class="badge border border-warning text-warning ms-1">Belum memilih santri</span>
        <?php endif; ?>
    </p>
</div>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><?= $mode === 'tingkatan'
                ? ($tingkatan !== '' ? 'Santri tingkat dipilih' : 'Santri semua tingkat')
                : ($pickedSingle !== null ? 'Santri terpilih (1)' : 'Santri belum dipilih') ?></div>
            <div class="app-mini-stat-value"><?= $totalSantriRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label"><?= $mode === 'tingkatan' ? 'Σ poin santri yang tampil' : ($pickedSingle ? 'Total poin santri ini' : 'Σ poin —') ?></div>
            <div class="app-mini-stat-value"><?= $pickedSingle === null && $mode === 'santri' ? '—' : $totalPoinAkumulasi ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Perlu tindakan</div>
            <div class="app-mini-stat-value text-danger"><?= $santriPerluTindakan ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tindak selesai</div>
            <div class="app-mini-stat-value text-success"><?= (int) $statusCounts['SELESAI'] ?></div>
        </div>
    </div>
</div>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end" id="filter-rekap-poin">
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small fw-semibold mb-1">Bulan</label>
                <input type="number" min="1" max="12" class="form-control" name="month" id="month-filter" value="<?= $month ?>">
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small fw-semibold mb-1">Tahun</label>
                <input type="number" min="2020" max="2100" class="form-control" name="year" id="year-filter" value="<?= $year ?>">
            </div>
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label small fw-semibold mb-1">Rekap ditampilkan</label>
                <select name="mode" id="rekap-mode" class="form-select">
                    <option value="tingkatan" <?= $mode === 'tingkatan' ? 'selected' : '' ?>>Semua santri dalam tingkatan</option>
                    <option value="santri" <?= $mode === 'santri' ? 'selected' : '' ?>>Satu santri (pilih dari daftar)</option>
                </select>
            </div>
            <div class="col-12 col-md-4 col-xl-4 <?= $mode === 'tingkatan' ? '' : 'd-none' ?>" id="rekap-wrap-tingkatan">
                <label class="form-label small fw-semibold mb-1">Tingkatan</label>
                <select class="form-select" name="tingkatan" id="tingkatan-filter">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($tingkatanList as $tg): ?>
                        <option value="<?= htmlspecialchars($tg) ?>" <?= strtolower($tingkatan) === strtolower((string) $tg) ? 'selected' : '' ?>><?= htmlspecialchars($tg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-xl <?= $mode === 'santri' ? '' : 'd-none' ?>" id="rekap-wrap-santri-picker">
                <label class="form-label small fw-semibold mb-1">Cari nama / NIS lalu klik salah satu santri</label>
                <input type="hidden" name="santri_id" id="field-santri-id" value="<?= $pickedSingle !== null ? (int) ($pickedSingle['santri_id'] ?? $santriIdPick) : (int) $santriIdPick ?>">
                <div class="position-relative">
                    <input type="text" class="form-control" id="santri-picker-q" autocomplete="off" spellcheck="false" placeholder="<?= $pickedSingle ? htmlspecialchars($pickedLabel) : 'Ketik 2 huruf atau lebih…' ?>" value="<?= $pickedSingle === null ? '' : htmlspecialchars($pickedLabel) ?>">
                    <div id="santri-picker-list" class="poin-santri-picker-list list-group shadow-sm border rounded mt-1 d-none" role="listbox"></div>
                </div>
            </div>
            <div class="col-12 col-xl-auto">
                <button type="submit" class="btn btn-success px-4 w-100 mt-xl-0 mt-2" id="btn-terap-rekap"><?= $mode === 'santri' ? 'Tampilkan data santri' : 'Terapkan' ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($mode === 'tingkatan'): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-2">
            <h2 class="h5 mb-0">Ringkasan poin per santri <?= $tingkatan !== '' ? '— ' . htmlspecialchars($tingkatan) : '(semua tingkat)' ?></h2>
            <span class="badge text-bg-secondary"><?= count($rows) ?> santri</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="tabel-ringkas-tingkatan">
                <thead class="table-light"><tr><th>No</th><th>NIS</th><th>Nama</th><th>Tingkatan</th><th class="text-end">Total poin bulan ini</th></tr></thead>
                <tbody>
                <?php if ($rows): ?>
                    <?php $no = 0; foreach ($rows as $zr): ?>
                        <?php ++$no; ?>
                        <tr>
                            <td class="text-muted small"><?= $no ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars((string) $zr['nis']) ?></td>
                            <td><?= htmlspecialchars((string) $zr['nama_santri']) ?></td>
                            <td><?= htmlspecialchars((string) ($zr['tingkatan'] ?: '—')) ?></td>
                            <td class="text-end"><span class="badge text-bg-<?= htmlspecialchars(poin_level_badge_class((int) $zr['total_poin'])) ?>"><?= (int) $zr['total_poin'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted">Tidak ada santri dalam filter sekarang.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($mode === 'santri' && $pickedSingle === null): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body py-4 text-center text-muted">
        <p class="mb-0"><strong>Ketik beberapa huruf</strong> di kolom pencarian, lalu <strong>pilih santri pada daftar</strong> yang muncul. Setelah santri aktif terisi, tekan <em>Tampilkan data santri</em> untuk memuat riwayat poin dan penanganan.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!$suppressDetailBlocks): ?>
<div id="perlu-tindakan" class="card shadow-sm mb-4 poin-priority-panel">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1 text-danger">Prioritas: santri perlu ditangani</h2>
                <p class="small text-muted mb-0"><?= $santriPerluTindakan ?> santri · ambang aktif mulai <strong><?= (int) $ambangMin ?> poin</strong> · periode <?= htmlspecialchars($periodeLabelIndo) ?></p>
            </div>
            <?php if ($eligibleRows): ?>
                <span class="badge rounded-pill text-bg-danger fs-6 px-3 py-2">Segera proses</span>
            <?php elseif ($santriMencapaiAmbang === 0): ?>
                <span class="badge rounded-pill text-bg-secondary fs-6 px-3 py-2">Tidak ada yang mencapai ambang</span>
            <?php else: ?>
                <span class="badge rounded-pill text-bg-success fs-6 px-3 py-2">Semua sudah selesai</span>
            <?php endif; ?>
        </div>

        <?php if ($eligibleRows): ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($eligibleRows as $row): ?>
                    <?php
                    $total = (int) $row['total_poin'];
                    $sid = (int) $row['santri_id'];
                    [$stBadge, $stLabel] = poin_status_tindak_badge($latestFollowupStatus[$sid] ?? null);
                    $lvl = poin_level_badge_class($total);
                    ?>
                    <div class="card poin-tindakan-card shadow-sm mb-0">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-lg-4 poin-tindakan-meta pe-lg-4">
                                    <div class="d-flex align-items-start flex-wrap gap-2 mb-2">
                                        <span class="badge text-bg-<?= htmlspecialchars($lvl) ?> fs-6"><?= $total ?> poin</span>
                                        <span class="badge text-bg-<?= htmlspecialchars($stBadge) ?>"><?= htmlspecialchars($stLabel) ?></span>
                                    </div>
                                    <div class="fw-semibold fs-6 mb-1"><?= htmlspecialchars((string) $row['nama_santri']) ?></div>
                                    <div class="small text-muted mb-2">
                                        NIS <?= htmlspecialchars((string) $row['nis']) ?>
                                        · Tingkat <span class="text-body"><?= htmlspecialchars((string) ($row['tingkatan'] ?: '-')) ?></span>
                                    </div>
                                    <div class="small text-muted mb-1" style="max-height: 4.5rem; overflow: auto;">Sanksi berlaku: <span class="text-body"><?= htmlspecialchars(resolve_sanction($sanctionRows, $total)) ?></span></div>
                                    <?php
                                    $lidPri = $ledgerDetailBySantri[$sid] ?? null;
                                    if ($lidPri && ($lidPri['jumlah_entri'] ?? 0) > 0) {
                                        $rPri = htmlspecialchars($lidPri['ringkasan_keterangan'] ?? '');
                                        $snippet = sprintf(
                                            '%d catatan ledger · PLUS %d · MINUS %d',
                                            (int) $lidPri['jumlah_entri'],
                                            (int) $lidPri['subtotal_plus'],
                                            (int) $lidPri['subtotal_minus']
                                        );
                                        ?>
                                    <div class="small mb-0 poin-asal-angka px-2 py-2 rounded">
                                        <div class="text-muted fst-italic mb-1">Asal nominal poin (ringkas)</div>
                                        <div class="text-body mb-1"><?= htmlspecialchars($snippet) ?></div>
                                        <?php if ($rPri !== '') { ?>
                                            <div class="small text-muted" style="max-height: 3.75rem; overflow: auto;"><?= $rPri ?></div>
                                        <?php } ?>
                                    </div>
                                    <?php } ?>
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        <?php if ($total >= 75): ?>
                                            <a class="btn btn-outline-danger btn-sm" target="_blank" href="/poin/surat.php?santri_id=<?= $sid ?>&month=<?= $month ?>&year=<?= $year ?>&sp=SP2">Cetak SP2</a>
                                        <?php elseif ($total >= 50): ?>
                                            <a class="btn btn-outline-warning btn-sm" target="_blank" href="/poin/surat.php?santri_id=<?= $sid ?>&month=<?= $month ?>&year=<?= $year ?>&sp=SP1">Cetak SP1</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-lg-8">
                                    <div class="small fw-semibold text-muted mb-2">Catat tindak lanjut</div>
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="action" value="save_followup">
                                        <input type="hidden" name="santri_id" value="<?= $sid ?>">
                                    <input type="hidden" name="reroute_month" value="<?= (int) $month ?>">
                                    <input type="hidden" name="reroute_year" value="<?= (int) $year ?>">
                                    <input type="hidden" name="reroute_mode" value="<?= htmlspecialchars($mode) ?>">
                                    <input type="hidden" name="reroute_tingkatan" value="<?= htmlspecialchars($tingkatan) ?>">
                                    <input type="hidden" name="reroute_santri_id" value="<?= (int) $santriIdPick ?>">
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Jenis tindakan</label>
                                            <?php $suggestedTindakan = resolve_sanction($sanctionRows, $total); ?>
                                            <select class="form-select form-select-sm" name="tindakan" required>
                                                <option value="">Pilih tindakan</option>
                                                <?php foreach ($sanctionRows as $sanRow): ?>
                                                    <?php $tind = trim((string) ($sanRow['tindakan'] ?? '')); if ($tind === '') { continue; } ?>
                                                    <option value="<?= htmlspecialchars($tind) ?>" <?= $suggestedTindakan === $tind ? 'selected' : '' ?>><?= htmlspecialchars($tind) ?> (≥<?= (int) $sanRow['ambang_poin'] ?> poin)</option>
                                                <?php endforeach; ?>
                                                <?php if ($suggestedTindakan !== '-' && $suggestedTindakan !== ''): ?>
                                                    <?php
                                                    $foundInList = false;
                                                    foreach ($sanctionRows as $sanRow) {
                                                        if (trim((string) ($sanRow['tindakan'] ?? '')) === $suggestedTindakan) {
                                                            $foundInList = true;
                                                            break;
                                                        }
                                                    }
                                                    if (!$foundInList):
                                                    ?>
                                                        <option value="<?= htmlspecialchars($suggestedTindakan) ?>" selected><?= htmlspecialchars($suggestedTindakan) ?> (disarankan)</option>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Durasi / keterangan</label>
                                            <input type="text" class="form-control form-control-sm" name="durasi_keterangan" placeholder="Contoh: 2 jam / 2 juz">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Penanggung jawab</label>
                                            <input type="text" class="form-control form-control-sm" name="handled_by_nama" value="<?= htmlspecialchars((string) ($_SESSION['user']['nama'] ?? 'Pengurus')) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Tanggal tindakan</label>
                                            <input type="date" class="form-control form-control-sm" name="tanggal_tindak" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Status penanganan</label>
                                            <select class="form-select form-select-sm" name="status_tindak" required>
                                                <option value="BELUM">Belum</option>
                                                <option value="PROSES">Proses</option>
                                                <option value="SELESAI">Selesai</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-0">Catatan (opsional)</label>
                                            <input type="text" class="form-control form-control-sm" name="keterangan" placeholder="Catatan singkat">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small mb-0">Bukti / verifikasi (opsional)</label>
                                            <textarea class="form-control form-control-sm" name="bukti_tindak" rows="2" placeholder="Contoh: sudah dicek oleh…"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary btn-sm">Simpan ke riwayat</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($santriMencapaiAmbang === 0): ?>
            <div class="alert alert-light border mb-0">
                <strong>Belum ada yang mencapai ambang.</strong> Pada periode ini belum ada santri (sesuai filter) dengan total poin ≥ <?= (int) $ambangMin ?>.
            </div>
        <?php else: ?>
            <div class="alert alert-success border-0 mb-0">
                <strong>Semua santri di ambang sudah ditangani.</strong> Total <?= $santriMencapaiAmbang ?> santri mencapai ambang; status tindak lanjut terakhir masing-masing sudah <em>Selesai</em>.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Ringkasan status tindak lanjut (semua entri)</h2>
        <p class="small text-muted mb-3">Diagram ini menghitung <strong>setiap baris</strong> riwayat penanganan, bukan jumlah santri unik.</p>
        <div class="mx-auto" style="max-width: 280px;">
            <canvas id="chart-status-tindak" height="72"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($pickedSingle !== null): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-2">
            <h2 class="h5 mb-0">Rincian poin &amp; jurnal santri ini</h2>
            <span class="badge text-bg-secondary">1 santri</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="tabel-rekap-santri">
                <thead class="table-light">
                <tr>
                    <th scope="col">NIS</th>
                    <th scope="col">Nama</th>
                    <th scope="col">Tingkatan</th>
                    <th scope="col">Total poin</th>
                    <th scope="col">Rincian angka</th>
                    <th scope="col">Ringkasan keterangan ledger</th>
                    <th scope="col">Sanksi berlaku</th>
                    <th scope="col">Surat</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $total = (int) $row['total_poin'];
                    $rid = (int) $row['santri_id'];
                    $urgent = isset($perluTindakanIdSet[$rid]);
                    $lvl = poin_level_badge_class($total);
                    $lidTbl = $ledgerDetailBySantri[$rid] ?? null;
                    $je = (int) ($lidTbl['jumlah_entri'] ?? 0);
                    $sp = (int) ($lidTbl['subtotal_plus'] ?? 0);
                    $sm = (int) ($lidTbl['subtotal_minus'] ?? 0);
                    $ketRaw = trim((string) ($lidTbl['ringkasan_keterangan'] ?? ''));
                    ?>
                    <tr class="<?= $urgent ? 'poin-row-urgent' : '' ?>">
                        <td class="font-monospace small"><?= htmlspecialchars((string) $row['nis']) ?></td>
                        <td>
                            <?= htmlspecialchars((string) $row['nama_santri']) ?>
                            <?php if ($urgent): ?><span class="badge text-bg-danger ms-1 align-middle">perlu tindakan</span><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($row['tingkatan'] ?: '-')) ?></td>
                        <td>
                            <span class="badge text-bg-<?= htmlspecialchars($lvl) ?>"><?= $total ?></span>
                            <?php if ($total === 0): ?>
                                <div class="small text-muted lh-sm mt-1">Belum ada poin di bulan ini.</div>
                            <?php endif; ?>
                        </td>
                        <td class="small poin-detail-angka-cell">
                            <?php if ($je === 0): ?>
                                <span class="text-muted">Tidak ada entri ledger</span>
                            <?php else: ?>
                                <div><strong><?= $je ?></strong> catatan ledger</div>
                                <div class="text-muted">Komponen: <strong class="text-body">+<?= $sp ?></strong> (PLUS) dan <strong class="text-body"><?= $sm ?></strong> (MINUS).</div>
                            <?php endif; ?>
                        </td>
                        <td class="small poin-keterangan-ringkas" title="<?= $ketRaw !== '' ? htmlspecialchars($ketRaw, ENT_QUOTES, 'UTF-8') : '' ?>">
                            <?php if ($ketRaw !== ''): ?>
                                <?= nl2br(htmlspecialchars($ketRaw, ENT_QUOTES, 'UTF-8'), false) ?>
                            <?php else: ?>
                                <span class="text-muted">Tidak ada teks pada entri ledger</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="small"><?= htmlspecialchars(resolve_sanction($sanctionRows, $total)) ?></span></td>
                        <td class="text-nowrap">
                            <?php if ($total >= 75): ?>
                                <a class="btn btn-outline-danger btn-sm" target="_blank" href="/poin/surat.php?santri_id=<?= $rid ?>&month=<?= $month ?>&year=<?= $year ?>&sp=SP2">SP2</a>
                            <?php elseif ($total >= 50): ?>
                                <a class="btn btn-outline-warning btn-sm" target="_blank" href="/poin/surat.php?santri_id=<?= $rid ?>&month=<?= $month ?>&year=<?= $year ?>&sp=SP1">SP1</a>
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

<?php if (!$suppressDetailBlocks): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0">Riwayat Penanganan Sanksi (<?= sprintf('%02d/%04d', $month, $year) ?>)</h2>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#riwayat-penanganan-collapse">Tampilkan/Sembunyikan</button>
        </div>
        <div class="collapse <?= $expandFollowSection ? 'show' : '' ?>" id="riwayat-penanganan-collapse">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="tabel-riwayat-penanganan">
                <thead><tr><th>Tanggal</th><th>Santri</th><th>Total Poin</th><th>Tindakan</th><th>Durasi/Ket.</th><th>Status</th><th>Pengurus Penangan</th><th>Bukti</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($followups as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $f['tanggal_tindak']) ?></td>
                            <td><?= htmlspecialchars((string) $f['nama_santri']) ?> (<?= htmlspecialchars((string) ($f['tingkatan'] ?: '-')) ?>)</td>
                            <td><?= (int) $f['total_poin'] ?></td>
                            <td><?= htmlspecialchars((string) $f['tindakan']) ?></td>
                            <td><?= htmlspecialchars((string) ($f['durasi_keterangan'] ?? '-')) ?></td>
                            <td>
                                <?php $status = strtoupper((string) ($f['status_tindak'] ?? 'BELUM')); ?>
                                <?php
                                $badgeClass = 'secondary';
                                if ($status === 'BELUM') { $badgeClass = 'danger'; }
                                if ($status === 'PROSES') { $badgeClass = 'warning'; }
                                if ($status === 'SELESAI') { $badgeClass = 'success'; }
                                ?>
                                <span class="badge text-bg-<?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                            </td>
                            <td><?= htmlspecialchars((string) $f['handled_by_nama']) ?></td>
                            <td><?= htmlspecialchars((string) ($f['bukti_tindak'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($f['keterangan'] ?? '-')) ?></td>
                        </tr>
                <?php endforeach; ?>
                <?php if (!$followups): ?>
                    <tr class="poin-riwayat-empty"><td colspan="9" class="text-center text-muted">Belum ada riwayat penanganan sanksi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0">Log Poin Periode</h2>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#log-poin-collapse">Tampilkan/Sembunyikan</button>
        </div>
        <div class="collapse <?= $expandFollowSection ? 'show' : '' ?>" id="log-poin-collapse">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="tabel-log-poin">
                <thead><tr><th>Tanggal</th><th>Santri</th><th>Jenis</th><th>Poin</th><th>Sumber</th><th>Keterangan</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                        <tr>
                        <td><?= htmlspecialchars($log['tanggal']) ?></td>
                        <td><?= htmlspecialchars($log['nama_santri']) ?></td>
                        <td><?= htmlspecialchars($log['jenis_perubahan']) ?></td>
                        <td><?= (int) $log['point_delta'] ?></td>
                        <td><?= htmlspecialchars($log['sumber_data']) ?></td>
                        <td><?= htmlspecialchars((string) $log['keterangan']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr class="poin-log-empty"><td colspan="6" class="text-center text-muted">Belum ada entri log poin pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (function () {
        var form = document.getElementById('filter-rekap-poin');
        if (!form) return;

        var POIN_LIST = <?= json_encode($santriPickerSource, JSON_UNESCAPED_UNICODE) ?> || [];
        var modeSel = document.getElementById('rekap-mode');
        var wrapTk = document.getElementById('rekap-wrap-tingkatan');
        var wrapPk = document.getElementById('rekap-wrap-santri-picker');
        var hidSid = document.getElementById('field-santri-id');
        var qPk = document.getElementById('santri-picker-q');
        var listPk = document.getElementById('santri-picker-list');
        var minLen = 2;
        var maxOpts = 50;

        function syncPanelsForMode() {
            if (!modeSel || !wrapTk || !wrapPk || !hidSid) return;
            if (modeSel.value === 'tingkatan') {
                wrapTk.classList.remove('d-none');
                wrapPk.classList.add('d-none');
                hidSid.value = '0';
                if (qPk) qPk.value = '';
            } else {
                wrapTk.classList.add('d-none');
                wrapPk.classList.remove('d-none');
                hidSid.value = '0';
                if (qPk) qPk.value = '';
            }
            if (listPk) {
                listPk.innerHTML = '';
                listPk.classList.add('d-none');
            }
        }

        ['month-filter', 'year-filter', 'tingkatan-filter'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function () {
                try { form.submit(); } catch (e) {}
            });
        });

        if (modeSel) {
            modeSel.addEventListener('change', function () {
                syncPanelsForMode();
                try { form.submit(); } catch (e2) {}
            });
        }

        if (modeSel) {
            if (modeSel.value === 'tingkatan') {
                wrapTk && wrapTk.classList.remove('d-none');
                wrapPk && wrapPk.classList.add('d-none');
            } else {
                wrapTk && wrapTk.classList.add('d-none');
                wrapPk && wrapPk.classList.remove('d-none');
            }
        }

        form.addEventListener('submit', function (e) {
            if (!modeSel) return;
            if (modeSel.value === 'santri') {
                var sid = parseInt(String(hidSid && hidSid.value ? hidSid.value : '0'), 10) || 0;
                if (sid <= 0) {
                    e.preventDefault();
                    window.alert('Pilih santri dari daftar saran yang muncul setelah Anda mengetik beberapa huruf.');
                    if (qPk) qPk.focus();

                    return;
                }
            }
        });

        function norm(s) {
            return ('' + s).toLowerCase().trim();
        }

        function renderPicker(q) {
            if (!listPk || !Array.isArray(POIN_LIST)) return;
            listPk.innerHTML = '';
            var n = norm(q);
            if (n.length < minLen) {
                listPk.classList.add('d-none');

                return;
            }
            var c = 0;
            POIN_LIST.forEach(function (s) {
                if (c >= maxOpts) return;
                var nama = '' + (s.nama_santri || '');
                var ni = '' + (s.nis || '');
                var hay = norm(nama + ' ' + ni);
                if (hay.indexOf(n) === -1) return;
                var a = document.createElement('button');
                a.type = 'button';
                a.className = 'list-group-item list-group-item-action py-2 small';
                a.textContent = nama + ' — NIS ' + ni + (s.tingkatan ? ' (' + s.tingkatan + ')' : '');
                a.dataset.sid = String(s.id);
                a.dataset.label = a.textContent;
                listPk.appendChild(a);
                c++;
            });
            if (c === 0) {
                var empty = document.createElement('div');
                empty.className = 'list-group-item text-muted small py-2';
                empty.textContent = 'Tidak ada nama atau NIS yang cocok.';
                listPk.appendChild(empty);
            }
            listPk.classList.remove('d-none');
        }

        function hidePicker() {
            if (listPk) {
                listPk.classList.add('d-none');
            }
        }

        if (listPk) {
            listPk.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
            listPk.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-sid]');
                if (!btn || !hidSid || !qPk) return;
                hidSid.value = btn.getAttribute('data-sid');
                qPk.value = btn.getAttribute('data-label');
                hidePicker();
            });
        }

        if (qPk && listPk && hidSid) {
            qPk.addEventListener('input', function () {
                if (norm(qPk.value) === '') {
                    hidSid.value = '0';
                }
                renderPicker(qPk.value);
            });
            qPk.addEventListener('focus', function () {
                renderPicker(qPk.value);
            });
            document.addEventListener('click', function (ev) {
                var wrap = document.getElementById('rekap-wrap-santri-picker');
                if (!wrap || !listPk || listPk.classList.contains('d-none')) return;
                if (!wrap.contains(ev.target)) hidePicker();
            });
        }

        var canvas = document.getElementById('chart-status-tindak');
        if (canvas && typeof Chart !== 'undefined') {
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: ['Belum', 'Proses', 'Selesai'],
                    datasets: [{
                        data: [<?= (int) $statusCounts['BELUM'] ?>, <?= (int) $statusCounts['PROSES'] ?>, <?= (int) $statusCounts['SELESAI'] ?>],
                        backgroundColor: ['#ef4444', '#f59e0b', '#22c55e']
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } }, cutout: '65%' }
            });
        }
    })();
</script>
