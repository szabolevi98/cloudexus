<?php

return [
    // List
    'list_title' => 'Bejövő számlák',
    'new' => 'Új bejövő számla',
    'invoice_number' => 'Számlaszám',
    'supplier' => 'Szállító',
    'issue_date' => 'Kiállítás',
    'due_date' => 'Fizetési határidő',
    'issued_from' => 'Kiállítástól',
    'issued_to' => 'Kiállításig',
    'amount' => 'Összeg',
    'none_found' => 'Nincs a szűrésnek megfelelő számla.',

    // Form
    'from_order_prefilled' => 'A {number} rendelés tételei előtöltve.',
    'choose_supplier' => 'Válassz szállítót…',
    'issue_date_label' => 'Kiállítás dátuma',
    'warehouse_stockin' => 'Raktári bevét raktára',
    'warehouse_hint' => 'Ha megadod, a tételek automatikusan raktári bevétként is könyvelődnek.',
    'no_auto_receipt' => '— nincs automatikus bevét —',
    'create_invoice' => 'Számla rögzítése',

    // Show
    'tax_number' => 'Adószám',
    'receipt_warehouse' => 'Bevét raktára',
    'product_name' => 'Megnevezés',
    'mark_paid' => 'Kifizetve jelölés',
    'void' => 'Stornózás',
    'confirm_void' => 'Biztosan stornózod a számlát?',
    'title_prefix' => 'Számla',

    // Flash / validation
    'required' => 'A szállító és legalább egy tétel megadása kötelező.',
    'created' => 'Bejövő számla rögzítve.',
    'created_with_stock' => 'Bejövő számla rögzítve. A tételek raktári bevétként is könyvelve.',
    'marked_paid' => 'Számla kifizetve jelölve.',
    'cancelled' => 'Számla stornózva.',
    'deleted' => 'Számla törölve.',
    'not_payable' => 'Csak kifizetetlen bejövő számla jelölhető kifizetettnek.',
    'not_cancellable' => 'Csak kifizetetlen bejövő számla stornózható.',
    'cancel_shortage' => 'A számlával bevételezett áru egy része már nincs a raktárban, ezért a sztornó nem tudja kivezetni; előbb rendezd a készletet.',
];
