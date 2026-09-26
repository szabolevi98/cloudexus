/*
 * Az egyes oldalak kis scriptjei, egy fájlban. Mindegyik csak akkor fut, ha
 * az oldalon ott a saját eleme; ami a szervertől kell nekik, azt data-*
 * attribútumból vagy egy nem futtatható JSON-blokkból olvassák (a
 * Content-Security-Policy script-src 'self' nem enged inline scriptet).
 * A layout defer-rel tölti, a jQuery, a Select2 és az app.js után.
 */
(function () {
    'use strict';

    var $ = function (selector) { return document.querySelector(selector); };
    var json = function (id) {
        var el = document.getElementById(id);
        if (!el) return null;
        try {
            return JSON.parse(el.textContent || 'null');
        } catch (e) {
            return null;
        }
    };
    var base = document.body.dataset.base || '';

    // API dokumentáció: a web/API.md, markdownból.
    (function () {
        var target = $('#markdown-content');
        if (!target || !window.marked) return;
        fetch(target.dataset.src)
            .then(function (r) { return r.text(); })
            .then(function (md) { target.innerHTML = window.marked.parse(md); })
            .catch(function () {
                var p = document.createElement('p');
                p.className = 'text-danger';
                p.textContent = target.dataset.error;
                target.replaceChildren(p);
            });
    })();

    // Pénztárbizonylat: bevételnél vevői, kiadásnál bejövő számla választható.
    (function () {
        var type = $('#type');
        var invoice = $('#invoice-field');
        var incoming = $('#incoming-invoice-field');
        if (!type || !invoice || !incoming) return;
        var sync = function () {
            var income = type.value === 'bevetel';
            invoice.classList.toggle('d-none', !income);
            incoming.classList.toggle('d-none', income);
        };
        type.addEventListener('change', sync);
        sync();
    })();

    // Raktárkészlet: a tárhelyek a választott raktáréi.
    (function () {
        var wh = $('#ov-warehouse');
        var loc = $('#ov-location');
        if (!wh || !loc) return;
        var filter = function () {
            Array.prototype.forEach.call(loc.options, function (o) {
                if (!o.value) return;
                var show = !wh.value || o.dataset.warehouse === wh.value;
                o.hidden = !show;
                if (!show && o.selected) loc.value = '';
            });
        };
        wh.addEventListener('change', filter);
        filter();
    })();

    // Bevét, kiadás, átadás: a tárhely-választó a raktár tárhelyeit kínálja
    // (<select data-locations-of="raktár-mező-id" data-empty="…">, a tárhelyek a #locations-data blokkban).
    (function () {
        var locations = json('locations-data');
        if (!locations) return;
        document.querySelectorAll('select[data-locations-of]').forEach(function (loc) {
            var wh = document.getElementById(loc.dataset.locationsOf);
            if (!wh) return;
            var fill = function () {
                var w = parseInt(wh.value, 10);
                loc.innerHTML = '';
                var none = document.createElement('option');
                none.value = '';
                none.textContent = loc.dataset.empty || '';
                loc.appendChild(none);
                locations.filter(function (l) { return l.warehouse_id == w; }).forEach(function (l) {
                    var o = document.createElement('option');
                    o.value = l.id;
                    o.textContent = l.code;
                    loc.appendChild(o);
                });
            };
            wh.addEventListener('change', fill);
            fill();
        });
    })();

    // Leltár: az eltérés, ahogy a megszámolt mennyiséget gépelik.
    (function () {
        var inputs = document.querySelectorAll('.cx-counted');
        if (!inputs.length) return;
        var format = new Intl.NumberFormat(document.documentElement.lang === 'hu' ? 'hu-HU' : 'en-US');
        inputs.forEach(function (input) {
            input.addEventListener('input', function () {
                var row = input.closest('tr');
                var book = parseFloat(row.querySelector('.cx-book').dataset.book) || 0;
                var cell = row.querySelector('.cx-diff');
                if (input.value === '') {
                    cell.textContent = '—';
                    cell.className = 'text-end cx-diff text-muted';
                    return;
                }
                var diff = (parseFloat(input.value) || 0) - book;
                cell.textContent = (diff > 0 ? '+' : '') + format.format(diff);
                cell.className = 'text-end cx-diff fw-medium ' + (diff === 0 ? 'text-muted' : (diff > 0 ? 'text-success' : 'text-danger'));
            });
        });
    })();

    // Árszabály: a hatókörhöz és a hatáshoz tartozó mezők.
    (function () {
        if (!$('.cx-rule-scope')) return;
        var sync = function (radioClass, fieldClass, attr) {
            var checked = document.querySelector('.' + radioClass + ':checked');
            var value = checked ? checked.value : null;
            document.querySelectorAll('.' + fieldClass).forEach(function (el) {
                var on = el.dataset[attr] === value;
                el.classList.toggle('d-none', !on);
                el.querySelectorAll('input, select').forEach(function (input) { input.disabled = !on; });
            });
        };
        var scope = function () { sync('cx-rule-scope', 'cx-rule-scope-field', 'scope'); };
        var effect = function () { sync('cx-rule-effect', 'cx-rule-effect-field', 'effect'); };
        document.querySelectorAll('.cx-rule-scope').forEach(function (r) { r.addEventListener('change', scope); });
        document.querySelectorAll('.cx-rule-effect').forEach(function (r) { r.addEventListener('change', effect); });
        scope();
        effect();
    })();

    // Szövegszerkesztő a termékek és kategóriák leírásához.
    (function () {
        if (!window.tinymce || !$('textarea.tinymce-editor')) return;
        var dark = window.cxDark ? window.cxDark() : false;
        window.tinymce.init({
            selector: 'textarea.tinymce-editor',
            base_url: base + '/assets/vendor/tinymce',
            suffix: '.min',
            height: 320,
            menubar: false,
            branding: false,
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table paste help wordcount',
            toolbar: 'undo redo | styleselect | bold italic | bullist numlist outdent indent | link image table | removeformat code fullscreen',
            skin: dark ? 'oxide-dark' : 'oxide',
            content_style: 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:14px}'
                + (dark ? 'body{background:#12162a;color:#e3e6f1}a{color:#9aa3f6}' : '')
        });
    })();

    // Termék: paraméter-sorok hozzáadása és törlése.
    (function () {
        var add = $('#attr-add');
        var rows = $('#attr-rows');
        var tpl = $('#attr-template');
        if (!add || !rows || !tpl) return;
        add.addEventListener('click', function () {
            var row = tpl.content.firstElementChild.cloneNode(true);
            rows.appendChild(row);
            if (window.cxInitSelect2) window.cxInitSelect2(row);
        });
        rows.addEventListener('click', function (e) {
            if (e.target.closest('.attr-remove')) e.target.closest('.attr-row').remove();
        });
    })();

    // Vezérlőpult: az elmúlt napok rendelései, a téma színeivel, újrarajzolva, ha a téma vált.
    (function () {
        var canvas = $('#orders-chart');
        var daily = json('orders-chart-data');
        if (!canvas || !daily || !window.Chart) return;
        var css = function (name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); };
        var chart = null;
        var draw = function () {
            if (chart) chart.destroy();
            var grid = css('--cx-border');
            var muted = css('--cx-muted');
            chart = new window.Chart(canvas, {
                type: 'line',
                data: {
                    labels: daily.map(function (d) { return d.date.slice(5).replace('-', '.'); }),
                    datasets: [{
                        label: canvas.dataset.label,
                        data: daily.map(function (d) { return d.total_value; }),
                        borderColor: css('--cx-primary'),
                        backgroundColor: window.cxDark && window.cxDark() ? 'rgba(117, 128, 240, 0.16)' : 'rgba(79, 91, 213, 0.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {legend: {display: false}},
                    scales: {
                        x: {grid: {color: grid}, ticks: {color: muted}},
                        y: {beginAtZero: true, grid: {color: grid}, ticks: {color: muted, callback: function (v) { return new Intl.NumberFormat('hu-HU').format(v); }}}
                    }
                }
            });
        };
        draw();
        document.addEventListener('cx:theme', draw);
    })();

    // Partner adatlap: az idővonal szűrése (minden, bizonylatok, pénz, tevékenység).
    (function () {
        var filter = $('#timeline-filter');
        if (!filter) return;
        filter.addEventListener('click', function (e) {
            var button = e.target.closest('[data-timeline-filter]');
            if (!button) return;
            var group = button.dataset.timelineFilter;
            filter.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b === button); });
            document.querySelectorAll('#partner-timeline [data-timeline-group]').forEach(function (item) {
                item.classList.toggle('d-none', group !== 'all' && item.dataset.timelineGroup !== group); // a hidden attribútum a d-flex !important mellett nem rejt
            });
        });
    })();

    // A választott partner hitelkerete, tartozása és fizetési határideje (common/partner-credit.twig).
    (function () {
        var box = $('.cx-partner-credit');
        var partner = $('#partner_id');
        if (!box || !partner || !window.jQuery) return;
        var money = function (n) { return new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' ' + box.dataset.currency; };
        var line = function (text, cls, icon) {
            var div = document.createElement('div');
            div.className = cls;
            var i = document.createElement('i');
            i.className = 'bi ' + icon + ' me-1';
            div.appendChild(i);
            div.appendChild(document.createTextNode(text));
            return div;
        };
        var show = function () {
            var id = parseInt(partner.value, 10);
            box.replaceChildren();
            box.hidden = true;
            if (!id) return;
            fetch(box.dataset.url + id + '/credit', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (c) {
                    if (!c) return;
                    if (c.overdue > 0) {
                        box.appendChild(line(box.dataset.tOverdue.replace('{amount}', money(c.overdue)), 'text-danger fw-medium', 'bi-exclamation-triangle'));
                    }
                    if (c.credit_limit !== null) {
                        var over = c.credit_left <= 0;
                        box.appendChild(line(
                            box.dataset.tLimit.replace('{limit}', money(c.credit_limit)).replace('{open}', money(c.open_balance)).replace('{left}', money(Math.max(0, c.credit_left))),
                            over ? 'text-danger' : 'text-muted', 'bi-wallet2'
                        ));
                        if (over) box.appendChild(line(box.dataset.tOver, 'text-danger fw-medium', 'bi-x-octagon'));
                    }
                    if (box.dataset.dueTarget) {
                        var due = document.querySelector(box.dataset.dueTarget);
                        var from = document.querySelector(box.dataset.dateSource);
                        var start = from && from.value ? new Date(from.value + 'T00:00:00') : new Date();
                        start.setDate(start.getDate() + c.payment_terms_days);
                        var pad = function (n) { return String(n).padStart(2, '0'); };
                        if (due) due.value = start.getFullYear() + '-' + pad(start.getMonth() + 1) + '-' + pad(start.getDate());
                        box.appendChild(line(box.dataset.tTerms.replace('{days}', c.payment_terms_days), 'text-muted', 'bi-calendar-check'));
                    }
                    box.hidden = box.children.length === 0;
                });
        };
        window.jQuery(partner).on('change', show);
        show();
    })();

    // Vevői rendelés: a szállítási és számlázási cím a választott partneréi.
    (function () {
        var addresses = json('partner-addresses');
        var partner = $('#partner_id');
        var shipping = $('#shipping_address_id');
        var billing = $('#billing_address_id');
        if (!addresses || !partner || !shipping || !billing || !window.jQuery) return;
        var label = function (a) {
            var text = a.postal_code + ' ' + a.city + ', ' + a.street;
            if (a.country && a.country !== 'Magyarország') text = a.country + ', ' + text;
            if (a.note) text += ' (' + a.note + ')';
            return text;
        };
        var fill = function () {
            var id = parseInt(partner.value, 10);
            var matching = addresses.filter(function (a) { return a.partner_id == id; });
            [shipping, billing].forEach(function (select) {
                select.innerHTML = '';
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = matching.length ? shipping.dataset.emptySome : shipping.dataset.emptyNone;
                select.appendChild(empty);
                matching.forEach(function (a) {
                    var o = document.createElement('option');
                    o.value = a.id;
                    o.textContent = label(a);
                    select.appendChild(o);
                });
            });
        };
        window.jQuery(partner).on('change', fill);
        fill();
    })();

    // Értékesítési tábla: az üzletek húzása szakaszok között és oszlopon belül.
    // Az elvesztett oszlopba ejtve előbb az okot kéri; mégsével visszaugrik.
    (function () {
        var board = document.getElementById('deal-board');
        if (!board || board.dataset.draggable !== '1') return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        var modalEl = document.getElementById('deal-lost-modal');
        var modal = modalEl && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        var drag = null;
        var pending = null;

        var cardBelow = function (list, y) {
            var cards = Array.prototype.filter.call(list.querySelectorAll('.cx-deal'), function (c) { return !drag || c !== drag.card; });
            for (var i = 0; i < cards.length; i++) {
                var box = cards[i].getBoundingClientRect();
                if (y < box.top + box.height / 2) return cards[i];
            }
            return null;
        };
        var putBack = function (move) {
            move.from.insertBefore(move.card, move.next && move.next.parentNode === move.from ? move.next : null);
        };
        var send = function (move, reason) {
            var column = move.card.closest('[data-stage]');
            var body = new FormData();
            body.append('_token', meta ? meta.content : '');
            body.append('stage', column.dataset.stage);
            body.append('q', board.dataset.q || '');
            body.append('owner', board.dataset.owner || '');
            column.querySelectorAll('.cx-deal').forEach(function (c) { body.append('order[]', c.dataset.id); });
            if (reason) body.append('reason', reason);
            fetch(board.dataset.url + move.card.dataset.id + '/move', {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) { return r.json().then(function (data) { if (!r.ok || !data.ok) throw new Error('move'); return data; }); })
                .then(function (data) {
                    Object.keys(data.board).forEach(function (stage) {
                        var col = board.querySelector('[data-stage="' + stage + '"]');
                        if (!col) return;
                        ['count', 'amount', 'weighted'].forEach(function (key) {
                            var el = col.querySelector('[data-col="' + key + '"]');
                            if (el) el.textContent = data.board[stage][key];
                        });
                    });
                    var totals = document.getElementById('deal-totals');
                    if (totals) totals.textContent = data.summary;
                    var chance = move.card.querySelector('[data-chance]');
                    if (chance) {
                        chance.textContent = data.deal.chance + '%';
                        chance.hidden = !data.deal.is_open;
                    }
                    if (!data.deal.is_open) move.card.classList.remove('is-late');
                })
                .catch(function () {
                    window.alert(board.dataset.tFailed);
                    window.location.reload();
                });
        };

        board.addEventListener('dragstart', function (e) {
            var card = e.target.closest ? e.target.closest('.cx-deal') : null;
            if (!card) return;
            drag = {card: card, from: card.parentNode, next: card.nextElementSibling, stage: card.closest('[data-stage]').dataset.stage};
            card.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.id);
        });
        board.addEventListener('dragend', function () {
            // Táblán kívül elengedve nincs drop: vissza a helyére.
            if (drag) {
                drag.card.classList.remove('is-dragging');
                putBack(drag);
                drag = null;
            }
            board.querySelectorAll('.is-over').forEach(function (l) { l.classList.remove('is-over'); });
        });
        board.addEventListener('dragover', function (e) {
            if (!drag) return;
            var column = e.target.closest ? e.target.closest('[data-stage]') : null;
            if (!column) return;
            e.preventDefault();
            // Keskeny képernyőn a tábla görög, ha a széléhez viszik a kártyát.
            var edge = board.getBoundingClientRect();
            if (e.clientX > edge.right - 60) board.scrollLeft += 18;
            else if (e.clientX < edge.left + 60) board.scrollLeft -= 18;
            var list = column.querySelector('[data-list]');
            board.querySelectorAll('.is-over').forEach(function (l) { if (l !== list) l.classList.remove('is-over'); });
            list.classList.add('is-over');
            var below = cardBelow(list, e.clientY);
            if (below !== drag.card) list.insertBefore(drag.card, below);
        });
        board.addEventListener('drop', function (e) {
            if (!drag) return;
            e.preventDefault();
            var move = drag;
            drag = null;
            move.card.classList.remove('is-dragging');
            board.querySelectorAll('.is-over').forEach(function (l) { l.classList.remove('is-over'); });
            var stage = move.card.closest('[data-stage]').dataset.stage;
            if (stage === 'lost' && move.stage !== 'lost') {
                if (!modal) { putBack(move); return; }
                pending = move;
                document.getElementById('deal-lost-reason').value = '';
                modal.show();
                return;
            }
            send(move, null);
        });

        if (modalEl) {
            modalEl.addEventListener('shown.bs.modal', function () { document.getElementById('deal-lost-reason').focus(); });
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (pending) { putBack(pending); pending = null; }
            });
            document.getElementById('deal-lost-form').addEventListener('submit', function (e) {
                e.preventDefault();
                var reason = document.getElementById('deal-lost-reason').value.trim();
                if (!reason || !pending) return;
                var move = pending;
                pending = null;
                modal.hide();
                send(move, reason);
            });
        }
    })();

    // CRM riport: az előrejelzés hónaponként — a teljes és a súlyozott érték, a téma színeivel.
    (function () {
        var canvas = $('#forecast-chart');
        var rows = json('forecast-chart-data');
        if (!canvas || !rows || !window.Chart) return;
        var css = function (name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); };
        var chart = null;
        var draw = function () {
            if (chart) chart.destroy();
            var muted = css('--cx-muted');
            var grid = css('--cx-border');
            chart = new window.Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map(function (r) { return r.label; }),
                    datasets: [
                        {label: canvas.dataset.amount, data: rows.map(function (r) { return r.amount; }), backgroundColor: css('--cx-primary-soft'), borderColor: css('--cx-primary'), borderWidth: 1},
                        {label: canvas.dataset.weighted, data: rows.map(function (r) { return r.weighted; }), backgroundColor: css('--cx-primary')}
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {legend: {labels: {color: muted}}},
                    scales: {
                        x: {grid: {display: false}, ticks: {color: muted}},
                        y: {beginAtZero: true, grid: {color: grid}, ticks: {color: muted, callback: function (v) { return new Intl.NumberFormat('hu-HU', {notation: 'compact'}).format(v); }}}
                    }
                }
            });
        };
        draw();
        document.addEventListener('cx:theme', draw);
    })();
})();
