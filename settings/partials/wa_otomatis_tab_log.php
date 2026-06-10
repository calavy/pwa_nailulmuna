<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Log pengiriman terbaru</h2>
        <p class="small text-muted mb-3">30 entri terakhir dari semua job WA otomatis.</p>
        <?php if ($waLogRecent === []): ?>
            <p class="text-muted small mb-0">Belum ada log atau tabel <code>wa_logs</code> belum tersedia.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr><th>Waktu</th><th>Target</th><th>Pesan</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($waLogRecent as $log): ?>
                        <tr>
                            <td class="text-nowrap small"><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars((string) ($log['target_phone'] ?? '')) ?></td>
                            <td class="small text-truncate" style="max-width:14rem"><?= htmlspecialchars((string) ($log['message_short'] ?? '')) ?></td>
                            <td><?= (int) ($log['is_success'] ?? 0) === 1 ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">Gagal</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h6 mb-2">Laporan kelas kosong</h2>
        <p class="small text-muted mb-2">Riwayat khusus pesan laporan kelas kosong dengan filter tanggal.</p>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/settings/wa_laporan_kelas_kosong.php')) ?>">Buka laporan kelas kosong</a>
    </div>
</div>
