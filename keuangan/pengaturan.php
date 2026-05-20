<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_pengaturan.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_transaksi_tables($pdo);
ensure_kelas_keuangan_table($pdo);

$section = trim((string) ($_GET['bagian'] ?? 'umum'));
$validSections = ['umum', 'tarif', 'akun', 'alokasi'];
if (!in_array($section, $validSections, true)) {
    $section = 'umum';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'save_periode' => keuangan_save_periode_settings($pdo, $_POST),
        'save_tarif' => keuangan_save_tarif_settings($pdo, $_POST),
        'save_akun' => keuangan_save_akun($pdo, $_POST),
        'save_alokasi' => keuangan_save_alokasi($pdo, $_POST),
        default => ['ok' => false, 'message' => 'Aksi tidak dikenali.'],
    };
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redirectSection = match ($action) {
        'save_tarif' => 'tarif',
        'save_akun' => 'akun',
        'save_alokasi' => 'alokasi',
        default => 'umum',
    };
    header('Location: /keuangan/pengaturan.php?bagian=' . urlencode($redirectSection));
    exit;
}

$periode = keuangan_tahun_ajaran_aktif($pdo);
$biayaDefs = keuangan_biaya_definitions();
$tiers = ['muadalah' => 'Muadalah', 'wustho' => 'Wustho', 'ulya' => 'Ulya'];
$akunRows = keuangan_fetch_akun_all($pdo);
$alokasiRows = keuangan_fetch_alokasi_all($pdo);
$editAkunId = (int) ($_GET['edit_akun'] ?? 0);
$editAlokasiId = (int) ($_GET['edit_alokasi'] ?? 0);
$editAkun = null;
$editAlokasi = null;
foreach ($akunRows as $ar) {
    if ((int) ($ar['id'] ?? 0) === $editAkunId) {
        $editAkun = $ar;
        break;
    }
}
foreach ($alokasiRows as $al) {
    if ((int) ($al['id'] ?? 0) === $editAlokasiId) {
        $editAlokasi = $al;
        break;
    }
}

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Pengaturan Keuangan';
$bodyClass = keuangan_body_class('keuangan-pengaturan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Pengaturan</p>
    <h1 class="h4 mb-1">Pengaturan Keuangan &amp; Syahriyah</h1>
    <p class="text-muted mb-0">
        Satu tempat untuk tahun ajaran, tarif, akun kas/bank, dan alokasi dana.
        Lainnya di menu pengaturan:
        <a href="/settings/kelas_keuangan.php">Kelas keuangan</a>,
        <a href="/keuangan/inventaris.php">Inventaris aset</a>,
        <a href="/keuangan/cashless_pin.php">Cashless &amp; uang saku</a>,
        <a href="/keuangan/potongan_syahriyah.php">Potongan syahriyah per santri</a>.
    </p>
</div>

<ul class="nav nav-tabs mb-3 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $section === 'umum' ? 'active' : '' ?>" href="?bagian=umum">Umum &amp; periode</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'tarif' ? 'active' : '' ?>" href="?bagian=tarif">Tarif biaya</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'akun' ? 'active' : '' ?>" href="?bagian=akun">Akun kas/bank</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'alokasi' ? 'active' : '' ?>" href="?bagian=alokasi">Alokasi syahriyah</a>
    </li>
</ul>

<?php if ($section === 'umum'): ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tahun ajaran keuangan</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_periode">
                    <div class="col-md-6">
                        <label class="form-label">Tahun mulai</label>
                        <input type="number" class="form-control" name="keuangan_periode_mulai" min="2000" max="2100"
                               value="<?= (int) $periode['mulai'] ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tahun selesai</label>
                        <input type="number" class="form-control" name="keuangan_periode_selesai" min="2000" max="2100"
                               value="<?= (int) $periode['selesai'] ?>" required>
                    </div>
                    <div class="col-12">
                        <p class="small text-muted mb-2">Dipakai di tagihan bulanan (Syahriyah + Makan), input pembayaran, dan laporan periode <?= (int) $periode['mulai'] ?>/<?= (int) $periode['selesai'] ?>.</p>
                        <button type="submit" class="btn btn-primary">Simpan periode</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Syahriyah &amp; operasional</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-outline-primary text-start" href="/settings/kelas_keuangan.php">
                    <i class="fa-solid fa-layer-group me-2"></i>Kelas / kategori keuangan santri
                </a>
                <a class="btn btn-warning text-start" href="/keuangan/potongan_syahriyah.php">
                    <i class="fa-solid fa-percent me-2"></i>Potongan syahriyah per santri (%)
                </a>
                <a class="btn btn-outline-primary text-start" href="/pembayaran/tagihan_syahriyah.php">
                    <i class="fa-solid fa-receipt me-2"></i>Tagihan syahriyah per bulan
                </a>
                <a class="btn btn-outline-secondary text-start" href="/pembayaran/laporan.php">
                    <i class="fa-solid fa-chart-column me-2"></i>Laporan syahriyah
                </a>
                <a class="btn btn-outline-secondary text-start" href="/keuangan/cashless_pin.php">
                    <i class="fa-solid fa-key me-2"></i>Cashless &amp; uang saku
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'tarif'): ?>
<?php
    $byKategori = [];
    foreach ($biayaDefs as $def) {
        $kat = (string) ($def['kategori'] ?? 'Lainnya');
        $byKategori[$kat][] = $def;
    }
?>
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Tarif komponen biaya (per tier Muadalah / Wustho / Ulya)</div>
    <div class="card-body">
        <p class="small text-muted">Tier diambil dari <strong>Kelas keuangan</strong> tiap santri. Nominal Rupiah tanpa titik — contoh: 200000.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_tarif">
            <?php foreach ($byKategori as $katNama => $defsKat): ?>
                <h2 class="h6 mt-3 mb-2 text-secondary"><?= htmlspecialchars($katNama) ?></h2>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Komponen</th>
                                <?php foreach ($tiers as $tl): ?>
                                    <th class="text-end" style="min-width:7rem"><?= htmlspecialchars($tl) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($defsKat as $def):
                            $slug = (string) $def['slug'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $def['nama']) ?></td>
                                <?php foreach ($tiers as $tk => $tl):
                                    $val = keuangan_fee_nominal_for_tier($pdo, $def, $tk);
                                    ?>
                                    <td>
                                        <input type="text" class="form-control form-control-sm text-end"
                                               name="fee[<?= htmlspecialchars($slug) ?>][<?= htmlspecialchars($tk) ?>]"
                                               value="<?= htmlspecialchars((string) $val) ?>" inputmode="numeric">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Simpan semua tarif</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'akun'): ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><?= $editAkun ? 'Ubah akun' : 'Tambah akun kas/bank' ?></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_akun">
                    <input type="hidden" name="akun_id" value="<?= (int) ($editAkun['id'] ?? 0) ?>">
                    <div class="col-md-6">
                        <label class="form-label">Jenis</label>
                        <select class="form-select" name="jenis_akun">
                            <?php foreach (['KAS' => 'Kas', 'BANK' => 'Bank', 'E-WALLET' => 'E-Wallet'] as $jv => $jl): ?>
                                <option value="<?= $jv ?>" <?= (($editAkun['jenis_akun'] ?? 'KAS') === $jv) ? 'selected' : '' ?>><?= $jl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama akun <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_akun" required value="<?= htmlspecialchars((string) ($editAkun['nama_akun'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama bank</label>
                        <input class="form-control" name="nama_bank" value="<?= htmlspecialchars((string) ($editAkun['nama_bank'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">No. rekening</label>
                        <input class="form-control" name="no_rekening" value="<?= htmlspecialchars((string) ($editAkun['no_rekening'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Atas nama</label>
                        <input class="form-control" name="atas_nama" value="<?= htmlspecialchars((string) ($editAkun['atas_nama'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Saldo awal</label>
                        <input class="form-control" name="opening_balance" inputmode="numeric"
                               value="<?= htmlspecialchars((string) (int) round((float) ($editAkun['opening_balance'] ?? 0))) ?>">
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="akun-default"
                                <?= (int) ($editAkun['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="akun-default">Akun default</label>
                        </div>
                        <?php if ($editAkun): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="akun-aktif"
                                <?= (int) ($editAkun['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="akun-aktif">Aktif</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Simpan akun</button>
                        <?php if ($editAkun): ?>
                            <a class="btn btn-outline-secondary" href="?bagian=akun">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Daftar akun</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Akun</th>
                                <th class="text-end">Saldo awal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($akunRows === []): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">Belum ada akun. Tambahkan kas bendahara.</td></tr>
                        <?php else: ?>
                            <?php foreach ($akunRows as $ar): ?>
                                <tr class="<?= (int) ($ar['is_active'] ?? 1) !== 1 ? 'table-secondary' : '' ?>">
                                    <td class="small">
                                        <strong><?= htmlspecialchars((string) $ar['nama_akun']) ?></strong>
                                        <div class="text-muted"><?= htmlspecialchars((string) $ar['jenis_akun']) ?>
                                            <?php if ((int) ($ar['is_default'] ?? 0) === 1): ?> · default<?php endif; ?>
                                            <?php if ((int) ($ar['is_active'] ?? 1) !== 1): ?> · nonaktif<?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah((int) round((float) ($ar['opening_balance'] ?? 0)))) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?bagian=akun&amp;edit_akun=<?= (int) $ar['id'] ?>">Ubah</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'alokasi'): ?>
<?php
    $alokasiAktifRows = keuangan_fetch_alokasi_aktif($pdo);
    $realisasiSyahriyah = keuangan_syahriyah_realisasi_ta($pdo);
    $simulasiAwal = keuangan_alokasi_simulasi($pdo);
    $periodeTa = keuangan_tahun_ajaran_aktif($pdo);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><?= $editAlokasi ? 'Ubah alokasi' : 'Tambah alokasi' ?></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_alokasi">
                    <input type="hidden" name="alokasi_id" value="<?= (int) ($editAlokasi['id'] ?? 0) ?>">
                    <div class="col-12">
                        <label class="form-label">Nama komponen <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_komponen" required value="<?= htmlspecialchars((string) ($editAlokasi['nama_komponen'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" name="kategori" required value="<?= htmlspecialchars((string) ($editAlokasi['kategori'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Persen %</label>
                        <input type="number" step="0.01" class="form-control" name="persen" value="<?= htmlspecialchars((string) ($editAlokasi['persen'] ?? '0')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Urutan</label>
                        <input type="number" class="form-control" name="urutan" value="<?= (int) ($editAlokasi['urutan'] ?? 0) ?>">
                    </div>
                    <?php if ($editAlokasi): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="alok-aktif"
                                <?= (int) ($editAlokasi['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="alok-aktif">Aktif</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Simpan alokasi</button>
                        <?php if ($editAlokasi): ?>
                            <a class="btn btn-outline-secondary" href="?bagian=alokasi">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
                <p class="small text-muted mt-2 mb-0">Persentase untuk pembagian dana syahriyah. Total alokasi aktif <strong>tidak boleh melebihi 100%</strong> saat disimpan.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Daftar alokasi</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Komponen</th>
                                <th class="text-end">%</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($alokasiRows === []): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">Belum ada alokasi.</td></tr>
                        <?php else: ?>
                            <?php
                            $totalPersen = 0.0;
                            foreach ($alokasiRows as $al):
                                if ((int) ($al['is_active'] ?? 1) === 1) {
                                    $totalPersen += (float) ($al['persen'] ?? 0);
                                }
                                ?>
                                <tr class="<?= (int) ($al['is_active'] ?? 1) !== 1 ? 'table-secondary' : '' ?>">
                                    <td class="small">
                                        <?= htmlspecialchars((string) $al['nama_komponen']) ?>
                                        <div class="text-muted"><?= htmlspecialchars((string) $al['kategori']) ?></div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars((string) $al['persen']) ?>%</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?bagian=alokasi&amp;edit_alokasi=<?= (int) $al['id'] ?>">Ubah</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($alokasiRows !== []): ?>
                    <p class="small text-muted px-3 py-2 mb-0">
                        Jumlah persen aktif:
                        <strong class="<?= $totalPersen > 100 ? 'text-danger' : '' ?>"><?= htmlspecialchars((string) round($totalPersen, 2)) ?>%</strong>
                        <?php if ($totalPersen > 100): ?><span class="text-danger"> — melebihi 100%</span><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($alokasiAktifRows !== []): ?>
<div class="card shadow-sm mt-3 border-info border-opacity-25" id="alokasi-sim-card">
    <div class="card-header bg-info bg-opacity-10 fw-semibold text-info-emphasis">Simulasi alokasi (what-if)</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Pagu dari realisasi <strong>syahriyah</strong> TA <?= (int) $periodeTa['mulai'] ?>/<?= (int) $periodeTa['selesai'] ?>:
            <strong><?= htmlspecialchars($formatRupiah($realisasiSyahriyah)) ?></strong>.
            Ubah persen di bawah untuk melihat nominal per pos <em>sebelum</em> disimpan.
        </p>
        <div class="row g-2 mb-3">
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Total %</div><div class="app-mini-stat-value" id="sim-total-persen"><?= htmlspecialchars((string) $simulasiAwal['total_persen']) ?>%</div></div></div>
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Sisa %</div><div class="app-mini-stat-value text-primary" id="sim-sisa-persen"><?= htmlspecialchars((string) $simulasiAwal['sisa_persen']) ?>%</div></div></div>
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Status</div><div class="app-mini-stat-value small <?= $simulasiAwal['ok'] ? 'text-success' : 'text-danger' ?>" id="sim-status-label"><?= $simulasiAwal['ok'] ? 'Valid' : 'Melebihi 100%' ?></div></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Komponen</th><th>Persen simulasi</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php foreach ($alokasiAktifRows as $al):
                    $aid = (int) ($al['id'] ?? 0);
                    $pct = (float) ($al['persen'] ?? 0);
                    $nomSim = $realisasiSyahriyah > 0 ? (int) floor($realisasiSyahriyah * $pct / 100) : 0;
                    ?>
                    <tr>
                        <td class="small"><strong><?= htmlspecialchars((string) $al['nama_komponen']) ?></strong><div class="text-muted"><?= htmlspecialchars((string) $al['kategori']) ?></div></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <input type="range" class="form-range flex-grow-1 sim-persen-range" min="0" max="100" step="0.5" data-id="<?= $aid ?>" value="<?= htmlspecialchars((string) $pct) ?>">
                                <input type="number" class="form-control form-control-sm sim-persen-input text-end" style="width:4.5rem" min="0" max="100" step="0.5" data-id="<?= $aid ?>" value="<?= htmlspecialchars((string) $pct) ?>">
                                <span class="small">%</span>
                            </div>
                        </td>
                        <td class="text-end font-monospace small sim-nominal" data-id="<?= $aid ?>"><?= htmlspecialchars($formatRupiah($nomSim)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-danger mb-0 mt-2 d-none" id="sim-warn"></p>
    </div>
</div>
<script>
(function () {
    const realisasi = <?= (int) $realisasiSyahriyah ?>;
    const fmt = function (n) { return 'Rp ' + Math.max(0, Math.floor(n)).toLocaleString('id-ID'); };
    function recalc() {
        let total = 0;
        document.querySelectorAll('.sim-persen-input').forEach(function (inp) {
            const p = Math.max(0, parseFloat(inp.value) || 0);
            total += p;
            const id = inp.getAttribute('data-id');
            const nomEl = document.querySelector('.sim-nominal[data-id="' + id + '"]');
            if (nomEl) nomEl.textContent = fmt(realisasi * p / 100);
        });
        total = Math.round(total * 100) / 100;
        const sisa = Math.max(0, Math.round((100 - total) * 100) / 100);
        const ok = total <= 100.0001;
        const totalEl = document.getElementById('sim-total-persen');
        const sisaEl = document.getElementById('sim-sisa-persen');
        const statusEl = document.getElementById('sim-status-label');
        const warnEl = document.getElementById('sim-warn');
        if (totalEl) { totalEl.textContent = total + '%'; totalEl.classList.toggle('text-danger', !ok); }
        if (sisaEl) sisaEl.textContent = sisa + '%';
        if (statusEl) { statusEl.textContent = ok ? 'Valid' : 'Melebihi 100%'; statusEl.className = 'app-mini-stat-value small ' + (ok ? 'text-success' : 'text-danger'); }
        if (warnEl) {
            if (!ok) { warnEl.textContent = 'Total ' + total + '% melebihi 100%. Sesuaikan sebelum menyimpan.'; warnEl.classList.remove('d-none'); }
            else warnEl.classList.add('d-none');
        }
    }
    function syncPair(id, value) {
        document.querySelectorAll('.sim-persen-range[data-id="' + id + '"], .sim-persen-input[data-id="' + id + '"]').forEach(function (el) { el.value = value; });
    }
    document.querySelectorAll('.sim-persen-range').forEach(function (r) {
        r.addEventListener('input', function () { syncPair(r.getAttribute('data-id'), r.value); recalc(); });
    });
    document.querySelectorAll('.sim-persen-input').forEach(function (inp) {
        inp.addEventListener('input', function () { syncPair(inp.getAttribute('data-id'), inp.value); recalc(); });
    });
    recalc();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
