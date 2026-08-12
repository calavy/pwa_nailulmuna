/**
 * Jadwal — sidebar focus, toolbar, kartu interaktif, tab hari mobile.
 */
(function () {
    'use strict';

    var DESKTOP_MQ = window.matchMedia('(min-width: 992px)');
    var focusPage = document.body.classList.contains('jadwal-page--focus');

    function initSidebarCollapsed() {
        if (!focusPage || !document.querySelector('.app-sidebar--desktop')) {
            return;
        }
        function apply() {
            var collapsed = DESKTOP_MQ.matches;
            document.body.classList.toggle('app-sidebar-collapsed', collapsed);
            document.querySelectorAll('.app-sidebar--desktop .app-side-nav-item').forEach(function (item) {
                if (collapsed) {
                    var label = item.querySelector('.app-side-nav-text');
                    if (label && !item.getAttribute('title')) {
                        item.setAttribute('title', label.textContent.trim());
                    }
                }
            });
        }
        apply();
        if (typeof DESKTOP_MQ.addEventListener === 'function') {
            DESKTOP_MQ.addEventListener('change', apply);
        } else if (typeof DESKTOP_MQ.addListener === 'function') {
            DESKTOP_MQ.addListener(apply);
        }
    }

    function cardDataFromEl(card) {
        if (!card) {
            return null;
        }
        return {
            el: card,
            editId: card.getAttribute('data-edit-id') || '',
            kegiatanId: card.getAttribute('data-kegiatan-id') || '',
            kegiatanNama: card.getAttribute('data-kegiatan-nama') || '',
            kategori: card.getAttribute('data-kategori') || '',
            jamMulai: card.getAttribute('data-jam-mulai') || '',
            jamSelesai: card.getAttribute('data-jam-selesai') || '',
            jamTampil: card.getAttribute('data-jam-tampil') || '',
            pembimbingId: card.getAttribute('data-pembimbing-id') || '0',
            pembimbingNama: card.getAttribute('data-pembimbing-nama') || '—',
            tempat: card.getAttribute('data-tempat') || '—',
            tingkatan: card.getAttribute('data-tingkatan') || '[]',
            hari: card.getAttribute('data-hari') || '[]',
            deleteIds: card.getAttribute('data-delete-ids') || '',
            editUrl: card.getAttribute('data-edit-url') || '#',
            tingkatanLabel: card.getAttribute('data-tingkatan-label') || '—'
        };
    }

    function fakeQuickEditBtn(data) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'jadwal-quick-edit d-none';
        btn.setAttribute('data-edit-id', data.editId);
        btn.setAttribute('data-kegiatan-id', data.kegiatanId);
        btn.setAttribute('data-kegiatan-nama', data.kegiatanNama);
        btn.setAttribute('data-kategori', data.kategori);
        btn.setAttribute('data-jam-mulai', data.jamMulai);
        btn.setAttribute('data-jam-selesai', data.jamSelesai);
        btn.setAttribute('data-pembimbing-id', data.pembimbingId);
        btn.setAttribute('data-tempat', data.tempat);
        btn.setAttribute('data-tingkatan', data.tingkatan);
        btn.setAttribute('data-hari', data.hari);
        return btn;
    }

    function fakeDeleteBtn(data) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'jadwal-delete-one d-none';
        btn.setAttribute('data-delete-ids', data.deleteIds);
        btn.setAttribute('data-confirm', 'Hapus slot jadwal ini? Presensi terkait ikut dihapus.');
        return btn;
    }

    var activeCardData = null;
    var detailModalEl = document.getElementById('jadwalDetailModal');
    var detailModal = (detailModalEl && typeof bootstrap !== 'undefined')
        ? bootstrap.Modal.getOrCreateInstance(detailModalEl)
        : null;

    function fillDetailModal(data) {
        if (!detailModalEl || !data) {
            return;
        }
        activeCardData = data;
        var jamEl = document.getElementById('jd-jam');
        var kgEl = document.getElementById('jd-kegiatan');
        var tkEl = document.getElementById('jd-tingkatan');
        var pbEl = document.getElementById('jd-pembimbing');
        var tpEl = document.getElementById('jd-tempat');
        var fullLink = document.getElementById('jd-link-full');
        if (jamEl) {
            jamEl.textContent = data.jamTampil || '—';
        }
        if (kgEl) {
            kgEl.textContent = data.kegiatanNama || '—';
        }
        if (tkEl) {
            tkEl.textContent = data.tingkatanLabel || '—';
        }
        if (pbEl) {
            pbEl.textContent = data.pembimbingNama || '—';
        }
        if (tpEl) {
            tpEl.textContent = data.tempat || '—';
        }
        if (fullLink) {
            fullLink.href = data.editUrl || '#';
        }
        var delBtn = detailModalEl.querySelector('.jadwal-detail-delete');
        if (delBtn) {
            delBtn.setAttribute('data-delete-ids', data.deleteIds || '');
        }
    }

    function openQuickEditFromData(data) {
        var btn = fakeQuickEditBtn(data);
        document.body.appendChild(btn);
        btn.click();
        btn.remove();
    }

    function triggerDeleteFromData(data) {
        var btn = fakeDeleteBtn(data);
        document.body.appendChild(btn);
        btn.click();
        btn.remove();
    }

    var contextMenu = document.getElementById('jadwal-context-menu');
    var contextCardData = null;

    function hideContextMenu() {
        if (contextMenu) {
            contextMenu.classList.add('d-none');
        }
        contextCardData = null;
    }

    function showContextMenu(x, y, data) {
        if (!contextMenu || !data) {
            return;
        }
        contextCardData = data;
        contextMenu.classList.remove('d-none');
        var fullLink = contextMenu.querySelector('[data-action="full"]');
        if (fullLink) {
            fullLink.href = data.editUrl || '#';
        }
        var w = contextMenu.offsetWidth || 160;
        var h = contextMenu.offsetHeight || 120;
        var left = Math.min(x, window.innerWidth - w - 8);
        var top = Math.min(y, window.innerHeight - h - 8);
        contextMenu.style.left = Math.max(8, left) + 'px';
        contextMenu.style.top = Math.max(8, top) + 'px';
    }

    function initHariTabs() {
        var wrap = document.querySelector('.jadwal-hari-mobile');
        if (!wrap) {
            return;
        }
        var tabs = wrap.querySelectorAll('.jadwal-hari-tabs__btn');
        var panels = wrap.querySelectorAll('.jadwal-hari-panel');
        if (tabs.length === 0) {
            return;
        }
        function activate(hari) {
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-hari') === String(hari);
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-hari-panel') === String(hari));
            });
            try {
                var url = new URL(window.location.href);
                if (String(hari) === String(wrap.getAttribute('data-initial-hari') || '')) {
                    url.searchParams.delete('filter_hari');
                } else {
                    url.searchParams.set('filter_hari', String(hari));
                }
                window.history.replaceState({}, '', url.toString());
            } catch (e) { /* ignore */ }
        }
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activate(tab.getAttribute('data-hari'));
            });
        });
    }

    function initCardDropdownMenus() {
        document.querySelectorAll('.jadwal-slot-card__actions.dropdown').forEach(function (wrap) {
            var card = wrap.closest('.jadwal-slot-card');
            var toggle = wrap.querySelector('[data-bs-toggle="dropdown"]');
            if (!card || !toggle || typeof bootstrap === 'undefined') {
                return;
            }
            toggle.addEventListener('show.bs.dropdown', function () {
                card.classList.add('is-actions-open');
            });
            toggle.addEventListener('hide.bs.dropdown', function () {
                card.classList.remove('is-actions-open');
            });
            wrap.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });
    }

    function initCardInteractions() {
        document.addEventListener('click', function (e) {
            if (contextMenu && !e.target.closest('#jadwal-context-menu')) {
                hideContextMenu();
            }

            var saranBtn = e.target.closest('.jadwal-jamaah-isi-saran');
            if (saranBtn) {
                var form = saranBtn.closest('form');
                if (!form) {
                    return;
                }
                var jm = form.querySelector('.jadwal-jamaah-jm');
                var js = form.querySelector('.jadwal-jamaah-js');
                if (jm) {
                    jm.value = saranBtn.getAttribute('data-jm') || '';
                }
                if (js) {
                    js.value = saranBtn.getAttribute('data-js') || '';
                }
                return;
            }

            if (e.target.closest('.jadwal-detail-edit') && activeCardData) {
                if (detailModal) {
                    detailModal.hide();
                }
                openQuickEditFromData(activeCardData);
                return;
            }

            if (contextMenu && e.target.closest('#jadwal-context-menu')) {
                var action = e.target.closest('[data-action]');
                if (!action || !contextCardData) {
                    return;
                }
                var act = action.getAttribute('data-action');
                hideContextMenu();
                if (act === 'edit') {
                    openQuickEditFromData(contextCardData);
                } else if (act === 'delete') {
                    triggerDeleteFromData(contextCardData);
                }
                return;
            }

            var card = e.target.closest('.jadwal-slot-card--clickable');
            if (!card) {
                return;
            }
            if (e.target.closest('.jadwal-slot-card__actions, .jadwal-slot-card__menu, .dropdown-menu, .jadwal-quick-edit, .jadwal-delete-one, a, button')) {
                return;
            }
            var data = cardDataFromEl(card);
            if (!data || !data.editId) {
                return;
            }
            if (DESKTOP_MQ.matches) {
                openQuickEditFromData(data);
            } else if (detailModal) {
                fillDetailModal(data);
                detailModal.show();
            }
        });

        document.addEventListener('contextmenu', function (e) {
            if (!DESKTOP_MQ.matches || !focusPage) {
                return;
            }
            var card = e.target.closest('.jadwal-slot-card--clickable');
            if (!card) {
                return;
            }
            e.preventDefault();
            var data = cardDataFromEl(card);
            if (data) {
                showContextMenu(e.clientX, e.clientY, data);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideContextMenu();
            }
            var card = e.target.closest('.jadwal-slot-card--clickable');
            if (!card || (e.key !== 'Enter' && e.key !== ' ')) {
                return;
            }
            if (e.target.closest('a, button, input, select, textarea')) {
                return;
            }
            e.preventDefault();
            var data = cardDataFromEl(card);
            if (!data) {
                return;
            }
            if (DESKTOP_MQ.matches) {
                openQuickEditFromData(data);
            } else if (detailModal) {
                fillDetailModal(data);
                detailModal.show();
            }
        });
    }

    var deleteForm = document.getElementById('form-jadwal-delete-one');
    if (deleteForm) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.jadwal-delete-one');
            if (!btn) {
                return;
            }
            var msg = btn.getAttribute('data-confirm') || 'Hapus slot jadwal ini? Presensi terkait ikut dihapus.';
            if (!window.confirm(msg)) {
                return;
            }
            var raw = btn.getAttribute('data-delete-ids') || '';
            var ids = raw.split(',').map(function (s) {
                return parseInt(s.trim(), 10);
            }).filter(function (n) {
                return n > 0;
            });
            deleteForm.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
                el.remove();
            });
            ids.forEach(function (id) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'ids[]';
                inp.value = String(id);
                deleteForm.appendChild(inp);
            });
            deleteForm.submit();
        });
    }

    var modalEl = document.getElementById('jadwalQuickEditModal');
    var form = document.getElementById('jadwalQuickEditForm');
    if (modalEl && form && typeof bootstrap !== 'undefined') {
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        function setTingkatanChecked(list) {
            var names = Array.isArray(list) ? list : [];
            form.querySelectorAll('input[name="tingkatan[]"]').forEach(function (cb) {
                cb.checked = names.indexOf(cb.value) !== -1;
            });
        }

        function setHariChecked(list) {
            var ids = Array.isArray(list) ? list.map(Number) : [];
            form.querySelectorAll('.jq-hari-check').forEach(function (cb) {
                cb.checked = ids.indexOf(Number(cb.value)) !== -1;
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.jadwal-quick-edit');
            if (!btn) {
                return;
            }
            var id = btn.getAttribute('data-edit-id') || '';
            if (!id) {
                return;
            }
            var base = form.getAttribute('data-edit-base') || ((window.PONDOK_APP_BASE || '') + '/jadwal/edit.php');
            form.action = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);

            var keg = document.getElementById('jq-kegiatan');
            var kat = (btn.getAttribute('data-kategori') || '').toLowerCase();
            if (keg) {
                keg.value = btn.getAttribute('data-kegiatan-id') || '';
                keg.disabled = kat === 'jamaah';
            }
            var pb = document.getElementById('jq-pembimbing');
            var pbNote = document.getElementById('jq-pembimbing-jamaah-note');
            if (pb) {
                if (kat === 'jamaah') {
                    pb.value = '0';
                    pb.disabled = true;
                } else {
                    pb.disabled = false;
                    pb.value = btn.getAttribute('data-pembimbing-id') || '0';
                }
            }
            if (pbNote) {
                pbNote.classList.toggle('d-none', kat !== 'jamaah');
            }
            var jm = document.getElementById('jq-jam-mulai');
            if (jm) {
                jm.value = btn.getAttribute('data-jam-mulai') || '';
            }
            var js = document.getElementById('jq-jam-selesai');
            if (js) {
                js.value = btn.getAttribute('data-jam-selesai') || '';
            }
            var tp = document.getElementById('jq-tempat');
            if (tp) {
                tp.value = btn.getAttribute('data-tempat') || '';
            }

            try {
                setTingkatanChecked(JSON.parse(btn.getAttribute('data-tingkatan') || '[]'));
            } catch (err) {
                setTingkatanChecked([]);
            }
            try {
                setHariChecked(JSON.parse(btn.getAttribute('data-hari') || '[]'));
            } catch (err2) {
                setHariChecked([]);
            }

            modal.show();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebarCollapsed();
        initHariTabs();
        initCardDropdownMenus();
        initCardInteractions();
    });
})();
