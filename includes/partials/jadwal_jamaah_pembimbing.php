<?php

declare(strict_types=1);

/**
 * Panel atur munawib jamaah harian (per hari & kelompok Putra/Putri).
 *
 * @var array<string, array<int, int>> $jamaahMunawibMap
 * @var list<array<string,mixed>> $munawibList
 * @var bool $showJadwalAksi
 */
$jamaahMunawibMap = $jamaahMunawibMap ?? ($jamaahPembimbingMap ?? ['putra' => [], 'putri' => []]);
$munawibList = $munawibList ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;

$hariPb = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu',
];
?>
<div class="jadwal-jamaah-intro mb-3">
    <p class="small text-muted mb-2">
        Berbeda dari ta'lim, <strong>munawib</strong> jamaah ditugaskan <strong>per hari</strong> untuk seluruh kegiatan jamaah
        dalam kelompok <strong>Putra</strong> atau <strong>Putri</strong> — bukan per slot Subuh/Dzuhur/dll.
        Satu munawib mengawasi semua shalat jamaah kelompoknya pada hari tersebut.
    </p>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah')) ?>">
            <i class="fa-solid fa-clock me-1"></i> Atur waktu jamaah
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=daftar&filter_kat=JAMAAH')) ?>">
            <i class="fa-solid fa-list me-1"></i> Lihat slot jadwal
        </a>
    </div>
</div>

<?php if ($munawibList === []): ?>
    <div class="alert alert-warning py-2 small">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        Belum ada data munawib. Tambahkan di menu <a href="<?= htmlspecialchars(app_href('/pembimbing/munawib.php')) ?>">Data Munawib</a>.
    </div>
<?php endif; ?>

<?php if ($showJadwalAksi): ?>
<form method="post" class="jadwal-jamaah-pembimbing-form">
    <input type="hidden" name="action" value="jamaah_munawib">

    <div class="table-responsive jadwal-jamaah-pembimbing-table-wrap">
        <table class="table table-sm table-bordered align-middle jadwal-jamaah-pembimbing-table mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="jadwal-jamaah-pembimbing-table__hari">Hari</th>
                    <th scope="col">
                        <i class="fa-solid fa-mars text-primary me-1" aria-hidden="true"></i>Putra
                    </th>
                    <th scope="col">
                        <i class="fa-solid fa-venus text-danger me-1" aria-hidden="true"></i>Putri
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hariPb as $hk => $hn): ?>
                    <tr>
                        <th scope="row" class="jadwal-jamaah-pembimbing-table__hari fw-semibold"><?= htmlspecialchars($hn) ?></th>
                        <?php foreach (jadwal_jamaah_kelompok_valid() as $kel): ?>
                            <?php $sel = (int) ($jamaahMunawibMap[$kel][$hk] ?? 0); ?>
                            <td>
                                <select name="<?= htmlspecialchars($kel) ?>[<?= (int) $hk ?>]" class="form-select form-select-sm">
                                    <option value="0">— Belum ditentukan —</option>
                                    <?php foreach ($munawibList as $mw): ?>
                                        <option value="<?= (int) $mw['id'] ?>" <?= $sel === (int) $mw['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($mw['nama'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-floppy-disk me-1"></i> Simpan munawib harian
        </button>
    </div>
</form>
<?php else: ?>
    <div class="table-responsive jadwal-jamaah-pembimbing-table-wrap">
        <table class="table table-sm table-bordered align-middle jadwal-jamaah-pembimbing-table mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Hari</th>
                    <th scope="col">Putra</th>
                    <th scope="col">Putri</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hariPb as $hk => $hn): ?>
                    <tr>
                        <th scope="row" class="fw-semibold"><?= htmlspecialchars($hn) ?></th>
                        <?php foreach (jadwal_jamaah_kelompok_valid() as $kel): ?>
                            <?php
                            $mid = (int) ($jamaahMunawibMap[$kel][$hk] ?? 0);
                            $nama = $mid > 0 ? jadwal_jamaah_munawib_label_hari($pdo, $hk, $kel) : '—';
                            ?>
                            <td><?= htmlspecialchars($nama !== '' ? $nama : '—') ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p class="small text-muted mt-3 mb-0">
    <i class="fa-solid fa-circle-info me-1"></i>
    Kehadiran jamaah dicatat lewat scan QR munawib, bukan pembimbing ta'lim.
</p>
