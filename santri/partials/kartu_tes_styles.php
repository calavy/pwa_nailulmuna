<?php

declare(strict_types=1);

/** @var string $logoHref */
/** @var string $headerColor */

?>
<style>
    @page { size: A5 portrait; margin: 6mm; }
    * { box-sizing: border-box; }
    body.kartu-tes-body {
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 10.5pt;
        color: #111827;
        margin: 0;
        background: linear-gradient(180deg, #f8fafc 0%, #ecfdf5 100%);
    }
    .kartu-tes-wrap { padding: 12px; }
    .kartu-tes-sheet {
        position: relative;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        width: 100%;
        max-width: 148mm;
        min-height: calc(210mm - 12mm);
        margin: 0 auto 12px;
        padding: 7mm 9mm;
        background: #fff;
        overflow: hidden;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
        display: flex;
        flex-direction: column;
    }
    .kartu-tes-sheet--kop-watermark::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-28deg);
        width: 220px;
        height: 220px;
        background-image: url("<?= htmlspecialchars($logoHref) ?>");
        background-repeat: no-repeat;
        background-position: center;
        background-size: contain;
        opacity: 0.04;
        z-index: 0;
        pointer-events: none;
    }
    <?= pondok_kop_surat_css($headerColor, $logoHref) ?>
    .kartu-tes-title {
        text-align: center;
        margin: 12px 0 14px;
        position: relative;
        z-index: 1;
    }
    .kartu-tes-title strong {
        font-size: 12pt;
        text-decoration: underline;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .kartu-tes-body-content {
        position: relative;
        z-index: 1;
        flex: 1;
        font-size: 10pt;
        line-height: 1.5;
    }
    .kartu-tes-info {
        width: 100%;
        border-collapse: collapse;
        margin: 0 0 16px;
    }
    .kartu-tes-info td {
        vertical-align: bottom;
        padding: 7px 0;
    }
    .kartu-tes-info td:first-child {
        width: 108px;
        font-weight: 700;
        color: #334155;
        white-space: nowrap;
    }
    .kartu-tes-info .val {
        border-bottom: 1px solid #0f172a;
        padding-bottom: 3px;
        min-height: 1.4em;
    }
    .kartu-tes-info .val--blank {
        letter-spacing: 0.12em;
        color: transparent;
        user-select: none;
    }
    .kartu-tes-hasil {
        margin: 18px 0 8px;
        padding: 12px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
    }
    .kartu-tes-hasil__label {
        font-weight: 700;
        margin: 0 0 10px;
        color: #334155;
    }
    .kartu-tes-hasil__opsi {
        display: flex;
        flex-wrap: wrap;
        gap: 24px;
        font-size: 10.5pt;
    }
    .kartu-tes-hasil__opsi span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .kartu-tes-kotak {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 1.5px solid #0f172a;
        border-radius: 2px;
        flex-shrink: 0;
    }
    .kartu-tes-ttd {
        margin-top: auto;
        padding-top: 18px;
        text-align: right;
        position: relative;
        z-index: 1;
        font-size: 9.5pt;
    }
    .kartu-tes-ttd .lokasi {
        margin-bottom: 6px;
    }
    .kartu-tes-ttd .jabatan {
        margin-bottom: 4px;
        font-weight: 600;
    }
    .kartu-tes-ttd .sign-space {
        height: 22mm;
        min-height: 64px;
    }
    .kartu-tes-ttd .garis-nama {
        display: inline-block;
        min-width: 58%;
        border-top: 1px solid #0f172a;
        padding-top: 6px;
        font-weight: 700;
    }
    @media print {
        body { background: #fff !important; }
        .app-topbar, .app-sidebar, .offcanvas, footer, .no-print { display: none !important; }
        .app-shell, .app-main, .app-content, .container-fluid {
            padding: 0 !important;
            margin: 0 !important;
            max-width: none !important;
        }
        .no-print { display: none !important; }
        .kartu-tes-wrap { padding: 0; }
        .kartu-tes-sheet {
            border-radius: 0;
            box-shadow: none;
            max-width: none;
            margin: 0;
            page-break-after: always;
            break-after: page;
        }
        .kartu-tes-sheet:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .kartu-tes-hasil {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>
