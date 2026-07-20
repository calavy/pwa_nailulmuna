<?php

declare(strict_types=1);

/**
 * Tombol + modal bayar Midtrans (portal wali).
 *
 * @var PDO $pdo
 * @var int $waliSantriId
 * @var string $kelasKat
 * @var int $sisaTotal
 * @var bool $compact ringkas (beranda) — CTA link / tombol penuh
 */

require_once __DIR__ . '/../../helpers/midtrans.php';

$sisaTotal = (int) ($sisaTotal ?? 0);
$compactMidtrans = !empty($compact);
$midtransReady = midtrans_enabled($pdo);

$kelasForMidtrans = (string) ($kelasKat ?? $waliKelasKategori ?? '');
if ($kelasForMidtrans === '' && isset($waliSantriRow) && is_array($waliSantriRow)) {
    $kelasForMidtrans = trim((string) ($waliSantriRow['kategori_kelas'] ?? $waliSantriRow['tingkatan'] ?? ''));
}

$midtransOptions = [];
if ($midtransReady && $sisaTotal > 0 && (int) ($waliSantriId ?? 0) > 0) {
    $midtransOptions = midtrans_tunggakan_options_for_santri($pdo, (int) $waliSantriId, $kelasForMidtrans);
}

$canPay = $midtransReady && $sisaTotal > 0 && $midtransOptions !== [];

if (!$canPay) {
    // Pesan diagnostik agar wali/admin tahu kenapa QRIS belum bisa
    if (!$midtransReady) {
        if ($compactMidtrans) {
            return;
        }
        echo '<div class="alert alert-light border small mb-3 py-2">Bayar online (QRIS/VA) belum siap. Pengurus perlu mengaktifkan Midtrans di Pengaturan.</div>';

        return;
    }
    if ($sisaTotal <= 0) {
        if ($compactMidtrans) {
            return;
        }
        echo '<p class="small text-muted mb-3">Tidak ada sisa tagihan — tombol bayar online tidak ditampilkan.</p>';

        return;
    }
    // Ada sisa tapi opsi bulan kosong
    if ($compactMidtrans) {
        echo '<a class="btn btn-sm btn-teal w-100 mb-2" href="' . htmlspecialchars(app_href('/wali/keuangan.php?tab=tagihan')) . '">'
            . '<i class="fa-solid fa-credit-card me-1"></i> Bayar online (QRIS / VA)</a>';

        return;
    }
    echo '<div class="alert alert-warning small mb-3 py-2">Ada sisa tagihan, tetapi bulan tunggakan belum bisa dipilih. Buka detail tagihan atau hubungi pengurus.</div>';

    return;
}

// Compact beranda: CTA ke halaman tagihan (modal penuh di sana)
if ($compactMidtrans) {
    ?>
    <a class="btn btn-sm btn-teal w-100 mb-2" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=tagihan')) ?>">
        <i class="fa-solid fa-credit-card me-1"></i> Bayar online (QRIS / VA)
    </a>
    <p class="small text-muted mb-0">Pilih bulan tagihan, lalu bayar via QRIS atau Virtual Account di Midtrans.</p>
    <?php
    return;
}

$chargeUrl = app_href('/wali/api_midtrans_charge.php');
$clientKey = midtrans_client_key($pdo);
$snapJs = midtrans_snap_js_url($pdo);
?>
<div class="mb-3">
    <button type="button" class="btn btn-teal w-100" id="btnMidtransBayar" data-bs-toggle="modal" data-bs-target="#modalMidtransBayar">
        <i class="fa-solid fa-credit-card me-1"></i> Bayar online (QRIS / VA)
    </button>
    <p class="small text-muted mt-1 mb-0">
        Setelah klik, popup Midtrans menampilkan <strong>QRIS</strong> dan <strong>Virtual Account</strong>.
        Pembayaran tercatat otomatis — pengurus tidak perlu input manual.
    </p>
</div>

<div class="modal fade" id="modalMidtransBayar" tabindex="-1" aria-labelledby="modalMidtransBayarLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="modalMidtransBayarLabel">Bayar tagihan online</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Pilih bulan tunggakan. Lanjut bayar membuka popup Midtrans dengan pilihan <strong>QRIS</strong> dan <strong>VA bank</strong>.</p>
                <div class="list-group mb-2" id="midtransBulanList">
                    <?php foreach ($midtransOptions as $opt): ?>
                        <label class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                            <span class="d-flex align-items-center gap-2">
                                <input type="radio" name="midtrans_bulan" class="form-check-input mt-0"
                                       value="<?= (int) $opt['bulan'] ?>"
                                       data-tm="<?= (int) $opt['tahun_mulai'] ?>"
                                       data-ts="<?= (int) $opt['tahun_selesai'] ?>"
                                       <?= $opt === $midtransOptions[0] ? 'checked' : '' ?>>
                                <?= htmlspecialchars((string) $opt['label']) ?>
                            </span>
                            <span class="font-monospace text-danger small">Rp <?= number_format((int) $opt['sisa'], 0, ',', '.') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-danger py-2 small d-none" id="midtransErr"></div>
                <div class="small text-muted d-none" id="midtransLoading">Menyiapkan pembayaran…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-teal btn-sm" id="btnMidtransLanjut">Lanjut bayar</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($snapJs) ?>" data-client-key="<?= htmlspecialchars($clientKey) ?>" id="midtrans-snap-script"></script>
<script>
(function () {
    const chargeUrl = <?= json_encode($chargeUrl, JSON_UNESCAPED_UNICODE) ?>;
    const santriId = <?= (int) $waliSantriId ?>;
    const successUrl = <?= json_encode(app_href('/wali/keuangan.php?tab=bayar&midtrans=success'), JSON_UNESCAPED_UNICODE) ?>;
    const pendingUrl = <?= json_encode(app_href('/wali/keuangan.php?tab=bayar&midtrans=pending'), JSON_UNESCAPED_UNICODE) ?>;
    const btn = document.getElementById('btnMidtransLanjut');
    const errEl = document.getElementById('midtransErr');
    const loadingEl = document.getElementById('midtransLoading');

    function showErr(msg) {
        if (!errEl) return;
        errEl.textContent = msg || 'Gagal';
        errEl.classList.remove('d-none');
    }
    function clearErr() {
        if (!errEl) return;
        errEl.textContent = '';
        errEl.classList.add('d-none');
    }

    function waitForSnap(maxMs) {
        return new Promise(function (resolve) {
            const start = Date.now();
            (function tick() {
                if (window.snap && typeof window.snap.pay === 'function') {
                    resolve(true);
                    return;
                }
                if (Date.now() - start >= maxMs) {
                    resolve(false);
                    return;
                }
                setTimeout(tick, 150);
            })();
        });
    }

    function openSnapOrRedirect(data) {
        const token = data.token || '';
        const redirectUrl = data.redirect_url || '';

        function goRedirect() {
            if (redirectUrl) {
                window.location.href = redirectUrl;
                return true;
            }
            return false;
        }

        if (!window.snap || typeof window.snap.pay !== 'function') {
            if (goRedirect()) return;
            showErr('Snap Midtrans gagal dimuat. Muat ulang halaman, atau coba lagi.');
            return;
        }

        const modalEl = document.getElementById('modalMidtransBayar');
        if (modalEl && window.bootstrap) {
            bootstrap.Modal.getInstance(modalEl)?.hide();
        }

        try {
            window.snap.pay(token, {
                onSuccess: function () { window.location.href = successUrl; },
                onPending: function () { window.location.href = pendingUrl; },
                onError: function (result) {
                    const msg = (result && (result.status_message || result.message))
                        ? String(result.status_message || result.message)
                        : 'Pembayaran gagal atau dibatalkan.';
                    showErr(msg);
                    if (redirectUrl) {
                        // Cadangan: buka halaman Snap penuh (QRIS/VA tetap ada)
                        setTimeout(function () { window.location.href = redirectUrl; }, 1200);
                        return;
                    }
                    const m = document.getElementById('modalMidtransBayar');
                    if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
                },
                onClose: function () {}
            });
        } catch (e) {
            if (!goRedirect()) {
                showErr('Gagal membuka Snap: ' + (e && e.message ? e.message : 'unknown'));
            }
        }
    }

    btn?.addEventListener('click', async function () {
        clearErr();
        const selected = document.querySelector('input[name="midtrans_bulan"]:checked');
        if (!selected) {
            showErr('Pilih bulan tagihan.');
            return;
        }
        btn.disabled = true;
        loadingEl?.classList.remove('d-none');
        if (loadingEl) loadingEl.textContent = 'Menyiapkan pembayaran Midtrans…';
        try {
            const res = await fetch(chargeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    santri_id: santriId,
                    bulan: parseInt(selected.value, 10),
                    tahun_ajaran_mulai: parseInt(selected.getAttribute('data-tm') || '0', 10),
                    tahun_ajaran_selesai: parseInt(selected.getAttribute('data-ts') || '0', 10)
                })
            });
            const rawText = await res.text();
            let data = null;
            try {
                data = rawText ? JSON.parse(rawText) : null;
            } catch (parseErr) {
                showErr('Respons server tidak valid (HTTP ' + res.status + '). Coba muat ulang halaman.');
                return;
            }
            if (!res.ok || !data || !data.ok || !data.token) {
                const msg = (data && data.message)
                    ? data.message
                    : ('Gagal membuat transaksi (HTTP ' + res.status + ').');
                showErr(msg);
                return;
            }

            if (loadingEl) loadingEl.textContent = 'Membuka Snap (QRIS / VA)…';
            const snapReady = await waitForSnap(4000);
            if (!snapReady && data.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }
            openSnapOrRedirect(data);
        } catch (e) {
            showErr('Koneksi gagal ke server. Periksa jaringan / ngrok, lalu coba lagi.');
        } finally {
            btn.disabled = false;
            loadingEl?.classList.add('d-none');
            if (loadingEl) loadingEl.textContent = 'Menyiapkan pembayaran…';
        }
    });
})();
</script>
