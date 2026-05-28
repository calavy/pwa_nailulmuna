/**
 * Tema warna kartu ID — preset tetap atau warna dominan dari logo pondok.
 */
(function (global) {
    'use strict';

    var PRESETS = {
        ocean: ['#1e3a8a', '#1d4ed8', '#0ea5e9', '#bfdbfe', '#93c5fd', 'rgba(30,64,175,.30)'],
        emerald: ['#053d2d', '#0b5d46', '#12795d', '#8bd7bf', '#67c8ab', 'rgba(5,61,45,.36)'],
        royal: ['#312e81', '#5b21b6', '#7c3aed', '#d8b4fe', '#c4b5fd', 'rgba(67,24,161,.32)'],
        sunset: ['#9a3412', '#c2410c', '#f97316', '#fdba74', '#fb923c', 'rgba(154,52,18,.32)']
    };

    function hexToRgb(hex) {
        var h = String(hex || '').replace('#', '').trim();
        if (h.length === 3) {
            h = h.split('').map(function (c) { return c + c; }).join('');
        }
        if (h.length !== 6) return null;
        var n = parseInt(h, 16);
        if (isNaN(n)) return null;
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function rgbToHex(r, g, b) {
        function toHex(v) {
            var s = Math.max(0, Math.min(255, Math.round(v))).toString(16);
            return s.length < 2 ? '0' + s : s;
        }
        return '#' + toHex(r) + toHex(g) + toHex(b);
    }

    function adjust(hex, amount) {
        var rgb = hexToRgb(hex);
        if (!rgb) return hex;
        return rgbToHex(rgb.r + amount, rgb.g + amount, rgb.b + amount);
    }

    function paletteFromBase(base) {
        var c1 = adjust(base, -58);
        var rgb = hexToRgb(c1) || { r: 30, g: 64, b: 175 };
        return [c1, adjust(base, -18), adjust(base, 34), adjust(base, 72), adjust(base, 56),
            'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',.33)'];
    }

    function themeObjectToValues(theme) {
        if (!theme || !theme.grad1) return null;
        return [
            theme.grad1,
            theme.grad2,
            theme.grad3,
            theme.border || adjust(theme.base || theme.grad2, 72),
            theme.print_border || adjust(theme.base || theme.grad2, 56),
            theme.shadow || 'rgba(30,64,175,.33)'
        ];
    }

    function applyTheme(cards, values) {
        if (!values || !values.length) return;
        var list = cards;
        if (!list || !list.length) return;
        for (var i = 0; i < list.length; i++) {
            var card = list[i];
            card.style.setProperty('--card-grad-1', values[0]);
            card.style.setProperty('--card-grad-2', values[1]);
            card.style.setProperty('--card-grad-3', values[2]);
            card.style.setProperty('--card-border', values[3]);
            card.style.setProperty('--card-print-border', values[4]);
            if (values[5]) {
                card.style.setProperty('--card-shadow', values[5]);
            }
        }
    }

    function extractDominantFromImageData(data, width, height) {
        var samples = [];
        var i;
        for (i = 0; i < data.length; i += 4) {
            var a = data[i + 3];
            if (a < 24) continue;
            var r = data[i];
            var g = data[i + 1];
            var b = data[i + 2];
            var max = Math.max(r, g, b);
            var min = Math.min(r, g, b);
            var lum = 0.299 * r + 0.587 * g + 0.114 * b;
            if (lum < 32 || lum > 232) continue;
            var sat = max > 0 ? (max - min) / max : 0;
            if (sat < 0.14) continue;
            var score = sat * (1 - Math.abs(lum - 118) / 118);
            samples.push({ r: r, g: g, b: b, score: score });
        }
        if (samples.length < 8) return null;
        samples.sort(function (a, b) { return b.score - a.score; });
        var take = Math.max(3, Math.ceil(samples.length * 0.22));
        var sumR = 0;
        var sumG = 0;
        var sumB = 0;
        for (i = 0; i < take; i++) {
            sumR += samples[i].r;
            sumG += samples[i].g;
            sumB += samples[i].b;
        }
        return rgbToHex(sumR / take, sumG / take, sumB / take);
    }

    function loadBrandFromLogo(logoUrl, onSuccess, onError) {
        if (!logoUrl) {
            onError();
            return;
        }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            try {
                var size = 48;
                var canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext('2d');
                if (!ctx) throw new Error('Canvas tidak tersedia');
                ctx.drawImage(img, 0, 0, size, size);
                var px = ctx.getImageData(0, 0, size, size);
                var base = extractDominantFromImageData(px.data, size, size);
                if (!base) throw new Error('Warna logo tidak terbaca');
                onSuccess(paletteFromBase(base));
            } catch (e) {
                onError();
            }
        };
        img.onerror = function () { onError(); };
        img.src = logoUrl + (logoUrl.indexOf('?') >= 0 ? '&' : '?') + 'v=' + Date.now();
    }

    function init(options) {
        var cards = options.cards || [];
        if (cards.length === undefined && cards instanceof Element) {
            cards = [cards];
        }
        if (!cards.length) return;

        var picker = options.picker || null;
        var logoUrl = options.logoUrl || '';
        var brandTheme = options.brandTheme || null;
        var fallbackKey = options.fallback || 'ocean';

        function applyFallback() {
            applyTheme(cards, PRESETS[fallbackKey] || PRESETS.ocean);
        }

        function applyBrand() {
            var fromServer = themeObjectToValues(brandTheme);
            if (fromServer) {
                applyTheme(cards, fromServer);
                return;
            }
            // Tema "brand" selalu hijau gelap.
            applyTheme(cards, PRESETS.emerald);
        }

        function applyByName(name) {
            var key = String(name || '').toLowerCase();
            if (PRESETS[key]) {
                applyTheme(cards, PRESETS[key]);
                return;
            }
            applyBrand();
        }

        if (picker) {
            picker.addEventListener('change', function () {
                applyByName(picker.value);
            });
        }

        applyByName(picker ? (picker.value || 'brand') : 'brand');
    }

    global.KartuBrandTheme = {
        init: init,
        applyTheme: applyTheme,
        loadBrandFromLogo: loadBrandFromLogo,
        paletteFromBase: paletteFromBase,
        presets: PRESETS
    };
})(typeof window !== 'undefined' ? window : this);
