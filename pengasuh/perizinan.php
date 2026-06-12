<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_roles(['admin', 'pengurus', 'kiai']);

perizinan_approval_ensure_schema($pdo);
perizinan_rombongan_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$isPengasuhOnly = user_is_pengasuh_kiai() && !is_super_admin() && strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'kiai';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'setujui_pengasuh') {
        $res = perizinan_pengasuh_setujui($pdo, (int) ($_POST['izin_id'] ?? 0), $userId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/pengasuh/perizinan.php'));
        exit;
    }
    if ($action === 'setujui_rombongan_pengasuh') {
        $res = perizinan_pengasuh_setujui_rombongan($pdo, (int) ($_POST['rombongan_id'] ?? 0), $userId);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/pengasuh/perizinan.php'));
        exit;
    }
}

$pendingRows = perizinan_pengasuh_pending_list($pdo, 100);
$rombonganPending = [];
$rombonganSeen = [];
foreach ($pendingRows as $row) {
    $rid = (int) ($row['rombongan_id'] ?? 0);
    if ($rid > 0 && !isset($rombonganSeen[$rid])) {
        $rombonganSeen[$rid] = true;
        $meta = perizinan_rombongan_meta($pdo, $rid);
        if ($meta && strtoupper((string) ($meta['approval_status'] ?? '')) === 'PENDING') {
            $anggota = perizinan_rombongan_anggota($pdo, $rid);
            $rombonganPending[] = [
                'id' => $rid,
                'jenis_izin' => (string) ($meta['jenis_izin'] ?? ''),
                'tanggal_mulai' => (string) ($meta['tanggal_mulai'] ?? ''),
                'tanggal_selesai' => (string) ($meta['tanggal_selesai'] ?? ''),
                'jam_mulai' => (string) ($meta['jam_mulai'] ?? ''),
                'jam_selesai' => (string) ($meta['jam_selesai'] ?? ''),
                'alasan' => (string) ($meta['alasan'] ?? ''),
                'jumlah' => count($anggota),
            ];
        }
    }
}

$pageTitle = 'Persetujuan Izin — Pengasuh';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/pengasuh/dashboard.php')) ?>">Pengasuh</a> · Perizinan</p>
    <h1 class="h4 mb-1">Persetujuan Izin Syar'i</h1>
    <p class="text-muted mb-0">
        Hanya <strong>Izin Syar'i</strong> yang memerlukan persetujuan pengasuh di halaman ini.
        Jenis izin lain hanya pemberitahuan — pengurus menyetujui di menu <em>Persetujuan Izin</em>.
        <?php if (!$isPengasuhOnly): ?>
            <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Modul perizinan pengurus</a>
        <?php endif; ?>
    </p>
</div>

<?php if ($rombonganPending !== []): ?>
<div class="card shadow-sm mb-3 border-warning">
    <div class="card-header fw-semibold bg-warning-subtle">Izin rombongan menunggu</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Rombongan</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rombonganPending as $rm): ?>
                    <tr>
                        <td>
                            <strong>#<?= (int) $rm['id'] ?></strong>
                            <div class="small text-muted"><?= (int) $rm['jumlah'] ?> santri</div>
                        </td>
                        <td><?= htmlspecialchars(jenis_izin_label((string) $rm['jenis_izin'])) ?></td>
                        <td class="small">
                            <?= htmlspecialchars(app_format_izin_rentang(
                                (string) $rm['tanggal_mulai'],
                                (string) $rm['tanggal_selesai'],
                                substr((string) $rm['jam_mulai'], 0, 5),
                                substr((string) $rm['jam_selesai'], 0, 5)
                            )) ?>
                        </td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('Setujui seluruh santri dalam rombongan ini?');">
                                <input type="hidden" name="action" value="setujui_rombongan_pengasuh">
                                <input type="hidden" name="rombongan_id" value="<?= (int) $rm['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success">Setujui rombongan</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Permohonan izin syar'i menunggu persetujuan</span>
        <span class="badge text-bg-warning"><?= count($pendingRows) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($pendingRows === []): ?>
            <div class="text-muted text-center py-4">Tidak ada izin syar'i yang menunggu persetujuan pengasuh.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Santri</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th>Alasan</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingRows as $iz): ?>
                    <?php if ((int) ($iz['rombongan_id'] ?? 0) > 0) {
                        continue;
                    } ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string) ($iz['tingkatan'] ?? '-')) ?> · <?= htmlspecialchars((string) ($iz['nis'] ?? '')) ?></div>
                        </td>
                        <td><?= htmlspecialchars(jenis_izin_label((string) ($iz['jenis_izin'] ?? 'KELUAR'))) ?></td>
                        <td class="small">
                            <?= htmlspecialchars(app_format_izin_rentang(
                                (string) ($iz['tanggal_mulai'] ?? ''),
                                (string) ($iz['tanggal_selesai'] ?? ''),
                                substr((string) ($iz['jam_mulai'] ?? ''), 0, 5),
                                substr((string) ($iz['jam_selesai'] ?? ''), 0, 5)
                            )) ?>
                        </td>
                        <td class="small"><?= htmlspecialchars(mb_strimwidth((string) ($iz['alasan'] ?? ''), 0, 80, '…')) ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('Setujui permohonan izin ini sebagai pengasuh?');">
                                <input type="hidden" name="action" value="setujui_pengasuh">
                                <input type="hidden" name="izin_id" value="<?= (int) $iz['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success">Setujui</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
