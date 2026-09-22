<?php

declare(strict_types=1);

require_once __DIR__ . '/offline_sync_dedup.php';

/**
 * Simpan input poin manual (online atau dari antrian offline).
 *
 * @param array<string,mixed> $post
 * @return array{ok:bool,type:string,message:string,ledger_id?:int}
 */
function poin_offline_submit(PDO $pdo, array $post, int $userId): array
{
    require_once __DIR__ . '/app.php';
    require_once __DIR__ . '/akademik.php';

    ensure_point_tables($pdo);
    ensure_akademik_libur_table($pdo);

    $clientUuid = offline_sync_client_uuid_from_post($post);
    if ($clientUuid !== '') {
        $idem = offline_sync_idempotent_response($pdo, $clientUuid, 'poin_input');
        if ($idem !== null && ($idem['handled'] ?? false)) {
            return [
                'ok' => ($idem['type'] ?? '') === 'success',
                'type' => (string) ($idem['type'] ?? 'success'),
                'message' => (string) ($idem['message'] ?? 'OK'),
            ];
        }
    }

    $santriId = (int) ($post['santri_id'] ?? 0);
    $jenis = strtoupper(trim((string) ($post['jenis_perubahan'] ?? 'PLUS')));
    $tanggal = (string) ($post['tanggal'] ?? date('Y-m-d'));
    $ruleId = (int) ($post['rule_id'] ?? 0);
    $customPoint = (int) ($post['point_custom'] ?? 0);
    $peringanId = (int) ($post['peringan_id'] ?? 0);
    $pemberatId = (int) ($post['pemberat_id'] ?? 0);
    $keterangan = trim((string) ($post['keterangan'] ?? ''));

    if ($santriId <= 0) {
        return ['ok' => false, 'type' => 'error', 'message' => 'Pilih santri terlebih dahulu.'];
    }
    if (!in_array($jenis, ['PLUS', 'MINUS'], true)) {
        $jenis = 'PLUS';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $point = 0;
    $ruleActive = true;
    $ruleRow = null;
    if ($ruleId > 0) {
        $ruleStmt = $pdo->prepare('SELECT id, nama_rule, bobot_poin, jenis_rule, is_active, kategori FROM point_rules WHERE id = :id');
        $ruleStmt->execute(['id' => $ruleId]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        $ruleRow = is_array($rule) ? $rule : null;
        if ($rule) {
            $ruleActive = (int) ($rule['is_active'] ?? 1) === 1;
            $ruleJenis = strtoupper((string) ($rule['jenis_rule'] ?? 'PLUS'));
            if ($ruleJenis !== $jenis) {
                return [
                    'ok' => false,
                    'type' => 'error',
                    'message' => 'Rule tidak sesuai jenis perubahan (' . ($jenis === 'MINUS' ? 'pengurangan' : 'penambahan') . ').',
                ];
            }
            if (!$ruleActive && $customPoint <= 0) {
                return [
                    'ok' => false,
                    'type' => 'warning',
                    'message' => 'Rule sudah nonaktif. Gunakan poin custom atau perbarui cache offline.',
                ];
            }
            $point = (int) $rule['bobot_poin'];
            if ($keterangan === '') {
                $keterangan = ($jenis === 'MINUS' ? 'Remedial: ' : 'Input rule: ') . (string) $rule['nama_rule'];
                if (!$ruleActive) {
                    $keterangan .= ' (rule nonaktif saat sync)';
                }
            }
        }
    }
    if ($customPoint > 0) {
        $point = $customPoint;
    }
    if ($point <= 0) {
        return ['ok' => false, 'type' => 'error', 'message' => 'Bobot poin harus lebih dari 0.'];
    }

    require_once __DIR__ . '/poin_calc.php';
    $pointBase = $point;
    if ($jenis === 'PLUS' && ($peringanId > 0 || $pemberatId > 0)) {
        if (!poin_rule_allows_modifier_row($ruleRow) && $ruleId > 0) {
            $peringanId = 0;
            $pemberatId = 0;
        } elseif ($ruleId <= 0 && $customPoint > 0 && $customPoint < 3) {
            $peringanId = 0;
            $pemberatId = 0;
        } else {
            $point = poin_apply_modifier(
                $pointBase,
                $peringanId > 0 ? $peringanId : null,
                $pemberatId > 0 ? $pemberatId : null,
                $pdo
            );
            if ($peringanId > 0 && table_exists($pdo, 'point_peringan')) {
                $nm = $pdo->prepare('SELECT nama FROM point_peringan WHERE id = :id');
                $nm->execute(['id' => $peringanId]);
                $n = trim((string) ($nm->fetchColumn() ?: ''));
                if ($n !== '') {
                    $keterangan = trim($keterangan . ($keterangan !== '' ? ' — ' : '') . 'Peringan: ' . $n);
                }
            }
            if ($pemberatId > 0 && table_exists($pdo, 'point_pemberat')) {
                $nm = $pdo->prepare('SELECT nama FROM point_pemberat WHERE id = :id');
                $nm->execute(['id' => $pemberatId]);
                $n = trim((string) ($nm->fetchColumn() ?: ''));
                if ($n !== '') {
                    $keterangan = trim($keterangan . ($keterangan !== '' ? ' — ' : '') . 'Pemberat: ' . $n);
                }
            }
        }
    }

    $liburN = akademik_libur_info($pdo, $tanggal, 'penilaian');
    if ($liburN !== null && akademik_blokir_penilaian_libur($pdo)) {
        return [
            'ok' => false,
            'type' => 'error',
            'message' => 'Tanggal libur: ' . $liburN['nama'] . ' — input poin tidak diizinkan.',
        ];
    }

    $delta = $jenis === 'MINUS' ? -$point : $point;
    $hasModifierCols = column_exists($pdo, 'point_ledger', 'point_base');
    if ($hasModifierCols) {
        $insert = $pdo->prepare('
            INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, point_base, peringan_id, pemberat_id, rule_id, sumber_data, keterangan, created_by)
            VALUES (:santri_id, :tanggal, :jenis_perubahan, :point_delta, :point_base, :peringan_id, :pemberat_id, :rule_id, "MANUAL", :keterangan, :created_by)
        ');
        $insert->execute([
            'santri_id' => $santriId,
            'tanggal' => $tanggal,
            'jenis_perubahan' => $jenis,
            'point_delta' => $delta,
            'point_base' => $jenis === 'PLUS' ? $pointBase : null,
            'peringan_id' => ($jenis === 'PLUS' && $peringanId > 0) ? $peringanId : null,
            'pemberat_id' => ($jenis === 'PLUS' && $pemberatId > 0) ? $pemberatId : null,
            'rule_id' => $ruleId > 0 ? $ruleId : null,
            'keterangan' => $keterangan !== '' ? $keterangan : ($jenis === 'MINUS' ? 'Pengurangan poin manual' : 'Penambahan poin manual'),
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    } else {
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
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    }
    $ledgerId = (int) $pdo->lastInsertId();

    if ($jenis === 'PLUS') {
        require_once __DIR__ . '/poin_wa.php';
        poin_wa_maybe_notify_santri($pdo, $santriId, $tanggal);
        require_once __DIR__ . '/push_events.php';
        push_maybe_pelanggaran_berat_after_point($pdo, $santriId);
    }

    if ($clientUuid !== '') {
        $clientAt = trim((string) ($post['client_created_at'] ?? ''));
        offline_sync_log_write($pdo, $clientUuid, 'poin_input', $userId, 'accepted', $ledgerId, $clientAt !== '' ? $clientAt : null);
    }

    return [
        'ok' => true,
        'type' => 'success',
        'message' => 'Input poin berhasil disimpan.',
        'ledger_id' => $ledgerId,
    ];
}

/**
 * @return array{ok:bool,version:string,santri:list<array>,point_rules:list<array>,generated_at:string}
 */
function poin_offline_reference_pack(PDO $pdo): array
{
    require_once __DIR__ . '/santri_list_sort.php';

    ensure_point_tables($pdo);
    $santriList = $pdo->query('SELECT id, nis, nama_santri, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll(PDO::FETCH_ASSOC);
    $ruleList = $pdo->query('SELECT id, kode_rule, kategori, nama_rule, bobot_poin, jenis_rule, contoh_pelanggaran FROM point_rules WHERE is_active = 1 ORDER BY urutan ASC, kategori ASC')->fetchAll(PDO::FETCH_ASSOC);
    $payload = json_encode(['santri' => $santriList, 'rules' => $ruleList], JSON_UNESCAPED_UNICODE);
    $version = substr(hash('sha256', $payload), 0, 16);

    return [
        'ok' => true,
        'version' => $version,
        'santri' => array_map(static fn($r) => [
            'id' => (int) ($r['id'] ?? 0),
            'nis' => (string) ($r['nis'] ?? ''),
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
        ], $santriList),
        'point_rules' => array_map(static fn($r) => [
            'id' => (int) ($r['id'] ?? 0),
            'kode_rule' => (string) ($r['kode_rule'] ?? ''),
            'kategori' => (string) ($r['kategori'] ?? ''),
            'nama_rule' => (string) ($r['nama_rule'] ?? ''),
            'bobot_poin' => (int) ($r['bobot_poin'] ?? 0),
            'jenis_rule' => (string) ($r['jenis_rule'] ?? 'PLUS'),
            'contoh_pelanggaran' => (string) ($r['contoh_pelanggaran'] ?? ''),
        ], $ruleList),
        'generated_at' => date('c'),
    ];
}
