<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_skbt.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';

require_roles(['admin', 'pengurus', 'kiai']);
ensure_santri_identity_columns($pdo);

$periodeResolved = skbt_resolve_periode($pdo, $_GET);
$periodeMode = (string) ($periodeResolved['mode'] ?? 'ta_penuh');
$tahunSyawal = (int) ($periodeResolved['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
$santriId = (int) ($_GET['santri_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$periodeKe = max(0, (int) ($_GET['periode_ke'] ?? 0));

$calMode = strtolower(trim((string) ($_GET['mode'] ?? 'hijriyah')));
if (!in_array($calMode, ['hijriyah', 'masehi'], true)) {
    $calMode = 'hijriyah';
}
$bulanPick = max(1, min(12, (int) ($_GET['month'] ?? (int) date('n'))));
$tahunPick = (int) ($_GET['year'] ?? 0);
$bulanDari = max(0, min(12, (int) ($_GET['bulan_dari'] ?? 0)));
$bulanSampai = max(0, min(12, (int) ($_GET['bulan_sampai'] ?? 0)));
$dariPick = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$sampaiPick = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));

$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$cariRows = [];
if ($q !== '' && strlen($q) >= 2) {
    $aktifSql = santri_sql_aktif_only('s');
    $st = $pdo->prepare('
        SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan
        FROM santri s
        WHERE ' . $aktifSql . '
          AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)
        ORDER BY s.' . $namaCol . ' ASC
        LIMIT 25
    ');
    $st->execute(['q' => '%' . $q . '%']);
    $cariRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$santriPick = $santriId > 0 ? skbt_santri_profil($pdo, $santriId, false) : null;
$previewRingkas = null;
if ($santriPick && $santriId > 0) {
    $previewRingkas = skbt_preview_counts($pdo, $santriId, $tahunSyawal, $periodeResolved);
}

$masehiMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$hijriyahMonths = hijri_nama_bulan_list();
$hijriBulanSingkat = skbt_bulan_hijri_singkat();

$pageTitle = 'SKBT — Surat Keterangan Belajar & Tingkatan';
require_once __DIR__ . '/../includes/header.php';

$baseQs = static function (array $extra = []) use ($periodeMode, $tahunSyawal, $calMode, $bulanPick, $tahunPick, $dariPick, $sampaiPick, $periodeKe, $bulanDari, $bulanSampai): array {
    $qs = ['periode_mode' => $periodeMode];
    if ($periodeMode === 'ta_penuh') {
        $qs['tahun_syawal'] = $tahunSyawal;
        if ($bulanDari > 0) {
            $qs['bulan_dari'] = $bulanDari;
        }
        if ($bulanSampai > 0) {
            $qs['bulan_sampai'] = $bulanSampai;
        }
    } elseif ($periodeMode === 'bulan') {
        $qs['mode'] = $calMode;
        $qs['month'] = $bulanPick;
        if ($tahunPick > 0) {
            $qs['year'] = $tahunPick;
        }
    } else {
        $qs['dari'] = $dariPick;
        $qs['sampai'] = $sampaiPick;
    }
    if ($periodeKe > 0) {
        $qs['periode_ke'] = $periodeKe;
    }

    return array_merge($qs, $extra);
};
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Akademik</p>
    <h1 class="h4 mb-1">SKBT — Laporan keaktivan kegiatan</h1>
    <p class="text-muted small mb-0">
        Surat Keterangan Belajar dan Tingkatan dari rekap presensi, nilai ikhtibar, dan nilai manual pembimbing.
        Cetak <strong>1 lembar F4</strong> (potong periode TA, mis. Syawal — R.Awal).
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-0">Cari santri</label>
                <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Nama / NIS / QR" autofocus>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Jenis periode</label>
                <select name="periode_mode" id="skbt_periode_mode" class="form-select">
                    <option value="ta_penuh" <?= $periodeMode === 'ta_penuh' ? 'selected' : '' ?>>TA penuh (Syawal–Ramadhan)</option>
                    <option value="bulan" <?= $periodeMode === 'bulan' ? 'selected' : '' ?>>Per bulan</option>
                    <option value="rentang" <?= $periodeMode === 'rentang' ? 'selected' : '' ?>>Rentang tanggal</option>
                </select>
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-ta" style="<?= $periodeMode !== 'ta_penuh' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Tahun Syawal</label>
                <input type="number" name="tahun_syawal" class="form-control" min="1300" max="1500" value="<?= (int) $tahunSyawal ?>">
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-ta" style="<?= $periodeMode !== 'ta_penuh' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Bulan awal</label>
                <select name="bulan_dari" class="form-select form-select-sm">
                    <option value="">Syawal</option>
                    <?php foreach (skbt_bulan_urutan_ta() as $m): ?>
                        <option value="<?= (int) $m ?>" <?= $bulanDari === (int) $m ? 'selected' : '' ?>><?= htmlspecialchars((string) ($hijriBulanSingkat[$m] ?? (string) $m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-ta" style="<?= $periodeMode !== 'ta_penuh' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Bulan akhir</label>
                <select name="bulan_sampai" class="form-select form-select-sm">
                    <option value="">Ramadhan</option>
                    <?php foreach (skbt_bulan_urutan_ta() as $m): ?>
                        <option value="<?= (int) $m ?>" <?= $bulanSampai === (int) $m ? 'selected' : '' ?>><?= htmlspecialchars((string) ($hijriBulanSingkat[$m] ?? (string) $m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-bulan" style="<?= $periodeMode !== 'bulan' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Kalender</label>
                <select name="mode" class="form-select">
                    <option value="hijriyah" <?= $calMode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                    <option value="masehi" <?= $calMode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                </select>
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-bulan" style="<?= $periodeMode !== 'bulan' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Bulan</label>
                <select name="month" class="form-select">
                    <?php
                    $monthNames = $calMode === 'hijriyah' ? $hijriyahMonths : $masehiMonths;
                    foreach ($monthNames as $num => $label):
                        ?>
                        <option value="<?= (int) $num ?>" <?= $bulanPick === (int) $num ? 'selected' : '' ?>><?= htmlspecialchars((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-bulan" style="<?= $periodeMode !== 'bulan' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Tahun</label>
                <input type="number" name="year" class="form-control" min="1300" max="2100" value="<?= $tahunPick > 0 ? $tahunPick : (int) date('Y') ?>">
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-rentang" style="<?= $periodeMode !== 'rentang' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Dari</label>
                <input type="date" name="dari" class="form-control" value="<?= htmlspecialchars($dariPick) ?>">
            </div>
            <div class="col-md-2 skbt-opt skbt-opt-rentang" style="<?= $periodeMode !== 'rentang' ? 'display:none' : '' ?>">
                <label class="form-label small mb-0">Sampai</label>
                <input type="date" name="sampai" class="form-control" value="<?= htmlspecialchars($sampaiPick) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Periode ke</label>
                <input type="number" name="periode_ke" class="form-control" min="0" max="99" value="<?= $periodeKe > 0 ? $periodeKe : '' ?>" placeholder="Opsional">
            </div>
            <?php if ($santriId > 0): ?>
                <input type="hidden" name="santri_id" value="<?= $santriId ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Terapkan</button>
            </div>
        </form>

        <?php if ($cariRows !== [] && !$santriPick): ?>
            <div class="list-group list-group-flush border rounded mt-3">
                <?php foreach ($cariRows as $cr): ?>
                    <a class="list-group-item list-group-item-action py-2"
                       href="<?= htmlspecialchars(app_href('/akademik/skbt.php?' . http_build_query($baseQs(['santri_id' => (int) ($cr['id'] ?? 0), 'q' => ''])))) ?>">
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($cr['nama_santri'] ?? '-')) ?></div>
                        <div class="small text-muted">NIS <?= htmlspecialchars((string) ($cr['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($cr['tingkatan'] ?? '')) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($q !== '' && strlen($q) >= 2): ?>
            <p class="small text-muted mt-2 mb-0">Santri tidak ditemukan.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($santriPick && $previewRingkas): ?>
    <?php
    $cetakQs = skbt_periode_query_params($pdo, $periodeResolved, [
        'santri_id' => $santriId,
    ]);
    if ($periodeKe > 0) {
        $cetakQs['periode_ke'] = $periodeKe;
    }
    $cetakUrl = app_href('/akademik/skbt_cetak.php?' . http_build_query($cetakQs));
    ?>
    <div class="card shadow-sm border-success mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars((string) ($santriPick['nama_santri'] ?? '-')) ?></h2>
                    <div class="text-muted small">
                        NIS <?= htmlspecialchars((string) ($santriPick['nis'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['tingkatan'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($previewRingkas['periode']['label'] ?? '')) ?>
                        <?php if (!empty($previewRingkas['periode']['rentang_tampilan'])): ?>
                            (<?= htmlspecialchars((string) $previewRingkas['periode']['rentang_tampilan']) ?>)
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-success" href="<?= htmlspecialchars($cetakUrl) ?>" target="_blank">
                        <i class="fa-solid fa-print me-1"></i> Cetak SKBT (F4)
                    </a>
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($cetakUrl . '&preview=1') ?>" target="_blank">Pratinjau</a>
                </div>
            </div>
            <div class="row g-2 mt-3 small">
                <div class="col-md-3">
                    <span class="badge text-bg-primary"><?= (int) ($previewRingkas['disiplin_kelas'] ?? 0) ?> ta'lim / disiplin</span>
                </div>
                <div class="col-md-3">
                    <span class="badge text-bg-info"><?= (int) ($previewRingkas['presensi_jamaah'] ?? 0) ?> jama'ah</span>
                </div>
                <div class="col-md-3">
                    <span class="badge text-bg-secondary"><?= (int) ($previewRingkas['lainnya'] ?? 0) ?> lainnya</span>
                </div>
                <div class="col-md-3">
                    <span class="badge text-bg-success"><?= (int) ($previewRingkas['ikhtibar_jumlah'] ?? 0) ?> ikhtibar</span>
                    <?php if ((int) ($previewRingkas['manual_jumlah'] ?? 0) > 0): ?>
                        <span class="badge text-bg-warning text-dark"><?= (int) ($previewRingkas['manual_jumlah']) ?> manual</span>
                    <?php endif; ?>
                    <?php if ($previewRingkas['akademik_rata_nilai'] ?? $previewRingkas['ikhtibar_rata_nilai'] ?? null): ?>
                        <span class="text-muted ms-1">rata <?= htmlspecialchars((string) ($previewRingkas['akademik_rata_nilai'] ?? $previewRingkas['ikhtibar_rata_nilai'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($santriId > 0): ?>
    <div class="alert alert-warning">Santri tidak ditemukan.</div>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fa-solid fa-file-lines fa-2x mb-2 opacity-50"></i>
        <p class="mb-0 small">Cari dan pilih santri untuk membuat SKBT.</p>
    </div>
<?php endif; ?>

<script>
(function () {
    var sel = document.getElementById('skbt_periode_mode');
    if (!sel) return;
    function sync() {
        var v = sel.value;
        document.querySelectorAll('.skbt-opt-ta').forEach(function (el) { el.style.display = v === 'ta_penuh' ? '' : 'none'; });
        document.querySelectorAll('.skbt-opt-bulan').forEach(function (el) { el.style.display = v === 'bulan' ? '' : 'none'; });
        document.querySelectorAll('.skbt-opt-rentang').forEach(function (el) { el.style.display = v === 'rentang' ? '' : 'none'; });
    }
    sel.addEventListener('change', sync);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
