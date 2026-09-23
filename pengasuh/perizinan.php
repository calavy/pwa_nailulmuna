<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';
require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';

require_roles(['admin', 'pengurus', 'kiai']);

perizinan_rombongan_ensure_schema($pdo);

$isPengasuhOnly = user_is_pengasuh_kiai() && !is_super_admin() && strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'kiai';
$izinAksiHref = app_href('/pengasuh/izin_aksi.php');
$pendingRows = perizinan_pengasuh_pending_list($pdo, 100);
foreach ($pendingRows as &$pendingRow) {
    if (!isset($pendingRow['approval_status'])) {
        $pendingRow['approval_status'] = 'PENDING';
    }
}
unset($pendingRow);
$izinAlpaMap = perizinan_alpa_map_for_rows($pdo, $pendingRows);
$munawibPending = pb_munawib_pengajuan_pending_list($pdo, 50);

$rombonganById = [];
$individuRows = [];
foreach ($pendingRows as $row) {
    $rid = (int) ($row['rombongan_id'] ?? 0);
    if ($rid <= 0) {
        $individuRows[] = $row;
        continue;
    }
    if (!isset($rombonganById[$rid])) {
        $meta = perizinan_rombongan_meta($pdo, $rid) ?: [];
        $rombonganById[$rid] = [
            'id' => $rid,
            'jenis_izin' => (string) ($meta['jenis_izin'] ?? $row['jenis_izin'] ?? ''),
            'syari_kategori' => (string) ($meta['syari_kategori'] ?? $row['syari_kategori'] ?? ''),
            'tanggal_mulai' => (string) ($meta['tanggal_mulai'] ?? $row['tanggal_mulai'] ?? ''),
            'tanggal_selesai' => (string) ($meta['tanggal_selesai'] ?? $row['tanggal_selesai'] ?? ''),
            'jam_mulai' => (string) ($meta['jam_mulai'] ?? $row['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($meta['jam_selesai'] ?? $row['jam_selesai'] ?? ''),
            'alasan' => (string) ($meta['alasan'] ?? ''),
            'tujuan' => (string) ($meta['tujuan'] ?? ''),
            'jumlah' => 0,
            'izin_ids' => [],
        ];
    }
    $rombonganById[$rid]['jumlah']++;
    $rombonganById[$rid]['izin_ids'][] = (int) ($row['id'] ?? 0);
}
$rombonganPending = array_values($rombonganById);

$pageTitle = 'Persetujuan Izin — Pengasuh';
$pageScripts = [
    app_asset_href('/assets/js/izin-alpa-modal.js'),
    app_asset_href('/assets/js/pengasuh-izin-setujui.js'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pengasuh/dashboard.php')) ?>">Pengasuh</a> · Perizinan</p>
    <h1 class="h4 mb-1">Persetujuan Izin</h1>
    <p class="text-muted mb-0">
        Setelah disetujui, izin langsung aktif (QR) — pengurus mencetak surat.
        <?php if (!$isPengasuhOnly): ?>
            <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Modul perizinan pengurus</a>
        <?php endif; ?>
    </p>
</div>

<?php if ($rombonganPending !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Rombongan</div>
    <div class="card-body">
        <div class="pg-izin-list">
        <?php foreach ($rombonganPending as $rm):
            $rid = (int) $rm['id'];
            $rmRingkas = perizinan_alpa_pilih_rombongan($izinAlpaMap, $rm['izin_ids'] ?? []);
            $alpaCek = $rmRingkas['cek'];
            $blokirR = (int) $rmRingkas['blokir'];
            $rmJudul = 'Rombongan · ' . (int) $rm['jumlah'] . ' santri';
            $rmTanggal = app_format_izin_rentang(
                (string) $rm['tanggal_mulai'],
                (string) $rm['tanggal_selesai'],
                substr((string) $rm['jam_mulai'], 0, 5),
                substr((string) $rm['jam_selesai'], 0, 5)
            );
            $rmNote = $blokirR > 0
                ? ($blokirR . ' dari ' . (int) $rm['jumlah'] . ' santri terhalang ALPA')
                : '';
            $rmAlasan = trim((string) ($rm['alasan'] ?? ''));
            $rmMeta = $rmTanggal;
            if ($rmAlasan !== '') {
                $rmMeta .= ' · ' . $rmAlasan;
            }
            ?>
            <article class="pg-izin-card pg-izin-card--rombongan">
                <div class="pg-izin-card__body">
                    <div class="fw-semibold"><?= htmlspecialchars($rmJudul) ?></div>
                    <div class="small text-muted pg-izin-card__meta"<?= $rmAlasan !== '' ? ' title="' . htmlspecialchars($rmMeta) . '"' : '' ?>><?= htmlspecialchars($rmMeta) ?></div>
                </div>
                <div class="pg-izin-card__actions">
                    <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-setujui-form"
                        <?= perizinan_pengasuh_setujui_form_attrs($pdo, $rm, $rmJudul, $rmTanggal, $alpaCek, $rmNote !== '' ? ['data-alpa-rombongan-note' => $rmNote] : []) ?>>
                        <input type="hidden" name="action" value="setujui_rombongan_pengasuh">
                        <input type="hidden" name="rombongan_id" value="<?= $rid ?>">
                        <button type="submit" class="btn btn-success btn-sm pg-pengasuh-submit">Setujui</button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-tolak-form" data-confirm="Tolak izin rombongan ini?">
                        <input type="hidden" name="action" value="tolak_rombongan_pengasuh">
                        <input type="hidden" name="rombongan_id" value="<?= $rid ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Tolak</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="pg-izin-flash" class="alert d-none mb-3" role="status"></div>

<?php if ($munawibPending !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Pengganti munawib pembimbing</span>
        <span class="badge text-bg-warning"><?= count($munawibPending) ?></span>
    </div>
    <div class="card-body">
        <div class="pg-izin-list">
        <?php foreach ($munawibPending as $mp):
            $mpNama = (string) ($mp['nama_pembimbing'] ?? 'Pembimbing');
            $mpRentang = app_format_izin_rentang(
                (string) ($mp['tanggal_mulai'] ?? ''),
                (string) ($mp['tanggal_selesai'] ?? ''),
                '',
                ''
            );
            $mpMeta = $mpRentang . ' · ' . (string) ($mp['nama_kegiatan'] ?? 'Kegiatan')
                . ' → ' . (string) ($mp['munawib_nama'] ?? 'Munawib');
            $mpAlasan = trim((string) ($mp['alasan'] ?? ''));
            $mpMateri = pb_jadwal_materi_ringkas((string) ($mp['materi_pengganti'] ?? ''));
            ?>
            <article class="pg-izin-card">
                <div class="pg-izin-card__body">
                    <div class="fw-semibold"><?= htmlspecialchars($mpNama) ?></div>
                    <div class="small text-muted pg-izin-card__meta"><?= htmlspecialchars($mpMeta) ?></div>
                    <?php if ($mpAlasan !== ''): ?>
                    <div class="small mt-1"><span class="text-muted">Alasan:</span> <?= htmlspecialchars($mpAlasan) ?></div>
                    <?php endif; ?>
                    <?php if ($mpMateri !== ''): ?>
                    <div class="small text-muted"><?= htmlspecialchars($mpMateri) ?></div>
                    <?php endif; ?>
                </div>
                <div class="pg-izin-card__actions flex-column align-items-stretch gap-1">
                    <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-setujui-form">
                        <input type="hidden" name="action" value="setujui_munawib_pengasuh">
                        <input type="hidden" name="pengajuan_id" value="<?= (int) ($mp['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-success btn-sm w-100">Setujui munawib</button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-tolak-form" data-confirm="Tolak pengajuan munawib ini?">
                        <input type="hidden" name="action" value="tolak_munawib_pengasuh">
                        <input type="hidden" name="pengajuan_id" value="<?= (int) ($mp['id'] ?? 0) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Tolak</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Menunggu persetujuan</span>
        <span class="badge text-bg-warning"><?= count($individuRows) ?></span>
    </div>
    <div class="card-body">
        <?php if ($individuRows === []): ?>
            <div class="text-muted text-center py-4">Tidak ada izin yang menunggu.</div>
        <?php else: ?>
        <div class="pg-izin-list">
            <?php foreach ($individuRows as $iz):
                $alpaCek = $izinAlpaMap[(int) ($iz['id'] ?? 0)] ?? ['subject' => false, 'allowed' => true];
                $izNama = (string) ($iz['nama_santri'] ?? '');
                $izTanggal = app_format_izin_rentang(
                    (string) ($iz['tanggal_mulai'] ?? ''),
                    (string) ($iz['tanggal_selesai'] ?? ''),
                    substr((string) ($iz['jam_mulai'] ?? ''), 0, 5),
                    substr((string) ($iz['jam_selesai'] ?? ''), 0, 5)
                );
                $izAlasan = trim((string) ($iz['alasan'] ?? ''));
                $izMeta = $izTanggal;
                if ($izAlasan !== '') {
                    $izMeta .= ' · ' . $izAlasan;
                }
                ?>
                <article class="pg-izin-card">
                    <div class="pg-izin-card__body">
                        <div class="fw-semibold"><?= htmlspecialchars($izNama) ?></div>
                        <div class="small text-muted pg-izin-card__meta"<?= $izAlasan !== '' ? ' title="' . htmlspecialchars($izMeta) . '"' : '' ?>><?= htmlspecialchars($izMeta) ?></div>
                    </div>
                    <div class="pg-izin-card__actions">
                        <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-setujui-form"
                            <?= perizinan_pengasuh_setujui_form_attrs($pdo, $iz, $izNama, $izTanggal, $alpaCek) ?>>
                            <input type="hidden" name="action" value="setujui_pengasuh">
                            <input type="hidden" name="izin_id" value="<?= (int) $iz['id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm pg-pengasuh-submit">Setujui</button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-tolak-form" data-confirm="Tolak permohonan izin ini?">
                            <input type="hidden" name="action" value="tolak_pengasuh">
                            <input type="hidden" name="izin_id" value="<?= (int) $iz['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Tolak</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/partials/pengasuh_izin_setujui_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
