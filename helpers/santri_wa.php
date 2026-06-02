<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wali.php';

/**
 * Nomor WA wali efektif: daftar wali_santri (jika terhubung) → no_wa_wali → kontak ayah/ibu.
 */
function santri_resolve_no_wa_wali(PDO $pdo, int|array $santri): string
{
    if (is_int($santri)) {
        if ($santri <= 0 || !table_exists($pdo, 'santri')) {
            return '';
        }
        $cols = ['id', 'no_wa_wali', 'nama_ayah', 'no_kontak_ayah', 'nama_ibu', 'no_kontak_ibu'];
        if (column_exists($pdo, 'santri', 'wali_santri_id')) {
            $cols[] = 'wali_santri_id';
        }
        $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santri]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return '';
        }

        return santri_resolve_no_wa_wali($pdo, $row);
    }

    $wid = (int) ($santri['wali_santri_id'] ?? 0);
    if ($wid > 0 && table_exists($pdo, 'wali_santri')) {
        $stW = $pdo->prepare('SELECT no_wa FROM wali_santri WHERE id = :id LIMIT 1');
        $stW->execute(['id' => $wid]);
        $waWali = wali_santri_normalize_wa_digits((string) ($stW->fetchColumn() ?: ''));
        if ($waWali !== '') {
            return $waWali;
        }
    }

    $waMain = column_exists($pdo, 'santri', 'no_wa_wali')
        ? wali_santri_normalize_wa_digits((string) ($santri['no_wa_wali'] ?? ''))
        : '';
    if ($waMain !== '') {
        return $waMain;
    }

    foreach (['no_kontak_ayah', 'no_kontak_ibu'] as $col) {
        if (!column_exists($pdo, 'santri', $col)) {
            continue;
        }
        $wa = wali_santri_normalize_wa_digits((string) ($santri[$col] ?? ''));
        if ($wa !== '') {
            return $wa;
        }
    }

    return '';
}
