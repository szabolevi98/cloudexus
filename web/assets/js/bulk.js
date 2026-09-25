/*
 * Tömeges műveletek egy listán: a sorok elején pipák (form="bulk-form"), a
 * fejlécben egy "mindet" pipa, alul egy sáv, ami csak akkor látszik, ha van
 * kipipált sor, és megmondja, hány. A sáv űrlapja küldi a kipipált sorokat
 * és a választott műveletet; a szerver ellenőrzi a jogot és a műveletet.
 */
(function () {
    'use strict';

    var form = document.getElementById('bulk-form');
    if (!form) {
        return;
    }

    var all = document.querySelector('[data-bulk-all]');
    var bar = document.querySelector('[data-bulk-bar]');
    var count = document.querySelector('[data-bulk-count]');
    var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('input[form="bulk-form"][name="ids[]"]')); };

    function update() {
        var ticked = boxes().filter(function (box) { return box.checked; }).length;
        bar.hidden = ticked === 0;
        count.textContent = (count.dataset.template || '{count}').replace('{count}', String(ticked));
        if (all) {
            all.checked = ticked > 0 && ticked === boxes().length;
            all.indeterminate = ticked > 0 && ticked < boxes().length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes().forEach(function (box) { box.checked = all.checked; });
            update();
        });
    }
    boxes().forEach(function (box) { box.addEventListener('change', update); });

    // A kategória vagy csoport választó csak a hozzá tartozó műveletnél látszik.
    var action = form.querySelector('[name="action"]');
    function showTarget() {
        form.querySelectorAll('[data-bulk-for]').forEach(function (el) {
            el.hidden = el.dataset.bulkFor !== action.value;
        });
    }
    if (action) {
        action.addEventListener('change', showTarget);
        showTarget();
    }

    update();
})();
