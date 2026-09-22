<?php

declare(strict_types=1);

function poin_rule_allows_modifier(int $bobotPoin, string $kategori): bool
{
    if ($bobotPoin < 3) {
        return false;
    }
    if (stripos($kategori, 'D. Ringan') !== false || stripos($kategori, 'Ringan') === 0) {
        return false;
    }

    return true;
}

function poin_rule_allows_modifier_row(?array $rule): bool
{
    if ($rule === null) {
        return false;
    }

    return poin_rule_allows_modifier(
        (int) ($rule['bobot_poin'] ?? 0),
        (string) ($rule['kategori'] ?? '')
    );
}

/**
 * Satu modifier aktif: peringan ATAU pemberat (bukan keduanya).
 */
function poin_apply_modifier(int $base, ?int $peringanId, ?int $pemberatId, PDO $pdo): int
{
    $base = max(0, $base);
    if ($base === 0) {
        return 0;
    }
    if ($peringanId !== null && $peringanId > 0 && $pemberatId !== null && $pemberatId > 0) {
        $pemberatId = null;
    }

    $persen = 0;
    if ($peringanId !== null && $peringanId > 0 && table_exists($pdo, 'point_peringan')) {
        $st = $pdo->prepare('SELECT efek_persen FROM point_peringan WHERE id = :id AND is_active = 1');
        $st->execute(['id' => $peringanId]);
        $persen = (int) ($st->fetchColumn() ?: 0);
    } elseif ($pemberatId !== null && $pemberatId > 0 && table_exists($pdo, 'point_pemberat')) {
        $st = $pdo->prepare('SELECT efek_persen FROM point_pemberat WHERE id = :id AND is_active = 1');
        $st->execute(['id' => $pemberatId]);
        $persen = (int) ($st->fetchColumn() ?: 0);
    }

    if ($persen === 0) {
        return $base;
    }

    $final = (int) round($base * (1 + $persen / 100));

    return max(1, $final);
}
