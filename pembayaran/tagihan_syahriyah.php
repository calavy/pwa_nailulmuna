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
$q = trim((string) ($_GET['q'] ?? ''));
$kelasLabels = kelas_keuangan_label_map($pdo);

$listPack = tagihan_syahriyah_list_cached($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
$tablesOk = (bool) ($listPack['tables_ok'] ?? false);
$bodyAll = $listPack['body'] ?? [];
$sumTagihan = (int) ($listPack['sum_tagihan'] ?? 0);
$sumBayar = (int) ($listPack['sum_bayar'] ?? 0);
$countLunas = (int) ($listPack['count_lunas'] ?? 0);
$countBelum = (int) ($listPack['count_belum'] ?? 0);
$countSebagian = (int) ($listPack['count_sebagian'] ?? 0);

$filterStatus = strtolower(trim((string) ($_GET['status'] ?? 'harus_bayar')));
if (!in_array($filterStatus, ['harus_bayar', 'semua', 'lunas'], true)) {
    $filterStatus = 'harus_bayar';
}
$ringkas = ((string) ($_GET['ringkas'] ?? '1')) !== '0';
$perPage = min(100, max(20, (int) ($_GET['per_page'] ?? 50)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$body = $bodyAll;
if ($q !== '') {
    $qLower = strtolower($q);
    $body = array_values(array_filter($bodyAll, static function (array $r) use ($qLower): bool {
        $namaCari = strtolower((string) ($r['nama'] ?? '') . ' ' . (string) ($r['nis'] ?? ''));

        return str_contains($namaCari, $qLower);
    }));
}
if ($filterStatus === 'harus_bayar') {
    $body = array_values(array_filter($body, static function (array $r): bool {
        $st = (string) ($r['status'] ?? '');

        return in_array($st, ['Belum', 'Sebagian'], true) || (int) ($r['sisa'] ?? 0) > 0;
    }));
} elseif ($filterStatus === 'lunas') {
    $body = array_values(array_filter($body, static function (array $r): bool {
        return (string) ($r['status'] ?? '') === 'Lunas';
    }));
}

$sumTagihan = 0;
$sumBayar = 0;
$sumSisa = 0;
$countLunas = 0;
$countBelum = 0;
$countSebagian = 0;
foreach ($body as $r) {
    $sumTagihan += (int) ($r['tagihan'] ?? 0);
    $sumBayar += min((int) ($r['bayar'] ?? 0), (int) ($r['tagihan'] ?? 0));
    $sumSisa += (int) ($r['sisa'] ?? 0);
    $status = (string) ($r['status'] ?? '');
    if ($status === 'Lunas') {
        $countLunas++;
    } elseif ($status === 'Belum') {
        $countBelum++;
    } elseif ($status === 'Sebagian') {
        $countSebagian++;
    }
}

$totalRows = count($body);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$bodyPage = $totalRows > 0 ? array_slice($body, ($page - 1) * $perPage, $perPage) : [];

$queryBase = [
    'bulan' => $bulanTagihan,
    'status' => $filterStatus,
    'ringkas' => $ringkas ? '1' : '0',
    'per_page' => $perPage,
];
if ($q !== '') {
    $queryBase['q'] = $q;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim_wa_santri') {
    $santriIdWa = (int) ($_POST['santri_id'] ?? 0);
    $preview = wa_tagihan_preview_santri($pdo, $santriIdWa, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    if ($preview && ($preview['ok'] ?? false) && !empty($preview['phone'])) {
        if (send_wa_message($pdo, (string) $preview['phone'], (string) ($preview['message'] ?? ''))) {
            set_flash('success', 'WA tagihan terkirim ke wali ' . (string) ($preview['nama'] ?? 'santri') . '.');
        } else {
            set_flash('error', 'Gagal mengirim WA (periksa gateway).');
        }
    } else {
        set_flash('error', (string) ($preview['error'] ?? $preview['message'] ?? 'Tidak bisa mengirim tagihan untuk santri ini.'));
    }
    header('Location: ' . app_href('/pembayaran/tagihan_syahriyah.php?' . http_build_query($queryBase)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim_wa_tagihan') {
    require_once __DIR__ . '/../helpers/wa_tagihan.php';
    $res = wa_tagihan_jalankan_kirim($pdo, true, $bulanTagihan);
    set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
    header('Location: ' . app_href('/pembayaran/tagihan_syahriyah.php?' . http_build_query(array_merge($queryBase, ['page' => $page]))));
    exit;
}

if (strtoupper(trim((string) ($_SERVER['HTTP_X_TAGIHAN_PARTIAL'] ?? ''))) === '1') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/partials/tagihan_syahriyah_list_block.php';
    exit;
}

$pageTitle = 'Status Tagihan Bulanan';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconTagihan = bendahara_page_icon('tagihan');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1"></i>
        <a href="/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon"><i class="fa-solid <?= htmlspecialchars($iconTagihan) ?>"></i></span>
        Tagihan Bulanan
    </h1>
    <p class="text-muted mb-0">
        <strong>Hanya untuk melihat status</strong> tagihan wajib per santri.
        Untuk <strong>mencatat pembayaran</strong>, gunakan
        <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php?mode=BULANAN&mulai=1')) ?>">Input pembayaran</a>
        (formulir yang sama — pilih santri, bulan tagihan, dan komponen bayar).
        Tagihan <strong>wajib</strong>: <strong>Syahriyah</strong> saja.
        <strong>Makan</strong> dan <strong>Saku</strong> opsional (bisa dibayar terpisah).
        Kalender <?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?>.
        <?php if ($bulanTagihan === (int) $berjalan['bulan']): ?>
            <span class="badge text-bg-primary">Bulan berjalan</span>
        <?php endif; ?>
        <?php if ($slotAktif && !empty($slotAktif['masehi_awal'])): ?>
            Periode aktif: <strong><?= htmlspecialchars((string) ($slotAktif['label'] ?? pondok_bulan_slot_label_tampilan($pdo, $slotAktif))) ?></strong>
            <span class="text-muted">(<?= htmlspecialchars((string) $slotAktif['masehi_awal']) ?> s/d <?= htmlspecialchars((string) $slotAktif['masehi_akhir']) ?> M)</span>.
        <?php endif; ?>
        Potongan syahriyah per santri diatur di
        <a href="/keuangan/pengaturan.php?bagian=santri_bulanan&amp;sub=potongan">Pengaturan potongan syahriyah</a>.
    </p>
</div>

<?php if (!pondok_kalender_hijriyah($pdo)): ?>
    <div class="alert alert-warning">
        Kalender tagihan masih <strong>Masehi</strong> (bulan Januari–Desember, contoh «Mei»).
        Agar bulan tampil <strong>Muharram, Safar, … Dzulhijjah</strong>, ubah di
        <a href="/settings/pesantren.php">Pengaturan pondok</a> → Kalender tagihan → <strong>Hijriyah</strong>, lalu simpan.
    </div>
<?php endif; ?>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/keuangan/pembayaran.php">Input pembayaran</a> sekali untuk inisialisasi skema.</div>
<?php endif; ?>

<?php if ($tablesOk): ?>
<div class="alert alert-light border small mb-3 py-2">
    <i class="fa-solid fa-circle-info me-1 text-primary"></i>
    Tombol <strong>Bayar</strong> membuka <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a>
    dengan santri dan bulan yang sama — tidak ada formulir input terpisah.
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>
<?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="" id="form-tagihan-filter">
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
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Tampilkan</label>
        <select class="form-select form-select-sm" name="status" data-auto-submit="1">
            <option value="harus_bayar" <?= $filterStatus === 'harus_bayar' ? 'selected' : '' ?>>Harus bayar (wajib)</option>
            <option value="semua" <?= $filterStatus === 'semua' ? 'selected' : '' ?>>Semua santri</option>
            <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Sudah lunas wajib</option>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input class="form-control form-control-sm" type="search" name="q" id="tagihan-cari" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama atau NIS…" autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Per halaman</label>
        <select class="form-select form-select-sm" name="per_page" data-auto-submit="1">
            <?php foreach ([30, 50, 80, 100] as $pp): ?>
                <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> baris</option>
            <?php endforeach; ?>
        </select>
    </div>
    <input type="hidden" name="ringkas" value="<?= $ringkas ? '1' : '0' ?>" id="tagihan-ringkas-input">
    <div class="col-6 col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
    </div>
</form>

<div id="tagihan-list-root">
<?php require __DIR__ . '/partials/tagihan_syahriyah_list_block.php'; ?>
</div>

<link rel="stylesheet" href="<?= htmlspecialchars(app_href('/assets/css/tagihan-list.css')) ?>">
<script>
window.TAGIHAN_WA_API = <?= json_encode(app_href('/api/wa/tagihan_santri.php'), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars(app_href('/assets/js/tagihan-syahriyah-list.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
