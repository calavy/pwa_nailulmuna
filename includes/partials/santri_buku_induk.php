<?php

declare(strict_types=1);

/**
 * Partial: Buku Induk Digital (timeline santri).
 *
 * @var PDO $pdo
 * @var array<string, mixed> $santri
 * @var list<array<string, mixed>> $tingkatanRows
 * @var list<array<string, mixed>> $hidmahRows
 * @var list<array<string, mixed>> $asramaRows
 * @var list<array<string, mixed>> $keaktifanPerTahun
 * @var list<array<string, mixed>> $pelanggaranRows
 * @var int $filterTa
 * @var bool $readOnly
 * @var bool $showKeaktifanNilai
 * @var bool $showPelanggaran
 * @var bool $showRiwayatSensitif @deprecated gunakan $showKeaktifanNilai / $showPelanggaran
 * @var string $filterFormAction
 * @var string|null $tabHidden
 */

$filterTa = (int) ($filterTa ?? 0);
$readOnly = (bool) ($readOnly ?? false);
$filterFormAction = (string) ($filterFormAction ?? '');
$tabHidden = isset($tabHidden) ? (string) $tabHidden : null;
$santriId = (int) ($santri['id'] ?? 0);
$legacySensitif = (bool) ($showRiwayatSensitif ?? false);
if (!isset($showKeaktifanNilai)) {
    $showKeaktifanNilai = !$readOnly && function_exists('user_can_view_keaktifan_nilai')
        ? user_can_view_keaktifan_nilai()
        : $legacySensitif;
}
if (!isset($showPelanggaran)) {
    $showPelanggaran = $santriId > 0 && !$readOnly && function_exists('user_can_view_pelanggaran_riwayat')
        ? user_can_view_pelanggaran_riwayat($santriId)
        : $legacySensitif;
}
$showKeaktifanNilai = (bool) $showKeaktifanNilai;
$showPelanggaran = (bool) $showPelanggaran;

$tingkatanShow = santri_riwayat_filter_ta_mulai($tingkatanRows ?? [], $filterTa);
$hidmahShow = santri_riwayat_filter_ta_mulai($hidmahRows ?? [], $filterTa);
$asramaShow = santri_riwayat_filter_ta_mulai($asramaRows ?? [], $filterTa);
$keaktifanShow = santri_riwayat_filter_tahun_kalender_ta($keaktifanPerTahun ?? [], $filterTa);
$pelanggaranShow = $pelanggaranRows ?? [];
$totalPoinPel = 0;
foreach ($pelanggaranShow as $pl) {
    $totalPoinPel += (int) ($pl['point_delta'] ?? 0);
}
$taOptions = santri_riwayat_tahun_filter_options($pdo, $santriId);

$resetHref = $filterFormAction;
if ($tabHidden !== null && $tabHidden !== '') {
    $resetHref .= (str_contains($resetHref, '?') ? '&' : '?') . 'tab=' . urlencode($tabHidden);
}
?>
<link href="/assets/css/santri-timeline.css" rel="stylesheet">

<div class="santri-buku-induk">
    <form method="get" action="<?= htmlspecialchars($filterFormAction) ?>" class="buku-filter card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <input type="hidden" name="id" value="<?= $santriId ?>">
                <?php if ($tabHidden !== null && $tabHidden !== ''): ?>
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tabHidden) ?>">
                <?php endif; ?>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small mb-0">Filter tahun ajaran</label>
                    <select name="th" class="form-select form-select-sm">
                        <option value="0">Semua tahun</option>
                        <?php foreach ($taOptions as $y): ?>
                            <option value="<?= (int) $y ?>"<?= $filterTa === (int) $y ? ' selected' : '' ?>><?= (int) $y ?>/<?= (int) $y + 1 ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex flex-wrap gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
                    <?php if ($filterTa > 0): ?>
                        <a href="<?= htmlspecialchars($resetHref) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
                <?php if (!$readOnly): ?>
                <div class="col-12 col-md ms-md-auto text-md-end">
                    <span class="small text-muted">Kelola akademik/khidmah/asrama di tab kelola. Keaktifan &amp; pelanggaran dari presensi/poin.</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <section class="buku-section" aria-labelledby="buku-akademik">
        <h2 class="buku-section-title" id="buku-akademik">A. Riwayat Akademik (Ngaji)</h2>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tahun Ajaran</th>
                        <th>Tingkatan</th>
                        <th>Kelas</th>
                        <th>Wali Kelas</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tingkatanShow as $tr): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars(santri_tahun_ajaran_label(['mulai' => (int) $tr['tahun_ajaran_mulai'], 'selesai' => (int) $tr['tahun_ajaran_selesai']], $pdo)) ?></td>
                        <td><?= htmlspecialchars((string) $tr['tingkatan']) ?></td>
                        <td><?= htmlspecialchars(santri_riwayat_kelas_tampilan($pdo, (string) ($tr['kategori_kelas'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($tr['wali_kelas'] ?? '')) !== '' ? (string) $tr['wali_kelas'] : '—') ?></td>
                        <td><span class="badge text-bg-light border"><?= htmlspecialchars(santri_riwayat_status_akademik_label((string) ($tr['status_akademik'] ?? 'BERJALAN'))) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tingkatanShow === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat akademik<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="buku-section" aria-labelledby="buku-khidmah">
        <h2 class="buku-section-title" id="buku-khidmah">B. Riwayat Khidmah (Pengabdian / Organisasi)</h2>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Bagian / Jabatan</th>
                        <th>Deskripsi Tugas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hidmahShow as $hr): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars(santri_riwayat_hidmah_periode_label($hr)) ?></td>
                        <td>
                            <span class="d-block fw-semibold"><?= htmlspecialchars((string) $hr['nama_hidmah']) ?></span>
                            <span class="badge text-bg-info" style="font-size:.65rem"><?= htmlspecialchars(santri_hidmah_jenis_label((string) $hr['jenis_peran'])) ?></span>
                        </td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($hr['keterangan'] ?? '')) !== '' ? (string) $hr['keterangan'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($hidmahShow === []): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Belum ada riwayat khidmah<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="buku-section" aria-labelledby="buku-asrama">
        <h2 class="buku-section-title" id="buku-asrama">C. Riwayat Penempatan Ruang (Asrama)</h2>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Gedung</th>
                        <th>Kamar</th>
                        <th>No. Lemari / Kasur</th>
                        <th>Periode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($asramaShow as $ar): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($ar['gedung'] ?? 'Asrama')) ?></td>
                        <td><?= htmlspecialchars((string) $ar['nama_kamar']) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($ar['no_ranjang'] ?? '')) !== '' ? (string) $ar['no_ranjang'] : '—') ?></td>
                        <td><?= htmlspecialchars(santri_riwayat_asrama_periode_label($ar)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($asramaShow === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Belum ada riwayat penempatan asrama<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($showKeaktifanNilai): ?>
    <section class="buku-section" aria-labelledby="buku-keaktifan">
        <h2 class="buku-section-title" id="buku-keaktifan">D. Nilai Keaktifan <span class="fw-normal text-muted">(Baik / Sedang / Buruk)</span></h2>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tahun</th>
                        <th>Nilai</th>
                        <th class="text-end">Hadir</th>
                        <th class="text-end">Izin</th>
                        <th class="text-end">Sakit</th>
                        <th class="text-end">ALPA</th>
                        <th class="text-end">% Hadir</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keaktifanShow as $ka): ?>
                    <tr>
                        <td class="fw-semibold"><?= (int) $ka['th'] ?></td>
                        <td>
                            <span class="badge <?= santri_riwayat_keaktifan_badge_class((string) $ka['label']) ?>"><?= htmlspecialchars((string) $ka['label']) ?></span>
                            <?php if (($ka['sumber'] ?? '') === 'pengasuh'): ?>
                                <span class="text-muted small ms-1">pengasuh</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $ka['hadir'] ?></td>
                        <td class="text-end"><?= (int) $ka['izin'] ?></td>
                        <td class="text-end"><?= (int) $ka['sakit'] ?></td>
                        <td class="text-end"><?= (int) $ka['alpa'] ?></td>
                        <td class="text-end"><?= number_format((float) $ka['persen_hadir'], 1, ',', '.') ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($keaktifanShow === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Belum ada data presensi<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted px-2 py-1 mb-0">
            Nilai ditetapkan pengasuh pondok; tanpa penilaian pengasuh, nilai mengikuti rekap presensi (ALPA per tahun kalender).
            <?php if (!$readOnly && function_exists('user_can_edit_keaktifan_nilai') && user_can_edit_keaktifan_nilai()): ?>
                <a href="<?= htmlspecialchars(app_href('/pengasuh/nilai_keaktifan.php')) ?>" class="ms-1">Kelola penilaian</a>
            <?php endif; ?>
        </p>
    </section>
    <?php endif; ?>

    <?php if ($showPelanggaran): ?>
    <section class="buku-section" aria-labelledby="buku-pelanggaran">
        <h2 class="buku-section-title" id="buku-pelanggaran">E. Riwayat Pelanggaran <span class="fw-normal text-muted">(kedisiplinan)</span></h2>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama pelanggaran</th>
                        <th>Kategori</th>
                        <th class="text-end">Poin</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pelanggaranShow as $pl): ?>
                    <?php $namaPel = santri_riwayat_pelanggaran_nama($pl); ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars((string) $pl['tanggal']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($namaPel) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($pl['kategori'] ?? '—')) ?></td>
                        <td class="text-end text-danger fw-semibold">+<?= (int) $pl['point_delta'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pelanggaranShow === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada pelanggaran<?= $filterTa > 0 ? ' untuk filter ini' : '' ?> (poin presensi tidak ditampilkan).</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($pelanggaranShow !== []): ?>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="3" class="text-end fw-semibold">Total poin (tampilan ini)</td>
                        <td class="text-end fw-bold text-danger">+<?= $totalPoinPel ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!$readOnly): ?>
        <p class="small text-muted px-2 py-1 mb-0">
            <a href="/poin/input.php">Input poin</a> ·
            <a href="/santri/riwayat.php?id=<?= $santriId ?>&tab=pelanggaran">Kelola di tab Pelanggaran</a>
        </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!$showKeaktifanNilai && !$showPelanggaran): ?>
    <div class="alert alert-light border small mb-0" role="status">
        <i class="fa-solid fa-lock me-1 text-muted"></i>
        <strong>Nilai keaktifan</strong> dapat dilihat santri di portal. <strong>Pelanggaran</strong> dapat dilihat wali dan santri melalui portal masing-masing.
    </div>
    <?php elseif (!$showKeaktifanNilai && $showPelanggaran): ?>
    <p class="small text-muted mb-0"><i class="fa-solid fa-circle-info me-1"></i> Nilai keaktifan dapat dilihat santri di <a href="/santri_portal/keaktifan.php">portal santri</a>.</p>
    <?php endif; ?>
</div>
