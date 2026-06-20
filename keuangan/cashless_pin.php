<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

require_roles(['admin', 'pengurus']);
require_once __DIR__ . '/../helpers/santri_list_sort.php';

keuangan_ensure_schema_deferred($pdo);
santri_list_sort_mode($_GET['santri_sort'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'save_cashless_limit') {
        $limitRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['batas_harian'] ?? '10000')) ?? '10000';
        $limit = max(0, (int) $limitRaw);
        save_setting($pdo, 'cashless_daily_limit', (string) $limit);
        set_flash('success', 'Batas harian cashless berhasil disimpan.');
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_scan_uang_setting') {
        $isEnabled = (int) ($_POST['cashless_scan_uang_enabled'] ?? 0) === 1 ? '1' : '0';
        $isVoice = (int) ($_POST['cashless_scan_uang_voice'] ?? 0) === 1 ? '1' : '0';
        $maxNominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['cashless_scan_uang_max_nominal'] ?? '200000')) ?? '200000';
        $maxNominal = max(1000, (int) $maxNominalRaw);
        save_setting($pdo, 'cashless_scan_uang_enabled', $isEnabled);
        save_setting($pdo, 'cashless_scan_uang_voice', $isVoice);
        save_setting($pdo, 'cashless_scan_uang_max_nominal', (string) $maxNominal);
        set_flash('success', 'Pengaturan scan uang cashless berhasil disimpan.');
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'create_qr_nominal_map') {
        $kode = cashless_normalize_money_qr_payload((string) ($_POST['map_kode_qr'] ?? ''));
        $nominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['map_nominal'] ?? '0')) ?? '0';
        $nominal = (int) $nominalRaw;
        $ket = trim((string) ($_POST['map_keterangan'] ?? ''));
        if ($kode === '' || strlen($kode) > 120) {
            set_flash('error', 'Kode QR wajib diisi (alfanumerik, maks. 120 karakter setelah normalisasi).');
            header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
            exit;
        }
        if ($nominal <= 0) {
            set_flash('error', 'Nominal harus lebih dari 0.');
            header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
            exit;
        }
        try {
            $pdo->prepare('
                INSERT INTO cashless_nominal_qr_map (kode_qr, nominal, keterangan, is_aktif)
                VALUES (:kode, :nominal, :ket, 1)
            ')->execute(['kode' => $kode, 'nominal' => $nominal, 'ket' => $ket !== '' ? $ket : null]);
            set_flash('success', 'Peta QR nominal ditambahkan. Isi QR dengan teks: ' . $kode . ' (atau huruf kecil; sistem menyesuaikan).');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menyimpan: kode mungkin sudah dipakai.');
        }
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'update_qr_nominal_map') {
        $id = (int) ($_POST['map_id'] ?? 0);
        $nominalRaw = preg_replace('/[^0-9]/', '', (string) ($_POST['map_nominal'] ?? '0')) ?? '0';
        $nominal = (int) $nominalRaw;
        $ket = trim((string) ($_POST['map_keterangan'] ?? ''));
        $aktif = (int) ($_POST['map_is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($id <= 0 || $nominal <= 0) {
            set_flash('error', 'Data peta QR tidak valid.');
        } else {
            $pdo->prepare('
                UPDATE cashless_nominal_qr_map
                SET nominal = :nominal, keterangan = :ket, is_aktif = :aktif
                WHERE id = :id
            ')->execute([
                'nominal' => $nominal,
                'ket' => $ket !== '' ? $ket : null,
                'aktif' => $aktif,
                'id' => $id,
            ]);
            set_flash('success', 'Peta QR nominal diperbarui.');
        }
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'delete_qr_nominal_map') {
        $id = (int) ($_POST['map_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM cashless_nominal_qr_map WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Peta QR nominal dihapus.');
        }
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#peta-qr-nominal'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_cashless_pin') {
        $santriId = (int) ($_POST['santri_id'] ?? 0);
        $pinBaru = trim((string) ($_POST['pin_baru'] ?? ''));
        $pinKonf = trim((string) ($_POST['pin_konfirmasi'] ?? ''));
        if ($santriId <= 0) {
            set_flash('error', 'Pilih santri.');
            header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pin#form-pin-cashless'));
            exit;
        }
        if (strlen($pinBaru) < 4) {
            set_flash('error', 'PIN minimal 4 digit.');
            header('Location: ' . app_rewrite_internal_url('/keuangan/cashless_pin.php?tab=pin&ubah_pin=' . $santriId . '#form-pin-cashless'));
            exit;
        }
        if ($pinBaru !== $pinKonf) {
            set_flash('error', 'PIN dan konfirmasi PIN tidak sama.');
            header('Location: ' . app_rewrite_internal_url('/keuangan/cashless_pin.php?tab=pin&ubah_pin=' . $santriId . '#form-pin-cashless'));
            exit;
        }
        $pdo->prepare('INSERT IGNORE INTO cashless_accounts (santri_id, balance) VALUES (:santri_id, 0)')->execute(['santri_id' => $santriId]);
        $pdo->prepare('UPDATE cashless_accounts SET pin_hash = :pin_hash WHERE santri_id = :santri_id')->execute([
            'pin_hash' => password_hash($pinBaru, PASSWORD_DEFAULT),
            'santri_id' => $santriId,
        ]);
        set_flash('success', 'PIN cashless berhasil disimpan.');
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pin#form-pin-cashless'));
        exit;
    }
    if (($_POST['action'] ?? '') === 'save_koperasi_cashless') {
        foreach (cashless_koperasi_list($pdo, false) as $kop) {
            $id = (int) $kop['id'];
            $nama = trim((string) ($_POST['koperasi_nama_' . $id] ?? ''));
            if ($nama !== '') {
                save_setting($pdo, cashless_koperasi_nama_setting_key($id), $nama);
            }
            $pw = trim((string) ($_POST['koperasi_password_' . $id] ?? ''));
            if ($pw !== '') {
                save_setting($pdo, cashless_koperasi_password_setting_key($id), password_hash($pw, PASSWORD_DEFAULT));
            }
        }
        set_flash('success', 'Pengaturan koperasi cashless berhasil disimpan.');
        header('Location: ' . app_href('/keuangan/cashless_pin.php?tab=pengaturan#koperasi-cashless'));
        exit;
    }
}

$rekapSaldo = cashless_rekap_saldo_santri($pdo);
$santriPinStatusRows = $rekapSaldo['rows'];
$rekapSummary = $rekapSaldo['summary'];
$dailyLimit = (int) ($rekapSaldo['daily_limit'] ?? 10000);

$scanUangEnabled = app_setting($pdo, 'cashless_scan_uang_enabled', '1') === '1';
$scanUangVoice = app_setting($pdo, 'cashless_scan_uang_voice', '1') === '1';
$scanUangMaxNominal = max(1000, (int) app_setting($pdo, 'cashless_scan_uang_max_nominal', '200000'));
$qrNominalMapRows = $pdo->query('
    SELECT id, kode_qr, nominal, keterangan, is_aktif
    FROM cashless_nominal_qr_map
    ORDER BY is_aktif DESC, nominal ASC, kode_qr ASC
')->fetchAll();

$ubahPinSantriId = (int) ($_GET['ubah_pin'] ?? 0);
$allowedTabs = ['rekap', 'pin', 'pengaturan'];
$activeTab = (string) ($_GET['tab'] ?? 'rekap');
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'rekap';
}
if ($ubahPinSantriId > 0) {
    $activeTab = 'pin';
}

$santriNameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
$santriLevelExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
$joinKelas = '';
if (!column_exists($pdo, 'santri', 'tingkatan') && column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
    $joinKelas = ' LEFT JOIN kelas k ON k.id = s.kelas_id ';
    $santriLevelExpr = 'k.nama_kelas';
}
$whereAktif = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE s.is_aktif = 1 ' : '';
$orderBySantri = santri_list_order_sql('s');
$santriRows = $pdo->query("
    SELECT s.id, s.nis, {$santriNameExpr} AS nama_santri, {$santriLevelExpr} AS tingkatan
    FROM santri s
    {$joinKelas}
    {$whereAktif}
    ORDER BY {$orderBySantri}
")->fetchAll();

$koperasiList = cashless_koperasi_list($pdo, false);

$pageTitle = 'Rekap Saldo & PIN Cashless';
require_once __DIR__ . '/../includes/header.php';
$flashOk = get_flash('success');
$flashErr = get_flash('error');
$tabBaseUrl = app_href('/keuangan/cashless_pin.php');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <p class="page-intro-kicker mb-1 small"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-pengaturan')) ?>">Pengaturan</a> · <a href="<?= htmlspecialchars(app_href('/keuangan/cashless.php')) ?>">Cashless</a></p>
        <h1 class="h4 mb-0">Rekap Saldo &amp; PIN Cashless</h1>
        <p class="small text-muted mb-0">Laporan saldo uang saku per santri, status PIN, dan pengaturan cashless dalam satu halaman.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_scan.php')) ?>" class="btn btn-outline-danger btn-sm">Scan cashless</a>
        <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php')) ?>" class="btn btn-outline-secondary btn-sm">Laporan koperasi</a>
    </div>
</div>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link<?= $activeTab === 'rekap' ? ' active' : '' ?>" href="<?= htmlspecialchars($tabBaseUrl . '?tab=rekap') ?>">Rekap saldo</a></li>
    <li class="nav-item"><a class="nav-link<?= $activeTab === 'pin' ? ' active' : '' ?>" href="<?= htmlspecialchars($tabBaseUrl . '?tab=pin#form-pin-cashless') ?>">Pengaturan PIN</a></li>
    <li class="nav-item"><a class="nav-link<?= $activeTab === 'pengaturan' ? ' active' : '' ?>" href="<?= htmlspecialchars($tabBaseUrl . '?tab=pengaturan') ?>">Pengaturan cashless</a></li>
</ul>

<?php if ($activeTab === 'rekap'): ?>
<div id="rekap-saldo-cashless">
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Total saldo</div>
                <div class="app-mini-stat-value text-success">Rp <?= number_format((int) $rekapSummary['total_saldo'], 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Santri bersaldo</div>
                <div class="app-mini-stat-value"><?= (int) $rekapSummary['jumlah_bersaldo'] ?> <span class="fs-6 text-muted fw-normal">/ <?= (int) $rekapSummary['total_santri'] ?></span></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">PIN sudah diatur</div>
                <div class="app-mini-stat-value text-primary"><?= (int) $rekapSummary['pin_sudah'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Belum PIN · batas/hari</div>
                <div class="app-mini-stat-value"><span class="text-warning"><?= (int) $rekapSummary['pin_belum'] ?></span> <span class="fs-6 text-muted fw-normal">· Rp <?= number_format($dailyLimit, 0, ',', '.') ?></span></div>
            </div>
        </div>
    </div>
    <div class="alert alert-info small">
        Saldo = top-up pos <em>Saku</em> − belanja cashless. Batas belanja harian per santri: <strong>Rp <?= number_format($dailyLimit, 0, ',', '.') ?></strong>.
    </div>
    <div class="card shadow-sm">
        <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <strong class="small">Rekap saldo per santri</strong>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <select id="rekap-filter-pin" class="form-select form-select-sm" style="max-width:11rem">
                    <option value="">Semua PIN</option>
                    <option value="sudah">PIN sudah</option>
                    <option value="belum">Belum PIN</option>
                </select>
                <select id="rekap-filter-saldo" class="form-select form-select-sm" style="max-width:11rem">
                    <option value="">Semua saldo</option>
                    <option value="ada">Ada saldo</option>
                    <option value="kosong">Saldo nol</option>
                </select>
                <input type="search" id="rekap-search" class="form-control form-control-sm" placeholder="Cari NIS / nama…" style="max-width:14rem">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Cetak</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="rekap-saldo-table">
                <thead class="table-light">
                    <tr class="small text-muted text-uppercase">
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Tingkatan</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-end d-none d-md-table-cell">Top-up</th>
                        <th class="text-end d-none d-md-table-cell">Belanja</th>
                        <th class="text-end d-none d-lg-table-cell">Pakai hari ini</th>
                        <th class="text-end d-none d-lg-table-cell">Sisa jatah</th>
                        <th>PIN</th>
                        <th class="text-end d-print-none">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($santriPinStatusRows): foreach ($santriPinStatusRows as $sr):
                    $saldo = (int) ($sr['saldo'] ?? 0);
                    $pinOk = (int) ($sr['pin_terpasang'] ?? 0) === 1;
                    ?>
                    <tr data-pin="<?= $pinOk ? 'sudah' : 'belum' ?>" data-saldo="<?= $saldo > 0 ? 'ada' : 'kosong' ?>">
                        <td class="font-monospace small"><?= htmlspecialchars((string) $sr['nis']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $sr['nama_santri']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) (($sr['tingkatan'] ?? '') !== '' ? $sr['tingkatan'] : '—')) ?></td>
                        <td class="text-end font-monospace fw-semibold <?= $saldo > 0 ? 'text-success' : 'text-muted' ?>">Rp <?= number_format($saldo, 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small d-none d-md-table-cell">Rp <?= number_format((int) ($sr['total_topup'] ?? 0), 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small d-none d-md-table-cell">Rp <?= number_format((int) ($sr['total_debit'] ?? 0), 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small d-none d-lg-table-cell">Rp <?= number_format((int) ($sr['debit_hari_ini'] ?? 0), 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small d-none d-lg-table-cell">Rp <?= number_format((int) ($sr['sisa_jatah_hari'] ?? 0), 0, ',', '.') ?></td>
                        <td>
                            <?php if ($pinOk): ?>
                                <span class="badge text-bg-success-subtle text-success border border-success-subtle">Sudah</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap d-print-none">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/cashless_pin.php?tab=pin&ubah_pin=' . (int) $sr['id'] . '#form-pin-cashless')) ?>">Ubah PIN</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Tidak ada data santri aktif.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($santriPinStatusRows !== []): ?>
                <tfoot class="table-light">
                    <tr class="fw-semibold small">
                        <td colspan="3">Total (<?= (int) $rekapSummary['total_santri'] ?> santri)</td>
                        <td class="text-end font-monospace text-success">Rp <?= number_format((int) $rekapSummary['total_saldo'], 0, ',', '.') ?></td>
                        <td colspan="6"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var search = document.getElementById('rekap-search');
    var filterPin = document.getElementById('rekap-filter-pin');
    var filterSaldo = document.getElementById('rekap-filter-saldo');
    var table = document.getElementById('rekap-saldo-table');
    if (!table) { return; }
    function applyFilter() {
        var q = search ? (search.value || '').toLowerCase().trim() : '';
        var pin = filterPin ? filterPin.value : '';
        var saldo = filterSaldo ? filterSaldo.value : '';
        table.querySelectorAll('tbody tr[data-pin]').forEach(function (tr) {
            var hay = (tr.innerText || '').toLowerCase();
            var ok = (q === '' || hay.indexOf(q) !== -1)
                && (pin === '' || tr.getAttribute('data-pin') === pin)
                && (saldo === '' || tr.getAttribute('data-saldo') === saldo);
            tr.style.display = ok ? '' : 'none';
        });
    }
    if (search) { search.addEventListener('input', applyFilter); }
    if (filterPin) { filterPin.addEventListener('change', applyFilter); }
    if (filterSaldo) { filterSaldo.addEventListener('change', applyFilter); }
})();
</script>
<style>@media print { .nav-tabs, .btn, .form-select, .form-control, .d-print-none, .app-sidebar, .app-topbar { display: none !important; } }</style>

<?php elseif ($activeTab === 'pin'): ?>
<div class="row g-3 justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm" id="form-pin-cashless">
            <div class="card-header fw-semibold small">Atur / ganti PIN cashless</div>
            <div class="card-body">
                <p class="small text-muted">PIN dipakai saat scan belanja dan bisa dipakai login <a href="<?= htmlspecialchars(app_href('/santri_portal/login.php')) ?>" target="_blank" rel="noopener">portal santri</a> jika PIN portal belum diatur.</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_cashless_pin">
                    <div class="mb-2">
                        <label class="form-label">Santri</label>
                        <select class="form-select" name="santri_id" id="select-santri-pin" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriRows as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $ubahPinSantriId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['nis']) ?> — <?= htmlspecialchars((string) $s['nama_santri']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">PIN baru (min. 4 digit)</label>
                        <input type="password" name="pin_baru" class="form-control" minlength="4" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konfirmasi PIN</label>
                        <input type="password" name="pin_konfirmasi" class="form-control" minlength="4" autocomplete="new-password" required>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary">Simpan PIN</button>
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($tabBaseUrl . '?tab=rekap') ?>">Lihat rekap saldo</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="row g-3">
    <div class="col-lg-12">
        <div class="card shadow-sm mb-3" id="koperasi-cashless">
            <div class="card-header fw-semibold small">Portal Koperasi (login petugas)</div>
            <div class="card-body">
                <p class="small text-muted">Atur nama tampilan dan password login untuk masing-masing koperasi. Petugas masuk lewat <a href="<?= htmlspecialchars(app_href('/koperasi/index.php')) ?>" target="_blank" rel="noopener">Portal Koperasi</a>.</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_koperasi_cashless">
                    <?php foreach ($koperasiList as $kop):
                        $kid = (int) $kop['id'];
                        $pwSet = trim((string) app_setting($pdo, cashless_koperasi_password_setting_key($kid), '')) !== '';
                        ?>
                        <div class="border rounded p-3 mb-2">
                            <div class="fw-semibold small mb-2"><?= htmlspecialchars((string) $kop['nama']) ?></div>
                            <div class="mb-2">
                                <label class="form-label small">Nama tampilan</label>
                                <input type="text" name="koperasi_nama_<?= $kid ?>" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $kop['nama']) ?>" maxlength="120">
                            </div>
                            <div class="mb-0">
                                <label class="form-label small">Password login petugas <?= $pwSet ? '<span class="text-success">(sudah diatur)</span>' : '<span class="text-warning">(belum diatur)</span>' ?></label>
                                <input type="password" name="koperasi_password_<?= $kid ?>" class="form-control form-control-sm" placeholder="Kosongkan jika tidak ingin mengubah" autocomplete="new-password">
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan koperasi</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header fw-semibold small">Uang saku &amp; batas harian</div>
            <div class="card-body">
                <form method="post" class="mb-3 border rounded p-3 bg-light-subtle">
                    <input type="hidden" name="action" value="save_cashless_limit">
                    <label class="form-label">Batas belanja harian cashless</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" name="batas_harian" class="form-control" value="<?= number_format($dailyLimit, 0, ',', '.') ?>" required>
                        <button class="btn btn-outline-primary">Simpan</button>
                    </div>
                    <div class="form-text">Maksimal total belanja cashless per santri per hari (contoh: Rp 10.000). Saldo dari top-up Saku bisa lebih besar, tetapi pemakaian harian tetap dibatasi.</div>
                </form>
                <form method="post" class="mb-3 border rounded p-3">
                    <input type="hidden" name="action" value="save_scan_uang_setting">
                    <label class="form-label fw-semibold">Pengaturan Scan Uang (Nominal via Kamera)</label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Mode Scan Uang</label>
                            <select class="form-select" name="cashless_scan_uang_enabled">
                                <option value="1" <?= $scanUangEnabled ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= !$scanUangEnabled ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Suara Transaksi</label>
                            <select class="form-select" name="cashless_scan_uang_voice">
                                <option value="1" <?= $scanUangVoice ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= !$scanUangVoice ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Batas Maks Nominal per Scan</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="text" class="form-control" name="cashless_scan_uang_max_nominal" value="<?= number_format($scanUangMaxNominal, 0, ',', '.') ?>">
                            </div>
                            <div class="form-text">Nominal setiap QR mengikuti <strong>Peta QR nominal</strong> di bawah; nilai tidak boleh melebihi batas ini.</div>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary mt-2">Simpan Pengaturan Scan Uang</button>
                </form>
                <div class="mb-3 border rounded p-3 bg-light-subtle" id="peta-qr-nominal">
                    <h2 class="h6">Peta QR nominal tetap (scan uang)</h2>
                    <p class="small text-muted mb-2">
                        Buat QR dengan <strong>teks biasa</strong> sesuai kode di sini. Contoh kode <code>1hhey45ljsj</code> → nominal Rp 5.000.
                        Huruf besar/kecil diabaikan; karakter selain huruf/angka dihapus saat scan. Kode bisa dipakai berulang.
                    </p>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="create_qr_nominal_map">
                        <div class="col-md-4">
                            <label class="form-label small mb-0">Kode isi QR</label>
                            <input type="text" class="form-control form-control-sm" name="map_kode_qr" maxlength="120" placeholder="1hhey45ljsj" required autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Nominal (Rp)</label>
                            <input type="text" class="form-control form-control-sm" name="map_nominal" placeholder="5000" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">Keterangan (opsional)</label>
                            <input type="text" class="form-control form-control-sm" name="map_keterangan" placeholder="Snack / ATK">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-success w-100">Tambah</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 align-middle">
                            <thead class="table-light"><tr><th>Kode tersimpan</th><th>Nominal</th><th>Keterangan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                            <tbody>
                            <?php if ($qrNominalMapRows): foreach ($qrNominalMapRows as $mr): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string) $mr['kode_qr']) ?></code></td>
                                    <td colspan="4">
                                        <div class="d-flex flex-wrap align-items-end gap-2">
                                            <form method="post" class="d-flex flex-wrap align-items-end gap-2 flex-grow-1">
                                                <input type="hidden" name="action" value="update_qr_nominal_map">
                                                <input type="hidden" name="map_id" value="<?= (int) $mr['id'] ?>">
                                                <div class="flex-grow-1" style="flex-basis:6rem;min-width:5rem">
                                                    <label class="form-label small mb-0 text-muted">Rp</label>
                                                    <input type="text" class="form-control form-control-sm" name="map_nominal" value="<?= number_format((int) ($mr['nominal'] ?? 0), 0, ',', '.') ?>" required>
                                                </div>
                                                <div class="flex-grow-1" style="flex-basis:10rem;min-width:8rem">
                                                    <label class="form-label small mb-0 text-muted">Ket.</label>
                                                    <input type="text" class="form-control form-control-sm" name="map_keterangan" value="<?= htmlspecialchars((string) ($mr['keterangan'] ?? '')) ?>" placeholder="Opsional">
                                                </div>
                                                <div class="flex-grow-1" style="flex-basis:6rem;min-width:5rem">
                                                    <label class="form-label small mb-0 text-muted">Status</label>
                                                    <select class="form-select form-select-sm" name="map_is_aktif">
                                                        <option value="1" <?= (int) ($mr['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Aktif</option>
                                                        <option value="0" <?= (int) ($mr['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                                                    </select>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                            </form>
                                            <form method="post" class="mb-1" onsubmit="return confirm('Hapus peta kode ini?')">
                                                <input type="hidden" name="action" value="delete_qr_nominal_map">
                                                <input type="hidden" name="map_id" value="<?= (int) $mr['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="text-center text-muted small">Belum ada peta. Tambahkan kode di atas lalu cetak QR dengan generator online (isi teks = kode).</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
