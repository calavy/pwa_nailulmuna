<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/wa_nomor.php';

wa_nomor_ensure_schema($pdo);

$peranDefs = wa_nomor_peran_definitions();
$filterPeran = trim((string) ($_GET['peran'] ?? ''));
if ($filterPeran !== '' && !isset($peranDefs[$filterPeran])) {
    $filterPeran = '';
}

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save') {
        $res = wa_nomor_save($pdo, [
            'id' => (int) ($_POST['id'] ?? 0),
            'nama' => (string) ($_POST['nama'] ?? ''),
            'no_wa' => (string) ($_POST['no_wa'] ?? ''),
            'peran' => (array) ($_POST['peran'] ?? []),
            'catatan' => (string) ($_POST['catatan'] ?? ''),
            'is_aktif' => isset($_POST['is_aktif']),
            'urutan' => (int) ($_POST['urutan'] ?? 0),
        ]);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        $redirect = '/settings/wa_akun.php';
        if ($filterPeran !== '') {
            $redirect .= '?peran=' . rawurlencode($filterPeran);
        }
        header('Location: ' . app_href($redirect));
        exit;
    }

    if ($action === 'delete') {
        $res = wa_nomor_delete($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/settings/wa_akun.php' . ($filterPeran !== '' ? '?peran=' . rawurlencode($filterPeran) : '')));
        exit;
    }

    if ($action === 'toggle') {
        $res = wa_nomor_toggle($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/settings/wa_akun.php' . ($filterPeran !== '' ? '?peran=' . rawurlencode($filterPeran) : '')));
        exit;
    }

    if ($action === 'sync_settings') {
        wa_nomor_sync_settings($pdo);
        set_flash('success', 'Pengaturan WA disinkronkan dari daftar nomor.');
        header('Location: ' . app_href('/settings/wa_akun.php'));
        exit;
    }
}

$kontakList = wa_nomor_list($pdo, $filterPeran !== '' ? $filterPeran : null);
$peranCounts = wa_nomor_count_by_peran($pdo);
$totalKontak = count(wa_nomor_list($pdo));
$totalAktif = count(wa_nomor_list($pdo, null, true));

if ($editId > 0) {
    $editRow = wa_nomor_get($pdo, $editId);
}

// Grup peran untuk tampilan form
$peranGroups = [];
foreach ($peranDefs as $key => $meta) {
    $group = (string) ($meta['group'] ?? 'Lainnya');
    if (!isset($peranGroups[$group])) {
        $peranGroups[$group] = [];
    }
    $peranGroups[$group][$key] = $meta;
}
