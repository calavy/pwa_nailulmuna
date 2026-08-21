<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-3">Status singkat</h2>
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Gateway</div>
                    <div class="fw-semibold <?= $waGatewayErr === null ? 'text-success' : 'text-warning' ?>">
                        <?= $waGatewayErr === null ? 'Siap kirim' : 'Perlu perbaikan' ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Master WA</div>
                    <div class="fw-semibold"><?= $waMasterOn ? 'Aktif' : 'Nonaktif' ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Mode notifikasi</div>
                    <div class="fw-semibold"><?= htmlspecialchars(match ($notifyMode) { 'push' => 'Push saja', 'wa' => 'WA saja', default => 'WA + Push' }) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-2 h-100">
                    <div class="small text-muted">Tagihan otomatis</div>
                    <div class="fw-semibold"><?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? 'Aktif' : 'Nonaktif' ?></div>
                </div>
            </div>
        </div>
        <?php if ($waGatewayErr !== null): ?>
            <div class="alert alert-warning py-2 small mt-3 mb-0"><?= htmlspecialchars($waGatewayErr) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock me-1"></i> Cron otomatis</h2>
        <?php
        $waCronStale = wa_auto_cron_is_stale($pdo);
        $waCronActive = $waCronLastRun !== '' && !$waCronStale;
        $waFallbackOn = ($values['wa_auto_web_fallback_enabled'] ?? '0') === '1';
        $waDoubleSendRisk = $waCronActive && $waFallbackOn;
        $waDispatchStrict = ($values['wa_dispatch_strict_mode'] ?? '1') === '1';
        $waFallbackAutoOff = trim((string) app_setting($pdo, 'wa_auto_fallback_auto_disabled_at', ''));
        ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="badge <?= $waCronActive ? 'bg-success' : ($waCronLastRun === '' ? 'bg-secondary' : 'bg-danger') ?>">
                <?= $waCronActive ? 'Cron aktif' : ($waCronLastRun === '' ? 'Belum pernah jalan' : 'Cron tidak update (&gt;10 menit)') ?>
            </span>
            <?php if (!empty($waJadwal['enabled'])): ?>
                <span class="badge <?= !empty($waJadwal['is_send_day']) ? 'bg-primary' : 'bg-light text-dark border' ?>">
                    Hari ini: hari ke-<?= (int) ($waJadwal['today_day'] ?? 0) ?> (<?= htmlspecialchars((string) ($waJadwal['calendar'] ?? '')) ?>)
                </span>
                <?php if (!empty($waJadwal['is_send_day'])): ?>
                    <span class="badge <?= !empty($waJadwal['send_time_ok']) ? 'bg-success' : 'bg-warning text-dark' ?>">
                        <?= !empty($waJadwal['send_time_ok']) ? 'Sudah jam kirim' : 'Menunggu jam ' . htmlspecialchars((string) ($waJadwal['send_time'] ?? '')) ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($waDoubleSendRisk): ?>
            <div class="alert alert-danger py-2 small mb-2">
                <strong>Risiko kirim dobel:</strong> Cron hosting sudah aktif, tetapi <strong>Fallback cron</strong> masih ON.
                Nonaktifkan di tab <a href="?tab=gateway">Gateway</a> — atau biarkan cron HTTP menonaktifkannya otomatis pada hit berikutnya.
            </div>
        <?php elseif ($waCronStale && $waFallbackOn): ?>
            <div class="alert alert-info py-2 small mb-2">
                Cron belum terdeteksi aktif; fallback web saat staf buka app masih berjalan sebagai cadangan.
            </div>
        <?php endif; ?>
        <?php if (!$waDispatchStrict): ?>
            <div class="alert alert-warning py-2 small mb-2">
                <strong>Dedup nonaktif:</strong> Aktifkan "Cegah duplikat WA otomatis" di tab <a href="?tab=gateway">Gateway</a>.
            </div>
        <?php endif; ?>
        <?php if ($waFallbackAutoOff !== ''): ?>
            <p class="small text-muted mb-2">Fallback otomatis dimatikan: <strong><?= htmlspecialchars($waFallbackAutoOff) ?></strong>
                (<?= htmlspecialchars((string) app_setting($pdo, 'wa_auto_fallback_auto_disabled_reason', 'hosting_cron_http')) ?>)</p>
        <?php endif; ?>
        <p class="small text-muted mb-2">Satu cron <code>cron/wa_auto.php</code> melayani <strong>seluruh</strong> WA otomatis (ALPA, tagihan wali, cashless, kelas kosong, poin, dll.). Jadwalkan setiap <strong>1–5 menit</strong> di panel hosting. Pastikan hanya <strong>satu baris</strong> crontab (hindari duplikat). Setelah cron aktif, nonaktifkan <strong>Fallback cron</strong> di tab Gateway.</p>
        <div class="alert alert-light border py-2 small mb-2">
            <strong>Deploy ke hosting yang sudah jalan:</strong> upload/pull kode saja — jadwal cron di panel hosting dan setting di database <strong>tidak berubah</strong>.
            Setelah deploy, pastikan badge <strong>Cron aktif</strong> (hijau) dan klik <strong>Tes cron</strong> di bawah.
        </div>
        <div class="small mb-3">
            <div class="fw-semibold mb-1">Perintah cron</div>
            <div class="font-monospace bg-light border rounded p-2 mb-1 user-select-all">php <?= htmlspecialchars($waCronCliPath ?? (str_replace('\\', '/', dirname(__DIR__, 3) . '/cron/wa_auto.php'))) ?></div>
            <div class="text-muted mb-1">HTTP (jika CLI tidak tersedia):</div>
            <div class="font-monospace bg-light border rounded p-2 user-select-all">curl "<?= htmlspecialchars($cronUrl) ?>"</div>
        </div>
        <ul class="small mb-3 ps-3">
            <li>Terakhir jalan: <strong><?= $waCronLastRun !== '' ? htmlspecialchars($waCronLastRun) : 'Belum pernah' ?></strong></li>
            <li>Tick berat terakhir: <strong><?= $waLastHeavy !== '' ? htmlspecialchars($waLastHeavy) : '—' ?></strong></li>
            <li>Job WA terjadwal terakhir: <strong><?= $waScheduledLastAt !== '' ? htmlspecialchars($waScheduledLastAt) : 'Belum pernah' ?></strong></li>
            <li>Tagihan terakhir: <strong><?= $waTagihanLastRun !== '' ? htmlspecialchars($waTagihanLastRun) : '—' ?></strong>
                <?php if (is_array($waLastStats) && ((int) ($waLastStats['sent'] ?? 0) > 0 || (int) ($waLastStats['failed'] ?? 0) > 0)): ?>
                    — <?= (int) ($waLastStats['sent'] ?? 0) ?> terkirim, <?= (int) ($waLastStats['failed'] ?? 0) ?> gagal
                <?php endif; ?>
            </li>
            <?php if (is_array($waPartialFail)): ?>
                <li class="text-warning">Partial fail terakhir: <?= (int) ($waPartialFail['sent'] ?? 0) ?> terkirim, <?= (int) ($waPartialFail['failed'] ?? 0) ?> gagal — retry berikutnya melewati santri yang sudah sukses.</li>
            <?php endif; ?>
            <?php if ($waScheduledLast && !empty($waScheduledLast['skipped'])): ?>
                <li class="text-warning">Job terjadwal dilewati: <?= htmlspecialchars((string) ($waScheduledLast['gateway_error'] ?? $waScheduledLast['reason'] ?? 'gateway')) ?></li>
            <?php elseif (is_array($waScheduledLast['jobs'] ?? null)): ?>
                <?php
                $jobLabels = [
                    'alpa' => 'ALPA',
                    'tagihan' => 'Tagihan wali',
                    'kelas_kosong' => 'Kelas kosong',
                    'cashless_laporan' => 'Laporan cashless',
                    'poin_ambang' => 'Poin ambang',
                ];
                ?>
                <li>Job terjadwal terakhir:
                    <?php foreach ($jobLabels as $jobKey => $jobLabel): ?>
                        <?php
                        $jobRow = $waScheduledLast['jobs'][$jobKey] ?? null;
                        if (!is_array($jobRow)) {
                            continue;
                        }
                        $jobNote = trim((string) ($jobRow['note'] ?? ''));
                        ?>
                        <span class="d-inline-block me-2"><?= htmlspecialchars($jobLabel) ?>:
                            <?= !empty($jobRow['ran']) ? '<span class="text-success">jalan</span>' : '<span class="text-muted">—</span>' ?>
                            <?php if ($jobNote !== ''): ?>
                                <span class="text-muted">(<?= htmlspecialchars($jobNote) ?>)</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </li>
            <?php endif; ?>
            <?php if ($waAlpaLast): ?>
                <li>ALPA crossing terakhir: <?= (int) ($waAlpaLast['sent'] ?? 0) ?> terkirim
                    <?php if ((int) ($waAlpaLast['sent_putra'] ?? 0) > 0 || (int) ($waAlpaLast['sent_putri'] ?? 0) > 0): ?>
                        (Putra: <?= (int) ($waAlpaLast['sent_putra'] ?? 0) ?>, Putri: <?= (int) ($waAlpaLast['sent_putri'] ?? 0) ?>)
                    <?php endif; ?>
                    · <?= htmlspecialchars((string) ($waAlpaLast['note'] ?? '')) ?>
                </li>
            <?php endif; ?>
        </ul>
        <h3 class="h6 mb-2">Checklist setelah deploy (hosting)</h3>
        <ol class="small mb-3 ps-3">
            <li>Badge di atas = <strong>Cron aktif</strong> (hijau) dan <em>Terakhir jalan</em> &lt; 10 menit</li>
            <li>Klik <strong>Tes cron</strong> → respons <code>OK wa_auto ...</code></li>
            <li>Jangan hapus baris cron di panel hosting; jangan impor ulang database production</li>
            <li>Setting gateway, kunci cron, nomor penerima tetap di database — tidak perlu diisi ulang</li>
        </ol>
        <h3 class="h6 mb-2">Checklist otomatisasi WA</h3>
        <ul class="small mb-3 ps-3">
            <li>Gateway: <?= $waGatewayErr === null ? '<span class="text-success">OK</span>' : '<span class="text-danger">Error</span>' ?></li>
            <li>Master WA: <?= $waMasterOn ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Gateway personal: <?= ($waGatewayProvider ?? 'fonte') === 'meta' ? '<span class="text-success">Meta Cloud API</span> (grup tetap Fonte)' : '<span class="text-success">Fonte</span>' ?></li>
            <li>Mode notifikasi: <?= htmlspecialchars(match ($notifyMode) { 'push' => 'Push saja (izin WA off)', 'wa' => 'WA saja', default => 'WA + Push' }) ?></li>
            <li>Tagihan wali (jadwal): <?= ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Pembayaran tercatat → wali: <?= $waPembayaranWaliEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Scan pembimbing: <?= trim((string) app_setting($pdo, 'wa_pembimbing_scan_enabled', '1')) === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Kelas kosong: <?= trim((string) app_setting($pdo, 'wa_kelas_kosong_enabled', '1')) === '1' ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Grup presensi: <?= ($waPresensiGrupFonte ?? '') !== '' && ($waPresensiGrupFonteEnabled ?? false) ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>WA pembimbing terkait: <?= ($waPresensiKirimPembimbingEnabled ?? true) ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Cashless laporan: <?= $cashlessLaporanHarianWaEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Cashless transaksi → wali: <?= $cashlessTransaksiWaEnabled ? '<span class="text-success">Aktif</span>' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Fallback tanpa cron: <?= $waFallbackOn ? '<span class="text-warning">Aktif</span> (saat staf buka app)' : '<span class="text-muted">Nonaktif</span>' ?></li>
            <li>Cegah duplikat (ledger): <?= $waDispatchStrict ? '<span class="text-success">Aktif</span>' : '<span class="text-danger">Nonaktif</span>' ?></li>
        </ul>
        <div class="input-group input-group-sm mb-2" style="max-width:36rem">
            <span class="input-group-text">URL cron</span>
            <input type="text" class="form-control font-monospace" readonly value="<?= htmlspecialchars($cronUrl) ?>" id="wa-cron-url">
            <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('wa-cron-url').value)">Salin</button>
            <a class="btn btn-outline-primary" href="<?= htmlspecialchars($cronUrl) ?>" target="_blank" rel="noopener">Tes cron</a>
        </div>
        <p class="small text-muted mb-0">Atur kunci cron di tab <a href="?tab=gateway">Gateway</a>. <?= $waCronKey === '' ? '<span class="text-warning">Belum ada kunci.</span>' : '<span class="text-success">Kunci aktif.</span>' ?></p>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h6 mb-2">Alur kerja (mudah dipahami)</h2>
        <ol class="small text-muted mb-0 ps-3">
            <li class="mb-1"><strong>Gateway</strong> — pilih Fonte atau Meta, isi kredensial, lalu tes kirim ke satu nomor.</li>
            <li class="mb-1"><strong>Tagihan Wali</strong> — atur jadwal &amp; aktifkan pengingat syahriyah.</li>
            <li class="mb-1"><strong>Cashless</strong> — transaksi/saldo rendah ke wali &amp; laporan harian ke pengurus.</li>
            <li class="mb-1"><strong>Presensi</strong> — pengingat scan, munawib, laporan kelas kosong.</li>
            <li class="mb-1"><strong>Alpa</strong> — tier penerima &amp; nomor fallback alpa otomatis.</li>
            <li class="mb-1"><strong>Izin</strong> — permohonan baru (PENDING) &amp; notifikasi saat izin disetujui.</li>
            <li><strong>Template</strong> — sesuaikan teks pesan bila perlu.</li>
        </ol>
    </div>
</div>
