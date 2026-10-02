<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/keuangan_ta_context.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_kartu_syahriyah.php';
require_once __DIR__ . '/tagihan_khusus_wali.php';

function keuangan_rekap_tagihan_santri_slug_nama(string $nama): string
{
    $s = strtolower(trim($nama));
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? 'santri';
    $s = trim($s, '-');
    if ($s === '') {
        return 'santri';
    }

    return mb_substr($s, 0, 40);
}

/** Nama file unduhan aman. */
function keuangan_rekap_tagihan_santri_filename(array $santri, int $taMulai, int $taSelesai): string
{
    $nis = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($santri['nis'] ?? '0')) ?: '0';
    $slug = keuangan_rekap_tagihan_santri_slug_nama((string) ($santri['nama_santri'] ?? ''));

    return sprintf('kekurangan-%s-%s-TA%d-%d.html', $nis, $slug, $taMulai, $taSelesai);
}

/**
 * Hanya baris dengan kekurangan; bulan belum berjalan diabaikan.
 *
 * @param list<array<string,mixed>> $bulanRows
 * @param list<array<string,mixed>> $awalRows
 * @param list<array<string,mixed>> $tagihanKhusus
 * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
 */
function keuangan_rekap_tagihan_santri_filter_kekurangan(array $bulanRows, array $awalRows, array $tagihanKhusus): array
{
    $bulan = array_values(array_filter($bulanRows, static function (array $br): bool {
        if ((string) ($br['status'] ?? '') === 'belum') {
            return false;
        }

        return (int) ($br['sisa'] ?? 0) > 0;
    }));
    $awal = array_values(array_filter($awalRows, static function (array $ar): bool {
        return (int) ($ar['sisa'] ?? 0) > 0;
    }));
    $khusus = array_values(array_filter($tagihanKhusus, static function (array $tk): bool {
        if ((string) ($tk['status'] ?? '') === 'batal') {
            return false;
        }

        return tagihan_khusus_sisa($tk) > 0;
    }));

    return [$bulan, $awal, $khusus];
}

/**
 * Agregat kekurangan (input sudah difilter).
 *
 * @param list<array<string,mixed>> $bulanRows
 * @param list<array<string,mixed>> $awalRows
 * @param list<array<string,mixed>> $tagihanKhusus
 * @return array{bulanan_sisa:int,awal_sisa:int,khusus_sisa:int,grand_sisa:int}
 */
function keuangan_rekap_tagihan_santri_totals(array $bulanRows, array $awalRows, array $tagihanKhusus): array
{
    $bulananSisa = 0;
    foreach ($bulanRows as $br) {
        $bulananSisa += (int) ($br['sisa'] ?? 0);
    }
    $awalSisa = 0;
    foreach ($awalRows as $ar) {
        $awalSisa += (int) ($ar['sisa'] ?? 0);
    }
    $khususSisa = 0;
    foreach ($tagihanKhusus as $tk) {
        $khususSisa += tagihan_khusus_sisa($tk);
    }

    return [
        'bulanan_sisa' => $bulananSisa,
        'awal_sisa' => $awalSisa,
        'khusus_sisa' => $khususSisa,
        'grand_sisa' => $bulananSisa + $awalSisa + $khususSisa,
    ];
}

/**
 * @return list<array{label:string,sisa:int}>
 */
function keuangan_rekap_bulan_pos_sisa_lines(array $bulanRow): array
{
    $out = [];
    foreach (['saku' => 'Saku', 'makan' => 'Makan', 'syahriyah' => 'Syahriyah'] as $key => $label) {
        $cell = is_array($bulanRow[$key] ?? null) ? $bulanRow[$key] : [];
        $sisa = (int) ($cell['sisa'] ?? 0);
        if ($sisa > 0) {
            $out[] = ['label' => $label, 'sisa' => $sisa];
        }
    }

    return $out;
}

/** Status tampilan sopan untuk kekurangan awal tahun. */
function keuangan_rekap_awal_status_sopan(array $ar): string
{
    $paid = (int) ($ar['paid'] ?? 0);
    $sisa = (int) ($ar['sisa'] ?? 0);
    if ($sisa <= 0) {
        return 'Lunas';
    }
    if ($paid <= 0) {
        return 'Belum lunas';
    }

    return 'Sebagian';
}

/**
 * @param array<string, mixed> $pack
 */
function keuangan_rekap_tagihan_santri_intro_html(PDO $pdo, array $pack): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $santri = (array) ($pack['santri'] ?? []);
    $ponpes = (array) ($pack['ponpes'] ?? []);
    $ta = (array) ($pack['ta'] ?? []);
    $totals = (array) ($pack['totals'] ?? []);
    $grandSisa = (int) ($totals['grand_sisa'] ?? 0);
    $namaSantri = trim((string) ($santri['nama_santri'] ?? ''));
    if ($namaSantri === '') {
        $namaSantri = 'putra/putri Bapak/Ibu';
    }
    $namaPonpes = trim((string) ($ponpes['nama'] ?? app_brand_nama_ponpes($pdo, 'Pondok Pesantren')));
    $taLabel = (string) ($ta['label'] ?? '');
    $ketKeuangan = trim((string) app_setting(
        $pdo,
        'keterangan_pengurus_bidang_keuangan',
        'Bertanggung jawab atas administrasi keuangan dan tagihan santri.'
    ));

    ob_start();
    ?>
    <section class="rekap-intro" aria-label="Salam pembuka">
        <p class="rekap-salam">Assalamu&rsquo;alaikum Wr. Wb.</p>
        <p>Nyuwun pangapunten, kami matur datang kepada Bapak/Ibu wali santri <strong><?= $h($namaSantri) ?></strong>.</p>
        <p>Atas nama Pengurus <strong><?= $h($namaPonpes) ?></strong>, <em>Pengurus Bidang Keuangan</em>,<?php if ($ketKeuangan !== ''): ?>
            <span class="rekap-ket-keuangan"><?= $h($ketKeuangan) ?></span><?php endif; ?>
            kami bermaksud memberitahukan kekurangan pembayaran putra/putri Bapak/Ibu<?= $taLabel !== '' ? ' pada tahun ajaran <strong>' . $h($taLabel) . '</strong>' : '' ?>.</p>
        <?php if ($grandSisa <= 0): ?>
            <p class="rekap-empty-state"><strong>Alhamdulillah</strong>, saat ini tidak ada kekurangan tagihan yang perlu dilaporkan untuk santri ini.</p>
        <?php else: ?>
            <p>Berikut rincian kekurangan yang masih perlu diselesaikan:</p>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

/**
 * @param array<string, mixed> $pack
 */
function keuangan_rekap_tagihan_santri_outro_html(PDO $pdo, array $pack): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $totals = (array) ($pack['totals'] ?? []);
    if ((int) ($totals['grand_sisa'] ?? 0) <= 0) {
        return '';
    }
    $namaPonpes = trim((string) (($pack['ponpes']['nama'] ?? '') ?: app_brand_nama_ponpes($pdo, 'Pondok Pesantren')));

    return '<section class="rekap-outro"><p>Berkenaan dengan hal tersebut, kami mohon maaf apabila baru saat ini dapat melaporkan kepada Bapak/Ibu. '
        . 'Atas pengertian dan kerja samanya, kami ucapkan terima kasih.</p>'
        . '<p class="rekap-outro-sign">Pengurus Bidang Keuangan<br><strong>' . $h($namaPonpes) . '</strong></p></section>';
}

/**
 * @return array<string, mixed>|null
 */
function keuangan_rekap_tagihan_santri_build(PDO $pdo, int $santriId): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return null;
    }
    ensure_santri_identity_columns($pdo);
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare('SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan, kategori_kelas FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $santri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$santri) {
        return null;
    }

    keuangan_ensure_schema_deferred($pdo);
    $keuanganTa = keuangan_ta_resolve($pdo);
    $taMulai = (int) $keuanganTa['mulai'];
    $taSelesai = (int) $keuanganTa['selesai'];
    $berjalan = keuangan_periode_berjalan($pdo);
    $bulanBerjalan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));

    $bulanRowsRaw = keuangan_kartu_pembayaran_bulan_rows($pdo, $santriId, $taMulai, $taSelesai, $bulanBerjalan);
    $awalRowsRaw = keuangan_kartu_pembayaran_awal_tahun_rows($pdo, $santriId, $taMulai, $taSelesai);
    ensure_keuangan_tagihan_khusus_table($pdo);
    $tagihanKhususRaw = tagihan_khusus_list_admin($pdo, $santriId, 200);

    [$bulanRows, $awalRows, $tagihanKhusus] = keuangan_rekap_tagihan_santri_filter_kekurangan(
        $bulanRowsRaw,
        $awalRowsRaw,
        $tagihanKhususRaw
    );
    $totals = keuangan_rekap_tagihan_santri_totals($bulanRows, $awalRows, $tagihanKhusus);

    $logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
    $logoUrl = $logoPath !== '' ? app_asset_href($logoPath) : '';

    $cashlessOk = table_exists($pdo, 'cashless_accounts') || table_exists($pdo, 'cashless_transactions');
    $cashlessSaldo = 0;
    if ($cashlessOk) {
        require_once __DIR__ . '/cashless_koperasi.php';
        $cashlessSaldo = cashless_santri_saldo_tampil($pdo, $santriId);
    }

    return [
        'santri' => $santri,
        'totals' => $totals,
        'cashless_ok' => $cashlessOk,
        'cashless_saldo' => $cashlessSaldo,
        'ponpes' => [
            'nama' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
            'alamat' => trim((string) app_setting($pdo, 'alamat_ponpes', '')),
            'logo_url' => $logoUrl,
        ],
        'ta' => [
            'mulai' => $taMulai,
            'selesai' => $taSelesai,
            'label' => (string) ($keuanganTa['label'] ?? ($taMulai . '/' . $taSelesai)),
        ],
        'bulan_rows' => $bulanRows,
        'awal_rows' => $awalRows,
        'tagihan_khusus' => $tagihanKhusus,
        'generated_at' => date('Y-m-d H:i'),
        'filename' => keuangan_rekap_tagihan_santri_filename($santri, $taMulai, $taSelesai),
    ];
}

function keuangan_rekap_tagihan_santri_print_css(): string
{
    return '
        *, *::before, *::after { box-sizing: border-box; }
        body.keuangan-module.rekap-tagihan-santri-page { margin: 0; padding: 8px 10px; overflow-x: hidden; max-width: 100vw; }
        .rekap-wrap { max-width: 520px; margin: 0 auto; width: 100%; }
        .rekap-header { text-align: center; margin-bottom: 0.85rem; padding-bottom: 0.65rem; border-bottom: 2px solid #0f172a; }
        .rekap-header img { max-height: 48px; margin-bottom: 0.5rem; max-width: 100%; }
        .rekap-header h1 { font-size: 1rem; font-weight: 700; margin: 0 0 0.25rem; line-height: 1.35; }
        .rekap-header .sub { font-size: 0.78rem; color: #475569; margin: 0; }
        .rekap-meta { font-size: 0.72rem; color: #64748b; margin-bottom: 0.75rem; }
        .rekap-intro { font-size: 0.82rem; line-height: 1.55; margin-bottom: 1rem; color: #334155; }
        .rekap-intro p { margin: 0 0 0.55rem; }
        .rekap-salam { font-weight: 600; }
        .rekap-ket-keuangan { display: block; font-size: 0.75rem; color: #64748b; margin: 0.25rem 0; font-style: italic; }
        .rekap-empty-state { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.65rem 0.85rem; color: #166534; }
        .rekap-outro { font-size: 0.82rem; line-height: 1.55; margin-top: 1rem; color: #334155; }
        .rekap-outro-sign { margin-top: 0.75rem; font-size: 0.78rem; color: #64748b; }
        .rekap-section { margin-bottom: 1rem; }
        .rekap-section h2 { font-size: 0.88rem; font-weight: 600; margin: 0 0 0.4rem; color: #0f172a; }
        .rekap-santri-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.8rem; font-size: 0.8rem; margin-bottom: 0.75rem; }
        .rekap-santri-box strong { font-size: 0.92rem; display: block; margin-bottom: 0.2rem; }
        .rekap-santri-saldo { margin-top: 0.45rem; font-weight: 600; color: #0f172a; }
        .rekap-santri-hint { margin: 0.25rem 0 0; font-size: 0.68rem; color: #64748b; line-height: 1.4; font-weight: 400; }
        .rekap-totals { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.6rem 0.8rem; font-size: 0.8rem; margin-bottom: 1rem; }
        .rekap-totals dl { margin: 0; }
        .rekap-totals .row-total { display: flex; justify-content: space-between; gap: 8px; padding: 0.18rem 0; }
        .rekap-totals .row-total dt { color: #334155; font-weight: 500; flex: 1; min-width: 0; }
        .rekap-totals .row-total dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
        .rekap-totals .row-grand { margin-top: 0.4rem; padding-top: 0.4rem; border-top: 2px solid #1e40af; }
        .rekap-totals .row-grand dt { font-weight: 700; color: #0f172a; }
        .rekap-totals .row-grand dd { font-weight: 800; color: #1e3a8a; font-size: 0.92rem; }
        table.rekap-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; table-layout: fixed; }
        table.rekap-table th, table.rekap-table td { border: 1px solid #cbd5e1; padding: 0.28rem 0.32rem; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
        table.rekap-table th { background: #f1f5f9; font-weight: 600; text-align: left; }
        table.rekap-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        table.rekap-table tfoot th, table.rekap-table tfoot td { background: #e2e8f0; font-weight: 700; }
        .rekap-pos-list { margin: 0.15rem 0 0; padding-left: 1rem; font-size: 0.68rem; color: #475569; }
        .rekap-pos-list li { margin-bottom: 0.1rem; }
        .rekap-khusus-card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.5rem 0.6rem; margin-bottom: 0.45rem; font-size: 0.76rem; background: #fff; }
        .rekap-khusus-card .trx-top { display: flex; justify-content: space-between; gap: 6px; font-weight: 600; margin-bottom: 0.2rem; }
        .rekap-khusus-card .trx-meta { color: #64748b; font-size: 0.7rem; margin-bottom: 0.15rem; }
        .rekap-footer { font-size: 0.65rem; color: #64748b; margin-top: 1rem; padding-top: 0.55rem; border-top: 1px solid #e2e8f0; line-height: 1.45; }
        .noprint-toolbar { margin: 8px 10px; max-width: 100%; }
        @media (max-width: 480px) {
            .rekap-wrap { max-width: 100%; }
            table.rekap-table { font-size: 0.67rem; }
            .rekap-section-khusus .rekap-table-desktop { display: none !important; }
            .rekap-section-khusus .rekap-cards-mobile { display: block !important; }
        }
        @media (min-width: 481px) {
            .rekap-cards-mobile { display: none !important; }
        }
        @media print {
            body.keuangan-module.rekap-tagihan-santri-page { margin: 8px; }
            .rekap-wrap { max-width: none; }
            .rekap-section-khusus .rekap-table-desktop { display: block !important; }
            .rekap-cards-mobile { display: none !important; }
        }
    ';
}

/**
 * @param array<string, mixed> $pack
 */
function keuangan_rekap_tagihan_santri_render_html(array $pack, bool $preview = false): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return '<!DOCTYPE html><html><body><p>Kesalahan konfigurasi.</p></body></html>';
    }

    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $fmt = static fn (int $n): string => keuangan_format_rupiah($n);

    $santri = (array) ($pack['santri'] ?? []);
    $ponpes = (array) ($pack['ponpes'] ?? []);
    $ta = (array) ($pack['ta'] ?? []);
    $bulanRows = (array) ($pack['bulan_rows'] ?? []);
    $awalRows = (array) ($pack['awal_rows'] ?? []);
    $tagihanKhusus = (array) ($pack['tagihan_khusus'] ?? []);
    $totals = (array) ($pack['totals'] ?? []);
    $grandSisa = (int) ($totals['grand_sisa'] ?? 0);
    $hasKekurangan = $grandSisa > 0;
    $cashlessOk = !empty($pack['cashless_ok']);
    $cashlessSaldo = (int) ($pack['cashless_saldo'] ?? 0);

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Pemberitahuan kekurangan — <?= $h((string) ($santri['nama_santri'] ?? '')) ?></title>
    <?= keuangan_typography_font_links() ?>
    <style><?= keuangan_typography_print_css() . keuangan_rekap_tagihan_santri_print_css() ?></style>
</head>
<body class="<?= $h(keuangan_body_class('rekap-tagihan-santri-page')) ?>">
<?php if ($preview): ?>
<div class="noprint noprint-toolbar">
    <button type="button" onclick="window.print()" style="padding:8px 14px;margin-right:8px;">Cetak / PDF</button>
    <a href="<?= $h(app_href('/pembayaran/kartu_syahriyah_santri.php?santri_id=' . (int) ($santri['id'] ?? 0))) ?>">Kembali ke kartu</a>
</div>
<?php endif; ?>
<div class="rekap-wrap">
    <header class="rekap-header">
        <?php if (($ponpes['logo_url'] ?? '') !== ''): ?>
            <img src="<?= $h((string) $ponpes['logo_url']) ?>" alt="">
        <?php endif; ?>
        <h1><?= $h((string) ($ponpes['nama'] ?? '')) ?></h1>
        <?php if (($ponpes['alamat'] ?? '') !== ''): ?>
            <p class="sub"><?= $h((string) $ponpes['alamat']) ?></p>
        <?php endif; ?>
        <p class="sub" style="margin-top:0.5rem;font-weight:600;color:#0f172a;">Pemberitahuan Kekurangan Pembayaran</p>
        <p class="sub">Tahun ajaran <?= $h((string) ($ta['label'] ?? '')) ?></p>
    </header>

    <p class="rekap-meta">Dicetak: <?= $h((string) ($pack['generated_at'] ?? '')) ?> WIB</p>

    <div class="rekap-santri-box">
        <strong><?= $h((string) ($santri['nama_santri'] ?? '-')) ?></strong>
        NIS <?= $h((string) ($santri['nis'] ?? '-')) ?> · <?= $h((string) ($santri['tingkatan'] ?? '-')) ?>
        <?php if ($cashlessOk): ?>
            <div class="rekap-santri-saldo">Saldo uang saku (cashless): <?= $fmt($cashlessSaldo) ?></div>
            <p class="rekap-santri-hint">Saldo saku terpisah dari tagihan syahriyah/makan; hanya untuk informasi wali.</p>
        <?php endif; ?>
    </div>

    <?= keuangan_rekap_tagihan_santri_intro_html($pdo, $pack) ?>

    <?php if ($hasKekurangan): ?>
    <section class="rekap-totals" aria-label="Ringkasan kekurangan">
        <dl>
            <div class="row-total">
                <dt>Total kekurangan bulanan</dt>
                <dd><?= $fmt((int) ($totals['bulanan_sisa'] ?? 0)) ?></dd>
            </div>
            <div class="row-total">
                <dt>Total kekurangan awal tahun</dt>
                <dd><?= $fmt((int) ($totals['awal_sisa'] ?? 0)) ?></dd>
            </div>
            <div class="row-total">
                <dt>Total kekurangan khusus wali</dt>
                <dd><?= ((int) ($totals['khusus_sisa'] ?? 0)) > 0 ? $fmt((int) $totals['khusus_sisa']) : '—' ?></dd>
            </div>
            <div class="row-total row-grand">
                <dt>Jumlah total kekurangan</dt>
                <dd><?= $fmt($grandSisa) ?></dd>
            </div>
        </dl>
    </section>

    <?php if ($bulanRows !== []): ?>
    <section class="rekap-section">
        <h2>Rincian kekurangan bulanan</h2>
        <table class="rekap-table">
            <thead>
                <tr>
                    <th>Bulan</th>
                    <th class="num">Kekurangan</th>
                    <th>Rincian</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bulanRows as $br):
                    $posLines = keuangan_rekap_bulan_pos_sisa_lines($br);
                    ?>
                    <tr>
                        <td><?= $h((string) ($br['label'] ?? '')) ?></td>
                        <td class="num"><?= $fmt((int) ($br['sisa'] ?? 0)) ?></td>
                        <td>
                            <?php if ($posLines === []): ?>
                                <span style="color:#64748b;">—</span>
                            <?php else: ?>
                                <ul class="rekap-pos-list">
                                    <?php foreach ($posLines as $pl): ?>
                                        <li><?= $h((string) $pl['label']) ?>: <?= $fmt((int) $pl['sisa']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Subtotal</th>
                    <th class="num"><?= $fmt((int) ($totals['bulanan_sisa'] ?? 0)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($awalRows !== []): ?>
    <section class="rekap-section">
        <h2>Rincian kekurangan awal tahun</h2>
        <table class="rekap-table">
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th class="num">Kekurangan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($awalRows as $ar): ?>
                    <tr>
                        <td><?= $h((string) ($ar['nama'] ?? '-')) ?></td>
                        <td class="num"><?= $fmt((int) ($ar['sisa'] ?? 0)) ?></td>
                        <td><?= $h(keuangan_rekap_awal_status_sopan($ar)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Subtotal</th>
                    <th class="num"><?= $fmt((int) ($totals['awal_sisa'] ?? 0)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($tagihanKhusus !== []): ?>
    <section class="rekap-section rekap-section-khusus">
        <h2>Rincian kekurangan tagihan khusus</h2>
        <div class="rekap-cards-mobile">
            <?php foreach ($tagihanKhusus as $tk):
                $sisaTk = tagihan_khusus_sisa($tk);
                $ketTk = trim((string) ($tk['judul'] ?? '') . ' ' . (string) ($tk['keterangan'] ?? ''));
                ?>
                <div class="rekap-khusus-card">
                    <div class="trx-top">
                        <span><?= $h((string) ($tk['tanggal_tagihan'] ?? '')) ?></span>
                        <span><?= $fmt($sisaTk) ?></span>
                    </div>
                    <div class="trx-meta"><?= $h(tagihan_khusus_kategori_label((string) ($tk['kategori'] ?? ''))) ?></div>
                    <?php if ($ketTk !== ''): ?>
                        <div><?= $h($ketTk) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="rekap-table-desktop">
            <table class="rekap-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Keterangan</th>
                        <th class="num">Kekurangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tagihanKhusus as $tk):
                        $sisaTk = tagihan_khusus_sisa($tk);
                        ?>
                        <tr>
                            <td><?= $h((string) ($tk['tanggal_tagihan'] ?? '')) ?></td>
                            <td><?= $h(tagihan_khusus_kategori_label((string) ($tk['kategori'] ?? ''))) ?></td>
                            <td><?= $h(trim((string) ($tk['judul'] ?? '') . ' ' . (string) ($tk['keterangan'] ?? ''))) ?></td>
                            <td class="num"><?= $fmt($sisaTk) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">Subtotal</th>
                        <th class="num"><?= $fmt((int) ($totals['khusus_sisa'] ?? 0)) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?= keuangan_rekap_tagihan_santri_outro_html($pdo, $pack) ?>
    <?php endif; ?>

    <footer class="rekap-footer">
        Dokumen ini hanya memuat kekurangan tagihan yang belum lunas (bulan yang belum berjalan tidak dicantumkan).
        Bukan bukti transfer resmi; bukti resmi mengikuti kuitansi yang diterbitkan bendahara <?= $h((string) ($ponpes['nama'] ?? '')) ?>.
    </footer>
</div>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}
