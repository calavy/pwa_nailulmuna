<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_perbaikan_kas.php';
require_once __DIR__ . '/../helpers/keuangan_diagnostik.php';
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_validasi_pesan.php';

keuangan_ensure_schema_deferred($pdo);
pembayaran_edit_token_ensure_schema($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$isSuperAdmin = is_super_admin();
$canEditFull = pembayaran_edit_token_user_boleh_edit($pdo);
$akunRows = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = keuangan_perbaikan_kas_default_akun_id($pdo);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'patch_akun') {
        $res = keuangan_perbaikan_kas_patch_akun(
            $pdo,
            (string) ($_POST['tipe'] ?? ''),
            (int) ($_POST['id'] ?? 0),
            (int) ($_POST['akun_id'] ?? 0),
            $currentUserId
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'patch_semua') {
        $res = keuangan_perbaikan_kas_patch_semua_tanpa_akun(
            $pdo,
            (int) ($_POST['akun_id'] ?? $defaultAkunId),
            $currentUserId
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'delete') {
        if (!$isSuperAdmin) {
            set_flash('error', 'Hanya super admin yang dapat menghapus transaksi.');
            header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
            exit;
        }
        $res = keuangan_perbaikan_kas_hapus(
            $pdo,
            (string) ($_POST['tipe'] ?? ''),
            (int) ($_POST['id'] ?? 0),
            $currentUserId,
            (string) ($_POST['alasan'] ?? '')
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'restore') {
        if (!$isSuperAdmin) {
            set_flash('error', 'Hanya super admin yang dapat memulihkan transaksi.');
            header('Location: ' . app_href('/keuangan/perbaikan-kas.php#riwayat-hapus'));
            exit;
        }
        $res = keuangan_perbaikan_kas_restore($pdo, (int) ($_POST['audit_id'] ?? 0), $currentUserId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php#riwayat-hapus'));
        exit;
    }

    if ($action === 'redeem_token') {
        $res = pembayaran_edit_token_redeem($pdo, $currentUserId, (string) ($_POST['token_plain'] ?? ''));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'sync_cashless') {
        require_once __DIR__ . '/../helpers/cashless_koperasi.php';
        $n = cashless_sync_all_account_balances($pdo);
        set_flash('success', 'Saldo cashless disamakan untuk ' . $n . ' akun santri.');
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'backfill_saku_topup') {
        $res = keuangan_pembayaran_backfill_saku_topup($pdo, $currentUserId, false);
        set_flash($res['ok'] ? 'success' : 'warning', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php#saku-topup'));
        exit;
    }
}

$ringkas = keuangan_perbaikan_kas_ringkas($pdo);
$diagnostik = keuangan_diagnostik_menyeluruh($pdo);
$diagRingkas = $diagnostik['ringkas'] ?? [];
$diagItems = $diagnostik['items'] ?? [];
$gajiTanpaPengeluaran = $diagnostik['gaji_tanpa_pengeluaran'] ?? [];
$sakuTanpaTopup = $diagnostik['saku_tanpa_topup'] ?? [];
$nominalBerlebihan = $diagnostik['nominal_berlebihan'] ?? ($ringkas['nominal_berlebihan'] ?? []);
$totalDetailSelisih = $diagnostik['total_detail_selisih'] ?? ($ringkas['total_detail_selisih'] ?? []);
$riwayatHapus = $isSuperAdmin ? keuangan_perbaikan_kas_list_riwayat_hapus($pdo, 80) : [];
$tokenRequired = pembayaran_edit_token_required_for_current_user();
$tokenAktif = pembayaran_edit_token_session_aktif($pdo);

$pageTitle = 'Perbaikan Kas';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';

/**
 * @param list<array<string,mixed>> $rows
 */
function perbaikan_kas_render_tabel(
    PDO $pdo,
    string $tipe,
    array $rows,
    array $akunRows,
    int $defaultAkunId,
    callable $fmt,
    bool $isSuperAdmin,
    bool $canEditFull
): void {
    if ($rows === []) {
        echo '<p class="text-muted small mb-0">Tidak ada.</p>';

        return;
    }

    $def = keuangan_kesalahan_kas_def($tipe . '_tanpa_akun');
    $keteranganUmum = (string) ($def['penjelasan'] ?? '') . ' ' . (string) ($def['dampak'] ?? '');

    echo '<p class="small text-muted px-3 pt-2 mb-0"><i class="fa-solid fa-circle-info me-1"></i> ' . htmlspecialchars($keteranganUmum) . '</p>';
    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Tanggal</th><th>Keterangan</th><th class="text-end">Nominal</th>';
    echo '<th>Kesalahan</th><th style="min-width:12rem">Perbaiki akun</th><th class="text-end">Aksi</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $keterangan = '';
        if ($tipe === 'pembayaran') {
            $keterangan = trim((string) ($row['nis'] ?? '') . ' — ' . (string) ($row['nama_santri'] ?? ''));
        } elseif ($tipe === 'pemasukan') {
            $keterangan = (string) ($row['sumber'] ?? '');
            if (!empty($row['dari_pihak'])) {
                $keterangan .= ' · ' . (string) $row['dari_pihak'];
            }
        } else {
            $keterangan = (string) ($row['pos'] ?? '') . ' · ' . (string) ($row['penanggung_jawab'] ?? '');
        }

        echo '<tr>';
        echo '<td>#' . $id . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['tanggal'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($keterangan) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) round((float) ($row['nominal'] ?? 0)))) . '</td>';
        echo '<td class="small text-danger">' . htmlspecialchars((string) ($def['judul'] ?? 'Tanpa akun kas')) . '</td>';
        echo '<td>';
        echo '<form method="post" class="d-flex gap-1 align-items-center">';
        echo '<input type="hidden" name="action" value="patch_akun">';
        echo '<input type="hidden" name="tipe" value="' . htmlspecialchars($tipe) . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<select name="akun_id" class="form-select form-select-sm" required>';
        $saranAkun = keuangan_diagnostik_saran_akun_id($pdo, $tipe, $row, $akunRows);
        foreach ($akunRows as $ar) {
            $aid = (int) ($ar['id'] ?? 0);
            $jenisAk = strtoupper(trim((string) ($ar['jenis_akun'] ?? 'KAS')));
            $sel = $aid === $saranAkun ? ' selected' : '';
            echo '<option value="' . $aid . '"' . $sel . '>' . htmlspecialchars((string) ($ar['nama_akun'] ?? '')) . ' (' . htmlspecialchars($jenisAk) . ')</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-sm btn-success text-nowrap">Simpan</button>';
        echo '</form>';
        echo '</td>';
        echo '<td class="text-end text-nowrap">';
        echo '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars(keuangan_perbaikan_kas_edit_url($tipe, $id)) . '">Edit penuh</a> ';
        if ($isSuperAdmin || ($tipe !== 'pembayaran' && $canEditFull)) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#hapusModal" ';
            echo 'data-tipe="' . htmlspecialchars($tipe) . '" data-id="' . $id . '" data-label="' . htmlspecialchars($keterangan) . '">Hapus</button>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a> · Koreksi</p>
    <h1 class="h4 mb-1">Perbaikan Kas</h1>
    <p class="text-muted mb-0 small">
        Transaksi tanpa akun kas/bank menyebabkan selisih antara saldo hitung dan saldo fisik.
        Input baru <strong>sudah ditolak</strong> jika akun tidak dipilih atau nominal melebihi sisa tagihan.
        Perbaiki data lama di sini — termasuk nominal berlebihan sebelum validasi ketat — hapus jika entri ganda, atau pulihkan dari riwayat hapus di bawah.
    </p>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Prioritas tinggi</div>
            <div class="app-mini-stat-value text-danger"><?= (int) ($diagRingkas['tinggi'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Prioritas sedang</div>
            <div class="app-mini-stat-value text-warning"><?= (int) ($diagRingkas['sedang'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tanpa akun kas</div>
            <div class="app-mini-stat-value"><?= (int) ($diagRingkas['tanpa_akun'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Nominal tanpa akun</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($diagRingkas['nominal_tanpa_akun'] ?? 0))) ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><i class="fa-solid fa-stethoscope me-1"></i> Saran perbaikan menyeluruh</strong>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($sakuTanpaTopup !== []): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Buat top-up cashless untuk <?= count($sakuTanpaTopup) ?> pembayaran Saku yang belum punya TOPUP?');">
                <input type="hidden" name="action" value="backfill_saku_topup">
                <button type="submit" class="btn btn-sm btn-warning">Backfill top-up saku (<?= count($sakuTanpaTopup) ?>)</button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Samakan saldo cashless semua santri dari ledger transaksi?');">
                <input type="hidden" name="action" value="sync_cashless">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Samakan saldo cashless</button>
            </form>
            <a href="<?= htmlspecialchars(app_href('/keuangan/neraca-perbaikan.php')) ?>" class="btn btn-sm btn-outline-primary">Sinkron jurnal / neraca</a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($diagItems === []): ?>
            <p class="text-muted small mb-0 px-3 py-3">Tidak ada temuan diagnostik khusus.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($diagItems as $item): ?>
                    <?php
                    $prio = (string) ($item['prioritas'] ?? 'rendah');
                    $badge = match ($prio) {
                        'tinggi' => 'danger',
                        'sedang' => 'warning',
                        default => 'secondary',
                    };
                    ?>
                    <li class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="flex-grow-1">
                                <span class="badge text-bg-<?= $badge ?> me-1"><?= htmlspecialchars(ucfirst($prio)) ?></span>
                                <strong><?= htmlspecialchars((string) ($item['judul'] ?? '')) ?></strong>
                                <?php if ((int) ($item['jumlah'] ?? 0) > 0): ?>
                                    <span class="text-muted small">· <?= (int) $item['jumlah'] ?> kasus</span>
                                <?php endif; ?>
                                <?php if ((int) ($item['nominal'] ?? 0) > 0): ?>
                                    <span class="text-muted small">· <?= htmlspecialchars((string) ($item['nominal_fmt'] ?? '')) ?></span>
                                <?php endif; ?>
                                <p class="small text-muted mb-0 mt-1"><?= htmlspecialchars((string) ($item['penjelasan'] ?? '')) ?></p>
                                <?php if ((string) ($item['solusi'] ?? '') !== ''): ?>
                                    <p class="small mb-0"><em><?= htmlspecialchars((string) $item['solusi']) ?></em></p>
                                <?php endif; ?>
                            </div>
                            <a href="<?= htmlspecialchars(app_href((string) ($item['href_aksi'] ?? '/keuangan/perbaikan-kas.php'))) ?>" class="btn btn-sm btn-outline-primary align-self-start">
                                <?= htmlspecialchars((string) ($item['href_label'] ?? 'Detail')) ?>
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($ringkas['jumlah'] === 0 && ($ringkas['duplikat'] ?? []) === [] && $gajiTanpaPengeluaran === [] && $sakuTanpaTopup === [] && $nominalBerlebihan === [] && $totalDetailSelisih === []): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-1"></i>
    Semua transaksi kas sudah terhubung ke akun. Tidak ada yang perlu diperbaiki.
    <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>" class="alert-link">Lihat rekap kas</a>
</div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Tanpa akun kas</div>
            <div class="app-mini-stat-value text-warning"><?= (int) $ringkas['jumlah'] ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total nominal bermasalah</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $ringkas['nominal'])) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kemungkinan dobel</div>
            <div class="app-mini-stat-value"><?= count($ringkas['duplikat'] ?? []) ?></div>
        </div>
    </div>
</div>

<?php if ($ringkas['jumlah'] > 0 && $akunRows !== []): ?>
<div class="card shadow-sm mb-3 border-warning">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> Perbaiki semua sekaligus</h2>
        <p class="small text-muted mb-2">Hubungkan semua transaksi tanpa akun ke satu akun. Untuk transfer, pilih akun BANK; untuk tunai pilih KAS.</p>
        <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Perbaiki semua transaksi tanpa akun ke akun yang dipilih?');">
            <input type="hidden" name="action" value="patch_semua">
            <div class="col-md-6">
                <label class="form-label small">Akun kas tujuan</label>
                <select name="akun_id" class="form-select" required>
                    <?php foreach ($akunRows as $ar): ?>
                        <option value="<?= (int) ($ar['id'] ?? 0) ?>" <?= (int) ($ar['id'] ?? 0) === $defaultAkunId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?>
                            (<?= htmlspecialchars((string) ($ar['jenis_akun'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-warning">
                    Perbaiki <?= (int) $ringkas['jumlah'] ?> transaksi
                </button>
            </div>
        </form>
    </div>
</div>
<?php elseif ($akunRows === []): ?>
<div class="alert alert-danger">
    Belum ada akun kas aktif.
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=akun')) ?>">Buat akun kas</a> terlebih dahulu.
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tokenRequired && !$tokenAktif): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Token super admin (untuk hapus pemasukan/pengeluaran)</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="redeem_token">
            <div class="col-md-8">
                <input type="password" name="token_plain" class="form-control" placeholder="Token edit" autocomplete="off" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary">Aktifkan token</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pembayaran'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3" id="pembayaran">
    <div class="card-header bg-white">
        <strong>Pembayaran santri tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pembayaran']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel($pdo, 'pembayaran', $ringkas['pembayaran'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pemasukan'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3" id="pemasukan">
    <div class="card-header bg-white">
        <strong>Pemasukan lain tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pemasukan']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel($pdo, 'pemasukan', $ringkas['pemasukan'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pengeluaran'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3" id="pengeluaran">
    <div class="card-header bg-white">
        <strong>Pengeluaran tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pengeluaran']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel($pdo, 'pengeluaran', $ringkas['pengeluaran'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($sakuTanpaTopup !== []): ?>
<div class="card shadow-sm mb-3 border-warning" id="saku-topup">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <strong>Pembayaran Saku tanpa top-up cashless</strong>
            <span class="badge bg-warning text-dark ms-1"><?= count($sakuTanpaTopup) ?></span>
        </div>
        <form method="post" class="d-inline" onsubmit="return confirm('Buat top-up cashless untuk semua pembayaran di bawah?');">
            <input type="hidden" name="action" value="backfill_saku_topup">
            <button type="submit" class="btn btn-sm btn-warning">Backfill semua top-up</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>ID bayar</th><th>Santri</th><th>Tanggal</th><th class="text-end">Nominal saku</th></tr></thead>
                <tbody>
                <?php foreach ($sakuTanpaTopup as $so): ?>
                    <tr>
                        <td>#<?= (int) ($so['pembayaran_id'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($so['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($so['tanggal_bayar'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($so['nominal_saku'] ?? 0)))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($gajiTanpaPengeluaran !== []): ?>
<div class="card shadow-sm mb-3" id="gaji">
    <div class="card-header bg-white">
        <strong>Gaji pembimbing tanpa pengeluaran kas</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($gajiTanpaPengeluaran) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>ID</th><th>Periode</th><th class="text-end">Nominal</th><th>Keterangan</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($gajiTanpaPengeluaran as $g): ?>
                    <tr>
                        <td>#<?= (int) ($g['id'] ?? 0) ?></td>
                        <td>Bulan <?= (int) ($g['bulan_tagihan'] ?? 0) ?> · TA <?= (int) ($g['tahun_ajaran_mulai'] ?? 0) ?>/<?= (int) ($g['tahun_ajaran_selesai'] ?? 0) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($g['total_bayar'] ?? 0)))) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($g['keterangan'] ?? '—')) ?></td>
                        <td class="text-end"><a href="<?= htmlspecialchars(app_href('/rekap/pembimbing.php')) ?>" class="btn btn-sm btn-outline-primary">Kelola gaji</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['duplikat'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3 border-danger" id="duplikat">
    <div class="card-header bg-white">
        <strong>Kemungkinan pembayaran dobel</strong>
        <span class="badge bg-danger ms-1"><?= count($ringkas['duplikat']) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Santri</th>
                        <th>Tanggal</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Jumlah baris</th>
                        <th>ID</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ringkas['duplikat'] as $dup): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dup['nis'] ?? '') . ' — ' . (string) ($dup['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($dup['tanggal_bayar'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($dup['nominal'] ?? 0)))) ?></td>
                        <td class="text-center"><span class="badge bg-danger"><?= (int) ($dup['jumlah'] ?? 0) ?>×</span></td>
                        <td class="small text-muted">#<?= (int) ($dup['id_pertama'] ?? 0) ?>–#<?= (int) ($dup['id_terakhir'] ?? 0) ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat_edit.php?id=' . (int) ($dup['id_terakhir'] ?? 0))) ?>" class="btn btn-sm btn-outline-danger">Periksa &amp; hapus</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted px-3 py-2 mb-0">
            <?php $dupDef = keuangan_kesalahan_kas_def('pembayaran_dobel'); ?>
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= htmlspecialchars((string) $dupDef['penjelasan'] . ' ' . $dupDef['dampak']) ?>
            Input baru sudah ditolak jika pos wajib sudah lunas. Hapus baris duplikat lewat edit pembayaran (super admin + alasan).
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($nominalBerlebihan !== []): ?>
<div class="card shadow-sm mb-3 border-warning" id="nominal-berlebihan">
    <div class="card-header bg-white">
        <strong>Pembayaran melebihi tagihan periode</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($nominalBerlebihan) ?></span>
    </div>
    <div class="card-body p-0">
        <?php $nbDef = keuangan_kesalahan_kas_def('pembayaran_nominal_berlebihan'); ?>
        <p class="small text-muted px-3 pt-2 mb-0">
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= htmlspecialchars((string) $nbDef['penjelasan'] . ' ' . $nbDef['dampak']) ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Santri</th>
                        <th>Periode</th>
                        <th>Pos</th>
                        <th class="text-end">Tagihan</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Kelebihan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($nominalBerlebihan as $nb): ?>
                    <?php
                    $jp = (string) ($nb['jenis_periode'] ?? 'BULANAN');
                    $periodeLabel = $jp === 'AWAL_TAHUN'
                        ? 'Awal tahun TA ' . (int) ($nb['tahun_ajaran_mulai'] ?? 0) . '/' . (int) ($nb['tahun_ajaran_selesai'] ?? 0)
                        : 'Bulan ' . (int) ($nb['bulan_tagihan'] ?? 0) . ' · TA ' . (int) ($nb['tahun_ajaran_mulai'] ?? 0) . '/' . (int) ($nb['tahun_ajaran_selesai'] ?? 0);
                    $pid = (int) ($nb['pembayaran_id'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($nb['nis'] ?? '') . ' — ' . (string) ($nb['nama_santri'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars($periodeLabel) ?></td>
                        <td><?= htmlspecialchars((string) ($nb['pos_nama'] ?? $nb['pos_slug'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($nb['expected'] ?? 0))) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($nb['total_paid'] ?? 0))) ?></td>
                        <td class="text-end text-danger fw-semibold"><?= htmlspecialchars($fmt((int) ($nb['kelebihan'] ?? 0))) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($pid > 0): ?>
                            <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat_edit.php?id=' . $pid)) ?>" class="btn btn-sm btn-outline-warning">Edit pembayaran</a>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted px-3 py-2 mb-0">
            <em><?= htmlspecialchars((string) $nbDef['solusi']) ?></em>
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($totalDetailSelisih !== []): ?>
<div class="card shadow-sm mb-3 border-warning" id="total-detail-selisih">
    <div class="card-header bg-white">
        <strong>Total pembayaran ≠ jumlah detail</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($totalDetailSelisih) ?></span>
    </div>
    <div class="card-body p-0">
        <?php $tdDef = keuangan_kesalahan_kas_def('pembayaran_total_detail_selisih'); ?>
        <p class="small text-muted px-3 pt-2 mb-0">
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= htmlspecialchars((string) $tdDef['penjelasan'] . ' ' . $tdDef['dampak']) ?>
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tanggal</th>
                        <th>Santri</th>
                        <th class="text-end">Total header</th>
                        <th class="text-end">Jumlah detail</th>
                        <th class="text-end">Selisih</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($totalDetailSelisih as $td): ?>
                    <?php
                    $totalHdr = (int) round((float) ($td['total_nominal'] ?? 0));
                    $sumDet = (int) round((float) ($td['sum_detail'] ?? 0));
                    $sel = $totalHdr - $sumDet;
                    ?>
                    <tr>
                        <td>#<?= (int) ($td['id'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($td['tanggal_bayar'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($td['nis'] ?? '') . ' — ' . (string) ($td['nama_santri'] ?? ''), ' —')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt($totalHdr)) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt($sumDet)) ?></td>
                        <td class="text-end text-danger"><?= htmlspecialchars($fmt(abs($sel))) ?><?= $sel > 0 ? ' (+)' : ($sel < 0 ? ' (−)' : '') ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat_edit.php?id=' . (int) ($td['id'] ?? 0))) ?>" class="btn btn-sm btn-outline-warning">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isSuperAdmin): ?>
<div class="card shadow-sm mb-3" id="riwayat-hapus">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat hapus</strong>
        <span class="badge bg-secondary"><?= count($riwayatHapus) ?> dapat dipulihkan</span>
    </div>
    <div class="card-body p-0">
        <?php if ($riwayatHapus === []): ?>
            <p class="text-muted small mb-0 px-3 py-3">Belum ada transaksi kas yang dihapus, atau semua sudah dipulihkan.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Jenis</th>
                            <th>ID</th>
                            <th>Ringkasan</th>
                            <th>Alasan hapus</th>
                            <th>Petugas</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($riwayatHapus as $rh): ?>
                        <?php
                        $tipeRh = (string) ($rh['tipe'] ?? '');
                        $tipeLabel = match ($tipeRh) {
                            'pembayaran' => 'Pembayaran',
                            'pemasukan' => 'Pemasukan',
                            'pengeluaran' => 'Pengeluaran',
                            default => ucfirst($tipeRh),
                        };
                        ?>
                        <tr>
                            <td class="small text-nowrap"><?= htmlspecialchars((string) ($rh['created_at'] ?? '')) ?></td>
                            <td><span class="badge text-bg-light border"><?= htmlspecialchars($tipeLabel) ?></span></td>
                            <td class="font-monospace">#<?= (int) ($rh['entity_id'] ?? 0) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($rh['ringkas'] ?? '—')) ?></td>
                            <td class="small" style="max-width:14rem;"><?= nl2br(htmlspecialchars((string) ($rh['alasan'] ?? ''))) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($rh['user_nama'] ?? '—')) ?></td>
                            <td class="text-end text-nowrap">
                                <form method="post" class="d-inline" onsubmit="return confirm('Pulihkan transaksi #<?= (int) ($rh['entity_id'] ?? 0) ?> (<?= htmlspecialchars($tipeLabel) ?>)?');">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="audit_id" value="<?= (int) ($rh['audit_id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="fa-solid fa-rotate-left me-1"></i> Pulihkan
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted px-3 py-2 mb-0">
                Transaksi yang dipulihkan kembali ke database dengan ID asli.
                Jika belum punya akun kas, perbaiki lagi di tabel di atas.
                Log lengkap: <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat_audit.php')) ?>">audit operasional</a>.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="hapusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tipe" id="hapusTipe">
            <input type="hidden" name="id" id="hapusId">
            <div class="modal-header">
                <h5 class="modal-title">Hapus transaksi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Anda akan menghapus: <strong id="hapusLabel"></strong></p>
                <label class="form-label">Alasan penghapusan <span class="text-danger">*</span></label>
                <textarea name="alasan" class="form-control" rows="3" required placeholder="Contoh: entri ganda, salah input"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus permanen</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('hapusModal')?.addEventListener('show.bs.modal', function (ev) {
    const btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('hapusTipe').value = btn.getAttribute('data-tipe') || '';
    document.getElementById('hapusId').value = btn.getAttribute('data-id') || '';
    document.getElementById('hapusLabel').textContent = btn.getAttribute('data-label') || '';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
