<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/keuangan_kelas_makan.php';

/**
 * @var PDO $pdo
 * @var array<string, string> $tiers
 * @var array<string, array<string, int>> $feeMatrixSyMakan
 * @var int $taMulaiTarifBulan
 * @var int $taSelesaiTarifBulan
 * @var array<int, array<string, mixed>> $bulanSlotsTarif
 * @var array<int, array<string, array<string, int>>> $tarifBulanMap
 */
$posLabels = ['syahriyah' => 'Syahriyah', 'makan' => keuangan_makan_pos_nama($pdo)];
?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">1. Tarif default (semua bulan)</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Nominal dasar per tier Muadalah / Wustho / Ulya. Dipakai jika tidak ada tarif khusus per bulan (tombol di bawah).
        </p>
        <form method="post">
            <input type="hidden" name="action" value="save_tarif">
            <input type="hidden" name="redirect_bagian" value="tarif">
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
                    <?php foreach ($posLabels as $slug => $nama): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($nama) ?></td>
                            <?php foreach ($tiers as $tk => $tl):
                                $val = (int) ($feeMatrixSyMakan[$slug][$tk] ?? 0);
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
            <button type="submit" class="btn btn-primary btn-sm">Simpan tarif default</button>
        </form>
    </div>
</div>

<div class="card shadow-sm" id="tarif-per-bulan">
    <div class="card-header fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>2. Tarif khusus per bulan tagihan</span>
        <button type="button" class="btn btn-outline-secondary btn-sm syahriyah-toggle-bulan"
                data-target="#tarif-bulan-panel" aria-expanded="false">Ubah per bulan</button>
    </div>
    <div class="card-body" id="tarif-bulan-panel">
        <p class="small text-muted mb-3 syahriyah-bulan-cols d-none">
            Isi hanya bulan yang nominal <strong>syahriyah</strong> atau <strong>makan</strong> berbeda dari tarif default. Kosongkan = pakai default.
        </p>
        <form method="get" class="row g-2 align-items-end mb-3 syahriyah-bulan-cols d-none">
            <input type="hidden" name="bagian" value="tarif">
            <?php
            $taMulai = $taMulaiTarifBulan;
            $taSelesai = $taSelesaiTarifBulan;
            $taColClass = 'col-md-8';
            $taInputMode = 'dropdown';
            $nameMulai = 'ta_mulai';
            $nameSelesai = 'ta_selesai';
            require __DIR__ . '/../../includes/partials/pondok_ta_fields.php';
            ?>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Tampilkan</button>
            </div>
        </form>
        <p class="small mb-3 syahriyah-bulan-cols d-none">
            Tahun ajaran:
            <strong><?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, ['mulai' => $taMulaiTarifBulan, 'selesai' => $taSelesaiTarifBulan])) ?></strong>
        </p>
        <form method="post" class="syahriyah-bulan-cols d-none">
            <input type="hidden" name="action" value="save_tarif_bulan">
            <input type="hidden" name="tarif_bulan_ta_mulai" value="<?= (int) $taMulaiTarifBulan ?>">
            <input type="hidden" name="tarif_bulan_ta_selesai" value="<?= (int) $taSelesaiTarifBulan ?>">
            <?php foreach ($posLabels as $posSlug => $posNama): ?>
                <h3 class="h6 mt-3 mb-2 text-secondary"><?= htmlspecialchars($posNama) ?></h3>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:9rem">Bulan</th>
                                <?php foreach ($tiers as $tl): ?>
                                    <th class="text-end" style="min-width:6.5rem"><?= htmlspecialchars($tl) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bulanSlotsTarif as $slot):
                            $bulan = (int) ($slot['bulan_tagihan'] ?? 0);
                            if ($bulan < 1 || $bulan > 12) {
                                continue;
                            }
                            $bulanLabel = pondok_bulan_slot_label_tampilan($pdo, $slot);
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($bulanLabel) ?></td>
                                <?php foreach ($tiers as $tk => $tl):
                                    $defVal = (int) ($feeMatrixSyMakan[$posSlug][$tk] ?? 0);
                                    $customVal = (int) ($tarifBulanMap[$bulan][$posSlug][$tk] ?? 0);
                                    $showVal = $customVal > 0 ? $customVal : '';
                                    $ph = $defVal > 0 ? 'default ' . number_format($defVal, 0, ',', '.') : '';
                                    ?>
                                    <td>
                                        <input type="text" class="form-control form-control-sm text-end"
                                               name="fee_bulan[<?= $bulan ?>][<?= htmlspecialchars($posSlug) ?>][<?= htmlspecialchars($tk) ?>]"
                                               value="<?= $showVal !== '' ? htmlspecialchars((string) $showVal) : '' ?>"
                                               placeholder="<?= htmlspecialchars($ph) ?>"
                                               inputmode="numeric"
                                               title="<?= htmlspecialchars($ph !== '' ? 'Kosongkan untuk ' . $ph : '') ?>">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-sm">Simpan tarif per bulan</button>
        </form>
        <p class="small text-muted mb-0 syahriyah-bulan-intro">Klik <strong>Ubah per bulan</strong> untuk mengisi tarif syahriyah dan makan per bulan tagihan.</p>
    </div>
</div>
