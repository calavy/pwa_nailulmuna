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
$countLunas = 0;
$countBelum = 0;
$countSebagian = 0;
foreach ($body as $r) {
    $sumTagihan += (int) ($r['tagihan'] ?? 0);
    $sumBayar += min((int) ($r['bayar'] ?? 0), (int) ($r['tagihan'] ?? 0));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim_wa_tagihan') {
    $res = wa_tagihan_kirim_manual($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
    header('Location: ' . app_href('/pembayaran/tagihan_syahriyah.php?' . http_build_query(array_merge($queryBase, ['page' => $page]))));
    exit;
}

$pageTitle = 'Tagihan Bulanan';
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
        Tagihan <strong>wajib</strong>: <strong>Syahriyah</strong> saja.
        <strong>Makan</strong> dan <strong>Saku</strong> opsional (bisa dibayar terpisah).
        Kalender <?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?>.
        <?php if ($slotAktif && !empty($slotAktif['masehi_awal'])): ?>
            Periode aktif: <strong><?= htmlspecialchars((string) ($slotAktif['label'] ?? pondok_bulan_slot_label_tampilan($pdo, $slotAktif))) ?></strong>
            <span class="text-muted">(<?= htmlspecialchars((string) $slotAktif['masehi_awal']) ?> s/d <?= htmlspecialchars((string) $slotAktif['masehi_akhir']) ?> M)</span>.
        <?php endif; ?>
        Potongan syahriyah per santri diatur di
        <a href="/keuangan/potongan_syahriyah.php">Pengaturan potongan syahriyah</a>.
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

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>
<?php require __DIR__ . '/../includes/partials/santri_sort_toolbar.php'; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="" id="form-tagihan-filter">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select class="form-select form-select-sm pondok-bulan-select" name="bulan" data-auto-submit="1">
            <?php foreach ($bulanSlots as $slot): ?>
                <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                <option value="<?= $b ?>" <?= $b === $bulanTagihan ? 'selected' : '' ?>><?= htmlspecialchars((string) ($slot['label'] ?? '')) ?></option>
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
        <input class="form-control form-control-sm" type="search" name="q" id="tagihan-cari" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik lalu Enter atau tunggu…" autocomplete="off">
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
<p class="small text-muted mb-2">
    Menampilkan <strong><?= $totalRows ?></strong> santri
    <?php if ($filterStatus === 'harus_bayar'): ?>(belum lunas tagihan wajib)<?php endif; ?>
    · halaman <?= $page ?> / <?= $totalPages ?>
    <?php if (count($bodyAll) !== $totalRows): ?>
        · dari <?= count($bodyAll) ?> santri aktif
    <?php endif; ?>
    <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" id="btn-tagihan-ringkas" aria-pressed="<?= $ringkas ? 'true' : 'false' ?>">
        <?= $ringkas ? 'Tampilkan detail kolom' : 'Mode ringkas' ?>
    </button>
    <form method="post" class="d-inline ms-2" onsubmit="return confirm('Kirim WA tagihan ke wali santri yang masih punya tagihan belum lunas? (Tidak mengganggu jadwal otomatis.)')">
        <input type="hidden" name="action" value="kirim_wa_tagihan">
        <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Kirim WA tagihan</button>
    </form>
</p>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-tagihan">
            <div class="app-mini-stat-label">Total tagihan (filter)</div>
            <div class="app-mini-stat-value">Rp <?= number_format($sumTagihan, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-bayar">
            <div class="app-mini-stat-label">Terpenuhi</div>
            <div class="app-mini-stat-value text-success">Rp <?= number_format($sumBayar, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-lunas">
            <div class="app-mini-stat-label">Lunas</div>
            <div class="app-mini-stat-value"><?= $countLunas ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Belum</div>
            <div class="app-mini-stat-value text-danger"><?= $countBelum ?></div>
        </div>
    </div>
    <div class="col-4 col-md-2">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-sebagian">
            <div class="app-mini-stat-label">Sebagian</div>
            <div class="app-mini-stat-value text-warning"><?= $countSebagian ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm tagihan-list-card<?= $ringkas ? ' tagihan-list-card--ringkas' : '' ?>">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 tagihan-santri-table" id="tabel-tagihan">
                <thead class="table-light">
                    <tr>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th class="tagihan-col-detail">Kelas</th>
                        <th class="text-end">Sisa wajib</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($bodyPage === []): ?>
                    <tr><td colspan="6" class="text-muted text-center py-4">Tidak ada data atau tidak cocok filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($bodyPage as $r): ?>
                    <?php
                    $kkDisp = trim((string) ($r['kategori'] ?? ''));
                    $kelasLabel = $kkDisp === ''
                        ? (string) ($r['tingkatan'] !== '' ? $r['tingkatan'] : '—')
                        : ($kelasLabels[strtoupper($kkDisp)] ?? $kkDisp);
                    $hasOps = (int) ($r['mk_expected'] ?? 0) > 0 || (int) ($r['sk_expected'] ?? 0) > 0;
                    ?>
                    <tr class="tagihan-row" data-id="<?= (int) $r['id'] ?>">
                        <td class="font-monospace small"><?= htmlspecialchars((string) $r['nis']) ?></td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars((string) $r['nama']) ?></span>
                            <?php if ($hasOps && $ringkas): ?>
                                <span class="badge text-bg-light text-dark border ms-1" style="font-size:.65rem">+opsional</span>
                            <?php endif; ?>
                        </td>
                        <td class="small tagihan-col-detail"><?= htmlspecialchars($kelasLabel) ?></td>
                        <td class="text-end font-monospace small fw-semibold <?= (int) $r['sisa'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= (int) $r['sisa'] > 0 ? 'Rp ' . number_format((int) $r['sisa'], 0, ',', '.') : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= htmlspecialchars((string) $r['statusClass']) ?>"><?= htmlspecialchars((string) $r['status']) ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary tagihan-btn-detail d-none d-md-inline-flex" data-row="<?= (int) $r['id'] ?>" title="Detail">
                                <i class="fa-solid fa-chevron-down"></i>
                            </button>
                            <a class="btn btn-sm btn-outline-primary" href="/keuangan/pembayaran.php?santri_id=<?= (int) $r['id'] ?>&bulan=<?= (int) $bulanTagihan ?>"><i class="fa-solid fa-money-bill-wave me-1"></i> Bayar</a>
                        </td>
                    </tr>
                    <tr class="tagihan-row-detail d-none" id="tagihan-detail-<?= (int) $r['id'] ?>" data-parent="<?= (int) $r['id'] ?>">
                        <td colspan="6" class="small bg-light py-2">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <strong class="text-success">Wajib · Syahriyah</strong>
                                    <?php if ((int) $r['sy_expected'] > 0): ?>
                                        <div>Sisa: <strong>Rp <?= number_format((int) $r['sy_sisa'], 0, ',', '.') ?></strong>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['sy_expected'], 0, ',', '.') ?></span></div>
                                        <?php if (!empty($r['sy_dijeda'])): ?>
                                            <div class="text-secondary">Potongan dijeda (tarif penuh)</div>
                                        <?php elseif ($r['sy_persen'] > 0 && $r['sy_ket_potongan'] !== ''): ?>
                                            <div class="text-warning">Potongan <?= rtrim(rtrim(number_format((float) $r['sy_persen'], 1, ',', '.'), '0'), ',') ?>% · <?= htmlspecialchars((string) $r['sy_ket_potongan']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-muted">Tidak ada tarif</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <strong class="text-info">Opsional · Makan</strong>
                                    <?php if ((int) $r['mk_expected'] > 0): ?>
                                        <div>Sisa: Rp <?= number_format((int) $r['mk_sisa'], 0, ',', '.') ?>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['mk_expected'], 0, ',', '.') ?></span></div>
                                    <?php else: ?>
                                        <div class="text-muted">—</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <strong class="text-info">Opsional · Saku</strong>
                                    <?php if ((int) $r['sk_expected'] > 0): ?>
                                        <div>Sisa: Rp <?= number_format((int) $r['sk_sisa'], 0, ',', '.') ?>
                                            <span class="text-muted">/ Rp <?= number_format((int) $r['sk_expected'], 0, ',', '.') ?></span></div>
                                    <?php else: ?>
                                        <div class="text-muted">—</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$ringkas): ?>
                            <div class="mt-1 text-muted">Terbayar wajib: Rp <?= number_format(min((int) $r['bayar'], (int) $r['tagihan']), 0, ',', '.') ?> · Tier <?= htmlspecialchars((string) $r['tier']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3 d-flex flex-wrap justify-content-center gap-1" aria-label="Halaman tagihan">
    <?php
    $pageWindow = 5;
    $startP = max(1, $page - (int) floor($pageWindow / 2));
    $endP = min($totalPages, $startP + $pageWindow - 1);
    $startP = max(1, $endP - $pageWindow + 1);
    if ($page > 1):
        $prevQ = $queryBase;
        $prevQ['page'] = $page - 1;
        ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($prevQ)) ?>">«</a>
    <?php endif;
    for ($p = $startP; $p <= $endP; $p++):
        $pq = $queryBase;
        $pq['page'] = $p;
        ?>
        <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= htmlspecialchars(http_build_query($pq)) ?>"><?= $p ?></a>
    <?php endfor;
    if ($page < $totalPages):
        $nextQ = $queryBase;
        $nextQ['page'] = $page + 1;
        ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($nextQ)) ?>">»</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<link rel="stylesheet" href="<?= htmlspecialchars(app_href('/assets/css/tagihan-list.css')) ?>">
<script src="<?= htmlspecialchars(app_href('/assets/js/tagihan-syahriyah-list.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
