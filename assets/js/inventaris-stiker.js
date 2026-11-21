(function () {
    var dataEl = document.getElementById('inv-stiker-data');
    var cv = document.getElementById('stickerCanvas');
    if (!dataEl || !cv) return;

    var config = {};
    try {
        config = JSON.parse(dataEl.textContent || '{}');
    } catch (err) {
        config = {};
    }

    var ctx = cv.getContext('2d');
    var F = "'Inter', sans-serif";
    var BW = 1200;
    var BH = 480;
    var THEMES = {
        emerald: { n: 'Hijau Pesantren', bg: '#124624', acc: '#9cd660', sub: '#9cd660', box: '#9cd660', shadow: '#092613', text: '#0f361c', ld: '#165b33', ll: '#8cc63f', badge: '#0d361b' },
        teal: { n: 'Biru Teal', bg: '#0f4c53', acc: '#4fd1df', sub: '#6ce5f2', box: '#6ee2eb', shadow: '#072b30', text: '#08333a', ld: '#165b33', ll: '#7ac143', badge: '#09343a' },
        navy: { n: 'Biru Navy', bg: '#172554', acc: '#60a5fa', sub: '#93c5fd', box: '#93c5fd', shadow: '#0c1433', text: '#0f172a', ld: '#1e3a8a', ll: '#38bdf8', badge: '#0f172a' },
        maroon: { n: 'Merah Marun', bg: '#450a0a', acc: '#fda4af', sub: '#fecdd3', box: '#fecdd3', shadow: '#2b0505', text: '#450a0a', ld: '#7f1d1d', ll: '#fb7185', badge: '#2b0505' }
    };
    var PRESETS = {
        landscape: [['Standar panjang (10 × 4 cm • 1200×480)', 1200, 480], ['Kecil (8 × 3,2 cm • 1000×400)', 1000, 400], ['Besar / label rak (12,5 × 5 cm • 1500×600)', 1500, 600], ['Ekstra besar (15 × 6 cm • 1800×720)', 1800, 720], ['Rasio 16:9 (1280×720)', 1280, 720]],
        square: [['Persegi standar (6 × 6 cm • 800×800)', 800, 800], ['Persegi besar (8 × 8 cm • 1000×1000)', 1000, 1000], ['Persegi kecil (4 × 4 cm • 600×600)', 600, 600]],
        portrait: [['Potret standar (5 × 8 cm • 600×960)', 600, 960], ['Potret kartu (6 × 9 cm • 680×1020)', 680, 1020], ['Potret panjang (4 × 10 cm • 520×1150)', 520, 1150]]
    };

    var T = THEMES.emerald;
    var layout = 'classic';
    var orient = 'landscape';
    var cBox = null;
    var cLine = null;
    var uploadedLogo = null;
    var pondokLogo = null;
    var qrMan = false;
    var currentIndex = 0;
    var items = Array.isArray(config.items) ? config.items : [];
    var qrCache = { d: null, c: null };

    var inputs = {
        sheetLocCode: document.getElementById('sheetLocCode'),
        sheetCatCode: document.getElementById('sheetCatCode'),
        sheetSeqNum: document.getElementById('sheetSeqNum'),
        sheetParentCode: document.getElementById('sheetParentCode'),
        mainCode: document.getElementById('mainCode'),
        mainCodeFont: document.getElementById('mainCodeFont'),
        boxColorPicker: document.getElementById('boxColorPicker'),
        lineColorPicker: document.getElementById('lineColorPicker'),
        qrText: document.getElementById('qrText'),
        qrSubText: document.getElementById('qrSubText'),
        qrContent: document.getElementById('qrContent'),
        orgName: document.getElementById('orgName'),
        orgAddress: document.getElementById('orgAddress'),
        footerText: document.getElementById('footerText'),
        badgeText: document.getElementById('badgeText')
    };

    function val(id) {
        return inputs[id] ? inputs[id].value : '';
    }
    function boxFill() { return cBox || T.box; }
    function lineCol() { return cLine || T.acc; }
    function rgba(hex, alpha) {
        var n = parseInt(String(hex).slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }
    function divCol() { return rgba(lineCol(), 0.4); }
    function org() { return val('orgName').toUpperCase(); }
    function foot() { return val('footerText').toUpperCase(); }
    function badge() { return val('badgeText').toUpperCase(); }
    function activeLogo() { return uploadedLogo || pondokLogo; }
    function expectedQr(kode) {
        return String(config.cekBase || '') + encodeURIComponent(kode || '');
    }

    function rr(x, y, w, h, r) {
        r = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
        else ctx.rect(x, y, w, h);
    }
    function line(x1, y1, x2, y2, color, width) {
        ctx.strokeStyle = color;
        ctx.lineWidth = width || 1.5;
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
    }
    function ls(px) {
        if ('letterSpacing' in ctx) ctx.letterSpacing = px + 'px';
    }
    function fit(text, x, y, maxWidth, size, weight, min, color, align, family) {
        family = family || F;
        ctx.textAlign = align || 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = color;
        ctx.font = weight + ' ' + size + 'px ' + family;
        while (ctx.measureText(text).width > maxWidth && size > min) {
            size -= 0.5;
            ctx.font = weight + ' ' + size + 'px ' + family;
        }
        ctx.fillText(text, x, y);
        return ctx.measureText(text).width;
    }
    function currentNama() {
        var item = items[currentIndex];
        return item && item.nama ? String(item.nama).trim() : '';
    }
    function splitCode(text, family, size) {
        ctx.font = '900 ' + size + 'px ' + family;
        var parts = text.split('-');
        if (parts.length < 2) {
            var mid = Math.ceil(text.length / 2);
            return [text.slice(0, mid), text.slice(mid)];
        }
        var best = 1;
        var bestDiff = Infinity;
        for (var i = 1; i < parts.length; i++) {
            var left = parts.slice(0, i).join('-');
            var right = parts.slice(i).join('-');
            var diff = Math.abs(ctx.measureText(left).width - ctx.measureText(right).width);
            if (diff < bestDiff) {
                bestDiff = diff;
                best = i;
            }
        }
        return [parts.slice(0, best).join('-'), parts.slice(best).join('-')];
    }
    function inkOn(hex) {
        var raw = String(hex || '').replace('#', '');
        if (raw.length === 3) raw = raw.replace(/./g, function (ch) { return ch + ch; });
        var n = parseInt(raw, 16);
        if (!isFinite(n) || raw.length < 6) return '#0f172a';
        var y = (((n >> 16) & 255) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000;
        return y >= 150 ? '#0f172a' : '#f8fafc';
    }
    function code(cx, cy, maxWidth, maxHeight, color) {
        color = inkOn(boxFill());
        var text = (val('mainCode') || '').toUpperCase();
        var family = val('mainCodeFont') || F;
        var nama = currentNama();
        var namaSlot = nama ? Math.min(34, Math.max(22, maxHeight * 0.2)) : 0;
        var codeHeight = Math.max(40, maxHeight - namaSlot);
        var min = Math.max(42, Math.round(codeHeight * 0.32));
        var size = Math.min(codeHeight * 0.82, 168);
        var lines = [text];
        if (text) {
            ctx.font = '900 ' + size + 'px ' + family;
            while (size > min && ctx.measureText(text).width > maxWidth) {
                size -= 2;
                ctx.font = '900 ' + size + 'px ' + family;
            }
            if (ctx.measureText(text).width > maxWidth) {
                var best = min;
                var limit = Math.min(codeHeight * 0.46, 120);
                for (var probe = min; probe <= limit; probe += 2) {
                    var two = splitCode(text, family, probe);
                    ctx.font = '900 ' + probe + 'px ' + family;
                    var wide = Math.max(ctx.measureText(two[0]).width, ctx.measureText(two[1]).width);
                    if (wide <= maxWidth && probe * 2.15 <= codeHeight) best = probe;
                }
                size = best;
                lines = splitCode(text, family, size);
            }
        }
        var lineH = size * 1.08;
        var block = text ? lineH * lines.length : 0;
        var top = cy - (block + namaSlot) / 2;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = color;
        if (text) {
            ctx.font = '900 ' + size + 'px ' + family;
            lines.forEach(function (lineText, index) {
                ctx.fillText(lineText, cx, top + lineH * index + lineH / 2);
            });
        }
        if (nama) {
            fit(nama, cx, top + block + namaSlot / 2, maxWidth, Math.max(18, namaSlot * 0.72), 700, 16, color);
        }
    }
    function boxDraw(x, y, w, h, r, shadow, stroke) {
        if (shadow) {
            rr(x, y + 5, w, h, r);
            ctx.fillStyle = T.shadow;
            ctx.fill();
        }
        rr(x, y, w, h, r);
        ctx.fillStyle = boxFill();
        ctx.fill();
        if (stroke) {
            ctx.lineWidth = 4;
            ctx.strokeStyle = stroke;
            ctx.stroke();
        }
    }
    function pill(cx, y, label, bg, border, color, h, r, minW, maxW, fontSize, align) {
        if (!label) return 0;
        ctx.font = '800 ' + fontSize + 'px ' + F;
        var w = Math.min(maxW, Math.max(minW, ctx.measureText(label).width + 36));
        var x = align === 'r' ? cx - w : align === 'l' ? cx : cx - w / 2;
        rr(x, y, w, h, r);
        ctx.fillStyle = bg;
        ctx.fill();
        if (border) {
            ctx.lineWidth = 1.5;
            ctx.strokeStyle = border;
            ctx.stroke();
        }
        fit(label, x + w / 2, y + h / 2 + 0.5, w - 16, fontSize, 800, 9, color);
        return w;
    }
    function frame(W, H) {
        rr(2, 2, W - 4, H - 4, Math.min(42, Math.min(W, H) * 0.07));
        ctx.fillStyle = '#051416';
        ctx.fill();
    }
    function pattern(W, H, radius, clipW) {
        ctx.save();
        rr(8, 8, W - 16, H - 16, radius);
        ctx.clip();
        ctx.beginPath();
        ctx.rect(0, 0, clipW || W, H);
        ctx.clip();
        ctx.strokeStyle = 'rgba(255,255,255,.05)';
        ctx.lineWidth = 1.5;
        var step = 56;
        for (var x = -step; x < W + step; x += step) {
            for (var y = -step; y < H + step; y += step) {
                ctx.save();
                ctx.translate(x + step / 2, y + step / 2);
                ctx.rotate(Math.PI / 4);
                ctx.strokeRect(-14, -14, 28, 28);
                ctx.rotate(Math.PI / 4);
                ctx.strokeRect(-14, -14, 28, 28);
                ctx.restore();
            }
        }
        ctx.restore();
    }
    function bgFrame(W, H) {
        rr(8, 8, W - 16, H - 16, 36);
        ctx.fillStyle = T.bg;
        ctx.fill();
        pattern(W, H, 36);
        rr(18, 18, W - 36, H - 36, 28);
        ctx.strokeStyle = lineCol();
        ctx.lineWidth = 3;
        ctx.stroke();
    }

    function qrImage(data) {
        if (qrCache.d === data && qrCache.c) return qrCache.c;
        if (typeof QRCode === 'undefined') return null;
        var box = document.getElementById('hiddenQrContainer');
        if (!box) return qrCache.c;
        box.innerHTML = '';
        try {
            new QRCode(box, {
                text: data || '-',
                width: 512,
                height: 512,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
            var canvas = box.querySelector('canvas');
            if (canvas) qrCache = { d: data, c: canvas };
        } catch (err) {
            console.error(err);
        }
        return qrCache.c;
    }
    function qrCard(x, y, w, h, stroke, labelColor, subColor) {
        rr(x, y, w, h, 22);
        ctx.fillStyle = '#fff';
        ctx.fill();
        if (stroke) {
            ctx.lineWidth = 4;
            ctx.strokeStyle = stroke;
            ctx.stroke();
        }
        var sub = val('qrSubText');
        var band = sub ? 76 : 50;
        var pad = Math.max(14, Math.round(Math.min(w, h) * 0.045));
        var room = h - band - pad;
        if (room < 88) {
            band = Math.max(sub ? 44 : 32, Math.round(h * 0.2));
            pad = Math.max(10, Math.round(pad * 0.8));
            room = h - band - pad;
        }
        var size = Math.min(w - pad * 2, room);
        if (size < 64) {
            band = sub ? 40 : 28;
            pad = 8;
            size = Math.max(48, Math.min(w - pad * 2, h - band - pad));
        }
        var image = qrImage(val('qrContent') || val('qrText') || '-');
        var qx = x + (w - size) / 2;
        var qy = y + pad;
        if (image) ctx.drawImage(image, qx, qy, size, size);
        var labelSize = Math.max(20, Math.round(band * 0.34));
        var labelY = y + pad + size + (band * (sub ? 0.34 : 0.5));
        fit(val('qrText').toUpperCase(), x + w / 2, labelY, w - 24, labelSize, 900, 18, labelColor);
        if (sub) fit(sub, x + w / 2, labelY + labelSize * 0.95, w - 24, Math.max(14, labelSize * 0.62), 600, 13, subColor || '#526058');
    }

    function disc(cx, cy, r, color) {
        ctx.beginPath();
        ctx.arc(cx, cy, Math.max(1, r), 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();
    }
    function arcText(text, cx, cy, radius, start, end, bottom) {
        var chars = Array.from(text);
        var widths = chars.map(function (ch) { return ctx.measureText(ch).width * 1.12 / radius; });
        var total = widths.reduce(function (sum, n) { return sum + n; }, 0);
        var mid = (start + end) / 2;
        var angle = bottom ? mid + total / 2 : mid - total / 2;
        chars.forEach(function (ch, i) {
            var ang = bottom ? angle - widths[i] / 2 : angle + widths[i] / 2;
            ctx.save();
            ctx.translate(cx + Math.cos(ang) * radius, cy + Math.sin(ang) * radius);
            ctx.rotate(bottom ? ang - Math.PI / 2 : ang + Math.PI / 2);
            ctx.fillText(ch, 0, 0);
            ctx.restore();
            angle += bottom ? -widths[i] : widths[i];
        });
    }
    function emblem(cx, cy, r) {
        ctx.save();
        var scale = r / 122;
        var P = Math.PI;
        var logo = activeLogo();
        disc(cx, cy, r, '#051416');
        disc(cx, cy, r - 5 * scale, T.box);
        disc(cx, cy, r - 12 * scale, '#fff');
        if (logo) {
            ctx.beginPath();
            ctx.arc(cx, cy, r - 12 * scale, 0, P * 2);
            ctx.clip();
            var inner = r - 12 * scale;
            var fitScale = Math.min(inner * 1.85 / logo.width, inner * 1.85 / logo.height);
            ctx.drawImage(logo, cx - logo.width * fitScale / 2, cy - logo.height * fitScale / 2, logo.width * fitScale, logo.height * fitScale);
            ctx.restore();
            return;
        }
        var outer = r - 16 * scale;
        var innerBand = r - 44 * scale;
        ctx.beginPath();
        ctx.arc(cx, cy, outer, P * 0.92, P * 2.08);
        ctx.arc(cx, cy, innerBand, P * 2.08, P * 0.92, true);
        ctx.closePath();
        ctx.fillStyle = T.ld;
        ctx.fill();
        ctx.beginPath();
        ctx.arc(cx, cy, outer, P * 0.08, P * 0.92);
        ctx.arc(cx, cy, innerBand, P * 0.92, P * 0.08, true);
        ctx.closePath();
        ctx.fillStyle = T.ll;
        ctx.fill();
        var textRadius = (outer + innerBand) / 2;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#fff';
        ctx.font = '800 ' + (r * 0.098) + 'px ' + F;
        if (r >= 60) arcText('ASRAMA PELAJAR ISLAM', cx, cy, textRadius, -P * 0.86, -P * 0.14, false);
        ctx.fillStyle = '#052e16';
        ctx.font = '800 ' + (r * 0.085) + 'px ' + F;
        if (r >= 60) arcText('NAILUL MUNA GONDOSULI', cx, cy, textRadius, P * 0.16, P * 0.84, true);
        disc(cx, cy, innerBand - 4 * scale, '#fff');
        ctx.lineWidth = Math.max(1, 2 * scale);
        ctx.strokeStyle = T.ld;
        ctx.stroke();
        var s = r / (r < 60 ? 105 : 132);
        ctx.fillStyle = T.ld;
        ctx.beginPath();
        ctx.moveTo(cx, cy - 46 * s);
        ctx.lineTo(cx + 34 * s, cy - 22 * s);
        ctx.lineTo(cx + 26 * s, cy + 32 * s);
        ctx.lineTo(cx, cy + 46 * s);
        ctx.lineTo(cx - 26 * s, cy + 32 * s);
        ctx.lineTo(cx - 34 * s, cy - 22 * s);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.fillRect(cx - 8 * s, cy - 24 * s, 16 * s, 32 * s);
        ctx.beginPath();
        ctx.arc(cx, cy - 24 * s, Math.max(0.5, 9 * s), P, 0);
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(cx, cy + 12 * s);
        ctx.quadraticCurveTo(cx - 14 * s, cy + 6 * s, cx - 22 * s, cy + 12 * s);
        ctx.lineTo(cx - 22 * s, cy + 26 * s);
        ctx.quadraticCurveTo(cx - 14 * s, cy + 20 * s, cx, cy + 26 * s);
        ctx.quadraticCurveTo(cx + 14 * s, cy + 20 * s, cx + 22 * s, cy + 26 * s);
        ctx.lineTo(cx + 22 * s, cy + 12 * s);
        ctx.quadraticCurveTo(cx + 14 * s, cy + 6 * s, cx, cy + 12 * s);
        ctx.fill();
        ctx.restore();
    }

    function classic(W, H) {
        bgFrame(W, H);
        line(300, 56, 300, H - 56, divCol());
        line(868, 56, 868, H - 56, divCol());
        emblem(154, H / 2, 104);
        fit(org(), 584, 72, 520, 30, 900, 16, '#fff');
        fit(val('orgAddress'), 584, 108, 520, 18, 600, 12, T.sub);
        boxDraw(324, 132, 520, 214, 24, true);
        code(584, 239, 476, 184, T.text);
        fit(foot(), 584, 378, 520, 18, 800, 12, '#fff');
        pill(584, 418, badge(), T.badge, T.sub, T.sub, 26, 13, 180, 520, 13.5);
        qrCard(888, 40, 276, 400, null, T.text);
    }
    function modern(W, H) {
        bgFrame(W, H);
        ctx.save();
        rr(20, 20, W - 40, H - 40, 26);
        ctx.clip();
        ctx.fillStyle = 'rgba(0,0,0,.24)';
        ctx.fillRect(20, 20, W - 40, 118);
        ctx.fillStyle = lineCol();
        ctx.fillRect(20, 136, W - 40, 2.5);
        ctx.restore();
        emblem(94, 80, 47);
        fit(org(), 166, 62, W - 208, 32, 900, 16, '#fff', 'left');
        fit(val('orgAddress'), 166, 102, W - 208, 19, 600, 11, T.sub, 'left');
        boxDraw(40, 152, 792, 236, 22, true);
        code(436, 270, 744, 204, T.text);
        var pillH = 28;
        var pillY = 410;
        var pillW = pill(816, pillY, badge(), T.badge, T.sub, T.sub, pillH, 14, 176, 280, 13.5, 'r');
        fit(foot(), 48, pillY + pillH / 2, 760 - pillW, 18, 800, 12, '#fff', 'left');
        qrCard(856, 152, 304, 288, null, T.text);
    }
    function clean(W, H) {
        rr(8, 8, W - 16, H - 16, 36);
        ctx.fillStyle = '#f8fafc';
        ctx.fill();
        ctx.save();
        rr(8, 8, W - 16, H - 16, 36);
        ctx.clip();
        ctx.fillStyle = T.bg;
        ctx.fillRect(0, 0, 268, H);
        ctx.restore();
        pattern(W, H, 36, 268);
        ctx.save();
        rr(8, 8, W - 16, H - 16, 36);
        ctx.clip();
        ctx.fillStyle = lineCol();
        ctx.fillRect(264, 0, 6, H);
        ctx.restore();
        emblem(134, 240, 96);
        fit(org(), 568, 70, 500, 30, 900, 16, T.bg);
        fit(val('orgAddress'), 568, 106, 500, 18, 600, 12, '#475569');
        boxDraw(296, 128, 524, 220, 24, false, cLine || T.bg);
        code(558, 238, 480, 188, T.text);
        fit(foot(), 558, 380, 500, 18, 800, 12, T.bg);
        pill(558, 420, badge(), T.bg, null, T.sub, 26, 13, 176, 500, 13.5);
        qrCard(848, 32, 316, 416, cLine || T.bg, '#0f172a', '#64748b');
    }
    function editorial(W, H) {
        var accent = cLine || T.bg;
        function label(text, x, y, spacing, size) {
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#64748b';
            ctx.font = '800 ' + size + 'px ' + F;
            ls(spacing);
            ctx.fillText(text, x, y);
            ls(0);
        }
        ctx.save();
        rr(2, 2, W - 4, H - 4, 34);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.clip();
        ctx.fillStyle = accent;
        ctx.fillRect(0, 0, W, 16);
        emblem(94, 82, 46);
        label('INVENTARIS ASET', 160, 54, 4, 12.5);
        var raw = org().trim();
        var space = raw.indexOf(' ');
        var first = space > 0 ? raw.slice(0, space) : raw;
        var rest = space > 0 ? raw.slice(space) : '';
        var size = 36;
        ctx.font = '900 ' + size + 'px ' + F;
        while (ctx.measureText(raw).width > 704 && size > 18) {
            size -= 1;
            ctx.font = '900 ' + size + 'px ' + F;
        }
        ctx.textAlign = 'left';
        ctx.fillStyle = '#5a9e7f';
        ctx.fillText(first, 160, 98);
        ctx.fillStyle = T.bg;
        ctx.fillText(rest, 160 + ctx.measureText(first).width, 98);
        line(48, 142, 860, 142, cLine ? divCol() : '#cbd5e1');
        label('KATEGORI & LOKASI:', 48, 176, 2.5, 11.5);
        fit(foot(), 48, 208, 320, 20, 800, 13, '#0f172a', 'left');
        fit(val('orgAddress'), 48, 238, 320, 16, 500, 11, '#475569', 'left');
        line(48, 268, 360, 268, '#e2e8f0');
        label('STATUS PENGAWASAN:', 48, 298, 2.5, 11.5);
        pill(48, 328, badge(), T.bg, null, '#fff', 32, 10, 185, 320, 13, 'l');
        ctx.textAlign = 'left';
        ctx.fillStyle = '#64748b';
        ctx.font = '500 13px ' + F;
        ctx.fillText('Dokumen & Aset Resmi', 48, 392);
        boxDraw(392, 158, 468, 292, 20, false, T.bg);
        ctx.save();
        rr(392, 158, 468, 292, 20);
        ctx.clip();
        ctx.fillStyle = accent;
        ctx.fillRect(392, 158, 12, 292);
        ctx.restore();
        ctx.textAlign = 'center';
        ctx.fillStyle = T.text;
        ctx.font = '800 13px ' + F;
        ls(2.4);
        ctx.fillText('KODE UNIT INVENTARIS', 626, 186);
        ls(0);
        code(626, 318, 420, 210, T.text);
        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(878, 16, W - 878, H - 16);
        line(878, 16, 878, H, cLine ? divCol() : '#cbd5e1');
        qrCard(898, 32, W - 922, H - 64, '#cbd5e1', T.bg, '#64748b');
        ctx.restore();
        rr(2, 2, W - 4, H - 4, 34);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 4;
        ctx.stroke();
    }

    function vertical(W, H, ratio) {
        var port = ratio < 0.88;
        var pad = Math.round(Math.min(W, H) * 0.032);
        var outerR = Math.round(Math.min(W, H) * 0.055);
        var light = layout === 'clean-light' || layout === 'editorial';
        var fillColor = light ? T.bg : '#fff';
        var subColor = light ? '#475569' : T.sub;
        rr(8, 8, W - 16, H - 16, outerR);
        ctx.fillStyle = light ? '#fff' : T.bg;
        ctx.fill();
        if (!light) pattern(W, H, outerR);
        if (layout === 'editorial') {
            ctx.save();
            rr(8, 8, W - 16, H - 16, outerR);
            ctx.clip();
            ctx.fillStyle = cLine || T.bg;
            ctx.fillRect(0, 0, W, Math.max(12, H * 0.022));
            ctx.restore();
        }
        rr(pad, pad, W - 2 * pad, H - 2 * pad, Math.max(16, outerR - 8));
        ctx.strokeStyle = cLine || (light ? T.bg : T.acc);
        ctx.lineWidth = 3;
        ctx.stroke();
        var top = pad + Math.round(H * 0.03);
        var logoR;
        var logoY;
        var sepY;
        var orgSize;
        var orgY;
        var addrY;
        if (port) {
            logoR = Math.round(Math.min(W * 0.085, H * 0.048));
            logoY = top + logoR;
            orgSize = Math.round(Math.min(W * 0.044, H * 0.026));
            orgY = logoY + logoR + Math.round(H * 0.016);
            addrY = orgY + Math.round(orgSize * 1.15);
            sepY = addrY + Math.round(H * 0.016);
        } else {
            var headerH = Math.round(H * 0.15);
            logoR = Math.round(Math.min(headerH * 0.38, W * 0.075));
            logoY = top + headerH * 0.46;
            orgSize = Math.round(Math.min(W * 0.036, headerH * 0.24));
            sepY = top + headerH + Math.round(H * 0.012);
        }
        if (layout === 'modern-header') {
            ctx.save();
            rr(pad + 2, pad + 2, W - 2 * pad - 4, H - 2 * pad - 4, Math.max(14, outerR - 10));
            ctx.clip();
            ctx.fillStyle = 'rgba(0,0,0,.24)';
            ctx.fillRect(pad, pad, W - 2 * pad, sepY - pad);
            ctx.fillStyle = lineCol();
            ctx.fillRect(pad, sepY, W - 2 * pad, 2.5);
            ctx.restore();
        }
        if (port) {
            emblem(W / 2, logoY, logoR);
            fit(org(), W / 2, orgY, W - 2 * pad - 30, orgSize, 900, 13, fillColor);
            fit(val('orgAddress'), W / 2, addrY, W - 2 * pad - 30, Math.round(orgSize * 0.62), 600, 10, subColor);
        } else {
            var logoX = pad + Math.round(W * 0.04) + logoR;
            emblem(logoX, logoY, logoR);
            var textX = logoX + logoR + Math.round(W * 0.032);
            var textW = W - textX - pad - Math.round(W * 0.03);
            fit(org(), textX, logoY - logoR * 0.26, textW, orgSize, 900, 13, fillColor, 'left');
            fit(val('orgAddress'), textX, logoY + logoR * 0.3, textW, Math.round(orgSize * 0.62), 600, 10, subColor, 'left');
        }
        if (layout !== 'modern-header') line(pad + 16, sepY, W - pad - 16, sepY, light ? '#cbd5e1' : divCol());
        var boxX = pad + Math.round(W * 0.04);
        var boxW = W - 2 * boxX;
        var boxY = sepY + Math.round(H * 0.018);
        var boxH = Math.round(H * (port ? 0.26 : 0.32));
        boxDraw(boxX, boxY, boxW, boxH, 20, !light, light ? T.bg : null);
        code(W / 2, boxY + boxH / 2, boxW - 40, boxH - 24, T.text);
        var lowerTop = boxY + boxH + Math.round(H * 0.02);
        var lowerH = H - lowerTop - pad - Math.round(H * 0.018);
        if (port) {
            var qrH = Math.round(lowerH * 0.8);
            var qrW = Math.min(boxW, Math.round(qrH * 0.96));
            qrCard((W - qrW) / 2, lowerTop, qrW, qrH, light ? T.bg : null, T.text);
            var footY = lowerTop + qrH + Math.max(16, Math.round((lowerH - qrH) * 0.38));
            fit(foot(), W / 2, footY, W - 2 * pad - 24, Math.round(W * 0.034), 800, 12, fillColor);
            pill(W / 2, footY + Math.round(W * 0.045), badge(), light ? T.bg : T.badge, light ? null : T.sub, T.sub, Math.round(W * 0.048), Math.round(W * 0.024), 180, W - 2 * pad - 30, Math.max(12, Math.round(W * 0.024)));
        } else {
            var qrW2 = Math.round(boxW * 0.58);
            qrCard(boxX, lowerTop, qrW2, lowerH, light ? T.bg : null, T.text);
            var infoX = boxX + qrW2 + Math.round(W * 0.03);
            var infoW = boxX + boxW - infoX;
            var infoMid = infoX + infoW / 2;
            fit('KATEGORI INVENTARIS', infoMid, lowerTop + lowerH * 0.22, infoW, Math.max(12, Math.round(W * 0.018)), 800, 11, light ? '#64748b' : T.sub);
            fit(foot(), infoMid, lowerTop + lowerH * 0.44, infoW, Math.round(W * 0.032), 900, 13, fillColor);
            pill(infoMid, lowerTop + lowerH * 0.7, badge(), light ? T.bg : T.badge, light ? null : T.sub, T.sub, Math.round(lowerH * 0.16), Math.round(lowerH * 0.08), 165, infoW, Math.max(12, Math.round(W * 0.02)));
        }
    }

    function fileName() {
        var raw = (val('qrText') || val('mainCode') || 'stiker-inventaris').trim();
        return raw.replace(/[^a-zA-Z0-9-_]/g, '_') + '.jpg';
    }
    function render() {
        var W = cv.width;
        var H = cv.height;
        var ratio = W / H;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, W, H);
        if (ratio < 1.35) {
            frame(W, H);
            vertical(W, H, ratio);
        } else {
            var scale = Math.min(W / BW, H / BH);
            var ox = (W - BW * scale) / 2;
            var oy = (H - BH * scale) / 2;
            if (ox > 0.5 || oy > 0.5) {
                ctx.fillStyle = layout === 'clean-light' ? '#f8fafc' : layout === 'editorial' ? '#ffffff' : T.bg;
                ctx.fillRect(0, 0, W, H);
            }
            ctx.save();
            ctx.translate(ox, oy);
            ctx.scale(scale, scale);
            frame(BW, BH);
            if (layout === 'modern-header') modern(BW, BH);
            else if (layout === 'clean-light') clean(BW, BH);
            else if (layout === 'editorial') editorial(BW, BH);
            else classic(BW, BH);
            ctx.restore();
        }
        var nameEl = document.getElementById('previewFilename');
        if (nameEl) nameEl.textContent = fileName();
        var dim = document.getElementById('dimLabel');
        if (dim) dim.textContent = 'Pratinjau stiker (' + W + ' × ' + H + ' px)';
        var badgeEl = document.getElementById('sizeBadge');
        if (badgeEl) badgeEl.textContent = W + ' × ' + H + ' px';
    }

    function fontsReady() {
        var family = val('mainCodeFont');
        if (!document.fonts || !document.fonts.load) return Promise.resolve();
        return Promise.all([
            document.fonts.load("500 24px 'Inter'"),
            document.fonts.load("600 24px 'Inter'"),
            document.fonts.load("700 24px 'Inter'"),
            document.fonts.load("800 24px 'Inter'"),
            document.fonts.load("900 24px 'Inter'"),
            document.fonts.load('900 24px ' + family),
            document.fonts.load('700 24px ' + family)
        ]).catch(function () {});
    }

    function mark(selector, attr, value) {
        document.querySelectorAll(selector).forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute(attr) === value);
        });
    }
    function setLayout(name) {
        layout = name;
        mark('[data-layout]', 'data-layout', name);
        render();
    }
    function setSize(width, height) {
        var w = Math.max(300, Math.min(3000, parseInt(width, 10) || 1200));
        var h = Math.max(300, Math.min(3000, parseInt(height, 10) || 480));
        cv.width = w;
        cv.height = h;
        document.getElementById('cw').value = String(w);
        document.getElementById('ch').value = String(h);
        var wrap = document.getElementById('wrap');
        if (wrap) wrap.style.maxWidth = w < h ? '380px' : w === h ? '480px' : '720px';
        render();
    }
    function fillPresets() {
        var select = document.getElementById('sizePreset');
        select.innerHTML = PRESETS[orient].map(function (preset, index) {
            return '<option value="' + index + '">' + preset[0] + '</option>';
        }).join('') + '<option value="custom">Ukuran kustom (manual)</option>';
    }
    function setOrient(name, applyDefault) {
        orient = name;
        mark('[data-orient]', 'data-orient', name);
        fillPresets();
        if (applyDefault) {
            document.getElementById('sizePreset').value = '0';
            setSize(PRESETS[name][0][1], PRESETS[name][0][2]);
        }
    }
    function setTheme(key) {
        if (!THEMES[key]) return;
        T = THEMES[key];
        cBox = null;
        cLine = null;
        inputs.boxColorPicker.value = T.box;
        document.getElementById('boxColorHex').textContent = T.box.toUpperCase();
        inputs.lineColorPicker.value = T.acc;
        document.getElementById('lineColorHex').textContent = T.acc.toUpperCase();
        mark('[data-theme]', 'data-theme', key);
        render();
    }
    function setBox(color) {
        cBox = color;
        inputs.boxColorPicker.value = color;
        document.getElementById('boxColorHex').textContent = color.toUpperCase();
        render();
    }
    function setLine(color) {
        cLine = color;
        inputs.lineColorPicker.value = color;
        document.getElementById('lineColorHex').textContent = color.toUpperCase();
        render();
    }

    function applySheetCode() {
        var loc = val('sheetLocCode').trim().toUpperCase();
        var cat = val('sheetCatCode').trim().toUpperCase();
        var seqRaw = val('sheetSeqNum').trim().toUpperCase();
        var seq = /^\d+$/.test(seqRaw) ? seqRaw.padStart(Math.max(2, parseInt(config.digitUrut, 10) || 2), '0') : seqRaw;
        var parent = val('sheetParentCode').trim().toUpperCase();
        if (seq !== seqRaw) inputs.sheetSeqNum.value = seq;
        var generated = [loc, cat, seq].filter(Boolean).join('-');
        if (generated) {
            inputs.mainCode.value = generated;
            inputs.qrText.value = generated;
            if (!qrMan) inputs.qrContent.value = expectedQr(generated);
        }
        inputs.qrSubText.value = parent ? 'Set Induk: ' + parent : 'Pindai untuk cek data';
        render();
    }
    function bump(text, delta) {
        return String(text).replace(/(\d+)(?!.*\d)/, function (match) {
            return String(Math.max(0, parseInt(match, 10) + delta)).padStart(match.length, '0');
        });
    }
    function stepNumber(delta) {
        var seq = val('sheetSeqNum').trim();
        if (/^\d+$/.test(seq)) {
            inputs.sheetSeqNum.value = String(Math.max(1, parseInt(seq, 10) + delta)).padStart(seq.length, '0');
        }
        inputs.mainCode.value = bump(val('mainCode'), delta);
        inputs.qrText.value = bump(val('qrText'), delta);
        if (!qrMan) inputs.qrContent.value = expectedQr(val('qrText'));
        else inputs.qrContent.value = bump(val('qrContent'), delta);
        render();
    }
    function applyItem(index) {
        var item = items[index] || items[0];
        if (!item) return;
        currentIndex = index;
        qrMan = false;
        inputs.sheetLocCode.value = item.kodeLokasi || '';
        inputs.sheetCatCode.value = item.kodeKategori || '';
        inputs.sheetSeqNum.value = item.nomorUrut || '';
        inputs.sheetParentCode.value = item.kodeInduk || '';
        inputs.mainCode.value = item.kode || '';
        inputs.qrText.value = item.qrText || item.kode || '';
        inputs.qrSubText.value = item.qrSubText || 'Pindai untuk cek data';
        inputs.qrContent.value = item.qrContent || expectedQr(item.kode || '');
        inputs.footerText.value = item.footerText || 'ASET INVENTARIS';
        inputs.badgeText.value = item.badgeText || 'JANGAN DILEPAS';
        var picker = document.getElementById('pilih-barang');
        if (picker) picker.value = String(index);
        render();
    }

    function syncItem(index) {
        var item = items[index];
        if (!item) return;
        currentIndex = index;
        qrMan = false;
        inputs.mainCode.value = item.kode || '';
        inputs.qrText.value = item.qrText || item.kode || '';
        inputs.qrSubText.value = item.qrSubText || 'Pindai untuk cek data';
        inputs.qrContent.value = item.qrContent || expectedQr(item.kode || '');
        inputs.footerText.value = item.footerText || 'ASET INVENTARIS';
        inputs.badgeText.value = item.badgeText || 'JANGAN DILEPAS';
    }
    function fieldSnapshot() {
        return {
            mainCode: inputs.mainCode.value,
            qrText: inputs.qrText.value,
            qrSubText: inputs.qrSubText.value,
            qrContent: inputs.qrContent.value,
            footerText: inputs.footerText.value,
            badgeText: inputs.badgeText.value,
            qrMan: qrMan,
            index: currentIndex
        };
    }
    function restoreFields(snapshot) {
        inputs.mainCode.value = snapshot.mainCode;
        inputs.qrText.value = snapshot.qrText;
        inputs.qrSubText.value = snapshot.qrSubText;
        inputs.qrContent.value = snapshot.qrContent;
        inputs.footerText.value = snapshot.footerText;
        inputs.badgeText.value = snapshot.badgeText;
        qrMan = snapshot.qrMan;
        currentIndex = snapshot.index;
    }
    function downloadJPG(silent) {
        return fontsReady().then(function () {
            var snapshot = fieldSnapshot();
            syncItem(currentIndex);
            render();
            var name = fileName();
            var ok = false;
            try {
                var link = document.createElement('a');
                link.download = name;
                link.href = cv.toDataURL('image/jpeg', 0.96);
                document.body.appendChild(link);
                link.click();
                link.remove();
                if (!silent) showToast('Download berhasil', 'Berkas ' + name + ' telah disimpan.');
                ok = true;
            } catch (err) {
                if (!silent) showToast('Gagal export', 'Gambar tidak dapat diunduh. Coba tanpa logo luar.');
            }
            restoreFields(snapshot);
            render();
            return ok;
        });
    }

    var toastTimer;
    function showToast(title, message) {
        var toast = document.getElementById('toast');
        document.getElementById('toastTitle').textContent = title;
        document.getElementById('toastMessage').textContent = message;
        toast.classList.add('is-on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.remove('is-on'); }, 3200);
    }
    function resetView() {
        inputs.orgName.value = config.orgName || 'PONPES A.P.I NAILUL MUNA';
        inputs.orgAddress.value = config.orgAddress || 'Gondosuli, Muntilan, Magelang';
        inputs.mainCodeFont.value = "'Inter', sans-serif";
        uploadedLogo = null;
        document.getElementById('logoUpload').value = '';
        document.getElementById('logoFileName').textContent = config.logoUrl ? 'Logo pondok aktif' : 'Ganti logo (PNG/JPG)';
        document.getElementById('clearLogoBtn').classList.add('d-none');
        setOrient('landscape', true);
        setLayout('classic');
        setTheme('emerald');
        applyItem(currentIndex);
        showToast('Tampilan direset', 'Stiker kembali ke data barang, ukuran, dan tema awal.');
    }

    function loadImage(url) {
        return new Promise(function (resolve) {
            if (!url) {
                resolve(null);
                return;
            }
            var img = new Image();
            if (/^https?:/i.test(url)) img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { resolve(null); };
            img.src = url;
        });
    }

    ['sheetLocCode', 'sheetCatCode', 'sheetSeqNum', 'sheetParentCode'].forEach(function (key) {
        inputs[key].addEventListener('input', applySheetCode);
        inputs[key].addEventListener('change', applySheetCode);
    });
    inputs.qrText.addEventListener('input', function () {
        if (!qrMan) inputs.qrContent.value = expectedQr(val('qrText'));
        render();
    });
    inputs.qrContent.addEventListener('input', function () {
        qrMan = val('qrContent') !== expectedQr(val('qrText'));
        render();
    });
    ['mainCode', 'qrSubText', 'orgName', 'orgAddress', 'footerText', 'badgeText'].forEach(function (key) {
        inputs[key].addEventListener('input', render);
    });
    inputs.mainCodeFont.addEventListener('change', function () { fontsReady().then(render); });
    inputs.boxColorPicker.addEventListener('input', function (event) { setBox(event.target.value); });
    inputs.lineColorPicker.addEventListener('input', function (event) { setLine(event.target.value); });
    document.querySelectorAll('[data-box-color]').forEach(function (button) {
        button.addEventListener('click', function () { setBox(button.getAttribute('data-box-color')); });
    });
    document.querySelectorAll('[data-line-color]').forEach(function (button) {
        button.addEventListener('click', function () { setLine(button.getAttribute('data-line-color')); });
    });
    document.getElementById('samakan-garis').addEventListener('click', function () { setLine(boxFill()); });
    document.querySelectorAll('[data-layout]').forEach(function (button) {
        button.addEventListener('click', function () { setLayout(button.getAttribute('data-layout')); });
    });
    document.querySelectorAll('[data-orient]').forEach(function (button) {
        button.addEventListener('click', function () { setOrient(button.getAttribute('data-orient'), true); });
    });
    document.querySelectorAll('[data-theme]').forEach(function (button) {
        button.addEventListener('click', function () { setTheme(button.getAttribute('data-theme')); });
    });
    document.getElementById('sizePreset').addEventListener('change', function () {
        var selected = document.getElementById('sizePreset').value;
        if (selected === 'custom') return;
        var preset = PRESETS[orient][parseInt(selected, 10)];
        if (preset) setSize(preset[1], preset[2]);
    });
    ['cw', 'ch'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            document.getElementById('sizePreset').value = 'custom';
            setSize(document.getElementById('cw').value, document.getElementById('ch').value);
        });
    });
    document.getElementById('logoUpload').addEventListener('change', function (event) {
        var file = event.target.files && event.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (loadEvent) {
            var img = new Image();
            img.onload = function () {
                uploadedLogo = img;
                document.getElementById('logoFileName').textContent = file.name.slice(0, 28);
                document.getElementById('clearLogoBtn').classList.remove('d-none');
                render();
                showToast('Logo diperbarui', 'Logo kustom terpasang pada stiker.');
            };
            img.src = loadEvent.target.result;
        };
        reader.readAsDataURL(file);
    });
    document.getElementById('clearLogoBtn').addEventListener('click', function () {
        uploadedLogo = null;
        document.getElementById('logoUpload').value = '';
        document.getElementById('logoFileName').textContent = config.logoUrl ? 'Logo pondok aktif' : 'Ganti logo (PNG/JPG)';
        document.getElementById('clearLogoBtn').classList.add('d-none');
        render();
    });
    var pilih = document.getElementById('pilih-barang');
    if (pilih) pilih.addEventListener('change', function () { applyItem(parseInt(pilih.value, 10) || 0); });
    document.getElementById('nomor-sebelum').addEventListener('click', function () { stepNumber(-1); });
    document.getElementById('nomor-berikut').addEventListener('click', function () { stepNumber(1); });
    document.getElementById('unduh-jpg').addEventListener('click', function () { downloadJPG(false); });
    document.getElementById('unduh-jpg-bawah').addEventListener('click', function () { downloadJPG(false); });
    document.getElementById('reset-stiker').addEventListener('click', resetView);
    var semua = document.getElementById('unduh-semua');
    if (semua) {
        semua.addEventListener('click', function () {
            var asal = currentIndex;
            var i = 0;
            function berikutnya() {
                if (i >= items.length) {
                    applyItem(asal);
                    showToast('Unduh selesai', items.length + ' berkas JPG diminta.');
                    return;
                }
                applyItem(i);
                downloadJPG(true).then(function () {
                    i += 1;
                    setTimeout(berikutnya, 450);
                });
            }
            berikutnya();
        });
    }

    setOrient('landscape', false);
    loadImage(config.logoUrl || '').then(function (img) {
        pondokLogo = img;
        fontsReady().then(function () { applyItem(0); });
    });
})();
