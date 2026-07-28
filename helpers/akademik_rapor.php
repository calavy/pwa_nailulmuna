<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik_rapor_pdf.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/rekap_periode.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/rekap_keaktifan.php';
require_once __DIR__ . '/hijri_kalender.php';

function ensure_akademik_rapor_columns(PDO $pdo): void
{
    ensure_akademik_rapor_table($pdo);
    akademik_add_column($pdo, 'akademik_rapor', 'periode_mode', "VARCHAR(20) NULL DEFAULT 'hijriyah'");
    akademik_add_column($pdo, 'akademik_rapor', 'periode_bulan', 'TINYINT UNSIGNED NULL');
    akademik_add_column($pdo, 'akademik_rapor', 'periode_tahun', 'SMALLINT UNSIGNED NULL');
    akademik_add_column($pdo, 'akademik_rapor', 'pdf_path', 'VARCHAR(255) NULL');
    akademik_add_column($pdo, 'akademik_rapor', 'pdf_original_name', 'VARCHAR(200) NULL');
    akademik_add_column($pdo, 'akademik_rapor', 'jenis_rapor', "VARCHAR(20) NOT NULL DEFAULT 'pesantren'");
    akademik_add_column($pdo, 'akademik_rapor', 'wa_terbit_sent_at', 'DATETIME NULL');
    if (app_setting($pdo, 'akademik_rapor_jenis_migrated', '') !== '1' && table_exists($pdo, 'akademik_rapor')) {
        try {
            $pdo->exec("UPDATE akademik_rapor SET jenis_rapor = 'pesantren' WHERE jenis_rapor IS NULL OR TRIM(jenis_rapor) = ''");
        } catch (Throwable $e) {
        }
        save_setting($pdo, 'akademik_rapor_jenis_migrated', '1');
    }
}

/** @return list<string> */
function akademik_rapor_jenis_opsi(): array
{
    return ['pesantren', 'pkpps'];
}

function akademik_rapor_jenis_normalize(string $raw): string
{
    $j = strtolower(trim($raw));

    return in_array($j, akademik_rapor_jenis_opsi(), true) ? $j : 'pesantren';
}

function akademik_rapor_jenis_label(string $jenis): string
{
    return akademik_rapor_jenis_normalize($jenis) === 'pkpps' ? 'PKPPS' : 'Pesantren';
}

function akademik_rapor_admin_base_path(string $jenis): string
{
    return akademik_rapor_jenis_normalize($jenis) === 'pkpps' ? '/pkpps/rapor.php' : '/akademik/rapor.php';
}

function akademik_rapor_pengaturan_path(string $jenis): string
{
    return akademik_rapor_jenis_normalize($jenis) === 'pkpps'
        ? '/pkpps/pengaturan_rapor.php'
        : '/settings/surat_cetak.php?tab=template';
}

/**
 * @return list<array<string, mixed>>
 */
function akademik_rapor_santri_list_for_jenis(PDO $pdo, string $jenis): array
{
    $jenis = akademik_rapor_jenis_normalize($jenis);
    if ($jenis === 'pkpps') {
        if (!table_exists($pdo, 'pkpps_santri')) {
            return [];
        }
        pkpps_ensure_schema($pdo);
        require_once __DIR__ . '/santri_list_sort.php';
        $order = santri_list_order_sql('s');
        $sql = "
            SELECT s.id, s.nis, s.nama_santri, COALESCE(pt.nama_tingkatan, s.tingkatan, '') AS tingkatan
            FROM pkpps_santri ps
            INNER JOIN santri s ON s.id = ps.santri_id
            INNER JOIN pkpps_tingkatan pt ON pt.id = ps.pkpps_tingkatan_id
            WHERE ps.is_aktif = 1
        ";
        if (column_exists($pdo, 'santri', 'is_aktif')) {
            $sql .= ' AND COALESCE(s.is_aktif, 1) = 1';
        }
        $sql .= ' ORDER BY ' . $order . ' LIMIT 600';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    require_once __DIR__ . '/santri_list_sort.php';
    $sql = 'SELECT id, nis, nama_santri';
    if (column_exists($pdo, 'santri', 'tingkatan')) {
        $sql .= ', tingkatan';
    }
    $sql .= ' FROM santri';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $sql .= ' ORDER BY ' . santri_list_order_sql('santri') . ' LIMIT 600';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function akademik_rapor_santri_valid_for_jenis(PDO $pdo, int $santriId, string $jenis): bool
{
    if ($santriId <= 0) {
        return false;
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    if ($jenis === 'pkpps') {
        if (!function_exists('santri_portal_pkpps_aktif')) {
            require_once __DIR__ . '/akademik_pkpps_tugas.php';
        }

        return santri_portal_pkpps_aktif($pdo, $santriId);
    }
    $st = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (bool) $st->fetchColumn();
}

/**
 * @return array<string, mixed>|null
 */
function akademik_rapor_fetch_edit_row(PDO $pdo, int $editId, string $jenis): ?array
{
    if ($editId <= 0) {
        return null;
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    $st = $pdo->prepare('SELECT * FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
    $st->execute(['id' => $editId, 'j' => $jenis]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function akademik_rapor_list_for_jenis(PDO $pdo, string $jenis, int $filterSantri = 0): array
{
    $jenis = akademik_rapor_jenis_normalize($jenis);
    $sql = '
        SELECT r.*, s.nis, s.nama_santri, s.no_wa_wali
        FROM akademik_rapor r
        INNER JOIN santri s ON s.id = r.santri_id
        WHERE r.jenis_rapor = :jenis
    ';
    $params = ['jenis' => $jenis];
    if ($filterSantri > 0) {
        $sql .= ' AND r.santri_id = :fid';
        $params['fid'] = $filterSantri;
    }
    $sql .= ' ORDER BY r.tanggal_terbit DESC, r.id DESC LIMIT 100';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Muat konten rapor (presensi, setoran, tugas) sesuai jenis.
 *
 * @param array<string, mixed> $row
 * @return array{periode:array,periode_label:string,presensi:?array,setoran:list,tugas:list,jenis:string,section_labels:array<string,string>}
 */
function akademik_rapor_konten_from_row(PDO $pdo, array $row): array
{
    $jenis = akademik_rapor_jenis_normalize((string) ($row['jenis_rapor'] ?? 'pesantren'));
    $santriId = (int) ($row['santri_id'] ?? 0);
    $periode = rapor_periode_dari_row($pdo, $row);
    $tugasSumber = $jenis === 'pkpps' ? 'PKPPS' : null;
    $sectionLabels = [
        'presensi' => 'Presensi bulanan',
        'setoran' => 'Setoran hafalan',
        'tugas' => 'Hasil tugas (Ikhtibar) per pembimbing',
    ];
    if ($jenis === 'pkpps') {
        if (!function_exists('pkpps_rapor_section_labels')) {
            require_once __DIR__ . '/pkpps_rapor.php';
        }
        $sectionLabels = pkpps_rapor_section_labels($pdo);
    }

    return [
        'periode' => $periode,
        'periode_label' => (string) $periode['label'],
        'presensi' => rapor_presensi_bulan($pdo, $santriId, $periode),
        'setoran' => rapor_setoran_bulan($pdo, $santriId, $periode),
        'tugas' => rapor_tugas_bulan($pdo, $santriId, $periode, $tugasSumber),
        'jenis' => $jenis,
        'section_labels' => $sectionLabels,
    ];
}

/**
 * Proses POST admin rapor; redirect & exit jika ada aksi.
 */
function akademik_rapor_process_admin_post(PDO $pdo, string $jenis, string $basePath): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    $basePath = trim($basePath) !== '' ? $basePath : akademik_rapor_admin_base_path($jenis);
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'hapus_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        if ($rid > 0) {
            $oldSt = $pdo->prepare('SELECT pdf_path FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
            $oldSt->execute(['id' => $rid, 'j' => $jenis]);
            $oldPdf = trim((string) ($oldSt->fetchColumn() ?: ''));
            $pdo->prepare('DELETE FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j')->execute(['id' => $rid, 'j' => $jenis]);
            if ($oldPdf !== '') {
                akademik_rapor_pdf_delete_file($oldPdf);
            }
            set_flash('success', 'Rapor dihapus.');
        }
        header('Location: ' . app_href($basePath));
        exit;
    }

    if ($action === 'upload_pdf_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        if ($rid <= 0) {
            set_flash('error', 'Rapor tidak valid.');
            header('Location: ' . app_href($basePath));
            exit;
        }
        $chk = $pdo->prepare('SELECT id, pdf_path FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
        $chk->execute(['id' => $rid, 'j' => $jenis]);
        $curRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$curRow) {
            set_flash('error', 'Rapor tidak ditemukan.');
            header('Location: ' . app_href($basePath));
            exit;
        }
        $oldPdf = trim((string) ($curRow['pdf_path'] ?? ''));
        $upload = akademik_rapor_pdf_handle_upload($_FILES['pdf_file'] ?? [], $rid, $oldPdf !== '' ? $oldPdf : null);
        if (!$upload['ok']) {
            set_flash('error', (string) ($upload['error'] ?? 'Upload PDF gagal.'));
            header('Location: ' . app_href($basePath . '?edit=' . $rid . '#rapor-form'));
            exit;
        }
        if (!empty($upload['path'])) {
            $pdo->prepare('UPDATE akademik_rapor SET pdf_path = :p, pdf_original_name = :o WHERE id = :id AND jenis_rapor = :j')->execute([
                'p' => (string) $upload['path'],
                'o' => (string) ($upload['original_name'] ?? 'rapor.pdf'),
                'id' => $rid,
                'j' => $jenis,
            ]);
            $flash = 'PDF rapor berhasil diunggah.';
            $pubSt = $pdo->prepare('SELECT is_published FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
            $pubSt->execute(['id' => $rid, 'j' => $jenis]);
            $isPub = (int) ($pubSt->fetchColumn() ?: 0) === 1;
            if ($isPub) {
                $flash .= ' Wali dapat melihat di portal.';
                $waMsg = akademik_rapor_wa_kirim_saat_terbit($pdo, $rid, $jenis, true);
                if ($waMsg !== '') {
                    $flash .= ' ' . $waMsg;
                }
                set_flash('success', $flash);
            } else {
                set_flash('warning', $flash . ' Rapor masih Draft — klik Terbitkan agar wali bisa melihat di portal.');
            }
        }
        header('Location: ' . app_href($basePath . '?edit=' . $rid . '#rapor-form'));
        exit;
    }

    if ($action === 'hapus_pdf_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        if ($rid > 0) {
            $cur = $pdo->prepare('SELECT pdf_path FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
            $cur->execute(['id' => $rid, 'j' => $jenis]);
            $oldPdf = trim((string) ($cur->fetchColumn() ?: ''));
            if ($oldPdf !== '') {
                akademik_rapor_pdf_delete_file($oldPdf);
                $pdo->prepare('UPDATE akademik_rapor SET pdf_path = NULL, pdf_original_name = NULL WHERE id = :id AND jenis_rapor = :j')->execute(['id' => $rid, 'j' => $jenis]);
                set_flash('success', 'PDF rapor dihapus.');
            }
        }
        header('Location: ' . app_href($basePath . '?edit=' . $rid . '#rapor-form'));
        exit;
    }

    if ($action === 'terbitkan_rapor') {
        $rid = (int) ($_POST['rapor_id'] ?? 0);
        if ($rid <= 0) {
            set_flash('error', 'Rapor tidak valid.');
            header('Location: ' . app_href($basePath));
            exit;
        }
        $own = $pdo->prepare('SELECT id, is_published FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
        $own->execute(['id' => $rid, 'j' => $jenis]);
        $prevRow = $own->fetch(PDO::FETCH_ASSOC);
        if (!$prevRow) {
            set_flash('error', 'Rapor tidak ditemukan.');
            header('Location: ' . app_href($basePath));
            exit;
        }
        $wasPublished = (int) ($prevRow['is_published'] ?? 0) === 1;
        $pdo->prepare('UPDATE akademik_rapor SET is_published = 1 WHERE id = :id AND jenis_rapor = :j')->execute(['id' => $rid, 'j' => $jenis]);
        $messages = ['Rapor diterbitkan ke portal wali.'];
        $waMsg = akademik_rapor_wa_kirim_saat_terbit($pdo, $rid, $jenis, $wasPublished);
        if ($waMsg !== '') {
            $messages[] = $waMsg;
        }
        set_flash('success', implode(' ', $messages));
        header('Location: ' . app_href($basePath . '?santri_id=' . (int) ($_POST['santri_id'] ?? 0)));
        exit;
    }

    if ($action !== 'simpan_rapor') {
        return;
    }

    $rid = (int) ($_POST['rapor_id'] ?? 0);
    $sid = (int) ($_POST['santri_id'] ?? 0);
    $judul = trim((string) ($_POST['judul_periode'] ?? ''));
    $tgl = trim((string) ($_POST['tanggal_terbit'] ?? ''));
    $narasi = trim((string) ($_POST['narasi'] ?? ''));
    $pred = trim((string) ($_POST['predikat_akhlak'] ?? ''));
    $cat = trim((string) ($_POST['catatan_pondok'] ?? ''));
    $published = isset($_POST['is_published']) ? 1 : 0;
    $periodeMode = strtolower(trim((string) ($_POST['periode_mode'] ?? 'hijriyah')));
    if (!in_array($periodeMode, ['masehi', 'hijriyah'], true)) {
        $periodeMode = 'hijriyah';
    }
    $periodeBulan = max(1, min(12, (int) ($_POST['periode_bulan'] ?? 0)));
    $periodeTahun = (int) ($_POST['periode_tahun'] ?? 0);
    if ($periodeBulan < 1 || $periodeTahun < 1) {
        $defP = rapor_periode_default_dari_tanggal($pdo, $tgl);
        $periodeMode = $defP['mode'];
        $periodeBulan = $defP['month'];
        $periodeTahun = $defP['year'];
    }

    $redirectEdit = $basePath . ($rid > 0 ? '?edit=' . $rid : '');
    if ($sid <= 0 || $judul === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        set_flash('error', 'Santri, judul periode, dan tanggal terbit wajib valid.');
        header('Location: ' . app_rewrite_internal_url($redirectEdit));
        exit;
    }

    ensure_akademik_libur_table($pdo);
    $liburN = akademik_libur_info($pdo, $tgl, 'penilaian');
    if ($liburN !== null && akademik_blokir_penilaian_libur($pdo)) {
        set_flash('error', 'Tanggal terbit pada hari libur: ' . $liburN['nama'] . ' — tidak disimpan.');
        header('Location: ' . app_rewrite_internal_url($redirectEdit));
        exit;
    }

    if (!akademik_rapor_santri_valid_for_jenis($pdo, $sid, $jenis)) {
        $msg = $jenis === 'pkpps'
            ? 'Santri harus terdaftar aktif di program PKPPS.'
            : 'Santri tidak ditemukan.';
        set_flash('error', $msg);
        header('Location: ' . app_rewrite_internal_url($redirectEdit));
        exit;
    }

    $uid = (int) ($_SESSION['user']['id'] ?? 0) ?: null;
    $wasPublished = 0;
    $oldPdfPath = '';
    $isUpdate = $rid > 0;
    $payload = [
        'sid' => $sid,
        'judul' => mb_substr($judul, 0, 160),
        'tgl' => $tgl,
        'pm' => $periodeMode,
        'pb' => $periodeBulan,
        'pt' => $periodeTahun,
        'nar' => $narasi !== '' ? $narasi : null,
        'pred' => $pred !== '' ? mb_substr($pred, 0, 100) : null,
        'cat' => $cat !== '' ? $cat : null,
        'pub' => $published,
        'jenis' => $jenis,
    ];

    if ($isUpdate) {
        $own = $pdo->prepare('SELECT id, is_published, pdf_path FROM akademik_rapor WHERE id = :id AND jenis_rapor = :j LIMIT 1');
        $own->execute(['id' => $rid, 'j' => $jenis]);
        $prevRow = $own->fetch(PDO::FETCH_ASSOC);
        if (!$prevRow) {
            set_flash('error', 'Rapor tidak ditemukan untuk jenis ini.');
            header('Location: ' . app_href($basePath));
            exit;
        }
        $wasPublished = (int) ($prevRow['is_published'] ?? 0);
        $oldPdfPath = trim((string) ($prevRow['pdf_path'] ?? ''));
        $clearWaSent = $published === 0 ? ', wa_terbit_sent_at = NULL' : '';
        $pdo->prepare('
            UPDATE akademik_rapor SET
                santri_id = :sid, judul_periode = :judul, tanggal_terbit = :tgl,
                periode_mode = :pm, periode_bulan = :pb, periode_tahun = :pt,
                narasi = :nar, predikat_akhlak = :pred, catatan_pondok = :cat, is_published = :pub' . $clearWaSent . '
            WHERE id = :id AND jenis_rapor = :jenis
        ')->execute($payload + ['id' => $rid]);
    } else {
        $pdo->prepare('
            INSERT INTO akademik_rapor (
                santri_id, judul_periode, tanggal_terbit, periode_mode, periode_bulan, periode_tahun,
                narasi, predikat_akhlak, catatan_pondok, is_published, jenis_rapor, created_by
            ) VALUES (
                :sid, :judul, :tgl, :pm, :pb, :pt, :nar, :pred, :cat, :pub, :jenis, :uid
            )
        ')->execute($payload + ['uid' => $uid]);
        $rid = (int) $pdo->lastInsertId();
    }

    $messages = [$isUpdate ? 'Rapor diperbarui.' : 'Rapor ditambahkan.'];
    $flashType = 'success';

    $pdfResult = akademik_rapor_apply_pdf_upload_from_request($pdo, $rid, $jenis, $oldPdfPath !== '' ? $oldPdfPath : null);
    if ($pdfResult['message'] !== '') {
        $messages[] = $pdfResult['message'];
    }
    if (!$pdfResult['ok']) {
        $flashType = 'error';
    }

    if ($published === 1) {
        $waMsg = akademik_rapor_wa_kirim_saat_terbit($pdo, $rid, $jenis, $wasPublished === 1);
        if ($waMsg !== '') {
            $messages[] = $waMsg;
        }
    } elseif ($pdfResult['ok'] && !empty($pdfResult['message'])) {
        $messages[] = 'Belum diterbitkan — wali belum bisa melihat di portal.';
        $flashType = 'warning';
    }

    set_flash($flashType, implode(' ', $messages));
    header('Location: ' . app_rewrite_internal_url($basePath . '?edit=' . $rid . '#rapor-form'));
    exit;
}

/**
 * @return array{ok:bool, message:string}
 */
function akademik_rapor_apply_pdf_upload_from_request(PDO $pdo, int $raporId, string $jenis, ?string $oldRelativePath = null): array
{
    if ($raporId <= 0) {
        return ['ok' => true, 'message' => ''];
    }
    $file = $_FILES['pdf_file'] ?? [];
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'message' => ''];
    }
    $upload = akademik_rapor_pdf_handle_upload($file, $raporId, $oldRelativePath);
    if (!$upload['ok']) {
        return ['ok' => false, 'message' => (string) ($upload['error'] ?? 'Upload PDF gagal.')];
    }
    if (empty($upload['path'])) {
        return ['ok' => true, 'message' => ''];
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    $pdo->prepare('UPDATE akademik_rapor SET pdf_path = :p, pdf_original_name = :o WHERE id = :id AND jenis_rapor = :j')->execute([
        'p' => (string) $upload['path'],
        'o' => (string) ($upload['original_name'] ?? 'rapor.pdf'),
        'id' => $raporId,
        'j' => $jenis,
    ]);

    return ['ok' => true, 'message' => 'PDF rapor berhasil diunggah.'];
}

function akademik_rapor_wa_auto_enabled(PDO $pdo, string $jenis): bool
{
    if (!function_exists('wa_rapor_auto_enabled')) {
        require_once __DIR__ . '/wa_templates.php';
    }

    return wa_rapor_auto_enabled($pdo, $jenis);
}

function akademik_rapor_wa_template_slug(string $jenis): string
{
    if (!function_exists('wa_rapor_template_slug')) {
        require_once __DIR__ . '/wa_templates.php';
    }

    return wa_rapor_template_slug($jenis);
}

function akademik_rapor_wa_template(PDO $pdo, string $jenis): string
{
    if (!function_exists('wa_template_get')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    wa_template_migrate_rapor_legacy($pdo);

    return wa_template_get($pdo, akademik_rapor_wa_template_slug($jenis));
}

/**
 * @param array<string, mixed> $raporRow
 */
function akademik_rapor_wa_render_pesan(PDO $pdo, array $raporRow, string $jenis): string
{
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    $tab = $jenis === 'pkpps' ? 'rapor_pkpps' : 'rapor_pesantren';
    $portalPath = '/wali/akademik.php?tab=' . $tab;
    $portalUrl = function_exists('app_url') ? app_url($portalPath) : app_href($portalPath);
    $namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', ''));
    if ($namaPonpes === '') {
        $namaPonpes = trim((string) app_setting($pdo, 'nama_pondok', ''));
    }

    return wa_template_render($pdo, akademik_rapor_wa_template_slug($jenis), [
        'nama_santri' => (string) ($raporRow['nama_santri'] ?? ''),
        'judul_periode' => (string) ($raporRow['judul_periode'] ?? ''),
        'tanggal_terbit' => (string) ($raporRow['tanggal_terbit'] ?? ''),
        'nis' => (string) ($raporRow['nis'] ?? ''),
        'portal_url' => $portalUrl,
        'jenis_rapor' => akademik_rapor_jenis_label($jenis),
        'nama_ponpes' => $namaPonpes,
    ]);
}

/** Kirim WA saat rapor diterbitkan; kembalikan pesan flash tambahan atau string kosong. */
function akademik_rapor_wa_kirim_saat_terbit(PDO $pdo, int $raporId, string $jenis, bool $wasAlreadyPublished): string
{
    if ($raporId <= 0) {
        return '';
    }
    $jenis = akademik_rapor_jenis_normalize($jenis);
    if (!akademik_rapor_wa_auto_enabled($pdo, $jenis)) {
        return '';
    }
    if (!function_exists('wa_otomatis_should_run')) {
        require_once __DIR__ . '/wa_otomatis.php';
    }
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return '';
    }

    $st = $pdo->prepare('
        SELECT r.*, s.nis, s.nama_santri, s.no_wa_wali
        FROM akademik_rapor r
        INNER JOIN santri s ON s.id = r.santri_id
        WHERE r.id = :id AND r.jenis_rapor = :j AND r.is_published = 1
        LIMIT 1
    ');
    $st->execute(['id' => $raporId, 'j' => $jenis]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }
    if ($wasAlreadyPublished && trim((string) ($row['wa_terbit_sent_at'] ?? '')) !== '') {
        return '';
    }

    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    $phone = santri_resolve_no_wa_wali($pdo, $row);
    if ($phone === '') {
        return 'WA otomatis tidak terkirim: nomor wali kosong.';
    }

    $pesan = akademik_rapor_wa_render_pesan($pdo, $row, $jenis);
    if ($pesan === '') {
        return '';
    }

    $result = send_wa_message_with_result($pdo, $phone, $pesan, [
        'kind' => 'rapor',
        'dedup_key' => 'rapor:' . $raporId . ':' . $jenis . ':wali',
    ]);
    if (!empty($result['success'])) {
        $pdo->prepare('UPDATE akademik_rapor SET wa_terbit_sent_at = NOW() WHERE id = :id AND jenis_rapor = :j')->execute([
            'id' => $raporId,
            'j' => $jenis,
        ]);

        return 'Notifikasi WA ke wali terkirim.';
    }

    $err = trim((string) ($result['error'] ?? $result['message'] ?? 'gagal'));

    return 'WA otomatis gagal: ' . ($err !== '' ? $err : 'periksa pengaturan gateway.');
}

/**
 * @return array<string,mixed>|null
 */
function akademik_rapor_fetch_for_wali(PDO $pdo, int $raporId, int $waliSantriId): ?array
{
    if ($raporId <= 0 || $waliSantriId <= 0 || !table_exists($pdo, 'akademik_rapor')) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT r.*, s.nis, s.nama_santri
        FROM akademik_rapor r
        INNER JOIN santri s ON s.id = r.santri_id
        WHERE r.id = :id AND r.santri_id = :sid AND r.is_published = 1
        LIMIT 1
    ');
    $st->execute(['id' => $raporId, 'sid' => $waliSantriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array{mode:string,month:int,year:int} */
function rapor_periode_default_dari_tanggal(PDO $pdo, string $tanggalTerbit): array
{
    $cal = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
    $mode = $cal === 'MASEHI' ? 'masehi' : 'hijriyah';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalTerbit)) {
        $tanggalTerbit = date('Y-m-d');
    }
    if ($mode === 'masehi') {
        return [
            'mode' => 'masehi',
            'month' => (int) date('n', strtotime($tanggalTerbit)),
            'year' => (int) date('Y', strtotime($tanggalTerbit)),
        ];
    }
    $h = konversiKeHijriah($pdo, $tanggalTerbit);
    if (is_array($h)) {
        return [
            'mode' => 'hijriyah',
            'month' => max(1, min(12, (int) ($h['bulan_hijriyah'] ?? 1))),
            'year' => (int) ($h['tahun_hijriah'] ?? akademik_hijri_anchor_hari_ini($pdo)['y']),
        ];
    }
    $anchor = akademik_hijri_anchor_hari_ini($pdo);

    return ['mode' => 'hijriyah', 'month' => (int) $anchor['m'], 'year' => (int) $anchor['y']];
}

/**
 * @param array<string,mixed> $row baris akademik_rapor
 * @return array{mode:string,month:int,year:int,start_date:string,end_date:string,label:string,hijri_label:string}
 */
function rapor_periode_dari_row(PDO $pdo, array $row): array
{
    $mode = strtolower(trim((string) ($row['periode_mode'] ?? '')));
    if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
        $def = rapor_periode_default_dari_tanggal($pdo, (string) ($row['tanggal_terbit'] ?? date('Y-m-d')));
        $mode = $def['mode'];
        $month = $def['month'];
        $year = $def['year'];
    } else {
        $month = max(1, min(12, (int) ($row['periode_bulan'] ?? 0)));
        $year = (int) ($row['periode_tahun'] ?? 0);
        if ($month < 1 || $year < 1) {
            $def = rapor_periode_default_dari_tanggal($pdo, (string) ($row['tanggal_terbit'] ?? date('Y-m-d')));
            $month = $def['month'];
            $year = $def['year'];
        }
    }

    return rekap_resolve_periode($pdo, ['mode' => $mode, 'month' => $month, 'year' => $year]);
}

/**
 * @return array<string,mixed>|null satu santri dari rekap_keaktifan_build_per_santri
 */
function rapor_presensi_bulan(PDO $pdo, int $santriId, array $periode): ?array
{
    if ($santriId <= 0) {
        return null;
    }
    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $rows = presensi_fetch_rows_rekap_periode($pdo, $periode, 0);
    $filtered = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['santri_id'] ?? 0) === $santriId));
    if ($filtered === []) {
        return null;
    }
    $ranked = rekap_keaktifan_build_per_santri($filtered, $goodMax, $mediumMax);

    return $ranked[0] ?? null;
}

/**
 * Hasil tugas ikhtibar per pembimbing / mapel dalam rentang periode.
 *
 * @return list<array{pembimbing_nama:string,mapel_label:string,tugas:list<array<string,mixed>>}>
 */
function rapor_tugas_bulan(PDO $pdo, int $santriId, array $periode, ?string $sumberFilter = null): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'ikhtibar_tugas')) {
        return [];
    }
    require_once __DIR__ . '/akademik_ikhtibar.php';
    ensure_akademik_ikhtibar_tables($pdo);

    $sumberSql = '';
    $params = [
        'sid' => $santriId,
        'start' => (string) $periode['start_date'],
        'end' => (string) $periode['end_date'],
    ];
    if ($sumberFilter !== null && $sumberFilter !== '' && column_exists($pdo, 'ikhtibar_tugas', 'sumber')) {
        $sumberSql = ' AND UPPER(COALESCE(t.sumber, \'IKHTIBAR\')) = :sumber';
        $params['sumber'] = strtoupper($sumberFilter);
    } elseif ($sumberFilter === null && column_exists($pdo, 'ikhtibar_tugas', 'sumber')) {
        $sumberSql = ' AND UPPER(COALESCE(t.sumber, \'IKHTIBAR\')) <> \'PKPPS\'';
    }

    $stmt = $pdo->prepare('
        SELECT
            t.id AS tugas_id,
            t.judul,
            t.tanggal,
            t.mapel_label,
            t.filter_tingkatan,
            k.nama_kegiatan,
            u.nama AS pembimbing_nama,
            ses.status AS sesi_status,
            ses.skor_pg,
            ses.skor_esai,
            ses.nilai_total,
            ses.waktu_mulai,
            ses.waktu_selesai
        FROM ikhtibar_sesi ses
        INNER JOIN ikhtibar_tugas t ON t.id = ses.tugas_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN kegiatan k ON k.id = t.kegiatan_id
        WHERE ses.santri_id = :sid
          AND t.tanggal BETWEEN :start AND :end
          ' . $sumberSql . '
        ORDER BY u.nama ASC, t.mapel_label ASC, t.tanggal DESC, t.id DESC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groups = [];
    foreach ($rows as $r) {
        $mapel = trim((string) ($r['mapel_label'] ?? ''));
        if ($mapel === '') {
            $kg = trim((string) ($r['nama_kegiatan'] ?? ''));
            $tk = trim((string) ($r['filter_tingkatan'] ?? ''));
            $mapel = $kg !== '' ? $kg . ($tk !== '' ? ' — ' . $tk : '') : ($tk !== '' ? $tk : 'Umum');
        }
        $pem = trim((string) ($r['pembimbing_nama'] ?? ''));
        if ($pem === '') {
            $pem = 'Pembimbing';
        }
        $key = $pem . "\0" . $mapel;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'pembimbing_nama' => $pem,
                'mapel_label' => $mapel,
                'tugas' => [],
            ];
        }
        $groups[$key]['tugas'][] = [
            'judul' => (string) ($r['judul'] ?? ''),
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'sesi_status' => (string) ($r['sesi_status'] ?? ''),
            'skor_pg' => $r['skor_pg'],
            'skor_esai' => $r['skor_esai'],
            'nilai_total' => $r['nilai_total'],
            'waktu_mulai' => $r['waktu_mulai'],
            'waktu_selesai' => $r['waktu_selesai'],
        ];
    }

    return array_values($groups);
}

function rapor_sesi_status_label(string $status): string
{
    return match (strtolower($status)) {
        'selesai' => 'Selesai',
        'berjalan' => 'Sedang dikerjakan',
        'habis_waktu' => 'Waktu habis',
        'menunggu' => 'Belum mulai',
        default => ucfirst($status),
    };
}

function rapor_kategori_badge_class(string $kategori): string
{
    return match ($kategori) {
        'Bagus' => 'success',
        'Baik' => 'primary',
        'Sedang' => 'warning',
        'Buruk' => 'danger',
        default => 'secondary',
    };
}

/**
 * Setoran hafalan santri dalam rentang periode rapor.
 *
 * @return list<array<string,mixed>>
 */
function rapor_setoran_bulan(PDO $pdo, int $santriId, array $periode): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'akademik_hafalan_setoran')) {
        return [];
    }
    require_once __DIR__ . '/akademik.php';
    ensure_akademik_hafalan_setoran_table($pdo);

    $hasKat = column_exists($pdo, 'akademik_hafalan_setoran', 'kategori_setoran');
    $cols = 'h.id, h.tanggal_setoran, h.target_hafalan, h.juz_halaman, h.nilai_skor, h.predikat, h.catatan';
    if ($hasKat) {
        $cols .= ', h.kategori_setoran, h.baris_setor, h.kalender_hijriyah, k.nama_kitab AS bait_nama';
    } else {
        $cols .= ", 'ALQURAN' AS kategori_setoran, NULL AS baris_setor, NULL AS kalender_hijriyah, NULL AS bait_nama";
    }

    $sql = "
        SELECT {$cols}
        FROM akademik_hafalan_setoran h
    ";
    if ($hasKat && table_exists($pdo, 'akademik_bait_kitab')) {
        $sql .= ' LEFT JOIN akademik_bait_kitab k ON k.id = h.bait_kitab_id';
    }
    $sql .= ' WHERE h.santri_id = :sid AND h.tanggal_setoran BETWEEN :start AND :end
        ORDER BY h.tanggal_setoran DESC, h.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute([
        'sid' => $santriId,
        'start' => (string) $periode['start_date'],
        'end' => (string) $periode['end_date'],
    ]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function rapor_setoran_kategori_label(string $kat): string
{
    return strtoupper(trim($kat)) === 'BAIT' ? 'Bait (kitab)' : 'Al-Qur\'an';
}

/** Nama wali kelas dari riwayat tingkatan santri (TA terbaru, cocokkan tingkatan bila ada). */
function rapor_wali_kelas_santri(PDO $pdo, int $santriId, string $tingkatan = ''): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri_riwayat_tingkatan')) {
        return '';
    }
    require_once __DIR__ . '/santri_riwayat.php';
    ensure_santri_riwayat_tables($pdo);
    if (!column_exists($pdo, 'santri_riwayat_tingkatan', 'wali_kelas')) {
        return '';
    }

    $tingkatan = trim($tingkatan);
    if ($tingkatan !== '') {
        $st = $pdo->prepare('
            SELECT wali_kelas FROM santri_riwayat_tingkatan
            WHERE santri_id = :sid AND tingkatan = :tg
              AND wali_kelas IS NOT NULL AND TRIM(wali_kelas) <> ""
            ORDER BY tahun_ajaran_mulai DESC, id DESC
            LIMIT 1
        ');
        $st->execute(['sid' => $santriId, 'tg' => $tingkatan]);
        $wk = trim((string) ($st->fetchColumn() ?: ''));
        if ($wk !== '') {
            return $wk;
        }
    }

    $st2 = $pdo->prepare('
        SELECT wali_kelas FROM santri_riwayat_tingkatan
        WHERE santri_id = :sid AND wali_kelas IS NOT NULL AND TRIM(wali_kelas) <> ""
        ORDER BY tahun_ajaran_mulai DESC, id DESC
        LIMIT 1
    ');
    $st2->execute(['sid' => $santriId]);

    return trim((string) ($st2->fetchColumn() ?: ''));
}
