<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';
require_once __DIR__ . '/../helpers/munawib.php';

require_roles(['admin', 'pengurus', 'petugas_absensi', 'pembimbing']);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
if ($pembimbingId <= 0 && !$bolehSemua && $role === 'pembimbing' && table_exists($pdo, 'pembimbing')) {
    $nipLogin = trim((string) ($_SESSION['user']['username'] ?? ''));
    if ($nipLogin !== '') {
        $stPbId = $pdo->prepare('SELECT id, nama_pembimbing, nip FROM pembimbing WHERE TRIM(nip) = :nip LIMIT 1');
        $stPbId->execute(['nip' => $nipLogin]);
        $pbRowLogin = $stPbId->fetch(PDO::FETCH_ASSOC);
        if (is_array($pbRowLogin)) {
            $pembimbingId = (int) ($pbRowLogin['id'] ?? 0);
            $pembimbingInfo = [
                'id' => $pembimbingId,
                'nama' => (string) ($pbRowLogin['nama_pembimbing'] ?? ''),
                'nip' => (string) ($pbRowLogin['nip'] ?? $nipLogin),
            ];
        }
    }
}
$isSelfService = ($role === 'pembimbing' || (int) ($_SESSION['munawib_id'] ?? 0) > 0) && !$bolehSemua;
$today = date('Y-m-d');
$act = strtolower(trim((string) ($_GET['act'] ?? '')));

pb_jadwal_override_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSelfService) {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($pembimbingId <= 0) {
        set_flash('error', 'Akun login belum terhubung ke data pembimbing.');
        header('Location: ' . app_href('/pembimbing/perizinan.php'));
        exit;
    }

    if ($postAction === 'batal_override') {
        $res = pb_jadwal_hapus_override($pdo, $pembimbingId, (int) ($_POST['override_id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php'));
        exit;
    }

    $jadwalId = (int) ($_POST['jadwal_id'] ?? 0);
    $slotRes = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $today);
    if (!$slotRes['ok']) {
        $actBack = match ($postAction) {
            'simpan_pindah' => 'pindah',
            'simpan_munawib' => 'munawib',
            default => '',
        };
        set_flash('error', $slotRes['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php' . ($actBack !== '' ? '?act=' . rawurlencode($actBack) : '')));
        exit;
    }
    /** @var array<string,mixed> $slot */
    $slot = $slotRes['slot'];
    $alasan = trim((string) ($_POST['alasan'] ?? ''));

    if ($postAction === 'simpan_pindah') {
        $res = pb_jadwal_simpan_pindah_waktu($pdo, $pembimbingId, $slot, $today, (string) ($_POST['jam_mulai_baru'] ?? ''), $alasan, $userId);
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?act=pindah'));
        exit;
    }
    if ($postAction === 'simpan_munawib') {
        $materiParsed = pb_jadwal_parse_materi_halaman(
            is_array($_POST['materi_hal'] ?? null) ? $_POST['materi_hal'] : [],
            is_array($_POST['materi_isi'] ?? null) ? $_POST['materi_isi'] : []
        );
        if (!$materiParsed['ok']) {
            set_flash('error', $materiParsed['pesan']);
            header('Location: ' . app_href('/pembimbing/perizinan.php?act=munawib'));
            exit;
        }
        $res = pb_jadwal_simpan_cari_munawib(
            $pdo,
            $pembimbingId,
            $slot,
            $today,
            (int) ($_POST['munawib_id'] ?? 0),
            $alasan,
            $userId,
            $materiParsed['rows'] ?? []
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['pesan']);
        header('Location: ' . app_href('/pembimbing/perizinan.php?act=munawib'));
        exit;
    }
}

$slotsHariIni = $isSelfService && $pembimbingId > 0 ? pb_jadwal_slots_hari_ini($pdo, $pembimbingId, $today) : [];
$munawibList = $isSelfService ? munawib_list_aktif($pdo) : [];
$riwayatOverride = $isSelfService && $pembimbingId > 0 ? pb_jadwal_riwayat_override($pdo, $pembimbingId) : [];

$izinSql = '
    SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.status_izin, b.nama_pembimbing, b.nip, k.nama_kegiatan
    FROM perizinan_pembimbing i
    INNER JOIN pembimbing b ON b.id = i.pembimbing_id
    LEFT JOIN kegiatan k ON k.id = i.kegiatan_id
';
if ($isSelfService) {
    $izinSql .= ' WHERE i.pembimbing_id = :pid';
}
$izinSql .= ' ORDER BY i.id DESC LIMIT 50';
$stIzin = $pdo->prepare($izinSql);
if ($isSelfService) {
    $stIzin->execute(['pid' => $pembimbingId]);
} else {
    $stIzin->execute();
}
$izinList = $stIzin->fetchAll();

$pageTitle = 'Perizinan Pembimbing';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Perizinan</p>
    <h1 class="h4 mb-1">Pengaturan kegiatan hari ini</h1>
    <p class="text-muted mb-0">
        <?php if ($isSelfService): ?>
            Ubah waktu atau cari munawib (dengan tugas per halaman). Bukan pengajuan izin keluar. Perubahan dapat diubah hingga <?= PB_JADWAL_BATAS_JAM_SEBELUM ?> jam sebelum jadwal asli.
        <?php else: ?>
            Pantau perubahan jadwal dan izin pembimbing.
        <?php endif; ?>
    </p>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($isSelfService): ?>

<?php if ($act === ''): ?>
<div class="row g-3 mb-4 justify-content-center pb-perizinan-choices">
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php?act=munawib')) ?>" class="card shadow-sm h-100 text-decoration-none pb-perizinan-choice">
            <div class="card-body text-center py-4">
                <div class="display-6 text-primary mb-2"><i class="fa-solid fa-user-clock"></i></div>
                <h2 class="h6 mb-1 text-dark">Cari munawib</h2>
                <p class="small text-muted mb-0">Pilih pengganti + isi tugas per halaman</p>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php?act=pindah')) ?>" class="card shadow-sm h-100 text-decoration-none pb-perizinan-choice">
            <div class="card-body text-center py-4">
                <div class="display-6 text-success mb-2"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <h2 class="h6 mb-1 text-dark">Pindah waktu</h2>
                <p class="small text-muted mb-0">Ta'lim/ta'alum · max <?= PB_JADWAL_MAX_PINDAH_BULAN ?>x/bulan per kegiatan</p>
            </div>
        </a>
    </div>
</div>
<?php else: ?>
<p class="mb-3"><a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke pilihan</a></p>

<?php if ($slotsHariIni === []): ?>
    <div class="alert alert-warning small">
        Tidak ada kegiatan jadwal Anda hari ini (<?= htmlspecialchars(date('d M Y')) ?>).
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Kelola jadwal</a>
    </div>
<?php elseif ($act === 'pindah'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2">Pindah waktu kegiatan hari ini</h2>
            <p class="small text-muted">Pilih jam mulai baru — jam selesai mengikuti durasi jadwal asli. Hanya kegiatan ta'lim & ta'alum.</p>
            <form method="post" id="form-pindah-waktu" class="row g-3">
                <input type="hidden" name="action" value="simpan_pindah">
                <div class="col-md-6">
                    <label class="form-label">Kegiatan hari ini</label>
                    <select class="form-select" name="jadwal_id" id="pb-slot-pindah" required>
                        <option value="">— Pilih —</option>
                        <?php foreach ($slotsHariIni as $sl):
                            $taalim = strtoupper((string) ($sl['kategori_kegiatan'] ?? '')) === 'TAALIM';
                        ?>
                            <option value="<?= (int) $sl['jadwal_id'] ?>"
                                data-mulai="<?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>"
                                data-selesai="<?= htmlspecialchars(substr((string) $sl['jam_selesai'], 0, 5)) ?>"
                                data-durasi="<?= (int) $sl['durasi_menit'] ?>"
                                data-bisa="<?= !empty($sl['batas_ubah']['ok']) && $taalim && (int) $sl['sisa_pindah_bulan'] > 0 ? '1' : '0' ?>"
                                data-sisa="<?= (int) $sl['sisa_pindah_bulan'] ?>"
                                <?= !$taalim ? 'disabled' : '' ?>>
                                <?= htmlspecialchars((string) $sl['nama_kegiatan']) ?> · <?= htmlspecialchars((string) $sl['tingkatan']) ?>
                                · <?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $sl['jam_selesai'], 0, 5)) ?>
                                <?= !$taalim ? '(bukan ta\'lim)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jam mulai baru</label>
                    <input type="time" class="form-control" name="jam_mulai_baru" id="pb-jam-baru" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jam selesai (otomatis)</label>
                    <input type="text" class="form-control" id="pb-jam-selesai-preview" readonly value="—">
                </div>
                <div class="col-12">
                    <label class="form-label">Alasan / sebab</label>
                    <textarea class="form-control" name="alasan" rows="2" required placeholder="Jelaskan alasan pergeseran"></textarea>
                </div>
                <div class="col-12">
                    <p class="small text-muted mb-2" id="pb-pindah-info"></p>
                    <button type="submit" class="btn btn-success" id="pb-btn-pindah"><i class="fa-solid fa-check me-1"></i> Simpan pergeseran</button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($act === 'munawib'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2">Cari munawib pengganti</h2>
            <p class="small text-muted">Isi tugas/materi per halaman agar munawib tahu apa yang diajarkan.</p>
            <form method="post" class="row g-3" id="form-munawib">
                <input type="hidden" name="action" value="simpan_munawib">
                <div class="col-md-6">
                    <label class="form-label">Kegiatan hari ini</label>
                    <select class="form-select" name="jadwal_id" required>
                        <option value="">— Pilih —</option>
                        <?php foreach ($slotsHariIni as $sl): ?>
                            <?php if (empty($sl['batas_ubah']['ok'])) { continue; } ?>
                            <option value="<?= (int) $sl['jadwal_id'] ?>">
                                <?= htmlspecialchars((string) $sl['nama_kegiatan']) ?> · <?= htmlspecialchars(substr((string) $sl['jam_mulai'], 0, 5)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Munawib</label>
                    <select class="form-select" name="munawib_id" required>
                        <option value="">— Pilih munawib —</option>
                        <?php foreach ($munawibList as $mw): ?>
                            <option value="<?= (int) $mw['id'] ?>"><?= htmlspecialchars((string) $mw['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label mb-0">Tugas / materi per halaman</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="pb-tambah-hal"><i class="fa-solid fa-plus me-1"></i> Tambah halaman</button>
                    </div>
                    <div id="pb-materi-rows" class="d-flex flex-column gap-2">
                        <div class="row g-2 pb-materi-row">
                            <div class="col-3 col-sm-2">
                                <input type="text" class="form-control form-control-sm" name="materi_hal[]" placeholder="Hal" value="1" required>
                            </div>
                            <div class="col">
                                <input type="text" class="form-control form-control-sm" name="materi_isi[]" placeholder="Isi tugas / materi halaman ini" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Alasan / sebab</label>
                    <textarea class="form-control" name="alasan" rows="2" required></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i> Catat munawib &amp; tugas</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var wrap = document.getElementById('pb-materi-rows');
        var btn = document.getElementById('pb-tambah-hal');
        if (!wrap || !btn) return;
        btn.addEventListener('click', function () {
            var n = wrap.querySelectorAll('.pb-materi-row').length + 1;
            var row = document.createElement('div');
            row.className = 'row g-2 pb-materi-row';
            row.innerHTML = '<div class="col-3 col-sm-2"><input type="text" class="form-control form-control-sm" name="materi_hal[]" placeholder="Hal" value="' + n + '" required></div>'
                + '<div class="col"><input type="text" class="form-control form-control-sm" name="materi_isi[]" placeholder="Isi tugas / materi halaman ini" required></div>';
            wrap.appendChild(row);
        });
    })();
    </script>
<?php endif; ?>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h6 mb-2">Riwayat perubahan hari ini & terakhir</h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Tanggal</th><th>Kegiatan</th><th>Jenis</th><th>Detail</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php if ($riwayatOverride === []): ?>
                    <tr><td colspan="5" class="text-muted text-center small py-3">Belum ada perubahan.</td></tr>
                <?php endif; ?>
                <?php foreach ($riwayatOverride as $rv):
                    $jenis = (string) ($rv['jenis'] ?? '');
                    $jenisLabel = match ($jenis) {
                        'PINDAH_WAKTU' => 'Pindah waktu',
                        'GANTI_MATERI' => 'Ganti materi',
                        'CARI_MUNAWIB' => 'Munawib',
                        default => $jenis,
                    };
                    $detail = '';
                    if ($jenis === 'PINDAH_WAKTU') {
                        $detail = substr((string) ($rv['jam_mulai_asli'] ?? ''), 0, 5) . '→' . substr((string) ($rv['jam_mulai_baru'] ?? ''), 0, 5);
                    } elseif ($jenis === 'CARI_MUNAWIB') {
                        $detail = (string) ($rv['munawib_nama'] ?? '');
                        $mat = pb_jadwal_materi_ringkas((string) ($rv['materi_pengganti'] ?? ''));
                        if ($mat !== '') {
                            $detail .= ' · ' . $mat;
                        }
                    } else {
                        $detail = pb_jadwal_materi_ringkas((string) ($rv['materi_pengganti'] ?? ''));
                    }
                    $bisaBatal = pb_jadwal_cek_batas_waktu((string) ($rv['tanggal'] ?? ''), (string) ($rv['jam_mulai_asli'] ?? ''))['ok'];
                ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars((string) ($rv['tanggal'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($rv['nama_kegiatan'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars($jenisLabel) ?></td>
                        <td class="small"><?= htmlspecialchars($detail) ?></td>
                        <td class="text-end">
                            <?php if ($bisaBatal): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Batalkan perubahan ini?')">
                                <input type="hidden" name="action" value="batal_override">
                                <input type="hidden" name="override_id" value="<?= (int) ($rv['id'] ?? 0) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Batal</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted small">Terkunci</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('pb-slot-pindah');
    var jamBaru = document.getElementById('pb-jam-baru');
    var preview = document.getElementById('pb-jam-selesai-preview');
    var info = document.getElementById('pb-pindah-info');
    var btn = document.getElementById('pb-btn-pindah');
    if (!sel || !jamBaru) return;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function selesaiFromMulai(mulai, durasi) {
        if (!mulai || !durasi) return '—';
        var p = mulai.split(':');
        var d = new Date(1970, 0, 1, parseInt(p[0], 10), parseInt(p[1], 10));
        d.setMinutes(d.getMinutes() + durasi);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function refresh() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            preview.value = '—';
            info.textContent = '';
            if (btn) btn.disabled = true;
            return;
        }
        var durasi = parseInt(opt.getAttribute('data-durasi') || '60', 10);
        preview.value = selesaiFromMulai(jamBaru.value || opt.getAttribute('data-mulai'), durasi);
        var bisa = opt.getAttribute('data-bisa') === '1';
        var sisa = opt.getAttribute('data-sisa') || '0';
        info.textContent = bisa
            ? 'Sisa kuota pindah bulan ini: ' + sisa + 'x · Asli ' + opt.getAttribute('data-mulai') + '–' + opt.getAttribute('data-selesai')
            : 'Kegiatan ini tidak bisa dipindah (bukan ta\'lim, kuota habis, atau sudah lewat batas waktu).';
        if (btn) btn.disabled = !bisa;
    }
    sel.addEventListener('change', refresh);
    jamBaru.addEventListener('input', refresh);
    refresh();
})();
</script>

<?php else: ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Daftar izin pembimbing (legacy)</h2>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover">
                <thead><tr><th>Pembimbing</th><th>Kegiatan</th><th>Jenis</th><th>Tanggal</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($izinList === []): ?><tr><td colspan="5" class="text-muted text-center small">Belum ada data.</td></tr><?php endif; ?>
                <?php foreach ($izinList as $i): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $i['nama_pembimbing']) ?></td>
                        <td><?= htmlspecialchars((string) ($i['nama_kegiatan'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) $i['jenis_izin']) ?></td>
                        <td><?= htmlspecialchars((string) $i['tanggal_mulai']) ?> s/d <?= htmlspecialchars((string) $i['tanggal_selesai']) ?></td>
                        <td><?= htmlspecialchars((string) $i['status_izin']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
