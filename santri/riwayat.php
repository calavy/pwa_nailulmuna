<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);
$tab = trim((string) ($_GET['tab'] ?? 'buku'));
if (!in_array($tab, ['buku', 'tingkatan', 'hidmah', 'asrama', 'domisili', 'pelanggaran'], true)) {
    $tab = 'buku';
}
if ($tab === 'pelanggaran' && !user_can_view_pelanggaran_riwayat($id)) {
    set_flash('error', 'Anda tidak berhak melihat riwayat pelanggaran santri ini.');
    $tab = 'buku';
}
$thPel = (int) ($_GET['th_pel'] ?? 0);
$filterTa = (int) ($_GET['th'] ?? 0);

$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id');
$st->execute(['id' => $id]);
$santri = $st->fetch(PDO::FETCH_ASSOC);

if (!$santri) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/santri/semua_jati.php'));
    exit;
}

ensure_santri_riwayat_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'backfill') {
        santri_riwayat_backfill_from_santri($pdo, $id);
        set_flash('success', 'Riwayat tingkatan diselaraskan dari data santri saat ini.');
    } elseif ($action === 'save_tingkatan') {
        $tm = (int) ($_POST['tahun_ajaran_mulai'] ?? 0);
        $ting = trim((string) ($_POST['tingkatan'] ?? ''));
        if ($tm >= 2000 && $ting !== '') {
            $ta = ['mulai' => $tm, 'selesai' => $tm + 1];
            $stAk = strtoupper(trim((string) ($_POST['status_akademik'] ?? 'BERJALAN')));
            if (!in_array($stAk, santri_riwayat_status_akademik_options(), true)) {
                $stAk = 'BERJALAN';
            }
            $pdo->prepare('
                INSERT INTO santri_riwayat_tingkatan (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, tingkatan, kategori_kelas, wali_kelas, status_akademik, catatan)
                VALUES (:sid, :tm, :ts, :ting, :kat, :wk, :stak, :cat)
                ON DUPLICATE KEY UPDATE tingkatan = VALUES(tingkatan), kategori_kelas = VALUES(kategori_kelas),
                    wali_kelas = VALUES(wali_kelas), status_akademik = VALUES(status_akademik), catatan = VALUES(catatan)
            ')->execute([
                'sid' => $id,
                'tm' => $ta['mulai'],
                'ts' => $ta['selesai'],
                'ting' => mb_substr($ting, 0, 80),
                'kat' => trim((string) ($_POST['kategori_kelas'] ?? '')) ?: null,
                'wk' => trim((string) ($_POST['wali_kelas'] ?? '')) ?: null,
                'stak' => $stAk,
                'cat' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
            ]);
            set_flash('success', 'Tingkatan tahun ajaran ' . santri_tahun_ajaran_label($ta, $pdo) . ' disimpan.');
        } else {
            set_flash('error', 'Tahun ajaran dan tingkatan wajib diisi.');
        }
        $tab = 'tingkatan';
    } elseif ($action === 'save_hidmah') {
        $editHid = (int) ($_POST['hidmah_id'] ?? 0);
        if (santri_riwayat_hidmah_save($pdo, $_POST, $id, $editHid > 0 ? $editHid : null)) {
            set_flash('success', $editHid > 0 ? 'Data hidmah diperbarui.' : 'Data hidmah ditambahkan.');
        } else {
            set_flash('error', 'Nama hidmah wajib diisi.');
        }
        $tab = 'hidmah';
    } elseif ($action === 'delete_hidmah') {
        $hidId = (int) ($_POST['hidmah_id'] ?? 0);
        if ($hidId > 0) {
            $pdo->prepare('DELETE FROM santri_riwayat_hidmah WHERE id = :id AND santri_id = :sid')->execute(['id' => $hidId, 'sid' => $id]);
            set_flash('success', 'Data hidmah dihapus.');
        }
        $tab = 'hidmah';
    } elseif ($action === 'save_asrama') {
        $editAsr = (int) ($_POST['asrama_id'] ?? 0);
        $_POST['gedung'] = $_POST['gedung'] ?? santri_riwayat_gedung_label($santri);
        if (santri_riwayat_asrama_save($pdo, $_POST, $id, $editAsr > 0 ? $editAsr : null)) {
            set_flash('success', $editAsr > 0 ? 'Penempatan asrama diperbarui.' : 'Penempatan asrama ditambahkan.');
        } else {
            set_flash('error', 'Nama kamar wajib diisi.');
        }
        $tab = 'asrama';
    } elseif ($action === 'delete_asrama') {
        $asrId = (int) ($_POST['asrama_id'] ?? 0);
        if ($asrId > 0) {
            $pdo->prepare('DELETE FROM santri_riwayat_asrama WHERE id = :id AND santri_id = :sid')->execute(['id' => $asrId, 'sid' => $id]);
            set_flash('success', 'Riwayat asrama dihapus.');
        }
        $tab = 'asrama';
    } elseif ($action === 'save_domisili') {
        $editDom = (int) ($_POST['domisili_id'] ?? 0);
        $_POST['gedung'] = $_POST['gedung'] ?? santri_riwayat_gedung_label($santri);
        if (santri_riwayat_domisili_save($pdo, $_POST, $id, $editDom > 0 ? $editDom : null)) {
            set_flash('success', $editDom > 0 ? 'Domisili diperbarui.' : 'Domisili ditambahkan.');
        } else {
            set_flash('error', 'Nama kamar / unit wajib diisi.');
        }
        $tab = 'domisili';
    } elseif ($action === 'delete_domisili') {
        $domId = (int) ($_POST['domisili_id'] ?? 0);
        if ($domId > 0 && table_exists($pdo, 'santri_riwayat_domisili')) {
            $pdo->prepare('DELETE FROM santri_riwayat_domisili WHERE id = :id AND santri_id = :sid')->execute(['id' => $domId, 'sid' => $id]);
            set_flash('success', 'Riwayat domisili dihapus.');
        }
        $tab = 'domisili';
    }
    $redir = '/santri/riwayat.php?id=' . $id . '&tab=' . urlencode($tab);
    if ($thPel > 0) {
        $redir .= '&th_pel=' . $thPel;
    }
    if ($filterTa > 0) {
        $redir .= '&th=' . $filterTa;
    }
    header('Location: ' . app_href($redir));
    exit;
}

// Backfill ringan saat pertama buka (jika belum ada baris tingkatan)
$tingkatanRows = santri_riwayat_tingkatan_list($pdo, $id);
if ($tingkatanRows === []) {
    santri_riwayat_backfill_from_santri($pdo, $id);
    $tingkatanRows = santri_riwayat_tingkatan_list($pdo, $id);
}

$ringkasan = santri_riwayat_ringkasan($pdo, $santri);
$hidmahRows = santri_riwayat_hidmah_list($pdo, $id);
santri_riwayat_backfill_asrama_from_santri($pdo, $id, $santri);
santri_riwayat_domisili_ensure_for_santri($pdo, $id, $santri);
$asramaRows = santri_riwayat_asrama_list($pdo, $id);
$pelanggaranRows = santri_riwayat_pelanggaran_list($pdo, $id, $thPel > 0 ? $thPel : null);
$pelanggaranPerTahun = santri_riwayat_pelanggaran_per_tahun($pdo, $id);
$keaktifanPerTahun = santri_riwayat_keaktifan_per_tahun($pdo, $id);
$keaktifanByTh = [];
foreach ($keaktifanPerTahun as $ka) {
    $keaktifanByTh[(int) $ka['th']] = $ka;
}
$keaktifanTahunPilih = $thPel > 0 ? ($keaktifanByTh[$thPel] ?? null) : null;

$editHidmah = null;
$editHidId = (int) ($_GET['edit_hidmah'] ?? 0);
if ($editHidId > 0) {
    foreach ($hidmahRows as $hr) {
        if ((int) $hr['id'] === $editHidId) {
            $editHidmah = $hr;
            break;
        }
    }
}
$editAsrama = null;
$editAsrId = (int) ($_GET['edit_asrama'] ?? 0);
if ($editAsrId > 0) {
    foreach ($asramaRows as $ar) {
        if ((int) $ar['id'] === $editAsrId) {
            $editAsrama = $ar;
            break;
        }
    }
}
$gedungDefault = santri_riwayat_gedung_label($santri);

$taAktif = santri_tahun_ajaran_for_date($pdo);
$tglMasuk = trim((string) ($santri['tanggal_masuk'] ?? ''));
$taMasuk = $tglMasuk !== '' ? santri_tahun_ajaran_for_date($pdo, $tglMasuk) : null;

$pageTitle = 'Riwayat santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div class="flex-grow-1">
        <p class="page-intro-kicker mb-1">Manajemen SDM · Buku induk digital</p>
        <h1 class="h3 mb-1">Riwayat santri</h1>
        <p class="mb-0">
            <span class="fw-semibold"><?= htmlspecialchars((string) $santri['nama_santri']) ?></span>
            <span class="text-muted font-monospace">· NIS <?= htmlspecialchars((string) ($santri['nis'] ?? '')) ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/edit.php?id=' . (int) $id)) ?>" class="btn btn-outline-warning btn-sm">Edit biodata</a>
        <a href="/santri/semua_jati.php" class="btn btn-outline-secondary btn-sm">Data induk</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <?php
    $tabs = [
        'buku' => 'Buku induk',
        'tingkatan' => 'Kelola akademik',
        'hidmah' => 'Kelola khidmah',
        'asrama' => 'Kelola asrama',
        'domisili' => 'Domisili',
    ];
    if (user_can_view_pelanggaran_riwayat($id)) {
        $tabs['pelanggaran'] = 'Pelanggaran';
    }
    foreach ($tabs as $k => $label):
        $active = $tab === $k ? ' active' : '';
        $href = '/santri/riwayat.php?id=' . $id . '&tab=' . $k;
        if ($filterTa > 0 && $k === 'buku') {
            $href .= '&th=' . $filterTa;
        }
    ?>
    <li class="nav-item">
        <a class="nav-link<?= $active ?>" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'buku'): ?>
<div class="row g-2 mb-3 santri-data-actions">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body py-2 small">
                <div class="text-muted">Status</div>
                <?php $statusRiwayat = santri_status_from_row($santri); ?>
                <span class="badge <?= santri_status_badge_class($statusRiwayat) ?>"><?= htmlspecialchars(santri_status_label($statusRiwayat)) ?></span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body py-2 small">
                <div class="text-muted">Tingkatan saat ini</div>
                <strong><?= htmlspecialchars((string) ($ringkasan['tingkatan_saat_ini'] ?: '—')) ?></strong>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body py-2 small">
                <div class="text-muted">Tahun ajaran berjalan</div>
                <strong><?= htmlspecialchars(santri_tahun_ajaran_label($taAktif, $pdo)) ?></strong>
            </div>
        </div>
    </div>
</div>
<?php
$filterFormAction = '/santri/riwayat.php';
$tabHidden = 'buku';
$readOnly = false;
$showKeaktifanNilai = user_can_view_keaktifan_nilai();
$showPelanggaran = user_can_view_pelanggaran_riwayat($id);
$pelanggaranRows = $showPelanggaran ? santri_riwayat_pelanggaran_list_buku($pdo, $id, $filterTa) : [];
require __DIR__ . '/../includes/partials/santri_buku_induk.php';
?>

<?php elseif ($tab === 'tingkatan'): ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <strong>Tingkatan per tahun ajaran</strong>
                <form method="post" class="d-inline" onsubmit="return confirm('Selaraskan dari tingkatan & tanggal masuk saat ini?');">
                    <input type="hidden" name="action" value="backfill">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Sinkron otomatis</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th class="ps-3">Tahun ajaran</th><th>Tingkatan</th><th>Kelas</th><th>Wali kelas</th><th>Status</th><th>Catatan</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tingkatanRows as $tr): ?>
                            <tr>
                                <td class="ps-3 fw-semibold"><?= htmlspecialchars(santri_tahun_ajaran_label(['mulai' => (int) $tr['tahun_ajaran_mulai'], 'selesai' => (int) $tr['tahun_ajaran_selesai']], $pdo)) ?></td>
                                <td><?= htmlspecialchars((string) $tr['tingkatan']) ?></td>
                                <td><?= htmlspecialchars(santri_riwayat_kelas_tampilan($pdo, (string) ($tr['kategori_kelas'] ?? ''))) ?></td>
                                <td class="small"><?= htmlspecialchars(trim((string) ($tr['wali_kelas'] ?? '')) !== '' ? (string) $tr['wali_kelas'] : '—') ?></td>
                                <td><?= htmlspecialchars(santri_riwayat_status_akademik_label((string) ($tr['status_akademik'] ?? 'BERJALAN'))) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars((string) ($tr['catatan'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($tingkatanRows === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Belum ada data. Tambah manual atau klik Sinkron otomatis.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-2">Setiap kali tingkatan diubah lewat <strong>Edit santri</strong>, tahun ajaran berjalan otomatis tercatat. Untuk tahun-tahun lalu, isi manual di form kanan.</p>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Tambah / ubah per tahun ajaran</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_tingkatan">
                    <?php $taMetaTingkat = pondok_ta_form_meta($pdo); ?>
                    <div class="col-12 pondok-ta-field" data-ta-hijri="<?= pondok_kalender_hijriyah($pdo) ? '1' : '0' ?>">
                        <label class="form-label"><?= htmlspecialchars($taMetaTingkat['label_mulai']) ?></label>
                        <input type="number" name="tahun_ajaran_mulai" class="form-control form-control-sm pondok-ta-mulai"
                               min="<?= (int) $taMetaTingkat['min'] ?>" max="<?= (int) $taMetaTingkat['max'] ?>"
                               value="<?= (int) $taAktif['mulai'] ?>" required>
                        <div class="form-text"><?= pondok_kalender_hijriyah($pdo) ? 'Contoh: 1447 untuk TA 1447/1448 H' : 'Contoh: 2025 untuk TA 2025/2026' ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tingkatan</label>
                        <input type="text" name="tingkatan" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($santri['tingkatan'] ?? '')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Kategori kelas (opsional)</label>
                        <input type="text" name="kategori_kelas" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($santri['kategori_kelas'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Wali kelas (opsional)</label>
                        <input type="text" name="wali_kelas" class="form-control form-control-sm" placeholder="Mis. Ust. Ahmad">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Status akademik</label>
                        <select name="status_akademik" class="form-select form-select-sm">
                            <?php foreach (santri_riwayat_status_akademik_options() as $stOpt): ?>
                                <option value="<?= htmlspecialchars($stOpt) ?>"><?= htmlspecialchars(santri_riwayat_status_akademik_label($stOpt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan (opsional)</label>
                        <input type="text" name="catatan" class="form-control form-control-sm" placeholder="Mis. naik tingkat">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'hidmah'): ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header py-2"><strong>Riwayat hidmah &amp; peran</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">TA</th>
                                <th>Jenis</th>
                                <th>Nama hidmah</th>
                                <th>Periode</th>
                                <th class="text-end pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($hidmahRows as $hr): ?>
                            <?php
                            $taLabel = santri_tahun_ajaran_label([
                                'mulai' => (int) $hr['tahun_ajaran_mulai'],
                                'selesai' => (int) ($hr['tahun_ajaran_selesai'] ?? 0) ?: (int) $hr['tahun_ajaran_mulai'] + 1,
                            ], $pdo);
                            $periode = '';
                            if (!empty($hr['tanggal_mulai'])) {
                                $periode = (string) $hr['tanggal_mulai'];
                                if (!empty($hr['tanggal_selesai'])) {
                                    $periode .= ' — ' . $hr['tanggal_selesai'];
                                }
                            } else {
                                $periode = '—';
                            }
                            ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars($taLabel) ?></td>
                                <td><span class="badge text-bg-info"><?= htmlspecialchars(santri_hidmah_jenis_label((string) $hr['jenis_peran'])) ?></span></td>
                                <td><?= htmlspecialchars((string) $hr['nama_hidmah']) ?></td>
                                <td class="small"><?= htmlspecialchars($periode) ?></td>
                                <td class="text-end pe-3 text-nowrap">
                                    <a href="/santri/riwayat.php?id=<?= $id ?>&tab=hidmah&edit_hidmah=<?= (int) $hr['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus data hidmah ini?');">
                                        <input type="hidden" name="action" value="delete_hidmah">
                                        <input type="hidden" name="hidmah_id" value="<?= (int) $hr['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                            <?php if (trim((string) ($hr['keterangan'] ?? '')) !== ''): ?>
                            <tr class="table-light"><td colspan="5" class="ps-3 small text-muted pb-2"><?= htmlspecialchars((string) $hr['keterangan']) ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($hidmahRows === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data hidmah. Gunakan form di samping.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <strong><?= $editHidmah ? 'Edit hidmah' : 'Tambah hidmah' ?></strong>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_hidmah">
                    <?php if ($editHidmah): ?>
                        <input type="hidden" name="hidmah_id" value="<?= (int) $editHidmah['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label">Jenis peran</label>
                        <select name="jenis_peran" class="form-select form-select-sm" required>
                            <?php foreach (santri_hidmah_jenis_options() as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"<?= ($editHidmah && strtoupper((string) $editHidmah['jenis_peran']) === $opt) || (!$editHidmah && $opt === 'HIDMAH') ? ' selected' : '' ?>>
                                    <?= htmlspecialchars(santri_hidmah_jenis_label($opt)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama hidmah / jabatan</label>
                        <input type="text" name="nama_hidmah" class="form-control form-control-sm" required
                               placeholder="Mis. Ketua bidang tahfidz, Toko pondok"
                               value="<?= htmlspecialchars((string) ($editHidmah['nama_hidmah'] ?? '')) ?>">
                    </div>
                    <?php
                    $taMulaiHidmah = (int) ($editHidmah['tahun_ajaran_mulai'] ?? $taAktif['mulai']);
                    $taSelesaiHidmah = !empty($editHidmah['tahun_ajaran_selesai'])
                        ? (int) $editHidmah['tahun_ajaran_selesai']
                        : $taMulaiHidmah + 1;
                    $taColClass = 'col-6';
                    require __DIR__ . '/../includes/partials/pondok_ta_fields.php';
                    ?>
                    <div class="col-6">
                        <label class="form-label">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string) ($editHidmah['tanggal_mulai'] ?? '')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control form-control-sm"
                               value="<?= htmlspecialchars((string) ($editHidmah['tanggal_selesai'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Keterangan</label>
                        <textarea name="keterangan" class="form-control form-control-sm" rows="2"><?= htmlspecialchars((string) ($editHidmah['keterangan'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Simpan</button>
                        <?php if ($editHidmah): ?>
                            <a href="/santri/riwayat.php?id=<?= $id ?>&tab=hidmah" class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <p class="small text-muted mt-2">
            <strong>Hidmah</strong> = tugas khidmah umum.
            <strong>Pengurus santri</strong> = struktur kepengurusan.
            <strong>Pembantu usaha pondok</strong> = usaha/koperasi pondok.
        </p>
    </div>
</div>

<?php require __DIR__ . '/_riwayat_asrama_tab.php'; ?>

<?php require __DIR__ . '/_riwayat_domisili_tab.php'; ?>

<?php elseif ($tab === 'pelanggaran'): ?>
<?php
$tahunFilterList = [];
foreach ($keaktifanPerTahun as $ka) {
    $tahunFilterList[(int) $ka['th']] = true;
}
foreach ($pelanggaranPerTahun as $pt) {
    $tahunFilterList[(int) $pt['th']] = true;
}
krsort($tahunFilterList);
?>
<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Keaktifan per tahun</strong> <span class="text-muted small fw-normal">(dari presensi)</span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Tahun</th>
                        <th>Nilai</th>
                        <th>Keterangan</th>
                        <th class="text-end pe-3">Filter</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_keys($tahunFilterList) as $thRow): ?>
                    <?php $kaRow = $keaktifanByTh[(int) $thRow] ?? null; ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= (int) $thRow ?></td>
                        <td>
                            <?php if ($kaRow): ?>
                                <span class="badge <?= santri_riwayat_keaktifan_badge_class((string) $kaRow['label']) ?>"><?= htmlspecialchars((string) $kaRow['label']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $kaRow ? htmlspecialchars((string) $kaRow['keterangan']) : 'Tidak ada data presensi' ?></td>
                        <td class="text-end pe-3">
                            <a href="/santri/riwayat.php?id=<?= $id ?>&tab=pelanggaran&th_pel=<?= (int) $thRow ?>"
                               class="btn btn-outline-primary btn-sm<?= $thPel === (int) $thRow ? ' active' : '' ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tahunFilterList === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data presensi tercatat.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($thPel > 0 && $keaktifanTahunPilih): ?>
<div class="alert alert-light border py-2 mb-3 small">
    <strong>Keaktifan <?= $thPel ?>:</strong>
    <span class="badge <?= santri_riwayat_keaktifan_badge_class((string) $keaktifanTahunPilih['label']) ?>"><?= htmlspecialchars((string) $keaktifanTahunPilih['label']) ?></span>
    <?= htmlspecialchars((string) $keaktifanTahunPilih['keterangan']) ?>
</div>
<?php endif; ?>

<?php if ($pelanggaranPerTahun !== []): ?>
<div class="row g-2 mb-3">
    <?php foreach ($pelanggaranPerTahun as $pt): ?>
    <div class="col-6 col-md-3">
        <a href="/santri/riwayat.php?id=<?= $id ?>&tab=pelanggaran&th_pel=<?= (int) $pt['th'] ?>"
           class="card shadow-sm text-decoration-none <?= $thPel === (int) $pt['th'] ? 'border-primary' : '' ?>">
            <div class="card-body py-2 text-center">
                <div class="h6 mb-0">Pelanggaran <?= (int) $pt['th'] ?></div>
                <div class="small text-muted"><?= (int) $pt['jumlah'] ?> kejadian · <?= (int) $pt['total_poin'] ?> poin</div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
    <?php if ($thPel > 0): ?>
    <div class="col-auto align-self-center">
        <a href="/santri/riwayat.php?id=<?= $id ?>&tab=pelanggaran" class="btn btn-outline-secondary btn-sm">Semua tahun</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between">
        <strong>Riwayat pelanggaran kedisiplinan</strong>
        <a href="/poin/input.php" class="btn btn-outline-primary btn-sm">Input poin</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Tanggal</th>
                        <th>Nama pelanggaran</th>
                        <th>Aturan</th>
                        <th>Kategori</th>
                        <th class="text-end">Poin</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pelanggaranRows as $pl): ?>
                    <?php
                    $namaPel = santri_riwayat_pelanggaran_nama($pl);
                    $ketAsli = trim((string) ($pl['keterangan'] ?? ''));
                    ?>
                    <tr>
                        <td class="ps-3 text-nowrap"><?= htmlspecialchars((string) $pl['tanggal']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($namaPel) ?></td>
                        <td class="small">
                            <?php if (!empty($pl['nama_rule'])): ?>
                                <?= htmlspecialchars((string) $pl['nama_rule']) ?>
                                <?php if (!empty($pl['kode_rule'])): ?>
                                    <span class="text-muted font-monospace">(<?= htmlspecialchars((string) $pl['kode_rule']) ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars((string) ($pl['sumber_data'] ?? 'MANUAL')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars((string) ($pl['kategori'] ?? '—')) ?></td>
                        <td class="text-end fw-semibold text-danger">+<?= (int) $pl['point_delta'] ?></td>
                    </tr>
                    <?php if ($ketAsli !== '' && $ketAsli !== $namaPel && stripos($ketAsli, (string) ($pl['nama_rule'] ?? '')) === false): ?>
                    <tr class="table-light">
                        <td></td>
                        <td colspan="4" class="small text-muted pb-2">Catatan: <?= htmlspecialchars($ketAsli) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($pelanggaranRows === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada pelanggaran kedisiplinan<?= $thPel > 0 ? ' pada tahun ' . $thPel : '' ?> (poin presensi tidak ditampilkan).</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="small text-muted mt-2">
    <strong>Keaktifan</strong> dihitung dari presensi (hadir/izin/sakit/ALPA) per tahun — nilai Baik, Sedang, atau Buruk.
    <strong>Pelanggaran</strong> hanya dari input poin manual/rule; poin otomatis presensi (ALPA/telat) tidak ditampilkan di sini.
</p>
<?php endif; ?>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
