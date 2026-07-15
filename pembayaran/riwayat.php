<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';
require_once __DIR__ . '/../helpers/keuangan_cek_pembayaran.php';

require_roles(['admin', 'pengurus']);
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
keuangan_ensure_schema_deferred($pdo);
pembayaran_edit_token_ensure_schema($pdo);
$canKoreksiPembayaran = user_can_koreksi_pembayaran();
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'redeem_token'
    && $canKoreksiPembayaran
) {
    $redeemResult = pembayaran_edit_token_redeem($pdo, $currentUserId, (string) ($_POST['token_plain'] ?? ''));
    if ($redeemResult['ok']) {
        set_flash('success', $redeemResult['message']);
    } else {
        set_flash('error', $redeemResult['message']);
    }
    $redirectQs = [];
    foreach (['dari', 'sampai', 'jenis', 'santri_id', 'metode', 'pos', 'limit', 'q', 'semua_periode'] as $qk) {
        if (isset($_POST[$qk]) && (string) $_POST[$qk] !== '') {
            $redirectQs[$qk] = (string) $_POST[$qk];
        }
    }
    $redirectUrl = app_url('pembayaran/riwayat.php');
    if ($redirectQs !== []) {
        $redirectUrl .= '?' . http_build_query($redirectQs);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$tokenEditRequired = pembayaran_edit_token_required_for_current_user();
$tokenEditSessionAktif = pembayaran_edit_token_session_aktif($pdo);
$tokenEditUnlocked = $canKoreksiPembayaran && (!$tokenEditRequired || $tokenEditSessionAktif);

$tanggalDari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$tanggalSampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
$jenis = strtoupper(trim((string) ($_GET['jenis'] ?? '')));
if (!in_array($jenis, ['', 'BULANAN', 'AWAL_TAHUN'], true)) {
    $jenis = '';
}
$santriId = (int) ($_GET['santri_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$semuaPeriode = (string) ($_GET['semua_periode'] ?? '') === '1';
$metode = strtoupper(trim((string) ($_GET['metode'] ?? '')));
if (!in_array($metode, ['', 'KAS', 'TRANSFER'], true)) {
    $metode = '';
}
$posSlug = trim((string) ($_GET['pos'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 500);
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 2000) {
    $limit = 2000;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalDari)) {
    $tanggalDari = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSampai)) {
    $tanggalSampai = date('Y-m-d');
}
if ($tanggalDari > $tanggalSampai) {
    $tmp = $tanggalDari;
    $tanggalDari = $tanggalSampai;
    $tanggalSampai = $tmp;
}

[$tanggalDari, $tanggalSampai, $semuaPeriodeAktif] = keuangan_riwayat_pembayaran_resolve_tanggal(
    $pdo,
    $tanggalDari,
    $tanggalSampai,
    $santriId,
    $q,
    $semuaPeriode
);

$tablesOk = table_exists($pdo, 'keuangan_pembayaran');
$detailOk = $tablesOk && table_exists($pdo, 'keuangan_pembayaran_detail');
$list = [];
$santriSelected = null;
$posOptions = [];
$detailMap = [];
$ringkasan = [
    'jumlah' => 0,
    'total' => 0.0,
    'per_metode' => [],
];
$ringkasanPos = [];

if ($tablesOk) {
    if ($detailOk) {
        $posOptions = keuangan_pembayaran_pos_options($pdo);
    }

    $kkCol = column_exists($pdo, 'santri', 'kategori_kelas') ? 's.kategori_kelas' : "''";
    $joinUser = table_exists($pdo, 'users') ? 'LEFT JOIN users u ON u.id = p.created_by' : '';
    $namaPetugas = table_exists($pdo, 'users') ? 'u.nama AS nama_petugas' : "'' AS nama_petugas";
    $joinAkun = '';
    $namaAkun = "'' AS nama_akun";
    if (table_exists($pdo, 'keuangan_akun') && column_exists($pdo, 'keuangan_pembayaran', 'akun_id')) {
        $joinAkun = 'LEFT JOIN keuangan_akun ak ON ak.id = p.akun_id';
        $namaAkun = 'COALESCE(ak.nama_akun, \'\') AS nama_akun';
    }

    $sqlFromJoins = "
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        {$joinUser}
        {$joinAkun}";
    $sqlWhere = '
        WHERE p.tanggal_bayar BETWEEN :dari AND :sampai';
    $params = ['dari' => $tanggalDari, 'sampai' => $tanggalSampai];
    if ($jenis !== '') {
        $sqlWhere .= ' AND p.jenis_periode = :jenis';
        $params['jenis'] = $jenis;
    }
    if ($santriId > 0) {
        $sqlWhere .= ' AND p.santri_id = :sid';
        $params['sid'] = $santriId;
    }
    if ($metode !== '' && column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')) {
        $sqlWhere .= ' AND p.metode_bayar = :metode';
        $params['metode'] = $metode;
    }
    if ($posSlug !== '' && $detailOk) {
        $sqlWhere .= ' AND EXISTS (SELECT 1 FROM keuangan_pembayaran_detail dx WHERE dx.pembayaran_id = p.id AND LOWER(TRIM(dx.pos_slug)) = :pos_slug)';
        $params['pos_slug'] = strtolower(trim($posSlug));
    }
    [$qSql, $qParams] = keuangan_riwayat_pembayaran_sql_q_filter($pdo, $q);
    if ($qSql !== '') {
        $sqlWhere .= $qSql;
        $params = array_merge($params, $qParams);
    }

    $sqlBase = $sqlFromJoins . $sqlWhere;

    if ($posSlug !== '' && $detailOk) {
        $sumStmt = $pdo->prepare('
            SELECT COUNT(DISTINCT p.id) AS jml, COALESCE(SUM(d.nominal), 0) AS total
            ' . $sqlFromJoins . '
            INNER JOIN keuangan_pembayaran_detail d
                ON d.pembayaran_id = p.id AND LOWER(TRIM(d.pos_slug)) = :pos_slug_sum
            ' . $sqlWhere);
        $sumParams = $params;
        $sumParams['pos_slug_sum'] = strtolower(trim($posSlug));
        $sumStmt->execute($sumParams);
    } else {
        $sumStmt = $pdo->prepare('SELECT COUNT(*) AS jml, COALESCE(SUM(p.total_nominal), 0) AS total ' . $sqlBase);
        $sumStmt->execute($params);
    }
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC);
    if ($sumRow) {
        $ringkasan['jumlah'] = (int) ($sumRow['jml'] ?? 0);
        $ringkasan['total'] = (float) ($sumRow['total'] ?? 0);
    }

    if (column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')) {
        if ($posSlug !== '' && $detailOk) {
            $grp = $pdo->prepare('
                SELECT p.metode_bayar, COUNT(DISTINCT p.id) AS jml, COALESCE(SUM(d.nominal), 0) AS total
                ' . $sqlFromJoins . '
                INNER JOIN keuangan_pembayaran_detail d
                    ON d.pembayaran_id = p.id AND LOWER(TRIM(d.pos_slug)) = :pos_slug_met
                ' . $sqlWhere . '
                GROUP BY p.metode_bayar ORDER BY p.metode_bayar ASC');
            $grpParams = $params;
            $grpParams['pos_slug_met'] = strtolower(trim($posSlug));
            $grp->execute($grpParams);
        } else {
            $grp = $pdo->prepare('SELECT p.metode_bayar, COUNT(*) AS jml, COALESCE(SUM(p.total_nominal), 0) AS total ' . $sqlBase . ' GROUP BY p.metode_bayar ORDER BY p.metode_bayar ASC');
            $grp->execute($params);
        }
        $ringkasan['per_metode'] = $grp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($detailOk) {
        $posAggExtra = '';
        $posAggParams = $params;
        if ($posSlug !== '') {
            $posAggExtra = ' AND LOWER(TRIM(d.pos_slug)) = :pos_slug_agg';
            $posAggParams['pos_slug_agg'] = strtolower(trim($posSlug));
        }
        $posAggSql = '
            SELECT d.pos_slug, d.pos_nama, COUNT(DISTINCT d.pembayaran_id) AS jml_trx, COALESCE(SUM(d.nominal), 0) AS total_nominal
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            INNER JOIN santri s ON s.id = p.santri_id
            ' . $joinUser . '
            ' . $joinAkun . '
            ' . $sqlWhere . $posAggExtra . '
            GROUP BY d.pos_slug, d.pos_nama
            ORDER BY d.pos_nama ASC';
        $posAgg = $pdo->prepare($posAggSql);
        try {
            $posAgg->execute($posAggParams);
            $ringkasanPos = $posAgg->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $ringkasanPos = [];
        }
    }

    $nominalListSelect = 'p.total_nominal';
    if ($posSlug !== '' && $detailOk) {
        $nominalListSelect = '(
            SELECT COALESCE(SUM(dn.nominal), 0)
            FROM keuangan_pembayaran_detail dn
            WHERE dn.pembayaran_id = p.id AND LOWER(TRIM(dn.pos_slug)) = :pos_slug_list
        ) AS total_nominal';
        $params['pos_slug_list'] = strtolower(trim($posSlug));
    }

    $sqlList = "
        SELECT p.id, p.santri_id, p.jenis_periode, p.tahun_ajaran_mulai, p.tahun_ajaran_selesai, p.bulan_tagihan,
               p.tanggal_bayar, {$nominalListSelect}, p.metode_bayar, p.keterangan, p.no_referensi, p.created_at,
               s.nis, s.nama_santri, {$kkCol} AS kategori_kelas, {$namaPetugas}, {$namaAkun}
        " . $sqlBase . ' ORDER BY p.tanggal_bayar DESC, p.id DESC LIMIT ' . (int) $limit;
    $st = $pdo->prepare($sqlList);
    $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $list);
    $ids = array_values(array_filter($ids, static fn (int $v): bool => $v > 0));
    if ($ids !== [] && $detailOk) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $det = $pdo->prepare("SELECT pembayaran_id, pos_slug, pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id IN ($in) ORDER BY pembayaran_id ASC, id ASC");
        $det->execute($ids);
        foreach ($det->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $pid = (int) $d['pembayaran_id'];
            if (!isset($detailMap[$pid])) {
                $detailMap[$pid] = [];
            }
            $detailMap[$pid][] = $d;
        }
    }

    require_once __DIR__ . '/../helpers/santri_list_sort.php';
    santri_list_sort_mode($_GET['santri_sort'] ?? null);
    if ($santriId > 0) {
        ensure_santri_identity_columns($pdo);
        $stSantri = $pdo->prepare('SELECT id, nis, nama_santri FROM santri WHERE id = :id LIMIT 1');
        $stSantri->execute(['id' => $santriId]);
        $santriSelected = $stSantri->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}


$pageTitle = 'Riwayat Pembayaran';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconRiwayat = bendahara_page_icon('riwayat');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>
        <a href="/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconRiwayat) ?>"></i></span>
        Riwayat pembayaran (detail)
    </h1>
    <p class="text-muted mb-0">Filter tanggal, jenis, santri, metode, dan komponen POS. Cari nama/NIS santri untuk melihat seluruh pembayaran pada periode TA.</p>
    <?php if (user_can_lihat_audit_operasional() || is_super_admin()): ?>
        <p class="small mb-0 mt-2 d-flex flex-wrap gap-2">
            <?php if (user_can_lihat_audit_operasional()): ?>
                <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php')) ?>"><i class="fa-solid fa-clipboard-list me-1"></i> Log audit operasional</a>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_url('pembayaran/edit_token.php')) ?>"><i class="fa-solid fa-key me-1"></i> Token edit pembayaran</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($canKoreksiPembayaran): ?>
    <?php if (!$tokenEditRequired): ?>
        <div class="alert alert-info py-2 small mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-shield-halved"></i>
            <div>
                <strong>Super admin</strong> — mode edit selalu terbuka untuk Anda. Token tidak diperlukan.
            </div>
        </div>
    <?php elseif ($tokenEditSessionAktif): ?>
        <div class="alert alert-success py-2 small mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-lock-open"></i>
            <div class="flex-grow-1">
                <strong>Mode edit terbuka.</strong>
                Token aktif untuk session Anda — klik <i class="fa-solid fa-pen-to-square mx-1"></i> pada baris untuk mengedit.
                <span class="text-muted">Berlaku sampai Anda logout.</span>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-warning-subtle mb-3">
            <div class="card-body py-3">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <i class="fa-solid fa-lock text-warning-emphasis mt-1"></i>
                    <div>
                        <strong class="d-block">Mode edit terkunci</strong>
                        <span class="small text-muted">Minta token sekali pakai ke super admin, masukkan di bawah — setelah terbuka Anda bisa mengedit banyak pembayaran hingga logout.</span>
                    </div>
                </div>
                <form method="post" class="row g-2 align-items-end" autocomplete="off">
                    <input type="hidden" name="action" value="redeem_token">
                    <input type="hidden" name="dari" value="<?= htmlspecialchars($tanggalDari) ?>">
                    <input type="hidden" name="sampai" value="<?= htmlspecialchars($tanggalSampai) ?>">
                    <?php if ($jenis !== ''): ?><input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis) ?>"><?php endif; ?>
                    <?php if ($santriId > 0): ?><input type="hidden" name="santri_id" value="<?= $santriId ?>"><?php endif; ?>
                    <?php if ($metode !== ''): ?><input type="hidden" name="metode" value="<?= htmlspecialchars($metode) ?>"><?php endif; ?>
                    <?php if ($posSlug !== ''): ?><input type="hidden" name="pos" value="<?= htmlspecialchars($posSlug) ?>"><?php endif; ?>
                    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
                    <?php if ($semuaPeriode): ?><input type="hidden" name="semua_periode" value="1"><?php endif; ?>
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                    <div class="col-12 col-md-8 col-lg-5">
                        <label class="form-label small text-muted mb-1">Token edit</label>
                        <input type="text" name="token_plain" class="form-control form-control-sm font-monospace text-uppercase" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="40" required>
                    </div>
                    <div class="col-12 col-md-4 col-lg-3">
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="fa-solid fa-lock-open me-1"></i>Buka mode edit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
$flashOk = get_flash('success');
$flashErr = get_flash('error');
?>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small mb-3"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small mb-3"><i class="fa-solid fa-circle-exclamation me-1"></i><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel pembayaran keuangan belum ada. Buka <a href="/keuangan/index.php">Keuangan</a> untuk inisialisasi.</div>
<?php endif; ?>

<?php if ($tablesOk): ?>
<?php if ($semuaPeriodeAktif): ?>
    <div class="alert alert-info py-2 small mb-3">
        <i class="fa-solid fa-calendar-days me-1"></i>
        Rentang tanggal diperluas ke <strong>seluruh periode TA aktif</strong>
        (<?= htmlspecialchars($tanggalDari) ?> s/d <?= htmlspecialchars($tanggalSampai) ?>)
        karena opsi <strong>Semua periode TA aktif</strong> dicentang.
    </div>
<?php endif; ?>
<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Dari tanggal</label>
        <input class="form-control form-control-sm" type="date" name="dari" value="<?= htmlspecialchars($tanggalDari) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Sampai tanggal</label>
        <input class="form-control form-control-sm" type="date" name="sampai" value="<?= htmlspecialchars($tanggalSampai) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Jenis periode</label>
        <select class="form-select form-select-sm" name="jenis">
            <option value="" <?= $jenis === '' ? 'selected' : '' ?>>Semua</option>
            <option value="BULANAN" <?= $jenis === 'BULANAN' ? 'selected' : '' ?>>Bulanan</option>
            <option value="AWAL_TAHUN" <?= $jenis === 'AWAL_TAHUN' ? 'selected' : '' ?>>Awal tahun</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Metode bayar</label>
        <select class="form-select form-select-sm" name="metode">
            <option value="" <?= $metode === '' ? 'selected' : '' ?>>Semua</option>
            <option value="KAS" <?= $metode === 'KAS' ? 'selected' : '' ?>>Kas</option>
            <option value="TRANSFER" <?= $metode === 'TRANSFER' ? 'selected' : '' ?>>Transfer</option>
        </select>
    </div>
    <div class="col-12 col-md-3 col-lg-2">
        <label class="form-label small mb-0 fw-semibold text-primary">
            <i class="fa-solid fa-tags me-1"></i>Filter POS
        </label>
        <select class="form-select form-select-sm border-primary-subtle" name="pos" <?= !$detailOk ? 'disabled title="Tabel rincian belum ada"' : '' ?>>
            <option value="">Semua POS / komponen</option>
            <?php foreach ($posOptions as $po): ?>
                <option value="<?= htmlspecialchars((string) $po['pos_slug']) ?>" <?= $posSlug === (string) $po['pos_slug'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $po['pos_nama']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text small">Pilih komponen rincian. <?= $posSlug !== '' ? '<span class="text-primary"><i class="fa-solid fa-filter"></i> Aktif</span>' : '' ?></div>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0">Santri</label>
        <select class="form-select form-select-sm santri-select-searchable" name="santri_id"
            data-santri-ajax="1"
            data-santri-search-url="<?= htmlspecialchars(app_href('/api/keuangan/santri_search.php')) ?>"
            data-search-placeholder="Ketik nama atau NIS santri…">
            <option value="0">Semua santri</option>
            <?php if (is_array($santriSelected)): ?>
                <option value="<?= (int) $santriSelected['id'] ?>" selected>
                    <?= htmlspecialchars((string) ($santriSelected['nis'] ?: '-')) ?> — <?= htmlspecialchars((string) $santriSelected['nama_santri']) ?>
                </option>
            <?php endif; ?>
        </select>
    </div>
    <div class="col-12 col-md-3 d-flex align-items-end">
        <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" name="semua_periode" value="1" id="semua_periode" <?= $semuaPeriode ? 'checked' : '' ?>>
            <label class="form-check-label small" for="semua_periode">Semua periode TA aktif</label>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Maks. baris</label>
        <select class="form-select form-select-sm" name="limit">
            <?php foreach ([200, 500, 1000, 2000] as $lm): ?>
                <option value="<?= $lm ?>" <?= $limit === $lm ? 'selected' : '' ?>><?= $lm ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="fa-solid fa-filter me-1"></i> Terapkan filter</button>
        <a class="btn btn-outline-secondary btn-sm" href="/pembayaran/riwayat.php"><i class="fa-solid fa-rotate-left me-1"></i> Reset</a>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-trx">
            <div class="app-mini-stat-label">Jumlah transaksi (sesuai filter)</div>
            <div class="app-mini-stat-value"><?= number_format($ringkasan['jumlah'], 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-total">
            <div class="app-mini-stat-label">Total nominal</div>
            <div class="app-mini-stat-value text-success">Rp <?= number_format((int) round($ringkasan['total']), 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-rows">
            <div class="app-mini-stat-label">Baris tabel (dibatasi)</div>
            <div class="app-mini-stat-value"><?= count($list) ?> / <?= $limit ?></div>
            <div class="small text-muted mt-1">Ringkasan total di atas memakai <strong>semua</strong> transaksi pada filter, bukan hanya baris yang ditampilkan.</div>
        </div>
    </div>
</div>

<?php if ($ringkasan['per_metode'] !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <h2 class="h6 mb-2">Terjumlah per metode bayar</h2>
        <div class="table-responsive mb-0">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>Metode</th><th class="text-end">Jumlah</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($ringkasan['per_metode'] as $pm): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($pm['metode_bayar'] ?? '-')) ?></td>
                            <td class="text-end"><?= number_format((int) ($pm['jml'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end font-monospace">Rp <?= number_format((int) round((float) ($pm['total'] ?? 0)), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($detailOk && $ringkasanPos !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <h2 class="h6 mb-2">Terjumlah per POS (komponen rincian)</h2>
        <p class="small text-muted mb-2">Nominal dari baris rincian; satu transaksi bisa memuat beberapa POS.</p>
        <div class="table-responsive mb-0">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>POS</th><th class="text-end">Trx terlibat</th><th class="text-end">Σ nominal rincian</th></tr></thead>
                <tbody>
                    <?php foreach ($ringkasanPos as $rp): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($rp['pos_nama'] ?? '')) ?> <span class="text-muted small">(<?= htmlspecialchars((string) ($rp['pos_slug'] ?? '')) ?>)</span></td>
                            <td class="text-end"><?= number_format((int) ($rp['jml_trx'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end font-monospace">Rp <?= number_format((int) round((float) ($rp['total_nominal'] ?? 0)), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tablesOk): ?>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>ID</th>
                        <th>Santri</th>
                        <th>Kelas keuangan</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th>Rincian POS</th>
                        <th class="text-end">Total</th>
                        <th>Metode</th>
                        <th>Akun / ref.</th>
                        <th>Petugas</th>
                        <th class="text-end">Kuitansi</th>
                        <?php if ($canKoreksiPembayaran): ?>
                            <th class="text-end">Admin</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="<?= $canKoreksiPembayaran ? 13 : 12 ?>" class="text-muted text-center py-4">Belum ada pembayaran pada rentang &amp; filter ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($list as $row): ?>
                    <?php
                    $periodeLabel = pondok_label_periode_pembayaran($pdo, $row);
                    $pid = (int) $row['id'];
                    $dets = $detailMap[$pid] ?? [];
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars((string) $row['tanggal_bayar']) ?></td>
                        <td class="small font-monospace">#<?= $pid ?></td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars((string) $row['nama_santri']) ?></div>
                            <div class="font-monospace text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars((string) $row['nis']) ?></div>
                        </td>
                        <td class="small">
                            <?php
                            $kk = trim((string) ($row['kategori_kelas'] ?? ''));
                            echo $kk !== '' ? htmlspecialchars(kelas_keuangan_label_for_kode($pdo, $kk)) : '—';
                            ?>
                        </td>
                        <td><span class="badge text-bg-light text-dark border"><?= htmlspecialchars((string) ($row['jenis_periode'] ?? '')) ?></span></td>
                        <td class="small"><?= htmlspecialchars($periodeLabel) ?></td>
                        <td class="small" style="max-width:14rem;">
                            <?php if (!$detailOk): ?>
                                <span class="text-muted">—</span>
                            <?php elseif ($dets === []): ?>
                                <span class="text-muted">Tanpa rincian</span>
                            <?php else: ?>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($dets as $d):
                                        if ($posSlug !== '' && strtolower(trim((string) ($d['pos_slug'] ?? ''))) !== strtolower(trim($posSlug))) {
                                            continue;
                                        }
                                        ?>
                                        <li><?= htmlspecialchars((string) ($d['pos_nama'] ?? '')) ?> <span class="text-muted">Rp <?= number_format((int) round((float) ($d['nominal'] ?? 0)), 0, ',', '.') ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small">Rp <?= number_format((int) round((float) $row['total_nominal']), 0, ',', '.') ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($row['metode_bayar'] ?? 'KAS')) ?></td>
                        <td class="small">
                            <?php
                            $ak = trim((string) ($row['nama_akun'] ?? ''));
                            $ref = trim((string) ($row['no_referensi'] ?? ''));
                            echo $ak !== '' ? htmlspecialchars($ak) : '—';
                            if ($ref !== '') {
                                echo '<div class="text-muted" style="font-size:0.7rem;">Ref: ' . htmlspecialchars($ref) . '</div>';
                            }
                            ?>
                        </td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($row['nama_petugas'] ?? '')) !== '' ? (string) $row['nama_petugas'] : '—') ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= htmlspecialchars(app_href('/keuangan/kuitansi.php?id=' . $pid)) ?>"><i class="fa-solid fa-receipt me-1"></i> Buka</a>
                        </td>
                        <?php if ($canKoreksiPembayaran): ?>
                            <td class="text-end text-nowrap">
                                <?php if ($tokenEditUnlocked): ?>
                                    <a class="btn btn-sm btn-outline-warning" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_edit.php?id=' . $pid)) ?>" title="Edit / hapus (mode terbuka)">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_edit.php?id=' . $pid)) ?>" title="Buka untuk masukkan token edit">
                                        <i class="fa-solid fa-lock"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php if (trim((string) ($row['keterangan'] ?? '')) !== ''): ?>
                        <tr class="table-light">
                            <td colspan="<?= $canKoreksiPembayaran ? 13 : 12 ?>" class="small py-1"><strong>Keterangan:</strong> <?= nl2br(htmlspecialchars((string) $row['keterangan'])) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <?php if ($list !== []): ?>
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td colspan="7">Jumlah total (filter · <?= (int) $ringkasan['jumlah'] ?> transaksi<?= count($list) < (int) $ringkasan['jumlah'] ? ', tampil ' . count($list) . ' terbaru' : '' ?>)</td>
                        <td class="text-end font-monospace">Rp <?= number_format((int) round((float) $ringkasan['total']), 0, ',', '.') ?></td>
                        <td colspan="<?= $canKoreksiPembayaran ? 5 : 4 ?>"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
