/*
 * Ami minden oldalon kell: a Select2 mezők, a mobil menü, a CSRF token a POST
 * űrlapokon, és a data-* attribútumok, amik az inline eseménykezelők helyére
 * léptek (a Content-Security-Policy script-src 'self' nem enged inline
 * scriptet):
 *
 *   data-confirm="…"   űrlapon vagy gombon: megerősítés küldés előtt
 *   data-print         gomb: nyomtatás
 *   data-back          link: vissza az előző oldalra
 *   data-select        mező: kattintásra kijelöli a tartalmát
 *   data-copy="…"      gomb: a szöveget a vágólapra teszi
 *   data-autosubmit    mező: változáskor elküldi az űrlapját
 *   data-fill="#id" data-value="…"  gomb: a mezőbe írja az értéket (pl. egy kapcsolattartó e-mailjét)
 *
 * A szövegeket a layout adja, egy nem futtatható JSON-blokkban (#cx-i18n).
 */
(function () {
    'use strict';

    var i18n = {};
    var data = document.getElementById('cx-i18n');
    if (data) {
        try {
            i18n = JSON.parse(data.textContent || '{}');
        } catch (e) {
            i18n = {};
        }
    }
    var s2 = i18n.select2 || {};
    var text = function (key) { return s2[key] || ''; };

    // Select2 csak angol üzeneteket tartalmaz, ezért a sajátjainkat adjuk át neki.
    // A {n} helyőrzőket itt cseréljük, mert a darabszám csak futásidőben ismert.
    window.cxSelect2Lang = {
        errorLoading: function () { return text('error_loading'); },
        inputTooLong: function (args) {
            var n = args.input.length - args.maximum;
            return text(n === 1 ? 'input_too_long_one' : 'input_too_long_other').replace('{n}', n);
        },
        inputTooShort: function (args) { return text('input_too_short').replace('{n}', args.minimum - args.input.length); },
        loadingMore: function () { return text('loading_more'); },
        maximumSelected: function (args) {
            return text(args.maximum === 1 ? 'maximum_selected_one' : 'maximum_selected_other').replace('{n}', args.maximum);
        },
        noResults: function () { return text('no_results'); },
        searching: function () { return text('searching'); },
        removeAllItems: function () { return text('remove_all_items'); },
        removeItem: function () { return text('remove_item'); },
        search: function () { return text('search'); }
    };

    // Select2: helyi (kis lista) és AJAX-os (nagy adathalmaz, pl. 20.000 termék) mezők.
    // Újrahasználható, hogy a dinamikusan hozzáadott mezőkre is meghívható legyen.
    window.cxInitSelect2 = function (root) {
        if (!window.jQuery || !jQuery.fn.select2) return;
        var $root = root ? jQuery(root) : jQuery(document);

        $root.find('.cx-select2').addBack('.cx-select2').each(function () {
            if (this.classList.contains('select2-hidden-accessible')) return;
            jQuery(this).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: jQuery(this).data('placeholder') || i18n.choose_placeholder || '',
                language: window.cxSelect2Lang,
                allowClear: !jQuery(this).prop('multiple') && !this.hasAttribute('required'),
                tags: this.hasAttribute('data-tags'),
                tokenSeparators: this.hasAttribute('data-tags') ? [','] : []
            });
        });

        $root.find('.cx-ajax-select').addBack('.cx-ajax-select').each(function () {
            if (this.classList.contains('select2-hidden-accessible')) return;
            var $el = jQuery(this);
            $el.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $el.data('placeholder') || i18n.search_placeholder || '',
                language: window.cxSelect2Lang,
                allowClear: !$el.prop('multiple') && !this.hasAttribute('required'),
                minimumInputLength: 0,
                ajax: {
                    url: $el.data('url'),
                    dataType: 'json',
                    delay: 200,
                    data: function (params) { return {q: params.term || '', page: params.page || 1}; },
                    processResults: function (result) { return {results: result.results, pagination: {more: result.more}}; },
                    cache: true
                }
            });
        });
    };
    window.cxInitSelect2();

    // A mobil menü.
    (function () {
        var sidebar = document.querySelector('.cx-sidebar');
        var backdrop = document.querySelector('.cx-backdrop');
        var toggle = document.querySelector('.cx-topbar__toggle');
        if (!sidebar) return;

        function openSidebar() {
            sidebar.classList.add('is-open');
            if (backdrop) backdrop.classList.add('is-visible');
        }
        function closeSidebar() {
            sidebar.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-visible');
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
            });
        }
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        // Egy menüpontra kattintva mobilon záruljon be a menü.
        sidebar.querySelectorAll('.cx-nav__link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) closeSidebar();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    })();

    // A CSRF token minden POST űrlapra.
    (function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.content : '';
        if (!token) return;
        document.querySelectorAll('form').forEach(function (form) {
            // getAttribute: egy "method" nevű mező eltakarná a form.method-ot.
            if ((form.getAttribute('method') || '').toLowerCase() === 'post' && !form.querySelector('input[name="_token"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_token';
                input.value = token;
                form.appendChild(input);
            }
        });
    })();

    // A fejléc téma-kapcsolója: világos és sötét között vált. Hogy most melyik
    // látszik, azt "rendszer szerint" választásnál csak a böngésző tudja
    // (data-bs-theme, lásd theme-head.js): a kapcsoló ebből tudja, melyik ikont
    // mutassa és merre váltson; és az oldal azonnal átvált, mielőtt a választás
    // elmentődik és az oldal visszajön.
    (function () {
        var form = document.querySelector('[data-theme-switch]');
        if (!form) return;
        var root = document.documentElement;
        var shown = function () { return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; };
        var label = function () {
            var now = shown();
            var button = form.querySelector('button');
            form.setAttribute('data-shown', now);
            form.elements.to.value = now === 'dark' ? 'light' : 'dark';
            button.title = now === 'dark' ? form.dataset.toLight : form.dataset.toDark;
            button.setAttribute('aria-label', button.title);
        };
        label();
        document.addEventListener('cx:theme', label);
        form.addEventListener('submit', function () {
            // A küldendő cél már a mezőben van; a kapcsoló ne írja át.
            document.removeEventListener('cx:theme', label);
            root.setAttribute('data-bs-theme', form.elements.to.value);
            document.dispatchEvent(new CustomEvent('cx:theme'));
        });
    })();

    // Megerősítés: egy gombon kattintáskor (a gomb egy másik űrlapot is küldhet a form="…" attribútummal),
    // egy űrlapon küldéskor.
    document.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('button[data-confirm], a[data-confirm]') : null;
        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute && form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-print], [data-back], [data-copy], [data-select], [data-fill]') : null;
        if (!el) return;
        if (el.hasAttribute('data-fill')) {
            var target = document.querySelector(el.getAttribute('data-fill'));
            if (target) {
                target.value = el.getAttribute('data-value') || '';
                target.focus();
            }
        } else if (el.hasAttribute('data-print')) {
            e.preventDefault();
            window.print();
        } else if (el.hasAttribute('data-back')) {
            e.preventDefault();
            window.history.back();
        } else if (el.hasAttribute('data-copy')) {
            if (navigator.clipboard) navigator.clipboard.writeText(el.getAttribute('data-copy'));
        } else if (el.hasAttribute('data-select') && el.select) {
            el.select();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-autosubmit') && e.target.form) {
            e.target.form.submit();
        }
    });
})();
