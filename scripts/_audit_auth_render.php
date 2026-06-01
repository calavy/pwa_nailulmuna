<?php
declare(strict_types=1);
ob_start();
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/config/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/helpers/app.php';
require_once $root . '/helpers/login_pembimbing.php';
require_once $root . '/helpers/rekap_keaktifan_hari.php';
require_once $root . '/helpers/pembimbing_dashboard.php';
ob_end_clean();

global $pdo;

function audit_set_user(PDO $pdo, string $username): bool
{
    $st = $pdo->prepare('SELECT id, nama, username, role, is_super_admin, foto_profil FROM users WHERE username = :u LIMIT 1');
    $st->execute(['u' => $username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $_SESSION['user'] = [
        'id' => (int) $row['id'],
        'nama' => (string) ($row['nama'] ?? $row['username']),
        'username' => (string) $row['username'],
        'role' => (string) $row['role'],
        'is_super_admin' => (int) ($row['is_super_admin'] ?? 0),
        'foto_profil' => (string) ($row['foto_profil'] ?? ''),
    ];
    return true;
}

function audit_fetch_page(string $relPath): array
{
    global $pdo;
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? $relPath;
    $path = realpath(dirname(__DIR__) . '/' . ltrim($relPath, '/'));
    if (!$path || !is_file($path)) {
        return ['status' => 'MISSING', 'title' => '', 'bytes' => 0, 'note' => 'file not found'];
    }
    ob_start();
    $note = '';
    try {
        include $path;
    } catch (Throwable $e) {
        $note = $e->getMessage();
    }
    $html = (string) ob_get_clean();
    if ($note !== '') {
        return ['status' => 'FATAL', 'title' => '', 'bytes' => strlen($html), 'note' => $note];
    }
    if (preg_match('/Fatal error|Parse error|Uncaught (?:Exception|Error)/i', $html)) {
        return ['status' => 'FATAL', 'title' => '', 'bytes' => strlen($html), 'note' => 'fatal in output'];
    }
    $title = '';
    if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
        $title = trim($m[1]);
    }
    $status = strlen($html) > 500 ? 'OK' : 'WARN';
    return ['status' => $status, 'title' => $title, 'bytes' => strlen($html), 'note' => ''];
}

$roles = ['admin' => 'admin', 'pembimbing' => 'PMB001', 'pengurus' => 'slamet'];
$pages = [
    '/dashboard.php','/yayasan/operasional.php','/yayasan/keaktifan.php','/yayasan/ringkasan.php',
    '/pembimbing/dashboard.php','/presensi/scan.php','/presensi/scan.php?portal=1','/rekap/keaktifan_hari.php',
    '/rekap/hub.php','/keuangan/index.php','/santri/index.php','/pembimbing/index.php','/jadwal/index.php',
    '/pkpps/index.php','/settings/profil.php','/yayasan/timeline.php','/pembimbing/nilai_manual.php',
    '/pembayaran/tagihan_syahriyah.php','/santri/riwayat.php?id=1',
];

echo "AUTH_RENDER\n";
foreach ($roles as $label => $username) {
    $_SESSION = [];
    if (!audit_set_user($pdo, $username)) {
        echo "SKIP|$label|$username\n";
        continue;
    }
    foreach ($pages as $page) {
        $r = audit_fetch_page($page);
        echo "$label|$username|$page|{$r['status']}|{$r['bytes']}|{$r['title']}|{$r['note']}\n";
    }
}

$today = date('Y-m-d');
try {
    $rows = rekap_keaktifan_hari_data($pdo, $today, null, null);
    $riwayat = rekap_keaktifan_hari_riwayat_pembimbing_masuk($pdo, $today);
    $kosong = rekap_keaktifan_hari_kegiatan_kosong($pdo, $today, null, null);
    echo "FLOW|keaktifan_data|" . count($rows) . "\n";
    echo "FLOW|riwayat_pb|" . count($riwayat) . "\n";
    echo "FLOW|kegiatan_kosong|" . count($kosong) . "\n";
} catch (Throwable $e) {
    echo "FLOW|keaktifan|FAIL|{$e->getMessage()}\n";
}

audit_set_user($pdo, 'PMB001');
$pbId = (int) ($_SESSION['user']['id'] ?? 0);
if ($pbId > 0 && function_exists('pembimbing_dashboard_hadir_hari_ini')) {
    $hadir = pembimbing_dashboard_hadir_hari_ini($pdo, $pbId) ? '1' : '0';
    echo "FLOW|pb_hadir_badge|$hadir\n";
}
