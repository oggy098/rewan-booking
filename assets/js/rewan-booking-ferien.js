(function () {
    'use strict';

    if (typeof RewanFerien === 'undefined') {
        return;
    }

    var months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    var state = {
        employeeId: String(RewanFerien.employeeId || ''),
        cursor: new Date(),
        anchor: '',
        end: '',
        existing: null,
        showAll: false
    };
    state.cursor.setDate(1);

    var grid = document.getElementById('rb-ferien-grid');
    var monthLabel = document.getElementById('rb-ferien-month');
    var hint = document.getElementById('rb-ferien-hint');
    var sheet = document.getElementById('rb-ferien-sheet');
    var sheetTitle = document.getElementById('rb-ferien-sheet-title');
    var titleInput = document.getElementById('rb-ferien-title');
    var noteWrap = document.getElementById('rb-ferien-note-wrap');
    var saveBtn = document.getElementById('rb-ferien-save');
    var deleteLink = document.getElementById('rb-ferien-delete');
    var countEl = document.getElementById('rb-ferien-count');

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function iso(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function parseIso(value) {
        var parts = String(value).split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }

    function pretty(value) {
        var date = parseIso(value);
        return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear();
    }

    function ranges() {
        var all = RewanFerien.ranges || {};
        return all[state.employeeId] || all[Number(state.employeeId)] || [];
    }

    function covering(day) {
        var list = ranges();
        for (var i = 0; i < list.length; i++) {
            if (day >= list[i].start && day <= list[i].end) {
                return list[i];
            }
        }
        return null;
    }

    function ordered(a, b) {
        return a <= b ? [a, b] : [b, a];
    }

    function setHint(text) {
        if (hint) {
            hint.textContent = text;
        }
    }

    function closeSheet() {
        if (sheet) {
            sheet.hidden = true;
        }
        state.anchor = '';
        state.end = '';
        state.existing = null;
        render();
        setHint('Ersten Tag antippen.');
    }

    function openSheet(existing) {
        if (!sheet) {
            return;
        }
        state.existing = existing || null;
        var from = existing ? existing.start : ordered(state.anchor, state.end || state.anchor)[0];
        var to = existing ? existing.end : ordered(state.anchor, state.end || state.anchor)[1];
        var label = from === to ? pretty(from) : pretty(from) + ' – ' + pretty(to);
        sheetTitle.textContent = existing && existing.title ? label + ' · ' + existing.title : label;
        if (existing) {
            noteWrap.hidden = true;
            saveBtn.hidden = true;
            deleteLink.hidden = false;
            var card = document.querySelector('.rb-ferien-card[data-employee="' + state.employeeId + '"][data-start="' + existing.start + '"][data-end="' + existing.end + '"]');
            deleteLink.href = card ? card.querySelector('a').getAttribute('href') : '#';
        } else {
            noteWrap.hidden = false;
            saveBtn.hidden = false;
            deleteLink.hidden = true;
            titleInput.value = 'Ferien';
        }
        sheet.hidden = false;
    }

    function renderCards() {
        var visible = 0;
        document.querySelectorAll('.rb-ferien-card').forEach(function (card) {
            var show = state.showAll || card.getAttribute('data-employee') === state.employeeId;
            card.hidden = !show;
            if (show) {
                visible += 1;
            }
        });
        if (countEl) {
            countEl.textContent = String(visible);
        }
    }

    function render() {
        if (!grid || !monthLabel) {
            return;
        }
        var year = state.cursor.getFullYear();
        var month = state.cursor.getMonth();
        monthLabel.textContent = months[month] + ' ' + year;
        grid.innerHTML = '';
        var first = new Date(year, month, 1);
        var lead = (first.getDay() + 6) % 7;
        var days = new Date(year, month + 1, 0).getDate();
        var today = iso(new Date());
        var i;
        for (i = 0; i < lead; i++) {
            var blank = document.createElement('span');
            blank.className = 'rb-ferien-day is-empty';
            grid.appendChild(blank);
        }
        for (i = 1; i <= days; i++) {
            var date = new Date(year, month, i);
            var key = iso(date);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'rb-ferien-day';
            button.textContent = String(i);
            button.setAttribute('data-date', key);
            if (key === today) {
                button.classList.add('is-today');
            }
            if (covering(key)) {
                button.classList.add('is-marked');
            }
            if (state.anchor) {
                var pair = ordered(state.anchor, state.end || state.anchor);
                if (key >= pair[0] && key <= pair[1]) {
                    button.classList.add('is-range');
                }
                if (key === state.anchor) {
                    button.classList.add('is-anchor');
                }
            }
            grid.appendChild(button);
        }
        renderCards();
    }

    function saveRange() {
        var pair = ordered(state.anchor, state.end || state.anchor);
        var body = new URLSearchParams();
        body.set('action', 'rewan_booking_save_ferien_range');
        body.set('nonce', RewanFerien.nonce);
        body.set('employee_id', state.employeeId);
        body.set('start', pair[0]);
        body.set('end', pair[1]);
        body.set('title', titleInput ? titleInput.value : 'Ferien');
        saveBtn.disabled = true;
        fetch(RewanFerien.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || !payload.success) {
                saveBtn.disabled = false;
                setHint(payload && payload.data && payload.data.message ? payload.data.message : 'Konnte nicht gespeichert werden.');
                return;
            }
            window.location = RewanFerien.reload + '&employee_id=' + encodeURIComponent(state.employeeId) + '&message=absence_saved';
        }).catch(function () {
            saveBtn.disabled = false;
            setHint('Konnte nicht gespeichert werden.');
        });
    }

    if (grid) {
        grid.addEventListener('click', function (event) {
            var button = event.target.closest('.rb-ferien-day');
            if (!button || button.classList.contains('is-empty')) {
                return;
            }
            var day = button.getAttribute('data-date');
            if (!state.anchor) {
                var hit = covering(day);
                if (hit) {
                    openSheet(hit);
                    render();
                    return;
                }
                state.anchor = day;
                state.end = '';
                setHint('Jetzt den letzten Tag antippen. Derselbe Tag nochmal = nur dieser eine Tag.');
                render();
                return;
            }
            if (day === state.anchor && !state.end) {
                state.end = day;
                openSheet(null);
                render();
                return;
            }
            state.end = day;
            openSheet(null);
            render();
        });
    }

    document.querySelectorAll('.rb-ferien-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            state.employeeId = chip.getAttribute('data-employee-id');
            state.anchor = '';
            state.end = '';
            if (sheet) {
                sheet.hidden = true;
            }
            document.querySelectorAll('.rb-ferien-chip').forEach(function (el) {
                el.classList.toggle('is-selected', el === chip);
            });
            setHint('Ersten Tag antippen.');
            render();
        });
    });

    var prev = document.getElementById('rb-ferien-prev');
    var next = document.getElementById('rb-ferien-next');
    if (prev) {
        prev.addEventListener('click', function () {
            state.cursor.setMonth(state.cursor.getMonth() - 1);
            render();
        });
    }
    if (next) {
        next.addEventListener('click', function () {
            state.cursor.setMonth(state.cursor.getMonth() + 1);
            render();
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', saveRange);
    }
    var cancel = document.getElementById('rb-ferien-cancel');
    if (cancel) {
        cancel.addEventListener('click', closeSheet);
    }
    var allBtn = document.getElementById('rb-ferien-all');
    if (allBtn) {
        allBtn.addEventListener('click', function () {
            state.showAll = !state.showAll;
            allBtn.classList.toggle('is-on', state.showAll);
            allBtn.textContent = state.showAll ? 'Nur diese Person' : 'Alle anzeigen';
            renderCards();
        });
    }
    document.querySelectorAll('.rb-ferien-card-del, #rb-ferien-delete').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!window.confirm('Diese Ferien wirklich löschen?')) {
                event.preventDefault();
            }
        });
    });

    render();
})();
