(function () {
    'use strict';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }
    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    var table = qs('#ynTimelineTable tbody');
    var jsonInput = qs('#timeline_json');
    var form = qs('#ynNotulenForm');

    function emptyRow() {
        return {
            bagian: '',
            keputusan: '',
            penanggung_jawab: '',
            waktu_mulai: '',
            batas_waktu: '',
            keterangan: ''
        };
    }

    function readRows() {
        if (!table) return [];
        return qsa('tr', table).map(function (tr) {
            var waktuMulai = (qs('[data-field="waktu_mulai"]', tr) || {}).value || '';
            var batas = (qs('[data-field="batas_waktu"]', tr) || {}).value || '';
            if (batas.indexOf('T') > -1) {
                batas = batas.replace('T', ' ');
            }
            return {
                bagian: (qs('[data-field="bagian"]', tr) || {}).value || '',
                keputusan: (qs('[data-field="keputusan"]', tr) || {}).value || '',
                penanggung_jawab: (qs('[data-field="penanggung_jawab"]', tr) || {}).value || '',
                waktu_mulai: waktuMulai,
                batas_waktu: batas,
                keterangan: (qs('[data-field="keterangan"]', tr) || {}).value || ''
            };
        }).filter(function (r) {
            return r.keputusan.trim() !== '' || r.bagian.trim() !== '' || r.penanggung_jawab.trim() !== '';
        });
    }

    function syncJson() {
        if (jsonInput) {
            jsonInput.value = JSON.stringify(readRows());
        }
    }

    function addRow(data) {
        if (!table) return;
        data = data || emptyRow();
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" class="form-control form-control-sm" data-field="bagian" placeholder="Bagian"></td>' +
            '<td><input type="text" class="form-control form-control-sm" data-field="keputusan" placeholder="Keputusan"></td>' +
            '<td><input type="text" class="form-control form-control-sm" data-field="penanggung_jawab" placeholder="PJ"></td>' +
            '<td><input type="time" class="form-control form-control-sm" data-field="waktu_mulai" step="60" title="Format 24 jam"></td>' +
            '<td><input type="datetime-local" class="form-control form-control-sm" data-field="batas_waktu" step="60" title="Batas waktu"></td>' +
            '<td><input type="text" class="form-control form-control-sm" data-field="keterangan" placeholder="Keterangan"></td>' +
            '<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-danger yn-row-del" title="Hapus baris">&times;</button></td>';
        table.appendChild(tr);
        qsa('input', tr).forEach(function (inp) {
            var field = inp.getAttribute('data-field');
            if (field && data[field] !== undefined) {
                var val = data[field];
                if (field === 'waktu_mulai' && val.indexOf(' ') > -1) {
                    val = val.split(' ')[1].substring(0, 5);
                }
                if (field === 'batas_waktu') {
                    val = val.replace(' ', 'T').substring(0, 16);
                }
                inp.value = val;
            }
            inp.addEventListener('input', syncJson);
        });
        tr.querySelector('.yn-row-del').addEventListener('click', function () {
            if (qsa('tr', table).length <= 1) {
                qsa('input', tr).forEach(function (i) { i.value = ''; });
                syncJson();
                return;
            }
            tr.remove();
            syncJson();
        });
        syncJson();
    }

    if (table) {
        var initial = [];
        try {
            initial = JSON.parse(jsonInput && jsonInput.value ? jsonInput.value : '[]');
        } catch (e) {
            initial = [];
        }
        if (!initial.length) {
            addRow(emptyRow());
        } else {
            initial.forEach(function (row) { addRow(row); });
        }
        var addBtn = qs('#ynTimelineAddRow');
        if (addBtn) {
            addBtn.addEventListener('click', function () { addRow(emptyRow()); });
        }
    }

    if (form) {
        form.addEventListener('submit', syncJson);
    }

    function insertAtCursor(textarea, prefix) {
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var val = textarea.value;
        var before = val.substring(0, start);
        var selected = val.substring(start, end);
        var after = val.substring(end);
        var lineStart = before.lastIndexOf('\n') + 1;
        var atLineStart = before.length === lineStart;
        var insert = prefix + (selected || '');
        if (!atLineStart && before.length > 0 && before.charAt(before.length - 1) !== '\n') {
            insert = '\n' + insert;
        }
        textarea.value = before + insert + after;
        var pos = (before + insert).length;
        textarea.focus();
        textarea.setSelectionRange(pos, pos);
    }

    qsa('[data-yn-format]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var mode = btn.getAttribute('data-yn-format');
            var ta = targetId ? document.getElementById(targetId) : null;
            if (!ta) return;
            if (mode === 'num') insertAtCursor(ta, '1. ');
            if (mode === 'bullet') insertAtCursor(ta, '• ');
        });
    });

    qsa('[data-yn-preview]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var previewId = btn.getAttribute('data-preview');
            var ta = targetId ? document.getElementById(targetId) : null;
            var box = previewId ? document.getElementById(previewId) : null;
            if (!ta || !box) return;
            var lines = ta.value.split(/\r?\n/);
            var html = '';
            var inOl = false;
            var inUl = false;
            function closeLists() {
                if (inOl) { html += '</ol>'; inOl = false; }
                if (inUl) { html += '</ul>'; inUl = false; }
            }
            lines.forEach(function (line) {
                var t = line.trim();
                if (t === '') { closeLists(); return; }
                var mNum = t.match(/^(\d+)[.)]\s+(.+)$/);
                if (mNum) {
                    if (!inOl) { closeLists(); html += '<ol>'; inOl = true; }
                    html += '<li>' + escapeHtml(mNum[2]) + '</li>';
                    return;
                }
                var mBul = t.match(/^[-•*]\s+(.+)$/);
                if (mBul) {
                    if (!inUl) { closeLists(); html += '<ul>'; inUl = true; }
                    html += '<li>' + escapeHtml(mBul[1]) + '</li>';
                    return;
                }
                closeLists();
                html += '<p>' + escapeHtml(t) + '</p>';
            });
            closeLists();
            box.innerHTML = html || '<p class="text-muted mb-0">—</p>';
            box.classList.remove('d-none');
        });
    });

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
