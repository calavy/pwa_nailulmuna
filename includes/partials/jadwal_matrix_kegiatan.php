<?php

declare(strict_types=1);

/**
 * Peta jadwal: kolom terpisah Hari · Waktu · Kegiatan · Tingkatan.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$petaRows = jadwal_peta_rows_sorted($jadwalList);
$prevKegiatan = '';
?>
<?php if ($petaRows === []): ?>
    <div class="jadwal-peta-empty text-center py-4">
        <div class="jadwal-peta-empty__ico mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
        <p class="text-muted small mb-0">Belum ada jadwal. Tambah lewat tombol <strong>+ Jadwal</strong>.</p>
    </div>
<?php else: ?>
    <div class="jadwal-peta">
        <div class="jadwal-peta-toolbar d-none d-md-flex">
            <span class="jadwal-peta-toolbar__item"><i class="fa-solid fa-calendar-day"></i> Hari</span>
            <span class="jadwal-peta-toolbar__item"><i class="fa-regular fa-clock"></i> Waktu</span>
            <span class="jadwal-peta-toolbar__item"><i class="fa-solid fa-bookmark"></i> Kegiatan</span>
            <span class="jadwal-peta-toolbar__item"><i class="fa-solid fa-layer-group"></i> Tingkatan</span>
        </div>
        <div class="jadwal-peta-scroll table-responsive">
            <table class="jadwal-peta-table table mb-0">
                <thead>
                    <tr>
                        <th class="jadwal-peta-th jadwal-peta-th--hari">Hari</th>
                        <th class="jadwal-peta-th jadwal-peta-th--waktu">Waktu</th>
                        <th class="jadwal-peta-th jadwal-peta-th--kegiatan">Nama kegiatan</th>
                        <th class="jadwal-peta-th jadwal-peta-th--tingkatan">Tingkatan</th>
                        <th class="jadwal-peta-th jadwal-peta-th--extra d-none d-lg-table-cell">Lokasi</th>
                        <th class="jadwal-peta-th jadwal-peta-th--extra d-none d-xl-table-cell">Pembimbing</th>
                        <th class="jadwal-peta-th jadwal-peta-th--aksi text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($petaRows as $row):
                    $namaKg = trim((string) ($row['nama_kegiatan'] ?? '—'));
                    $hk = (int) ($row['hari_ke'] ?? 0);
                    $hariLabel = $hari[$hk] ?? ('Hari ' . $hk);
                    $hariSlug = jadwal_hari_badge_slug($hk);
                    $tingkatan = trim((string) ($row['tingkatan'] ?? '—'));
                    $tempat = trim((string) ($row['tempat'] ?? ''));
                    $pem = trim((string) ($row['nama_pembimbing'] ?? ''));
                    $groupStart = $namaKg !== $prevKegiatan;
                    $prevKegiatan = $namaKg;
                    ?>
                    <tr class="jadwal-peta-row<?= $groupStart ? ' jadwal-peta-row--group-start' : '' ?>">
                        <td class="jadwal-peta-td jadwal-peta-td--hari" data-label="Hari">
                            <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($hariSlug) ?>">
                                <?= htmlspecialchars($hariLabel) ?>
                            </span>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--waktu" data-label="Waktu">
                            <span class="jadwal-peta-waktu">
                                <i class="fa-regular fa-clock jadwal-peta-waktu__ico" aria-hidden="true"></i>
                                <?= htmlspecialchars(jadwal_jam_ringkas($row)) ?>
                            </span>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--kegiatan" data-label="Kegiatan">
                            <span class="jadwal-peta-kegiatan">
                                <?php if ($groupStart): ?>
                                    <span class="jadwal-peta-kegiatan__dot" aria-hidden="true"></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($namaKg) ?>
                            </span>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--tingkatan" data-label="Tingkatan">
                            <span class="jadwal-peta-tingkatan"><?= htmlspecialchars($tingkatan) ?></span>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--extra d-none d-lg-table-cell" data-label="Lokasi">
                            <?php if ($tempat !== ''): ?>
                                <span class="jadwal-peta-meta"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($tempat) ?></span>
                            <?php else: ?>
                                <span class="jadwal-peta-meta jadwal-peta-meta--empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--extra d-none d-xl-table-cell" data-label="Pembimbing">
                            <?php if ($pem !== '' && $pem !== '-'): ?>
                                <span class="jadwal-peta-meta"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($pem) ?></span>
                            <?php else: ?>
                                <span class="jadwal-peta-meta jadwal-peta-meta--empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-td--aksi text-end text-nowrap" data-label="Aksi">
                            <?php $jid = (int) ($row['id'] ?? 0); ?>
                            <?php if ($jid > 0): ?>
                                <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $jid)) ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Edit jadwal"><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus slot jadwal ini? Presensi terkait ikut dihapus.')">
                                    <input type="hidden" name="action" value="hapus_jadwal">
                                    <input type="hidden" name="id" value="<?= $jid ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Hapus jadwal"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="jadwal-peta-foot small text-muted mb-0 mt-2">
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= count($petaRows) ?> slot · diurutkan per kegiatan, hari, dan jam.
        </p>
    </div>
<?php endif; ?>
