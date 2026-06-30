(function () {
    'use strict';

    var cfg = window.UserCatatanConfig || {};
    var catatanId = parseInt(cfg.catatanId, 10) || 0;
    var saveUrl = cfg.saveUrl || '';
    var importUrl = cfg.importUrl || '';
    var initialGrid = Array.isArray(cfg.grid) ? cfg.grid : [];

    var statusEl = document.getElementById('catatan-save-status');
    var saveBtn = document.getElementById('catatan-btn-save');
    var importInput = document.getElementById('catatan-import-file');
    var container = document.getElementById('catatan-spreadsheet');
    var btnAddRow = document.getElementById('catatan-add-row');
    var btnAddCol = document.getElementById('catatan-add-col');

    var saveTimer = null;
    var saving = false;
    var dirty = false;

    function setStatus(text, variant) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = text;
        statusEl.className = 'badge align-self-center text-bg-' + (variant || 'secondary');
    }

    function normalizeGrid(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return [['', '', '']];
        }
        return data.map(function (row) {
            if (!Array.isArray(row)) {
                return [''];
            }
            return row.map(function (cell) {
                return cell === null || cell === undefined ? '' : String(cell);
            });
        });
    }

    function getGridData() {
        if (!container) {
            return normalizeGrid(initialGrid);
        }
        var rows = container.querySelectorAll('tbody tr');
        var grid = [];
        rows.forEach(function (tr) {
            var line = [];
            tr.querySelectorAll('td[contenteditable]').forEach(function (td) {
                line.push((td.textContent || '').trim());
            });
            grid.push(line);
        });
        return normalizeGrid(grid.length ? grid : initialGrid);
    }

    function trimTrailingEmpty(grid) {
        var data = normalizeGrid(grid);
        while (data.length > 1) {
            var last = data[data.length - 1];
            if (last.some(function (c) { return c !== ''; })) {
                break;
            }
            data.pop();
        }
        return data.length ? data : [['', '', '']];
    }

    function renderGrid(grid) {
        if (!container) {
            return;
        }
        var data = trimTrailingEmpty(grid);
        var colCount = 0;
        data.forEach(function (row) {
            colCount = Math.max(colCount, row.length);
        });
        colCount = Math.max(3, colCount);

        var html = '<div class="table-responsive user-catatan-table-wrap"><table class="table table-sm table-bordered user-catatan-table mb-0"><tbody>';
        data.forEach(function (row) {
            html += '<tr>';
            for (var c = 0; c < colCount; c++) {
                var val = row[c] !== undefined ? row[c] : '';
                html += '<td contenteditable="true" spellcheck="false">' + escapeHtml(val) + '</td>';
            }
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;

        container.querySelectorAll('td[contenteditable]').forEach(function (td) {
            td.addEventListener('input', scheduleSave);
            td.addEventListener('blur', scheduleSave);
        });

        setStatus('Siap', 'secondary');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function scheduleSave() {
        dirty = true;
        setStatus('Belum disimpan…', 'warning');
        if (saveTimer) {
            clearTimeout(saveTimer);
        }
        saveTimer = setTimeout(function () {
            saveGrid(false);
        }, 1500);
    }

    function saveGrid(manual) {
        if (!saveUrl || catatanId <= 0 || saving) {
            return;
        }
        if (!dirty && !manual) {
            return;
        }
        saving = true;
        setStatus('Menyimpan…', 'info');

        var body = new FormData();
        body.append('catatan_id', String(catatanId));
        body.append('grid', JSON.stringify(getGridData()));

        fetch(saveUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error((json && json.error) || 'Gagal menyimpan');
                }
                dirty = false;
                setStatus('Tersimpan', 'success');
            })
            .catch(function (err) {
                setStatus('Gagal simpan', 'danger');
                if (manual) {
                    alert(err.message || 'Gagal menyimpan catatan.');
                }
            })
            .finally(function () {
                saving = false;
            });
    }

    function importXlsx(file) {
        if (!importUrl || !file || catatanId <= 0) {
            return;
        }
        if (!window.confirm('Impor file ini akan mengganti seluruh isi catatan. Lanjutkan?')) {
            return;
        }
        setStatus('Mengimpor…', 'info');
        var body = new FormData();
        body.append('catatan_id', String(catatanId));
        body.append('file_import', file);

        fetch(importUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error((json && json.error) || 'Gagal impor');
                }
                if (Array.isArray(json.grid)) {
                    renderGrid(json.grid);
                }
                dirty = false;
                setStatus('Impor berhasil', 'success');
            })
            .catch(function (err) {
                setStatus('Gagal impor', 'danger');
                alert(err.message || 'Gagal mengimpor file.');
            });
    }

    function addRow() {
        var grid = getGridData();
        var cols = grid[0] ? grid[0].length : 3;
        var row = [];
        for (var i = 0; i < cols; i++) {
            row.push('');
        }
        grid.push(row);
        renderGrid(grid);
        scheduleSave();
    }

    function addCol() {
        var grid = getGridData();
        grid = grid.map(function (row) {
            row.push('');
            return row;
        });
        renderGrid(grid);
        scheduleSave();
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (saveTimer) {
                clearTimeout(saveTimer);
            }
            saveGrid(true);
        });
    }

    if (importInput) {
        importInput.addEventListener('change', function () {
            var file = importInput.files && importInput.files[0];
            importInput.value = '';
            if (file) {
                importXlsx(file);
            }
        });
    }

    if (btnAddRow) {
        btnAddRow.addEventListener('click', addRow);
    }
    if (btnAddCol) {
        btnAddCol.addEventListener('click', addCol);
    }

    renderGrid(initialGrid);
})();
