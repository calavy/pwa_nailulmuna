<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/wa_templates.php';
require_once __DIR__ . '/yayasan_timeline.php';
require_once __DIR__ . '/app_path.php';

function yayasan_tugas_wa_enabled(PDO $pdo): bool
{
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return false;
    }

    return trim((string) app_setting($pdo, 'wa_yayasan_tugas_enabled', '1')) === '1';
}

function yayasan_tugas_wa_noprogress_enabled(PDO $pdo): bool
{
    if (!yayasan_tugas_wa_enabled($pdo)) {
        return false;
    }

    return trim((string) app_setting($pdo, 'wa_yayasan_tugas_noprogress_enabled', '1')) === '1';
}

function yayasan_tugas_pic_wa_phone(PDO $pdo, int $picUserId): string
{
    if ($picUserId <= 0 || !table_exists($pdo, 'users')) {
        return '';
    }
    $st = $pdo->prepare('SELECT username, no_wa, nama FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $picUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }
    $phone = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
    if ($phone !== '') {
        return $phone;
    }
    if (!table_exists($pdo, 'pembimbing')) {
        return '';
    }
    $nip = trim((string) ($row['username'] ?? ''));
    if ($nip === '') {
        return '';
    }
    $stPb = $pdo->prepare('SELECT no_wa FROM pembimbing WHERE TRIM(nip) = :nip LIMIT 1');
    $stPb->execute(['nip' => $nip]);
    $waPb = trim((string) ($stPb->fetchColumn() ?: ''));

    return normalize_wa_phone($waPb);
}

/**
 * @param array<string, mixed> $task
 * @return array<string, string>
 */
function yayasan_tugas_wa_template_vars(PDO $pdo, array $task, string $namaPenerima = '', string $peran = 'PJ'): array
{
    $link = rtrim(app_public_url(), '/') . app_href('/pembimbing/tugas_yayasan.php?id=' . (int) ($task['id'] ?? 0));
    $desk = trim((string) ($task['deskripsi'] ?? ''));
    $pjLine = trim((string) ($task['pj_nama'] ?? $task['pic_nama'] ?? $task['penanggung_jawab'] ?? ''));
    $pembantuLine = trim((string) ($task['pembantu_nama'] ?? ''));
    $timLine = '';
    if ($pjLine !== '') {
        $timLine .= 'PJ: ' . $pjLine;
    }
    if ($pembantuLine !== '') {
        $timLine .= ($timLine !== '' ? "\n" : '') . 'Pembantu: ' . $pembantuLine;
    }

    return [
        'nama_pembimbing' => $namaPenerima !== '' ? $namaPenerima : ($pjLine !== '' ? $pjLine : 'Pembimbing'),
        'peran' => $peran === 'PEMBANTU' ? 'Pembantu' : 'Penanggung Jawab (PJ)',
        'tim_penugasan' => $timLine !== '' ? $timLine . "\n" : '',
        'judul_tugas' => (string) ($task['judul'] ?? ''),
        'kategori' => (string) ($task['category'] ?? 'Yayasan'),
        'tanggal_mulai' => (string) ($task['start_at'] ?? $task['tanggal_mulai'] ?? ''),
        'tanggal_tenggat' => (string) ($task['due_at'] ?? $task['tanggal_target'] ?? ''),
        'deskripsi' => $desk !== '' ? 'Catatan: ' . $desk . "\n" : '',
        'link_tugas' => $link,
        'nama_ponpes' => app_brand_nama_ponpes($pdo),
        'progres' => (string) ((int) ($task['progress'] ?? 0)) . '%',
    ];
}

/**
 * Kirim WA penugasan / perubahan ke semua PJ & pembantu.
 *
 * @param 'baru'|'ubah' $jenis
 */
function yayasan_tugas_wa_notify_assignees(PDO $pdo, int $taskId, string $jenis = 'baru'): int
{
    if (!yayasan_tugas_wa_enabled($pdo) || wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }
    $task = yayasan_tugas_get($pdo, $taskId);
    if ($task === null) {
        return 0;
    }
    $slug = $jenis === 'ubah' ? 'yayasan_tugas_diubah' : 'yayasan_tugas_baru';
    $sent = 0;
    $recipients = array_merge(
        array_map(static fn(array $p): array => ['id' => (int) ($p['id'] ?? 0), 'nama' => (string) ($p['nama'] ?? ''), 'peran' => 'PJ'], (array) ($task['pj_list'] ?? [])),
        array_map(static fn(array $p): array => ['id' => (int) ($p['id'] ?? 0), 'nama' => (string) ($p['nama'] ?? ''), 'peran' => 'PEMBANTU'], (array) ($task['pembantu_list'] ?? []))
    );
    if ($recipients === [] && (int) ($task['pic_id'] ?? 0) > 0) {
        $recipients[] = [
            'id' => (int) $task['pic_id'],
            'nama' => (string) ($task['pic_nama'] ?? ''),
            'peran' => 'PJ',
        ];
    }
    foreach ($recipients as $rcp) {
        $uid = (int) ($rcp['id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $phone = yayasan_tugas_pic_wa_phone($pdo, $uid);
        if ($phone === '') {
            continue;
        }
        $vars = yayasan_tugas_wa_template_vars($pdo, $task, (string) ($rcp['nama'] ?? ''), (string) ($rcp['peran'] ?? 'PJ'));
        $pesan = wa_template_render($pdo, $slug, $vars);
        if (trim($pesan) === '') {
            continue;
        }
        if (send_wa_message($pdo, $phone, $pesan, ['kind' => 'presensi'])) {
            $sent++;
        }
    }

    return $sent;
}

/** @deprecated gunakan yayasan_tugas_wa_notify_assignees */
function yayasan_tugas_wa_notify_pic(PDO $pdo, int $taskId, string $jenis = 'baru'): bool
{
    return yayasan_tugas_wa_notify_assignees($pdo, $taskId, $jenis) > 0;
}

/**
 * Pengingat WA jika tugas sudah dimulai tetapi progres masih 0%.
 */
function trigger_wa_yayasan_tugas_belum_progres(PDO $pdo): void
{
    if (!yayasan_tugas_wa_noprogress_enabled($pdo) || wa_otomatis_gateway_error($pdo) !== null) {
        return;
    }

    yayasan_timeline_ensure_schema($pdo);
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $jamSetelahMulai = max(1, min(72, (int) app_setting($pdo, 'wa_yayasan_tugas_noprogress_jam', '6')));

    $sql = '
        SELECT DISTINCT t.*
        FROM yayasan_tugas t
        LEFT JOIN yayasan_tugas_assignee a ON a.tugas_id = t.id
        WHERE t.progress = 0
          AND t.status <> "SELESAI"
          AND (t.pic_id IS NOT NULL AND t.pic_id > 0 OR a.user_id IS NOT NULL)
          AND COALESCE(t.start_at, CONCAT(t.tanggal_mulai, " 08:00:00")) <= :now_cutoff
          AND COALESCE(t.due_at, CONCAT(t.tanggal_target, " 17:00:00")) >= :now
        ORDER BY t.due_at ASC
        LIMIT 80
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'now_cutoff' => date('Y-m-d H:i:s', strtotime($now . ' -' . $jamSetelahMulai . ' hours')),
        'now' => $now,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $taskId = (int) ($row['id'] ?? 0);
        if ($taskId <= 0) {
            continue;
        }
        $task = yayasan_tugas_enrich_row($pdo, $row);
        $recipients = array_merge(
            (array) ($task['pj_list'] ?? []),
            (array) ($task['pembantu_list'] ?? [])
        );
        if ($recipients === [] && (int) ($task['pic_id'] ?? 0) > 0) {
            $recipients[] = ['id' => (int) $task['pic_id'], 'nama' => (string) ($task['pic_nama'] ?? '')];
        }
        foreach ($recipients as $rcp) {
            $uid = (int) ($rcp['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $debounceKey = 'wa_yt_noprogress_' . $today . '_' . $taskId . '_' . $uid;
            if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
                continue;
            }
            $phone = yayasan_tugas_pic_wa_phone($pdo, $uid);
            if ($phone === '') {
                continue;
            }
            $peran = 'PJ';
            foreach ((array) ($task['pembantu_list'] ?? []) as $pb) {
                if ((int) ($pb['id'] ?? 0) === $uid) {
                    $peran = 'PEMBANTU';
                    break;
                }
            }
            $vars = yayasan_tugas_wa_template_vars($pdo, $task, (string) ($rcp['nama'] ?? ''), $peran);
            $pesan = wa_template_render($pdo, 'yayasan_tugas_belum_progres', $vars);
            if (trim($pesan) === '') {
                continue;
            }
            if (send_wa_message($pdo, $phone, $pesan, ['kind' => 'presensi'])) {
                save_setting($pdo, $debounceKey, '1');
            }
        }
    }
}
