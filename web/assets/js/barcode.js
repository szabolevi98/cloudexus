/*
 * A vonalkódgyűjtő: a kézi olvasó a kódot "begépeli" és Entert küld; egy
 * ismert kód új sort ad, ugyanaz még egyszer eggyel növeli, egy ismeretlen
 * kódról szól. A raktár választása szűri a tárhelyeket.
 *
 * A beállítások a #barcode-config JSON-blokkból jönnek (stock/barcode.twig).
 */
const config = JSON.parse(document.getElementById('barcode-config').textContent);

(function () {
    const locs = config.locations;
    const wh = document.getElementById('warehouse_id');
    const loc = document.getElementById('location_id');
    function fill() {
        const w = parseInt(wh.value, 10);
        loc.innerHTML = '';
        const none = document.createElement('option');
        none.value = '';
        none.textContent = config.no_location;
        loc.appendChild(none);
        locs.filter(l => l.warehouse_id == w).forEach(l => {
            const o = document.createElement('option');
            o.value = l.id; o.textContent = l.code;
            loc.appendChild(o);
        });
    }
    wh.addEventListener('change', fill);
    fill();
})();

(function () {
    const input = document.getElementById('scan-input');
    const body = document.getElementById('scan-body');
    const emptyRow = document.getElementById('scan-empty');
    const feedback = document.getElementById('scan-feedback');
    const submitBtn = document.getElementById('scan-submit');
    const rows = new Map();

    function syncState() {
        emptyRow.style.display = rows.size ? 'none' : '';
        submitBtn.disabled = rows.size === 0;
    }

    function addProduct(product) {
        if (rows.has(product.id)) {
            const qtyInput = rows.get(product.id).querySelector('input[name="quantity[]"]');
            qtyInput.value = (parseFloat(qtyInput.value) || 0) + 1;
            return;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="fw-medium">${product.sku}<input type="hidden" name="product_id[]" value="${product.id}"></td>
            <td>${product.name}</td>
            <td><input type="number" step="0.001" min="0.001" name="quantity[]" class="form-control form-control-sm" value="1"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger scan-remove"><i class="bi bi-x-lg"></i></button></td>
        `;
        tr.querySelector('.scan-remove').addEventListener('click', () => {
            rows.delete(product.id);
            tr.remove();
            syncState();
            input.focus();
        });
        rows.set(product.id, tr);
        body.appendChild(tr);
        syncState();
    }

    input.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();

        const code = input.value.trim();
        if (!code) return;

        try {
            const res = await fetch(config.lookup_url + '?code=' + encodeURIComponent(code));
            const data = await res.json();

            if (data.found) {
                addProduct(data.product);
                feedback.textContent = '✓ ' + data.product.sku + ' — ' + data.product.name;
                feedback.className = 'form-text text-success';
            } else {
                feedback.textContent = '✗ ' + config.unknown_code + ' ' + code;
                feedback.className = 'form-text text-danger';
            }
        } catch {
            feedback.textContent = config.lookup_error;
            feedback.className = 'form-text text-danger';
        }

        input.value = '';
        input.focus();
    });

    // Az Enter a beolvasó mezőben ne küldje el a formot más mezőkből sem véletlenül.
    document.getElementById('barcode-form').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target !== submitBtn && e.target.type !== 'submit') {
            if (e.target === input) return; // már kezelve
            e.preventDefault();
        }
    });
})();

