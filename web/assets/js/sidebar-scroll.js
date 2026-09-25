/*
 * A bal menü görgetése lapváltáskor.
 *
 * Minden kattintás teljes újratöltés, ezért a menü a tetejére ugrott: aki
 * lent kattintott (Beállítások, API), annak az aktív pont a menü alján,
 * látótéren kívül maradt.
 *
 * Két lépés, ebben a sorrendben:
 *   1. Visszaállítás: ha a menüből kattintottunk, a menü ugyanott áll, ahol a
 *      kattintáskor. Így semmi nem ugrik: amit előtte láttunk, azt látjuk utána.
 *   2. Odagörgetés: ha az aktív pont így sem látszik (egy link a lapon, új fül),
 *      középre görgetjük. Ha látszik, nem mozdul semmi.
 *
 * A szkript közvetlenül a menü után, szinkron fut: a lap még nincs kirajzolva,
 * így a görgetés nem villan. A sessionStorage csak kényelem; ha a böngésző
 * tiltja, a 2. lépés akkor is működik.
 */
(function () {
    'use strict';

    var KEY = 'cx-sidebar-scroll';
    var sidebar = document.querySelector('.cx-sidebar');
    if (!sidebar) {
        return;
    }

    try {
        var saved = window.sessionStorage.getItem(KEY);
        if (saved !== null) {
            sidebar.scrollTop = parseInt(saved, 10) || 0;
            window.sessionStorage.removeItem(KEY);
        }
    } catch (e) {
        // tárolás nélkül csak a 2. lépés marad
    }

    var active = sidebar.querySelector('.cx-nav__link.is-active');
    if (active) {
        var box = sidebar.getBoundingClientRect();
        var item = active.getBoundingClientRect();
        if (box.height > 0 && (item.top < box.top || item.bottom > box.bottom)) {
            sidebar.scrollTop += item.top - box.top - (box.height - item.height) / 2;
        }
    }

    // Csak a menüből, ebben a fülben indított lapváltás menti a helyet: egy
    // tartalmi link után az aktív pont a jó kiindulás, és egy új fülbe nyitott
    // link (Ctrl, középső gomb) sem hagyhat itt régi értéket a következő lapnak.
    sidebar.addEventListener('click', function (event) {
        var plain = event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
        if (plain && event.target.closest && event.target.closest('a[href]')) {
            try {
                window.sessionStorage.setItem(KEY, String(sidebar.scrollTop));
            } catch (e) {
                // nincs mit tenni
            }
        }
    });
})();
