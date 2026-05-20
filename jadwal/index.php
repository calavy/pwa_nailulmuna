<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'jadwal_kegiatan')) {
    set_flash('error', 'Tabel jadwal belum ada. Jalankan schema_presensi.sql terlebih dahulu.');
    header('Location: /dashboard.php');
    exit;
}
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'kegiatan') {
        $namaKegiatan = trim($_POST['nama_kegiatan'] ?? '');
        if ($namaKegiatan !== '') {
            $insert = $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, is_active) VALUES (:nama_kegiatan, 1)');
            $insert->execute(['nama_kegiatan' => $namaKegiatan]);
            set_flash('success', 'Kegiatan ditambahkan.');
        }
    }

    if ($action === 'jadwal') {
        $tingkatanInput = $_POST['tingkatan'] ?? [];
        $hariInput = $_POST['hari_ke'] ?? [];
        $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn($v): bool => $v !== '')) : [];
        $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn($v): bool => $v >= 0 && $v <= 7)) : [];

        if (!$tingkatanDipilih || !$hariDipilih) {
            set_flash('error', 'Pilih minimal 1 tingkatan dan 1 hari.');
            header('Location: /jadwal/index.php');
            exit;
        }

        $tempatJadwal = trim((string) ($_POST['tempat'] ?? ''));
        $tempatJadwal = $tempatJadwal !== '' ? $tempatJadwal : null;
        $insert = $pdo->prepare('
            INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat)
            VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, :pembimbing_id, :tempat)
        ');
        $created = 0;
        foreach ($tingkatanDipilih as $tingkatan) {
            foreach ($hariDipilih as $hariKe) {
                $insert->execute([
                    'kegiatan_id' => (int) ($_POST['kegiatan_id'] ?? 0),
                    'tingkatan' => $tingkatan,
                    'hari_ke' => $hariKe,
                    'jam_mulai' => $_POST['jam_mulai'] ?? '00:00',
                    'jam_selesai' => $_POST['jam_selesai'] ?? '00:00',
                    'pembimbing_id' => (int) ($_POST['pembimbing_id'] ?? 0) ?: null,
                    'tempat' => $tempatJadwal,
                ]);
                $created++;
            }
        }
        set_flash('success', 'Jadwal berhasil ditambahkan: ' . $created . ' data.');
    }

    if ($action === 'hapus_jadwal') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $delete = $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id');
            $delete->execute(['id' => $id]);
            set_flash('success', 'Jadwal berhasil dihapus.');
        } else {
            set_flash('error', 'ID jadwal tidak valid.');
        }
    }

    header('Location: /jadwal/index.php');
    exit;
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');
$pembimbingList = table_exists($pdo, 'pembimbing')
    ? $pdo->query('SELECT id, nama_pembimbing FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll()
    : [];
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan, is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
$jadwalList = $pdo->query("SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, k.nama_kegiatan, COALESCE(p.nama_pembimbing, '-') AS nama_pembimbing FROM jadwal_kegiatan j INNER JOIN kegiatan k ON k.id = j.kegiatan_id LEFT JOIN pembimbing p ON p.id = j.pembimbing_id ORDER BY j.tingkatan ASC, j.hari_ke ASC, j.jam_mulai ASC")->fetchAll();
$totalKegiatan = count($kegiatanList);
$totalJadwal = count($jadwalList);
$totalTingkatanJadwal = count($jadwalGrouped ?? []);

$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

$jadwalGrouped = [];
foreach ($jadwalList as $row) {
    $tg = (string) $row['tingkatan'];
    $hk = (int) $row['hari_ke'];
    if (!isset($jadwalGrouped[$tg])) {
        $jadwalGrouped[$tg] = [];
    }
    if (!isset($jadwalGrouped[$tg][$hk])) {
        $jadwalGrouped[$tg][$hk] = [];
    }
    $jadwalGrouped[$tg][$hk][] = $row;
}

$tingkatanSortIndex = array_flip(array_values($tingkatanList));
uksort($jadwalGrouped, static function (string $a, string $b) use ($tingkatanSortIndex): int {
    $ia = $tingkatanSortIndex[$a] ?? PHP_INT_MAX;
    $ib = $tingkatanSortIndex[$b] ?? PHP_INT_MAX;
    if ($ia !== $ib) {
        return $ia <=> $ib;
    }
    return strcmp($a, $b);
});

$pageTitle = 'Jadwal Kegiatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Jadwal</p>
    <h1 class="h4 mb-1">Jadwal kegiatan santri</h1>
    <p class="text-muted mb-0">Atur kegiatan per tingkatan, hari, jam, pembimbing, dan lokasi dalam satu halaman.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Data kegiatan</div>
            <div class="app-mini-stat-value"><?= $totalKegiatan ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total jadwal</div>
            <div class="app-mini-stat-value"><?= $totalJadwal ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tingkatan terjadwal</div>
            <div class="app-mini-stat-value"><?= count(array_unique(array_map(static fn(array $r): string => (string) ($r['tingkatan'] ?? '-'), $jadwalList))) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Tambah Kegiatan</h2>
                <form method="post">
                    <input type="hidden" name="action" value="kegiatan">
                    <input type="text" class="form-control mb-2" name="nama_kegiatan" placeholder="Nama kegiatan" required>
                    <button class="btn btn-success btn-sm">Simpan Kegiatan</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Set Jadwal per Tingkatan</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="jadwal">
                    <div class="col-12">
                        <label class="form-label">Kegiatan</label>
                        <select class="form-select" name="kegiatan_id" required>
                            <option value="">Pilih kegiatan</option>
                            <?php foreach ($kegiatanList as $kegiatan): ?>
                                <option value="<?= $kegiatan['id'] ?>"><?= htmlspecialchars($kegiatan['nama_kegiatan']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tingkatan (boleh centang banyak)</label>
                        <div class="border rounded p-2" style="max-height: 170px; overflow-y: auto;">
                            <?php foreach ($tingkatanList as $tg): ?>
                                <?php $tgId = 'tg-' . md5((string) $tg); ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="tingkatan[]" id="<?= htmlspecialchars($tgId) ?>" value="<?= htmlspecialchars((string) $tg) ?>">
                                    <label class="form-check-label" for="<?= htmlspecialchars($tgId) ?>"><?= htmlspecialchars((string) $tg) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Pembimbing (opsional)</label>
                        <select class="form-select" name="pembimbing_id">
                            <option value="0">Belum ditentukan</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nama_pembimbing']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Hari (boleh centang banyak)</label>
                        <div class="border rounded p-2">
                            <?php foreach ($hari as $key => $label): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="hari_ke[]" id="hari-<?= (int) $key ?>" value="<?= (int) $key ?>">
                                    <label class="form-check-label" for="hari-<?= (int) $key ?>"><?= htmlspecialchars($label) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jam Mulai</label>
                        <input type="time" class="form-control" name="jam_mulai" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jam Selesai</label>
                        <input type="time" class="form-control" name="jam_selesai" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tempat / lokasi</label>
                        <input type="text" class="form-control" name="tempat" maxlength="255" placeholder="Contoh: Masjid Utama, Aula, Ruang Kelas A">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm">Simpan Jadwal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Daftar Jadwal</h2>
                <p class="text-muted small mb-3">Dikelompokkan per tingkatan, lalu per hari. Baris diurutkan berdasarkan jam.</p>
                <?php if ($jadwalList === []): ?>
                    <p class="text-muted mb-0">Belum ada jadwal.</p>
                <?php else: ?>
                    <?php foreach ($jadwalGrouped as $namaTingkatan => $byHari): ?>
                        <div class="mb-4">
                            <h3 class="h6 text-primary border-bottom pb-2 mb-3"><?= htmlspecialchars($namaTingkatan) ?></h3>
                            <?php
                            $hariKeys = array_keys($byHari);
                            sort($hariKeys, SORT_NUMERIC);
                            ?>
                            <?php foreach ($hariKeys as $hariKe):
                                $items = $byHari[$hariKe] ?? [];
                                if ($items === []) {
                                    continue;
                                }
                                usort($items, static function (array $a, array $b): int {
                                    return strcmp((string) $a['jam_mulai'], (string) $b['jam_mulai']);
                                });
                                ?>
                                <div class="ms-0 ms-md-2 mb-3">
                                    <div class="fw-semibold small text-secondary mb-2">
                                        <?= htmlspecialchars($hari[$hariKe] ?? 'Hari #' . $hariKe) ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Jam</th>
                                                    <th>Kegiatan</th>
                                                    <th>Tempat</th>
                                                    <th>Pembimbing</th>
                                                    <th class="text-end">Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars(substr($item['jam_mulai'], 0, 5)) ?> - <?= htmlspecialchars(substr($item['jam_selesai'], 0, 5)) ?></td>
                                                        <td><?= htmlspecialchars($item['nama_kegiatan']) ?></td>
                                                        <td><?= htmlspecialchars(trim((string) ($item['tempat'] ?? '')) !== '' ? (string) $item['tempat'] : '—') ?></td>
                                                        <td><?= htmlspecialchars($item['nama_pembimbing']) ?></td>
                                                        <td class="text-end text-nowrap">
                                                            <a href="/jadwal/edit.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus jadwal ini?')">
                                                                <input type="hidden" name="action" value="hapus_jadwal">
                                                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
