<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $pack
 */
$summary = (array) ($pack['summary'] ?? []);
$portalLight = !empty($pack['portal_light']);
$perSantriShow = array_slice((array) ($pack['per_santri'] ?? []), 0, 15);
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Kasus izin sakit</div>
                <div class="fs-2 fw-bold text-info"><?= (int) ($summary['total_kasus'] ?? 0) ?></div>
                <div class="small text-muted"><?= (int) ($summary['total_santri'] ?? 0) ?> santri unik</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Total hari sakit</div>
                <div class="fs-2 fw-bold text-primary"><?= (int) ($summary['total_hari_sakit'] ?? 0) ?></div>
                <div class="small text-muted">Rata <?= htmlspecialchars((string) ($summary['rata_hari_per_santri'] ?? 0)) ?> hari/santri</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">Sakit aktif hari ini</div>
                <div class="fs-2 fw-bold text-warning"><?= (int) ($summary['sakit_aktif_hari_ini'] ?? 0) ?></div>
                <div class="small text-muted">Izin sakit / presensi sakit</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
            <div class="card-body">
                <div class="small text-muted text-uppercase fw-bold">E-Health &amp; suhu tinggi</div>
                <div class="fs-2 fw-bold text-danger"><?= (int) ($summary['ehealth_records'] ?? 0) ?></div>
                <div class="small text-muted"><?= (int) ($summary['suhu_tinggi'] ?? 0) ?> catatan ≥38°C</div>
            </div>
        </div>
    </div>
</div>

<?php if (!$portalLight): ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Tren 6 Bulan — Kasus &amp; Santri</div>
                <div class="card-body">
                    <div style="height:260px"><canvas id="chartKesehatanBulan"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Status Penanganan (E-Health)</div>
            <div class="card-body d-flex justify-content-center">
                <div style="height:220px;width:100%;max-width:320px"><canvas id="chartKesehatanStatus"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Distribusi Suhu Tubuh</div>
            <div class="card-body">
                <div style="height:220px"><canvas id="chartKesehatanSuhu"></canvas></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$portalLight && !empty($pack['gejala_top'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Gejala Terbanyak (E-Health)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Gejala</th><th class="text-end">Frekuensi</th></tr></thead>
                <tbody>
                <?php foreach ((array) $pack['gejala_top'] as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars(ucfirst((string) ($g['gejala'] ?? ''))) ?></td>
                        <td class="text-end font-monospace"><?= (int) ($g['jumlah'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Ranking Santri</span>
                    <span class="badge text-bg-secondary"><?= count($perSantriShow) ?><?= $portalLight && count((array) ($pack['per_santri'] ?? [])) > 15 ? '+' : '' ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:360px;overflow-y:auto">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light sticky-top"><tr><th>Santri</th><th class="text-end">Kasus</th><th class="text-end">Hari</th></tr></thead>
                        <tbody>
                            <?php foreach ($perSantriShow as $ps): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string) ($ps['nama_santri'] ?? '')) ?></div>
                                    <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($ps['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($ps['tingkatan'] ?? '')) ?></div>
                                </td>
                                <td class="text-end small"><?= (int) ($ps['kasus'] ?? 0) ?></td>
                                <td class="text-end small fw-semibold text-primary"><?= (int) ($ps['hari_sakit'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (($pack['per_santri'] ?? []) === []): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Sakit Aktif Hari Ini</div>
            <div class="card-body p-0">
                <?php $aktif = (array) ($pack['aktif_hari_ini'] ?? []); ?>
                <?php if ($aktif === []): ?>
                    <div class="p-4 text-center text-muted">Tidak ada santri sakit yang perlu perhatian hari ini.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Santri</th><th>Periode</th><th>Sumber</th><th>Alasan</th></tr></thead>
                            <tbody>
                            <?php foreach ($aktif as $a): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars((string) ($a['nama_santri'] ?? '')) ?></div>
                                        <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($a['tingkatan'] ?? '')) ?></div>
                                    </td>
                                    <td class="small text-nowrap"><?= htmlspecialchars((string) ($a['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($a['tanggal_selesai'] ?? '')) ?></td>
                                    <td class="small"><span class="badge text-bg-info"><?= htmlspecialchars(str_replace('_', ' ', (string) ($a['sumber'] ?? ''))) ?></span></td>
                                    <td class="small"><?= htmlspecialchars((string) ($a['alasan'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($portalLight): ?>
<div class="alert alert-light border small mb-4 py-2">
    <i class="fa-solid fa-circle-info text-primary me-1"></i>
    Portal menampilkan ringkasan bulan berjalan (top 15 santri, tanpa grafik tren).
    <a class="alert-link" href="<?= htmlspecialchars(app_href('/perizinan/rekap_aktif.php')) ?>">Buka rekap izin lengkap <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
</div>
<?php endif; ?>

<?php if (!$portalLight): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Detail Izin Sakit — <?= htmlspecialchars((string) ($pack['periode_label'] ?? '')) ?></span>
        <span class="badge text-bg-info"><?= count((array) ($pack['detail_rows'] ?? [])) ?> kasus</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Santri</th>
                        <th>Periode izin</th>
                        <th class="text-end">Hari</th>
                        <th>Gejala / alasan</th>
                        <th>Suhu</th>
                        <th>Status</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ((array) ($pack['detail_rows'] ?? []) as $dr): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($dr['nama_santri'] ?? '')) ?></div>
                            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars((string) ($dr['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($dr['tingkatan'] ?? '')) ?></div>
                        </td>
                        <td class="small text-nowrap">
                            <?= htmlspecialchars((string) ($dr['tanggal_mulai'] ?? '')) ?>
                            – <?= htmlspecialchars((string) ($dr['tanggal_selesai'] ?? '')) ?>
                        </td>
                        <td class="text-end small fw-semibold"><?= (int) ($dr['hari_efektif'] ?? 0) ?></td>
                        <td class="small" style="max-width:14rem">
                            <?= htmlspecialchars((string) (($dr['gejala'] ?? '') !== '' ? $dr['gejala'] : ($dr['alasan'] ?? '—'))) ?>
                        </td>
                        <td class="small font-monospace">
                            <?= $dr['suhu_tubuh'] !== null && $dr['suhu_tubuh'] !== '' ? htmlspecialchars((string) $dr['suhu_tubuh']) . '°C' : '—' ?>
                        </td>
                        <td class="small"><?= htmlspecialchars(yayasan_kesehatan_status_label((string) ($dr['status_kesehatan'] ?? ''))) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($dr['tindakan'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (($pack['detail_rows'] ?? []) === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada izin sakit pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>