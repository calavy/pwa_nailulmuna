<?php

declare(strict_types=1);

/** @return list<string> */
function inventaris_barang_kategori_options(): array
{
    return ['Furnitur', 'Elektronik', 'Peralatan', 'Perlengkapan asrama', 'Lainnya'];
}

/** @return list<string> */
function inventaris_barang_kondisi_options(): array
{
    return ['Baik', 'Rusak ringan', 'Rusak berat', 'Hilang'];
}

/** @return list<string> */
function inventaris_barang_status_options(): array
{
    return ['Lengkap', 'Tidak lengkap'];
}

/** @return list<string> */
function inventaris_barang_status_pakai_options(): array
{
    return ['Tersedia', 'Dipakai', 'Sedang diperbaiki', 'Menunggu suku cadang', 'Dipinjam', 'Disimpan'];
}

function inventaris_barang_pick(string $value, array $options, string $fallback): string
{
    $value = trim($value);

    return in_array($value, $options, true) ? $value : $fallback;
}

function inventaris_barang_clip(string $value, int $max): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function inventaris_barang_nullable(string $value): ?string
{
    $value = trim($value);

    return $value === '' ? null : $value;
}

function ensure_inventaris_barang_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS inventaris_barang (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(64) NOT NULL,
            nama VARCHAR(160) NOT NULL,
            nama_label VARCHAR(160) NULL,
            kategori VARCHAR(40) NOT NULL DEFAULT \'Lainnya\',
            lokasi VARCHAR(160) NULL,
            merk VARCHAR(120) NULL,
            keterangan VARCHAR(500) NULL,
            jumlah INT NOT NULL DEFAULT 1,
            satuan VARCHAR(40) NOT NULL DEFAULT \'unit\',
            kondisi VARCHAR(40) NOT NULL DEFAULT \'Baik\',
            status_kelengkapan VARCHAR(40) NOT NULL DEFAULT \'Lengkap\',
            catatan_kekurangan VARCHAR(500) NULL,
            penanggung_jawab VARCHAR(120) NULL,
            asal_barang VARCHAR(160) NULL,
            status_barang VARCHAR(40) NOT NULL DEFAULT \'Tersedia\',
            tanggal_cek DATE NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_inventaris_barang_kode (kode),
            KEY idx_inventaris_barang_nama (nama),
            KEY idx_inventaris_barang_status (status_kelengkapan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS inventaris_barang_cek (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barang_id INT NOT NULL,
            kondisi VARCHAR(40) NOT NULL,
            status_kelengkapan VARCHAR(40) NOT NULL,
            catatan VARCHAR(500) NULL,
            user_id INT NULL,
            nama_petugas VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inventaris_cek_barang (barang_id),
            CONSTRAINT fk_inventaris_cek_barang FOREIGN KEY (barang_id) REFERENCES inventaris_barang(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('ALTER TABLE inventaris_barang MODIFY kode VARCHAR(64) NOT NULL');
    $pdo->exec('ALTER TABLE inventaris_barang ADD COLUMN IF NOT EXISTS nama_label VARCHAR(160) NULL');
    $pdo->exec('ALTER TABLE inventaris_barang ADD COLUMN IF NOT EXISTS asal_barang VARCHAR(160) NULL');
    $pdo->exec('ALTER TABLE inventaris_barang ADD COLUMN IF NOT EXISTS status_barang VARCHAR(40) NOT NULL DEFAULT \'Tersedia\'');
    $pdo->exec('ALTER TABLE inventaris_barang_cek ADD COLUMN IF NOT EXISTS komponen_json TEXT NULL');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS inventaris_barang_komponen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barang_id INT NOT NULL,
            nama VARCHAR(160) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT \'Lengkap\',
            catatan VARCHAR(255) NULL,
            urutan INT NOT NULL DEFAULT 0,
            KEY idx_inventaris_komponen_barang (barang_id),
            CONSTRAINT fk_inventaris_komponen_barang FOREIGN KEY (barang_id) REFERENCES inventaris_barang(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $ready = true;
}

/** @return array{kode:string,error:string} */
function inventaris_barang_kode_dari_input(string $raw): array
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return ['kode' => '', 'error' => ''];
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/', $raw)) {
        return ['kode' => '', 'error' => 'Kode barang hanya boleh huruf, angka, tanda minus, dan garis bawah.'];
    }

    return ['kode' => $raw, 'error' => ''];
}

function inventaris_barang_kode_dipakai(PDO $pdo, string $kode, int $kecualiId = 0): bool
{
    $st = $pdo->prepare('SELECT id FROM inventaris_barang WHERE kode = :kode AND id <> :id LIMIT 1');
    $st->execute(['kode' => $kode, 'id' => $kecualiId]);

    return $st->fetchColumn() !== false;
}

function inventaris_barang_generate_kode(PDO $pdo): string
{
    $prefix = 'BRG-' . date('Y') . '-';
    $stmt = $pdo->prepare('SELECT kode FROM inventaris_barang WHERE kode LIKE :pfx ORDER BY id DESC LIMIT 1');
    $stmt->execute(['pfx' => $prefix . '%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $seq = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $seq = (int) $m[1] + 1;
    }

    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function inventaris_barang_extract_kode(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/[?&]kode=([^&#]+)/', $raw, $m)) {
        $raw = rawurldecode($m[1]);
    }

    return inventaris_barang_kode_dari_input($raw)['kode'];
}

function inventaris_barang_cek_url(string $kode): string
{
    if (!function_exists('app_public_url')) {
        require_once __DIR__ . '/app_path.php';
    }

    return app_public_url() . app_href('/inventaris/cek.php?kode=' . rawurlencode($kode));
}

/**
 * @param array<string, mixed> $post
 * @return array{nama:string,nama_label:?string,kategori:string,lokasi:?string,merk:?string,keterangan:?string,jumlah:int,satuan:string,kondisi:string,status_kelengkapan:string,status_barang:string,catatan_kekurangan:?string,penanggung_jawab:?string,asal_barang:?string,tanggal_cek:?string,is_aktif:int}
 */
function inventaris_barang_normalize_input(array $post): array
{
    $tanggal = trim((string) ($post['tanggal_cek'] ?? ''));
    if ($tanggal !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = '';
    }
    $jumlah = (int) ($post['jumlah'] ?? 1);
    if ($jumlah < 1) {
        $jumlah = 1;
    }
    if ($jumlah > 99999) {
        $jumlah = 99999;
    }
    $satuan = inventaris_barang_clip((string) ($post['satuan'] ?? ''), 40);

    return [
        'nama' => inventaris_barang_clip((string) ($post['nama'] ?? ''), 160),
        'nama_label' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['nama_label'] ?? ''), 160)),
        'kategori' => inventaris_barang_pick((string) ($post['kategori'] ?? ''), inventaris_barang_kategori_options(), 'Lainnya'),
        'lokasi' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['lokasi'] ?? ''), 160)),
        'merk' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['merk'] ?? ''), 120)),
        'keterangan' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['keterangan'] ?? ''), 500)),
        'jumlah' => $jumlah,
        'satuan' => $satuan !== '' ? $satuan : 'unit',
        'kondisi' => inventaris_barang_pick((string) ($post['kondisi'] ?? ''), inventaris_barang_kondisi_options(), 'Baik'),
        'status_kelengkapan' => inventaris_barang_pick((string) ($post['status_kelengkapan'] ?? ''), inventaris_barang_status_options(), 'Lengkap'),
        'status_barang' => inventaris_barang_pick((string) ($post['status_barang'] ?? ''), inventaris_barang_status_pakai_options(), 'Tersedia'),
        'catatan_kekurangan' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['catatan_kekurangan'] ?? ''), 500)),
        'penanggung_jawab' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['penanggung_jawab'] ?? ''), 120)),
        'asal_barang' => inventaris_barang_nullable(inventaris_barang_clip((string) ($post['asal_barang'] ?? ''), 160)),
        'tanggal_cek' => $tanggal !== '' ? $tanggal : null,
        'is_aktif' => (int) ($post['is_aktif'] ?? 0) === 1 ? 1 : 0,
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string,id:int}
 */
/**
 * @param array<string, mixed> $post
 * @return list<array{nama:string,status:string,catatan:?string}>
 */
function inventaris_barang_parse_komponen(array $post): array
{
    $names = $post['komponen_nama'] ?? [];
    if (!is_array($names)) {
        return [];
    }
    $statuses = is_array($post['komponen_status'] ?? null) ? $post['komponen_status'] : [];
    $notes = is_array($post['komponen_catatan'] ?? null) ? $post['komponen_catatan'] : [];
    $out = [];
    foreach ($names as $i => $nama) {
        $nama = inventaris_barang_clip((string) $nama, 160);
        if ($nama === '') {
            continue;
        }
        $out[] = [
            'nama' => $nama,
            'status' => inventaris_barang_pick((string) ($statuses[$i] ?? ''), inventaris_barang_status_options(), 'Lengkap'),
            'catatan' => inventaris_barang_nullable(inventaris_barang_clip((string) ($notes[$i] ?? ''), 255)),
        ];
    }

    return $out;
}

/**
 * @param list<array{nama:string,status:string,catatan:?string}> $komponen
 */
function inventaris_barang_status_dari_komponen(array $komponen, string $fallback): string
{
    if ($komponen === []) {
        return $fallback;
    }
    foreach ($komponen as $row) {
        if (($row['status'] ?? '') !== 'Lengkap') {
            return 'Tidak lengkap';
        }
    }

    return 'Lengkap';
}

/**
 * @param list<array{nama:string,status:string,catatan:?string}> $komponen
 */
function inventaris_barang_replace_komponen(PDO $pdo, int $barangId, array $komponen): void
{
    $pdo->prepare('DELETE FROM inventaris_barang_komponen WHERE barang_id = :id')->execute(['id' => $barangId]);
    if ($komponen === []) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO inventaris_barang_komponen (barang_id, nama, status, catatan, urutan)
        VALUES (:barang_id, :nama, :status, :catatan, :urutan)
    ');
    foreach ($komponen as $i => $row) {
        $ins->execute([
            'barang_id' => $barangId,
            'nama' => $row['nama'],
            'status' => $row['status'],
            'catatan' => $row['catatan'],
            'urutan' => $i,
        ]);
    }
}

/** @return list<array<string, mixed>> */
function inventaris_barang_komponen_list(PDO $pdo, int $barangId): array
{
    if ($barangId <= 0) {
        return [];
    }
    ensure_inventaris_barang_tables($pdo);
    $st = $pdo->prepare('
        SELECT id, barang_id, nama, status, catatan, urutan
        FROM inventaris_barang_komponen
        WHERE barang_id = :id
        ORDER BY urutan ASC, id ASC
    ');
    $st->execute(['id' => $barangId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array{nama:string,status:string,catatan:?string}> */
function inventaris_barang_komponen_snapshot(PDO $pdo, int $barangId): array
{
    $rows = [];
    foreach (inventaris_barang_komponen_list($pdo, $barangId) as $row) {
        $rows[] = [
            'nama' => (string) $row['nama'],
            'status' => (string) $row['status'],
            'catatan' => inventaris_barang_nullable((string) ($row['catatan'] ?? '')),
        ];
    }

    return $rows;
}

function inventaris_barang_save(PDO $pdo, array $post, int $id = 0): array
{
    ensure_inventaris_barang_tables($pdo);
    $data = inventaris_barang_normalize_input($post);
    if ($data['nama'] === '') {
        return ['ok' => false, 'message' => 'Nama barang wajib diisi.', 'id' => 0];
    }
    $kodeInput = inventaris_barang_kode_dari_input((string) ($post['kode'] ?? ''));
    if ($kodeInput['error'] !== '') {
        return ['ok' => false, 'message' => $kodeInput['error'], 'id' => 0];
    }
    $komponen = inventaris_barang_parse_komponen($post);
    $data['status_kelengkapan'] = inventaris_barang_status_dari_komponen($komponen, $data['status_kelengkapan']);

    if ($id > 0) {
        $ada = inventaris_barang_find($pdo, $id);
        if ($ada === null) {
            return ['ok' => false, 'message' => 'Barang tidak ditemukan.', 'id' => 0];
        }
        $kode = $kodeInput['kode'] !== '' ? $kodeInput['kode'] : (string) $ada['kode'];
        if (inventaris_barang_kode_dipakai($pdo, $kode, $id)) {
            return ['ok' => false, 'message' => 'Kode barang sudah dipakai.', 'id' => 0];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('
                UPDATE inventaris_barang
                SET kode = :kode, nama = :nama, nama_label = :nama_label, kategori = :kategori, lokasi = :lokasi, merk = :merk, keterangan = :keterangan,
                    jumlah = :jumlah, satuan = :satuan, kondisi = :kondisi, status_kelengkapan = :status_kelengkapan,
                    status_barang = :status_barang, catatan_kekurangan = :catatan_kekurangan, penanggung_jawab = :penanggung_jawab,
                    asal_barang = :asal_barang, tanggal_cek = :tanggal_cek, is_aktif = :is_aktif
                WHERE id = :id
            ')->execute($data + ['kode' => $kode, 'id' => $id]);
            inventaris_barang_replace_komponen($pdo, $id, $komponen);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Gagal menyimpan barang.', 'id' => 0];
        }

        return ['ok' => true, 'message' => 'Data barang diperbarui.', 'id' => $id];
    }

    $manual = $kodeInput['kode'];
    $attempts = $manual !== '' ? 1 : 3;
    $lastError = 'Gagal menyimpan barang.';
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $kode = $manual !== '' ? $manual : inventaris_barang_generate_kode($pdo);
        try {
            $pdo->beginTransaction();
            $pdo->prepare('
                INSERT INTO inventaris_barang (
                    kode, nama, nama_label, kategori, lokasi, merk, keterangan, jumlah, satuan, kondisi,
                    status_kelengkapan, status_barang, catatan_kekurangan, penanggung_jawab, asal_barang, tanggal_cek, is_aktif
                ) VALUES (
                    :kode, :nama, :nama_label, :kategori, :lokasi, :merk, :keterangan, :jumlah, :satuan, :kondisi,
                    :status_kelengkapan, :status_barang, :catatan_kekurangan, :penanggung_jawab, :asal_barang, :tanggal_cek, :is_aktif
                )
            ')->execute($data + ['kode' => $kode]);
            $newId = (int) $pdo->lastInsertId();
            inventaris_barang_replace_komponen($pdo, $newId, $komponen);
            $pdo->commit();

            return ['ok' => true, 'message' => 'Barang disimpan dengan kode ' . $kode . '. Unduh kode QR lalu tempelkan pada barang.', 'id' => $newId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dup = str_contains(strtolower($e->getMessage()), 'duplicate');
            if ($manual !== '' && $dup) {
                return ['ok' => false, 'message' => 'Kode barang sudah dipakai.', 'id' => 0];
            }
            $lastError = $dup ? 'Kode barang sudah dipakai.' : 'Gagal menyimpan barang.';
            if (!$dup) {
                break;
            }
        }
    }

    return ['ok' => false, 'message' => $lastError, 'id' => 0];
}

function inventaris_barang_delete(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    ensure_inventaris_barang_tables($pdo);
    $st = $pdo->prepare('DELETE FROM inventaris_barang WHERE id = :id');
    $st->execute(['id' => $id]);

    return $st->rowCount() > 0;
}

/** @return array<string, mixed>|null */
function inventaris_barang_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    ensure_inventaris_barang_tables($pdo);
    $st = $pdo->prepare('SELECT * FROM inventaris_barang WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function inventaris_barang_find_by_kode(PDO $pdo, string $kode): ?array
{
    $kode = inventaris_barang_extract_kode($kode);
    if ($kode === '') {
        return null;
    }
    ensure_inventaris_barang_tables($pdo);
    $st = $pdo->prepare('SELECT * FROM inventaris_barang WHERE kode = :kode LIMIT 1');
    $st->execute(['kode' => $kode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function inventaris_barang_by_ids(PDO $pdo, array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    $clean = array_values($clean);
    if ($clean === []) {
        return [];
    }
    ensure_inventaris_barang_tables($pdo);
    $holders = [];
    $params = [];
    foreach ($clean as $i => $id) {
        $key = 'id' . $i;
        $holders[] = ':' . $key;
        $params[$key] = $id;
    }
    $st = $pdo->prepare('SELECT * FROM inventaris_barang WHERE id IN (' . implode(',', $holders) . ') ORDER BY nama ASC, id ASC');
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array{q?:string,status?:string,status_pakai?:string,kondisi?:string,nonaktif?:string} $filter
 * @return list<array<string, mixed>>
 */
function inventaris_barang_list(PDO $pdo, array $filter = []): array
{
    ensure_inventaris_barang_tables($pdo);
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filter['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(b.nama LIKE :q1 OR b.kode LIKE :q2 OR b.lokasi LIKE :q3 OR b.merk LIKE :q4 OR b.asal_barang LIKE :q5)';
        $like = '%' . $q . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
        $params['q4'] = $like;
        $params['q5'] = $like;
    }
    $status = trim((string) ($filter['status'] ?? ''));
    if (in_array($status, inventaris_barang_status_options(), true)) {
        $where[] = 'b.status_kelengkapan = :status';
        $params['status'] = $status;
    }
    $kondisi = trim((string) ($filter['kondisi'] ?? ''));
    if (in_array($kondisi, inventaris_barang_kondisi_options(), true)) {
        $where[] = 'b.kondisi = :kondisi';
        $params['kondisi'] = $kondisi;
    }
    $statusPakai = trim((string) ($filter['status_pakai'] ?? ''));
    if (in_array($statusPakai, inventaris_barang_status_pakai_options(), true)) {
        $where[] = 'b.status_barang = :status_pakai';
        $params['status_pakai'] = $statusPakai;
    }
    if ((string) ($filter['nonaktif'] ?? '') !== '1') {
        $where[] = 'b.is_aktif = 1';
    }
    $st = $pdo->prepare('
        SELECT b.*,
            COALESCE(k.total_komponen, 0) AS komponen_total,
            COALESCE(k.lengkap_komponen, 0) AS komponen_lengkap
        FROM inventaris_barang b
        LEFT JOIN (
            SELECT barang_id,
                COUNT(*) AS total_komponen,
                SUM(CASE WHEN status = \'Lengkap\' THEN 1 ELSE 0 END) AS lengkap_komponen
            FROM inventaris_barang_komponen
            GROUP BY barang_id
        ) k ON k.barang_id = b.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.nama ASC, b.id DESC
    ');
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{aktif:int,lengkap:int,kurang:int} */
function inventaris_barang_ringkas(PDO $pdo): array
{
    ensure_inventaris_barang_tables($pdo);
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN is_aktif = 1 THEN 1 ELSE 0 END) AS aktif,
            SUM(CASE WHEN is_aktif = 1 AND status_kelengkapan = 'Lengkap' THEN 1 ELSE 0 END) AS lengkap,
            SUM(CASE WHEN is_aktif = 1 AND status_kelengkapan = 'Tidak lengkap' THEN 1 ELSE 0 END) AS kurang
        FROM inventaris_barang
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'aktif' => (int) ($row['aktif'] ?? 0),
        'lengkap' => (int) ($row['lengkap'] ?? 0),
        'kurang' => (int) ($row['kurang'] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function inventaris_barang_catat_cek(PDO $pdo, int $barangId, array $post, int $userId, string $namaPetugas): array
{
    ensure_inventaris_barang_tables($pdo);
    $barang = inventaris_barang_find($pdo, $barangId);
    if ($barang === null) {
        return ['ok' => false, 'message' => 'Barang tidak ditemukan.'];
    }
    $status = inventaris_barang_pick((string) ($post['status_kelengkapan'] ?? ''), inventaris_barang_status_options(), '');
    if ($status === '') {
        return ['ok' => false, 'message' => 'Status kelengkapan wajib dipilih.'];
    }
    $kondisi = (string) ($barang['kondisi'] ?? 'Baik');
    $namaPetugas = inventaris_barang_clip($namaPetugas, 120);
    $hari = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO inventaris_barang_cek (barang_id, kondisi, status_kelengkapan, catatan, komponen_json, user_id, nama_petugas)
            VALUES (:barang_id, :kondisi, :status_kelengkapan, NULL, NULL, :user_id, :nama_petugas)
        ')->execute([
            'barang_id' => $barangId,
            'kondisi' => $kondisi,
            'status_kelengkapan' => $status,
            'user_id' => $userId > 0 ? $userId : null,
            'nama_petugas' => $namaPetugas !== '' ? $namaPetugas : null,
        ]);
        $pdo->prepare('
            UPDATE inventaris_barang
            SET status_kelengkapan = :status_kelengkapan, tanggal_cek = :tanggal_cek
            WHERE id = :id
        ')->execute([
            'status_kelengkapan' => $status,
            'tanggal_cek' => $hari,
            'id' => $barangId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan hasil pengecekan.'];
    }

    return ['ok' => true, 'message' => 'Hasil pengecekan tercatat.'];
}

/** @return list<array<string, mixed>> */
function inventaris_barang_riwayat(PDO $pdo, int $barangId, int $limit = 20): array
{
    if ($barangId <= 0) {
        return [];
    }
    ensure_inventaris_barang_tables($pdo);
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare('
        SELECT id, kondisi, status_kelengkapan, catatan, komponen_json, nama_petugas, created_at
        FROM inventaris_barang_cek
        WHERE barang_id = :id
        ORDER BY id DESC
        LIMIT ' . $limit . '
    ');
    $st->execute(['id' => $barangId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function inventaris_barang_boleh_mengelola(): bool
{
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return false;
    }
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }

    return function_exists('user_can_access_permission_key')
        && user_can_access_permission_key('inventaris_barang');
}

function inventaris_barang_badge_status(string $status): string
{
    return $status === 'Lengkap' ? 'text-bg-success' : 'text-bg-warning';
}

function inventaris_barang_badge_status_pakai(string $status): string
{
    return match ($status) {
        'Tersedia' => 'text-bg-success',
        'Dipakai' => 'text-bg-primary',
        'Sedang diperbaiki', 'Menunggu suku cadang' => 'text-bg-warning',
        'Dipinjam' => 'text-bg-info',
        default => 'text-bg-secondary',
    };
}

function inventaris_barang_badge_kondisi(string $kondisi): string
{
    return match ($kondisi) {
        'Baik' => 'text-bg-success',
        'Rusak ringan' => 'text-bg-warning',
        'Rusak berat' => 'text-bg-danger',
        'Hilang' => 'text-bg-dark',
        default => 'text-bg-secondary',
    };
}
