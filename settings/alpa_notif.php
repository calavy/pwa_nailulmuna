<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
ensure_alpa_tier_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_periode') {
        $mode = strtolower(trim((string) ($_POST['periode_mode'] ?? 'monthly')));
        if (!in_array($mode, ['weekly', 'monthly', 'default'], true)) {
            $mode = 'monthly';
        }
        save_setting($pdo, 'alpa_notif_periode_mode', $mode);

        $tglMulaiRaw = trim((string) ($_POST['tanggal_mulai'] ?? ''));
        $tglMulai = '';
        if ($tglMulaiRaw !== '') {
            $ts = strtotime($tglMulaiRaw);
            if ($ts) {
                $tglMulai = date('Y-m-d', $ts);
            }
        }
        save_setting($pdo, 'alpa_notif_tanggal_mulai', $tglMulai);

        $msg = 'Mode periode notifikasi diperbarui: ' . alpa_tier_periode_label($mode) . '.';
        if ($tglMulai !== '') {
            $msg .= ' Tanggal awal perhitungan: ' . $tglMulai . '.';
        } else {
            $msg .= ' Tanpa batas tanggal awal (semua alpa dihitung).';
        }
        set_flash('success', $msg);
        header('Location: ' . app_href('/settings/alpa_notif.php'));
        exit;
    }

    if ($action === 'save_tier') {
        $id = (int) ($_POST['id'] ?? 0);
        $threshold = max(1, (int) ($_POST['threshold'] ?? 0));
        $label = trim((string) ($_POST['label'] ?? ''));
        $wa = trim((string) ($_POST['wa'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($threshold < 1) {
            set_flash('error', 'Ambang harus angka ≥ 1.');
        } elseif ($wa === '' && $isActive === 1) {
            set_flash('error', 'Nomor WA penerima wajib diisi untuk tier aktif.');
        } else {
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE alpa_tier_notif SET threshold = :t, label = :l, wa = :w, is_active = :a WHERE id = :id');
                $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'a' => $isActive, 'id' => $id]);
                set_flash('success', 'Tier diperbarui (ambang ' . $threshold . ').');
            } else {
                $st = $pdo->prepare('INSERT INTO alpa_tier_notif (threshold, label, wa, is_active) VALUES (:t, :l, :w, :a)');
                $st->execute(['t' => $threshold, 'l' => $label, 'w' => $wa, 'a' => $isActive]);
                set_flash('success', 'Tier baru ditambahkan (ambang ' . $threshold . ').');
            }
        }
        header('Location: ' . app_href('/settings/alpa_notif.php'));
        exit;
    }

    if ($action === 'delete_tier') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM alpa_tier_notif WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Tier dihapus.');
        }
        header('Location: ' . app_href('/settings/alpa_notif.php'));
        exit;
    }

    if ($action === 'reset_log') {
        $pdo->exec('TRUNCATE TABLE alpa_tier_dispatch_log');
        set_flash('success', 'Log dispatch direset. Tier akan dikirim ulang pada generate alpa berikutnya.');
        header('Location: ' . app_href('/settings/alpa_notif.php'));
        exit;
    }
}

$periodeMode = alpa_tier_periode_mode($pdo);
$tanggalMulai = alpa_tier_tanggal_mulai($pdo);
$tiers = alpa_tier_list($pdo, false);

$logTotal = (int) ($pdo->query('SELECT COUNT(*) FROM alpa_tier_dispatch_log')->fetchColumn() ?: 0);

$pageTitle = 'Notifikasi Alpa Bertahap';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/alpa_notif.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Notifikasi Alpa Bertahap</h1>
    <p class="text-muted mb-0 small">
        Atur ambang batas alpa yang memicu pengiriman WA ke pengurus berbeda. Contoh: 5 → pengurus A, 10 → pengurus B, 15 → pengurus C.
        Perhitungan ALPA mengikuti <em>periode</em> yang dipilih: mingguan, bulanan, atau akumulasi sejak awal.
    </p>
</div>

<?php if ($msg = get_flash('success')): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>
<?php if ($msg = get_flash('error')): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock-rotate-left text-primary me-1"></i> Periode perhitungan</h2>
        <p class="text-muted small mb-3">
            <strong>Mode periode</strong> menentukan kapan hitungan alpa di-reset (mingguan/bulanan/akumulatif).
            <strong>Tanggal awal</strong> membatasi alpa mana yang dihitung — alpa sebelum tanggal ini diabaikan.
            Kosongkan tanggal awal jika ingin menghitung semua alpa.
        </p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="save_periode">
            <div class="col-md-5">
                <label class="form-label small">Mode periode</label>
                <select name="periode_mode" class="form-select form-select-sm">
                    <?php foreach (['monthly', 'weekly', 'default'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $periodeMode === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars(alpa_tier_periode_label($opt)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Tanggal awal perhitungan</label>
                <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggalMulai) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100">Simpan</button>
            </div>
            <div class="col-md-2 text-md-end">
                <?php if ($tanggalMulai !== ''): ?>
                    <span class="badge text-bg-success-subtle border text-success">Mulai <?= htmlspecialchars($tanggalMulai) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-light border">Tanpa batas awal</span>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <small class="text-muted">
                    Aktif:
                    <strong><?= htmlspecialchars(alpa_tier_periode_label($periodeMode)) ?></strong><?php if ($tanggalMulai !== ''): ?>, perhitungan dimulai sejak <strong><?= htmlspecialchars($tanggalMulai) ?></strong><?php endif; ?>.
                </small>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h2 class="h6 mb-1"><i class="fa-solid fa-layer-group text-warning me-1"></i> Daftar Tier (ambang &amp; penerima)</h2>
                <p class="text-muted small mb-0">
                    Tambah/edit tier di bawah. Hitungan bersifat <strong>kumulatif</strong>:
                    saat alpa = 5 dikirim ke tier-5, saat bertambah jadi 10 dikirim ke tier-10, dst.
                    Jika satu tier sudah dikirim pada periode tertentu, tidak akan dikirim ulang.
                </p>
            </div>
            <form method="post" onsubmit="return confirm('Reset log dispatch? Semua tier akan dianggap belum pernah dikirim — bisa terjadi pengiriman ulang.');">
                <input type="hidden" name="action" value="reset_log">
                <button class="btn btn-outline-danger btn-sm" type="submit">
                    Reset log dispatch (<?= $logTotal ?>)
                </button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 110px;">Ambang</th>
                    <th>Label / Penanggung jawab</th>
                    <th>Nomor WA (pisah koma)</th>
                    <th style="width: 90px;" class="text-center">Aktif</th>
                    <th style="width: 160px;" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tiers): ?>
                    <tr><td colspan="5" class="text-muted text-center py-3">Belum ada tier. Tambah di bawah.</td></tr>
                <?php endif; ?>
                <?php foreach ($tiers as $t): ?>
                    <tr>
                        <form method="post">
                            <input type="hidden" name="action" value="save_tier">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <td><input type="number" name="threshold" class="form-control form-control-sm" min="1" value="<?= (int) $t['threshold'] ?>" required></td>
                            <td><input type="text" name="label" class="form-control form-control-sm" value="<?= htmlspecialchars($t['label']) ?>" placeholder="Mis: Wali Kelas, Pengasuh, Pondok Pusat"></td>
                            <td><input type="text" name="wa" class="form-control form-control-sm" value="<?= htmlspecialchars($t['wa']) ?>" placeholder="628xx, 628yy"></td>
                            <td class="text-center"><input type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-primary" type="submit">Simpan</button>
                        </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus tier ambang <?= (int) $t['threshold'] ?>?');">
                                    <input type="hidden" name="action" value="delete_tier">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                </form>
                            </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-light">
                    <form method="post">
                        <input type="hidden" name="action" value="save_tier">
                        <td><input type="number" name="threshold" class="form-control form-control-sm" min="1" placeholder="Mis: 5" required></td>
                        <td><input type="text" name="label" class="form-control form-control-sm" placeholder="Mis: Wali Kelas"></td>
                        <td><input type="text" name="wa" class="form-control form-control-sm" placeholder="628xx, 628yy" required></td>
                        <td class="text-center"><input type="checkbox" name="is_active" value="1" checked></td>
                        <td class="text-end"><button class="btn btn-sm btn-success" type="submit">+ Tambah</button></td>
                    </form>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-light border small">
    <strong>Cara kerja:</strong><br>
    1. Setiap kali alpa baru dicatat (lewat <em>Generate Alpa Otomatis</em>), sistem menjumlahkan alpa santri pada periode aktif (<?= htmlspecialchars(alpa_tier_periode_label($periodeMode)) ?>)<?php if ($tanggalMulai !== ''): ?> — hanya alpa <em>sejak <?= htmlspecialchars($tanggalMulai) ?></em> yang dihitung<?php endif; ?>.<br>
    2. Untuk tiap tier (urut ambang ASC): jika jumlah alpa ≥ ambang DAN tier itu belum pernah dikirim untuk santri ini pada periode ini → WA dikirim ke nomor tier, lalu dicatat di log.<br>
    3. Tier yang sudah dikirim pada satu periode tidak akan diulang sampai periode tersebut habis (mingguan/bulanan) atau log direset (mode default).<br>
    4. <strong>Tanggal awal</strong> berguna saat awal tahun ajaran/semester baru — set tanggal mulainya agar alpa sebelumnya tidak ikut menentukan tier yang sudah lewat. Boleh kosong (semua alpa dihitung).<br>
    5. Jika tidak ada tier aktif, sistem tetap memakai notifikasi tunggal lama (≥ <em>batas_alpa_notif</em> kali → <em>wa_pengurus</em>).
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
