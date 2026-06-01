<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../../helpers/pembimbing_nilai_manual.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$page = trim((string) ($_GET['page'] ?? ''));
$generatedAt = date('c');

try {
    switch ($page) {
        case 'keaktifan_hari': {
            require_roles(['admin', 'pengurus', 'kiai', 'pembimbing']);
            $tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
                $tanggal = date('Y-m-d');
            }
            $tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
            $rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatan !== '' ? $tingkatan : null);
            $detailKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);
            $ringkasan = rekap_keaktifan_hari_ringkasan_from_detail($detailKeg);
            echo json_encode([
                'ok' => true,
                'page' => $page,
                'title' => 'Keaktifan Hari Ini',
                'generated_at' => $generatedAt,
                'params' => ['tanggal' => $tanggal, 'tingkatan' => $tingkatan],
                'totals' => rekap_keaktifan_hari_totals($ringkasan),
                'ringkasan' => $ringkasan,
                'detail_kegiatan' => $detailKeg,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'nilai_manual_rekap': {
            require_roles(['admin', 'pengurus', 'pembimbing']);
            pembimbing_nilai_manual_ensure_schema($pdo);
            $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
            $userId = (int) ($_SESSION['user']['id'] ?? 0);
            $bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
            $pbAktif = pembimbing_dashboard_current_pembimbing($pdo, $userId);
            $pembimbingIdAktif = (int) ($pbAktif['id'] ?? 0);
            $filterPb = $bolehSemua ? max(0, (int) ($_GET['pembimbing_id'] ?? 0)) : $pembimbingIdAktif;
            $where = 'WHERE t.is_aktif = 1';
            $params = [];
            if ($filterPb > 0) {
                $where .= ' AND t.pembimbing_id = :pid';
                $params['pid'] = $filterPb;
            }
            $st = $pdo->prepare('
                SELECT t.id, t.judul, t.aspek, t.tanggal_mulai, t.tanggal_selesai, p.nama_pembimbing,
                       (SELECT COUNT(*) FROM pembimbing_nilai_manual n WHERE n.target_id = t.id) AS jumlah_nilai
                FROM pembimbing_penilaian_target t
                INNER JOIN pembimbing p ON p.id = t.pembimbing_id
                ' . $where . '
                ORDER BY t.tanggal_mulai DESC, t.id DESC
                LIMIT 200
            ');
            $st->execute($params);
            echo json_encode([
                'ok' => true,
                'page' => $page,
                'title' => 'Rekapan Nilai Manual',
                'generated_at' => $generatedAt,
                'params' => ['pembimbing_id' => $filterPb],
                'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'tugas_ikhtibar_rekap': {
            ikhtibar_require_pembimbing_access();
            ensure_akademik_ikhtibar_tables($pdo);
            $userId = (int) ($_SESSION['user']['id'] ?? 0);
            $rows = ikhtibar_rekap_tugas_pembimbing($pdo, $userId);
            echo json_encode([
                'ok' => true,
                'page' => $page,
                'title' => 'Rekap Tugas Ikhtibar',
                'generated_at' => $generatedAt,
                'rows' => $rows,
                'summary' => [
                    'total_tugas' => count($rows),
                    'total_selesai' => array_sum(array_map(static fn ($r) => (int) ($r['jumlah_selesai'] ?? 0), $rows)),
                    'esai_belum' => array_sum(array_map(static fn ($r) => (int) ($r['esai_belum_koreksi'] ?? 0), $rows)),
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Halaman rekap tidak dikenali.'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat data rekap.'], JSON_UNESCAPED_UNICODE);
}
