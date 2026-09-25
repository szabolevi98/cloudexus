/*
 * A téma a <html> data-bs-theme attribútuma: a Bootstrap és a saját --cx-*
 * változók is ebből olvasnak. "Rendszer" választásnál a böngészőtől
 * kérdezzük meg, még az első kirajzolás előtt — ezért szinkron, a <head>-ben
 * töltődik —, hogy sötét gépen ne villanjon fel a világos oldal; és követjük,
 * ha a rendszer közben vált.
 */
(function () {
    var root = document.documentElement;
    window.cxDark = function () { return root.getAttribute('data-bs-theme') === 'dark'; };
    if (root.dataset.themeMode !== 'system' || !window.matchMedia) return;
    var query = window.matchMedia('(prefers-color-scheme: dark)');
    var apply = function () {
        root.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
        document.dispatchEvent(new CustomEvent('cx:theme'));
    };
    apply();
    if (query.addEventListener) query.addEventListener('change', apply);
})();
