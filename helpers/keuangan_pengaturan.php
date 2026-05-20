<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_alokasi.php';

/** @return array{ok:bool,message:string} */
function keuangan_save_periode_settings(PDO $pdo, array $post): array
{
    $mulai = (int) ($post['keuangan_periode_mulai'] ?? 0);
    $selesai = (int) ($post['keuangan_periode_selesai'] ?? 0);
    if ($mulai < 2000 || $mulai > 2100) {
        return ['ok' => false, 'message' => 'Tahun ajaran mulai tidak valid.'];
    }
    if ($selesai < $mulai) {
        $selesai = $mulai + 1;
    }
    save_setting($pdo, 'keuangan_periode_mulai', (string) $mulai);
    save_setting($pdo, 'keuangan_periode_selesai', (string) $selesai);

    return ['ok' => true, 'message' => 'Periode tahun ajaran disimpan (' . $mulai . '/' . $selesai . ').'];
}

/** @return array{ok:bool,message:string} */
function keuangan_save_tarif_settings(PDO $pdo, array $post): array
{
    $fees = $post['fee'] ?? [];
    if (!is_array($fees)) {
        return ['ok' => false, 'message' => 'Data tarif tidak valid.'];
    }
    $tiers = ['muadalah', 'wustho', 'ulya'];
    $defs = keuangan_biaya_definitions();
    $slugValid = [];
    foreach ($defs as $def) {
        $slugValid[(string) $def['slug']] = true;
    }

    foreach ($fees as $slug => $tierRows) {
        if (!isset($slugValid[$slug]) || !is_array($tierRows)) {
            continue;
        }
        foreach ($tiers as $tier) {
            if (!array_key_exists($tier, $tierRows)) {
                continue;
            }
            $nom = keuangan_money_input_to_int((string) $tierRows[$tier]);
            save_setting($pdo, 'keuangan_fee_' . $slug . '_' . $tier, (string) max(0, $nom));
        }
    }

    return ['ok' => true, 'message' => 'Tarif komponen biaya berhasil disimpan.'];
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_akun_all(PDO $pdo): array
{
    ensure_keuangan_transaksi_tables($pdo);
    if (!table_exists($pdo, 'keuangan_akun')) {
        return [];
    }

    return $pdo->query('
        SELECT id, jenis_akun, nama_akun, nama_bank, no_rekening, atas_nama,
               opening_balance, is_default, is_active
        FROM keuangan_akun
        ORDER BY is_default DESC, is_active DESC, jenis_akun ASC, id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,message:string} */
function keuangan_save_akun(PDO $pdo, array $post): array
{
    ensure_keuangan_transaksi_tables($pdo);
    $id = (int) ($post['akun_id'] ?? 0);
    $jenis = strtoupper(trim((string) ($post['jenis_akun'] ?? 'KAS')));
    $nama = trim((string) ($post['nama_akun'] ?? ''));
    $namaBank = trim((string) ($post['nama_bank'] ?? ''));
    $noRek = trim((string) ($post['no_rekening'] ?? ''));
    $atasNama = trim((string) ($post['atas_nama'] ?? ''));
    $opening = keuangan_money_input_to_int((string) ($post['opening_balance'] ?? '0'));
    $isDefault = !empty($post['is_default']);
    $isActive = !isset($post['is_active']) || (string) $post['is_active'] === '1';

    if (!in_array($jenis, ['KAS', 'BANK', 'E-WALLET'], true)) {
        $jenis = 'KAS';
    }
    if ($nama === '') {
        return ['ok' => false, 'message' => 'Nama akun wajib diisi.'];
    }

    if ($isDefault) {
        $pdo->exec('UPDATE keuangan_akun SET is_default = 0');
    }

    if ($id > 0) {
        $pdo->prepare('
            UPDATE keuangan_akun SET
                jenis_akun = :jenis, nama_akun = :nama, nama_bank = :nama_bank,
                no_rekening = :no_rekening, atas_nama = :atas_nama,
                opening_balance = :opening, is_default = :is_default, is_active = :is_active
            WHERE id = :id
        ')->execute([
            'jenis' => $jenis,
            'nama' => $nama,
            'nama_bank' => $namaBank !== '' ? $namaBank : null,
            'no_rekening' => $noRek !== '' ? $noRek : null,
            'atas_nama' => $atasNama !== '' ? $atasNama : null,
            'opening' => $opening,
            'is_default' => $isDefault ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);

        return ['ok' => true, 'message' => 'Akun kas/bank diperbarui.'];
    }

    $pdo->prepare('
        INSERT INTO keuangan_akun (jenis_akun, nama_akun, nama_bank, no_rekening, atas_nama, opening_balance, is_default, is_active)
        VALUES (:jenis, :nama, :nama_bank, :no_rekening, :atas_nama, :opening, :is_default, 1)
    ')->execute([
        'jenis' => $jenis,
        'nama' => $nama,
        'nama_bank' => $namaBank !== '' ? $namaBank : null,
        'no_rekening' => $noRek !== '' ? $noRek : null,
        'atas_nama' => $atasNama !== '' ? $atasNama : null,
        'opening' => $opening,
        'is_default' => $isDefault ? 1 : 0,
    ]);

    return ['ok' => true, 'message' => 'Akun kas/bank ditambahkan.'];
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_alokasi_all(PDO $pdo): array
{
    ensure_keuangan_transaksi_tables($pdo);
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return [];
    }

    return $pdo->query('
        SELECT id, nama_komponen, kategori, persen, urutan, is_active
        FROM keuangan_alokasi
        ORDER BY urutan ASC, nama_komponen ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,message:string} */
function keuangan_save_alokasi(PDO $pdo, array $post): array
{
    ensure_keuangan_transaksi_tables($pdo);
    $id = (int) ($post['alokasi_id'] ?? 0);
    $nama = trim((string) ($post['nama_komponen'] ?? ''));
    $kategori = trim((string) ($post['kategori'] ?? ''));
    $persen = (float) str_replace(',', '.', (string) ($post['persen'] ?? '0'));
    $urutan = (int) ($post['urutan'] ?? 0);
    $isActive = !isset($post['is_active']) || (string) $post['is_active'] === '1';

    if ($nama === '' || $kategori === '') {
        return ['ok' => false, 'message' => 'Nama komponen dan kategori wajib diisi.'];
    }
    if ($persen < 0) {
        return ['ok' => false, 'message' => 'Persentase tidak boleh negatif.'];
    }

    $validasi = keuangan_alokasi_validate_persen($pdo, $persen, $id, $isActive);
    if (!$validasi['ok']) {
        return ['ok' => false, 'message' => $validasi['message']];
    }

    if ($id > 0) {
        $pdo->prepare('
            UPDATE keuangan_alokasi SET nama_komponen = :nama, kategori = :kat, persen = :persen, urutan = :urutan, is_active = :aktif
            WHERE id = :id
        ')->execute([
            'nama' => $nama,
            'kat' => $kategori,
            'persen' => $persen,
            'urutan' => $urutan,
            'aktif' => $isActive ? 1 : 0,
            'id' => $id,
        ]);

        return ['ok' => true, 'message' => 'Alokasi syahriyah diperbarui.'];
    }

    $pdo->prepare('
        INSERT INTO keuangan_alokasi (nama_komponen, kategori, persen, urutan, is_active)
        VALUES (:nama, :kat, :persen, :urutan, 1)
    ')->execute([
        'nama' => $nama,
        'kat' => $kategori,
        'persen' => $persen,
        'urutan' => $urutan,
    ]);

    return ['ok' => true, 'message' => 'Alokasi syahriyah ditambahkan.'];
}
