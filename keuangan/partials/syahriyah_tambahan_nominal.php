<?php



declare(strict_types=1);



/**

 * Tambahan syahriyah PKPPS per kelas keuangan (Wustho / Ulya; Muadalah non-PKPPS).

 *

 * @var PDO $pdo

 * @var array<int, string> $bulanLabelsShort

 */

require_once __DIR__ . '/../../helpers/keuangan_pkpps_syahriyah.php';



$bulanLabelsShort = $bulanLabelsShort ?? [

    1 => 'B1', 2 => 'B2', 3 => 'B3', 4 => 'B4', 5 => 'B5', 6 => 'B6',

    7 => 'B7', 8 => 'B8', 9 => 'B9', 10 => 'B10', 11 => 'B11', 12 => 'B12',

];

$defaultPkppsGlobal = keuangan_pkpps_syahriyah_nominal($pdo, 0);
$pkppsAlokasiOptions = keuangan_pkpps_alokasi_komponen_options($pdo);
$pkppsAlokasiSelected = trim((string) app_setting($pdo, 'keuangan_pkpps_alokasi_komponen', ''));
$pkppsAlokasiAuto = keuangan_pkpps_alokasi_komponen_nama($pdo);

$kelasPkppsRows = kelas_keuangan_list_for_pkpps_syahriyah($pdo);

?>

<div class="card shadow-sm mt-4" id="tambahan-pkpps">

    <div class="card-header fw-semibold">3. Tambahan syahriyah PKPPS</div>

    <div class="card-body">

        <p class="small text-muted mb-3">

            Hanya santri aktif <strong>PKPPS</strong>. Nominal per <strong>kelas keuangan</strong>

            (<em>Wustho 1/2/3</em> → tarif <strong>Wustho</strong>, <em>Ulya 1/2/3</em> → <strong>Ulya</strong>).

            <strong>Muadalah</strong> tidak memakai tambahan PKPPS.

            Masuk tagihan syahriyah dan dialokasikan ke komponen

            <strong><?= htmlspecialchars($pkppsAlokasiAuto) ?></strong> (gaji guru)

            — lihat alokasi &amp; laporan

            <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">syahriyah PKPPS</a>.

        </p>



        <?php if ($kelasPkppsRows === []): ?>

            <p class="text-muted small mb-0">

                Belum ada kelas keuangan Wustho/Ulya aktif.

                <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">Kelas keuangan</a>.

            </p>

        <?php else: ?>



        <form method="post">

            <input type="hidden" name="action" value="save_pkpps_syahriyah">

            <div class="mb-3">

                <label class="form-label small">Default global (jika kelas tidak diisi)</label>

                <input type="number" name="pkpps_syahriyah_default" class="form-control form-control-sm" style="max-width:12rem"

                       min="0" step="1000" value="<?= (int) $defaultPkppsGlobal ?>">

            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">

                <button type="button" class="btn btn-outline-secondary btn-sm syahriyah-toggle-bulan"

                        data-target="#pkpps-bulan-cols" aria-expanded="false">Ubah per bulan</button>

            </div>

            <div class="table-responsive" id="pkpps-bulan-cols">

                <table class="table table-sm table-bordered align-middle mb-3">

                    <thead class="table-light">

                    <tr>

                        <th>Kelas keuangan (PKPPS)</th>

                        <th class="text-end">Default (Rp)</th>

                        <?php for ($b = 1; $b <= 12; $b++): ?>

                            <th class="text-end small syahriyah-bulan-cols d-none"><?= htmlspecialchars($bulanLabelsShort[$b] ?? (string) $b) ?></th>

                        <?php endfor; ?>

                    </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($kelasPkppsRows as $kr):

                        $kk = strtoupper(trim((string) ($kr['kode'] ?? '')));

                        if ($kk === '') {

                            continue;

                        }

                        $tierLabel = ucfirst(strtolower(trim((string) ($kr['tarif_keuangan_tier'] ?? ''))));

                        $pkppsDef = keuangan_pkpps_syahriyah_nominal($pdo, 0, 0, 0, $kk);

                        ?>

                        <tr>

                            <td>

                                <div class="fw-semibold small"><?= htmlspecialchars(kelas_keuangan_label_for_kode($pdo, $kk)) ?></div>

                                <div class="text-muted small"><?= htmlspecialchars($tierLabel) ?> · tingkatan PKPPS 1/2/3 memakai baris ini</div>

                            </td>

                            <td>

                                <input type="number" class="form-control form-control-sm text-end"

                                       name="pkpps_syahriyah_kelas[<?= htmlspecialchars($kk) ?>][default]"

                                       min="0" step="1000" value="<?= (int) $pkppsDef ?>">

                            </td>

                            <?php for ($b = 1; $b <= 12; $b++): ?>

                                <td class="syahriyah-bulan-cols d-none">

                                    <input type="number" class="form-control form-control-sm text-end"

                                           name="pkpps_syahriyah_kelas[<?= htmlspecialchars($kk) ?>][bulan][<?= $b ?>]"

                                           min="0" step="1000"

                                           value="<?= (int) keuangan_pkpps_syahriyah_nominal($pdo, $b, 0, 0, $kk) ?>">

                                </td>

                            <?php endfor; ?>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <div class="mb-3">
                <label class="form-label small">Alokasi tambahan PKPPS ke komponen</label>
                <select name="pkpps_alokasi_komponen" class="form-select form-select-sm" style="max-width:28rem">
                    <option value="">Otomatis — komponen gaji (<?= htmlspecialchars($pkppsAlokasiAuto) ?>)</option>
                    <?php foreach ($pkppsAlokasiOptions as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt['nama']) ?>" <?= $pkppsAlokasiSelected === (string) $opt['nama'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $opt['nama']) ?> (<?= number_format((float) $opt['persen'], 2) ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Bagian PKPPS dari pembayaran syahriyah digabung ke komponen ini (bukan Dana Umum terpisah).</div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Simpan tambahan PKPPS</button>

        </form>

        <?php endif; ?>

    </div>

</div>

