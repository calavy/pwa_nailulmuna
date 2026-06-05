<?php

declare(strict_types=1);

/**
 * Pengaturan tambahan syahriyah PKPPS — tampilan ringkas per tier Wustho/Ulya.
 *
 * @var PDO $pdo
 */
require_once __DIR__ . '/../../helpers/keuangan_pkpps_syahriyah.php';

$defaultPkppsGlobal = keuangan_pkpps_syahriyah_nominal($pdo, 0);
$pkppsAlokasiOptions = keuangan_pkpps_alokasi_komponen_options($pdo);
$pkppsAlokasiSelected = trim((string) app_setting($pdo, 'keuangan_pkpps_alokasi_komponen', ''));
$pkppsAlokasiAuto = keuangan_pkpps_alokasi_komponen_nama($pdo);

$kelasPkppsRows = kelas_keuangan_list_for_pkpps_syahriyah($pdo);
$tierNominals = ['wustho' => $defaultPkppsGlobal, 'ulya' => $defaultPkppsGlobal];
foreach ($kelasPkppsRows as $kr) {
    $kk = strtoupper(trim((string) ($kr['kode'] ?? '')));
    $tier = strtolower(trim((string) ($kr['tarif_keuangan_tier'] ?? '')));
    if ($kk === '' || !isset($tierNominals[$tier])) {
        continue;
    }
    $stored = keuangan_pkpps_syahriyah_nominal_stored($pdo, 0, $kk);
    $tierNominals[$tier] = $stored !== null
        ? $stored
        : keuangan_pkpps_syahriyah_nominal($pdo, 0, 0, 0, $kk);
}

$detailOpen = isset($_GET['pkpps_detail']) && $_GET['pkpps_detail'] === '1';
?>

<div class="card shadow-sm mt-4" id="tambahan-pkpps">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>3. Tambahan syahriyah PKPPS</span>
        <a class="small" href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">Laporan PKPPS</a>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Santri aktif <strong>PKPPS</strong> mendapat tambahan pada tagihan syahriyah bulanan.
            Isi nominal per <strong>Wustho</strong> dan <strong>Ulya</strong> (berlaku semua bulan, semua tingkatan PKPPS di tier tersebut).
            Muadalah tidak memakai PKPPS.
        </p>

        <?php if ($kelasPkppsRows === []): ?>
            <p class="text-muted small mb-0">
                Belum ada kelas keuangan Wustho/Ulya.
                <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">Kelas keuangan</a>.
            </p>
        <?php else: ?>

        <form method="post">
            <input type="hidden" name="action" value="save_pkpps_syahriyah">

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small">Cadangan global (jika tier kosong)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Rp</span>
                        <input type="number" name="pkpps_syahriyah_default" class="form-control" min="0" step="1000"
                               value="<?= (int) $defaultPkppsGlobal ?>">
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="fw-semibold mb-2"><i class="fa-solid fa-book-quran me-1 text-primary"></i> Wustho (1/2/3)</div>
                        <div class="input-group">
                            <span class="input-group-text">Rp / bulan</span>
                            <input type="number" name="pkpps_tier_wustho" class="form-control" min="0" step="1000"
                                   value="<?= (int) $tierNominals['wustho'] ?>">
                        </div>
                        <div class="form-text">Wustho 1, 2, 3 memakai nominal ini.</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="fw-semibold mb-2"><i class="fa-solid fa-graduation-cap me-1 text-success"></i> Ulya (1/2/3)</div>
                        <div class="input-group">
                            <span class="input-group-text">Rp / bulan</span>
                            <input type="number" name="pkpps_tier_ulya" class="form-control" min="0" step="1000"
                                   value="<?= (int) $tierNominals['ulya'] ?>">
                        </div>
                        <div class="form-text">Ulya 1, 2, 3 memakai nominal ini.</div>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small">Alokasi tambahan PKPPS ke komponen</label>
                <select name="pkpps_alokasi_komponen" class="form-select form-select-sm" style="max-width:28rem">
                    <option value="">Otomatis — <?= htmlspecialchars($pkppsAlokasiAuto) ?></option>
                    <?php foreach ($pkppsAlokasiOptions as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt['nama']) ?>" <?= $pkppsAlokasiSelected === (string) $opt['nama'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $opt['nama']) ?> (<?= number_format((float) $opt['persen'], 2) ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">Simpan pengaturan PKPPS</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan&pkpps_detail=1#tambahan-pkpps')) ?>">
                    <?= $detailOpen ? 'Sembunyikan' : 'Buka' ?> pengaturan per kelas/bulan
                </a>
            </div>

            <?php if ($detailOpen): ?>
                <details class="mt-3" open>
                    <summary class="small text-muted mb-2">Nominal per kelas keuangan &amp; per bulan (lanjutan)</summary>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Kelas</th>
                                <th class="text-end">Default</th>
                                <?php for ($b = 1; $b <= 12; $b++): ?>
                                    <th class="text-end small">B<?= $b ?></th>
                                <?php endfor; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($kelasPkppsRows as $kr):
                                $kk = strtoupper(trim((string) ($kr['kode'] ?? '')));
                                if ($kk === '') {
                                    continue;
                                }
                                $def = keuangan_pkpps_syahriyah_nominal_stored($pdo, 0, $kk);
                                $defVal = $def !== null ? $def : keuangan_pkpps_syahriyah_nominal($pdo, 0, 0, 0, $kk);
                                ?>
                                <tr>
                                    <td class="small fw-semibold"><?= htmlspecialchars(kelas_keuangan_label_for_kode($pdo, $kk)) ?></td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm text-end"
                                               name="pkpps_syahriyah_kelas[<?= htmlspecialchars($kk) ?>][default]"
                                               min="0" step="1000" value="<?= (int) $defVal ?>">
                                    </td>
                                    <?php for ($b = 1; $b <= 12; $b++):
                                        $storedB = keuangan_pkpps_syahriyah_nominal_stored($pdo, $b, $kk);
                                        $valB = $storedB !== null ? $storedB : keuangan_pkpps_syahriyah_nominal($pdo, $b, 0, 0, $kk);
                                        ?>
                                        <td>
                                            <input type="number" class="form-control form-control-sm text-end"
                                                   name="pkpps_syahriyah_kelas[<?= htmlspecialchars($kk) ?>][bulan][<?= $b ?>]"
                                                   min="0" step="1000" value="<?= (int) $valB ?>">
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>
