<?php

declare(strict_types=1);

/**
 * Peta jadwal: tabel visual dengan badge hari & waktu menonjol.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$jadwalPembimbingScope = $jadwalPembimbingScope ?? false;
$petaRows = jadwal_peta_rows_gabung($jadwalList);
$prevKg = '';
?>
<?php if ($petaRows === []): ?>
    <div class="jadwal-peta-empty text-center py-4">
        <div class="jadwal-peta-empty__ico mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
        <p class="text-muted small mb-0">Belum ada jadwal.</p>
    </div>
<?php else: ?>
    <div class="jadwal-peta">
        <div class="jadwal-peta-scroll">
            <table class="jadwal-peta-table table mb-0">
                <thead>
                    <tr>
                        <th class="jadwal-peta-th--hari">Hari</th>
                        <th class="jadwal-peta-th--waktu">Waktu</th>
                        <th>Kegiatan</th>
                        <th class="jadwal-peta-th--tingkatan">Tingkatan</th>
                        <th>Pembimbing</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($petaRows as $row):
                    $namaKg = trim((string) ($row['nama_kegiatan'] ?? '—'));
                    $kat = (string) ($row['kategori_kegiatan'] ?? 'TAALIM');
                    $hariList = $row['_hari_list'] ?? [(int) ($row['hari_ke'] ?? 0)];
                    $hkPrimary = (int) ($hariList[0] ?? 0);
                    $hariLabel = jadwal_hari_list_label($hariList, $hari);
                    $hariSlug = jadwal_hari_badge_slug($hkPrimary);
                    $tingkatan = trim((string) ($row['tingkatan'] ?? '—'));
                    $pem = trim((string) ($row['nama_pembimbing'] ?? ''));
                    $groupStart = $namaKg !== $prevKg;
                    $prevKg = $namaKg;
                    $mergeIds = array_values(array_filter(array_map('intval', $row['_merge_ids'] ?? [(int) ($row['id'] ?? 0)])));
                    $editId = (int) ($mergeIds[0] ?? 0);
                    $tkList = $row['_tingkatan_list'] ?? [];
                    if ($tkList === [] && $tingkatan !== '' && $tingkatan !== '—') {
                        $tkList = [$tingkatan];
                    }
                    ?>
                    <tr class="jadwal-peta-row<?= $groupStart ? ' jadwal-peta-row--group-start' : '' ?>">
                        <td class="jadwal-peta-td">
                            <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($hariSlug) ?>"><?= htmlspecialchars($hariLabel) ?></span>
                        </td>
                        <td class="jadwal-peta-td">
                            <span class="jadwal-peta-waktu font-monospace js-time-24">
                                <i class="fa-regular fa-clock jadwal-peta-waktu__ico" aria-hidden="true"></i>
                                <?= htmlspecialchars(jadwal_jam_ringkas($row)) ?>
                            </span>
                        </td>
                        <td class="jadwal-peta-td">
                            <span class="jadwal-peta-kegiatan">
                                <span class="jadwal-kat-dot <?= htmlspecialchars(jadwal_kategori_dot_class($kat)) ?>"></span>
                                <?= htmlspecialchars($namaKg) ?>
                            </span>
                        </td>
                        <td class="jadwal-peta-td">
                            <?php if ($tkList !== []): ?>
                                <?php foreach ($tkList as $tkBadge): ?>
                                    <span class="jadwal-peta-tingkatan me-1"><?= htmlspecialchars((string) $tkBadge) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="jadwal-peta-td jadwal-peta-meta"><?= ($pem !== '' && $pem !== '-') ? htmlspecialchars($pem) : '—' ?></td>
                        <td class="jadwal-peta-td text-end text-nowrap">
                            <?php if ($editId > 0 && ($showJadwalAksi || $jadwalPembimbingScope)): ?>
                                <?php if ($showJadwalAksi): ?>
                                <button type="button"
                                    class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                                    title="Edit cepat"
                                    data-edit-id="<?= $editId ?>"
                                    data-kegiatan-id="<?= (int) ($row['kegiatan_id'] ?? 0) ?>"
                                    data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($row['jam_mulai'] ?? ''))) ?>"
                                    data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($row['jam_selesai'] ?? ''))) ?>"
                                    data-pembimbing-id="<?= (int) ($row['pembimbing_id'] ?? 0) ?>"
                                    data-tempat="<?= htmlspecialchars(trim((string) ($row['tempat'] ?? ''))) ?>"
                                    data-tingkatan="<?= htmlspecialchars(json_encode($tkList, JSON_UNESCAPED_UNICODE)) ?>"
                                    data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $hariList)), JSON_UNESCAPED_UNICODE)) ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <?php endif; ?>
                                <button type="button"
                                    class="btn btn-outline-danger btn-sm py-0 px-2 jadwal-delete-one"
                                    title="Hapus"
                                    data-delete-ids="<?= htmlspecialchars(implode(',', $mergeIds)) ?>"
                                    data-confirm="Hapus slot jadwal ini? Presensi terkait ikut dihapus.">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="jadwal-peta-foot small text-muted mb-0 mt-2">
            <?= count($petaRows) ?> baris · waktu sama digabung (hari & tingkatan).
        </p>
    </div>
<?php endif; ?>
