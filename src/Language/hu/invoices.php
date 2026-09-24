<?php

return [
    // List
    'list_title' => 'Számlák',
    'new' => 'Új számla',
    'invoice_number' => 'Számlaszám',
    'issue_date' => 'Kiállítás',
    'due_date' => 'Fizetési határidő',
    'issued_from' => 'Kiállítástól',
    'issued_to' => 'Kiállításig',
    'none_found' => 'Nincs a szűrésnek megfelelő számla.',

    // Form
    'from_order_prefilled' => 'A {number} rendelés tételei előtöltve.',
    'choose_partner' => 'Válassz partnert…',
    'issue_date_label' => 'Kiállítás dátuma',
    'warehouse_stockout' => 'Készletkiadás raktárból',
    'warehouse_hint' => 'Ha megadod, a számla tételei automatikusan raktári kiadásként is könyvelődnek.',
    'no_auto_issue' => '— nincs automatikus kiadás —',
    'shipping_cost' => 'Szállítási költség ({currency})',
    'payment_cost' => 'Fizetési költség ({currency})',
    'payment_cost_hint' => '(pl. utánvét)',
    'create_invoice' => 'Számla kiállítása',

    // Show
    'address' => 'Cím',
    'product_name' => 'Megnevezés',
    'shipping_cost_row' => 'Szállítási költség',
    'payment_cost_row' => 'Fizetési költség',
    'mark_paid' => 'Kifizetve jelölés',
    'void' => 'Sztornó számla kiállítása',
    'confirm_void' => 'Kiállítod a sztornó számlát? Az eredeti számla sztornózva lesz, a kiadott áru visszakerül a raktárba.',
    'title_prefix' => 'Számla',

    // Print
    'print_doc_title' => 'SZÁMLA',
    'stamp_paid' => 'KIFIZETVE',
    'stamp_cancelled' => 'STORNÓZVA',
    'seller' => 'Eladó',
    'buyer' => 'Vevő',
    'tax_number' => 'Adószám',
    'bank_account' => 'Bankszámla',
    'payment_method' => 'Fizetési mód',
    'payment_transfer' => 'Átutalás',
    'total_due' => 'Fizetendő összesen',
    'print_footer' => 'A számlát a Cloudexus ügyviteli rendszer állította ki.',

    // Flash / validation
    'required' => 'A partner és legalább egy tétel megadása kötelező.',
    'shortage' => 'Nincs elég készlet a kiadáshoz: {items}',
    'shortage_item' => '{sku} (elérhető: {available}, kért: {requested})',
    'created' => 'Számla kiállítva.',
    'created_with_stock' => 'Számla kiállítva. A tételek raktári kiadásként is könyvelve.',
    'marked_paid' => 'Számla kifizetve jelölve.',
    'cancelled' => 'Számla stornózva.',
    'deleted' => 'Számla törölve.',

    // VAT, storno, supply date, payment method
    'fulfilment_date' => 'Teljesítés dátuma',
    'number_on_save' => 'A végleges sorszámot a mentés adja ki.',
    'unit_price_net' => 'Nettó egységár',
    'vat_rate' => 'ÁFA',
    'net' => 'Nettó',
    'vat' => 'ÁFA',
    'gross' => 'Bruttó',
    'vat_summary' => 'ÁFA-összesítő',
    'net_total' => 'Nettó összesen',
    'vat_total' => 'ÁFA összesen',
    'gross_total' => 'Bruttó összesen',
    'storno_title' => 'SZTORNÓ SZÁMLA',
    'storno_badge' => 'Sztornó számla',
    'storno_of' => 'A(z) {number} számla sztornója',
    'stornoed_by' => 'Sztornózva a(z) {number} számlával',
    'stornoed' => 'Sztornó számla kiállítva. Az eredeti számla sztornózva, a kiadott áru visszakönyvelve.',
    'not_stornoable' => 'Ez a számla nem sztornózható: csak kiállított, még ki nem fizetett számlát lehet sztornózni.',
    'storno_has_payments' => 'Erre a számlára már érkezett befizetés: előbb vond vissza a befizetést, csak utána sztornózható.',
    'not_payable' => 'Ez a számla nem jelölhető kifizetettnek.',
    'order_not_invoiceable' => 'A(z) {number} rendelésből nem állítható ki számla: már számlázva van, vagy nincs megerősítve.',
    'payment_methods' => [
        'transfer' => 'Átutalás',
        'cash' => 'Készpénz',
        'card' => 'Bankkártya',
        'cod' => 'Utánvét',
    ],

    // CSV
    'csv' => [
        'number' => 'Számlaszám',
        'partner' => 'Partner',
        'issue_date' => 'Kiállítás',
        'due_date' => 'Fizetési határidő',
        'status' => 'Állapot',
        'total' => 'Végösszeg',
    ],
    'csv_status' => [
        'unpaid' => 'fizetésre vár',
        'paid' => 'kifizetve',
        'cancelled' => 'stornózva',
        'storno' => 'sztornó számla',
    ],
];
