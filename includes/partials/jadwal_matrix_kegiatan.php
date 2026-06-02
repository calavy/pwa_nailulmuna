<?php

declare(strict_types=1);

/**
 * Peta jadwal: satu baris per slot waktu (kegiatan · tingkatan · hari · pembimbing · waktu).
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$jadwalPembimbingScope = $jadwalPembimbingScope ?? false;
$petaRows = jadwal_peta_rows_gabung($jadwalList);
?>
<?php if ($petaRows === []): ?>
    <div class="jadwal-peta-empty text-center py-4">
        <div class="jadwal-peta-empty__ico mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
        <p class="text-muted small mb-0">Belum ada jadwal. Tambah <a href="<?= htmlspecialchars(app_href('/jadwal/tambah_kegiatan.php')) ?>">kegiatan</a> lalu buat <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>">jadwal baru</a>.</p>
    </div>
<?php else: ?>
    <div class="jadwal-peta">
        <div class="table-responsive">
            <table class="jadwal-peta-table table table-sm table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kegiatan</th>
                        <th>Tingkatan</th>
                        <th>Hari</th>
                        <th>Pembimbing</th>
                        <th>Waktu</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($petaRows as $row):
                    $namaKg = trim((string) ($row['nama_kegiatan'] ?? '—'));
                    $hariLabel = jadwal_hari_list_label($row['_hari_list'] ?? [(int) ($row['hari_ke'] ?? 0)], $hari);
                    $tingkatan = trim((string) ($row['tingkatan'] ?? '—'));
                    $pem = trim((string) ($row['nama_pembimbing'] ?? ''));
                    ?>
                    <tr>
                        <td class="small fw-semibold"><?= htmlspecialchars($namaKg) ?></td>
                        <td class="small">
                            <?php
                            $tkList = $row['_tingkatan_list'] ?? [];
                            if ($tkList === [] && $tingkatan !== '' && $tingkatan !== '—') {
                                $tkList = [$tingkatan];
                            }
                            if ($tkList !== []): ?>
                                <?php foreach ($tkList as $tkBadge): ?>
                                    <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge me-1"><?= htmlspecialchars((string) $tkBadge) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="small text-nowrap"><?= htmlspecialchars($hariLabel) ?></td>
                        <td class="small"><?= ($pem !== '' && $pem !== '-') ? htmlspecialchars($pem) : '—' ?></td>
                        <td class="small font-monospace js-time-24 text-nowrap"><?= htmlspecialchars(jadwal_jam_ringkas($row)) ?></td>
                        <td class="text-end text-nowrap">
                            <?php $jid = (int) ($row['id'] ?? 0); ?>
                            <?php if ($jid > 0 && ($showJadwalAksi || $jadwalPembimbingScope)): ?>
                                <?php if ($showJadwalAksi): ?>
                                <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $jid)) ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Edit jadwal"><i class="fa-solid fa-pen"></i></a>
                                <?php endif; ?>
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
        <p class="jadwal-peta-foot small text-muted mb-0 mt-2 px-2">
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= count($petaRows) ?> baris jadwal · waktu sama digabung (hari & tingkatan).
        </p>
    </div>
<?php endif; ?>
