<?php

declare(strict_types=1);
?>
<style>
.st-kartu-wrap {
    display: flex;
    justify-content: center;
    padding: 1rem 0 2rem;
}
.st-kartu-card__badge-tmp {
    display: inline-block;
    font-size: 1.75mm;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    background: rgba(255, 220, 100, .96);
    color: #2a2e34;
    padding: .5mm 2mm;
    border-radius: 1mm;
    margin-bottom: 1.2mm;
}
.st-kartu-card__content-box--tmp {
    border-color: rgba(230, 233, 238, .95);
}
.st-kartu-card {
    --st-green: #1b4d2e;
    --st-green-mid: #2d6b42;
    --st-name-size: 3.1mm;
    --st-box-top: 10mm;
    --st-box-side: 2.4mm;
    width: 54mm;
    height: 86mm;
    position: relative;
    overflow: hidden;
    border-radius: 3.2mm;
    background: var(--st-green);
    color: #fff;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    box-shadow: 0 12px 32px rgba(15, 45, 28, .35);
    box-sizing: border-box;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.st-kartu-card__waves {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 85% 55% at 50% 52%, rgba(120, 190, 140, .22) 0%, transparent 58%),
        repeating-radial-gradient(circle at 50% 50%, transparent 0 3mm, rgba(180, 230, 190, .06) 3mm 3.15mm);
    pointer-events: none;
    z-index: 0;
}
.st-kartu-card__inner {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
}
/* Kotak putih — mulai ~10 mm dari atas */
.st-kartu-card__content-box {
    position: absolute;
    top: var(--st-box-top);
    left: var(--st-box-side);
    right: var(--st-box-side);
    bottom: var(--st-box-side);
    border: .5mm solid rgba(255, 255, 255, .94);
    border-radius: 1.2mm;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2.2mm 2.4mm 2.4mm;
    text-align: center;
    overflow: hidden;
}
.st-kartu-card__head {
    width: 100%;
    flex-shrink: 0;
    padding: 0 .3mm;
    box-sizing: border-box;
}
.st-kartu-card__ponpes {
    font-size: 2.85mm;
    font-weight: 800;
    letter-spacing: .02em;
    line-height: 1.16;
    margin: 0;
    text-transform: uppercase;
    word-break: break-word;
    hyphens: auto;
}
.st-kartu-card__ponpes--md { font-size: 2.5mm; letter-spacing: .015em; }
.st-kartu-card__ponpes--sm { font-size: 2.2mm; line-height: 1.14; }
.st-kartu-card__ponpes--xs { font-size: 1.95mm; line-height: 1.12; }
.st-kartu-card__addr {
    font-size: 1.85mm;
    line-height: 1.3;
    margin: .8mm 0 0;
    opacity: .96;
    font-weight: 500;
    word-break: break-word;
}
.st-kartu-card__addr--sm { font-size: 1.65mm; line-height: 1.26; }
.st-kartu-card__head-rule {
    width: 100%;
    height: .35mm;
    background: rgba(255, 255, 255, .88);
    margin: 1.6mm 0 1.8mm;
    border: 0;
    flex-shrink: 0;
}
.st-kartu-card__body {
    width: 100%;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.st-kartu-card__photo-ring {
    width: 18.5mm;
    height: 18.5mm;
    border-radius: 50%;
    border: .95mm solid #fff;
    background: var(--st-green-mid);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 1px 5px rgba(0, 0, 0, .14);
}
.st-kartu-card__photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.st-kartu-card__photo-ph {
    font-size: 5.8mm;
    font-weight: 800;
    color: rgba(255, 255, 255, .92);
    letter-spacing: .04em;
}
.st-kartu-card__nis {
    font-size: 3.6mm;
    font-weight: 800;
    margin: 1.1mm 0 .9mm;
    letter-spacing: .02em;
    line-height: 1;
    flex-shrink: 0;
}
.st-kartu-card__name-wrap {
    width: 100%;
    max-width: 100%;
    padding: 0 .2mm;
    box-sizing: border-box;
    flex-shrink: 1;
    min-height: 0;
    overflow: hidden;
}
.st-kartu-card__name-pill {
    display: block;
    width: 100%;
    max-width: 100%;
    background: #fff;
    color: #111;
    font-size: var(--st-name-size);
    font-weight: 800;
    line-height: 1.18;
    padding: 1mm 2mm;
    border-radius: 999px;
    text-transform: uppercase;
    word-break: break-word;
    overflow-wrap: anywhere;
    box-sizing: border-box;
    max-height: var(--st-name-max-h, none);
    overflow: hidden;
}
.st-kartu-card__name-pill--lg { --st-name-size: 2.95mm; }
.st-kartu-card__name-pill--md { --st-name-size: 2.55mm; line-height: 1.16; }
.st-kartu-card__name-pill--sm { --st-name-size: 2.25mm; line-height: 1.14; border-radius: 2mm; }
.st-kartu-card__name-pill--xs { --st-name-size: 1.95mm; line-height: 1.12; border-radius: 1.8mm; padding: .9mm 1.6mm; }
.st-kartu-card__name-pill--xxs { --st-name-size: 1.75mm; line-height: 1.1; border-radius: 1.6mm; padding: .8mm 1.4mm; letter-spacing: .01em; }
.st-kartu-card__name-pill--xxxs { --st-name-size: 1.5mm; line-height: 1.08; border-radius: 1.4mm; padding: .65mm 1.2mm; letter-spacing: 0; }
.st-kartu-card__name-pill.is-multiline { border-radius: 2mm; }
.st-kartu-card__bin {
    font-size: 2.2mm;
    font-style: italic;
    margin: .75mm 0 0;
    opacity: .95;
    min-height: 2mm;
    line-height: 1.18;
    max-width: 100%;
    word-break: break-word;
    padding: 0 .8mm;
    flex-shrink: 1;
    min-height: 0;
    overflow: hidden;
}
.st-kartu-card__bin--sm { font-size: 1.95mm; }
.st-kartu-card__bin--xs { font-size: 1.75mm; line-height: 1.14; }
.st-kartu-card__bin--xxs { font-size: 1.55mm; line-height: 1.12; }
.st-kartu-card__bin--xxxs { font-size: 1.4mm; line-height: 1.1; }
.st-kartu-card__spacer {
    flex: 1;
    min-height: .4mm;
}
.st-kartu-card__footer {
    width: 100%;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.st-kartu-card__qr-frame {
    width: 18.5mm;
    height: 18.5mm;
    border: .45mm dashed rgba(255, 255, 255, .95);
    border-radius: 1mm;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: .8mm;
    box-sizing: border-box;
    background: rgba(255, 255, 255, .04);
    flex-shrink: 0;
}
.st-kartu-card__qr {
    width: 16mm;
    height: 16mm;
    object-fit: contain;
    display: block;
    background: #fff;
    border-radius: .4mm;
}
.st-kartu-card__motto {
    font-size: 1.55mm;
    font-weight: 800;
    letter-spacing: .06em;
    margin: 1.2mm 0 0;
    text-transform: uppercase;
    line-height: 1.12;
    max-width: 100%;
    word-break: break-word;
}
/* Kartu sementara — abu-abu polos (setelah .st-kartu-card agar tidak tertimpa hijau) */
.st-kartu-card.st-kartu-card--sementara {
    --st-green: #7a8088;
    --st-green-mid: #686d75;
    background: #7a8088;
    box-shadow: 0 12px 32px rgba(55, 58, 64, .35);
}
.st-kartu-card.st-kartu-card--sementara .st-kartu-card__waves {
    display: none;
}
@media print {
    .no-print { display: none !important; }
    .st-kartu-wrap { padding: 0; }
    .st-kartu-card {
        box-shadow: none;
        page-break-inside: avoid;
    }
    .st-kartu-card.st-kartu-card--sementara {
        background: #7a8088 !important;
    }
    @page {
        size: 54mm 86mm;
        margin: 0;
    }
}
</style>
