<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

ensure_santri_identity_columns($pdo);
yayasan_ensure_tables($pdo);

$today = date('Y-m-d');
$putra = 0;
$putri = 0;
if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'jenis_kelamin')) {
    $aktifSql = '';
    if (column_exists($pdo, 'santri', 'status_santri')) {
        $aktifSql = ' WHERE ' . santri_sql_aktif_only('santri');
    } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
        $aktifSql = ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $row = $pdo->query(
        'SELECT
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra,
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri
         FROM santri' . $aktifSql
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $putra = (int) ($row['putra'] ?? 0);
    $putri = (int) ($row['putri'] ?? 0);
}

$mukiminCount = mukimin_count($pdo);
$pengurusCount = 0;
$rapatBulanIni = 0;
if (table_exists($pdo, 'yayasan_pengurus')) {
    $pengurusCount = (int) $pdo->query('SELECT COUNT(*) FROM yayasan_pengurus')->fetchColumn();
}
if (table_exists($pdo, 'yayasan_rapat')) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM yayasan_rapat WHERE tanggal_rapat >= :awal AND tanggal_rapat <= :akhir'
    );
    $st->execute(['awal' => date('Y-m-01'), 'akhir' => date('Y-m-t')]);
    $rapatBulanIni = (int) $st->fetchColumn();
}

$izinAktifCount = 0;
if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
    $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND i.approval_status = "DISETUJUI"' : '';
    $cntStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . santri_sql_aktif_only('s') . '
         WHERE i.status_izin = "IZIN"
           AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
    );
    $cntStmt->execute(['today' => $today]);
    $izinAktifCount = (int) $cntStmt->fetchColumn();
}

$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$pageTitle = 'Executive Summary';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-4">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · Eksekutif</p>
    <h1 class="h3 mb-1">Executive Summary</h1>
    <p class="text-muted mb-0">Ringkasan strategis santri, perizinan, dan struktur yayasan — per <?= htmlspecialchars(date('d F Y')) ?>.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Santri putra</div>
                <div class="fs-3 fw-bold"><?= $putra ?></div>
                <div class="small text-muted">Aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Santri putri</div>
                <div class="fs-3 fw-bold"><?= $putri ?></div>
                <div class="small text-muted">Aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Mukimin</div>
                <div class="fs-3 fw-bold"><?= $mukiminCount ?></div>
                <div class="small text-muted">Non aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Sedang izin</div>
                <div class="fs-3 fw-bold"><?= $izinAktifCount ?></div>
                <div class="small text-muted">Hari ini</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Pengurus</div>
                <div class="fs-3 fw-bold"><?= $pengurusCount ?></div>
                <div class="small text-muted">Terdaftar</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Rapat</div>
                <div class="fs-3 fw-bold"><?= $rapatBulanIni ?></div>
                <div class="small text-muted">Bulan ini</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h6 text-uppercase text-muted fw-bold mb-3">Tautan cepat</h2>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars(app_href('/yayasan/pengurus.php')) ?>" class="btn btn-outline-primary btn-sm">Pengurus</a>
            <a href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>" class="btn btn-outline-primary btn-sm">Rapat</a>
            <a href="<?= htmlspecialchars(app_href('/yayasan/notulen.php')) ?>" class="btn btn-outline-primary btn-sm">Notulen</a>
            <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="btn btn-outline-secondary btn-sm">Dashboard utama</a>
            <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Keuangan</a>
            <a href="<?= htmlspecialchars(app_href('/rekap/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Rekap presensi</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
