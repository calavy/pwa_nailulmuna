<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_roles(['admin', 'pengurus']);
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../helpers/keuangan_cek_pembayaran.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

keuangan_ensure_schema_deferred($pdo);
$sortMode = santri_list_sort_mode($_GET['santri_sort'] ?? null);

$berjalan = keuangan_periode_berjalan($pdo);
$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$bulanTagihan = max(1, min(12, (int) ($_GET['bulan'] ?? $berjalan['bulan'])));
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$slotAktif = pondok_slot_dari_bulan_tagihan($bulanSlots, $bulanTagihan);
$kalenderMode = pondok_kalender_mode($pdo);
$kelasLabels = kelas_keuangan_label_map($pdo);

$jenis = strtolower(trim((string) ($_GET['jenis'] ?? 'bulanan')));
if (!in_array($jenis, ['bulanan', 'awal_tahun', 'gabungan'], true)) {
    $jenis = 'bulanan';
}

$q = trim((string) ($_GET['q'] ?? ''));
$filterStatus = strtolower(trim((string) ($_GET['status'] ?? 'harus_bayar')));
if (!in_array($filterStatus, ['harus_bayar', 'belum_lunas', 'semua', 'lunas'], true)) {
    $filterStatus = 'harus_bayar';
}
if ($jenis === 'gabungan' && $filterStatus === 'harus_bayar') {
    $filterStatus = 'belum_lunas';
}

$perPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$snapshot = keuangan_cek_pembayaran_snapshot($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan);

if ($jenis === 'awal_tahun') {
    $listPack = keuangan_cek_pembayaran_awal_tahun_list_cached($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
} elseif ($jenis === 'gabungan') {
    $listPack = keuangan_cek_pembayaran_gabungan_compute($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
} else {
    $listPack = tagihan_syahriyah_list_cached($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
}

$tablesOk = (bool) ($listPack['tables_ok'] ?? false);
$bodyAll = $listPack['body'] ?? [];
$body = keuangan_cek_pembayaran_filter_rows($bodyAll, $filterStatus, $q);
$ringkas = keuangan_cek_pembayaran_ringkas_from_body($body);

$sumTagihan = (int) ($ringkas['sum_tagihan'] ?? 0);
$sumBayar = (int) ($ringkas['sum_bayar'] ?? 0);
$sumSisa = (int) ($ringkas['sum_sisa'] ?? 0);
$countLunas = (int) ($ringkas['count_lunas'] ?? 0);
$countBelum = (int) ($ringkas['count_belum'] ?? 0);
$countSebagian = (int) ($ringkas['count_sebagian'] ?? 0);

$totalRows = count($body);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$bodyPage = $totalRows > 0 ? array_slice($body, ($page - 1) * $perPage, $perPage) : [];

$queryBase = [
    'jenis' => $jenis,
    'bulan' => $bulanTagihan,
    'status' => $filterStatus,
    'per_page' => $perPage,
];
if ($q !== '') {
    $queryBase['q'] = $q;
}

$pageTitle = 'Cek Pembayaran';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconCek = 'fa-clipboard-check';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1"></i>
        <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon"><i class="fa-solid <?= htmlspecialchars($iconCek) ?>"></i></span>
        Cek Pembayaran
    </h1>
    <p class="text-muted mb-0">
        Pantau status tagihan <strong>bulanan</strong> dan <strong>awal tahun</strong> per santri — lunas atau belum lunas.
        Perhitungan selaras dengan dashboard keuangan sampai <strong>saldo kas terkini</strong>.
        Untuk mencatat pembayaran, gunakan
        <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a>.
        Kalender <?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?>.
        <?php if ($bulanTagihan === (int) $berjalan['bulan']): ?>
            <span class="badge text-bg-primary">Bulan berjalan</span>
        <?php endif; ?>
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a> sekali untuk inisialisasi skema.</div>
<?php endif; ?>

<?php if ($tablesOk): ?>
    <?php require __DIR__ . '/partials/cek_pembayaran_ringkasan.php'; ?>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <?php
    $tabs = [
        'bulanan' => 'Tagihan Bulanan',
        'awal_tahun' => 'Awal Tahun',
        'gabungan' => 'Belum Lunas (Gabungan)',
    ];
    foreach ($tabs as $tabKey => $tabLabel):
        $tabQ = array_merge($queryBase, ['jenis' => $tabKey, 'page' => 1]);
        if ($tabKey === 'gabungan') {
            $tabQ['status'] = 'belum_lunas';
        } elseif ($tabKey === 'bulanan' && $filterStatus === 'belum_lunas') {
            $tabQ['status'] = 'harus_bayar';
        }
        ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $jenis === $tabKey ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query($tabQ)) ?>">
                <?= htmlspecialchars($tabLabel) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>
<?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis) ?>">
    <?php if ($jenis !== 'awal_tahun'): ?>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select class="form-select form-select-sm pondok-bulan-select" name="bulan" data-auto-submit="1">
            <?php foreach ($bulanSlots as $slot): ?>
                <?php
                $b = (int) ($slot['bulan_tagihan'] ?? 0);
                $isBerjalan = $b === (int) $berjalan['bulan'];
                ?>
                <option value="<?= $b ?>" <?= $b === $bulanTagihan ? 'selected' : '' ?>><?= htmlspecialchars((string) ($slot['label'] ?? '')) ?><?= $isBerjalan ? ' ★ berjalan' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
        <input type="hidden" name="bulan" value="<?= $bulanTagihan ?>">
    <?php endif; ?>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Tampilkan</label>
        <select class="form-select form-select-sm" name="status" data-auto-submit="1">
            <?php if ($jenis === 'gabungan'): ?>
                <option value="belum_lunas" <?= $filterStatus === 'belum_lunas' ? 'selected' : '' ?>>Belum lunas (salah satu)</option>
                <option value="semua" <?= $filterStatus === 'semua' ? 'selected' : '' ?>>Semua santri</option>
                <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Sudah lunas semua</option>
            <?php else: ?>
                <option value="harus_bayar" <?= $filterStatus === 'harus_bayar' ? 'selected' : '' ?>>Harus bayar (belum lunas)</option>
                <option value="semua" <?= $filterStatus === 'semua' ? 'selected' : '' ?>>Semua santri</option>
                <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Sudah lunas</option>
            <?php endif; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Per halaman</label>
        <select class="form-select form-select-sm" name="per_page" data-auto-submit="1">
            <?php foreach ([30, 50, 80, 100] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Terapkan</button>
    </div>
</form>

<?php if ($jenis === 'bulanan' && $slotAktif && !empty($slotAktif['masehi_awal'])): ?>
    <p class="small text-muted mb-2">
        Periode bulanan aktif: <strong><?= htmlspecialchars((string) ($slotAktif['label'] ?? pondok_bulan_slot_label_tampilan($pdo, $slotAktif))) ?></strong>
        <span class="text-muted">(<?= htmlspecialchars((string) $slotAktif['masehi_awal']) ?> s/d <?= htmlspecialchars((string) $slotAktif['masehi_akhir']) ?> M)</span>.
        Detail per komponen: <a href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php?' . http_build_query(['bulan' => $bulanTagihan]))) ?>">Status tagihan bulanan</a>.
    </p>
<?php endif; ?>

<?php if ($jenis === 'awal_tahun'): ?>
    <p class="small text-muted mb-2">
        Tagihan awal tahun TA <strong><?= htmlspecialchars((string) ($snapshot['ta_label'] ?? '')) ?></strong> — komponen sesuai jenis santri (baru/lama).
    </p>
<?php endif; ?>

<?php if ($tablesOk): ?>
    <?php require __DIR__ . '/partials/cek_pembayaran_tabel.php'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
