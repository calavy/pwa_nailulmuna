<?php

declare(strict_types=1);

require_once __DIR__ . '/ikhtibar_import.php';

function ikhtibar_soal_typography_head(): void
{
    require_once __DIR__ . '/app.php';
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_asset_href('/assets/css/ikhtibar-soal-arabic.css')) . '">' . "\n";
}

function ikhtibar_soal_teks_html(string $text, bool $small = false): string
{
    $cls = 'ikhtibar-soal-text' . ($small ? ' ikhtibar-soal-text--small' : '');

    return '<div class="' . $cls . '" dir="auto">' . nl2br(htmlspecialchars($text)) . '</div>';
}

/**
 * Ubah struct import/form ke baris mirip DB untuk render kartu santri.
 *
 * @param array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>} $soal
 * @return list<array<string,mixed>>
 */
function ikhtibar_soal_preview_rows_dari_struct(array $soal): array
{
    $rows = [];
    $fakeId = 1;
    ksort($soal['pg']);
    foreach ($soal['pg'] as $nom => $row) {
        if (trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        $rows[] = [
            'id' => $fakeId++,
            'jenis' => 'PG',
            'nomor' => (int) $nom,
            'teks_soal' => (string) ($row['teks'] ?? ''),
            'opsi_a' => $row['a'] ?? null,
            'opsi_b' => $row['b'] ?? null,
            'opsi_c' => $row['c'] ?? null,
            'opsi_d' => $row['d'] ?? null,
            'opsi_e' => $row['e'] ?? null,
            'pg_jumlah_opsi' => ikhtibar_pg_normalisasi_jumlah_opsi($row),
            'kunci_jawaban' => $row['kunci'] ?? null,
            'bobot_nilai' => (float) ($row['bobot'] ?? 100),
        ];
    }
    ksort($soal['esai']);
    foreach ($soal['esai'] as $nom => $row) {
        if (trim((string) ($row['teks'] ?? '')) === '') {
            continue;
        }
        $rows[] = [
            'id' => $fakeId++,
            'jenis' => 'ESAI',
            'nomor' => (int) $nom,
            'teks_soal' => (string) ($row['teks'] ?? ''),
            'opsi_a' => null,
            'opsi_b' => null,
            'opsi_c' => null,
            'opsi_d' => null,
            'opsi_e' => null,
            'kunci_jawaban' => $row['kunci'] ?? null,
            'bobot_nilai' => (float) ($row['bobot'] ?? 100),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $soalList
 * @param array{readonly?:bool,show_kunci?:bool,preview_badge?:bool,portal_style?:bool,interactive?:bool} $opts
 */
function ikhtibar_render_soal_cards_html(array $soalList, array $opts = []): string
{
    $readonly = (bool) ($opts['readonly'] ?? true);
    $interactive = (bool) ($opts['interactive'] ?? false);
    if ($interactive) {
        $readonly = false;
    }
    $showKunci = (bool) ($opts['show_kunci'] ?? false);
    $previewBadge = (bool) ($opts['preview_badge'] ?? true);
    $portalStyle = (bool) ($opts['portal_style'] ?? false);
    $cardClass = $portalStyle ? 'ikhtibar-soal-card' : 'ikhtibar-preview-card';

    ob_start();
    $no = 0;
    foreach ($soalList as $soal) {
        $no++;
        $sid = (int) ($soal['id'] ?? $no);
        $jenis = (string) ($soal['jenis'] ?? 'PG');
        $jenisLabel = $jenis === 'PG' ? 'PG' : 'Esai';
        ?>
        <div class="card mb-2 border-0 shadow-sm <?= $cardClass ?>" data-soal-id="<?= $sid ?>">
            <div class="card-body py-2">
                <div class="small text-muted mb-1">
                    <?= htmlspecialchars($jenisLabel) ?> · Soal <?= $no ?>
                    <?php if ($previewBadge): ?>
                        <span class="badge text-bg-light border ms-1">Pratinjau</span>
                    <?php endif; ?>
                </div>
                <div class="mb-2"><?= ikhtibar_soal_teks_html((string) ($soal['teks_soal'] ?? '')) ?></div>
                <?php if ($jenis === 'PG'): ?>
                    <?php
                    $jumlahOpsi = ikhtibar_pg_jumlah_opsi_dari_row($soal);
                    foreach (ikhtibar_pg_opsi_huruf_list($jumlahOpsi) as $huruf):
                        $col = 'opsi_' . strtolower($huruf);
                        if (trim((string) ($soal[$col] ?? '')) === '') {
                            continue;
                        }
                        $inputId = 'preview_j' . $sid . '_' . $huruf;
                        ?>
                        <div class="form-check ikhtibar-soal-text" dir="auto">
                            <input class="form-check-input" type="radio" name="preview_jawaban_<?= $sid ?>" value="<?= $huruf ?>" id="<?= htmlspecialchars($inputId) ?>"<?= $readonly ? ' disabled' : '' ?>>
                            <label class="form-check-label" for="<?= htmlspecialchars($inputId) ?>"><?= $huruf ?>. <?= htmlspecialchars((string) $soal[$col]) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($showKunci && trim((string) ($soal['kunci_jawaban'] ?? '')) !== ''): ?>
                        <p class="small text-success mb-0 mt-1">Kunci: <?= htmlspecialchars((string) $soal['kunci_jawaban']) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <textarea class="form-control form-control-sm ikhtibar-soal-input" dir="auto" rows="3"<?= $readonly ? ' readonly' : '' ?> placeholder="Jawaban esai santri…"></textarea>
                    <?php if ($showKunci && trim((string) ($soal['kunci_jawaban'] ?? '')) !== ''): ?>
                        <p class="small text-muted mb-0 mt-1">Kunci esai (pembimbing): <?= nl2br(htmlspecialchars((string) $soal['kunci_jawaban'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    if ($no === 0) {
        echo '<p class="text-muted small mb-0">Belum ada soal untuk ditampilkan.</p>';
    }

    return (string) ob_get_clean();
}

/**
 * Payload kunci/bobot untuk hitung nilai pratinjau (hanya halaman pembimbing).
 *
 * @param list<array<string,mixed>> $soalList
 * @return list<array{id:int,jenis:string,kunci?:string,bobot:float}>
 */
function ikhtibar_preview_skor_client_payload(array $soalList): array
{
    $out = [];
    foreach ($soalList as $soal) {
        $id = (int) ($soal['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $jenis = (string) ($soal['jenis'] ?? 'PG');
        $row = [
            'id' => $id,
            'jenis' => $jenis,
            'bobot' => (float) ($soal['bobot_nilai'] ?? 100),
        ];
        if ($jenis === 'PG') {
            $row['kunci'] = strtoupper(trim((string) ($soal['kunci_jawaban'] ?? '')));
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Header identitas santri + info tugas (kerjakan / pratinjau).
 *
 * @param array<string,mixed> $tugas
 * @param array<string,mixed>|null $santriRow nis, nama_santri, tingkatan
 * @param array{preview?:bool,sisa_detik?:int|null,durasi_menit?:int,status?:string,text_controls?:bool} $opts
 */
function ikhtibar_render_kerjakan_header_html(array $tugas, ?array $santriRow, array $opts = []): string
{
    $preview = (bool) ($opts['preview'] ?? false);
    $textControls = (bool) ($opts['text_controls'] ?? false);
    $sisaDetik = array_key_exists('sisa_detik', $opts) ? $opts['sisa_detik'] : null;
    $durasiMenit = max(0, (int) ($opts['durasi_menit'] ?? $tugas['durasi_menit'] ?? 0));
    $status = (string) ($opts['status'] ?? 'menunggu');
    $judul = (string) ($tugas['judul'] ?? 'Tugas');

    if ($preview) {
        $nis = '—';
        $nama = 'Contoh Nama Santri';
        $tingkatan = '—';
    } else {
        $nis = htmlspecialchars((string) ($santriRow['nis'] ?? '—'));
        $nama = htmlspecialchars((string) ($santriRow['nama_santri'] ?? '—'));
        $tingkatan = htmlspecialchars((string) ($santriRow['tingkatan'] ?? '—'));
    }

    $timerClass = 'alert-secondary';
    $timerHtml = '';
    if ($status === 'berjalan' && $sisaDetik !== null) {
        $sisaMenit = (int) ceil(max(0, (int) $sisaDetik) / 60);
        $timerClass = $sisaDetik <= 300 ? 'alert-danger' : 'alert-warning';
        $timerHtml = '<div class="alert ' . $timerClass . ' py-2 text-center mb-0 mt-2 ikhtibar-timer-box" id="timer-box">'
            . 'Sisa waktu: <strong id="timer-display">--:--</strong>'
            . ' <span class="d-block small mt-1">(<span id="sisa-menit-display">' . $sisaMenit . '</span> menit tersisa)</span>'
            . '</div>';
    } elseif ($preview || $status === 'menunggu') {
        $timerHtml = '<p class="small text-muted mb-0 mt-2">'
            . 'Durasi tugas: <strong>' . $durasiMenit . ' menit</strong>'
            . ($preview ? ' · dihitung mundur saat santri mulai' : ' · dimulai setelah menekan Mulai Tugas')
            . '</p>';
    }

    ob_start();
    ?>
    <div class="card border-0 shadow-sm mb-3 ikhtibar-kerjakan-header">
        <div class="card-body py-2">
            <?php if ($preview): ?>
                <span class="badge text-bg-light border mb-2">Contoh identitas santri</span>
            <?php endif; ?>
            <div class="row g-2 small">
                <div class="col-6"><span class="text-muted">NIS</span><br><strong><?= $preview ? $nis : $nis ?></strong></div>
                <div class="col-6"><span class="text-muted">Tingkatan</span><br><strong><?= $preview ? $tingkatan : $tingkatan ?></strong></div>
                <div class="col-12"><span class="text-muted">Nama</span><br><strong><?= $preview ? htmlspecialchars($nama) : $nama ?></strong></div>
            </div>
            <hr class="my-2">
            <h1 class="h6 fw-bold mb-1"><?= htmlspecialchars($judul) ?></h1>
            <?php if (!$preview && $status !== 'berjalan'): ?>
                <p class="small text-muted mb-0">Durasi <?= $durasiMenit ?> menit · urutan soal diacak</p>
            <?php endif; ?>
            <?= $timerHtml ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}
