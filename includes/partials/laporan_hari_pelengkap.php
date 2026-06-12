<?php

declare(strict_types=1);

/**
 * Blok pelengkap laporan harian (telat, kegiatan khusus, PKPPS, perizinan, action list).
 *
 * @var array{kegiatan:list<array>,izin:list<array>,stats:array<string,int>} $telatData
 * @var list<array<string,mixed>> $kegiatanKhususHari
 * @var list<array<string,mixed>> $pkppsSnapshot
 * @var array{aktif:list<array>,pending:list<array>,pending_count:int} $perizinanHari
 * @var list<array<string,mixed>> $santriPerhatian
 */

$telatStats = $telatData['stats'] ?? [];
$telatKeg = $telatData['kegiatan'] ?? [];
$telatIzin = $telatData['izin'] ?? [];
?>

<?php if (($santriPerhatian ?? []) !== []): ?>
<section class="mb-4" id="laporan-action-list">
    <h2 class="yp-section-title"><i class="fa-solid fa-bell me-2"></i>Santri Perlu Perhatian</h2>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Prioritas</th><th>Santri</th><th>Kelas</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                <?php foreach ($santriPerhatian as $sp): ?>
                    <?php
                    $prio = (string) ($sp['priority'] ?? 'info');
                    $badge = match ($prio) {
                        'tinggi' => 'danger',
                        'sedang' => 'warning',
                        default => 'secondary',
                    };
                    ?>
                    <tr>
                        <td><span class="badge text-bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars((string) ($sp['label'] ?? '')) ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) ($sp['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($sp['tingkatan'] ?? '-')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($sp['detail'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="mb-4" id="laporan-telat">
    <h2 class="yp-section-title"><i class="fa-solid fa-clock me-2"></i>Keterlambatan Hari Ini</h2>
    <?php if ((int) ($telatStats['telat_kegiatan'] ?? 0) === 0 && (int) ($telatStats['telat_izin'] ?? 0) === 0): ?>
        <div class="yp-empty-inline">Tidak ada keterlambatan tercatat hari ini.</div>
    <?php else: ?>
        <div class="row g-2 mb-2">
            <div class="col-auto"><span class="badge text-bg-warning text-dark"><?= (int) ($telatStats['telat_kegiatan'] ?? 0) ?> telat kegiatan</span></div>
            <div class="col-auto"><span class="badge text-bg-info"><?= (int) ($telatStats['telat_izin'] ?? 0) ?> telat kembali izin</span></div>
            <?php if ((int) ($telatStats['telat_berat'] ?? 0) > 0): ?>
                <div class="col-auto"><span class="badge text-bg-danger"><?= (int) $telatStats['telat_berat'] ?> telat ≥60 menit</span></div>
            <?php endif; ?>
        </div>
        <?php if ($telatKeg !== []): ?>
        <div class="card border-0 shadow-sm mb-2">
            <div class="card-header bg-white py-2 small fw-semibold">Telat scan kegiatan</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Santri</th><th>Kelas</th><th>Kegiatan</th><th>Telat</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($telatKeg, 0, 30) as $tr): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($tr['nama_santri'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($tr['tingkatan'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($tr['nama_kegiatan'] ?? '')) ?></td>
                            <td><span class="badge text-bg-warning text-dark"><?= (int) ($tr['telat_menit'] ?? 0) ?> mnt</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($telatIzin !== []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 small fw-semibold">Telat kembali izin</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Santri</th><th>Kelas</th><th>Jenis</th><th>Keterangan</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($telatIzin, 0, 20) as $tr): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($tr['nama_santri'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($tr['tingkatan'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($tr['jenis_izin'] ?? '')) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars(mb_substr((string) ($tr['alasan'] ?? ''), 0, 80)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="mb-4" id="laporan-kegiatan-khusus">
    <h2 class="yp-section-title"><i class="fa-solid fa-star me-2"></i>Kegiatan Khusus Hari Ini</h2>
    <?php if (($kegiatanKhususHari ?? []) === []): ?>
        <div class="yp-empty-inline">Tidak ada kegiatan khusus dijadwalkan hari ini.</div>
    <?php else: ?>
        <div class="table-responsive card border-0 shadow-sm">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Kegiatan</th><th>Kategori</th><th>Kelas</th><th>Waktu</th><th>Scan</th></tr></thead>
                <tbody>
                <?php foreach ($kegiatanKhususHari as $kk): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars((string) ($kk['nama_kegiatan'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($kk['kategori_kegiatan'] ?? 'TAALIM')) ?></td>
                        <td><?= htmlspecialchars((string) ($kk['tingkatan'] ?? '-')) ?></td>
                        <td class="small"><?= htmlspecialchars(substr((string) ($kk['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($kk['jam_selesai'] ?? ''), 0, 5)) ?></td>
                        <td><strong><?= (int) ($kk['total_scan'] ?? 0) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="mb-4" id="laporan-pkpps">
    <h2 class="yp-section-title"><i class="fa-solid fa-layer-group me-2"></i>PKPPS Hari Ini</h2>
    <?php if (($pkppsSnapshot ?? []) === []): ?>
        <div class="yp-empty-inline">Belum ada presensi PKPPS tercatat hari ini.</div>
    <?php else: ?>
        <div class="yp-kelas-grid">
            <?php foreach ($pkppsSnapshot as $pk): ?>
                <div class="yp-kelas-card">
                    <div class="yp-kelas-card__head">
                        <div class="yp-kelas-card__tk"><?= htmlspecialchars((string) ($pk['tingkatan'] ?? '')) ?></div>
                        <div class="yp-kelas-card__pct"><?= (int) round((float) ($pk['persen'] ?? 0)) ?>%</div>
                    </div>
                    <div class="yp-kelas-card__ratio"><strong><?= (int) ($pk['hadir'] ?? 0) ?></strong>/<?= (int) ($pk['total'] ?? 0) ?></div>
                    <div class="yp-kelas-card__sub">H <?= (int) ($pk['hadir'] ?? 0) ?> · I <?= (int) ($pk['izin'] ?? 0) ?> · S <?= (int) ($pk['sakit'] ?? 0) ?> · A <?= (int) ($pk['alpa'] ?? 0) ?></div>
                    <div class="progress mt-2" style="height:6px">
                        <div class="progress-bar bg-primary" style="width:<?= (float) ($pk['persen'] ?? 0) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2 mb-0"><a href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php')) ?>">Rekap PKPPS lengkap</a></p>
    <?php endif; ?>
</section>

<section class="mb-4" id="laporan-perizinan">
    <h2 class="yp-section-title"><i class="fa-solid fa-person-walking-arrow-right me-2"></i>Perizinan</h2>
    <?php if ((int) ($perizinanHari['pending_count'] ?? 0) > 0): ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="fa-solid fa-hourglass-half me-1"></i>
            <strong><?= (int) $perizinanHari['pending_count'] ?></strong> pengajuan izin menunggu persetujuan pengasuh.
            <a href="<?= htmlspecialchars(app_href('/pengasuh/perizinan.php')) ?>" class="alert-link ms-1">Tinjau</a>
        </div>
    <?php endif; ?>
    <?php if (($perizinanHari['aktif'] ?? []) === []): ?>
        <div class="yp-empty-inline">Tidak ada izin aktif hari ini.</div>
    <?php else: ?>
        <div class="table-responsive card border-0 shadow-sm">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Santri</th><th>Kelas</th><th>Jenis</th><th>Periode</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($perizinanHari['aktif'], 0, 25) as $iz): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($iz['tingkatan'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($iz['jenis_izin'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($iz['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($iz['tanggal_selesai'] ?? '')) ?></td>
                        <td><span class="badge text-bg-info"><?= htmlspecialchars((string) ($iz['status_izin'] ?? 'IZIN')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
