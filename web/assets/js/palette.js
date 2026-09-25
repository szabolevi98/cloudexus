/*
 * Ugrás bárhová Ctrl+K-val (vagy Cmd+K-val): egy doboz, ami néhány betűből
 * megtalál egy terméket, partnert, számlát, rendelést vagy oldalt, üresen
 * pedig a legutóbb megnyitottakat mutatja. A találatokat a szerver adja
 * (/palette?q=), a jogosultság szerint szűrve.
 *
 * Nyilak mozognak, Enter megnyit, Ctrl+Enter új lapon, Escape bezár — a
 * <dialog> elem gondoskodik a fókuszról. Külön fájl, nem beágyazott script,
 * hogy egy szigorú Content-Security-Policy mellett is fusson.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('cx-palette');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var input = dialog.querySelector('[data-palette-input]');
    var list = dialog.querySelector('[data-palette-list]');
    var items = [];
    var active = 0;
    var asked = 0;
    var timer = null;

    function open() {
        input.value = '';
        dialog.showModal();
        input.focus();
        search();
    }

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
            event.preventDefault();
            if (dialog.open) {
                dialog.close();
            } else {
                open();
            }
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-palette-open]')) {
            event.preventDefault();
            open();
        }
    });

    // A doboz mellé kattintás (a háttérre) bezárja.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 120);
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (items.length) {
                active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                draw();
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(items[active], event.ctrlKey || event.metaKey);
        }
    });

    function search() {
        var mine = ++asked;

        fetch(dialog.dataset.url + '?q=' + encodeURIComponent(input.value.trim()), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : {items: []}; })
            .catch(function () { return {items: []}; })
            .then(function (result) {
                if (mine !== asked) {
                    return;
                }
                items = result.items || [];
                active = 0;
                draw();
            });
    }

    function draw() {
        list.innerHTML = '';
        var group = null;

        if (items.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'cx-palette__empty';
            empty.textContent = dialog.dataset.nothing;
            list.appendChild(empty);
            return;
        }

        items.forEach(function (item, index) {
            if (item.group !== group) {
                group = item.group;
                var heading = document.createElement('li');
                heading.className = 'cx-palette__group';
                heading.setAttribute('role', 'presentation');
                heading.textContent = group;
                list.appendChild(heading);
            }

            var li = document.createElement('li');
            li.className = 'cx-palette__item' + (index === active ? ' is-active' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === active ? 'true' : 'false');

            var label = document.createElement('span');
            label.className = 'cx-palette__label';
            label.textContent = item.label;
            li.appendChild(label);
            if (item.hint) {
                var hint = document.createElement('span');
                hint.className = 'cx-palette__hint';
                hint.textContent = item.hint;
                li.appendChild(hint);
            }

            li.addEventListener('mousemove', function () {
                if (active !== index) {
                    active = index;
                    draw();
                }
            });
            li.addEventListener('click', function (event) {
                go(item, event.ctrlKey || event.metaKey);
            });
            list.appendChild(li);
        });

        var current = list.querySelector('.is-active');
        if (current && current.scrollIntoView) {
            current.scrollIntoView({block: 'nearest'});
        }
    }

    function go(item, newTab) {
        if (!item) {
            return;
        }
        if (newTab) {
            window.open(item.url, '_blank', 'noopener');
        } else {
            window.location.href = item.url;
        }
    }
})();
