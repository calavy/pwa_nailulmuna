<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/perizinan_jenis.php';

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

perizinan_rombongan_ensure_schema($pdo);
perizinan_approval_ensure_schema($pdo);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$namaPengasuh = (string) app_setting($pdo, 'nama_pengasuh', '');
$hideCetakSurat = user_is_pengasuh_kiai();
$bolehBypassAlpa = perizinan_user_boleh_bypass_alpa($pdo);
$filterStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['PENDING', 'DISETUJUI', 'DITOLAK'], true)) {
    $filterStatus = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create_rombongan') {
        $santriIds = array_map('intval', (array) ($_POST['santri_ids_rombongan'] ?? []));
        $res = perizinan_rombongan_create($pdo, $_POST, $santriIds, $userId);
        if ($res['ok']) {
            if (empty($res['auto_approved'])) {
                $jenisRombongan = perizinan_jenis_izin_normalize((string) ($_POST['jenis_izin'] ?? 'KELUAR'));
                perizinan_push_setelah_pengajuan(
                    $pdo,
                    'Izin rombongan (' . count($santriIds) . ' santri)',
                    '',
                    $jenisRombongan,
                    trim((string) ($_POST['tanggal_mulai'] ?? date('Y-m-d'))),
                    trim((string) ($_POST['tanggal_selesai'] ?? date('Y-m-d'))),
                    [
                        'jam_mulai' => trim((string) ($_POST['jam_mulai'] ?? date('H:i'))),
                        'jam_selesai' => trim((string) ($_POST['jam_selesai'] ?? date('H:i'))),
                        'alasan' => trim((string) ($_POST['alasan'] ?? '')),
                        'tujuan' => trim((string) ($_POST['tujuan'] ?? '')),
                    ]
                );
            }
            set_flash('success', $res['message']);
            if (!empty($res['auto_approved']) && !empty($res['rombongan_id'])) {
                header('Location: ' . app_rewrite_internal_url('/perizinan/surat_rombongan.php?id=' . (int) $res['rombongan_id']));
                exit;
            }
            header('Location: ' . app_href('/perizinan/izin_rombongan.php'));
            exit;
        }
        set_flash('error', $res['message']);
        header('Location: ' . app_href('/perizinan/izin_rombongan.php'));
        exit;
    }
    if ($action === 'approve_rombongan') {
        $rid = (int) ($_POST['rombongan_id'] ?? 0);
        $metaRombongan = $rid > 0 ? perizinan_rombongan_meta($pdo, $rid) : null;
        if (is_array($metaRombongan) && perizinan_memerlukan_persetujuan_pengasuh((string) ($metaRombongan['jenis_izin'] ?? ''))) {
            set_flash('error', 'Izin syar\'i rombongan disetujui oleh pengasuh. Pengurus tidak perlu menyetujui lagi — gunakan tombol Cetak A4.');
            header('Location: ' . app_href('/perizinan/izin_rombongan.php'));
            exit;
        }
        $bypassRombongan = perizinan_request_bypass_alpa($pdo, $_POST);
        $res = perizinan_rombongan_approve($pdo, $rid, $_POST, $userId, $bypassRombongan);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        if ($res['ok'] && $rid > 0) {
            header('Location: ' . app_rewrite_internal_url('/perizinan/surat_rombongan.php?id=' . $rid));
            exit;
        }
        header('Location: ' . app_href('/perizinan/izin_rombongan.php'));
        exit;
    }
    if ($action === 'reject_rombongan') {
        $rid = (int) ($_POST['rombongan_id'] ?? 0);
        $res = perizinan_rombongan_tolak($pdo, $rid, $userId, 'pengurus');
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/perizinan/izin_rombongan.php'));
        exit;
    }
}

$rombonganSantriGrouped = perizinan_rombongan_santri_aktif_grouped($pdo);
$listRows = perizinan_rombongan_list($pdo, $filterStatus);
$totalPending = count(array_filter($listRows, static fn(array $r): bool => ($r['approval_status'] ?? '') === 'PENDING'));
$totalDisetujui = count(array_filter($listRows, static fn(array $r): bool => ($r['approval_status'] ?? '') === 'DISETUJUI'));

$pageTitle = 'Izin Rombongan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Perizinan</a> · Pengajuan
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-people-group text-primary me-1"></i> Izin Rombongan</h1>
    <p class="text-muted mb-0">
        Ajukan izin untuk <strong>minimal 2 santri</strong> sekaligus — satu surat A4 untuk seluruh rombongan.
        Saat kembali, scan kartu masing-masing santri di halaman presensi.
        Persetujuan perorangan ada di menu <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Persetujuan Izin</a>.
    </p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Menunggu</div>
            <div class="app-mini-stat-value text-warning"><?= $totalPending ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Disetujui (tampilan)</div>
            <div class="app-mini-stat-value text-success"><?= $totalDisetujui ?></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tautan</div>
            <div class="d-flex flex-wrap gap-2 mt-1">
                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/permohonan.php')) ?>">Izin perorangan</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/izin_tetap.php')) ?>">Izin tetap</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                Form izin rombongan
            </div>
            <div class="card-body">
                <form method="post" class="row g-2" id="form-izin-rombongan" data-rombongan-min="2" data-rombongan-target="rombongan-input">
                    <input type="hidden" name="action" value="create_rombongan">
                    <div class="col-12">
                        <label class="form-label">Pilih santri rombongan <span class="text-danger">*</span> <span class="text-muted fw-normal">(min. 2)</span></label>
                        <div class="form-text mb-1">Ketik NIS atau nama, lalu centang santri yang muncul.</div>
                        <input type="search" class="form-control form-control-sm mb-2" id="izin-rombongan-cari-santri" placeholder="Cari NIS atau nama santri…" autocomplete="off">
                        <div id="izin-rombongan-santri-terpilih" class="d-flex flex-wrap gap-1 mb-2"></div>
                        <?php
                        $rombonganPickerName = 'santri_ids_rombongan[]';
                        $rombonganPickerId = 'rombongan-input';
                        $rombonganPickerShowToolbar = true;
                        $rombonganPickerHideNamaInList = false;
                        $rombonganPickerStartHidden = true;
                        require __DIR__ . '/partials/rombongan_santri_picker.php';
                        ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jenis izin</label>
                        <select class="form-select form-select-sm" name="jenis_izin" id="jenis-izin-rombongan" required>
                            <?php $selectedJenis = 'KELUAR'; require __DIR__ . '/partials/jenis_izin_select_options.php'; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alasan</label>
                        <textarea class="form-control form-control-sm" name="alasan" rows="2" required placeholder="Keperluan rombongan…"></textarea>
                    </div>
                    <?php
                    $tujuanWrapId = 'wrap-tujuan-rombongan';
                    $tujuanJenisSelectId = 'jenis-izin-rombongan';
                    $tujuanValue = '';
                    $tujuanInputClass = 'form-control-sm';
                    require __DIR__ . '/partials/tujuan_izin_field.php';
                    ?>
                    <div class="col-4">
                        <label class="form-label">Jam mulai</label>
                        <input type="text" name="jam_mulai" class="form-control form-control-sm" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Jam selesai</label>
                        <input type="text" name="jam_selesai" class="form-control form-control-sm" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam(date('H:i'))) ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Durasi (jam)</label>
                        <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pemberi izin</label>
                        <input type="text" class="form-control form-control-sm" name="pemberi_izin" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pengasuh</label>
                        <input type="text" class="form-control form-control-sm" name="penandatangan_pengasuh" value="<?= htmlspecialchars($namaPengasuh) ?>" <?= $namaPengasuh !== '' ? 'readonly' : '' ?> required>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-light border small py-2 mb-0">
                            <i class="fa-solid fa-circle-info text-primary me-1"></i>
                            Jenis <strong>Sakit</strong> tidak tersedia untuk rombongan — gunakan pengajuan perorangan + E-Health.
                        </div>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane me-1"></i> Simpan izin rombongan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Riwayat izin rombongan</span>
                <?php if ($totalPending > 0): ?>
                    <span class="badge text-bg-warning"><?= $totalPending ?> menunggu</span>
                <?php endif; ?>
            </div>
            <div class="card-body border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label small text-muted mb-0">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua</option>
                            <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Menunggu</option>
                            <option value="DISETUJUI" <?= $filterStatus === 'DISETUJUI' ? 'selected' : '' ?>>Disetujui</option>
                            <option value="DITOLAK" <?= $filterStatus === 'DITOLAK' ? 'selected' : '' ?>>Ditolak</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Jenis &amp; periode</th>
                                <th>Santri</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($listRows === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada izin rombongan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($listRows as $rm):
                            $rid = (int) ($rm['id'] ?? 0);
                            $st = (string) ($rm['approval_status'] ?? 'PENDING');
                            $jumlah = (int) ($rm['jumlah_santri'] ?? 0);
                            $kembali = (int) ($rm['jumlah_kembali'] ?? 0);
                            $rombonganSyari = perizinan_memerlukan_persetujuan_pengasuh((string) ($rm['jenis_izin'] ?? ''));
                            $rombonganTungguPengasuh = false;
                            if ($rombonganSyari && column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
                                $stRp = $pdo->prepare('SELECT COUNT(*) FROM perizinan WHERE rombongan_id = :rid AND approval_status = "PENDING" AND pengasuh_approved_at IS NULL');
                                $stRp->execute(['rid' => $rid]);
                                $rombonganTungguPengasuh = (int) ($stRp->fetchColumn() ?: 0) > 0;
                            }
                            $badge = match ($st) {
                                'DISETUJUI' => 'success',
                                'DITOLAK' => 'danger',
                                default => 'warning',
                            };
                            ?>
                            <tr>
                                <td class="font-monospace small"><?= $rid ?></td>
                                <td class="small">
                                    <span class="fw-semibold"><?= htmlspecialchars(jenis_izin_label((string) ($rm['jenis_izin'] ?? ''))) ?></span><br>
                                    <?= htmlspecialchars((string) ($rm['tanggal_mulai'] ?? '')) ?> s/d <?= htmlspecialchars((string) ($rm['tanggal_selesai'] ?? '')) ?><br>
                                    <span class="text-muted"><?= htmlspecialchars(substr((string) ($rm['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)) ?></span>
                                </td>
                                <td class="small">
                                    <?= $jumlah ?> santri
                                    <?php if ($st === 'DISETUJUI' && $kembali > 0): ?>
                                        <br><span class="text-success"><?= $kembali ?> sudah kembali</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                                    <?php if ($rombonganTungguPengasuh): ?>
                                        <span class="badge text-bg-warning">Tunggu pengasuh</span>
                                    <?php elseif ($rombonganSyari && $st === 'PENDING'): ?>
                                        <span class="badge text-bg-info">Izin syar'i</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($st === 'PENDING'): ?>
                                    <?php if (!$rombonganSyari): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="approve_rombongan">
                                        <input type="hidden" name="rombongan_id" value="<?= $rid ?>">
                                        <input type="hidden" name="tanggal_mulai" value="<?= htmlspecialchars((string) ($rm['tanggal_mulai'] ?? '')) ?>">
                                        <input type="hidden" name="tanggal_selesai" value="<?= htmlspecialchars((string) ($rm['tanggal_selesai'] ?? '')) ?>">
                                        <input type="hidden" name="jam_mulai" value="<?= htmlspecialchars(substr((string) ($rm['jam_mulai'] ?? ''), 0, 5)) ?>">
                                        <input type="hidden" name="jam_selesai" value="<?= htmlspecialchars(substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)) ?>">
                                        <?php if ($bolehBypassAlpa): ?>
                                            <label class="small me-1"><input type="checkbox" name="bypass_alpa" value="1"> Lewati ALPA</label>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-success">Setujui</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Tolak izin rombongan ini?');">
                                        <input type="hidden" name="action" value="reject_rombongan">
                                        <input type="hidden" name="rombongan_id" value="<?= $rid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Tolak</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$hideCetakSurat && ($st === 'DISETUJUI' || ($rombonganSyari && !$rombonganTungguPengasuh && $st === 'PENDING'))): ?>
                                        <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="<?= htmlspecialchars(app_href('/perizinan/surat_rombongan.php?id=' . $rid)) ?>"><i class="fa-solid fa-print"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-rombongan-picker.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-tujuan-field.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/izin-rombongan-ui.js')) ?>" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
