<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/akademik_rapor.php';

/**
 * Partial isi rapor: presensi bulanan + tugas pembimbing.
 * Variabel: $raporPeriodeLabel, $raporPresensi, $raporTugas, $raporSetoran, $raporCompact (bool, opsional)
 */
$raporCompact = !empty($raporCompact);
$raporPeriodeLabel = (string) ($raporPeriodeLabel ?? '');
$raporPresensi = is_array($raporPresensi ?? null) ? $raporPresensi : null;
$raporTugas = is_array($raporTugas ?? null) ? $raporTugas : [];
$raporSetoran = is_array($raporSetoran ?? null) ? $raporSetoran : [];
?>

<?php if ($raporPeriodeLabel !== ''): ?>
    <p class="small text-muted mb-2">Periode penilaian: <strong><?= htmlspecialchars($raporPeriodeLabel) ?></strong></p>
<?php endif; ?>

<section class="mb-3">
    <h3 class="h6 text-primary border-bottom pb-1 mb-2">Presensi bulanan</h3>
    <?php if ($raporPresensi === null): ?>
        <p class="small text-muted mb-0">Tidak ada data presensi pada periode ini.</p>
    <?php else: ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="badge text-bg-<?= rapor_kategori_badge_class((string) ($raporPresensi['kategori'] ?? '')) ?>">
                <?= htmlspecialchars((string) ($raporPresensi['kategori'] ?? '-')) ?>
            </span>
            <span class="small">Hadir <strong><?= (int) ($raporPresensi['hadir'] ?? 0) ?></strong> / <?= (int) ($raporPresensi['total'] ?? 0) ?>
                (<?= htmlspecialchars((string) ($raporPresensi['persen_hadir'] ?? 0)) ?>%)</span>
            <span class="small text-muted">I: <?= (int) ($raporPresensi['izin'] ?? 0) ?> · S: <?= (int) ($raporPresensi['sakit'] ?? 0) ?> · A: <?= (int) ($raporPresensi['alpa'] ?? 0) ?></span>
        </div>
        <?php
        $perKg = $raporPresensi['per_kegiatan'] ?? [];
        if (is_array($perKg) && $perKg !== []):
            ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0<?= $raporCompact ? ' small' : '' ?>">
                    <thead class="table-light">
                        <tr>
                            <th>Kegiatan</th>
                            <th class="text-center">H</th>
                            <th class="text-center">I</th>
                            <th class="text-center">S</th>
                            <th class="text-center">A</th>
                            <th class="text-center">%</th>
                            <th>Kategori</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($perKg as $namaKg => $kg): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $namaKg) ?></td>
                            <td class="text-center"><?= (int) ($kg['hadir'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($kg['izin'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($kg['sakit'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($kg['alpa'] ?? 0) ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) ($kg['persen_hadir'] ?? 0)) ?></td>
                            <td>
                                <span class="badge text-bg-<?= rapor_kategori_badge_class((string) ($kg['kategori'] ?? '')) ?>">
                                    <?= htmlspecialchars((string) ($kg['kategori'] ?? '-')) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="mb-3">
    <h3 class="h6 text-primary border-bottom pb-1 mb-2">Setoran hafalan</h3>
    <?php if ($raporSetoran === []): ?>
        <p class="small text-muted mb-0">Belum ada setoran pada periode ini.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0<?= $raporCompact ? ' small' : '' ?>">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Materi / target</th>
                        <th class="text-end">Nilai</th>
                        <th>Predikat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($raporSetoran as $st): ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars((string) ($st['tanggal_setoran'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars(rapor_setoran_kategori_label((string) ($st['kategori_setoran'] ?? 'ALQURAN'))) ?></td>
                        <td class="small">
                            <?= htmlspecialchars((string) ($st['target_hafalan'] ?? '')) ?>
                            <?php if (trim((string) ($st['juz_halaman'] ?? '')) !== ''): ?>
                                <span class="text-muted"> · <?= htmlspecialchars((string) $st['juz_halaman']) ?></span>
                            <?php endif; ?>
                            <?php if (strtoupper((string) ($st['kategori_setoran'] ?? '')) === 'BAIT' && (int) ($st['baris_setor'] ?? 0) > 0): ?>
                                <span class="text-muted"> (<?= (int) $st['baris_setor'] ?> baris)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $st['nilai_skor'] !== null && $st['nilai_skor'] !== '' ? htmlspecialchars((string) $st['nilai_skor']) : '—' ?></td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($st['predikat'] ?? '')) !== '' ? (string) $st['predikat'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section>
    <h3 class="h6 text-primary border-bottom pb-1 mb-2">Hasil tugas (Ikhtibar) per pembimbing</h3>
    <?php if ($raporTugas === []): ?>
        <p class="small text-muted mb-0">Belum ada tugas / nilai pada periode ini.</p>
    <?php else: ?>
        <?php foreach ($raporTugas as $grp): ?>
            <div class="mb-3">
                <div class="fw-semibold small mb-1">
                    <?= htmlspecialchars((string) ($grp['pembimbing_nama'] ?? 'Pembimbing')) ?>
                    <span class="text-muted fw-normal">· <?= htmlspecialchars((string) ($grp['mapel_label'] ?? '')) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0<?= $raporCompact ? ' small' : '' ?>">
                        <thead>
                            <tr>
                                <th>Tugas</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th class="text-end">PG</th>
                                <th class="text-end">Esai</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($grp['tugas'] ?? []) as $t): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></td>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) ($t['tanggal'] ?? '')) ?></td>
                                <td class="small"><?= htmlspecialchars(rapor_sesi_status_label((string) ($t['sesi_status'] ?? ''))) ?></td>
                                <td class="text-end"><?= $t['skor_pg'] !== null && $t['skor_pg'] !== '' ? htmlspecialchars((string) $t['skor_pg']) : '—' ?></td>
                                <td class="text-end"><?= $t['skor_esai'] !== null && $t['skor_esai'] !== '' ? htmlspecialchars((string) $t['skor_esai']) : '—' ?></td>
                                <td class="text-end fw-semibold"><?= $t['nilai_total'] !== null && $t['nilai_total'] !== '' ? htmlspecialchars((string) $t['nilai_total']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
