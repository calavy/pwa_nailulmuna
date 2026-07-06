<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var callable(int): string $formatRupiah */

require_once __DIR__ . '/../../helpers/keuangan_rekap_tagihan_bulan.php';

$wajib = keuangan_pengaturan_wajib_ringkas($pdo);
$fmt = $formatRupiah ?? static fn(int $n): string => keuangan_format_rupiah($n);
?>
<div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Wajib bayar — santri baru vs lama</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=tarif#tarif-awal-jenis')) ?>">Atur tarif &amp; komponen</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <h3 class="h6 text-secondary">Tagihan bulanan</h3>
                <ul class="small mb-0 ps-3">
                    <li>
                        <strong>Wajib:</strong>
                        <?= htmlspecialchars(implode(', ', (array) ($wajib['wajib_bulanan'] ?? [])) ?: '—') ?>
                    </li>
                    <li>
                        <strong>Opsional:</strong>
                        <?= htmlspecialchars(implode(', ', (array) ($wajib['opsional_bulanan'] ?? [])) ?: '—') ?>
                        (override per santri di tab Per santri)
                    </li>
                    <li>
                        <strong>Santri baru:</strong>
                        <?= !empty($wajib['tagihan_mulai_masuk'])
                            ? 'Ditagih mulai bulan tanggal masuk TA'
                            : '<span class="text-muted">Aturan bulan masuk nonaktif — semua dari bulan 1</span>' ?>
                    </li>
                    <li><strong>Santri lama:</strong> Ditagih penuh dari bulan 1 TA</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h3 class="h6 text-secondary">Pembayaran awal tahun</h3>
                <p class="small mb-2">
                    <?= !empty($wajib['bedakan_awal_tahun'])
                        ? 'Tarif &amp; komponen <strong>berbeda</strong> untuk santri baru vs lama.'
                        : 'Tarif awal tahun <strong>sama</strong> untuk semua santri (beda jenis nonaktif).' ?>
                </p>
                <?php if (($wajib['komponen_awal_tahun'] ?? []) !== []): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Komponen</th>
                                <th class="text-center">Baru</th>
                                <th class="text-center">Lama</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($wajib['komponen_awal_tahun'] as $k): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($k['nama'] ?? '')) ?></td>
                                <td class="text-center"><?= !empty($k['baru']) ? '✓' : '—' ?></td>
                                <td class="text-center"><?= !empty($k['lama']) ? '✓' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="small text-muted mb-0">Belum ada komponen awal tahun.</p>
                <?php endif; ?>
            </div>
        </div>
        <p class="small text-muted mb-0">
            Target di <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>">Rekap Kas Bulanan</a>
            dan <a href="<?= htmlspecialchars(app_href('/pembayaran/rekap_pos.php')) ?>">Rekap per POS</a>
            dihitung otomatis dari pengaturan ini × santri aktif (termasuk aturan bulan masuk &amp; jenis baru/lama).
        </p>
    </div>
</div>
