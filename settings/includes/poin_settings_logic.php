<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';

require_roles(['admin', 'pengurus']);
ensure_point_tables($pdo);

require_once __DIR__ . '/../../helpers/keaktifan_alpa_tanpa_scan.php';
keaktifan_alpa_tanpa_scan_redirect_if_saved($pdo);

$poinPostActions = ['save_auto', 'add_rule', 'delete_rule', 'add_sanction', 'delete_sanction'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, $poinPostActions, true)) {
    if ($action === 'save_auto') {
        save_setting($pdo, 'point_auto_alpa', (string) max(0, (int) ($_POST['point_auto_alpa'] ?? 5)));
        save_setting($pdo, 'point_auto_telat', (string) max(0, (int) ($_POST['point_auto_telat'] ?? 1)));
        set_flash('success', 'Setting auto poin presensi berhasil disimpan.');
    } elseif ($action === 'add_rule') {
        $jenisRule = strtoupper(trim((string) ($_POST['jenis_rule'] ?? 'PLUS')));
        if (!in_array($jenisRule, ['PLUS', 'MINUS'], true)) {
            $jenisRule = 'PLUS';
        }
        $insert = $pdo->prepare('
            INSERT INTO point_rules (kode_rule, kategori, nama_rule, bobot_poin, jenis_rule, contoh_pelanggaran, urutan)
            VALUES (:kode_rule, :kategori, :nama_rule, :bobot_poin, :jenis_rule, :contoh_pelanggaran, :urutan)
        ');
        $insert->execute([
            'kode_rule' => trim((string) ($_POST['kode_rule'] ?? 'RULE_' . date('His'))),
            'kategori' => trim((string) ($_POST['kategori'] ?? 'Lainnya')),
            'nama_rule' => trim((string) ($_POST['nama_rule'] ?? 'Rule baru')),
            'bobot_poin' => (int) ($_POST['bobot_poin'] ?? 1),
            'jenis_rule' => $jenisRule,
            'contoh_pelanggaran' => trim((string) ($_POST['contoh_pelanggaran'] ?? '')),
            'urutan' => (int) ($_POST['urutan'] ?? 0),
        ]);
        set_flash('success', 'Rule poin ' . ($jenisRule === 'MINUS' ? 'pengurangan' : 'penambahan') . ' berhasil ditambahkan.');
    } elseif ($action === 'delete_rule') {
        $del = $pdo->prepare('DELETE FROM point_rules WHERE id = :id');
        $del->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        set_flash('success', 'Rule poin berhasil dihapus.');
    } elseif ($action === 'add_sanction') {
        $insert = $pdo->prepare('
            INSERT INTO point_sanctions (ambang_poin, tindakan, urutan)
            VALUES (:ambang_poin, :tindakan, :urutan)
        ');
        $insert->execute([
            'ambang_poin' => (int) ($_POST['ambang_poin'] ?? 0),
            'tindakan' => trim((string) ($_POST['tindakan'] ?? '')),
            'urutan' => (int) ($_POST['urutan'] ?? 0),
        ]);
        set_flash('success', 'Ambang sanksi berhasil ditambahkan.');
    } elseif ($action === 'delete_sanction') {
        $del = $pdo->prepare('DELETE FROM point_sanctions WHERE id = :id');
        $del->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        set_flash('success', 'Ambang sanksi berhasil dihapus.');
    }
    header('Location: ' . app_href('/settings/peraturan.php'));
    exit;
    }
}

$rules = $pdo->query('SELECT * FROM point_rules ORDER BY urutan ASC, id ASC')->fetchAll();
$sanctions = $pdo->query('SELECT * FROM point_sanctions ORDER BY ambang_poin ASC')->fetchAll();
$pointAutoAlpa = (int) app_setting($pdo, 'point_auto_alpa', '5');
$pointAutoTelat = (int) app_setting($pdo, 'point_auto_telat', '1');
$totalRules = count($rules);
$totalSanctions = count($sanctions);
