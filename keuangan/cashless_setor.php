<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$canSetor = cashless_user_can_setor_harian();
$isSuperAdmin = is_super_admin();

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$rekapDari = trim((string) ($_GET['rekap_dari'] ?? date('Y-m-d', strtotime($tanggal . ' -13 days'))));
$rekapSampai = trim((string) ($_GET['rekap_sampai'] ?? $tanggal));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rekapDari)) {
    $rekapDari = date('Y-m-d', strtotime('-13 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rekapSampai)) {
    $rekapSampai = $tanggal;
}
if ($rekapDari > $rekapSampai) {
    [$rekapDari, $rekapSampai] = [$rekapSampai, $rekapDari];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $postTanggal = trim((string) ($_POST['tanggal'] ?? $tanggal));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postTanggal)) {
        $postTanggal = $tanggal;
    }
    $kopId = (int) ($_POST['koperasi_id'] ?? 0);
    $kopIds = array_map('intval', (array) ($_POST['koperasi_ids'] ?? []));

    if ($action === 'setor_koperasi_multi') {
        $res = cashless_koperasi_setor_multi($pdo, $kopIds, $postTanggal, $userId);
        set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? 'Setor gagal.'));
    } elseif ($action === 'setor_koperasi') {
        $res = cashless_koperasi_setor_harian($pdo, $kopId, $postTanggal, $userId);
        set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? 'Setor gagal.'));
    } elseif (in_array($action, ['delete_debit_tx', 'edit_debit_tx'], true)) {
        $res = cashless_koperasi_admin_aksi_transaksi($pdo, $action, $_POST, $userId, $isSuperAdmin);
        if ($res !== null) {
            set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
        }
    }

    header('Location: ' . app_href('/keuangan/cashless_setor.php?' . http_build_query([
        'tanggal' => $postTanggal,
        'rekap_dari' => $rekapDari,
        'rekap_sampai' => $rekapSampai,
    ])));
    exit;
}

$panels = cashless_koperasi_panel_setor_harian($pdo, $tanggal);
$rekapTanggal = cashless_koperasi_rekap_tanggal_range($pdo, $rekapDari, $rekapSampai);
$rekapBelumSetor = cashless_koperasi_rekap_belum_setor_range($pdo, $rekapDari, $rekapSampai);
$koperasiList = cashless_koperasi_list($pdo);
$tanggalLabel = app_format_tanggal_id($tanggal);
$sakuReal = cashless_saku_total_real($pdo);
$belanjaHari = cashless_koperasi_total_debit_tanggal($pdo, $tanggal);
$belumSetorHari = cashless_koperasi_total_belum_setor_tanggal($pdo, $tanggal);
$sudahSetorHari = 0;
$belumSetorJumlah = 0;
$koperasiBisaSetor = [];
foreach ($panels as $p) {
    if (!empty($p['sudah_setor']) && is_array($p['setor_log'] ?? null)) {
        $sudahSetorHari += (int) round((float) ($p['setor_log']['total_nominal'] ?? 0));
    }
    $belumJ = (int) (($p['belum_setor']['jumlah'] ?? 0));
    if (empty($p['sudah_setor'])) {
        $belumSetorJumlah += $belumJ;
    }
    if ($belumJ > 0 && empty($p['sudah_setor'])) {
        $koperasiBisaSetor[] = (int) ($p['koperasi_id'] ?? 0);
    }
}

$pageTitle = 'Setor Cashless Koperasi';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 page-intro">
    <div>
        <p class="page-intro-kicker text-muted mb-1">Keuangan · Cashless Koperasi</p>
        <h1 class="h4 mb-0">Setor Uang Saku (Cashless)</h1>
        <p class="small text-muted mb-0">
            Alur: pembayaran <strong>pos Saku</strong> → Saldo Saku naik → <strong>scan jajan</strong> Saldo Saku turun (sesuai transaksi, meski belum setor)
            → <strong>setor harian</strong> uang fisik diserahkan ke koperasi, <strong>kas bendahara berkurang</strong>.
        </p>
    </div>
    <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-chart-column me-1"></i> Laporan
    </a>
</div>

<form method="get" class="row g-2 align-items-end mb-4">
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Tanggal setor</label>
        <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($tanggal) ?>" required>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-calendar-day me-1"></i> Tampilkan</button>
    </div>
    <div class="col-md-6 text-md-end">
        <span class="small text-muted">Hari ini: <strong><?= htmlspecialchars($tanggalLabel) ?></strong></span>
    </div>
</form>

<div class="alert alert-light border small mb-4">
    <p class="fw-semibold mb-2"><i class="fa-solid fa-route me-1"></i> Alur uang saku / cashless / jajan</p>
    <ol class="mb-0 ps-3">
        <li class="mb-1"><strong>Pembayaran pos Saku</strong> — kas masuk, Saldo Saku santri bertambah.</li>
        <li class="mb-1"><strong>Scan jajan di koperasi</strong> — Saldo Saku berkurang saat scan; <strong>tidak menunggu setor</strong>. Batas harian reset tiap pergantian tanggal.</li>
        <li class="mb-0"><strong>Setor harian</strong> — bendahara menyerahkan uang fisik ke koperasi; <strong>kas berkurang</strong>.</li>
    </ol>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fa-solid fa-wallet me-1"></i> Total Saldo Saku seluruh santri</div>
                <div class="h4 mb-0 text-primary">Rp <?= number_format((int) ($sakuReal['total'] ?? 0), 0, ',', '.') ?></div>
                <div class="small text-muted">Top-up − semua belanja (sudah termasuk hari ini)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fa-solid fa-cart-shopping me-1"></i> Belanja cashless · <?= htmlspecialchars($tanggalLabel) ?></div>
                <div class="h4 mb-0 text-info">Rp <?= number_format($belanjaHari, 0, ',', '.') ?></div>
                <div class="small text-muted">Sudah mengurangi saldo santri saat scan</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fa-solid fa-vault me-1"></i> Uang fisik belum disetor · <?= htmlspecialchars($tanggalLabel) ?></div>
                <div class="h4 mb-0 text-warning">Rp <?= number_format($belumSetorHari, 0, ',', '.') ?></div>
                <div class="small text-muted"><?= $belumSetorJumlah ?> transaksi · masih di bendahara</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="small text-muted mb-1"><i class="fa-solid fa-circle-check me-1"></i> Sudah disetor · <?= htmlspecialchars($tanggalLabel) ?></div>
                <div class="h4 mb-0 text-success">Rp <?= number_format($sudahSetorHari, 0, ',', '.') ?></div>
                <div class="small text-muted">Kas bendahara sudah diserahkan ke koperasi</div>
            </div>
        </div>
    </div>
</div>

<?php if ($canSetor && $koperasiBisaSetor !== []): ?>
<form method="post" id="form-setor-multi" class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 bg-light rounded border">
    <input type="hidden" name="action" value="setor_koperasi_multi">
    <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
    <span class="small fw-semibold me-1">Setor sekaligus:</span>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-pilih-semua-setor">Pilih semua belum setor</button>
    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Setor koperasi terpilih? Kas bendahara akan berkurang. Saldo santri sudah terpotong saat scan.');">
        <i class="fa-solid fa-vault me-1"></i> Setor koperasi terpilih
    </button>
</form>
<?php endif; ?>

<div class="cashless-kop-grid mb-4">
    <?php foreach ($panels as $panel):
        $kid = (int) ($panel['koperasi_id'] ?? 0);
        $theme = (array) ($panel['theme'] ?? cashless_koperasi_card_theme($kid));
        $sudahSetor = !empty($panel['sudah_setor']);
        $belum = (array) ($panel['belum_setor'] ?? []);
        $belumTotal = (int) ($belum['total'] ?? 0);
        $belumJumlah = (int) ($belum['jumlah'] ?? 0);
        $totalHari = (int) ($panel['total_hari'] ?? 0);
        $log = is_array($panel['setor_log'] ?? null) ? $panel['setor_log'] : null;
        $transaksi = (array) ($panel['transaksi'] ?? []);
        ?>
        <article class="cashless-kop-card<?= $sudahSetor ? ' is-active' : '' ?>" style="--kop-gradient: <?= htmlspecialchars((string) ($theme['gradient'] ?? '')) ?>;">
            <div class="cashless-kop-card__bg" aria-hidden="true"></div>
            <div class="cashless-kop-card__body">
                <div class="cashless-kop-card__top">
                    <?php if ($canSetor && !$sudahSetor && $belumJumlah > 0): ?>
                        <label class="d-flex align-items-center gap-2 mb-0 me-2">
                            <input type="checkbox" class="form-check-input kop-setor-check mt-0" name="koperasi_ids[]" value="<?= $kid ?>" form="form-setor-multi">
                        </label>
                    <?php endif; ?>
                    <div class="cashless-kop-card__icon"><i class="fa-solid <?= htmlspecialchars((string) ($theme['icon'] ?? 'fa-store')) ?>"></i></div>
                    <span class="cashless-kop-card__chip">KOP <?= htmlspecialchars((string) ($theme['chip'] ?? (string) $kid)) ?></span>
                </div>
                <h2 class="cashless-kop-card__name"><?= htmlspecialchars((string) ($panel['nama'] ?? '')) ?></h2>
                <p class="cashless-kop-card__amount">Rp <?= number_format($totalHari, 0, ',', '.') ?></p>
                <div class="cashless-kop-card__meta">
                    <span><i class="fa-solid fa-receipt"></i> <?= (int) ($panel['jumlah_transaksi'] ?? 0) ?> transaksi</span>
                    <?php if ($sudahSetor): ?>
                        <span class="text-success"><i class="fa-solid fa-circle-check"></i> Sudah disetor</span>
                    <?php elseif ($belumJumlah > 0): ?>
                        <span class="text-warning"><i class="fa-solid fa-clock"></i> Belum setor Rp <?= number_format($belumTotal, 0, ',', '.') ?></span>
                    <?php else: ?>
                        <span class="text-muted"><i class="fa-solid fa-minus"></i> Tidak ada transaksi</span>
                    <?php endif; ?>
                </div>

                <?php if ($sudahSetor && $log): ?>
                    <div class="small text-success mt-2 mb-2">
                        <i class="fa-solid fa-vault me-1"></i>
                        Disetor <?= htmlspecialchars(app_format_datetime_id((string) ($log['created_at'] ?? ''))) ?>
                        · Rp <?= number_format((int) round((float) ($log['total_nominal'] ?? 0)), 0, ',', '.') ?>
                    </div>
                    <button type="button" class="btn btn-success btn-sm w-100" disabled>
                        <i class="fa-solid fa-check me-1"></i> Sudah disetor
                    </button>
                <?php elseif ($belumJumlah > 0 && $canSetor): ?>
                    <form method="post" class="mt-2 mb-0" onsubmit="return confirm('Setor Rp <?= number_format($belumTotal, 0, ',', '.') ?> ke koperasi ini? Kas bendahara berkurang (saldo santri sudah terpotong saat scan).');">
                        <input type="hidden" name="action" value="setor_koperasi">
                        <input type="hidden" name="koperasi_id" value="<?= $kid ?>">
                        <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            <i class="fa-solid fa-vault me-1"></i> Setor Rp <?= number_format($belumTotal, 0, ',', '.') ?>
                        </button>
                    </form>
                <?php elseif ($belumJumlah > 0): ?>
                    <div class="badge text-bg-secondary w-100 mt-2 py-2">Menunggu setor admin/pengurus</div>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" disabled>Tidak ada transaksi</button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var btn = document.getElementById('btn-pilih-semua-setor');
    if (!btn) return;
    btn.addEventListener('click', function () {
        document.querySelectorAll('.kop-setor-check').forEach(function (el) {
            el.checked = true;
        });
    });
})();
</script>

<?php foreach ($panels as $panel):
    $kid = (int) ($panel['koperasi_id'] ?? 0);
    $transaksi = (array) ($panel['transaksi'] ?? []);
    if ($transaksi === []) {
        continue;
    }
    ?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold small d-flex justify-content-between align-items-center">
        <span>Rincian — <?= htmlspecialchars((string) ($panel['nama'] ?? '')) ?> · <?= htmlspecialchars($tanggalLabel) ?></span>
        <?php if (!empty($panel['sudah_setor'])): ?>
            <span class="badge text-bg-success">Sudah disetor</span>
        <?php else: ?>
            <span class="badge text-bg-warning text-dark">Belum disetor</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Keterangan</th>
                        <th class="text-end">Nominal</th>
                        <th>Status</th>
                        <?php if ($isSuperAdmin): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transaksi as $tx): ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars(app_format_datetime_id((string) ($tx['tanggal'] ?? ''))) ?></td>
                        <td class="font-monospace small"><?= htmlspecialchars((string) ($tx['nis'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($tx['nama_santri'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) (($tx['keterangan'] ?? '') !== '' ? $tx['keterangan'] : '-')) ?></td>
                        <td class="text-end fw-semibold">Rp <?= number_format((int) round((float) ($tx['nominal'] ?? 0)), 0, ',', '.') ?></td>
                        <td>
                            <?php if (!empty($tx['setor_at'])): ?>
                                <span class="badge text-bg-success">Setor</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning text-dark">Menunggu</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isSuperAdmin): ?>
                        <td class="text-end text-nowrap">
                            <?php
                            $txId = (int) ($tx['id'] ?? 0);
                            $txNominal = (int) round((float) ($tx['nominal'] ?? 0));
                            $txKet = (string) ($tx['keterangan'] ?? '');
                            $txSetor = !empty($tx['setor_at']);
                            $txNama = (string) ($tx['nama_santri'] ?? '');
                            ?>
                            <button type="button"
                                    class="btn btn-outline-primary btn-sm py-0 px-1 me-1"
                                    title="Edit"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEditDebitTx"
                                    data-tx-id="<?= $txId ?>"
                                    data-nominal="<?= $txNominal ?>"
                                    data-keterangan="<?= htmlspecialchars($txKet, ENT_QUOTES, 'UTF-8') ?>"
                                    data-nama="<?= htmlspecialchars($txNama, ENT_QUOTES, 'UTF-8') ?>"
                                    data-setor="<?= $txSetor ? '1' : '0' ?>">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="post" class="d-inline form-delete-debit-tx" data-setor="<?= $txSetor ? '1' : '0' ?>">
                                <input type="hidden" name="action" value="delete_debit_tx">
                                <input type="hidden" name="tx_id" value="<?= $txId ?>">
                                <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="card shadow-sm border-warning mb-3">
    <div class="card-header fw-semibold small bg-warning-subtle">
        Rekap cashless belum disetorkan
        <span class="text-muted fw-normal">
            (<?= htmlspecialchars(app_format_tanggal_id($rekapDari)) ?>
            – <?= htmlspecialchars(app_format_tanggal_id($rekapSampai)) ?>)
        </span>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="small text-muted">Total nominal belum setor</div>
                <div class="h5 mb-0 text-warning">Rp <?= number_format((int) ($rekapBelumSetor['total_nominal'] ?? 0), 0, ',', '.') ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Jumlah transaksi</div>
                <div class="h5 mb-0"><?= (int) ($rekapBelumSetor['total_transaksi'] ?? 0) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Hari × koperasi</div>
                <div class="h5 mb-0"><?= (int) ($rekapBelumSetor['jumlah_baris'] ?? 0) ?></div>
            </div>
        </div>
        <?php if (($rekapBelumSetor['rows'] ?? []) === []): ?>
            <p class="small text-success mb-0"><i class="fa-solid fa-circle-check me-1"></i>Tidak ada DEBIT belum disetor pada rentang ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Koperasi</th>
                            <th class="text-end">Tx</th>
                            <th class="text-end">Nominal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ((array) $rekapBelumSetor['rows'] as $br):
                        $tglB = (string) ($br['tanggal'] ?? '');
                        $setorUrl = app_href('/keuangan/cashless_setor.php?' . http_build_query([
                            'tanggal' => $tglB,
                            'rekap_dari' => $rekapDari,
                            'rekap_sampai' => $rekapSampai,
                        ]));
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= htmlspecialchars(app_format_tanggal_id($tglB)) ?></td>
                            <td><?= htmlspecialchars((string) ($br['nama'] ?? '')) ?></td>
                            <td class="text-end"><?= (int) ($br['jumlah'] ?? 0) ?></td>
                            <td class="text-end">Rp <?= number_format((int) ($br['total'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end">
                                <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars($setorUrl) ?>">Setor hari itu</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">Hanya transaksi jajan (DEBIT) dengan status belum setor. Rentang mengikuti filter di bawah.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small">Ringkasan per tanggal</div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
            <div class="col-md-4">
                <label class="form-label small">Dari</label>
                <input type="date" name="rekap_dari" class="form-control form-control-sm" value="<?= htmlspecialchars($rekapDari) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Sampai</label>
                <input type="date" name="rekap_sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($rekapSampai) ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Perbarui ringkasan</button>
            </div>
        </form>
        <?php if ($rekapTanggal === []): ?>
            <p class="small text-muted mb-0">Tidak ada transaksi pada rentang tanggal ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <?php foreach ($koperasiList as $kop): ?>
                                <th class="text-end"><?= htmlspecialchars((string) $kop['nama']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rekapTanggal as $hari): ?>
                        <?php
                        $tglRow = (string) ($hari['tanggal'] ?? '');
                        $kopMap = [];
                        foreach ((array) ($hari['koperasi'] ?? []) as $k) {
                            $kopMap[(int) ($k['koperasi_id'] ?? 0)] = $k;
                        }
                        $rowUrl = app_href('/keuangan/cashless_setor.php?tanggal=' . urlencode($tglRow));
                        ?>
                        <tr>
                            <td>
                                <a href="<?= htmlspecialchars($rowUrl) ?>" class="text-decoration-none fw-semibold">
                                    <?= htmlspecialchars(app_format_tanggal_id($tglRow)) ?>
                                </a>
                            </td>
                            <?php foreach ($koperasiList as $kop):
                                $kid = (int) ($kop['id'] ?? 0);
                                $cell = $kopMap[$kid] ?? null;
                                ?>
                                <td class="text-end small">
                                    <?php if ($cell): ?>
                                        Rp <?= number_format((int) ($cell['total'] ?? 0), 0, ',', '.') ?>
                                        <?php if (!empty($cell['sudah_setor'])): ?>
                                            <span class="badge text-bg-success ms-1">✓</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning text-dark ms-1">!</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                <span class="badge text-bg-success">✓</span> sudah disetor ·
                <span class="badge text-bg-warning text-dark">!</span> belum disetor · klik tanggal untuk detail
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="modalEditDebitTx" tabindex="-1" aria-labelledby="modalEditDebitTxLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit_debit_tx">
            <input type="hidden" name="tx_id" id="editDebitTxId" value="">
            <input type="hidden" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditDebitTxLabel">Edit transaksi cashless</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="editDebitTxSantri"></p>
                <div class="alert alert-warning small py-2 d-none" id="editDebitTxSetorWarn">
                    Transaksi ini sudah pernah disetor. Setelah diubah, periksa kesesuaian setor harian.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="editDebitNominal">Nominal (Rp)</label>
                    <input type="number" name="nominal" id="editDebitNominal" class="form-control" min="1" step="1" required>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold" for="editDebitKeterangan">Keterangan</label>
                    <input type="text" name="keterangan" id="editDebitKeterangan" class="form-control" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('modalEditDebitTx');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        document.getElementById('editDebitTxId').value = btn.getAttribute('data-tx-id') || '';
        document.getElementById('editDebitNominal').value = btn.getAttribute('data-nominal') || '';
        document.getElementById('editDebitKeterangan').value = btn.getAttribute('data-keterangan') || '';
        document.getElementById('editDebitTxSantri').textContent = 'Santri: ' + (btn.getAttribute('data-nama') || '-');
        var warn = document.getElementById('editDebitTxSetorWarn');
        if (warn) {
            warn.classList.toggle('d-none', btn.getAttribute('data-setor') !== '1');
        }
    });
    document.querySelectorAll('.form-delete-debit-tx').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = 'Hapus transaksi ini? Saldo santri akan dikembalikan.';
            if (form.getAttribute('data-setor') === '1') {
                msg += '\n\nPERINGATAN: Transaksi sudah pernah disetor.';
            }
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
