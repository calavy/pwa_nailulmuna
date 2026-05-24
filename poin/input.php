<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus']);
santri_list_sort_mode($_GET['santri_sort'] ?? null);
ensure_point_tables($pdo);
ensure_akademik_libur_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $santriId = (int) ($_POST['santri_id'] ?? 0);
    $jenis = strtoupper(trim((string) ($_POST['jenis_perubahan'] ?? 'PLUS')));
    $tanggal = (string) ($_POST['tanggal'] ?? date('Y-m-d'));
    $ruleId = (int) ($_POST['rule_id'] ?? 0);
    $customPoint = (int) ($_POST['point_custom'] ?? 0);
    $keterangan = trim((string) ($_POST['keterangan'] ?? ''));

    if ($santriId <= 0) {
        set_flash('error', 'Pilih santri terlebih dahulu.');
        header('Location: ' . app_href('/poin/input.php'));
        exit;
    }
    if (!in_array($jenis, ['PLUS', 'MINUS'], true)) {
        $jenis = 'PLUS';
    }

    $point = 0;
    if ($ruleId > 0) {
        $ruleStmt = $pdo->prepare('SELECT id, nama_rule, bobot_poin FROM point_rules WHERE id = :id');
        $ruleStmt->execute(['id' => $ruleId]);
        $rule = $ruleStmt->fetch();
        if ($rule) {
            $point = (int) $rule['bobot_poin'];
            if ($keterangan === '') {
                $keterangan = 'Input rule: ' . $rule['nama_rule'];
            }
        }
    }
    if ($customPoint > 0) {
        $point = $customPoint;
    }
    if ($point <= 0) {
        set_flash('error', 'Bobot poin harus lebih dari 0.');
        header('Location: ' . app_href('/poin/input.php'));
        exit;
    }

    $liburN = akademik_libur_info($pdo, $tanggal, 'penilaian');
    if ($liburN !== null && akademik_blokir_penilaian_libur($pdo)) {
        set_flash('error', 'Tanggal ini libur: ' . $liburN['nama'] . ' — input poin tidak diizinkan (atur di Kalender akademik).');
        header('Location: ' . app_href('/poin/input.php'));
        exit;
    }

    $delta = $jenis === 'MINUS' ? -$point : $point;
    $insert = $pdo->prepare('
        INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, rule_id, sumber_data, keterangan, created_by)
        VALUES (:santri_id, :tanggal, :jenis_perubahan, :point_delta, :rule_id, "MANUAL", :keterangan, :created_by)
    ');
    $insert->execute([
        'santri_id' => $santriId,
        'tanggal' => $tanggal,
        'jenis_perubahan' => $jenis,
        'point_delta' => $delta,
        'rule_id' => $ruleId > 0 ? $ruleId : null,
        'keterangan' => $keterangan !== '' ? $keterangan : ($jenis === 'MINUS' ? 'Pengurangan poin manual' : 'Penambahan poin manual'),
        'created_by' => (int) ($_SESSION['user']['id'] ?? 1),
    ]);

    if ($jenis === 'MINUS') {
        push_maybe_pelanggaran_berat_after_point($pdo, $santriId);
    }

    set_flash('success', 'Input poin berhasil disimpan.');
    header('Location: ' . app_href('/poin/input.php'));
    exit;
}

$santriList = $pdo->query('SELECT id, nis, nama_santri, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll();
$ruleList = $pdo->query('SELECT id, kategori, nama_rule, bobot_poin FROM point_rules WHERE is_active = 1 ORDER BY urutan ASC, kategori ASC')->fetchAll();
$recentRows = $pdo->query('
    SELECT pl.tanggal, pl.jenis_perubahan, pl.point_delta, pl.keterangan, s.nama_santri, s.tingkatan
    FROM point_ledger pl
    INNER JOIN santri s ON s.id = pl.santri_id
    ORDER BY pl.id DESC
    LIMIT 30
')->fetchAll();
$totalRulesAktif = count($ruleList);
$totalSantriTersedia = count($santriList);
$totalInputTerakhir = count($recentRows);

$pageTitle = 'Input Poin Kedisiplinan';
$loadSantriSelectJs = true;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Poin</p>
    <h1 class="h4 mb-1">Input poin kedisiplinan</h1>
    <p class="text-muted mb-0">Catat pelanggaran/remedial dengan rule atau poin custom lalu pantau riwayat terbaru.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rule aktif</div>
            <div class="app-mini-stat-value"><?= $totalRulesAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Santri tersedia</div>
            <div class="app-mini-stat-value"><?= $totalSantriTersedia ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Riwayat tampil</div>
            <div class="app-mini-stat-value"><?= $totalInputTerakhir ?></div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Form Input/Pengurangan Poin</h1>
                <form method="post" class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Santri</label>
                        <select class="form-select santri-select-searchable" name="santri_id" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['nama_santri'] . ' - ' . ($s['tingkatan'] ?: '-') . ' (' . $s['nis'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Jenis</label>
                        <select class="form-select" name="jenis_perubahan">
                            <option value="PLUS">Tambah Poin</option>
                            <option value="MINUS">Kurangi Poin (Remedial)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Rule Pelanggaran (opsional)</label>
                        <select class="form-select" name="rule_id">
                            <option value="0">Pilih rule / kosongkan jika poin custom</option>
                            <?php foreach ($ruleList as $r): ?>
                                <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['kategori'] . ' - ' . $r['nama_rule'] . ' (' . $r['bobot_poin'] . ' poin)') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Poin Custom (opsional)</label>
                        <input type="number" min="1" class="form-control" name="point_custom" placeholder="Contoh: 2">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama pelanggaran / keterangan</label>
                        <textarea class="form-control" name="keterangan" rows="2" placeholder="Contoh: Tidak mengikuti apel, terlambat sholat subuh"></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Riwayat Input Terakhir</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover">
                        <thead><tr><th>Tanggal</th><th>Santri</th><th>Tingkatan</th><th>Jenis</th><th>Poin</th><th>Keterangan</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                <td><?= htmlspecialchars($row['nama_santri']) ?></td>
                                <td><?= htmlspecialchars($row['tingkatan'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($row['jenis_perubahan']) ?></td>
                                <td><?= (int) $row['point_delta'] ?></td>
                                <td><?= htmlspecialchars((string) $row['keterangan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
