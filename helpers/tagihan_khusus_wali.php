<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_wa.php';
require_once __DIR__ . '/keuangan_typography.php';

function ensure_keuangan_tagihan_khusus_table(PDO $pdo): void
{
    $addColumnSafe = static function (string $sqlIfNotExists, string $sqlFallback) use ($pdo): void {
        try {
            $pdo->exec($sqlIfNotExists);
        } catch (PDOException $e) {
            try {
                $pdo->exec($sqlFallback);
            } catch (PDOException $e2) {
                $msg = strtolower($e2->getMessage());
                if (str_contains($msg, 'duplicate column') || str_contains($msg, '1060')) {
                    return;
                }
                throw $e2;
            }
        }
    };

    if (!table_exists($pdo, 'keuangan_tagihan_khusus')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keuangan_tagihan_khusus (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                kategori VARCHAR(40) NOT NULL DEFAULT 'lainnya',
                judul VARCHAR(200) NOT NULL,
                keterangan TEXT NULL,
                nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
                nominal_dibayar DECIMAL(12,2) NOT NULL DEFAULT 0,
                tanggal_tagihan DATE NOT NULL,
                status ENUM('tertunda','lunas','batal') NOT NULL DEFAULT 'tertunda',
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                alokasi_nama VARCHAR(150) NULL,
                pengeluaran_id INT NULL,
                pembayaran_id INT NULL,
                wa_notified_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tkh_santri (santri_id),
                INDEX idx_tkh_status (status),
                INDEX idx_tkh_tanggal (tanggal_tagihan),
                INDEX idx_tkh_pengeluaran (pengeluaran_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        if (!column_exists($pdo, 'keuangan_tagihan_khusus', 'alokasi_nama')) {
            $addColumnSafe(
                'ALTER TABLE keuangan_tagihan_khusus ADD COLUMN IF NOT EXISTS alokasi_nama VARCHAR(150) NULL AFTER is_published',
                'ALTER TABLE keuangan_tagihan_khusus ADD COLUMN alokasi_nama VARCHAR(150) NULL AFTER is_published'
            );
        }
        if (!column_exists($pdo, 'keuangan_tagihan_khusus', 'pengeluaran_id')) {
            $addColumnSafe(
                'ALTER TABLE keuangan_tagihan_khusus ADD COLUMN IF NOT EXISTS pengeluaran_id INT NULL AFTER alokasi_nama',
                'ALTER TABLE keuangan_tagihan_khusus ADD COLUMN pengeluaran_id INT NULL AFTER alokasi_nama'
            );
        }
    }
}

/** @return list<array{value:string,label:string}> */
function tagihan_khusus_alokasi_syahriyah_options(PDO $pdo): array
{
    if (!function_exists('keuangan_fetch_alokasi_aktif')) {
        require_once __DIR__ . '/keuangan_alokasi.php';
    }
    if (!defined('KEUNGAN_ALOKASI_JENIS_SYAHRIYAH')) {
        require_once __DIR__ . '/keuangan_alokasi.php';
    }
    $out = [];
    foreach (keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH) as $ar) {
        $nama = trim((string) ($ar['nama_komponen'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $out[] = [
            'value' => $nama,
            'label' => $nama . ' (' . (string) ($ar['persen'] ?? '0') . '%)',
        ];
    }

    return $out;
}

function tagihan_khusus_alokasi_default_kategori(PDO $pdo, string $kategori): string
{
    $opts = tagihan_khusus_alokasi_syahriyah_options($pdo);
    if ($opts === []) {
        return '';
    }
    $names = array_map(static fn(array $o): string => (string) ($o['value'] ?? ''), $opts);
    $prefer = match (tagihan_khusus_kategori_normalize($kategori)) {
        'berobat', 'obat' => ['Kesehatan', 'kesehatan'],
        default => [],
    };
    foreach ($prefer as $needle) {
        foreach ($names as $nama) {
            if (stripos($nama, $needle) !== false) {
                return $nama;
            }
        }
    }

    return (string) ($opts[0]['value'] ?? '');
}

function tagihan_khusus_pos_pengeluaran(string $kategori): string
{
    return match (tagihan_khusus_kategori_normalize($kategori)) {
        'berobat' => 'Biaya berobat santri',
        'obat' => 'Obat & alkes santri',
        'transport' => 'Transport santri',
        'kegiatan' => 'Kegiatan santri',
        default => 'Tagihan khusus santri',
    };
}

function tagihan_khusus_default_akun_id(PDO $pdo): int
{
    if (!function_exists('keuangan_fetch_akun_aktif')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    $rows = keuangan_fetch_akun_aktif($pdo);
    foreach ($rows as $ar) {
        if ((int) ($ar['is_default'] ?? 0) === 1) {
            return (int) ($ar['id'] ?? 0);
        }
    }

    return $rows !== [] ? (int) ($rows[0]['id'] ?? 0) : 0;
}

function tagihan_khusus_pengeluaran_keterangan(int $tagihanId, string $namaSantri, string $judul): string
{
    $ket = 'Pinjaman alokasi syahriyah — tagihan wali #' . $tagihanId;
    if ($namaSantri !== '') {
        $ket .= ' — ' . $namaSantri;
    }
    if ($judul !== '') {
        $ket .= ': ' . $judul;
    }

    return mb_substr($ket, 0, 500);
}

/**
 * @param array{santri_id:int,kategori:string,judul:string,nominal:int,tanggal:string,alokasi_nama:string,nama_santri?:string} $ctx
 */
function tagihan_khusus_create_pengeluaran_alokasi(PDO $pdo, int $tagihanId, array $ctx, int $userId, string $penanggungJawab): int
{
    if (!function_exists('ensure_keuangan_transaksi_tables')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    ensure_keuangan_transaksi_tables($pdo);

    $alokasiNama = trim((string) ($ctx['alokasi_nama'] ?? ''));
    $nominal = max(0, (int) ($ctx['nominal'] ?? 0));
    $tanggal = trim((string) ($ctx['tanggal'] ?? date('Y-m-d')));
    if ($alokasiNama === '' || $nominal <= 0 || $tagihanId <= 0) {
        return 0;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    if (!function_exists('keuangan_validasi_alokasi_pengeluaran')) {
        require_once __DIR__ . '/keuangan_alokasi.php';
    }
    $alokasiErr = keuangan_validasi_alokasi_pengeluaran($pdo, $alokasiNama);
    if ($alokasiErr !== null) {
        throw new RuntimeException($alokasiErr);
    }

    $akunId = tagihan_khusus_default_akun_id($pdo);
    if ($akunId <= 0) {
        throw new RuntimeException('Akun kas/bank default belum diatur.');
    }

    $pos = tagihan_khusus_pos_pengeluaran((string) ($ctx['kategori'] ?? 'lainnya'));
    $keterangan = tagihan_khusus_pengeluaran_keterangan(
        $tagihanId,
        trim((string) ($ctx['nama_santri'] ?? '')),
        trim((string) ($ctx['judul'] ?? ''))
    );
    $pj = trim($penanggungJawab) !== '' ? trim($penanggungJawab) : 'Bendahara';

    $cols = ['tanggal', 'penanggung_jawab', 'pos', 'alokasi_nama', 'nominal', 'keterangan', 'created_by'];
    $vals = [':tanggal', ':penanggung_jawab', ':pos', ':alokasi_nama', ':nominal', ':keterangan', ':created_by'];
    $params = [
        'tanggal' => $tanggal,
        'penanggung_jawab' => $pj,
        'pos' => $pos,
        'alokasi_nama' => $alokasiNama,
        'nominal' => $nominal,
        'keterangan' => $keterangan,
        'created_by' => $userId > 0 ? $userId : null,
    ];
    if (column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar')) {
        $cols[] = 'metode_keluar';
        $vals[] = ':metode_keluar';
        $params['metode_keluar'] = 'KAS';
    }
    if (column_exists($pdo, 'keuangan_pengeluaran', 'akun_id')) {
        $cols[] = 'akun_id';
        $vals[] = ':akun_id';
        $params['akun_id'] = $akunId;
    }

    $sql = 'INSERT INTO keuangan_pengeluaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $pdo->prepare($sql)->execute($params);
    $pengeluaranId = (int) $pdo->lastInsertId();

    if (!function_exists('keuangan_transaksi_bootstrap_jurnal')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    keuangan_transaksi_bootstrap_jurnal();
    if (!function_exists('keuangan_jurnal_pengeluaran')) {
        require_once __DIR__ . '/keuangan_jurnal.php';
    }
    keuangan_jurnal_pengeluaran($pdo, $pengeluaranId, $tanggal, $akunId, $nominal, $pos, $userId);

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return $pengeluaranId;
}

/**
 * @param array{kategori:string,judul:string,nominal:int,tanggal:string,alokasi_nama:string,nama_santri?:string} $ctx
 */
function tagihan_khusus_sync_pengeluaran_alokasi(
    PDO $pdo,
    int $pengeluaranId,
    array $ctx,
    int $userId,
    string $penanggungJawab
): void {
    if ($pengeluaranId <= 0) {
        return;
    }
    if (!function_exists('keuangan_pengeluaran_get')) {
        require_once __DIR__ . '/keuangan_pengeluaran_riwayat.php';
    }
    $row = keuangan_pengeluaran_get($pdo, $pengeluaranId);
    if ($row === null) {
        return;
    }

    $alokasiNama = trim((string) ($ctx['alokasi_nama'] ?? ''));
    $nominal = max(0, (int) ($ctx['nominal'] ?? 0));
    $tanggal = trim((string) ($ctx['tanggal'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    if (!function_exists('keuangan_validasi_alokasi_pengeluaran')) {
        require_once __DIR__ . '/keuangan_alokasi.php';
    }
    $alokasiErr = keuangan_validasi_alokasi_pengeluaran($pdo, $alokasiNama);
    if ($alokasiErr !== null) {
        throw new RuntimeException($alokasiErr);
    }

    $pos = tagihan_khusus_pos_pengeluaran((string) ($ctx['kategori'] ?? 'lainnya'));
    $tagihanId = 0;
    if (preg_match('/tagihan wali #(\d+)/', (string) ($row['keterangan'] ?? ''), $m)) {
        $tagihanId = (int) ($m[1] ?? 0);
    }
    $keterangan = tagihan_khusus_pengeluaran_keterangan(
        $tagihanId > 0 ? $tagihanId : $pengeluaranId,
        trim((string) ($ctx['nama_santri'] ?? '')),
        trim((string) ($ctx['judul'] ?? ''))
    );
    $akunId = (int) ($row['akun_id'] ?? 0);
    if ($akunId <= 0) {
        $akunId = tagihan_khusus_default_akun_id($pdo);
    }

    $sets = [
        'tanggal = :tanggal',
        'pos = :pos',
        'alokasi_nama = :alokasi_nama',
        'nominal = :nominal',
        'keterangan = :keterangan',
    ];
    $params = [
        'tanggal' => $tanggal,
        'pos' => $pos,
        'alokasi_nama' => $alokasiNama,
        'nominal' => $nominal,
        'keterangan' => $keterangan,
        'id' => $pengeluaranId,
    ];
    $pj = trim($penanggungJawab);
    if ($pj !== '') {
        $sets[] = 'penanggung_jawab = :penanggung_jawab';
        $params['penanggung_jawab'] = $pj;
    }

    $pdo->prepare('UPDATE keuangan_pengeluaran SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

    if (!function_exists('keuangan_transaksi_bootstrap_jurnal')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    keuangan_transaksi_bootstrap_jurnal();
    if (!function_exists('keuangan_jurnal_delete_by_ref')) {
        require_once __DIR__ . '/keuangan_jurnal.php';
    }
    keuangan_jurnal_delete_by_ref($pdo, 'pengeluaran', $pengeluaranId);
    if ($akunId > 0) {
        keuangan_jurnal_pengeluaran($pdo, $pengeluaranId, $tanggal, $akunId, $nominal, $pos, $userId);
    }

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();
}

function tagihan_khusus_hapus_pengeluaran_terkait(PDO $pdo, int $pengeluaranId): void
{
    if ($pengeluaranId <= 0 || !table_exists($pdo, 'keuangan_pengeluaran')) {
        return;
    }
    if (!function_exists('keuangan_transaksi_bootstrap_jurnal')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    keuangan_transaksi_bootstrap_jurnal();
    if (!function_exists('keuangan_jurnal_delete_by_ref')) {
        require_once __DIR__ . '/keuangan_jurnal.php';
    }
    keuangan_jurnal_delete_by_ref($pdo, 'pengeluaran', $pengeluaranId);
    $pdo->prepare('DELETE FROM keuangan_pengeluaran WHERE id = :id')->execute(['id' => $pengeluaranId]);

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();
}

/** @return array<string, string> */
function tagihan_khusus_kategori_opsi(): array
{
    return [
        'berobat' => 'Biaya berobat / rawat',
        'obat' => 'Obat & alkes',
        'transport' => 'Transport / perjalanan',
        'kegiatan' => 'Kegiatan / study tour',
        'lainnya' => 'Lainnya',
    ];
}

function tagihan_khusus_kategori_label(string $kategori): string
{
    $opsi = tagihan_khusus_kategori_opsi();

    return $opsi[$kategori] ?? $opsi['lainnya'];
}

function tagihan_khusus_kategori_normalize(string $raw): string
{
    $k = strtolower(trim($raw));

    return array_key_exists($k, tagihan_khusus_kategori_opsi()) ? $k : 'lainnya';
}

/** @param array<string, mixed> $row */
function tagihan_khusus_sisa(array $row): int
{
    if (($row['status'] ?? '') === 'batal') {
        return 0;
    }
    $nom = (int) round((float) ($row['nominal'] ?? 0));
    $paid = (int) round((float) ($row['nominal_dibayar'] ?? 0));

    return max(0, $nom - $paid);
}

/** @param array<string, mixed> $row */
function tagihan_khusus_status_label(array $row): string
{
    $st = (string) ($row['status'] ?? 'tertunda');
    if ($st === 'batal') {
        return 'Dibatalkan';
    }
    if ($st === 'lunas' || tagihan_khusus_sisa($row) <= 0) {
        return 'Lunas';
    }

    return 'Belum lunas';
}

/** @param array<string, mixed> $row */
function tagihan_khusus_status_badge_class(array $row): string
{
    $st = (string) ($row['status'] ?? 'tertunda');
    if ($st === 'batal') {
        return 'secondary';
    }
    if ($st === 'lunas' || tagihan_khusus_sisa($row) <= 0) {
        return 'success';
    }

    return 'warning';
}

/**
 * @return array{ok:bool,message:string,id?:int}
 */
function tagihan_khusus_save(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_tagihan_khusus_table($pdo);

    $id = (int) ($post['tagihan_id'] ?? 0);
    $sid = (int) ($post['santri_id'] ?? 0);
    $kategori = tagihan_khusus_kategori_normalize((string) ($post['kategori'] ?? 'lainnya'));
    $judul = trim((string) ($post['judul'] ?? ''));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $nominal = max(0, (int) preg_replace('/\D/', '', (string) ($post['nominal'] ?? '0')));
    $tgl = trim((string) ($post['tanggal_tagihan'] ?? ''));
    $published = isset($post['is_published']) ? 1 : 0;
    $kirimWa = isset($post['kirim_wa']);
    $pinjamAlokasi = isset($post['pinjam_alokasi']);
    $alokasiNama = trim((string) ($post['alokasi_nama'] ?? ''));
    $penanggungJawab = trim((string) ($post['penanggung_jawab'] ?? ''));

    if ($sid <= 0 || $judul === '' || $nominal <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return ['ok' => false, 'message' => 'Santri, judul, nominal, dan tanggal tagihan wajib diisi dengan benar.'];
    }
    if ($pinjamAlokasi) {
        if ($alokasiNama === '') {
            $alokasiNama = tagihan_khusus_alokasi_default_kategori($pdo, $kategori);
        }
        if ($alokasiNama === '') {
            return ['ok' => false, 'message' => 'Pilih komponen alokasi syahriyah atau atur alokasi di Pengaturan keuangan.'];
        }
        if (!function_exists('keuangan_validasi_alokasi_pengeluaran')) {
            require_once __DIR__ . '/keuangan_alokasi.php';
        }
        $alokasiErr = keuangan_validasi_alokasi_pengeluaran($pdo, $alokasiNama);
        if ($alokasiErr !== null) {
            return ['ok' => false, 'message' => $alokasiErr];
        }
    } else {
        $alokasiNama = '';
    }

    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $stS = $pdo->prepare('SELECT id, ' . $namaCol . ' AS nama_santri FROM santri WHERE id = :id LIMIT 1');
    $stS->execute(['id' => $sid]);
    $santriRow = $stS->fetch(PDO::FETCH_ASSOC);
    if (!$santriRow) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }
    $namaSantri = trim((string) ($santriRow['nama_santri'] ?? ''));

    $pengCtx = [
        'santri_id' => $sid,
        'kategori' => $kategori,
        'judul' => $judul,
        'nominal' => $nominal,
        'tanggal' => $tgl,
        'alokasi_nama' => $alokasiNama,
        'nama_santri' => $namaSantri,
    ];

    if ($id > 0) {
        $own = $pdo->prepare('SELECT id, nominal_dibayar, status, pengeluaran_id FROM keuangan_tagihan_khusus WHERE id = :id LIMIT 1');
        $own->execute(['id' => $id]);
        $prev = $own->fetch(PDO::FETCH_ASSOC);
        if (!$prev) {
            return ['ok' => false, 'message' => 'Tagihan tidak ditemukan.'];
        }
        if ((string) ($prev['status'] ?? '') === 'batal') {
            return ['ok' => false, 'message' => 'Tagihan yang dibatalkan tidak dapat diedit.'];
        }
        $paid = (int) round((float) ($prev['nominal_dibayar'] ?? 0));
        if ($nominal < $paid) {
            return ['ok' => false, 'message' => 'Nominal tagihan tidak boleh lebih kecil dari yang sudah dibayar (Rp ' . number_format($paid, 0, ',', '.') . ').'];
        }
        $status = $paid >= $nominal ? 'lunas' : 'tertunda';
        $pengeluaranId = (int) ($prev['pengeluaran_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                UPDATE keuangan_tagihan_khusus SET
                    santri_id = :sid, kategori = :kat, judul = :judul, keterangan = :ket,
                    nominal = :nom, tanggal_tagihan = :tgl, is_published = :pub, status = :st,
                    alokasi_nama = :alok, pengeluaran_id = :pid
                WHERE id = :id
            ')->execute([
                'sid' => $sid,
                'kat' => $kategori,
                'judul' => mb_substr($judul, 0, 200),
                'ket' => $keterangan !== '' ? $keterangan : null,
                'nom' => $nominal,
                'tgl' => $tgl,
                'pub' => $published,
                'st' => $status,
                'alok' => $pinjamAlokasi ? $alokasiNama : null,
                'pid' => $pinjamAlokasi ? $pengeluaranId : null,
                'id' => $id,
            ]);

            if ($pinjamAlokasi) {
                if ($pengeluaranId > 0) {
                    tagihan_khusus_sync_pengeluaran_alokasi($pdo, $pengeluaranId, $pengCtx, $userId, $penanggungJawab);
                } else {
                    $newPid = tagihan_khusus_create_pengeluaran_alokasi($pdo, $id, $pengCtx, $userId, $penanggungJawab);
                    $pdo->prepare('UPDATE keuangan_tagihan_khusus SET pengeluaran_id = :pid WHERE id = :id')->execute([
                        'pid' => $newPid,
                        'id' => $id,
                    ]);
                }
            } elseif ($pengeluaranId > 0) {
                tagihan_khusus_hapus_pengeluaran_terkait($pdo, $pengeluaranId);
                $pdo->prepare('UPDATE keuangan_tagihan_khusus SET pengeluaran_id = NULL, alokasi_nama = NULL WHERE id = :id')->execute(['id' => $id]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        $msg = 'Tagihan diperbarui.';
        if ($pinjamAlokasi) {
            $msg .= ' Pengeluaran alokasi syahriyah disinkronkan.';
        }
        if ($kirimWa && $published === 1) {
            $wa = tagihan_khusus_kirim_wa($pdo, $id, false);
            if ($wa !== '') {
                $msg .= ' ' . $wa;
            }
        }

        return ['ok' => true, 'message' => $msg, 'id' => $id];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO keuangan_tagihan_khusus (
                santri_id, kategori, judul, keterangan, nominal, tanggal_tagihan,
                is_published, status, alokasi_nama, created_by
            ) VALUES (
                :sid, :kat, :judul, :ket, :nom, :tgl, :pub, :st, :alok, :uid
            )
        ')->execute([
            'sid' => $sid,
            'kat' => $kategori,
            'judul' => mb_substr($judul, 0, 200),
            'ket' => $keterangan !== '' ? $keterangan : null,
            'nom' => $nominal,
            'tgl' => $tgl,
            'pub' => $published,
            'st' => 'tertunda',
            'alok' => $pinjamAlokasi ? $alokasiNama : null,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $newId = (int) $pdo->lastInsertId();

        if ($pinjamAlokasi) {
            $newPid = tagihan_khusus_create_pengeluaran_alokasi($pdo, $newId, $pengCtx, $userId, $penanggungJawab);
            $pdo->prepare('UPDATE keuangan_tagihan_khusus SET pengeluaran_id = :pid WHERE id = :id')->execute([
                'pid' => $newPid,
                'id' => $newId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }

    $msg = 'Tagihan khusus ditambahkan.';
    if ($pinjamAlokasi) {
        $msg .= ' Dana dicatat sebagai pengeluaran dari alokasi syahriyah (' . $alokasiNama . ').';
    }
    if ($kirimWa && $published === 1) {
        $wa = tagihan_khusus_kirim_wa($pdo, $newId, false);
        if ($wa !== '') {
            $msg .= ' ' . $wa;
        }
    }

    return ['ok' => true, 'message' => $msg, 'id' => $newId];
}

/**
 * @return array{ok:bool,message:string}
 */
function tagihan_khusus_catat_bayar(PDO $pdo, array $post): array
{
    ensure_keuangan_tagihan_khusus_table($pdo);
    $id = (int) ($post['tagihan_id'] ?? 0);
    $bayar = max(0, (int) preg_replace('/\D/', '', (string) ($post['nominal_bayar'] ?? '0')));
    if ($id <= 0 || $bayar <= 0) {
        return ['ok' => false, 'message' => 'Tagihan dan nominal bayar wajib valid.'];
    }

    $st = $pdo->prepare('SELECT * FROM keuangan_tagihan_khusus WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Tagihan tidak ditemukan.'];
    }
    if ((string) ($row['status'] ?? '') === 'batal') {
        return ['ok' => false, 'message' => 'Tagihan sudah dibatalkan.'];
    }

    $sisa = tagihan_khusus_sisa($row);
    if ($sisa <= 0) {
        return ['ok' => false, 'message' => 'Tagihan sudah lunas.'];
    }
    if ($bayar > $sisa) {
        return ['ok' => false, 'message' => 'Nominal bayar melebihi sisa tagihan (Rp ' . number_format($sisa, 0, ',', '.') . ').'];
    }

    $newPaid = (int) round((float) ($row['nominal_dibayar'] ?? 0)) + $bayar;
    $nom = (int) round((float) ($row['nominal'] ?? 0));
    $status = $newPaid >= $nom ? 'lunas' : 'tertunda';
    $pdo->prepare('UPDATE keuangan_tagihan_khusus SET nominal_dibayar = :p, status = :st WHERE id = :id')->execute([
        'p' => $newPaid,
        'st' => $status,
        'id' => $id,
    ]);

    return ['ok' => true, 'message' => $status === 'lunas' ? 'Tagihan lunas.' : 'Pembayaran dicatat. Sisa Rp ' . number_format($nom - $newPaid, 0, ',', '.') . '.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function tagihan_khusus_batalkan(PDO $pdo, int $id): array
{
    ensure_keuangan_tagihan_khusus_table($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Tagihan tidak valid.'];
    }

    $st = $pdo->prepare('SELECT pengeluaran_id FROM keuangan_tagihan_khusus WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $pengeluaranId = (int) ($st->fetchColumn() ?: 0);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE keuangan_tagihan_khusus SET status = 'batal', is_published = 0 WHERE id = :id")->execute(['id' => $id]);
        if ($pengeluaranId > 0) {
            tagihan_khusus_hapus_pengeluaran_terkait($pdo, $pengeluaranId);
            $pdo->prepare('UPDATE keuangan_tagihan_khusus SET pengeluaran_id = NULL, alokasi_nama = NULL WHERE id = :id')->execute(['id' => $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal membatalkan: ' . $e->getMessage()];
    }

    $msg = 'Tagihan dibatalkan.';
    if ($pengeluaranId > 0) {
        $msg .= ' Pengeluaran alokasi terkait dihapus.';
    }

    return ['ok' => true, 'message' => $msg];
}

/**
 * @return list<array<string, mixed>>
 */
function tagihan_khusus_list_admin(PDO $pdo, int $filterSantri = 0, int $limit = 80): array
{
    ensure_keuangan_tagihan_khusus_table($pdo);
    if (!table_exists($pdo, 'keuangan_tagihan_khusus')) {
        return [];
    }

    $sql = '
        SELECT t.*, s.nis, s.nama_santri
        FROM keuangan_tagihan_khusus t
        INNER JOIN santri s ON s.id = t.santri_id
        WHERE 1=1
    ';
    $params = [];
    if ($filterSantri > 0) {
        $sql .= ' AND t.santri_id = :sid';
        $params['sid'] = $filterSantri;
    }
    $sql .= ' ORDER BY t.tanggal_tagihan DESC, t.id DESC LIMIT ' . max(1, min(200, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function tagihan_khusus_list_wali(PDO $pdo, int $santriId, int $limit = 40): array
{
    ensure_keuangan_tagihan_khusus_table($pdo);
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_tagihan_khusus')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT *
        FROM keuangan_tagihan_khusus
        WHERE santri_id = :sid AND is_published = 1 AND status <> :batal
        ORDER BY tanggal_tagihan DESC, id DESC
        LIMIT ' . max(1, min(100, $limit)) . '
    ');
    $st->execute(['sid' => $santriId, 'batal' => 'batal']);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, mixed>|null */
function tagihan_khusus_fetch(PDO $pdo, int $id): ?array
{
    ensure_keuangan_tagihan_khusus_table($pdo);
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT t.*, s.nis, s.nama_santri
        FROM keuangan_tagihan_khusus t
        INNER JOIN santri s ON s.id = t.santri_id
        WHERE t.id = :id LIMIT 1
    ');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function tagihan_khusus_wa_pesan(PDO $pdo, array $row): string
{
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', ''));
    if ($namaPonpes === '') {
        $namaPonpes = trim((string) app_setting($pdo, 'nama_pondok', ''));
    }
    $portalUrl = function_exists('app_url') ? app_url('/wali/keuangan.php?tab=tagihan_lain') : app_href('/wali/keuangan.php?tab=tagihan_lain');
    $sisa = tagihan_khusus_sisa($row);

    return wa_template_render($pdo, 'tagihan_khusus_wali', [
        'nama_santri' => (string) ($row['nama_santri'] ?? ''),
        'judul' => (string) ($row['judul'] ?? ''),
        'kategori' => tagihan_khusus_kategori_label((string) ($row['kategori'] ?? '')),
        'nominal' => keuangan_format_rupiah((int) round((float) ($row['nominal'] ?? 0))),
        'sisa' => keuangan_format_rupiah($sisa),
        'tanggal' => (string) ($row['tanggal_tagihan'] ?? ''),
        'keterangan' => trim((string) ($row['keterangan'] ?? '')) !== '' ? trim((string) $row['keterangan']) : '—',
        'alokasi' => trim((string) ($row['alokasi_nama'] ?? '')) !== '' ? trim((string) $row['alokasi_nama']) : '—',
        'portal_url' => $portalUrl,
        'nama_ponpes' => $namaPonpes,
    ]);
}

/** Kembalikan pesan flash tambahan atau string kosong. */
function tagihan_khusus_kirim_wa(PDO $pdo, int $id, bool $forceResend = false): string
{
    $row = tagihan_khusus_fetch($pdo, $id);
    if (!$row || (int) ($row['is_published'] ?? 0) !== 1 || (string) ($row['status'] ?? '') === 'batal') {
        return 'WA tidak terkirim: tagihan tidak aktif di portal.';
    }
    if (!$forceResend && trim((string) ($row['wa_notified_at'] ?? '')) !== '' && tagihan_khusus_sisa($row) <= 0) {
        return '';
    }
    if (!function_exists('wa_otomatis_should_run')) {
        require_once __DIR__ . '/wa_otomatis.php';
    }
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return 'WA tidak terkirim: master WA otomatis nonaktif.';
    }

    $phone = santri_resolve_no_wa_wali($pdo, $row);
    if ($phone === '') {
        return 'WA tidak terkirim: nomor wali kosong.';
    }

    $pesan = tagihan_khusus_wa_pesan($pdo, $row);
    $result = send_wa_message_with_result($pdo, $phone, $pesan);
    if (!empty($result['ok'])) {
        $pdo->prepare('UPDATE keuangan_tagihan_khusus SET wa_notified_at = NOW() WHERE id = :id')->execute(['id' => $id]);

        return 'Notifikasi WA ke wali terkirim.';
    }

    $err = trim((string) ($result['error'] ?? $result['message'] ?? ''));

    return 'WA gagal: ' . ($err !== '' ? $err : 'periksa gateway.');
}

/**
 * @return array{count:int,total_sisa:int}
 */
function tagihan_khusus_wali_ringkasan(PDO $pdo, int $santriId): array
{
    $rows = tagihan_khusus_list_wali($pdo, $santriId);
    $count = 0;
    $totalSisa = 0;
    foreach ($rows as $r) {
        $sisa = tagihan_khusus_sisa($r);
        if ($sisa > 0) {
            ++$count;
            $totalSisa += $sisa;
        }
    }

    return ['count' => $count, 'total_sisa' => $totalSisa];
}
