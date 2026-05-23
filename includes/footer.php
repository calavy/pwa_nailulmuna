<?php if (isset($_SESSION['user'])): ?>
<?php require_once __DIR__ . '/partials/sdm_modals.php'; ?>
<?php endif; ?>
    </main>
        </div>
    </div>
</div>
<script>
    (function () {
        const cards = document.querySelectorAll('.app-mini-stat');
        if (!cards.length) return;
        const detectIcon = function (label) {
            const t = (label || '').toLowerCase();
            if (t.includes('saldo') || t.includes('bayar') || t.includes('keuangan') || t.includes('gaji')) return '$';
            if (t.includes('santri') || t.includes('wali') || t.includes('pembimbing') || t.includes('user')) return 'U';
            if (t.includes('izin') || t.includes('sakit') || t.includes('alpa')) return '!';
            if (t.includes('waktu') || t.includes('jam') || t.includes('jadwal') || t.includes('bulan')) return 'T';
            if (t.includes('rekap') || t.includes('laporan')) return '#';
            return '*';
        };
        cards.forEach(function (card) {
            const labelEl = card.querySelector('.app-mini-stat-label');
            const label = labelEl ? labelEl.textContent || '' : '';
            card.setAttribute('data-icon', detectIcon(label));
        });
    })();
</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (isset($_SESSION['user'])): ?>
    <script src="/assets/js/sdm-modals.js"></script>
    <script src="/assets/js/santri-select.js"></script>
    <?php require_once __DIR__ . '/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
</body>
</html>
