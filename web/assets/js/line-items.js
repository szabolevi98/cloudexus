/*
 * A bizonylatok tételsorai (rendelés, számla, szállítói rendelés, bejövő
 * számla): termékválasztó, mennyiség, ár, sorösszeg és végösszeg, ahogy
 * gépelik. Az értékesítési űrlapokon (van partnerválasztó) az ár a partner
 * csoportjára, a mennyiségre és a bizonylat napjára szól, az árszabályokkal;
 * a beszerzésieken a sima ár.
 *
 * A beállítások a #line-items-config JSON-blokkból jönnek (common/line-items.twig).
 */
document.addEventListener('DOMContentLoaded', function () {
    const body = document.getElementById('line-items-body');
    const template = document.getElementById('line-item-template');
    const totalCell = document.getElementById('line-items-total');
    const config = JSON.parse(document.getElementById('line-items-config').textContent);
    const prefill = config.prefill || [];
    const partnerSelect = config.partner_select_id ? document.getElementById(config.partner_select_id) : null;
    const extraCostSelector = config.extra_cost_selector;
    // Sales forms (with a partner picker) get group prices and price rules for
    // the line quantity and the document date; purchase forms the plain price.
    const salesPricing = !!partnerSelect;
    const dateInput = config.date_input_name ? document.querySelector('[name="' + config.date_input_name + '"]') : null;
    const ruleLabel = config.rule_label;
    const priceCache = {};
    const numberFormat = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' ' + config.currency;

    function currentPartnerId() {
        return partnerSelect ? (parseInt(partnerSelect.value, 10) || 0) : 0;
    }

    function fetchEffectivePrice(productId, quantity) {
        const partnerId = salesPricing ? currentPartnerId() : 0;
        const qty = salesPricing && quantity > 0 ? quantity : 1;
        const date = salesPricing && dateInput ? dateInput.value : '';
        const key = [productId, partnerId, qty, date].join(':');
        if (priceCache[key]) {
            return priceCache[key];
        }
        const params = new URLSearchParams({ product_id: productId });
        if (salesPricing) {
            if (partnerId) params.set('partner_id', partnerId);
            params.set('quantity', qty);
            if (date) params.set('date', date);
        } else {
            params.set('base', '1');
        }
        const promise = fetch(config.pricing_url + '?' + params).then((r) => r.json());
        priceCache[key] = promise;
        return promise;
    }

    // Fills the row's price unless the user typed one; shows the rule that set it.
    function applyPrice(row) {
        const productId = row.querySelector('.cx-line-item__product').value;
        if (!productId || row.dataset.manualPrice === '1') {
            return;
        }
        const qty = parseFloat(row.querySelector('.cx-line-item__quantity').value) || 1;
        fetchEffectivePrice(productId, qty).then((data) => {
            row.querySelector('.cx-line-item__price').value = data.price || 0;
            const hint = row.querySelector('.cx-line-item__rule');
            hint.hidden = !data.rule;
            hint.textContent = data.rule ? ruleLabel.replace('{name}', data.rule.name) : '';
            recalcRow(row);
        });
    }

    function repriceAll() {
        body.querySelectorAll('tr').forEach(applyPrice);
    }

    function recalcRow(row) {
        const qty = parseFloat(row.querySelector('.cx-line-item__quantity').value) || 0;
        const price = parseFloat(row.querySelector('.cx-line-item__price').value) || 0;
        row.querySelector('.cx-line-item__total').textContent = numberFormat(qty * price);
        recalcTotal();
    }

    function recalcTotal() {
        let total = 0;
        body.querySelectorAll('tr').forEach((row) => {
            const qty = parseFloat(row.querySelector('.cx-line-item__quantity').value) || 0;
            const price = parseFloat(row.querySelector('.cx-line-item__price').value) || 0;
            total += qty * price;
        });
        if (extraCostSelector) {
            document.querySelectorAll(extraCostSelector).forEach((input) => {
                total += parseFloat(input.value) || 0;
            });
        }
        totalCell.textContent = numberFormat(total);
    }

    function addRow(data) {
        const row = template.content.firstElementChild.cloneNode(true);
        const productSelect = row.querySelector('.cx-line-item__product');

        // Előtöltéshez (jelenleg nem használt út, de a JSON alak régről ezt
        // engedi): a Select2 AJAX módhoz a kiválasztott opciónak már a DOM-ban
        // kell lennie, mielőtt inicializáljuk, különben csak az id látszódna.
        if (data && data.product_id) {
            const opt = document.createElement('option');
            opt.value = data.product_id;
            opt.selected = true;
            opt.textContent = data.text || ('#' + data.product_id);
            productSelect.appendChild(opt);
        }

        jQuery(productSelect).on('change', () => {
            // A new product takes its own price again, even over a typed one.
            delete row.dataset.manualPrice;
            applyPrice(row);
        });
        let repriceTimer = null;
        row.querySelector('.cx-line-item__quantity').addEventListener('input', () => {
            recalcRow(row);
            if (salesPricing) {
                // A quantity break may change the price; ask once the typing stops.
                clearTimeout(repriceTimer);
                repriceTimer = setTimeout(() => applyPrice(row), 350);
            }
        });
        row.querySelector('.cx-line-item__price').addEventListener('input', () => {
            row.dataset.manualPrice = '1';
            row.querySelector('.cx-line-item__rule').hidden = true;
            recalcRow(row);
        });
        row.querySelector('.cx-line-item__remove').addEventListener('click', () => {
            row.remove();
            recalcTotal();
        });

        body.appendChild(row);
        if (window.cxInitSelect2) window.cxInitSelect2(row);

        if (data) {
            row.querySelector('.cx-line-item__quantity').value = data.quantity;
            row.querySelector('.cx-line-item__price').value = data.unit_price;
            // A line taken over from an order keeps the order's price.
            row.dataset.manualPrice = '1';
            recalcRow(row);
        }
    }

    document.getElementById('add-line-item').addEventListener('click', () => addRow());

    if (extraCostSelector) {
        document.querySelectorAll(extraCostSelector).forEach((input) => {
            input.addEventListener('input', recalcTotal);
        });
    }

    if (partnerSelect) {
        jQuery(partnerSelect).on('change', repriceAll);
    }
    if (dateInput) {
        dateInput.addEventListener('change', repriceAll);
    }

    if (prefill.length) {
        prefill.forEach((item) => addRow(item));
    } else {
        addRow();
    }
});
