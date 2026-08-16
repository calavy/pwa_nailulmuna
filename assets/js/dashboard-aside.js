/**
 * Dashboard sidebar — pencarian cepat modul.
 */
(function () {
    'use strict';

    var searchWrap = document.getElementById('dash-menu-search-wrap');
    var searchInput = document.getElementById('dash-menu-search-input');
    var searchResults = document.getElementById('dash-menu-search-results');
    var searchItems = window.DASH_MENU_SEARCH_ITEMS;

    if (!searchWrap || !searchInput || !searchResults || !Array.isArray(searchItems) || searchItems.length === 0) {
        return;
    }

    var activeIdx = -1;
    var debounceTimer = null;

    function norm(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
    }

    function closeResults() {
        searchResults.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeIdx = -1;
    }

    function openResults() {
        searchResults.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function renderResults(query) {
        var q = norm(query.trim());
        var matches = q === ''
            ? searchItems.slice(0, 12)
            : searchItems.filter(function (item) {
                return norm(item.label).includes(q) || norm(item.path).includes(q);
            }).slice(0, 16);

        searchResults.innerHTML = '';
        if (matches.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'dash-aside-search__empty';
            empty.textContent = 'Tidak ada modul yang cocok.';
            searchResults.appendChild(empty);
            openResults();
            return;
        }

        matches.forEach(function (item, idx) {
            var row = document.createElement('a');
            row.className = 'dash-aside-search__item';
            row.href = item.path;
            row.setAttribute('role', 'option');
            row.id = 'dash-search-opt-' + idx;
            row.dataset.idx = String(idx);
            var ico = document.createElement('i');
            ico.className = item.icon;
            ico.setAttribute('aria-hidden', 'true');
            var lbl = document.createElement('span');
            lbl.textContent = item.label;
            row.append(ico, lbl);
            searchResults.appendChild(row);
        });
        openResults();
        setActive(0);
    }

    function setActive(idx) {
        var rows = searchResults.querySelectorAll('.dash-aside-search__item');
        rows.forEach(function (r) { r.classList.remove('is-active'); });
        activeIdx = idx;
        if (idx >= 0 && rows[idx]) {
            rows[idx].classList.add('is-active');
            searchInput.setAttribute('aria-activedescendant', rows[idx].id || '');
            rows[idx].scrollIntoView({ block: 'nearest' });
        }
    }

    function scheduleRender() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(function () {
            renderResults(searchInput.value);
        }, 80);
    }

    searchInput.addEventListener('focus', function () {
        renderResults(searchInput.value);
    });
    searchInput.addEventListener('input', scheduleRender);
    searchInput.addEventListener('keydown', function (e) {
        var rows = searchResults.querySelectorAll('.dash-aside-search__item');
        if (e.key === 'Escape') {
            closeResults();
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (searchResults.hidden) {
                renderResults(searchInput.value);
                return;
            }
            setActive(Math.min(activeIdx + 1, rows.length - 1));
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(activeIdx - 1, 0));
            return;
        }
        if (e.key === 'Enter' && activeIdx >= 0 && rows[activeIdx]) {
            e.preventDefault();
            window.location.href = rows[activeIdx].href;
        }
    });
    document.addEventListener('click', function (e) {
        if (!searchWrap.contains(e.target)) {
            closeResults();
        }
    });
})();
