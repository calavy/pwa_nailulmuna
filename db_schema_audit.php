<?php

require_once __DIR__ . '/config/database.php';

/** @param list<string> $columns */
function audit_table(PDO $pdo, string $table, array $columns): array
{
    $exists = table_exists($pdo, $table);
    $result = [
        'table' => $table,
        'exists' => $exists,
        'columns' => [],
    ];

    foreach ($columns as $column) {
        $result['columns'][] = [
            'name' => $column,
            'exists' => $exists ? column_exists($pdo, $table, $column) : false,
        ];
    }

    return $result;
}

$required = [
    'app_settings' => ['setting_key', 'setting_value'],
    'users' => ['id', 'username', 'password', 'role'],
    'santri' => ['id', 'nis', 'nama_santri', 'qr', 'tingkatan', 'kategori_kelas', 'no_wa_wali', 'wali_santri_id', 'is_aktif'],
    'kelas_keuangan' => ['id', 'kode', 'nama_tampilan', 'tarif_keuangan_tier', 'urutan', 'is_aktif'],
    'wali_santri' => ['id', 'nama', 'no_wa', 'alamat', 'nomor_id', 'user_id', 'created_at'],
    'keuangan_pembayaran' => ['id', 'santri_id', 'jenis_periode', 'tanggal_bayar', 'total_nominal', 'metode_bayar', 'akun_id', 'no_referensi'],
    'keuangan_pembayaran_detail' => ['id', 'pembayaran_id', 'pos_slug', 'pos_nama', 'nominal'],
    'cashless_accounts' => ['santri_id', 'pin_hash', 'balance'],
    'cashless_transactions' => ['id', 'santri_id', 'jenis', 'nominal', 'keterangan', 'tanggal'],
    'cashless_nominal_qr_map' => ['id', 'kode_qr', 'nominal', 'keterangan', 'is_aktif'],
    'akademik_hafalan_setoran' => ['id', 'santri_id', 'tanggal_setoran', 'target_hafalan', 'juz_halaman', 'nilai_skor', 'predikat', 'catatan', 'created_by'],
    'akademik_rapor' => ['id', 'santri_id', 'judul_periode', 'tanggal_terbit', 'narasi', 'predikat_akhlak', 'catatan_pondok', 'is_published', 'created_by'],
];

$auditRows = [];
$missingTables = 0;
$missingColumns = 0;

foreach ($required as $table => $columns) {
    $row = audit_table($pdo, $table, $columns);
    if (!$row['exists']) {
        $missingTables++;
    }
    foreach ($row['columns'] as $col) {
        if (!$col['exists']) {
            $missingColumns++;
        }
    }
    $auditRows[] = $row;
}

$dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '-');
$allGood = $missingTables === 0 && $missingColumns === 0;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Skema DB</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f8fa; color: #1f2937; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .ok { color: #0f766e; font-weight: 700; }
        .bad { color: #b91c1c; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="card">
        <h1 style="margin-top:0;">Audit Skema Database</h1>
        <p style="margin:.25rem 0;">Database aktif: <code><?= htmlspecialchars($dbName) ?></code></p>
        <p style="margin:.25rem 0;">
            Status:
            <?php if ($allGood): ?>
                <span class="ok">OK (semua tabel/kolom wajib tersedia)</span>
            <?php else: ?>
                <span class="bad">PERLU PERBAIKAN</span>
                (tabel kurang: <?= $missingTables ?>, kolom kurang: <?= $missingColumns ?>)
            <?php endif; ?>
        </p>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Rincian Tabel Wajib</h2>
        <table>
            <thead>
            <tr>
                <th style="width:220px;">Tabel</th>
                <th style="width:120px;">Status</th>
                <th>Kolom Wajib</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($auditRows as $row): ?>
                <tr>
                    <td><code><?= htmlspecialchars($row['table']) ?></code></td>
                    <td><?= $row['exists'] ? '<span class="ok">OK</span>' : '<span class="bad">MISSING</span>' ?></td>
                    <td>
                        <?php foreach ($row['columns'] as $col): ?>
                            <div>
                                <code><?= htmlspecialchars($col['name']) ?></code>
                                <?= $col['exists'] ? '<span class="ok">OK</span>' : '<span class="bad">MISSING</span>' ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
