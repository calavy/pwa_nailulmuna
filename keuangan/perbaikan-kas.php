<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_perbaikan_kas.php';
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_validasi_pesan.php';

keuangan_ensure_schema_deferred($pdo);
pembayaran_edit_token_ensure_schema($pdo);

$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$isSuperAdmin = is_super_admin();
$canEditFull = pembayaran_edit_token_user_boleh_edit($pdo);
$akunRows = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = keuangan_perbaikan_kas_default_akun_id($pdo);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'patch_akun') {
        $res = keuangan_perbaikan_kas_patch_akun(
            $pdo,
            (string) ($_POST['tipe'] ?? ''),
            (int) ($_POST['id'] ?? 0),
            (int) ($_POST['akun_id'] ?? 0),
            $currentUserId
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'patch_semua') {
        $res = keuangan_perbaikan_kas_patch_semua_tanpa_akun(
            $pdo,
            (int) ($_POST['akun_id'] ?? $defaultAkunId),
            $currentUserId
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'delete') {
        if (!$isSuperAdmin) {
            set_flash('error', 'Hanya super admin yang dapat menghapus transaksi.');
            header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
            exit;
        }
        $res = keuangan_perbaikan_kas_hapus(
            $pdo,
            (string) ($_POST['tipe'] ?? ''),
            (int) ($_POST['id'] ?? 0),
            $currentUserId,
            (string) ($_POST['alasan'] ?? '')
        );
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }

    if ($action === 'redeem_token') {
        $res = pembayaran_edit_token_redeem($pdo, $currentUserId, (string) ($_POST['token_plain'] ?? ''));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/perbaikan-kas.php'));
        exit;
    }
}

$ringkas = keuangan_perbaikan_kas_ringkas($pdo);
$tokenRequired = pembayaran_edit_token_required_for_current_user();
$tokenAktif = pembayaran_edit_token_session_aktif($pdo);

$pageTitle = 'Perbaikan Kas';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';

/**
 * @param list<array<string,mixed>> $rows
 */
function perbaikan_kas_render_tabel(
    string $tipe,
    array $rows,
    array $akunRows,
    int $defaultAkunId,
    callable $fmt,
    bool $isSuperAdmin,
    bool $canEditFull
): void {
    if ($rows === []) {
        echo '<p class="text-muted small mb-0">Tidak ada.</p>';

        return;
    }

    $def = keuangan_kesalahan_kas_def($tipe . '_tanpa_akun');
    $keteranganUmum = (string) ($def['penjelasan'] ?? '') . ' ' . (string) ($def['dampak'] ?? '');

    echo '<p class="small text-muted px-3 pt-2 mb-0"><i class="fa-solid fa-circle-info me-1"></i> ' . htmlspecialchars($keteranganUmum) . '</p>';
    echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Tanggal</th><th>Keterangan</th><th class="text-end">Nominal</th>';
    echo '<th>Kesalahan</th><th style="min-width:12rem">Perbaiki akun</th><th class="text-end">Aksi</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $keterangan = '';
        if ($tipe === 'pembayaran') {
            $keterangan = trim((string) ($row['nis'] ?? '') . ' — ' . (string) ($row['nama_santri'] ?? ''));
        } elseif ($tipe === 'pemasukan') {
            $keterangan = (string) ($row['sumber'] ?? '');
            if (!empty($row['dari_pihak'])) {
                $keterangan .= ' · ' . (string) $row['dari_pihak'];
            }
        } else {
            $keterangan = (string) ($row['pos'] ?? '') . ' · ' . (string) ($row['penanggung_jawab'] ?? '');
        }

        echo '<tr>';
        echo '<td>#' . $id . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['tanggal'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($keterangan) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) round((float) ($row['nominal'] ?? 0)))) . '</td>';
        echo '<td class="small text-danger">' . htmlspecialchars((string) ($def['judul'] ?? 'Tanpa akun kas')) . '</td>';
        echo '<td>';
        echo '<form method="post" class="d-flex gap-1 align-items-center">';
        echo '<input type="hidden" name="action" value="patch_akun">';
        echo '<input type="hidden" name="tipe" value="' . htmlspecialchars($tipe) . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<select name="akun_id" class="form-select form-select-sm" required>';
        foreach ($akunRows as $ar) {
            $aid = (int) ($ar['id'] ?? 0);
            $sel = $aid === $defaultAkunId ? ' selected' : '';
            echo '<option value="' . $aid . '"' . $sel . '>' . htmlspecialchars((string) ($ar['nama_akun'] ?? '')) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-sm btn-success text-nowrap">Simpan</button>';
        echo '</form>';
        echo '</td>';
        echo '<td class="text-end text-nowrap">';
        echo '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars(keuangan_perbaikan_kas_edit_url($tipe, $id)) . '">Edit penuh</a> ';
        if ($isSuperAdmin || ($tipe !== 'pembayaran' && $canEditFull)) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#hapusModal" ';
            echo 'data-tipe="' . htmlspecialchars($tipe) . '" data-id="' . $id . '" data-label="' . htmlspecialchars($keterangan) . '">Hapus</button>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a> · Koreksi</p>
    <h1 class="h4 mb-1">Perbaikan Kas</h1>
    <p class="text-muted mb-0 small">
        Transaksi tanpa akun kas/bank menyebabkan selisih antara saldo hitung dan saldo fisik.
        Input baru <strong>sudah ditolak</strong> jika akun tidak dipilih.
        Perbaiki data lama di sini, atau hapus jika entri ganda.
    </p>
</div>

<?php if ($ringkas['jumlah'] === 0 && ($ringkas['duplikat'] ?? []) === []): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check me-1"></i>
    Semua transaksi kas sudah terhubung ke akun. Tidak ada yang perlu diperbaiki.
    <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>" class="alert-link">Lihat rekap kas</a>
</div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Tanpa akun kas</div>
            <div class="app-mini-stat-value text-warning"><?= (int) $ringkas['jumlah'] ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total nominal bermasalah</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $ringkas['nominal'])) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Kemungkinan dobel</div>
            <div class="app-mini-stat-value"><?= count($ringkas['duplikat'] ?? []) ?></div>
        </div>
    </div>
</div>

<?php if ($ringkas['jumlah'] > 0 && $akunRows !== []): ?>
<div class="card shadow-sm mb-3 border-warning">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> Perbaiki semua sekaligus</h2>
        <p class="small text-muted mb-2">Hubungkan semua transaksi tanpa akun ke satu akun kas yang sama.</p>
        <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Perbaiki semua transaksi tanpa akun ke akun yang dipilih?');">
            <input type="hidden" name="action" value="patch_semua">
            <div class="col-md-6">
                <label class="form-label small">Akun kas tujuan</label>
                <select name="akun_id" class="form-select" required>
                    <?php foreach ($akunRows as $ar): ?>
                        <option value="<?= (int) ($ar['id'] ?? 0) ?>" <?= (int) ($ar['id'] ?? 0) === $defaultAkunId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?>
                            (<?= htmlspecialchars((string) ($ar['jenis_akun'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-warning">
                    Perbaiki <?= (int) $ringkas['jumlah'] ?> transaksi
                </button>
            </div>
        </form>
    </div>
</div>
<?php elseif ($akunRows === []): ?>
<div class="alert alert-danger">
    Belum ada akun kas aktif.
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=akun')) ?>">Buat akun kas</a> terlebih dahulu.
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tokenRequired && !$tokenAktif): ?>
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Token super admin (untuk hapus pemasukan/pengeluaran)</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="redeem_token">
            <div class="col-md-8">
                <input type="password" name="token_plain" class="form-control" placeholder="Token edit" autocomplete="off" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary">Aktifkan token</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pembayaran'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white">
        <strong>Pembayaran santri tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pembayaran']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel('pembayaran', $ringkas['pembayaran'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pemasukan'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white">
        <strong>Pemasukan lain tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pemasukan']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel('pemasukan', $ringkas['pemasukan'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['pengeluaran'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-white">
        <strong>Pengeluaran tanpa akun</strong>
        <span class="badge bg-warning text-dark ms-1"><?= count($ringkas['pengeluaran']) ?></span>
    </div>
    <div class="card-body p-0">
        <?php perbaikan_kas_render_tabel('pengeluaran', $ringkas['pengeluaran'], $akunRows, $defaultAkunId, $fmt, $isSuperAdmin, $canEditFull); ?>
    </div>
</div>
<?php endif; ?>

<?php if (($ringkas['duplikat'] ?? []) !== []): ?>
<div class="card shadow-sm mb-3 border-danger">
    <div class="card-header bg-white">
        <strong>Kemungkinan pembayaran dobel</strong>
        <span class="badge bg-danger ms-1"><?= count($ringkas['duplikat']) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Santri</th>
                        <th>Tanggal</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Jumlah baris</th>
                        <th>ID</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ringkas['duplikat'] as $dup): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dup['nis'] ?? '') . ' — ' . (string) ($dup['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($dup['tanggal_bayar'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) round((float) ($dup['nominal'] ?? 0)))) ?></td>
                        <td class="text-center"><span class="badge bg-danger"><?= (int) ($dup['jumlah'] ?? 0) ?>×</span></td>
                        <td class="small text-muted">#<?= (int) ($dup['id_pertama'] ?? 0) ?>–#<?= (int) ($dup['id_terakhir'] ?? 0) ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat_edit.php?id=' . (int) ($dup['id_terakhir'] ?? 0))) ?>" class="btn btn-sm btn-outline-danger">Periksa &amp; hapus</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted px-3 py-2 mb-0">
            <?php $dupDef = keuangan_kesalahan_kas_def('pembayaran_dobel'); ?>
            <i class="fa-solid fa-circle-info me-1"></i>
            <?= htmlspecialchars((string) $dupDef['penjelasan'] . ' ' . $dupDef['dampak']) ?>
            Input baru sudah ditolak jika pos wajib sudah lunas. Hapus baris duplikat lewat edit pembayaran (super admin + alasan).
        </p>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="hapusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tipe" id="hapusTipe">
            <input type="hidden" name="id" id="hapusId">
            <div class="modal-header">
                <h5 class="modal-title">Hapus transaksi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Anda akan menghapus: <strong id="hapusLabel"></strong></p>
                <label class="form-label">Alasan penghapusan <span class="text-danger">*</span></label>
                <textarea name="alasan" class="form-control" rows="3" required placeholder="Contoh: entri ganda, salah input"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus permanen</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('hapusModal')?.addEventListener('show.bs.modal', function (ev) {
    const btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('hapusTipe').value = btn.getAttribute('data-tipe') || '';
    document.getElementById('hapusId').value = btn.getAttribute('data-id') || '';
    document.getElementById('hapusLabel').textContent = btn.getAttribute('data-label') || '';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
